<?php
/**
 * Gift wrapping behavior tests with isolated WordPress/WooCommerce stubs.
 *
 * Usage: php tests/smoke/gift-wrap.php
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/');

class CK_OWS_Base {
	public static function instance(): static {
		return new static();
	}
}

class CK_OWS_Settings {
	public static array $values = array(
		'gift_wrap_enabled' => 'yes',
		'gift_wrap_tag' => 'giftable',
		'gift_wrap_price' => '5.50',
	);

	public static function get(string $key, $default = '') {
		return self::$values[$key] ?? $default;
	}
}

function add_action(...$args): void {}
function add_filter(...$args): void {}
function __(string $text, string $domain = ''): string { return $text; }
function wp_unslash(string $text): string { return stripslashes($text); }
function sanitize_textarea_field(string $text): string {
	return trim(preg_replace('/%[a-f0-9]{2}/i', '', wp_strip_all_tags($text)));
}
function wp_strip_all_tags(string $text): string {
	return strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $text));
}
function esc_html(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}
function wc_add_notice(string $text, string $type): void {
	$GLOBALS['notices'][] = array('text' => $text, 'type' => $type);
}
function wc_get_product(int $id) { return $GLOBALS['products'][$id] ?? false; }
function has_term(string $tag, string $taxonomy, int $id): bool {
	return 'product_tag' === $taxonomy && in_array($tag, $GLOBALS['tags'][$id] ?? array(), true);
}
function wc_tax_enabled(): bool { return $GLOBALS['tax_enabled']; }
function wc_prices_include_tax(): bool { return $GLOBALS['prices_include_tax']; }
function wc_price($price, array $args = array()): string {
	$symbol = 'EUR' === ($args['currency'] ?? 'AUD') ? '&euro;' : '&#36;';
	return '<span class="amount">' . $symbol . number_format((float) $price, 2, '.', '') . '</span>';
}

class WC_Product {
	public function __construct(private string $type, private int $parent = 0) {}
	public function is_type($types): bool { return in_array($this->type, (array) $types, true); }
	public function get_parent_id(): int { return $this->parent; }
	public function get_name(): string { return 'Giftable mug'; }
}

class WC_Tax {
	public static array $calls = array();
	public static array $rate_calls = array();

	public static function get_rates(string $class, $customer): array {
		self::$rate_calls[] = array($class, $customer);
		return array('gst' => array('rate' => 10));
	}

	public static function calc_tax(float $amount, array $rates, bool $inclusive): array {
		self::$calls[] = array($amount, $rates, $inclusive);
		$taxes = array();
		foreach ($rates as $id => $rate) {
			$fraction = $rate['rate'] / 100;
			$taxes[$id] = $inclusive ? $amount - $amount / (1 + $fraction) : $amount * $fraction;
		}
		return $taxes;
	}
}

class WC_Cart_Fees {
	public array $fees = array();
	public function remove_all_fees(): void { $this->fees = array(); }
	public function add_fee(array $fee): void {
		// Keep every call, so duplicate additions cannot be hidden by the stub.
		$this->fees[] = $fee;
	}
}

class WC_Cart {
	private WC_Cart_Fees $fees;
	private object $customer;
	public function __construct(public array $lines) {
		$this->fees = new WC_Cart_Fees();
		$this->customer = (object) array('country' => 'AU');
	}
	public function get_cart(): array { return $this->lines; }
	public function get_customer(): object { return $this->customer; }
	public function fees_api(): WC_Cart_Fees { return $this->fees; }
	public function calculate_totals(CK_OWS_Gift_Wrap $wrap): void {
		// WooCommerce clears fees before firing woocommerce_cart_calculate_fees.
		$this->fees->remove_all_fees();
		$wrap->calculate_fees($this);
	}
}

class WC_Order_Item_Product {
	public array $metadata = array();
	public function add_meta_data(string $key, $value, bool $unique = false): void {
		$this->metadata[] = array('key' => $key, 'value' => $value, 'unique' => $unique);
	}
}

class WC_Order {
	public function get_currency(): string { return 'EUR'; }
}

require_once dirname(__DIR__, 2) . '/includes/class-ck-ows-gift-wrap.php';

$failures = array();
$assertions = 0;
function check(bool $condition, string $label): void {
	++$GLOBALS['assertions'];
	if (! $condition) {
		$GLOBALS['failures'][] = $label;
	}
}
function close_to(float $expected, float $actual): bool { return abs($expected - $actual) < 0.000001; }

$products = array(
	10 => new WC_Product('simple'),
	20 => new WC_Product('variable'),
	21 => new WC_Product('variation', 20),
	30 => new WC_Product('simple'),
	31 => new WC_Product('variation', 30),
	40 => new WC_Product('external'),
	50 => new WC_Product('grouped'),
);
$tags = array(10 => array('giftable'), 20 => array('giftable'), 31 => array('giftable'), 40 => array('giftable'), 50 => array('giftable'));
$notices = array();
$tax_enabled = true;
$prices_include_tax = true;
$wrap = CK_OWS_Gift_Wrap::instance();

foreach (array(10, 20, 21) as $id) {
	check($wrap->eligible($id), 'Tagged simple/variable parent or inherited variation is eligible: ' . $id);
}
foreach (array(30, 31, 40, 50, 999) as $id) {
	check(! $wrap->eligible($id), 'Untagged parent, unsupported or missing product is ineligible: ' . $id);
}
CK_OWS_Settings::$values['gift_wrap_enabled'] = 'no';
check(! $wrap->eligible(10), 'Disabled gift wrapping rejects tagged products');
CK_OWS_Settings::$values['gift_wrap_enabled'] = 'yes';
foreach (array('', 'another-tag') as $tag) {
	CK_OWS_Settings::$values['gift_wrap_tag'] = $tag;
	check(! $wrap->eligible(10), 'Eligibility requires the configured nonempty tag: ' . $tag);
}
CK_OWS_Settings::$values['gift_wrap_tag'] = 'giftable';

$original = array('existing' => 'preserved');
foreach (array(array(), array('ck_ows_gift_message' => 'Unselected'), array('ck_ows_gift_wrap' => 'no'), array('ck_ows_gift_wrap' => array('yes'))) as $post) {
	$_POST = $post;
	check($original === $wrap->add_cart_data($original, 10, 0), 'Unselected request adds no gift data');
	check($wrap->validate(true, 30, 3), 'Unselected ineligible product keeps valid result');
	check(! $wrap->validate(false, 10, 3), 'Unselected request preserves earlier validation failure');
}
check(array() === $notices, 'Unselected requests emit no notices');
$_POST = array('ck_ows_gift_wrap' => 'yes', 'ck_ows_gift_message' => 'Forged', 'price' => '0.01');
check(! $wrap->validate(true, 30, 3), 'Forged selection on ineligible product fails validation');
check($original === $wrap->add_cart_data($original, 30, 0), 'Forged ineligible selection adds no gift data');
check($original === $wrap->add_cart_data($original, 20, 31), 'Cart data checks supplied variation eligibility');
check(1 === count($notices) && 'error' === $notices[0]['type'], 'Ineligible selection emits an error notice');

$messages = array(
	'empty optional message' => array('', true),
	'multiline text' => array("Happy birthday!\nLove, Mum", true),
	'200 ASCII characters' => array(str_repeat('a', 200), true),
	'201 ASCII characters' => array(str_repeat('a', 201), false),
	'200 Unicode characters' => array(str_repeat('é', 200), true),
	'201 Unicode characters' => array(str_repeat('é', 201), false),
	'emoji' => array('Happy birthday 🎁', false),
	'dingbat' => array('With love ❤', false),
	'variation selector' => array("Hi\u{FE0F}", false),
	'keycap' => array("1\u{20E3}", false),
	'array input' => array(array('invalid'), true),
);
foreach ($messages as $label => [$message, $expected]) {
	$_POST = array('ck_ows_gift_wrap' => 'yes', 'ck_ows_gift_message' => $message);
	$notices = array();
	check($expected === $wrap->validate(true, 20, 3), 'Message validation: ' . $label);
	check(($expected ? array() : array('error')) === array_column($notices, 'type'), 'Message notices: ' . $label);
}
$_POST = array('ck_ows_gift_wrap' => 'yes');
check($wrap->validate(true, 10, 1), 'Missing optional message is accepted');
check(! $wrap->validate(false, 10, 1), 'Selected valid request preserves earlier failure');
check('' === $wrap->add_cart_data(array(), 10, 0)['ck_ows_gift_wrap']['message'], 'Missing message is stored as empty text');
$_POST = array(
	'ck_ows_gift_wrap' => 'yes',
	'ck_ows_gift_message' => addslashes(" <b>Happy</b> birthday, O'Connor!\nLove & hugs "),
	'price' => '0.01',
	'ck_ows_gift_wrap_price' => '0.01',
);
$data = $wrap->add_cart_data($original, 20, 21);
$expected_wrap = array('price' => 5.5, 'message' => "Happy birthday, O'Connor!\nLove & hugs");
check(array_merge($original, array('ck_ows_gift_wrap' => $expected_wrap)) === $data, 'Cart data inherits parent eligibility, sanitizes/unslashes text and uses configured price');

$lines = array(
	'wrapped' => array_merge($data, array('quantity' => 3, 'data' => $products[21])),
	'plain' => array('quantity' => 3, 'data' => $products[10]),
);
foreach (array(true, false) as $inclusive) {
	$prices_include_tax = $inclusive;
	$mode = $inclusive ? 'inclusive' : 'exclusive';
	$cart = new WC_Cart($lines);
	WC_Tax::$calls = WC_Tax::$rate_calls = array();
	for ($pass = 1; $pass <= 3; ++$pass) {
		$cart->calculate_totals($wrap);
		$fees = $cart->fees_api()->fees;
		check(1 === count($fees), "$mode totals pass $pass has one fee after reset");
		$fee = $fees[0] ?? array();
		check(close_to(15.0, (float) ($fee['amount'] ?? -1)), "$mode quantity 3 fee excludes mocked 10% tax");
		check('ck_ows_gift_wrap_wrapped' === ($fee['id'] ?? '') && 'Gift wrapping — Giftable mug × 3' === ($fee['name'] ?? ''), "$mode fee identifies cart line and quantity");
		check(true === ($fee['taxable'] ?? null) && '' === ($fee['tax_class'] ?? null), "$mode fee uses taxable standard class");
	}
	check($lines === $cart->get_cart(), "$mode repeated totals do not mutate cart data");
	check(array_fill(0, 3, array('', $cart->get_customer())) === WC_Tax::$rate_calls, "$mode rates use cart customer and standard class");
	check(3 === count(WC_Tax::$calls), "$mode recalculates tax on each pass");
	foreach (WC_Tax::$calls as [$gross, $rates, $extract_inclusive]) {
		check(close_to(16.5, $gross) && true === $extract_inclusive && isset($rates['gst']), "$mode extracts included tax from quantity 3 gross price");
	}
	$fee_tax = WC_Tax::calc_tax((float) ($fee['amount'] ?? 0), array('gst' => array('rate' => 10)), false);
	check(close_to(16.5, (float) ($fee['amount'] ?? 0) + array_sum($fee_tax)), "$mode fee plus tax equals three advertised unit prices");
	$cart->lines['wrapped']['quantity'] = 1;
	$cart->calculate_totals($wrap);
	check(1 === count($cart->fees_api()->fees) && close_to(5.0, $cart->fees_api()->fees[0]['amount']), "$mode quantity change rebuilds fee without compounding");
	unset($cart->lines['wrapped']['ck_ows_gift_wrap']);
	$cart->calculate_totals($wrap);
	check(array() === $cart->fees_api()->fees, "$mode removing wrapping clears the previous fee");
}
$tax_enabled = false;
$cart = new WC_Cart($lines);
WC_Tax::$rate_calls = array();
$cart->calculate_totals($wrap);
check(close_to(16.5, $cart->fees_api()->fees[0]['amount']), 'Tax disabled charges quantity 3 gross amount');
check(array() === WC_Tax::$rate_calls, 'Tax disabled does not request tax rates');

$existing_display = array(array('key' => 'Existing', 'value' => 'Keep'));
check($existing_display === $wrap->display_cart_data($existing_display, array()), 'Plain cart item has no gift metadata');
$unsafe = array('ck_ows_gift_wrap' => array('price' => 5.5, 'message' => "<img src=x onerror=alert(1)>\nTom & Jerry"));
$display = $wrap->display_cart_data($existing_display, $unsafe);
check(3 === count($display) && $existing_display[0] === $display[0], 'Gift display preserves existing metadata and appends two rows');
check('Gift wrapping' === ($display[1]['key'] ?? '') && wc_price(5.5) . ' per item (incl. tax)' === ($display[1]['value'] ?? ''), 'Cart display shows unit price including tax');
check('Gift tag message' === ($display[2]['key'] ?? '') && $unsafe['ck_ows_gift_wrap']['message'] === ($display[2]['value'] ?? ''), 'Cart message has labeled raw value');
check("&lt;img src=x onerror=alert(1)&gt;<br />\nTom &amp; Jerry" === ($display[2]['display'] ?? ''), 'Cart display escapes unsafe HTML and preserves line breaks');
$empty = array('ck_ows_gift_wrap' => array('price' => 5.5, 'message' => ''));
check(1 === count($wrap->display_cart_data(array(), $empty)), 'Empty message omits cart message row');

$order = new WC_Order();
$item = new WC_Order_Item_Product();
$wrap->save_order_data($item, 'plain', array(), $order);
check(array() === $item->metadata, 'Plain order item has no gift metadata');
$wrap->save_order_data($item, 'wrapped', $lines['wrapped'], $order);
check(array(
	array('key' => 'Gift wrapping', 'value' => 'Yes — €5.50 per item (incl. tax); charged separately', 'unique' => true),
	array('key' => 'Gift tag message', 'value' => $expected_wrap['message'], 'unique' => true),
	array('key' => '_ck_ows_gift_wrap_unit_price_incl_tax', 'value' => 5.5, 'unique' => true),
) === $item->metadata, 'Order stores public plain-text wrapping/message and private unit price in order currency');
$item = new WC_Order_Item_Product();
$wrap->save_order_data($item, 'empty', $empty, $order);
check(array('Gift wrapping', '_ck_ows_gift_wrap_unit_price_incl_tax') === array_column($item->metadata, 'key'), 'Empty message omits order message metadata');

if (! empty($failures)) {
	foreach ($failures as $failure) {
		fwrite(STDERR, '[FAIL] ' . $failure . "\n");
	}
	fwrite(STDERR, count($failures) . ' failures across ' . $assertions . " assertions.\n");
	exit(1);
}

fwrite(STDOUT, 'Gift wrap behavior tests passed (' . $assertions . " assertions).\n");
exit(0);
