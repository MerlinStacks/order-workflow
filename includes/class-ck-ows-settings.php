<?php
/**
 * Settings module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Settings {
	public const OPTION_KEY = 'ck_ows_settings';
	private const TRACKING_SYNC_NONCE       = 'ck_ows_run_tracking_sync';
	private const TRACKING_SYNC_NONCE_FIELD = 'ck_ows_tracking_sync_nonce';
	private const TEST_CONNECTION_NONCE       = 'ck_ows_test_connections';
	private const TEST_CONNECTION_NONCE_FIELD = 'ck_ows_test_connections_nonce';
	private const EXPORT_SETTINGS_NONCE       = 'ck_ows_export_settings';
	private const EXPORT_SETTINGS_NONCE_FIELD = 'ck_ows_export_settings_nonce';
	private const IMPORT_SETTINGS_NONCE       = 'ck_ows_import_settings';
	private const IMPORT_SETTINGS_NONCE_FIELD = 'ck_ows_import_settings_nonce';
	private const DLQ_RETRY_NONCE             = 'ck_ows_retry_dead_letter';
	private const DLQ_RETRY_NONCE_FIELD       = 'ck_ows_retry_dead_letter_nonce';
	private const DLQ_CLEAR_NONCE             = 'ck_ows_clear_dead_letters';
	private const DLQ_CLEAR_NONCE_FIELD       = 'ck_ows_clear_dead_letters_nonce';
	private const DLQ_RETRY_ALL_NONCE         = 'ck_ows_retry_all_dead_letters';
	private const DLQ_RETRY_ALL_NONCE_FIELD   = 'ck_ows_retry_all_dead_letters_nonce';

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
		add_action( 'admin_post_ck_ows_test_connections', array( $this, 'test_connections' ) );
		add_action( 'admin_post_ck_ows_export_settings', array( $this, 'export_settings' ) );
		add_action( 'admin_post_ck_ows_import_settings', array( $this, 'import_settings' ) );
		add_action( 'admin_post_ck_ows_retry_dead_letter', array( $this, 'retry_dead_letter' ) );
		add_action( 'admin_post_ck_ows_clear_dead_letters', array( $this, 'clear_dead_letters' ) );
		add_action( 'admin_post_ck_ows_retry_all_dead_letters', array( $this, 'retry_all_dead_letters' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'filter_account_menu_items' ), 1000 );
	}

	public static function get( string $key, $default = '' ) {
		$options = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $options ) ) {
			return $default;
		}

		$value = $options[ $key ] ?? $default;

		if ( in_array( $key, self::sensitive_keys(), true ) && is_string( $value ) ) {
			return self::decrypt_sensitive_value( $value );
		}

		return $value;
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
		$is_settings_page = '' !== $this->settings_page_hook && $hook_suffix === $this->settings_page_hook;
		$is_settings_submenu = str_ends_with( $hook_suffix, '_page_ck-reg-guard' );

		if ( ! $is_settings_page && ! $is_settings_submenu ) {
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
		$this->register_field( 'tracking_email_events_enabled', __( 'Forward tracking events to email platform', 'ck-order-workflow-suite' ), 'checkbox' );
		$this->register_field( 'tracking_email_events_webhook_url', __( 'Email platform webhook URL', 'ck-order-workflow-suite' ), 'text' );
		$this->register_field( 'tracking_email_events_auth_token', __( 'Webhook auth token (optional)', 'ck-order-workflow-suite' ), 'text' );
		$this->register_field( 'tracking_email_events_timeout_seconds', __( 'Webhook timeout (seconds)', 'ck-order-workflow-suite' ), 'number' );

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

		add_settings_section(
			'ck_ows_registration_guard_section',
			esc_html__( 'Registration Guard', 'ck-order-workflow-suite' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Control anti-bot registration rules for the WooCommerce My Account registration form.', 'ck-order-workflow-suite' ) . '</p>';
			},
			'ck-ows-settings'
		);

		$this->register_field( 'show_account_dashboard_tab', __( 'Show Dashboard tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_orders_tab', __( 'Show Orders tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_downloads_tab', __( 'Show Downloads tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_addresses_tab', __( 'Show Addresses tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_details_tab', __( 'Show Account details tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_invoices_tab', __( 'Show Invoices tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_security_tab', __( 'Show Security tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_email_preferences_tab', __( 'Show Email preferences tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'show_account_logout_tab', __( 'Show Logout tab', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_account_menu_section' );
		$this->register_field( 'registration_blocked_domains', __( 'Blocked email domains', 'ck-order-workflow-suite' ), 'textarea', 'ck_ows_registration_guard_section' );

		add_settings_section(
			'ck_ows_operations_section',
			esc_html__( 'Operations & Safety', 'ck-order-workflow-suite' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Retention, retries, and operational controls for production stores.', 'ck-order-workflow-suite' ) . '</p>';
			},
			'ck-ows-settings'
		);

		$this->register_field( 'keep_data_on_uninstall', __( 'Keep plugin data on uninstall', 'ck-order-workflow-suite' ), 'checkbox', 'ck_ows_operations_section' );
		$this->register_field( 'tracking_email_events_retry_attempts', __( 'Webhook retry attempts', 'ck-order-workflow-suite' ), 'number', 'ck_ows_operations_section' );
		$this->register_field( 'tracking_email_events_retry_backoff_minutes', __( 'Retry backoff (minutes)', 'ck-order-workflow-suite' ), 'number', 'ck_ows_operations_section' );
	}

	public function sanitize_settings( array $input ): array {
		$current = get_option( self::OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		$previous_tracking_enabled = (string) ( $current['tracking_sync_enabled'] ?? 'yes' );
		$previous_tracking_interval = absint( $current['tracking_sync_interval_hours'] ?? 6 );
		$raw_tracking_webhook_url = isset( $input['tracking_email_events_webhook_url'] ) ? trim( (string) $input['tracking_email_events_webhook_url'] ) : '';
		$raw_email_preferences_base_url = isset( $input['email_preferences_api_base_url'] ) ? trim( (string) $input['email_preferences_api_base_url'] ) : '';

		$current['auspost_api_key']             = $this->sanitize_sensitive_setting( $input, $current, 'auspost_api_key' );
		$current['auspost_account_number']      = isset( $input['auspost_account_number'] ) ? sanitize_text_field( (string) $input['auspost_account_number'] ) : '';
		$current['tracking_sync_enabled']       = $this->is_enabled_input( $input, 'tracking_sync_enabled' ) ? 'yes' : 'no';
		$current['tracking_sync_interval_hours'] = isset( $input['tracking_sync_interval_hours'] ) ? max( 1, min( 24, absint( $input['tracking_sync_interval_hours'] ) ) ) : 6;
		$current['tracking_email_events_enabled'] = $this->is_enabled_input( $input, 'tracking_email_events_enabled' ) ? 'yes' : 'no';
		$current['tracking_email_events_webhook_url'] = isset( $input['tracking_email_events_webhook_url'] ) ? $this->sanitize_https_webhook_url( (string) $input['tracking_email_events_webhook_url'] ) : '';
		if ( '' !== $raw_tracking_webhook_url && '' === $current['tracking_email_events_webhook_url'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'ck_ows_invalid_tracking_webhook_url',
				esc_html__( 'Email platform webhook URL was not saved. Enter a valid HTTPS URL (for example: https://example.com/webhook).', 'ck-order-workflow-suite' ),
				'error'
			);
		}
		$current['tracking_email_events_auth_token'] = $this->sanitize_sensitive_setting( $input, $current, 'tracking_email_events_auth_token' );
		$current['tracking_email_events_timeout_seconds'] = isset( $input['tracking_email_events_timeout_seconds'] ) ? max( 3, min( 30, absint( $input['tracking_email_events_timeout_seconds'] ) ) ) : 10;
		$current['tracking_email_events_retry_attempts'] = isset( $input['tracking_email_events_retry_attempts'] ) ? max( 0, min( 5, absint( $input['tracking_email_events_retry_attempts'] ) ) ) : 3;
		$current['tracking_email_events_retry_backoff_minutes'] = isset( $input['tracking_email_events_retry_backoff_minutes'] ) ? max( 1, min( 60, absint( $input['tracking_email_events_retry_backoff_minutes'] ) ) ) : 5;
		$current['email_preferences_api_base_url'] = isset( $input['email_preferences_api_base_url'] ) ? $this->sanitize_https_base_url( (string) $input['email_preferences_api_base_url'] ) : '';
		if ( '' !== $raw_email_preferences_base_url && '' === $current['email_preferences_api_base_url'] ) {
			add_settings_error(
				self::OPTION_KEY,
				'ck_ows_invalid_email_preferences_base_url',
				esc_html__( 'API Base URL was not saved. Enter a valid HTTPS base URL (for example: https://api.overseek.com).', 'ck-order-workflow-suite' ),
				'error'
			);
		}
		$current['email_preferences_account_id'] = isset( $input['email_preferences_account_id'] ) ? sanitize_text_field( (string) $input['email_preferences_account_id'] ) : '';
		$current['email_preferences_webhook_secret'] = $this->sanitize_sensitive_setting( $input, $current, 'email_preferences_webhook_secret' );
		$current['show_account_dashboard_tab']  = $this->is_enabled_input( $input, 'show_account_dashboard_tab' ) ? 'yes' : 'no';
		$current['show_account_orders_tab']     = $this->is_enabled_input( $input, 'show_account_orders_tab' ) ? 'yes' : 'no';
		$current['show_account_downloads_tab']  = $this->is_enabled_input( $input, 'show_account_downloads_tab' ) ? 'yes' : 'no';
		$current['show_account_addresses_tab']  = $this->is_enabled_input( $input, 'show_account_addresses_tab' ) ? 'yes' : 'no';
		$current['show_account_details_tab']    = $this->is_enabled_input( $input, 'show_account_details_tab' ) ? 'yes' : 'no';
		$current['show_account_invoices_tab']   = $this->is_enabled_input( $input, 'show_account_invoices_tab' ) ? 'yes' : 'no';
		$current['show_account_security_tab']   = $this->is_enabled_input( $input, 'show_account_security_tab' ) ? 'yes' : 'no';
		$current['show_account_email_preferences_tab'] = $this->is_enabled_input( $input, 'show_account_email_preferences_tab' ) ? 'yes' : 'no';
		$current['show_account_logout_tab']     = $this->is_enabled_input( $input, 'show_account_logout_tab' ) ? 'yes' : 'no';
		$current['registration_blocked_domains'] = isset( $input['registration_blocked_domains'] ) ? $this->sanitize_blocked_domain_list( (string) $input['registration_blocked_domains'] ) : '';
		$current['keep_data_on_uninstall'] = $this->is_enabled_input( $input, 'keep_data_on_uninstall' ) ? 'yes' : 'no';

		if ( $previous_tracking_enabled !== $current['tracking_sync_enabled'] || $previous_tracking_interval !== (int) $current['tracking_sync_interval_hours'] ) {
			wp_clear_scheduled_hook( 'ck_ows_tracking_sync_event' );
		}

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

		settings_errors( self::OPTION_KEY );

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Configuration', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ck_ows_settings_group' );

		echo '<h2 class="nav-tab-wrapper ck-ows-tabs" role="tablist" aria-label="' . esc_attr__( 'Settings sections', 'ck-order-workflow-suite' ) . '">';
		echo '<button type="button" class="nav-tab nav-tab-active ck-ows-tab" role="tab" id="ck-ows-tab-tracking" aria-controls="ck-ows-panel-tracking" aria-selected="true" data-target="tracking">' . esc_html__( 'Tracking', 'ck-order-workflow-suite' ) . '</button>';
		echo '<button type="button" class="nav-tab ck-ows-tab" role="tab" id="ck-ows-tab-email-preferences" aria-controls="ck-ows-panel-email-preferences" aria-selected="false" tabindex="-1" data-target="email-preferences">' . esc_html__( 'Email Preferences', 'ck-order-workflow-suite' ) . '</button>';
		echo '<button type="button" class="nav-tab ck-ows-tab" role="tab" id="ck-ows-tab-account-tabs" aria-controls="ck-ows-panel-account-tabs" aria-selected="false" tabindex="-1" data-target="account-tabs">' . esc_html__( 'My Account Tabs', 'ck-order-workflow-suite' ) . '</button>';
		echo '<button type="button" class="nav-tab ck-ows-tab" role="tab" id="ck-ows-tab-registration-guard" aria-controls="ck-ows-panel-registration-guard" aria-selected="false" tabindex="-1" data-target="registration-guard">' . esc_html__( 'Registration Guard', 'ck-order-workflow-suite' ) . '</button>';
		echo '<button type="button" class="nav-tab ck-ows-tab" role="tab" id="ck-ows-tab-operations" aria-controls="ck-ows-panel-operations" aria-selected="false" tabindex="-1" data-target="operations">' . esc_html__( 'Operations', 'ck-order-workflow-suite' ) . '</button>';
		echo '</h2>';

		echo '<div id="ck-ows-panel-tracking" class="ck-ows-panel is-active" role="tabpanel" aria-labelledby="ck-ows-tab-tracking">';
		echo '<p>' . esc_html__( 'Configure your AusPost API details to fetch live tracking events for customer orders.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'ck-ows-settings', 'ck_ows_tracking_section' );
		echo '</table>';
		echo '</div>';

		echo '<div id="ck-ows-panel-email-preferences" class="ck-ows-panel" role="tabpanel" aria-labelledby="ck-ows-tab-email-preferences" hidden>';
		echo '<p>' . esc_html__( 'Configure your OverSeek API credentials for customer email preferences.', 'ck-order-workflow-suite' ) . '</p>';
		$email_preferences_base_url = (string) self::get( 'email_preferences_api_base_url', '' );
		if ( '' !== $email_preferences_base_url && ! $this->is_allowed_email_preferences_host( $email_preferences_base_url ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'Email preferences API host is not on the allowlist, so frontend email preferences will stay disabled. Update the URL or extend the allowlist via ck_ows_email_preferences_allowed_hosts.', 'ck-order-workflow-suite' );
			echo '</p></div>';
		}
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

		echo '<div id="ck-ows-panel-registration-guard" class="ck-ows-panel" role="tabpanel" aria-labelledby="ck-ows-tab-registration-guard" hidden>';
		echo '<p>' . esc_html__( 'Control anti-bot registration rules for the WooCommerce My Account registration form.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'ck-ows-settings', 'ck_ows_registration_guard_section' );
		echo '</table>';
		echo '</div>';

		echo '<div id="ck-ows-panel-operations" class="ck-ows-panel" role="tabpanel" aria-labelledby="ck-ows-tab-operations" hidden>';
		echo '<p>' . esc_html__( 'Configure operational safety controls, retries, and data retention behavior.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		do_settings_fields( 'ck-ows-settings', 'ck_ows_operations_section' );
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
		wp_nonce_field( self::TRACKING_SYNC_NONCE, self::TRACKING_SYNC_NONCE_FIELD );
		submit_button( __( 'Run tracking sync now', 'ck-order-workflow-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<hr>';
		echo '<p>' . esc_html__( 'Run live connection checks against current AusPost and webhook settings.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ck_ows_test_connections">';
		wp_nonce_field( self::TEST_CONNECTION_NONCE, self::TEST_CONNECTION_NONCE_FIELD );
		submit_button( __( 'Test connections', 'ck-order-workflow-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Settings Import/Export', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ck_ows_export_settings">';
		wp_nonce_field( self::EXPORT_SETTINGS_NONCE, self::EXPORT_SETTINGS_NONCE_FIELD );
		submit_button( __( 'Export settings JSON', 'ck-order-workflow-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px;">';
		echo '<input type="hidden" name="action" value="ck_ows_import_settings">';
		wp_nonce_field( self::IMPORT_SETTINGS_NONCE, self::IMPORT_SETTINGS_NONCE_FIELD );
		echo '<p><label for="ck_ows_import_json"><strong>' . esc_html__( 'Paste settings JSON', 'ck-order-workflow-suite' ) . '</strong></label></p>';
		echo '<textarea id="ck_ows_import_json" name="ck_ows_import_json" class="large-text code" rows="8" spellcheck="false"></textarea>';
		submit_button( __( 'Import settings JSON', 'ck-order-workflow-suite' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Diagnostics', 'ck-order-workflow-suite' ) . '</h2>';
		$this->render_diagnostics_panel();
		echo '</div>';

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Dead Letters', 'ck-order-workflow-suite' ) . '</h2>';
		$this->render_dead_letters_panel();
		echo '</div>';

		if ( isset( $_GET['ck_ows_sync_ran'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Tracking sync completed. Check order tracking panels for latest data.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_tested'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Connection tests complete. See diagnostics panel for latest results.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_imported'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings imported and saved successfully.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_dead_retried'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Dead-letter event queued for retry.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_dead_cleared'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Dead-letter queue cleared.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_dead_retried_all'] ) ) {
			$count = absint( wp_unslash( $_GET['ck_ows_dead_retried_all'] ) );
			/* translators: %d: number of dead-letter events queued for retry. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Queued %d dead-letter event(s) for retry.', 'ck-order-workflow-suite' ), $count ) ) . '</p></div>';
		}
		echo '</div>';
	}

	public function run_tracking_sync_now(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::TRACKING_SYNC_NONCE, self::TRACKING_SYNC_NONCE_FIELD );

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

	public function test_connections(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::TEST_CONNECTION_NONCE, self::TEST_CONNECTION_NONCE_FIELD );

		$results = array(
			'ran_at'        => time(),
			'auspost'       => $this->test_auspost_connection(),
			'email_webhook' => $this->test_webhook_connection(),
		);

		update_option( 'ck_ows_last_connection_tests', $results, false );
		CK_OWS_Audit::log_system_event( 'test_connections', array( 'result' => $results ) );

		$redirect = add_query_arg(
			array(
				'page'          => 'ck-ows-settings',
				'ck_ows_tested' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function export_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::EXPORT_SETTINGS_NONCE, self::EXPORT_SETTINGS_NONCE_FIELD );

		$settings = get_option( self::OPTION_KEY, array() );
		$payload  = is_array( $settings ) ? $settings : array();
		$json     = wp_json_encode( $payload, JSON_PRETTY_PRINT );

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ck-ows-settings-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo is_string( $json ) ? $json : '{}';
		exit;
	}

	public function import_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::IMPORT_SETTINGS_NONCE, self::IMPORT_SETTINGS_NONCE_FIELD );

		$raw = isset( $_POST['ck_ows_import_json'] ) ? (string) wp_unslash( $_POST['ck_ows_import_json'] ) : '';
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$redirect = add_query_arg( 'page', 'ck-ows-settings', admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$sanitized = $this->sanitize_settings( $data );
		update_option( self::OPTION_KEY, $sanitized, false );
		CK_OWS_Audit::log_system_event( 'settings_imported', array( 'keys' => array_keys( $sanitized ) ) );

		$redirect = add_query_arg(
			array(
				'page'            => 'ck-ows-settings',
				'ck_ows_imported' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function retry_dead_letter(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::DLQ_RETRY_NONCE, self::DLQ_RETRY_NONCE_FIELD );

		$index = isset( $_POST['dead_letter_index'] ) ? absint( wp_unslash( $_POST['dead_letter_index'] ) ) : -1;
		$rows  = get_option( 'ck_ows_tracking_event_dead_letters', array() );
		$rows  = is_array( $rows ) ? $rows : array();

		if ( ! isset( $rows[ $index ] ) || ! is_array( $rows[ $index ] ) ) {
			$redirect = add_query_arg( array( 'page' => 'ck-ows-settings' ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$row       = $rows[ $index ];
		$order_id  = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
		$event     = isset( $row['event'] ) && is_array( $row['event'] ) ? $row['event'] : array();
		$attempt   = 1;

		if ( $order_id > 0 && ! empty( $event ) ) {
			wp_schedule_single_event(
				time() + 5,
				'ck_ows_tracking_event_retry',
				array(
					array(
						'order_id' => $order_id,
						'event'    => $event,
						'attempt'  => $attempt,
					),
				)
			);

			unset( $rows[ $index ] );
			$rows = array_values( $rows );
			update_option( 'ck_ows_tracking_event_dead_letters', $rows, false );
			CK_OWS_Audit::log_system_event( 'dead_letter_retried', array( 'order_id' => $order_id ) );
		}

		$redirect = add_query_arg(
			array(
				'page'               => 'ck-ows-settings',
				'ck_ows_dead_retried' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function clear_dead_letters(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::DLQ_CLEAR_NONCE, self::DLQ_CLEAR_NONCE_FIELD );

		delete_option( 'ck_ows_tracking_event_dead_letters' );
		CK_OWS_Audit::log_system_event( 'dead_letters_cleared' );

		$redirect = add_query_arg(
			array(
				'page'               => 'ck-ows-settings',
				'ck_ows_dead_cleared' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function retry_all_dead_letters(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::DLQ_RETRY_ALL_NONCE, self::DLQ_RETRY_ALL_NONCE_FIELD );

		$rows = get_option( 'ck_ows_tracking_event_dead_letters', array() );
		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$queued = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$order_id = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
			$event    = isset( $row['event'] ) && is_array( $row['event'] ) ? $row['event'] : array();

			if ( $order_id <= 0 || empty( $event ) ) {
				continue;
			}

			wp_schedule_single_event(
				time() + 5,
				'ck_ows_tracking_event_retry',
				array(
					array(
						'order_id' => $order_id,
						'event'    => $event,
						'attempt'  => 1,
					),
				)
			);

			$queued++;
		}

		delete_option( 'ck_ows_tracking_event_dead_letters' );
		CK_OWS_Audit::log_system_event( 'dead_letters_retried_all', array( 'queued' => $queued ) );

		$redirect = add_query_arg(
			array(
				'page'                   => 'ck-ows-settings',
				'ck_ows_dead_retried_all' => $queued,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function filter_account_menu_items( array $items ): array {
		$visibility_to_endpoint = array(
			'show_account_dashboard_tab'         => array(
				'endpoint'   => 'dashboard',
				'legacy_key' => 'hide_account_dashboard_tab',
			),
			'show_account_orders_tab'            => array(
				'endpoint'   => 'orders',
				'legacy_key' => 'hide_account_orders_tab',
			),
			'show_account_downloads_tab'         => array(
				'endpoint'   => 'downloads',
				'legacy_key' => 'hide_account_downloads_tab',
			),
			'show_account_addresses_tab'         => array(
				'endpoint'   => 'edit-address',
				'legacy_key' => 'hide_account_addresses_tab',
			),
			'show_account_details_tab'           => array(
				'endpoint'   => 'edit-account',
				'legacy_key' => 'hide_account_details_tab',
			),
			'show_account_invoices_tab'          => array(
				'endpoint'   => 'invoices',
				'legacy_key' => 'hide_account_invoices_tab',
			),
			'show_account_security_tab'          => array(
				'endpoint'   => 'security',
				'legacy_key' => 'hide_account_security_tab',
			),
			'show_account_email_preferences_tab' => array(
				'endpoint'   => 'email-preferences',
				'legacy_key' => 'hide_account_email_preferences_tab',
			),
			'show_account_logout_tab'            => array(
				'endpoint'   => 'customer-logout',
				'legacy_key' => 'hide_account_logout_tab',
			),
		);

		foreach ( $visibility_to_endpoint as $show_key => $config ) {
			$endpoint_key = (string) $config['endpoint'];
			$legacy_key   = (string) $config['legacy_key'];

			if ( ! $this->is_account_tab_visible( $show_key, $legacy_key ) ) {
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
		$default = '';
		if ( 'tracking_sync_interval_hours' === $key ) {
			$default = 6;
		} elseif ( 'tracking_email_events_timeout_seconds' === $key ) {
			$default = 10;
		} elseif ( 'tracking_email_events_retry_attempts' === $key ) {
			$default = 3;
		} elseif ( 'tracking_email_events_retry_backoff_minutes' === $key ) {
			$default = 5;
		}

		$value = self::get( $key, $default );
		$is_account_visibility_toggle = 0 === strpos( $key, 'show_account_' ) || 0 === strpos( $key, 'hide_account_' );
		$is_sensitive_field = in_array( $key, self::sensitive_keys(), true );

		if ( 0 === strpos( $key, 'show_account_' ) ) {
			$value = self::get( $key, 'yes' );
		}

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
			$min = '1';
			$max = '24';

			if ( 'tracking_email_events_timeout_seconds' === $key ) {
				$min = '3';
				$max = '30';
			} elseif ( 'tracking_email_events_retry_attempts' === $key ) {
				$min = '0';
				$max = '5';
			} elseif ( 'tracking_email_events_retry_backoff_minutes' === $key ) {
				$min = '1';
				$max = '60';
			}

			echo '<input type="number" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="small-text">';

			if ( 'tracking_email_events_timeout_seconds' === $key ) {
				echo '<p class="description">' . esc_html__( 'Recommended: 10 seconds. Used when sending events to your email platform webhook.', 'ck-order-workflow-suite' ) . '</p>';
			} elseif ( 'tracking_email_events_retry_attempts' === $key ) {
				echo '<p class="description">' . esc_html__( 'Retries after initial delivery fails. Set to 0 to disable retries.', 'ck-order-workflow-suite' ) . '</p>';
			} elseif ( 'tracking_email_events_retry_backoff_minutes' === $key ) {
				echo '<p class="description">' . esc_html__( 'Base delay before retrying failed webhook deliveries.', 'ck-order-workflow-suite' ) . '</p>';
			}
			return;
		}

		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="large-text code" rows="8" spellcheck="false">' . esc_textarea( (string) $value ) . '</textarea>';

			if ( 'registration_blocked_domains' === $key ) {
				$default_domains = class_exists( 'CK_OWS_Registration_Guard' ) ? CK_OWS_Registration_Guard::default_blocked_domains() : array();
				$default_domains = is_array( $default_domains ) ? array_values( array_filter( array_map( 'strval', $default_domains ) ) ) : array();

				echo '<p class="description">' . esc_html__( 'One domain per line, for example: mailinator.com', 'ck-order-workflow-suite' ) . '</p>';
				echo '<p class="description">' . esc_html__( 'These custom domains are added to the built-in disposable domain list.', 'ck-order-workflow-suite' ) . '</p>';

				if ( ! empty( $default_domains ) ) {
					echo '<p class="description"><strong>' . esc_html__( 'Built-in blocked domains:', 'ck-order-workflow-suite' ) . '</strong><br>' . nl2br( esc_html( implode( "\n", $default_domains ) ) ) . '</p>';
				}
			}

			return;
		}

		if ( $is_sensitive_field ) {
			$display_value = '' !== (string) $value ? '********' : '';
			echo '<input type="password" name="' . esc_attr( $name ) . '" value="' . esc_attr( $display_value ) . '" class="regular-text" autocomplete="new-password">';
			echo '<p class="description">' . esc_html__( 'Saved values are hidden. Leave unchanged to keep the existing value.', 'ck-order-workflow-suite' ) . '</p>';
		} else {
			echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text" autocomplete="off">';
		}

		if ( 'tracking_email_events_webhook_url' === $key ) {
			echo '<p class="description">' . esc_html__( 'HTTPS endpoint that receives normalized tracking lifecycle events for automation.', 'ck-order-workflow-suite' ) . '</p>';
			$this->render_field_error( $key );
		}

		if ( 'tracking_email_events_auth_token' === $key ) {
			echo '<p class="description">' . esc_html__( 'If set, requests include Authorization: Bearer {token}.', 'ck-order-workflow-suite' ) . '</p>';
		}

		if ( 'email_preferences_api_base_url' === $key ) {
			$allowed_hosts = apply_filters(
				'ck_ows_email_preferences_allowed_hosts',
				array(
					'api.overseek.com',
					'staging-api.overseek.com',
				)
			);

			if ( is_array( $allowed_hosts ) && ! empty( $allowed_hosts ) ) {
				$allowed_hosts = array_values(
					array_filter(
						array_map(
							'strtolower',
							array_map( 'strval', $allowed_hosts )
						)
					)
				);

				if ( ! empty( $allowed_hosts ) ) {
					echo '<p class="description">';
					echo esc_html__( 'Allowed hosts:', 'ck-order-workflow-suite' ) . ' ' . esc_html( implode( ', ', $allowed_hosts ) );
					echo '</p>';
				}
			}

			$this->render_field_error( $key );
		}
	}

	private function render_field_error( string $key ): void {
		$settings_errors = get_settings_errors( self::OPTION_KEY );

		if ( ! is_array( $settings_errors ) || empty( $settings_errors ) ) {
			return;
		}

		$error_code_by_field = array(
			'tracking_email_events_webhook_url' => 'ck_ows_invalid_tracking_webhook_url',
			'email_preferences_api_base_url'    => 'ck_ows_invalid_email_preferences_base_url',
		);

		if ( ! isset( $error_code_by_field[ $key ] ) ) {
			return;
		}

		$error_code = $error_code_by_field[ $key ];

		foreach ( $settings_errors as $error ) {
			if ( ! is_array( $error ) || ( $error['code'] ?? '' ) !== $error_code ) {
				continue;
			}

			echo '<p class="description" style="color:#b32d2e;">' . esc_html( (string) ( $error['message'] ?? '' ) ) . '</p>';
			return;
		}
	}

	private function is_enabled_input( array $input, string $key ): bool {
		if ( ! isset( $input[ $key ] ) ) {
			return false;
		}

		return '1' === (string) $input[ $key ];
	}

	private function sanitize_sensitive_setting( array $input, array $current, string $key ): string {
		if ( ! isset( $input[ $key ] ) ) {
			return '';
		}

		$value = sanitize_text_field( (string) $input[ $key ] );

		if ( '' === trim( $value ) || '********' === $value ) {
			return isset( $current[ $key ] ) ? (string) $current[ $key ] : '';
		}

		return $this->encrypt_sensitive_value( $value );
	}

	private function is_account_tab_visible( string $show_key, string $legacy_hide_key ): bool {
		$options = get_option( self::OPTION_KEY, array() );
		$options = is_array( $options ) ? $options : array();

		if ( array_key_exists( $show_key, $options ) ) {
			return 'yes' === (string) $options[ $show_key ];
		}

		if ( array_key_exists( $legacy_hide_key, $options ) ) {
			return 'yes' !== (string) $options[ $legacy_hide_key ];
		}

		return true;
	}

	private function get_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M3 6.25h14M3 10h14M3 13.75h9" stroke="black" stroke-width="1.8" stroke-linecap="round"/><circle cx="15.2" cy="13.75" r="2.2" fill="black"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private function sanitize_https_base_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . $url;
		}

		$url = untrailingslashit( $url );

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		if ( 'https' !== $scheme || empty( $parts['host'] ) ) {
			return '';
		}

		$host = (string) $parts['host'];
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		$sanitized = 'https://' . $host;

		if ( $port > 0 ) {
			$sanitized .= ':' . $port;
		}

		$sanitized .= $path;

		return esc_url_raw( $sanitized );
	}

	private function sanitize_https_webhook_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		if ( 'https' !== $scheme || empty( $parts['host'] ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		$sanitized = 'https://' . $parts['host'] . $path;

		if ( '' !== $query ) {
			$sanitized .= '?' . $query;
		}

		return esc_url_raw( $sanitized );
	}

	private function is_allowed_email_preferences_host( string $base_url ): bool {
		$host = wp_parse_url( $base_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$allowed_hosts = apply_filters(
			'ck_ows_email_preferences_allowed_hosts',
			array(
				'api.overseek.com',
				'staging-api.overseek.com',
			)
		);

		if ( ! is_array( $allowed_hosts ) || empty( $allowed_hosts ) ) {
			return false;
		}

		$host = strtolower( $host );
		$allowed_hosts = array_values(
			array_filter(
				array_map(
					'strtolower',
					array_map( 'strval', $allowed_hosts )
				)
			)
		);

		return in_array( $host, $allowed_hosts, true );
	}

	private function sanitize_blocked_domain_list( string $value ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $value );

		if ( ! is_array( $lines ) ) {
			return '';
		}

		$domains = array();

		foreach ( $lines as $line ) {
			$domain = strtolower( trim( sanitize_text_field( (string) $line ) ) );

			if ( '' === $domain ) {
				continue;
			}

			if ( false === preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) {
				continue;
			}

			$domains[] = $domain;
		}

		$domains = array_values( array_unique( $domains ) );

		return implode( "\n", $domains );
	}

	private function render_diagnostics_panel(): void {
		$next_sync     = wp_next_scheduled( 'ck_ows_tracking_sync_event' );
		$cron_enabled  = 'yes' === CK_OWS_Settings::get( 'tracking_sync_enabled', 'yes' );
		$last_tests    = get_option( 'ck_ows_last_connection_tests', array() );
		$last_webhook  = get_option( 'ck_ows_last_webhook_delivery', array() );
		$dead_letters  = get_option( 'ck_ows_tracking_event_dead_letters', array() );
		$audit_entries = CK_OWS_Audit::read_recent( 10 );

		echo '<ul>';
		echo '<li><strong>' . esc_html__( 'WordPress', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( get_bloginfo( 'version' ) ) . '</li>';
		echo '<li><strong>' . esc_html__( 'WooCommerce', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'HPOS compatibility declared', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html__( 'yes', 'ck-order-workflow-suite' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Tracking cron enabled', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( $cron_enabled ? 'yes' : 'no' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Next tracking sync', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( $next_sync ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_sync ) : 'not scheduled' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Last webhook delivery', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( $this->format_diagnostic_row( $last_webhook ) ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Dead-letter queue size', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( is_array( $dead_letters ) ? (string) count( $dead_letters ) : '0' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Last connection tests', 'ck-order-workflow-suite' ) . ':</strong> ' . esc_html( $this->format_diagnostic_row( $last_tests ) ) . '</li>';
		echo '</ul>';

		if ( ! empty( $audit_entries ) ) {
			echo '<p><strong>' . esc_html__( 'Recent audit events', 'ck-order-workflow-suite' ) . '</strong></p>';
			echo '<ul>';
			foreach ( $audit_entries as $entry ) {
				$action = isset( $entry['action'] ) ? (string) $entry['action'] : 'unknown';
				$ts     = isset( $entry['ts'] ) ? absint( $entry['ts'] ) : 0;
				echo '<li>' . esc_html( $action . ' - ' . ( $ts > 0 ? wp_date( 'Y-m-d H:i:s', $ts ) : '' ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function render_dead_letters_panel(): void {
		$rows = get_option( 'ck_ows_tracking_event_dead_letters', array() );
		$rows = is_array( $rows ) ? array_values( $rows ) : array();

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No dead-letter events currently queued.', 'ck-order-workflow-suite' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Failed webhook events that exceeded retries. You can retry individual events or clear the queue.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<table class="widefat striped" role="presentation">';
		echo '<thead><tr><th>' . esc_html__( 'Time', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Order', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Attempts', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Error', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Action', 'ck-order-workflow-suite' ) . '</th></tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $index => $row ) {
			$ts       = isset( $row['ts'] ) ? absint( $row['ts'] ) : 0;
			$order_id = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
			$attempts = isset( $row['attempts'] ) ? absint( $row['attempts'] ) : 0;
			$error    = isset( $row['last_error'] ) ? sanitize_text_field( (string) $row['last_error'] ) : '';

			echo '<tr>';
			echo '<td>' . esc_html( $ts > 0 ? wp_date( 'Y-m-d H:i:s', $ts ) : '-' ) . '</td>';
			echo '<td>#' . esc_html( (string) $order_id ) . '</td>';
			echo '<td>' . esc_html( (string) $attempts ) . '</td>';
			echo '<td>' . esc_html( $error ) . '</td>';
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="ck_ows_retry_dead_letter">';
			echo '<input type="hidden" name="dead_letter_index" value="' . esc_attr( (string) $index ) . '">';
			wp_nonce_field( self::DLQ_RETRY_NONCE, self::DLQ_RETRY_NONCE_FIELD );
			submit_button( __( 'Retry', 'ck-order-workflow-suite' ), 'secondary', 'submit', false );
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<div style="display:flex;gap:8px;align-items:center;margin-top:12px;">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ck_ows_retry_all_dead_letters">';
		wp_nonce_field( self::DLQ_RETRY_ALL_NONCE, self::DLQ_RETRY_ALL_NONCE_FIELD );
		echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Queue retries for all dead-letter events?', 'ck-order-workflow-suite' ) ) . '\');">' . esc_html__( 'Retry all', 'ck-order-workflow-suite' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
		echo '<input type="hidden" name="action" value="ck_ows_clear_dead_letters">';
		wp_nonce_field( self::DLQ_CLEAR_NONCE, self::DLQ_CLEAR_NONCE_FIELD );
		echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Permanently clear all dead-letter events?', 'ck-order-workflow-suite' ) ) . '\');">' . esc_html__( 'Clear dead-letter queue', 'ck-order-workflow-suite' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	private function test_auspost_connection(): array {
		$api_key = trim( (string) self::get( 'auspost_api_key', '' ) );

		if ( '' === $api_key ) {
			return array( 'ok' => false, 'message' => 'Missing API key' );
		}

		$response = wp_remote_get(
			'https://digitalapi.auspost.com.au/postcode/search.json?q=2000',
			array(
				'timeout' => 10,
				'headers' => array( 'AUTH-KEY' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array( 'ok' => $code >= 200 && $code < 300, 'message' => 'HTTP ' . $code );
	}

	private function test_webhook_connection(): array {
		$url = trim( (string) self::get( 'tracking_email_events_webhook_url', '' ) );

		if ( '' === $url ) {
			return array( 'ok' => false, 'message' => 'Missing webhook URL' );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'event' => 'ck_ows_connection_test', 'ts' => time() ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array( 'ok' => $code >= 200 && $code < 300, 'message' => 'HTTP ' . $code );
	}

	private function format_diagnostic_row( $row ): string {
		if ( ! is_array( $row ) || empty( $row ) ) {
			return 'n/a';
		}

		$ts = isset( $row['ran_at'] ) ? absint( $row['ran_at'] ) : ( isset( $row['ts'] ) ? absint( $row['ts'] ) : 0 );
		return $ts > 0 ? wp_date( 'Y-m-d H:i:s', $ts ) : 'available';
	}

	private static function sensitive_keys(): array {
		return array(
			'auspost_api_key',
			'tracking_email_events_auth_token',
			'email_preferences_webhook_secret',
		);
	}

	private function encrypt_sensitive_value( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$encrypted = self::maybe_encrypt( $value );

		return '' !== $encrypted ? $encrypted : $value;
	}

	private static function decrypt_sensitive_value( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 0 !== strpos( $value, 'enc:' ) ) {
			return $value;
		}

		$decrypted = self::maybe_decrypt( $value );

		return '' !== $decrypted ? $decrypted : '';
	}

	private static function maybe_encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );

		try {
			$iv = random_bytes( 16 );
		} catch ( Exception $exception ) {
			unset( $exception );
			return '';
		}

		$cipher_raw = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( ! is_string( $cipher_raw ) || '' === $cipher_raw ) {
			return '';
		}

		$payload = base64_encode( $iv . $cipher_raw );

		return 'enc:' . $payload;
	}

	private static function maybe_decrypt( string $encoded ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$payload = substr( $encoded, 4 );
		$raw     = base64_decode( $payload, true );

		if ( ! is_string( $raw ) || strlen( $raw ) <= 16 ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$iv  = substr( $raw, 0, 16 );
		$cipher_raw = substr( $raw, 16 );
		$plain = openssl_decrypt( $cipher_raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return is_string( $plain ) ? $plain : '';
	}
}
