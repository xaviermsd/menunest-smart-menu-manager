<?php
/**
 * Import with preview.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Reads an exported menu file, shows what will happen, then imports it as a new menu.
 *
 * @phpstan-import-type MenuData from MenuNest_Serializer
 * @phpstan-import-type MenuItemData from MenuNest_Serializer
 * @phpstan-import-type Context from MenuNest_Builder
 * @phpstan-import-type Resolved from MenuNest_Builder
 */
final class MenuNest_Importer {

	const MAX_BYTES = 5242880;
	// 5 MB.
	const MAX_ITEMS  = 5000;
	const BATCH_SIZE = 20;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_menunest_import_preview', array( __CLASS__, 'handle_preview' ) );
		add_action( 'wp_ajax_menunest_import_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_menunest_import_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_menunest_import_search', array( __CLASS__, 'ajax_search' ) );
	}

	/**
	 * Step 1: read the uploaded or pasted file, check it and open the preview.
	 */
	public static function handle_preview(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_import' );

		$raw = '';
		if ( ! empty( $_FILES['import_file']['tmp_name'] ) && isset( $_FILES['import_file']['error'], $_FILES['import_file']['size'] ) ) {
			$tmp = sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) );
			if ( UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] || ! is_uploaded_file( $tmp ) ) {
				self::fail( __( 'The file could not be uploaded. Please try again.', 'menunest-smart-menu-manager' ) );
			}
			if ( (int) $_FILES['import_file']['size'] > self::MAX_BYTES ) {
				self::fail( __( 'The file is too large. The limit is 5 MB.', 'menunest-smart-menu-manager' ) );
			}
			$raw = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local uploaded file.
		} elseif ( ! empty( $_POST['import_text'] ) ) {
			$raw = trim( wp_unslash( $_POST['import_text'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and every field is sanitized in self::validate().
		}

		if ( '' === $raw ) {
			self::fail( __( 'Choose a MenuNest export file, or paste its contents.', 'menunest-smart-menu-manager' ) );
		}
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			self::fail( __( 'The file is too large. The limit is 5 MB.', 'menunest-smart-menu-manager' ) );
		}

		$data = self::validate( $raw );
		if ( is_wp_error( $data ) ) {
			self::fail( $data->get_error_message() );
		}

		// Store the cleaned data as JSON; it is checked again every time it is read.
		$token = strtolower( wp_generate_password( 20, false ) );
		// Lowercase: tokens pass through sanitize_key() when read.
		set_transient(
			'menunest_import_' . $token,
			array(
				'user' => get_current_user_id(),
				'json' => (string) wp_json_encode( $data ),
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect(
			MenuNest_Plugin::admin_url(
				'transfer',
				array(
					'step'     => 'preview',
					'token'    => $token,
					'_wpnonce' => wp_create_nonce( 'menunest_preview_' . $token ),
				)
			)
		);
		exit;
	}

	/**
	 * Decode and clean an export file. Nothing in the file is trusted.
	 *
	 * @param string $raw File contents.
	 * @return array|WP_Error
	 * @phpstan-return MenuData|WP_Error
	 */
	public static function validate( string $raw ) {
		$data = json_decode( $raw, true, 64 );

		if ( ! is_array( $data ) || ! is_array( $data['menu'] ?? null ) ) {
			return new WP_Error( 'menunest_bad_json', __( 'This is not a valid JSON file.', 'menunest-smart-menu-manager' ) );
		}
		if ( ( $data['format'] ?? '' ) !== MenuNest_Serializer::FORMAT || ! isset( $data['menu'], $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'menunest_bad_format', __( 'This file was not made by MenuNest. Export the menu with MenuNest first.', 'menunest-smart-menu-manager' ) );
		}
		if ( (int) ( $data['version'] ?? 0 ) > MenuNest_Serializer::VERSION ) {
			return new WP_Error( 'menunest_new_format', __( 'This file was made by a newer version of MenuNest. Please update the plugin first.', 'menunest-smart-menu-manager' ) );
		}
		if ( count( $data['items'] ) > self::MAX_ITEMS ) {
			/* translators: %d: maximum number of items. */
			return new WP_Error( 'menunest_too_many', sprintf( __( 'This menu has too many items. The limit is %d.', 'menunest-smart-menu-manager' ), self::MAX_ITEMS ) );
		}

		$items = array();
		$seen  = array();
		foreach ( $data['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$clean = self::clean_item( $item );
			// A parent must come earlier in the file; otherwise the item moves to the top level.
			if ( $clean['parent'] && ! isset( $seen[ $clean['parent'] ] ) ) {
				$clean['parent'] = 0;
			}
			$seen[ $clean['id'] ] = true;
			$items[]              = $clean;
		}

		$name = sanitize_text_field( (string) ( $data['menu']['name'] ?? '' ) );

		return array(
			'format'      => MenuNest_Serializer::FORMAT,
			'version'     => MenuNest_Serializer::VERSION,
			'exported_at' => sanitize_text_field( (string) ( $data['exported_at'] ?? '' ) ),
			'site_url'    => esc_url_raw( (string) ( $data['site_url'] ?? '' ) ),
			'menu'        => array(
				'id'        => absint( $data['menu']['id'] ?? 0 ),
				'name'      => '' !== $name ? $name : __( 'Imported menu', 'menunest-smart-menu-manager' ),
				'locations' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $data['menu']['locations'] ?? array() ) ) ) ),
			),
			'items'       => $items,
		);
	}

	/**
	 * Clean one item.
	 *
	 * @param array $item Raw item.
	 * @phpstan-param array<mixed> $item
	 * @return array
	 * @phpstan-return MenuItemData
	 */
	private static function clean_item( array $item ): array {
		$types = array( 'post_type', 'taxonomy', 'post_type_archive', 'custom' );
		$type  = in_array( $item['type'] ?? '', $types, true ) ? $item['type'] : 'custom';

		$meta = array();
		if ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) {
			foreach ( $item['meta'] as $key => $values ) {
				$key = (string) $key;
				if ( ! self::is_importable_meta_key( $key ) ) {
					continue;
				}
				$meta[ $key ] = array_map( array( __CLASS__, 'clean_value' ), array_values( (array) $values ) );
			}
		}

		$target = (string) ( $item['target'] ?? '' );

		return array(
			'id'          => absint( $item['id'] ),
			'parent'      => absint( $item['parent'] ?? 0 ),
			'order'       => (int) ( $item['order'] ?? 0 ),
			'type'        => $type,
			'object'      => sanitize_key( (string) ( $item['object'] ?? 'custom' ) ),
			'object_id'   => absint( $item['object_id'] ?? 0 ),
			'slug'        => sanitize_title( (string) ( $item['slug'] ?? '' ) ),
			'path'        => implode( '/', array_filter( array_map( 'sanitize_title', explode( '/', (string) ( $item['path'] ?? '' ) ) ) ) ),
			'title'       => wp_kses_post( (string) ( $item['title'] ?? '' ) ),
			'label'       => sanitize_text_field( (string) ( $item['label'] ?? '' ) ),
			'url'         => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
			'target'      => '_blank' === $target ? '_blank' : '',
			'classes'     => array_values( array_filter( array_map( 'sanitize_html_class', (array) ( $item['classes'] ?? array() ) ) ) ),
			'xfn'         => sanitize_text_field( (string) ( $item['xfn'] ?? '' ) ),
			'description' => wp_kses_post( (string) ( $item['description'] ?? '' ) ),
			'attr_title'  => sanitize_text_field( (string) ( $item['attr_title'] ?? '' ) ),
			'meta'        => $meta,
		);
	}

	/**
	 * Extra item settings may only use plain key names, and never WordPress's own.
	 *
	 * @param string $key Meta key from the file.
	 * @return bool
	 */
	public static function is_importable_meta_key( string $key ): bool {
		if ( ! preg_match( '/^[A-Za-z0-9_\-.:]{1,191}$/', $key ) ) {
			return false;
		}
		if ( MenuNest_Serializer::is_core_meta( $key ) || 0 === strpos( $key, '_wp_' ) ) {
			return false;
		}

		/**
		 * Filters whether an extra menu item setting may be imported.
		 *
		 * @param bool   $allowed Whether the key is allowed.
		 * @param string $key     Meta key.
		 */
		return (bool) apply_filters( 'menunest_import_meta_key', true, $key );
	}

	/**
	 * Clean an extra-setting value of any shape.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Nesting level.
	 * @return mixed
	 */
	public static function clean_value( $value, int $depth = 0 ) {
		if ( is_array( $value ) ) {
			if ( $depth > 5 ) {
				return array();
			}
			$clean = array();
			foreach ( $value as $k => $v ) {
				$clean[ sanitize_text_field( (string) $k ) ] = self::clean_value( $v, $depth + 1 );
			}
			return $clean;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return wp_kses_post( (string) $value );
	}

	/**
	 * Read the stored file for a preview token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 * @phpstan-return MenuData|null
	 */
	public static function get_upload( string $token ): ?array {
		$stored = get_transient( 'menunest_import_' . sanitize_key( $token ) );
		if ( ! is_array( $stored ) || ! isset( $stored['user'], $stored['json'] ) || get_current_user_id() !== (int) $stored['user'] ) {
			return null;
		}
		$data = self::validate( (string) $stored['json'] );
		return is_wp_error( $data ) ? null : $data;
	}

	/**
	 * Is this file from this site?
	 *
	 * @param array $data Import data.
	 * @phpstan-param MenuData $data
	 * @return array Context for MenuNest_Builder::resolve().
	 * @phpstan-return Context
	 */
	public static function context( array $data ): array {
		return array(
			'same_site'  => untrailingslashit( $data['site_url'] ) === untrailingslashit( home_url( '/' ) ),
			'source_url' => $data['site_url'],
		);
	}

	/**
	 * Everything the preview screen shows.
	 *
	 * @param array $data Import data.
	 * @phpstan-param MenuData $data
	 * @return array
	 * @phpstan-return array{context: Context, rows: list<array{item: MenuItemData, depth: int, resolved: Resolved}>, counts: array<string, int>, matched: int, unmatched: int, missing: list<string>, locations: list<array{slug: string, available: bool, label: string}>}
	 */
	public static function build_preview( array $data ): array {
		$context  = self::context( $data );
		$rows     = array();
		$depth_of = array();
		$counts   = array(
			MenuNest_Builder::STATUS_MATCHED   => 0,
			MenuNest_Builder::STATUS_CONVERTED => 0,
			MenuNest_Builder::STATUS_CUSTOM    => 0,
			MenuNest_Builder::STATUS_REWRITTEN => 0,
		);
		$missing  = array();

		foreach ( $data['items'] as $item ) {
			$depth                   = $item['parent'] && isset( $depth_of[ $item['parent'] ] ) ? $depth_of[ $item['parent'] ] + 1 : 0;
			$depth_of[ $item['id'] ] = $depth;
			$resolved                = MenuNest_Builder::resolve( $item, $context );

			++$counts[ $resolved['status'] ];

			if ( 'post_type' === $item['type'] && ! post_type_exists( $item['object'] ) ) {
				/* translators: %s: post type name. */
				$missing[ 'pt:' . $item['object'] ] = sprintf( __( 'Content type "%s" is not available on this site.', 'menunest-smart-menu-manager' ), $item['object'] );
			} elseif ( 'taxonomy' === $item['type'] && ! taxonomy_exists( $item['object'] ) ) {
				/* translators: %s: taxonomy name. */
				$missing[ 'tx:' . $item['object'] ] = sprintf( __( 'Taxonomy "%s" is not available on this site.', 'menunest-smart-menu-manager' ), $item['object'] );
			}

			$rows[] = array(
				'item'     => $item,
				'depth'    => $depth,
				'resolved' => $resolved,
			);
		}//end foreach

		$registered = get_registered_nav_menus();
		$locations  = array();
		foreach ( $data['menu']['locations'] as $location ) {
			$locations[] = array(
				'slug'      => $location,
				'available' => isset( $registered[ $location ] ),
				'label'     => $registered[ $location ] ?? $location,
			);
		}

		return array(
			'context'   => $context,
			'rows'      => $rows,
			'counts'    => $counts,
			'matched'   => $counts[ MenuNest_Builder::STATUS_MATCHED ] + $counts[ MenuNest_Builder::STATUS_CUSTOM ] + $counts[ MenuNest_Builder::STATUS_REWRITTEN ],
			'unmatched' => $counts[ MenuNest_Builder::STATUS_CONVERTED ],
			'missing'   => array_values( $missing ),
			'locations' => $locations,
		);
	}

	/**
	 * Step 2: create the menu and plan the work.
	 */
	public static function ajax_start(): void {
		check_ajax_referer( 'menunest_import', 'nonce' );
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ) ), 403 );
		}

		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = self::get_upload( $token );
		if ( ! $data ) {
			wp_send_json_error( array( 'message' => __( 'This preview has expired. Please upload the file again.', 'menunest-smart-menu-manager' ) ) );
		}

		$overrides = array();
		if ( isset( $_POST['overrides'] ) && is_array( $_POST['overrides'] ) ) {
			foreach ( wp_unslash( $_POST['overrides'] ) as $old_id => $choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked against a strict pattern below.
				$choice = (string) $choice;
				if ( preg_match( '/^(auto|custom|post:\d+|term:\d+)$/', $choice ) ) {
					$overrides[ absint( $old_id ) ] = $choice;
				}
			}
		}

		$name = isset( $_POST['menu_name'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_name'] ) ) : '';
		$name = MenuNest_Builder::unique_menu_name( '' !== $name ? $name : $data['menu']['name'] );

		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			wp_send_json_error( array( 'message' => $menu_id->get_error_message() ) );
		}

		set_transient(
			'menunest_job_' . $token,
			array(
				'user'         => get_current_user_id(),
				'menu_id'      => (int) $menu_id,
				'name'         => $name,
				'position'     => 0,
				'id_map'       => array(),
				'overrides'    => $overrides,
				'include_meta' => ! empty( $_POST['include_meta'] ),
				'stats'        => array(
					'created'   => 0,
					'failed'    => 0,
					'converted' => 0,
				),
			),
			HOUR_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'total'   => count( $data['items'] ),
				'menu_id' => (int) $menu_id,
				'name'    => $name,
			)
		);
	}

	/**
	 * Step 3: create the next batch of items. Called until finished, so big menus
	 * never hit a server time limit and the screen can show progress.
	 */
	public static function ajax_step(): void {
		check_ajax_referer( 'menunest_import', 'nonce' );
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ) ), 403 );
		}

		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = self::get_upload( $token );
		$job   = get_transient( 'menunest_job_' . $token );

		if ( ! $data || ! is_array( $job ) || get_current_user_id() !== (int) $job['user'] ) {
			wp_send_json_error( array( 'message' => __( 'This import has expired. Please start again.', 'menunest-smart-menu-manager' ) ) );
		}

		$context = self::context( $data );
		$total   = count( $data['items'] );
		$end     = min( $total, $job['position'] + self::BATCH_SIZE );
		$errors  = array();

		for ( $i = $job['position']; $i < $end; $i++ ) {
			$item                = $data['items'][ $i ];
			$context['override'] = $job['overrides'][ $item['id'] ] ?? 'auto';
			$resolved            = MenuNest_Builder::resolve( $item, $context );
			$id_map              = $job['id_map'];

			if ( MenuNest_Builder::create_item( $job['menu_id'], $item, $resolved, $id_map, $job['include_meta'] ) ) {
				++$job['stats']['created'];
				if ( MenuNest_Builder::STATUS_CONVERTED === $resolved['status'] ) {
					++$job['stats']['converted'];
				}
			} else {
				++$job['stats']['failed'];
				/* translators: %s: menu item title. */
				$errors[] = sprintf( __( 'Could not create "%s".', 'menunest-smart-menu-manager' ), $item['label'] );
			}
			$job['id_map'] = $id_map;
		}

		$job['position'] = $end;
		$finished        = $end >= $total;

		if ( $finished ) {
			delete_transient( 'menunest_job_' . $token );
			delete_transient( 'menunest_import_' . $token );

			MenuNest_History::log(
				'import',
				$job['name'],
				/* translators: 1: number of items created, 2: source site address. */
				sprintf( __( 'Imported %1$d items from %2$s as a new menu.', 'menunest-smart-menu-manager' ), $job['stats']['created'], $data['site_url'] ),
				array(
					'source_url'  => $data['site_url'],
					'exported_at' => $data['exported_at'],
					'stats'       => $job['stats'],
					'overrides'   => count( array_filter( $job['overrides'], static fn( $v ) => 'auto' !== $v ) ),
				)
			);
		} else {
			set_transient( 'menunest_job_' . $token, $job, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'done'     => $end,
				'total'    => $total,
				'finished' => $finished,
				'stats'    => $job['stats'],
				'errors'   => $errors,
				'edit_url' => add_query_arg(
					array(
						'action' => 'edit',
						'menu'   => $job['menu_id'],
					),
					admin_url( 'nav-menus.php' )
				),
			)
		);
	}

	/**
	 * Search pages, posts and terms for the manual mapping box.
	 */
	public static function ajax_search(): void {
		check_ajax_referer( 'menunest_import', 'nonce' );
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ) ), 403 );
		}

		$q       = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$results = array();

		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( $results );
		}

		$post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
		$posts      = get_posts(
			array(
				's'                => $q,
				'post_type'        => array_keys( $post_types ),
				'post_status'      => array( 'publish', 'private' ),
				'numberposts'      => 10,
				'suppress_filters' => false,
			)
		);
		foreach ( $posts as $post ) {
			$results[] = array(
				'value' => 'post:' . $post->ID,
				'label' => $post_types[ $post->post_type ]->labels->singular_name . ': ' . wp_strip_all_tags( get_the_title( $post ) ),
			);
		}

		$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'objects' );
		$terms      = get_terms(
			array(
				'taxonomy'   => array_keys( $taxonomies ),
				'name__like' => $q,
				'hide_empty' => false,
				'number'     => 10,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$results[] = array(
					'value' => 'term:' . $term->term_id,
					'label' => $taxonomies[ $term->taxonomy ]->labels->singular_name . ': ' . $term->name,
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Back to the upload form with an error message.
	 *
	 * @param string $message Message.
	 * @phpstan-return never
	 */
	private static function fail( string $message ): void {
		set_transient( 'menunest_import_error_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( MenuNest_Plugin::admin_url( 'transfer', array( 'menunest_notice' => 'import_error' ) ) );
		exit;
	}
}
