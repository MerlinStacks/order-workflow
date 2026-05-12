<?php
/**
 * Registration guard module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Registration_Guard {
	private static ?CK_OWS_Registration_Guard $instance = null;

	public static function instance(): CK_OWS_Registration_Guard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 5.
	}
}
