<?php
/**
 * Audit logging helpers.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Audit {
    private const OPTION_AUDIT_LOG = 'ck_ows_audit_log';

    public static function log_order_event( WC_Order $order, string $action, array $context = array() ): void {
        $user_id = get_current_user_id();
        $entry   = array(
            'ts'       => time(),
            'type'     => 'order',
            'action'   => sanitize_key( $action ),
            'order_id' => $order->get_id(),
            'user_id'  => $user_id,
            'context'  => self::sanitize_context( $context ),
        );

        self::append_log_entry( $entry );
    }

    public static function log_system_event( string $action, array $context = array() ): void {
        $entry = array(
            'ts'      => time(),
            'type'    => 'system',
            'action'  => sanitize_key( $action ),
            'user_id' => get_current_user_id(),
            'context' => self::sanitize_context( $context ),
        );

        self::append_log_entry( $entry );
    }

    public static function read_recent( int $limit = 20 ): array {
        $rows = get_option( self::OPTION_AUDIT_LOG, array() );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return array();
        }

        $limit = max( 1, min( 100, $limit ) );
        $rows  = array_slice( array_reverse( $rows ), 0, $limit );

        return is_array( $rows ) ? $rows : array();
    }

    private static function append_log_entry( array $entry ): void {
        $rows = get_option( self::OPTION_AUDIT_LOG, array() );
        $rows = is_array( $rows ) ? $rows : array();
        $rows[] = $entry;

        if ( count( $rows ) > 100 ) {
            $rows = array_slice( $rows, -100 );
        }

        update_option( self::OPTION_AUDIT_LOG, $rows, false );
    }

    private static function sanitize_context( array $context ): array {
        $clean = array();

        foreach ( $context as $key => $value ) {
            $clean_key = sanitize_key( (string) $key );

            if ( is_scalar( $value ) || null === $value ) {
                $clean[ $clean_key ] = sanitize_text_field( (string) $value );
                continue;
            }

            if ( is_array( $value ) ) {
                $clean[ $clean_key ] = wp_json_encode( $value );
            }
        }

        return $clean;
    }
}
