<?php
/**
 * Plugin Name: CK WooCommerce Order Workflow Suite
 * Plugin URI:  https://example.com
 * Description: Custom order workflow, customer account enhancements, artwork approvals, and tracking tools for WooCommerce.
 * Version:     0.1.14
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Author:      CK
 * License:     GPL-2.0-or-later
 * Text Domain: ck-order-workflow-suite
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

define( 'CK_OWS_VERSION', '0.1.14' );
define( 'CK_OWS_FILE', __FILE__ );
define( 'CK_OWS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CK_OWS_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook(
	__FILE__,
	static function (): void {
		add_rewrite_endpoint( 'invoices', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'security', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'email-preferences', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		foreach ( array( 'ck_ows_tracking_sync_event', 'ck_ows_tracking_sync_continuation', 'ck_ows_tracking_refresh_order', 'ck_ows_tracking_event_retry', 'ck_ows_artwork_event_retry', 'ck_ows_action_scheduler_cleanup', 'ck_ows_action_scheduler_cleanup_continuation' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
		delete_option( 'ck_ows_tracking_sync_lock' );
		delete_option( 'ck_ows_tracking_sync_cursor' );
		delete_transient( 'ck_ows_tracking_schedule_check' );
		delete_transient( 'ck_ows_action_scheduler_cleanup_schedule_check' );
		delete_transient( 'ck_ows_schedule_health_check' );
		delete_option( 'ck_ows_action_scheduler_cleanup_lock' );
		flush_rewrite_rules();
	}
);

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
