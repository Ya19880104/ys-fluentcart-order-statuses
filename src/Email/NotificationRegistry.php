<?php
/**
 * Every custom status, as an entry in FluentCart's own notification list.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

namespace YangSheep\FluentCart\OrderStatuses\Email;

use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The add-on side of FluentCart → Settings → Email Notifications.
 *
 * `EmailNotifications::getNotifications()` applies
 * `fluent_cart/email_notifications` to its own default array and treats
 * whatever comes back as the whole list: the SPA groups it by `group_label`,
 * gives every row FluentCart's on/off switch, and stores the operator's
 * `active` and `subject` in its own meta row keyed by our `name`. So this
 * plugin adds **no** mail settings of its own beyond the two content fields in
 * §3.2 — the sender, the wrapper, the footer, the toggle and the preview are
 * all FluentCart's, and stay FluentCart's.
 *
 * Four entries per enabled custom status: customer and admin, on whichever axis
 * the status lives. The `name` carries the slug rather than a serial number, so
 * renaming a status keeps the operator's toggle and subject, and deleting one
 * simply stops registering its entries (FluentCart's stored config for a name
 * nobody registers any more is inert — see `EmailNotifications::getNotifications()`,
 * which only ever merges config *into* a registered entry).
 *
 * Free FluentCart cannot edit the body of a notification
 * (`EmailNotificationController::update()` strips `email_body` unless Pro's
 * `fluent_cart/prepare_email_template_data` puts it back), so the editable
 * content is the subject plus the heading and message declared here as
 * `extra_fields` — the schema-driven form FluentCart already renders for its
 * own settings tabs. Core does not persist those two; `Email\ContentStore` does.
 */
final class NotificationRegistry {

	/** Group key and label the SPA groups our rows under. */
	const GROUP = 'ys_custom_status';

	/** Every notification name this plugin owns starts with this. */
	const NAME_PREFIX = 'ys_status_';

	/**
	 * Template-path prefix.
	 *
	 * Deliberately not `emails.*`: `TemplateService::getTemplateByPathName()`
	 * prefixes core paths with `emails.` before applying
	 * `fluent_cart/email/template_view_path`, and a value under that namespace
	 * could collide with a core view file. `ys-status.` cannot.
	 */
	const TEMPLATE_PREFIX = 'ys-status.';

	/** The one extra-fields form we declare, and the key our storage uses. */
	const FORM_NAME = 'ys_content';

	/** Late enough that a site-level filter can still have the last word. */
	const PRIORITY = 20;

	/** @var array|null Per-request cache of the built entries. */
	private static $cache = null;

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'fluent_cart/email_notifications', array( $this, 'addNotifications' ), self::PRIORITY );
		add_filter( 'fluent_cart/email_notification_data', array( $this, 'addEditorData' ), self::PRIORITY, 2 );

		// The entries are derived from the status settings, so a save has to
		// drop them the same way `StatusRegistry` drops its own cache.
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'flushCache' ) );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'flushCache' ) );
		add_action( 'update_option_' . ContentStore::OPTION, array( __CLASS__, 'flushCache' ) );
		add_action( 'add_option_' . ContentStore::OPTION, array( __CLASS__, 'flushCache' ) );
	}

	/**
	 * @return void
	 */
	public static function flushCache() {
		self::$cache = null;
	}

	/**
	 * @param mixed $notifications FluentCart's notification map.
	 * @return array
	 */
	public function addNotifications( $notifications ) {
		$notifications = is_array( $notifications ) ? $notifications : array();

		return array_merge( $notifications, self::entries() );
	}

	/**
	 * Fill the editor's copy of the extra fields from our own storage.
	 *
	 * `GET email-notification/{name}` applies this filter, and the SPA reads
	 * `notification.extra_fields` together with
	 * `notification.settings.extra[<name>]` — the second one wins per field, so
	 * this is where a stored heading/message has to appear. Core never puts
	 * anything in `settings.extra`; it is ours to fill and ours to keep.
	 *
	 * @param mixed  $notification The notification array.
	 * @param string $name         Notification name.
	 * @return mixed
	 */
	public function addEditorData( $notification, $name ) {
		if ( ! is_array( $notification ) || ! self::isOurName( $name ) ) {
			return $notification;
		}

		$parts = self::parseName( $name );

		if ( null === $parts ) {
			return $notification;
		}

		$content = self::contentFor( $parts['axis'], $parts['slug'], $parts['recipient'] );

		if ( ! isset( $notification['settings'] ) || ! is_array( $notification['settings'] ) ) {
			$notification['settings'] = array();
		}

		$extra = isset( $notification['settings']['extra'] ) && is_array( $notification['settings']['extra'] )
			? $notification['settings']['extra']
			: array();

		$extra[ $name ] = array( self::FORM_NAME => $content );

		$notification['settings']['extra'] = $extra;

		return $notification;
	}

	/**
	 * Every entry this plugin registers, keyed by notification name.
	 *
	 * @return array<string,array>
	 */
	public static function entries() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$settings = StatusRegistry::settings();
		$entries  = array();

		foreach ( Settings::AXES as $axis ) {
			foreach ( Settings::customStatuses( $axis, $settings ) as $slug => $definition ) {
				foreach ( array( 'customer', 'admin' ) as $recipient ) {
					$entries[ self::nameFor( $axis, $slug, $recipient ) ] = self::entry( $axis, $slug, $definition, $recipient );
				}
			}
		}

		self::$cache = $entries;

		return $entries;
	}

	/**
	 * @param string $axis       'order' or 'shipping'.
	 * @param string $slug       Status slug.
	 * @param array  $definition Status definition.
	 * @param string $recipient  'customer' or 'admin'.
	 * @return array
	 */
	private static function entry( $axis, $slug, array $definition, $recipient ) {
		$label   = $definition['label'];
		$name    = self::nameFor( $axis, $slug, $recipient );
		$content = self::contentFor( $axis, $slug, $recipient, $definition );

		return array(
			'event'            => self::eventFor( $axis, $slug ),
			'group'            => self::GROUP,
			'group_label'      => self::groupLabel(),
			'title'            => self::title( $axis, $label, $recipient ),
			'description'      => self::description( $axis, $label, $recipient, $definition ),
			'recipient'        => $recipient,
			'smartcode_groups' => array(),
			'template_path'    => self::templatePathFor( $axis, $slug, $recipient ),
			'is_async'         => false,
			'extra_fields'     => self::extraFields( $content ),
			'settings'         => array(
				// Off by default, on both axes and for both recipients: this
				// plugin must never start mailing a shop's customers because
				// someone added a workflow step.
				'active'              => 'no',
				'subject'             => self::defaultSubject( $label, $recipient ),
				'is_default_body'     => 'yes',
				'email_body'          => '',
				'attach_pdf_template' => '',
			),
			// Not a setting FluentCart reads — carried so anything reading the
			// registered list back (our own admin screen, a sibling add-on) can
			// tell which status a row belongs to without parsing the name.
			'ys_axis'          => $axis,
			'ys_slug'          => $slug,
		);
	}

	/**
	 * @return string
	 */
	public static function groupLabel() {
		return __( 'Custom Order Statuses', 'ys-fluentcart-order-statuses' );
	}

	/**
	 * @param string $axis      'order' or 'shipping'.
	 * @param string $label     Status label.
	 * @param string $recipient 'customer' or 'admin'.
	 * @return string
	 */
	private static function title( $axis, $label, $recipient ) {
		if ( 'admin' === $recipient ) {
			return 'shipping' === $axis
				? sprintf(
					/* translators: %s: shipping status label */
					__( 'Notify admin when the shipping status changes to “%s”', 'ys-fluentcart-order-statuses' ),
					$label
				)
				: sprintf(
					/* translators: %s: order status label */
					__( 'Notify admin when the order status changes to “%s”', 'ys-fluentcart-order-statuses' ),
					$label
				);
		}

		return 'shipping' === $axis
			? sprintf(
				/* translators: %s: shipping status label */
				__( 'Send mail to customer when the shipping status changes to “%s”', 'ys-fluentcart-order-statuses' ),
				$label
			)
			: sprintf(
				/* translators: %s: order status label */
				__( 'Send mail to customer when the order status changes to “%s”', 'ys-fluentcart-order-statuses' ),
				$label
			);
	}

	/**
	 * @param string $axis       'order' or 'shipping'.
	 * @param string $label      Status label.
	 * @param string $recipient  'customer' or 'admin'.
	 * @param array  $definition Status definition.
	 * @return string
	 */
	private static function description( $axis, $label, $recipient, array $definition ) {
		$where = 'shipping' === $axis
			? __( 'This is a custom shipping status added by YS FluentCart Order Statuses. The status itself is managed on FluentCart → Order Statuses → Shipping statuses.', 'ys-fluentcart-order-statuses' )
			: __( 'This is a custom order status added by YS FluentCart Order Statuses. The status itself is managed on FluentCart → Order Statuses → Order statuses.', 'ys-fluentcart-order-statuses' );

		$who = 'admin' === $recipient
			? sprintf(
				/* translators: %s: status label */
				__( 'The shop admin address is mailed every time an order moves to “%s”.', 'ys-fluentcart-order-statuses' ),
				$label
			)
			: sprintf(
				/* translators: %s: status label */
				__( 'The customer is mailed every time their order moves to “%s”.', 'ys-fluentcart-order-statuses' ),
				$label
			);

		$parts = array( $who, $where );

		// A custom order status that carries the order to `shipped` makes
		// FluentCart's own "Order has been shipped" notification fire as well.
		// Both mails are correct, and suppressing one of them would be a
		// surprise, so the operator is told instead.
		if ( 'order' === $axis && Settings::SHIPPING_PIPELINE_EXIT === (string) $definition['linked_shipping_status'] && 'customer' === $recipient ) {
			$parts[] = __( 'This status also sets the shipping status to “Shipped”, so FluentCart’s own “Order has been shipped” mail fires too. Switch one of the two off if the customer should only get one.', 'ys-fluentcart-order-statuses' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * @param string $label     Status label.
	 * @param string $recipient 'customer' or 'admin'.
	 * @return string
	 */
	public static function defaultSubject( $label, $recipient ) {
		return 'admin' === $recipient
			? sprintf(
				/* translators: %s: status label */
				__( 'Order #{{order.invoice_no}} is now %s', 'ys-fluentcart-order-statuses' ),
				$label
			)
			: sprintf(
				/* translators: %s: status label */
				__( 'Order #{{order.invoice_no}} — %s', 'ys-fluentcart-order-statuses' ),
				$label
			);
	}

	/**
	 * The heading and message the operator sees, stored value or default.
	 *
	 * @param string     $axis       'order' or 'shipping'.
	 * @param string     $slug       Status slug.
	 * @param string     $recipient  'customer' or 'admin'.
	 * @param array|null $definition Optional pre-read definition.
	 * @return array{heading:string,message:string}
	 */
	public static function contentFor( $axis, $slug, $recipient, array $definition = null ) {
		$stored = ContentStore::get( self::nameFor( $axis, $slug, $recipient ) );

		if ( null !== $stored ) {
			return $stored;
		}

		return self::defaultContent( $axis, $slug, $recipient, $definition );
	}

	/**
	 * @param string     $axis       'order' or 'shipping'.
	 * @param string     $slug       Status slug.
	 * @param string     $recipient  'customer' or 'admin'.
	 * @param array|null $definition Optional pre-read definition.
	 * @return array{heading:string,message:string}
	 */
	public static function defaultContent( $axis, $slug, $recipient, array $definition = null ) {
		if ( null === $definition ) {
			$custom     = Settings::customStatuses( $axis, StatusRegistry::settings() );
			$definition = isset( $custom[ $slug ] ) ? $custom[ $slug ] : array( 'label' => $slug );
		}

		$label = isset( $definition['label'] ) ? $definition['label'] : $slug;

		return array(
			'heading' => sprintf(
				/* translators: %s: status label */
				__( 'Your order is now %s', 'ys-fluentcart-order-statuses' ),
				$label
			),
			'message' => 'admin' === $recipient
				? sprintf(
					/* translators: %s: status label */
					__( 'Order #{{order.invoice_no}} for {{order.customer.full_name}} is now %s.', 'ys-fluentcart-order-statuses' ),
					$label
				)
				: sprintf(
					/* translators: %s: status label */
					__( 'Hello {{order.customer.full_name}}, the status of your order #{{order.invoice_no}} has changed to %s.', 'ys-fluentcart-order-statuses' ),
					$label
				),
		);
	}

	/**
	 * The one schema-driven form FluentCart's own editor renders for us.
	 *
	 * Measured against the SPA (`EditEmailNotification`): a form is either
	 * `{ form_name, schema, values }` or a map of those keyed by form name, the
	 * values are merged over `settings.extra[<name>][<form_name>]`, and the
	 * whole state comes back on save as
	 * `settings.extra = { <name>: { <form_name>: { … } } }`. The field types
	 * are the ones `InputRenderer` maps (`input`, `textarea`, `select`, …) and
	 * anything in `attributes` is spread onto the underlying control, which is
	 * how a textarea gets its rows.
	 *
	 * @param array $values Current heading/message.
	 * @return array
	 */
	private static function extraFields( array $values ) {
		return array(
			'form_name' => self::FORM_NAME,
			'title'     => __( 'Message content', 'ys-fluentcart-order-statuses' ),
			'sub_title' => __( 'Shown inside FluentCart’s own e-mail template, above the order summary. Shortcodes such as {{order.invoice_no}} work here.', 'ys-fluentcart-order-statuses' ),
			'schema'    => array(
				'heading' => array(
					'type'        => 'input',
					'label'       => __( 'Heading', 'ys-fluentcart-order-statuses' ),
					'placeholder' => __( 'Your order is now …', 'ys-fluentcart-order-statuses' ),
					'note'        => __( 'One line, shown in bold at the top of the e-mail.', 'ys-fluentcart-order-statuses' ),
					'value'       => '',
				),
				'message'  => array(
					'type'        => 'textarea',
					'label'       => __( 'Message', 'ys-fluentcart-order-statuses' ),
					'placeholder' => __( 'Tell the customer what happens next…', 'ys-fluentcart-order-statuses' ),
					'note'        => __( 'Each line becomes its own paragraph. Plain text only — HTML is stripped.', 'ys-fluentcart-order-statuses' ),
					'value'       => '',
					'attributes'  => array(
						'type' => 'textarea',
						'rows' => 5,
					),
				),
			),
			'values'    => $values,
		);
	}

	/**
	 * @param string $axis      'order' or 'shipping'.
	 * @param string $slug      Status slug.
	 * @param string $recipient 'customer' or 'admin'.
	 * @return string
	 */
	public static function nameFor( $axis, $slug, $recipient ) {
		return self::NAME_PREFIX . $axis . '_' . $slug . '_' . $recipient;
	}

	/**
	 * @param string $axis      'order' or 'shipping'.
	 * @param string $slug      Status slug.
	 * @param string $recipient 'customer' or 'admin'.
	 * @return string
	 */
	public static function templatePathFor( $axis, $slug, $recipient ) {
		return self::TEMPLATE_PREFIX . $axis . '.' . $slug . '.' . $recipient;
	}

	/**
	 * The FluentCart event a status change on this axis fires.
	 *
	 * `OrderStatusUpdated::afterDispatch()` fires
	 * `fluent_cart/order_status_changed_to_<slug>` and
	 * `fluent_cart/shipping_status_changed_to_<slug>` for a custom slug exactly
	 * as it does for a built-in one — the event name has no allow-list.
	 *
	 * @param string $axis 'order' or 'shipping'.
	 * @param string $slug Status slug.
	 * @return string Event name, without the `fluent_cart/` prefix.
	 */
	public static function eventFor( $axis, $slug ) {
		$prefix = 'shipping' === $axis ? 'shipping_status_changed_to_' : 'order_status_changed_to_';

		return $prefix . $slug;
	}

	/**
	 * @param string $name Notification name.
	 * @return bool
	 */
	public static function isOurName( $name ) {
		return is_string( $name ) && 0 === strpos( $name, self::NAME_PREFIX );
	}

	/**
	 * Which status a notification name is about.
	 *
	 * A slug may contain underscores, so the name cannot be split on them.
	 * Matching against the registered entries is both unambiguous and cheap —
	 * the list is already built and cached.
	 *
	 * @param string $name Notification name.
	 * @return array{axis:string,slug:string,recipient:string}|null
	 */
	public static function parseName( $name ) {
		$entries = self::entries();

		if ( ! isset( $entries[ $name ] ) ) {
			return null;
		}

		$entry = $entries[ $name ];

		return array(
			'axis'      => $entry['ys_axis'],
			'slug'      => $entry['ys_slug'],
			'recipient' => $entry['recipient'],
		);
	}

	/**
	 * Which status a template path is about.
	 *
	 * Unlike the name, the path can simply be split: a slug is
	 * `[a-z][a-z0-9_-]*` and can never contain a dot.
	 *
	 * @param string $path Template path.
	 * @return array{axis:string,slug:string,recipient:string}|null
	 */
	public static function parseTemplatePath( $path ) {
		if ( ! is_string( $path ) || 0 !== strpos( $path, self::TEMPLATE_PREFIX ) ) {
			return null;
		}

		$parts = explode( '.', $path );

		if ( 4 !== count( $parts ) ) {
			return null;
		}

		if ( ! in_array( $parts[1], Settings::AXES, true ) || ! in_array( $parts[3], array( 'customer', 'admin' ), true ) ) {
			return null;
		}

		return array(
			'axis'      => $parts[1],
			'slug'      => $parts[2],
			'recipient' => $parts[3],
		);
	}

	/**
	 * Whether FluentCart has this notification switched on.
	 *
	 * Read straight out of FluentCart's own config — this plugin deliberately
	 * stores no on/off state of its own, so the answer can only come from there.
	 *
	 * @param string $name Notification name.
	 * @return bool
	 */
	public static function isActive( $name ) {
		$config = self::configMap();

		return isset( $config[ $name ]['active'] ) && 'yes' === $config[ $name ]['active'];
	}

	/**
	 * FluentCart's stored per-notification settings, name => settings.
	 *
	 * Read in one call rather than one per name: `getNotificationConfig($name)`
	 * rebuilds the entire default notification array every time it is asked,
	 * and the settings screen asks once per status per recipient.
	 *
	 * @return array<string,array>
	 */
	private static function configMap() {
		if ( ! class_exists( '\FluentCart\App\Services\Email\EmailNotifications' ) ) {
			return array();
		}

		$config = \FluentCart\App\Services\Email\EmailNotifications::getNotificationConfig();

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Per-status e-mail state for the settings screen.
	 *
	 * @return array<string,array<string,array<string,bool>>> axis => slug => recipient => on.
	 */
	public static function stateMap() {
		$settings = StatusRegistry::settings();
		$config   = self::configMap();
		$out      = array(
			'order'    => array(),
			'shipping' => array(),
		);

		foreach ( Settings::AXES as $axis ) {
			foreach ( Settings::customStatuses( $axis, $settings ) as $slug => $definition ) {
				$customer = self::nameFor( $axis, $slug, 'customer' );
				$admin    = self::nameFor( $axis, $slug, 'admin' );

				$out[ $axis ][ $slug ] = array(
					'customer' => isset( $config[ $customer ]['active'] ) && 'yes' === $config[ $customer ]['active'],
					'admin'    => isset( $config[ $admin ]['active'] ) && 'yes' === $config[ $admin ]['active'],
					'name'     => $customer,
				);
			}
		}

		return $out;
	}
}
