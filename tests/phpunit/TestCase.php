<?php
/**
 * Test base class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Set up before each test class to ensure abilities are registered.
	 *
	 * This method registers test fixtures once per test class that extends TestCase.
	 * The fixtures persist for the entire test suite run and are NOT cleaned up
	 * between test classes. See tear_down_after_class() for rationale.
	 *
	 * Registration pattern:
	 * 1. Add hooks for category/ability registration
	 * 2. Fire hooks if not already fired
	 * 3. Abilities registered via hooks persist globally
	 *
	 * This follows Option 2 from our analysis: Global registration with no cleanup,
	 * using DummyAbility methods for centralized test fixture management.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Register plugin's default category and abilities via the same methods
		// the production code uses. We hook them the same way McpAdapter::maybe_create_default_server()
		// does, so if the hooks haven't fired yet they'll be picked up automatically.
		$adapter = McpAdapter::instance();
		add_action( 'wp_abilities_api_categories_init', array( $adapter, 'register_default_category' ) );
		add_action( 'wp_abilities_api_init', array( $adapter, 'register_default_abilities' ) );

		// Use DummyAbility to register test category and abilities.
		add_action( 'wp_abilities_api_categories_init', array( DummyAbility::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( DummyAbility::class, 'register_abilities' ) );
	}

	/**
	 * Clean up after each test.
	 *
	 * Resets DummyErrorHandler and DummyObservabilityHandler between tests.
	 */
	public function tear_down(): void {
		DummyErrorHandler::reset();
		DummyObservabilityHandler::reset();
		parent::tear_down();
	}

	/**
	 * Create a test MCP server instance with optional tools, resources, and prompts.
	 *
	 * @param array $tools Optional ability names to register as tools.
	 * @param array $resources Optional ability names to register as resources.
	 * @param array $prompts Optional ability names or builder classes to register as prompts.
	 *
	 * @return \WP\MCP\Core\McpServer The configured MCP server instance.
	 * @throws \Exception
	 */
	public function makeServer( array $tools = array(), array $resources = array(), array $prompts = array() ): McpServer {
		return new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			$tools,
			$resources,
			$prompts,
		);
	}

	/**
	 * Registers an ability inside the wp_abilities_api_init hook.
	 *
	 * This helper ensures abilities are registered during the hook execution,
	 * as required by WordPress abilities API which uses doing_action() checks.
	 *
	 * @param string               $name The ability name.
	 * @param array<string, mixed> $args The ability arguments.
	 *
	 * @return void
	 */
	protected function register_ability_in_hook( string $name, array $args ): void {
		// If already registered, skip to avoid duplicate-registration _doing_it_wrong.
		if ( wp_has_ability( $name ) ) {
			return;
		}

		// If we're already inside the hook, register directly.
		if ( doing_action( 'wp_abilities_api_init' ) ) {
			wp_register_ability( $name, $args );
			return;
		}

		// Spoof hook context to register ability without triggering _doing_it_wrong.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		wp_register_ability( $name, $args );
		array_pop( $wp_current_filter );
	}
}
