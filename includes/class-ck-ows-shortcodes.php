<?php
/**
 * Shortcodes module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Shortcodes {
	private static ?CK_OWS_Shortcodes $instance = null;

	public static function instance(): CK_OWS_Shortcodes {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'order_tracking_summary', array( $this, 'order_tracking_summary' ) );
		add_shortcode( 'wc_invoice_link', array( $this, 'invoice_link' ) );
	}

	public function order_tracking_summary(): string {
		$order_id = absint( get_query_var( 'order-received' ) );

		if ( ! $order_id && isset( $_GET['thankyou_order_id'] ) ) {
			$order_id = absint( wp_unslash( $_GET['thankyou_order_id'] ) );
		}

		if ( ! $order_id ) {
			return '<p class="woocommerce-error">' . esc_html__( 'Order not found', 'ck-order-workflow-suite' ) . '</p>';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return '<p class="woocommerce-error">' . esc_html__( 'Invalid order', 'ck-order-workflow-suite' ) . '</p>';
		}

		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( ! $order_key || ! $order->key_is_valid( $order_key ) ) {
			return '<p class="woocommerce-error">' . esc_html__( 'Invalid order key', 'ck-order-workflow-suite' ) . '</p>';
		}

		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( empty( $tracking_items ) || ! is_array( $tracking_items ) ) {
			return '<p class="woocommerce-info">' . esc_html__( 'Your order has been received. Tracking information will be added once your order has been shipped.', 'ck-order-workflow-suite' ) . '</p>';
		}

		ob_start();
		echo '<div class="woocommerce-shipment-tracking-container">';
		echo '<h2>' . esc_html__( 'Track Your Shipment', 'ck-order-workflow-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tracking details for your order are below. It may take up to 24 hours for the carrier to update the information.', 'ck-order-workflow-suite' ) . '</p>';

		foreach ( $tracking_items as $item ) {
			$provider = isset( $item['custom_tracking_provider'] ) && '' !== $item['custom_tracking_provider']
				? (string) $item['custom_tracking_provider']
				: ucwords( str_replace( '_', ' ', (string) ( $item['tracking_provider'] ?? '' ) ) );
			$number   = (string) ( $item['tracking_number'] ?? '' );
			$shipped  = ! empty( $item['date_shipped'] ) ? wp_date( get_option( 'date_format' ), (int) $item['date_shipped'] ) : __( 'N/A', 'ck-order-workflow-suite' );

			echo '<div class="tracking-item">';
			echo '<p>';
			echo esc_html(
				sprintf(
					/* translators: 1: ship date, 2: carrier, 3: tracking number */
					__( 'Shipped on %1$s via %2$s with tracking number: %3$s', 'ck-order-workflow-suite' ),
					$shipped,
					$provider ?: __( 'Carrier', 'ck-order-workflow-suite' ),
					$number ?: __( 'N/A', 'ck-order-workflow-suite' )
				)
			);
			echo '</p>';

			$tracking_url = '';
			if ( ! empty( $item['formatted_tracking_link'] ) ) {
				$tracking_url = (string) $item['formatted_tracking_link'];
			} elseif ( ! empty( $item['custom_tracking_link'] ) ) {
				$tracking_url = (string) $item['custom_tracking_link'];
			}

			if ( '' !== $tracking_url ) {
				echo '<a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer" class="button track-button">' . esc_html__( 'Track Shipment', 'ck-order-workflow-suite' ) . '</a>';
			}

			echo '</div>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	public function invoice_link( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'link_text' => __( 'Download PDF Invoice', 'ck-order-workflow-suite' ),
				'class'     => '',
			),
			$atts,
			'wc_invoice_link'
		);

		$order_id  = absint( get_query_var( 'order-received' ) );
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( ! $order_id || '' === $order_key ) {
			return '';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! $order->key_is_valid( $order_key ) ) {
			return '';
		}

		$pdf_url = add_query_arg(
			array(
				'action'        => 'generate_wpo_wcpdf',
				'document_type' => 'invoice',
				'order_ids'     => $order->get_id(),
				'order_key'     => $order->get_order_key(),
				'nonce'         => wp_create_nonce( 'wpo_wcpdf' ),
			),
			admin_url( 'admin-ajax.php' )
		);

		return sprintf(
			'<a href="%1$s" class="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_url( $pdf_url ),
			esc_attr( trim( 'button ' . (string) $atts['class'] ) ),
			esc_html( (string) $atts['link_text'] )
		);
	}
}
