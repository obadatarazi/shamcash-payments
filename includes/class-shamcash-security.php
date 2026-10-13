<?php
/**
 * Security helpers – capability checks, nonces, sanitization.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Security
{
    public const CAPABILITY = 'manage_woocommerce';

    /**
     * Abort if current user cannot manage WooCommerce.
     */
    public static function require_manage_cap(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(
                esc_html__('You do not have permission to perform this action.', 'shamcash-payments'),
                esc_html__('Unauthorized', 'shamcash-payments'),
                ['response' => 403]
            );
        }
    }

    /**
     * Verify a nonce or die.
     */
    public static function verify_nonce(string $nonce, string $action): void
    {
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(
                esc_html__('Security check failed. Please refresh the page and try again.', 'shamcash-payments'),
                esc_html__('Security Error', 'shamcash-payments'),
                ['response' => 403]
            );
        }
    }

    /**
     * Sanitize a settings array coming from the admin form.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function sanitize_settings(array $input): array
    {
        $clean = [];

        $clean['agent_key'] = isset($input['agent_key'])
            ? sanitize_text_field(wp_unslash((string) $input['agent_key']))
            : '';

        // Secret key – do not strip too aggressively, but remove control characters
        $clean['secret_key'] = isset($input['secret_key'])
            ? preg_replace('/[\x00-\x1F\x7F]/', '', trim(wp_unslash((string) $input['secret_key'])))
            : '';

        $clean['base_url'] = self::sanitize_http_url(
            isset($input['base_url']) ? (string) $input['base_url'] : ''
        );

        $clean['callback_url'] = self::sanitize_http_url(
            isset($input['callback_url']) ? (string) $input['callback_url'] : '',
            true
        );

        $clean['redirect_url'] = self::sanitize_http_url(
            isset($input['redirect_url']) ? (string) $input['redirect_url'] : '',
            true
        );

        $clean['title'] = isset($input['title'])
            ? sanitize_text_field(wp_unslash((string) $input['title']))
            : 'ShamCash';

        $clean['description'] = isset($input['description'])
            ? sanitize_textarea_field(wp_unslash((string) $input['description']))
            : '';

        $allowed_paid = ['processing', 'completed', 'on-hold'];
        $clean['order_status_paid'] = isset($input['order_status_paid']) && in_array($input['order_status_paid'], $allowed_paid, true)
            ? $input['order_status_paid']
            : 'processing';

        $allowed_failed = ['failed', 'cancelled', 'pending'];
        $clean['order_status_failed'] = isset($input['order_status_failed']) && in_array($input['order_status_failed'], $allowed_failed, true)
            ? $input['order_status_failed']
            : 'failed';

        $clean['debug'] = (isset($input['debug']) && $input['debug'] === 'yes') ? 'yes' : 'no';

        $clean['email_on_paid']   = (isset($input['email_on_paid']) && $input['email_on_paid'] === 'yes') ? 'yes' : 'no';
        $clean['email_on_failed'] = (isset($input['email_on_failed']) && $input['email_on_failed'] === 'yes') ? 'yes' : 'no';
        $clean['email_recipients'] = isset($input['email_recipients'])
            ? sanitize_text_field(wp_unslash((string) $input['email_recipients']))
            : '';

        return $clean;
    }

    /**
     * Sanitize an http(s) URL without letting esc_url_raw invent a scheme (M4).
     *
     * @param bool $allow_empty When true, empty input stays empty instead of failing.
     */
    public static function sanitize_http_url(string $value, bool $allow_empty = false): string
    {
        $value = trim(wp_unslash($value));
        if ($value === '') {
            return $allow_empty ? '' : '';
        }

        if (!preg_match('#^https?://#i', $value) || !wp_http_validate_url($value)) {
            return '';
        }

        return esc_url_raw($value);
    }

    /**
     * Never log secrets or encrypted payloads.
     */
    public static function redact_for_log(string $message): string
    {
        // Remove anything that looks like a long base64 secret or encData
        $message = preg_replace('/[A-Za-z0-9+\/=]{40,}/', '[REDACTED]', $message) ?? $message;
        return $message;
    }
}
