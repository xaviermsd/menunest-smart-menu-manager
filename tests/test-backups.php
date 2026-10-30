<?php
/**
 * Tests for backups and restore.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * @covers MenuNest_Backups
 */
class MenuNest_Backups_Test extends MenuNest_TestCase {

	public function test_manual_backup_stores_the_saved_menu(): void {
		$menu = $this->make_menu();

		$backup_id = MenuNest_Backups::create( $menu['menu_id'] );

		$this->assertIsInt( $backup_id );
		$this->assertGreaterThan( 0, $backup_id );
		$post = get_post( $backup_id );
		$this->assertSame( MenuNest_Backups::POST_TYPE, $post->post_type );
		$this->assertSame( 'private', $post->post_status, 'Backups are never public.' );
		$this->assertSame( '6', get_post_meta( $backup_id, '_menunest_items', true ) );
		$this->assertSame( 'manual', get_post_meta( $backup_id, '_menunest_reason', true ) );

		$data = MenuNest_Backups::get_data( $backup_id );
		$this->assertIsArray( $data );
		$this->assertCount( 6, $data['items'] );
	}

	public function test_backup_of_missing_menu_fails(): void {
		$this->assertWPError( MenuNest_Backups::create( 999999 ) );
	}

	public function test_automatic_backup_is_skipped_when_nothing_changed(): void {
		$menu = $this->make_menu();

		$first  = MenuNest_Backups::create( $menu['menu_id'], 'before_save' );
		$second = MenuNest_Backups::create( $menu['menu_id'], 'before_save' );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $second );
		$this->assertCount( 1, MenuNest_Backups::query( $menu['menu_id'] ) );
	}

	public function test_manual_backup_is_never_skipped(): void {
		$menu = $this->make_menu();

		MenuNest_Backups::create( $menu['menu_id'] );
		MenuNest_Backups::create( $menu['menu_id'] );

		$this->assertCount( 2, MenuNest_Backups::query( $menu['menu_id'] ) );
	}

	public function test_automatic_backup_of_empty_menu_is_skipped(): void {
		$menu_id = (int) wp_create_nav_menu( 'Empty' );
		$this->assertSame( 0, MenuNest_Backups::create( $menu_id, 'before_save' ) );
	}

	public function test_only_the_newest_backups_are_kept_per_menu(): void {
		update_option( MenuNest_Settings::OPTION, array( 'max_backups' => 3 ) );
		$menu  = $this->make_menu();
		$other = $this->make_menu();
		MenuNest_Backups::create( $other['menu_id'] );

		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = MenuNest_Backups::create( $menu['menu_id'] );
		}

		$kept = wp_list_pluck( MenuNest_Backups::query( $menu['menu_id'] ), 'ID' );
		$this->assertCount( 3, $kept );
		$this->assertSame( array_reverse( array_slice( $ids, 2 ) ), $kept, 'The newest three remain, newest first.' );
		$this->assertCount( 1, MenuNest_Backups::query( $other['menu_id'] ), 'Other menus keep their own backups.' );
	}

	public function test_restore_puts_the_menu_back_and_backs_up_first(): void {
		$menu   = $this->make_menu();
		$before = $this->outline( $menu['menu_id'] );
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$this->assertIsInt( $backup );

		// Damage the menu.
		wp_delete_post( $menu['items']['corals'], true );
		$this->add_item(
			$menu['menu_id'],
			array(
				'menu-item-title' => 'Oops',
				'menu-item-url'   => '#',
			)
		);
		$damaged = $this->outline( $menu['menu_id'] );

		$result = MenuNest_Backups::restore( $backup, 'replace' );

		$this->assertIsArray( $result );
		$this->assertSame( $menu['menu_id'], $result['menu_id'], 'Restored into the same menu, so theme locations stay.' );
		$this->assertSame( $before, $this->outline( $menu['menu_id'] ) );

		$safety = MenuNest_Backups::query( $menu['menu_id'], 1 )[0];
		$this->assertSame( 'before_restore', get_post_meta( $safety->ID, '_menunest_reason', true ) );
		$safety_data = MenuNest_Backups::get_data( $safety->ID );
		$this->assertIsArray( $safety_data );
		$this->assertContains( 'Oops', array_column( $safety_data['items'], 'title' ), 'The damaged version can be restored too.' );
		$this->assertCount( count( $damaged ), $safety_data['items'] );
	}

	public function test_restore_as_new_menu_leaves_the_original_alone(): void {
		$menu   = $this->make_menu();
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$this->assertIsInt( $backup );
		wp_delete_post( $menu['items']['heading'], true );
		$current = $this->outline( $menu['menu_id'] );

		$result = MenuNest_Backups::restore( $backup, 'new' );

		$this->assertIsArray( $result );
		$this->assertNotSame( $menu['menu_id'], $result['menu_id'] );
		$this->assertStringContainsString( '(restored', $result['name'] );
		$this->assertCount( 6, $this->outline( $result['menu_id'] ) );
		$this->assertSame( $current, $this->outline( $menu['menu_id'] ) );
	}

	public function test_restore_of_deleted_menu_creates_a_new_one(): void {
		$menu   = $this->make_menu();
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$this->assertIsInt( $backup );
		wp_delete_nav_menu( $menu['menu_id'] );

		$result = MenuNest_Backups::restore( $backup, 'replace' );

		$this->assertIsArray( $result );
		$this->assertNotSame( $menu['menu_id'], $result['menu_id'] );
		$this->assertCount( 6, $this->outline( $result['menu_id'] ) );
	}

	public function test_restore_of_missing_backup_fails(): void {
		$this->assertWPError( MenuNest_Backups::restore( 999999 ) );
	}

	public function test_damaged_backup_is_rejected(): void {
		$menu   = $this->make_menu();
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$this->assertIsInt( $backup );
		update_post_meta( $backup, '_menunest_data', '{not json' );

		$this->assertNull( MenuNest_Backups::get_data( $backup ) );
		$this->assertWPError( MenuNest_Backups::restore( $backup ) );
	}

	public function test_get_data_ignores_other_post_types(): void {
		$post = self::factory()->post->create();
		$this->assertNull( MenuNest_Backups::get_data( $post ) );
	}

	public function test_delete_and_clear_are_danger_buttons(): void {
		$menu = $this->make_menu();
		MenuNest_Backups::create( $menu['menu_id'] );

		$_GET = array( 'tab' => 'backups' );
		ob_start();
		MenuNest_Admin::render();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/class="button button-small menunest-button-danger"[^>]*menunest_backup_delete/', $html );

		$_GET = array( 'tab' => 'history' );
		ob_start();
		MenuNest_Admin::render();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/class="button menunest-button-danger"[^>]*menunest_history_clear/', $html );
	}

	public function test_history_records_manual_backups_and_restores_only(): void {
		$menu = $this->make_menu();
		MenuNest_Backups::create( $menu['menu_id'], 'before_save' );
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$this->assertIsInt( $backup );
		MenuNest_Backups::restore( $backup );

		$actions = array_column( MenuNest_History::query()['entries'], 'action' );
		$this->assertSame( array( 'restore', 'backup' ), $actions );
	}

	public function test_saving_a_menu_triggers_an_automatic_backup(): void {
		$menu = $this->make_menu();
		$this->simulate_menu_save( $menu['menu_id'], wp_create_nonce( 'update-nav_menu' ) );

		$backups = MenuNest_Backups::query( $menu['menu_id'] );
		$this->assertCount( 1, $backups );
		$this->assertSame( 'before_save', get_post_meta( $backups[0]->ID, '_menunest_reason', true ) );
	}

	public function test_no_automatic_backup_without_a_valid_nonce(): void {
		$menu = $this->make_menu();
		$this->simulate_menu_save( $menu['menu_id'], 'invalid' );
		$this->assertCount( 0, MenuNest_Backups::query( $menu['menu_id'] ) );
	}

	public function test_no_automatic_backup_for_users_who_cannot_edit_menus(): void {
		$menu = $this->make_menu();
		wp_set_current_user( self::$subscriber_id );
		$this->simulate_menu_save( $menu['menu_id'], wp_create_nonce( 'update-nav_menu' ) );
		$this->assertCount( 0, MenuNest_Backups::query( $menu['menu_id'] ) );
	}

	public function test_no_automatic_backup_when_turned_off(): void {
		update_option( MenuNest_Settings::OPTION, array( 'auto_backup' => 0 ) );
		$menu = $this->make_menu();
		$this->simulate_menu_save( $menu['menu_id'], wp_create_nonce( 'update-nav_menu' ) );
		$this->assertCount( 0, MenuNest_Backups::query( $menu['menu_id'] ) );
	}

	public function test_restore_handler_needs_permission(): void {
		$menu   = $this->make_menu();
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		wp_set_current_user( self::$subscriber_id );
		$this->add_nonce( 'menunest_backup_restore' );
		$_GET['backup'] = (string) $backup;

		$this->expectException( WPDieException::class );
		MenuNest_Backups::handle_restore();
	}

	public function test_restore_handler_needs_a_nonce(): void {
		$menu           = $this->make_menu();
		$_GET['backup'] = (string) MenuNest_Backups::create( $menu['menu_id'] );

		$this->expectException( WPDieException::class );
		MenuNest_Backups::handle_restore();
	}

	public function test_restore_handler_restores_and_opens_the_menu(): void {
		$menu   = $this->make_menu();
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$this->add_nonce( 'menunest_backup_restore' );
		$_GET['backup'] = (string) $backup;
		$_GET['mode']   = 'replace';

		$location = $this->run_handler( array( MenuNest_Backups::class, 'handle_restore' ) );

		$this->assertStringContainsString( 'nav-menus.php', $location );
		$this->assertStringContainsString( 'menu=' . $menu['menu_id'], $location );
		$this->assertStringContainsString( 'menunest_notice=restored', $location );
	}

	public function test_delete_handler_removes_only_backups(): void {
		$menu   = $this->make_menu();
		$backup = MenuNest_Backups::create( $menu['menu_id'] );
		$page   = self::factory()->post->create();

		$this->add_nonce( 'menunest_backup_delete' );
		$_GET['backup'] = (string) $page;
		$this->run_handler( array( MenuNest_Backups::class, 'handle_delete' ) );
		$this->assertNotNull( get_post( $page ), 'A normal post can never be deleted through this handler.' );

		$_GET['backup'] = (string) $backup;
		$this->run_handler( array( MenuNest_Backups::class, 'handle_delete' ) );
		$this->assertNull( get_post( $backup ) );
	}

	public function test_create_handler_saves_a_backup(): void {
		$menu = $this->make_menu();
		$this->add_nonce( 'menunest_backup_create' );
		$_POST['menu'] = (string) $menu['menu_id'];

		$location = $this->run_handler( array( MenuNest_Backups::class, 'handle_create' ) );

		$this->assertStringContainsString( 'menunest_notice=backup_created', $location );
		$this->assertCount( 1, MenuNest_Backups::query( $menu['menu_id'] ) );
	}

	/**
	 * Pretend the Appearance > Menus form was submitted.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $nonce   Nonce value.
	 */
	private function simulate_menu_save( int $menu_id, string $nonce ): void {
		$_POST['action']                = 'update';
		$_POST['menu']                  = (string) $menu_id;
		$_POST['update-nav-menu-nonce'] = $nonce;
		MenuNest_Backups::auto_backup_before_save();
	}
}
