<?php
/**
 * Tracking integrations module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Tracking {
	private const CRON_HOOK               = 'ck_ows_tracking_sync_event';
	private const SYNC_LOCK_KEY            = 'ck_ows_tracking_sync_lock';
	private const META_LIVE_TRACKING      = '_ck_ows_live_tracking';
	private const META_LAST_SYNC_TS       = '_ck_ows_live_tracking_last_sync';
	private const META_LAST_SYNC_ERROR    = '_ck_ows_live_tracking_last_error';
	private const META_LAST_EVENT_HASH    = '_ck_ows_live_tracking_last_event_hash';

	private static ?CK_OWS_Tracking $instance = null;

	public static function instance(): CK_OWS_Tracking {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_interval_schedule' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'sync_tracking_data' ) );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_live_tracking_panel' ), 25 );
	}

	public function register_interval_schedule( array $schedules ): array {
		$hours = max( 1, absint( CK_OWS_Settings::get( 'tracking_sync_interval_hours', 6 ) ) );

		$schedules['ck_ows_tracking_interval'] = array(
			'interval' => $hours * HOUR_IN_SECONDS,
			'display'  => sprintf( 'CK OWS Tracking (%d hours)', $hours ),
		);

		return $schedules;
	}

	public function ensure_schedule(): void {
		if ( 'yes' !== CK_OWS_Settings::get( 'tracking_sync_enabled', 'yes' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'ck_ows_tracking_interval', self::CRON_HOOK );
		}
	}

	public function sync_tracking_data(): void {
		$api_key = trim( (string) CK_OWS_Settings::get( 'auspost_api_key', '' ) );

		if ( '' === $api_key ) {
			return;
		}

		if ( false !== get_transient( self::SYNC_LOCK_KEY ) ) {
			return;
		}

		set_transient( self::SYNC_LOCK_KEY, '1', 10 * MINUTE_IN_SECONDS );

		try {

			$orders = wc_get_orders(
				array(
					'limit'   => 50,
					'orderby' => 'date',
					'order'   => 'DESC',
					'date_created' => '>' . ( time() - ( 14 * DAY_IN_SECONDS ) ),
					'status'  => array( 'processing', 'awaiting-artwork', 'in-production', 'in-dispatch', 'completed' ),
				)
			);

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$tracking_numbers = $this->extract_tracking_numbers( $order );

				if ( empty( $tracking_numbers ) ) {
					continue;
				}

				if ( $this->should_skip_sync_for_delivered_order( $order, $tracking_numbers ) ) {
					continue;
				}

				$latest_payload = null;
				$last_error     = '';

				foreach ( $tracking_numbers as $tracking_number ) {
					if ( ! $this->looks_like_auspost_tracking_number( $tracking_number ) ) {
						continue;
					}

					$result = $this->fetch_auspost_tracking( $tracking_number, $api_key );

					if ( is_wp_error( $result ) ) {
						$last_error = $result->get_error_message();
						continue;
					}

					$latest_payload = $result;
					break;
				}

				if ( null === $latest_payload ) {
					$order->update_meta_data( self::META_LAST_SYNC_TS, time() );
					$order->update_meta_data( self::META_LAST_SYNC_ERROR, $last_error );
					$order->save();
					continue;
				}

				$event_hash = md5( wp_json_encode( $latest_payload ) );
				$prev_hash  = (string) $order->get_meta( self::META_LAST_EVENT_HASH, true );

				$order->update_meta_data( self::META_LIVE_TRACKING, $latest_payload );
				$order->update_meta_data( self::META_LAST_SYNC_TS, time() );
				$order->delete_meta_data( self::META_LAST_SYNC_ERROR );
				$order->update_meta_data( self::META_LAST_EVENT_HASH, $event_hash );
				$order->save();

				if ( $event_hash !== $prev_hash ) {
					do_action( 'ck_ows_tracking_updated', $order->get_id(), $latest_payload );
				}
			}
		} finally {
			delete_transient( self::SYNC_LOCK_KEY );
		}
	}

	public function render_live_tracking_panel( WC_Order $order ): void {
		if ( ! is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );

		if ( empty( $tracking ) || ! is_array( $tracking ) ) {
			$tracking = $this->refresh_tracking_for_order_view( $order );
		}

		$fallback = $this->extract_tracking_links( $order );

		if ( empty( $tracking ) && empty( $fallback ) ) {
			return;
		}

		echo '<section class="ck-ows-live-tracking">';
		echo '<h2>' . esc_html__( 'Shipment Tracking', 'ck-order-workflow-suite' ) . '</h2>';

		if ( is_array( $tracking ) && ! empty( $tracking ) ) {
			$status = (string) ( $tracking['status'] ?? $tracking['tracking_status'] ?? __( 'In transit', 'ck-order-workflow-suite' ) );
			echo '<p><strong>' . esc_html__( 'Latest status:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $status ) . '</p>';

			if ( ! empty( $tracking['last_event'] ) && is_array( $tracking['last_event'] ) ) {
				$desc = (string) ( $tracking['last_event']['description'] ?? '' );
				$when = (string) ( $tracking['last_event']['date'] ?? '' );
				$loc  = (string) ( $tracking['last_event']['location'] ?? '' );
				echo '<p>' . esc_html( trim( $desc . ' ' . $when . ' ' . $loc ) ) . '</p>';
			}
		}

		if ( ! empty( $fallback ) ) {
			echo '<p>';
			foreach ( $fallback as $index => $url ) {
				echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html( 0 === $index ? __( 'Track shipment', 'ck-order-workflow-suite' ) : __( 'Track another parcel', 'ck-order-workflow-suite' ) ) . '</a> ';
			}
			echo '</p>';
		}

		echo '</section>';
	}

	private function refresh_tracking_for_order_view( WC_Order $order ): array {
		$last_sync_ts = (int) $order->get_meta( self::META_LAST_SYNC_TS, true );

		if ( $last_sync_ts > 0 && ( time() - $last_sync_ts ) < ( 15 * MINUTE_IN_SECONDS ) ) {
			return array();
		}

		$api_key = trim( (string) CK_OWS_Settings::get( 'auspost_api_key', '' ) );
		$username = trim( (string) CK_OWS_Settings::get( 'auspost_api_username', '' ) );
		$password = trim( (string) CK_OWS_Settings::get( 'auspost_api_password', '' ) );

		if ( '' === $api_key && ( '' === $username || '' === $password ) ) {
			return array();
		}

		$tracking_numbers = $this->extract_tracking_numbers( $order );

		if ( empty( $tracking_numbers ) ) {
			return array();
		}

		if ( $this->should_skip_sync_for_delivered_order( $order, $tracking_numbers ) ) {
			$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );
			return is_array( $tracking ) ? $tracking : array();
		}

		foreach ( $tracking_numbers as $tracking_number ) {
			if ( ! $this->looks_like_auspost_tracking_number( $tracking_number ) ) {
				continue;
			}

			$result = $this->fetch_auspost_tracking( $tracking_number, $api_key );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$order->update_meta_data( self::META_LIVE_TRACKING, $result );
			$order->update_meta_data( self::META_LAST_SYNC_TS, time() );
			$order->delete_meta_data( self::META_LAST_SYNC_ERROR );
			$order->save();

			return $result;
		}

		$order->update_meta_data( self::META_LAST_SYNC_TS, time() );
		$order->save();

		return array();
	}

	private function extract_tracking_numbers( WC_Order $order ): array {
		$numbers = array();
		$items   = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! empty( $item['tracking_number'] ) ) {
					$numbers[] = sanitize_text_field( (string) $item['tracking_number'] );
				}
			}
		}

		$numbers = array_unique( array_filter( $numbers ) );

		return array_values( $numbers );
	}

	private function extract_tracking_links( WC_Order $order ): array {
		$links = array();
		$items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( ! is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			$provider       = strtolower( (string) ( $item['tracking_provider'] ?? $item['custom_tracking_provider'] ?? '' ) );
			$tracking_num   = isset( $item['tracking_number'] ) ? (string) $item['tracking_number'] : '';

			if ( ! empty( $item['formatted_tracking_link'] ) ) {
				$links[] = (string) $item['formatted_tracking_link'];
			} elseif ( ! empty( $item['custom_tracking_link'] ) ) {
				$links[] = (string) $item['custom_tracking_link'];
			} elseif ( '' !== $tracking_num && $this->is_auspost_provider( $provider ) ) {
				$links[] = 'https://auspost.com.au/mypost/track/#/details/' . rawurlencode( $tracking_num );
			}
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	private function is_auspost_provider( string $provider ): bool {
		if ( '' === $provider ) {
			return false;
		}

		$provider = strtolower( $provider );

		return false !== strpos( $provider, 'australia post' ) || false !== strpos( $provider, 'auspost' );
	}

	private function looks_like_auspost_tracking_number( string $tracking_number ): bool {
		$normalized = strtoupper( preg_replace( '/\s+/', '', $tracking_number ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( 1 === preg_match( '/^[A-Z]{2}[0-9]{9}AU$/', $normalized ) ) {
			return true;
		}

		if ( 1 === preg_match( '/^[0-9]{10,22}$/', $normalized ) ) {
			return true;
		}

		return false;
	}

	private function should_skip_sync_for_delivered_order( WC_Order $order, array $tracking_numbers ): bool {
		$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );

		if ( ! is_array( $tracking ) || empty( $tracking ) ) {
			return false;
		}

		$stored_tracking_number = isset( $tracking['tracking_number'] ) ? sanitize_text_field( (string) $tracking['tracking_number'] ) : '';

		if ( '' !== $stored_tracking_number && ! in_array( $stored_tracking_number, $tracking_numbers, true ) ) {
			return false;
		}

		if ( $this->is_delivered_payload( $tracking ) ) {
			return true;
		}

		return false;
	}

	private function is_delivered_payload( array $payload ): bool {
		$status      = strtolower( trim( (string) ( $payload['status'] ?? $payload['tracking_status'] ?? '' ) ) );
		$description = strtolower( trim( (string) ( $payload['last_event']['description'] ?? '' ) ) );

		if ( false !== strpos( $status, 'delivered' ) || false !== strpos( $description, 'delivered' ) ) {
			return true;
		}

		$raw = $payload['raw'] ?? array();
		if ( ! is_array( $raw ) ) {
			return false;
		}

		$raw_status = strtolower( trim( (string) ( $raw['status'] ?? $raw['tracking_status'] ?? $raw['delivery_status'] ?? '' ) ) );

		if ( false !== strpos( $raw_status, 'delivered' ) ) {
			return true;
		}

		return false;
	}

	private function fetch_auspost_tracking( string $tracking_number, string $api_key ) {
		$username = trim( (string) CK_OWS_Settings::get( 'auspost_api_username', '' ) );
		$password = trim( (string) CK_OWS_Settings::get( 'auspost_api_password', '' ) );
		$base_url = untrailingslashit( (string) CK_OWS_Settings::get( 'auspost_tracking_api_base_url', '' ) );

		$headers = array(
			'Accept' => 'application/json',
		);

		if ( '' !== $username && '' !== $password ) {
			if ( '' === $base_url ) {
				$base_url = 'https://digitalapi.auspost.com.au/shipping/v1';
			}

			$url                    = $base_url . '/track?tracking_ids=' . rawurlencode( $tracking_number );
			$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
		} else {
			if ( '' === $base_url ) {
				$base_url = 'https://digitalapi.auspost.com.au/track/v2';
			}

			$url                 = $base_url . '/track?tracking_ids=' . rawurlencode( $tracking_number );
			$headers['AUTH-KEY'] = $api_key;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ck_ows_auspost_http_error', sprintf( 'AusPost API returned HTTP %d', $code ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'ck_ows_auspost_parse_error', 'Invalid AusPost API response.' );
		}

		$article = $this->extract_article_from_tracking_response( $body );
		if ( ! is_array( $article ) || empty( $article ) ) {
			return new WP_Error( 'ck_ows_auspost_no_result', 'No tracking data returned by AusPost.' );
		}

		$events     = $this->extract_tracking_events_from_article( $article );
		$last_event = ! empty( $events ) ? $events[0] : array();

		if ( empty( $last_event ) && ! empty( $events ) ) {
			$last_event = end( $events );
			if ( ! is_array( $last_event ) ) {
				$last_event = array();
			}
		}

		return array(
			'provider'        => 'auspost',
			'tracking_number' => $tracking_number,
			'status'          => (string) ( $article['status'] ?? $article['tracking_status'] ?? $article['delivery_status'] ?? '' ),
			'last_event'      => array(
				'description' => (string) ( $last_event['description'] ?? $last_event['event_description'] ?? $last_event['event'] ?? '' ),
				'date'        => (string) ( $last_event['date'] ?? $last_event['event_time'] ?? $last_event['datetime'] ?? '' ),
				'location'    => (string) ( $last_event['location'] ?? $last_event['location_name'] ?? $last_event['facility_name'] ?? '' ),
			),
			'eta'             => (string) ( $article['estimated_delivery_date'] ?? '' ),
			'raw'             => $article,
		);
	}

	private function extract_article_from_tracking_response( array $body ): array {
		$tracking_results = $body['tracking_results'] ?? array();

		if ( is_array( $tracking_results ) && isset( $tracking_results[0] ) && is_array( $tracking_results[0] ) ) {
			$first = $tracking_results[0];

			if ( isset( $first['articles'][0] ) && is_array( $first['articles'][0] ) ) {
				return $first['articles'][0];
			}

			return $first;
		}

		if ( isset( $body['articles'][0] ) && is_array( $body['articles'][0] ) ) {
			return $body['articles'][0];
		}

		if ( isset( $body['article'] ) && is_array( $body['article'] ) ) {
			return $body['article'];
		}

		return array();
	}

	private function extract_tracking_events_from_article( array $article ): array {
		$event_keys = array( 'events', 'tracking_events', 'article_events' );

		foreach ( $event_keys as $key ) {
			if ( isset( $article[ $key ] ) && is_array( $article[ $key ] ) ) {
				return $article[ $key ];
			}
		}

		return array();
	}
}
