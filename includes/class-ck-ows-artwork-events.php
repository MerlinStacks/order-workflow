<?php
/**
 * Artwork event sync between WooCommerce and OverSeek.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Artwork_Events extends CK_OWS_Base {
	private const ALLOWED_STATUSES      = array( 'uploaded', 'approval_requested', 'approved', 'changes_requested', 'override_used' );
	private const DEDUPE_TRANSIENT_BASE = 'ck_ows_artwork_evt_';
	private const RETRY_HOOK            = 'ck_ows_artwork_event_retry';

	protected function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::RETRY_HOOK, array( $this, 'retry_event_delivery' ), 10, 1 );
	}

	public function register_routes(): void {
		register_rest_route(
			'overseek/v1',
			'/artwork-events',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_incoming_event' ),
				'permission_callback' => array( $this, 'authenticate_incoming_event_request' ),
			)
		);
	}

	public function authenticate_incoming_event_request( WP_REST_Request $request ) {
		$token = trim( (string) CK_OWS_Settings::get( 'artwork_events_auth_token', '' ) );

		if ( '' === $token ) {
			return new WP_Error( 'ck_ows_artwork_events_token_missing', __( 'Artwork events endpoint is not configured.', 'ck-order-workflow-suite' ), array( 'status' => 403 ) );
		}

		$header = trim( (string) $request->get_header( 'authorization' ) );

		if ( '' === $header || 0 !== strpos( $header, 'Bearer ' ) ) {
			return new WP_Error( 'ck_ows_artwork_events_token_required', __( 'Missing bearer token.', 'ck-order-workflow-suite' ), array( 'status' => 401 ) );
		}

		$provided = trim( substr( $header, 7 ) );

		if ( ! hash_equals( $token, $provided ) ) {
			return new WP_Error( 'ck_ows_artwork_events_token_invalid', __( 'Invalid bearer token.', 'ck-order-workflow-suite' ), array( 'status' => 401 ) );
		}

		return true;
	}

	public function handle_incoming_event( WP_REST_Request $request ): WP_REST_Response {
		$event = $request->get_param( 'event' );
		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Missing or invalid event object' ), 400 );
		}

		$validated = $this->sanitize_event_payload( $event );
		if ( is_wp_error( $validated ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $validated->get_error_message() ), 400 );
		}

		$status = (string) $validated['event_status'];
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'skipped' => true, 'reason' => 'unsupported_status' ), 202 );
		}

		if ( ! class_exists( 'OverSeek_Main' ) && ! defined( 'OVERSEEK_WC_VERSION' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'OverSeek plugin not connected' ), 503 );
		}

		if ( $this->is_duplicate_event( $validated ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 202 );
		}

		$order = wc_get_order( (int) $validated['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Order not found' ), 400 );
		}

		$applied = $this->apply_event_to_order( $order, $validated );
		if ( is_wp_error( $applied ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => $applied->get_error_message() ), 502 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'forwarded' => true ), 202 );
	}

	public function dispatch_event_for_order( WC_Order $order, string $event_status, array $extra = array() ): bool {
		if ( ! in_array( $event_status, self::ALLOWED_STATUSES, true ) ) {
			return false;
		}

		$webhook_url = $this->resolve_artwork_events_webhook_url();
		if ( '' === $webhook_url ) {
			return false;
		}

		$event = $this->build_event_payload( $order, $event_status, $extra );
		if ( empty( $event ) ) {
			return false;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout' => max( 3, min( 30, absint( CK_OWS_Settings::get( 'tracking_email_events_timeout_seconds', 10 ) ) ) ),
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( array( 'event' => $event ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->track_delivery_result( false, $order->get_id(), $event, $response->get_error_message() );
			$this->schedule_retry( $order->get_id(), $event, 1, $response->get_error_message() );
			do_action( 'ck_ows_artwork_event_delivery_failed', $order->get_id(), $event, $response->get_error_message() );
			CK_OWS_Audit::log_order_event(
				$order,
				'artwork_event_dispatch_failed',
				array(
					'event_status' => $event_status,
					'error'        => $response->get_error_message(),
				)
			);
			return false;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->track_delivery_result( false, $order->get_id(), $event, 'HTTP ' . $status_code );
			$this->schedule_retry( $order->get_id(), $event, 1, 'HTTP ' . $status_code );
			do_action( 'ck_ows_artwork_event_delivery_failed', $order->get_id(), $event, 'HTTP ' . $status_code );
			CK_OWS_Audit::log_order_event(
				$order,
				'artwork_event_dispatch_failed',
				array(
					'event_status' => $event_status,
					'error'        => 'HTTP ' . $status_code,
				)
			);
			return false;
		}

		$this->track_delivery_result( true, $order->get_id(), $event, 'HTTP ' . $status_code );
		CK_OWS_Audit::log_order_event( $order, 'artwork_event_dispatched', array( 'event_status' => $event_status, 'http' => $status_code ) );
		do_action( 'ck_ows_artwork_event_delivered', $order->get_id(), $event, $status_code );
		return true;
	}

	public function retry_event_delivery( array $payload ): void {
		$order_id = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$attempt  = isset( $payload['attempt'] ) ? absint( $payload['attempt'] ) : 1;
		$event    = isset( $payload['event'] ) && is_array( $payload['event'] ) ? $payload['event'] : array();

		if ( $order_id <= 0 || empty( $event ) ) {
			return;
		}

		$webhook_url = $this->resolve_artwork_events_webhook_url();
		if ( '' === $webhook_url ) {
			$this->schedule_retry( $order_id, $event, $attempt + 1, 'Missing webhook URL' );
			return;
		}

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout' => max( 3, min( 30, absint( CK_OWS_Settings::get( 'tracking_email_events_timeout_seconds', 10 ) ) ) ),
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( array( 'event' => $event ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->track_delivery_result( false, $order_id, $event, $response->get_error_message() );
			$this->schedule_retry( $order_id, $event, $attempt + 1, $response->get_error_message() );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->track_delivery_result( false, $order_id, $event, 'HTTP ' . $status_code );
			$this->schedule_retry( $order_id, $event, $attempt + 1, 'HTTP ' . $status_code );
			return;
		}

		$this->track_delivery_result( true, $order_id, $event, 'HTTP ' . $status_code );
	}

	private function sanitize_event_payload( array $event ) {
		$order_id = isset( $event['order_id'] ) ? absint( $event['order_id'] ) : 0;
		$status   = isset( $event['event_status'] ) ? sanitize_key( (string) $event['event_status'] ) : '';

		if ( $order_id <= 0 ) {
			return new WP_Error( 'invalid_order_id', 'Missing or invalid order_id' );
		}

		if ( '' === $status ) {
			return new WP_Error( 'invalid_event_status', 'Missing event_status' );
		}

		return array(
			'event_name'      => isset( $event['event_name'] ) ? sanitize_text_field( (string) $event['event_name'] ) : '',
			'event_status'    => $status,
			'occurred_at'     => isset( $event['occurred_at'] ) ? sanitize_text_field( (string) $event['occurred_at'] ) : gmdate( 'c' ),
			'order_id'        => $order_id,
			'order_number'    => isset( $event['order_number'] ) ? sanitize_text_field( (string) $event['order_number'] ) : '',
			'customer_email'  => isset( $event['customer_email'] ) ? sanitize_email( (string) $event['customer_email'] ) : '',
			'customer_phone'  => isset( $event['customer_phone'] ) ? sanitize_text_field( (string) $event['customer_phone'] ) : '',
			'customer_name'   => isset( $event['customer_name'] ) ? sanitize_text_field( (string) $event['customer_name'] ) : '',
			'proof_url'       => isset( $event['proof_url'] ) ? $this->sanitize_allowed_proof_url( (string) $event['proof_url'] ) : '',
			'proof_version'   => isset( $event['proof_version'] ) ? sanitize_text_field( (string) $event['proof_version'] ) : '',
			'notes'           => isset( $event['notes'] ) ? sanitize_textarea_field( (string) $event['notes'] ) : '',
			'staff_user'      => isset( $event['staff_user'] ) ? sanitize_text_field( (string) $event['staff_user'] ) : '',
			'source'          => isset( $event['source'] ) ? sanitize_key( (string) $event['source'] ) : '',
			'source_version'  => isset( $event['source_version'] ) ? sanitize_text_field( (string) $event['source_version'] ) : '',
		);
	}

	private function apply_event_to_order( WC_Order $order, array $event ) {
		$proof_url = (string) $event['proof_url'];
		$status    = (string) $event['event_status'];

		if ( in_array( $status, array( 'uploaded', 'approval_requested' ), true ) ) {
			if ( '' !== $proof_url ) {
				$order->update_meta_data( CK_OWS_Artwork_Proof::META_PROOF_URL, $proof_url );
			}
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_APPROVAL_STATE, CK_OWS_Artwork_Proof::STATE_PENDING );
			if ( 'awaiting-artwork' !== $order->get_status() ) {
				$order->update_status( 'awaiting-artwork', __( 'Artwork proof received from OverSeek.', 'ck-order-workflow-suite' ), true );
			}
		}

		if ( 'approved' === $status ) {
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_APPROVAL_STATE, CK_OWS_Artwork_Proof::STATE_APPROVED );
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_APPROVED_AT, time() );
			if ( 'awaiting-artwork' === $order->get_status() ) {
				$order->update_status( 'in-production', __( 'Artwork approved via OverSeek event.', 'ck-order-workflow-suite' ), true );
			}
		}

		if ( 'changes_requested' === $status ) {
			$note = '' !== trim( (string) $event['notes'] ) ? (string) $event['notes'] : __( 'Changes requested in OverSeek.', 'ck-order-workflow-suite' );
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_APPROVAL_STATE, CK_OWS_Artwork_Proof::STATE_CHANGES );
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_CHANGES_REQUESTED_AT, time() );
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_CHANGES_REQUEST_MESSAGE, $note );
			if ( 'awaiting-artwork' !== $order->get_status() ) {
				$order->update_status( 'awaiting-artwork', __( 'Artwork changes requested via OverSeek event.', 'ck-order-workflow-suite' ), true );
			}
		}

		if ( 'override_used' === $status ) {
			$reason = '' !== trim( (string) $event['notes'] ) ? (string) $event['notes'] : __( 'Staff override used in OverSeek.', 'ck-order-workflow-suite' );
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_OVERRIDE_REASON, $reason );
			$order->update_meta_data( CK_OWS_Artwork_Proof::META_OVERRIDE_AT, time() );
			$order->update_status( 'in-production', __( 'Staff override received from OverSeek event.', 'ck-order-workflow-suite' ), true );
		}

		$order->save();
		$order->add_order_note( sprintf( __( 'Artwork event received: %s', 'ck-order-workflow-suite' ), $status ) );
		CK_OWS_Audit::log_order_event( $order, 'artwork_event_received', array( 'event_status' => $status ) );

		return true;
	}

	private function sanitize_allowed_proof_url( string $url ): string {
		$url = CK_OWS_Utils::sanitize_https_url( $url );

		if ( '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$allowed_hosts = apply_filters(
			'ck_ows_artwork_proof_allowed_hosts',
			array_values( array_filter( array_merge( array( $home ), CK_OWS_Utils::default_overseek_allowed_hosts() ) ) )
		);

		return is_string( $host ) && is_array( $allowed_hosts ) && CK_OWS_Utils::is_allowed_host( $host, $allowed_hosts ) ? $url : '';
	}

	private function build_event_payload( WC_Order $order, string $event_status, array $extra ): array {
		$proof_url = (string) $order->get_meta( CK_OWS_Artwork_Proof::META_PROOF_URL, true );
		$revisions = $order->get_meta( CK_OWS_Artwork_Proof::META_PROOF_REVISIONS, true );
		$version   = '1';

		if ( is_array( $revisions ) && ! empty( $revisions ) ) {
			$version = (string) count( $revisions );
		}

		$event_name_map = array(
			'uploaded'           => 'artwork_uploaded',
			'approval_requested' => 'artwork_approval_requested',
			'approved'           => 'artwork_approved',
			'changes_requested'  => 'artwork_changes_requested',
			'override_used'      => 'artwork_override_used',
		);

		$current_user = wp_get_current_user();
		$staff_user   = '';
		if ( $current_user instanceof WP_User ) {
			$staff_user = '' !== trim( (string) $current_user->user_email ) ? (string) $current_user->user_email : (string) $current_user->user_login;
		}

		$notes = isset( $extra['notes'] ) ? sanitize_textarea_field( (string) $extra['notes'] ) : '';

		return array(
			'event_id'        => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'ck-ows-artwork-', true ) ),
			'schema_version'  => '1',
			'event_name'      => $event_name_map[ $event_status ] ?? 'artwork_event',
			'event_status'    => $event_status,
			'occurred_at'     => gmdate( 'c' ),
			'order_id'        => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'customer_email'  => (string) $order->get_billing_email(),
			'customer_phone'  => (string) $order->get_billing_phone(),
			'customer_name'   => trim( $order->get_formatted_billing_full_name() ),
			'proof_url'       => $proof_url,
			'proof_version'   => $version,
			'notes'           => $notes,
			'staff_user'      => $staff_user,
			'source'          => 'ck_order_workflow_suite',
			'source_site'     => home_url(),
			'source_version'  => CK_OWS_VERSION,
		);
	}

	private function build_headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$token = trim( (string) CK_OWS_Settings::get( 'artwork_events_auth_token', '' ) );

		if ( '' === $token ) {
			$token = trim( (string) get_option( 'overseek_relay_api_key', '' ) );
		}

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	private function resolve_artwork_events_webhook_url(): string {
		$configured = trim( (string) CK_OWS_Settings::get( 'artwork_events_webhook_url', '' ) );

		if ( '' !== $configured ) {
			return esc_url_raw( $configured );
		}

		$health_url = home_url( '/wp-json/overseek/v1/health' );
		$response   = wp_remote_get(
			$health_url,
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->sanitize_https_url( home_url( '/wp-json/overseek/v1/artwork-events' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $this->sanitize_https_url( home_url( '/wp-json/overseek/v1/artwork-events' ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return $this->sanitize_https_url( home_url( '/wp-json/overseek/v1/artwork-events' ) );
		}

		$url = isset( $decoded['artworkEventsWebhookUrl'] ) ? trim( (string) $decoded['artworkEventsWebhookUrl'] ) : '';

		if ( '' !== $url ) {
			return $this->sanitize_https_url( $url );
		}

		return $this->sanitize_https_url( home_url( '/wp-json/overseek/v1/artwork-events' ) );
	}

	private function sanitize_https_url( string $url ): string {
		return CK_OWS_Utils::sanitize_https_url( $url );
	}

	private function is_duplicate_event( array $event ): bool {
		if ( isset( $event['event_id'] ) && '' !== trim( (string) $event['event_id'] ) ) {
			$key = self::DEDUPE_TRANSIENT_BASE . md5( 'id|' . (string) $event['event_id'] );
			if ( false !== get_transient( $key ) ) {
				return true;
			}

			set_transient( $key, '1', 7 * DAY_IN_SECONDS );

			return false;
		}

		$key_material = implode(
			'|',
			array(
				(string) ( $event['order_id'] ?? '' ),
				(string) ( $event['proof_version'] ?? '' ),
				(string) ( $event['event_status'] ?? '' ),
				(string) ( $event['occurred_at'] ?? '' ),
			)
		);

		if ( '' === trim( $key_material, '|' ) ) {
			return false;
		}

		$key = self::DEDUPE_TRANSIENT_BASE . md5( $key_material );
		if ( false !== get_transient( $key ) ) {
			return true;
		}

		set_transient( $key, '1', 7 * DAY_IN_SECONDS );

		return false;
	}

	private function track_delivery_result( bool $ok, int $order_id, array $event, string $message ): void {
		update_option(
			'ck_ows_last_artwork_webhook_delivery',
			array(
				'ts'           => time(),
				'ok'           => $ok,
				'order_id'     => $order_id,
				'event_status' => isset( $event['event_status'] ) ? sanitize_key( (string) $event['event_status'] ) : '',
				'event_id'     => isset( $event['event_id'] ) ? sanitize_text_field( (string) $event['event_id'] ) : '',
				'message'      => sanitize_text_field( $message ),
			),
			false
		);
	}

	private function schedule_retry( int $order_id, array $event, int $attempt, string $error_message ): void {
		$max_attempts = max( 0, min( 5, absint( CK_OWS_Settings::get( 'tracking_email_events_retry_attempts', 3 ) ) ) );

		if ( $attempt > $max_attempts ) {
			$this->push_dead_letter( $order_id, $event, $attempt, $error_message );
			return;
		}

		$backoff_minutes = max( 1, min( 60, absint( CK_OWS_Settings::get( 'tracking_email_events_retry_backoff_minutes', 5 ) ) ) );
		$delay_seconds   = max( 60, $backoff_minutes * 60 * max( 1, $attempt ) );

		wp_schedule_single_event(
			time() + $delay_seconds,
			self::RETRY_HOOK,
			array(
				array(
					'order_id' => $order_id,
					'event'    => $event,
					'attempt'  => $attempt,
				),
			)
		);
	}

	private function push_dead_letter( int $order_id, array $event, int $attempts, string $error_message ): void {
		$rows = get_option( 'ck_ows_artwork_event_dead_letters', array() );
		$rows = is_array( $rows ) ? $rows : array();

		$rows[] = array(
			'ts'         => time(),
			'order_id'   => $order_id,
			'attempts'   => max( 1, $attempts ),
			'last_error' => sanitize_text_field( $error_message ),
			'event'      => $event,
		);

		if ( count( $rows ) > 50 ) {
			$rows = array_slice( $rows, -50 );
		}

		update_option( 'ck_ows_artwork_event_dead_letters', $rows, false );
	}
}
