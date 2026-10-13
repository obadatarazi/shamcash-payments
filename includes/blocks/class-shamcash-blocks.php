<?php
/**
 * WooCommerce Blocks (block checkout) payment method integration.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class ShamCash_Blocks_Payment_Method extends AbstractPaymentMethodType
{
    protected $name = 'shamcash';

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_shamcash_settings', []);
    }

    public function is_active(): bool
    {
        return ($this->get_setting('enabled') === 'yes');
    }

    /**
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'shamcash-blocks-checkout';

        wp_register_script(
            $handle,
            SHAMCASH_PAYMENTS_URL . 'assets/js/blocks-checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            SHAMCASH_PAYMENTS_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'shamcash-payments', SHAMCASH_PAYMENTS_PATH . 'languages');
        }

        return [$handle];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        return [
            'title'       => $this->get_setting('title', __('ShamCash', 'shamcash-payments')),
            'description' => $this->get_setting('description', __('Pay securely with ShamCash e-wallet.', 'shamcash-payments')),
            'supports'    => ['products', 'refunds'],
        ];
    }
}

/**
 * Registrar for the blocks payment method + cart/checkout compatibility.
 */
final class ShamCash_Blocks
{
    public static function init(): void
    {
        // Compatibility is declared from shamcash-payments.php on before_woocommerce_init.
        add_action('woocommerce_blocks_payment_method_type_registration', [self::class, 'register']);
    }

    /**
     * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry
     */
    public static function register($registry): void
    {
        if (!class_exists(AbstractPaymentMethodType::class)) {
            return;
        }

        $registry->register(new ShamCash_Blocks_Payment_Method());
    }
}
