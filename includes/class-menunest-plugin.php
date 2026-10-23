<?php
/**
 * Plugin bootstrap.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers storage and wires every part of the plugin together.
 */
final class MenuNest_Plugin {

	/**
	 * Capability needed for everything MenuNest does. Same as Appearance > Menus.
	 */
	const CAPABILITY = 'edit_theme_options';

	/**
	 * Hook everything up.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );

		if ( is_admin() ) {
			self::init_admin();
		}
	}

	/**
	 * Admin screens, admin-post.php actions and AJAX actions (admin-ajax.php counts as admin).
	 */
	public static function init_admin(): void {
		MenuNest_Settings::init();
		MenuNest_Backups::init();
		MenuNest_History::init();
		MenuNest_Importer::init();
		MenuNest_Exporter::init();
		MenuNest_Editor::init();
		MenuNest_Admin::init();
	}

	/**
	 * Backups and history entries are stored as private, hidden post types.
	 * No custom database tables are created.
	 */
	public static function register_post_types(): void {
		$shared = array(
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'query_var'           => false,
			'rewrite'             => false,
			'can_export'          => false,
			'supports'            => array( 'title', 'author' ),
		);

		register_post_type(
			MenuNest_Backups::POST_TYPE,
			array_merge(
				$shared,
				array(
					'labels' => array(
						'name'          => __( 'Menu backups', 'menunest-smart-menu-manager' ),
						'singular_name' => __( 'Menu backup', 'menunest-smart-menu-manager' ),
					),
				)
			)
		);

		register_post_type(
			MenuNest_History::POST_TYPE,
			array_merge(
				$shared,
				array(
					'labels' => array(
						'name'          => __( 'Menu history', 'menunest-smart-menu-manager' ),
						'singular_name' => __( 'Menu history entry', 'menunest-smart-menu-manager' ),
					),
				)
			)
		);
	}

	/**
	 * URL of a MenuNest admin tab.
	 *
	 * @param string $tab  Tab slug.
	 * @param array  $args Extra query arguments.
	 * @phpstan-param array<string, int|string> $args
	 * @return string
	 */
	public static function admin_url( string $tab = 'backups', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => MenuNest_Admin::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'themes.php' )
		);
	}
}
