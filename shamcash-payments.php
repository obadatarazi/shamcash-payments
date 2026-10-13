<?php
/**
 * Plugin Name:       ShamCash Payments
 * Plugin URI:        https://sy.linkedin.com/in/obada-tarazi
 * Description:       Accept ShamCash payments in WooCommerce. Create bills, process refunds, handle encrypted webhooks, and manage transactions with a clean admin experience. English and Arabic translations included.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Obada Altarazi
 * Author URI:        https://sy.linkedin.com/in/obada-tarazi
 * Text Domain:       shamcash-payments
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SHAMCASH_PAYMENTS_VERSION', '1.0.0');
define('SHAMCASH_PAYMENTS_FILE', __FILE__);
define('SHAMCASH_PAYMENTS_PATH', plugin_dir_path(__FILE__));
define('SHAMCASH_PAYMENTS_URL', plugin_dir_url(__FILE__));
define('SHAMCASH_PAYMENTS_BASENAME', plugin_basename(__FILE__));

/**
 * Autoload the bundled ShamCash SDK (no Composer required on the site for the SDK itself).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'OkToCode\\ShamCash\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = SHAMCASH_PAYMENTS_PATH . 'lib/sham-cash-sdk/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

/**
 * Declare Blocks/HPOS compatibility early — before_woocommerce_init has already
 * fired if we wait until plugins_loaded priority 20.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            SHAMCASH_PAYMENTS_FILE,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            SHAMCASH_PAYMENTS_FILE,
            true
        );
    }
});

/**
 * Register block checkout payment method when WooCommerce Blocks loads.
 * Must be hooked at file load time so we do not miss woocommerce_blocks_loaded.
 */
add_action('woocommerce_blocks_loaded', static function (): void {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }

    require_once SHAMCASH_PAYMENTS_PATH . 'includes/blocks/class-shamcash-blocks.php';
    ShamCash_Blocks::init();
});

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'shamcash-payments',
        false,
        dirname(SHAMCASH_PAYMENTS_BASENAME) . '/languages'
    );

    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-security.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-plugin.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-settings.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-logger.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-client-factory.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-webhook.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-mailer.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-onboarding.php';
    require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-connection-tester.php';

    ShamCash_Plugin::instance()->init();
    ShamCash_Onboarding::instance()->init();
    ShamCash_Connection_Tester::instance()->init();

    if (class_exists('WooCommerce')) {
        require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-gateway.php';
        require_once SHAMCASH_PAYMENTS_PATH . 'includes/class-shamcash-order-handler.php';

        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = 'ShamCash_Gateway';
            return $gateways;
        });
    }
}, 10);

/**
 * Activation
 */
register_activation_hook(__FILE__, static function (): void {
    if (get_current_user_id()) {
        delete_user_meta(get_current_user_id(), 'shamcash_onboarding_dismissed');
    }

    // Migrate legacy option into the WooCommerce gateway store once.
    $legacy = get_option('shamcash_payments_settings', false);
    $gateway = get_option('woocommerce_shamcash_settings', false);
    if (is_array($legacy) && (!is_array($gateway) || ($gateway['agent_key'] ?? '') === '')) {
        $merged = is_array($gateway) ? array_merge($gateway, $legacy) : $legacy;
        update_option('woocommerce_shamcash_settings', $merged, false);
    }
    if (false !== $legacy) {
        delete_option('shamcash_payments_settings');
    }
});

/**
 * Deactivation
 */
register_deactivation_hook(__FILE__, static function (): void {
    if (get_current_user_id()) {
        delete_user_meta(get_current_user_id(), 'shamcash_onboarding_dismissed');
    }
});
