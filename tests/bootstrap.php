<?php
/**
 * PHPUnit bootstrap: loads the official WordPress test suite, then MenuNest.
 *
 * Set WP_TESTS_DIR to use your own copy of the WordPress tests library;
 * otherwise the one from Composer (wp-phpunit/wp-phpunit) is used.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

$menunest_root      = dirname( __DIR__ );
$menunest_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $menunest_tests_dir ) {
	$menunest_tests_dir = $menunest_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- How wp-phpunit finds its config.
}

define(
	'WP_TESTS_PHPUNIT_POLYFILLS_PATH', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by the WordPress test suite.
	$menunest_root . '/vendor/yoast/phpunit-polyfills'
);

require_once $menunest_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $menunest_root ) {
		require $menunest_root . '/menunest-smart-menu-manager.php';
		// Tests do not run inside wp-admin, so load the admin side by hand.
		MenuNest_Plugin::init_admin();
	}
);

require $menunest_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-menunest-testcase.php';
