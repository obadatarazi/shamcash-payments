<?php
/**
 * Uninstall cleanup for ShamCash Payments.
 *
 * @package ShamCash_Payments
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_shamcash_settings');
delete_option('shamcash_payments_settings');

// Remove per-user dismissal flags.
global $wpdb;
if (isset($wpdb->usermeta)) {
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('shamcash_onboarding_dismissed', 'shamcash_setup_notice_dismissed')"
    );
}

// Clear any leftover webhook locks.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('shamcash_wh_lock_') . '%'
    )
);
