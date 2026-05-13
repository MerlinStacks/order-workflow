# Tracking Email Events Integration

## Purpose

This integration forwards normalized Australia Post tracking lifecycle events to your email platform webhook so your team can trigger shipment automations outside WooCommerce.

## How It Works

1. `CK_OWS_Tracking` syncs latest AusPost data per order.
2. If payload hash changed, plugin emits `ck_ows_tracking_updated`.
3. `CK_OWS_Tracking_Email_Events` listens to that hook.
4. It normalizes status to a lifecycle event and POSTs JSON to your webhook.
5. Duplicate events are suppressed for 7 days via transient idempotency key.

## Settings

In `WooCommerce -> CK Workflow -> Tracking` configure:

- `Forward tracking events to email platform` (checkbox)
- `Email platform webhook URL` (HTTPS required)
- `Webhook auth token (optional)` (sent as `Authorization: Bearer {token}`)
- `Webhook timeout (seconds)` (3 to 30, default 10)

For your email platform API, use:

- URL format: `https://<host>/api/tracking-email-events/{accountId}`
- Method: `POST`
- Content-Type: `application/json`

## Event Mapping

Raw status and latest event description are mapped to:

- `in_transit`
- `out_for_delivery`
- `delivery_attempted`
- `delivered`
- `exception`

If no known status can be inferred, no event is dispatched.

## Webhook Payload

Requests are `POST` with `Content-Type: application/json`.

Important: send the exact object under `event` (matching CK OWS sender contract).

```json
{
  "event": {
    "event_name": "shipment_out_for_delivery",
    "event_status": "out_for_delivery",
    "provider": "auspost",
    "order_id": 1234,
    "order_number": "1234",
    "tracking_number": "AB123456789AU",
    "occurred_at": "2026-05-13T08:21:00+10:00",
    "location": "Brisbane QLD",
    "description": "Out for delivery",
    "eta": "2026-05-14",
    "customer_email": "customer@example.com",
    "customer_phone": "0400000000",
    "customer_name": "Jane Example",
    "order_total": "189.95",
    "order_currency": "AUD",
    "order_status": "in-dispatch",
    "source": "ck_order_workflow_suite",
    "source_version": "0.1.0"
  }
}
```

## Platform Contract (Confirmed)

- Feature gate: account must have `TRACKING_EMAIL_EVENTS` enabled.
  - If disabled/missing: API returns `403`.
- Optional bearer auth:
  - If account has `webhookAuthToken` configured, sender must include `Authorization: Bearer {token}`.
  - If missing/incorrect: API returns `401`.
- Accepted status values:
  - `in_transit`
  - `out_for_delivery`
  - `delivery_attempted`
  - `delivered`
  - `exception`
- Response behavior:
  - `202 { success: true, accepted: true, triggerType: ... }` when accepted.
  - `202 { success: false, skipped: true, reason: "unsupported_event_status" }` for unsupported statuses.
  - `400` for malformed payload (for example missing `event`).
  - `401` for required auth failures.
  - `403` for feature-disabled accounts.

Implementation note: CK OWS should treat HTTP `202` as success, including skipped responses.

## Idempotency and Dedupe

The plugin creates an idempotency key from:

- `order_id`
- `tracking_number`
- `event_status`
- `occurred_at`

If the same key is seen again, event delivery is skipped.

## Operational Hooks

Use these hooks for observability:

- `ck_ows_tracking_event_delivered( int $order_id, array $event, int $http_status_code )`
- `ck_ows_tracking_event_delivery_failed( int $order_id, array $event, string $reason )`

Example:

```php
add_action( 'ck_ows_tracking_event_delivered', function ( int $order_id, array $event, int $http_status_code ): void {
    error_log( sprintf( 'Tracking event delivered for order %d with HTTP %d', $order_id, $http_status_code ) );
}, 10, 3 );
```

## Developer Notes

- The webhook URL must be HTTPS.
- Failed sends are surfaced through `ck_ows_tracking_event_delivery_failed`.
- If you need queue-based retries, keep this sender as-is and implement a listener on failed hook to enqueue retries in your infrastructure.
- Existing 7-day idempotency in CK OWS should remain enabled; this endpoint expects sender-side dedupe.

## Integration Checklist (Copy/Paste)

1. Enable feature gate on target account:
   - `AccountFeature.featureKey = TRACKING_EMAIL_EVENTS`
   - `AccountFeature.isEnabled = true`
2. Configure plugin webhook URL:
   - `tracking_email_events_webhook_url = https://<host>/api/tracking-email-events/{accountId}`
3. Configure plugin sender toggle:
   - `tracking_email_events_enabled = yes`
4. If account requires auth token:
   - Set `AccountFeature.config.webhookAuthToken` on platform side.
   - Set matching `tracking_email_events_auth_token` in plugin.
5. Keep timeout sensible:
   - `tracking_email_events_timeout_seconds = 10` (recommended default)
6. Verify payload contract:
   - Request body includes top-level `event` object.
   - `event.event_status` is one of: `in_transit`, `out_for_delivery`, `delivery_attempted`, `delivered`, `exception`.
7. Verify response handling:
   - Treat HTTP `202` as success (both accepted and skipped variants).
   - Treat `400/401/403` as failures requiring investigation.
8. Verify sender dedupe:
   - Leave CK OWS 7-day idempotency enabled.
9. Smoke test end-to-end:
   - Run `Run tracking sync now` in admin.
   - Confirm platform receives `POST /api/tracking-email-events/{accountId}`.
   - Confirm automation trigger logs match expected `event_status`.
