<?php
/**
 * Order statuses module.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Statuses {
	public const STATUS_IN_PRODUCTION       = 'wc-in-production';
	public const STATUS_IN_DISPATCH         = 'wc-in-dispatch';
	public const STATUS_AWAITING_ARTWORK    = 'wc-awaiting-artwork-approval';

	private static ?CK_OWS_Statuses $instance = null;

	public static function instance(): CK_OWS_Statuses {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'inject_statuses' ) );
	}

	public function register_statuses(): void {
		register_post_status(
			self::STATUS_IN_PRODUCTION,
			array(
				'label'                     => _x( 'In Production', 'Order status', 'ck-order-workflow-suite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'In Production <span class="count">(%s)</span>',
					'In Production <span class="count">(%s)</span>',
					'ck-order-workflow-suite'
				),
			)
		);

		register_post_status(
			self::STATUS_IN_DISPATCH,
			array(
				'label'                     => _x( 'In Dispatch', 'Order status', 'ck-order-workflow-suite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'In Dispatch <span class="count">(%s)</span>',
					'In Dispatch <span class="count">(%s)</span>',
					'ck-order-workflow-suite'
				),
			)
		);

		register_post_status(
			self::STATUS_AWAITING_ARTWORK,
			array(
				'label'                     => _x( 'Awaiting Artwork Approval', 'Order status', 'ck-order-workflow-suite' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'Awaiting Artwork Approval <span class="count">(%s)</span>',
					'Awaiting Artwork Approval <span class="count">(%s)</span>',
					'ck-order-workflow-suite'
				),
			)
		);
	}

	public function inject_statuses( array $statuses ): array {
		$updated = array();

		foreach ( $statuses as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$updated[ self::STATUS_AWAITING_ARTWORK ] = _x( 'Awaiting Artwork Approval', 'Order status', 'ck-order-workflow-suite' );
				$updated[ self::STATUS_IN_PRODUCTION ]    = _x( 'In Production', 'Order status', 'ck-order-workflow-suite' );
				$updated[ self::STATUS_IN_DISPATCH ]      = _x( 'In Dispatch', 'Order status', 'ck-order-workflow-suite' );
			}
		}

		if ( ! isset( $updated[ self::STATUS_IN_PRODUCTION ] ) ) {
			$updated[ self::STATUS_AWAITING_ARTWORK ] = _x( 'Awaiting Artwork Approval', 'Order status', 'ck-order-workflow-suite' );
			$updated[ self::STATUS_IN_PRODUCTION ]    = _x( 'In Production', 'Order status', 'ck-order-workflow-suite' );
			$updated[ self::STATUS_IN_DISPATCH ]      = _x( 'In Dispatch', 'Order status', 'ck-order-workflow-suite' );
		}

		return $updated;
	}
}
