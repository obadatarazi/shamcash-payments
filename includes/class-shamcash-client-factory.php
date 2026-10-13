<?php
/**
 * Creates a configured OkToCode\ShamCash\Client instance from plugin settings.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use OkToCode\ShamCash\Client;

final class ShamCash_Client_Factory
{
    /**
     * Build a client. Returns null if credentials are incomplete.
     */
    public static function make(?array $settings = null): ?Client
    {
        $settings ??= self::settings();

        $agentKey  = trim((string) ($settings['agent_key'] ?? ''));
        $secretKey = trim((string) ($settings['secret_key'] ?? ''));
        $baseUrl   = trim((string) ($settings['base_url'] ?? ''));

        if ($agentKey === '' || $secretKey === '' || $baseUrl === '') {
            return null;
        }

        $callbackUrl = self::resolve_callback_url($settings);
        $redirectUrl = self::resolve_redirect_url($settings);

        try {
            return new Client(
                agentKey: $agentKey,
                secretKey: $secretKey,
                baseUrl: $baseUrl,
                callbackUrl: $callbackUrl,
                redirectUrl: $redirectUrl,
            );
        } catch (\Throwable $e) {
            ShamCash_Logger::error('Failed to create ShamCash client: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Single settings store: WooCommerce gateway option.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $settings = get_option('woocommerce_shamcash_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        // One-time fallback for sites that still have the legacy option.
        if (($settings['agent_key'] ?? '') === '' && ($settings['secret_key'] ?? '') === '') {
            $legacy = get_option('shamcash_payments_settings', []);
            if (is_array($legacy) && $legacy !== []) {
                $settings = array_merge($settings, $legacy);
                update_option('woocommerce_shamcash_settings', $settings, false);
                delete_option('shamcash_payments_settings');
            }
        }

        return $settings;
    }

    public static function resolve_callback_url(array $settings): string
    {
        $custom = trim((string) ($settings['callback_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return rest_url('shamcash/v1/webhook');
    }

    public static function resolve_redirect_url(array $settings): string
    {
        $custom = trim((string) ($settings['redirect_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        return home_url('/');
    }
}
