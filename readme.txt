=== CK WooCommerce Order Workflow Suite ===
Contributors: ck
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Custom WooCommerce order workflow plugin for statuses, artwork approvals, account UX, and tracking.

== Description ==

CK WooCommerce Order Workflow Suite centralizes custom order workflow and customer account enhancements in one plugin.

Current feature set includes:

- Custom statuses: In Production, In Dispatch, Awaiting Artwork Approval.
- Admin quick actions and bulk actions for status updates.
- Artwork proof workflow with mandatory customer approval gate before production.
- Staff override for artwork gate with mandatory reason and audit notes.
- Customer shipping address edits on processing orders.
- My Account upgrades: invoices tab, order timeline, order cards, security panel.
- My Account email preferences endpoint backed by OverSeek API.
- Registration guard anti-bot protections.
- Shipment tracking display with AusPost live sync support.
- Optional forwarding of tracking lifecycle events to email platform webhooks.

For external automations, custom statuses and tracking update hooks are available.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate WooCommerce.
3. Activate CK WooCommerce Order Workflow Suite.
4. Go to `WooCommerce -> CK Workflow` and add your AusPost API key.
5. Save settings and optionally run `Run tracking sync now`.
6. Flush permalinks once (`Settings -> Permalinks -> Save`) to register account endpoints.

== My Account Features ==

- `Invoices` endpoint with PDF invoice links when WPO_WCPDF is available.
- Card-based orders list with item previews, status badges, invoice and tracking actions.
- Order progress timeline including conditional Artwork Approval stage.
- `Security` endpoint with last login and password change activity.
- `Email Preferences` endpoint with global/marketing flags and list memberships.

== Artwork Approval Flow ==

1. Staff uploads an artwork PDF on the order.
2. Order moves to Awaiting Artwork Approval.
3. Customer approves or requests changes in My Account.
4. Order can move to In Production only when approved, unless staff override is used.

== Tracking ==

- Supports shipment links from Woo shipment tracking meta.
- Supports AusPost API sync for live tracking details.
- Emits `ck_ows_tracking_updated` when tracked data changes.
- Can forward normalized shipment lifecycle events to an external webhook.

== Legacy Snippet Migration ==

After enabling this plugin, disable legacy code snippets that overlap with:

- Invoices endpoint/menu.
- Address notice.
- Registration guard.
- Order tracking and invoice shortcodes.

Keeping old snippets active can cause duplicate output and duplicate hooks.

== Changelog ==

See `changelog.md`.

== Documentation ==

- `docs/client-quickstart.md` for store owner onboarding.
- `docs/release-checklist.md` for pre-launch QA.
- `docs/hooks-and-events.md` for automation/webhook integration.
- `docs/tracking-email-events.md` for developer integration with email platforms.
