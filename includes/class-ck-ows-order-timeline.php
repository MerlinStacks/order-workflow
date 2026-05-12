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
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_timeline' ), 15 );
	}

	public function capture_stage_timestamp( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		$meta_key = $this->status_to_meta_key( $to_status );

		if ( '' === $meta_key ) {
			return;
		}

		if ( (int) $order->get_meta( $meta_key, true ) > 0 ) {
			return;
		}

		$order->update_meta_data( $meta_key, time() );
		$order->save();
	}

	public function render_timeline( WC_Order $order ): void {
		if ( ! is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id() ) {
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
		$current_index  = $this->resolve_current_index( $stages, $current_status );

		echo '<section class="ck-ows-order-timeline">';
		echo '<h2>' . esc_html__( 'Order Progress', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Follow each stage of your order. Carrier scans may take up to 24 hours to appear.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<ol class="ck-ows-order-timeline__list">';

		foreach ( $stages as $index => $stage ) {
			$state = 'upcoming';

			if ( $stage['ts'] > 0 ) {
				$state = 'done';
			} elseif ( $index === $current_index ) {
				$state = 'current';
			}

			echo '<li class="ck-ows-order-timeline__item is-' . esc_attr( $state ) . '">';
			echo '<div class="ck-ows-order-timeline__dot" aria-hidden="true"></div>';
			echo '<div class="ck-ows-order-timeline__content">';
			echo '<strong>' . esc_html( (string) $stage['label'] ) . '</strong>';

			if ( $stage['ts'] > 0 ) {
				echo '<div>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stage['ts'] ) ) . '</div>';
			} elseif ( 'current' === $state ) {
				echo '<div>' . esc_html__( 'In progress', 'ck-order-workflow-suite' ) . '</div>';
			} else {
				echo '<div>' . esc_html__( 'Pending', 'ck-order-workflow-suite' ) . '</div>';
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
}
