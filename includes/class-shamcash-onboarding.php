<?php
/**
 * Onboarding checklist shown after activation.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Onboarding
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
        add_action('admin_notices', [$this, 'render_checklist']);
        add_action('wp_ajax_shamcash_dismiss_onboarding', [$this, 'ajax_dismiss']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Dashboard + WooCommerce screens only (L7).
        $allowed = (
            $hook === 'index.php'
            || strpos($hook, 'woocommerce') !== false
            || strpos($hook, 'wc-') !== false
        );
        if (!$allowed) {
            return;
        }

        wp_enqueue_style(
            'shamcash-onboarding',
            SHAMCASH_PAYMENTS_URL . 'assets/css/onboarding.css',
            [],
            SHAMCASH_PAYMENTS_VERSION
        );

        wp_enqueue_script(
            'shamcash-onboarding',
            SHAMCASH_PAYMENTS_URL . 'assets/js/onboarding.js',
            ['jquery'],
            SHAMCASH_PAYMENTS_VERSION,
            true
        );

        wp_localize_script('shamcash-onboarding', 'shamcashOnboarding', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('shamcash_dismiss_onboarding'),
        ]);
    }

    private function should_show(): bool
    {
        if (!current_user_can('manage_woocommerce')) {
            return false;
        }

        if (!$this->is_allowed_screen()) {
            return false;
        }

        if (get_user_meta(get_current_user_id(), 'shamcash_onboarding_dismissed', true)) {
            return false;
        }

        if ($this->is_fully_configured()) {
            return false;
        }

        return true;
    }

    /**
     * Checklist + assets only on Dashboard and WooCommerce screens (L6 / N7).
     */
    private function is_allowed_screen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen || !isset($screen->id)) {
            return false;
        }

        $id = (string) $screen->id;

        return $id === 'dashboard'
            || str_contains($id, 'woocommerce')
            || str_contains($id, 'wc-');
    }

    private function is_fully_configured(): bool
    {
        $settings = ShamCash_Client_Factory::settings();
        $agent    = trim((string) ($settings['agent_key'] ?? ''));
        $secret   = trim((string) ($settings['secret_key'] ?? ''));
        $base     = trim((string) ($settings['base_url'] ?? ''));
        $enabled  = ($settings['enabled'] ?? 'no') === 'yes';

        return $agent !== '' && $secret !== '' && $base !== '' && $enabled;
    }

    public function render_checklist(): void
    {
        if (!$this->should_show()) {
            return;
        }

        $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=shamcash');
        $settings     = ShamCash_Client_Factory::settings();

        $agent   = trim((string) ($settings['agent_key'] ?? '')) !== '';
        $secret  = trim((string) ($settings['secret_key'] ?? '')) !== '';
        $base    = trim((string) ($settings['base_url'] ?? '')) !== '';
        $enabled = ($settings['enabled'] ?? 'no') === 'yes';

        $is_ar = str_starts_with(determine_locale(), 'ar');
        $dir   = $is_ar ? 'rtl' : 'ltr';

        $steps = [
            ['label' => __('Enter your Agent Key', 'shamcash-payments'), 'done' => $agent],
            ['label' => __('Enter your Secret Key', 'shamcash-payments'), 'done' => $secret],
            ['label' => __('Confirm the Base URL', 'shamcash-payments'), 'done' => $base],
            ['label' => __('Enable the payment gateway', 'shamcash-payments'), 'done' => $enabled],
            ['label' => __('Save settings', 'shamcash-payments'), 'done' => $agent && $secret && $base && $enabled],
        ];
        ?>
        <div class="notice shamcash-onboarding-notice" dir="<?php echo esc_attr($dir); ?>" id="shamcash-onboarding">
            <div class="shamcash-onboarding-header">
                <strong><?php esc_html_e('ShamCash Setup Checklist', 'shamcash-payments'); ?></strong>
                <button type="button" class="shamcash-dismiss notice-dismiss" aria-label="<?php esc_attr_e('Dismiss this checklist', 'shamcash-payments'); ?>">
                    <span class="screen-reader-text"><?php esc_html_e('Dismiss this checklist', 'shamcash-payments'); ?></span>
                </button>
            </div>

            <p class="shamcash-onboarding-intro">
                <?php esc_html_e('Welcome! Complete the steps below to start accepting ShamCash payments:', 'shamcash-payments'); ?>
            </p>

            <ul class="shamcash-checklist">
                <?php foreach ($steps as $step) : ?>
                    <li class="<?php echo $step['done'] ? 'is-done' : 'is-pending'; ?>">
                        <span class="dashicons <?php echo $step['done'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
                        <span class="step-label"><?php echo esc_html($step['label']); ?></span>
                        <span class="step-status">
                            <?php echo $step['done'] ? esc_html__('Done', 'shamcash-payments') : esc_html__('Pending', 'shamcash-payments'); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="shamcash-onboarding-actions">
                <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary">
                    <?php esc_html_e('Go to ShamCash Settings', 'shamcash-payments'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function ajax_dismiss(): void
    {
        check_ajax_referer('shamcash_dismiss_onboarding', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        update_user_meta(get_current_user_id(), 'shamcash_onboarding_dismissed', 1);
        wp_send_json_success();
    }
}
