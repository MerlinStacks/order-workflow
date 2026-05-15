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

if ( 'yes' === (string) ( $settings['keep_data_on_uninstall'] ?? 'no' ) ) {
    return;
}

delete_option( 'ck_ows_settings' );
delete_option( 'ck_ows_reg_guard_log' );
delete_option( 'ck_ows_audit_log' );
delete_option( 'ck_ows_last_connection_tests' );
delete_option( 'ck_ows_last_webhook_delivery' );
delete_option( 'ck_ows_tracking_event_dead_letters' );

global $wpdb;

if ( isset( $wpdb->postmeta ) ) {
    $meta_prefix = '_ck_ows_%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_prefix ) );
}
