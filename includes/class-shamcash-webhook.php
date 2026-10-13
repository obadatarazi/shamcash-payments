<?php
/**
 * Handles the encrypted ShamCash webhook via WordPress REST API.
 *
 * Endpoint: POST /wp-json/shamcash/v1/webhook
 *
 * Security notes:
 * - Endpoint is public by design (ShamCash servers call it).
 * - Authentication = successful decryption with the merchant secret.
 * - We never trust the payload until CryptoException is not thrown.
 * - Paid events are re-verified via getBill() before payment_complete().
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use OkToCode\ShamCash\Enum\BillStatus;
use OkToCode\ShamCash\Enum\Currency;
use OkToCode\ShamCash\Exception\CryptoException;

final class ShamCash_Webhook
{
    private static ?self $instance = null;

    private const LOCK_TTL = 30;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('shamcash/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $raw_body = $request->get_body();

        if ($raw_body === '' || strlen($raw_body) > 65536) {
            ShamCash_Logger::warning('Webhook rejected: empty or oversized body');
            return new \WP_REST_Response(['message' => 'Invalid body'], 400);
        }

        $client = ShamCash_Client_Factory::make();
        if (null === $client) {
            ShamCash_Logger::error('Webhook received but ShamCash client is not configured');
            return new \WP_REST_Response(['message' => 'Not configured'], 503);
        }

        try {
            $event = $client->parseWebhook($raw_body);
        } catch (CryptoException $e) {
            ShamCash_Logger::warning('Webhook decryption/validation failed: ' . ShamCash_Security::redact_for_log($e->getMessage()));
            return new \WP_REST_Response(['message' => 'Invalid payload'], 400);
        } catch (\Throwable $e) {
            ShamCash_Logger::error('Webhook unexpected error: ' . ShamCash_Security::redact_for_log($e->getMessage()));
            return new \WP_REST_Response(['message' => 'Server error'], 500);
        }

        $allowed_statuses = [
            BillStatus::Paid->value,
            BillStatus::Expired->value,
            BillStatus::Pending->value,
            BillStatus::Refund->value,
            BillStatus::PartlyRefunded->value,
        ];
        $status_id = $event->statusId;
        if (!in_array($status_id, $allowed_statuses, true)) {
            ShamCash_Logger::warning('Webhook rejected: unknown statusId ' . $status_id);
            return new \WP_REST_Response(['message' => 'Unknown status'], 400);
        }

        ShamCash_Logger::info(sprintf(
            'Webhook accepted – billNo: %s, status: %s, tranId: %s',
            sanitize_text_field($event->billNo),
            $event->status?->name ?? (string) $status_id,
            $event->tranId ?? 'null'
        ));

        $order = $this->find_order_by_bill_no($event->billNo);

        if (!$order) {
            ShamCash_Logger::warning('No order found for billNo: ' . sanitize_text_field($event->billNo));
            return new \WP_REST_Response(['message' => 'OK – order not found'], 200);
        }

        if ($order->get_payment_method() !== 'shamcash') {
            ShamCash_Logger::warning('Webhook bill matched order #' . $order->get_id() . ' with non-ShamCash payment method');
            return new \WP_REST_Response(['message' => 'OK – payment method mismatch'], 200);
        }

        // Idempotency on tranId (or none) + status — not just last status.
        $event_key = ($event->tranId !== null ? (string) $event->tranId : 'none') . ':' . $status_id;
        $processed = $order->get_meta('_shamcash_processed_events');
        if (!is_array($processed)) {
            $processed = [];
        }
        if (in_array($event_key, $processed, true)) {
            ShamCash_Logger::debug('Duplicate webhook ignored for order #' . $order->get_id() . ' key=' . $event_key);
            return new \WP_REST_Response(['message' => 'OK – already processed'], 200);
        }

        if (!$this->acquire_lock($order->get_id())) {
            // Ask ShamCash to retry — another webhook is handling this order (N4).
            ShamCash_Logger::debug('Webhook lock busy for order #' . $order->get_id());
            return new \WP_REST_Response(['message' => 'Locked – retry'], 503);
        }

        try {
            // Re-check after lock.
            $processed = $order->get_meta('_shamcash_processed_events');
            if (!is_array($processed)) {
                $processed = [];
            }
            if (in_array($event_key, $processed, true)) {
                return new \WP_REST_Response(['message' => 'OK – already processed'], 200);
            }

            $mark_done = true;

            if ($event->status === BillStatus::Paid) {
                $mark_done = $this->handle_paid($order, $event, $client);
            } elseif ($event->status === BillStatus::Expired) {
                $this->handle_expired($order, $event);
            } elseif (
                $event->status === BillStatus::Refund
                || $event->status === BillStatus::PartlyRefunded
            ) {
                $mark_done = $this->handle_refund($order, $event, $client);
            }

            if (!$mark_done) {
                // Transient failure (e.g. getBill) — do not mark done; ask ShamCash to retry (N3).
                return new \WP_REST_Response(['message' => 'Temporary failure – retry'], 503);
            }

            $processed[] = $event_key;
            // Keep the list bounded.
            if (count($processed) > 50) {
                $processed = array_slice($processed, -50);
            }
            $order->update_meta_data('_shamcash_processed_events', $processed);
            $order->update_meta_data('_shamcash_last_status', (string) $status_id);
            $order->save();
        } finally {
            $this->release_lock($order->get_id());
        }

        return new \WP_REST_Response(['message' => 'OK'], 200);
    }

    /**
     * @return bool True when the event may be marked processed; false to request a retry.
     */
    private function handle_paid(\WC_Order $order, object $event, \OkToCode\ShamCash\Client $client): bool
    {
        if ($order->is_paid()) {
            return true;
        }

        // Server-side verification before completing payment.
        try {
            $bill = $client->getBill($event->billNo);
        } catch (\Throwable $e) {
            ShamCash_Logger::error('getBill verification failed for order #' . $order->get_id() . ': ' . ShamCash_Security::redact_for_log($e->getMessage()));
            // Do not mark as processed — return 503 so ShamCash retries (N3).
            return false;
        }

        $expected_amount = number_format((float) $order->get_total(), 2, '.', '');
        $expected_currency = $this->map_currency($order->get_currency());

        $amount_ok   = $this->amounts_equal($bill->amount, $expected_amount);
        $currency_ok = $expected_currency !== null && $bill->currencyId === $expected_currency->value;
        $status_ok   = $bill->status === BillStatus::Paid || $bill->statusId === BillStatus::Paid->value;

        if (!$amount_ok || !$currency_ok || !$status_ok) {
            ShamCash_Logger::warning(sprintf(
                'Bill verification mismatch for order #%d – amount:%s/%s currencyId:%d/%s statusId:%d',
                $order->get_id(),
                $bill->amount,
                $expected_amount,
                $bill->currencyId,
                $expected_currency?->value ?? 'n/a',
                $bill->statusId
            ));
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: bill amount, 2: order amount, 3: bill status id */
                    __('ShamCash bill verification mismatch (bill amount %1$s vs order %2$s, status %3$d). Held for review.', 'shamcash-payments'),
                    $bill->amount,
                    $expected_amount,
                    $bill->statusId
                )
            );
            return true;
        }

        $settings      = ShamCash_Client_Factory::settings();
        $target_status = $settings['order_status_paid'] ?? 'processing';

        $order->payment_complete($event->tranId ? (string) $event->tranId : '');
        $order->add_order_note(
            sprintf(
                /* translators: 1: transaction id */
                __('ShamCash payment completed. Transaction ID: %s', 'shamcash-payments'),
                $event->tranId ?? 'n/a'
            )
        );

        if ($order->get_status() !== $target_status && $target_status !== 'processing') {
            $order->update_status($target_status, __('Status updated by ShamCash webhook.', 'shamcash-payments'));
        }

        ShamCash_Logger::info('Order #' . $order->get_id() . ' marked as paid via webhook');

        ShamCash_Mailer::notify_paid($order, $event->billNo, $event->tranId);

        return true;
    }

    private function handle_expired(\WC_Order $order, object $event): void
    {
        if (!in_array($order->get_status(), ['pending', 'on-hold', 'failed'], true)) {
            return;
        }

        $settings      = ShamCash_Client_Factory::settings();
        $target_status = $settings['order_status_failed'] ?? 'failed';

        $order->update_status(
            $target_status,
            __('ShamCash bill expired (customer did not complete payment within the time limit).', 'shamcash-payments')
        );

        ShamCash_Logger::info('Order #' . $order->get_id() . ' marked as expired via webhook');

        ShamCash_Mailer::notify_failed($order, $event->billNo);
    }

    /**
     * @return bool True when the event may be marked processed; false to request a retry.
     */
    private function handle_refund(\WC_Order $order, object $event, \OkToCode\ShamCash\Client $client): bool
    {
        $tran_id = $event->tranId ? (string) $event->tranId : '';
        if ($tran_id !== '') {
            foreach ($order->get_refunds() as $existing) {
                if ($existing->get_meta('_shamcash_refund_tran_id') === $tran_id) {
                    ShamCash_Logger::debug('Refund webhook already applied for tranId ' . $tran_id);
                    return true;
                }
            }

            $seen = $order->get_meta('_shamcash_refund_tran_ids');
            if (is_array($seen) && in_array($tran_id, $seen, true)) {
                ShamCash_Logger::debug('Refund webhook already recorded on order for tranId ' . $tran_id);
                return true;
            }
        }

        $remaining = (float) $order->get_remaining_refund_amount();
        if ($remaining <= 0) {
            $order->add_order_note(
                __('ShamCash refund webhook received but order has no remaining refundable amount.', 'shamcash-payments')
            );
            return true;
        }

        $amount = $remaining;
        try {
            $bill = $client->getBill($event->billNo);
            if ($tran_id !== '' && $bill->refunds !== []) {
                foreach ($bill->refunds as $api_refund) {
                    if ((string) $api_refund->tranId === $tran_id) {
                        $amount = min($remaining, (float) $api_refund->amount);
                        break;
                    }
                }
            } elseif ($event->status === BillStatus::PartlyRefunded && $bill->refunds !== []) {
                $latest = $bill->refunds[array_key_last($bill->refunds)];
                $amount = min($remaining, (float) $latest->amount);
            }
        } catch (\Throwable $e) {
            ShamCash_Logger::warning('getBill for refund webhook failed: ' . ShamCash_Security::redact_for_log($e->getMessage()));
            // Need the bill amount to avoid guessing — ask ShamCash to retry (N3).
            return false;
        }

        if ($amount <= 0) {
            return true;
        }

        $refund = wc_create_refund([
            'order_id'       => $order->get_id(),
            'amount'         => number_format($amount, 2, '.', ''),
            'reason'         => sprintf(
                /* translators: 1: transaction id */
                __('ShamCash refund webhook. Transaction ID: %s', 'shamcash-payments'),
                $tran_id !== '' ? $tran_id : 'n/a'
            ),
            'refund_payment' => false,
        ]);

        if (is_wp_error($refund)) {
            ShamCash_Logger::error('wc_create_refund failed: ' . $refund->get_error_message());
            $order->add_order_note(
                __('ShamCash refund webhook received but WooCommerce refund could not be created: ', 'shamcash-payments')
                . $refund->get_error_message()
            );
            return true;
        }

        if ($tran_id !== '') {
            $refund->update_meta_data('_shamcash_refund_tran_id', $tran_id);
            $refund->save();

            $seen = $order->get_meta('_shamcash_refund_tran_ids');
            if (!is_array($seen)) {
                $seen = [];
            }
            if (!in_array($tran_id, $seen, true)) {
                $seen[] = $tran_id;
                $order->update_meta_data('_shamcash_refund_tran_ids', array_slice($seen, -50));
            }
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: amount, 2: transaction id */
                __('ShamCash refund webhook applied for %1$s. Transaction ID: %2$s', 'shamcash-payments'),
                html_entity_decode(wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $tran_id !== '' ? $tran_id : 'n/a'
            )
        );

        ShamCash_Logger::info('Refund webhook applied for order #' . $order->get_id());

        return true;
    }

    private function find_order_by_bill_no(string $billNo): ?\WC_Order
    {
        $billNo = sanitize_text_field($billNo);

        // Exact match on current bill number.
        $orders = wc_get_orders([
            'limit'          => 1,
            'payment_method' => 'shamcash',
            'meta_key'       => '_shamcash_bill_no',
            'meta_value'     => $billNo,
            'return'         => 'objects',
        ]);

        if (!empty($orders)) {
            return $orders[0];
        }

        // Match against attempt history (serialized array contains the bill no).
        $history_orders = wc_get_orders([
            'limit'          => 20,
            'payment_method' => 'shamcash',
            'meta_key'       => '_shamcash_bill_history',
            'meta_compare'   => 'LIKE',
            'meta_value'     => $billNo,
            'return'         => 'objects',
        ]);

        foreach ($history_orders as $candidate) {
            $history = $candidate->get_meta('_shamcash_bill_history');
            if (is_array($history) && in_array($billNo, $history, true)) {
                return $candidate;
            }
        }

        // No Order-{id} regex fallback — that could attach payments to the wrong order.
        return null;
    }

    private function acquire_lock(int $order_id): bool
    {
        $key = 'shamcash_wh_lock_' . $order_id;
        $now = time();

        if (wp_cache_add($key, $now, 'shamcash', self::LOCK_TTL)) {
            // Also use options for hosts without a persistent object cache.
        }

        if (add_option($key, (string) $now, '', 'no')) {
            return true;
        }

        $existing = (int) get_option($key, 0);
        if ($existing > 0 && ($now - $existing) > self::LOCK_TTL) {
            delete_option($key);
            return (bool) add_option($key, (string) $now, '', 'no');
        }

        return false;
    }

    private function release_lock(int $order_id): void
    {
        $key = 'shamcash_wh_lock_' . $order_id;
        delete_option($key);
        wp_cache_delete($key, 'shamcash');
    }

    private function map_currency(string $code): ?Currency
    {
        return match (strtoupper($code)) {
            'USD' => Currency::Usd,
            'SYP' => Currency::Syp,
            default => null,
        };
    }

    private function amounts_equal(string $a, string $b): bool
    {
        return number_format((float) $a, 2, '.', '') === number_format((float) $b, 2, '.', '');
    }
}
