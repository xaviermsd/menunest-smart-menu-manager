<?php
/**
 * Tests for reading a menu into portable data.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * @covers MenuNest_Serializer
 */
class MenuNest_Serializer_Test extends MenuNest_TestCase {

	public function test_missing_menu_returns_error(): void {
		$this->assertWPError( MenuNest_Serializer::export_menu( 999999 ) );
	}

	public function test_export_has_format_site_and_menu_details(): void {
		$menu = $this->make_menu();
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu['menu_id'] ) );

		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );

		$this->assertIsArray( $data );
		$this->assertSame( MenuNest_Serializer::FORMAT, $data['format'] );
		$this->assertSame( MenuNest_Serializer::VERSION, $data['version'] );
		$this->assertSame( home_url( '/' ), $data['site_url'] );
		$this->assertSame( $menu['menu_id'], $data['menu']['id'] );
		$this->assertSame( array( 'primary' ), $data['menu']['locations'] );
		$this->assertCount( 6, $data['items'] );
	}

	public function test_items_keep_order_parents_slugs_and_paths(): void {
		$menu = $this->make_menu();
		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $data );
		$items = array_column( $data['items'], null, 'id' );

		$this->assertSame( array_values( $menu['items'] ), array_column( $data['items'], 'id' ), 'Items are in menu order.' );

		$team = $items[ $menu['items']['team'] ];
		$this->assertSame( $menu['items']['about'], $team['parent'] );
		$this->assertSame( 'team', $team['slug'] );
		$this->assertSame( 'about-us/team', $team['path'], 'Child pages carry their full path.' );

		$soft = $items[ $menu['items']['soft'] ];
		$this->assertSame( 'taxonomy', $soft['type'] );
		$this->assertSame( 'category', $soft['object'] );
		$this->assertSame( 'soft-corals', $soft['slug'] );

		$contact = $items[ $menu['items']['contact'] ];
		$this->assertSame( 'custom', $contact['type'] );
		$this->assertSame( home_url( '/contact/' ), $contact['url'] );
		$this->assertSame( array( 'cta', 'highlight' ), $contact['classes'] );
		$this->assertSame( '_blank', $contact['target'] );
	}

	public function test_title_stays_empty_when_item_follows_content_title(): void {
		$menu = $this->make_menu();
		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $data );

		$about = $data['items'][0];
		$this->assertSame( '', $about['title'], 'Empty title means "use the page title".' );
		$this->assertSame( 'About Us', $about['label'] );
	}

	public function test_extra_settings_are_exported_but_core_meta_is_not(): void {
		$menu = $this->make_menu();
		add_post_meta( $menu['items']['about'], '_theme_icon', 'star' );
		add_post_meta( $menu['items']['about'], 'mega_menu', array( 'columns' => 3 ) );

		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $data );
		$meta = $data['items'][0]['meta'];

		$this->assertSame( array( 'star' ), $meta['_theme_icon'] );
		$this->assertSame( array( array( 'columns' => 3 ) ), $meta['mega_menu'], 'Arrays come back as arrays, not serialized strings.' );
		foreach ( array_keys( $meta ) as $key ) {
			$this->assertStringStartsNotWith( '_menu_item_', $key );
		}
	}

	public function test_extra_settings_can_be_left_out(): void {
		$menu = $this->make_menu();
		add_post_meta( $menu['items']['about'], '_theme_icon', 'star' );

		$data = MenuNest_Serializer::export_menu( $menu['menu_id'], false );
		$this->assertIsArray( $data );
		$this->assertSame( array(), $data['items'][0]['meta'] );
	}

	public function test_drafts_are_not_exported(): void {
		$menu = $this->make_menu();
		wp_update_nav_menu_item(
			$menu['menu_id'],
			0,
			array(
				'menu-item-title'  => 'Unsaved',
				'menu-item-url'    => '#',
				'menu-item-status' => 'draft',
			)
		);

		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $data );
		$this->assertNotContains( 'Unsaved', array_column( $data['items'], 'title' ) );
	}

	public function test_fingerprint_changes_only_when_the_menu_changes(): void {
		$menu   = $this->make_menu();
		$first  = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$second = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );

		$this->assertSame( MenuNest_Serializer::fingerprint( $first ), MenuNest_Serializer::fingerprint( $second ), 'Export time does not affect it.' );

		$this->add_item(
			$menu['menu_id'],
			array(
				'menu-item-title' => 'New',
				'menu-item-url'   => '#',
			)
		);
		$third = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $third );
		$this->assertNotSame( MenuNest_Serializer::fingerprint( $first ), MenuNest_Serializer::fingerprint( $third ) );
	}

	/**
	 * @dataProvider data_core_meta
	 */
	public function test_is_core_meta( string $key, bool $expected ): void {
		$this->assertSame( $expected, MenuNest_Serializer::is_core_meta( $key ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function data_core_meta(): array {
		return array(
			'item type'      => array( '_menu_item_type', true ),
			'item url'       => array( '_menu_item_url', true ),
			'edit lock'      => array( '_edit_lock', true ),
			'theme icon'     => array( '_theme_icon', false ),
			'mega menu'      => array( 'mega_menu', false ),
			'similar prefix' => array( 'menu_item_extra', false ),
		);
	}
}
