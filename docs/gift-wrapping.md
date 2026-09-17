# Gift wrapping

## Setup

1. Open **CK Workflow → Gift Wrapping** and enable the feature.
2. Enter one existing product tag **slug**, for example `gift-wrap`.
3. Enter the tax-inclusive price per wrapped unit in the store currency.
4. Assign that tag to eligible simple products or variable parent products.
5. Disable the previous gift-wrap add-on for those products to avoid duplicate controls and charges. This module does not migrate third-party cart data.

The classic WooCommerce product add-to-cart form displays a responsive gift card. Gift wrapping is opt-in. An optional, text-only handwritten message supports up to 200 characters and is shared across the units on that cart line. Different messages or wrapped/unwrapped selections create separate cart lines.

## Pricing and orders

- Quantity 3 at $5.50 means $16.50 of wrapping, including tax for taxable customers.
- A native WooCommerce fee per wrapped cart line follows quantity updates and disappears when that line is removed. Coupons do not discount fees.
- Wrapping uses the **standard tax class**, independently of the product's tax class. The inclusive amount is converted to net using the customer's applicable rates, regardless of whether catalog prices are entered inclusive or exclusive. WooCommerce applies tax exemptions and rounding normally; exempt customers do not pay the tax component.
- The configured price is snapshotted when added to the cart; changing settings does not reprice existing selections. Disabling wrapping prevents new selections but honours existing cart selections.
- Order product items store public `Gift wrapping` and `Gift tag message` metadata plus a private numeric `_ck_ows_gift_wrap_unit_price_incl_tax` snapshot. Native order fee lines hold the charge and taxes. These work with HPOS, backend order details, emails, and the WooCommerce REST API.
- Fees are separate from product lines: when refunding wrapping, refund the corresponding fee explicitly. Administrative product quantity changes after checkout do not automatically resize wrapping fees.

## Overseek release check

This repository provides invoice links, not the Overseek PDF renderer. The charge is available as a standard order fee and gift details as public line-item metadata. **Actual Overseek invoice rendering remains unverified.** Before releasing, generate an invoice for a wrapped quantity-3 order and confirm its template includes fee lines and both metadata fields. If it omits either, its external template/integration needs updating.

## Verification

`php tests/smoke/gift-wrap.php` runs isolated behavioral tests. `npm run test:gift-wrap` runs real WooCommerce integration tests on the disposable wp-env site; CI also runs them in the HPOS matrix.

On staging, check desktop and 320px/375px mobile product forms, variable products, keyboard operation, messages, cart quantity changes, tax addresses/exemptions, checkout and invoices. The selector targets classic product forms; block-only product add-to-cart interfaces and third-party quick views need separate integration testing. Cart/checkout fees use WooCommerce's native lifecycle, but Store API checkout has not been verified here.
