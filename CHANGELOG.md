# Changelog

All notable changes to YS FluentCart Order Statuses.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.1.0]: https://yangsheep.com.tw
