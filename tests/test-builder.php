<?php
/**
 * Tests for matching exported items to content and creating menu items.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * @covers MenuNest_Builder
 */
class MenuNest_Builder_Test extends MenuNest_TestCase {

	/**
	 * Export a fresh test menu.
	 *
	 * @return array{menu: array{menu_id: int, items: array<string, int>, posts: array<string, int>, terms: array<string, int>}, data: array<string, mixed>}
	 */
	private function exported(): array {
		$menu = $this->make_menu();
		$data = MenuNest_Serializer::export_menu( $menu['menu_id'] );
		$this->assertIsArray( $data );
		return array(
			'menu' => $menu,
			'data' => $data,
		);
	}

	/**
	 * Context for data from this site.
	 *
	 * @return array{same_site: bool, source_url: string}
	 */
	private function same_site(): array {
		return array(
			'same_site'  => true,
			'source_url' => home_url( '/' ),
		);
	}

	/**
	 * Context for data from another site.
	 *
	 * @return array{same_site: bool, source_url: string}
	 */
	private function other_site(): array {
		return array(
			'same_site'  => false,
			'source_url' => 'https://old-site.example/',
		);
	}

	public function test_same_site_matches_by_id(): void {
		$set   = $this->exported();
		$about = $set['data']['items'][0];

		$result = MenuNest_Builder::resolve( $about, $this->same_site() );

		$this->assertSame( MenuNest_Builder::STATUS_MATCHED, $result['status'] );
		$this->assertSame( $set['menu']['posts']['about'], $result['args']['menu-item-object-id'] );
		$this->assertSame( 'Page: About Us', $result['target'] );
	}

	public function test_other_site_matches_child_page_by_full_path(): void {
		$set  = $this->exported();
		$team = $set['data']['items'][1];

		// Another page called "team" at the top level must not be picked.
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'team',
			)
		);
		$team['object_id'] = 999999;

		$result = MenuNest_Builder::resolve( $team, $this->other_site() );

		$this->assertSame( MenuNest_Builder::STATUS_MATCHED, $result['status'] );
		$this->assertSame( $set['menu']['posts']['team'], $result['args']['menu-item-object-id'] );
	}

	public function test_other_site_matches_term_by_slug(): void {
		$set               = $this->exported();
		$soft              = $set['data']['items'][3];
		$soft['object_id'] = 999999;

		$result = MenuNest_Builder::resolve( $soft, $this->other_site() );

		$this->assertSame( MenuNest_Builder::STATUS_MATCHED, $result['status'] );
		$this->assertSame( $set['menu']['terms']['soft'], $result['args']['menu-item-object-id'] );
		$this->assertSame( 'Category: Soft Corals', $result['target'] );
	}

	public function test_other_site_ignores_stored_ids(): void {
		$set           = $this->exported();
		$about         = $set['data']['items'][0];
		$about['slug'] = 'does-not-exist';
		$about['path'] = 'does-not-exist';

		$result = MenuNest_Builder::resolve( $about, $this->other_site() );

		$this->assertSame( MenuNest_Builder::STATUS_CONVERTED, $result['status'], 'An ID from another site means nothing here.' );
	}

	public function test_missing_page_becomes_custom_link_with_warning(): void {
		$set                = $this->exported();
		$about              = $set['data']['items'][0];
		$about['url']       = 'https://old-site.example/about-us/';
		$about['slug']      = 'gone';
		$about['path']      = 'gone';
		$about['object_id'] = 999999;

		$result = MenuNest_Builder::resolve( $about, $this->other_site() );

		$this->assertSame( MenuNest_Builder::STATUS_CONVERTED, $result['status'] );
		$this->assertSame( 'custom', $result['args']['menu-item-type'] );
		$this->assertSame( home_url( '/about-us/' ), $result['args']['menu-item-url'], 'The link points at this site now.' );
		$this->assertStringContainsString( 'gone', $result['warning'] );
	}

	public function test_missing_content_type_is_explained(): void {
		$set            = $this->exported();
		$item           = $set['data']['items'][0];
		$item['object'] = 'product';

		$result = MenuNest_Builder::resolve( $item, $this->other_site() );

		$this->assertSame( MenuNest_Builder::STATUS_CONVERTED, $result['status'] );
		$this->assertStringContainsString( '"product" does not exist', $result['warning'] );
	}

	public function test_missing_taxonomy_is_explained(): void {
		$set            = $this->exported();
		$item           = $set['data']['items'][2];
		$item['object'] = 'product_cat';

		$result = MenuNest_Builder::resolve( $item, $this->other_site() );

		$this->assertSame( MenuNest_Builder::STATUS_CONVERTED, $result['status'] );
		$this->assertStringContainsString( '"product_cat" does not exist', $result['warning'] );
	}

	/**
	 * @dataProvider data_urls
	 */
	public function test_normalise_url( string $url, string $expected_path_or_url, bool $is_local ): void {
		$result   = MenuNest_Builder::normalise_url( $url, $this->other_site() );
		$expected = $is_local ? home_url( $expected_path_or_url ) : $expected_path_or_url;
		if ( $is_local && '/' !== substr( $expected_path_or_url, -1 ) && '/' !== $expected_path_or_url ) {
			$expected = untrailingslashit( $expected );
		}
		$this->assertSame( $expected, $result );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function data_urls(): array {
		return array(
			'old site page'       => array( 'https://old-site.example/shop/', '/shop/', true ),
			'old site, http, www' => array( 'http://www.old-site.example/shop/', '/shop/', true ),
			'old site, no slash'  => array( 'https://old-site.example/shop', '/shop', true ),
			'old site with query' => array( 'https://old-site.example/?s=coral', '/?s=coral', true ),
			'old site home'       => array( 'https://old-site.example/', '/', true ),
			'external site'       => array( 'https://example.net/page/', 'https://example.net/page/', false ),
			'look-alike domain'   => array( 'https://old-site.example.evil.com/x', 'https://old-site.example.evil.com/x', false ),
			'hash link'           => array( '#', '#', false ),
			'mail link'           => array( 'mailto:a@b.co', 'mailto:a@b.co', false ),
			'empty'               => array( '', '', false ),
		);
	}

	public function test_same_site_urls_are_never_changed(): void {
		$this->assertSame( 'https://old-site.example/a/', MenuNest_Builder::normalise_url( 'https://old-site.example/a/', $this->same_site() ) );
	}

	public function test_custom_link_reports_rewritten_or_kept(): void {
		$set     = $this->exported();
		$contact = $set['data']['items'][4];

		$contact['url'] = 'https://old-site.example/contact/';
		$this->assertSame( MenuNest_Builder::STATUS_REWRITTEN, MenuNest_Builder::resolve( $contact, $this->other_site() )['status'] );

		$contact['url'] = 'https://example.net/';
		$this->assertSame( MenuNest_Builder::STATUS_CUSTOM, MenuNest_Builder::resolve( $contact, $this->other_site() )['status'] );
	}

	public function test_override_to_custom_link(): void {
		$set   = $this->exported();
		$about = $set['data']['items'][0];

		$result = MenuNest_Builder::resolve( $about, array_merge( $this->same_site(), array( 'override' => 'custom' ) ) );

		$this->assertSame( 'custom', $result['args']['menu-item-type'] );
		$this->assertSame( $about['url'], $result['args']['menu-item-url'] );
	}

	public function test_override_to_other_post_and_term(): void {
		$set   = $this->exported();
		$about = $set['data']['items'][0];
		$other = self::factory()->post->create( array( 'post_title' => 'Blog post' ) );

		$to_post = MenuNest_Builder::resolve( $about, array_merge( $this->same_site(), array( 'override' => 'post:' . $other ) ) );
		$this->assertSame( 'post', $to_post['args']['menu-item-object'] );
		$this->assertSame( $other, $to_post['args']['menu-item-object-id'] );

		$to_term = MenuNest_Builder::resolve( $about, array_merge( $this->same_site(), array( 'override' => 'term:' . $set['menu']['terms']['corals'] ) ) );
		$this->assertSame( 'taxonomy', $to_term['args']['menu-item-type'] );
		$this->assertSame( 'category', $to_term['args']['menu-item-object'] );
	}

	public function test_manual_choice_keeps_the_item_label(): void {
		$set  = $this->exported();
		$soft = $set['data']['items'][3];

		$result = MenuNest_Builder::resolve( $soft, array_merge( $this->other_site(), array( 'override' => 'term:' . $set['menu']['terms']['corals'] ) ) );

		$this->assertSame( $set['menu']['terms']['corals'], $result['args']['menu-item-object-id'] );
		$this->assertSame( 'Soft Corals', $result['args']['menu-item-title'], 'The menu still says "Soft Corals".' );
	}

	public function test_automatic_match_keeps_following_the_content_title(): void {
		$set    = $this->exported();
		$result = MenuNest_Builder::resolve( $set['data']['items'][0], $this->same_site() );
		$this->assertSame( '', $result['args']['menu-item-title'], 'Renaming the page later still renames the menu item.' );
	}

	/**
	 * @dataProvider data_invalid_overrides
	 */
	public function test_invalid_override_falls_back_to_automatic( string $override ): void {
		$set   = $this->exported();
		$about = $set['data']['items'][0];

		$result = MenuNest_Builder::resolve( $about, array_merge( $this->same_site(), array( 'override' => $override ) ) );

		$this->assertSame( MenuNest_Builder::STATUS_MATCHED, $result['status'] );
		$this->assertSame( $set['menu']['posts']['about'], $result['args']['menu-item-object-id'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function data_invalid_overrides(): array {
		return array(
			'missing post' => array( 'post:999999' ),
			'missing term' => array( 'term:999999' ),
			'unknown kind' => array( 'user:1' ),
			'garbage'      => array( 'nonsense' ),
		);
	}

	public function test_override_cannot_link_to_private_post_types(): void {
		$set    = $this->exported();
		$about  = $set['data']['items'][0];
		$hidden = self::factory()->post->create( array( 'post_type' => MenuNest_Backups::POST_TYPE ) );

		$result = MenuNest_Builder::resolve( $about, array_merge( $this->same_site(), array( 'override' => 'post:' . $hidden ) ) );

		$this->assertSame( 'page', $result['args']['menu-item-object'], 'Backups are not viewable, so the override is ignored.' );
	}

	public function test_build_all_recreates_structure_and_settings(): void {
		$set = $this->exported();
		add_post_meta( $set['menu']['items']['about'], '_theme_icon', 'star' );
		$data = MenuNest_Serializer::export_menu( $set['menu']['menu_id'] );
		$this->assertIsArray( $data );

		$new_id = (int) wp_create_nav_menu( 'Copy' );
		$stats  = MenuNest_Builder::build_all( $new_id, $data['items'], $this->same_site() );

		$this->assertSame(
			array(
				'created'   => 6,
				'failed'    => 0,
				'converted' => 0,
			),
			$stats
		);
		$this->assertSame( $this->outline( $set['menu']['menu_id'] ), $this->outline( $new_id ) );

		$copied = wp_get_nav_menu_items( $new_id );
		$this->assertIsArray( $copied );
		$this->assertSame( 'star', get_post_meta( $copied[0]->ID, '_theme_icon', true ) );
		$this->assertSame( array( 'cta', 'highlight' ), array_values( array_filter( $copied[4]->classes ) ) );
		$this->assertSame( '_blank', $copied[4]->target );
	}

	public function test_build_all_can_skip_extra_settings(): void {
		$set = $this->exported();
		add_post_meta( $set['menu']['items']['about'], '_theme_icon', 'star' );
		$data = MenuNest_Serializer::export_menu( $set['menu']['menu_id'] );
		$this->assertIsArray( $data );

		$new_id = (int) wp_create_nav_menu( 'Copy' );
		MenuNest_Builder::build_all( $new_id, $data['items'], $this->same_site(), false );

		$copied = wp_get_nav_menu_items( $new_id );
		$this->assertIsArray( $copied );
		$this->assertSame( '', get_post_meta( $copied[0]->ID, '_theme_icon', true ) );
	}

	public function test_converted_item_gets_a_visible_title(): void {
		$set           = $this->exported();
		$about         = $set['data']['items'][0];
		$about['slug'] = 'gone';
		$about['path'] = 'gone';

		$new_id = (int) wp_create_nav_menu( 'Copy' );
		$map    = array();
		$result = MenuNest_Builder::resolve( $about, $this->other_site() );
		$id     = MenuNest_Builder::create_item( $new_id, $about, $result, $map, false );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'About Us', get_post( $id )->post_title, 'Uses the old label instead of an empty title.' );
	}

	public function test_child_of_missing_parent_goes_to_top_level(): void {
		$set  = $this->exported();
		$team = $set['data']['items'][1];

		$new_id = (int) wp_create_nav_menu( 'Copy' );
		$map    = array();
		$id     = MenuNest_Builder::create_item( $new_id, $team, MenuNest_Builder::resolve( $team, $this->same_site() ), $map, false );

		$this->assertSame( '0', get_post_meta( $id, '_menu_item_menu_item_parent', true ) );
	}

	public function test_empty_menu_removes_saved_and_draft_items(): void {
		$set = $this->exported();
		wp_update_nav_menu_item(
			$set['menu']['menu_id'],
			0,
			array(
				'menu-item-title'  => 'Draft',
				'menu-item-url'    => '#',
				'menu-item-status' => 'draft',
			)
		);

		MenuNest_Builder::empty_menu( $set['menu']['menu_id'] );

		$this->assertSame( array(), wp_get_nav_menu_items( $set['menu']['menu_id'], array( 'post_status' => 'any' ) ) );
	}

	public function test_unique_menu_name(): void {
		wp_create_nav_menu( 'Shop' );
		wp_create_nav_menu( 'Shop 2' );

		$this->assertSame( 'Blog', MenuNest_Builder::unique_menu_name( 'Blog' ) );
		$this->assertSame( 'Shop 3', MenuNest_Builder::unique_menu_name( 'Shop' ) );
	}
}
