# Architecture

## Bootstrap Flow

1. `ck-order-workflow-suite.php` loads on plugin activation.
2. Declares HPOS compatibility via `before_woocommerce_init`.
3. Validates WooCommerce availability on `plugins_loaded`.
4. Loads `CK_OWS_Plugin`, registers its constrained autoloader, and boots only the status module.
5. Loads account, admin, tracking, event, and workflow modules only when their request context or proxy hook runs.

## Request-Scoped Loading

- Steady-state storefront requests load the bootstrap, singleton base, and status module. The hourly tracking schedule check is the only periodic exception.
- My Account navigation is built by the bootstrap; endpoint classes load only for their matching endpoint.
- Order-detail modules load only for View Order and authenticated thank-you requests.
- Settings and order-management classes load only on their admin page, WooCommerce order screen, or matching action handler.
- Tracking workers, webhook handlers, registration checks, timeline capture, and artwork gating use lightweight proxy hooks that autoload their module only when the corresponding event occurs.
- Shortcode classes load only when one of the plugin shortcodes is rendered.

## Module Map

- `CK_OWS_Statuses`
  - Registers custom statuses.
  - Injects statuses into WooCommerce status list.
- `CK_OWS_Admin_Order_Actions`
  - Adds row quick actions, order-detail quick actions, and bulk actions.
  - Handles secure status updates and admin notices.
- `CK_OWS_Customer_Shipping_Edit`
  - Renders shipping edit form on My Account order view.
  - Saves shipping details for processing orders only.
- `CK_OWS_Account_Invoices`
  - Renders the bootstrap-registered `invoices` endpoint.
  - Renders customer invoice list with fallback actions.
- `CK_OWS_Registration_Guard`
  - Adds WooCommerce and WordPress registration anti-bot controls.
  - Provides an admin block log for review and cleanup.
- `CK_OWS_Shortcodes`
  - Implements lazy callbacks for `[order_tracking_summary]` and `[wc_invoice_link]`.
- `CK_OWS_Order_Timeline`
  - Captures stage timestamps and renders order progress timeline.
- `CK_OWS_Account_Order_Cards`
  - Replaces orders endpoint output with card list UI.
- `CK_OWS_Address_Quality`
  - Applies postcode/suburb validation on My Account saves.
- `CK_OWS_Account_Security`
  - Renders the security endpoint and tracks account activity events.
- `CK_OWS_Account_Email_Preferences`
  - Renders the email preferences endpoint for customer subscription controls.
  - Syncs preferences with OverSeek Email Preferences API.
- `CK_OWS_Artwork_Proof`
  - Handles proof upload, customer approval/change request, and production gate.
- `CK_OWS_Tracking`
  - Runs scheduled/manual AusPost tracking sync.
  - Stores normalized live tracking payload and emits update hook.
- `CK_OWS_Tracking_Email_Events`
  - Listens for tracking updates and forwards lifecycle events to email platform webhook.
  - Emits success/failure hooks for delivery observability.
- `CK_OWS_Settings`
  - Provides WooCommerce admin settings and manual sync trigger.

## Key Data Paths

### Artwork approval

1. Admin uploads proof PDF on order.
2. Order meta updated with proof file reference and `pending` state.
3. Order auto-moves to `awaiting-artwork`.
4. Customer approves or requests changes from My Account.
5. Production transition allowed only with customer approval or staff override reason.

### Tracking sync

1. Cron/manual trigger calls `sync_tracking_data()`.
2. Module extracts shipment tracking numbers from order meta.
3. Module requests AusPost API data.
4. Latest tracking payload saved to order meta.
5. If payload changed, emit `ck_ows_tracking_updated`.
6. Optional event forwarder posts mapped shipment lifecycle event to email platform webhook.

### Timeline

1. Status change hook maps status to stage timestamp meta.
2. My Account order view renders stages and stage state.
3. Artwork stage appears only for proof-enabled orders.

## State and Metadata

Status states:

- `wc-in-production`
- `wc-in-dispatch`
- `wc-awaiting-artwork`

Primary metadata groups:

- Artwork proof and approval state.
- Timeline timestamps.
- Tracking payload, sync timestamp, sync error, payload hash.
- Account security activity timestamps.

## Compatibility Notes

- Designed for HPOS-compatible WooCommerce environments.
- Fallback behaviors when optional plugins are absent:
  - invoice button fallback to order view,
  - tracking fallback to direct link generation where possible.
