<?php
/**
 * Runs when MenuNest is deleted from the Plugins screen.
 * Data is only removed when the "Remove data" setting is on. Menus are never touched.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$menunest_settings = get_option( 'menunest_settings', array() );

if ( empty( $menunest_settings['delete_on_remove'] ) ) {
	return;
}

foreach ( array( 'menunest_backup', 'menunest_log' ) as $menunest_post_type ) {
	do {
		$menunest_ids = get_posts(
			array(
				'post_type'      => $menunest_post_type,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		foreach ( $menunest_ids as $menunest_id ) {
			wp_delete_post( $menunest_id, true );
		}
	} while ( $menunest_ids );
}

delete_option( 'menunest_settings' );
delete_metadata( 'user', 0, 'menunest_backup_consent', '', true );
