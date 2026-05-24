<?php
/**
 * Address quality checks module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Address_Quality extends CK_OWS_Base {
	protected function __construct() {
		add_action( 'woocommerce_after_save_address_validation', array( $this, 'validate_quality' ), 10, 4 );
	}

	public function validate_quality( int $user_id, string $load_address, array $address, $customer = null ): void {
		unset( $user_id, $customer );

		$country  = strtoupper( (string) ( $address['country'] ?? '' ) );
		$city     = trim( (string) ( $address['city'] ?? '' ) );
		$postcode = strtoupper( trim( (string) ( $address['postcode'] ?? '' ) ) );

		if ( '' === $city ) {
			wc_add_notice( __( 'Please enter a suburb/city for your address.', 'ck-order-workflow-suite' ), 'error' );
		}

		if ( '' === $postcode ) {
			wc_add_notice( __( 'Please enter a postcode for your address.', 'ck-order-workflow-suite' ), 'error' );
			return;
		}

		if ( 'AU' === $country && ! preg_match( '/^\d{4}$/', $postcode ) ) {
			wc_add_notice( __( 'Australian postcodes must be exactly 4 digits.', 'ck-order-workflow-suite' ), 'error' );
			return;
		}

		if ( 'NZ' === $country && ! preg_match( '/^\d{4}$/', $postcode ) ) {
			wc_add_notice( __( 'New Zealand postcodes must be exactly 4 digits.', 'ck-order-workflow-suite' ), 'error' );
			return;
		}

		if ( strlen( preg_replace( '/\s+/', '', $postcode ) ) < 3 ) {
			wc_add_notice( __( 'Please check your postcode. It looks incomplete.', 'ck-order-workflow-suite' ), 'notice' );
		}

		if ( strlen( $city ) < 2 ) {
			/* translators: %s: address type label */
			wc_add_notice( sprintf( __( 'Please check your %s suburb/city. It looks too short.', 'ck-order-workflow-suite' ), esc_html( $load_address ) ), 'notice' );
		}
	}
}
