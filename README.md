# YS FluentCart Order Statuses

Custom order and shipping statuses for [FluentCart](https://fluentcart.com/) — in the
spirit of YITH WooCommerce Custom Order Status, but built for the way FluentCart
actually works. Add your own workflow states with their own names and colours,
give each one a payment condition, and stop FluentCart overwriting them the
moment a payment lands. Built-in statuses can be renamed too.

Since 0.2 those states are a **workflow**: an ordered pipeline with a one-click
template, an optional "one step at a time" rule, a shipping status that follows
the order status, and a report that answers the question a production schedule
actually asks — *what is queued, and what has been sitting there too long?*

* **Version:** 0.2.0
* **Requires:** WordPress 6.0+, PHP 7.4+, FluentCart 1.6.0+ (developed and tested against 1.6.3)
* **Text domain:** `ys-fluentcart-order-statuses` (ships with zh_TW)
* **Nothing in FluentCart or WordPress core is patched.** Public filters, one option, one admin page and the plugin's own REST namespace.

---

## 1. The three axes, and which one you actually want

`wp_fct_orders` carries three independent status columns. They are not
interchangeable, and confusing them is the single biggest source of grief when
modelling a fulfilment workflow:

| Axis | Column | Built-in values | Who writes it |
|---|---|---|---|
| **Order status** | `status` `VARCHAR(20)` | `draft` `processing` `completed` `on-hold` `canceled` `failed` | the checkout, every payment gateway, and you |
| **Payment status** | `payment_status` `VARCHAR(20)` | `pending` `paid` `partially_paid` `failed` `refunded` `partially_refunded` `authorized` `payment_scheduled` | payments, refunds and webhooks only |
| **Shipping status** | `shipping_status` `VARCHAR(20)` | `unshipped` `shipped` `delivered` `unshippable` | **nothing, automatically — only a human** |

This plugin lets you add statuses to the **order** and **shipping** axes, and
rename (never add to) the **payment** axis.

> **Put a multi-step fulfilment workflow on the shipping axis.**
> Measured on 1.6.3: nothing in FluentCart writes `shipping_status` by itself. It
> changes when somebody changes it, and never otherwise. The order axis, by
> contrast, is rewritten by the payment code every time money arrives — which is
> the whole reason §3 below exists. "Sourcing in the US → at the US warehouse →
> in transit → customs → delivered" is a shipping workflow, not an order-status
> workflow, and it is far safer there.

Because the columns are `VARCHAR(20)`, a custom slug is capped at **20
characters**. The plugin enforces that, along with a lowercase
`letter`-`[a-z0-9_-]` shape, no collision with a built-in slug, and no
duplicates.

---

## 2. What a custom order status can carry

| Field | Meaning |
|---|---|
| **Label** | What staff and customers see. Translatable through the usual WordPress tooling. |
| **Slug** | What goes in the database column. ≤ 20 chars, auto-derived from the label, editable until you save. |
| **Colour** | A hex colour, used for the badge everywhere the status is shown. |
| **Description** | An internal note; it appears only on the settings screen. |
| **Available on** (`payment_requirement`) | `any` / `paid orders only` / `unpaid orders only`. See §4. |
| **After payment** (`on_payment`) | `keep this status` / `let FluentCart set Processing`. See §3. |
| **Also set shipping to** (`linked_shipping_status`) | One shipping status to apply alongside this one. See §2a. |
| **Enabled** | Off hides it everywhere without deleting the definition — orders already on it keep their value. |
| **Selectable in the admin** | Off keeps the status displayable but removes it from the status dropdown, for statuses only your own code should set. |

Custom **shipping** statuses carry the same fields minus `payment_requirement`
and `on_payment`, which have no meaning on that axis.

Everything lives in one option, `ys_fct_status_settings`, and the whole document
can be exported and imported as JSON from the Tools tab.

---

## 2a. The workflow

The custom order statuses are a **sequence**, not a set. The order they are
listed in on the settings screen is the order of the workflow, and step 1 is
always the built-in `processing` — FluentCart writes that the moment a payment is
recorded, so every paid order passes through it whether you asked for it or not.
Rename it on the *Built-in labels* tab; the template below calls it "Paid".

**Create the standard workflow** builds the shape most shops want:

| Step | Status | Available on | After payment | Also sets shipping to |
|---|---|---|---|---|
| 1 | Paid (`processing`, built-in) | — | — | — |
| 2 | In production (`in_production`) | paid orders only | keep | — |
| 3 | Shipment scheduled (`ship_scheduled`) | paid orders only | keep | — |
| 4 | Shipped (`shipped_done`) | paid orders only | keep | `shipped` |

It is a starting point, not a schema: rename, recolour, reorder, extend or delete
any of it afterwards. Nothing is written until you press the button, nothing you
have already configured is overwritten, and pressing it twice does nothing.

### The dropdown follows the workflow

`fluent_cart/editable_order_statuses` is returned in workflow order, so
FluentCart's own status dropdown reads *Paid → In production → Shipment scheduled
→ Shipped* with the remaining built-ins underneath. Array order is the only lever
that map offers — the dropdown component has no ordering hook of its own.

### Linked shipping status

The last step of an order workflow is usually also a fulfilment fact, and making
staff set it twice is how the two axes drift apart. A custom order status can
therefore name one shipping status, and moving an order into it moves the
shipping status too.

The write goes through FluentCart's `OrderResource::updateStatuses()` rather than
a direct column update, because that is where core resets each physical line
item's `fulfilled_quantity`, validates against `editable_shipping_statuses` and
dispatches the event that fires `shipping_status_changed_to_<slug>`. Writing the
column directly would give the right value and none of the behaviour around it.
A note goes in the order's activity timeline either way:

> **Shipping status updated automatically** — The order status Shipped is linked
> to a shipping status, so the shipping status was changed from "unshipped" to
> "shipped".

Digital and non-shippable orders have no shipping status at all — the column is
an empty string — so they are skipped, and the timeline says so rather than
failing quietly. The binding is **one-way**: changing the shipping status never
changes the order status. Two axes with manual controls on both sides and a
two-way binding between them is a loop waiting to happen.

### Strict workflow (off by default)

With **Strict workflow** on, an order that is on a workflow step may only move to
the step immediately before or after it. A skip is refused with a message that
names the step that was missed:

> The order workflow runs one step at a time. This order is on "In production",
> so it cannot move straight to "Shipped" — the next step is "Shipment
> scheduled". Turn off strict order workflow on the Order Statuses screen to
> allow skipping.

Three things it deliberately does **not** do:

* It never blocks a move *out of* the workflow. Cancelling, completing or holding
  an order has to work from anywhere; a rule that can trap an order is worse than
  no rule.
* It never blocks a move *into* the workflow from outside it. Keeping unpaid
  orders out is `payment_requirement`'s job, and that is a different question.
* It does not reorder anything. The workflow is whatever order you put the
  statuses in.

Enforced in the same two layers as §4: a `422` from `rest_pre_dispatch`, and the
offending slugs dropped from `fluent_cart/editable_order_statuses` for the order
in flight so core's own write-side allow-list agrees.

---

## 3. 🔴 Payment overwrites a custom order status — and what this plugin does about it

This is the one piece of FluentCart behaviour the plugin has to work around, so
it is worth stating precisely. `app/Helpers/StatusHelper.php` contains:

```php
$orderStatus = $this->order->status;
if (!in_array($orderStatus, Status::getOrderSuccessStatuses())) {
    if ($orderPaymentStatus == Status::PAYMENT_PAID) {
        $orderStatus = Status::ORDER_PROCESSING;
    }
}
```

`Status::getOrderSuccessStatuses()` returns a hardcoded `['completed',
'processing']` **with no filter**. A custom status can therefore never be in it:
the moment a payment is recorded, an order sitting on `sourcing` is rewritten to
`processing`. For a proxy-shopping shop that is exactly backwards — "paid" is
when the work *starts*.

There is no pre-write hook on that path, so the plugin acts immediately after
it. `OrderStatusUpdated` dispatches synchronously, inside the same method,
before anything else reads the new value; the plugin listens on
`fluent_cart/order_status_changed` at priority 5 and writes the custom slug back
with a plain `$wpdb->update`, leaving a line in the order's activity timeline:

> **Custom order status kept** — Payment was recorded. FluentCart set this order
> to Processing; YS Order Statuses restored the custom status 美國採購中
> (sourcing) because it is configured to be kept after payment.

Four things make that safe, and each is covered by a test:

1. **No recursion.** The write is `$wpdb->update`, not `StatusHelper` or
   `$order->updateStatus()`, either of which would dispatch another
   `OrderStatusUpdated` straight back into the listener.
2. **Digital orders are handled.** A few lines further down the same method,
   FluentCart auto-completes digital orders using the in-memory model — which
   would undo the restore. When the plugin restores a status it also returns
   `false` from `fluent_cart/order_status/auto_complete_digital_order` for that
   one order.
3. **A deliberate admin change is never undone.** An admin picking "Processing"
   by hand reaches the same action. The discriminator is core's own `manageStock`
   argument: `true` from the payment paths, `false` from
   `OrderResource::updateStatuses()`. The plugin only restores on `true`, and
   only when the order really is paid and the new status really is `processing`.
4. **Compare-and-set.** The write only lands if the row still holds the value
   core just wrote, so a concurrent change is never clobbered.

Per status you choose `keep this status` (default) or `let FluentCart set
Processing`. There is also a **global switch** on the Tools tab that turns the
whole mechanism off.

### What it does not change

`completeRelatedCart()` and the revenue reports are driven by `payment_status`,
not `status`, so neither is affected — see §6.

---

## 4. Payment conditions (`payment_requirement`)

A status can be restricted to paid or unpaid orders. "Paid" means
`payment_status` is one of `paid`, `partially_paid` or `partially_refunded` —
money arrived.

Enforcement happens on the write, with a message that names the status:

> “Paid only step” can only be used on orders that have been paid. This order has
> not been paid yet.

**Why it is enforced on the write rather than hidden in the dropdown** — and this
was measured, not assumed:

`fluent_cart/editable_order_statuses` is applied by
`Status::getEditableOrderStatuses()`, which takes no arguments and passes an
empty array as the filter's second parameter. **The filter never receives the
order.** Worse, the FluentCart admin is a Vue SPA that reads the whole list once
per page load from `window.fluentCartAdminApp`, before any order is open, with
the order id living only in the URL fragment — which never reaches the server.
Per-order filtering of that dropdown is therefore impossible without shipping a
Vue build.

What *is* knowable is the REST route. Every call that carries an order does so as
`/fluent-cart/v2/orders/{id}/…`, and `rest_pre_dispatch` runs before the
controller. The plugin records the id there, which gives two layers of
enforcement inside that one request:

* a clear `422` from `rest_pre_dispatch`, which is what the operator sees; and
* the offending slugs dropped from `fluent_cart/editable_order_statuses` at
  priority 30, so core's own write-side allow-list agrees — covering any code
  path in the same request that does not go through REST.

---

## 5. Labels and colours in the UI

Also measured rather than assumed, and the two surfaces differ:

* **Admin (order list and order detail).** The badge is
  `<span class="badge success"><!---->Completed</span>`. The variant class comes
  from a map of *built-in* slugs, and **the slug itself never reaches the DOM**.
  The text is the raw column value humanised in JavaScript — `us_warehouse`
  renders as "Us Warehouse", and, verified directly,
  `window.fluentCartAdminApp.order_statuses.processing` can be "處理中" while the
  badge still says "Processing". So the label map behind
  `fluent_cart/order_statuses` reaches the status dropdown, the Orders filter and
  every server-rendered surface — **but not the badge**.
* **Storefront (customer dashboard).** The badge is
  `<span class="fct-badge fct-sourcing fct-small">` — an unrecognised slug goes
  straight into a class. That is a CSS hook the admin never gives us.

The plugin closes both gaps from the outside:

* **CSS**, keyed on `[data-ys-status="<slug>"]` **and** `.fct-badge.fct-<slug>`.
  The second means storefront colours work with no JavaScript at all.
* **A small tagger script** that matches a badge's text against every spelling a
  managed slug can be rendered as (the raw slug, the humanised slug, and the
  configured label — so a second pass is a no-op), stamps `data-ys-status` on it
  and swaps the text for the configured label. It only ever touches badges whose
  text is a known spelling of a status this plugin manages; everything else is
  left exactly as FluentCart rendered it, and a slug that is ambiguous (defined
  on two axes with different labels) is deliberately left alone.

The text replacement edits the badge's text node in place rather than assigning
`textContent`, so Vue's comment anchor survives and the component can still patch
itself. To turn the relabelling off and keep only the colours:

```php
add_filter( 'ys_fct_status/patch_admin_labels', '__return_false' );
```

---

## 5a. The Order Status Report

*FluentCart → Order Statuses → **Order Status Report***. Four blocks, all read
straight from `wp_fct_orders`, so the numbers match what you see when you filter
the Orders list by the same status:

* **Workflow funnel** — how many orders are on each step right now. Deliberately
  unfiltered by date: "how much work is queued" is a question about the present,
  and the order placed last month that is still in production is exactly the one
  you need to see.
* **Order statuses / Shipping statuses** — orders and money per status, split into
  paid and unpaid columns. "Paid" means the *payment* status is `paid`,
  `partially_paid` or `partially_refunded` — money arrived. Amounts are the sum of
  `total_amount` in the currency's minor unit; a multi-currency store is told the
  total is unconverted rather than handed a wrong number with a confident symbol
  on it.
* **Time in each status** — average and longest completed stay per step. Only
  stays that have *ended* are counted: an order still sitting on a step has not
  finished its stay, and averaging it in would drag every number towards zero the
  moment a batch of new orders arrives. The ones still open are the next block.
* **Stuck orders** — everything that has been on the same step for longer than the
  threshold (default 3 days, configurable on the Tools tab). For a production
  schedule this is the block that matters.

Each block exports to CSV (UTF-8 BOM so Excel reads Chinese correctly, and every
cell defused against formula injection). There is an optional **daily summary
e-mail** with the funnel and the stuck list, sent by WP-Cron with a catch-up on
the next admin page load — cron on a quiet shop is not a scheduler.

The timings come from this plugin's own table, `{prefix}ys_fct_status_history`,
written from `fluent_cart/order_status_changed` and
`fluent_cart/shipping_status_changed`. Changes from before the plugin was
installed can be recovered once from FluentCart's activity log with **Import
history from the activity log** — best effort, because core stores each change as
a translated sentence rather than as columns; lines it cannot read are skipped
and counted.

Two more places the workflow shows up in FluentCart's own screens:

* **The Orders list** gets one *saved view* per custom status ("In production
  (5)"), through `fluent_cart/admin_table_saved_views`. With four built-in tabs
  already present they appear under **More views**.
* **The order page** gets a **Status history** panel, through
  `fluent_cart/widgets/single_order_page`: both axes on one timeline with how long
  each stay lasted.

### Why the report is not under FluentCart → Reports

Because FluentCart 1.6 has no way to put it there, and this was measured rather
than assumed:

| What you might reach for | Why it does not work |
|---|---|
| `fluent_cart_routes` (JS filter) | Registers a route to a **compiled Vue component**. Using it means shipping a Vue build, a toolchain and a version lock to FluentCart's internal component API. |
| `fluent_cart_dashboard_main` / `_aside` / `_inner` | CSS class names, not template slots. Nothing is rendered into them. |
| `fluent_cart/report/sources_query`, `…/sanitize_params_rules` | Both belong to the traffic-source (UTM) report only. |

What *is* available, and what this plugin uses instead: a WordPress submenu page
of its own, its own REST namespace, `fluent_cart/admin_table_saved_views` for the
Orders list, `fluent_cart/widgets/single_order_page` for the order page, and
`fluent_cart/orders_list` for the list payload. The last one has a caveat worth
knowing: it can add anything to a row, but the admin SPA renders only the columns
declared in its compiled bundle, so `ys_status_meta` reaches the browser and is
ignored there. It is added for anything reading `GET /orders` directly — an export
script, a BI job — not for the screen.

---

## 6. Known limitations

These come from arrays hardcoded in FluentCart with no filter. They are not
bugs in this plugin, and no add-on can route around them without patching core.

| Hardcoded in core | Consequence |
|---|---|
| `Status::getOrderSuccessStatuses()` → `['completed','processing']` | Payment rewrites a custom order status. Handled — see §3. |
| `Status::getOrderFailedStatuses()` → `['failed','canceled']` | A custom status can never mean "failed" for core's purposes. |
| `Status::getOrderPaymentSuccessStatuses()` / `getReportStatuses()` | These read `payment_status`, so **revenue reporting is unaffected by custom order statuses** — measured: FluentCart's own dashboard returned an identical "Order Value (Paid)" with the same order on `sourcing` and on `processing`. |
| `SubscriptionReportService` uses `whereIn('status', getOrderSuccessStatuses())` | The handful of reports that filter by *order status* (not payment status) exclude custom statuses. |
| `OrderResource::updateStatuses()` refuses any change once `status === 'canceled'` | Core behaviour; custom statuses are equally blocked. This is intentional and not worked around. |
| The Orders list *tabs* (All / Completed / Processing / On Hold) are a fixed set — `OrderFilter::tabsMap()` has no filter | Custom statuses appear as **saved views** beside them instead (§5a). With four built-in tabs already there, the SPA shows only the first four entries and puts the rest under **More views**. |
| `BaseFilter::applyAdvancedFilter()` returns immediately unless FluentCart **Pro** is active | A saved view built on an advanced filter would silently match every order on a free store. The views this plugin adds use the simple search expression `status = <slug>` instead, which is not gated. The Orders *advanced filter* UI is extended either way — it just cannot be driven from a saved view without Pro. |
| The admin SPA renders only the columns in its compiled bundle | `ys_status_meta` on `fluent_cart/orders_list` reaches the browser and is not displayed. It is there for API consumers. |
| The admin badge humanises the slug instead of reading the label map | Worked around with the tagger in §5; with JavaScript disabled the admin shows "Sourcing" rather than "美國採購中". Two statuses on different axes that render the *same* word are resolved by slug, so the one whose slug spells that word keeps it. |

Two more, by design:

* **Custom payment statuses are not supported in v1,** and the UI says so.
  `payment_status` drives download permissions (`FileDownloader.php`), refunds,
  customer lifetime value (`Customer.php`) and every revenue report through
  hardcoded lists. A new value would go quietly missing from all of them. You can
  rename the built-in payment statuses, which is safe.
* **Deactivating the plugin never changes an order row.** Orders sitting on a
  custom status keep their value and FluentCart falls back to showing the raw
  slug. Use the **Move orders** button on a status's row to migrate them to a
  built-in status first. Uninstalling does not delete the settings either, unless
  you opt in with `define( 'YS_FCT_STATUS_REMOVE_DATA', true );` — the definitions
  are the only thing that can turn a stored slug back into a name a human
  recognises.

---

## 7. Hooks for other add-ons

Custom statuses get the same per-status actions as the built-ins, so
notifications and integrations work unchanged:

```php
add_action( 'fluent_cart/order_status_changed_to_sourcing', function ( $data ) {
    // $data['order'], $data['old_status'], $data['new_status'], …
} );

add_action( 'fluent_cart/shipping_status_changed_to_us_warehouse', function ( $data ) { … } );
```

This plugin adds two of its own:

| Hook | Type | When |
|---|---|---|
| `ys_fct_status/custom_status_restored` | action — `$orderId, $slug, $data` | after a custom status was written back post-payment |
| `ys_fct_status/linked_shipping_applied` | action — `$orderId, $shippingSlug, $orderSlug` | after a linked shipping status was applied |
| `ys_fct_status/patch_admin_labels` | filter — `bool` | return false to leave FluentCart's rendered badge text alone |
| `ys_fct_status/record_restore_history` | filter — `bool, $orderId, $slug` | return true to also record the post-payment round trip through `processing` in the status history (off by default: it describes core's behaviour, not the order's) |

---

## 8. Admin screen and REST

**FluentCart → Order Statuses**, a plain WordPress page (no build step, no CDN)
with five tabs: *Order statuses*, *Shipping statuses*, *Built-in labels*, *Order
Status Report* and *Tools*. Each row shows how many orders currently sit on that
status, and a status still in use cannot be removed until its orders are moved.

REST namespace `ys-fct-status/v1`, every route capability-gated
(`PermissionManager::hasPermission(['orders/manage'])`, falling back to
`manage_options`) **and** nonce-checked — including the read-only report routes,
because order counts and amounts by status are commercial information and a
nonce-less GET is readable by any page the logged-in shopkeeper happens to open:

| Route | Method | Purpose |
|---|---|---|
| `/settings` | GET / POST | read or replace the whole status document |
| `/usage` | GET | per-slug order counts for both axes |
| `/migrate` | POST | move every order off one slug onto another, with an activity note per order |
| `/export` | GET | the settings document plus export metadata |
| `/import` | POST | replace everything from an exported file |
| `/template` | POST | merge the standard workflow into the current settings |
| `/reports/overview` | GET | distribution, funnel, dwell and stuck list (`since` / `until` optional) |
| `/reports/history` | GET | one order's status timeline (`order_id`) |
| `/reports/export` | GET | one report as CSV (`type` = `distribution` / `shipping` / `dwell` / `stalled`) |
| `/reports/backfill` | POST | rebuild the imported history from FluentCart's activity log |
| `/reports/summary-test` | POST | send the daily summary now |

One table, `{prefix}ys_fct_status_history` (`order_id`, `axis`, `old_status`,
`new_status`, `changed_by`, `source`, `changed_at`), created with `dbDelta`
behind the `ys_fct_status_db_version` option and written **from hooks only** —
nothing accepts a row from a request.

---

## 9. Tests

Unit tests — pure logic, no WordPress, no database:

```
php tests/run.php
```

Integration scenarios — the full tables against a real site, driving FluentCart's
own REST routes through `rest_do_request()`:

```
wp eval-file tests/seed-fixtures.php        # once
wp eval-file tests/status-scenarios.php     # T1–T15  (0.1)
wp eval-file tests/pipeline-scenarios.php   # P1–P5, R1–R7  (0.2)
wp eval-file tests/revenue-probe.php
wp eval-file tests/measure-editable-filter.php
```

`pipeline-scenarios.php` rewrites `ys_fct_status_settings` with the standard
workflow and creates `STATUS-` orders; it touches nothing else.

Results and database evidence: [`docs/TEST-REPORT.md`](docs/TEST-REPORT.md) (0.1)
and [`docs/TEST-REPORT-v0.2.md`](docs/TEST-REPORT-v0.2.md) (0.2).
