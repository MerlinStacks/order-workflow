<?php
/**
 * Shared singleton base class.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

abstract class CK_OWS_Base {
	/**
	 * Instances keyed by concrete class name.
	 *
	 * @var array<string, object>
	 */
	private static array $instances = array();

	/**
	 * Constructor.
	 */
	protected function __construct() {}

	/**
	 * Prevent cloning.
	 */
	final protected function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @return void
	 */
	final public function __wakeup(): void {
		throw new RuntimeException( 'Cannot unserialize singleton.' );
	}

	/**
	 * Get singleton instance for concrete class.
	 *
	 * @return static
	 */
	final public static function instance(): static {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();
		}

		return self::$instances[ $class ];
	}
}
