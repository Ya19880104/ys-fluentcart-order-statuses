<?php
/**
 * v0.2: the pipeline, the linked shipping status and the report plumbing.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

use YangSheep\FluentCart\OrderStatuses\Admin\SavedViews;
use YangSheep\FluentCart\OrderStatuses\History\Backfill;
use YangSheep\FluentCart\OrderStatuses\Pipeline\StrictGuard;
use YangSheep\FluentCart\OrderStatuses\Pipeline\Template;
use YangSheep\FluentCart\OrderStatuses\Reports\CsvExporter;
use YangSheep\FluentCart\OrderStatuses\Settings;
use YangSheep\FluentCart\OrderStatuses\StatusRegistry;

// ── linked_shipping_status ───────────────────────────────────────────────────

YsStatusTest::group( 'Settings::sanitize — linked_shipping_status' );

$linked = Settings::sanitize(
	array(
		'shipping' => array(
			array( 'slug' => 'us_warehouse', 'label' => 'At the warehouse' ),
		),
		'order'    => array(
			array( 'slug' => 'built', 'label' => 'Built', 'linked_shipping_status' => 'shipped' ),
			array( 'slug' => 'stored', 'label' => 'Stored', 'linked_shipping_status' => 'us_warehouse' ),
			array( 'slug' => 'invented', 'label' => 'Invented', 'linked_shipping_status' => 'nope_not_real' ),
			array( 'slug' => 'unlinked', 'label' => 'Unlinked' ),
		),
	)
);

$byslug = array();

foreach ( $linked['order'] as $definition ) {
	$byslug[ $definition['slug'] ] = $definition;
}

YsStatusTest::same( 'shipped', $byslug['built']['linked_shipping_status'], 'a built-in shipping slug is accepted' );
YsStatusTest::same( 'us_warehouse', $byslug['stored']['linked_shipping_status'], 'a custom shipping slug defined in the same document is accepted' );
YsStatusTest::same( '', $byslug['invented']['linked_shipping_status'], 'a link to a status that does not exist is dropped' );
YsStatusTest::same( '', $byslug['unlinked']['linked_shipping_status'], 'no link means an empty string, not a missing key' );
YsStatusTest::ok( ! isset( $linked['shipping'][0]['linked_shipping_status'] ), 'a shipping status carries no link of its own' );

$crossAxis = Settings::sanitize(
	array(
		'order' => array(
			array( 'slug' => 'bogus', 'label' => 'Bogus', 'linked_shipping_status' => 'completed' ),
		),
	)
);

YsStatusTest::same( '', $crossAxis['order'][0]['linked_shipping_status'], 'an ORDER status slug cannot be used as a shipping link' );

// ── the pipeline ─────────────────────────────────────────────────────────────

YsStatusTest::group( 'Settings::pipeline' );

$flow = Settings::sanitize(
	array(
		'order' => array(
			array( 'slug' => 'step_c', 'label' => 'Third', 'sort_order' => 30 ),
			array( 'slug' => 'step_a', 'label' => 'First', 'sort_order' => 10 ),
			array( 'slug' => 'step_b', 'label' => 'Second', 'sort_order' => 20 ),
			array( 'slug' => 'step_off', 'label' => 'Disabled', 'sort_order' => 40, 'enabled' => false ),
		),
	)
);

YsStatusTest::same(
	array( 'processing', 'step_a', 'step_b', 'step_c' ),
	Settings::pipeline( $flow ),
	'the pipeline is processing plus the enabled statuses in list order'
);

YsStatusTest::same( 0, Settings::pipelinePosition( 'processing', $flow ), 'the built-in entry status is step 0' );
YsStatusTest::same( 2, Settings::pipelinePosition( 'step_b', $flow ), 'positions follow the list order, not the slug' );
YsStatusTest::same( -1, Settings::pipelinePosition( 'completed', $flow ), 'a status outside the pipeline has no position' );
YsStatusTest::same( -1, Settings::pipelinePosition( 'step_off', $flow ), 'a disabled status is not part of the pipeline' );
YsStatusTest::same( false, Settings::pipelineStrict( $flow ), 'strict mode is off unless asked for' );

// ── strict mode ──────────────────────────────────────────────────────────────

YsStatusTest::group( 'StrictGuard::rejectionReason' );

$GLOBALS['ys_status_options'][ Settings::OPTION ] = $flow;
StatusRegistry::flushCache();

YsStatusTest::same( null, StrictGuard::rejectionReason( 'processing', 'step_a' ), 'one step forward is allowed' );
YsStatusTest::same( null, StrictGuard::rejectionReason( 'step_b', 'step_a' ), 'one step back is allowed' );
YsStatusTest::same( null, StrictGuard::rejectionReason( 'step_b', 'step_b' ), 'staying put is allowed' );
YsStatusTest::ok( null !== StrictGuard::rejectionReason( 'processing', 'step_c' ), 'skipping two steps forward is refused' );
YsStatusTest::ok( null !== StrictGuard::rejectionReason( 'step_c', 'processing' ), 'skipping two steps back is refused' );
YsStatusTest::same( null, StrictGuard::rejectionReason( 'step_a', 'canceled' ), 'leaving the pipeline is always allowed' );
YsStatusTest::same( null, StrictGuard::rejectionReason( 'on-hold', 'step_c' ), 'entering the pipeline from outside it is always allowed' );

YsStatusTest::ok(
	false !== strpos( (string) StrictGuard::rejectionReason( 'processing', 'step_c' ), 'First' ),
	'the refusal names the step that was skipped'
);

// ── the dropdown reads in workflow order ─────────────────────────────────────

YsStatusTest::group( 'StatusRegistry::inPipelineOrder' );

$sorted = StatusRegistry::inPipelineOrder(
	array(
		'on-hold'    => 'On Hold',
		'completed'  => 'Completed',
		'step_b'     => 'Second',
		'processing' => 'Processing',
		'step_a'     => 'First',
	)
);

YsStatusTest::same(
	array( 'processing', 'step_a', 'step_b', 'on-hold', 'completed' ),
	array_keys( $sorted ),
	'pipeline steps come first, in workflow order; everything else keeps its place'
);

YsStatusTest::same( 'Second', $sorted['step_b'], 'the labels are untouched by the reordering' );

// ── the template ─────────────────────────────────────────────────────────────

YsStatusTest::group( 'Pipeline\Template' );

$fresh = Template::mergeInto( Settings::defaults() );

YsStatusTest::same(
	array( 'in_production', 'ship_scheduled', 'shipped_done' ),
	$fresh['added'],
	'a fresh install gets all three steps'
);

YsStatusTest::same(
	array( 'processing', 'in_production', 'ship_scheduled', 'shipped_done' ),
	Settings::pipeline( $fresh['settings'] ),
	'the template lands in workflow order'
);

$templated = array();

foreach ( $fresh['settings']['order'] as $definition ) {
	$templated[ $definition['slug'] ] = $definition;
}

YsStatusTest::same( 'shipped', $templated['shipped_done']['linked_shipping_status'], 'the last step also sets the shipping status' );
YsStatusTest::same( 'paid_only', $templated['in_production']['payment_requirement'], 'every step needs the order to be paid' );
YsStatusTest::same( 'keep', $templated['in_production']['on_payment'], 'every step survives a later payment' );
YsStatusTest::same( 'Paid', $fresh['settings']['overrides']['order']['processing']['label'], 'processing is relabelled to Paid' );

$again = Template::mergeInto( $fresh['settings'] );

YsStatusTest::same( array(), $again['added'], 'applying the template twice adds nothing' );
YsStatusTest::same( 3, count( $again['skipped'] ), 'the three existing steps are reported as skipped' );
YsStatusTest::same( count( $fresh['settings']['order'] ), count( $again['settings']['order'] ), 'and nothing is duplicated' );

$customised                                              = $fresh['settings'];
$customised['overrides']['order']['processing']['label'] = 'Money in';
$customised['order'][0]['label']                         = 'Making it';

$respectful = Template::mergeInto( $customised );

YsStatusTest::same( 'Money in', $respectful['settings']['overrides']['order']['processing']['label'], 'an operator label is never overwritten' );
YsStatusTest::same( 'Making it', $respectful['settings']['order'][0]['label'], 'an operator-renamed step is left alone' );

// ── saved views ──────────────────────────────────────────────────────────────

YsStatusTest::group( 'Admin\SavedViews' );

$GLOBALS['ys_status_options'][ Settings::OPTION ] = $fresh['settings'];
StatusRegistry::flushCache();

$views = ( new SavedViews() )->addOrderViews( array(), array( 'filterOptions' => array() ) );

YsStatusTest::ok( isset( $views['order_table']['saved_views'] ), 'the order_table entry is created even when the filter is handed an empty array' );
YsStatusTest::same( 3, count( $views['order_table']['saved_views'] ), 'one view per custom order status' );

$first = $views['order_table']['saved_views'][0];

YsStatusTest::same( 'ys_status_in_production', $first['slug'], 'the slug is prefixed so it cannot collide with a core tab' );
YsStatusTest::same( 'In production', $first['name'], 'without counts the name is the plain label' );
YsStatusTest::same( 'simple', $first['query_params']['filter_type'], 'simple, because advanced filters are a Pro-only code path server side' );
YsStatusTest::same( 'status = in_production', $first['query_params']['search'], 'the search expression targets the status column' );
YsStatusTest::same( 0, $first['owner_id'], 'nobody owns these views, so the SPA offers no rename or delete' );

$kept = ( new SavedViews() )->addOrderViews(
	array( 'order_table' => array( 'filters' => array( 'advance' => array() ), 'saved_views' => array( array( 'slug' => 'someone_else' ) ) ) ),
	array()
);

YsStatusTest::same( 4, count( $kept['order_table']['saved_views'] ), 'views from another add-on are kept' );
YsStatusTest::ok( isset( $kept['order_table']['filters'] ), 'and so is the rest of the table config' );

// ── the activity-log parser ──────────────────────────────────────────────────

YsStatusTest::group( 'History\Backfill::parseLine' );

YsStatusTest::same(
	array( 'on-hold', 'in_production' ),
	Backfill::parseLine( 'Order status has been updated from on-hold to in_production' ),
	'the English sentence core writes'
);

YsStatusTest::same(
	array( 'unshipped', 'shipped' ),
	Backfill::parseLine( 'Shipping status has been updated from unshipped to shipped' ),
	'the shipping variant'
);

YsStatusTest::same(
	array( 'sourcing', 'processing' ),
	Backfill::parseLine( '訂單狀態已從 sourcing 變更為 processing' ),
	'a translated sentence still yields the two slugs'
);

YsStatusTest::same( null, Backfill::parseLine( 'Order Paid' ), 'a line with no transition in it is skipped' );
YsStatusTest::same( null, Backfill::parseLine( '' ), 'an empty line is skipped' );

YsStatusTest::same( 'shipping', Backfill::axisFor( 'Shipping status updated', 'from unshipped to shipped' ), 'the shipping axis is recognised' );
YsStatusTest::same( 'order', Backfill::axisFor( 'Order status updated', 'from on-hold to processing' ), 'everything else is the order axis' );

// ── CSV ──────────────────────────────────────────────────────────────────────

YsStatusTest::group( 'Reports\CsvExporter' );

YsStatusTest::same( "\"'=1+1\"", CsvExporter::cell( '=1+1' ), 'a formula is defused with a leading apostrophe' );
YsStatusTest::same( "\"'=cmd|' /c calc\"", CsvExporter::cell( '=cmd|\' /c calc' ), 'including the command-injection shape' );
YsStatusTest::same( '"In production"', CsvExporter::cell( 'In production' ), 'ordinary text is only quoted' );
YsStatusTest::same( "\"'+41\"", CsvExporter::cell( '+41' ), 'plus is a formula prefix too' );
YsStatusTest::same( "\"'-41\"", CsvExporter::cell( '-41' ), 'so is minus' );
YsStatusTest::same( "\"'@SUM(A1)\"", CsvExporter::cell( '@SUM(A1)' ), 'so is at' );
YsStatusTest::same( '"He said ""no"""', CsvExporter::cell( 'He said "no"' ), 'quotes are doubled' );
YsStatusTest::same( '"下生產"', CsvExporter::cell( '下生產' ), 'non-ASCII is passed through untouched' );

$csv = CsvExporter::render( array( array( 'a', 'b' ), array( 1, 2 ) ) );

YsStatusTest::same( CsvExporter::BOM, substr( $csv, 0, 3 ), 'the file starts with a UTF-8 BOM' );
YsStatusTest::same( "\"a\",\"b\"\r\n\"1\",\"2\"\r\n", substr( $csv, 3 ), 'rows are CRLF separated' );

// Leave the option store as the other suites expect to find it.
unset( $GLOBALS['ys_status_options'][ Settings::OPTION ] );
StatusRegistry::flushCache();
