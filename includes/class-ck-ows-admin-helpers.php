<?php
/**
 * Shared admin helpers.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Admin_Helpers {
	public static function get_order_screen_ids( string $context = 'list' ): array {
		$ids = 'edit' === $context ? array( 'shop_order' ) : array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( $ids ) );
	}

	public static function is_order_screen( string $context = 'list' ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && isset( $screen->id ) && in_array( $screen->id, self::get_order_screen_ids( $context ), true );
	}

	public static function maybe_enqueue_admin_ui( string $context = 'list' ): bool {
		if ( ! self::is_order_screen( $context ) ) {
			return false;
		}

		wp_enqueue_style( 'ck-ows-admin-ui', CK_OWS_URL . 'assets/css/admin-ui.css', array(), CK_OWS_VERSION );

		return true;
	}

	public static function get_fallback_notice_from_get( string $prefix = 'ck_ows' ): ?array {
		$message_key = $prefix . '_notice';
		$type_key    = $prefix . '_notice_type';

		if ( ! isset( $_GET[ $message_key ] ) || ! isset( $_GET[ $type_key ] ) ) {
			return null;
		}

		$message = sanitize_text_field( wp_unslash( $_GET[ $message_key ] ) );
		$type    = sanitize_key( wp_unslash( $_GET[ $type_key ] ) );

		if ( '' === $message ) {
			return null;
		}

		if ( ! in_array( $type, array( 'error', 'success', 'notice', 'info' ), true ) ) {
			$type = 'notice';
		}

		return array(
			'message' => $message,
			'type'    => $type,
		);
	}

	public static function redirect_with_notice( string $url, string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
			wp_safe_redirect( $url );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'ck_ows_notice'      => $message,
					'ck_ows_notice_type' => $type,
				),
				$url
			)
		);
		exit;
	}

	public static function validate_admin_redirect_url( string $redirect, string $fallback ): string {
		$validated = wp_validate_redirect( $redirect, '' );

		if ( '' === $validated ) {
			return $fallback;
		}

		$admin_parts    = wp_parse_url( admin_url() );
		$validated_parts = wp_parse_url( $validated );

		if ( ! is_array( $admin_parts ) || ! is_array( $validated_parts ) ) {
			return $fallback;
		}

		$admin_scheme = isset( $admin_parts['scheme'] ) ? strtolower( (string) $admin_parts['scheme'] ) : '';
		$admin_host   = isset( $admin_parts['host'] ) ? strtolower( (string) $admin_parts['host'] ) : '';
		$admin_port   = isset( $admin_parts['port'] ) ? absint( $admin_parts['port'] ) : 0;
		$admin_path   = isset( $admin_parts['path'] ) ? (string) $admin_parts['path'] : '/wp-admin/';

		$val_scheme = isset( $validated_parts['scheme'] ) ? strtolower( (string) $validated_parts['scheme'] ) : '';
		$val_host   = isset( $validated_parts['host'] ) ? strtolower( (string) $validated_parts['host'] ) : '';
		$val_port   = isset( $validated_parts['port'] ) ? absint( $validated_parts['port'] ) : 0;
		$val_path   = isset( $validated_parts['path'] ) ? (string) $validated_parts['path'] : '';

		if ( '' === $admin_scheme || '' === $admin_host || $val_scheme !== $admin_scheme || $val_host !== $admin_host ) {
			return $fallback;
		}

		if ( $admin_port !== $val_port && ( 0 !== $admin_port || 0 !== $val_port ) ) {
			return $fallback;
		}

		$admin_path = untrailingslashit( $admin_path ) . '/';

		if ( 0 !== strpos( $val_path, $admin_path ) ) {
			return $fallback;
		}

		return $validated;
	}
}
