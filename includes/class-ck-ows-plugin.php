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
		$is_account_page = function_exists( 'is_account_page' ) && is_account_page();
		$is_thankyou_page = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$is_flatsome     = function_exists( 'wp_get_theme' ) && 'flatsome' === strtolower( (string) wp_get_theme()->get_template() );
		$needs_popup_css = $is_account_page && $is_flatsome && function_exists( 'is_user_logged_in' ) && ! is_user_logged_in();

		if ( ! $is_account_page && ! $needs_popup_css && ! $is_thankyou_page ) {
			return;
		}

		wp_enqueue_style(
			'ck-ows-account-ui',
			CK_OWS_URL . 'assets/css/account-ui.css',
			array(),
			CK_OWS_VERSION
		);

		if ( $needs_popup_css ) {
			wp_enqueue_script(
				'ck-ows-auth-toggle',
				CK_OWS_URL . 'assets/js/auth-toggle.js',
				array(),
				CK_OWS_VERSION,
				true
			);
		}

		if ( $is_account_page ) {
			$logout_redirect_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
			$logout_url          = wp_logout_url( $logout_redirect_url );

			wp_enqueue_script(
				'ck-ows-account-logout-confirm',
				CK_OWS_URL . 'assets/js/account-logout-confirm.js',
				array(),
				CK_OWS_VERSION,
				true
			);

			wp_localize_script(
				'ck-ows-account-logout-confirm',
				'ckOwsLogoutConfirm',
				array(
					'title'   => __( 'Log out?', 'ck-order-workflow-suite' ),
					'message' => __( 'Are you sure you want to log out of your account?', 'ck-order-workflow-suite' ),
					'cancel'  => __( 'Cancel', 'ck-order-workflow-suite' ),
					'confirm' => __( 'Confirm and log out', 'ck-order-workflow-suite' ),
					'logoutUrl' => $logout_url,
				)
			);
		}

	}

	/**
	 * Load required class files.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		require_once CK_OWS_PATH . 'includes/class-ck-ows-base.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-utils.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-admin-helpers.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-tracking-helpers.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-audit.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-account-menu-helper.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-invoice-integration.php';
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
		require_once CK_OWS_PATH . 'includes/class-ck-ows-account-email-preferences.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-artwork-proof.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-artwork-events.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-tracking.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-tracking-email-events.php';
		require_once CK_OWS_PATH . 'includes/class-ck-ows-settings.php';
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
		CK_OWS_Account_Email_Preferences::instance();
		CK_OWS_Artwork_Proof::instance();
		CK_OWS_Artwork_Events::instance();
		CK_OWS_Tracking::instance();
		CK_OWS_Tracking_Email_Events::instance();
		CK_OWS_Settings::instance();
	}
}
