<?php
/**
 * Tracking classification behavior tests.
 *
 * Usage: php tests/smoke/tracking-classification.php
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

if (! function_exists('sanitize_text_field')) {
	function sanitize_text_field($value): string {
		return trim(strip_tags((string) $value));
	}
}

if (! class_exists('WC_Order')) {
	class WC_Order {
		private array $metadata;

		public function __construct(array $metadata) {
			$this->metadata = $metadata;
		}

		public function get_meta(string $key, bool $single = true) {
			unset($single);

			return $this->metadata[$key] ?? '';
		}
	}
}

require_once dirname(__DIR__, 2) . '/includes/class-ck-ows-tracking-helpers.php';

$failures = array();

$delivered_phrases = array(
	'Delivered',
	'Item delivered to recipient',
	'Delivery complete',
	'Proof of delivery captured',
	'Collected by customer',
	'Left in a safe place',
);

$not_delivered_phrases = array(
	'Not delivered',
	'Not yet delivered',
	'Undelivered item',
	'Unable to be delivered',
	'Could not be delivered',
	'Delivery attempted',
	'Failed delivery',
	'Awaiting collection',
	'Ready for collection',
	'Returning to sender',
);

foreach ($delivered_phrases as $phrase) {
	if (! CK_OWS_Tracking_Helpers::is_delivered_status_text($phrase)) {
		$failures[] = 'Expected delivered classification: ' . $phrase;
	}
}

foreach ($not_delivered_phrases as $phrase) {
	if (CK_OWS_Tracking_Helpers::is_delivered_status_text($phrase)) {
		$failures[] = 'Unexpected delivered classification: ' . $phrase;
	}
}

if (CK_OWS_Tracking_Helpers::contains_delivered_event(array(array('description' => 'Unable to be delivered')))) {
	$failures[] = 'Negative delivery event was treated as delivered';
}

$order = new WC_Order(
	array(
		'_wc_shipment_tracking_items' => array(
			array(
				'tracking_provider' => 'Australia Post',
				'tracking_number'   => '123456789012',
			),
			array(
				'tracking_provider' => 'DHL',
				'tracking_number'   => '987654321098',
			),
			array(
				'custom_tracking_link' => 'https://auspost.com.au/mypost/track/#/details/AA123456789AU',
				'tracking_number'      => 'AA123456789AU',
			),
		),
	)
);

$auspost_numbers = CK_OWS_Tracking_Helpers::extract_auspost_tracking_numbers($order);
$expected_numbers = array('123456789012', 'AA123456789AU');

if ($expected_numbers !== $auspost_numbers) {
	$failures[] = 'AusPost carrier filtering returned unexpected tracking numbers';
}

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, '[FAIL] ' . $failure . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "Tracking classification tests passed.\n");
exit(0);
