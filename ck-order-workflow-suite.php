<?php
/**
 * Plugin Name: CK WooCommerce Order Workflow Suite
 * Plugin URI:  https://example.com
 * Description: Custom order workflow, customer account enhancements, artwork approvals, and tracking tools for WooCommerce.
 * Version:     0.1.1
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author:      CK
 * License:     GPL-2.0-or-later
 * Text Domain: ck-order-workflow-suite
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

define( 'CK_OWS_VERSION', '0.1.1' );
define( 'CK_OWS_FILE', __FILE__ );
define( 'CK_OWS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CK_OWS_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				CK_OWS_FILE,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'CK WooCommerce Order Workflow Suite requires WooCommerce to be active.', 'ck-order-workflow-suite' );
					echo '</p></div>';
				}
			);

			return;
		}

		require_once CK_OWS_PATH . 'includes/class-ck-ows-plugin.php';
		CK_OWS_Plugin::instance();
	}
);
