<?php
/**
 * Core plugin bootstrap.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class CK_OWS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var CK_OWS_Plugin|null
	 */
	private static ?CK_OWS_Plugin $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return CK_OWS_Plugin
	 */
	public static function instance(): CK_OWS_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 99 );
		$this->boot_modules();
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'ck-ows-account-ui',
			CK_OWS_URL . 'assets/css/account-ui.css',
			array(),
			CK_OWS_VERSION
		);

		$nav_inline_css = '
		.woocommerce-account .woocommerce .woocommerce-MyAccount-navigation,
		.woocommerce-account #customer_login ~ .woocommerce .woocommerce-MyAccount-navigation {
			float: none !important;
			width: 100% !important;
			max-width: 100% !important;
			margin: 0 0 18px !important;
		}
		.woocommerce-account .woocommerce .woocommerce-MyAccount-navigation ul {
			display: flex !important;
			flex-direction: row !important;
			flex-wrap: wrap !important;
			gap: 10px !important;
			padding: 14px 0 !important;
			margin: 0 !important;
		}
		.woocommerce-account .woocommerce .woocommerce-MyAccount-navigation li {
			display: inline-flex !important;
			float: none !important;
			width: auto !important;
			margin: 0 !important;
		}
		.woocommerce-account .woocommerce .woocommerce-MyAccount-navigation li a {
			display: inline-flex !important;
			width: auto !important;
			padding: 8px 18px !important;
			border-radius: 999px !important;
		}
		.woocommerce-account .woocommerce .woocommerce-MyAccount-content {
			float: none !important;
			width: 100% !important;
		}
		';

		wp_add_inline_style( 'ck-ows-account-ui', $nav_inline_css );
	}

	/**
	 * Load required class files.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		require_once CK_OWS_PATH . 'includes/class-ck-ows-statuses.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-admin-order-actions.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-customer-shipping-edit.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-account-invoices.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-registration-guard.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-shortcodes.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-order-timeline.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-account-order-cards.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-address-quality.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-account-security.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-artwork-proof.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-tracking.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-settings.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-events.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-helpers.php';
	}

	/**
	 * Initialize all modules.
	 *
	 * @return void
	 */
	private function boot_modules(): void {
		CK_OWS_Statuses::instance();
		CK_OWS_Admin_Order_Actions::instance();
		CK_OWS_Customer_Shipping_Edit::instance();
		CK_OWS_Account_Invoices::instance();
		CK_OWS_Registration_Guard::instance();
		CK_OWS_Shortcodes::instance();
		CK_OWS_Order_Timeline::instance();
		CK_OWS_Account_Order_Cards::instance();
		CK_OWS_Address_Quality::instance();
		CK_OWS_Account_Security::instance();
		CK_OWS_Artwork_Proof::instance();
		CK_OWS_Tracking::instance();
		CK_OWS_Settings::instance();
		CK_OWS_Events::instance();
		CK_OWS_Helpers::instance();
	}
}
