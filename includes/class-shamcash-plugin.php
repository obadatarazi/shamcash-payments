<?php
/**
 * Main plugin bootstrap.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class ShamCash_Plugin
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
        if (is_admin()) {
            ShamCash_Settings::instance()->init();
        }

        ShamCash_Webhook::instance()->init();

        // Compatibility declarations live in shamcash-payments.php (before_woocommerce_init).

        add_action('admin_notices', [$this, 'maybe_show_setup_notice']);
        add_action('wp_ajax_shamcash_dismiss_setup_notice', [$this, 'ajax_dismiss_setup_notice']);
    }

    public function maybe_show_setup_notice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (get_user_meta(get_current_user_id(), 'shamcash_setup_notice_dismissed', true)) {
            return;
        }

        $settings = ShamCash_Client_Factory::settings();
        $agent    = $settings['agent_key'] ?? '';
        $secret   = $settings['secret_key'] ?? '';

        if ($agent !== '' && $secret !== '') {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && 'woocommerce_page_wc-settings' === $screen->id) {
            return;
        }

        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=shamcash');
        $nonce = wp_create_nonce('shamcash_dismiss_setup_notice');
        ?>
        <div class="notice notice-warning is-dismissible shamcash-setup-notice" data-nonce="<?php echo esc_attr($nonce); ?>">
            <p>
                <strong><?php esc_html_e('ShamCash Payments', 'shamcash-payments'); ?></strong>
                —
                <?php
                printf(
                    /* translators: %s: settings link HTML */
                    esc_html__('Please configure your ShamCash API credentials to start accepting payments. %s', 'shamcash-payments'),
                    '<a href="' . esc_url($url) . '">' . esc_html__('Go to settings', 'shamcash-payments') . '</a>'
                );
                ?>
            </p>
        </div>
        <script>
        (function () {
            document.addEventListener('click', function (e) {
                var notice = e.target.closest && e.target.closest('.shamcash-setup-notice');
                if (!notice || !e.target.classList.contains('notice-dismiss')) {
                    return;
                }
                var nonce = notice.getAttribute('data-nonce') || '';
                var body = new FormData();
                body.append('action', 'shamcash_dismiss_setup_notice');
                body.append('nonce', nonce);
                if (window.fetch) {
                    fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' });
                }
            }, true);
        })();
        </script>
        <?php
    }

    public function ajax_dismiss_setup_notice(): void
    {
        check_ajax_referer('shamcash_dismiss_setup_notice', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(null, 403);
        }
        update_user_meta(get_current_user_id(), 'shamcash_setup_notice_dismissed', 1);
        wp_send_json_success();
    }
}
