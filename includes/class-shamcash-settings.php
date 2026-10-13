<?php
/**
 * Plugin settings helpers (WooCommerce gateway option is the single store).
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Settings
{
    private static ?self $instance = null;

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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . SHAMCASH_PAYMENTS_BASENAME, [$this, 'plugin_links']);
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'woocommerce') === false && strpos($hook, 'wc-settings') === false) {
            return;
        }

        wp_enqueue_style(
            'shamcash-admin',
            SHAMCASH_PAYMENTS_URL . 'assets/css/admin.css',
            [],
            SHAMCASH_PAYMENTS_VERSION
        );
    }

    /**
     * @param array<string> $links
     * @return array<string>
     */
    public function plugin_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=shamcash')),
            esc_html__('Settings', 'shamcash-payments')
        );

        array_unshift($links, $settings_link);
        return $links;
    }

    public static function get(string $key, mixed $default = ''): mixed
    {
        $settings = ShamCash_Client_Factory::settings();
        return $settings[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(array $data): void
    {
        $current = ShamCash_Client_Factory::settings();
        $merged  = array_merge($current, $data);
        update_option('woocommerce_shamcash_settings', $merged, false);
        delete_option('shamcash_payments_settings');
    }
}
