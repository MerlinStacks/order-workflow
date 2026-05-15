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

	private static ?CK_OWS_Order_Timeline $instance = null;

	public static function instance(): CK_OWS_Order_Timeline {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'capture_stage_timestamp' ), 30, 4 );
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'render_timeline' ), 5 );
	}

	public function capture_stage_timestamp( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		$meta_key = $this->status_to_meta_key( $to_status );

		if ( '' === $meta_key ) {
			return;
		}

		$order->update_meta_data( $meta_key, time() );
		$order->save();
	}

	public function render_timeline( WC_Order $order ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$is_owner = (int) $order->get_user_id() === get_current_user_id();
		$is_staff = current_user_can( 'edit_shop_order', $order->get_id() ) || current_user_can( 'edit_shop_orders' );

		if ( ! $is_owner && ! $is_staff ) {
			return;
		}

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
			'label' => __( 'Delivered', 'ck-order-workflow-suite' ),
			'ts'    => (int) $order->get_meta( self::META_TS_DELIVERED, true ),
		);

		$current_status = $order->get_status();

		if ( $this->is_non_progress_status( $current_status ) ) {
			$this->render_non_progress_notice( $order, $current_status );
			return;
		}

		$current_index  = $this->resolve_current_index( $stages, $current_status );
		$status_label   = wc_get_order_status_name( $current_status );
		$created_date   = $order->get_date_created();
		$created_label  = $created_date ? wp_date( get_option( 'date_format' ), $created_date->getTimestamp() ) : __( 'an earlier date', 'ck-order-workflow-suite' );

		echo '<section class="ck-ows-order-timeline">';
		echo '<h2 class="ck-ows-order-timeline__title">' . esc_html__( 'Order Progress', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p class="ck-ows-order-timeline__intro">' . esc_html__( 'Follow each stage of your order. Carrier scans may take up to 24 hours to appear.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<p class="ck-ows-order-timeline__summary">';
		echo '<span>' . sprintf( esc_html__( 'Order #%1$s placed on %2$s', 'ck-order-workflow-suite' ), esc_html( $order->get_order_number() ), esc_html( $created_label ) ) . '</span>';
		echo '<span class="ck-ows-order-timeline__status">' . esc_html( $status_label ) . '</span>';
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
		return in_array( $order_status, array( 'pending', 'failed', 'cancelled', 'on-hold', 'draft', 'checkout-draft' ), true );
	}

	private function render_non_progress_notice( WC_Order $order, string $order_status ): void {
		$status_label  = wc_get_order_status_name( $order_status );
		$reason_labels = array(
			'pending'        => __( 'This order is waiting for payment confirmation before it can move into fulfilment.', 'ck-order-workflow-suite' ),
			'failed'         => __( 'We could not complete payment for this order.', 'ck-order-workflow-suite' ),
			'cancelled'      => __( 'This order was cancelled and will not continue through production or dispatch.', 'ck-order-workflow-suite' ),
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

	private function get_stage_icon_svg( string $stage_key ): string {
		if ( 'completed' === $stage_key ) {
			return '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>';
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
