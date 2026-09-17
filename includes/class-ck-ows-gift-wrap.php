<?php
/**
 * Per-unit gift wrapping using native WooCommerce fees and order item metadata.
 *
 * @package CK_Order_Workflow_Suite
 */

defined( 'ABSPATH' ) || exit;

class CK_OWS_Gift_Wrap extends CK_OWS_Base {

	protected function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Render after quantity and other options, immediately before the cart button.
		add_action( 'woocommerce_after_add_to_cart_quantity', array( $this, 'render_option' ), PHP_INT_MAX );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_data' ), 10, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'calculate_fees' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_data' ), 10, 4 );
	}

	/** Variations inherit the tag on their parent product. */
	public function eligible( int $product_id ): bool {
		$tag = (string) CK_OWS_Settings::get( 'gift_wrap_tag', '' );
		if ( 'yes' !== CK_OWS_Settings::get( 'gift_wrap_enabled', 'no' ) || '' === $tag ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		return $product && $product->is_type( array( 'simple', 'variable', 'variation' ) )
			&& has_term( $tag, 'product_tag', $product->get_parent_id() ?: $product_id );
	}

	public function enqueue_assets(): void {
		if ( ! is_product() || ! $this->eligible( (int) get_queried_object_id() ) ) {
			return;
		}
		wp_enqueue_style( 'ck-ows-gift-wrap', CK_OWS_URL . 'assets/css/gift-wrap.css', array(), CK_OWS_VERSION );
		wp_enqueue_script( 'ck-ows-gift-wrap', CK_OWS_URL . 'assets/js/gift-wrap.js', array(), CK_OWS_VERSION, true );
	}

	public function render_option(): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! $this->eligible( $product->get_id() ) ) {
			return;
		}
		$id = wp_unique_id( 'ck-gift-wrap-' );
		?>
		<div class="ck-ows-gift-wrap">
			<label class="ck-ows-gift-wrap__choice" for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" name="ck_ows_gift_wrap" value="yes" id="<?php echo esc_attr( $id ); ?>" aria-controls="<?php echo esc_attr( $id . '-message' ); ?>">
				<svg class="ck-ows-gift-wrap__icon" viewBox="0 0 40 40" fill="none" aria-hidden="true" focusable="false"><rect x="7" y="17" width="26" height="18" rx="2" fill="#292720"/><rect x="5" y="12" width="30" height="7" rx="2" fill="#45413a"/><path d="M20 12v23M7 24h26" stroke="#d7b867" stroke-width="3"/><path d="M20 12C8 12 9 2 15 5c3 2 5 7 5 7Zm0 0c12 0 11-10 5-7-3 2-5 7-5 7Z" stroke="#b18d3e" stroke-width="2" stroke-linejoin="round"/></svg>
				<span class="ck-ows-gift-wrap__heading"><strong><?php esc_html_e( 'Make it a gift', 'ck-order-workflow-suite' ); ?></strong><span><?php esc_html_e( 'Add gift wrapping', 'ck-order-workflow-suite' ); ?></span></span>
			</label>
			<p class="ck-ows-gift-wrap__description"><?php esc_html_e( 'Beautifully wrapped in black paper, finished with gold satin ribbon.', 'ck-order-workflow-suite' ); ?></p>
			<div class="ck-ows-gift-wrap__message" id="<?php echo esc_attr( $id . '-message' ); ?>">
				<label for="<?php echo esc_attr( $id . '-text' ); ?>"><?php esc_html_e( 'Gift tag message', 'ck-order-workflow-suite' ); ?> <span><?php esc_html_e( '(optional)', 'ck-order-workflow-suite' ); ?></span></label>
				<textarea id="<?php echo esc_attr( $id . '-text' ); ?>" name="ck_ows_gift_message" rows="3" maxlength="200" aria-describedby="<?php echo esc_attr( $id . '-hint' ); ?>" placeholder="<?php esc_attr_e( 'A little note to make their day…', 'ck-order-workflow-suite' ); ?>"></textarea>
				<small id="<?php echo esc_attr( $id . '-hint' ); ?>"><?php esc_html_e( 'Handwritten with care. Please use text only, no emojis. Up to 200 characters.', 'ck-order-workflow-suite' ); ?></small>
			</div>
		</div>
		<?php
	}

	private function price(): float {
		return max( 0, (float) CK_OWS_Settings::get( 'gift_wrap_price', '0' ) );
	}

	/** These public cart fields never supply a price or eligibility decision. */
	private function selected(): bool {
		return isset( $_POST['ck_ows_gift_wrap'] ) && 'yes' === $_POST['ck_ows_gift_wrap'];
	}

	private function message(): string {
		return isset( $_POST['ck_ows_gift_message'] ) && is_string( $_POST['ck_ows_gift_message'] )
			? sanitize_textarea_field( wp_unslash( $_POST['ck_ows_gift_message'] ) ) : '';
	}

	public function validate( bool $valid, int $product_id, $quantity ): bool {
		if ( ! $this->selected() ) {
			return $valid;
		}
		if ( ! $this->eligible( $product_id ) ) {
			wc_add_notice( __( 'Gift wrapping is not available for this product.', 'ck-order-workflow-suite' ), 'error' );
			return false;
		}
		$message = $this->message();
		if ( mb_strlen( $message ) > 200 || preg_match( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{20E3}]/u', $message ) ) {
			wc_add_notice( __( 'Please keep your gift message to 200 characters and remove emojis so we can handwrite it.', 'ck-order-workflow-suite' ), 'error' );
			return false;
		}
		return $valid;
	}

	/** WooCommerce persists this in its session and includes it in cart-line identity. */
	public function add_cart_data( array $data, int $product_id, int $variation_id ): array {
		if ( $this->selected() && $this->eligible( $variation_id ?: $product_id ) ) {
			$data['ck_ows_gift_wrap'] = array( 'price' => $this->price(), 'message' => $this->message() );
		}
		return $data;
	}

	public function display_cart_data( array $data, array $cart_item ): array {
		if ( isset( $cart_item['ck_ows_gift_wrap'] ) ) {
			$wrap = $cart_item['ck_ows_gift_wrap'];
			$data[] = array( 'key' => __( 'Gift wrapping', 'ck-order-workflow-suite' ), 'value' => sprintf( __( '%s per item (incl. tax)', 'ck-order-workflow-suite' ), wc_price( $wrap['price'] ) ) );
			if ( '' !== $wrap['message'] ) {
				$data[] = array( 'key' => __( 'Gift tag message', 'ck-order-workflow-suite' ), 'value' => $wrap['message'], 'display' => nl2br( esc_html( $wrap['message'] ) ) );
			}
		}
		return $data;
	}

	/** Rebuilt on every totals pass: no price mutation, compounding, or coupon discount. */
	public function calculate_fees( $cart ): void {
		foreach ( $cart->get_cart() as $key => $line ) {
			if ( ! isset( $line['ck_ows_gift_wrap'] ) ) {
				continue;
			}
			$gross = (float) $line['ck_ows_gift_wrap']['price'] * (float) $line['quantity'];
			$rates = wc_tax_enabled() ? WC_Tax::get_rates( '', $cart->get_customer() ) : array();
			$net   = $gross - array_sum( WC_Tax::calc_tax( $gross, $rates, true ) );
			$cart->fees_api()->add_fee( array(
				'id'        => 'ck_ows_gift_wrap_' . $key,
				'name'      => sprintf( __( 'Gift wrapping — %1$s × %2$s', 'ck-order-workflow-suite' ), $line['data']->get_name(), $line['quantity'] ),
				'amount'    => $net,
				'taxable'   => true,
				'tax_class' => '',
			) );
		}
	}

	/** Public metadata appears in admin, emails, REST and metadata-aware invoices. */
	public function save_order_data( $item, string $cart_item_key, array $values, $order ): void {
		if ( ! isset( $values['ck_ows_gift_wrap'] ) ) {
			return;
		}
		$wrap  = $values['ck_ows_gift_wrap'];
		$price = html_entity_decode( wp_strip_all_tags( wc_price( $wrap['price'], array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		$item->add_meta_data( 'Gift wrapping', sprintf( __( 'Yes — %1$s per item (incl. tax); charged separately', 'ck-order-workflow-suite' ), $price ), true );
		if ( '' !== $wrap['message'] ) {
			$item->add_meta_data( 'Gift tag message', $wrap['message'], true );
		}
		$item->add_meta_data( '_ck_ows_gift_wrap_unit_price_incl_tax', $wrap['price'], true );
	}
}
