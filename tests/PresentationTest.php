<?php
/**
 * payment_requirement, the generated CSS and the badge tagger.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

use YangSheep\FluentCart\OrderStatuses\Payment\RequirementGuard;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\Support\LabelTagger;
use YangSheep\FluentCart\OrderStatuses\Support\OrderRepository;
use YangSheep\FluentCart\OrderStatuses\Support\StatusCss;

YsStatusTest::group( 'RequirementGuard::allows' );

YsStatusTest::same( true, RequirementGuard::allows( 'any', true ), 'any allows a paid order' );
YsStatusTest::same( true, RequirementGuard::allows( 'any', false ), 'any allows an unpaid order' );
YsStatusTest::same( true, RequirementGuard::allows( 'paid_only', true ), 'paid_only allows a paid order' );
YsStatusTest::same( false, RequirementGuard::allows( 'paid_only', false ), 'paid_only refuses an unpaid order' );
YsStatusTest::same( false, RequirementGuard::allows( 'unpaid_only', true ), 'unpaid_only refuses a paid order' );
YsStatusTest::same( true, RequirementGuard::allows( 'unpaid_only', false ), 'unpaid_only allows an unpaid order' );
YsStatusTest::same( true, RequirementGuard::allows( 'garbage', false ), 'an unknown requirement never blocks' );

YsStatusTest::group( 'OrderRepository::isPaid' );

YsStatusTest::same( true, OrderRepository::isPaid( 'paid' ), 'paid counts' );
YsStatusTest::same( true, OrderRepository::isPaid( 'partially_paid' ), 'partially paid counts' );
YsStatusTest::same( true, OrderRepository::isPaid( 'partially_refunded' ), 'partially refunded counts — money did arrive' );
YsStatusTest::same( false, OrderRepository::isPaid( 'pending' ), 'pending does not' );
YsStatusTest::same( false, OrderRepository::isPaid( 'refunded' ), 'fully refunded does not' );
YsStatusTest::same( false, OrderRepository::isPaid( '' ), 'an empty value does not' );

YsStatusTest::group( 'StatusCss::build' );

$css = StatusCss::build(
	array(
		'sourcing'   => '#db8a3e',
		'bad_colour' => 'red',
		'"; }evil{'  => '#000000',
	)
);

YsStatusTest::ok( false !== strpos( $css, '[data-ys-status="sourcing"]' ), 'the attribute hook is emitted' );
YsStatusTest::ok( false !== strpos( $css, '.fct-badge.fct-sourcing' ), 'the storefront class hook is emitted' );
YsStatusTest::ok( false !== strpos( $css, 'rgba(219,138,62,.14)' ), 'the background is the colour at 14% alpha' );
YsStatusTest::ok( false === strpos( $css, 'bad_colour' ), 'a status with an invalid colour is skipped' );
YsStatusTest::ok( false === strpos( $css, '}evil{' ), 'a hostile slug cannot escape the selector' );
YsStatusTest::ok( false !== strpos( $css, '[data-ys-status="evil"]' ), '…it is stripped to its harmless characters instead' );
YsStatusTest::same( '', StatusCss::build( array() ), 'nothing to paint means no stylesheet' );

YsStatusTest::group( 'LabelTagger::map' );

$settings = Settings::sanitize( YsStatusFixture::full() );
$map      = LabelTagger::map( $settings );

YsStatusTest::same( 'sourcing', $map['Sourcing']['s'], 'the humanised slug maps back to the slug' );
YsStatusTest::same( '美國採購中', $map['Sourcing']['l'], 'and carries the configured label' );
YsStatusTest::same( 'sourcing', $map['sourcing']['s'], 'the raw slug is also recognised' );
YsStatusTest::same( 'sourcing', $map['美國採購中']['s'], 'so is the label itself, so a second pass is a no-op' );
YsStatusTest::same( 'us_warehouse', $map['Us Warehouse']['s'], 'multi-word slugs are title-cased the way FluentCart prints them' );
YsStatusTest::same( 'processing', $map['Processing']['s'], 'overridden built-ins are covered' );
YsStatusTest::same( 'unshipped', $map['Unshipped']['s'], 'shipping overrides are covered' );
YsStatusTest::same( 'pending', $map['Pending']['s'], 'payment overrides are covered' );
YsStatusTest::same( false, isset( $map['Switched Off'] ), 'a disabled status is not tagged' );
YsStatusTest::same( true, isset( $map['Hidden Step'] ), 'a non-editable status is still tagged — it can be displayed' );

$ambiguous = LabelTagger::map(
	Settings::sanitize(
		array(
			'order'    => array( array( 'slug' => 'warehouse', 'label' => 'Warehouse' ) ),
			'shipping' => array( array( 'slug' => 'warehouse', 'label' => 'Depot' ) ),
		)
	)
);

YsStatusTest::same( false, isset( $ambiguous['Warehouse'] ), 'two statuses sharing a spelling are both left alone' );

// The pipeline template's last order status is called "Shipped", and so is the
// built-in shipping status. Measured on 1.6.3: before this was handled, the
// order badge was relabelled to "Shipped" and the very next observer pass read
// that word, failed to resolve it, and stripped the tag — so the badge lost its
// colour a fraction of a second after gaining it.
$collision = LabelTagger::map(
	Settings::sanitize(
		array(
			'order'     => array( array( 'slug' => 'shipped_done', 'label' => 'Shipped' ) ),
			'overrides' => array( 'shipping' => array( 'shipped' => array( 'label' => 'Shipped' ) ) ),
		)
	)
);

YsStatusTest::same( 'shipped_done', $collision['Shipped Done']['s'], 'the order status is found by its own slug spelling' );
YsStatusTest::same( 'shipped', $collision['Shipped']['s'], 'the shared word belongs to the status whose SLUG spells it' );
YsStatusTest::same( 'shipped', $collision['shipped']['s'], 'and so does the raw slug' );

$collisionScript = LabelTagger::script( $collision, 'span.badge' );

YsStatusTest::ok( false !== strpos( $collisionScript, 'if(cur&&L[cur]===k){return;}' ), 'a badge already carrying our label is left alone on later passes' );
YsStatusTest::ok( false !== strpos( $collisionScript, '"shipped_done":"Shipped"' ), 'the slug => label table is shipped alongside the spelling map' );

YsStatusTest::group( 'LabelTagger::script' );

$script = LabelTagger::script( $map, 'span.badge' );

YsStatusTest::ok( false !== strpos( $script, 'MutationObserver' ), 're-renders are re-tagged' );
YsStatusTest::ok( false !== strpos( $script, '"span.badge"' ), 'the selector is JSON-encoded into the script' );
YsStatusTest::ok( false !== strpos( $script, '美國' ) || false !== strpos( $script, '美國採購中' ), 'the label survives encoding' );
YsStatusTest::ok( false === strpos( $script, '</script' ), 'nothing can close the script tag' );

add_filter( 'ys_fct_status/patch_admin_labels', static fn() => false );
YsStatusTest::ok( false !== strpos( LabelTagger::script( $map, 'span.badge' ), 'var REL=false' ), 'relabelling can be switched off by filter' );

YsStatusTest::group( 'LabelTagger::hasAnything' );

YsStatusTest::same( true, LabelTagger::hasAnything( $settings ), 'a configured site has work to do' );
YsStatusTest::same( false, LabelTagger::hasAnything( Settings::defaults() ), 'a fresh install does not' );
YsStatusTest::same(
	true,
	LabelTagger::hasAnything( Settings::sanitize( array( 'overrides' => array( 'payment' => array( 'pending' => array( 'label' => 'Awaiting' ) ) ) ) ) ),
	'an override alone is enough'
);
