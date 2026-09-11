<?php
/**
 * Zero-dependency test runner.
 *
 *   php tests/run.php
 *
 * PHPUnit would mean a composer install for a handful of classes that touch no
 * database; this keeps `git clone && php tests/run.php` working on a machine
 * with nothing but PHP. The scenarios that DO need FluentCart live in
 * `tests/status-scenarios.php` and run through wp-cli against a real site.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Fixture.php';

$suites = array(
	__DIR__ . '/SettingsTest.php',
	__DIR__ . '/RegistryTest.php',
	__DIR__ . '/PipelineTest.php',
	__DIR__ . '/PresentationTest.php',
	__DIR__ . '/ShippingWorkflowTest.php',
	// Last: it defines FLUENTCART_VERSION, which nothing else may see.
	__DIR__ . '/BootstrapGuardTest.php',
);

foreach ( $suites as $suite ) {
	require $suite;
}

exit( YsStatusTest::report() );
