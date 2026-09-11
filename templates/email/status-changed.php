<?php
/**
 * The body of a custom-status notification.
 *
 * Rendered by FluentCart's own view renderer (`App::make('view')->render()`)
 * from `Email\Templates`, which points `fluent_cart/email/template_view_path`
 * here. Everything around this file — the `<html>` wrapper, the order header,
 * the footer and the shortcode pass — is FluentCart's
 * `EmailNotificationMailer::parseEmailContent()`, so this file renders the body
 * and nothing else, exactly as `app/Views/emails/order/shipped/customer.php`
 * does.
 *
 * Two things it must survive:
 *
 *  - the **preview** endpoint, which renders it with a sample order and no
 *    `new_status` at all — hence the status is read from the template path
 *    rather than from the payload;
 *  - **shortcodes**, which are resolved by `ShortcodeTemplateBuilder` *after*
 *    this file has run. `esc_html()` leaves `{{` and `}}` alone, so the
 *    operator's text is escaped here and still resolves there.
 *
 * @var \FluentCart\App\Models\Order $order
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

use YangSheep\FluentCart\OrderStatuses\Email\NotificationRegistry;
use YangSheep\FluentCart\OrderStatuses\Email\Templates;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ys_parts = NotificationRegistry::parseTemplatePath( Templates::currentPath() );

if ( null === $ys_parts ) {
	// Only reachable if something rendered this file directly. Nothing sensible
	// to say about a status nobody named.
	return;
}

$ys_axis      = $ys_parts['axis'];
$ys_slug      = $ys_parts['slug'];
$ys_recipient = $ys_parts['recipient'];

$ys_custom     = Settings::customStatuses( $ys_axis, StatusRegistry::settings() );
$ys_definition = isset( $ys_custom[ $ys_slug ] ) ? $ys_custom[ $ys_slug ] : null;

$ys_label   = null !== $ys_definition ? $ys_definition['label'] : $ys_slug;
$ys_color   = null !== $ys_definition ? $ys_definition['color'] : Settings::DEFAULT_COLOR;
$ys_content = NotificationRegistry::contentFor( $ys_axis, $ys_slug, $ys_recipient, $ys_definition );

$ys_order = isset( $order ) ? $order : null;

// `explode` on "\n" only: ContentStore normalises CRLF on the way in.
$ys_lines = array_values(
	array_filter(
		array_map( 'trim', explode( "\n", (string) $ys_content['message'] ) ),
		static function ( $line ) {
			return '' !== $line;
		}
	)
);

$ys_is_physical = is_object( $ys_order ) && isset( $ys_order->fulfillment_type ) && 'physical' === $ys_order->fulfillment_type;
$ys_link        = '';

if ( is_object( $ys_order ) && method_exists( $ys_order, 'getViewUrl' ) ) {
	$ys_link = (string) $ys_order->getViewUrl( 'admin' === $ys_recipient ? 'admin' : 'customer' );
}
?>

<div class="space_bottom_30">
	<?php if ( '' !== $ys_content['heading'] ) : ?>
		<p style="font-size:18px;font-weight:600;color:rgb(44,62,80);line-height:28px;margin:0 0 12px;">
			<?php echo esc_html( $ys_content['heading'] ); ?>
		</p>
	<?php endif; ?>

	<?php foreach ( $ys_lines as $ys_line ) : ?>
		<p><?php echo esc_html( $ys_line ); ?></p>
	<?php endforeach; ?>

	<p style="margin-top:16px;">
		<span style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:13px;font-weight:600;color:#ffffff;background-color:<?php echo esc_attr( $ys_color ); ?>;">
			<?php echo esc_html( $ys_label ); ?>
		</span>
	</p>
</div>

<?php
if ( is_object( $ys_order ) ) {
	\FluentCart\App\App::make( 'view' )->render(
		'emails.parts.items_table',
		array(
			'order'          => $ys_order,
			'formattedItems' => $ys_order->order_items,
			'heading'        => esc_html__( 'Order Summary', 'ys-fluentcart-order-statuses' ),
		)
	);

	if ( $ys_is_physical ) {
		echo '<hr />';

		\FluentCart\App\App::make( 'view' )->render(
			'emails.parts.addresses',
			array(
				'order' => $ys_order,
			)
		);
	}

	\FluentCart\App\App::make( 'view' )->render(
		'emails.parts.call_to_action_box',
		array(
			'content'     => 'admin' === $ys_recipient
				? esc_html__( 'Open the order in the FluentCart dashboard to see the full history.', 'ys-fluentcart-order-statuses' )
				: esc_html__( 'You can follow your order at any time from your account.', 'ys-fluentcart-order-statuses' ),
			'link'        => $ys_link,
			'button_text' => esc_html__( 'View order', 'ys-fluentcart-order-statuses' ),
		)
	);
}
