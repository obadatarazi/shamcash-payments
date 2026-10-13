<?php
/**
 * Logger – writes to WooCommerce logs when available.
 * Secrets and long base64 strings are redacted.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Logger
{
    public static function log(string $message, string $level = 'info'): void
    {
        $settings = class_exists('ShamCash_Client_Factory')
            ? ShamCash_Client_Factory::settings()
            : get_option('woocommerce_shamcash_settings', []);
        $debug    = ($settings['debug'] ?? 'no') === 'yes';

        if (!$debug && !in_array($level, ['error', 'critical', 'warning'], true)) {
            return;
        }

        $message = '[ShamCash] ' . ShamCash_Security::redact_for_log($message);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, ['source' => 'shamcash-payments']);
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
    }

    public static function debug(string $message): void
    {
        self::log($message, 'debug');
    }

    public static function info(string $message): void
    {
        self::log($message, 'info');
    }

    public static function warning(string $message): void
    {
        self::log($message, 'warning');
    }

    public static function error(string $message): void
    {
        self::log($message, 'error');
    }
}
