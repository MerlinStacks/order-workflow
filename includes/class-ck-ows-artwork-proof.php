<?php
/**
 * Artwork proof workflow module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Artwork_Proof {
	private static ?CK_OWS_Artwork_Proof $instance = null;

	public static function instance(): CK_OWS_Artwork_Proof {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 6.
	}
}
