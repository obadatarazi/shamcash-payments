<?php
/**
 * WooCommerce Payment Gateway for ShamCash.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use OkToCode\ShamCash\Enum\Currency;
use OkToCode\ShamCash\Exception\ApiException;
use OkToCode\ShamCash\Exception\TransportException;

final class ShamCash_Gateway extends WC_Payment_Gateway
{
    private const MAX_BILL_ATTEMPTS = 3;

    public function __construct()
    {
        $this->id                 = 'shamcash';
        $this->method_title       = __('ShamCash', 'shamcash-payments');
        $this->method_description = __('Accept payments via ShamCash e-wallet. Customers are redirected to complete payment securely.', 'shamcash-payments');
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', __('ShamCash', 'shamcash-payments'));
        $this->description = $this->get_option('description', __('Pay securely with ShamCash e-wallet.', 'shamcash-payments'));
        $this->enabled     = $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $webhook_url = rest_url('shamcash/v1/webhook');

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'shamcash-payments'),
                'type'    => 'checkbox',
                'label'   => __('Enable ShamCash Payments', 'shamcash-payments'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'shamcash-payments'),
                'type'        => 'text',
                'description' => __('Payment method title shown to customers at checkout.', 'shamcash-payments'),
                'default'     => __('ShamCash', 'shamcash-payments'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'shamcash-payments'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown to customers at checkout.', 'shamcash-payments'),
                'default'     => __('Pay securely with ShamCash e-wallet.', 'shamcash-payments'),
                'desc_tip'    => true,
            ],
            'api_section' => [
                'title'       => __('API Credentials', 'shamcash-payments'),
                'type'        => 'title',
                'description' => __('These values are provided when you create a service in the ShamCash merchant dashboard. Keep the Secret Key private.', 'shamcash-payments'),
            ],
            'agent_key' => [
                'title'       => __('Agent Key', 'shamcash-payments'),
                'type'        => 'text',
                'description' => __('Your ShamCash agent key.', 'shamcash-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'secret_key' => [
                'title'       => __('Secret Key', 'shamcash-payments'),
                'type'        => 'password',
                'description' => __('Base64-encoded 32-byte secret. Never share this value.', 'shamcash-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'base_url' => [
                'title'       => __('Base URL', 'shamcash-payments'),
                'type'        => 'text',
                'description' => __('ShamCash API base URL (no trailing slash).', 'shamcash-payments'),
                'default'     => 'https://api.shamcash.com',
                'desc_tip'    => true,
            ],
            'test_connection_btn' => [
                'title'       => __('Test Connection', 'shamcash-payments'),
                'type'        => 'shamcash_test_button',
                'description' => __('Click to verify your credentials with ShamCash servers (read-only check).', 'shamcash-payments'),
            ],
            'urls_section' => [
                'title'       => __('Callback & Redirect', 'shamcash-payments'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('Built-in Webhook URL (recommended): %s', 'shamcash-payments'),
                    '<code style="user-select:all;word-break:break-all">' . esc_html($webhook_url) . '</code>'
                ),
            ],
            'callback_url' => [
                'title'       => __('Custom Callback URL', 'shamcash-payments'),
                'type'        => 'text',
                'description' => __('Leave empty to use the secure built-in webhook endpoint.', 'shamcash-payments'),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => $webhook_url,
            ],
            'redirect_url' => [
                'title'       => __('Custom Redirect URL', 'shamcash-payments'),
                'type'        => 'text',
                'description' => __('Where the customer returns after payment. Leave empty to use the order thank-you page.', 'shamcash-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'status_section' => [
                'title' => __('Order Status Mapping', 'shamcash-payments'),
                'type'  => 'title',
            ],
            'order_status_paid' => [
                'title'       => __('Status after successful payment', 'shamcash-payments'),
                'type'        => 'select',
                'description' => __('WooCommerce order status set when ShamCash confirms payment.', 'shamcash-payments'),
                'default'     => 'processing',
                'options'     => [
                    'processing' => __('Processing', 'shamcash-payments'),
                    'completed'  => __('Completed', 'shamcash-payments'),
                    'on-hold'    => __('On hold', 'shamcash-payments'),
                ],
                'desc_tip'    => true,
            ],
            'order_status_failed' => [
                'title'       => __('Status when bill expires', 'shamcash-payments'),
                'type'        => 'select',
                'description' => __('Status used when the customer does not pay within the time limit.', 'shamcash-payments'),
                'default'     => 'failed',
                'options'     => [
                    'failed'    => __('Failed', 'shamcash-payments'),
                    'cancelled' => __('Cancelled', 'shamcash-payments'),
                    'pending'   => __('Pending payment', 'shamcash-payments'),
                ],
                'desc_tip'    => true,
            ],
            'email_section' => [
                'title'       => __('Email Notifications', 'shamcash-payments'),
                'type'        => 'title',
                'description' => __('Optional notifications sent to store administrators.', 'shamcash-payments'),
            ],
            'email_on_paid' => [
                'title'   => __('Payment received', 'shamcash-payments'),
                'type'    => 'checkbox',
                'label'   => __('Send email when a payment is successfully received', 'shamcash-payments'),
                'default' => 'no',
            ],
            'email_on_failed' => [
                'title'   => __('Bill expired', 'shamcash-payments'),
                'type'    => 'checkbox',
                'label'   => __('Send email when a bill expires without payment', 'shamcash-payments'),
                'default' => 'no',
            ],
            'email_recipients' => [
                'title'       => __('Email recipients', 'shamcash-payments'),
                'type'        => 'text',
                'description' => __('Comma-separated list of emails. Leave empty to use the site admin email.', 'shamcash-payments'),
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => get_option('admin_email'),
            ],
            'debug' => [
                'title'       => __('Debug Log', 'shamcash-payments'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'shamcash-payments'),
                'default'     => 'no',
                'description' => __('Logs appear under WooCommerce → Status → Logs (source: shamcash-payments). Secrets are never written.', 'shamcash-payments'),
            ],
        ];
    }

    public function generate_password_html($key, $data): string
    {
        if ($key !== 'secret_key') {
            return parent::generate_password_html($key, $data);
        }

        $field_key = $this->get_field_key($key);
        $defaults  = [
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'password',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => [],
        ];
        $data = wp_parse_args($data, $defaults);

        $saved = (string) $this->get_option($key);
        $hint  = '';
        if ($saved !== '') {
            $suffix = strlen($saved) > 4 ? substr($saved, -4) : '****';
            $hint   = ' <span class="description">' . esc_html(
                sprintf(
                    /* translators: %s: last 4 characters of the saved secret */
                    __('Saved, ends in %s. Leave blank to keep the current secret.', 'shamcash-payments'),
                    $suffix
                )
            ) . '</span>';
        }

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <input
                        class="input-text regular-input <?php echo esc_attr($data['class']); ?>"
                        type="password"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        style="<?php echo esc_attr($data['css']); ?>"
                        value=""
                        placeholder="<?php echo esc_attr($data['placeholder']); ?>"
                        autocomplete="new-password"
                    />
                    <?php echo $this->get_description_html($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $hint; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    public function validate_secret_key_field(string $key, string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim(wp_unslash($value))) ?? '';

        if ($value === '') {
            return (string) $this->get_option($key);
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            WC_Admin_Settings::add_error(
                __('Secret Key must be a Base64-encoded 32-byte value.', 'shamcash-payments')
            );
            return (string) $this->get_option($key);
        }

        return $value;
    }

    public function validate_base_url_field(string $key, string $value): string
    {
        $value = esc_url_raw(trim(wp_unslash($value)));
        if ($value === '' || !preg_match('#^https?://#i', $value)) {
            WC_Admin_Settings::add_error(
                __('Base URL must be a valid http or https URL.', 'shamcash-payments')
            );
            return (string) $this->get_option($key, 'https://api.shamcash.com');
        }

        return untrailingslashit($value);
    }

    public function validate_callback_url_field(string $key, string $value): string
    {
        $value = trim(wp_unslash($value));
        if ($value === '') {
            return '';
        }

        $value = esc_url_raw($value);
        if ($value === '' || !preg_match('#^https?://#i', $value)) {
            WC_Admin_Settings::add_error(
                __('Custom Callback URL must be a valid http or https URL.', 'shamcash-payments')
            );
            return (string) $this->get_option($key);
        }

        return $value;
    }

    public function validate_redirect_url_field(string $key, string $value): string
    {
        $value = trim(wp_unslash($value));
        if ($value === '') {
            return '';
        }

        $value = esc_url_raw($value);
        if ($value === '' || !preg_match('#^https?://#i', $value)) {
            WC_Admin_Settings::add_error(
                __('Custom Redirect URL must be a valid http or https URL.', 'shamcash-payments')
            );
            return (string) $this->get_option($key);
        }

        return $value;
    }

    public function validate_email_recipients_field(string $key, string $value): string
    {
        $value = sanitize_text_field(wp_unslash($value));
        if ($value === '') {
            return '';
        }

        $parts  = array_map('trim', explode(',', $value));
        $valid  = [];
        $bad    = false;
        foreach ($parts as $email) {
            if ($email === '') {
                continue;
            }
            if (!is_email($email)) {
                $bad = true;
                continue;
            }
            $valid[] = $email;
        }

        if ($bad) {
            WC_Admin_Settings::add_error(
                __('One or more email recipients are invalid.', 'shamcash-payments')
            );
        }

        return implode(', ', $valid);
    }

    public function process_admin_options(): void
    {
        parent::process_admin_options();

        // Drop the legacy duplicate settings store.
        delete_option('shamcash_payments_settings');
    }

    /**
     * @param int $order_id
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'shamcash-payments'), 'error');
            return ['result' => 'failure'];
        }

        $client = ShamCash_Client_Factory::make();
        if (null === $client) {
            wc_add_notice(__('ShamCash is not configured. Please contact the store owner.', 'shamcash-payments'), 'error');
            ShamCash_Logger::error('process_payment called but client could not be created');
            return ['result' => 'failure'];
        }

        $amount   = $order->get_total();
        $currency = $this->map_currency($order->get_currency());

        if (null === $currency) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: currency code */
                    __('Currency %s is not supported by ShamCash.', 'shamcash-payments'),
                    $order->get_currency()
                ),
                'error'
            );
            return ['result' => 'failure'];
        }

        $redirect_url = $this->get_return_url($order);
        $settings_redirect = trim((string) $this->get_option('redirect_url'));
        if ($settings_redirect !== '') {
            $redirect_url = $settings_redirect;
        }

        $attempt = (int) $order->get_meta('_shamcash_bill_attempt');
        $history = $order->get_meta('_shamcash_bill_history');
        if (!is_array($history)) {
            $history = [];
        }

        $bill = null;
        $last_error = null;

        for ($i = 0; $i < self::MAX_BILL_ATTEMPTS; $i++) {
            $attempt++;
                $bill_no = $this->generate_bill_no($order);

            try {
                $bill = $client->createBill(
                    billNo: $bill_no,
                    amount: number_format((float) $amount, 2, '.', ''),
                    currency: $currency,
                    note: sprintf('Order #%d – %s', $order->get_id(), $order->get_billing_email()),
                    redirectUrl: $redirect_url,
                );

                $history[] = $bill->billNo;
                $order->update_meta_data('_shamcash_bill_no', $bill->billNo);
                $order->update_meta_data('_shamcash_bill_attempt', $attempt);
                $order->update_meta_data('_shamcash_bill_history', $history);
                $order->update_meta_data('_shamcash_payment_url', $bill->paymentUrl);
                $order->update_meta_data('_shamcash_status_id', (string) $bill->statusId);
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: bill number */
                        __('ShamCash bill created: %s. Customer redirected to payment page.', 'shamcash-payments'),
                        $bill->billNo
                    )
                );
                $order->save();

                ShamCash_Logger::info(sprintf(
                    'Bill created for order #%d – billNo: %s, amount: %s',
                    $order->get_id(),
                    $bill->billNo,
                    $bill->amount
                ));

                return [
                    'result'   => 'success',
                    'redirect' => $bill->paymentUrl,
                ];
            } catch (ApiException $e) {
                $last_error = $e;
                $history[]  = $bill_no;
                $order->update_meta_data('_shamcash_bill_attempt', $attempt);
                $order->update_meta_data('_shamcash_bill_history', $history);
                $order->save();

                if ($e->isBillNoAlreadyExists() && $i < self::MAX_BILL_ATTEMPTS - 1) {
                    continue;
                }

                ShamCash_Logger::error('createBill ApiException: ' . ShamCash_Security::redact_for_log($e->getMessage()));
                wc_add_notice(__('Unable to create ShamCash payment. Please try again.', 'shamcash-payments'), 'error');
                return ['result' => 'failure'];
            } catch (TransportException $e) {
                ShamCash_Logger::error('createBill TransportException: ' . ShamCash_Security::redact_for_log($e->getMessage()));
                wc_add_notice(__('Payment service temporarily unavailable. Please try again later.', 'shamcash-payments'), 'error');
                return ['result' => 'failure'];
            } catch (\Throwable $e) {
                ShamCash_Logger::error('createBill unexpected: ' . ShamCash_Security::redact_for_log($e->getMessage()));
                wc_add_notice(__('An unexpected error occurred. Please try again.', 'shamcash-payments'), 'error');
                return ['result' => 'failure'];
            }
        }

        ShamCash_Logger::error('createBill exhausted retries for order #' . $order_id . ($last_error ? ': ' . $last_error->getMessage() : ''));
        wc_add_notice(
            __('Unable to create a unique ShamCash payment bill after several attempts. Please try again or contact support.', 'shamcash-payments'),
            'error'
        );
        return ['result' => 'failure'];
    }

    /**
     * Unique per site: {site-prefix}-{order_id}-{random}.
     */
    private function generate_bill_no(\WC_Order $order): string
    {
        $host = (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?: 'site');
        $prefix = preg_replace('/[^a-zA-Z0-9]/', '', $host) ?: 'site';
        $prefix = substr(strtolower($prefix), 0, 12);

        return sprintf(
            '%s-%d-%s',
            $prefix,
            $order->get_id(),
            wp_generate_password(6, false, false)
        );
    }

    /**
     * @param int        $order_id
     * @param float|null $amount
     * @param string     $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('invalid_order', __('Order not found.', 'shamcash-payments'));
        }

        $bill_no = $order->get_meta('_shamcash_bill_no');
        if (!$bill_no) {
            return new \WP_Error('no_bill', __('No ShamCash bill found for this order.', 'shamcash-payments'));
        }

        $client = ShamCash_Client_Factory::make();
        if (null === $client) {
            return new \WP_Error('not_configured', __('ShamCash is not configured.', 'shamcash-payments'));
        }

        $refund_amount = $amount !== null
            ? number_format((float) $amount, 2, '.', '')
            : number_format((float) $order->get_total(), 2, '.', '');

        // Stable key from order + refund attempt count (WooCommerce creates the refund after this returns).
        $refund_count = count($order->get_refunds());
        $idempotency_key = sprintf('refund-%d-%d', $order->get_id(), $refund_count + 1);

        try {
            $result = $client->refundBill(
                billNo: $bill_no,
                amount: $refund_amount,
                idempotencyKey: $idempotency_key,
                note: $reason !== '' ? $reason : sprintf('Refund for order #%d', $order->get_id()),
            );
        } catch (ApiException $e) {
            ShamCash_Logger::error('refundBill ApiException: ' . ShamCash_Security::redact_for_log($e->getMessage()));
            return new \WP_Error('refund_failed', $e->getMessage());
        } catch (\Throwable $e) {
            ShamCash_Logger::error('refundBill unexpected: ' . ShamCash_Security::redact_for_log($e->getMessage()));
            return new \WP_Error('refund_failed', __('Refund request failed.', 'shamcash-payments'));
        }

        $order->add_order_note(
            sprintf(
                /* translators: 1: amount, 2: transaction id */
                __('ShamCash refund of %1$s processed. Transaction ID: %2$s', 'shamcash-payments'),
                $refund_amount,
                $result->tranId
            )
        );

        ShamCash_Logger::info(sprintf(
            'Refund processed for order #%d – amount: %s, tranId: %s',
            $order->get_id(),
            $refund_amount,
            $result->tranId
        ));

        return true;
    }

    private function map_currency(string $code): ?Currency
    {
        return match (strtoupper($code)) {
            'USD' => Currency::Usd,
            'SYP' => Currency::Syp,
            default => null,
        };
    }

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }

        $agent  = $this->get_option('agent_key');
        $secret = $this->get_option('secret_key');
        $base   = $this->get_option('base_url');

        if ($agent === '' || $secret === '' || $base === '') {
            return false;
        }

        if (WC()->cart) {
            $currency = $this->map_currency(get_woocommerce_currency());
            if (null === $currency) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the Test Connection button (custom field type).
     */
    public function generate_shamcash_test_button_html(string $key, array $data): string
    {
        $defaults = [
            'title'       => '',
            'description' => '',
        ];
        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <button type="button" class="button button-secondary" id="shamcash-test-connection-btn">
                        <?php esc_html_e('Test Connection Now', 'shamcash-payments'); ?>
                    </button>
                    <p class="description"><?php echo esc_html($data['description']); ?></p>
                    <div id="shamcash-test-connection-result" style="display:none;margin-top:10px;padding:8px 12px;border-radius:4px;"></div>
                </fieldset>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }
}
