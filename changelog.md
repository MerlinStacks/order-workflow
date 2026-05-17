# Changelog

## 0.1.5 - 2026-05-17

### Fixed

- Fixed admin quick status action icons and safer status-change redirects/notices.
- Added separate workflow quick actions on the admin order detail page.

## 0.1.4 - 2026-05-15

### Added

- Added uninstall cleanup support with configurable keep-data toggle.
- Added operational settings for webhook retries and retry backoff.
- Added webhook retry scheduler with dead-letter queue capture for failed deliveries.
- Added settings import/export actions and connection test actions in admin.
- Added diagnostics panel with cron schedule visibility, webhook health, and recent audit events.
- Added audit logging for sensitive order workflow actions.

### Changed

- Extended smoke security contracts to cover new privileged admin actions.

## 0.1.3 - 2026-05-15

### Changed

- Fixed My Account login/register layout sizing so form cards no longer render as narrow columns.
- Centered Flatsome popup auth panel and removed empty side space in login-only mode.
- Added popup login/register toggle behavior to show one form at a time.

## 0.1.2 - 2026-05-15

### Changed

- Refined WooCommerce login and registration form styling for better alignment with plugin account UI.
- Added Flatsome-compatible styling support for popup login/register forms.
- Added a register CTA button/link on login forms when account registration is enabled.
- Extended registration guard coverage to native WordPress registration forms.

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
