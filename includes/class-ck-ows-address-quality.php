<?php
/**
 * Address quality checks module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Address_Quality {
	private static ?CK_OWS_Address_Quality $instance = null;

	public static function instance(): CK_OWS_Address_Quality {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 8.
	}
}
