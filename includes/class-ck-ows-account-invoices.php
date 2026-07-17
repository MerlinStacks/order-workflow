<?php
/**
 * Account invoices endpoint module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Account_Invoices extends CK_OWS_Base {
	private static bool $address_notice_rendered = false;

	protected function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_invoice_request' ) );
		add_action( 'woocommerce_account_invoices_endpoint', array( $this, 'render_endpoint' ) );
		add_action( 'woocommerce_before_edit_account_address_form', array( $this, 'render_address_notice' ) );
	}

	public function render_address_notice(): void {
		if ( self::$address_notice_rendered ) {
			return;
		}

		self::$address_notice_rendered = true;

		echo '<div style="background:#fff8e1;border:1px solid #fec610;border-radius:10px;padding:0.85rem 1.1rem;margin-bottom:1.5rem;font-size:0.85rem;color:#666;line-height:1.6;font-family:-apple-system,sans-serif">';
		echo '<strong style="color:#141414">' . esc_html__( 'Heads up:', 'ck-order-workflow-suite' ) . '</strong> ';
		echo esc_html__( 'Changing your address here updates it for future orders only. It will not change the delivery address on any orders already placed.', 'ck-order-workflow-suite' );
		echo '</div>';
	}

	public function render_endpoint( $current_page = 1 ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$current_page = max( 1, absint( $current_page ) );
		$limit        = max( 1, (int) apply_filters( 'ck_ows_account_invoices_limit', 20 ) );
		$results      = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'limit'       => $limit,
				'page'        => $current_page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array( 'wc-processing', 'wc-completed', CK_OWS_Statuses::STATUS_IN_PRODUCTION, CK_OWS_Statuses::STATUS_IN_DISPATCH ),
			)
		);
		$orders        = is_object( $results ) && isset( $results->orders ) ? $results->orders : array();
		$max_num_pages = is_object( $results ) && isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 0;

		echo '<div class="ck-invoices">';
		echo '<h3 class="ck-invoices__title">' . esc_html__( 'Your Invoices', 'ck-order-workflow-suite' ) . '</h3>';

		if ( empty( $orders ) ) {
			echo '<div class="ck-invoices__empty">' . esc_html__( 'No invoices available yet.', 'ck-order-workflow-suite' ) . '</div>';
			$this->render_pagination( $current_page, $max_num_pages );
			echo '</div>';
			return;
		}

		echo '<div class="ck-invoices__list">';

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_number = $order->get_order_number();
			$order_date   = wc_format_datetime( $order->get_date_created() );
			$order_total  = $order->get_formatted_order_total();

			echo '<div class="ck-invoices__row">';
			echo '<div class="ck-invoices__info">';
			echo '<strong>#' . esc_html( (string) $order_number ) . '</strong>';
			echo '<span>' . esc_html( $order_date ) . ' · ' . wp_kses_post( $order_total ) . '</span>';
			echo '</div>';

			$pdf_url = $this->get_invoice_proxy_url( $order );

			if ( '' !== $pdf_url ) {
				echo '<a href="' . esc_url( $pdf_url ) . '" class="ck-invoices__dl" target="_blank" rel="noopener">';
				echo '<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
				echo esc_html__( 'Download PDF', 'ck-order-workflow-suite' );
				echo '</a>';
			} elseif ( CK_OWS_Invoice_Integration::PROVIDER_NEW === CK_OWS_Invoice_Integration::get_provider() ) {
				$invoice_status = CK_OWS_Invoice_Integration::get_invoice_status( $order );
				$label          = 'failed' === $invoice_status ? esc_html__( 'Invoice Unavailable', 'ck-order-workflow-suite' ) : esc_html__( 'Invoice Pending', 'ck-order-workflow-suite' );
				echo '<span class="ck-invoices__state">' . esc_html( $label ) . '</span>';
			} else {
				echo '<a href="' . esc_url( $order->get_view_order_url() ) . '" class="ck-invoices__dl">' . esc_html__( 'View Order', 'ck-order-workflow-suite' ) . '</a>';
			}

			echo '</div>';
		}

		echo '</div>';
		$this->render_pagination( $current_page, $max_num_pages );
		echo '</div>';
	}

	private function render_pagination( int $current_page, int $max_num_pages ): void {
		if ( $max_num_pages <= 1 ) {
			return;
		}

		echo '<nav class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination" aria-label="' . esc_attr__( 'Invoices pagination', 'ck-order-workflow-suite' ) . '">';
		if ( $current_page > 1 ) {
			echo '<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button" href="' . esc_url( wc_get_endpoint_url( 'invoices', $current_page - 1 ) ) . '">' . esc_html__( 'Previous', 'woocommerce' ) . '</a>';
		}
		if ( $current_page < $max_num_pages ) {
			echo '<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button" href="' . esc_url( wc_get_endpoint_url( 'invoices', $current_page + 1 ) ) . '">' . esc_html__( 'Next', 'woocommerce' ) . '</a>';
		}
		echo '</nav>';
	}

	public function maybe_redirect_invoice_request(): void {
		if ( ! is_user_logged_in() || ! isset( $_GET['ck_ows_invoice_order'] ) ) {
			return;
		}

		$order_id = absint( wp_unslash( $_GET['ck_ows_invoice_order'] ) );
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		$invoice_url = $this->get_invoice_url( $order );
		if ( '' === $invoice_url ) {
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		wp_safe_redirect( $invoice_url );
		exit;
	}

	private function get_invoice_url( WC_Order $order ): string {
		return CK_OWS_Invoice_Integration::get_invoice_download_url( $order );
	}

	private function get_invoice_proxy_url( WC_Order $order ): string {
		$invoice_url = $this->get_invoice_url( $order );

		if ( '' === $invoice_url ) {
			return '';
		}

		return (string) add_query_arg(
			array(
				'ck_ows_invoice_order' => $order->get_id(),
			),
			wc_get_page_permalink( 'myaccount' )
		);
	}
}
