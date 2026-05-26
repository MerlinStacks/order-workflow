<?php
/**
 * Shared utility methods.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Utils {
	public static function string_ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	public static function sanitize_https_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		if ( 'https' !== $scheme ) {
			return '';
		}

		$path      = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query     = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$sanitized = 'https://' . $parts['host'] . $path;

		if ( '' !== $query ) {
			$sanitized .= '?' . $query;
		}

		return esc_url_raw( $sanitized );
	}

	public static function sanitize_https_base_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . $url;
		}

		$url   = untrailingslashit( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';

		if ( 'https' !== $scheme || empty( $parts['host'] ) ) {
			return '';
		}

		$sanitized = 'https://' . $parts['host'];

		if ( isset( $parts['port'] ) && (int) $parts['port'] > 0 ) {
			$sanitized .= ':' . (int) $parts['port'];
		}

		if ( isset( $parts['path'] ) ) {
			$sanitized .= (string) $parts['path'];
		}

		return esc_url_raw( $sanitized );
	}

	public static function is_allowed_host( string $host, array $allowed_hosts ): bool {
		$host = strtolower( trim( $host ) );

		if ( '' === $host ) {
			return false;
		}

		foreach ( $allowed_hosts as $allowed_host ) {
			$allowed_host = strtolower( trim( (string) $allowed_host ) );

			if ( '' === $allowed_host ) {
				continue;
			}

			if ( 0 === strpos( $allowed_host, '*.' ) ) {
				$domain = substr( $allowed_host, 2 );

				if ( '' !== $domain && ( $host === $domain || self::string_ends_with( $host, '.' . $domain ) ) ) {
					return true;
				}

				continue;
			}

			if ( $host === $allowed_host ) {
				return true;
			}
		}

		return false;
	}

	public static function default_overseek_allowed_hosts(): array {
		return array(
			'api.overseek.com',
			'staging-api.overseek.com',
			'*.overseek.com',
			'*.overseek.com.au',
		);
	}

	public static function allowed_hosts_from_filter( string $filter_name ): array {
		$allowed_hosts = apply_filters( $filter_name, self::default_overseek_allowed_hosts() );

		if ( ! is_array( $allowed_hosts ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					'strtolower',
					array_map( 'strval', $allowed_hosts )
				)
			)
		);
	}

	public static function http_status_hint( int $code ): string {
		switch ( $code ) {
			case 400:
				return 'Bad request - payload or query format issue';
			case 401:
			case 403:
				return 'Auth rejected - check token/credentials';
			case 404:
				return 'Endpoint not found - check URL path';
			case 405:
				return 'Method not allowed - endpoint must accept POST';
			case 415:
				return 'Unsupported media type - endpoint should accept application/json';
			case 422:
				return 'Payload validation failed';
			default:
				return $code >= 500 ? 'Server error at destination' : '';
		}
	}

	public static function is_http_success( int $code ): bool {
		return $code >= 200 && $code < 300;
	}

	public static function is_click_and_collect_order( WC_Order $order ): bool {
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
				continue;
			}

			$method_id    = strtolower( trim( (string) $shipping_item->get_method_id() ) );
			$method_title = strtolower( trim( (string) $shipping_item->get_method_title() ) );
			$instance_id  = strtolower( trim( (string) $shipping_item->get_instance_id() ) );
			$haystack     = trim( $method_id . ' ' . $method_title . ' ' . $instance_id );

			foreach ( array( 'local_pickup', 'click and collect', 'click & collect', 'click_collect', 'click-and-collect', 'collection' ) as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
