<?php
/**
 * Registration guard module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Registration_Guard extends CK_OWS_Base {
	private const MIN_SECONDS = 3;
	private const IP_LIMIT    = 5;
	private const LOG_MAX     = 200;
	private const LOG_TTL     = 90 * DAY_IN_SECONDS;
	private const OPTION_LOG  = 'ckrg_block_log';

	protected function __construct() {
		add_action( 'woocommerce_register_form', array( $this, 'inject_fields' ) );
		add_action( 'register_form', array( $this, 'inject_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration' ), 10, 4 );
		add_filter( 'registration_errors', array( $this, 'validate_wp_registration' ), 10, 3 );

		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 99 );
		add_action( 'admin_init', array( $this, 'redirect_legacy_admin_path' ) );
	}

	public function redirect_legacy_admin_path(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( '' === $uri || ! preg_match( '#/wp-admin/ck-reg-guard/?(?:\?|$)#', $uri ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ck-reg-guard' ) );
		exit;
	}

	public function inject_fields(): void {
		$token = wp_generate_password( 24, false );
		set_transient( 'ckrg_ts_' . $token, time(), 30 * MINUTE_IN_SECONDS );

		echo '<div class="ck-ows-reg-guard-trap" style="position:absolute;left:-9999px;height:0;overflow:hidden;" aria-hidden="true">';
		echo '<label for="ck_website_url">Website</label>';
		echo '<input type="text" id="ck_website_url" name="ck_website_url" tabindex="-1" autocomplete="off" value="">';
		echo '<input type="text" id="ck_company_name" name="ck_company_name" tabindex="-1" autocomplete="off" value="">';
		echo '</div>';
		echo '<input type="hidden" name="ck_reg_ts_token" value="' . esc_attr( $token ) . '">';
	}

	public function validate_registration( WP_Error $errors, string $username, string $password, string $email ): WP_Error {
		unset( $password );

		return $this->validate_registration_attempt( $errors, $username, $email );
	}

	public function validate_wp_registration( WP_Error $errors, string $username, string $email ): WP_Error {
		return $this->validate_registration_attempt( $errors, $username, $email );
	}

	private function validate_registration_attempt( WP_Error $errors, string $username, string $email ): WP_Error {

		$hp1 = sanitize_text_field( wp_unslash( $_POST['ck_website_url'] ?? '' ) );
		$hp2 = sanitize_text_field( wp_unslash( $_POST['ck_company_name'] ?? '' ) );
		if ( '' !== $hp1 || '' !== $hp2 ) {
			$this->log_block( $email, $username, 'honeypot' );
			$errors->add( 'bot_detected', __( 'Registration failed. Please try again.', 'woocommerce' ) );
			return $errors;
		}

		$token = sanitize_text_field( wp_unslash( $_POST['ck_reg_ts_token'] ?? '' ) );
		if ( '' !== $token ) {
			$page_load_ts = (int) get_transient( 'ckrg_ts_' . $token );
			delete_transient( 'ckrg_ts_' . $token );
			if ( $page_load_ts > 0 && ( time() - $page_load_ts ) < self::MIN_SECONDS ) {
				$this->log_block( $email, $username, 'too_fast' );
				$errors->add( 'bot_detected', __( 'Registration failed. Please try again.', 'woocommerce' ) );
				return $errors;
			}
		}

		$domain = strtolower( substr( strrchr( $email, '@' ) ?: '', 1 ) );
		if ( '' !== $domain && in_array( $domain, $this->get_blocked_domains(), true ) ) {
			$this->log_block( $email, $username, 'blocked_domain:' . $domain );
			$errors->add( 'invalid_email', __( 'That email domain is not accepted. Please use a real email address.', 'woocommerce' ) );
			return $errors;
		}

		if ( '' !== $username ) {
			if ( preg_match( '/^[a-z0-9]{14,}$/i', $username ) ) {
				$this->log_block( $email, $username, 'gibberish_username' );
				$errors->add( 'invalid_username', __( 'That username is not allowed. Please choose a real name.', 'woocommerce' ) );
				return $errors;
			}
			if ( preg_match( '/^\d{7,}$/', $username ) ) {
				$this->log_block( $email, $username, 'phone_username' );
				$errors->add( 'invalid_username', __( 'That username is not allowed. Please choose a real name.', 'woocommerce' ) );
				return $errors;
			}
		}

		$ip     = $this->get_ip();
		$ip_key = 'ckrg_ip_' . md5( $ip );
		$hits   = (int) get_transient( $ip_key );
		if ( $hits >= self::IP_LIMIT ) {
			$this->log_block( $email, $username, 'rate_limit:' . $ip );
			$errors->add( 'bot_detected', __( 'Too many registration attempts. Please try again later.', 'woocommerce' ) );
			return $errors;
		}

		set_transient( $ip_key, $hits + 1, HOUR_IN_SECONDS );

		return $errors;
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'ck-ows-settings',
			esc_html__( 'CK Registration Guard', 'ck-order-workflow-suite' ),
			esc_html__( 'Registration Guard', 'ck-order-workflow-suite' ),
			'manage_woocommerce',
			'ck-reg-guard',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No permission.', 'ck-order-workflow-suite' ) );
		}

		if ( isset( $_POST['ckrg_clear'] ) ) {
			check_admin_referer( 'ckrg_clear_log' );
			delete_option( self::OPTION_LOG );
			echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Block log cleared.', 'ck-order-workflow-suite' ) . '</strong></p></div>';
		}

		$stored_log = get_option( self::OPTION_LOG, array() );
		$stored_log = is_array( $stored_log ) ? $stored_log : array();
		$log        = $this->prune_log( $stored_log );

		if ( $log !== $stored_log ) {
			update_option( self::OPTION_LOG, $log, false );
		}
		$tally = array(
			'honeypot'       => 0,
			'too_fast'       => 0,
			'blocked_domain' => 0,
			'username'       => 0,
			'rate_limit'     => 0,
		);

		foreach ( $log as $entry ) {
			$reason = (string) ( $entry['reason'] ?? '' );
			if ( 0 === strpos( $reason, 'honeypot' ) ) {
				$tally['honeypot']++;
			} elseif ( 'too_fast' === $reason ) {
				$tally['too_fast']++;
			} elseif ( 0 === strpos( $reason, 'blocked_domain' ) ) {
				$tally['blocked_domain']++;
			} elseif ( substr( $reason, -8 ) === 'username' ) {
				$tally['username']++;
			} elseif ( 0 === strpos( $reason, 'rate_limit' ) ) {
				$tally['rate_limit']++;
			}
		}

		echo '<div class="wrap ck-ows-admin">';
		echo '<div class="ck-ows-hero">';
		echo '<h1>' . esc_html__( 'Registration Guard', 'ck-order-workflow-suite' ) . '</h1>';
		echo '<p>' . esc_html__( 'Review blocked WooCommerce registration attempts and tune anti-bot rules.', 'ck-order-workflow-suite' ) . '</p>';
		echo '</div>';

		echo '<div class="ck-ows-card">';
		echo '<h2>' . esc_html__( 'Block Summary', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Blocked registration attempts (latest entries).', 'ck-order-workflow-suite' ) . '</p>';
		/* translators: %d: total blocked registration attempts. */
		echo '<p>' . esc_html( sprintf( __( 'Total blocked: %d', 'ck-order-workflow-suite' ), count( $log ) ) ) . '</p>';
		/* translators: 1: honeypot count, 2: too fast count, 3: blocked domain count, 4: blocked username count, 5: rate limit count. */
		echo '<p>' . esc_html( sprintf( __( 'Honeypot: %1$d | Too fast: %2$d | Bad domain: %3$d | Bad username: %4$d | Rate limit: %5$d', 'ck-order-workflow-suite' ), $tally['honeypot'], $tally['too_fast'], $tally['blocked_domain'], $tally['username'], $tally['rate_limit'] ) ) . '</p>';

		if ( ! empty( $log ) ) {
			echo '<form method="post" style="margin:12px 0;">';
			wp_nonce_field( 'ckrg_clear_log' );
			echo '<input type="hidden" name="ckrg_clear" value="1">';
			echo '<button class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Clear all block logs?', 'ck-order-workflow-suite' ) ) . '\');">' . esc_html__( 'Clear Log', 'ck-order-workflow-suite' ) . '</button>';
			echo '</form>';

			echo '<div style="overflow-x:auto;">';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Email', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Username', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'Reason', 'ck-order-workflow-suite' ) . '</th><th>' . esc_html__( 'IP', 'ck-order-workflow-suite' ) . '</th></tr></thead><tbody>';
			foreach ( $log as $entry ) {
				echo '<tr>';
				echo '<td>' . esc_html( wp_date( 'd M Y H:i', (int) ( $entry['ts'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry['email'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $entry['username'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $entry['reason'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $entry['ip'] ?? '' ) ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'No blocks logged yet.', 'ck-order-workflow-suite' ) . '</p>';
		}

		echo '</div>';
		echo '</div>';
	}

	private function log_block( string $email, string $username, string $reason ): void {
		$log = get_option( self::OPTION_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$log = $this->prune_log( $log );

		array_unshift(
			$log,
			array(
				'ts'       => time(),
				'email'    => $email,
				'username' => $username,
				'reason'   => $reason,
				'ip'       => $this->get_ip(),
			)
		);

		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	private function prune_log( array $log ): array {
		$min_ts = time() - self::LOG_TTL;

		$log = array_values(
			array_filter(
				$log,
				static function ( $entry ) use ( $min_ts ): bool {
					if ( ! is_array( $entry ) ) {
						return false;
					}

					$ts = isset( $entry['ts'] ) ? absint( $entry['ts'] ) : 0;

					return $ts >= $min_ts;
				}
			)
		);

		return array_slice( $log, 0, self::LOG_MAX );
	}

	private function get_ip(): string {
		$candidates = array();

		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidates[] = trim( (string) reset( $forwarded ) );
		}

		$candidates[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

		foreach ( $candidates as $candidate ) {
			$ip = is_string( $candidate ) ? trim( $candidate ) : '';

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	private function get_blocked_domains(): array {
		$domains = self::default_blocked_domains();

		$custom_domains_raw = CK_OWS_Settings::get( 'registration_blocked_domains', '' );
		$custom_domains     = preg_split( '/\r\n|\r|\n/', (string) $custom_domains_raw );

		if ( is_array( $custom_domains ) && ! empty( $custom_domains ) ) {
			$custom_domains = array_values(
				array_filter(
					array_map(
						'strtolower',
						array_map( 'trim', array_map( 'strval', $custom_domains ) )
					)
				)
			);

			$domains = array_values( array_unique( array_merge( $domains, $custom_domains ) ) );
		}

		return apply_filters( 'ck_ows_registration_blocked_domains', $domains );
	}

	public static function default_blocked_domains(): array {
		return array(
			'mailinator.com',
			'guerrillamail.com',
			'tempmail.com',
			'temp-mail.org',
			'throwaway.email',
			'sharklasers.com',
			'yopmail.com',
			'trashmail.com',
			'dispostable.com',
			'fakeinbox.com',
			'maildrop.cc',
			'spamgourmet.com',
			'txt.bell.ca',
			'txt.telus.com',
			'fido.ca',
			'pcs.rogers.com',
			'msg.telus.com',
			'msg.koodomobile.com',
		);
	}
}
