<?php
/**
 * Account security panel module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Security {
	private static ?CK_OWS_Account_Security $instance = null;

	public static function instance(): CK_OWS_Account_Security {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 8.
	}
}
