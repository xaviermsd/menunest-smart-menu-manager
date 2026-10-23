<?php
/**
 * Shared helpers for MenuNest tests.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

/**
 * Thrown instead of redirecting, so tests can check where a handler sends the user.
 */
class MenuNest_Test_Redirect extends Exception {

	/**
	 * Where the handler wanted to go.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Constructor.
	 *
	 * @param string $location Redirect target.
	 */
	public function __construct( string $location ) {
		parent::__construct( 'Redirect to ' . $location );
		$this->location = $location;
	}
}

/**
 * Base test case.
 */
abstract class MenuNest_TestCase extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Create shared users once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Run as an administrator and capture redirects.
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::$admin_id );
		register_nav_menus( array( 'primary' => 'Primary' ) );
		add_filter( 'wp_redirect', array( $this, 'throw_redirect' ) );
	}

	/**
	 * Clean request data.
	 */
	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		remove_filter( 'wp_redirect', array( $this, 'throw_redirect' ) );
		parent::tear_down();
	}

	/**
	 * Turn a redirect into an exception.
	 *
	 * @param string $location Target.
	 * @throws MenuNest_Test_Redirect Always.
	 */
	public function throw_redirect( string $location ): void {
		throw new MenuNest_Test_Redirect( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only exception, never shown.
	}

	/**
	 * Run an admin-post handler and return where it redirected.
	 *
	 * @param callable $handler Handler.
	 * @return string Redirect target.
	 */
	protected function run_handler( callable $handler ): string {
		try {
			$handler();
		} catch ( MenuNest_Test_Redirect $redirect ) {
			return $redirect->location;
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Add a valid nonce for an action to the request.
	 *
	 * @param string $action Nonce action.
	 * @param string $field  Request field.
	 */
	protected function add_nonce( string $action, string $field = '_wpnonce' ): void {
		$nonce              = wp_create_nonce( $action );
		$_GET[ $field ]     = $nonce;
		$_POST[ $field ]    = $nonce;
		$_REQUEST[ $field ] = $nonce;
	}

	/**
	 * A menu that covers every item type:
	 *
	 *   About Us (page)
	 *     Team (child page, under About Us)
	 *   Corals (category)
	 *     Soft Corals (subcategory)
	 *   Contact (custom link to this site)
	 *   Heading (custom link "#")
	 *
	 * @return array{menu_id: int, items: array<string, int>, posts: array<string, int>, terms: array<string, int>}
	 */
	protected function make_menu(): array {
		$about  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About Us',
				'post_name'  => 'about-us',
			)
		);
		$team   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Team',
				'post_name'   => 'team',
				'post_parent' => $about,
			)
		);
		$corals = $this->category( 'Corals', 'corals', 0 );
		$soft   = $this->category( 'Soft Corals', 'soft-corals', $corals );

		$menu_id = (int) wp_create_nav_menu( 'Main Menu ' . wp_generate_password( 6, false ) );
		$items   = array();

		$items['about']   = $this->add_item(
			$menu_id,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $about,
				'menu-item-position'  => 1,
			)
		);
		$items['team']    = $this->add_item(
			$menu_id,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $team,
				'menu-item-parent-id' => $items['about'],
				'menu-item-position'  => 2,
			)
		);
		$items['corals']  = $this->add_item(
			$menu_id,
			array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $corals,
				'menu-item-position'  => 3,
			)
		);
		$items['soft']    = $this->add_item(
			$menu_id,
			array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $soft,
				'menu-item-parent-id' => $items['corals'],
				'menu-item-position'  => 4,
			)
		);
		$items['contact'] = $this->add_item(
			$menu_id,
			array(
				'menu-item-type'     => 'custom',
				'menu-item-title'    => 'Contact',
				'menu-item-url'      => home_url( '/contact/' ),
				'menu-item-classes'  => 'cta highlight',
				'menu-item-target'   => '_blank',
				'menu-item-position' => 5,
			)
		);
		$items['heading'] = $this->add_item(
			$menu_id,
			array(
				'menu-item-type'     => 'custom',
				'menu-item-title'    => 'Heading',
				'menu-item-url'      => '#',
				'menu-item-position' => 6,
			)
		);

		return array(
			'menu_id' => $menu_id,
			'items'   => $items,
			'posts'   => array(
				'about' => $about,
				'team'  => $team,
			),
			'terms'   => array(
				'corals' => $corals,
				'soft'   => $soft,
			),
		);
	}

	/**
	 * Get or create a category.
	 *
	 * @param string $name   Name.
	 * @param string $slug   Slug.
	 * @param int    $parent_id Parent term ID.
	 * @return int
	 */
	protected function category( string $name, string $slug, int $parent_id ): int {
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( $term instanceof WP_Term ) {
			return $term->term_id;
		}
		return self::factory()->category->create(
			array(
				'name'   => $name,
				'slug'   => $slug,
				'parent' => $parent_id,
			)
		);
	}

	/**
	 * Add a published item to a menu.
	 *
	 * @param int                       $menu_id Menu ID.
	 * @param array<string, int|string> $args    Item arguments.
	 * @return int
	 */
	protected function add_item( int $menu_id, array $args ): int {
		$id = wp_update_nav_menu_item( $menu_id, 0, array_merge( array( 'menu-item-status' => 'publish' ), $args ) );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * The structure of a menu as readable lines, for example "  Team -> page".
	 *
	 * @param int $menu_id Menu ID.
	 * @return list<string>
	 */
	protected function outline( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish' ) );
		$items = is_array( $items ) ? $items : array();
		usort(
			$items,
			static function ( WP_Post $a, WP_Post $b ): int {
				return $a->menu_order - $b->menu_order;
			}
		);

		$depth = array();
		$lines = array();
		foreach ( $items as $item ) {
			$parent             = (int) $item->menu_item_parent;
			$depth[ $item->ID ] = $parent && isset( $depth[ $parent ] ) ? $depth[ $parent ] + 1 : 0;
			$lines[]            = str_repeat( '  ', $depth[ $item->ID ] ) . $item->title . ' -> ' . ( 'custom' === $item->type ? $item->url : $item->object );
		}
		return $lines;
	}

	/**
	 * Data as it would look when exported from another site.
	 *
	 * @param array<string, mixed> $data Export data from this site.
	 * @return array<string, mixed>
	 */
	protected function as_other_site( array $data ): array {
		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$json = str_replace( untrailingslashit( home_url() ), 'https://old-site.example', $json );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		return $data;
	}
}
