# Changelog

All notable changes to YS FluentCart Order Statuses.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] — 2026-09-11

The workflow can now be **driven**, not just declared. Measured on FluentCart
1.6.3: a *paid* order has no order-status control anywhere in the admin — the
header carries Refund, a disabled Edit and a "More Action" menu (Change Shipping
Status, Cancel Order, Sync Order Statuses, Receipt) — so an operator whose whole
workflow starts after payment could not move an order along it from FluentCart's
own UI. 0.3 supplies the control, and adds a second, safer home for the same
workflow on the shipping axis. Upgrading from 0.2.0 changes nothing about an
existing configuration.

### Added

- **An "Order workflow" panel on the order page**, above the status history:
  the order's status on both axes with its step number, a dropdown of **only
  the moves this order is allowed to make** (in workflow order), a *Change*
  button and a one-click *Next step →* button. The list and the write are one
  rule — `Pipeline\Changer` builds the dropdown from
  `Status::getEditableOrderStatuses()` with the order in scope and asks the same
  guards the REST veto asks — so an option that would be refused is never
  offered, and a refusal that happens anyway is printed inline in the veto's own
  words. The write goes through `OrderResource::updateStatuses()` with
  `manage_stock: false`, so every event, activity line, `fulfilled_quantity`
  update and linked-shipping follow-up behaves exactly as for a manual change.
  No inline script: FluentCart injects widgets with `innerHTML`, so the behaviour
  lives in `assets/admin/order-changer.js` behind one delegated listener.
- **A fulfilment workflow template on the shipping axis** (*Tools → Create the
  fulfilment workflow*): `unshipped` (relabelled "Awaiting production") →
  `in_production` → `ship_scheduled` → the built-in `shipped`, which is
  deliberately not redefined because it is the slug core checks when it marks
  line items fulfilled. Nothing in FluentCart ever overwrites `shipping_status`,
  and FluentCart's own "Change Shipping Status" dialog drives it on a paid order,
  so this is the recommended axis for any post-payment workflow. Both templates
  can coexist; applying either twice is a no-op.
- **FluentCart's shipping dialog lists the steps in workflow order.** Both
  `fluent_cart/shipping_statuses` and `fluent_cart/editable_shipping_statuses`
  are now ordered — the SPA builds that dialog from the *display* map, which is
  not the obvious one.
- **The report has an axis switch** (*Order status / Shipping status*) for the
  funnel, the dwell table and the stuck list; both distribution tables are
  always shown. The axis is named beside each heading and carried in the CSV
  filename for the two exports that follow the switch.
- **Saved views for custom shipping statuses** on the Orders list ("In
  production · shipping (4)"). A shipping view cannot use a search expression —
  `OrderFilter::getSearchableFields()` has no `shipping_status` entry — so the
  clause is added on `fluent_cart/orders_list_filter_query`, with the view slug
  captured on `rest_pre_dispatch` and checked against the configured statuses
  before it is used.
- The Shipping statuses tab draws the fulfilment workflow as a numbered strip,
  as the Order statuses tab already did for the order workflow.
- New REST routes: `GET orders/{id}/state`, `POST orders/{id}/change`;
  `template` and `reports/overview` / `reports/export` take an `axis`.
- `RequirementGuard::rejectionReason()` is public and static so the control and
  the veto share it.

### Verified, no change needed

- *More Action → Sync Order Statuses* on a kept custom status runs the same
  overwrite as a payment (`processing`), and `RestoreHandler` puts the custom
  status straight back — checked in both directions (C7).

### Tests

- 233 unit assertions (`php tests/run.php`), and a new 109-assertion
  walkthrough `tests/changer-scenarios.php` (C1–C7 for the control, S1–S6 for
  the shipping-axis workflow) on top of the 67 + 118 from 0.1 and 0.2 — all
  driven through real REST requests and read back from the database.

## [0.2.0] — 2026-09-11

Custom order statuses become a **workflow** rather than a set, and the plugin
grows a report of its own. Developed against FluentCart 1.6.3; minimum supported
1.6.0. Upgrading from 0.1.0 changes nothing about an existing configuration —
the new fields default to off and a 0.1.0 settings document is read unchanged.

### Added

- **A pipeline.** The custom order statuses are an ordered sequence, starting at
  the built-in `processing` (which FluentCart writes the moment payment lands and
  which the template relabels to "Paid"). The order they are listed in on the
  settings screen is the order they appear in on FluentCart's own status
  dropdown — array order is the only lever that map offers.
- **A one-click template** for the standard workflow: *Paid → In production →
  Shipment scheduled → Shipped*, plus suggested names for the built-in statuses.
  It is never written automatically, never overwrites anything already
  configured, and applying it twice does nothing.
- **`linked_shipping_status`.** A custom order status can name one shipping
  status to set alongside it. The write goes through
  `OrderResource::updateStatuses()`, so `fulfilled_quantity` and the
  `shipping_status_changed_to_*` events behave exactly as they do for a manual
  change. Digital and non-shippable orders are skipped with a line in the
  activity timeline. One direction only — shipping never drives the order status.
- **`pipeline_strict`** (off by default): an order on a workflow step may only
  move to the adjacent one, and a skip is refused with a message naming the step
  that was missed. Leaving the workflow (complete, cancel, hold) and joining it
  from outside are always allowed.
- **A status-history table**, `{prefix}ys_fct_status_history`, written from
  `fluent_cart/order_status_changed` and `fluent_cart/shipping_status_changed`.
  It is what the dwell times are measured from. Installed with `dbDelta` behind a
  versioned option, so an in-place update creates it without a reactivation.
- **Backfill from FluentCart's activity log** — a one-off, best-effort import of
  the status changes from before the plugin was installed. Lines it cannot read
  are skipped and counted; running it twice does not duplicate anything.
- **An Order Status Report tab**: distribution per status split by whether money
  arrived, the workflow as a funnel, average and longest time per step, the
  orders that have been on one step too long, and CSV for each. Pure CSS charts,
  no third-party JavaScript. The README explains why it is not under
  FluentCart → Reports.
- **Saved views on the Orders list** — one per custom order status, through
  `fluent_cart/admin_table_saved_views`.
- **A "Status history" widget** on the order page, through
  `fluent_cart/widgets/single_order_page`: both axes on one timeline, with how
  long each stay lasted.
- **An optional daily summary e-mail** (WP-Cron plus a catch-up on the next admin
  page load, because cron on a quiet shop is not a scheduler).
- **`ys_status_meta` on each row of `fluent_cart/orders_list`** — label, colour,
  step number and days in status. The admin SPA does not render unknown keys;
  this is for anything reading `GET /orders` directly.
- New REST routes under `ys-fct-status/v1`: `reports/overview`,
  `reports/history`, `reports/export`, `reports/backfill`,
  `reports/summary-test` and `template`. Same bar as the rest — capability plus
  nonce.
- New hooks: `ys_fct_status/linked_shipping_applied` and
  `ys_fct_status/record_restore_history`.

### Fixed

- A badge lost its colour a moment after being relabelled whenever two statuses
  on different axes render the same word — which the template makes the common
  case, since its last order status and the built-in shipping status are both
  "Shipped". Slug spellings now outrank label spellings when claiming a rendered
  word, and a badge already carrying the configured label is left alone on later
  passes.
- The fixture customer's e-mail address and the local development paths were
  removed from the repository.

### Changed

- Schema version 2. `linked_shipping_status`, `pipeline_strict`, `stall_days`
  and `daily_summary` are added on read, so a 0.1.0 export still imports.
- Uninstall (opt-in, unchanged) now also drops the history table and clears the
  cron event. Deactivating still changes nothing at all.

## [0.1.0] — 2026-09-11

First release. Developed against FluentCart 1.6.3; minimum supported 1.6.0.

### Added

- **Custom order statuses** and **custom shipping statuses**, each with a label,
  a ≤ 20-character slug (the column is `VARCHAR(20)`), a colour, a description,
  a sort order, an enabled flag and an "offer it in the admin dropdown" flag.
  Registered through `fluent_cart/order_statuses`,
  `fluent_cart/editable_order_statuses`, `fluent_cart/shipping_statuses` and
  `fluent_cart/editable_shipping_statuses`, so FluentCart's own dropdowns and
  its REST write-side allow-list pick them up with no core changes.
- **`on_payment` per order status.** FluentCart's `StatusHelper` rewrites any
  order to `processing` when payment lands, against a hardcoded array with no
  filter. Statuses set to *keep* are written straight back — with a
  compare-and-set `$wpdb->update`, an entry in the order's activity timeline,
  and a guard that also suppresses core's digital auto-complete for that order
  so the restore is not immediately undone. Statuses set to *let FluentCart
  decide* are left to core. A global switch turns the whole mechanism off.
- **`payment_requirement` per order status** (`any` / `paid_only` /
  `unpaid_only`), enforced on the write with a message naming the status, via
  `rest_pre_dispatch` plus a second pass that narrows
  `fluent_cart/editable_order_statuses` for the order in flight.
- **Relabelling and recolouring of built-in statuses** on all three axes.
  Payment statuses can be renamed but never added to — the README explains why.
- **Colours and labels on the rendered badge**, in the admin and the storefront.
  Storefront colours are pure CSS (`.fct-badge.fct-<slug>`); the admin needs a
  small tagger because the slug never reaches its markup. Switchable with
  `ys_fct_status/patch_admin_labels`.
- **Custom statuses in the Orders advanced filter**, through
  `fluent_cart/admin_filter_options`.
- **Admin screen** at *FluentCart → Order Statuses* — four tabs, per-status order
  counts, reordering, and a **Move orders** tool that migrates every order off a
  status before it is removed (one activity note per order).
- **Export / import** of the whole status document as JSON.
- **REST namespace `ys-fct-status/v1`** — `settings`, `usage`, `migrate`,
  `export`, `import` — every route capability-gated and nonce-checked.
- **`ys_fct_status/custom_status_restored`** action for add-ons that want to know
  a status survived payment.
- zh_TW translation; POT template for everything else.
- 128 unit assertions (`php tests/run.php`) and a 67-assertion integration
  walkthrough driven through FluentCart's own REST routes
  (`wp eval-file tests/status-scenarios.php`).

### Notes

- Deactivating or uninstalling never changes an order row. Uninstall keeps the
  settings unless `YS_FCT_STATUS_REMOVE_DATA` is defined.
- Custom payment statuses are deliberately out of scope for v1.

[0.3.0]: https://yangsheep.com.tw
[0.2.0]: https://yangsheep.com.tw
[0.1.0]: https://yangsheep.com.tw
