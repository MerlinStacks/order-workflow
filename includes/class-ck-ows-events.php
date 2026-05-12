<?php
/**
 * Event dispatcher module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Events {
	private static ?CK_OWS_Events $instance = null;

	public static function instance(): CK_OWS_Events {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Implement in Milestone 10.
	}
}
