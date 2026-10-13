<?php
/**
 * AJAX Test Connection handler.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use OkToCode\ShamCash\Enum\ResultCode;
use OkToCode\ShamCash\Exception\ApiException;
use OkToCode\ShamCash\Exception\CryptoException;
use OkToCode\ShamCash\Exception\TransportException;

final class ShamCash_Connection_Tester
{
    private static ?self $instance = null;

    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'api.shamcash.com',
        'sandbox.shamcash.com',
        'api-sandbox.shamcash.com',
    ];

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
        add_action('wp_ajax_shamcash_test_connection', [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (strpos($hook, 'woocommerce') === false && strpos($hook, 'wc-settings') === false) {
            return;
        }

        wp_enqueue_script(
            'shamcash-test-connection',
            SHAMCASH_PAYMENTS_URL . 'assets/js/test-connection.js',
            ['jquery'],
            SHAMCASH_PAYMENTS_VERSION,
            true
        );

        wp_localize_script('shamcash-test-connection', 'shamcashTest', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('shamcash_test_connection'),
            'testing' => __('Testing...', 'shamcash-payments'),
            'success' => __('Connection successful', 'shamcash-payments'),
            'failed'  => __('Connection failed', 'shamcash-payments'),
        ]);
    }

    public function handle(): void
    {
        check_ajax_referer('shamcash_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                // Plain text — JS uses createTextNode (avoid double-encoding &amp;) (N6).
                'message' => __('You do not have permission to perform this action.', 'shamcash-payments'),
            ], 403);
        }

        $agent  = isset($_POST['agent_key']) ? sanitize_text_field(wp_unslash((string) $_POST['agent_key'])) : '';
        $secret = isset($_POST['secret_key'])
            ? (preg_replace('/[\x00-\x1F\x7F]/', '', trim(wp_unslash((string) $_POST['secret_key']))) ?? '')
            : '';
        $base_raw = isset($_POST['base_url']) ? trim(wp_unslash((string) $_POST['base_url'])) : '';
        $base     = ($base_raw !== '' && preg_match('#^https?://#i', $base_raw) && wp_http_validate_url($base_raw))
            ? esc_url_raw($base_raw)
            : '';

        $saved = ShamCash_Client_Factory::settings();
        if ($agent === '') {
            $agent = trim((string) ($saved['agent_key'] ?? ''));
        }
        if ($secret === '') {
            $secret = trim((string) ($saved['secret_key'] ?? ''));
        }
        if ($base === '') {
            $base = trim((string) ($saved['base_url'] ?? ''));
        }

        if ($agent === '' || $secret === '' || $base === '') {
            wp_send_json_error([
                'message' => __('Please enter Agent Key, Secret Key and Base URL first.', 'shamcash-payments'),
            ]);
        }

        if (!$this->is_allowed_base_url($base, $saved)) {
            wp_send_json_error([
                'message' => __('Base URL is not allowed. Use the saved Base URL or an official ShamCash host.', 'shamcash-payments'),
            ]);
        }

        try {
            $client = new \OkToCode\ShamCash\Client(
                agentKey: $agent,
                secretKey: $secret,
                baseUrl: $base,
            );
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Invalid credentials: %s', 'shamcash-payments'),
                    $e->getMessage()
                ),
            ]);
        }

        try {
            $from = gmdate('Y-m-d', strtotime('-7 days'));
            $to   = gmdate('Y-m-d');
            $client->listTransactions($from, $to, 0, 10);

            wp_send_json_success([
                'message' => __('Connection successful. Credentials verified with ShamCash servers.', 'shamcash-payments'),
            ]);
        } catch (ApiException $e) {
            $code = $e->resultCode;
            $fail_codes = [
                ResultCode::AgentKeyInvalid->value,
                ResultCode::AgentNotActive->value,
                ResultCode::EncryptedDataInvalid->value,
                ResultCode::AccountBlocked->value,
            ];

            if (in_array($code, $fail_codes, true)) {
                wp_send_json_error([
                    'message' => $this->message_for_result_code($code, $e->apiMessage),
                ]);
            }

            // Harmless business codes (e.g. 1703 BillNotFound) still prove encryption + reachability.
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: API message */
                    __('Successfully reached the server (encryption & key verified). API message: %s', 'shamcash-payments'),
                    $e->apiMessage
                ),
            ]);
        } catch (TransportException $e) {
            wp_send_json_error([
                'message' => $this->transport_error_message($e, $base),
            ]);
        } catch (CryptoException $e) {
            wp_send_json_error([
                'message' => __('Encryption/decryption failed. Please check the Secret Key.', 'shamcash-payments'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => __('An unexpected error occurred during the test.', 'shamcash-payments'),
            ]);
        }
    }

    private function transport_error_message(TransportException $e, string $base): string
    {
        $raw  = $e->getMessage();
        $host = (string) (wp_parse_url($base, PHP_URL_HOST) ?: '');

        // Friendly message when DNS / host cannot be resolved (N5).
        if (
            stripos($raw, 'Could not resolve host') !== false
            || stripos($raw, 'getaddrinfo') !== false
            || stripos($raw, 'name or service not known') !== false
            || stripos($raw, 'nodename nor servname') !== false
            || (str_contains($raw, 'cURL error 6'))
        ) {
            return sprintf(
                /* translators: %s: hostname */
                __('Could not resolve host “%s”. Check the Base URL spelling and your server DNS.', 'shamcash-payments'),
                $host !== '' ? $host : $base
            );
        }

        return sprintf(
            /* translators: %s: error details */
            __('Could not reach ShamCash server. Check Base URL or network. Details: %s', 'shamcash-payments'),
            $raw
        );
    }

    /**
     * @param array<string, mixed> $saved
     */
    private function is_allowed_base_url(string $base, array $saved): bool
    {
        $base = untrailingslashit($base);

        if ($base === '' || !wp_http_validate_url($base)) {
            return false;
        }

        if (!preg_match('#^https?://#i', $base)) {
            return false;
        }

        $saved_base = untrailingslashit(trim((string) ($saved['base_url'] ?? '')));
        if ($saved_base !== '' && strcasecmp($base, $saved_base) === 0) {
            return true;
        }

        $host = strtolower((string) wp_parse_url($base, PHP_URL_HOST));
        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    private function message_for_result_code(int $code, string $api_message): string
    {
        return match ($code) {
            ResultCode::AgentKeyInvalid->value => __('Connection failed: invalid Agent Key (1701).', 'shamcash-payments'),
            ResultCode::AgentNotActive->value => __('Connection failed: agent is not active (1702).', 'shamcash-payments'),
            ResultCode::EncryptedDataInvalid->value => __('Connection failed: invalid encrypted data / Secret Key (1705).', 'shamcash-payments'),
            ResultCode::AccountBlocked->value => __('Connection failed: account is blocked (1706).', 'shamcash-payments'),
            default => sprintf(
                /* translators: 1: result code, 2: API message */
                __('Connection failed (%1$d): %2$s', 'shamcash-payments'),
                $code,
                $api_message
            ),
        };
    }
}
