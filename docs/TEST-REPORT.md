# YS FluentCart Order Statuses 0.1.0 — test report

**Environment** — WordPress 7.1, FluentCart 1.6.3, PHP 8.x, MariaDB 11.4, a local
development site, currency USD, COD
(offline) gateway enabled. `ys-fluentcart-price-calculator` 0.2.1 and
`ys-fluentcart-store-credit` 0.2.0 were active throughout; neither was modified
and another engineer was working on the latter on the same site at the same
time.

**Fixtures** — `STATUS-Physical-01` (physical, $60, product 21 / variation 9) and
the customer `status-shopper@example.test` (customer 47 / WP user 43), created by
`tests/seed-fixtures.php`. Every order created by the walkthrough carries the
note `STATUS- fixture order`. No existing product, order, customer or store
setting was altered.

## How to reproduce

```bash
# Unit tests — no WordPress, no database
php tests/run.php                                  # PASS — 128 assertions

# Integration walkthrough, against the real site
wp eval-file tests/seed-fixtures.php              # once
wp eval-file tests/status-scenarios.php           # PASS — 67 assertions  (full output: docs/scenario-output.txt)
wp eval-file tests/revenue-probe.php              # T14
wp eval-file tests/measure-editable-filter.php    # the §2.2 measurement
```

Every status change in the walkthrough goes through FluentCart's **own** REST
routes via `rest_do_request()` — `PUT /fluent-cart/v2/orders/{id}/statuses` and
`POST /fluent-cart/v2/orders/{id}/mark-as-paid` — so `rest_pre_dispatch`, the
route permission callback, `OrderResource::updateStatuses()` and
`StatusHelper::syncOrderStatuses()` all run exactly as they do for the admin SPA.
Assertions read the database row back afterwards, never the in-memory model.

**Status configuration used** (written by the walkthrough, visible in
`wp option get ys_fct_status_settings`):

| Axis | Slug | Label | Colour | Available on | After payment |
|---|---|---|---|---|---|
| order | `sourcing` | 美國採購中 | `#db8a3e` | any | keep |
| order | `core_decides` | Core decides | `#2563eb` | any | let FluentCart decide |
| order | `paid_step` | Paid only step | `#16a34a` | paid only | keep |
| order | `unpaid_step` | Unpaid only step | `#dc2626` | unpaid only | keep |
| order | `temp_step` | Temporary step | `#7c3aed` | any | keep |
| shipping | `us_warehouse` | 已到美國倉 | `#16244a` | — | — |
| override | `processing` | 處理中 | `#2563eb` | — | — |
| override | `pending` (payment) | 待付款 | — | — | — |
| override | `unshipped` (shipping) | 未出貨 | — | — | — |

Order ids from the final walkthrough run: T1 `413`, T4 `414`, T4-digital `415`,
T5 `416`, T4b `417`, T4c `418`, T6 `419`, T7 `420`, T15 `421`, T11 `422`/`423`,
T14 `424`.

---

## Results

| # | Scenario | Result |
|---|---|---|
| T1 | Add a custom order status and set it | ✅ pass |
| T2 | Order list label, colour and filter option | ✅ pass |
| T3 | Slug too long / reserved / duplicate / malformed | ✅ pass |
| T4 | `on_payment=keep` survives payment | ✅ pass (physical **and** digital) |
| T5 | `on_payment=let_core_decide` | ✅ pass |
| T6 | `payment_requirement` | ✅ pass (enforced on the write — see §2.2 answer) |
| T7 | Custom shipping status | ✅ pass |
| T8 | Relabel a built-in status | ✅ pass |
| T9 | Storefront customer dashboard | ✅ pass |
| T10 | `fluent_cart/order_status_changed_to_<slug>` fires | ✅ pass |
| T11 | Delete a status in use → migrate its orders | ✅ pass |
| T12 | Deactivate / reactivate | ✅ pass |
| T13 | Export → wipe → import | ✅ pass |
| T14 | Revenue reporting | ✅ pass (unaffected, measured twice) |
| T15 | A canceled order still cannot change status | ✅ pass |

Plus three scenarios the spec did not ask for but the mechanism needs:
**T4b** (global restore switch off), **T4c** (a deliberate admin change is not
bounced back) and **T4-digital** (core's digital auto-complete does not undo the
restore).

---

### T1 — add a custom order status and set it

Steps: save `sourcing` / 美國採購中 through `POST /ys-fct-status/v1/settings`;
set it on order 413 through FluentCart's own `PUT /orders/413/statuses`.

```
ok   T1 REST accepted                      → 200
ok   T1 DB status is sourcing
ok   T1 activity records the change
ok   T1 slug appears in editable list
ok   T1 label comes from settings          → 美國採購中
```

DB:

```
id   status    payment_status  shipping_status  fulfillment_type  total_paid
413  sourcing  pending         unshipped        physical          0
```

Activity (`wp_fct_activity`, `module_id = 413`): *Order status has been updated
from on-hold to sourcing*.

`Status::getEditableOrderStatuses()` after the save — FluentCart's own list, no
core changes:

```json
{"on-hold":"On Hold","processing":"處理中","completed":"Completed","canceled":"Canceled",
 "sourcing":"美國採購中","core_decides":"Core decides","paid_step":"Paid only step",
 "unpaid_step":"Unpaid only step","temp_step":"Temporary step","tw_customs":"台灣清關中"}
```

Browser: `docs/screenshots/01-order-statuses-tab.png` (the settings screen),
`03-order-detail-custom-status.png` (order detail showing 美國採購中 in `#db8a3e`).

The last entry, `tw_customs` / 台灣清關中, was created **through the browser UI**
rather than the REST script, as an end-to-end check that the admin page really
writes: typed into the form, saved, then read back out of
`Status::getEditableOrderStatuses()`.

### T2 — order list label, colour and filter

```
ok   T2 custom status is a filter option        → advance → order → status → sourcing = 美國採購中
ok   T2 built-in relabel reaches the filter     → processing = 處理中
ok   T2 colour map carries the slug             → #db8a3e
```

Browser (`docs/screenshots/02-orders-list-colours.png`) — measured computed
styles on the Orders table:

| Badge text | `data-ys-status` | computed colour |
|---|---|---|
| 美國採購中 | `sourcing` | `rgb(219, 138, 62)` = `#DB8A3E` |
| 處理中 | `processing` | `rgb(37, 99, 235)` = `#2563EB` |
| Paid only step | `paid_step` | `rgb(22, 163, 74)` = `#16A34A` |
| 待付款 | `pending` | FluentCart's own warning colour (label-only override) |

**Caveat:** custom statuses reach the Orders **advanced filter** (which this
plugin extends through `fluent_cart/admin_filter_options`), not the fixed
top-level tab strip (*All / Completed / Processing / On Hold*), which is
hardcoded in the Vue bundle.

### T3 — slug validation

Unit level (`Settings::slugError`):

| Input | Result |
|---|---|
| `this_slug_is_far_too_long_for_varchar20` (38 chars) | `too_long` |
| 20 characters exactly | accepted |
| `completed` | `reserved` |
| `cancelled` (core's other spelling) | `reserved` |
| `draft` | `reserved` |
| `9lives`, `_leading` | `invalid_characters` |
| `sourcing` when `sourcing` already exists | `duplicate` |

REST level: `POST /ys-fct-status/v1/settings` with `slug: "completed"` → **422**.

Browser: typing `completed` into a new row and pressing *Save changes* shows

> “completed” is one of FluentCart's own statuses. Rename it on the Built-in
> labels tab instead of redefining it.

(`docs/screenshots/13-slug-validation-error.png`.) The sanitiser silently drops
an invalid definition, which is right for a stored row and wrong for a form —
hence the explicit 422, so a status never vanishes without a word.

### T4 — `on_payment = keep` survives payment 🔴

The core scenario. Order 414: physical, COD, `on-hold` → `sourcing` → *mark as
paid*.

```
ok   T4 mark-as-paid accepted             → 200
ok   T4 order status is still sourcing
ok   T4 payment status is paid
ok   T4 total_paid matches                → 6000
ok   T4 activity explains the restore
```

DB after payment:

```
id   status    payment_status  shipping_status  fulfillment_type  total_paid
414  sourcing  paid            unshipped        physical          6000
```

Activity timeline for order 414, in order — this is the whole mechanism visible:

```
1430  Order status updated       Order status has been updated from on-hold to sourcing
1431  Order Paid                 Order Paid successfully!
1432  Order status updated       Order status has been updated from sourcing to processing   ← core
1433  Custom order status kept   Payment was recorded. FluentCart set this order to
                                 Processing; YS Order Statuses restored the custom status
                                 美國採購中 (sourcing) because it is configured to be kept
                                 after payment.                                              ← this plugin
```

Screenshot: `docs/screenshots/04-order-activity-restore-note.png`, and
`03-order-detail-custom-status.png` shows the finished order — `$60` *Total
Paid*, payment badge *Paid*, status badge 美國採購中.

**T4-digital** — order 415, identical but `fulfillment_type = digital`:

```
ok   T4 digital order is not auto-completed   → status still `sourcing`
ok   T4 digital payment status is paid
```

This is the trap the spec did not list. A few lines after the status-changed
event, `syncOrderStatuses()` auto-completes digital orders using the in-memory
model, which would have overwritten the restored row. The restore therefore also
returns `false` from `fluent_cart/order_status/auto_complete_digital_order` for
the order it just handled. Without that guard order 415 ends on `completed`.

### T4b — the global switch

Same as T4 with `restore_on_payment = no`. Order 417 ends on `processing`:
`ok  T4b with restore off, core wins`.

### T4c — a deliberate admin change is not undone

Order 418: `sourcing` → paid (restored to `sourcing`, verified) → an admin
explicitly sets *Processing* through the status endpoint.

```
ok   T4c restore happened first    → sourcing
ok   T4c admin choice sticks       → processing
```

The discriminator is core's own `manageStock` argument on `OrderStatusUpdated`:
`true` from the payment paths, `false` from `OrderResource::updateStatuses()`.
Without it, an admin could never move a paid order off a custom status.

### T5 — `on_payment = let_core_decide`

Order 416, status `core_decides`, then paid:

```
ok   T5 core moved the order to processing   → processing
ok   T5 payment status is paid
ok   T5 no restore note was written
```

### T6 — `payment_requirement`

One order (419) walked through all five steps:

| Step | Request | Result |
|---|---|---|
| unpaid, want `paid_step` | `PUT /orders/419/statuses` | **422** — “Paid only step” can only be used on orders that have been paid. This order has not been paid yet. Row unchanged (`on-hold`). |
| unpaid, want `unpaid_step` | same | 200, DB `unpaid_step` |
| pay the order | `mark-as-paid` | DB still `unpaid_step` (kept) |
| paid, want `paid_step` | same | 200, DB `paid_step` |
| paid, want `unpaid_step` | same | **422** |

### T7 — custom shipping status

Order 420:

```
ok   T7 REST accepted                              → 200
ok   T7 DB shipping_status                         → us_warehouse
ok   T7 order status untouched                     → on-hold
ok   T7 payment does not touch shipping_status     → still us_warehouse after mark-as-paid
ok   T7 payment did move the order status          → processing
```

The last two lines are the point: payment rewrote the **order** status and left
the **shipping** status completely alone. That is why the README tells operators
to put fulfilment workflows on the shipping axis.

Browser: `docs/screenshots/05-shipping-status-dropdown.png` — FluentCart's own
*Update Shipping Status* modal listing 未出貨 / Shipped / Delivered /
Unshippable / **已到美國倉**.

### T8 — relabel a built-in status

```
ok   T8 processing is relabelled             → 處理中
ok   T8 completed keeps its label            → Completed
ok   T8 payment pending is relabelled        → 待付款
ok   T8 shipping unshipped is relabelled     → 未出貨
ok   T8 slug in the database is untouched    → wp_fct_orders.status = 'processing'
```

Browser: `docs/screenshots/10-builtin-labels-tab.png`, and 處理中/待付款/未出貨
visible in the orders list and storefront screenshots.

### T9 — storefront customer dashboard

Logged in as `status-shopper` (cookie generated with `wp_generate_auth_cookie`;
no password was ever typed). `/account/` and `/account/purchase-history`:

| Badge | class | `data-ys-status` | computed colour |
|---|---|---|---|
| 美國採購中 | `fct-badge fct-sourcing fct-small` | `sourcing` | `rgb(219, 138, 62)` |
| 處理中 | `fct-badge fct-warning fct-small` | `processing` | `rgb(37, 99, 235)` |
| Paid only step | `fct-badge fct-paid_step fct-small` | `paid_step` | `rgb(22, 163, 74)` |
| Canceled | `fct-badge fct-danger fct-small` | — | FluentCart's own |

Screenshots: `06-customer-dashboard.png`, `07-customer-purchase-history.png`.
No console errors or warnings on either page.

### T10 — per-status actions fire

A listener registered on each `fluent_cart/order_status_changed_to_<slug>` and
`fluent_cart/shipping_status_changed_to_<slug>` recorded every firing:

```
ok   T10 order_status_changed_to_sourcing fired            → sourcing#413
ok   T10 order_status_changed_to_core_decides fired        → core_decides#416
ok   T10 shipping_status_changed_to_us_warehouse fired     → ship:us_warehouse#420
```

This is what a notification add-on will hang off.

### T11 — delete a status in use

Two orders (422, 423) put on `temp_step`, then
`POST /ys-fct-status/v1/migrate {axis: order, from: temp_step, to: processing}`:

```
ok   T11 temp_step is in use before                → 2
ok   T11 migrate accepted                          → 200
ok   T11 moved every order                         → 2
ok   T11 usage is now zero                         → 0
ok   T11 the sample order moved                    → processing
ok   T11 activity explains it
ok   T11 orders on other statuses were not touched → order 414 still `sourcing`
```

Each migrated order gets a warning-level activity entry: *The custom status
Temporary step was removed. This order was moved to 處理中.* The migration is a
plain `$wpdb->update`, deliberately **not** routed through `StatusHelper` — a
settings save must not fire one status-changed event (and one set of
notifications) per order.

Browser: `docs/screenshots/12-move-orders.png` — the *Move orders* box, which
only appears on a status that still has orders, and blocks removal until it is
empty.

### T12 — deactivate / reactivate

```
before deactivate      sourcing 5, paid_step 3, us_warehouse 3
wp plugin deactivate ys-fluentcart-order-statuses
after deactivate       sourcing 5, paid_step 3, us_warehouse 3        ← unchanged
```

With the plugin off, `Status::getOrderStatuses()` returns only the five core
statuses and `getShippingStatuses()` only the four core ones — no errors, no
fatals. Both the admin order detail and the storefront render the humanised raw
slug ("Sourcing", "Paid Step") and behave normally
(`docs/screenshots/09-deactivated-raw-slugs.png`). No console errors.

After `wp plugin activate`, the labels are back:

```json
{"processing":"處理中","completed":"Completed","on-hold":"On Hold","canceled":"Canceled",
 "failed":"Failed","sourcing":"美國採購中",…}
```

and the order counts are still `sourcing 5, paid_step 3`.

### T13 — export → wipe → import

```
ok   T13 export accepted                 → 200
ok   T13 everything is gone              → order: []
ok   T13 core statuses still work        → `processing` still present
ok   T13 import accepted                 → 200
ok   T13 settings round-tripped exactly  → the whole document is identical
```

The round-trip comparison is `===` on the entire normalised settings array, not a
spot check. The importer runs the same whitelist sanitiser as the settings form,
so nothing from the file reaches the option unfiltered — a unit test covers
`<script>alert(1)</script>Evil` in an imported label being stored as
`alert(1)Evil`.

### T14 — revenue reporting is unaffected

Two independent measurements.

**SQL semantics** (order 424, `sourcing` + `paid`):

```
ok   T14 revenue reports (payment_status) include it     → COUNT = 1
ok   T14 order-status reports (hardcoded) exclude it     → COUNT = 0
```

**FluentCart's own reporting endpoint** (`tests/revenue-probe.php`) — asked
`GET /fluent-cart/v2/reports/dashboard-stats` for today, flipped the order
between `sourcing` and `processing`, and asked again:

```
report while on the custom status : paid_orders 282, Order Value (Paid) 2 123 700 (cents)
report while on processing        : paid_orders 282, Order Value (Paid) 2 123 700
identical: yes
```

Revenue is driven entirely by `payment_status`, so a custom **order** status is
invisible to it. Screenshot: `docs/screenshots/08-revenue-report.png` — Gross
Summary $21.79K, which includes the paid `sourcing` orders.

The reports that *are* affected are the few filtering by order status —
`SubscriptionReportService` uses `whereIn('status', getOrderSuccessStatuses())`,
a hardcoded `['completed','processing']`. Documented in the README.

### T15 — a canceled order cannot change status

Order 421 set to `canceled`, then asked for `sourcing`:

```
ok   T15 order is canceled       → canceled
ok   T15 the change is refused   → row unchanged
ok   T15 core says why           → "You cannot change the order status once it has been canceled."
```

Core behaviour (`OrderResource.php`), deliberately not worked around.

---

## The two measured unknowns

### 1. Can `fluent_cart/editable_order_statuses` see the order? **No — but the REST route can.**

`tests/measure-editable-filter.php`, run against the live site. Registered a
probe on the filter with `accepted_args = 2` and recorded every call.

**Call with no request in flight** (`Status::getEditableOrderStatuses()` direct):

```json
{"arg_count":2,"second_arg":[],"order_context_id":0,
 "callers":["FluentCart\\App\\Helpers\\Status::getEditableOrderStatuses","eval",…]}
```

**Call during a real REST status change** on order 346:

```json
{"arg_count":2,"second_arg":[],"order_context_id":346,
 "callers":["FluentCart\\App\\Helpers\\Status::getEditableOrderStatuses",
            "FluentCart\\Api\\Resource\\OrderResource::updateStatuses",
            "FluentCart\\App\\Http\\Controllers\\OrderController::updateStatuses",…],
 "returned_slugs":["on-hold","processing","completed","canceled","measure_any"]}
```

So:

* **The filter's second argument is an empty array in every call.** Core passes
  no order, ever — `getEditableOrderStatuses()` takes no arguments and has none
  to pass.
* The filter is called *both* with an order in scope
  (`OrderResource::updateStatuses()`) and with none at all (`MenuHandler`'s
  localize block, once per admin page load).
* **The order is recoverable from the REST route.** Every order-scoped call
  arrives as `/fluent-cart/v2/orders/{id}/…`, and `rest_pre_dispatch` runs before
  the controller. `Support\OrderContext` records the id there and drops it on
  `rest_post_dispatch`. In the run above it correctly reported `346` inside the
  controller and `0` outside — and `measure_probe` (`paid_only`, order unpaid)
  had already been dropped from the returned list by that context.

**Approach taken — two layers:**

1. `rest_pre_dispatch` at priority 9 vetoes the change with a **422** naming the
   status and saying why. This is what the operator sees.
2. `fluent_cart/editable_order_statuses` at priority 30 narrows the list for the
   order in flight, so core's own write-side allow-list
   (`if (isset($validStatuses[$newStatus]))`) agrees. This covers any code path
   in the same request that does not go through REST.

**What is deliberately not attempted:** hiding the option in the admin dropdown.
The FluentCart admin is a Vue SPA that reads `editable_order_statues` **once per
page load** from `window.fluentCartAdminApp` — before any order is open — and the
order id lives only in the URL hash fragment, which never reaches the server.
Verified directly in the browser: the localized payload is one global map, and
`app.js` binds it as `editableOrderStatues: this.appVars.editable_order_statues`.
Per-order filtering of that dropdown is impossible without shipping a Vue build.
The status is therefore offered and refused with an explanation, which T6 shows
is clear at the point of use.

### 2. Payment overwrite restore (§2.3) — works, with one extra guard the spec did not anticipate

T4, T4-digital, T4b and T4c above. The mechanism is:

1. `fluent_cart/order_status_changed` at priority 5 — it dispatches
   synchronously from inside `StatusHelper::syncOrderStatuses()`, before anything
   else reads the new value.
2. Restore only when **all** of: the restore switch is on; `old_status` is one of
   ours with `on_payment = keep`; `new_status === 'processing'` (core's
   hardcoded target); the order's `payment_status` is now `paid` /
   `partially_paid` / `partially_refunded`; and `manageStock === true` (the
   payment paths pass `true`, the admin dropdown passes `false` — T4c).
3. Write with `$wpdb->update` and a **compare-and-set** on the value core just
   wrote. Never `StatusHelper` or `$order->updateStatus()`, either of which would
   dispatch another `OrderStatusUpdated` straight back into the listener.
4. **Suppress core's digital auto-complete for that order** via
   `fluent_cart/order_status/auto_complete_digital_order` — the extra guard. The
   filter is applied *after* the status-changed dispatch, so the flag set in step
   3 is visible to it. Without this, T4-digital fails: the row is restored and
   then immediately overwritten with `completed` by the in-memory model's
   `save()`.
5. Sync the in-memory model (`syncOriginalAttribute('status')`) so a later
   unrelated `save()` cannot write the stale `processing` back.
6. Write the activity note, then fire `ys_fct_status/custom_status_restored`.

Double-firing (webhook + browser return both calling `syncOrderStatuses()`) is
covered twice: core's own atomic payment claim, and a per-request set of already
restored order ids in the handler.

### 3. Front-end markup selector — measured, and the two surfaces differ

| Surface | Rendered markup | Slug in the DOM? | Label from `fluent_cart/order_statuses`? |
|---|---|---|---|
| Admin list + detail | `<span class="badge info"><!---->Sourcing</span>` | **No** — the class is a variant (`success`/`warning`/`danger`/`info`) chosen from a map of built-in slugs | **No** — the text is the raw column value humanised in JS |
| Storefront dashboard | `<span class="fct-badge fct-sourcing fct-small"><!---->Sourcing</span>` | **Yes** — an unrecognised slug goes straight into a class | **No** — same humanised text |

The "no" in the admin's label column was verified directly:
`window.fluentCartAdminApp.order_statuses.processing` was `"處理中"` while the
badge still read `"Processing"`, and `us_warehouse` rendered as `"Us Warehouse"`.
So the label map reaches the status dropdown, the Orders filter and everything
server-rendered — but not the badge.

**Selectors used:**

* CSS — `[data-ys-status="<slug>"], .fct-badge.fct-<slug>`. The second is the
  storefront's own class, so storefront colours need no JavaScript at all.
* Tagger script — `span.badge, .el-tag` in the admin;
  `.fct-badge, span.badge, .fct_order_status, .fct-order-status, .el-tag` on the
  storefront. It matches a badge's text against every spelling a managed slug can
  take (raw slug, humanised slug, configured label), stamps `data-ys-status` and
  replaces the text with the configured label.

The text swap edits the badge's **text node** in place rather than assigning
`textContent`, so Vue's comment anchor (`<!---->`) survives and the component can
still patch itself. A slug defined on two axes with different labels is left
alone rather than guessed at. `ys_fct_status/patch_admin_labels` turns the
relabelling off and keeps only the colours.

---

## Clean-run checks

**`wp-content/debug.log`** — zero lines from this plugin across the whole
session:

```
grep -c "OrderStatuses\|ys-fluentcart-order-statuses\|ys_fct_status" wp-content/debug.log
0
```

The log does contain `[ys-fluentcart-store-credit]` lines from the other
engineer's concurrent work — not this plugin's. It also has one fatal at
09:04:46 UTC, `Class "FluentCart\App\Services\StoreSettings" not found`, thrown
from `wp eval` — an exploratory one-liner typed while looking for the customer
dashboard page, with a class name that does not exist. No plugin file was
involved, and no notice, warning or fatal from `src/` appears anywhere in the
log.

**Browser console** — one error across every page visited, and it is a
deliberate one: the `422` from the T3 slug-validation test. Otherwise clean on
the settings screen, the FluentCart order list, the order detail, the customer
dashboard and the purchase history. DevTools' "form field should have an id or
name" advisory fired 163 times on the first pass and was fixed (every field on
the settings screen now carries an `aria-label`, `id` or `name`); a re-check
reports `{total: 98, unlabelled: 0}`.

**Side effects on the shared site** — none outside the fixtures. Products,
customers, store settings, pages, currency and gateways untouched; no database
reset; the web server and the database were never restarted; the other two YS plugins never
deactivated or modified.

---

## Deviations from the design document

| § | Spec said | What was built, and why |
|---|---|---|
| 2.2 | "if you cannot get the order, offer everything and validate in `fluent_cart/order_status_changed`" | That hook fires **after** the write — vetoing there would mean undoing a change that already happened. `rest_pre_dispatch` is a genuine pre-write veto and returns a proper 422, so it was used instead. The order-aware narrowing of `editable_order_statuses` was also achievable and is kept as a second layer. |
| 2.3 | Three sub-steps (hook, `$wpdb->update`, global switch) | All three, plus the digital auto-complete guard, the `manageStock` discriminator and the compare-and-set. Without the first of those, T4 fails on digital products; without the second, T4c fails. |
| 2.4 | "drag-to-reorder (SortableJS inline or native DnD plus up/down buttons)" | Up/down buttons only. They are keyboard-accessible, need no library, and a status list is typically five rows. |
| 2.5 | "colours via `[data-status="slug"]` or our own wrapper class" | Both hooks, plus label replacement — which the spec did not anticipate, because it assumed the badge reads `fluent_cart/order_statuses`. It does not. |
| 4 | T1–T15 | All fifteen, plus T4b, T4c and T4-digital. |
| — | — | Uninstall does **not** delete the settings by default (opt in with `YS_FCT_STATUS_REMOVE_DATA`). Deleting the definitions while orders still carry the slugs is data loss, not tidy-up. |

## Known limitations

Listed in full in the README, §6. In short: FluentCart hardcodes
`getOrderSuccessStatuses()`, `getOrderFailedStatuses()`,
`getOrderPaymentSuccessStatuses()` and `getReportStatuses()` with no filters, so
a custom order status can never be "success" or "failed" for core's purposes and
is invisible to the few reports that filter by order status (revenue is safe —
T14). A canceled order still cannot change status. The Orders list tab strip is
fixed. Custom **payment** statuses are deliberately out of scope for v1.
