<?php
/**
 * Turns a menu into portable data (used by backups, export and duplicate).
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Reads a saved menu into a plain array.
 *
 * @phpstan-type MenuItemData array{
 *     id: int,
 *     parent: int,
 *     order: int,
 *     type: string,
 *     object: string,
 *     object_id: int,
 *     slug: string,
 *     path: string,
 *     title: string,
 *     label: string,
 *     url: string,
 *     target: string,
 *     classes: list<string>,
 *     xfn: string,
 *     description: string,
 *     attr_title: string,
 *     meta: array<string, list<mixed>>
 * }
 * @phpstan-type MenuData array{
 *     format: string,
 *     version: int,
 *     plugin_version?: string,
 *     exported_at: string,
 *     site_url: string,
 *     menu: array{id: int, name: string, locations: list<string>},
 *     items: list<MenuItemData>
 * }
 */
final class MenuNest_Serializer {

	const FORMAT  = 'menunest-menu';
	const VERSION = 1;

	/**
	 * Meta keys WordPress manages itself. Everything else is copied, so settings
	 * added by themes and plugins (icons, mega menu options) travel with the item.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_core_meta( string $key ): bool {
		return 0 === strpos( $key, '_menu_item_' ) || in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true );
	}

	/**
	 * Read a saved menu.
	 *
	 * @param int  $menu_id      Menu term ID.
	 * @param bool $include_meta Include extra item settings from themes and plugins.
	 * @return array|WP_Error
	 * @phpstan-return MenuData|WP_Error
	 */
	public static function export_menu( int $menu_id, bool $include_meta = true ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error( 'menunest_no_menu', __( 'Menu not found.', 'menunest-smart-menu-manager' ) );
		}

		$raw_items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'publish' ) );
		$raw_items = is_array( $raw_items ) ? $raw_items : array();

		usort(
			$raw_items,
			static function ( WP_Post $a, WP_Post $b ): int {
				return $a->menu_order - $b->menu_order;
			}
		);

		$items = array();
		foreach ( $raw_items as $item ) {
			$items[] = self::export_item( $item, $include_meta );
		}

		$locations = array();
		foreach ( (array) get_nav_menu_locations() as $location => $assigned ) {
			if ( (int) $assigned === (int) $menu->term_id ) {
				$locations[] = (string) $location;
			}
		}

		return array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'plugin_version' => MENUNEST_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url( '/' ),
			'menu'           => array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'locations' => $locations,
			),
			'items'          => $items,
		);
	}

	/**
	 * Read a property that wp_setup_nav_menu_item() adds to a menu item.
	 *
	 * @param WP_Post $item Menu item.
	 * @param string  $name Property name.
	 * @return mixed
	 */
	public static function prop( WP_Post $item, string $name ) {
		return isset( $item->$name ) ? $item->$name : null;
	}

	/**
	 * One menu item as plain data.
	 *
	 * @param WP_Post $item         Menu item set up by wp_setup_nav_menu_item().
	 * @param bool    $include_meta Include extra settings.
	 * @return array
	 * @phpstan-return MenuItemData
	 */
	private static function export_item( WP_Post $item, bool $include_meta ): array {
		$type      = (string) self::prop( $item, 'type' );
		$object    = (string) self::prop( $item, 'object' );
		$object_id = (int) self::prop( $item, 'object_id' );
		$slug      = '';
		$path      = '';

		if ( 'post_type' === $type ) {
			$post = get_post( $object_id );
			if ( $post instanceof WP_Post ) {
				$slug = $post->post_name;
				if ( is_post_type_hierarchical( $post->post_type ) ) {
					$path = (string) get_page_uri( $post );
				}
			}
		} elseif ( 'taxonomy' === $type ) {
			$term = get_term( $object_id, $object );
			if ( $term instanceof WP_Term ) {
				$slug = $term->slug;
			}
		}

		$meta = array();
		if ( $include_meta ) {
			$all = get_post_meta( $item->ID );
			foreach ( is_array( $all ) ? $all : array() as $key => $values ) {
				$key = (string) $key;
				if ( self::is_core_meta( $key ) ) {
					continue;
				}
				$meta[ $key ] = array_values( array_map( 'maybe_unserialize', (array) $values ) );
			}
		}

		$classes = array();
		foreach ( (array) self::prop( $item, 'classes' ) as $class ) {
			if ( is_string( $class ) && '' !== $class ) {
				$classes[] = $class;
			}
		}

		return array(
			'id'                    => $item->ID,
			'parent'                => (int) self::prop( $item, 'menu_item_parent' ),
			'order'                 => $item->menu_order,
			'type'                  => $type,
			'object'                => $object,
			'object_id'             => $object_id,
			'slug'                  => $slug,
			'path'                  => $path,
			'title'                 => $item->post_title,
			// Empty means "use the linked content's own title".
							'label' => wp_strip_all_tags( (string) self::prop( $item, 'title' ) ),
			'url'                   => (string) self::prop( $item, 'url' ),
			'target'                => (string) self::prop( $item, 'target' ),
			'classes'               => $classes,
			'xfn'                   => (string) self::prop( $item, 'xfn' ),
			'description'           => $item->post_content,
			'attr_title'            => $item->post_excerpt,
			'meta'                  => $meta,
		);
	}

	/**
	 * Fingerprint of a menu's items, used to skip duplicate automatic backups.
	 *
	 * @param array $data Data from export_menu().
	 * @phpstan-param MenuData $data
	 * @return string
	 */
	public static function fingerprint( array $data ): string {
		return md5( (string) wp_json_encode( array( $data['menu']['name'], $data['items'] ) ) );
	}
}
