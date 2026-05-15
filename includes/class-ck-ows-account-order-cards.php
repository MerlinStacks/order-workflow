<?php
/**
 * Account order cards module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Order_Cards {
	private static ?CK_OWS_Account_Order_Cards $instance = null;

	public static function instance(): CK_OWS_Account_Order_Cards {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp', array( $this, 'replace_orders_endpoint_renderer' ) );
	}

	public function replace_orders_endpoint_renderer(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );
		add_action( 'woocommerce_account_orders_endpoint', array( $this, 'render_orders_endpoint' ) );
	}

	public function render_orders_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$customer_id = get_current_user_id();
		$limit       = (int) apply_filters( 'ck_ows_account_orders_card_limit', 20 );

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		echo '<div class="ck-ows-orders-cards">';
		echo '<h2 class="ck-ows-orders-cards__title">' . esc_html__( 'Your Orders', 'ck-order-workflow-suite' ) . '</h2>';

		if ( empty( $orders ) ) {
			echo '<div class="ck-ows-orders-cards__empty">' . esc_html__( 'No orders found yet.', 'ck-order-workflow-suite' ) . '</div>';
			echo '</div>';
			return;
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$this->render_order_card( $order );
		}

		echo '</div>';
	}

	private function render_order_card( WC_Order $order ): void {
		$order_number = $order->get_order_number();
		$order_date   = $order->get_date_created();
		$status_name  = wc_get_order_status_name( $order->get_status() );
		$total        = $order->get_formatted_order_total();

		echo '<article class="ck-ows-order-card">';
		echo '<header class="ck-ows-order-card__header">';
		echo '<div><strong>#' . esc_html( (string) $order_number ) . '</strong>';
		if ( $order_date ) {
			echo '<div class="ck-ows-order-card__date">' . esc_html( wc_format_datetime( $order_date ) ) . '</div>';
		}
		echo '</div>';
		echo '<span class="ck-ows-order-card__status">' . esc_html( $status_name ) . '</span>';
		echo '</header>';

		echo '<div class="ck-ows-order-card__items">';
		$this->render_line_item_preview( $order );
		echo '</div>';

		echo '<footer class="ck-ows-order-card__footer">';
		echo '<div class="ck-ows-order-card__total">' . wp_kses_post( $total ) . '</div>';
		echo '<div class="ck-ows-order-card__actions">';
		echo '<a class="button" href="' . esc_url( $order->get_view_order_url() ) . '">' . esc_html__( 'View Order', 'ck-order-workflow-suite' ) . '</a>';

		$invoice_url = $this->get_invoice_url( $order );
		if ( '' !== $invoice_url ) {
			echo '<a class="button" href="' . esc_url( $invoice_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Invoice', 'ck-order-workflow-suite' ) . '</a>';
		}

		$tracking_url = $this->get_tracking_url( $order );
		if ( '' !== $tracking_url ) {
			echo '<a class="button" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Track', 'ck-order-workflow-suite' ) . '</a>';
		}

		echo '</div>';
		echo '</footer>';
		echo '</article>';
	}

	private function render_line_item_preview( WC_Order $order ): void {
		$items = $order->get_items( 'line_item' );
		$shown = 0;

		foreach ( $items as $item ) {
			if ( $shown >= 2 ) {
				break;
			}

			$product = $item->get_product();
			$image   = '';
			if ( $product ) {
				$image = $product->get_image( array( 48, 48 ) );
			}

			echo '<div class="ck-ows-order-card__item">';
			if ( '' !== $image ) {
				echo '<div class="ck-ows-order-card__thumb">' . wp_kses_post( $image ) . '</div>';
			}
			echo '<div class="ck-ows-order-card__item-meta">';
			echo '<span>' . esc_html( $item->get_name() ) . '</span>';
			/* translators: %d: item quantity. */
			echo '<small>' . esc_html( sprintf( __( 'Qty: %d', 'ck-order-workflow-suite' ), $item->get_quantity() ) ) . '</small>';
			echo '</div>';
			echo '</div>';

			$shown++;
		}

		if ( count( $items ) > 2 ) {
			echo '<div class="ck-ows-order-card__more">' . esc_html__( 'More items in this order', 'ck-order-workflow-suite' ) . '</div>';
		}
	}

	private function get_invoice_url( WC_Order $order ): string {
		$actions = wc_get_account_orders_actions( $order );
		if ( ! isset( $actions['invoice']['url'] ) || '' === (string) $actions['invoice']['url'] ) {
			return '';
		}

		return (string) add_query_arg(
			array(
				'ck_ows_invoice_order' => $order->get_id(),
			),
			wc_get_page_permalink( 'myaccount' )
		);
	}

	private function get_tracking_url( WC_Order $order ): string {
		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( ! is_array( $tracking_items ) || empty( $tracking_items ) ) {
			return '';
		}

		foreach ( $tracking_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! empty( $item['formatted_tracking_link'] ) ) {
				return (string) $item['formatted_tracking_link'];
			}

			if ( ! empty( $item['custom_tracking_link'] ) ) {
				return (string) $item['custom_tracking_link'];
			}
		}

		return '';
	}

}
