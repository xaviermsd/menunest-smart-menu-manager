<?php
/**
 * Tests for the history log, settings, duplicate and uninstall.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * @covers MenuNest_History
 * @covers MenuNest_Settings
 * @covers MenuNest_Editor
 */
class MenuNest_History_Settings_Test extends MenuNest_TestCase {

	public function test_log_records_who_what_and_when(): void {
		MenuNest_History::log( 'export', 'Main Menu', 'Exported 6 items.', array( 'items' => 6 ) );

		$entry = MenuNest_History::query()['entries'][0];
		$user  = get_userdata( self::$admin_id );
		$this->assertInstanceOf( WP_User::class, $user );

		$this->assertSame( 'export', $entry['action'] );
		$this->assertSame( 'Main Menu', $entry['menu'] );
		$this->assertSame( 'Exported 6 items.', $entry['summary'] );
		$this->assertSame( $user->display_name, $entry['user'] );
		$this->assertSame( self::$admin_id, $entry['user_id'] );
		$this->assertSame( array( 'items' => 6 ), $entry['details'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT/', $entry['date'] );
	}

	public function test_history_is_newest_first_and_paged(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			MenuNest_History::log( 'export', 'Menu', 'Entry ' . $i );
		}

		$page = MenuNest_History::query( 1, 2 );
		$this->assertSame( 5, $page['total'] );
		$this->assertSame( array( 'Entry 5', 'Entry 4' ), array_column( $page['entries'], 'summary' ) );
		$this->assertSame( array( 'Entry 1' ), array_column( MenuNest_History::query( 3, 2 )['entries'], 'summary' ) );
	}

	public function test_history_is_capped(): void {
		update_option( MenuNest_Settings::OPTION, array( 'max_log_entries' => 50 ) );
		for ( $i = 1; $i <= 55; $i++ ) {
			MenuNest_History::log( 'export', 'Menu', 'Entry ' . $i );
		}

		$this->assertSame( 50, MenuNest_History::query()['total'] );
		$this->assertSame( 'Entry 55', MenuNest_History::query()['entries'][0]['summary'] );
	}

	public function test_log_strips_html(): void {
		MenuNest_History::log( 'export', '<b>Menu</b>', '<script>x</script>Done' );
		$entry = MenuNest_History::query()['entries'][0];
		$this->assertSame( 'Menu', $entry['menu'] );
		$this->assertSame( 'Done', $entry['summary'] );
	}

	public function test_clear_removes_everything_and_notes_it(): void {
		MenuNest_History::log( 'export', 'Menu', 'One' );
		MenuNest_History::log( 'export', 'Menu', 'Two' );
		$this->add_nonce( 'menunest_history_clear' );

		$location = $this->run_handler( array( MenuNest_History::class, 'handle_clear' ) );

		$this->assertStringContainsString( 'menunest_notice=history_cleared', $location );
		$entries = MenuNest_History::query()['entries'];
		$this->assertCount( 1, $entries );
		$this->assertSame( 'history_clear', $entries[0]['action'] );
	}

	public function test_clear_needs_a_nonce(): void {
		$this->expectException( WPDieException::class );
		MenuNest_History::handle_clear();
	}

	public function test_clear_needs_permission(): void {
		wp_set_current_user( self::$subscriber_id );
		$this->add_nonce( 'menunest_history_clear' );
		$this->expectException( WPDieException::class );
		MenuNest_History::handle_clear();
	}

	public function test_settings_defaults(): void {
		delete_option( MenuNest_Settings::OPTION );
		$this->assertSame( 10, MenuNest_Settings::get( 'max_backups' ) );
		$this->assertSame( 1, MenuNest_Settings::get( 'auto_backup' ) );
		$this->assertSame( 500, MenuNest_Settings::get( 'max_log_entries' ) );
		$this->assertSame( 0, MenuNest_Settings::get( 'delete_on_remove' ) );
		$this->assertSame( 0, MenuNest_Settings::get( 'unknown' ) );
	}

	/**
	 * @dataProvider data_settings
	 *
	 * @param mixed                $input    Submitted value.
	 * @param array<string, int>   $expected Stored value.
	 */
	public function test_settings_are_kept_in_range( $input, array $expected ): void {
		$this->assertSame( $expected, MenuNest_Settings::sanitize( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: array<string, int>}>
	 */
	public function data_settings(): array {
		return array(
			'normal'       => array(
				array(
					'max_backups'      => '5',
					'auto_backup'      => '1',
					'max_log_entries'  => '200',
					'delete_on_remove' => '1',
				),
				array(
					'max_backups'      => 5,
					'auto_backup'      => 1,
					'max_log_entries'  => 200,
					'delete_on_remove' => 1,
				),
			),
			'too small'    => array(
				array(
					'max_backups'     => '0',
					'max_log_entries' => '-5',
				),
				array(
					'max_backups'      => 1,
					'auto_backup'      => 0,
					'max_log_entries'  => 50,
					'delete_on_remove' => 0,
				),
			),
			'too big'      => array(
				array(
					'max_backups'     => '9999',
					'max_log_entries' => '999999',
				),
				array(
					'max_backups'      => 100,
					'auto_backup'      => 0,
					'max_log_entries'  => 5000,
					'delete_on_remove' => 0,
				),
			),
			'not an array' => array(
				'garbage',
				array(
					'max_backups'      => 10,
					'auto_backup'      => 0,
					'max_log_entries'  => 500,
					'delete_on_remove' => 0,
				),
			),
		);
	}

	public function test_duplicate_copies_the_menu_and_opens_the_copy(): void {
		$menu = $this->make_menu();
		$this->add_nonce( 'menunest_editor' );
		$_GET['menu'] = (string) $menu['menu_id'];

		$location = $this->run_handler( array( MenuNest_Editor::class, 'handle_duplicate' ) );

		$this->assertSame( 1, preg_match( '/menu=(\d+)/', $location, $m ) );
		$copy_id = (int) $m[1];
		$this->assertNotSame( $menu['menu_id'], $copy_id );
		$this->assertSame( $this->outline( $menu['menu_id'] ), $this->outline( $copy_id ) );
		$copy = wp_get_nav_menu_object( $copy_id );
		$this->assertInstanceOf( WP_Term::class, $copy );
		$this->assertStringEndsWith( '(copy)', $copy->name );
		$this->assertSame( 'duplicate', MenuNest_History::query()['entries'][0]['action'] );
	}

	public function test_duplicate_needs_permission(): void {
		$menu = $this->make_menu();
		wp_set_current_user( self::$subscriber_id );
		$this->add_nonce( 'menunest_editor' );
		$_GET['menu'] = (string) $menu['menu_id'];

		$this->expectException( WPDieException::class );
		MenuNest_Editor::handle_duplicate();
	}

	public function test_uninstall_keeps_data_by_default(): void {
		$menu = $this->make_menu();
		MenuNest_Backups::create( $menu['menu_id'] );
		update_option( MenuNest_Settings::OPTION, array( 'delete_on_remove' => 0 ) );

		$this->run_uninstall();

		$this->assertCount( 1, MenuNest_Backups::query() );
		$this->assertNotFalse( get_option( MenuNest_Settings::OPTION ) );
	}

	public function test_uninstall_removes_data_when_asked_but_never_menus(): void {
		$menu = $this->make_menu();
		MenuNest_Backups::create( $menu['menu_id'] );
		update_option( MenuNest_Settings::OPTION, array( 'delete_on_remove' => 1 ) );
		update_user_meta( self::$admin_id, MenuNest_Editor::CONSENT_META, time() );
		update_user_meta( self::$admin_id, MenuNest_Editor::DISMISS_META, time() );

		$this->run_uninstall();

		$this->assertSame( array(), MenuNest_Backups::query() );
		$this->assertSame( 0, MenuNest_History::query()['total'] );
		$this->assertFalse( get_option( MenuNest_Settings::OPTION ) );
		$this->assertSame( '', get_user_meta( self::$admin_id, MenuNest_Editor::CONSENT_META, true ) );
		$this->assertSame( '', get_user_meta( self::$admin_id, MenuNest_Editor::DISMISS_META, true ) );
		$this->assertCount( 6, $this->outline( $menu['menu_id'] ), 'Menus are untouched.' );
	}

	/**
	 * Run uninstall.php.
	 */
	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'menunest-smart-menu-manager/menunest-smart-menu-manager.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.
		}
		include dirname( __DIR__ ) . '/uninstall.php';
	}
}
