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
		$can_view_without_key = is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id();

		if ( ( '' === $order_key || ! $order->key_is_valid( $order_key ) ) && ! $can_view_without_key ) {
			return '<p class="woocommerce-error">' . esc_html__( 'Invalid order key', 'ck-order-workflow-suite' ) . '</p>';
		}

		$status = (string) $order->get_status();
		$eta_html = '';

		if ( class_exists( 'CK_OWS_Tracking' ) ) {
			$tracking_payload = CK_OWS_Tracking::instance()->get_tracking_payload_for_order( $order );
			$eta             = trim( (string) ( $tracking_payload['eta'] ?? '' ) );

			if ( '' !== $eta ) {
				$eta_html = $this->get_estimated_delivery_markup( $eta );
			}
		}

		if ( 'processing' === $status ) {
			return '<p class="woocommerce-info">' . esc_html__( 'Thanks for your order. We have received it and will start preparing it shortly.', 'ck-order-workflow-suite' ) . '</p>' . $eta_html;
		}

		if ( 'in-production' === $status ) {
			return '<p class="woocommerce-info">' . esc_html__( 'Your order is now in production. Keep an eye on it, it will be dispatched soon.', 'ck-order-workflow-suite' ) . '</p>' . $eta_html;
		}

		if ( 'in-dispatch' === $status ) {
			return '<p class="woocommerce-info">' . esc_html__( 'We are completing final quality checks and dispatching your order now.', 'ck-order-workflow-suite' ) . '</p>' . $eta_html;
		}

		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items', true );
		if ( ! is_array( $tracking_items ) ) {
			$tracking_items = array();
		}

		if ( 'completed' === $status ) {
			$timeline_markup = CK_OWS_Order_Timeline::instance()->get_timeline_markup( $order, true );

			if ( '' === $timeline_markup ) {
				return '<p class="woocommerce-info">' . esc_html__( 'Your order has been completed. Tracking information will appear shortly.', 'ck-order-workflow-suite' ) . '</p>';
			}

			$tracking_url = '';
			foreach ( $tracking_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( ! empty( $item['formatted_tracking_link'] ) ) {
					$tracking_url = (string) $item['formatted_tracking_link'];
					break;
				}

				if ( ! empty( $item['custom_tracking_link'] ) ) {
					$tracking_url = (string) $item['custom_tracking_link'];
					break;
				}
			}

			if ( '' === $tracking_url && ! empty( $tracking_items ) ) {
				$first_item = reset( $tracking_items );
				$provider   = strtolower( trim( (string) ( $first_item['custom_tracking_provider'] ?? $first_item['tracking_provider'] ?? '' ) ) );
				$number     = trim( (string) ( $first_item['tracking_number'] ?? '' ) );

				if ( '' !== $number && ( false !== strpos( $provider, 'auspost' ) || false !== strpos( $provider, 'australia post' ) ) ) {
					$tracking_url = 'https://auspost.com.au/mypost/track/#/details/' . rawurlencode( $number );
				}
			}

			ob_start();
			echo $eta_html;
			echo $timeline_markup;

			if ( '' !== $tracking_url ) {
				echo '<p><a class="button ck-ows-track-auspost" target="_blank" rel="noopener" href="' . esc_url( $tracking_url ) . '"><span class="ck-ows-track-auspost__logo" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" role="presentation"><path d="M10 2A10 10 0 1 0 10 22V2Z"/><path d="M12 2h.5A10 10 0 0 1 12.5 22H12v-5h.5A5 5 0 0 0 12.5 7H12V2Z"/></svg></span>' . esc_html__( 'Track on AusPost', 'ck-order-workflow-suite' ) . '</a></p>';
			}

			return (string) ob_get_clean();
		}

		if ( empty( $tracking_items ) ) {
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

	private function get_estimated_delivery_markup( string $eta ): string {
		return '<p class="ck-ows-order-eta"><strong>' . esc_html__( 'Order estimated delivery date', 'ck-order-workflow-suite' ) . '</strong> ' . esc_html( $eta ) . '</p>';
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

		if ( ! $order_id ) {
			return '';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$can_view_without_key = is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id();

		if ( ( '' === $order_key || ! $order->key_is_valid( $order_key ) ) && ! $can_view_without_key ) {
			return '';
		}

		$pdf_url = CK_OWS_Invoice_Integration::get_invoice_download_url( $order );

		if ( '' === $pdf_url && CK_OWS_Invoice_Integration::PROVIDER_NEW !== CK_OWS_Invoice_Integration::get_provider() ) {
			$nonce = wp_create_nonce( 'wpo_wcpdf' );

			$pdf_url = add_query_arg(
				array(
					'action'        => 'generate_wpo_wcpdf',
					'document_type' => 'invoice',
					'order_ids'     => $order->get_id(),
					'order_key'     => $order->get_order_key(),
					'nonce'         => $nonce,
				),
				admin_url( 'admin-ajax.php' )
			);
		}

		if ( '' === $pdf_url ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s" class="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_url( $pdf_url ),
			esc_attr( trim( 'button ' . (string) $atts['class'] ) ),
			esc_html( (string) $atts['link_text'] )
		);
	}
}
