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
	}

	public function sanitize_settings( array $input ): array {
		$current = get_option( self::OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();

		$current['auspost_api_key']             = isset( $input['auspost_api_key'] ) ? sanitize_text_field( (string) $input['auspost_api_key'] ) : '';
		$current['auspost_account_number']      = isset( $input['auspost_account_number'] ) ? sanitize_text_field( (string) $input['auspost_account_number'] ) : '';
		$current['tracking_sync_enabled']       = isset( $input['tracking_sync_enabled'] ) ? 'yes' : 'no';
		$current['tracking_sync_interval_hours'] = isset( $input['tracking_sync_interval_hours'] ) ? max( 1, min( 24, absint( $input['tracking_sync_interval_hours'] ) ) ) : 6;

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
		echo '<h2>' . esc_html__( 'Tracking Configuration', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'ck_ows_settings_group' );
		do_settings_sections( 'ck-ows-settings' );
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

	private function register_field( string $key, string $label, string $type ): void {
		add_settings_field(
			$key,
			esc_html( $label ),
			array( $this, 'render_field' ),
			'ck-ows-settings',
			'ck_ows_tracking_section',
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

		$name = self::OPTION_KEY . '[' . $key . ']';

		if ( 'checkbox' === $type ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( 'yes', (string) $value, false ) . '> ' . esc_html__( 'Enabled', 'ck-order-workflow-suite' ) . '</label>';
			return;
		}

		if ( 'number' === $type ) {
			echo '<input type="number" min="1" max="24" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="small-text">';
			return;
		}

		echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text" autocomplete="off">';
	}

	private function get_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none"><path d="M3 6.25h14M3 10h14M3 13.75h9" stroke="black" stroke-width="1.8" stroke-linecap="round"/><circle cx="15.2" cy="13.75" r="2.2" fill="black"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
