# YS FluentCart Order Statuses 0.3.0 — test report

**Environment** — WordPress 7.1, FluentCart 1.6.3 (free; **no FluentCart Pro**),
PHP 8.x, MariaDB 11.4, a local development site, currency USD, COD (offline)
gateway enabled. `ys-fluentcart-price-calculator` 0.3.0 and
`ys-fluentcart-store-credit` 0.2.0 were active throughout and neither was
modified.

**Fixtures** — `STATUS-Physical-01` (physical, $60), `STATUS-Digital-01`
(digital) and the customer `status-shopper@example.test`, created by
`tests/seed-fixtures.php`. Every order the walkthrough creates carries a
`STATUS- changer: …` note. No existing product, order, customer or store setting
was altered; the walkthrough rewrites only `ys_fct_status_settings`.

**Status configuration** — both one-click templates, applied by the walkthrough
and left in place afterwards:

| Axis | Step | Slug | Label | Kind |
|---|---|---|---|---|
| order | 1 | `processing` | Paid | built-in, relabelled |
| order | 2 | `in_production` | In production | custom, paid only, keep after payment |
| order | 3 | `ship_scheduled` | Shipment scheduled | custom, paid only, keep after payment |
| order | 4 | `shipped_done` | Shipped | custom, paid only, keep, `linked_shipping_status = shipped` |
| shipping | 1 | `unshipped` | Awaiting production | built-in, relabelled |
| shipping | 2 | `in_production` | In production | custom |
| shipping | 3 | `ship_scheduled` | Shipment scheduled | custom |
| shipping | 4 | `shipped` | Shipped | built-in, untouched |

The two axes deliberately share the slugs `in_production` and `ship_scheduled`;
S1 checks that each axis keeps its own definition and S5 that the saved views
built on them cannot collide.

## How to reproduce

```bash
# Unit tests — no WordPress, no database
php tests/run.php                                  # PASS — 233 assertions

# Integration walkthroughs, against a real site, in this order
wp eval-file tests/seed-fixtures.php               # once
wp eval-file tests/status-scenarios.php            # PASS — 67 assertions  (0.1)
wp eval-file tests/pipeline-scenarios.php          # PASS — 118 assertions (0.2)
wp eval-file tests/changer-scenarios.php           # PASS — 109 assertions (0.3)
```

Full output of the final run: [`docs/unit-output-v0.3.txt`](unit-output-v0.3.txt)
and [`docs/changer-output.txt`](changer-output.txt). The 0.1 and 0.2 suites were
re-run against the 0.3 code on the same site and still pass (the R6 assertion
on the CSV filename was updated, because the dwell and stuck-order exports now
carry the axis in their name).

Every status change goes through a real REST request dispatched with
`rest_do_request()` — FluentCart's own `PUT /fluent-cart/v2/orders/{id}/statuses`,
`POST …/mark-as-paid` and `…/sync-statuses`, or this plugin's
`POST /ys-fct-status/v1/orders/{id}/change` — so `rest_pre_dispatch`, the
route's permission callback, `OrderResource::updateStatuses()` and
`StatusHelper::syncOrderStatuses()` all run exactly as they do for the admin
SPA. Every assertion reads the database row back afterwards rather than trusting
a model that is still in memory.

Order ids from the final run: C1 `707`, C2 `708`, C3 `709`, C4 `710`, C5 `711`,
C6 `712`, C7 `713`, C7b `714`, S2 `715`, S3 `716`. History table
`wp_ys_fct_status_history`: 1 433 rows after the run.

The browser walkthrough (screenshots `docs/screenshots/v0.3-01` … `v0.3-12`)
was done over real HTTP with the SPA's own nonce, in Chrome, logged in as the
administrator, against the final code — the last source change predates every
screenshot. The headless suites above were re-run once more after the
translation catalogue and the documentation were finished.

---

## Results

| # | Scenario | Result |
|---|---|---|
| C1 | The control changes a **paid** order's status, which FluentCart's UI cannot | ✅ pass |
| C2 | A refused move comes back as a 422 the control can print | ✅ pass |
| C3 | Strict workflow narrows the control and the write together | ✅ pass |
| C4 | The one-click next step, to the end of the workflow | ✅ pass |
| C5 | A canceled order: the order axis closes, the shipping axis does not | ✅ pass |
| C6 | A digital order has no shipping axis to change | ✅ pass |
| C7 | *Sync Order Statuses* on a kept custom status — the guard holds | ✅ pass |
| S1 | The fulfilment workflow template (shipping axis) | ✅ pass |
| S2 | The workflow driven end to end through FluentCart's own shipping route | ✅ pass |
| S3 | The same workflow from the order page's control | ✅ pass |
| S4 | The report on the shipping axis matches the database | ✅ pass |
| S5 | Saved views on `shipping_status` | ✅ pass |
| S6 | The control's markup on FluentCart's own order page | ✅ pass |

### C1 — the control on a paid order

Order 707, physical, marked paid through `mark-as-paid`: the payment path put it
on step 1 (`processing`, shown as "Paid"). `GET orders/707/state` offered
`in_production` first and never offered `processing` itself. `POST …/change
{axis: order, status: in_production}` answered 200 with the fresh state and the
label "In production"; `SELECT status FROM wp_fct_orders WHERE id = 707` said
`in_production`; FluentCart wrote its own *Order status has been updated from
processing to in_production* activity line; the history table recorded the move.

Screenshot `v0.3-02-more-action-menu.png` is the measurement this feature rests
on: the same order in FluentCart's admin, with its *More Action* menu open —
Change Shipping Status, Cancel Order, Sync Order Statuses, Receipt — and no
order-status control anywhere on the page. `v0.3-01` shows the panel this
plugin adds; `v0.3-03` the page after the change; `v0.3-05` the *Next step →*
button after one more.

### C2 — refusals

Order 708, **unpaid**. The state did not offer `in_production` (paid only).
Posting it anyway was refused with 422 and the payment requirement's own
sentence; the row did not move. Posting the status the order was already on was
refused too ("This order is already on that status."). `v0.3-04` shows the
sentence printed inline beside the control.

### C3 — strict workflow

Order 709 on `in_production` with `pipeline_strict` on: the state dropped
`shipped_done` (the skip) and kept `ship_scheduled` (the neighbour); posting the
skip was refused with the message naming the missed step; the row did not
move. With strict off, `shipped_done` was offered again.

### C4 — next step

Order 710: `next` after `in_production` was `ship_scheduled`; accepted and
written; `next` was then `shipped_done`; accepted; after the last step there is
no next. The linked shipping status still followed (`shipping_status = shipped`).
An order outside the workflow (on `completed`) reports no next step rather than
guessing one.

### C5 — canceled

Order 711 canceled through FluentCart's own route. The order axis reported
`locked` with core's rule spelled out and offered nothing; posting a move was
refused. The shipping axis stayed open — as it is in core — and a shipping
change landed.

### C6 — digital

Order 712 (digital): the shipping axis reported `available: false`, locked with
"This order has nothing to ship…", offered nothing; posting a shipping move was
refused; the column stayed an empty string.

### C7 — Sync Order Statuses

Order 713 on the kept custom status `ship_scheduled`. `POST …/sync-statuses`
succeeded; the row still said `ship_scheduled`; the order core handed back said
so too; the restore left its usual line in the activity. Order 714, same setup
with the global restore switch **off**: the sync overwrote the status to
`processing` — which is the behaviour the switch is documented to expose, and the
proof that C7's first half was the guard and not luck. `v0.3-12` shows the
activity timeline: core's *ship_scheduled → processing* line immediately followed
by the plugin's *Custom order status kept*.

### S1 — the shipping template

Applying it added two custom shipping statuses, relabelled `unshipped` and the
`paid` payment status, and produced the pipeline `unshipped → in_production →
ship_scheduled → shipped`. FluentCart's own shipping map (the one its *Change
Shipping Status* dialog is built from) lists them in that order, before
`shipped`. Applying it twice added nothing. It added no order statuses. Both
templates coexist; the shipping label set first was kept; the order workflow's
last step still links to `shipped`; the shared slug has its own definition on
each axis. `v0.3-07` is the Shipping statuses tab with the workflow strip,
`v0.3-08` the Tools tab with both template buttons, `v0.3-06` FluentCart's own
dialog listing *Awaiting production → In production → Shipment scheduled →
Shipped*.

### S2 — through FluentCart's own route

Order 715, paid. `in_production` and `ship_scheduled` were each accepted by
core's `change_shipping_status`, written to `shipping_status`, left `status`
alone and fulfilled nothing yet; `shipped` was accepted, and core set
`fulfilled_quantity` on the line item. `shipping_status_changed_to_in_production`
fired for a custom slug and `…_to_shipped` for the built-in one; the history
table recorded every step.

### S3 — from the control

Order 716: the shipping axis started at step 1 with `in_production` as the next
step; the change route accepted it; the database agreed; the response reported
step 2 and the next one after it. Strict order workflow does not constrain the
shipping axis.

### S4 — the report

`reports/overview?axis=shipping`: the funnel follows the fulfilment pipeline;
each step's count equals `SELECT COUNT(*) FROM wp_fct_orders WHERE
shipping_status = …`; the paid split equals the same query with the payment
clause; the dwell table knows the custom shipping steps and has timed the
completed stays on them; the stuck list is reported for the shipping axis and
contains the backdated order, which the order-axis stuck list does not; the
overview says which axis it answered and still returns both distribution
tables. `v0.3-09` is the tab with the switch on *Shipping status*.

### S5 — saved views

One view per custom shipping status, beside the order-axis ones; the two axes do
not collide on one slug (different prefixes); a shipping view carries no search
expression; its name carries the live count and the axis marker. Requesting the
Orders list with `active_view = ys_shipping_in_production` returned exactly the
orders on that shipping status and nothing else; an unknown view slug selects
nothing on its own. `v0.3-10` and `v0.3-11` show the views in the SPA.

### S6 — the markup

The order page's widget list carries both panels; the control is addressed to
this order; it has a select per axis and a next-step button; it contains no
`<script>`. A label containing `<script>alert(1)</script>"'` was fed through the
settings sanitiser, which strips the tag before it is ever stored; the widget
contains no markup from the label and the surviving quotes are escaped.

---

## Environment observations, for the record

- **A paid order has no order-status control in FluentCart 1.6.3's admin.**
  Measured in the browser: header buttons *Refund*, *Edit* (disabled), *More
  Action* → Change Shipping Status / Cancel Order / Sync Order Statuses /
  Receipt. The Orders *list* has a bulk status action. This is the gap §2b of the
  README fills.
- **`window.fluentCartAdminApp` has no `editable_shipping_statuses`.** The SPA
  localises `order_statuses`, `editable_order_statuses`, `payment_statuses`,
  `editable_payment_statuses` and `shipping_statuses`; the *Change Shipping
  Status* dialog is therefore built from the display map. Ordering only the
  editable one left the custom steps listed after `unshippable` — seen in the
  browser before `StatusRegistry::shippingStatuses()` was ordered too.
- **`OrderFilter::getSearchableFields()` has no `shipping_status` entry** and no
  filter; a saved view with the expression `shipping_status = x` falls through
  to the free-text branch and matches nothing. Hence the
  `orders_list_filter_query` clause in `Admin\SavedViews`.
- **`BaseFilter::parseAcceptedView()` returns `null` for a saved view**, so
  `fluent_cart/orders_list_filter_query` receives an array whose `active_view`
  is empty; the request parameter is captured on `rest_pre_dispatch` instead.
- **`innerHTML` does not run `<script>`**; a widget that needs behaviour needs an
  enqueued script.
- **`StatusHelper::syncOrderStatuses()` dispatches with `manageStock = true`**
  from *Sync Order Statuses* too, which is what lets `RestoreHandler` tell it
  apart from the operator's own change (`manage_stock = false`).

## Known limitations

- The control reloads the order view after a successful change. The SPA has no
  public "refresh this order" event; patching only the widget would leave the
  header badge, the activity feed and the fulfilment marker stale.
- The stuck list watches only the custom steps on either axis. An order sitting
  on the built-in entry status (`processing` / `unshipped`) is not reported.
- Shipping-axis saved views filter by one column and cannot be combined with
  another saved view; the SPA applies one view at a time, which is also true of
  core's own views.
