<?php
/**
 * Backups and restore.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Saves copies of menus and puts them back.
 *
 * @phpstan-import-type MenuData from MenuNest_Serializer
 * @phpstan-import-type Stats from MenuNest_Builder
 */
final class MenuNest_Backups {

	const POST_TYPE = 'menunest_backup';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'load-nav-menus.php', array( __CLASS__, 'auto_backup_before_save' ), 1 );
		add_action( 'wp_ajax_menunest_backup_create', array( __CLASS__, 'ajax_create' ) );
		add_action( 'admin_post_menunest_backup_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_menunest_backup_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_menunest_backup_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_menunest_backup_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Why a backup was made.
	 *
	 * @return array<string, string>
	 */
	public static function reason_labels(): array {
		return array(
			'manual'         => __( 'Manual', 'menunest-smart-menu-manager' ),
			'before_save'    => __( 'Automatic, before saving', 'menunest-smart-menu-manager' ),
			'before_restore' => __( 'Automatic, before restoring', 'menunest-smart-menu-manager' ),
		);
	}

	/**
	 * Back up the saved menu right before WordPress saves new changes to it.
	 * Runs on load-nav-menus.php, which fires before Appearance > Menus handles the form.
	 */
	public static function auto_backup_before_save(): void {
		// Only the Save Menu form of Appearance > Menus carries this nonce field.
		if ( ! isset( $_POST['update-nav-menu-nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['update-nav-menu-nonce'] ) ), 'update-nav_menu' ) ) {
			return;
		}
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			return;
		}
		if ( ! isset( $_POST['action'] ) || 'update' !== sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			return;
		}
		$menu_id = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
		if ( ! $menu_id || ! MenuNest_Settings::get( 'auto_backup' ) ) {
			return;
		}

		self::create( $menu_id, 'before_save' );
	}

	/**
	 * Save a backup.
	 *
	 * @param int    $menu_id Menu term ID.
	 * @param string $reason  Key from reason_labels().
	 * @return int|WP_Error Backup ID, 0 when skipped (nothing changed since the last automatic backup).
	 */
	public static function create( int $menu_id, string $reason = 'manual' ) {
		$data = MenuNest_Serializer::export_menu( $menu_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$hash = MenuNest_Serializer::fingerprint( $data );

		if ( 'manual' !== $reason ) {
			if ( empty( $data['items'] ) ) {
				return 0;
			}
			$latest = self::query( (int) $menu_id, 1 );
			if ( $latest && get_post_meta( $latest[0]->ID, '_menunest_hash', true ) === $hash ) {
				return 0;
			}
		}

		$backup_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => $data['menu']['name'],
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $backup_id ) ) {
			return $backup_id;
		}

		update_post_meta( $backup_id, '_menunest_menu_id', (int) $menu_id );
		update_post_meta( $backup_id, '_menunest_reason', sanitize_key( $reason ) );
		update_post_meta( $backup_id, '_menunest_items', count( $data['items'] ) );
		update_post_meta( $backup_id, '_menunest_hash', $hash );
		update_post_meta( $backup_id, '_menunest_data', wp_slash( (string) wp_json_encode( $data ) ) );

		self::prune( (int) $menu_id );

		if ( 'manual' === $reason ) {
			MenuNest_History::log(
				'backup',
				$data['menu']['name'],
				/* translators: %d: number of menu items. */
				sprintf( _n( 'Backup saved (%d item).', 'Backup saved (%d items).', count( $data['items'] ), 'menunest-smart-menu-manager' ), count( $data['items'] ) ),
				array( 'backup_id' => $backup_id )
			);
		}

		return (int) $backup_id;
	}

	/**
	 * Backups, newest first.
	 *
	 * @param int $menu_id Only this menu (0 = all menus).
	 * @param int $limit   How many (-1 = all).
	 * @return WP_Post[]
	 * @phpstan-return list<WP_Post>
	 */
	public static function query( int $menu_id = 0, int $limit = -1 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $limit,
			'orderby'        => 'date ID',
			'order'          => 'DESC',
		);
		if ( $menu_id ) {
			$args['meta_key']   = '_menunest_menu_id'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small, admin-only list.
			$args['meta_value'] = (string) $menu_id; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small, admin-only list.
		}
		return array_values( get_posts( $args ) );
	}

	/**
	 * Read a backup's data.
	 *
	 * @param int $backup_id Backup ID.
	 * @return array|null
	 * @phpstan-return MenuData|null
	 */
	public static function get_data( int $backup_id ): ?array {
		$post = get_post( $backup_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		// Read through the same checks as an import, so a damaged backup can never break a menu.
		$data = MenuNest_Importer::validate( (string) get_post_meta( $post->ID, '_menunest_data', true ) );
		return is_wp_error( $data ) ? null : $data;
	}

	/**
	 * Keep the number of backups per menu set in Settings.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	private static function prune( int $menu_id ): void {
		$keep = MenuNest_Settings::get( 'max_backups' );
		foreach ( array_slice( self::query( $menu_id ), $keep ) as $old ) {
			wp_delete_post( $old->ID, true );
		}
	}

	/**
	 * Put a backup back.
	 *
	 * @param int    $backup_id Backup ID.
	 * @param string $mode      "replace" restores into the original menu, "new" creates a new menu.
	 * @return array|WP_Error The menu ID, its name and counts of created items.
	 * @phpstan-return array{menu_id: int, name: string, stats: Stats}|WP_Error
	 */
	public static function restore( int $backup_id, string $mode = 'replace' ) {
		$data = self::get_data( $backup_id );
		if ( ! $data ) {
			return new WP_Error( 'menunest_no_backup', __( 'Backup not found.', 'menunest-smart-menu-manager' ) );
		}

		$context = MenuNest_Importer::context( $data );
		$menu    = wp_get_nav_menu_object( $data['menu']['id'] );

		if ( 'replace' === $mode && $menu ) {
			// Safety net: keep the current state so this restore can be undone too.
			self::create( $menu->term_id, 'before_restore' );
			MenuNest_Builder::empty_menu( $menu->term_id );
			$menu_id = (int) $menu->term_id;
			$name    = $menu->name;
		} else {
			$name = MenuNest_Builder::unique_menu_name(
				/* translators: 1: menu name, 2: backup date. */
				sprintf( __( '%1$s (restored %2$s)', 'menunest-smart-menu-manager' ), $data['menu']['name'], get_the_date( 'Y-m-d H:i', $backup_id ) )
			);
			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}
		}

		$stats = MenuNest_Builder::build_all( (int) $menu_id, $data['items'], $context );

		MenuNest_History::log(
			'restore',
			$name,
			/* translators: 1: number of items, 2: backup date. */
			sprintf( __( 'Restored %1$d items from the backup of %2$s.', 'menunest-smart-menu-manager' ), $stats['created'], get_the_date( 'Y-m-d H:i', $backup_id ) ),
			array(
				'backup_id' => (int) $backup_id,
				'mode'      => 'replace' === $mode && $menu ? 'replace' : 'new',
				'stats'     => $stats,
			)
		);

		return array(
			'menu_id' => (int) $menu_id,
			'name'    => $name,
			'stats'   => $stats,
		);
	}

	/**
	 * "Back up now" on the Menus screen.
	 */
	public static function ajax_create(): void {
		check_ajax_referer( 'menunest_editor', 'nonce' );
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ) ), 403 );
		}

		$result = self::create( isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0 );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'backup_id' => $result ) );
	}

	/**
	 * "Back up now" on the Backups tab.
	 */
	public static function handle_create(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_backup_create' );
		$result = self::create( isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0 );
		self::done( is_wp_error( $result ) ? 'error' : 'backup_created' );
	}

	/**
	 * Restore buttons.
	 */
	public static function handle_restore(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_backup_restore' );
		$backup_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0;
		$mode      = ( isset( $_GET['mode'] ) && 'new' === $_GET['mode'] ) ? 'new' : 'replace';

		$result = self::restore( $backup_id, $mode );
		if ( is_wp_error( $result ) ) {
			self::done( 'error' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'action'          => 'edit',
					'menu'            => $result['menu_id'],
					'menunest_notice' => 'restored',
				),
				admin_url( 'nav-menus.php' )
			)
		);
		exit;
	}

	/**
	 * Download a backup as a JSON file that can be imported anywhere.
	 */
	public static function handle_download(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_backup_download' );
		$backup_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0;
		$data      = self::get_data( $backup_id );
		if ( ! $data ) {
			self::done( 'error' );
		}

		MenuNest_History::log(
			'backup_export',
			$data['menu']['name'],
			/* translators: %s: backup date. */
			sprintf( __( 'Backup of %s downloaded as JSON.', 'menunest-smart-menu-manager' ), get_the_date( 'Y-m-d H:i', $backup_id ) )
		);

		MenuNest_Exporter::send_json( $data, 'menunest-backup-' . sanitize_title( $data['menu']['name'] ) . '-' . get_the_date( 'Y-m-d-His', $backup_id ) );
	}

	/**
	 * Delete a backup.
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_backup_delete' );
		$backup_id = isset( $_GET['backup'] ) ? absint( $_GET['backup'] ) : 0;
		$post      = get_post( $backup_id );

		if ( $post && self::POST_TYPE === $post->post_type ) {
			MenuNest_History::log(
				'backup_delete',
				$post->post_title,
				/* translators: %s: backup date. */
				sprintf( __( 'Backup of %s deleted.', 'menunest-smart-menu-manager' ), get_the_date( 'Y-m-d H:i', $post ) )
			);
			wp_delete_post( $post->ID, true );
		}
		self::done( 'backup_deleted' );
	}

	/**
	 * Back to the Backups tab with a message.
	 *
	 * @param string $notice Notice key.
	 * @phpstan-return never
	 */
	private static function done( string $notice ): void {
		wp_safe_redirect( MenuNest_Plugin::admin_url( 'backups', array( 'menunest_notice' => $notice ) ) );
		exit;
	}
}
