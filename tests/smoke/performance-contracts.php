<?php
/**
 * Performance and customer-experience contract smoke tests.
 *
 * Usage: php tests/smoke/performance-contracts.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function performance_method_body(string $source, string $method): string {
	$pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*[^{]+)?\s*\{/';
	if (! preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
		return '';
	}

	$start = $matches[0][1] + strlen($matches[0][0]);
	$depth = 1;
	$length = strlen($source);

	for ($index = $start; $index < $length; $index++) {
		if ('{' === $source[$index]) {
			$depth++;
		} elseif ('}' === $source[$index]) {
			$depth--;
			if (0 === $depth) {
				return substr($source, $start, $index - $start);
			}
		}
	}

	return '';
}

function require_contract(string $source, string $needle, string $label, array &$failures): void {
	if (false === strpos($source, $needle)) {
		$failures[] = 'Missing contract: ' . $label;
	}
}

$files = array(
	'tracking'     => $root . '/includes/class-ck-ows-tracking.php',
	'settings'     => $root . '/includes/class-ck-ows-settings.php',
	'preferences'  => $root . '/includes/class-ck-ows-account-email-preferences.php',
	'artwork'      => $root . '/includes/class-ck-ows-artwork-proof.php',
	'registration' => $root . '/includes/class-ck-ows-registration-guard.php',
	'timeline'     => $root . '/includes/class-ck-ows-order-timeline.php',
	'plugin'       => $root . '/includes/class-ck-ows-plugin.php',
);

$sources = array();
$failures = array();

foreach ($files as $name => $file) {
	$source = file_get_contents($file);
	if (! is_string($source) || '' === $source) {
		$failures[] = 'Could not read ' . $name . ' source';
		continue;
	}
	$sources[$name] = $source;
}

if (empty($failures)) {
	$render_tracking = performance_method_body($sources['tracking'], 'get_tracking_payload_for_order');
	require_contract($render_tracking, 'is_tracking_sync_enabled()', 'tracking enabled render guard', $failures);
	require_contract($render_tracking, 'has_tracking_credentials()', 'tracking credential render guard', $failures);
	require_contract($render_tracking, 'extract_auspost_tracking_numbers', 'AusPost-only render scheduling', $failures);
	if (false !== strpos($render_tracking, '->save(') || false !== strpos($render_tracking, '->save_meta_data(')) {
		$failures[] = 'Tracking render path must not persist an order';
	}

	$refresh_tracking = performance_method_body($sources['tracking'], 'refresh_order_tracking');
	require_contract($refresh_tracking, 'MAX_PARCELS_PER_ORDER', 'bounded parcel requests per worker', $failures);
	require_contract($refresh_tracking, 'save_meta_data()', 'meta-only tracking persistence', $failures);

	$refresh_worker = performance_method_body($sources['tracking'], 'refresh_single_order');
	require_contract($refresh_worker, 'REFRESH_INTERVAL', 'worker-level tracking refresh throttle', $failures);

	$manual_sync = performance_method_body($sources['settings'], 'run_tracking_sync_now');
	require_contract($manual_sync, 'queue_tracking_sync()', 'background manual tracking sync', $failures);

	require_contract($sources['preferences'], 'PREFS_STALE_CACHE_TTL', 'stale email preference cache', $failures);
	require_contract($sources['preferences'], 'PREFS_FAILURE_TTL', 'email preference failure circuit breaker', $failures);
	require_contract($sources['preferences'], 'cache_submitted_preferences(', 'post-save email preference cache', $failures);

	$banner = performance_method_body($sources['artwork'], 'render_pending_approval_banner');
	require_contract($banner, "'limit'       => 2", 'bounded artwork banner query', $failures);
	require_contract($banner, "'status'      => array( 'awaiting-artwork' )", 'artwork status query filter', $failures);
	require_contract($banner, "'meta_key'    => self::META_APPROVAL_STATE", 'artwork approval query filter', $failures);

	$registration = performance_method_body($sources['registration'], 'validate_registration_attempt');
	$rate_limit_position = strpos($registration, '$hits >= self::IP_LIMIT');
	$honeypot_position = strpos($registration, '$this->log_block( $email, $username, \'honeypot\' )');
	if (false === $rate_limit_position || false === $honeypot_position || $rate_limit_position > $honeypot_position) {
		$failures[] = 'Registration limiter must run before rejection logging';
	}

	$timeline = performance_method_body($sources['timeline'], 'capture_stage_timestamp');
	require_contract($timeline, 'save_meta_data()', 'meta-only timeline persistence', $failures);

	$constructor = performance_method_body($sources['plugin'], '__construct');
	require_contract($constructor, 'register_autoloader()', 'constrained plugin autoloader', $failures);
	require_contract($constructor, 'CK_OWS_Statuses::instance()', 'eager status registration', $failures);
	foreach (array('CK_OWS_Settings::instance()', 'CK_OWS_Tracking::instance()', 'CK_OWS_Artwork_Proof::instance()', 'CK_OWS_Admin_Order_Actions::instance()') as $eager_module) {
		if (false !== strpos($constructor, $eager_module)) {
			$failures[] = 'Module must not load eagerly: ' . $eager_module;
		}
	}

	$customer_boot = performance_method_body($sources['plugin'], 'boot_customer_modules');
	require_contract($customer_boot, 'is_account_page()', 'account request guard', $failures);
	require_contract($customer_boot, "is_wc_endpoint_url( 'invoices' )", 'invoice endpoint loading guard', $failures);
	require_contract($customer_boot, "is_wc_endpoint_url( 'view-order' )", 'order-detail loading guard', $failures);

	$admin_boot = performance_method_body($sources['plugin'], 'boot_order_admin_modules');
	require_contract($admin_boot, "'woocommerce_page_wc-orders'", 'HPOS admin screen loading guard', $failures);
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, '[FAIL] ' . $failure . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "Performance contracts passed.\n");
exit(0);
