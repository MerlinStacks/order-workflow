# Client Quickstart

## What this plugin does

CK WooCommerce Order Workflow Suite adds custom order flow, customer artwork approvals, richer My Account pages, and shipment tracking support.

## Initial setup (10 minutes)

1. Activate plugin in `Plugins`.
2. Go to `WooCommerce -> CK Workflow`.
3. Add your AusPost API key.
4. Enable tracking sync and keep interval at `6` hours to start.
5. Save settings.
6. Click `Run tracking sync now` once.
7. Go to `Settings -> Permalinks` and click `Save Changes`.

## Daily team workflow

1. Upload proof PDF when an order needs approval.
2. Order automatically moves to `Awaiting Artwork Approval`.
3. Customer approves or requests changes from their account page.
4. Move order to `In Production` once approved.
5. Move order to `In Dispatch` when shipped.

## Important rules

- Orders with artwork proof cannot move to `In Production` without customer approval.
- Staff can override this rule, but a reason is mandatory.
- Customers can edit shipping address only while order is `Processing`.

## Where customers see updates

- My Account `Orders`: card view with status, item preview, invoice/tracking links.
- My Account `Invoices`: invoice list and PDF links.
- Order view page: timeline, proof approval panel (if applicable), and tracking details.
- My Account `Security`: last login and password-change activity.

## If something looks wrong

- Check `WooCommerce -> CK Workflow` API key settings.
- Run `Run tracking sync now`.
- Confirm old snippets are disabled.
- Flush permalinks again.
