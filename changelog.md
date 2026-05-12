# Changelog

## 0.1.0 - 2026-05-12

### Added

- Initial plugin scaffold and bootstrap.
- HPOS compatibility declaration.
- Module class placeholders for planned roadmap features.
- Documentation scaffold (`readme.txt`, `docs/`, `roadmap.md`).
- Custom WooCommerce statuses: In Production, In Dispatch, Awaiting Artwork Approval.
- Admin quick actions and bulk actions for custom status updates.
- Customer processing-order shipping address update form and secure save handler.
- My Account invoices endpoint, menu tab, and address-page notice.
- Backward-compatible shortcodes: `[order_tracking_summary]` and `[wc_invoice_link]`.
- Artwork proof workflow module with order-level PDF upload, customer approval actions, mandatory production gate, and staff override with mandatory reason.
- Customer order timeline with stage timestamps, delivered mapping, and conditional artwork stage display.
- Enhanced My Account orders endpoint with card-based order list including thumbnails, key item preview, status badge, invoice action, and tracking action.
- Address quality checks for My Account saves (postcode/suburb validation with warning-first behavior).
- My Account Security endpoint with last login tracking, password-change tracking, and account safety panel.
- WooCommerce admin settings page for AusPost API configuration and tracking sync options.
- Scheduled AusPost tracking sync with per-order live tracking meta, customer tracking panel, and `ck_ows_tracking_updated` automation hook.
