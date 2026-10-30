<?php
/**
 * Tests for reading and checking import files, and the import preview.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * @covers MenuNest_Importer
 */
class MenuNest_Importer_Test extends MenuNest_TestCase {

	/**
	 * A valid export as a JSON string.
	 *
	 * @param array<string, mixed> $changes Top-level values to replace.
	 * @return string
	 */
	private function export_json( array $changes = array() ): string {
		$menu = $this->make_menu();
		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $data );
		return (string) wp_json_encode( array_merge( $data, $changes ) );
	}

	/**
	 * A minimal file with one item.
	 *
	 * @param array<string, mixed> $item Item fields.
	 * @return string
	 */
	private function one_item_json( array $item ): string {
		return (string) wp_json_encode(
			array(
				'format'      => MenuNest_Serializer::FORMAT,
				'version'     => 1,
				'exported_at' => '2026-10-01T10:00:00+00:00',
				'site_url'    => 'https://old-site.example/',
				'menu'        => array(
					'id'        => 5,
					'name'      => 'Test',
					'locations' => array(),
				),
				'items'       => array( array_merge( array( 'id' => 10 ), $item ) ),
			)
		);
	}

	public function test_uploaded_path_keeps_windows_backslashes(): void {
		$path = 'C:\\xampp\\tmp\\php1A2B.tmp';

		$this->assertSame( $path, MenuNest_Importer::uploaded_path( array( 'tmp_name' => $path ) ) );
		$this->assertSame( '/tmp/phpAbC123', MenuNest_Importer::uploaded_path( array( 'tmp_name' => '/tmp/phpAbC123' ) ) );
		$this->assertSame( '', MenuNest_Importer::uploaded_path( array( 'tmp_name' => array( 'a', 'b' ) ) ) );
		$this->assertSame( '', MenuNest_Importer::uploaded_path( array() ) );
	}

	public function test_valid_export_round_trips(): void {
		$data = MenuNest_Importer::validate( $this->export_json() );

		$this->assertIsArray( $data );
		$this->assertCount( 6, $data['items'] );
		$this->assertSame( home_url( '/' ), $data['site_url'] );
	}

	/**
	 * @dataProvider data_bad_files
	 */
	public function test_bad_files_are_rejected( string $raw, string $code ): void {
		$result = MenuNest_Importer::validate( $raw );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_bad_files(): array {
		return array(
			'not json'          => array( '{oops', 'menunest_bad_json' ),
			'json but a string' => array( '"hello"', 'menunest_bad_json' ),
			'another plugin'    => array( '{"menu":{"name":"x"},"items":[]}', 'menunest_bad_format' ),
			'items not a list'  => array( '{"format":"menunest-menu","menu":{"name":"x"},"items":"x"}', 'menunest_bad_format' ),
			'newer format'      => array( '{"format":"menunest-menu","version":99,"menu":{"name":"x"},"items":[]}', 'menunest_new_format' ),
		);
	}

	public function test_too_many_items_are_rejected(): void {
		$items = array_fill( 0, MenuNest_Importer::MAX_ITEMS + 1, array( 'id' => 1 ) );
		$raw   = (string) wp_json_encode(
			array(
				'format' => MenuNest_Serializer::FORMAT,
				'menu'   => array( 'name' => 'Big' ),
				'items'  => $items,
			)
		);

		$result = MenuNest_Importer::validate( $raw );
		$this->assertWPError( $result );
		$this->assertSame( 'menunest_too_many', $result->get_error_code() );
	}

	public function test_deeply_nested_json_is_rejected(): void {
		$raw = str_repeat( '[', 100 ) . str_repeat( ']', 100 );
		$this->assertWPError( MenuNest_Importer::validate( $raw ) );
	}

	public function test_dangerous_values_are_cleaned(): void {
		$data = MenuNest_Importer::validate(
			$this->one_item_json(
				array(
					'type'        => 'custom',
					'title'       => 'Hi<script>alert(1)</script>',
					'url'         => 'javascript:alert(1)',
					'target'      => '_top',
					'classes'     => array( 'ok', 'bad"class', '<b>' ),
					'description' => '<img src=x onerror=alert(1)>',
					'object'      => '../../etc',
				)
			)
		);

		$this->assertIsArray( $data );
		$item = $data['items'][0];
		$this->assertStringNotContainsString( '<script', $item['title'] );
		$this->assertSame( '', $item['url'], 'javascript: links are removed.' );
		$this->assertSame( '', $item['target'], 'Only _blank is allowed.' );
		$this->assertSame( array( 'ok', 'badclass', 'b' ), $item['classes'] );
		$this->assertStringNotContainsString( 'onerror', $item['description'] );
		$this->assertSame( 'etc', $item['object'] );
	}

	public function test_unknown_item_type_becomes_custom(): void {
		$data = MenuNest_Importer::validate( $this->one_item_json( array( 'type' => 'evil' ) ) );
		$this->assertIsArray( $data );
		$this->assertSame( 'custom', $data['items'][0]['type'] );
	}

	public function test_items_without_id_are_dropped(): void {
		$raw  = (string) wp_json_encode(
			array(
				'format' => MenuNest_Serializer::FORMAT,
				'menu'   => array( 'name' => 'x' ),
				'items'  => array( array( 'title' => 'No ID' ), 'not an array', array( 'id' => 3 ) ),
			)
		);
		$data = MenuNest_Importer::validate( $raw );

		$this->assertIsArray( $data );
		$this->assertCount( 1, $data['items'] );
	}

	public function test_child_listed_before_its_parent_moves_to_top_level(): void {
		$raw  = (string) wp_json_encode(
			array(
				'format' => MenuNest_Serializer::FORMAT,
				'menu'   => array( 'name' => 'x' ),
				'items'  => array(
					array(
						'id'     => 2,
						'parent' => 1,
					),
					array( 'id' => 1 ),
					array(
						'id'     => 3,
						'parent' => 1,
					),
				),
			)
		);
		$data = MenuNest_Importer::validate( $raw );

		$this->assertIsArray( $data );
		$this->assertSame( array( 0, 0, 1 ), array_column( $data['items'], 'parent' ) );
	}

	public function test_core_meta_in_file_is_ignored_and_nesting_is_limited(): void {
		$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => 'too deep' ) ) ) ) ) ) );
		$data = MenuNest_Importer::validate(
			$this->one_item_json(
				array(
					'meta' => array(
						'_menu_item_url' => array( 'https://evil.example' ),
						'_theme_icon'    => array( 'star' ),
						'deep'           => array( $deep ),
						'html'           => array( '<b>ok</b><script>x</script>' ),
					),
				)
			)
		);

		$this->assertIsArray( $data );
		$meta = $data['items'][0]['meta'];
		$this->assertArrayNotHasKey( '_menu_item_url', $meta );
		$this->assertSame( array( 'star' ), $meta['_theme_icon'] );
		$this->assertSame( array(), $meta['deep'][0]['a']['b']['c']['d']['e']['f'] );
		$this->assertSame( '<b>ok</b>x', $meta['html'][0] );
	}

	public function test_missing_menu_name_gets_a_default(): void {
		$raw  = (string) wp_json_encode(
			array(
				'format' => MenuNest_Serializer::FORMAT,
				'menu'   => array( 'name' => '<b></b>' ),
				'items'  => array(),
			)
		);
		$data = MenuNest_Importer::validate( $raw );

		$this->assertIsArray( $data );
		$this->assertSame( 'Imported menu', $data['menu']['name'] );
	}

	public function test_preview_from_this_site_matches_everything(): void {
		$data = MenuNest_Importer::validate( $this->export_json() );
		$this->assertIsArray( $data );

		$preview = MenuNest_Importer::build_preview( $data );

		$this->assertTrue( $preview['context']['same_site'] );
		$this->assertSame( 6, $preview['matched'] );
		$this->assertSame( 0, $preview['unmatched'] );
		$this->assertSame( array( 0, 1, 0, 1, 0, 0 ), array_column( $preview['rows'], 'depth' ) );
	}

	public function test_preview_from_another_site_reports_problems(): void {
		$data = MenuNest_Importer::validate( $this->export_json() );
		$this->assertIsArray( $data );
		$data = MenuNest_Importer::validate( (string) wp_json_encode( $this->as_other_site( $data ) ) );
		$this->assertIsArray( $data );

		$data['items'][2]['slug']   = 'not-here';
		$data['items'][3]['object'] = 'product_cat';
		$data['menu']['locations']  = array( 'primary', 'no-such-location' );

		$preview = MenuNest_Importer::build_preview( $data );

		$this->assertFalse( $preview['context']['same_site'] );
		$this->assertSame( 2, $preview['unmatched'] );
		$this->assertSame( 4, $preview['matched'] );
		$this->assertCount( 1, $preview['missing'] );
		$this->assertStringContainsString( 'product_cat', $preview['missing'][0] );
		$this->assertSame(
			array( true, false ),
			array_column( $preview['locations'], 'available' ),
			'"primary" exists in the theme, "no-such-location" does not.'
		);
		$this->assertSame( MenuNest_Builder::STATUS_REWRITTEN, $preview['rows'][4]['resolved']['status'], 'The contact link now points at this site.' );
	}

	public function test_stored_preview_belongs_to_one_user(): void {
		$data = MenuNest_Importer::validate( $this->export_json() );
		set_transient(
			'menunest_import_abc',
			array(
				'user' => self::$admin_id,
				'json' => wp_json_encode( $data ),
			)
		);

		$this->assertIsArray( MenuNest_Importer::get_upload( 'abc' ) );

		wp_set_current_user( self::$subscriber_id );
		$this->assertNull( MenuNest_Importer::get_upload( 'abc' ), 'Another user cannot read it.' );
	}

	public function test_preview_handler_stores_the_file_and_opens_the_preview(): void {
		$this->add_nonce( 'menunest_import' );
		$_POST['import_text'] = wp_slash( $this->export_json() );

		$location = $this->run_handler( array( MenuNest_Importer::class, 'handle_preview' ) );

		$this->assertStringContainsString( 'step=preview', $location );
		$this->assertSame( 1, preg_match( '/token=([a-z0-9]+)/i', $location, $m ) );
		$this->assertIsArray( MenuNest_Importer::get_upload( strtolower( $m[1] ) ) );
	}

	public function test_preview_handler_reports_bad_files(): void {
		$this->add_nonce( 'menunest_import' );
		$_POST['import_text'] = 'nonsense';

		$location = $this->run_handler( array( MenuNest_Importer::class, 'handle_preview' ) );

		$this->assertStringContainsString( 'menunest_notice=import_error', $location );
		$this->assertSame( 'This is not a valid JSON file.', get_transient( 'menunest_import_error_' . self::$admin_id ) );
	}

	public function test_preview_handler_needs_permission(): void {
		wp_set_current_user( self::$subscriber_id );
		$this->add_nonce( 'menunest_import' );
		$_POST['import_text'] = $this->export_json();

		$this->expectException( WPDieException::class );
		MenuNest_Importer::handle_preview();
	}

	public function test_preview_handler_needs_a_nonce(): void {
		$_POST['import_text'] = $this->export_json();

		$this->expectException( WPDieException::class );
		MenuNest_Importer::handle_preview();
	}

	/**
	 * @dataProvider data_meta_keys
	 */
	public function test_only_safe_setting_keys_can_be_imported( string $key, bool $allowed ): void {
		$this->assertSame( $allowed, MenuNest_Importer::is_importable_meta_key( $key ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function data_meta_keys(): array {
		return array(
			'theme icon'         => array( '_theme_icon', true ),
			'mega menu'          => array( 'mega_menu-settings.v2', true ),
			'core item field'    => array( '_menu_item_url', false ),
			'wordpress internal' => array( '_wp_page_template', false ),
			'edit lock'          => array( '_edit_lock', false ),
			'html in key'        => array( '<script>', false ),
			'space in key'       => array( 'my key', false ),
			'empty'              => array( '', false ),
			'too long'           => array( str_repeat( 'a', 192 ), false ),
		);
	}

	public function test_developers_can_block_more_keys(): void {
		add_filter(
			'menunest_import_meta_key',
			static function ( bool $allowed, string $key ): bool {
				return $allowed && 'secret' !== $key;
			},
			10,
			2
		);
		$this->assertFalse( MenuNest_Importer::is_importable_meta_key( 'secret' ) );
		$this->assertTrue( MenuNest_Importer::is_importable_meta_key( 'public' ) );
	}

	public function test_preview_link_carries_a_nonce_that_the_screen_checks(): void {
		$this->add_nonce( 'menunest_import' );
		$_POST['import_text'] = wp_slash( $this->export_json() );
		$location             = $this->run_handler( array( MenuNest_Importer::class, 'handle_preview' ) );
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( '_wpnonce', $query );

		$_GET = array(
			'tab'      => 'transfer',
			'step'     => 'preview',
			'token'    => $query['token'],
			'_wpnonce' => $query['_wpnonce'],
		);
		ob_start();
		MenuNest_Admin::render();
		$this->assertStringContainsString( 'menunest-import-form', (string) ob_get_clean(), 'A valid link opens the preview.' );

		$_GET['_wpnonce'] = 'forged';
		ob_start();
		MenuNest_Admin::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'menunest-import-form', $html, 'A forged link does not.' );
		$this->assertStringContainsString( 'expired', $html );
	}
}
