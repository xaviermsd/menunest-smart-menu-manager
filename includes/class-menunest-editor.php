<?php
/**
 * Tools on Appearance > Menus.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Loads the menu editor helpers and handles their server-side actions.
 */
final class MenuNest_Editor {

	const CONSENT_META = 'menunest_backup_consent';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'load-nav-menus.php', array( __CLASS__, 'add_help_tab' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_ajax_menunest_save_consent', array( __CLASS__, 'ajax_save_consent' ) );
		add_action( 'admin_post_menunest_duplicate', array( __CLASS__, 'handle_duplicate' ) );
	}

	/**
	 * Load scripts and styles on the Menus screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'nav-menus.php' !== $hook || ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'menunest-editor', MENUNEST_URL . 'assets/css/editor.css', array(), MENUNEST_VERSION );
		wp_enqueue_script( 'menunest-editor', MENUNEST_URL . 'assets/js/editor.js', array( 'jquery', 'nav-menu', 'wp-i18n' ), MENUNEST_VERSION, true );
		wp_set_script_translations( 'menunest-editor', 'menunest-smart-menu-manager', MENUNEST_DIR . 'languages' );

		wp_localize_script(
			'menunest-editor',
			'MenuNestEditor',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'postUrl'    => admin_url( 'admin-post.php' ),
				'nonce'      => wp_create_nonce( 'menunest_editor' ),
				'consent'    => (bool) get_user_meta( get_current_user_id(), self::CONSENT_META, true ),
				'trees'      => self::term_trees(),
				'taxLabels'  => self::taxonomy_labels(),
				'menus'      => self::menus(),
				'menusUrl'   => admin_url( 'nav-menus.php?action=edit' ),
				'backupsUrl' => MenuNest_Plugin::admin_url( 'backups' ),
			)
		);
	}

	/**
	 * Parent and name of every term in hierarchical taxonomies that can be added
	 * to menus. Used by "Include subcategories" and by Bulk add name matching.
	 *
	 * @return array<string, array<int, array{0:int,1:string}>>
	 */
	private static function term_trees(): array {
		$trees = array();

		foreach ( get_taxonomies( array( 'show_in_nav_menus' => true ), 'names' ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'               => $taxonomy,
					'hide_empty'             => false,
					'number'                 => 3000,
					'update_term_meta_cache' => false,
				)
			);
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$map = array();
			foreach ( $terms as $term ) {
				$map[ $term->term_id ] = array( (int) $term->parent, html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
			}
			$trees[ $taxonomy ] = $map;
		}

		return $trees;
	}

	/**
	 * Singular taxonomy names, for example "Product category".
	 *
	 * @return array<string, string>
	 */
	private static function taxonomy_labels(): array {
		$labels = array();
		foreach ( get_taxonomies( array( 'show_in_nav_menus' => true ), 'objects' ) as $taxonomy ) {
			$labels[ $taxonomy->name ] = $taxonomy->labels->singular_name;
		}
		return $labels;
	}

	/**
	 * All menus, for the Bulk add menu switcher.
	 *
	 * @return array<int, array{id:int,name:string}>
	 */
	private static function menus(): array {
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'   => (int) $menu->term_id,
				'name' => $menu->name,
			);
		}
		return $menus;
	}

	/**
	 * Remember that this user accepted the backup notice.
	 */
	public static function ajax_save_consent(): void {
		check_ajax_referer( 'menunest_editor', 'nonce' );
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), self::CONSENT_META, time() );
		wp_send_json_success();
	}

	/**
	 * Copy a saved menu and open the copy.
	 */
	public static function handle_duplicate(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_editor' );

		$menu_id = isset( $_GET['menu'] ) ? absint( $_GET['menu'] ) : 0;
		$data    = MenuNest_Serializer::export_menu( $menu_id );
		if ( is_wp_error( $data ) ) {
			wp_die( esc_html( $data->get_error_message() ) );
		}

		/* translators: %s: menu name. */
		$name   = MenuNest_Builder::unique_menu_name( sprintf( __( '%s (copy)', 'menunest-smart-menu-manager' ), $data['menu']['name'] ) );
		$new_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		$stats = MenuNest_Builder::build_all(
			$new_id,
			$data['items'],
			array(
				'same_site'  => true,
				'source_url' => home_url( '/' ),
			)
		);

		MenuNest_History::log(
			'duplicate',
			$name,
			/* translators: 1: original menu name, 2: number of items. */
			sprintf( __( 'Copied from "%1$s" (%2$d items).', 'menunest-smart-menu-manager' ), $data['menu']['name'], $stats['created'] ),
			array( 'source_menu_id' => $menu_id )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'action'          => 'edit',
					'menu'            => $new_id,
					'menunest_notice' => 'duplicated',
				),
				admin_url( 'nav-menus.php' )
			)
		);
		exit;
	}

	/**
	 * Messages after a redirect to the Menus screen.
	 */
	public static function notices(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses which message to show.
		$notice = isset( $_GET['menunest_notice'] ) ? sanitize_key( wp_unslash( $_GET['menunest_notice'] ) ) : '';
		if ( 'nav-menus.php' !== $pagenow || '' === $notice ) {
			return;
		}

		$messages = array(
			'duplicated' => __( 'Menu duplicated. You are now editing the copy. It is not assigned to any location yet.', 'menunest-smart-menu-manager' ),
			'restored'   => __( 'Backup restored. A backup of the previous version was saved first, so you can undo this from Appearance > MenuNest.', 'menunest-smart-menu-manager' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			wp_admin_notice(
				$messages[ $notice ],
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * Explain the tools in the Help tab of the Menus screen.
	 */
	public static function add_help_tab(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			return;
		}

		$tips = array(
			__( 'Add new items under: pick a parent once, and every "Add to Menu" button puts new items under it.', 'menunest-smart-menu-manager' ),
			__( 'Plus button on an item: type a link text and URL to add a sub-item right there. Press Enter to add several in a row.', 'menunest-smart-menu-manager' ),
			__( 'Bulk add: paste a list, one item per line. Add a link after a | sign and indent lines to make sub-items.', 'menunest-smart-menu-manager' ),
			__( 'Include subcategories: adds a category with all its subcategories, already nested.', 'menunest-smart-menu-manager' ),
			__( 'Arrow on an item: fold or unfold its sub-items. Collapse all and Expand all are above the menu.', 'menunest-smart-menu-manager' ),
			__( 'Search this menu: highlights matching items. Press Enter to jump to the next one.', 'menunest-smart-menu-manager' ),
			__( 'Back up now and Duplicate menu: one-click safety copies. Backups are also made automatically every time you save.', 'menunest-smart-menu-manager' ),
		);

		$content  = '<p>' . esc_html__( 'MenuNest adds these tools to this screen. Nothing changes on your site until you click Save Menu.', 'menunest-smart-menu-manager' ) . '</p><ul>';
		$content .= implode( '', array_map( static fn( $tip ) => '<li>' . esc_html( $tip ) . '</li>', $tips ) );
		$content .= '</ul><p><a href="' . esc_url( MenuNest_Plugin::admin_url( 'backups' ) ) . '">' . esc_html__( 'Open backups, import, export and history', 'menunest-smart-menu-manager' ) . '</a></p>';

		$screen->add_help_tab(
			array(
				'id'      => 'menunest',
				'title'   => __( 'MenuNest tools', 'menunest-smart-menu-manager' ),
				'content' => $content,
			)
		);
	}
}
