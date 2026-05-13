<?php
/**
 * Account menu helper utilities.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Menu_Helper {
	public static function insert_before_logout( array $items, string $endpoint, string $label ): array {
		$new_items = array();
		$inserted  = false;

		foreach ( $items as $key => $menu_label ) {
			if ( 'customer-logout' === $key ) {
				$new_items[ $endpoint ] = $label;
				$inserted = true;
			}

			$new_items[ $key ] = $menu_label;
		}

		if ( ! $inserted ) {
			$new_items[ $endpoint ] = $label;
		}

		return $new_items;
	}
}
