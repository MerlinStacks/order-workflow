<?php
/**
 * Real WordPress and WooCommerce gift wrapping integration checks.
 *
 * Run on a disposable test site with both plugins active:
 * wp eval-file tests/integration/gift-wrap.php
 *
 * Exercises classic checkout item/fee creation, not payment or Store API checkout.
 */

declare(strict_types=1);

$failures = array();
$assertions = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures, &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$close = static function ( $expected, $actual ): bool {
	return abs( (float) $expected - (float) $actual ) < 0.00001;
};

$ready = class_exists( 'WooCommerce' ) && class_exists( 'CK_OWS_Settings' ) && class_exists( 'CK_OWS_Gift_Wrap' );
$assert( $ready, 'WooCommerce and the gift wrap module must be active.' );

if ( $ready ) {
	$order_ids = array();
	$product_ids = array();
	$term_id = 0;
	$rate_id = 0;
	$options = array();
	$missing = new stdClass();
	$original_post = $_POST;
	$original_cart = WC()->cart;
	$original_customer = WC()->customer;
	$original_session = WC()->session;
	$cache = new ReflectionProperty( CK_OWS_Settings::class, 'settings_cache' );
	$cache->setAccessible( true );
	$original_cache = $cache->getValue();
	$set_option = static function ( string $key, $value ) use ( &$options, $missing ): void {
		if ( ! array_key_exists( $key, $options ) ) {
			$options[ $key ] = get_option( $key, $missing );
		}
		update_option( $key, $value );
	};

	try {
		$slug = 'ck-ows-gift-test-' . strtolower( wp_generate_password( 12, false, false ) );
		$term = wp_insert_term( $slug, 'product_tag', array( 'slug' => $slug ) );
		if ( is_wp_error( $term ) ) {
			throw new RuntimeException( $term->get_error_message() );
		}
		$term_id = (int) $term['term_id'];

		// Write the real option before accessing feature settings. Bootstrap may
		// already have populated the private cache; preserve and reset it explicitly.
		$settings = get_option( CK_OWS_Settings::OPTION_KEY, array() );
		$set_option( CK_OWS_Settings::OPTION_KEY, array_merge( (array) $settings, array(
			'gift_wrap_enabled' => 'yes',
			'gift_wrap_tag' => $slug,
			'gift_wrap_price' => '5.50',
		) ) );
		$cache->setValue( null, null );
		$state = strtoupper( $slug );
		foreach ( array(
			'woocommerce_calc_taxes' => 'yes',
			'woocommerce_tax_based_on' => 'billing',
			'woocommerce_default_country' => 'AU:' . $state,
			'woocommerce_store_postcode' => '2000',
			'woocommerce_store_city' => 'Sydney',
			'woocommerce_currency' => 'AUD',
			'woocommerce_price_num_decimals' => '2',
			'woocommerce_tax_round_at_subtotal' => 'no',
		) as $key => $value ) {
			$set_option( $key, $value );
		}

		// A unique state avoids existing state-specific rates. Fail explicitly if
		// site-wide wildcard rates still interfere, rather than altering those rates.
		$rate_id = (int) WC_Tax::_insert_tax_rate( array(
			'tax_rate_country' => 'AU',
			'tax_rate_state' => $state,
			'tax_rate' => '10.0000',
			'tax_rate_name' => $slug,
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 0,
			'tax_rate_order' => 0,
			'tax_rate_class' => '',
		) );
		if ( ! $rate_id ) {
			throw new RuntimeException( 'Could not insert the standard 10% tax rate.' );
		}
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		// Use an in-memory guest session: no init(), cookie or session-save hooks.
		WC()->session = new WC_Session_Handler();
		WC()->customer = new WC_Customer( 0, false );
		WC()->customer->set_billing_country( 'AU' );
		WC()->customer->set_billing_state( $state );
		WC()->customer->set_billing_postcode( '2000' );
		WC()->customer->set_billing_city( 'Sydney' );
		WC()->customer->set_is_vat_exempt( false );
		$rates = WC_Tax::get_rates( '', WC()->customer );
		if ( 1 !== count( $rates ) || ! isset( $rates[ $rate_id ] ) || ! $close( 10, $rates[ $rate_id ]['rate'] ) ) {
			throw new RuntimeException( 'Tax fixture did not resolve exclusively to the inserted 10% rate; use a clean test site.' );
		}

		$product = new WC_Product_Simple();
		$product->set_name( 'Gift wrap integration product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '20.00' );
		$product->set_virtual( true );
		$product->set_tax_status( 'taxable' );
		$product->set_tax_class( '' );
		$product->set_tag_ids( array( $term_id ) );
		$product_ids[] = $product->save();
		$wrap = CK_OWS_Gift_Wrap::instance();
		$assert( $wrap->eligible( $product->get_id() ), 'The real tagged simple product is eligible.' );
		$assert( false !== has_filter( 'woocommerce_add_cart_item_data', array( $wrap, 'add_cart_data' ) ), 'add_cart_data is registered with WooCommerce.' );

		WC()->cart = new WC_Cart();
		$cart = WC()->cart;
		$checkout = WC_Checkout::instance();
		$message = "Happy birthday, O'Connor!\nLove & hugs";
		foreach ( array( 'no', 'yes' ) as $inclusive ) {
			$mode = 'prices_include_tax=' . $inclusive;
			$set_option( 'woocommerce_prices_include_tax', $inclusive );
			$cart->empty_cart( false );
			$_POST = array(
				'ck_ows_gift_wrap' => 'yes',
				'ck_ows_gift_message' => wp_slash( "<b>Happy</b> birthday, O'Connor!\nLove & hugs" ),
				'ck_ows_gift_wrap_price' => '0.01',
			);
			// WC_Cart invokes add_cart_data itself; do not inject prebuilt gift data.
			$key = $cart->add_to_cart( $product->get_id(), 3 );
			if ( ! $key ) {
				throw new RuntimeException( $mode . ': real cart add_to_cart failed.' );
			}
			$line = $cart->get_cart_item( $key );
			$assert( 3 === (int) $line['quantity'], $mode . ': cart quantity is three.' );
			$assert( $close( 5.50, $line['ck_ows_gift_wrap']['price'] ?? -1 ), $mode . ': configured unit price overrides POST price.' );
			$assert( $message === ( $line['ck_ows_gift_wrap']['message'] ?? null ), $mode . ': cart message is sanitized and unslashed.' );

			for ( $pass = 1; $pass <= 2; ++$pass ) {
				$cart->calculate_totals();
				$fees = $cart->get_fees();
				$assert( 1 === count( $fees ), $mode . ': totals pass ' . $pass . ' has exactly one fee.' );
				$fee = $fees[ 'ck_ows_gift_wrap_' . $key ] ?? null;
				$assert( null !== $fee, $mode . ': gift fee identifies the cart line.' );
				if ( $fee ) {
					$assert( $fee->taxable && '' === $fee->tax_class, $mode . ': fee uses the standard taxable class.' );
					$assert( $close( 15, $fee->total ), $mode . ': fee net is 15.00.' );
					$assert( $close( 1.50, $fee->tax ), $mode . ': real fee tax is 1.50.' );
					$assert( $close( 16.50, $fee->total + $fee->tax ), $mode . ': fee gross is 3 × 5.50 = 16.50.' );
				}
			}

			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				throw new RuntimeException( $order->get_error_message() );
			}
			$order_ids[] = $order->get_id();
			$order->set_currency( 'AUD' );
			$order->set_prices_include_tax( 'yes' === $inclusive );
			$checkout->create_order_line_items( $order, $cart );
			$checkout->create_order_fee_lines( $order, $cart );
			$order->calculate_totals( false );
			$order->save();
			$reloaded = wc_get_order( $order->get_id() );
			$assert( $reloaded instanceof WC_Order, $mode . ': order reloads through WooCommerce CRUD.' );
			if ( ! $reloaded instanceof WC_Order ) {
				continue;
			}
			$items = array_values( $reloaded->get_items( 'line_item' ) );
			$assert( 1 === count( $items ), $mode . ': one product line is persisted.' );
			if ( isset( $items[0] ) ) {
				$item = $items[0];
				$assert( $product->get_id() === $item->get_product_id() && 3 === $item->get_quantity(), $mode . ': product ID and quantity survive checkout.' );
				$assert( $message === $item->get_meta( 'Gift tag message' ), $mode . ': gift message metadata is persisted.' );
				$assert( $close( 5.50, $item->get_meta( '_ck_ows_gift_wrap_unit_price_incl_tax' ) ), $mode . ': private unit-price metadata is persisted.' );
				$price = html_entity_decode( wp_strip_all_tags( wc_price( 5.50, array( 'currency' => 'AUD' ) ) ), ENT_QUOTES, 'UTF-8' );
				$expected = sprintf( __( 'Yes — %1$s per item (incl. tax); charged separately', 'ck-order-workflow-suite' ), $price );
				$assert( $expected === $item->get_meta( 'Gift wrapping' ), $mode . ': public gift wrapping metadata is persisted.' );
			}
			$order_fees = array_values( $reloaded->get_items( 'fee' ) );
			$assert( 1 === count( $order_fees ), $mode . ': one checkout fee line is persisted.' );
			if ( isset( $order_fees[0] ) ) {
				$fee_item = $order_fees[0];
				$assert( $close( 15, $fee_item->get_total() ) && $close( 1.50, $fee_item->get_total_tax() ), $mode . ': persisted fee net and tax are correct.' );
				$assert( $close( 16.50, (float) $fee_item->get_total() + (float) $fee_item->get_total_tax() ), $mode . ': persisted fee gross is 16.50.' );
			}
		}
	} catch ( Throwable $throwable ) {
		$failures[] = 'Gift wrap integration threw: ' . $throwable->getMessage();
	} finally {
		// Attempt every cleanup even if an individual CRUD deletion fails.
		$cleanup = static function ( callable $callback ) use ( &$failures ): void {
			try {
				$callback();
			} catch ( Throwable $throwable ) {
				$failures[] = 'Cleanup failed: ' . $throwable->getMessage();
			}
		};
		foreach ( $order_ids as $id ) {
			$cleanup( static function () use ( $id ): void {
				$order = wc_get_order( $id );
				if ( $order ) {
					$order->delete( true );
				}
			} );
		}
		foreach ( array_reverse( $product_ids ) as $id ) {
			$cleanup( static function () use ( $id ): void {
				$product = wc_get_product( $id );
				if ( $product ) {
					$product->delete( true );
				}
			} );
		}
		$cleanup( static function () use ( $rate_id ): void {
			if ( $rate_id ) {
				WC_Tax::_delete_tax_rate( $rate_id );
			}
		} );
		$cleanup( static function () use ( $term_id ): void {
			if ( $term_id ) {
				wp_delete_term( $term_id, 'product_tag' );
			}
		} );
		foreach ( $options as $key => $value ) {
			$cleanup( static function () use ( $key, $value, $missing ): void {
				if ( $missing === $value ) {
					delete_option( $key );
				} else {
					update_option( $key, $value );
				}
			} );
		}
		$cache->setValue( null, $original_cache );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$_POST = $original_post;
		WC()->cart = $original_cart;
		WC()->customer = $original_customer;
		WC()->session = $original_session;
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, '[FAIL] ' . $failure . "\n" );
		fwrite( STDERR, '::error title=Gift wrap integration::' . str_replace( array( "\r", "\n" ), ' ', $failure ) . "\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, sprintf( "WooCommerce %s gift wrap integration checks passed (%d assertions).\n", WC_VERSION, $assertions ) );
exit( 0 );
