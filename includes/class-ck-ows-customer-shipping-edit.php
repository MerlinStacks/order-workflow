<?php
/**
 * Customer shipping edit module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Customer_Shipping_Edit {
	private static ?CK_OWS_Customer_Shipping_Edit $instance = null;

	public static function instance(): CK_OWS_Customer_Shipping_Edit {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_form' ), 20 );
		add_action( 'admin_post_ck_ows_update_shipping_address', array( $this, 'handle_update' ) );
	}

	public function render_form( WC_Order $order ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( (int) $order->get_user_id() !== $user_id ) {
			return;
		}

		if ( 'processing' !== $order->get_status() ) {
			return;
		}

		$action_url = admin_url( 'admin-post.php' );
		$fallback_notice = $this->get_fallback_notice();

		echo '<section class="ck-ows-shipping-edit">';

		if ( null !== $fallback_notice ) {
			echo '<div class="woocommerce-notices-wrapper">';
			echo '<ul class="woocommerce-' . esc_attr( $fallback_notice['type'] ) . '" role="alert">';
			echo '<li>' . esc_html( $fallback_notice['message'] ) . '</li>';
			echo '</ul>';
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'Update Shipping Address', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'You can update your delivery details while this order is still processing.', 'ck-order-workflow-suite' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		echo '<input type="hidden" name="action" value="ck_ows_update_shipping_address">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<input type="hidden" name="order_version" value="' . esc_attr( (string) $this->get_order_version( $order ) ) . '">';
		wp_nonce_field( 'ck_ows_update_shipping_' . $order->get_id() );

		$this->render_input( 'shipping_first_name', __( 'First name', 'ck-order-workflow-suite' ), (string) $order->get_shipping_first_name(), true );
		$this->render_input( 'shipping_last_name', __( 'Last name', 'ck-order-workflow-suite' ), (string) $order->get_shipping_last_name(), true );
		$this->render_input( 'shipping_company', __( 'Company', 'ck-order-workflow-suite' ), (string) $order->get_shipping_company(), false );
		$this->render_input( 'shipping_address_1', __( 'Address line 1', 'ck-order-workflow-suite' ), (string) $order->get_shipping_address_1(), true );
		$this->render_input( 'shipping_address_2', __( 'Address line 2', 'ck-order-workflow-suite' ), (string) $order->get_shipping_address_2(), false );
		$this->render_input( 'shipping_city', __( 'Suburb/City', 'ck-order-workflow-suite' ), (string) $order->get_shipping_city(), true );
		$this->render_input( 'shipping_state', __( 'State', 'ck-order-workflow-suite' ), (string) $order->get_shipping_state(), false );
		$this->render_input( 'shipping_postcode', __( 'Postcode', 'ck-order-workflow-suite' ), (string) $order->get_shipping_postcode(), true );
		$this->render_input( 'shipping_country', __( 'Country code', 'ck-order-workflow-suite' ), (string) $order->get_shipping_country(), true );

		echo '<p><button type="submit" class="button">' . esc_html__( 'Save shipping address', 'ck-order-workflow-suite' ) . '</button></p>';
		echo '</form>';
		echo '</section>';
	}

	public function handle_update(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to do that.', 'ck-order-workflow-suite' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		check_admin_referer( 'ck_ows_update_shipping_' . $order_id );

		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			wp_die( esc_html__( 'You do not have permission to edit this order.', 'ck-order-workflow-suite' ) );
		}

		if ( 'processing' !== $order->get_status() ) {
			$this->redirect_with_notice( $order, __( 'Shipping address can only be updated while the order is processing.', 'ck-order-workflow-suite' ), 'error' );
		}

		$expected_version = isset( $_POST['order_version'] ) ? absint( wp_unslash( $_POST['order_version'] ) ) : 0;
		$current_version  = $this->get_order_version( $order );

		if ( $expected_version <= 0 || $expected_version !== $current_version ) {
			$this->redirect_with_notice( $order, __( 'This order was updated before your address change was saved. Please refresh and try again.', 'ck-order-workflow-suite' ), 'error' );
		}

		$address = array(
			'first_name' => $this->clean_post( 'shipping_first_name' ),
			'last_name'  => $this->clean_post( 'shipping_last_name' ),
			'company'    => $this->clean_post( 'shipping_company' ),
			'address_1'  => $this->clean_post( 'shipping_address_1' ),
			'address_2'  => $this->clean_post( 'shipping_address_2' ),
			'city'       => $this->clean_post( 'shipping_city' ),
			'state'      => $this->clean_post( 'shipping_state' ),
			'postcode'   => $this->clean_post( 'shipping_postcode' ),
			'country'    => strtoupper( $this->clean_post( 'shipping_country' ) ),
		);

		if ( '' === $address['first_name'] || '' === $address['last_name'] || '' === $address['address_1'] || '' === $address['city'] || '' === $address['postcode'] || '' === $address['country'] ) {
			$this->redirect_with_notice( $order, __( 'Please complete all required shipping fields.', 'ck-order-workflow-suite' ), 'error' );
		}

		$latest_order = wc_get_order( $order_id );

		if ( ! $latest_order ) {
			wp_die( esc_html__( 'Order not found.', 'ck-order-workflow-suite' ) );
		}

		if ( (int) $latest_order->get_user_id() !== get_current_user_id() ) {
			wp_die( esc_html__( 'You do not have permission to edit this order.', 'ck-order-workflow-suite' ) );
		}

		if ( 'processing' !== $latest_order->get_status() || $this->get_order_version( $latest_order ) !== $expected_version ) {
			$this->redirect_with_notice( $latest_order, __( 'This order was updated before your address change was saved. Please refresh and try again.', 'ck-order-workflow-suite' ), 'error' );
		}

		$latest_order->set_address( $address, 'shipping' );
		$latest_order->add_order_note( __( 'Customer updated shipping address from My Account.', 'ck-order-workflow-suite' ) );
		$latest_order->save();

		$this->redirect_with_notice( $latest_order, __( 'Shipping address updated successfully.', 'ck-order-workflow-suite' ), 'success' );
	}

	private function get_order_version( WC_Order $order ): int {
		$modified = $order->get_date_modified();

		if ( $modified ) {
			return $modified->getTimestamp();
		}

		$created = $order->get_date_created();

		if ( $created ) {
			return $created->getTimestamp();
		}

		return 0;
	}

	private function redirect_with_notice( WC_Order $order, string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		$notice_url = add_query_arg(
			array(
				'ck_ows_notice'      => $message,
				'ck_ows_notice_type' => $type,
			),
			$order->get_view_order_url()
		);

		wp_safe_redirect( $notice_url );
		exit;
	}

	private function get_fallback_notice(): ?array {
		if ( ! isset( $_GET['ck_ows_notice'] ) || ! isset( $_GET['ck_ows_notice_type'] ) ) {
			return null;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['ck_ows_notice'] ) );
		$type    = sanitize_key( wp_unslash( $_GET['ck_ows_notice_type'] ) );

		if ( '' === $message ) {
			return null;
		}

		if ( ! in_array( $type, array( 'error', 'success', 'notice' ), true ) ) {
			$type = 'notice';
		}

		return array(
			'message' => $message,
			'type'    => $type,
		);
	}

	private function clean_post( string $key ): string {
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return sanitize_text_field( (string) $value );
	}

	private function render_input( string $name, string $label, string $value, bool $required ): void {
		echo '<p class="form-row form-row-wide">';
		echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label );

		if ( $required ) {
			echo ' <span class="required">*</span>';
		}

		echo '</label>';
		echo '<input type="text" class="input-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</p>';
	}
}
