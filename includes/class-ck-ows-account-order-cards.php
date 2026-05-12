<?php
/**
 * Account order cards module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Order_Cards {
	private static ?CK_OWS_Account_Order_Cards $instance = null;

	public static function instance(): CK_OWS_Account_Order_Cards {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 7.
	}
}
