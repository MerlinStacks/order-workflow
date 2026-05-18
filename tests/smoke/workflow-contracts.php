<?php
/**
 * Workflow and reliability contract smoke tests.
 *
 * Usage: php tests/smoke/workflow-contracts.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assert_contains(string $source, string $needle, string $label, array &$failures): void {
	if (false === strpos($source, $needle)) {
		$failures[] = '[FAIL] Missing contract: ' . $label;
	}
}

$failures = array();

$tracking_events_file = $root . '/includes/class-ck-ows-tracking-email-events.php';
$tracking_file        = $root . '/includes/class-ck-ows-tracking.php';
$settings_file        = $root . '/includes/class-ck-ows-settings.php';
$statuses_file        = $root . '/includes/class-ck-ows-statuses.php';
$uninstall_file       = $root . '/uninstall.php';

$tracking_source = file_get_contents($tracking_events_file);
$tracking_core_source = file_get_contents($tracking_file);
$settings_source = file_get_contents($settings_file);
$statuses_source = file_get_contents($statuses_file);
$uninstall_source = file_get_contents($uninstall_file);

if (! is_string($tracking_source) || '' === $tracking_source) {
	$failures[] = '[FAIL] Could not read tracking email events source';
}

if (! is_string($tracking_core_source) || '' === $tracking_core_source) {
	$failures[] = '[FAIL] Could not read tracking source';
}

if (! is_string($settings_source) || '' === $settings_source) {
	$failures[] = '[FAIL] Could not read settings source';
}

if (! is_string($statuses_source) || '' === $statuses_source) {
	$failures[] = '[FAIL] Could not read statuses source';
}

if (! is_string($uninstall_source) || '' === $uninstall_source) {
	$failures[] = '[FAIL] Could not read uninstall source';
}

if (empty($failures)) {
	assert_contains($tracking_source, "add_action( self::RETRY_HOOK", 'retry hook registration', $failures);
	assert_contains($tracking_source, 'schedule_retry(', 'retry scheduler usage', $failures);
	assert_contains($tracking_source, 'push_dead_letter(', 'dead-letter writer usage', $failures);
	assert_contains($tracking_source, "'ck_ows_last_webhook_delivery'", 'last webhook status tracking', $failures);
	assert_contains($tracking_core_source, 'isset( $first[\'items\'][0] )', 'AusPost tracking_results items parser', $failures);
	assert_contains($tracking_core_source, 'isset( $body[\'items\'][0] )', 'AusPost top-level items parser', $failures);

	assert_contains($settings_source, 'render_dead_letters_panel()', 'dead-letter panel renderer', $failures);
	assert_contains($settings_source, 'retry_dead_letter(): void', 'dead-letter retry handler', $failures);
	assert_contains($settings_source, 'clear_dead_letters(): void', 'dead-letter clear handler', $failures);
	assert_contains($settings_source, 'retry_all_dead_letters(): void', 'dead-letter retry all handler', $failures);
	assert_contains($settings_source, 'check_admin_referer( self::DLQ_RETRY_NONCE', 'retry nonce verification', $failures);
	assert_contains($settings_source, 'check_admin_referer( self::DLQ_CLEAR_NONCE', 'clear nonce verification', $failures);
	assert_contains($settings_source, 'check_admin_referer( self::DLQ_RETRY_ALL_NONCE', 'retry-all nonce verification', $failures);
	assert_contains($settings_source, "'artwork_events_webhook_url'", 'artwork webhook URL setting', $failures);
	assert_contains($settings_source, "'artwork_events_auth_token'", 'artwork auth token setting', $failures);
	assert_contains($statuses_source, 'track_webhook_blocked_status_transition', 'status transition webhook block tracker', $failures);
	assert_contains($statuses_source, 'is_blocked_external_status_transition', 'paid-to-cancelled external webhook block', $failures);
	assert_contains($statuses_source, '\'cancelled\' !== $to_status', 'cancelled-only external status block guard', $failures);
	assert_contains($statuses_source, 'woocommerce_rest_prepare_shop_order_object', 'REST order response status mask hook', $failures);
	assert_contains($statuses_source, 'mask_paid_cancelled_status_in_rest_response', 'REST paid-cancelled status response mask', $failures);
	assert_contains($statuses_source, "'_ck_ows_external_safe_status'", 'persisted external-safe order status', $failures);
	assert_contains($statuses_source, 'should_mask_cancelled_status', 'REST cancelled status mask guard', $failures);
	assert_contains($statuses_source, 'get_cancelled_status_mask', 'REST cancelled status mask resolver', $failures);
	assert_contains($statuses_source, 'woocommerce_webhook_payload', 'webhook order status mask hook', $failures);
	assert_contains($statuses_source, 'mask_order_status_in_webhook_payload', 'webhook cancelled status mask', $failures);

	assert_contains($uninstall_source, "keep_data_on_uninstall", 'uninstall keep-data toggle', $failures);
	assert_contains($uninstall_source, "delete_option( 'ckrg_block_log' )", 'registration guard cleanup on uninstall', $failures);
	assert_contains($uninstall_source, "delete_option( 'ck_ows_tracking_event_dead_letters' )", 'dead-letter cleanup on uninstall', $failures);
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, $failure . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "Workflow contracts passed.\n");
exit(0);
