<?php
/**
 * Artwork event sync between WooCommerce and OverSeek.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Artwork_Events {
	private const ALLOWED_STATUSES      = array( 'uploaded', 'approval_requested', 'approved', 'changes_requested', 'override_used' );
	private const DEDUPE_TRANSIENT_BASE = 'ck_ows_artwork_evt_';

	private static ?CK_OWS_Artwork_Events $instance = null;

	public static function instance(): CK_OWS_Artwork_Events {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'overseek/v1',
			'/artwork-events',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_incoming_event' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_incoming_event( WP_REST_Request $request ): WP_REST_Response {
		$token = trim( (string) CK_OWS_Settings::get( 'artwork_events_auth_token', '' ) );

		if ( '' !== $token ) {
			$header = trim( (string) $request->get_header( 'authorization' ) );
			if ( '' === $header || 0 !== strpos( $header, 'Bearer ' ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => 'Missing bearer token' ), 401 );
			}

			$provided = trim( substr( $header, 7 ) );
			if ( ! hash_equals( $token, $provided ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid bearer token' ), 401 );
			}
		}

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

		CK_OWS_Audit::log_order_event( $order, 'artwork_event_dispatched', array( 'event_status' => $event_status, 'http' => $status_code ) );
		return true;
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
			'proof_url'       => isset( $event['proof_url'] ) ? esc_url_raw( (string) $event['proof_url'] ) : '',
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
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return '';
		}

		$path      = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query     = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$sanitized = 'https://' . $parts['host'] . $path;

		if ( '' !== $query ) {
			$sanitized .= '?' . $query;
		}

		return esc_url_raw( $sanitized );
	}

	private function is_duplicate_event( array $event ): bool {
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
}
