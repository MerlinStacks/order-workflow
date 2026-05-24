<?php
/**
 * Shared tracking helpers.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Tracking_Helpers {
	public static function extract_tracking_numbers( WC_Order $order ): array {
		$numbers = array();
		$items   = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( ! is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item['tracking_number'] ) ) {
				$numbers[] = sanitize_text_field( (string) $item['tracking_number'] );
			}
		}

		return array_values( array_unique( array_filter( $numbers ) ) );
	}

	public static function extract_tracking_links( WC_Order $order ): array {
		$links = array();
		$items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( ! is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$provider     = strtolower( (string) ( $item['tracking_provider'] ?? $item['custom_tracking_provider'] ?? '' ) );
			$tracking_num = isset( $item['tracking_number'] ) ? (string) $item['tracking_number'] : '';

			if ( ! empty( $item['formatted_tracking_link'] ) ) {
				$links[] = (string) $item['formatted_tracking_link'];
			} elseif ( ! empty( $item['custom_tracking_link'] ) ) {
				$links[] = (string) $item['custom_tracking_link'];
			} elseif ( '' !== $tracking_num && self::is_auspost_provider( $provider ) ) {
				$links[] = 'https://auspost.com.au/mypost/track/#/details/' . rawurlencode( $tracking_num );
			}
		}

		return array_values( array_unique( array_filter( $links ) ) );
	}

	public static function is_auspost_provider( string $provider ): bool {
		$provider = strtolower( $provider );

		return '' !== $provider && ( false !== strpos( $provider, 'australia post' ) || false !== strpos( $provider, 'auspost' ) );
	}

	public static function looks_like_tracking_event( array $event ): bool {
		$description = trim( implode( ' ', array( (string) ( $event['description'] ?? '' ), (string) ( $event['event_description'] ?? '' ), (string) ( $event['event'] ?? '' ), (string) ( $event['status'] ?? '' ), (string) ( $event['summary'] ?? '' ), (string) ( $event['title'] ?? '' ) ) ) );
		$date        = trim( (string) ( $event['date'] ?? $event['event_time'] ?? $event['datetime'] ?? $event['time'] ?? $event['timestamp'] ?? '' ) );

		return '' !== $description && '' !== $date;
	}

	public static function extract_tracking_events_from_article( array $article ): array {
		foreach ( array( 'events', 'tracking_events', 'article_events', 'tracking_details' ) as $key ) {
			if ( isset( $article[ $key ] ) && is_array( $article[ $key ] ) ) {
				$events = self::normalize_tracking_events( $article[ $key ] );

				if ( ! empty( $events ) ) {
					return $events;
				}
			}
		}

		return self::collect_tracking_events_from_node( $article );
	}

	public static function normalize_tracking_events( array $events ): array {
		$normalized = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( self::looks_like_tracking_event( $event ) ) {
				$normalized[] = $event;
				continue;
			}

			$nested = self::collect_tracking_events_from_node( $event );

			if ( ! empty( $nested ) ) {
				$normalized = array_merge( $normalized, $nested );
			}
		}

		return $normalized;
	}

	public static function collect_tracking_events_from_node( $node, int $depth = 0 ): array {
		$max_depth = absint( apply_filters( 'ck_ows_tracking_event_depth_limit', 5 ) );

		if ( $depth > $max_depth || ! is_array( $node ) ) {
			return array();
		}

		if ( self::looks_like_tracking_event( $node ) ) {
			return array( $node );
		}

		$events = array();

		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				$events = array_merge( $events, self::collect_tracking_events_from_node( $child, $depth + 1 ) );
			}
		}

		return $events;
	}

	public static function parse_event_timestamp( string $value ): int {
		$timestamp = strtotime( trim( $value ) );

		return false === $timestamp ? 0 : (int) $timestamp;
	}

	public static function format_tracking_datetime( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? $value : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}

	public static function sort_events_newest_first( array $events ): array {
		usort(
			$events,
			static function ( $a, $b ): int {
				$a_time = is_array( $a ) ? self::parse_event_timestamp( (string) ( $a['date'] ?? $a['event_time'] ?? $a['datetime'] ?? $a['time'] ?? $a['timestamp'] ?? '' ) ) : 0;
				$b_time = is_array( $b ) ? self::parse_event_timestamp( (string) ( $b['date'] ?? $b['event_time'] ?? $b['datetime'] ?? $b['time'] ?? $b['timestamp'] ?? '' ) ) : 0;

				return $b_time <=> $a_time;
			}
		);

		return $events;
	}

	public static function resolve_latest_event( array $events ): array {
		$latest_event = array();
		$latest_ts    = 0;
		$has_valid_ts = false;

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_ts = self::parse_event_timestamp( (string) ( $event['date'] ?? $event['event_time'] ?? $event['datetime'] ?? $event['time'] ?? $event['timestamp'] ?? '' ) );

			if ( $event_ts > 0 ) {
				$has_valid_ts = true;
			}

			if ( $event_ts > $latest_ts ) {
				$latest_ts    = $event_ts;
				$latest_event = $event;
			}
		}

		if ( $has_valid_ts && ! empty( $latest_event ) ) {
			return $latest_event;
		}

		$first = $events[0] ?? array();

		return is_array( $first ) ? $first : array();
	}

	public static function contains_delivered_event( array $events ): bool {
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$haystack = strtolower( trim( implode( ' ', array( (string) ( $event['description'] ?? '' ), (string) ( $event['event_description'] ?? '' ), (string) ( $event['event'] ?? '' ), (string) ( $event['status'] ?? '' ), (string) ( $event['summary'] ?? '' ), (string) ( $event['title'] ?? '' ), (string) ( $event['event_type'] ?? '' ), (string) ( $event['code'] ?? '' ) ) ) ) );

			foreach ( array( 'delivered', 'delivery complete', 'proof of delivery', 'item delivered', 'successfully delivered', 'collected by customer', 'awaiting collection', 'left in a safe place' ) as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
