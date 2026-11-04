<?php
/**
 * Tests for the CoreAbilitiesPolyfill class.
 *
 * The suite runs against WordPress versions with and without the 7.2 core abilities
 * (see the CI matrix), so tests either hold on both paths or branch on feature
 * detection, never on a version number.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Compat;

use WP\MCP\Compat\CoreAbilitiesPolyfill;
use WP\MCP\Tests\TestCase;
use WP_Ability;

/**
 * Test CoreAbilitiesPolyfill functionality.
 */
final class CoreAbilitiesPolyfillTest extends TestCase {

	private const CORE_ABILITIES = array(
		'core/settings-get',
		'core/users-query',
		'core/content-query',
	);

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private int $subscriber_id;

	public function setUp(): void {
		parent::setUp();

		self::ensure_core_abilities_registered();

		$this->admin_id      = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Makes the core abilities available in the test registry on every WordPress version.
	 *
	 * The WordPress test framework unhooks core's own category and ability registration,
	 * so on WordPress versions that ship the abilities (7.2+) nothing registers them during
	 * tests — and the polyfill is correctly unhooked at plugin load there. Calling the
	 * polyfill's registrars directly fills the gap; every one of them is guarded, so where
	 * the polyfill (or anything else) already registered a piece this is a no-op. On 7.2+
	 * this loads the plugin's prefixed copies next to core's identically-shaped classes —
	 * exactly the coexistence the `WP_MCP_` prefix exists to allow.
	 */
	private static function ensure_core_abilities_registered(): void {
		global $wp_current_filter;

		// Spoof the one-time registration hooks, which fired before this test ran.
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		CoreAbilitiesPolyfill::register_categories();
		array_pop( $wp_current_filter );

		$wp_current_filter[] = 'wp_abilities_api_init';
		CoreAbilitiesPolyfill::register_core_abilities();
		array_pop( $wp_current_filter );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Whether the running WordPress supports `required_capabilities` natively (7.2+).
	 */
	private static function wordpress_supports_required_capabilities(): bool {
		return property_exists( WP_Ability::class, 'required_capabilities' );
	}

	/**
	 * On every supported WordPress version the three core abilities exist and execute.
	 *
	 * In production they come from the polyfill's copies before 7.2 and from core's own
	 * classes on 7.2+; the test framework strips core's registration, so here setUp()
	 * registers them back through the polyfill's copies on every version.
	 */
	public function test_core_abilities_are_available_on_every_wordpress(): void {
		foreach ( self::CORE_ABILITIES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "Ability {$name} is not registered." );
		}
	}

	public function test_core_ability_categories_are_available(): void {
		foreach ( array( 'site', 'user', 'content', 'uncategorized' ) as $category ) {
			$this->assertTrue( wp_has_ability_category( $category ), "Category {$category} is not registered." );
		}
	}

	/**
	 * The guard inside each copy's register() makes re-registration a no-op.
	 *
	 * This is what keeps a partially-shipped WordPress safe: a copy loaded because one of
	 * its abilities is missing re-registers nothing that core (or an earlier run) already
	 * provides — no `_doing_it_wrong`, no overwrite.
	 */
	public function test_register_guards_skip_abilities_that_already_exist(): void {
		global $wp_current_filter;

		$this->assertTrue( wp_has_ability( 'core/users-query' ) );

		$wp_current_filter[] = 'wp_abilities_api_init';
		( new \WP_MCP_Abilities_Users() )->register();
		array_pop( $wp_current_filter );

		$this->assertTrue( wp_has_ability( 'core/users-query' ) );
	}

	/**
	 * The registrar reloads a copy only while one of its abilities is missing, and the
	 * other copies — whose abilities are all registered — are skipped entirely.
	 */
	public function test_registrar_fills_only_missing_abilities(): void {
		global $wp_current_filter;

		wp_unregister_ability( 'core/users-query' );
		$this->assertFalse( wp_has_ability( 'core/users-query' ) );

		$wp_current_filter[] = 'wp_abilities_api_init';
		CoreAbilitiesPolyfill::register_core_abilities();
		array_pop( $wp_current_filter );

		foreach ( self::CORE_ABILITIES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "Ability {$name} is not registered." );
		}
	}

	public function test_settings_get_exposes_the_same_settings_as_core(): void {
		$ability = wp_get_ability( 'core/settings-get' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertIsArray( $schema );
		$exposed_names = $schema['properties']['fields']['items']['enum'];

		foreach ( array( 'title', 'description', 'posts_per_page', 'page_for_privacy_policy', 'default_comment_status' ) as $name ) {
			$this->assertContains( $name, $exposed_names );
		}
	}

	public function test_settings_get_denies_anonymous_users(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'core/settings-get' );
		$this->assertNotNull( $ability );
		$this->assertWPError( $ability->execute( array() ) );
	}

	public function test_settings_get_denies_users_without_manage_options(): void {
		wp_set_current_user( $this->subscriber_id );

		$ability = wp_get_ability( 'core/settings-get' );
		$this->assertNotNull( $ability );
		$this->assertWPError( $ability->execute( array() ) );
	}

	public function test_settings_get_returns_settings_for_administrators(): void {
		wp_set_current_user( $this->admin_id );

		$ability = wp_get_ability( 'core/settings-get' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array() );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'posts_per_page', $result );
	}

	public function test_users_query_executes(): void {
		wp_set_current_user( $this->admin_id );

		$ability = wp_get_ability( 'core/users-query' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array() );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
	}

	public function test_content_query_executes(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( $this->admin_id );

		$ability = wp_get_ability( 'core/content-query' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( array( 'where' => array( 'type' => 'post' ) ) );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
	}

	public function test_mapper_is_a_noop_when_wordpress_supports_required_capabilities(): void {
		if ( ! self::wordpress_supports_required_capabilities() ) {
			$this->markTestSkipped( 'This WordPress does not support required_capabilities.' );
		}

		$args = array(
			'label'                 => 'Test',
			'required_capabilities' => array( 'manage_options' ),
		);

		$this->assertSame( $args, CoreAbilitiesPolyfill::map_required_capabilities( $args, 'core/test' ) );
	}

	public function test_mapper_converts_required_capabilities_to_a_permission_callback(): void {
		if ( self::wordpress_supports_required_capabilities() ) {
			$this->markTestSkipped( 'This WordPress supports required_capabilities natively.' );
		}

		$args = CoreAbilitiesPolyfill::map_required_capabilities(
			array( 'required_capabilities' => array( 'manage_options' ) ),
			'core/test'
		);

		$this->assertArrayNotHasKey( 'required_capabilities', $args );
		$this->assertIsCallable( $args['permission_callback'] );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( $args['permission_callback']() );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( $args['permission_callback']() );
	}

	public function test_mapper_composes_with_an_existing_permission_callback(): void {
		if ( self::wordpress_supports_required_capabilities() ) {
			$this->markTestSkipped( 'This WordPress supports required_capabilities natively.' );
		}

		$args = CoreAbilitiesPolyfill::map_required_capabilities(
			array(
				'required_capabilities' => array( 'read' ),
				'permission_callback'   => static function ( $input = null ): bool {
					return 'yes' === $input;
				},
			),
			'core/test'
		);

		// The callback runs only after every capability passes.
		wp_set_current_user( 0 );
		$this->assertFalse( $args['permission_callback']( 'yes' ) );

		wp_set_current_user( $this->subscriber_id );
		$this->assertTrue( $args['permission_callback']( 'yes' ) );
		$this->assertFalse( $args['permission_callback']( 'no' ) );
	}

	public function test_mapper_drops_a_malformed_capabilities_argument(): void {
		if ( self::wordpress_supports_required_capabilities() ) {
			$this->markTestSkipped( 'This WordPress supports required_capabilities natively.' );
		}

		foreach ( array( 'manage_options', array() ) as $invalid ) {
			$args = CoreAbilitiesPolyfill::map_required_capabilities(
				array( 'required_capabilities' => $invalid ),
				'core/test'
			);

			// The unknown argument is removed, and no permission callback is synthesized from it.
			$this->assertSame( array(), $args );
		}
	}

	public function test_mapper_ignores_abilities_outside_the_core_namespace(): void {
		$args = array(
			'label'                 => 'Test',
			'required_capabilities' => array( 'manage_options' ),
		);

		$this->assertSame( $args, CoreAbilitiesPolyfill::map_required_capabilities( $args, 'my-plugin/test' ) );
	}

	public function test_post_type_flag_is_added_for_core_types_only(): void {
		$this->assertTrue( CoreAbilitiesPolyfill::add_post_type_arg( array(), 'post' )['show_in_abilities'] );
		$this->assertTrue( CoreAbilitiesPolyfill::add_post_type_arg( array(), 'page' )['show_in_abilities'] );
		$this->assertArrayNotHasKey( 'show_in_abilities', CoreAbilitiesPolyfill::add_post_type_arg( array(), 'book' ) );
	}

	public function test_post_type_flag_respects_an_explicit_value(): void {
		$args = array( 'show_in_abilities' => false );

		$this->assertFalse( CoreAbilitiesPolyfill::add_post_type_arg( $args, 'post' )['show_in_abilities'] );
	}

	public function test_setting_flag_is_added_for_mapped_settings_only(): void {
		$this->assertTrue( CoreAbilitiesPolyfill::add_setting_arg( array(), array(), 'general', 'blogname' )['show_in_abilities'] );
		$this->assertTrue( CoreAbilitiesPolyfill::add_setting_arg( array(), array(), 'reading', 'wp_page_for_privacy_policy' )['show_in_abilities'] );

		// The same option in another group is not a core initial setting.
		$this->assertArrayNotHasKey( 'show_in_abilities', CoreAbilitiesPolyfill::add_setting_arg( array(), array(), 'writing', 'blogname' ) );
		$this->assertArrayNotHasKey( 'show_in_abilities', CoreAbilitiesPolyfill::add_setting_arg( array(), array(), 'general', 'unmapped_option' ) );
	}

	public function test_setting_flag_respects_an_explicit_value(): void {
		$args = array( 'show_in_abilities' => false );

		$this->assertFalse( CoreAbilitiesPolyfill::add_setting_arg( $args, array(), 'general', 'blogname' )['show_in_abilities'] );
	}

	public function test_kill_switch_disables_every_shim(): void {
		add_filter( 'mcp_adapter_register_core_abilities', '__return_false' );

		$this->assertSame( array(), CoreAbilitiesPolyfill::add_post_type_arg( array(), 'post' ) );
		$this->assertSame( array(), CoreAbilitiesPolyfill::add_setting_arg( array(), array(), 'general', 'blogname' ) );

		$args = array( 'required_capabilities' => array( 'manage_options' ) );
		$this->assertSame( $args, CoreAbilitiesPolyfill::map_required_capabilities( $args, 'core/test' ) );

		remove_filter( 'mcp_adapter_register_core_abilities', '__return_false' );
	}

	public function test_kill_switch_disables_every_registrar(): void {
		global $wp_current_filter, $wp_registered_settings;

		$saved_settings = $wp_registered_settings;
		unset( $wp_registered_settings['wp_page_for_privacy_policy'] );

		wp_unregister_ability( 'core/users-query' );
		wp_unregister_ability_category( 'uncategorized' );

		add_filter( 'mcp_adapter_register_core_abilities', '__return_false' );

		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		CoreAbilitiesPolyfill::register_categories();
		array_pop( $wp_current_filter );

		$wp_current_filter[] = 'wp_abilities_api_init';
		CoreAbilitiesPolyfill::register_initial_settings_for_abilities();
		CoreAbilitiesPolyfill::register_core_abilities();
		array_pop( $wp_current_filter );

		$this->assertFalse( wp_has_ability_category( 'uncategorized' ) );
		$this->assertFalse( wp_has_ability( 'core/users-query' ) );
		$this->assertArrayNotHasKey( 'wp_page_for_privacy_policy', get_registered_settings() );

		remove_filter( 'mcp_adapter_register_core_abilities', '__return_false' );

		// With the switch back on, the guarded registrars restore what they manage.
		self::ensure_core_abilities_registered();
		$this->assertTrue( wp_has_ability_category( 'uncategorized' ) );
		$this->assertTrue( wp_has_ability( 'core/users-query' ) );

		$wp_registered_settings = $saved_settings;
	}

	public function test_privacy_policy_setting_is_registered_for_abilities(): void {
		global $new_allowed_options, $wp_registered_settings;

		$saved_registered = $wp_registered_settings;
		$saved_allowed    = $new_allowed_options;

		CoreAbilitiesPolyfill::register_initial_settings_for_abilities();

		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_page_for_privacy_policy', $registered );
		$this->assertNotEmpty( $registered['wp_page_for_privacy_policy']['show_in_abilities'] );
		$this->assertSame( $saved_allowed, $new_allowed_options, 'The allowed options list must be restored.' );

		$wp_registered_settings = $saved_registered;
	}
}
