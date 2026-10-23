<?php
/**
 * Settings.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Stores and validates the plugin settings (one option).
 */
final class MenuNest_Settings {

	const OPTION = 'menunest_settings';
	const GROUP  = 'menunest_settings';

	/**
	 * Default values.
	 *
	 * @return array<string, int>
	 */
	public static function defaults(): array {
		return array(
			'max_backups'      => 10,
			'auto_backup'      => 1,
			'max_log_entries'  => 500,
			'delete_on_remove' => 0,
		);
	}

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'capability' ) );
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * People who can edit menus can change these settings.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return MenuNest_Plugin::CAPABILITY;
	}

	/**
	 * Clean submitted values.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, int>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'max_backups'      => min( 100, max( 1, absint( $input['max_backups'] ?? 10 ) ) ),
			'auto_backup'      => empty( $input['auto_backup'] ) ? 0 : 1,
			'max_log_entries'  => min( 5000, max( 50, absint( $input['max_log_entries'] ?? 500 ) ) ),
			'delete_on_remove' => empty( $input['delete_on_remove'] ) ? 0 : 1,
		);
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting name.
	 * @return int
	 */
	public static function get( string $key ): int {
		$values = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return isset( $values[ $key ] ) ? (int) $values[ $key ] : 0;
	}
}
