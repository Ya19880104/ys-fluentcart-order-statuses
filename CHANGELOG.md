# Changelog

All notable changes to YS FluentCart Order Statuses.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.7.0] — 2026-10-05

The order page's *Order workflow* card stops repeating *More Action*: it shows
where the order stands and its next step, and an order status is chosen in one
place. Nothing about an existing configuration or the e-mails changes. The
plugin is also quiet on PHP 8.4 and 8.5, and Move orders is tested past its
first batch.

### For shop staff

- **The Order workflow card is shorter.** For the order status and the
  shipping status it shows the status the order is on, which step of the
  workflow that is, why nothing can be changed when nothing can (a canceled
  order, an order with nothing to ship), and the **Next step →** button. The
  *Move to…* list and the *Change* button are gone from both lines, and each
  line says where they went: "Other statuses: More Action → Change Order
  Status" (or "… → Change Shipping Status").
- **Any other status is chosen from More Action.** *More Action → Change Order
  Status* (added by this plugin in 0.6) for the order status, FluentCart's own
  *More Action → Change Shipping Status* for the shipping status. FluentCart's
  shipping dialog lists every shipping status it displays, including the
  order's current one and a custom one whose *Selectable in the admin* is off;
  choosing either is refused by FluentCart and changes nothing.
- **A canceled order keeps its shipping list on the card.** FluentCart offers no
  *Change Shipping Status* on a canceled order, although the shipping status can
  still change (for example to *Delivered* after a return), so there the card
  lists the shipping moves on screen, under a sentence saying why.
- **The card keeps a way back for the order status.** Its order-status list
  comes back, under a sentence saying why, when the *Change Order Status* entry
  cannot be used on the page: four seconds after the card appears there is no
  More Action trigger the entry can be added through, or the page is not this
  order's own view; the More Action menu opens but the entry cannot be added to
  it; or the entry's dialog cannot load the order because of a network failure
  or a server error — and then the dialog's message points to the list. When
  the dialog is refused because the page has been open too long (its session
  has expired), it says to reload the page instead, and shows no list that
  would be refused the same way. The rules are the same as before: *Completed*
  and *Canceled* ask first, *Completed* only on a paid order, and a cancel
  returns the items to stock.
- After a change from the card, a page that stays where it is — FluentCart asks
  "Leave site?" when the order has unsaved edits, and the answer was *Stay* —
  brings the card up to date and lets its buttons be used again, instead of
  leaving them greyed out.
- On a server running PHP 8.4 or newer that logs deprecation notices (for
  example with `WP_DEBUG` on), the plugin no longer writes "Implicitly marking
  parameter … as nullable is deprecated" lines to the PHP error log.

### Changed (technical)

- `OrderWidget::renderChanger()` — the widget's content and the `html` of
  `GET orders/{id}/state` — draws, per axis, the head (badge, `standing` note,
  step), then either the lock sentence, "There is nowhere for this order to move
  on this axis.", or the Next step button in its own
  `<p class="ys-fct-changer-row">`, then `<p class="ys-fct-changer-hint"
  data-ys-changer-hint="<axis>">` naming the More Action entry. When the order
  axis has targets it also carries `<div class="ys-fct-changer-fallback"
  data-ys-changer-fallback="order" hidden>`: a note, the
  `data-ys-changer-select="order"` select with the same options and
  `data-ys-confirm` flags as before, and the `data-ys-changer-go="order"` button.
  The shipping axis has a select and a Change button only on a canceled order
  (the order axis's `current` is `canceled`) with shipping targets: `<div
  class="ys-fct-changer-direct" data-ys-changer-direct="shipping">`, not hidden
  and without a hint line, holding a note, `data-ys-changer-select="shipping"`
  and `data-ys-changer-go="shipping"`. The widget's `subtitle` now describes the
  card's role and where statuses are changed; FluentCart 1.6.0 and 1.6.3 hand it
  to their card header, which does not display it.
- `assets/admin/order-changer.js` reveals the fallback (removes `hidden`, and
  hides that axis's `data-ys-changer-hint` line) when the More Action entry
  cannot be used: 4 s after the card appears (checked every 250 ms) the page is
  not the card's own `#/orders/<id>/view` route or has no
  `.fct-order-bulk-action-modal .fct-more-option-wrap [aria-controls]`; the
  menu lookup after activity on the trigger runs out (21 tries, 50 ms apart),
  or keeps throwing, while the trigger says `aria-expanded="true"`; or the
  dialog's `GET orders/{id}/state` fails without an HTTP status (network), with
  a `5xx`, or answers without an order axis. A card is noticed by one
  `MutationObserver` whose callback queues at most one `querySelectorAll` every
  100 ms. Once revealed for an order, the fallback is revealed at once on every
  card re-rendered for it until the page is left. The submit path is unchanged —
  `submit()` → `changeStatus()` → `POST orders/{id}/change` — and so are the
  confirmation, `confirmed: true` and the server's rules.
- More activity on the More Action trigger while the menu lookup is running
  restarts its count (it used to be ignored), so a click near the end of a
  hover's lookup still finds a menu created late.
- `fetchState()` rejects with the HTTP `status` on the Error. The dialog's
  `loadFailed` sentence names the list it has just revealed; when there is none
  to reveal (an order with no move, or no card on the page) the dialog shows the
  server's reason or the new `notLoaded` sentence. On a `401` or `403` it shows
  the new `reloadPage` sentence ("%s Reload the page and try again.", `%s` the
  server's reason) and reveals nothing; on any other `4xx`, the server's reason.
- After a successful change, `afterChange()` no longer gives up when the page
  has fired `beforeunload`: 1.5 s on, a page still marked as leaving is looked
  at again after `STAY_WAIT` (5 s), and if it is still there the cards are
  re-rendered from `GET orders/{id}/state`, `leaving` is cleared and the card's
  busy state (`busy`, `.is-busy`) is released; that second look never reloads
  the page itself. A card re-rendered in place is released the same way.
  Before, both left the card's buttons inert until a manual reload.
- `assets/admin/order-changer.css`: `.ys-fct-changer [hidden] { display: none
  !important; }`, so the attribute holds whatever display rule FluentCart's page
  brings, and spacing for the fallback, the canceled order's shipping list and
  the hint line.
- Strings: new — the card's subtitle, the two "Other statuses: More Action → …"
  lines, the fallback's note, the canceled order's shipping-list note,
  `notLoaded`, `reloadPage`; changed — `loadFailed`; dropped — the old
  subtitle. zh_TW updated and recompiled; FluentCart's *Change Shipping Status*
  reads 「變更出貨狀態」 in every zh_TW string, as it already did on the settings
  screen.

### Fixed (technical)

- The 22 optional array parameters that default to `null` now say so in their
  type: `?array $settings = null` instead of `array $settings = null`, in
  `Settings`, `Pipeline\Changer`, `Email\NotificationRegistry`,
  `Support\Labels` and `Reports\DailySummary`. PHP 8.4 deprecated the implicit
  form. Nullable types exist since PHP 7.1, so the minimum stays PHP 7.4; no
  caller, argument or return value changes. Every shipped PHP file now lints
  without a deprecation notice on PHP 8.5.

### Tests

- New unit group W (`tests/OrderCardTest.php`) renders the card from
  hand-written states: nothing to choose from on screen, one fallback, on the
  order axis, rendered hidden, with exactly the targets in order and the
  confirmation flags; the hint line on each open axis; on a canceled physical
  order, the shipping list on screen with no hint and no fallback, and none on a
  canceled digital one; Next step on both; the no-next-step, nowhere-to-move and
  locked lines; the notes; escaping of labels in the list and in the Next step
  button's attributes. For the stylesheet and the script it only matches source
  text: the `[hidden]` rule, and the lines that watch, reveal, handle the
  dialog's statuses and release the card.
- What the script does is run in a browser by the new
  `tests/order-changer-browser.py` (Playwright and Chrome, 56 checks): a page
  shaped like FluentCart's order view with the cards `OrderWidget` renders,
  every REST answer scripted and the page's clock paused. The fallback stays
  hidden while More Action works, also under a page rule setting `display` on
  every `div`; it is shown after the wait with no trigger or on another route,
  not before, and at once on a re-rendered card; an open menu that cannot take
  the entry shows it, a hover does not, and a late menu still gets the entry;
  the dialog's 401, 403, 404, 500, network failure and empty answer each give
  the right sentence and reveal or not, a server reason holding `$&` included;
  a page that stayed after `beforeunload`, or refreshed in place, gets a
  working card back; and a canceled order's shipping list posts the shipping
  axis. Each of 15 mutations
  of the script — the wait's reveal removed, a reveal at once, the re-render
  path, the initial scan, the menu-open reveal, the 401/403 and 4xx branches,
  the release, the stay check and others — fails it.
- `changer-scenarios` C5 and S6, `guard-scenarios` A1 and N restate the card
  for 0.7 from real orders: no list or Change button on screen; the hint lines;
  the order-axis list present, hidden, with the same targets and flags as the
  dialog (no *Completed* on an unpaid order, *Canceled* and *Completed*
  flagged); nothing on the shipping axis; Next step intact; the state route's
  `html` byte for byte the order page's widget; a canceled order's card with its
  lock sentence, no fallback, and its shipping moves on screen — exactly the
  ones the route accepts and the state route reports. S6's escaping probe is
  now an order status, since an open order's card lists no shipping moves.
- `guard-scenarios` A4b puts 1001 orders on a status of their own and empties
  it with `POST migrate`. It watches the statements the move sends — two full
  batches of 500 and a partial third, each one `UPDATE` of the orders and one
  history `INSERT` of the same size — then checks every order, not a sample:
  the count the route reports, nothing left on the source, each order on the
  target with `updated_at` refreshed, exactly one `migrate` history row and
  one activity line per order, and no mail. The orders are copies of one
  fixture order's row and are removed again once the section passes. All
  suites run on FluentCart 1.6.0 and 1.6.3, on PHP 8.2 and 8.5.

## [0.6.0] — 2026-10-04

Guard rails and records. Nothing about an existing configuration changes on
upgrade; what changes is what the screens and the routes will let happen, and
what gets written down when something does.

### For shop staff

- **The order status is changed where FluentCart changes the shipping status.**
  The order page's *More Action* menu has a new first entry, **Change Order
  Status**, which opens an *Update Order Status* dialog that looks and works like
  FluentCart's own *Update Shipping Status*. The *Order workflow* card in the
  sidebar is still there and does the same thing.
- **Completed and Canceled are never one click away.** Both ask first, saying
  what they mean. *Completed* is offered only on an order that has been paid,
  as in FluentCart's own *Mark As Complete*. Pressing Return in the card's
  dropdown no longer changes the status — only the button does. Canceling from
  the card or the dialog now returns the items to stock, as FluentCart's own
  *Cancel Order* does.
- **A status orders are on cannot be removed or renamed by accident.** Saving
  refuses with the status and the number of orders on it. The slug of a saved
  status can no longer be edited. Switching a status off asks first when orders
  are on it, and no longer deletes its e-mail heading and message.
- **Move orders asks first and leaves a trace.** It says how many orders move,
  from where to where, and that no e-mail goes out. It only moves orders to
  *Processing*, *On Hold* or one of your enabled statuses (*Unshipped* or an
  enabled status on the shipping axis) — never to *Canceled*, *Completed*,
  *Shipped* and the like. Every moved order shows the move in its activity and
  in its status history.
- **Orders on a status that no longer exists are no longer invisible.** The
  Order Statuses screen warns at the top, and *Tools* lists them with a Move
  orders button. On the order page such a status reads "not defined — this
  status was removed"; a switched-off one reads "disabled".
- **Import shows what it will change before it changes it, and can be undone.**
  Only a file exported by this plugin is accepted. The confirmation lists the
  statuses added, removed and changed and the settings that flip. *Tools →
  Export / import* offers **Undo the last import** afterwards.
- **The Tools tab has its own Save changes button.**
- **The order page's status history shows the latest changes**, not the
  earliest, and says how many older ones are not shown. A cancellation is
  recorded as leaving the status the order was really on.
- **Two people on the Order Statuses screen at once no longer undo each
  other.** A Save, Import or template from a screen that is out of date is
  refused with a message to reload. The template buttons also refuse while you
  have unsaved changes on the screen.
- **A status for "unpaid orders only" is no longer put back after payment.**
  Its *After payment* setting is fixed at *Let FluentCart set Processing*.
- **A new status cannot take a slug FluentCart already uses on another axis**
  — an order status called `shipped`, say, which would rename FluentCart's own
  shipping badge. A status already saved with such a slug is left alone.

### Changed (technical)

- `POST orders/{id}/change`: `completed` is refused with `422` on an unpaid
  order; `completed` and `canceled` are refused with `409`
  (`ys_fct_status_confirm_required`) unless the body carries `confirmed: true`;
  a cancel is handed to `OrderResource::updateStatuses()` with
  `manage_stock: true`, everything else keeps `false`. Targets carry a
  `confirm` flag. `GET orders/{id}/state` also returns the card's markup
  (`html`) and, per axis, `standing` (`builtin` / `enabled` / `disabled` /
  `undefined`).
- `POST settings` refuses (`422`, `ys_fct_status_in_use`) a document that drops
  or re-slugs a stored status with orders on it, per axis, before writing.
- `POST import` accepts only the export envelope (`plugin` and
  `settings.order` / `settings.shipping` lists) — `400` otherwise, `{}` included;
  runs the same slug and in-use checks as Save; `dry_run: true` returns the
  diff (`changes`, `summary`, `empty`) without writing. A real import stores the
  replaced settings and e-mail content in `ys_fct_status_settings_backup` (not
  autoloaded; time and user); `POST import/undo` restores it under the same
  in-use rule. Uninstall with data removal deletes the option.
- `POST migrate`: the source may not be a built-in of its axis and the target
  must be `processing`, `on-hold` (order axis), `unshipped` (shipping axis) or an
  enabled custom status — `400` otherwise. Every order on the source is moved
  in batches of 500 with no cap, `updated_at` is refreshed (GMT, as FluentCart's
  models write it), and each gets a history row with the new source `migrate`
  and an activity line. `OrderRepository::migrateStatus()` is replaced by
  `moveOrders()`.
- `usage` gains `orphans`: per axis, slug → count for values that are neither
  built-in nor defined (enabled or not).
- The payment-condition and strict-workflow vetoes stand aside for users who
  may not change order statuses, so FluentCart's own 401 / 403 answers them.
- A cancellation's history row takes its old status from an uncached read made
  on `rest_pre_dispatch` for the two status-changing routes, or from the
  order's latest order-axis history row — FluentCart's event carries the
  shipping status there in 1.6.0 and 1.6.3.
- `ContentStore::pruneOrphans()` keeps the text of every defined status, enabled
  or not.
- The order page's history panel selects the newest 40 rows.
- `GET settings` (and every settings-screen response) carries a `revision`, a
  fingerprint of the stored settings. `POST settings`, `POST import` and
  `POST template` refuse with `409` (`ys_fct_status_stale`) when the revision
  sent back no longer matches; a request without one is not checked.
- `Payment\RestoreHandler` never restores a status whose payment condition is
  `unpaid_only`; the settings screen holds such a status's *After payment* at
  `let_core_decide`.
- A **new** status whose slug is a built-in of another axis (order, payment or
  shipping) is refused with `422` (`reserved_elsewhere`); stored ones are left
  alone.

### Tests

- New `tests/guard-scenarios.php` covering every item above with refusing and
  accepting cases, and unit cases for the new rules. `status-scenarios` T6, T11
  and T13 and `email-scenarios` E5 now state the new restore, Move and Save
  behaviour;
  `pipeline-scenarios` R5 no longer assumes the activity log is shorter than the
  backfill's 2000-line window. All suites run on FluentCart 1.6.0 and 1.6.3.

## [0.5.0] — 2026-09-23

A status is now **one list, on every order**. An existing configuration is not
changed; this is about what the templates create and how the settings screen
presents the two payment settings.

### Changed

- **The templates no longer restrict their steps by payment.** The order-axis
  template used to mark *In production*, *Shipment scheduled* and *Shipped* as
  *paid orders only*, so an unpaid order was offered a different list from a paid
  one. They are now *any order*, the same default a status added by hand has
  always had. `on_payment` stays `keep`: that is the guard against FluentCart
  overwriting the status with *Processing* when a payment lands, not a
  restriction.
- **The templates no longer rename FluentCart's built-in statuses.** They used
  to relabel `processing` to "Paid", `on-hold` and the pending payment status to
  "Awaiting payment", and `unshipped` to "Awaiting production" — which made the
  workflow read like a paid list beside an unpaid one. FluentCart's own names
  are left as they are; the *Built-in labels* tab still renames them for a shop
  that wants to.
- **The two payment settings of an order status moved under *Advanced*.**
  *Available on* and *After payment* are no longer columns of the order-status
  table; they sit behind a collapsed *Advanced* line beneath each status. Its
  summary always shows what they are set to, and turns amber when either differs
  from the default, so a restricted status is still visible at a glance.
- The first step of the order workflow is described as "Set by FluentCart when
  a payment is recorded" instead of "Paid — set by FluentCart".

### Fixed

- **Documentation of FluentCart's native controls.** The README and the code
  comments said FluentCart offers no order-status control *on a paid order*, and
  that the Orders list has a bulk status action. Measured on 1.6.0 and 1.6.3,
  neither is right: FluentCart's admin has **no control for choosing an order
  status on any order** — only *Mark As Complete* (from `processing`), *Back to
  processing* (from `completed`) and *Cancel Order* — and the Orders list's bulk
  actions only delete. `editable_order_statuses` is read into one component and
  never rendered; on FluentCart's side it is only the write allow-list.

### Tests

- The unit and scenario suites assert the new template behaviour, and C2 proves
  an unpaid order is offered the same custom steps as a paid one before
  restricting one step under *Advanced* to show the payment condition still
  works. Run on FluentCart 1.6.0 and 1.6.3.

## [0.4.0] — 2026-09-12

Every workflow step can now **send an e-mail** — inside FluentCart's own
notification system rather than beside it. And the whole plugin is now tested
against **FluentCart 1.6.0** as well as 1.6.3, because 1.6.0 is what production
stores are running. Upgrading from 0.3.0 changes nothing about an existing
configuration and sends no mail until somebody switches one on.

### Added

- **Per-status e-mail notifications, registered into FluentCart.** Every enabled
  custom status contributes four rows to FluentCart → Settings → Email
  Configuration → Notifications, grouped under *Custom Order Statuses*: a
  customer mail and an admin copy, on whichever axis the status lives. They use
  FluentCart's own on/off switch, sender, reply-to, template wrapper, footer,
  preview and shortcode picker — this plugin adds no mail settings of its own.
  **All four are off by default.**
- **Two content fields in FluentCart's own editor** — a *Heading* and a
  multi-line *Message*, declared as an `extra_fields` schema form
  (`fluent_cart/email_notification_data`) and stored by this plugin
  (`fluent_cart/email_notification_updated`), because core deliberately persists
  nothing there. Both accept shortcodes. They exist because free FluentCart
  strips `email_body` on save, so the body is otherwise not editable at all.
- **A body template inside the plugin**, rendered by FluentCart's own view
  renderer through `fluent_cart/email/template_view_path` (which accepts an
  absolute path, so nothing is added to core's view directory): heading, the
  message with one paragraph per line, the status badge in its configured
  colour, the order summary, the delivery address on a physical order, and a
  *View order* button — the customer's account page, or the admin order screen
  for an admin copy. It derives its status from the template path rather than
  from the event payload, which is what makes FluentCart's **preview** endpoint
  render it correctly with a sample order and no status change.
- **`YS_FCT_STATUS_DISABLE_EMAILS`** — one constant in `wp-config.php` that
  stops every mail this plugin would send, for staging copies of production
  data. Applied on `fluent_cart/should_send_email_notification`, and only ever
  to this plugin's own notifications.
- **Export and import carry the e-mail text**, under a top-level
  `email_content` key beside `settings`. Settings schema version 3.
- **An e-mail line on every status row** of the Order Statuses screen — whether
  a mail is on and for whom, linking to FluentCart's notification screen. Read
  only: FluentCart owns that switch, and a second one here would be a second
  source of truth.
- `tests/email-scenarios.php` (E0–E10) and `tests/EmailTest.php`. The scenario
  suite never lets a mail reach `mail()` — it short-circuits `wp_mail()` on
  `pre_wp_mail` and reads back what would have been sent.

### Changed

- **FluentCart 1.6.0 is now a tested target, not just a declared minimum.** All
  five suites are run against a 1.6.0 site and a 1.6.3 site on every release.
- The settings document reports schema version 3. A version-2 document (a 0.3
  export) is read without a migration step, exactly as version 1 was.

### Fixed

- A multi-line message typed into FluentCart's notification editor arrived
  flattened to a single line, because `EmailNotificationRequest::sanitize()`
  runs `sanitize_text_field` over `settings.extra`. `Email\RequestCapture` takes
  an unflattened copy on `rest_pre_dispatch`, before the request guard runs, and
  the content store prefers it.

### Notes

- A custom order status with *Also set shipping status to* → *Shipped* makes
  FluentCart's own "Order has been shipped" notification fire alongside this
  plugin's. Both mails are correct; the notification's own description says so
  on screen. Nothing is suppressed.
- `fluent_cart/orders_list_filter_query` — which the 0.3 shipping-axis saved
  views rely on — turned out **not** to be new in 1.6.3. It does not appear in a
  source search for its literal name because `BaseFilter::get()` and
  `::paginate()` compose it from `getFilterName()`; both call sites are
  byte-identical in 1.6.0 and 1.6.3. The shipping-axis views therefore stay
  registered on 1.6.0, verified end to end through a real list request on both.

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

[0.5.0]: https://yangsheep.com.tw
[0.4.0]: https://yangsheep.com.tw
[0.3.0]: https://yangsheep.com.tw
[0.2.0]: https://yangsheep.com.tw
[0.1.0]: https://yangsheep.com.tw
