<?php
/**
 * Plugin-scoped Action Scheduler history cleanup.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes old completed actions created by this plugin without affecting other queues.
 */
class CK_OWS_Action_Scheduler_Cleanup {
	public const CLEANUP_HOOK      = 'ck_ows_action_scheduler_cleanup';
	public const CONTINUATION_HOOK = 'ck_ows_action_scheduler_cleanup_continuation';

	private const SCHEDULE_CHECK_KEY = 'ck_ows_action_scheduler_cleanup_schedule_check';
	private const LOCK_KEY           = 'ck_ows_action_scheduler_cleanup_lock';
	private const RETENTION_PERIOD   = DAY_IN_SECONDS;
	private const BATCH_SIZE         = 250;
	private const GROUPS             = array(
		'ck-ows-tracking',
		'ck-ows-tracking-events',
		'ck-ows-artwork',
	);

	private static ?CK_OWS_Action_Scheduler_Cleanup $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return CK_OWS_Action_Scheduler_Cleanup
	 */
	public static function instance(): CK_OWS_Action_Scheduler_Cleanup {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Ensure the lightweight WP-Cron cleanup schedule exists.
	 *
	 * @return void
	 */
	public function ensure_schedule(): void {
		if ( false !== get_transient( self::SCHEDULE_CHECK_KEY ) ) {
			return;
		}

		if ( wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			set_transient( self::SCHEDULE_CHECK_KEY, '1', HOUR_IN_SECONDS );
			return;
		}

		$result = wp_schedule_event( time() + 300, 'hourly', self::CLEANUP_HOOK, array(), true );
		if ( ! is_wp_error( $result ) && false !== $result ) {
			set_transient( self::SCHEDULE_CHECK_KEY, '1', HOUR_IN_SECONDS );
		}
	}

	/**
	 * Delete one bounded batch of completed plugin actions older than one day.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}

		$lock_owner = $this->acquire_lock();
		if ( '' === $lock_owner ) {
			return;
		}

		$fetched = 0;
		$errors  = 0;

		try {
			$store  = ActionScheduler_Store::instance();
			$cutoff = new DateTime( '@' . ( time() - self::RETENTION_PERIOD ) );

			foreach ( self::GROUPS as $group ) {
				$remaining = self::BATCH_SIZE - $fetched;
				if ( $remaining <= 0 ) {
					break;
				}

				$action_ids = $store->query_actions(
					array(
						'group'            => $group,
						'status'           => ActionScheduler_Store::STATUS_COMPLETE,
						'modified'         => $cutoff,
						'modified_compare' => '<=',
						'per_page'         => $remaining,
						'orderby'          => 'none',
					)
				);

				$fetched += count( $action_ids );
				foreach ( $action_ids as $action_id ) {
					try {
						$store->delete_action( (int) $action_id );
					} catch ( Throwable $exception ) {
						unset( $exception );
						++$errors;
					}
				}
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			++$errors;
		} finally {
			$this->release_lock( $lock_owner );
		}

		if ( self::BATCH_SIZE === $fetched && ! wp_next_scheduled( self::CONTINUATION_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUATION_HOOK );
		}

		if ( $errors > 0 && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf( 'Action Scheduler cleanup encountered %d deletion error(s).', $errors ),
				array( 'source' => 'ck-order-workflow-suite' )
			);
		}
	}

	/**
	 * Acquire the cleanup lock, recovering it after an interrupted run.
	 *
	 * @return string Lock owner token, or an empty string when unavailable.
	 */
	private function acquire_lock(): string {
		$owner   = wp_generate_uuid4();
		$payload = array(
			'owner'   => $owner,
			'expires' => time() + ( 10 * MINUTE_IN_SECONDS ),
		);

		if ( add_option( self::LOCK_KEY, $payload, '', false ) ) {
			return $owner;
		}

		$current = get_option( self::LOCK_KEY, array() );
		if ( ! is_array( $current ) || absint( $current['expires'] ?? 0 ) >= time() ) {
			return '';
		}

		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_KEY,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( self::LOCK_KEY, 'options' );

		return 1 === $deleted && add_option( self::LOCK_KEY, $payload, '', false ) ? $owner : '';
	}

	/**
	 * Release the lock only when this worker still owns it.
	 *
	 * @param string $owner Lock owner token.
	 * @return void
	 */
	private function release_lock( string $owner ): void {
		$current = get_option( self::LOCK_KEY, array() );

		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( $owner, (string) $current['owner'] ) ) {
			delete_option( self::LOCK_KEY );
		}
	}
}
