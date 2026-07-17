<?php
/**
 * Request-scoped bootstrap loading tests.
 *
 * Usage: php tests/smoke/bootstrap-loading.php
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');
define('CK_OWS_PATH', dirname(__DIR__, 2) . '/');

$ck_ows_registered_hooks = array();

function add_action(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): bool {
	global $ck_ows_registered_hooks;
	unset($callback);
	$ck_ows_registered_hooks[] = array('action', $hook_name, $priority, $accepted_args);

	return true;
}

function add_filter(string $hook_name, $callback, int $priority = 10, int $accepted_args = 1): bool {
	global $ck_ows_registered_hooks;
	unset($callback);
	$ck_ows_registered_hooks[] = array('filter', $hook_name, $priority, $accepted_args);

	return true;
}

function is_admin(): bool {
	return false;
}

function get_transient(string $key) {
	unset($key);

	return '1';
}

require_once CK_OWS_PATH . 'includes/class-ck-ows-plugin.php';

$plugin = CK_OWS_Plugin::instance();
$plugin->maybe_ensure_tracking_schedule();

$failures = array();

foreach (array('CK_OWS_Plugin', 'CK_OWS_Base', 'CK_OWS_Statuses') as $expected_class) {
	if (! class_exists($expected_class, false)) {
		$failures[] = 'Expected bootstrap class was not loaded: ' . $expected_class;
	}
}

$deferred_classes = array(
	'CK_OWS_Settings',
	'CK_OWS_Tracking',
	'CK_OWS_Tracking_Email_Events',
	'CK_OWS_Artwork_Proof',
	'CK_OWS_Artwork_Events',
	'CK_OWS_Order_Timeline',
	'CK_OWS_Registration_Guard',
	'CK_OWS_Admin_Order_Actions',
	'CK_OWS_Account_Menu_Helper',
	'CK_OWS_Account_Invoices',
	'CK_OWS_Account_Email_Preferences',
);

foreach ($deferred_classes as $deferred_class) {
	if (class_exists($deferred_class, false)) {
		$failures[] = 'Class should be deferred on a normal storefront request: ' . $deferred_class;
	}
}

$registered_hook_names = array_map(
	static function (array $hook): string {
		return $hook[1];
	},
	$ck_ows_registered_hooks
);

foreach (array('wp', 'rest_api_init', 'woocommerce_order_status_changed', 'ck_ows_tracking_sync_event', 'woocommerce_process_registration_errors') as $required_hook) {
	if (! in_array($required_hook, $registered_hook_names, true)) {
		$failures[] = 'Missing lazy bootstrap hook: ' . $required_hook;
	}
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, '[FAIL] ' . $failure . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "Bootstrap loading tests passed.\n");
exit(0);
