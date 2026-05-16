<?php
/**
 * Order timeline module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Order_Timeline {
	private const META_TS_PROCESSING         = '_ck_ows_ts_processing';
	private const META_TS_AWAITING_ARTWORK  = '_ck_ows_ts_awaiting_artwork_approval';
	private const META_TS_IN_PRODUCTION     = '_ck_ows_ts_in_production';
	private const META_TS_IN_DISPATCH       = '_ck_ows_ts_in_dispatch';
	private const META_TS_DELIVERED         = '_ck_ows_ts_delivered';
	private const META_LIVE_TRACKING        = '_ck_ows_live_tracking';

	private static ?CK_OWS_Order_Timeline $instance = null;

	public static function instance(): CK_OWS_Order_Timeline {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'capture_stage_timestamp' ), 30, 4 );
		add_action( 'woocommerce_thankyou', array( $this, 'render_timeline' ), 15 );
		add_action( 'woocommerce_view_order', array( $this, 'render_timeline' ), 5 );
	}

	public function capture_stage_timestamp( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		$meta_key = $this->status_to_meta_key( $to_status );

		if ( '' === $meta_key ) {
			return;
		}

		$order->update_meta_data( $meta_key, time() );
		$order->save();
	}

	public function render_timeline( $order ): void {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( (int) $order );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		echo $this->get_timeline_markup( $order, false );
	}

	public function get_timeline_markup( WC_Order $order, bool $allow_without_login = false ): string {
		if ( ! $allow_without_login && ! is_user_logged_in() ) {
			return '';
		}

		$is_owner = is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id();
		$is_staff = is_user_logged_in() && ( current_user_can( 'edit_shop_order', $order->get_id() ) || current_user_can( 'edit_shop_orders' ) );

		if ( ! $allow_without_login && ! $is_owner && ! $is_staff ) {
			return '';
		}

		ob_start();

		$this->backfill_missing_timestamps( $order );

		$has_artwork_stage = class_exists( 'CK_OWS_Artwork_Proof' ) && CK_OWS_Artwork_Proof::order_has_artwork_proof( $order );

		$stages = array(
			array(
				'key'   => 'processing',
				'label' => __( 'Processing', 'ck-order-workflow-suite' ),
				'ts'    => (int) $order->get_meta( self::META_TS_PROCESSING, true ),
			),
		);

		if ( $has_artwork_stage ) {
			$stages[] = array(
				'key'   => 'awaiting-artwork',
				'label' => __( 'Artwork Approval', 'ck-order-workflow-suite' ),
				'ts'    => (int) $order->get_meta( self::META_TS_AWAITING_ARTWORK, true ),
			);
		}

		$stages[] = array(
			'key'   => 'in-production',
			'label' => __( 'In Production', 'ck-order-workflow-suite' ),
			'ts'    => (int) $order->get_meta( self::META_TS_IN_PRODUCTION, true ),
		);
		$stages[] = array(
			'key'   => 'in-dispatch',
			'label' => __( 'In Dispatch', 'ck-order-workflow-suite' ),
			'ts'    => (int) $order->get_meta( self::META_TS_IN_DISPATCH, true ),
		);

		$stages[] = array(
			'key'   => 'completed',
			'label' => __( 'Dispatched', 'ck-order-workflow-suite' ),
			'ts'    => (int) $order->get_meta( self::META_TS_DELIVERED, true ),
		);

		$tracking_stages = $this->resolve_auspost_tracking_stages( $order );
		if ( ! empty( $tracking_stages ) ) {
			$stages = array_merge( $stages, $tracking_stages );
		}

		$current_status = $order->get_status();

		if ( $this->is_non_progress_status( $current_status ) ) {
			$this->render_non_progress_notice( $order, $current_status );
			return (string) ob_get_clean();
		}

		$current_index  = $this->resolve_current_index( $stages, $current_status );
		$created_date   = $order->get_date_created();
		$created_label  = $created_date ? wp_date( get_option( 'date_format' ), $created_date->getTimestamp() ) : __( 'an earlier date', 'ck-order-workflow-suite' );

		echo '<section class="ck-ows-order-timeline">';
		echo '<h2 class="ck-ows-order-timeline__title">' . esc_html__( 'Order Progress', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p class="ck-ows-order-timeline__intro">' . esc_html__( 'Follow each stage of your order. Carrier scans may take up to 24 hours to appear.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<p class="ck-ows-order-timeline__summary">';
		/* translators: 1: order number, 2: order placed date. */
		echo '<span>' . sprintf( esc_html__( 'Order #%1$s placed on %2$s', 'ck-order-workflow-suite' ), esc_html( $order->get_order_number() ), esc_html( $created_label ) ) . '</span>';
		echo '</p>';
		echo '<ol class="ck-ows-order-timeline__list" aria-label="' . esc_attr__( 'Order progress timeline', 'ck-order-workflow-suite' ) . '">';

		foreach ( $stages as $index => $stage ) {
			$state = 'upcoming';

			if ( $stage['ts'] > 0 ) {
				$state = 'done';
			} elseif ( $index === $current_index ) {
				$state = 'current';
			}

			echo '<li class="ck-ows-order-timeline__item is-' . esc_attr( $state ) . '">';
			echo '<div class="ck-ows-order-timeline__dot" aria-hidden="true">';
			echo '<span class="ck-ows-order-timeline__icon">' . $this->get_stage_icon_svg( (string) $stage['key'] ) . '</span>';
			echo '</div>';
			echo '<div class="ck-ows-order-timeline__content">';
			echo '<strong>' . esc_html( (string) $stage['label'] ) . '</strong>';

			if ( $stage['ts'] > 0 ) {
				echo '<div>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stage['ts'] ) ) . '</div>';
			} elseif ( 'current' === $state ) {
				echo '<div>' . esc_html__( 'In progress', 'ck-order-workflow-suite' ) . '</div>';
			}

			echo '</div>';
			echo '</li>';
		}

		echo '</ol>';
		echo '</section>';

		return (string) ob_get_clean();
	}

	private function status_to_meta_key( string $status ): string {
		$map = array(
			'processing'                => self::META_TS_PROCESSING,
			'awaiting-artwork' => self::META_TS_AWAITING_ARTWORK,
			'in-production'             => self::META_TS_IN_PRODUCTION,
			'in-dispatch'               => self::META_TS_IN_DISPATCH,
			'completed'                 => self::META_TS_DELIVERED,
		);

		return $map[ $status ] ?? '';
	}

	private function backfill_missing_timestamps( WC_Order $order ): void {
		$changed = false;

		if ( (int) $order->get_meta( self::META_TS_PROCESSING, true ) <= 0 ) {
			$seed = $order->get_date_paid();
			if ( ! $seed ) {
				$seed = $order->get_date_created();
			}
			if ( $seed ) {
				$order->update_meta_data( self::META_TS_PROCESSING, $seed->getTimestamp() );
				$changed = true;
			}
		}

		if ( 'completed' === $order->get_status() && (int) $order->get_meta( self::META_TS_DELIVERED, true ) <= 0 ) {
			$completed = $order->get_date_completed();
			if ( $completed ) {
				$order->update_meta_data( self::META_TS_DELIVERED, $completed->getTimestamp() );
				$changed = true;
			}
		}

		if ( $changed ) {
			$order->save();
		}
	}

	private function resolve_current_index( array $stages, string $order_status ): int {
		if ( $this->is_non_progress_status( $order_status ) ) {
			return -1;
		}

		foreach ( $stages as $index => $stage ) {
			if ( $stage['key'] === $order_status ) {
				return $index;
			}
		}

		$last_done = 0;
		foreach ( $stages as $index => $stage ) {
			if ( (int) $stage['ts'] > 0 ) {
				$last_done = $index;
			}
		}

		return min( $last_done + 1, count( $stages ) - 1 );
	}

	private function is_non_progress_status( string $order_status ): bool {
		return in_array( $order_status, array( 'pending', 'failed', 'cancelled', 'refunded', 'on-hold', 'draft', 'checkout-draft' ), true );
	}

	private function render_non_progress_notice( WC_Order $order, string $order_status ): void {
		$status_label  = wc_get_order_status_name( $order_status );
		$reason_labels = array(
			'pending'        => __( 'This order is waiting for payment confirmation before it can move into fulfilment.', 'ck-order-workflow-suite' ),
			'failed'         => __( 'We could not complete payment for this order.', 'ck-order-workflow-suite' ),
			'cancelled'      => __( 'This order was cancelled and will not continue through production or dispatch.', 'ck-order-workflow-suite' ),
			'refunded'       => __( 'This order was refunded and is no longer progressing through production or dispatch.', 'ck-order-workflow-suite' ),
			'on-hold'        => __( 'This order is currently on hold and is waiting for review before it can continue.', 'ck-order-workflow-suite' ),
			'draft'          => __( 'This order is still a draft and has not been placed yet.', 'ck-order-workflow-suite' ),
			'checkout-draft' => __( 'Checkout has not been completed for this order yet.', 'ck-order-workflow-suite' ),
		);

		$reason = $reason_labels[ $order_status ] ?? __( 'This order is not currently in the active fulfilment workflow.', 'ck-order-workflow-suite' );

		echo '<section class="ck-ows-order-timeline ck-ows-order-timeline--notice">';
		echo '<h2 class="ck-ows-order-timeline__title">' . esc_html__( 'Order Progress', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p class="ck-ows-order-timeline__intro">' . esc_html__( 'This order is not currently moving through the fulfilment timeline.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<p class="ck-ows-order-timeline__summary">';
		echo '<span>' . esc_html__( 'Current status', 'ck-order-workflow-suite' ) . '</span>';
		echo '<span class="ck-ows-order-timeline__status">' . esc_html( $status_label ) . '</span>';
		echo '</p>';
		echo '<p class="ck-ows-order-timeline__notice">' . esc_html( $reason ) . '</p>';
		echo '</section>';
	}

	private function resolve_auspost_tracking_stages( WC_Order $order ): array {
		$tracking = $order->get_meta( self::META_LIVE_TRACKING, true );

		if ( class_exists( 'CK_OWS_Tracking' ) ) {
			$tracking = CK_OWS_Tracking::instance()->get_tracking_payload_for_order( $order );
		}

		if ( ! is_array( $tracking ) || empty( $tracking ) ) {
			return array();
		}

		$provider = strtolower( trim( (string) ( $tracking['provider'] ?? '' ) ) );
		if ( '' !== $provider && 'auspost' !== $provider ) {
			return array();
		}


		$events = $this->extract_tracking_events_from_payload( is_array( $tracking['raw'] ?? null ) ? $tracking['raw'] : array() );

		if ( empty( $events ) ) {
			return array();
		}

		$milestones = array(
			'received-by-auspost' => array(
				'label'    => __( 'Received by Auspost', 'ck-order-workflow-suite' ),
				'patterns' => array( 'received by australia post', 'received by auspost', 'lodged', 'accepted by carrier', 'received by carrier' ),
			),
			'in-transit'          => array(
				'label'    => __( 'In Transit', 'ck-order-workflow-suite' ),
				'patterns' => array( 'in transit', 'transit', 'processed at facility', 'processed through facility', 'onboard for delivery' ),
			),
			'out-for-delivery'    => array(
				'label'    => __( 'Out for Delivery', 'ck-order-workflow-suite' ),
				'patterns' => array( 'out for delivery', 'onboard for delivery', 'with driver for delivery' ),
			),
			'delivered'           => array(
				'label'    => __( 'Delivered', 'ck-order-workflow-suite' ),
				'patterns' => array( 'delivered', 'proof of delivery', 'item delivered', 'successfully delivered', 'delivery complete', 'left in a safe place', 'awaiting collection', 'collected by customer' ),
			),
			'return-to-sender'    => array(
				'label'    => __( 'Returning to CustomKings', 'ck-order-workflow-suite' ),
				'patterns' => array( 'return to sender', 'returning to sender', 'returned to sender' ),
			),
		);

		$found_timestamps = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$description = strtolower( trim( (string) ( $event['description'] ?? $event['event_description'] ?? $event['event'] ?? $event['summary'] ?? $event['title'] ?? '' ) ) );
			$status      = strtolower( trim( (string) ( $event['status'] ?? '' ) ) );
			$haystack    = trim( $description . ' ' . $status );

			if ( '' === $haystack ) {
				continue;
			}

			$event_ts = $this->resolve_event_timestamp(
				(string) ( $event['date'] ?? $event['event_time'] ?? $event['datetime'] ?? $event['time'] ?? $event['timestamp'] ?? '' )
			);

			foreach ( $milestones as $key => $milestone ) {
				foreach ( $milestone['patterns'] as $pattern ) {
					if ( false !== strpos( $haystack, $pattern ) ) {
						if ( ! isset( $found_timestamps[ $key ] ) || ( $event_ts > 0 && $event_ts < $found_timestamps[ $key ] ) ) {
							$found_timestamps[ $key ] = $event_ts;
						}
						break;
					}
				}
			}
		}

		$tracking_status = strtolower( trim( (string) ( $tracking['status'] ?? $tracking['tracking_status'] ?? $tracking['raw']['status'] ?? $tracking['raw']['tracking_status'] ?? $tracking['raw']['delivery_status'] ?? '' ) ) );
		$status_ts       = $this->resolve_event_timestamp( (string) ( $tracking['last_event']['date'] ?? '' ) );

		if ( '' !== $tracking_status ) {
			if ( false !== strpos( $tracking_status, 'delivered' ) ) {
				if ( ! isset( $found_timestamps['delivered'] ) ) {
					$found_timestamps['delivered'] = $status_ts;
				}
			} elseif ( false !== strpos( $tracking_status, 'out for delivery' ) ) {
				if ( ! isset( $found_timestamps['out-for-delivery'] ) ) {
					$found_timestamps['out-for-delivery'] = $status_ts;
				}
			} elseif ( false !== strpos( $tracking_status, 'transit' ) ) {
				if ( ! isset( $found_timestamps['in-transit'] ) ) {
					$found_timestamps['in-transit'] = $status_ts;
				}
			}
		}

		$stages = array();
		foreach ( array( 'received-by-auspost', 'in-transit', 'out-for-delivery', 'delivered' ) as $key ) {
			if ( isset( $found_timestamps[ $key ] ) ) {
				$stages[] = array(
					'key'   => $key,
					'label' => $milestones[ $key ]['label'],
					'ts'    => (int) $found_timestamps[ $key ],
				);
			}
		}

		if ( isset( $found_timestamps['return-to-sender'] ) ) {
			$stages[] = array(
				'key'   => 'return-to-sender',
				'label' => $milestones['return-to-sender']['label'],
				'ts'    => (int) $found_timestamps['return-to-sender'],
			);
		}

		return $stages;
	}

	private function resolve_event_timestamp( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return 0;
		}

		return (int) $timestamp;
	}

	private function extract_tracking_events_from_payload( array $payload ): array {
		$event_keys = array( 'events', 'tracking_events', 'article_events', 'tracking_details' );

		foreach ( $event_keys as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$events = $this->normalize_tracking_events( $payload[ $key ] );
				if ( ! empty( $events ) ) {
					return $events;
				}
			}
		}

		return $this->collect_tracking_events_from_node( $payload );
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

			$nested = $this->collect_tracking_events_from_node( $event );
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

	private function get_stage_icon_svg( string $stage_key ): string {
		if ( 'delivered' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>';
		}

		if ( 'completed' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M5 3h10l4 4v14H5z"/><path d="M15 3v4h4"/><path d="M8 12h8M8 16h5"/></svg>';
		}

		if ( 'received-by-auspost' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M12 3l8 4.5-8 4.5-8-4.5z"/><path d="M4 7.5V16.5L12 21l8-4.5V7.5"/></svg>';
		}

		if ( 'in-transit' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M2 13h9l3-4h4l4 4v4h-2"/><circle cx="7" cy="17" r="1.7"/><circle cx="18" cy="17" r="1.7"/><path d="M11 17h5"/></svg>';
		}

		if ( 'out-for-delivery' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M2 13h11v4H2z"/><path d="M13 13h4l3 3v1h-7z"/><circle cx="7" cy="18" r="1.7"/><circle cx="18" cy="18" r="1.7"/></svg>';
		}

		if ( 'return-to-sender' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M20 12a8 8 0 1 1-2.35-5.66"/><path d="M20 4v6h-6"/></svg>';
		}

		if ( 'in-dispatch' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M3 7h11v8H3z"/><path d="M14 10h3l3 3v2h-6z"/><circle cx="8" cy="17" r="1.7"/><circle cx="18" cy="17" r="1.7"/></svg>';
		}

		if ( 'awaiting-artwork' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M3 5h14v10H3z"/><path d="M7.5 10h5"/><circle cx="20" cy="15" r="3"/><path d="M20 13.5v3M18.5 15h3"/></svg>';
		}

		if ( 'in-production' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M5 5h10v10H5z"/><path d="M8.5 2.5v3M11.5 2.5v3M8.5 15v3M11.5 15v3M2.5 8.5h3M2.5 11.5h3M15 8.5h3M15 11.5h3"/></svg>';
		}

		return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M12 3l8 4.5-8 4.5-8-4.5z"/><path d="M4 7.5V16.5L12 21l8-4.5V7.5"/></svg>';
	}
}
