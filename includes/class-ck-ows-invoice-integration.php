<?php
/**
 * Invoice integration helper.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Invoice_Integration {
	public const PROVIDER_LEGACY = 'legacy';
	public const PROVIDER_NEW    = 'new_plugin';

	public static function get_invoice_view_url( WC_Order $order ): string {
		if ( self::PROVIDER_NEW === self::get_provider() ) {
			$order_id = $order->get_id();
			$invoice  = self::get_new_invoice_data( $order_id );
			$status   = is_array( $invoice ) && isset( $invoice['status'] ) ? strtolower( (string) $invoice['status'] ) : '';

			if ( 'ready' === $status ) {
				return self::build_rest_download_url( $order_id );
			}

			return '';
		}

		$actions = wc_get_account_orders_actions( $order );

		if ( isset( $actions['invoice']['url'] ) && '' !== (string) $actions['invoice']['url'] ) {
			return html_entity_decode( (string) $actions['invoice']['url'], ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}

	public static function get_invoice_download_url( WC_Order $order ): string {
		if ( self::PROVIDER_NEW === self::get_provider() ) {
			$order_id = $order->get_id();
			$invoice  = self::get_new_invoice_data( $order_id );
			$status   = is_array( $invoice ) && isset( $invoice['status'] ) ? strtolower( (string) $invoice['status'] ) : '';

			if ( 'ready' === $status ) {
				return self::build_rest_download_url( $order_id );
			}

			return '';
		}

		return self::get_invoice_view_url( $order );
	}

	public static function get_provider(): string {
		$use_new_provider = (string) CK_OWS_Settings::get( 'use_new_invoice_plugin', 'no' );

		if ( 'yes' === $use_new_provider ) {
			return self::PROVIDER_NEW;
		}

		return self::PROVIDER_LEGACY;
	}

	private static function get_new_invoice_data( int $order_id ): ?array {
		if ( ! function_exists( 'overseek_get_invoice_for_order' ) ) {
			return null;
		}

		$user_id = is_user_logged_in() ? get_current_user_id() : null;

		try {
			$invoice = overseek_get_invoice_for_order( $order_id, $user_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return null;
		}

		if ( ! is_array( $invoice ) || empty( $invoice ) ) {
			return null;
		}

		return $invoice;
	}

	private static function build_rest_download_url( int $order_id ): string {
		return (string) add_query_arg(
			array(
				'order_id' => $order_id,
			),
			'/wp-json/overseek/v1/invoices/download'
		);
	}
}
