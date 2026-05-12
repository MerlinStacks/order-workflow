<?php
/**
 * Settings module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Settings {
	public const OPTION_KEY = 'ck_ows_settings';

	private static ?CK_OWS_Settings $instance = null;

	private string $settings_page_hook = '';

	public static function instance(): CK_OWS_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_ck_ows_run_tracking_sync', array( $this, 'run_tracking_sync_now' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'filter_account_menu_items' ), 1000 );
	}

	public static function get( string $key, $default = '' ) {
		$options = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $options ) ) {
			return $default;
		}

		return $options[ $key ] ?? $default;
	}

	public function register_admin_page(): void {
		$this->settings_page_hook = (string) add_menu_page(
			esc_html__( 'CK Order Workflow Settings', 'ck-order-workflow-suite' ),
			esc_html__( 'CK Workflow', 'ck-order-workflow-suite' ),
			'manage_woocommerce',
			'ck-ows-settings',
			array( $this, 'render_settings_page' ),
			$this->get_menu_icon(),
			56
		);
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( '' === $this->settings_page_hook || $hook_suffix !== $this->settings_page_hook ) {
			return;
		}

		wp_enqueue_style(
			'ck-ows-admin-ui',
			CK_OWS_URL . 'assets/css/admin-ui.css',
			array(),
			CK_OWS_VERSION
		);

		wp_enqueue_script(
			'ck-ows-admin-settings',
			CK_OWS_URL . 'assets/js/admin-settings.js',
			array(),
			CK_OWS_VERSION,
			true
		);
	}

	public function register_settings(): void {
		register_setting(
			'ck_ows_settings_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'ck_ows_tracking_section',
			esc_html__( 'Australia Post Tracking', 'ck-order-workflow-suite' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure your AusPost API details to fetch live tracking events for customer orders.', 'ck-order-workflow-suite' ) . '</p>';
			},
			'ck-ows-settings'
		);

		$this->register_field( 'auspost_api_key', __( 'AusPost API Key', 'ck-order-workflow-suite' ), 'text' );
		$this->register_field( 'auspost_account_number', __( 'AusPost Account Number (optional)', 'ck-order-workflow-suite' ), 'text' );
		$this->register_field( 'tracking_sync_enabled', __( 'Enable tracking sync', 'ck-order-workflow-suite' ), 'checkbox' );
		$this->register_field( 'tracking_sync_interval_hours', __( 'Sync interval (hours)', 'ck-order-workflow-suite' ), 'number' );

		add_settings_section(
			'ck_ows_email_preferences_section',
			esc_html__( 'Email Preferences API', 'ck-order-workflow-suite' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure the OverSeek API used by the My Account email preferences page.', 'ck-order-workflow-suite' ) . '</p>';
			},
			'ck-ows-settings'
		);

		$this->register_field( 'email_preferences_api_base_url', __( 'API Base URL', 'ck-order-workflow-suite' ), 'text', 'ck_ows_email_preferences_section' );
		$this->register_field( 'email_preferences_account_id', __( 'Account ID', 'ck-order-workflow-suite' ), 'text', 'ck_ows_email_preferences_section' );
		$this->register_field( 'email_preferences_webhook_secret', __( 'Webhook Secret (optional)', 'ck-order-workflow-suite' ), 'text', 'ck_ows_email_preferences_section' );

		add_settings_section(
			'ck_ows_account_menu_section',
			esc_html__( 'My Account Navigation', 'ck-order-workflow-suite' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Choose which default WooCommerce tabs are visible in My Account navigation.', 'ck-order-workflow-suite' ) . '</p>';
			},
			'ck-ows-settings'
		);

		$this->register_field( 'hide_account_dashboard_tab', __( 'Hide Dashboard tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_orders_tab', __( 'Hide Orders tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_downloads_tab', __( 'Hide Downloads tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_addresses_tab', __( 'Hide Addresses tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_details_tab', __( 'Hide Account details tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_invoices_tab', __( 'Hide Invoices tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_security_tab', __( 'Hide Security tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_email_preferences_tab', __( 'Hide Email preferences tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'hide_account_logout_tab', __( 'Hide Logout tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
	}

	public function sanitize_settings( array $input ): array {
		$current = get_option( self::OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();

		$current['auspost_api_key']             = isset( $input['auspost_api_key'] ) ? sanitize_text_field( (string) $input['auspost_api_key'] ) : '';
		$current['auspost_account_number']      = isset( $input['auspost_account_number'] ) ? sanitize_text_field( (string) $input['auspost_account_number'] ) : '';
		$current['tracking_sync_enabled']       = $this->is_enabled_input( $input, 'tracking_sync_enabled' ) ? 'yes' : 'no';
		$current['tracking_sync_interval_hours'] = isset( $input['tracking_sync_interval_hours'] ) ? max( 1, min( 24, absint( $input['tracking_sync_interval_hours'] ) ) ) : 6;
		$current['email_preferences_api_base_url'] = isset( $input['email_preferences_api_base_url'] ) ? esc_url_raw( trim( (string) $input['email_preferences_api_base_url'] ) ) : '';
		$current['email_preferences_account_id'] = isset( $input['email_preferences_account_id'] ) ? sanitize_text_field( (string) $input['email_preferences_account_id'] ) : '';
		$current['email_preferences_webhook_secret'] = isset( $input['email_preferences_webhook_secret'] ) ? sanitize_text_field( (string) $input['email_preferences_webhook_secret'] ) : '';
		$current['hide_account_dashboard_tab']  = $this->is_enabled_input( $input, 'hide_account_dashboard_tab' ) ? 'yes' : 'no';
		$current['hide_account_orders_tab']     = $this->is_enabled_input( $input, 'hide_account_orders_tab' ) ? 'yes' : 'no';
		$current['hide_account_downloads_tab']  = $this->is_enabled_input( $input, 'hide_account_downloads_tab' ) ? 'yes' : 'no';
		$current['hide_account_addresses_tab']  = $this->is_enabled_input( $input, 'hide_account_addresses_tab' ) ? 'yes' : 'no';
		$current['hide_account_details_tab']    = $this->is_enabled_input( $input, 'hide_account_details_tab' ) ? 'yes' : 'no';
		$current['hide_account_invoices_tab']   = $this->is_enabled_input( $input, 'hide_account_invoices_tab' ) ? 'yes' : 'no';
		$current['hide_account_security_tab']   = $this->is_enabled_input( $input, 'hide_account_security_tab' ) ? 'yes' : 'no';
		$current['hide_account_email_preferences_tab'] = $this->is_enabled_input( $input, 'hide_account_email_preferences_tab' ) ? 'yes' : 'no';
		$current['hide_account_logout_tab']     = $this->is_enabled_input( $input, 'hide_account_logout_tab' ) ? 'yes' : 'no';

		return $current;
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ck-order-workflow-suite' ) );
		}

		echo '<div class="wrap ck-ows-admin">';

		echo '<div class="ck-ows-hero">';
		echo '<h1>' . esc_html__( 'CK Workflow Settings', 'ck-order-workflow-suite' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage tracking integrations and workflow automation from one dedicated admin area.', 'ck-order-workflow-suite' ) . '</p>';
		echo '</div>';

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Configuration', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ck_ows_settings_group' );

		echo '<h2 class="nav-tab-wrapper ck-ows-tabs" role="tablist" aria-label="' . esc_attr__( 'Settings sections', 'ck-order-workflow-suite' ) . '">';
		echo '<button type="button" class="nav-tab nav-tab-active ck-ows-tab" role="tab" id="ck-ows-tab-tracking" aria-controls="ck-ows-panel-tracking" aria-selected="true" data-target="tracking">' . esc_html__( 'Tracking', 'ck-order-workflow-suite' ) . '</button>';
		echo '<button type="button" class="nav-tab ck-ows-tab" role="tab" id="ck-ows-tab-email-preferences" aria-controls="ck-ows-panel-email-preferences" aria-selected="false" tabindex="-1" data-target="email-preferences">' . esc_html__( 'Email Preferences', 'ck-order-workflow-suite' ) . '</button>';
		echo '<button type="button" class="nav-tab ck-ows-tab" role="tab" id="ck-ows-tab-account-tabs" aria-controls="ck-ows-panel-account-tabs" aria-selected="false" tabindex="-1" data-target="account-tabs">' . esc_html__( 'My Account Tabs', 'ck-order-workflow-suite' ) . '</button>';
		echo '</h2>';

		echo '<div id="ck-ows-panel-tracking" class="ck-ows-panel is-active" role="tabpanel" aria-labelledby="ck-ows-tab-tracking">';
		echo '<p>' . esc_html__( 'Configure your AusPost API details to fetch live tracking events for customer orders.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'ck-ows-settings', 'ck_ows_tracking_section' );
		echo '</table>';
		echo '</div>';

		echo '<div id="ck-ows-panel-email-preferences" class="ck-ows-panel" role="tabpanel" aria-labelledby="ck-ows-tab-email-preferences" hidden>';
		echo '<p>' . esc_html__( 'Configure your OverSeek API credentials for customer email preferences.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'ck-ows-settings', 'ck_ows_email_preferences_section' );
		echo '</table>';
		echo '</div>';

		echo '<div id="ck-ows-panel-account-tabs" class="ck-ows-panel" role="tabpanel" aria-labelledby="ck-ows-tab-account-tabs" hidden>';
		echo '<p>' . esc_html__( 'Choose which default WooCommerce tabs are visible in My Account navigation.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'ck-ows-settings', 'ck_ows_account_menu_section' );
		echo '</table>';
		echo '</div>';

		submit_button( __( 'Save settings', 'ck-order-workflow-suite' ) );
		echo '</form>';
		echo '</div>';

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Manual Actions', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use this to run an immediate tracking sync without waiting for the scheduled cron.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ck_ows_run_tracking_sync">';
		wp_nonce_field( 'ck_ows_run_tracking_sync' );
		submit_button( __( 'Run tracking sync now', 'ck-order-workflow-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';

		if ( isset( $_GET['ck_ows_sync_ran'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Tracking sync completed. Check order tracking panels for latest data.', 'ck-order-workflow-suite' ) . '</p></div>';
		}
		echo '</div>';
	}

	public function run_tracking_sync_now(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( 'ck_ows_run_tracking_sync' );

		if ( class_exists( 'CK_OWS_Tracking' ) ) {
			CK_OWS_Tracking::instance()->sync_tracking_data();
		}

		$redirect = add_query_arg(
			array(
				'page'            => 'ck-ows-settings',
				'ck_ows_sync_ran' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function filter_account_menu_items( array $items ): array {
		$toggle_to_endpoint = array(
			'hide_account_dashboard_tab'         => 'dashboard',
			'hide_account_orders_tab'            => 'orders',
			'hide_account_downloads_tab'         => 'downloads',
			'hide_account_addresses_tab'         => 'edit-address',
			'hide_account_details_tab'           => 'edit-account',
			'hide_account_invoices_tab'          => 'invoices',
			'hide_account_security_tab'          => 'security',
			'hide_account_email_preferences_tab' => 'email-preferences',
			'hide_account_logout_tab'            => 'customer-logout',
		);

		foreach ( $toggle_to_endpoint as $toggle_key => $endpoint_key ) {
			if ( 'yes' === self::get( $toggle_key, 'no' ) ) {
				unset( $items[ $endpoint_key ] );
			}
		}

		return $items;
	}

	private function register_field( string $key, string $label, string $type, string $section = 'ck_ows_tracking_section' ): void {
		add_settings_field(
			$key,
			esc_html( $label ),
			array( $this, 'render_field' ),
			'ck-ows-settings',
			$section,
			array(
				'key'  => $key,
				'type' => $type,
			)
		);
	}

	public function render_field( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$type  = (string) ( $args['type'] ?? 'text' );
		$value = self::get( $key, 'tracking_sync_interval_hours' === $key ? 6 : '' );
		$is_account_visibility_toggle = 0 === strpos( $key, 'hide_account_' );

		$name = self::OPTION_KEY . '[' . $key . ']';
		$id   = 'ck-ows-field-' . sanitize_html_class( $key );

		if ( 'checkbox' === $type ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';

			if ( $is_account_visibility_toggle ) {
				echo '<label class="ck-ows-switch" for="' . esc_attr( $id ) . '">';
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 'yes', (string) $value, false ) . '>';
				echo '<span class="ck-ows-switch__slider" aria-hidden="true"></span>';
				echo '<span class="screen-reader-text">' . esc_html__( 'Enabled', 'ck-order-workflow-suite' ) . '</span>';
				echo '</label>';
				return;
			}

			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 'yes', (string) $value, false ) . '> ' . esc_html__( 'Enabled', 'ck-order-workflow-suite' ) . '</label>';
			return;
		}

		if ( 'number' === $type ) {
			echo '<input type="number" min="1" max="24" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="small-text">';
			return;
		}

		echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text" autocomplete="off">';
	}

	private function is_enabled_input( array $input, string $key ): bool {
		if ( ! isset( $input[ $key ] ) ) {
			return false;
		}

		return '1' === (string) $input[ $key ];
	}

	private function get_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M3 6.25h14M3 10h14M3 13.75h9" stroke="black" stroke-width="1.8" stroke-linecap="round"/><circle cx="15.2" cy="13.75" r="2.2" fill="black"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
