<?php
/**
 * Tracking to email platform event forwarding.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Tracking_Email_Events {
	private const TRANSIENT_DEDUP_PREFIX = 'ck_ows_track_evt_';

	private static ?CK_OWS_Tracking_Email_Events $instance = null;

	public static function instance(): CK_OWS_Tracking_Email_Events {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'ck_ows_tracking_updated', array( $this, 'forward_event_to_email_platform' ), 10, 2 );
	}

	public function forward_event_to_email_platform( int $order_id, array $tracking_payload ): void {
		if ( 'yes' !== CK_OWS_Settings::get( 'tracking_email_events_enabled', 'no' ) ) {
			return;
		}

		$webhook_url = $this->sanitize_https_url( (string) CK_OWS_Settings::get( 'tracking_email_events_webhook_url', '' ) );
		if ( '' === $webhook_url ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$normalized_event = $this->build_normalized_event( $order, $tracking_payload );
		if ( empty( $normalized_event ) ) {
			return;
		}

		if ( ! $this->should_dispatch_event( $normalized_event ) ) {
			return;
		}

		$request_body = array(
			'event' => $normalized_event,
		);

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$token = trim( (string) CK_OWS_Settings::get( 'tracking_email_events_auth_token', '' ) );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$timeout = max( 3, min( 30, absint( CK_OWS_Settings::get( 'tracking_email_events_timeout_seconds', 10 ) ) ) );

		$response = wp_remote_post(
			$webhook_url,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			do_action( 'ck_ows_tracking_event_delivery_failed', $order_id, $normalized_event, $response->get_error_message() );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			do_action( 'ck_ows_tracking_event_delivery_failed', $order_id, $normalized_event, 'HTTP ' . $status_code );
			return;
		}

		do_action( 'ck_ows_tracking_event_delivered', $order_id, $normalized_event, $status_code );
	}

	private function build_normalized_event( WC_Order $order, array $tracking_payload ): array {
		$raw_status         = strtolower( trim( (string) ( $tracking_payload['status'] ?? '' ) ) );
		$raw_description    = strtolower( trim( (string) ( $tracking_payload['last_event']['description'] ?? '' ) ) );
		$normalized_status  = $this->map_status( $raw_status, $raw_description );

		if ( '' === $normalized_status ) {
			return array();
		}

		$event_time = (string) ( $tracking_payload['last_event']['date'] ?? '' );
		if ( '' === $event_time ) {
			$event_time = gmdate( 'c' );
		}

		$customer_email = (string) $order->get_billing_email();

		return array(
			'event_name'       => 'shipment_' . $normalized_status,
			'event_status'     => $normalized_status,
			'provider'         => 'auspost',
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'tracking_number'  => (string) ( $tracking_payload['tracking_number'] ?? '' ),
			'occurred_at'      => $event_time,
			'location'         => (string) ( $tracking_payload['last_event']['location'] ?? '' ),
			'description'      => (string) ( $tracking_payload['last_event']['description'] ?? '' ),
			'eta'              => (string) ( $tracking_payload['eta'] ?? '' ),
			'customer_email'   => $customer_email,
			'customer_phone'   => (string) $order->get_billing_phone(),
			'customer_name'    => trim( $order->get_formatted_billing_full_name() ),
			'order_total'      => (string) $order->get_total(),
			'order_currency'   => (string) $order->get_currency(),
			'order_status'     => (string) $order->get_status(),
			'source'           => 'ck_order_workflow_suite',
			'source_version'   => CK_OWS_VERSION,
		);
	}

	private function should_dispatch_event( array $normalized_event ): bool {
		$unique_key_material = implode(
			'|',
			array(
				(string) ( $normalized_event['order_id'] ?? '' ),
				(string) ( $normalized_event['tracking_number'] ?? '' ),
				(string) ( $normalized_event['event_status'] ?? '' ),
				(string) ( $normalized_event['occurred_at'] ?? '' ),
			)
		);

		if ( '' === trim( $unique_key_material, '|' ) ) {
			return false;
		}

		$transient_key = self::TRANSIENT_DEDUP_PREFIX . md5( $unique_key_material );
		if ( false !== get_transient( $transient_key ) ) {
			return false;
		}

		set_transient( $transient_key, '1', 7 * DAY_IN_SECONDS );
		return true;
	}

	private function map_status( string $status, string $description ): string {
		$haystack = trim( $status . ' ' . $description );

		if ( '' === $haystack ) {
			return '';
		}

		if ( false !== strpos( $haystack, 'delivered' ) ) {
			return 'delivered';
		}

		if ( false !== strpos( $haystack, 'out for delivery' ) ) {
			return 'out_for_delivery';
		}

		if ( false !== strpos( $haystack, 'attempted' ) || false !== strpos( $haystack, 'carded' ) ) {
			return 'delivery_attempted';
		}

		if ( false !== strpos( $haystack, 'delay' ) || false !== strpos( $haystack, 'exception' ) || false !== strpos( $haystack, 'unable' ) ) {
			return 'exception';
		}

		if ( false !== strpos( $haystack, 'transit' ) || false !== strpos( $haystack, 'processed' ) || false !== strpos( $haystack, 'picked up' ) || false !== strpos( $haystack, 'onboard' ) ) {
			return 'in_transit';
		}

		return '';
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

		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$sanitized = 'https://' . $parts['host'] . $path;

		if ( '' !== $query ) {
			$sanitized .= '?' . $query;
		}

		return esc_url_raw( $sanitized );
	}
}
