# Configuration

## Requirements

- WordPress 6.4+
- PHP 8.0+
- WooCommerce 8.0+

Optional integrations:

- WooCommerce PDF Invoices plugin (`WPO_WCPDF`) for invoice download links.
- WooCommerce shipment tracking meta (`_wc_shipment_tracking_items`) for tracking links and numbers.

## Settings Screen

Navigate to:

- `WooCommerce -> CK Workflow`

## Settings Reference

All settings are saved in one option key:

- `ck_ows_settings`

Fields:

1. `auspost_api_key`
   - Type: text
   - Required for live AusPost API sync.
2. `auspost_account_number`
   - Type: text
   - Optional currently, retained for account-level API scenarios.
3. `tracking_sync_enabled`
   - Type: boolean (`yes` or `no`)
   - Default: `yes`
4. `tracking_sync_interval_hours`
   - Type: integer
   - Range: `1` to `24`
   - Default: `6`
5. `tracking_email_events_enabled`
   - Type: boolean (`yes` or `no`)
   - Default: `no`
   - Enables forwarding normalized tracking lifecycle events to webhook.
6. `tracking_email_events_webhook_url`
   - Type: text (HTTPS URL)
   - Required only when tracking event forwarding is enabled.
7. `tracking_email_events_auth_token`
   - Type: text
   - Optional bearer token used for webhook authorization header.
8. `tracking_email_events_timeout_seconds`
   - Type: integer
   - Range: `3` to `30`
   - Default: `10`

## Manual Tracking Sync

The settings page provides a manual action:

- `Run tracking sync now`

Use this after changing API credentials or adding tracking numbers to confirm immediate results.

## Endpoint Registration

This plugin adds My Account endpoints:

- `invoices`
- `security`

After first activation (or if endpoint routes fail), flush permalinks once:

- `Settings -> Permalinks -> Save Changes`

## Address Validation Policy

Address quality checks on My Account save:

- Hard errors for missing suburb/city and missing postcode.
- Hard errors for AU/NZ postcodes not matching 4-digit format.
- Warning notices for suspiciously short values.

## Artwork Approval Policy

- Proof-enabled orders entering production require customer approval.
- Staff override is allowed only with mandatory reason.
- Override and approval actions are logged on order notes/meta.

## Security and Permissions

- Admin settings require `manage_woocommerce` capability.
- Order admin actions require `edit_shop_orders` capability.
- Customer-facing order actions require:
  - logged-in customer,
  - ownership of target order,
  - valid nonce.
