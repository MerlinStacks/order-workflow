<?php
/**
 * Tracking to email platform event forwarding.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Tracking_Email_Events extends CK_OWS_Base {
	private const TRANSIENT_DEDUP_PREFIX = 'ck_ows_track_evt_';
	private const RETRY_HOOK             = 'ck_ows_tracking_event_retry';

	public function forward_event_to_email_platform( int $order_id, array $tracking_payload ): void {
		if ( 'yes' !== CK_OWS_Settings::get( 'tracking_email_events_enabled', 'no' ) ) {
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

		if ( ! $this->schedule_delivery( $order_id, $normalized_event, 0, time() + 1 ) ) {
			$this->clear_event_claim( $normalized_event );
			$this->push_dead_letter( $order_id, $normalized_event, 'Unable to schedule initial delivery', 0 );
		}
	}

	public function retry_event_delivery( array $payload ): void {
		$order_id         = isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0;
		$attempt          = isset( $payload['attempt'] ) ? absint( $payload['attempt'] ) : 1;
		$normalized_event = isset( $payload['event'] ) && is_array( $payload['event'] ) ? $payload['event'] : array();

		if ( $order_id <= 0 || empty( $normalized_event ) ) {
			return;
		}

		$webhook_url = $this->resolve_tracking_events_webhook_url();
		if ( '' === $webhook_url ) {
			$this->schedule_retry( $order_id, $normalized_event, $attempt + 1, 'Missing webhook URL' );
			return;
		}

		$response = CK_OWS_Utils::remote_post(
			$webhook_url,
			array(
				'timeout' => max( 3, min( 30, absint( CK_OWS_Settings::get( 'tracking_email_events_timeout_seconds', 10 ) ) ) ),
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( array( 'event' => $normalized_event ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->track_delivery_result( false, $order_id, $normalized_event, $response->get_error_message() );
			$this->schedule_retry( $order_id, $normalized_event, $attempt + 1, $response->get_error_message() );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->track_delivery_result( false, $order_id, $normalized_event, 'HTTP ' . $status_code );
			$this->schedule_retry( $order_id, $normalized_event, $attempt + 1, 'HTTP ' . $status_code );
			return;
		}

		$this->track_delivery_result( true, $order_id, $normalized_event, 'HTTP ' . $status_code );
		$this->mark_event_delivered( $normalized_event );
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

		set_transient( $transient_key, 'pending', 7 * DAY_IN_SECONDS );
		return true;
	}

	private function mark_event_delivered( array $normalized_event ): void {
		$transient_key = $this->get_event_transient_key( $normalized_event );
		if ( '' !== $transient_key ) {
			set_transient( $transient_key, 'delivered', 7 * DAY_IN_SECONDS );
		}
	}

	private function clear_event_claim( array $normalized_event ): void {
		$transient_key = $this->get_event_transient_key( $normalized_event );
		if ( '' !== $transient_key ) {
			delete_transient( $transient_key );
		}
	}

	private function get_event_transient_key( array $normalized_event ): string {
		$key_material = implode( '|', array( (string) ( $normalized_event['order_id'] ?? '' ), (string) ( $normalized_event['tracking_number'] ?? '' ), (string) ( $normalized_event['event_status'] ?? '' ), (string) ( $normalized_event['occurred_at'] ?? '' ) ) );

		return '' === trim( $key_material, '|' ) ? '' : self::TRANSIENT_DEDUP_PREFIX . md5( $key_material );
	}

	private function map_status( string $status, string $description ): string {
		$haystack = trim( $status . ' ' . $description );

		if ( '' === $haystack ) {
			return '';
		}

		if ( false !== strpos( $haystack, 'out for delivery' ) ) {
			return 'out_for_delivery';
		}

		if ( false !== strpos( $haystack, 'attempted' ) || false !== strpos( $haystack, 'carded' ) ) {
			return 'delivery_attempted';
		}

		if ( false !== strpos( $haystack, 'awaiting collection' ) || false !== strpos( $haystack, 'ready for collection' ) ) {
			return 'awaiting_collection';
		}

		if ( false !== strpos( $haystack, 'delay' ) || false !== strpos( $haystack, 'exception' ) || false !== strpos( $haystack, 'unable' ) || false !== strpos( $haystack, 'failed' ) || false !== strpos( $haystack, 'undeliver' ) || false !== strpos( $haystack, 'not delivered' ) ) {
			return 'exception';
		}

		if ( CK_OWS_Tracking_Helpers::is_delivered_status_text( $haystack ) ) {
			return 'delivered';
		}

		if ( false !== strpos( $haystack, 'transit' ) || false !== strpos( $haystack, 'processed' ) || false !== strpos( $haystack, 'picked up' ) || false !== strpos( $haystack, 'onboard' ) ) {
			return 'in_transit';
		}

		return '';
	}

	private function sanitize_https_url( string $url ): string {
		return CK_OWS_Utils::sanitize_https_url( $url );
	}

	private function build_headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$token = trim( (string) CK_OWS_Settings::get( 'tracking_email_events_auth_token', '' ) );

		if ( '' === $token ) {
			$token = $this->resolve_overseek_tracking_events_token();
		}

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	private function resolve_tracking_events_webhook_url(): string {
		$configured_url = $this->sanitize_https_url( (string) CK_OWS_Settings::get( 'tracking_email_events_webhook_url', '' ) );

		if ( '' !== $configured_url ) {
			return $configured_url;
		}

		$health_url = home_url( '/wp-json/overseek/v1/health' );
		$account_id = trim( (string) CK_OWS_Settings::get( 'email_preferences_account_id', '' ) );

		if ( '' === $account_id ) {
			$account_id = trim( (string) get_option( 'overseek_account_id', '' ) );
		}

		$health_url = add_query_arg( array( 'account_id' => $account_id ), $health_url );
		$response   = CK_OWS_Utils::remote_get(
			$health_url,
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ! empty( $decoded['trackingEventsWebhookUrl'] ) ) {
				$discovered = $this->sanitize_https_url( (string) $decoded['trackingEventsWebhookUrl'] );

				if ( '' !== $discovered ) {
					return $discovered;
				}
			}
		}

		return $this->sanitize_https_url( home_url( '/wp-json/overseek/v1/tracking-email-events' ) );
	}

	private function resolve_overseek_tracking_events_token(): string {
		$keys = array(
			'overseek_tracking_events_api_key',
			'overseek_tracking_events_token',
			'overseek_tracking_email_events_token',
			'overseek_tracking_email_events_api_key',
			'overseek_webhook_auth_token',
			'overseek_relay_api_key',
		);

		foreach ( $keys as $key ) {
			$token = trim( (string) get_option( $key, '' ) );

			if ( '' !== $token ) {
				return $token;
			}
		}

		return '';
	}

	private function schedule_retry( int $order_id, array $normalized_event, int $attempt, string $last_error ): void {
		$max_attempts = max( 0, min( 5, absint( CK_OWS_Settings::get( 'tracking_email_events_retry_attempts', 3 ) ) ) );

		if ( $attempt > $max_attempts ) {
			$this->push_dead_letter( $order_id, $normalized_event, $last_error, $attempt - 1 );
			$this->clear_event_claim( $normalized_event );
			return;
		}

		$base_backoff_minutes = max( 1, min( 60, absint( CK_OWS_Settings::get( 'tracking_email_events_retry_backoff_minutes', 5 ) ) ) );
		$delay                = $base_backoff_minutes * MINUTE_IN_SECONDS * $attempt;

		if ( ! $this->schedule_delivery( $order_id, $normalized_event, $attempt, time() + $delay ) ) {
			$this->push_dead_letter( $order_id, $normalized_event, 'Unable to schedule retry: ' . $last_error, $attempt );
			$this->clear_event_claim( $normalized_event );
		}
	}

	private function schedule_delivery( int $order_id, array $event, int $attempt, int $timestamp ): bool {
		$args = array(
			array(
				'order_id' => $order_id,
				'event'    => $event,
				'attempt'  => $attempt,
			),
		);

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::RETRY_HOOK, $args, 'ck-ows-tracking-events' ) ) {
				return true;
			}

			return 0 !== as_schedule_single_action( $timestamp, self::RETRY_HOOK, $args, 'ck-ows-tracking-events', true );
		}

		return wp_next_scheduled( self::RETRY_HOOK, $args ) || wp_schedule_single_event( $timestamp, self::RETRY_HOOK, $args );
	}

	private function push_dead_letter( int $order_id, array $normalized_event, string $last_error, int $attempts ): void {
		$rows = get_option( 'ck_ows_tracking_event_dead_letters', array() );
		$rows = is_array( $rows ) ? $rows : array();
		$rows[] = array(
			'ts'         => time(),
			'order_id'   => $order_id,
			'attempts'   => $attempts,
			'last_error' => $last_error,
			'event'      => $normalized_event,
		);

		if ( count( $rows ) > 50 ) {
			$rows = array_slice( $rows, -50 );
		}

		update_option( 'ck_ows_tracking_event_dead_letters', $rows, false );
	}

	private function track_delivery_result( bool $ok, int $order_id, array $event, string $message ): void {
		update_option(
			'ck_ows_last_webhook_delivery',
			array(
				'ts'       => time(),
				'ok'       => $ok,
				'order_id' => $order_id,
				'message'  => $message,
				'event'    => $event,
			),
			false
		);
	}
}
