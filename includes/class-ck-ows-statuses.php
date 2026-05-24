<?php
/**
 * Order statuses module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Statuses extends CK_OWS_Base {
	public const STATUS_IN_PRODUCTION       = 'wc-in-production';
	public const STATUS_IN_DISPATCH         = 'wc-in-dispatch';
	public const STATUS_AWAITING_ARTWORK    = 'wc-awaiting-artwork';
	private const META_EXTERNAL_SAFE_STATUS = '_ck_ows_external_safe_status';
	private const WEBHOOK_BLOCK_TRANSIENT   = 'ck_ows_block_webhook_status_';

	protected function __construct() {
		add_action( 'init', array( $this, 'register_statuses' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'track_webhook_blocked_status_transition' ), 5, 4 );
		add_filter( 'wc_order_statuses', array( $this, 'inject_statuses' ) );
		add_filter( 'woocommerce_order_is_completed_statuses', array( $this, 'exclude_custom_fulfilment_statuses' ) );
		add_filter( 'woocommerce_rest_shop_order_object_query', array( $this, 'gate_readytoship_rest_order_query' ), 10, 2 );
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'mask_paid_cancelled_status_in_rest_response' ), 10, 3 );
		add_filter( 'woocommerce_webhook_payload', array( $this, 'mask_order_status_in_webhook_payload' ), 10, 4 );
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

			if ( '' !== $to_status && $this->is_blocked_external_status_transition( $from_status, $to_status ) ) {
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
		if ( $this->is_external_safe_status( $to_status ) ) {
			$order->update_meta_data( self::META_EXTERNAL_SAFE_STATUS, $this->get_external_safe_status( $to_status ) );
			$order->save_meta_data();
		}

		if ( $this->is_blocked_external_status_transition( $from_status, $to_status ) ) {
			if ( '' === $this->get_saved_external_safe_status( $order ) && $this->is_external_safe_status( $from_status ) ) {
				$order->update_meta_data( self::META_EXTERNAL_SAFE_STATUS, $this->get_external_safe_status( $from_status ) );
				$order->save_meta_data();
			}

			set_transient( self::WEBHOOK_BLOCK_TRANSIENT . $order_id, '1', 5 * MINUTE_IN_SECONDS );
		}
	}

	public function mask_order_status_in_webhook_payload( array $payload, string $resource, int $resource_id, int $webhook_id ): array {
		if ( 'order' !== $resource || ! isset( $payload['status'] ) ) {
			return $payload;
		}

		$order = wc_get_order( $resource_id );
		if ( ! $order instanceof WC_Order || ! $this->should_mask_cancelled_status( $order, (string) $payload['status'] ) ) {
			return $payload;
		}

		$payload['status'] = $this->get_cancelled_status_mask( $order );

		return $payload;
	}

	public function gate_readytoship_rest_order_query( array $args, WP_REST_Request $request ): array {
		if ( ! $this->is_readytoship_rest_request( $request ) ) {
			return $args;
		}

		$args['status'] = array( self::STATUS_IN_DISPATCH );

		return $args;
	}

	public function mask_paid_cancelled_status_in_rest_response( WP_REST_Response $response, WC_Order $order, WP_REST_Request $request ): WP_REST_Response {
		if ( $this->is_readytoship_rest_request( $request ) ) {
			$data = $response->get_data();
			if ( ! is_array( $data ) ) {
				return $response;
			}

			$data['status'] = $this->get_readytoship_rest_status( $order );
			$response->set_data( $data );

			return $response;
		}

		$status = $order->get_status();
		if ( $this->should_mask_cancelled_status( $order, $status ) ) {
			return $response;
		}

		return $response;
	}

	private function get_readytoship_rest_status( WC_Order $order ): string {
		$status = $order->get_status();

		if ( 'completed' === $status ) {
			return 'completed';
		}

		if ( 'cancelled' === $status && ! $this->should_mask_cancelled_status( $order, $status ) ) {
			return 'cancelled';
		}

		if ( 'processing' === $status || $this->is_custom_workflow_status( $status ) || $this->should_mask_cancelled_status( $order, $status ) ) {
			return 'processing';
		}

		return $status;
	}

	private function is_readytoship_rest_request( WP_REST_Request $request ): bool {
		$suffix = '';

		if ( class_exists( 'CK_OWS_Settings' ) ) {
			$suffix = sanitize_key( (string) CK_OWS_Settings::get( 'readytoship_consumer_key_suffix', '' ) );
		}

		if ( '' === $suffix ) {
			return false;
		}

		$consumer_key = $this->get_rest_consumer_key_from_request( $request );
		if ( '' === $consumer_key || ! CK_OWS_Utils::string_ends_with( sanitize_key( $consumer_key ), $suffix ) ) {
			return false;
		}

		$description = class_exists( 'CK_OWS_Settings' ) ? trim( (string) CK_OWS_Settings::get( 'readytoship_key_description', '' ) ) : '';
		if ( '' === $description ) {
			return true;
		}

		return $this->rest_consumer_key_description_matches( $consumer_key, $description );
	}

	private function get_rest_consumer_key_from_request( WP_REST_Request $request ): string {
		$consumer_key = (string) $request->get_param( 'consumer_key' );
		if ( '' !== $consumer_key ) {
			return $consumer_key;
		}

		$consumer_key = (string) $request->get_param( 'oauth_consumer_key' );
		if ( '' !== $consumer_key ) {
			return $consumer_key;
		}

		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) );
		}

		$authorization = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$authorization = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$authorization = sanitize_text_field( wp_unslash( (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( 0 !== stripos( $authorization, 'Basic ' ) ) {
			return '';
		}

		$decoded = base64_decode( substr( $authorization, 6 ), true );
		if ( ! is_string( $decoded ) || false === strpos( $decoded, ':' ) ) {
			return '';
		}

		return (string) strtok( $decoded, ':' );
	}

	private function rest_consumer_key_description_matches( string $consumer_key, string $description ): bool {
		global $wpdb;

		$consumer_key = sanitize_key( $consumer_key );
		$suffix       = substr( $consumer_key, -7 );

		if ( '' === $suffix ) {
			return false;
		}

		$table = $wpdb->prefix . 'woocommerce_api_keys';
		$match = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT key_id FROM {$table} WHERE truncated_key = %s AND description = %s LIMIT 1",
				$suffix,
				$description
			)
		);

		return null !== $match;
	}

	private function should_mask_cancelled_status( WC_Order $order, string $status ): bool {
		if ( 'cancelled' !== $status ) {
			return false;
		}

		return $order->get_date_paid() || '' !== $this->get_saved_external_safe_status( $order );
	}

	private function get_cancelled_status_mask( WC_Order $order ): string {
		$saved_status = $this->get_saved_external_safe_status( $order );
		if ( '' !== $saved_status ) {
			return $saved_status;
		}

		return 'processing';
	}

	private function get_saved_external_safe_status( WC_Order $order ): string {
		$status = sanitize_key( (string) $order->get_meta( self::META_EXTERNAL_SAFE_STATUS, true ) );

		return in_array( $status, array( 'processing', 'completed' ), true ) ? $status : '';
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

	private function is_external_safe_status( string $status ): bool {
		$status = ( 0 === strpos( $status, 'wc-' ) ) ? substr( $status, 3 ) : $status;

		return in_array(
			$status,
			array(
				'processing',
				'completed',
				'awaiting-artwork',
				'in-production',
				'in-dispatch',
			),
			true
		);
	}

	private function get_external_safe_status( string $status ): string {
		$status = ( 0 === strpos( $status, 'wc-' ) ) ? substr( $status, 3 ) : $status;

		return 'completed' === $status ? 'completed' : 'processing';
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
