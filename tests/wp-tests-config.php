<?php
/**
 * WordPress test configuration. Every value can be set with an environment variable.
 *
 * WP_CORE_DIR must point to a WordPress install that the tests may wipe.
 * Never point it, or the database below, at a real site.
 *
 * @package MenuNest
 */

define( 'ABSPATH', rtrim( (string) ( getenv( 'WP_CORE_DIR' ) ? getenv( 'WP_CORE_DIR' ) : '/tmp/wordpress' ), '/' ) . '/' );

define( 'WP_DEFAULT_THEME', 'twentytwentyone' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ? getenv( 'WP_DB_PASS' ) : '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required by the WordPress test suite.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'MenuNest Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
