<?php
/**
 * Settings module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Settings {
	private static ?CK_OWS_Settings $instance = null;

	public static function instance(): CK_OWS_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestones 6 and 9.
	}
}
