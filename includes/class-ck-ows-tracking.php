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
		add_action( 'init', array( $this, 'suppress_default_tracking_output' ), 20 );
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
		$username = trim( (string) CK_OWS_Settings::get( 'auspost_api_username', '' ) );
		$password = trim( (string) CK_OWS_Settings::get( 'auspost_api_password', '' ) );

		if ( '' === $api_key && ( '' === $username || '' === $password ) ) {
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
					if ( $this->is_stale_tracking_payload( $order, $tracking_numbers ) ) {
						$order->delete_meta_data( self::META_LIVE_TRACKING );
						$order->delete_meta_data( self::META_LAST_EVENT_HASH );
					}

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
		$is_store_manager = current_user_can( 'manage_woocommerce' );

		if ( ! is_user_logged_in() || ( ! $is_store_manager && (int) $order->get_user_id() !== get_current_user_id() ) ) {
			return;
		}

		$tracking = $this->get_tracking_payload_for_order( $order );

		$fallback = $this->extract_tracking_links( $order );

		if ( empty( $tracking ) && empty( $fallback ) ) {
			return;
		}

		echo '<section class="ck-ows-live-tracking">';
		echo '<h2>' . esc_html__( 'Shipment Tracking', 'ck-order-workflow-suite' ) . '</h2>';

		if ( is_array( $tracking ) && ! empty( $tracking ) ) {
			$status = '';
			foreach ( array( $tracking['status'] ?? '', $tracking['tracking_status'] ?? '' ) as $candidate_status ) {
				$candidate_status = trim( (string) $candidate_status );
				if ( '' !== $candidate_status ) {
					$status = $candidate_status;
					break;
				}
			}

			if ( '' === $status ) {
				$status = __( 'In transit', 'ck-order-workflow-suite' );
			}

			echo '<p><strong>' . esc_html__( 'Latest status:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $status ) . '</p>';

			$eta = trim( (string) ( $tracking['eta'] ?? '' ) );
			if ( '' !== $eta ) {
				echo '<p><strong>' . esc_html__( 'Estimated delivery:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $eta ) . '</p>';
			}

			if ( ! empty( $tracking['last_event'] ) && is_array( $tracking['last_event'] ) ) {
				$desc = trim( (string) ( $tracking['last_event']['description'] ?? '' ) );
				$when = trim( (string) ( $tracking['last_event']['date'] ?? '' ) );
				$loc  = trim( (string) ( $tracking['last_event']['location'] ?? '' ) );

				if ( '' !== $desc || '' !== $when || '' !== $loc ) {
				echo '<p><strong>' . esc_html__( 'Latest scan:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( trim( $desc ) ) . '</p>';

				if ( '' !== trim( $when ) ) {
					echo '<p><strong>' . esc_html__( 'Scan time:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $this->format_tracking_datetime( $when ) ) . '</p>';
				}

				if ( '' !== trim( $loc ) ) {
					echo '<p><strong>' . esc_html__( 'Scan location:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $loc ) . '</p>';
				}
				}
			}

			$recent_events = $this->extract_tracking_events_from_article( is_array( $tracking['raw'] ?? null ) ? $tracking['raw'] : array() );
			$recent_events = $this->sort_tracking_events_newest_first( $recent_events );

			if ( ! empty( $recent_events ) ) {
				echo '<p><strong>' . esc_html__( 'Recent scans:', 'ck-order-workflow-suite' ) . '</strong></p>';
				echo '<ul>';

				$shown = 0;
				foreach ( $recent_events as $event ) {
					if ( $shown >= 3 || ! is_array( $event ) ) {
						break;
					}

					$desc = trim( (string) ( $event['description'] ?? $event['event_description'] ?? $event['event'] ?? '' ) );
					$when = trim( (string) ( $event['date'] ?? $event['event_time'] ?? $event['datetime'] ?? '' ) );
					$loc  = trim( (string) ( $event['location'] ?? $event['location_name'] ?? $event['facility_name'] ?? '' ) );

					if ( '' === $desc && '' === $when && '' === $loc ) {
						continue;
					}

					$parts = array();
					if ( '' !== $desc ) {
						$parts[] = $desc;
					}
					if ( '' !== $when ) {
						$parts[] = $this->format_tracking_datetime( $when );
					}
					if ( '' !== $loc ) {
						$parts[] = $loc;
					}

					echo '<li>' . esc_html( implode( ' | ', $parts ) ) . '</li>';
					$shown++;
				}

				echo '</ul>';
			}
		}

		if ( ! empty( $fallback ) ) {
			echo '<p>';
			foreach ( $fallback as $index => $url ) {
				$label = 0 === $index ? __( 'Track on AusPost', 'ck-order-workflow-suite' ) : __( 'Track another parcel', 'ck-order-workflow-suite' );
				echo '<a class="button ck-ows-track-auspost" target="_blank" rel="noopener" href="' . esc_url( $url ) . '"><span class="ck-ows-track-auspost__logo" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" role="presentation"><path d="M10 2A10 10 0 1 0 10 22V2Z"/><path d="M12 2h.5A10 10 0 0 1 12.5 22H12v-5h.5A5 5 0 0 0 12.5 7H12V2Z"/></svg></span>' . esc_html( $label ) . '</a> ';
			}
			echo '</p>';
		}

		$debug_enabled = isset( $_GET['ck_ows_tracking_debug'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['ck_ows_tracking_debug'] ) );
		$can_view_debug = $is_store_manager || ( is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id() );
		if ( $can_view_debug && $debug_enabled ) {
			$events = $this->extract_tracking_events_from_article( is_array( $tracking['raw'] ?? null ) ? $tracking['raw'] : array() );
			$events = $this->sort_tracking_events_newest_first( $events );
			$events = array_slice( $events, 0, 10 );
			$raw    = is_array( $tracking['raw'] ?? null ) ? $tracking['raw'] : array();

			$debug = array(
				'order_id'        => $order->get_id(),
				'tracking_number' => (string) ( $tracking['tracking_number'] ?? '' ),
				'status'          => (string) ( $tracking['status'] ?? '' ),
				'eta'             => (string) ( $tracking['eta'] ?? '' ),
				'last_event'      => $tracking['last_event'] ?? array(),
				'events_sample'   => $events,
				'has_tracking'       => is_array( $tracking ) && ! empty( $tracking ),
				'last_sync_error'    => (string) $order->get_meta( self::META_LAST_SYNC_ERROR, true ),
				'raw_top_level_keys' => array_keys( $raw ),
				'raw_status_fields'  => array(
					'status'          => (string) ( $raw['status'] ?? '' ),
					'tracking_status' => (string) ( $raw['tracking_status'] ?? '' ),
					'delivery_status' => (string) ( $raw['delivery_status'] ?? '' ),
				),
				'raw_excerpt' => array(
					'trackable_items' => $raw['trackable_items'] ?? null,
					'articles'        => $raw['articles'] ?? null,
					'events'          => $raw['events'] ?? null,
					'tracking_events' => $raw['tracking_events'] ?? null,
					'article_events'  => $raw['article_events'] ?? null,
					'tracking_details' => $raw['tracking_details'] ?? null,
				),
			);

			echo '<details class="ck-ows-tracking-debug"><summary>' . esc_html__( 'Tracking debug (admin only)', 'ck-order-workflow-suite' ) . '</summary>';
			echo '<pre>' . esc_html( wp_json_encode( $debug, JSON_PRETTY_PRINT ) ) . '</pre>';
			echo '</details>';
		}

		echo '</section>';
		$this->render_tracking_ui_styles_once();
	}

	public function get_tracking_payload_for_order( WC_Order $order, bool $force_refresh = false ): array {
		$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );
		$last_sync_ts = (int) $order->get_meta( self::META_LAST_SYNC_TS, true );
		$is_refresh_due = $last_sync_ts <= 0 || ( time() - $last_sync_ts ) >= ( 15 * MINUTE_IN_SECONDS );

		if ( $force_refresh || $is_refresh_due || ! $this->is_tracking_payload_usable( $tracking ) ) {
			$tracking = $this->refresh_tracking_for_order_view( $order );
			if ( ! $this->is_tracking_payload_usable( $tracking ) ) {
				$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );
			}
		}

		return $this->is_tracking_payload_usable( $tracking ) ? $tracking : array();
	}

	public function debug_fetch_tracking_number( string $tracking_number ): array {
		$tracking_number = sanitize_text_field( $tracking_number );
		$api_key         = trim( (string) CK_OWS_Settings::get( 'auspost_api_key', '' ) );
		$username        = trim( (string) CK_OWS_Settings::get( 'auspost_api_username', '' ) );
		$password        = trim( (string) CK_OWS_Settings::get( 'auspost_api_password', '' ) );

		$result = array(
			'ran_at'          => time(),
			'tracking_number' => $tracking_number,
			'ok'              => false,
			'message'         => '',
			'auth_mode'       => '' !== $username && '' !== $password ? 'shipping_api_basic_auth' : 'track_api_auth_key',
			'payload'         => array(),
		);

		if ( '' === trim( $tracking_number ) ) {
			$result['message'] = __( 'Enter a tracking number to test.', 'ck-order-workflow-suite' );
			return $result;
		}

		if ( '' === $api_key && ( '' === $username || '' === $password ) ) {
			$result['message'] = __( 'Missing AusPost API key or shipping username/password.', 'ck-order-workflow-suite' );
			return $result;
		}

		if ( ! $this->looks_like_auspost_tracking_number( $tracking_number ) ) {
			$result['message'] = __( 'Tracking number does not look like an AusPost article ID, but the API test was still attempted.', 'ck-order-workflow-suite' );
		}

		$payload = $this->fetch_auspost_tracking( $tracking_number, $api_key );

		if ( is_wp_error( $payload ) ) {
			$result['message'] = $payload->get_error_message();
			return $result;
		}

		$result['ok']      = true;
		$result['message'] = __( 'AusPost returned a usable parsed tracking payload.', 'ck-order-workflow-suite' );
		$result['payload'] = $payload;

		return $result;
	}

	public function suppress_default_tracking_output(): void {
		if ( class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
			remove_action( 'woocommerce_view_order', array( 'WC_Shipment_Tracking_Actions', 'display_tracking_info' ), 20 );
			remove_action( 'woocommerce_order_details_after_order_table', array( 'WC_Shipment_Tracking_Actions', 'display_tracking_info' ), 20 );
			remove_action( 'woocommerce_order_details_before_order_table', array( 'WC_Shipment_Tracking_Actions', 'display_tracking_info' ), 20 );
		}

		if ( function_exists( 'wc_shipment_tracking' ) ) {
			$shipment_tracking = wc_shipment_tracking();
			if ( is_object( $shipment_tracking ) && isset( $shipment_tracking->actions ) && is_object( $shipment_tracking->actions ) ) {
				remove_action( 'woocommerce_view_order', array( $shipment_tracking->actions, 'display_tracking_info' ), 20 );
				remove_action( 'woocommerce_order_details_after_order_table', array( $shipment_tracking->actions, 'display_tracking_info' ), 20 );
				remove_action( 'woocommerce_order_details_before_order_table', array( $shipment_tracking->actions, 'display_tracking_info' ), 20 );
			}
		}

		$this->remove_tracking_display_callbacks_from_hook( 'woocommerce_view_order' );
		$this->remove_tracking_display_callbacks_from_hook( 'woocommerce_order_details_after_order_table' );
		$this->remove_tracking_display_callbacks_from_hook( 'woocommerce_order_details_before_order_table' );
	}

	private function remove_tracking_display_callbacks_from_hook( string $hook_name ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof WP_Hook ) {
			return;
		}

		$callbacks = $wp_filter[ $hook_name ]->callbacks;
		if ( ! is_array( $callbacks ) ) {
			return;
		}

		foreach ( $callbacks as $priority => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				$function = $entry['function'] ?? null;

				if ( ! is_array( $function ) || ! isset( $function[1] ) ) {
					continue;
				}

				if ( 'display_tracking_info' !== $function[1] ) {
					continue;
				}

				if ( is_object( $function[0] ) && false !== strpos( strtolower( get_class( $function[0] ) ), 'shipment_tracking' ) ) {
					remove_action( $hook_name, $function, (int) $priority );
				}
			}
		}
	}

	private function render_tracking_ui_styles_once(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;

		$styles = '.woocommerce-account .woocommerce-MyAccount-content table.my_account_tracking{display:none!important}'
			. '.woocommerce-account .woocommerce-MyAccount-content h2 + table.my_account_tracking{display:none!important}'
			. '.woocommerce-account .woocommerce-MyAccount-content h2:has(+table.my_account_tracking){display:none!important}'
			. '.woocommerce-account .woocommerce-MyAccount-content h2.ck-ows-hide-tracking-title{display:none!important}'
			. '.ck-ows-track-auspost{display:inline-flex;align-items:center;gap:8px;background:#e4002b!important;border-color:#e4002b!important;color:#fff!important;font-weight:700}'
			. '.ck-ows-track-auspost:hover,.ck-ows-track-auspost:focus{background:#bd0024!important;border-color:#bd0024!important;color:#fff!important}'
			. '.ck-ows-track-auspost__logo{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px}'
			. '.ck-ows-track-auspost__logo svg{display:block;width:22px;height:22px;fill:#fff}';

		$script = '(function(){'
			. 'function hideTrackingHeader(){'
			. 'var tables=document.querySelectorAll(".woocommerce-account .woocommerce-MyAccount-content table.my_account_tracking");'
			. 'for(var i=0;i<tables.length;i++){' 
			. 'var table=tables[i];var prev=table.previousElementSibling;'
			. 'if(prev&&prev.tagName==="H2"){' 
			. 'var txt=(prev.textContent||"").trim().toLowerCase();'
			. 'if(txt==="tracking information"||txt==="shipment tracking"||txt==="tracking"){prev.classList.add("ck-ows-hide-tracking-title");}'
			. '}'
			. '}'
			. '}'
			. 'hideTrackingHeader();'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",hideTrackingHeader);}else{hideTrackingHeader();}'
			. 'window.addEventListener("load",hideTrackingHeader);'
			. 'if(window.MutationObserver){'
			. 'var root=document.querySelector(".woocommerce-account .woocommerce-MyAccount-content")||document.body;'
			. 'var observer=new MutationObserver(function(){hideTrackingHeader();});'
			. 'observer.observe(root,{childList:true,subtree:true});'
			. 'setTimeout(function(){observer.disconnect();},10000);'
			. '}'
			. '})();';

		echo '<style>' . esc_html( $styles ) . '</style>';
		echo '<script>' . $script . '</script>';
	}

	private function refresh_tracking_for_order_view( WC_Order $order ): array {
		$last_sync_ts     = (int) $order->get_meta( self::META_LAST_SYNC_TS, true );
		$existing_payload = $order->get_meta( self::META_LIVE_TRACKING, true );

		if ( $last_sync_ts > 0 && ( time() - $last_sync_ts ) < ( 15 * MINUTE_IN_SECONDS ) && $this->is_tracking_payload_usable( $existing_payload ) ) {
			return is_array( $existing_payload ) ? $existing_payload : array();
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

		if ( $this->is_stale_tracking_payload( $order, $tracking_numbers ) ) {
			$order->delete_meta_data( self::META_LIVE_TRACKING );
			$order->delete_meta_data( self::META_LAST_EVENT_HASH );
		}

		$order->save();

		return array();
	}

	private function is_stale_tracking_payload( WC_Order $order, array $tracking_numbers ): bool {
		$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );

		if ( ! is_array( $tracking ) || empty( $tracking ) ) {
			return false;
		}

		$stored_tracking_number = trim( (string) ( $tracking['tracking_number'] ?? '' ) );

		if ( '' === $stored_tracking_number || empty( $tracking_numbers ) ) {
			return false;
		}

		foreach ( $tracking_numbers as $tracking_number ) {
			if ( strtolower( trim( (string) $tracking_number ) ) === strtolower( $stored_tracking_number ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_tracking_payload_usable( $tracking ): bool {
		if ( ! is_array( $tracking ) || empty( $tracking ) ) {
			return false;
		}

		$last_event = $tracking['last_event'] ?? array();
		if ( is_array( $last_event ) ) {
			$last_event_text = trim(
				implode(
					' ',
					array(
						(string) ( $last_event['description'] ?? '' ),
						(string) ( $last_event['date'] ?? '' ),
						(string) ( $last_event['location'] ?? '' ),
					)
				)
			);

			if ( '' !== $last_event_text ) {
				return true;
			}
		}

		$raw = $tracking['raw'] ?? array();
		if ( ! is_array( $raw ) ) {
			return false;
		}

		$events = $this->extract_tracking_events_from_article( $raw );

		return ! empty( $events );
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
		$last_event = $this->resolve_latest_tracking_event( $events );

		$status = '';
		foreach ( array( $article['status'] ?? '', $article['tracking_status'] ?? '', $article['delivery_status'] ?? '', $article['_tracking_result_status'] ?? '' ) as $candidate_status ) {
			$candidate_status = trim( (string) $candidate_status );
			if ( '' !== $candidate_status ) {
				$status = $candidate_status;
				break;
			}
		}

		if ( '' === $status ) {
			$status = trim( (string) ( $last_event['description'] ?? $last_event['event_description'] ?? $last_event['event'] ?? '' ) );
		}

		if ( $this->contains_delivered_event( $events ) ) {
			$status = 'Delivered';
		}

		$payload = array(
			'provider'        => 'auspost',
			'tracking_number' => $tracking_number,
			'status'          => $status,
			'last_event'      => array(
				'description' => (string) ( $last_event['description'] ?? $last_event['event_description'] ?? $last_event['event'] ?? $last_event['summary'] ?? $last_event['title'] ?? '' ),
				'date'        => (string) ( $last_event['date'] ?? $last_event['event_time'] ?? $last_event['datetime'] ?? $last_event['time'] ?? $last_event['timestamp'] ?? '' ),
				'location'    => (string) ( $last_event['location'] ?? $last_event['location_name'] ?? $last_event['facility_name'] ?? $last_event['place'] ?? '' ),
			),
			'eta'             => $this->resolve_estimated_delivery_text( $article ),
			'raw'             => $article,
		);

		if ( ! $this->is_tracking_payload_usable( $payload ) ) {
			return new WP_Error( 'ck_ows_auspost_empty_payload', 'AusPost returned no usable status or scan events for this tracking number.' );
		}

		return $payload;
	}

	private function resolve_estimated_delivery_text( array $article ): string {
		$candidates = array(
			$article['estimated_delivery_date'] ?? '',
			$article['estimated_delivery'] ?? '',
			$article['eta'] ?? '',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}

		$range = $article['estimated_delivery_range'] ?? $article['delivery_range'] ?? array();
		if ( is_array( $range ) ) {
			$start = trim( (string) ( $range['from'] ?? $range['start'] ?? '' ) );
			$end   = trim( (string) ( $range['to'] ?? $range['end'] ?? '' ) );

			if ( '' !== $start && '' !== $end ) {
				return $this->format_tracking_datetime( $start ) . ' - ' . $this->format_tracking_datetime( $end );
			}

			if ( '' !== $start ) {
				return $this->format_tracking_datetime( $start );
			}

			if ( '' !== $end ) {
				return $this->format_tracking_datetime( $end );
			}
		}

		return '';
	}

	private function extract_article_from_tracking_response( array $body ): array {
		$tracking_results = $body['tracking_results'] ?? array();

		if ( is_array( $tracking_results ) && isset( $tracking_results[0] ) && is_array( $tracking_results[0] ) ) {
			$first = $tracking_results[0];
			$status = (string) ( $first['status'] ?? '' );

			if ( isset( $first['trackable_items'][0] ) && is_array( $first['trackable_items'][0] ) ) {
				$item = $first['trackable_items'][0];

				if ( '' !== $status && ! isset( $item['_tracking_result_status'] ) ) {
					$item['_tracking_result_status'] = $status;
				}

				return $item;
			}

			if ( isset( $first['articles'][0] ) && is_array( $first['articles'][0] ) ) {
				$item = $first['articles'][0];

				if ( '' !== $status && ! isset( $item['_tracking_result_status'] ) ) {
					$item['_tracking_result_status'] = $status;
				}

				return $item;
			}

			if ( isset( $first['items'][0] ) && is_array( $first['items'][0] ) ) {
				$item = $first['items'][0];

				if ( '' !== $status && ! isset( $item['_tracking_result_status'] ) ) {
					$item['_tracking_result_status'] = $status;
				}

				return $item;
			}

			if ( '' !== $status && ! isset( $first['_tracking_result_status'] ) ) {
				$first['_tracking_result_status'] = $status;
			}

			$candidate = $this->find_tracking_article_candidate_in_node( $first );
			if ( ! empty( $candidate ) ) {
				if ( '' !== $status && ! isset( $candidate['_tracking_result_status'] ) ) {
					$candidate['_tracking_result_status'] = $status;
				}

				return $candidate;
			}

			return $first;
		}

		if ( isset( $body['articles'][0] ) && is_array( $body['articles'][0] ) ) {
			return $body['articles'][0];
		}

		if ( isset( $body['article'] ) && is_array( $body['article'] ) ) {
			return $body['article'];
		}

		if ( isset( $body['items'][0] ) && is_array( $body['items'][0] ) ) {
			return $body['items'][0];
		}

		$candidate = $this->find_tracking_article_candidate_in_node( $body );
		if ( ! empty( $candidate ) ) {
			return $candidate;
		}

		return array();
	}

	private function find_tracking_article_candidate_in_node( $node, int $depth = 0 ): array {
		if ( $depth > 4 || ! is_array( $node ) ) {
			return array();
		}

		if ( isset( $node['events'] ) && is_array( $node['events'] ) ) {
			return $node;
		}

		if ( isset( $node['tracking_events'] ) && is_array( $node['tracking_events'] ) ) {
			return $node;
		}

		if ( isset( $node['article_events'] ) && is_array( $node['article_events'] ) ) {
			return $node;
		}

		if ( isset( $node['tracking_details'] ) && is_array( $node['tracking_details'] ) ) {
			return $node;
		}

		foreach ( $node as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$candidate = $this->find_tracking_article_candidate_in_node( $child, $depth + 1 );
			if ( ! empty( $candidate ) ) {
				return $candidate;
			}
		}

		return array();
	}

	private function extract_tracking_events_from_article( array $article ): array {
		$event_keys = array( 'events', 'tracking_events', 'article_events', 'tracking_details' );

		foreach ( $event_keys as $key ) {
			if ( isset( $article[ $key ] ) && is_array( $article[ $key ] ) ) {
				$events = $this->normalize_tracking_events( $article[ $key ] );
				if ( ! empty( $events ) ) {
					return $events;
				}
			}
		}

		return $this->collect_tracking_events_from_node( $article );
	}

	private function normalize_tracking_events( array $events ): array {
		$normalized = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( $this->looks_like_tracking_event( $event ) ) {
				$normalized[] = $event;
				continue;
			}

			$nested = $this->collect_tracking_events_from_node( $event, 0 );
			if ( ! empty( $nested ) ) {
				$normalized = array_merge( $normalized, $nested );
			}
		}

		return $normalized;
	}

	private function collect_tracking_events_from_node( $node, int $depth = 0 ): array {
		if ( $depth > 5 || ! is_array( $node ) ) {
			return array();
		}

		if ( $this->looks_like_tracking_event( $node ) ) {
			return array( $node );
		}

		$events = array();
		foreach ( $node as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$events = array_merge( $events, $this->collect_tracking_events_from_node( $child, $depth + 1 ) );
		}

		return $events;
	}

	private function looks_like_tracking_event( array $event ): bool {
		$description = trim(
			implode(
				' ',
				array(
					(string) ( $event['description'] ?? '' ),
					(string) ( $event['event_description'] ?? '' ),
					(string) ( $event['event'] ?? '' ),
					(string) ( $event['status'] ?? '' ),
					(string) ( $event['summary'] ?? '' ),
					(string) ( $event['title'] ?? '' ),
				)
			)
		);
		$date = trim( (string) ( $event['date'] ?? $event['event_time'] ?? $event['datetime'] ?? $event['time'] ?? $event['timestamp'] ?? '' ) );

		return '' !== $description && '' !== $date;
	}

	private function resolve_latest_tracking_event( array $events ): array {
		$latest_event = array();
		$latest_ts    = 0;
		$has_valid_ts = false;

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_ts = strtotime( (string) ( $event['date'] ?? $event['event_time'] ?? $event['datetime'] ?? $event['time'] ?? $event['timestamp'] ?? '' ) );
			$event_ts = false === $event_ts ? 0 : (int) $event_ts;
			if ( $event_ts > 0 ) {
				$has_valid_ts = true;
			}

			if ( $event_ts > $latest_ts ) {
				$latest_ts    = $event_ts;
				$latest_event = $event;
			}
		}

		if ( $has_valid_ts && ! empty( $latest_event ) ) {
			return $latest_event;
		}

		$first = $events[0] ?? array();

		return is_array( $first ) ? $first : array();
	}

	private function contains_delivered_event( array $events ): bool {
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$haystack = strtolower(
				trim(
					implode(
						' ',
						array(
							(string) ( $event['description'] ?? '' ),
							(string) ( $event['event_description'] ?? '' ),
							(string) ( $event['event'] ?? '' ),
							(string) ( $event['status'] ?? '' ),
							(string) ( $event['summary'] ?? '' ),
							(string) ( $event['title'] ?? '' ),
							(string) ( $event['event_type'] ?? '' ),
							(string) ( $event['code'] ?? '' ),
						)
					)
				)
			);

			if ( '' === $haystack ) {
				continue;
			}

			foreach ( array( 'delivered', 'delivery complete', 'proof of delivery', 'item delivered', 'successfully delivered', 'collected by customer', 'awaiting collection', 'left in a safe place' ) as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function sort_tracking_events_newest_first( array $events ): array {
		usort(
			$events,
			static function ( $a, $b ): int {
				$a_time = is_array( $a ) ? strtotime( (string) ( $a['date'] ?? $a['event_time'] ?? $a['datetime'] ?? $a['time'] ?? $a['timestamp'] ?? '' ) ) : false;
				$b_time = is_array( $b ) ? strtotime( (string) ( $b['date'] ?? $b['event_time'] ?? $b['datetime'] ?? $b['time'] ?? $b['timestamp'] ?? '' ) ) : false;

				$a_ts = false === $a_time ? 0 : (int) $a_time;
				$b_ts = false === $b_time ? 0 : (int) $b_time;

				return $b_ts <=> $a_ts;
			}
		);

		return $events;
	}

	private function format_tracking_datetime( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}
}
