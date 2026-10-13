<?php
/**
 * Extra order-related helpers (admin order metabox, status checks, etc.).
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Order_Handler
{
    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'display_bill_info'], 10, 1);
    }

    public static function add_meta_box(): void
    {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'shamcash_payment_info',
            __('ShamCash Payment', 'shamcash-payments'),
            [self::class, 'render_meta_box'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * @param WP_Post|WC_Order $post_or_order
     */
    public static function render_meta_box($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'shamcash-payments') . '</p>';
            return;
        }

        $bill_no     = $order->get_meta('_shamcash_bill_no');
        $payment_url = $order->get_meta('_shamcash_payment_url');
        $status_id   = $order->get_meta('_shamcash_status_id');
        $last_status = $order->get_meta('_shamcash_last_status');

        if (!$bill_no) {
            echo '<p>' . esc_html__('No ShamCash bill associated with this order.', 'shamcash-payments') . '</p>';
            return;
        }

        echo '<p><strong>' . esc_html__('Bill No', 'shamcash-payments') . ':</strong><br><code>' . esc_html($bill_no) . '</code></p>';

        if ($status_id !== '') {
            echo '<p><strong>' . esc_html__('Initial Status ID', 'shamcash-payments') . ':</strong> ' . esc_html($status_id) . '</p>';
        }

        if ($last_status !== '') {
            echo '<p><strong>' . esc_html__('Last Webhook Status', 'shamcash-payments') . ':</strong> ' . esc_html($last_status) . '</p>';
        }

        if ($payment_url && !$order->is_paid()) {
            echo '<p><a class="button" href="' . esc_url($payment_url) . '" target="_blank" rel="noopener">' .
                esc_html__('Open Payment Page', 'shamcash-payments') .
                '</a></p>';
        }
    }

    public static function display_bill_info(\WC_Order $order): void
    {
        $bill_no = $order->get_meta('_shamcash_bill_no');
        if (!$bill_no) {
            return;
        }

        echo '<p><strong>' . esc_html__('ShamCash Bill', 'shamcash-payments') . ':</strong> ' .
            esc_html($bill_no) . '</p>';
    }
}

// Auto-init when file is loaded
ShamCash_Order_Handler::init();
