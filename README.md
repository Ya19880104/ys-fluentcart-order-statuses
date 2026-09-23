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

Since 0.3 the workflow can be **driven**. FluentCart's admin has no control for
choosing an order status at all — on any order, paid or not — so the order page
gets one, and the shipping axis — whose dialog FluentCart *does* offer, and which
nothing ever overwrites — gets a one-click template of its own. §0 is the
two-minute version of which to use.

Since 0.4 every step can **send an e-mail**, registered into FluentCart's own
notification system rather than beside it: the toggle, the sender, the template
wrapper, the footer and the preview are FluentCart's, and the operator writes
the heading and the message in FluentCart's own editor. §2c.

Since 0.5 a status is **one list, on every order**. The templates no longer
restrict their steps to paid orders or rename FluentCart's built-in statuses,
and the two payment settings of a status sit under a collapsed *Advanced* line
instead of in their own columns. An existing configuration is not changed. §2.

* **Version:** 0.5.0
* **Requires:** WordPress 6.0+, PHP 7.4+, FluentCart 1.6.0+ (the full test suite is run against both 1.6.0 and 1.6.3 on every release)
* **Text domain:** `ys-fluentcart-order-statuses` (ships with zh_TW)
* **Nothing in FluentCart or WordPress core is patched.** Public filters, one option, one admin page and the plugin's own REST namespace.

---

## 0. Two ways to build the same workflow — and which one to pick

Almost every shop that installs this plugin wants the same four states:

> **paid → in production → shipment scheduled → shipped**

There are two places to put them, both one button on the admin screen, and the
choice matters more than anything else on this page.

| | **Fulfilment workflow (shipping axis)** — *recommended* | **Order workflow (order axis)** |
|---|---|---|
| Where the button is | *Order Statuses → Tools* | *Order Statuses → Order statuses* |
| Column written | `shipping_status` | `status` |
| Does FluentCart ever overwrite it? | **No.** Measured on 1.6.3: nothing writes that column by itself, ever. | **Yes** — to `processing`, every time a payment is recorded. §3 is the whole workaround. |
| Can staff change it in FluentCart's own UI? | **Yes** — *More Action → Change Shipping Status* lists your custom steps, in order, on any order. | **No.** FluentCart's admin has no control for choosing an order status on any order — only *Mark As Complete*, *Back to processing* and *Cancel Order*. This plugin supplies one (§2b). |
| Does the customer see it? | Only where the theme prints a shipping status. | Yes — it is the order status on the customer dashboard. |
| Does it mark line items fulfilled? | Yes, at `shipped` — core's own `fulfilled_quantity` bookkeeping. | Only through `linked_shipping_status` (§2a). |

**If the workflow is about getting the goods out, it is a fulfilment workflow,
and it belongs on the shipping axis.** That is what a production
schedule is. Press *Create the fulfilment workflow (shipping axis)* on the Tools
tab and you get:

| Step | Status | Slug | Kind |
|---|---|---|---|
| 1 | Unshipped | `unshipped` | built-in, untouched |
| 2 | In production | `in_production` | custom |
| 3 | Shipment scheduled | `ship_scheduled` | custom |
| 4 | Shipped | `shipped` | built-in, untouched |

`shipped` is deliberately *not* redefined: it is the exact slug
`OrderResource::updateStatuses()` checks when it sets each physical line item's
`fulfilled_quantity`, so a custom "shipped" beside it would look identical to
staff and leave every order half-fulfilled in core's own books. The two custom
steps are inserted **before** it everywhere FluentCart lists shipping statuses.

**The order axis is still the right answer** when the states really are states
of the *order* rather than of the delivery — "awaiting artwork approval",
"awaiting customer confirmation" — or when you want them on the status your
customers already see. Everything in §2a–§4 is about that axis, and 0.3 adds the
control it was missing.

Both templates can be applied to the same store. The slugs `in_production` and
`ship_scheduled` appear on both axes on purpose: they are independent columns
with independent definitions, and the same word for the same step is the only
sane outcome. Neither template renames a built-in status.

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
> workflow, and it is far safer there. §0 has the one-click version.

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
| **Available on** (`payment_requirement`) | Under *Advanced*. `any order` (default) / `paid orders only` / `unpaid orders only`. See §4. |
| **After payment** (`on_payment`) | Under *Advanced*. `keep this status` (default) / `let FluentCart set Processing`. See §3. |
| **Also set shipping to** (`linked_shipping_status`) | One shipping status to apply alongside this one. See §2a. |
| **Enabled** | Off hides it everywhere without deleting the definition — orders already on it keep their value. |
| **Selectable in the admin** | Off keeps the status displayable but removes it from the status dropdown, for statuses only your own code should set. |

Custom **shipping** statuses carry the same fields minus `payment_requirement`
and `on_payment`, which have no meaning on that axis.

The two payment settings are folded under a collapsed **Advanced** line beneath
each order status, because the defaults suit almost every shop: the status is
offered on every order, and it stays put when a payment lands. The line's summary
always says what they are set to, and turns amber when either differs from the
default, so a status somebody did restrict is visible without opening anything.

Everything lives in one option, `ys_fct_status_settings`, and the whole document
can be exported and imported as JSON from the Tools tab.

---

## 2a. The workflow

The custom order statuses are a **sequence**, not a set. The order they are
listed in on the settings screen is the order of the workflow, and step 1 is
always the built-in `processing` — FluentCart writes that the moment a payment is
recorded, so every paid order passes through it whether you asked for it or not.
The template leaves it, and its name, exactly as FluentCart has it.

**Create the standard workflow** builds the shape most shops want:

| Step | Status | Available on | After payment | Also sets shipping to |
|---|---|---|---|---|
| 1 | Processing (`processing`, built-in) | — | — | — |
| 2 | In production (`in_production`) | any order | keep | — |
| 3 | Shipment scheduled (`ship_scheduled`) | any order | keep | — |
| 4 | Shipped (`shipped_done`) | any order | keep | `shipped` |

Before 0.5 the template marked every step *paid orders only* and renamed
`processing` to "Paid" and `on-hold` to "Awaiting payment", which made the
workflow read like a paid list beside an unpaid one. It does neither now; the
fulfilment template likewise stopped renaming `unshipped`. A configuration built
by the old template keeps what it has.

It is a starting point, not a schema: rename, recolour, reorder, extend or delete
any of it afterwards. Nothing is written until you press the button, nothing you
have already configured is overwritten, and pressing it twice does nothing.

### The list follows the workflow

`fluent_cart/editable_order_statuses` is returned in workflow order, so the
order-page control (§2b) lists the moves as *Processing → In production →
Shipment scheduled → Shipped*, with the remaining built-ins underneath.
FluentCart's own admin never renders this list: measured on 1.6.0 and 1.6.3, it
is read into one component's data and not used there, so on FluentCart's side it
is only the server-side write allow-list.

The shipping axis needs **two** maps ordered, and which two is not obvious.
`window.fluentCartAdminApp` carries `order_statuses`,
`editable_order_statuses`, `payment_statuses`, `editable_payment_statuses` and
`shipping_statuses` — and no `editable_shipping_statuses` at all. That filter is
the server-side write allow-list and nothing else. FluentCart's *Change Shipping
Status* dialog is therefore built from the **display** map, which is why
`fluent_cart/shipping_statuses` is ordered too. Ordering only the editable one
left the custom steps listed after `unshippable` in the dialog — seen in the
browser before the second line existed.

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

## 2b. The status control on the order page

**The gap, measured in FluentCart's compiled admin app on 1.6.0 and 1.6.3.**
Not one dropdown in the admin is bound to the order status. The order page's
*More Action* menu offers fixed moves only — **Mark As Complete** (shown only
while the order is on `processing`), **Back to processing** (only while it is
`completed`) and **Cancel Order** — beside the one free choice FluentCart does
offer, **Change Shipping Status**. The Orders list's bulk actions only delete.
That holds for every order, paid or not, so however many custom order statuses
are registered, FluentCart's own UI cannot put an order on one. (Earlier
versions of this README said the gap was specific to *paid* orders and that the
Orders list had a bulk status action. Both were wrong.)

So the *Status history* panel this plugin already owns grew a sibling above it —
**Order workflow** — which shows, for both axes at once:

* the status the order is on now, in its configured colour, and which step of
  the workflow that is;
* a dropdown of **only the moves this order is allowed to make**, in workflow
  order, and a **Change** button. With the defaults that is every enabled status,
  on every order: one drops out only if it is disabled, if strict mode forbids
  the jump, or if someone restricted it under *Advanced*;
* a **Next step →** button when the order is on a workflow step and there is one
  after it.

Three things about how it works are worth knowing:

**The list and the write are one rule, not two.** `Pipeline\Changer` builds the
dropdown by calling `Status::getEditableOrderStatuses()` with the order in scope
— the same list `OrderResource::updateStatuses()` validates against, already
narrowed by `payment_requirement` and `pipeline_strict` — and then asks those two
guards for their sentence about every remaining slug. An option that would be
refused is never offered. When one is refused anyway — because the page was left
open while somebody else changed a setting — the `422` appears **inline, beside
the control**, in exactly the words the REST veto would have used:

> The order workflow runs one step at a time. This order is on "Shipment
> scheduled", so it cannot move straight to "Paid" — the next step is "In
> production". Turn off strict order workflow on the Order Statuses screen to
> allow skipping.

**The write goes through FluentCart.** `POST ys-fct-status/v1/orders/{id}/change`
hands the change to `OrderResource::updateStatuses()` with core's own
`change_order_status` / `change_shipping_status` action and `manage_stock:
false` — the same call the admin dropdown makes, and the same one
`Pipeline\LinkedShipping` has made since 0.2. Everything downstream therefore
still happens: core's validation, its canceled-order refusal, `fulfilled_quantity`
on the shipping axis, the `OrderStatusUpdated` event and with it every
`*_status_changed_to_<slug>` action, FluentCart's own activity line, this
plugin's history row and its linked-shipping follow-up. `manage_stock: false` is
load-bearing — it is what tells `Payment\RestoreHandler` this is a person and
not a payment, and sending `true` would make the restore undo the operator's own
move.

**There is no inline script.** FluentCart injects an `html` widget's content
with `innerHTML`, which does not execute script nodes, so the markup is inert
and the behaviour lives in `assets/admin/order-changer.js` — enqueued on
FluentCart's own screens, listening through one delegated handler on `document`,
and costing nothing until a click lands inside the control. A successful change
reloads the order view, because it moves more of the page than this one panel:
the header badge, the activity feed, the fulfilment marker on the order items
and — when the new status carries a linked shipping status — the other axis of
the control itself. A refusal never reloads.

Two axes, two sets of rules, and the difference is the point:

| | Order axis | Shipping axis |
|---|---|---|
| `payment_requirement` | enforced | not applicable — money is not a shipping question |
| `pipeline_strict` | enforced | not applicable — it is defined over the order-status workflow |
| Canceled order | closed, with core's reason shown | **open**, exactly as it is in core |
| Digital / non-shippable order | open | closed, because the column is an empty string and inventing a value would be a lie |

The control is only rendered for a role that could use it
(`orders/manage`); the history panel below it keeps the lower `orders/view` bar
it has always had.

---

## 2c. E-mail notifications

A workflow step is only useful if somebody hears about it, so every enabled
custom status can send an e-mail — **inside FluentCart's own notification
system**, not beside it.

Nothing about the mail lives on this plugin's screen. For each custom status,
four rows appear in **FluentCart → Settings → Email Configuration →
Notifications**, under a group called *Custom Order Statuses*:

| Notification name | Fires on | Goes to |
|---|---|---|
| `ys_status_order_<slug>_customer` | `fluent_cart/order_status_changed_to_<slug>` | the customer |
| `ys_status_order_<slug>_admin` | same | the admin address in Mailing Settings |
| `ys_status_shipping_<slug>_customer` | `fluent_cart/shipping_status_changed_to_<slug>` | the customer |
| `ys_status_shipping_<slug>_admin` | same | the admin address in Mailing Settings |

**All four are off by default.** Adding a workflow step must never start mailing
a shop's customers.

Because they are FluentCart's own notifications, they get FluentCart's own
on/off switch, sender name and address, reply-to, template wrapper, footer,
preview and shortcode picker. The only thing this plugin adds to that screen is
two extra fields on the editor:

* **Heading** — one line, bold, at the top of the mail.
* **Message** — free text; each line becomes its own paragraph.

Both accept shortcodes (`{{order.invoice_no}}`, `{{order.customer.full_name}}`,
`{{settings.store_name}}` …), because FluentCart resolves them over the finished
body after the template has run. The subject is FluentCart's own field and
accepts the same shortcodes; the defaults are *Order #{{order.invoice_no}} —
«label»* for the customer and *Order #{{order.invoice_no}} is now «label»* for
the admin.

The body itself is a template inside this plugin, rendered by FluentCart's view
renderer through `fluent_cart/email/template_view_path`. It contains the
heading, the message, the status badge in the colour configured on the Order
Statuses screen, the order summary table, the delivery address on a physical
order, and a *View order* button — the customer's account page for a customer
mail, the admin order screen for an admin copy.

**Free versus Pro.** FluentCart free does not let anyone edit the *body* of any
notification — `EmailNotificationController::update()` strips `email_body`
unless FluentCart Pro restores it. That applies to core's notifications and to
these. The heading and the message are this plugin's answer to that: they are
the editable part of the body on a free store, and they keep working on Pro.

**Two mails on one change is possible, and intended.** If a custom order status
carries *Also set shipping status to* → *Shipped* (§2a), moving an order to it
changes both axes, and FluentCart's own "Order has been shipped" notification —
which is active by default — fires alongside ours. Both mails are correct. The
notification's description says so on the screen; switch one of the two off if
the customer should only get one.

**What never sends a mail:**

* **Move orders** (§8, `/migrate`). It rewrites the status column with one
  UPDATE and fires no event, by design — moving a thousand orders off a deleted
  status is not a thousand things the customer needs to hear about.
* **The payment restore** (§3). Writing a custom status back after FluentCart
  overwrote it does not fire a status-changed event either, so paying for an
  order does not re-announce the step it was already on.

**The kill switch.** A staging copy of a production database has real addresses
in it. One line in `wp-config.php` stops every mail this plugin would send,
without touching the operator's toggles:

```php
define( 'YS_FCT_STATUS_DISABLE_EMAILS', true );
```

It is applied on `fluent_cart/should_send_email_notification` and only ever
suppresses this plugin's own notifications — FluentCart's receipts are none of
its business. The same filter also drops a *customer* mail for an order with no
customer address; the admin copy still goes out, because an order in that state
is exactly the one a shop wants to hear about.

**Where the heading and message are stored.** In one option,
`ys_fct_status_email_content`, keyed by notification name. Core stores the
toggle and the subject itself, keyed by the same name — so renaming a status
keeps both, and deleting one leaves core's row behind harmlessly while this
plugin prunes its own. The option travels with **Export** and **Import** (§8)
under a top-level `email_content` key; an export written by 0.3 has no such key
and imports without touching whatever is stored.

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

### "Sync Order Statuses" reaches the same code, and the guard holds

FluentCart's order page has a *More Action → **Sync Order Statuses*** entry that
recomputes an order's payment and order status from its transactions. On an
order sitting on a kept custom status it runs the block above again — the order
is paid, the custom slug is not in `['completed','processing']`, so core writes
`processing`. **It is the same overwrite, from a button rather than from a
payment**, and it was worth checking rather than assuming, because the button
is the one an operator is most likely to press when something looks wrong.

The discriminator covers it. `StatusHelper::syncOrderStatuses()` dispatches
`OrderStatusUpdated( …, $manageStock = true, … )`, the same as every other
payment path, so `RestoreHandler` writes the custom slug straight back and
leaves its usual line in the activity timeline. Verified in the browser on 1.6.3
and in `tests/changer-scenarios.php` (C7), from both directions: with the
restore switched **off**, the same button really does leave the order on
`processing`. No change was needed.

One cosmetic consequence, and it is core's line rather than ours: the activity
timeline gains an *"Order status has been updated from `ship_scheduled` to
`processing`"* entry from FluentCart, immediately followed by this plugin's
*"Custom order status kept"* explaining that it was put back. The order row
never actually held `processing`, and the status history table records nothing —
a round trip that ends where it started is not a status change.

### What it does not change

`completeRelatedCart()` and the revenue reports are driven by `payment_status`,
not `status`, so neither is affected — see §6.

---

## 4. Payment conditions (`payment_requirement`)

**Optional, and off by default.** Every status is offered on every order unless
you restrict it here, under *Advanced* on the status's row; the templates never
do.

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

### Which workflow the report is about

At the top of the tab is a switch: **Order status / Shipping status**. It picks
which of the two workflows the funnel, the timings and the stuck list describe —
because those three read a *sequence of steps*, and since 0.3 there are two such
sequences. Each of the three headings carries the axis beside it, so a screenshot
of the funnel can never be mistaken for the other one.

The two distribution tables are not affected and are always both shown: they read
a column rather than a workflow, and a shop wants to see both columns of its own
store. The CSV for the timings and the stuck list follows the switch, and carries
the axis in the filename so exporting both workflows into one folder gives two
files whose names say which is which.

The stuck list uses the same rule on both axes: only the **custom** steps are
watched. An order that has been `completed` for a month is not stuck, it is
finished — and neither is one that has been `shipped` for a month, or one sitting
on `unshipped` because nobody has paid for it yet.

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

* **The Orders list** gets one *saved view* per custom status on **both** axes —
  "In production (5)" for the order status, "In production · shipping (4)" for
  the shipping one — through `fluent_cart/admin_table_saved_views`. With four
  built-in tabs already present they appear under **More views**. The axis marker
  is not decoration: the two axes can hold the same slug, and two views with the
  same name and different counts would be unreadable.
* **The order page** gets an **Order workflow** control (§2b) and a **Status
  history** panel, through `fluent_cart/widgets/single_order_page`: both axes on
  one timeline with how long each stay lasted.

### Why a shipping saved view is built differently

An order-axis view carries the search expression `status = <slug>`, which
`OrderFilter::applySimpleOperatorFilter()` resolves on its own. A shipping one
cannot: measured on 1.6.3, `OrderFilter::getSearchableFields()` knows `id`,
`status`, `invoice`, `payment`, `payment_by` and `customer` (plus `license` with
Pro), the method is `static` with no filter, and **`shipping_status` is not in
it**. An expression naming any other column falls through to the free-text
branch, which looks for the whole string `shipping_status = shipped` inside
invoice numbers, customer names and product titles — and therefore matches
nothing at all.

So a shipping view carries no search expression, and the clause is added on
`fluent_cart/orders_list_filter_query`, the one filter FluentCart applies to the
finished list query. The slug comes from the request's `active_view`, captured on
`rest_pre_dispatch` — necessary because `BaseFilter::parseAcceptedView()` returns
`null` whenever the incoming view turns out to be a saved view rather than one of
core's fixed tabs, so the array handed to that filter says the active view is
nothing. The captured slug is looked up against the configured statuses before it
is used; a view slug is not a free text channel into a `WHERE` clause, and the
fact that it is parameterised is not a reason to accept one.

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
| `OrderResource::updateStatuses()` refuses any change once `status === 'canceled'` | Core behaviour; custom statuses are equally blocked. This is intentional and not worked around — the order-page control says so and offers nothing, rather than offering moves that would all come back as a 400. The *shipping* status of a canceled order can still be changed, which is also core's behaviour. |
| FluentCart's admin has **no control for choosing an order status**, on any order | Only *Mark As Complete* (from `processing`), *Back to processing* (from `completed`) and *Cancel Order*. §2b adds a control; §0 explains why the shipping axis, whose dialog FluentCart does offer, avoids the problem. |
| `OrderFilter::getSearchableFields()` has no `shipping_status` entry, and no filter | A saved view cannot filter the Orders list by shipping status with a search expression. Worked around on `fluent_cart/orders_list_filter_query` — see §5a. |
| The Orders list *tabs* (All / Completed / Processing / On Hold) are a fixed set — `OrderFilter::tabsMap()` has no filter | Custom statuses appear as **saved views** beside them instead (§5a). With four built-in tabs already there, the SPA shows only the first four entries and puts the rest under **More views**. |
| `BaseFilter::applyAdvancedFilter()` returns immediately unless FluentCart **Pro** is active | A saved view built on an advanced filter would silently match every order on a free store. The views this plugin adds use the simple search expression `status = <slug>` instead, which is not gated. The Orders *advanced filter* UI is extended either way — it just cannot be driven from a saved view without Pro. |
| The admin SPA renders only the columns in its compiled bundle | `ys_status_meta` on `fluent_cart/orders_list` reaches the browser and is not displayed. It is there for API consumers. |
| Free FluentCart strips `email_body` on save (`EmailNotificationController::update()`); only Pro's `fluent_cart/prepare_email_template_data` puts it back | The **body** of a notification cannot be edited on a free store — core's own notifications included. The heading and message fields this plugin adds (§2c) are the editable part of the body instead, and keep working on Pro. |
| `EmailNotificationRequest::sanitize()` runs `sanitize_text_field` over `settings.extra`, which collapses every run of whitespace (newlines included) to one space | A multi-line message typed in FluentCart's editor would arrive flattened. Worked around by capturing the raw body on `rest_pre_dispatch` before the request guard runs — see `Email\RequestCapture`. |
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

Since 0.4 those same actions are what the per-status e-mail notifications are
registered on (§2c) — `Email\Dispatcher` binds one per enabled slug and calls
`EmailNotificationMailer::mailEmailsOfEvent()`, because core fires the actions
but binds none of them.

FluentCart filters this plugin consumes on the e-mail side, all public:

| Hook | Used for |
|---|---|
| `fluent_cart/email_notifications` | registering four notifications per custom status |
| `fluent_cart/email_notification_data` | filling `settings.extra` in the editor payload from this plugin's storage |
| `fluent_cart/email_notification_updated` | storing the heading and message after FluentCart saves a notification |
| `fluent_cart/email/template_view_path` | pointing the view renderer at `templates/email/status-changed.php` |
| `fluent_cart/should_send_email_notification` | the kill switch, and dropping a customer mail with no customer address |

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
Both workflow tabs draw the workflow as a numbered strip above the table, so the
effect of reordering a row is visible before anything is saved.

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
| `/export` | GET | the settings document, the e-mail content map (`email_content`) and export metadata |
| `/import` | POST | replace everything from an exported file; a 0.3 file without `email_content` imports without touching the stored e-mail text |
| `/template` | POST | merge a workflow template into the current settings (`axis` = `order` / `shipping`) |
| `/orders/{id}/state` | GET | where one order stands on both axes, the moves it may make, and its next step |
| `/orders/{id}/change` | POST | move one order (`axis` = `order` / `shipping`, `status` = slug), through `OrderResource::updateStatuses()` |
| `/reports/overview` | GET | distribution, funnel, dwell and stuck list (`axis`, `since` / `until` optional) |
| `/reports/history` | GET | one order's status timeline (`order_id`) |
| `/reports/export` | GET | one report as CSV (`type` = `distribution` / `shipping` / `dwell` / `stalled`, `axis` optional) |
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
wp eval-file tests/changer-scenarios.php    # C1–C7, S1–S6  (0.3)
wp eval-file tests/email-scenarios.php      # E0–E10  (0.4)
wp eval-file tests/revenue-probe.php
wp eval-file tests/measure-editable-filter.php
```

Every suite is run against **two** sites on every release — one on the oldest
supported FluentCart (1.6.0, which is what production runs) and one on the
newest tested (1.6.3). `tests/email-scenarios.php` never lets a mail reach
`mail()`: it short-circuits `wp_mail()` on `pre_wp_mail` and asserts against
what would have been sent.

Each scenario file rewrites `ys_fct_status_settings` and creates `STATUS-`
orders; they touch nothing else. Run them in the order above if you want the
site left holding the 0.4 configuration.

The suites, the fixtures and the per-release test reports (with the database
evidence behind every assertion) live in the development tree and are not part
of the published package.
