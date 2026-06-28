<?php
/**
 * SettingsPage unit tests.
 *
 * Covers the security-critical save path (sanitize_option) and the ability
 * discovery used to build the page, both of which back the
 * Settings > MCP Adapter screen added in this PR.
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
 * and that discovery reports each ability with the managed flag the UI relies
 * on. These are the cases that keep a hand-edited or replayed POST from
 * exposing arbitrary or adapter-internal abilities to the default MCP server.
 */
final class SettingsPageTest extends TestCase {

	private const ALPHA   = 'test/settings-alpha';
	private const BETA    = 'test/settings-beta';
	private const MANAGED = 'mcp-adapter/discover-abilities';

	/**
	 * Reset the option and ensure the two fixtures exist before each test.
	 * Parent declares set_up() public, so the override must match visibility.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( AbilityExposureFilter::OPTION );

		if ( ! wp_get_ability( self::ALPHA ) ) {
			$this->register_ability_in_hook( self::ALPHA, $this->fixture_args( 'Settings Alpha', 'Alpha for settings test' ) );
		}
		if ( wp_get_ability( self::BETA ) ) {
			return;
		}

		$this->register_ability_in_hook( self::BETA, $this->fixture_args( 'Settings Beta', 'Beta for settings test' ) );
	}

	/**
	 * Remove the fixtures so each test starts from a known registry.
	 */
	public function tear_down(): void {
		wp_unregister_ability( self::ALPHA );
		wp_unregister_ability( self::BETA );
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
}
