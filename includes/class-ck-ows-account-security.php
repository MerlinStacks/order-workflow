<?php
/**
 * Account security panel module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Security {
	private const META_LAST_LOGIN_TS      = '_ck_ows_last_login_ts';
	private const META_LAST_PASSWORD_TS   = '_ck_ows_last_password_change_ts';

	private static ?CK_OWS_Account_Security $instance = null;

	public static function instance(): CK_OWS_Account_Security {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ), 99 );
		add_action( 'woocommerce_account_security_endpoint', array( $this, 'render_endpoint' ) );

		add_action( 'wp_login', array( $this, 'track_login' ), 10, 2 );
		add_action( 'after_password_reset', array( $this, 'track_password_reset' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( $this, 'track_account_password_change' ), 20, 1 );
	}

	public function register_endpoint(): void {
		add_rewrite_endpoint( 'security', EP_ROOT | EP_PAGES );
	}

	public function add_menu_item( array $items ): array {
		$new_items = array();

		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new_items['security'] = __( 'Security', 'ck-order-workflow-suite' );
			}

			$new_items[ $key ] = $label;
		}

		return $new_items;
	}

	public function track_login( string $user_login, WP_User $user ): void {
		unset( $user_login );
		update_user_meta( $user->ID, self::META_LAST_LOGIN_TS, time() );
	}

	public function track_password_reset( WP_User $user, string $new_pass ): void {
		unset( $new_pass );
		update_user_meta( $user->ID, self::META_LAST_PASSWORD_TS, time() );
	}

	public function track_account_password_change( int $user_id ): void {
		$pass_1 = isset( $_POST['password_1'] ) ? trim( (string) wp_unslash( $_POST['password_1'] ) ) : '';
		$pass_2 = isset( $_POST['password_2'] ) ? trim( (string) wp_unslash( $_POST['password_2'] ) ) : '';

		if ( '' !== $pass_1 && $pass_1 === $pass_2 ) {
			update_user_meta( $user_id, self::META_LAST_PASSWORD_TS, time() );
		}
	}

	public function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id         = get_current_user_id();
		$last_login_ts   = (int) get_user_meta( $user_id, self::META_LAST_LOGIN_TS, true );
		$last_pass_ts    = (int) get_user_meta( $user_id, self::META_LAST_PASSWORD_TS, true );
		$account_url     = wc_get_endpoint_url( 'edit-account', '', wc_get_page_permalink( 'myaccount' ) );

		echo '<div class="ck-ows-security">';
		echo '<h3>' . esc_html__( 'Account Activity and Security', 'ck-order-workflow-suite' ) . '</h3>';
		echo '<p>' . esc_html__( 'Review your recent account activity and keep your login details secure.', 'ck-order-workflow-suite' ) . '</p>';

		echo '<div class="ck-ows-security__grid">';
		echo '<div class="ck-ows-security__card">';
		echo '<strong>' . esc_html__( 'Last login', 'ck-order-workflow-suite' ) . '</strong>';
		echo '<div>' . esc_html( $this->format_ts( $last_login_ts, __( 'No login recorded yet.', 'ck-order-workflow-suite' ) ) ) . '</div>';
		echo '</div>';

		echo '<div class="ck-ows-security__card">';
		echo '<strong>' . esc_html__( 'Password last changed', 'ck-order-workflow-suite' ) . '</strong>';
		echo '<div>' . esc_html( $this->format_ts( $last_pass_ts, __( 'No password change recorded yet.', 'ck-order-workflow-suite' ) ) ) . '</div>';
		echo '</div>';
		echo '</div>';

		echo '<p>';
		echo '<a class="button" href="' . esc_url( $account_url ) . '">' . esc_html__( 'Change password', 'ck-order-workflow-suite' ) . '</a>';
		echo '</p>';

		echo '<ul class="ck-ows-security__tips">';
		echo '<li>' . esc_html__( 'Use a unique password you do not use on other websites.', 'ck-order-workflow-suite' ) . '</li>';
		echo '<li>' . esc_html__( 'Do not share your account login with anyone outside your team or household.', 'ck-order-workflow-suite' ) . '</li>';
		echo '<li>' . esc_html__( 'Be cautious of emails asking for your password or payment details.', 'ck-order-workflow-suite' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<style>';
		echo '.ck-ows-security{display:flex;flex-direction:column;gap:12px}';
		echo '.ck-ows-security__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}';
		echo '.ck-ows-security__card{padding:12px;border:1px solid #ececec;border-radius:10px;background:#fff}';
		echo '.ck-ows-security__tips{margin:0 0 0 18px;padding:0;display:flex;flex-direction:column;gap:6px}';
		echo '@media(max-width:640px){.ck-ows-security__grid{grid-template-columns:1fr}}';
		echo '</style>';
	}

	private function format_ts( int $timestamp, string $fallback ): string {
		if ( $timestamp <= 0 ) {
			return $fallback;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
