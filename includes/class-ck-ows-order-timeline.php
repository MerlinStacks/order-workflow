<?php
/**
 * Order timeline module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Order_Timeline {
	private static ?CK_OWS_Order_Timeline $instance = null;

	public static function instance(): CK_OWS_Order_Timeline {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 7.
	}
}
