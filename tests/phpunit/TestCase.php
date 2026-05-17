<?php
/**
 * Test base class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests;

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

		// Register mcp-adapter category during the proper hook
		add_action(
			'wp_abilities_api_categories_init',
			static function () {
				$categories_registry = \WP_Ability_Categories_Registry::get_instance();
				if ( $categories_registry->is_registered( 'mcp-adapter' ) ) {
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
		add_action( 'wp_abilities_api_categories_init', array( DummyAbility::class, 'register_category' ) );

		// Use DummyAbility to register test abilities
		add_action( 'wp_abilities_api_init', array( DummyAbility::class, 'register_abilities' ) );
	}

	/**
	 * Set up before each test.
	 *
	 * Sets up `_doing_it_wrong` capturing for all tests.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->doing_it_wrong_log = array();
		add_action( 'doing_it_wrong_run', array( $this, 'record_doing_it_wrong' ), 10, 3 );
	}

	/**
	 * Clean up after each test.
	 *
	 * This method resets the state of test handlers to ensure test isolation.
	 * Automatically resets DummyErrorHandler and DummyObservabilityHandler between tests.
	 */
	public function tear_down(): void {
		remove_action( 'doing_it_wrong_run', array( $this, 'record_doing_it_wrong' ) );
		$this->doing_it_wrong_log = array();
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
	 * Captured `_doing_it_wrong` calls during a test.
	 *
	 * @var list<array{function:string,message:string,version:string}>
	 */
	protected $doing_it_wrong_log = array();

	/**
	 * Records `_doing_it_wrong` calls for later assertions.
	 *
	 * @param string $the_method Function name flagged by `_doing_it_wrong`.
	 * @param string $message    Message supplied to `_doing_it_wrong`.
	 * @param string $version    Version string supplied to `_doing_it_wrong`.
	 *
	 * @return void
	 */
	public function record_doing_it_wrong( string $the_method, string $message, string $version ): void {
		$this->doing_it_wrong_log[] = array(
			'function' => $the_method,
			'message'  => $message,
			'version'  => $version,
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
		// If we're already inside the hook, register directly
		if ( doing_action( 'wp_abilities_api_init' ) ) {
			wp_register_ability( $name, $args );
			return;
		}

		// Create a callback that registers the ability
		$callback = static function () use ( $name, $args ) {
			wp_register_ability( $name, $args );
		};

		// Add the callback to the hook
		add_action( 'wp_abilities_api_init', $callback, 999 );

		do_action( 'wp_abilities_api_init' );

		// Clean up the callback to prevent duplicate registrations if hook fires again
		remove_action( 'wp_abilities_api_init', $callback, 999 );
	}
}
