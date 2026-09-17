<?php
/**
 * Real WordPress and WooCommerce compatibility checks.
 *
 * Run with: wp eval-file tests/integration/woocommerce-compatibility.php
 */

// Do not declare strict_types here: WP-CLI eval-file prepends an evaluation wrapper.

$failures = array();
$order_id = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'WooCommerce' ), 'WooCommerce is not active.' );
$assert( defined( 'WC_VERSION' ), 'WC_VERSION is unavailable.' );
$assert( class_exists( 'CK_OWS_Plugin' ), 'The plugin bootstrap did not load.' );
$assert( class_exists( 'CK_OWS_Statuses' ), 'The custom status module did not load.' );
$assert( defined( 'CK_OWS_VERSION' ) && '0.1.14' === CK_OWS_VERSION, 'The runtime plugin version is not 0.1.14.' );

if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' ) ) {
	$statuses = wc_get_order_statuses();
	foreach ( array( 'wc-awaiting-artwork', 'wc-in-production', 'wc-in-dispatch' ) as $status ) {
		$assert( isset( $statuses[ $status ] ), sprintf( 'Custom order status %s is unavailable.', $status ) );
	}

	$hpos_option  = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	$hpos_enabled = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$assert( $hpos_option === $hpos_enabled, 'The active order data store does not match the HPOS setting.' );

	try {
		$order = wc_create_order();
		$assert( $order instanceof WC_Order, 'WooCommerce could not create a test order.' );

		if ( $order instanceof WC_Order ) {
			$order_id = $order->get_id();
			$order->set_billing_email( 'compatibility@example.com' );
			$order->update_meta_data( '_ck_ows_compatibility_test', 'stored' );
			$order->save();

			$reloaded = wc_get_order( $order_id );
			$assert( $reloaded instanceof WC_Order, 'wc_get_order() could not reload the test order.' );
			if ( $reloaded instanceof WC_Order ) {
				$assert( 'stored' === $reloaded->get_meta( '_ck_ows_compatibility_test' ), 'Order CRUD did not persist plugin metadata.' );
			}

			foreach ( array( 'awaiting-artwork', 'in-production', 'in-dispatch' ) as $status ) {
				if ( 'in-production' === $status ) {
					$order->update_meta_data( CK_OWS_Artwork_Proof::META_APPROVAL_STATE, CK_OWS_Artwork_Proof::STATE_APPROVED );
					$order->save_meta_data();
				}

				$order->update_status( $status );
				$order = wc_get_order( $order_id );
				$assert( $order instanceof WC_Order && $status === $order->get_status(), sprintf( 'Order transition to %s failed.', $status ) );
			}

			$matches = wc_get_orders(
				array(
					'include' => array( $order_id ),
					'status'  => array( 'in-dispatch' ),
					'limit'   => 1,
					'return'  => 'ids',
				)
			);
			$assert( in_array( $order_id, array_map( 'intval', $matches ), true ), 'wc_get_orders() could not query the custom status.' );
		}
	} catch ( Throwable $throwable ) {
		$failures[] = 'Order CRUD compatibility threw: ' . $throwable->getMessage();
	} finally {
		if ( $order_id > 0 ) {
			$cleanup_order = wc_get_order( $order_id );
			if ( $cleanup_order instanceof WC_Order ) {
				$cleanup_order->delete( true );
			}
		}
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '[FAIL] ' . $failure . "\n" );
		fwrite( STDERR, '::error title=WooCommerce compatibility::' . str_replace( array( "\r", "\n" ), ' ', $failure ) . "\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, sprintf( "WooCommerce %s compatibility checks passed.\n", WC_VERSION ) );
exit( 0 );
