# Operations Runbook

## Daily Workflow

1. Check new orders in WooCommerce.
2. Move orders with proofs into `Awaiting Artwork Approval` (auto-set on proof upload).
3. Monitor approvals and change requests from order notes/customer actions.
4. Move approved orders to `In Production`, then `In Dispatch`.
5. Verify tracking updates are syncing for dispatched orders.

## Artwork Approval Operations

- Upload proof PDF from order edit screen in `Artwork Proof Approval` metabox.
- Customer approval is required before production for proof-enabled orders.
- If production must proceed without customer approval:
  - use staff override,
  - provide mandatory reason,
  - verify reason is logged in order notes.

## Tracking Operations

- Configure API in `WooCommerce -> CK Workflow`.
- Ensure `Enable tracking sync` is checked.
- Run `Run tracking sync now` for immediate refresh when needed.
- Scheduled sync interval is controlled by `Sync interval (hours)`.

## Troubleshooting

### No live tracking data shown

- Confirm AusPost API key is saved.
- Confirm order has shipment tracking number meta.
- Use manual sync button and recheck order.
- Review order meta `_ck_ows_live_tracking_last_error` for API errors.

### Cannot move to In Production

- If order is in `Awaiting Artwork Approval` and proof exists:
  - ensure customer approved artwork, or
  - apply staff override with mandatory reason.

### My Account invoices/security pages 404

- Flush permalinks once:
  - `Settings -> Permalinks -> Save Changes`.

## Legacy Snippet Decommission Checklist

Disable old snippets after plugin activation:

- My Account address notice snippet.
- Invoices endpoint/menu snippet.
- Registration guard snippet.
- `[order_tracking_summary]` and `[wc_invoice_link]` snippet definitions.

Do this only once to avoid duplicate output.

## Rollback

If needed:

1. Deactivate the plugin.
2. Re-enable legacy snippets.
3. Flush permalinks if account endpoints were in use.
