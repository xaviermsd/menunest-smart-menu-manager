<?php
/**
 * Rebuilds menu items from portable data (used by restore, import and duplicate).
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Matches exported items to content on this site and creates the menu items.
 *
 * @phpstan-import-type MenuItemData from MenuNest_Serializer
 * @phpstan-type Context array{same_site: bool, source_url: string, override?: string}
 * @phpstan-type ItemArgs array<string, int|string>
 * @phpstan-type Resolved array{args: ItemArgs, status: string, target: string, warning: string}
 * @phpstan-type Stats array{created: int, failed: int, converted: int}
 */
final class MenuNest_Builder {

	const STATUS_MATCHED   = 'matched';
	const STATUS_CONVERTED = 'converted';
	const STATUS_CUSTOM    = 'custom';
	const STATUS_REWRITTEN = 'rewritten';

	/**
	 * Work out what an item becomes on this site.
	 *
	 * The context array holds: same_site (bool, true for restore and duplicate),
	 * source_url (home URL of the source site) and override (auto, custom,
	 * post:ID or term:ID, chosen in the import preview).
	 *
	 * @param array $item    One item from MenuNest_Serializer::export_menu().
	 * @param array $context Where the data came from and any manual choice.
	 * @phpstan-param MenuItemData $item
	 * @phpstan-param Context $context
	 * @return array Arguments for wp_update_nav_menu_item() (without parent), status (STATUS_*), target (what it links to) and warning.
	 * @phpstan-return Resolved
	 */
	public static function resolve( array $item, array $context ): array {
		$override = $context['override'] ?? 'auto';

		$args = array(
			'menu-item-title'       => $item['title'],
			'menu-item-description' => $item['description'],
			'menu-item-attr-title'  => $item['attr_title'],
			'menu-item-target'      => $item['target'],
			'menu-item-classes'     => implode( ' ', $item['classes'] ),
			'menu-item-xfn'         => $item['xfn'],
			'menu-item-status'      => 'publish',
		);

		if ( 'auto' !== $override ) {
			$manual = self::resolve_override( $override, $args, $item, $context );
			if ( $manual ) {
				return $manual;
			}
		}

		switch ( $item['type'] ) {
			case 'post_type':
				$post_id = self::find_post( $item, $context['same_site'] );
				if ( $post_id ) {
					return self::linked(
						$args,
						array(
							'menu-item-type'      => 'post_type',
							'menu-item-object'    => $item['object'],
							'menu-item-object-id' => $post_id,
						),
						self::post_label( $post_id )
					);
				}
				$warning = post_type_exists( $item['object'] )
					/* translators: 1: content type, for example "page". 2: item slug. */
					? sprintf( __( 'No %1$s with the address "%2$s" exists on this site.', 'menunest-smart-menu-manager' ), $item['object'], $item['path'] ? $item['path'] : $item['slug'] )
					/* translators: %s: post type name. */
					: sprintf( __( 'The content type "%s" does not exist on this site.', 'menunest-smart-menu-manager' ), $item['object'] );
				return self::converted( $args, $item, $context, $warning );

			case 'taxonomy':
				$term_id = self::find_term( $item, $context['same_site'] );
				if ( $term_id ) {
					$term = get_term( $term_id, $item['object'] );
					$term = $term instanceof WP_Term ? $term : null;
					return self::linked(
						$args,
						array(
							'menu-item-type'      => 'taxonomy',
							'menu-item-object'    => $item['object'],
							'menu-item-object-id' => $term_id,
						),
						self::term_label( $term )
					);
				}
				$warning = taxonomy_exists( $item['object'] )
					/* translators: 1: taxonomy name, for example "category". 2: term slug. */
					? sprintf( __( 'No %1$s with the slug "%2$s" exists on this site.', 'menunest-smart-menu-manager' ), $item['object'], $item['slug'] )
					/* translators: %s: taxonomy name. */
					: sprintf( __( 'The taxonomy "%s" does not exist on this site.', 'menunest-smart-menu-manager' ), $item['object'] );
				return self::converted( $args, $item, $context, $warning );

			case 'post_type_archive':
				$type = get_post_type_object( $item['object'] );
				if ( $type && $type->has_archive ) {
					return self::linked(
						$args,
						array(
							'menu-item-type'      => 'post_type_archive',
							'menu-item-object'    => $item['object'],
							'menu-item-object-id' => $item['object_id'],
						),
						/* translators: %s: post type label, for example "Products". */
						sprintf( __( '%s archive', 'menunest-smart-menu-manager' ), $type->labels->name )
					);
				}
				/* translators: %s: post type name. */
				return self::converted( $args, $item, $context, sprintf( __( 'The "%s" archive does not exist on this site.', 'menunest-smart-menu-manager' ), $item['object'] ) );

			default:
				$url              = self::normalise_url( $item['url'], $context );
				$rewritten        = $url !== $item['url'];
				$result           = self::linked(
					$args,
					array(
						'menu-item-type' => 'custom',
						'menu-item-url'  => $url,
					),
					$url
				);
				$result['status'] = $rewritten ? self::STATUS_REWRITTEN : self::STATUS_CUSTOM;
				return $result;
		}//end switch
	}

	/**
	 * Create one menu item. Parents must be created before their children.
	 *
	 * @param int   $menu_id      Menu term ID.
	 * @param array $item         Source item (needs id, parent, order, meta).
	 * @param array $resolved     Result of resolve().
	 * @param array $id_map       Old item ID => new item ID. Updated in place.
	 * @param bool  $include_meta Copy extra item settings.
	 * @phpstan-param MenuItemData $item
	 * @phpstan-param Resolved $resolved
	 * @phpstan-param array<int, int> $id_map
	 * @return int New item ID, or 0 on failure.
	 */
	public static function create_item( int $menu_id, array $item, array $resolved, array &$id_map, bool $include_meta ): int {
		$parent = $item['parent'];
		$args   = $resolved['args'];

		$args['menu-item-parent-id'] = isset( $id_map[ $parent ] ) ? (int) $id_map[ $parent ] : 0;
		$args['menu-item-position']  = $item['order'];

		// A converted item needs a visible title even when the original followed its content's title.
		if ( '' === $args['menu-item-title'] && 'custom' === ( $args['menu-item-type'] ?? '' ) ) {
			$args['menu-item-title'] = $item['label'];
		}

		$new_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return 0;
		}

		$id_map[ $item['id'] ] = (int) $new_id;

		if ( $include_meta ) {
			foreach ( $item['meta'] as $key => $values ) {
				if ( MenuNest_Serializer::is_core_meta( $key ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					add_post_meta( (int) $new_id, $key, wp_slash( $value ) );
				}
			}
		}

		return (int) $new_id;
	}

	/**
	 * Create every item at once (for restore and duplicate, which stay small and local).
	 *
	 * @param int   $menu_id      Menu term ID.
	 * @param array $items        Items from export data.
	 * @param array $context      See resolve().
	 * @param bool  $include_meta Copy extra item settings.
	 * @phpstan-param list<MenuItemData> $items
	 * @phpstan-param Context $context
	 * @return array Counts of created, failed and converted items.
	 * @phpstan-return Stats
	 */
	public static function build_all( int $menu_id, array $items, array $context, bool $include_meta = true ): array {
		$id_map = array();
		$stats  = array(
			'created'   => 0,
			'failed'    => 0,
			'converted' => 0,
		);

		foreach ( $items as $item ) {
			$resolved = self::resolve( $item, $context );
			if ( self::create_item( $menu_id, $item, $resolved, $id_map, $include_meta ) ) {
				++$stats['created'];
				if ( self::STATUS_CONVERTED === $resolved['status'] ) {
					++$stats['converted'];
				}
			} else {
				++$stats['failed'];
			}
		}

		return $stats;
	}

	/**
	 * Delete every item in a menu, including unsaved drafts.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	public static function empty_menu( int $menu_id ): void {
		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			wp_delete_post( $item->ID, true );
		}
	}

	/**
	 * A menu name that is not used yet.
	 *
	 * @param string $base Wanted name.
	 * @return string
	 */
	public static function unique_menu_name( string $base ): string {
		$name = $base;
		$n    = 2;
		while ( wp_get_nav_menu_object( $name ) ) {
			$name = $base . ' ' . $n;
			++$n;
		}
		return $name;
	}

	/**
	 * Point a custom link at this site when it pointed at the source site.
	 *
	 * @param string $url     Exported URL.
	 * @param array  $context See resolve().
	 * @phpstan-param Context $context
	 * @return string
	 */
	public static function normalise_url( string $url, array $context ): string {
		if ( $context['same_site'] || '' === $context['source_url'] || '' === $url || '#' === $url[0] ) {
			return $url;
		}

		$no_scheme = static function ( string $value ): string {
			return (string) preg_replace( '#^https?://(www\.)?#i', '', $value );
		};
		$source    = untrailingslashit( $no_scheme( $context['source_url'] ) );
		$bare      = $no_scheme( $url );

		if ( '' === $source ) {
			return $url;
		}

		// Only addresses on the source site itself: the domain must end right after it.
		$rest = substr( $bare, strlen( $source ) );
		if ( 0 !== strpos( $bare, $source ) || ( '' !== $rest && ! in_array( $rest[0], array( '/', '?', '#' ), true ) ) ) {
			return $url;
		}

		// Keep the path exactly as it was, including a trailing slash or none.
		return home_url( '/' . ltrim( $rest, '/' ) );
	}

	/**
	 * Find a post on this site.
	 *
	 * @param array $item      Exported item.
	 * @param bool  $same_site Trust the stored ID.
	 * @phpstan-param MenuItemData $item
	 * @return int
	 */
	private static function find_post( array $item, bool $same_site ): int {
		if ( ! post_type_exists( $item['object'] ) ) {
			return 0;
		}

		if ( $same_site && $item['object_id'] ) {
			$post = get_post( $item['object_id'] );
			if ( $post instanceof WP_Post && $post->post_type === $item['object'] && 'trash' !== $post->post_status ) {
				return $post->ID;
			}
		}

		if ( $item['path'] && is_post_type_hierarchical( $item['object'] ) ) {
			$post = get_page_by_path( $item['path'], OBJECT, $item['object'] );
			if ( $post instanceof WP_Post && 'trash' !== $post->post_status ) {
				return $post->ID;
			}
		}

		if ( $item['slug'] ) {
			$found = get_posts(
				array(
					'name'             => $item['slug'],
					'post_type'        => $item['object'],
					'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'numberposts'      => 1,
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}

		return 0;
	}

	/**
	 * Find a term on this site.
	 *
	 * @param array $item      Exported item.
	 * @param bool  $same_site Trust the stored ID.
	 * @phpstan-param MenuItemData $item
	 * @return int
	 */
	private static function find_term( array $item, bool $same_site ): int {
		if ( ! taxonomy_exists( $item['object'] ) ) {
			return 0;
		}

		if ( $same_site && $item['object_id'] ) {
			$term = get_term( $item['object_id'], $item['object'] );
			if ( $term instanceof WP_Term ) {
				return $term->term_id;
			}
		}

		if ( $item['slug'] ) {
			$term = get_term_by( 'slug', $item['slug'], $item['object'] );
			if ( $term instanceof WP_Term ) {
				return $term->term_id;
			}
		}

		return 0;
	}

	/**
	 * Apply a manual mapping chosen in the import preview.
	 *
	 * @param string $override Manual choice.
	 * @param array  $args     Base arguments.
	 * @param array  $item     Exported item.
	 * @param array  $context  Context.
	 * @phpstan-param ItemArgs $args
	 * @phpstan-param MenuItemData $item
	 * @phpstan-param Context $context
	 * @return array|null Null when the choice is invalid (falls back to automatic).
	 * @phpstan-return Resolved|null
	 */
	private static function resolve_override( string $override, array $args, array $item, array $context ): ?array {
		// A manually chosen target keeps the item's own label, not the target's title.
		if ( '' === $args['menu-item-title'] ) {
			$args['menu-item-title'] = $item['label'];
		}

		if ( 'custom' === $override ) {
			$url = self::normalise_url( $item['url'], $context );
			return array(
				'args'    => array_merge(
					$args,
					array(
						'menu-item-type' => 'custom',
						'menu-item-url'  => $url,
					)
				),
				'status'  => self::STATUS_CONVERTED,
				'target'  => $url,
				'warning' => '',
			);
		}

		$parts = explode( ':', $override, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		if ( 'post' === $parts[0] ) {
			$post = get_post( absint( $parts[1] ) );
			if ( $post instanceof WP_Post && is_post_type_viewable( $post->post_type ) ) {
				return self::linked(
					$args,
					array(
						'menu-item-type'      => 'post_type',
						'menu-item-object'    => $post->post_type,
						'menu-item-object-id' => $post->ID,
					),
					self::post_label( $post->ID )
				);
			}
		} elseif ( 'term' === $parts[0] ) {
			$term = get_term( absint( $parts[1] ) );
			if ( $term instanceof WP_Term ) {
				return self::linked(
					$args,
					array(
						'menu-item-type'      => 'taxonomy',
						'menu-item-object'    => $term->taxonomy,
						'menu-item-object-id' => $term->term_id,
					),
					self::term_label( $term )
				);
			}
		}//end if

		return null;
	}

	/**
	 * Result for an item linked to content on this site.
	 *
	 * @param array  $args  Base arguments.
	 * @param array  $link  Type, object and object ID.
	 * @param string $label What it links to.
	 * @phpstan-param ItemArgs $args
	 * @phpstan-param ItemArgs $link
	 * @return array
	 * @phpstan-return Resolved
	 */
	private static function linked( array $args, array $link, string $label ): array {
		return array(
			'args'    => array_merge( $args, $link ),
			'status'  => self::STATUS_MATCHED,
			'target'  => $label,
			'warning' => '',
		);
	}

	/**
	 * Result for an item that becomes a custom link.
	 *
	 * @param array  $args    Base arguments.
	 * @param array  $item    Exported item.
	 * @param array  $context Context.
	 * @param string $warning Why.
	 * @phpstan-param ItemArgs $args
	 * @phpstan-param MenuItemData $item
	 * @phpstan-param Context $context
	 * @return array
	 * @phpstan-return Resolved
	 */
	private static function converted( array $args, array $item, array $context, string $warning ): array {
		$url = self::normalise_url( $item['url'], $context );
		return array(
			'args'    => array_merge(
				$args,
				array(
					'menu-item-type' => 'custom',
					'menu-item-url'  => '' !== $url ? $url : '#',
				)
			),
			'status'  => self::STATUS_CONVERTED,
			'target'  => '' !== $url ? $url : '#',
			'warning' => $warning,
		);
	}

	/**
	 * "Page: About us".
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function post_label( int $post_id ): string {
		$post = get_post( $post_id );
		$type = $post instanceof WP_Post ? get_post_type_object( $post->post_type ) : null;
		$name = $type ? $type->labels->singular_name : '';
		return trim( $name . ': ' . wp_strip_all_tags( get_the_title( $post_id ) ), ': ' );
	}

	/**
	 * "Category: Corals".
	 *
	 * @param WP_Term|null $term Term.
	 * @return string
	 */
	private static function term_label( ?WP_Term $term ): string {
		if ( ! $term ) {
			return '';
		}
		$tax = get_taxonomy( $term->taxonomy );
		return ( $tax ? $tax->labels->singular_name . ': ' : '' ) . $term->name;
	}
}
