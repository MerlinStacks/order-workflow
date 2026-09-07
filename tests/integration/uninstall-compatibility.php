<?php
/**
 * Uninstall cleanup checks against the active WooCommerce order data store.
 *
 * Run with: wp eval-file tests/integration/uninstall-compatibility.php
 */

declare(strict_types=1);

$failures      = array();
$order_id      = 0;
$attachment_id = 0;
$cron_hook     = 'ck_ows_tracking_refresh_order';

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

try {
	$order = wc_create_order();
	$assert( $order instanceof WC_Order, 'WooCommerce could not create the uninstall test order.' );

	if ( $order instanceof WC_Order ) {
		$order_id = $order->get_id();
		$order->update_meta_data( '_ck_ows_uninstall_test', 'remove-me' );
		$order->save();
	}

	update_option( 'ck_ows_settings', array( 'keep_data_on_uninstall' => 'no' ) );
	update_option( 'ck_ows_last_tracking_number_test', array( 'ok' => true ) );
	update_option( 'ck_ows_last_artwork_webhook_test', array( 'ok' => true ) );
	set_transient( 'ck_ows_uninstall_test', 'remove-me', HOUR_IN_SECONDS );
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, $cron_hook );

	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => 'CK OWS uninstall test',
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
		)
	);
	if ( ! is_wp_error( $attachment_id ) ) {
		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, '_ck_ows_artwork_owned', '1' );
	} else {
		$attachment_id = 0;
		$failures[]    = 'Could not create the uninstall test attachment.';
	}

	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'ck-order-workflow-suite/ck-order-workflow-suite.php' );
	}
	require dirname( __DIR__, 2 ) . '/uninstall.php';

	$assert( false === get_option( 'ck_ows_last_tracking_number_test', false ), 'Tracking diagnostic option was not removed.' );
	$assert( false === get_option( 'ck_ows_last_artwork_webhook_test', false ), 'Artwork diagnostic option was not removed.' );
	$assert( false === get_transient( 'ck_ows_uninstall_test' ), 'Plugin transient was not removed.' );
	$assert( false === wp_next_scheduled( $cron_hook ), 'Plugin cron event was not removed.' );
	if ( $attachment_id > 0 ) {
		$assert( null === get_post( $attachment_id ), 'Plugin-owned artwork attachment was not removed.' );
	}

	global $wpdb;
	$hpos_enabled = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( $hpos_enabled ) {
		$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
		$stored_meta     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$hpos_meta_table} WHERE meta_key = %s AND order_id = %d",
				'_ck_ows_uninstall_test',
				$order_id
			)
		);
	} else {
		$stored_meta = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id = %d",
				'_ck_ows_uninstall_test',
				$order_id
			)
		);
	}
	$assert( null === $stored_meta, 'Plugin order metadata was not removed from the active order store.' );
} catch ( Throwable $throwable ) {
	$failures[] = 'Uninstall compatibility threw: ' . $throwable->getMessage();
} finally {
	if ( $order_id > 0 ) {
		$cleanup_order = wc_get_order( $order_id );
		if ( $cleanup_order instanceof WC_Order ) {
			$cleanup_order->delete( true );
		}
	}
	if ( $attachment_id > 0 && null !== get_post( $attachment_id ) ) {
		wp_delete_attachment( $attachment_id, true );
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '[FAIL] ' . $failure . "\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "Uninstall compatibility checks passed.\n" );
exit( 0 );
