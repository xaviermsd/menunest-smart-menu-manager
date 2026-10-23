<?php
/**
 * History log.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Records who did what to which menu, and when.
 *
 * @phpstan-type Entry array{date: string, user: string, user_id: int, action: string, menu: string, summary: string, details: array<mixed>}
 */
final class MenuNest_History {

	const POST_TYPE = 'menunest_log';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_menunest_history_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_menunest_history_clear', array( __CLASS__, 'handle_clear' ) );
	}

	/**
	 * Human readable names for each action.
	 *
	 * @return array<string, string>
	 */
	public static function action_labels(): array {
		return array(
			'export'        => __( 'Export', 'menunest-smart-menu-manager' ),
			'import'        => __( 'Import', 'menunest-smart-menu-manager' ),
			'backup'        => __( 'Backup', 'menunest-smart-menu-manager' ),
			'restore'       => __( 'Restore', 'menunest-smart-menu-manager' ),
			'backup_delete' => __( 'Backup deleted', 'menunest-smart-menu-manager' ),
			'backup_export' => __( 'Backup downloaded', 'menunest-smart-menu-manager' ),
			'duplicate'     => __( 'Duplicate', 'menunest-smart-menu-manager' ),
			'history_clear' => __( 'History cleared', 'menunest-smart-menu-manager' ),
		);
	}

	/**
	 * Add an entry.
	 *
	 * @param string $action  Key from action_labels().
	 * @param string $menu    Menu name.
	 * @param string $summary One sentence.
	 * @param array  $details Extra facts (counts, warnings).
	 * @phpstan-param array<string, mixed> $details
	 */
	public static function log( string $action, string $menu, string $summary, array $details = array() ): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => wp_strip_all_tags( $summary ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_menunest_action', sanitize_key( $action ) );
		update_post_meta( $post_id, '_menunest_menu', sanitize_text_field( $menu ) );
		update_post_meta( $post_id, '_menunest_details', wp_slash( (string) wp_json_encode( $details ) ) );

		self::prune();
	}

	/**
	 * Keep the log at the size set in Settings.
	 */
	private static function prune(): void {
		$keep = MenuNest_Settings::get( 'max_log_entries' );
		$old  = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 100,
				'offset'         => $keep,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		foreach ( $old as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * One page of entries.
	 *
	 * @param int $page     Page number, starting at 1.
	 * @param int $per_page Entries per page.
	 * @return array Entries and the total count.
	 * @phpstan-return array{entries: list<Entry>, total: int}
	 */
	public static function query( int $page = 1, int $per_page = 50 ): array {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => $per_page,
				'paged'          => max( 1, (int) $page ),
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);

		$entries = array();
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$entries[] = self::to_array( $post );
			}
		}

		return array(
			'entries' => $entries,
			'total'   => $query->found_posts,
		);
	}

	/**
	 * An entry as plain data.
	 *
	 * @param WP_Post $post Log post.
	 * @return array
	 * @phpstan-return Entry
	 */
	public static function to_array( WP_Post $post ): array {
		$user    = get_userdata( (int) $post->post_author );
		$details = json_decode( (string) get_post_meta( $post->ID, '_menunest_details', true ), true );

		return array(
			'date'    => gmdate( 'c', (int) get_post_timestamp( $post ) ),
			'user'    => $user ? $user->display_name : __( 'Unknown user', 'menunest-smart-menu-manager' ),
			'user_id' => (int) $post->post_author,
			'action'  => (string) get_post_meta( $post->ID, '_menunest_action', true ),
			'menu'    => (string) get_post_meta( $post->ID, '_menunest_menu', true ),
			'summary' => $post->post_title,
			'details' => is_array( $details ) ? $details : array(),
		);
	}

	/**
	 * Download the whole log as JSON or plain text.
	 */
	public static function handle_download(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_history_download' );

		$format  = ( isset( $_GET['format'] ) && 'txt' === $_GET['format'] ) ? 'txt' : 'json';
		$entries = array_map(
			array( __CLASS__, 'to_array' ),
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 5000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One-off admin download; the log is capped in Settings.
					'orderby'        => 'date ID',
					'order'          => 'DESC',
				)
			)
		);

		$labels   = self::action_labels();
		$filename = 'menunest-history-' . gmdate( 'Y-m-d' ) . '.' . $format;

		nocache_headers();
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		if ( 'txt' === $format ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			$lines = array();
			foreach ( $entries as $entry ) {
				$lines[] = implode(
					' | ',
					array(
						$entry['date'],
						$entry['user'],
						$labels[ $entry['action'] ] ?? $entry['action'],
						$entry['menu'],
						$entry['summary'],
					)
				);
			}
			echo implode( "\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text file download, not HTML.
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'site_url'     => home_url( '/' ),
				'generated_at' => gmdate( 'c' ),
				'entries'      => $entries,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	/**
	 * Delete every entry.
	 */
	public static function handle_clear(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_history_clear' );

		do {
			$ids = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		} while ( $ids );

		self::log( 'history_clear', '', __( 'History cleared.', 'menunest-smart-menu-manager' ) );

		wp_safe_redirect( MenuNest_Plugin::admin_url( 'history', array( 'menunest_notice' => 'history_cleared' ) ) );
		exit;
	}
}
