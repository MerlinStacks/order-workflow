# Architecture

## Bootstrap Flow

1. `ck-order-workflow-suite.php` loads on plugin activation.
2. Declares HPOS compatibility via `before_woocommerce_init`.
3. Validates WooCommerce availability on `plugins_loaded`.
4. Loads `CK_OWS_Plugin` and boots all modules.

## Module Map

- `CK_OWS_Statuses`
  - Registers custom statuses.
  - Injects statuses into WooCommerce status list.
- `CK_OWS_Admin_Order_Actions`
  - Adds row quick actions and bulk actions.
  - Handles secure status updates and admin notices.
- `CK_OWS_Customer_Shipping_Edit`
  - Renders shipping edit form on My Account order view.
  - Saves shipping details for processing orders only.
- `CK_OWS_Account_Invoices`
  - Adds `invoices` endpoint and menu entry.
  - Renders customer invoice list with fallback actions.
- `CK_OWS_Registration_Guard`
  - Reserved module for anti-bot registration controls.
- `CK_OWS_Shortcodes`
  - Registers `[order_tracking_summary]` and `[wc_invoice_link]`.
- `CK_OWS_Order_Timeline`
  - Captures stage timestamps and renders order progress timeline.
- `CK_OWS_Account_Order_Cards`
  - Replaces orders endpoint output with card list UI.
- `CK_OWS_Address_Quality`
  - Applies postcode/suburb validation on My Account saves.
- `CK_OWS_Account_Security`
  - Adds `security` endpoint and account activity panel.
- `CK_OWS_Account_Email_Preferences`
  - Adds `email-preferences` endpoint for customer subscription controls.
  - Syncs preferences with OverSeek Email Preferences API.
- `CK_OWS_Artwork_Proof`
  - Handles proof upload, customer approval/change request, and production gate.
- `CK_OWS_Tracking`
  - Runs scheduled/manual AusPost tracking sync.
  - Stores normalized live tracking payload and emits update hook.
- `CK_OWS_Settings`
  - Provides WooCommerce admin settings and manual sync trigger.
- `CK_OWS_Events`
  - Reserved module for expanded event orchestration.
- `CK_OWS_Helpers`
  - Reserved shared helper utilities.

## Key Data Paths

### Artwork approval

1. Admin uploads proof PDF on order.
2. Order meta updated with proof file reference and `pending` state.
3. Order auto-moves to `awaiting-artwork-approval`.
4. Customer approves or requests changes from My Account.
5. Production transition allowed only with customer approval or staff override reason.

### Tracking sync

1. Cron/manual trigger calls `sync_tracking_data()`.
2. Module extracts shipment tracking numbers from order meta.
3. Module requests AusPost API data.
4. Latest tracking payload saved to order meta.
5. If payload changed, emit `ck_ows_tracking_updated`.

### Timeline

1. Status change hook maps status to stage timestamp meta.
2. My Account order view renders stages and stage state.
3. Artwork stage appears only for proof-enabled orders.

## State and Metadata

Status states:

- `wc-in-production`
- `wc-in-dispatch`
- `wc-awaiting-artwork-approval`

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
