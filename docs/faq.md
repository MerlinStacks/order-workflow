# FAQ

## Why do Invoices or Security pages return 404?

Flush permalinks once after activation:

- `Settings -> Permalinks -> Save Changes`

## Why can an order not move to In Production?

If an order has artwork proof and is in `Awaiting Artwork Approval`, it requires either:

- customer approval, or
- staff override with mandatory reason.

## Can we send external emails on custom statuses?

Yes. Custom statuses are real WooCommerce statuses and can be used in automation conditions.

## Can we send external emails on tracking changes?

Yes. Use custom action hook:

- `ck_ows_tracking_updated`

See `docs/hooks-and-events.md` for payload details.

## Do we need an AusPost API key?

- For live tracking sync: yes.
- For basic tracking links only: no.

## What should be disabled after plugin activation?

Disable legacy snippets that overlap with:

- invoices endpoint/menu,
- account address notice,
- registration guard,
- tracking and invoice shortcodes.

This avoids duplicate output and duplicate hooks.
