<?php
/**
 * SettingsPage unit tests.
 *
 * Covers the security-critical save path (sanitize_option), the ability
 * discovery used to build the page, and the page rendering and registration,
 * which together back the Settings > MCP Adapter screen added in this PR.
 *
 * @package WP\MCP\Tests\Unit\Admin
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Admin;

use WP\MCP\Admin\AbilityExposureFilter;
use WP\MCP\Admin\SettingsPage;
use WP\MCP\Tests\TestCase;

/**
 * Class - SettingsPageTest
 *
 * Validates that the settings page only ever persists ability names that are
 * actually registered on the site and outside the adapter's managed namespace,
 * that discovery reports each ability with the managed flag the UI relies on,
 * that the page denies access without manage_options, and that it renders the
 * registered abilities as checkboxes bound to the canonical option.
 */
final class SettingsPageTest extends TestCase {

	private const ALPHA   = 'test/settings-alpha';
	private const BETA    = 'test/settings-beta';
	private const MANAGED = 'mcp-adapter/discover-abilities';

	/**
	 * Administrator user id used for the render test.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Create the administrator the render test runs as.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		self::$admin_id = wp_insert_user(
			array(
				'user_login' => 'spt_admin',
				'user_pass'  => 'password',
				'user_email' => 'spt_admin@example.com',
				'role'       => 'administrator',
			)
		);
	}

	/**
	 * Remove the test user.
	 */
	public static function tear_down_after_class(): void {
		if ( self::$admin_id ) {
			wp_delete_user( self::$admin_id );
		}
		parent::tear_down_after_class();
	}

	/**
	 * Reset the option, ensure the two fixtures exist, and default to the
	 * administrator. Parent declares set_up() public, so the override must
	 * match visibility.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( AbilityExposureFilter::OPTION );

		if ( ! wp_get_ability( self::ALPHA ) ) {
			$this->register_ability_in_hook( self::ALPHA, $this->fixture_args( 'Settings Alpha', 'Alpha for settings test' ) );
		}
		if ( ! wp_get_ability( self::BETA ) ) {
			$this->register_ability_in_hook( self::BETA, $this->fixture_args( 'Settings Beta', 'Beta for settings test' ) );
		}

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Restore the logged-out state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Build a minimal, valid ability registration payload.
	 *
	 * @param string $label       Human label.
	 * @param string $description Human description.
	 * @return array<string, mixed>
	 */
	private function fixture_args( string $label, string $description ): array {
		return array(
			'label'               => $label,
			'description'         => $description,
			'category'            => 'test',
			'input_schema'        => array( 'type' => 'object' ),
			'execute_callback'    => static function () {
				return array();
			},
			'permission_callback' => static function () {
				return true;
			},
		);
	}

	public function test_sanitize_returns_empty_array_for_non_array(): void {
		$page = new SettingsPage();
		$this->assertSame( array(), $page->sanitize_option( 'a-string' ) );
		$this->assertSame( array(), $page->sanitize_option( 42 ) );
		$this->assertSame( array(), $page->sanitize_option( null ) );
	}

	public function test_sanitize_keeps_registered_ability(): void {
		$page = new SettingsPage();
		$this->assertSame( array( self::ALPHA ), $page->sanitize_option( array( self::ALPHA ) ) );
	}

	public function test_sanitize_drops_unregistered_ability(): void {
		$page = new SettingsPage();
		$this->assertSame( array(), $page->sanitize_option( array( 'test/never-registered' ) ) );
	}

	public function test_sanitize_drops_managed_namespace_even_when_registered(): void {
		// The managed ability is registered by the parent fixture, but must
		// never be exposable through this page.
		$this->assertNotNull( wp_get_ability( self::MANAGED ) );

		$page = new SettingsPage();
		$this->assertSame( array(), $page->sanitize_option( array( self::MANAGED ) ) );
	}

	public function test_sanitize_drops_empty_and_non_string_entries(): void {
		$page = new SettingsPage();
		$out  = $page->sanitize_option(
			array( '', '   ', 123, array( 'nested' ), self::ALPHA )
		);
		$this->assertSame( array( self::ALPHA ), $out );
	}

	public function test_sanitize_deduplicates(): void {
		$page = new SettingsPage();
		$out  = $page->sanitize_option( array( self::ALPHA, self::ALPHA, self::ALPHA ) );
		$this->assertSame( array( self::ALPHA ), $out );
	}

	public function test_sanitize_keeps_only_valid_entries_in_order(): void {
		$page = new SettingsPage();
		$out  = $page->sanitize_option(
			array( self::BETA, 'test/never-registered', self::MANAGED, self::ALPHA )
		);
		$this->assertSame( array( self::BETA, self::ALPHA ), $out );
	}

	public function test_discover_includes_registered_abilities_keyed_by_name(): void {
		$page      = new SettingsPage();
		$abilities = $page->discover_abilities();

		$this->assertArrayHasKey( self::ALPHA, $abilities );
		$this->assertSame( 'Settings Alpha', $abilities[ self::ALPHA ]['label'] );
		$this->assertSame( 'Alpha for settings test', $abilities[ self::ALPHA ]['description'] );
		$this->assertArrayHasKey( 'managed', $abilities[ self::ALPHA ] );
	}

	public function test_discover_flags_managed_namespace(): void {
		$page      = new SettingsPage();
		$abilities = $page->discover_abilities();

		$this->assertArrayHasKey( self::MANAGED, $abilities );
		$this->assertTrue( $abilities[ self::MANAGED ]['managed'] );
		$this->assertFalse( $abilities[ self::ALPHA ]['managed'] );
	}

	public function test_discover_is_alphabetically_sorted(): void {
		$page = new SettingsPage();
		$keys = array_keys( $page->discover_abilities() );

		$sorted = $keys;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $keys );
	}

	public function test_register_wires_admin_hooks(): void {
		$page = new SettingsPage();
		$page->register();

		$this->assertNotFalse( has_action( 'admin_menu', array( $page, 'register_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $page, 'register_setting' ) ) );
	}

	public function test_register_setting_registers_the_option(): void {
		$page = new SettingsPage();
		$page->register_setting();

		$registered = get_registered_settings();
		$this->assertArrayHasKey( AbilityExposureFilter::OPTION, $registered );
	}

	public function test_render_outputs_checkboxes_bound_to_the_option(): void {
		update_option( AbilityExposureFilter::OPTION, array( self::ALPHA ) );
		$page = new SettingsPage();

		ob_start();
		$page->render_page();
		$html = (string) ob_get_clean();

		// Page chrome + the option-bound checkbox for a real ability.
		$this->assertStringContainsString( 'MCP Adapter', $html );
		$this->assertStringContainsString( esc_attr( AbilityExposureFilter::OPTION ) . '[]', $html );
		$this->assertStringContainsString( self::ALPHA, $html );
		// The opted-in ability renders checked.
		$this->assertStringContainsString( 'checked', $html );
		// The managed ability renders, but as a disabled/managed row.
		$this->assertStringContainsString( self::MANAGED, $html );
		$this->assertStringContainsString( 'managed by the adapter', $html );
	}
}
