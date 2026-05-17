# Release Checklist

## Pre-Release Setup

- Confirm WooCommerce is active.
- Confirm plugin is active.
- Flush permalinks once (`Settings -> Permalinks -> Save Changes`).
- Disable legacy snippets that overlap with this plugin.

## Admin Workflow Validation

- Order statuses visible in admin:
  - In Production
  - In Dispatch
  - Awaiting Artwork Approval
- Quick row actions appear and move orders to the intended status.
- Order-detail workflow quick actions appear and work separately from the artwork proof box.
- Bulk actions work on both:
  - classic orders table
  - HPOS orders table
- Admin notices appear for successful updates.

## Artwork Proof Validation

- Upload proof PDF on order edit page.
- Confirm order auto-moves to Awaiting Artwork Approval.
- Confirm proof link displays in order metabox.
- Confirm customer sees proof panel on My Account order view.
- Confirm customer can:
  - Approve artwork
  - Request changes with note
- Confirm production gate blocks move to In Production without approval.
- Confirm staff override requires reason and writes order note.

## Customer Account Validation

- Invoices endpoint works and appears in menu.
- Security endpoint works and appears in menu.
- Order cards render with:
  - status badge
  - item preview
  - total
  - invoice and tracking actions when available
- Timeline renders with expected stages and timestamps.
- Processing-order shipping address edit form appears and saves.

## Tracking Validation

- Add valid AusPost API key in settings.
- Enable tracking sync and set interval.
- Run manual `Run tracking sync now`.
- Confirm order meta updates with live tracking payload.
- Confirm tracking panel appears on My Account order view.
- Confirm `ck_ows_tracking_updated` fires when payload changes.

## Registration Guard Validation

- Honeypot/timing fields injected on My Account registration form.
- Simulated bot attempts are blocked.
- CK Order Workflow -> Registration Guard records blocked attempts.
- Clear log action works.

## Regression Checks

- `[order_tracking_summary]` shortcode output still works.
- `[wc_invoice_link]` shortcode output still works.
- No duplicate endpoint/menu output after snippet decommission.

## Final QA

- Run PHP lint on all plugin files.
- Validate key flows for both admin and customer roles.
- Capture screenshots for internal handoff.
