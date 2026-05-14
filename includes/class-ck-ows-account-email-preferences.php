<?php
/**
 * Account email preferences endpoint module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Email_Preferences {
	private static ?CK_OWS_Account_Email_Preferences $instance = null;
	private const SAVE_PREFS_NONCE       = 'ck_ows_save_email_preferences';
	private const SAVE_PREFS_NONCE_FIELD = 'ck_ows_save_email_preferences_nonce';

	public static function instance(): CK_OWS_Account_Email_Preferences {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ), 99 );
		add_action( 'woocommerce_account_email-preferences_endpoint', array( $this, 'render_endpoint' ) );
		add_action( 'admin_post_ck_ows_save_email_preferences', array( $this, 'handle_update' ) );
	}

	public function register_endpoint(): void {
		add_rewrite_endpoint( 'email-preferences', EP_ROOT | EP_PAGES );
	}

	public function add_menu_item( array $items ): array {
		return CK_OWS_Account_Menu_Helper::insert_before_logout(
			$items,
			'email-preferences',
			__( 'Email Preferences', 'ck-order-workflow-suite' )
		);
	}

	public function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$config = $this->get_api_config();

		echo '<div class="ck-ows-email-preferences">';
		echo '<h3>' . esc_html__( 'Email Preferences', 'ck-order-workflow-suite' ) . '</h3>';
		echo '<p>' . esc_html__( 'Choose which communications you would like to receive.', 'ck-order-workflow-suite' ) . '</p>';

		if ( ! $config['is_configured'] ) {
			echo '<p>' . esc_html__( 'Email preferences are not configured yet. Please contact support.', 'ck-order-workflow-suite' ) . '</p>';
			echo '</div>';
			return;
		}

		$user        = wp_get_current_user();
		$email       = strtolower( (string) $user->user_email );

		if ( ! is_email( $email ) ) {
			echo '<p>' . esc_html__( 'Unable to load email preferences for this account. Please contact support.', 'ck-order-workflow-suite' ) . '</p>';
			echo '</div>';
			return;
		}

		$preferences = $this->fetch_preferences( $config, $email );

		if ( is_wp_error( $preferences ) ) {
			echo '<p>' . esc_html( $preferences->get_error_message() ) . '</p>';
			echo '</div>';
			return;
		}

		$pref_data            = is_array( $preferences['preferences'] ?? null ) ? $preferences['preferences'] : array();
		$global_subscribed    = isset( $pref_data['globalSubscribed'] ) ? (bool) $pref_data['globalSubscribed'] : true;
		$marketing_subscribed = isset( $pref_data['marketingSubscribed'] ) ? (bool) $pref_data['marketingSubscribed'] : true;
		$lists                = is_array( $pref_data['lists'] ?? null ) ? $pref_data['lists'] : array();

		if ( isset( $_GET['ck_ows_pref_saved'] ) ) {
			echo '<div class="woocommerce-message" role="alert">' . esc_html__( 'Email preferences updated.', 'ck-order-workflow-suite' ) . '</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ck_ows_save_email_preferences">';
		wp_nonce_field( self::SAVE_PREFS_NONCE, self::SAVE_PREFS_NONCE_FIELD );

		echo '<input type="hidden" name="global_subscribed" value="0">';
		echo '<label><input type="checkbox" name="global_subscribed" value="1" ' . checked( true, $global_subscribed, false ) . '> ' . esc_html__( 'Receive all email communications', 'ck-order-workflow-suite' ) . '</label>';

		echo '<br><br>';
		echo '<input type="hidden" name="marketing_subscribed" value="0">';
		echo '<label><input type="checkbox" name="marketing_subscribed" value="1" ' . checked( true, $marketing_subscribed, false ) . '> ' . esc_html__( 'Receive marketing and promotional emails', 'ck-order-workflow-suite' ) . '</label>';

		if ( ! empty( $lists ) ) {
			echo '<h4>' . esc_html__( 'Mailing Lists', 'ck-order-workflow-suite' ) . '</h4>';

			foreach ( $lists as $list ) {
				$list_id       = isset( $list['id'] ) ? (string) $list['id'] : '';
				$list_name     = isset( $list['name'] ) ? (string) $list['name'] : '';
				$list_desc     = isset( $list['description'] ) ? (string) $list['description'] : '';
				$is_subscribed = ! empty( $list['isSubscribed'] );

				if ( '' === $list_id || '' === $list_name ) {
					continue;
				}

				echo '<label style="display:block;margin:0 0 10px;">';
				echo '<input type="checkbox" name="list_ids[]" value="' . esc_attr( $list_id ) . '" ' . checked( true, $is_subscribed, false ) . '> ';
				echo '<strong>' . esc_html( $list_name ) . '</strong>';

				if ( '' !== $list_desc ) {
					echo '<br><small>' . esc_html( $list_desc ) . '</small>';
				}

				echo '</label>';
			}
		}

		submit_button( __( 'Save Email Preferences', 'ck-order-workflow-suite' ) );
		echo '</form>';
		echo '</div>';
	}

	public function handle_update(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to update preferences.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( self::SAVE_PREFS_NONCE, self::SAVE_PREFS_NONCE_FIELD );

		$config = $this->get_api_config();

		if ( ! $config['is_configured'] ) {
			$this->redirect_to_page();
		}

		$user                 = wp_get_current_user();
		$email                = strtolower( (string) $user->user_email );

		if ( ! is_email( $email ) ) {
			$this->redirect_to_page();
		}

		$global_subscribed    = isset( $_POST['global_subscribed'] ) && '1' === (string) wp_unslash( $_POST['global_subscribed'] );
		$marketing_subscribed = isset( $_POST['marketing_subscribed'] ) && '1' === (string) wp_unslash( $_POST['marketing_subscribed'] );
		$list_ids_raw         = isset( $_POST['list_ids'] ) && is_array( $_POST['list_ids'] ) ? wp_unslash( $_POST['list_ids'] ) : array();
		$list_ids             = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $list_ids_raw ) ) ) );

		$payload = array(
			'accountId'           => $config['account_id'],
			'email'               => $email,
			'listIds'             => $list_ids,
			'marketingSubscribed' => $marketing_subscribed,
			'globalSubscribed'    => $global_subscribed,
			'reason'              => 'Updated via customer account page',
		);

		$response = wp_remote_post(
			$config['base_url'] . '/api/email/preferences/public',
			array(
				'headers' => $this->build_headers( $config ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->redirect_to_page();
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code > 299 ) {
			$this->redirect_to_page();
		}

		$this->redirect_to_page( true );
	}

	private function fetch_preferences( array $config, string $email ) {
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'ck_ows_email_pref_invalid_email', __( 'Email address is not valid for preference lookup.', 'ck-order-workflow-suite' ) );
		}

		$url = add_query_arg(
			array(
				'accountId' => $config['account_id'],
				'email'     => $email,
			),
			$config['base_url'] . '/api/email/preferences/public'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $this->build_headers( $config ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ck_ows_email_pref_api_error', __( 'Unable to load email preferences right now. Please try again later.', 'ck-order-workflow-suite' ) );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code > 299 ) {
			return new WP_Error( 'ck_ows_email_pref_api_status', __( 'Unable to load email preferences right now. Please try again later.', 'ck-order-workflow-suite' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return new WP_Error( 'ck_ows_email_pref_api_invalid', __( 'Email preferences response was invalid.', 'ck-order-workflow-suite' ) );
		}

		return $data;
	}

	private function get_api_config(): array {
		$base_url = $this->normalize_api_base_url( (string) CK_OWS_Settings::get( 'email_preferences_api_base_url', '' ) );
		$account  = (string) CK_OWS_Settings::get( 'email_preferences_account_id', '' );
		$secret   = (string) CK_OWS_Settings::get( 'email_preferences_webhook_secret', '' );
		$allowed  = $this->is_allowed_api_base_url( $base_url );

		return array(
			'base_url'      => $base_url,
			'account_id'    => $account,
			'webhook_secret'=> $secret,
			'is_configured' => '' !== $base_url && '' !== $account && $allowed,
		);
	}

	private function normalize_api_base_url( string $base_url ): string {
		$base_url = untrailingslashit( trim( $base_url ) );

		if ( '' === $base_url ) {
			return '';
		}

		$parts = wp_parse_url( $base_url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		if ( 'https' !== $scheme ) {
			return '';
		}

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		return esc_url_raw( $base_url );
	}

	private function is_allowed_api_base_url( string $base_url ): bool {
		if ( '' === $base_url ) {
			return false;
		}

		$host = wp_parse_url( $base_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );

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

	private function build_headers( array $config ): array {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( '' !== $config['webhook_secret'] ) {
			$headers['x-overseek-webhook-secret'] = $config['webhook_secret'];
		}

		return $headers;
	}

	private function redirect_to_page( bool $saved = false ): void {
		$redirect_url = wc_get_endpoint_url( 'email-preferences', '', wc_get_page_permalink( 'myaccount' ) );

		if ( $saved ) {
			$redirect_url = add_query_arg( 'ck_ows_pref_saved', '1', $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
