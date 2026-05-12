<?php
/**
 * Tracking integrations module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Tracking {
	private const CRON_HOOK               = 'ck_ows_tracking_sync_event';
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

		$orders = wc_get_orders(
			array(
				'limit'   => 50,
				'orderby' => 'date',
				'order'   => 'DESC',
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

			$latest_payload = null;
			$last_error     = '';

			foreach ( $tracking_numbers as $tracking_number ) {
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
	}

	public function render_live_tracking_panel( WC_Order $order ): void {
		if ( ! is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );
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
			if ( ! empty( $item['formatted_tracking_link'] ) ) {
				$links[] = (string) $item['formatted_tracking_link'];
			} elseif ( ! empty( $item['custom_tracking_link'] ) ) {
				$links[] = (string) $item['custom_tracking_link'];
			} elseif ( ! empty( $item['tracking_number'] ) ) {
				$links[] = 'https://auspost.com.au/mypost/track/#/details/' . rawurlencode( (string) $item['tracking_number'] );
			}
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	private function fetch_auspost_tracking( string $tracking_number, string $api_key ) {
		$url = 'https://digitalapi.auspost.com.au/track/v2/track?tracking_ids=' . rawurlencode( $tracking_number );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'AUTH-KEY' => $api_key,
					'Accept'   => 'application/json',
				),
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

		$article = $body['tracking_results'][0] ?? array();
		if ( ! is_array( $article ) || empty( $article ) ) {
			return new WP_Error( 'ck_ows_auspost_no_result', 'No tracking data returned by AusPost.' );
		}

		$events     = isset( $article['events'] ) && is_array( $article['events'] ) ? $article['events'] : array();
		$last_event = ! empty( $events ) ? $events[0] : array();

		return array(
			'provider'        => 'auspost',
			'tracking_number' => $tracking_number,
			'status'          => (string) ( $article['status'] ?? '' ),
			'last_event'      => array(
				'description' => (string) ( $last_event['description'] ?? '' ),
				'date'        => (string) ( $last_event['date'] ?? '' ),
				'location'    => (string) ( $last_event['location'] ?? '' ),
			),
			'eta'             => (string) ( $article['estimated_delivery_date'] ?? '' ),
			'raw'             => $article,
		);
	}
}
