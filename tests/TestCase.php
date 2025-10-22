<?php
/**
 * Test base class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Abilities\GetAbilityInfoAbility;
use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillsTestCase;

abstract class TestCase extends PolyfillsTestCase {

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

		// Register mcp-adapter category during the proper hook
		add_action(
			'abilities_api_categories_init',
			static function () {
				if ( \WP_Abilities_Category_Registry::get_instance()->is_registered( 'mcp-adapter' ) ) {
					return;
				}

				wp_register_ability_category(
					'mcp-adapter',
					array(
						'label'       => 'MCP Adapter',
						'description' => 'Abilities for the MCP Adapter',
					)
				);
			}
		);

		// Use DummyAbility to register test category
		add_action( 'abilities_api_categories_init', array( DummyAbility::class, 'register_category' ) );

		// Ensure categories API is initialized first
		if ( ! did_action( 'abilities_api_categories_init' ) ) {
			do_action( 'abilities_api_categories_init' );
		}

		// Use DummyAbility to register test abilities
		add_action( 'abilities_api_init', array( DummyAbility::class, 'register_abilities' ) );

		// Ensure abilities API is initialized so MCP abilities can be registered
		if ( ! did_action( 'abilities_api_init' ) ) {
			do_action( 'abilities_api_init' );
		}

		// Register the default MCP abilities directly for tests
		// Only register if they don't already exist to prevent duplicates
		if ( ! wp_get_ability( 'mcp-adapter/discover-abilities' ) ) {
			DiscoverAbilitiesAbility::register();
		}
		if ( ! wp_get_ability( 'mcp-adapter/get-ability-info' ) ) {
			GetAbilityInfoAbility::register();
		}
		if ( ! wp_get_ability( 'mcp-adapter/execute-ability' ) ) {
			ExecuteAbilityAbility::register();
		}
	}

	/**
	 * Clean up after each test class finishes.
	 *
	 * Note: We intentionally do NOT unregister test abilities here.
	 * Test fixtures from DummyAbility are designed to persist for the entire
	 * test suite run. This is necessary because WordPress hooks
	 * (abilities_api_init, abilities_api_categories_init) can only be fired
	 * once during the test suite execution. Re-registering between test classes
	 * would fail since the hooks have already been executed.
	 *
	 * This approach differs from abilities-api's test pattern, which registers
	 * fixtures per-test in set_up(). We use per-class registration with global
	 * persistence because our DummyAbility fixtures are designed as stable,
	 * reusable test helpers that don't interfere with test isolation.
	 */
	public static function tear_down_after_class(): void {
		parent::tear_down_after_class();
	}

	/**
	 * Clean up after each test.
	 *
	 * This method resets the state of test handlers to ensure test isolation.
	 * Automatically resets DummyErrorHandler and DummyObservabilityHandler between tests.
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
	 * Asserts that the given value is an instance of WP_Error.
	 *
	 * @param mixed  $actual  The value to check.
	 * @param string $message Optional. Message to display when the assertion fails.
	 *
	 * @return void
	 */
	public function assertWPError( $actual, string $message = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $actual, $message );
	}

	/**
	 * Asserts that the given value is not an instance of WP_Error.
	 *
	 * @param mixed  $actual  The value to check.
	 * @param string $message Optional. Message to display when the assertion fails.
	 *
	 * @return void
	 */
	public function assertNotWPError( $actual, string $message = '' ): void {
		$this->assertNotInstanceOf( \WP_Error::class, $actual, $message );
	}
}
