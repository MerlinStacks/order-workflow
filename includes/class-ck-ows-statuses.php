<?php
/**
 * Order statuses module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Statuses {
	public const STATUS_IN_PRODUCTION       = 'wc-in-production';
	public const STATUS_IN_DISPATCH         = 'wc-in-dispatch';
	public const STATUS_AWAITING_ARTWORK    = 'wc-awaiting-artwork';
	private const WEBHOOK_BLOCK_TRANSIENT   = 'ck_ows_block_webhook_status_';

	private static ?CK_OWS_Statuses $instance = null;

	public static function instance(): CK_OWS_Statuses {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_statuses' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'track_webhook_blocked_status_transition' ), 5, 4 );
		add_filter( 'wc_order_statuses', array( $this, 'inject_statuses' ) );
		add_filter( 'woocommerce_order_is_completed_statuses', array( $this, 'exclude_custom_fulfilment_statuses' ) );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'mask_paid_cancelled_status_in_rest_response' ), 10, 3 );
		add_filter( 'woocommerce_webhook_should_deliver', array( $this, 'prevent_webhooks_for_custom_status_transitions' ), 10, 3 );
	}

	public function exclude_custom_fulfilment_statuses( array $statuses ): array {
		$blocked = array(
			'awaiting-artwork',
			'in-production',
			'in-dispatch',
			self::STATUS_AWAITING_ARTWORK,
			self::STATUS_IN_PRODUCTION,
			self::STATUS_IN_DISPATCH,
		);

		return array_values( array_diff( $statuses, $blocked ) );
	}

	public function register_statuses(): void {
		register_post_status(
			self::STATUS_IN_PRODUCTION,
			array(
				'label'                     => _x( 'In Production', 'Order status', 'ck-order-workflow-suite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders with this status. */
				'label_count'               => _n_noop(
					'In Production <span class="count">(%s)</span>',
					'In Production <span class="count">(%s)</span>',
					'ck-order-workflow-suite'
				),
			)
		);

		register_post_status(
			self::STATUS_IN_DISPATCH,
			array(
				'label'                     => _x( 'In Dispatch', 'Order status', 'ck-order-workflow-suite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders with this status. */
				'label_count'               => _n_noop(
					'In Dispatch <span class="count">(%s)</span>',
					'In Dispatch <span class="count">(%s)</span>',
					'ck-order-workflow-suite'
				),
			)
		);

		register_post_status(
			self::STATUS_AWAITING_ARTWORK,
			array(
				'label'                     => _x( 'Awaiting Artwork Approval', 'Order status', 'ck-order-workflow-suite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders with this status. */
				'label_count'               => _n_noop(
					'Awaiting Artwork Approval <span class="count">(%s)</span>',
					'Awaiting Artwork Approval <span class="count">(%s)</span>',
					'ck-order-workflow-suite'
				),
			)
		);
	}

	public function inject_statuses( array $statuses ): array {
		$updated = array();

		foreach ( $statuses as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$updated[ self::STATUS_AWAITING_ARTWORK ] = _x( 'Awaiting Artwork Approval', 'Order status', 'ck-order-workflow-suite' );
				$updated[ self::STATUS_IN_PRODUCTION ]    = _x( 'In Production', 'Order status', 'ck-order-workflow-suite' );
				$updated[ self::STATUS_IN_DISPATCH ]      = _x( 'In Dispatch', 'Order status', 'ck-order-workflow-suite' );
			}
		}

		if ( ! isset( $updated[ self::STATUS_IN_PRODUCTION ] ) ) {
			$updated[ self::STATUS_AWAITING_ARTWORK ] = _x( 'Awaiting Artwork Approval', 'Order status', 'ck-order-workflow-suite' );
			$updated[ self::STATUS_IN_PRODUCTION ]    = _x( 'In Production', 'Order status', 'ck-order-workflow-suite' );
			$updated[ self::STATUS_IN_DISPATCH ]      = _x( 'In Dispatch', 'Order status', 'ck-order-workflow-suite' );
		}

		return $updated;
	}

	public function prevent_webhooks_for_custom_status_transitions( bool $should_deliver, $webhook, $arg ): bool {
		if ( ! $should_deliver ) {
			return $should_deliver;
		}

		if ( is_array( $arg ) ) {
			$from_status = isset( $arg[1] ) ? (string) $arg[1] : '';
			$to_status   = isset( $arg[2] ) ? (string) $arg[2] : '';

			if ( '' !== $to_status && ( $this->is_custom_workflow_status( $to_status ) || $this->is_blocked_external_status_transition( $from_status, $to_status ) ) ) {
				return false;
			}

			return $should_deliver;
		}

		$order_id = is_numeric( $arg ) ? absint( $arg ) : 0;
		if ( $order_id > 0 && false !== get_transient( self::WEBHOOK_BLOCK_TRANSIENT . $order_id ) ) {
			return false;
		}

		return $should_deliver;
	}

	public function track_webhook_blocked_status_transition( int $order_id, string $from_status, string $to_status, WC_Order $order ): void {
		if ( $this->is_custom_workflow_status( $to_status ) || $this->is_blocked_external_status_transition( $from_status, $to_status ) ) {
			set_transient( self::WEBHOOK_BLOCK_TRANSIENT . $order_id, '1', 5 * MINUTE_IN_SECONDS );
		}
	}

	public function mask_paid_cancelled_status_in_rest_response( WP_REST_Response $response, WC_Order $order, WP_REST_Request $request ): WP_REST_Response {
		if ( 'cancelled' !== $order->get_status() || ! $order->get_date_paid() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$data['status'] = 'processing';
		$response->set_data( $data );

		return $response;
	}

	private function is_custom_workflow_status( string $status ): bool {
		$normalized = ( 0 === strpos( $status, 'wc-' ) ) ? substr( $status, 3 ) : $status;

		return in_array(
			$normalized,
			array(
				'awaiting-artwork',
				'in-production',
				'in-dispatch',
			),
			true
		);
	}

	private function is_blocked_external_status_transition( string $from_status, string $to_status ): bool {
		$from_status = ( 0 === strpos( $from_status, 'wc-' ) ) ? substr( $from_status, 3 ) : $from_status;
		$to_status   = ( 0 === strpos( $to_status, 'wc-' ) ) ? substr( $to_status, 3 ) : $to_status;

		if ( 'cancelled' !== $to_status ) {
			return false;
		}

		return in_array(
			$from_status,
			array(
				'processing',
				'awaiting-artwork',
				'in-production',
				'in-dispatch',
				'completed',
			),
			true
		);
	}
}
