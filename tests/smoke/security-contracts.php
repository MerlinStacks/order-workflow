<?php
/**
 * Lightweight security contract smoke tests.
 *
 * Usage: php tests/smoke/security-contracts.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$checks = array(
	array(
		'file'    => $root . '/includes/class-ck-ows-customer-shipping-edit.php',
		'method'  => 'handle_update',
		'require' => array( 'check_admin_referer', 'is_user_logged_in' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-artwork-proof.php',
		'method'  => 'handle_customer_action',
		'require' => array( 'check_admin_referer', 'is_user_logged_in' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-artwork-proof.php',
		'method'  => 'handle_staff_upload',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-artwork-proof.php',
		'method'  => 'handle_staff_delete_revision',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-artwork-proof.php',
		'method'  => 'handle_staff_override',
		'require' => array( 'wp_verify_nonce', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-admin-order-actions.php',
		'method'  => 'handle_row_action',
		'require' => array( 'wp_verify_nonce', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'run_tracking_sync_now',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'test_connections',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'export_settings',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'import_settings',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'retry_dead_letter',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'clear_dead_letters',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'retry_all_dead_letters',
		'require' => array( 'check_admin_referer', 'current_user_can' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-account-email-preferences.php',
		'method'  => 'handle_update',
		'require' => array( 'check_admin_referer', 'is_user_logged_in' ),
	),
);

$redirect_checks = array(
	array(
		'file'    => $root . '/includes/class-ck-ows-admin-order-actions.php',
		'method'  => 'handle_row_action',
		'require' => array( 'wp_safe_redirect', 'get_redirect_url' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-admin-order-actions.php',
		'method'  => 'sanitize_redirect_url',
		'require' => array( 'wp_validate_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-customer-shipping-edit.php',
		'method'  => 'redirect_with_notice',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-artwork-proof.php',
		'method'  => 'redirect_customer_with_notice',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'run_tracking_sync_now',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'test_connections',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'import_settings',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'retry_dead_letter',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'clear_dead_letters',
		'require' => array( 'wp_safe_redirect' ),
	),
	array(
		'file'    => $root . '/includes/class-ck-ows-settings.php',
		'method'  => 'retry_all_dead_letters',
		'require' => array( 'wp_safe_redirect' ),
	),
);

function extract_method_body(string $source, string $method): string {
	$pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*[^{]+)?\s*\{/';
	if (! preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
		return '';
	}

	$start = $matches[0][1] + strlen($matches[0][0]);
	$len   = strlen($source);
	$depth = 1;

	for ($i = $start; $i < $len; $i++) {
		$char = $source[$i];
		if ('{' === $char) {
			$depth++;
			continue;
		}
		if ('}' === $char) {
			$depth--;
			if (0 === $depth) {
				return substr($source, $start, $i - $start);
			}
		}
	}

	return '';
}

$failures = array();

foreach ($checks as $check) {
	$source = file_get_contents($check['file']);
	if (! is_string($source) || '' === $source) {
		$failures[] = 'Could not read file: ' . $check['file'];
		continue;
	}

	$body = extract_method_body($source, $check['method']);
	if ('' === $body) {
		$failures[] = 'Method not found: ' . $check['method'] . ' in ' . $check['file'];
		continue;
	}

	foreach ($check['require'] as $required_call) {
		if (false === strpos($body, $required_call . '(')) {
			$failures[] = sprintf(
				'Missing %s() in %s::%s',
				$required_call,
				basename($check['file']),
				$check['method']
			);
		}
	}
}

foreach ($redirect_checks as $check) {
	$source = file_get_contents($check['file']);
	if (! is_string($source) || '' === $source) {
		$failures[] = 'Could not read file: ' . $check['file'];
		continue;
	}

	$body = extract_method_body($source, $check['method']);
	if ('' === $body) {
		$failures[] = 'Method not found: ' . $check['method'] . ' in ' . $check['file'];
		continue;
	}

	foreach ($check['require'] as $required_call) {
		if (false === strpos($body, $required_call . '(')) {
			$failures[] = sprintf(
				'Missing %s() in %s::%s',
				$required_call,
				basename($check['file']),
				$check['method']
			);
		}
	}

	if (false !== strpos($body, 'wp_redirect(')) {
		$failures[] = sprintf(
			'Use wp_safe_redirect() instead of wp_redirect() in %s::%s',
			basename($check['file']),
			$check['method']
		);
	}
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "[FAIL] {$failure}\n");
	}
	exit(1);
}

fwrite(STDOUT, "Security contracts passed.\n");
exit(0);
