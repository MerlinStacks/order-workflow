# Hooks and Events

## Core WordPress/WooCommerce Hooks Used

- `before_woocommerce_init`: HPOS compatibility declaration.
- `plugins_loaded`: plugin bootstrap after WooCommerce check.
- `init`: register statuses and account endpoints.
- `woocommerce_order_status_changed`: timeline stage timestamps and artwork approval gate.
- `woocommerce_admin_order_actions`: add quick status actions.
- `bulk_actions-edit-shop_order`: register bulk status actions (classic orders table).
- `bulk_actions-woocommerce_page_wc-orders`: register bulk status actions (HPOS orders table).
- `handle_bulk_actions-edit-shop_order`: process classic bulk actions.
- `handle_bulk_actions-woocommerce_page_wc-orders`: process HPOS bulk actions.
- `woocommerce_order_details_after_order_table`: render timeline, tracking, artwork panel, shipping edit form.
- `woocommerce_before_edit_account_address_form`: render account address notice.
- `woocommerce_after_save_address_validation`: apply postcode/suburb quality checks.
- `woocommerce_register_form`: inject registration guard honeypots and timing token.
- `woocommerce_process_registration_errors`: validate registration guard checks.
- `wp_login`: track last login timestamp.
- `after_password_reset`: track password reset timestamp.
- `woocommerce_save_account_details`: track password changes from account details.
- `cron_schedules`: add custom tracking sync schedule.

## Custom Action Hooks Emitted

### `ck_ows_tracking_updated`

Fires when live tracking payload hash changes after a sync run.

Arguments:

1. `int $order_id`
2. `array $payload`

Payload structure (normalized):

```php
array(
  'provider'        => 'auspost',
  'tracking_number' => 'ABC123456789',
  'status'          => 'in_transit',
  'last_event'      => array(
    'description' => 'Processed at facility',
    'date'        => '2026-05-12T09:20:00+10:00',
    'location'    => 'Melbourne VIC',
  ),
  'eta'             => '2026-05-14',
  'raw'             => array( /* original provider payload slice */ ),
)
```

### `ck_ows_tracking_event_delivered`

Fires after a tracking lifecycle event is successfully forwarded to the configured email platform webhook.

Note: HTTP `202` responses are treated as successful delivery, including platform responses where `accepted` is false and `skipped` is true.

Arguments:

1. `int $order_id`
2. `array $event`
3. `int $http_status_code`

### `ck_ows_tracking_event_delivery_failed`

Fires when forwarding a tracking lifecycle event fails.

Arguments:

1. `int $order_id`
2. `array $event`
3. `string $reason`

## Automation Examples

### Send external email on artwork approval status

Use WooCommerce status transition trigger:

- from any -> `awaiting-artwork-approval`

### Trigger webhook when tracking changes

```php
add_action( 'ck_ows_tracking_updated', function ( int $order_id, array $payload ): void {
    wp_remote_post( 'https://example-automation-endpoint.test/webhook', array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'event'    => 'tracking_updated',
            'order_id' => $order_id,
            'payload'  => $payload,
        ) ),
        'timeout' => 10,
    ) );
}, 10, 2 );
```

### Observe delivery outcomes for email-platform forwarding

```php
add_action( 'ck_ows_tracking_event_delivery_failed', function ( int $order_id, array $event, string $reason ): void {
    error_log( sprintf( 'Tracking event delivery failed for order %d: %s', $order_id, $reason ) );
}, 10, 3 );
```

## Internal Order Meta Keys (Selected)

- Artwork proof:
  - `_ck_ows_artwork_proof_id`
  - `_ck_ows_artwork_proof_url`
  - `_ck_ows_artwork_approval_state`
  - `_ck_ows_artwork_override_reason`
- Timeline:
  - `_ck_ows_ts_processing`
  - `_ck_ows_ts_awaiting_artwork_approval`
  - `_ck_ows_ts_in_production`
  - `_ck_ows_ts_in_dispatch`
  - `_ck_ows_ts_delivered`
- Tracking:
  - `_ck_ows_live_tracking`
  - `_ck_ows_live_tracking_last_sync`
  - `_ck_ows_live_tracking_last_error`
