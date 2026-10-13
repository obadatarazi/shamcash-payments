<?php
/**
 * Optional admin email notifications.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Mailer
{
    public static function notify_paid(\WC_Order $order, string $bill_no, ?int $tran_id): void
    {
        $settings = ShamCash_Client_Factory::settings();
        if (($settings['email_on_paid'] ?? 'no') !== 'yes') {
            return;
        }

        $recipients = self::recipients($settings);
        if ($recipients === []) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: order number */
            __('[%1$s] ShamCash payment received – Order #%2$s', 'shamcash-payments'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $order->get_order_number()
        );

        $amount = wp_strip_all_tags(
            wc_price((float) $order->get_total(), ['currency' => $order->get_currency()])
        );

        $body = sprintf(
            /* translators: 1: order number, 2: bill no, 3: transaction id, 4: amount, 5: customer name, 6: order URL */
            __("A ShamCash payment was successfully received.\n\nOrder: #%1\$s\nBill No: %2\$s\nTransaction ID: %3\$s\nAmount: %4\$s\nCustomer: %5\$s\n\nView order: %6\$s", 'shamcash-payments'),
            $order->get_order_number(),
            $bill_no,
            $tran_id ?? 'n/a',
            $amount,
            $order->get_formatted_billing_full_name(),
            $order->get_edit_order_url()
        );

        self::send($recipients, $subject, $body);
    }

    public static function notify_failed(\WC_Order $order, string $bill_no): void
    {
        $settings = ShamCash_Client_Factory::settings();
        if (($settings['email_on_failed'] ?? 'no') !== 'yes') {
            return;
        }

        $recipients = self::recipients($settings);
        if ($recipients === []) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: site name, 2: order number */
            __('[%1$s] ShamCash bill expired – Order #%2$s', 'shamcash-payments'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $order->get_order_number()
        );

        $amount = wp_strip_all_tags(
            wc_price((float) $order->get_total(), ['currency' => $order->get_currency()])
        );

        $body = sprintf(
            /* translators: 1: order number, 2: bill no, 3: amount, 4: customer name, 5: order URL */
            __("A ShamCash bill expired without payment.\n\nOrder: #%1\$s\nBill No: %2\$s\nAmount: %3\$s\nCustomer: %4\$s\n\nView order: %5\$s", 'shamcash-payments'),
            $order->get_order_number(),
            $bill_no,
            $amount,
            $order->get_formatted_billing_full_name(),
            $order->get_edit_order_url()
        );

        self::send($recipients, $subject, $body);
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private static function recipients(array $settings): array
    {
        $raw = trim((string) ($settings['email_recipients'] ?? ''));
        if ($raw === '') {
            $admin = get_option('admin_email');
            return is_email($admin) ? [$admin] : [];
        }

        $emails = array_map('trim', explode(',', $raw));
        $valid  = [];
        foreach ($emails as $email) {
            if (is_email($email)) {
                $valid[] = $email;
            }
        }
        return $valid;
    }

    /**
     * @param list<string> $to
     */
    private static function send(array $to, string $subject, string $body): void
    {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        foreach ($to as $email) {
            wp_mail($email, $subject, $body, $headers);
        }
    }
}
