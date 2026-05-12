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

		$this->render_inline_styles();

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
		if ( ! class_exists( 'WPO_WCPDF' ) ) {
			return '';
		}

		return (string) add_query_arg(
			array(
				'action'        => 'generate_wpo_wcpdf',
				'document_type' => 'invoice',
				'order_ids'     => $order->get_id(),
				'order_key'     => $order->get_order_key(),
				'nonce'         => wp_create_nonce( 'wpo_wcpdf' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	private function get_tracking_url( WC_Order $order ): string {
		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( ! is_array( $tracking_items ) || empty( $tracking_items ) ) {
			return '';
		}

		$item = reset( $tracking_items );

		if ( ! empty( $item['formatted_tracking_link'] ) ) {
			return (string) $item['formatted_tracking_link'];
		}

		if ( ! empty( $item['custom_tracking_link'] ) ) {
			return (string) $item['custom_tracking_link'];
		}

		return '';
	}

	private function render_inline_styles(): void {
		echo '<style>';
		echo '.ck-ows-orders-cards{display:flex;flex-direction:column;gap:14px}';
		echo '.ck-ows-orders-cards__title{margin:0 0 6px;font-size:1.2rem}';
		echo '.ck-ows-orders-cards__empty{padding:18px;border:1px solid #e5e5e5;border-radius:10px;color:#666}';
		echo '.ck-ows-order-card{border:1px solid #ececec;border-radius:12px;padding:14px;background:#fff}';
		echo '.ck-ows-order-card__header{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px}';
		echo '.ck-ows-order-card__date{font-size:.85rem;color:#666}';
		echo '.ck-ows-order-card__status{padding:4px 10px;border-radius:999px;background:#f3f3f3;font-size:.8rem;font-weight:600}';
		echo '.ck-ows-order-card__items{display:flex;flex-direction:column;gap:8px}';
		echo '.ck-ows-order-card__item{display:flex;gap:10px;align-items:center}';
		echo '.ck-ows-order-card__thumb img{width:48px;height:48px;object-fit:cover;border-radius:8px}';
		echo '.ck-ows-order-card__item-meta{display:flex;flex-direction:column}';
		echo '.ck-ows-order-card__item-meta small{color:#777}';
		echo '.ck-ows-order-card__more{font-size:.85rem;color:#666}';
		echo '.ck-ows-order-card__footer{margin-top:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}';
		echo '.ck-ows-order-card__total{font-weight:700}';
		echo '.ck-ows-order-card__actions{display:flex;gap:8px;flex-wrap:wrap}';
		echo '@media(max-width:640px){.ck-ows-order-card__header{flex-direction:column}.ck-ows-order-card__footer{flex-direction:column;align-items:flex-start}}';
		echo '</style>';
	}
}
