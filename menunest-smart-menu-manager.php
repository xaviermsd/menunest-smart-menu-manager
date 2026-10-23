<?php
/**
 * Plugin Name:       MenuNest: Smart Menu Manager
 * Description:       Add menu items under any parent, paste whole lists, collapse and search big menus, and back up, restore, import and export menus with a preview.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Harsh Prajapati
 * Author URI:        https://profiles.wordpress.org/xaviermsd/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       menunest-smart-menu-manager
 * Domain Path:       /languages
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'MENUNEST_VERSION', '1.0.0' );
define( 'MENUNEST_FILE', __FILE__ );
define( 'MENUNEST_DIR', plugin_dir_path( __FILE__ ) );
define( 'MENUNEST_URL', plugin_dir_url( __FILE__ ) );

require_once MENUNEST_DIR . 'includes/class-menunest-settings.php';
require_once MENUNEST_DIR . 'includes/class-menunest-serializer.php';
require_once MENUNEST_DIR . 'includes/class-menunest-builder.php';
require_once MENUNEST_DIR . 'includes/class-menunest-history.php';
require_once MENUNEST_DIR . 'includes/class-menunest-backups.php';
require_once MENUNEST_DIR . 'includes/class-menunest-importer.php';
require_once MENUNEST_DIR . 'includes/class-menunest-exporter.php';
require_once MENUNEST_DIR . 'includes/class-menunest-editor.php';
require_once MENUNEST_DIR . 'includes/class-menunest-admin.php';
require_once MENUNEST_DIR . 'includes/class-menunest-plugin.php';

MenuNest_Plugin::init();
