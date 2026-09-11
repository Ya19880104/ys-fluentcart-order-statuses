# YS FluentCart Order Statuses

Custom order and shipping statuses for [FluentCart](https://fluentcart.com/) — in the
spirit of YITH WooCommerce Custom Order Status, but built for the way FluentCart
actually works. Add your own workflow states with their own names and colours,
give each one a payment condition, and stop FluentCart overwriting them the
moment a payment lands. Built-in statuses can be renamed too.

* **Version:** 0.1.0
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
| **Enabled** | Off hides it everywhere without deleting the definition — orders already on it keep their value. |
| **Selectable in the admin** | Off keeps the status displayable but removes it from the status dropdown, for statuses only your own code should set. |

Custom **shipping** statuses carry the same fields minus `payment_requirement`
and `on_payment`, which have no meaning on that axis.

Everything lives in one option, `ys_fct_status_settings`, and the whole document
can be exported and imported as JSON from the Tools tab.

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
| The Orders list *tabs* (All / Completed / Processing / On Hold) are a fixed set | Custom statuses appear in the **advanced filter**, which the plugin extends, but not as a top-level tab. |
| The admin badge humanises the slug instead of reading the label map | Worked around with the tagger in §5; with JavaScript disabled the admin shows "Sourcing" rather than "美國採購中". |

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
| `ys_fct_status/patch_admin_labels` | filter — `bool` | return false to leave FluentCart's rendered badge text alone |

---

## 8. Admin screen and REST

**FluentCart → Order Statuses**, a plain WordPress page (no build step, no CDN)
with four tabs: *Order statuses*, *Shipping statuses*, *Built-in labels* and
*Tools*. Each row shows how many orders currently sit on that status, and a
status still in use cannot be removed until its orders are moved.

REST namespace `ys-fct-status/v1`, every route capability-gated
(`PermissionManager::hasPermission(['orders/manage'])`, falling back to
`manage_options`) **and** nonce-checked:

| Route | Method | Purpose |
|---|---|---|
| `/settings` | GET / POST | read or replace the whole status document |
| `/usage` | GET | per-slug order counts for both axes |
| `/migrate` | POST | move every order off one slug onto another, with an activity note per order |
| `/export` | GET | the settings document plus export metadata |
| `/import` | POST | replace everything from an exported file |

---

## 9. Tests

Unit tests — pure logic, no WordPress, no database:

```
php tests/run.php
```

Integration scenarios — the full T1–T15 table against a real site, driving
FluentCart's own REST routes through `rest_do_request()`:

```
wp eval-file tests/seed-fixtures.php      # once
wp eval-file tests/status-scenarios.php
wp eval-file tests/revenue-probe.php
wp eval-file tests/measure-editable-filter.php
```

Results and database evidence: [`docs/TEST-REPORT.md`](docs/TEST-REPORT.md).
