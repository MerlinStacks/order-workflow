<?php
/**
 * Uninstall cleanup.
 *
 * @package CK_Order_Workflow_Suite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'ck_ows_settings', array() );
$settings = is_array( $settings ) ? $settings : array();

foreach ( array( 'ck_ows_tracking_sync_event', 'ck_ows_tracking_sync_continuation', 'ck_ows_tracking_refresh_order', 'ck_ows_tracking_event_retry', 'ck_ows_artwork_event_retry', 'ck_ows_action_scheduler_cleanup', 'ck_ows_action_scheduler_cleanup_continuation' ) as $hook ) {
    wp_clear_scheduled_hook( $hook );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( $hook );
    }
}

if ( 'yes' === (string) ( $settings['keep_data_on_uninstall'] ?? 'no' ) ) {
    return;
}

delete_option( 'ck_ows_settings' );
delete_option( 'ckrg_block_log' );
delete_option( 'ck_ows_audit_log' );
delete_option( 'ck_ows_last_connection_tests' );
delete_option( 'ck_ows_last_webhook_delivery' );
delete_option( 'ck_ows_last_tracking_number_test' );
delete_option( 'ck_ows_last_artwork_webhook_test' );
delete_option( 'ck_ows_tracking_event_dead_letters' );
delete_option( 'ck_ows_artwork_event_dead_letters' );
delete_option( 'ck_ows_last_artwork_webhook_delivery' );
delete_option( 'ck_ows_tracking_sync_lock' );
delete_option( 'ck_ows_tracking_sync_cursor' );
delete_transient( 'ck_ows_tracking_schedule_check' );
delete_transient( 'ck_ows_action_scheduler_cleanup_schedule_check' );
delete_transient( 'ck_ows_schedule_health_check' );
delete_option( 'ck_ows_action_scheduler_cleanup_lock' );

$proof_attachments = get_posts(
    array(
        'post_type'      => 'attachment',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => '_ck_ows_artwork_owned',
        'meta_value'     => '1',
    )
);
foreach ( $proof_attachments as $attachment_id ) {
    wp_delete_attachment( (int) $attachment_id, true );
}

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_ck_ows_last_login_ts', '_ck_ows_last_password_change_ts')" );
$transient_prefix = $wpdb->esc_like( '_transient_ck_ows_' ) . '%';
$transient_timeout_prefix = $wpdb->esc_like( '_transient_timeout_ck_ows_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $transient_prefix, $transient_timeout_prefix ) );

if ( isset( $wpdb->postmeta ) ) {
    $meta_prefix = $wpdb->esc_like( '_ck_ows_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_prefix ) );
}

$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta_table ) ) === $hpos_meta_table ) {
    $meta_prefix = $wpdb->esc_like( '_ck_ows_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE %s", $meta_prefix ) );
}
