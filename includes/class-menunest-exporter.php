<?php
/**
 * Export.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a menu as a JSON file.
 */
final class MenuNest_Exporter {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_menunest_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Export one menu.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}
		check_admin_referer( 'menunest_export' );

		$menu_id      = isset( $_POST['menu'] ) ? absint( $_POST['menu'] ) : 0;
		$include_meta = ! empty( $_POST['include_meta'] );
		$data         = MenuNest_Serializer::export_menu( $menu_id, $include_meta );

		if ( is_wp_error( $data ) ) {
			wp_safe_redirect( MenuNest_Plugin::admin_url( 'transfer', array( 'menunest_notice' => 'error' ) ) );
			exit;
		}

		MenuNest_History::log(
			'export',
			$data['menu']['name'],
			/* translators: %d: number of menu items. */
			sprintf( _n( 'Exported %d item as JSON.', 'Exported %d items as JSON.', count( $data['items'] ), 'menunest-smart-menu-manager' ), count( $data['items'] ) ),
			array(
				'items'        => count( $data['items'] ),
				'include_meta' => $include_meta,
			)
		);

		self::send_json( $data, 'menunest-' . sanitize_title( $data['menu']['name'] ) . '-' . gmdate( 'Y-m-d' ) );
	}

	/**
	 * Send data as a downloadable JSON file and stop.
	 *
	 * @param array  $data     Data.
	 * @phpstan-param array<string, mixed> $data
	 * @param string $filename File name without extension.
	 */
	public static function send_json( array $data, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
