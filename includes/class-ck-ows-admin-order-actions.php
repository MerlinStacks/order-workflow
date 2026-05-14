<?php
/**
 * Admin order actions module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Admin_Order_Actions {
	private const ACTION_SET_IN_PRODUCTION    = 'ck_ows_set_in_production';
	private const ACTION_SET_IN_DISPATCH      = 'ck_ows_set_in_dispatch';
	private const ACTION_SET_AWAITING_ARTWORK = 'ck_ows_set_awaiting_artwork';
	private const STATUS_ACTION_NONCE         = 'ck_ows_set_order_status';
	private const STATUS_ACTION_NONCE_FIELD   = 'ck_ows_status_nonce';

	private static ?CK_OWS_Admin_Order_Actions $instance = null;

	public static function instance(): CK_OWS_Admin_Order_Actions {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_actions' ) );

		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_actions' ), 10, 3 );

		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_row_actions' ), 20, 2 );
		add_action( 'admin_post_ck_ows_set_order_status', array( $this, 'handle_row_action' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function register_bulk_actions( array $actions ): array {
		$actions[ self::ACTION_SET_AWAITING_ARTWORK ] = __( 'Change status to Awaiting Artwork Approval', 'ck-order-workflow-suite' );
		$actions[ self::ACTION_SET_IN_PRODUCTION ]    = __( 'Change status to In Production', 'ck-order-workflow-suite' );
		$actions[ self::ACTION_SET_IN_DISPATCH ]      = __( 'Change status to In Dispatch', 'ck-order-workflow-suite' );

		return $actions;
	}

	public function handle_bulk_actions( string $redirect_to, string $action, array $order_ids ): string {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect_to;
		}

		$status = $this->action_to_status( $action );

		if ( '' === $status ) {
			return $redirect_to;
		}

		$updated = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( absint( $order_id ) );

			if ( ! $order ) {
				continue;
			}

			if ( 'awaiting-artwork' === $status && ! $this->order_has_artwork_proof( $order ) ) {
				continue;
			}

			if ( 'in-production' === $status && ! $this->order_can_move_to_production( $order ) ) {
				continue;
			}

			$order->update_status(
				$status,
				__( 'Order status updated from bulk action.', 'ck-order-workflow-suite' ),
				true
			);
			$updated++;
		}

		if ( $updated > 0 ) {
			$redirect_to = add_query_arg(
				array(
					'ck_ows_bulk_updated' => $updated,
					'ck_ows_new_status'   => $status,
				),
				$redirect_to
			);
		}

		return $redirect_to;
	}

	public function add_row_actions( array $actions, WC_Order $order ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $actions;
		}

		$current_status = $order->get_status();

		if ( 'awaiting-artwork' !== $current_status && $this->order_has_artwork_proof( $order ) ) {
			$actions['ck_ows_awaiting_artwork'] = array(
				'url'    => $this->build_row_action_url( $order->get_id(), 'awaiting-artwork' ),
				'name'   => __( 'Awaiting Artwork Approval', 'ck-order-workflow-suite' ),
				'action' => 'ck-ows-awaiting-artwork',
			);
		}

		if ( 'in-production' !== $current_status ) {
			$actions['ck_ows_in_production'] = array(
				'url'    => $this->build_row_action_url( $order->get_id(), 'in-production' ),
				'name'   => __( 'In Production', 'ck-order-workflow-suite' ),
				'action' => 'ck-ows-in-production',
			);
		}

		if ( 'in-dispatch' !== $current_status ) {
			$actions['ck_ows_in_dispatch'] = array(
				'url'    => $this->build_row_action_url( $order->get_id(), 'in-dispatch' ),
				'name'   => __( 'In Dispatch', 'ck-order-workflow-suite' ),
				'action' => 'ck-ows-in-dispatch',
			);
		}

		return $actions;
	}

	public function handle_row_action(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ck-order-workflow-suite' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		check_admin_referer( self::STATUS_ACTION_NONCE, self::STATUS_ACTION_NONCE_FIELD );

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		if ( 'awaiting-artwork' === $status && ! $this->order_has_artwork_proof( $order ) ) {
			$redirect = $this->get_redirect_url();
			$redirect = add_query_arg( 'ck_ows_missing_artwork', 1, $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'in-production' === $status && ! $this->order_can_move_to_production( $order ) ) {
			$redirect = $this->get_redirect_url();
			$redirect = add_query_arg( 'ck_ows_artwork_approval_required', 1, $redirect );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! in_array( $status, array( 'awaiting-artwork', 'in-production', 'in-dispatch' ), true ) ) {
			wp_die( esc_html__( 'Invalid status transition.', 'ck-order-workflow-suite' ) );
		}

		$order->update_status( $status, __( 'Order status updated from quick action.', 'ck-order-workflow-suite' ), true );

		$redirect = $this->get_redirect_url();
		$redirect = add_query_arg(
			array(
				'ck_ows_status_updated' => 1,
				'ck_ows_new_status'     => $status,
			),
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function admin_notices(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		if ( isset( $_GET['ck_ows_status_updated'] ) && isset( $_GET['ck_ows_new_status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_GET['ck_ows_new_status'] ) );
			/* translators: %s: order status label */
			$message = sprintf( esc_html__( 'Order updated to %s.', 'ck-order-workflow-suite' ), esc_html( $this->format_status_label( $status ) ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_bulk_updated'] ) && isset( $_GET['ck_ows_new_status'] ) ) {
			$updated = absint( wp_unslash( $_GET['ck_ows_bulk_updated'] ) );
			$status  = sanitize_text_field( wp_unslash( $_GET['ck_ows_new_status'] ) );
			/* translators: 1: updated count, 2: order status label */
			$message = sprintf( esc_html__( '%1$d order(s) updated to %2$s.', 'ck-order-workflow-suite' ), $updated, esc_html( $this->format_status_label( $status ) ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_missing_artwork'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Cannot move order to Awaiting Artwork Approval until a proof PDF is uploaded.', 'ck-order-workflow-suite' );
			echo '</p></div>';
		}

		if ( isset( $_GET['ck_ows_artwork_approval_required'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Cannot move order to In Production until artwork is approved or a staff override is recorded.', 'ck-order-workflow-suite' );
			echo '</p></div>';
		}
	}

	private function action_to_status( string $action ): string {
		$map = array(
			self::ACTION_SET_AWAITING_ARTWORK => 'awaiting-artwork',
			self::ACTION_SET_IN_PRODUCTION    => 'in-production',
			self::ACTION_SET_IN_DISPATCH      => 'in-dispatch',
		);

		return $map[ $action ] ?? '';
	}

	private function build_row_action_url( int $order_id, string $status ): string {
		$url = add_query_arg(
			array(
				'action'   => 'ck_ows_set_order_status',
				'order_id' => $order_id,
				'status'   => $status,
				'redirect' => $this->get_redirect_url(),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::STATUS_ACTION_NONCE, self::STATUS_ACTION_NONCE_FIELD );
	}

	private function get_redirect_url(): string {
		$fallback = admin_url( 'edit.php?post_type=shop_order' );

		if ( isset( $_GET['redirect'] ) ) {
			$redirect = esc_url_raw( wp_unslash( $_GET['redirect'] ) );

			return $this->sanitize_redirect_url( $redirect, $fallback );
		}

		$referer = wp_get_referer();

		if ( $referer ) {
			return $this->sanitize_redirect_url( $referer, $fallback );
		}

		return $fallback;
	}

	private function sanitize_redirect_url( string $redirect, string $fallback ): string {
		$validated = wp_validate_redirect( $redirect, '' );

		if ( '' === $validated ) {
			return $fallback;
		}

		$admin_base_parts = wp_parse_url( admin_url() );
		$validated_parts  = wp_parse_url( $validated );

		if ( ! is_array( $admin_base_parts ) || ! is_array( $validated_parts ) ) {
			return $fallback;
		}

		$admin_scheme = isset( $admin_base_parts['scheme'] ) ? strtolower( (string) $admin_base_parts['scheme'] ) : '';
		$admin_host   = isset( $admin_base_parts['host'] ) ? strtolower( (string) $admin_base_parts['host'] ) : '';
		$admin_port   = isset( $admin_base_parts['port'] ) ? absint( $admin_base_parts['port'] ) : 0;
		$admin_path   = isset( $admin_base_parts['path'] ) ? (string) $admin_base_parts['path'] : '/wp-admin/';

		$val_scheme = isset( $validated_parts['scheme'] ) ? strtolower( (string) $validated_parts['scheme'] ) : '';
		$val_host   = isset( $validated_parts['host'] ) ? strtolower( (string) $validated_parts['host'] ) : '';
		$val_port   = isset( $validated_parts['port'] ) ? absint( $validated_parts['port'] ) : 0;
		$val_path   = isset( $validated_parts['path'] ) ? (string) $validated_parts['path'] : '';

		if ( '' === $admin_scheme || '' === $admin_host || '' === $val_scheme || '' === $val_host ) {
			return $fallback;
		}

		if ( $val_scheme !== $admin_scheme || $val_host !== $admin_host ) {
			return $fallback;
		}

		if ( $admin_port !== $val_port ) {
			if ( 0 !== $admin_port || 0 !== $val_port ) {
				return $fallback;
			}
		}

		$admin_path = untrailingslashit( $admin_path ) . '/';

		if ( 0 !== strpos( $val_path, $admin_path ) ) {
			return $fallback;
		}

		return $validated;
	}

	private function format_status_label( string $status ): string {
		$status = str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status;
		$all    = wc_get_order_statuses();

		return $all[ $status ] ?? ucfirst( str_replace( '-', ' ', str_replace( 'wc-', '', $status ) ) );
	}

	private function order_has_artwork_proof( WC_Order $order ): bool {
		if ( class_exists( 'CK_OWS_Artwork_Proof' ) ) {
			return CK_OWS_Artwork_Proof::order_has_artwork_proof( $order );
		}

		return false;
	}

	private function order_can_move_to_production( WC_Order $order ): bool {
		if ( class_exists( 'CK_OWS_Artwork_Proof' ) ) {
			return CK_OWS_Artwork_Proof::order_can_move_to_production( $order );
		}

		return true;
	}
}
