<?php
/**
 * Helper utilities.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Helpers {
	private static ?CK_OWS_Helpers $instance = null;

	public static function instance(): CK_OWS_Helpers {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Shared helpers are added as needed.
	}
}
