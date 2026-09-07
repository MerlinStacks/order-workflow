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
	private const SCHEDULE_HEALTH_CHECK_KEY = 'ck_ows_schedule_health_check';
	private const ACTION_SCHEDULER_CLEANUP_HOOK = 'ck_ows_action_scheduler_cleanup';
	private const ACTION_SCHEDULER_CLEANUP_CONTINUATION_HOOK = 'ck_ows_action_scheduler_cleanup_continuation';

	/**
	 * Singleton instance.
	 *
	 * @var CK_OWS_Plugin|null
	 */
	private static ?CK_OWS_Plugin $instance = null;
	private string $settings_page_hook = '';

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
		$this->register_autoloader();
		$this->register_hooks();
		CK_OWS_Statuses::instance();

		if ( is_admin() && ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) ) {
			$this->boot_admin_request_module();
		}
	}

	private function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 99 );
		add_action( 'init', array( $this, 'register_account_endpoints' ), 1 );
		add_action( 'init', array( $this, 'register_shortcodes' ), 1 );
		add_action( 'init', array( $this, 'maybe_ensure_tracking_schedule' ), 2 );
		add_action( 'wp', array( $this, 'boot_customer_modules' ), 1 );

		add_filter( 'cron_schedules', array( $this, 'register_tracking_interval_schedule' ) );
		add_action( 'ck_ows_tracking_sync_event', array( $this, 'sync_tracking_data' ) );
		add_action( 'ck_ows_tracking_sync_continuation', array( $this, 'continue_tracking_sync' ), 10, 3 );
		add_action( 'ck_ows_tracking_refresh_order', array( $this, 'refresh_tracking_order' ), 10, 3 );
		add_action( self::ACTION_SCHEDULER_CLEANUP_HOOK, array( $this, 'cleanup_action_scheduler_history' ) );
		add_action( self::ACTION_SCHEDULER_CLEANUP_CONTINUATION_HOOK, array( $this, 'cleanup_action_scheduler_history' ) );

		add_action( 'rest_api_init', array( $this, 'register_artwork_event_routes' ) );
		add_action( 'ck_ows_artwork_event_retry', array( $this, 'retry_artwork_event_delivery' ), 10, 1 );
		add_action( 'ck_ows_tracking_updated', array( $this, 'forward_tracking_event' ), 10, 2 );
		add_action( 'ck_ows_tracking_event_retry', array( $this, 'retry_tracking_event_delivery' ), 10, 1 );

		add_action( 'woocommerce_order_status_changed', array( $this, 'enforce_artwork_production_gate' ), 20, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'capture_order_stage_timestamp' ), 30, 4 );
		add_action( 'woocommerce_after_save_address_validation', array( $this, 'validate_address_quality' ), 10, 4 );

		add_action( 'wp_login', array( $this, 'track_account_login' ), 10, 2 );
		add_action( 'after_password_reset', array( $this, 'track_account_password_reset' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( $this, 'track_account_password_change' ), 20, 1 );

		add_action( 'woocommerce_register_form', array( $this, 'render_account_registration_fields' ), 9 );
		add_action( 'woocommerce_register_form', array( $this, 'inject_registration_guard_fields' ) );
		add_action( 'register_form', array( $this, 'inject_registration_guard_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_woocommerce_registration' ), 10, 4 );
		add_filter( 'registration_errors', array( $this, 'validate_wordpress_registration' ), 10, 3 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_account_registration_fields' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_account_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_items' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'filter_account_menu_items' ), 1000 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
			add_action( 'admin_init', array( $this, 'maybe_register_admin_settings' ), 1 );
			add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_admin_path' ), 1 );
			add_action( 'current_screen', array( $this, 'boot_order_admin_modules' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		$is_account_page = function_exists( 'is_account_page' ) && is_account_page();
		$is_thankyou_page = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$is_logged_in    = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$is_flatsome     = $is_account_page && ! $is_logged_in && function_exists( 'wp_get_theme' ) && 'flatsome' === strtolower( (string) wp_get_theme()->get_template() );
		$needs_popup_css = $is_account_page && $is_flatsome;

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

		if ( $is_account_page && is_user_logged_in() ) {
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

	private function register_autoloader(): void {
		spl_autoload_register(
			static function ( string $class_name ): void {
				if ( 1 !== preg_match( '/^CK_OWS_[A-Za-z0-9_]+$/', $class_name ) ) {
					return;
				}

				$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
				$file_path = CK_OWS_PATH . 'includes/' . $file_name;

				if ( is_readable( $file_path ) ) {
					require_once $file_path;
				}
			}
		);
	}

	private function boot_admin_request_module(): void {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( in_array( $action, array( 'ck_ows_set_awaiting_artwork', 'ck_ows_set_in_production', 'ck_ows_set_in_dispatch' ), true ) ) {
			CK_OWS_Admin_Order_Actions::instance();
		}
		if ( in_array( $action, array( 'ck_ows_artwork_upload', 'ck_ows_artwork_delete', 'ck_ows_artwork_action', 'ck_ows_artwork_override' ), true ) ) {
			CK_OWS_Artwork_Proof::instance();
		}
		if ( 'ck_ows_update_shipping_address' === $action ) {
			CK_OWS_Customer_Shipping_Edit::instance();
		}
		if ( 'ck_ows_save_email_preferences' === $action ) {
			CK_OWS_Account_Email_Preferences::instance();
		}
		if ( in_array( $action, array( 'ck_ows_run_tracking_sync', 'ck_ows_test_tracking_number', 'ck_ows_test_connections', 'ck_ows_test_artwork_webhook', 'ck_ows_export_settings', 'ck_ows_import_settings', 'ck_ows_retry_dead_letter', 'ck_ows_clear_dead_letters', 'ck_ows_retry_all_dead_letters' ), true ) ) {
			CK_OWS_Settings::instance();
		}
	}

	public function register_admin_pages(): void {
		$this->settings_page_hook = (string) add_menu_page(
			esc_html__( 'CK Order Workflow Settings', 'ck-order-workflow-suite' ),
			esc_html__( 'CK Workflow', 'ck-order-workflow-suite' ),
			'manage_woocommerce',
			'ck-ows-settings',
			array( $this, 'render_settings_page' ),
			$this->get_menu_icon(),
			56
		);

		add_submenu_page(
			'ck-ows-settings',
			esc_html__( 'CK Registration Guard', 'ck-order-workflow-suite' ),
			esc_html__( 'Registration Guard', 'ck-order-workflow-suite' ),
			'manage_woocommerce',
			'ck-reg-guard',
			array( $this, 'render_registration_guard_page' )
		);
	}

	public function maybe_register_admin_settings(): void {
		$page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';

		if ( 'ck-ows-settings' === $page || 'ck_ows_settings_group' === $option_page ) {
			CK_OWS_Settings::instance()->register_settings();
		}
	}

	public function maybe_redirect_legacy_admin_path(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( '' !== $uri && preg_match( '#/wp-admin/ck-reg-guard/?(?:\?|$)#', $uri ) ) {
			CK_OWS_Registration_Guard::instance()->redirect_legacy_admin_path();
		}
	}

	public function boot_order_admin_modules( $screen ): void {
		$screen_id = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		$valid_ids = array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$valid_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( in_array( $screen_id, array_unique( $valid_ids ), true ) ) {
			CK_OWS_Admin_Order_Actions::instance();
			CK_OWS_Artwork_Proof::instance();
		}
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		$is_settings_page = '' !== $this->settings_page_hook && $hook_suffix === $this->settings_page_hook;
		$is_guard_page    = str_ends_with( $hook_suffix, '_page_ck-reg-guard' );

		if ( ! $is_settings_page && ! $is_guard_page ) {
			return;
		}

		wp_enqueue_style( 'ck-ows-admin-ui', CK_OWS_URL . 'assets/css/admin-ui.css', array(), CK_OWS_VERSION );
		wp_enqueue_script( 'ck-ows-admin-settings', CK_OWS_URL . 'assets/js/admin-settings.js', array(), CK_OWS_VERSION, true );
	}

	public function render_settings_page(): void {
		CK_OWS_Settings::instance()->render_settings_page();
	}

	public function render_registration_guard_page(): void {
		CK_OWS_Registration_Guard::instance()->render_admin_page();
	}

	private function get_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M3 6.25h14M3 10h14M3 13.75h9" stroke="black" stroke-width="1.8" stroke-linecap="round"/><circle cx="15.2" cy="13.75" r="2.2" fill="black"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_account_endpoints(): void {
		add_rewrite_endpoint( 'invoices', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'security', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'email-preferences', EP_ROOT | EP_PAGES );
	}

	public function add_account_query_vars( array $query_vars ): array {
		$query_vars['invoices']          = 'invoices';
		$query_vars['security']          = 'security';
		$query_vars['email-preferences'] = 'email-preferences';

		return $query_vars;
	}

	public function register_shortcodes(): void {
		add_shortcode( 'order_tracking_summary', array( $this, 'render_order_tracking_shortcode' ) );
		add_shortcode( 'wc_invoice_link', array( $this, 'render_invoice_shortcode' ) );
	}

	public function render_order_tracking_shortcode( $atts = array(), $content = null, string $tag = '' ): string {
		unset( $atts, $content, $tag );

		return CK_OWS_Shortcodes::instance()->order_tracking_summary();
	}

	public function render_invoice_shortcode( $atts = array(), $content = null, string $tag = '' ): string {
		unset( $content, $tag );

		return CK_OWS_Shortcodes::instance()->invoice_link( is_array( $atts ) ? $atts : array() );
	}

	public function boot_customer_modules(): void {
		$is_account_page = function_exists( 'is_account_page' ) && is_account_page();
		$is_thankyou_page = function_exists( 'is_order_received_page' ) && is_order_received_page();

		if ( ! $is_account_page && ! $is_thankyou_page ) {
			return;
		}

		if ( $is_thankyou_page ) {
			CK_OWS_Tracking::instance()->suppress_default_tracking_output();
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		CK_OWS_Artwork_Proof::instance();

		if ( $is_account_page ) {
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'orders' ) ) {
				CK_OWS_Account_Order_Cards::instance()->replace_orders_endpoint_renderer();
			}
			if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'invoices' ) || is_wc_endpoint_url( 'edit-address' ) ) ) {
				CK_OWS_Account_Invoices::instance();
			}
			if ( isset( $_GET['ck_ows_invoice_order'] ) ) {
				CK_OWS_Account_Invoices::instance();
			}
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'security' ) ) {
				CK_OWS_Account_Security::instance();
			}
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'email-preferences' ) ) {
				CK_OWS_Account_Email_Preferences::instance();
			}
		}

		$is_order_details = $is_thankyou_page || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) );
		if ( $is_order_details ) {
			CK_OWS_Order_Timeline::instance();
			CK_OWS_Customer_Shipping_Edit::instance();
			$tracking = CK_OWS_Tracking::instance();
			$tracking->suppress_default_tracking_output();
		}
	}

	public function register_tracking_interval_schedule( array $schedules ): array {
		return CK_OWS_Tracking::instance()->register_interval_schedule( $schedules );
	}

	public function maybe_ensure_tracking_schedule(): void {
		if ( false !== get_transient( self::SCHEDULE_HEALTH_CHECK_KEY ) ) {
			return;
		}

		CK_OWS_Tracking::instance()->ensure_schedule();
		CK_OWS_Action_Scheduler_Cleanup::instance()->ensure_schedule();

		$tracking_ready = 'yes' !== CK_OWS_Settings::get( 'tracking_sync_enabled', 'yes' ) || wp_next_scheduled( 'ck_ows_tracking_sync_event' );
		$cleanup_ready  = wp_next_scheduled( self::ACTION_SCHEDULER_CLEANUP_HOOK );

		if ( $tracking_ready && $cleanup_ready ) {
			set_transient( self::SCHEDULE_HEALTH_CHECK_KEY, '1', HOUR_IN_SECONDS );
		}
	}

	public function maybe_ensure_action_scheduler_cleanup_schedule(): void {
		$this->maybe_ensure_tracking_schedule();
	}

	public function cleanup_action_scheduler_history(): void {
		CK_OWS_Action_Scheduler_Cleanup::instance()->cleanup();
	}

	public function sync_tracking_data(): void {
		CK_OWS_Tracking::instance()->sync_tracking_data();
	}

	public function continue_tracking_sync( int $offset, int $run_started_at, bool $allow_disabled ): void {
		CK_OWS_Tracking::instance()->sync_tracking_data( $offset, $run_started_at, $allow_disabled );
	}

	public function refresh_tracking_order( int $order_id, bool $allow_disabled = false, bool $force_refresh = false ): void {
		CK_OWS_Tracking::instance()->refresh_single_order( $order_id, $allow_disabled, $force_refresh );
	}

	public function register_artwork_event_routes(): void {
		CK_OWS_Artwork_Events::instance()->register_routes();
	}

	public function retry_artwork_event_delivery( array $payload ): void {
		CK_OWS_Artwork_Events::instance()->retry_event_delivery( $payload );
	}

	public function forward_tracking_event( int $order_id, array $tracking_payload ): void {
		CK_OWS_Tracking_Email_Events::instance()->forward_event_to_email_platform( $order_id, $tracking_payload );
	}

	public function retry_tracking_event_delivery( array $payload ): void {
		CK_OWS_Tracking_Email_Events::instance()->retry_event_delivery( $payload );
	}

	public function enforce_artwork_production_gate( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		CK_OWS_Artwork_Proof::instance()->enforce_production_gate( $order_id, $from_status, $to_status, $order );
	}

	public function capture_order_stage_timestamp( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		CK_OWS_Order_Timeline::instance()->capture_stage_timestamp( $order_id, $from_status, $to_status, $order );
	}

	public function validate_address_quality( int $user_id, string $load_address, array $address, $customer = null ): void {
		CK_OWS_Address_Quality::instance()->validate_quality( $user_id, $load_address, $address, $customer );
	}

	public function track_account_login( string $user_login, WP_User $user ): void {
		CK_OWS_Account_Security::instance()->track_login( $user_login, $user );
	}

	public function track_account_password_reset( WP_User $user, string $new_pass ): void {
		CK_OWS_Account_Security::instance()->track_password_reset( $user, $new_pass );
	}

	public function track_account_password_change( int $user_id ): void {
		CK_OWS_Account_Security::instance()->track_account_password_change( $user_id );
	}

	public function render_account_registration_fields(): void {
		CK_OWS_Registration_Guard::instance()->render_account_registration_fields();
	}

	public function inject_registration_guard_fields(): void {
		CK_OWS_Registration_Guard::instance()->inject_fields();
	}

	public function validate_woocommerce_registration( WP_Error $errors, string $username, string $password, string $email ): WP_Error {
		return CK_OWS_Registration_Guard::instance()->validate_registration( $errors, $username, $password, $email );
	}

	public function validate_wordpress_registration( WP_Error $errors, string $username, string $email ): WP_Error {
		return CK_OWS_Registration_Guard::instance()->validate_wp_registration( $errors, $username, $email );
	}

	public function save_account_registration_fields( int $customer_id ): void {
		CK_OWS_Registration_Guard::instance()->save_account_registration_fields( $customer_id );
	}

	public function add_account_menu_items( array $items ): array {
		foreach (
			array(
				'invoices'          => __( 'Invoices', 'ck-order-workflow-suite' ),
				'security'          => __( 'Security', 'ck-order-workflow-suite' ),
				'email-preferences' => __( 'Email Preferences', 'ck-order-workflow-suite' ),
			) as $endpoint => $label
		) {
			$items = CK_OWS_Account_Menu_Helper::insert_before_logout( $items, $endpoint, $label );
		}

		return $items;
	}

	public function filter_account_menu_items( array $items ): array {
		$options = get_option( 'ck_ows_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$visibility = array(
			'show_account_dashboard_tab'         => array( 'dashboard', 'hide_account_dashboard_tab' ),
			'show_account_orders_tab'            => array( 'orders', 'hide_account_orders_tab' ),
			'show_account_downloads_tab'         => array( 'downloads', 'hide_account_downloads_tab' ),
			'show_account_addresses_tab'         => array( 'edit-address', 'hide_account_addresses_tab' ),
			'show_account_details_tab'           => array( 'edit-account', 'hide_account_details_tab' ),
			'show_account_invoices_tab'          => array( 'invoices', 'hide_account_invoices_tab' ),
			'show_account_security_tab'          => array( 'security', 'hide_account_security_tab' ),
			'show_account_email_preferences_tab' => array( 'email-preferences', 'hide_account_email_preferences_tab' ),
			'show_account_logout_tab'            => array( 'customer-logout', 'hide_account_logout_tab' ),
		);

		foreach ( $visibility as $show_key => $config ) {
			$visible = true;
			if ( array_key_exists( $show_key, $options ) ) {
				$visible = 'yes' === (string) $options[ $show_key ];
			} elseif ( array_key_exists( $config[1], $options ) ) {
				$visible = 'yes' !== (string) $options[ $config[1] ];
			}

			if ( ! $visible ) {
				unset( $items[ $config[0] ] );
			}
		}

		return $items;
	}
}
