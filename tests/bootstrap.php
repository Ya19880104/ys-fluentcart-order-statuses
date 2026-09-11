<?php
/**
 * Test bootstrap: stubs, autoloader and a two-assertion harness.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

require __DIR__ . '/Stubs/wp.php';

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'YangSheep\\FluentCart\\OrderStatuses\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * The whole harness: a counter, two assertions and a report.
 */
final class YsStatusTest {

	/** @var int */
	private static $passed = 0;

	/** @var string[] */
	private static $failures = array();

	/** @var string */
	private static $group = '';

	/**
	 * @param string $name Group name printed before its assertions.
	 * @return void
	 */
	public static function group( string $name ): void {
		self::$group = $name;
		echo PHP_EOL . '# ' . $name . PHP_EOL;
	}

	/**
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  What is being asserted.
	 * @return void
	 */
	public static function same( $expected, $actual, string $message ): void {
		if ( $expected === $actual ) {
			self::$passed++;
			echo '  ok   ' . $message . PHP_EOL;
			return;
		}

		self::$failures[] = self::$group . ' / ' . $message;

		echo '  FAIL ' . $message . PHP_EOL;
		echo '       expected: ' . self::dump( $expected ) . PHP_EOL;
		echo '       actual:   ' . self::dump( $actual ) . PHP_EOL;
	}

	/**
	 * @param bool   $condition Condition.
	 * @param string $message   What is being asserted.
	 * @return void
	 */
	public static function ok( bool $condition, string $message ): void {
		self::same( true, $condition, $message );
	}

	/**
	 * @return int Process exit code.
	 */
	public static function report(): int {
		echo PHP_EOL;

		if ( empty( self::$failures ) ) {
			echo sprintf( 'PASS — %d assertions.', self::$passed ) . PHP_EOL;
			return 0;
		}

		echo sprintf( 'FAIL — %d passed, %d failed:', self::$passed, count( self::$failures ) ) . PHP_EOL;

		foreach ( self::$failures as $failure ) {
			echo '  - ' . $failure . PHP_EOL;
		}

		return 1;
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function dump( $value ): string {
		return is_scalar( $value ) || null === $value
			? var_export( $value, true )
			: str_replace( array( "\n", '  ' ), array( '', '' ), var_export( $value, true ) );
	}
}
