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
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_' . self::ACTION_SET_AWAITING_ARTWORK, array( $this, 'handle_row_action' ) );
		add_action( 'admin_post_' . self::ACTION_SET_IN_PRODUCTION, array( $this, 'handle_row_action' ) );
		add_action( 'admin_post_' . self::ACTION_SET_IN_DISPATCH, array( $this, 'handle_row_action' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function enqueue_admin_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}

		$valid_screens = array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$valid_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! in_array( $screen->id, $valid_screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'ck-ows-admin-ui',
			CK_OWS_URL . 'assets/css/admin-ui.css',
			array(),
			CK_OWS_VERSION
		);
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
		$failed  = 0;

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

			if ( ! $this->safe_update_order_status( $order, $status, __( 'Order status updated from bulk action.', 'ck-order-workflow-suite' ), 'bulk_status_update' ) ) {
				$failed++;
				continue;
			}

			$updated++;
		}

		if ( $updated > 0 || $failed > 0 ) {
			$redirect_to = add_query_arg(
				array(
					'ck_ows_bulk_updated' => $updated,
					'ck_ows_bulk_failed'  => $failed,
					'ck_ows_new_status'   => $status,
				),
				$redirect_to
			);
		}

		return $redirect_to;
	}

	public function add_row_actions( array $actions, WC_Order $order ): array {
		if ( ! $this->current_user_can_edit_order( $order ) ) {
			return $actions;
		}

		$current_status = $order->get_status();

		foreach ( $this->get_quick_status_actions() as $status => $action ) {
			if ( $status === $current_status || ! $this->can_show_status_action( $order, $status ) ) {
				continue;
			}

			$actions[ 'ck_ows_' . str_replace( '-', '_', $status ) ] = array(
				'url'    => $this->build_row_action_url( $order->get_id(), $status ),
				'name'   => $action['label'],
				'action' => $action['action'],
			);
		}

		return $actions;
	}

	public function add_order_metabox( string $screen ): void {
		$valid_screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$valid_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! in_array( $screen, $valid_screens, true ) ) {
			return;
		}

		add_meta_box(
			'ck-ows-status-actions',
			esc_html__( 'Workflow Quick Actions', 'ck-order-workflow-suite' ),
			array( $this, 'render_order_metabox' ),
			$screen,
			'side',
			'high'
		);
	}

	public function render_order_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not available.', 'ck-order-workflow-suite' ) . '</p>';
			return;
		}

		if ( ! $this->current_user_can_edit_order( $order ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to change this order status.', 'ck-order-workflow-suite' ) . '</p>';
			return;
		}

		$current_status = $order->get_status();

		echo '<div class="ck-ows-status-actions">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<input type="hidden" name="redirect" value="' . esc_url( $this->get_order_edit_redirect_url( $order ) ) . '">';
		wp_nonce_field( self::STATUS_ACTION_NONCE, self::STATUS_ACTION_NONCE_FIELD, false );
		echo '<p class="ck-ows-status-actions__current"><strong>' . esc_html__( 'Current status:', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $this->format_status_label( $current_status ) ) . '</p>';
		echo '<div class="ck-ows-status-actions__buttons">';

		foreach ( $this->get_quick_status_actions() as $status => $action ) {
			if ( $status === $current_status ) {
				echo '<button type="button" class="button ck-ows-status-actions__button is-current" disabled>' . esc_html( $action['label'] ) . '</button>';
				continue;
			}

			if ( ! $this->can_show_status_action( $order, $status ) ) {
				echo '<button type="button" class="button ck-ows-status-actions__button" disabled title="' . esc_attr( $this->get_status_blocked_message( $status ) ) . '">' . esc_html( $action['label'] ) . '</button>';
				continue;
			}

			echo '<button type="submit" name="action" value="' . esc_attr( $action['admin_action'] ) . '" formmethod="post" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="button button-secondary ck-ows-status-actions__button" formnovalidate>' . esc_html( $action['label'] ) . '</button>';
		}

		echo '</div>';
		echo '<p class="ck-ows-status-actions__hint">' . esc_html__( 'Artwork approval rules still apply before production.', 'ck-order-workflow-suite' ) . '</p>';
		echo '</div>';
	}

	public function handle_row_action(): void {
		$order_id = absint( $this->get_request_value( 'order_id' ) );
		$action   = sanitize_key( $this->get_request_value( 'action' ) );
		$status   = $this->action_to_status( $action );
		$nonce    = sanitize_text_field( $this->get_request_value( self::STATUS_ACTION_NONCE_FIELD ) );

		if ( ! wp_verify_nonce( $nonce, self::STATUS_ACTION_NONCE ) ) {
			$this->redirect_with_flag( 'ck_ows_invalid_status_nonce', 1 );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->redirect_with_flag( 'ck_ows_order_not_found', 1 );
		}

		if ( ! ( current_user_can( 'edit_shop_order', $order->get_id() ) || current_user_can( 'edit_shop_orders' ) ) ) {
			$this->redirect_with_flag( 'ck_ows_status_permission_denied', 1 );
		}

		if ( ! array_key_exists( $status, $this->get_quick_status_actions() ) ) {
			$this->redirect_with_flag( 'ck_ows_invalid_status_action', 1 );
		}

		if ( 'awaiting-artwork' === $status && ! $this->order_has_artwork_proof( $order ) ) {
			$this->redirect_with_flag( 'ck_ows_missing_artwork', 1 );
		}

		if ( 'in-production' === $status && ! $this->order_can_move_to_production( $order ) ) {
			$this->redirect_with_flag( 'ck_ows_artwork_approval_required', 1 );
		}

		if ( ! $this->safe_update_order_status( $order, $status, __( 'Order status updated from quick action.', 'ck-order-workflow-suite' ), 'quick_status_update' ) ) {
			$this->redirect_with_flag( 'ck_ows_status_update_failed', 1 );
		}

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

			if ( $updated > 0 ) {
				/* translators: 1: updated count, 2: order status label */
				$message = sprintf( esc_html__( '%1$d order(s) updated to %2$s.', 'ck-order-workflow-suite' ), $updated, esc_html( $this->format_status_label( $status ) ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		if ( isset( $_GET['ck_ows_bulk_failed'] ) && isset( $_GET['ck_ows_new_status'] ) ) {
			$failed = absint( wp_unslash( $_GET['ck_ows_bulk_failed'] ) );
			$status = sanitize_text_field( wp_unslash( $_GET['ck_ows_new_status'] ) );

			if ( $failed > 0 ) {
				/* translators: 1: failed count, 2: order status label */
				$message = sprintf( esc_html__( '%1$d order(s) could not be updated to %2$s. Please try again or update from the order edit screen.', 'ck-order-workflow-suite' ), $failed, esc_html( $this->format_status_label( $status ) ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
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

		if ( isset( $_GET['ck_ows_order_not_found'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Order not found, so the status was not changed.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_status_permission_denied'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'You do not have permission to change that order status.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_invalid_status_action'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid quick status action.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_invalid_status_nonce'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The quick status action expired. Please refresh the orders page and try again.', 'ck-order-workflow-suite' ) . '</p></div>';
		}

		if ( isset( $_GET['ck_ows_status_update_failed'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The order status could not be changed. Please try again or update the status from the order edit screen.', 'ck-order-workflow-suite' ) . '</p></div>';
		}
	}

	private function get_quick_status_actions(): array {
		return array(
			'awaiting-artwork' => array(
				'label'        => __( 'Awaiting Artwork Approval', 'ck-order-workflow-suite' ),
				'action'       => 'ck-ows-awaiting-artwork',
				'admin_action' => self::ACTION_SET_AWAITING_ARTWORK,
			),
			'in-production'    => array(
				'label'        => __( 'In Production', 'ck-order-workflow-suite' ),
				'action'       => 'ck-ows-in-production',
				'admin_action' => self::ACTION_SET_IN_PRODUCTION,
			),
			'in-dispatch'      => array(
				'label'        => __( 'In Dispatch', 'ck-order-workflow-suite' ),
				'action'       => 'ck-ows-in-dispatch',
				'admin_action' => self::ACTION_SET_IN_DISPATCH,
			),
		);
	}

	private function can_show_status_action( WC_Order $order, string $status ): bool {
		if ( ! $this->is_forward_status_transition( $order->get_status(), $status ) ) {
			return false;
		}

		if ( 'awaiting-artwork' === $status ) {
			return $this->order_has_artwork_proof( $order );
		}

		if ( 'in-production' === $status ) {
			return $this->order_can_move_to_production( $order );
		}

		return array_key_exists( $status, $this->get_quick_status_actions() );
	}

	private function is_forward_status_transition( string $current_status, string $target_status ): bool {
		$workflow_order = array(
			'processing'       => 10,
			'awaiting-artwork' => 20,
			'in-production'    => 30,
			'in-dispatch'      => 40,
			'completed'        => 50,
		);

		if ( ! isset( $workflow_order[ $target_status ] ) ) {
			return false;
		}

		if ( ! isset( $workflow_order[ $current_status ] ) ) {
			return true;
		}

		return $workflow_order[ $target_status ] > $workflow_order[ $current_status ];
	}

	private function get_status_blocked_message( string $status ): string {
		if ( 'awaiting-artwork' === $status ) {
			return __( 'Upload a proof PDF before moving to Awaiting Artwork Approval.', 'ck-order-workflow-suite' );
		}

		if ( 'in-production' === $status ) {
			return __( 'Artwork must be approved or overridden before moving to In Production.', 'ck-order-workflow-suite' );
		}

		return __( 'This quick status action is not currently available.', 'ck-order-workflow-suite' );
	}

	private function redirect_with_flag( string $flag, int $value ): void {
		wp_safe_redirect( add_query_arg( $flag, $value, $this->get_redirect_url() ) );
		exit;
	}

	private function get_request_value( string $key ): string {
		if ( isset( $_POST[ $key ] ) ) {
			$value = wp_unslash( $_POST[ $key ] );

			return is_scalar( $value ) ? (string) $value : '';
		}

		if ( isset( $_GET[ $key ] ) ) {
			$value = wp_unslash( $_GET[ $key ] );

			return is_scalar( $value ) ? (string) $value : '';
		}

		return '';
	}

	private function current_user_can_edit_order( WC_Order $order ): bool {
		return current_user_can( 'edit_shop_order', $order->get_id() ) || current_user_can( 'edit_shop_orders' );
	}

	private function safe_update_order_status( WC_Order $order, string $status, string $note, string $audit_action ): bool {
		try {
			$order->update_status( $status, $note, true );
			CK_OWS_Audit::log_order_event( $order, $audit_action, array( 'status' => $status ) );

			return true;
		} catch ( Throwable $exception ) {
			$this->log_status_update_exception( $order, $status, $exception );

			return false;
		}
	}

	private function log_status_update_exception( WC_Order $order, string $status, Throwable $exception ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf(
					'Failed to update order #%1$d to %2$s: %3$s',
					$order->get_id(),
					$status,
					$exception->getMessage()
				),
				array(
					'source'    => 'ck-order-workflow-suite',
					'exception' => $exception,
				)
			);
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
		$actions = $this->get_quick_status_actions();

		if ( ! isset( $actions[ $status ] ) ) {
			return admin_url( 'edit.php?post_type=shop_order' );
		}

		$url = add_query_arg(
			array(
				'action'   => $actions[ $status ]['admin_action'],
				'order_id' => $order_id,
				'redirect' => $this->get_current_order_list_url(),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::STATUS_ACTION_NONCE, self::STATUS_ACTION_NONCE_FIELD );
	}

	private function get_redirect_url(): string {
		$fallback = admin_url( 'edit.php?post_type=shop_order' );

		if ( isset( $_POST['redirect'] ) || isset( $_GET['redirect'] ) ) {
			$redirect = esc_url_raw( $this->get_request_value( 'redirect' ) );

			return $this->sanitize_redirect_url( $redirect, $fallback );
		}

		$referer = wp_get_referer();

		if ( $referer ) {
			return $this->sanitize_redirect_url( $referer, $fallback );
		}

		return $fallback;
	}

	private function get_order_edit_redirect_url( WC_Order $order ): string {
		$url = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '';

		if ( ! is_string( $url ) || '' === $url ) {
			$url = get_edit_post_link( $order->get_id(), 'raw' );
		}

		return $this->sanitize_redirect_url( is_string( $url ) ? $url : '', admin_url( 'edit.php?post_type=shop_order' ) );
	}

	private function get_current_admin_url(): string {
		$fallback    = admin_url( 'edit.php?post_type=shop_order' );
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $request_uri ) {
			return $this->get_redirect_url();
		}

		$admin_parts = wp_parse_url( admin_url() );

		if ( ! is_array( $admin_parts ) || empty( $admin_parts['scheme'] ) || empty( $admin_parts['host'] ) ) {
			return $this->get_redirect_url();
		}

		$origin = $admin_parts['scheme'] . '://' . $admin_parts['host'];
		if ( ! empty( $admin_parts['port'] ) ) {
			$origin .= ':' . absint( $admin_parts['port'] );
		}

		return $this->sanitize_redirect_url( $origin . $request_uri, $fallback );
	}

	private function get_current_order_list_url(): string {
		$current = $this->get_current_admin_url();
		$current = remove_query_arg(
			array(
				'action',
				'action2',
				'order_id',
				self::STATUS_ACTION_NONCE_FIELD,
				'ck_ows_status_updated',
				'ck_ows_new_status',
				'ck_ows_status_update_failed',
				'ck_ows_invalid_status_nonce',
				'ck_ows_invalid_status_action',
				'ck_ows_order_not_found',
				'ck_ows_status_permission_denied',
				'ck_ows_missing_artwork',
				'ck_ows_artwork_approval_required',
			),
			$current
		);

		foreach ( array( 'status', 'post_status' ) as $query_key ) {
			if ( isset( $_GET[ $query_key ] ) ) {
				$query_value = sanitize_text_field( wp_unslash( $_GET[ $query_key ] ) );

				if ( '' !== $query_value ) {
					$current = add_query_arg( $query_key, $query_value, $current );
				}
			}
		}

		return $current;
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
		$status = ( 0 === strpos( $status, 'wc-' ) ) ? $status : 'wc-' . $status;
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
