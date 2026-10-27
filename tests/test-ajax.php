<?php
/**
 * Tests for the AJAX actions: import in batches, mapping search, Back up now, backup notice.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * @covers MenuNest_Importer
 * @covers MenuNest_Backups
 * @covers MenuNest_Editor
 */
class MenuNest_Ajax_Test extends WP_Ajax_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Subscriber.
	 *
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * Users shared by all tests.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Run as an administrator.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Call an AJAX action and decode its JSON answer.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $post   Request data.
	 * @return array<string, mixed>
	 */
	private function ajax( string $action, array $post ): array {
		$_POST                = $post;
		$_REQUEST             = $post;
		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'The action answered with JSON.' );
		return $response;
	}

	/**
	 * A menu with $count custom links, the second half nested under the first item.
	 *
	 * @param int $count Number of items.
	 * @return int Menu ID.
	 */
	private function menu_with_items( int $count ): int {
		$menu_id = (int) wp_create_nav_menu( 'Source ' . wp_generate_password( 6, false ) );
		$first   = 0;
		for ( $i = 1; $i <= $count; $i++ ) {
			$id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => 'Item ' . $i,
					'menu-item-url'       => home_url( '/item-' . $i . '/' ),
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $i,
					'menu-item-parent-id' => $i > $count / 2 ? $first : 0,
				)
			);
			if ( 1 === $i ) {
				$first = (int) $id;
			}
		}
		return $menu_id;
	}

	/**
	 * Store a preview the way the upload form does and return its token.
	 *
	 * @param int $menu_id Menu to export.
	 * @return string
	 */
	private function stored_preview( int $menu_id ): string {
		$data = MenuNest_Serializer::export_menu( $menu_id );
		$this->assertIsArray( $data );
		$token = 'tok' . strtolower( wp_generate_password( 10, false ) );
		set_transient(
			'menunest_import_' . $token,
			array(
				'user' => self::$admin_id,
				'json' => wp_json_encode( $data ),
			)
		);
		return $token;
	}

	public function test_import_runs_in_batches_and_keeps_structure(): void {
		$source = $this->menu_with_items( 45 );
		$token  = $this->stored_preview( $source );
		$nonce  = wp_create_nonce( 'menunest_import' );

		$start = $this->ajax(
			'menunest_import_start',
			array(
				'nonce'     => $nonce,
				'token'     => $token,
				'menu_name' => 'Imported',
			)
		);
		$this->assertTrue( $start['success'] );
		$this->assertSame( 45, $start['data']['total'] );
		$this->assertSame( 'Imported', $start['data']['name'] );

		$steps = 0;
		do {
			$step = $this->ajax(
				'menunest_import_step',
				array(
					'nonce' => $nonce,
					'token' => $token,
				)
			);
			$this->assertTrue( $step['success'] );
			++$steps;
		} while ( ! $step['data']['finished'] && $steps < 10 );

		$this->assertSame( 3, $steps, '45 items in batches of 20 take three calls.' );
		$this->assertSame( 45, $step['data']['stats']['created'] );

		$items = wp_get_nav_menu_items( $start['data']['menu_id'] );
		$this->assertIsArray( $items );
		$this->assertCount( 45, $items );
		$nested = array_filter(
			$items,
			static function ( WP_Post $item ): bool {
				return '0' !== (string) $item->menu_item_parent;
			}
		);
		$this->assertCount( 23, $nested, 'Items 23 to 45 stay under item 1.' );

		$this->assertFalse( get_transient( 'menunest_import_' . $token ), 'Temporary data is removed when done.' );
		$this->assertSame( 'import', MenuNest_History::query()['entries'][0]['action'] );
	}

	public function test_manual_choices_are_applied(): void {
		$source = $this->menu_with_items( 2 );
		$token  = $this->stored_preview( $source );
		$nonce  = wp_create_nonce( 'menunest_import' );
		$page   = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Target page',
			)
		);
		$data   = MenuNest_Serializer::export_menu( $source );
		$this->assertIsArray( $data );
		$first  = $data['items'][0]['id'];
		$second = $data['items'][1]['id'];

		$start = $this->ajax(
			'menunest_import_start',
			array(
				'nonce'     => $nonce,
				'token'     => $token,
				'overrides' => array(
					$first  => 'post:' . $page,
					$second => 'term:not-a-number',
				),
			)
		);
		$this->ajax(
			'menunest_import_step',
			array(
				'nonce' => $nonce,
				'token' => $token,
			)
		);

		$items = wp_get_nav_menu_items( $start['data']['menu_id'] );
		$this->assertIsArray( $items );
		$this->assertSame( 'post_type', $items[0]->type );
		$this->assertSame( $page, (int) $items[0]->object_id );
		$this->assertSame( 'custom', $items[1]->type, 'An invalid choice is ignored.' );
	}

	public function test_import_name_is_made_unique(): void {
		wp_create_nav_menu( 'Taken' );
		$token = $this->stored_preview( $this->menu_with_items( 1 ) );

		$start = $this->ajax(
			'menunest_import_start',
			array(
				'nonce'     => wp_create_nonce( 'menunest_import' ),
				'token'     => $token,
				'menu_name' => 'Taken',
			)
		);

		$this->assertSame( 'Taken 2', $start['data']['name'] );
	}

	public function test_expired_preview_is_reported(): void {
		$response = $this->ajax(
			'menunest_import_start',
			array(
				'nonce' => wp_create_nonce( 'menunest_import' ),
				'token' => 'missing',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'expired', $response['data']['message'] );
	}

	public function test_another_user_cannot_continue_an_import(): void {
		$token = $this->stored_preview( $this->menu_with_items( 3 ) );
		$this->ajax(
			'menunest_import_start',
			array(
				'nonce' => wp_create_nonce( 'menunest_import' ),
				'token' => $token,
			)
		);

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$response = $this->ajax(
			'menunest_import_step',
			array(
				'nonce' => wp_create_nonce( 'menunest_import' ),
				'token' => $token,
			)
		);

		$this->assertFalse( $response['success'] );
	}

	/**
	 * @dataProvider data_protected_actions
	 */
	public function test_actions_reject_a_bad_nonce( string $action ): void {
		$_POST    = array( 'nonce' => 'bad' );
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test setup.

		$this->expectException( WPAjaxDieStopException::class );
		$this->_handleAjax( $action );
	}

	/**
	 * @dataProvider data_protected_actions
	 */
	public function test_actions_reject_users_who_cannot_edit_menus( string $action, string $nonce_action ): void {
		wp_set_current_user( self::$subscriber_id );

		$response = $this->ajax( $action, array( 'nonce' => wp_create_nonce( $nonce_action ) ) );

		$this->assertFalse( $response['success'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_protected_actions(): array {
		return array(
			'import start'   => array( 'menunest_import_start', 'menunest_import' ),
			'import step'    => array( 'menunest_import_step', 'menunest_import' ),
			'search'         => array( 'menunest_import_search', 'menunest_import' ),
			'back up now'    => array( 'menunest_backup_create', 'menunest_editor' ),
			'notice accept'  => array( 'menunest_save_consent', 'menunest_editor' ),
			'notice dismiss' => array( 'menunest_dismiss_notice', 'menunest_editor' ),
		);
	}

	public function test_search_finds_pages_and_categories(): void {
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Coral care guide',
			)
		);
		self::factory()->category->create( array( 'name' => 'Coral food' ) );

		$response = $this->ajax(
			'menunest_import_search',
			array(
				'nonce' => wp_create_nonce( 'menunest_import' ),
				'q'     => 'Coral',
			)
		);

		$labels = array_column( $response['data'], 'label' );
		$this->assertContains( 'Page: Coral care guide', $labels );
		$this->assertContains( 'Category: Coral food', $labels );
	}

	public function test_search_needs_two_characters(): void {
		$response = $this->ajax(
			'menunest_import_search',
			array(
				'nonce' => wp_create_nonce( 'menunest_import' ),
				'q'     => 'C',
			)
		);
		$this->assertSame( array(), $response['data'] );
	}

	public function test_back_up_now_saves_a_backup(): void {
		$menu_id = $this->menu_with_items( 2 );

		$response = $this->ajax(
			'menunest_backup_create',
			array(
				'nonce' => wp_create_nonce( 'menunest_editor' ),
				'menu'  => (string) $menu_id,
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, MenuNest_Backups::query( $menu_id ) );
	}

	public function test_accepting_the_notice_is_remembered(): void {
		$this->ajax( 'menunest_save_consent', array( 'nonce' => wp_create_nonce( 'menunest_editor' ) ) );
		$this->assertNotEmpty( get_user_meta( self::$admin_id, MenuNest_Editor::CONSENT_META, true ) );
	}

	public function test_not_now_is_remembered_and_does_not_count_as_consent(): void {
		$response = $this->ajax( 'menunest_dismiss_notice', array( 'nonce' => wp_create_nonce( 'menunest_editor' ) ) );

		$this->assertTrue( $response['success'] );
		$this->assertNotEmpty( get_user_meta( self::$admin_id, MenuNest_Editor::DISMISS_META, true ) );
		$this->assertSame( '', get_user_meta( self::$admin_id, MenuNest_Editor::CONSENT_META, true ) );
	}
}
