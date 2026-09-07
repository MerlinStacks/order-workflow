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
$invoice_file         = $root . '/includes/class-ck-ows-invoice-integration.php';
$settings_file        = $root . '/includes/class-ck-ows-settings.php';
$statuses_file        = $root . '/includes/class-ck-ows-statuses.php';
$artwork_file         = $root . '/includes/class-ck-ows-artwork-events.php';
$proof_file           = $root . '/includes/class-ck-ows-artwork-proof.php';
$plugin_file          = $root . '/includes/class-ck-ows-plugin.php';
$cleanup_file         = $root . '/includes/class-ck-ows-action-scheduler-cleanup.php';
$uninstall_file       = $root . '/uninstall.php';

$tracking_source = file_get_contents($tracking_events_file);
$tracking_core_source = file_get_contents($tracking_file);
$invoice_source = file_get_contents($invoice_file);
$settings_source = file_get_contents($settings_file);
$statuses_source = file_get_contents($statuses_file);
$artwork_source = file_get_contents($artwork_file);
$proof_source = file_get_contents($proof_file);
$plugin_source = file_get_contents($plugin_file);
$cleanup_source = file_get_contents($cleanup_file);
$uninstall_source = file_get_contents($uninstall_file);

if (! is_string($tracking_source) || '' === $tracking_source) {
	$failures[] = '[FAIL] Could not read tracking email events source';
}

if (! is_string($tracking_core_source) || '' === $tracking_core_source) {
	$failures[] = '[FAIL] Could not read tracking source';
}

if (! is_string($invoice_source) || '' === $invoice_source) {
	$failures[] = '[FAIL] Could not read invoice integration source';
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

if (! is_string($plugin_source) || '' === $plugin_source) {
	$failures[] = '[FAIL] Could not read plugin bootstrap source';
}

if (! is_string($cleanup_source) || '' === $cleanup_source) {
	$failures[] = '[FAIL] Could not read Action Scheduler cleanup source';
}

if (empty($failures)) {
	assert_contains($plugin_source, "add_action( 'ck_ows_tracking_event_retry'", 'lazy retry hook registration', $failures);
	assert_contains($tracking_source, 'schedule_retry(', 'retry scheduler usage', $failures);
	assert_contains($tracking_source, 'push_dead_letter(', 'dead-letter writer usage', $failures);
	assert_contains($tracking_source, 'schedule_delivery(', 'queued tracking webhook delivery', $failures);
	assert_contains($tracking_core_source, 'ORDER_REFRESH_HOOK', 'background order tracking refresh', $failures);
	assert_contains($tracking_core_source, 'CONTINUATION_HOOK', 'bounded tracking continuation', $failures);
	assert_contains($plugin_source, "ck_ows_action_scheduler_cleanup", 'plugin-scoped action history cleanup hook', $failures);
	assert_contains($cleanup_source, "ActionScheduler_Store::STATUS_COMPLETE", 'completed-only action history cleanup', $failures);
	assert_contains($cleanup_source, "'group'            => \$group", 'group-scoped action history cleanup', $failures);
	assert_contains($cleanup_source, 'RETENTION_PERIOD   = DAY_IN_SECONDS', '24-hour action history retention', $failures);
	assert_contains($cleanup_source, 'BATCH_SIZE         = 250', 'bounded action history cleanup', $failures);
	if (false !== strpos($cleanup_source, 'action_scheduler_retention_period')) {
		$failures[] = '[FAIL] Plugin cleanup must not alter global Action Scheduler retention';
	}
	assert_contains($tracking_core_source, 'should_skip_sync_for_delivered_order( $order, $tracking_numbers )', 'delivered-order queue suppression', $failures);
	assert_contains($tracking_source, "'ck_ows_last_webhook_delivery'", 'last webhook status tracking', $failures);
	assert_contains($tracking_core_source, 'isset( $first[\'items\'][0] )', 'AusPost tracking_results items parser', $failures);
	assert_contains($tracking_core_source, 'isset( $body[\'items\'][0] )', 'AusPost top-level items parser', $failures);
	assert_contains($invoice_source, "'invoice_token'", 'OverSeek invoice download token parameter', $failures);

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
	assert_contains($statuses_source, 'woocommerce_rest_shop_order_object_query', 'ReadyToShip REST order query gate hook', $failures);
	assert_contains($statuses_source, 'mask_paid_cancelled_status_in_rest_response', 'REST paid-cancelled status response mask', $failures);
	assert_contains($artwork_source, 'get_event_dedupe_state', 'artwork post-success event deduplication', $failures);
	assert_contains($artwork_source, 'schedule_delivery(', 'queued artwork webhook delivery', $failures);
	assert_contains($proof_source, 'proof_identity', 'proof revision-bound customer action', $failures);
	assert_contains($proof_source, 'production_gate_guard', 'request-local production gate recursion guard', $failures);
	assert_contains($statuses_source, 'gate_readytoship_rest_order_query', 'ReadyToShip REST order query gate', $failures);
	assert_contains($statuses_source, 'is_readytoship_rest_request', 'ReadyToShip REST request detector', $failures);
	assert_contains($statuses_source, 'get_readytoship_rest_status', 'ReadyToShip REST status mapper', $failures);
	assert_contains($settings_source, 'readytoship_consumer_key_suffix', 'ReadyToShip API key suffix setting', $failures);
	assert_contains($settings_source, 'readytoship_key_description', 'ReadyToShip API key description setting', $failures);
	assert_contains($statuses_source, "'_ck_ows_external_safe_status'", 'persisted external-safe order status', $failures);
	assert_contains($statuses_source, 'should_mask_cancelled_status', 'REST cancelled status mask guard', $failures);
	assert_contains($statuses_source, 'get_cancelled_status_mask', 'REST cancelled status mask resolver', $failures);
	assert_contains($statuses_source, 'woocommerce_webhook_payload', 'webhook order status mask hook', $failures);
	assert_contains($statuses_source, 'mask_order_status_in_webhook_payload', 'webhook cancelled status mask', $failures);

	assert_contains($uninstall_source, "keep_data_on_uninstall", 'uninstall keep-data toggle', $failures);
	assert_contains($uninstall_source, "delete_option( 'ckrg_block_log' )", 'registration guard cleanup on uninstall', $failures);
	assert_contains($uninstall_source, "delete_option( 'ck_ows_tracking_event_dead_letters' )", 'dead-letter cleanup on uninstall', $failures);
	assert_contains($uninstall_source, "delete_option( 'ck_ows_last_tracking_number_test' )", 'tracking diagnostic cleanup on uninstall', $failures);
	assert_contains($uninstall_source, "delete_option( 'ck_ows_last_artwork_webhook_test' )", 'artwork diagnostic cleanup on uninstall', $failures);
	assert_contains($uninstall_source, "'wc_orders_meta'", 'HPOS order metadata table cleanup on uninstall', $failures);
	assert_contains($uninstall_source, "esc_like( '_ck_ows_' )", 'literal uninstall metadata prefix', $failures);
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, $failure . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "Workflow contracts passed.\n");
exit(0);
