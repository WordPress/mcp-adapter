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

		// Ensure categories API is initialized first
		if ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
			do_action( 'wp_abilities_api_categories_init' );
		}

		// Use DummyAbility to register test abilities
		add_action( 'wp_abilities_api_init', array( DummyAbility::class, 'register_abilities' ) );

		// Register the default MCP abilities inside the hook
		add_action(
			'wp_abilities_api_init',
			static function () {
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
		);

		// Ensure abilities API is initialized so MCP abilities can be registered
		if ( ! did_action( 'wp_abilities_api_init' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
	}

	/**
	 * Clean up after each test class finishes.
	 *
	 * Note: We intentionally do NOT unregister test abilities here.
	 * Test fixtures from DummyAbility are designed to persist for the entire
	 * test suite run. This is necessary because WordPress hooks
	 * (wp_abilities_api_init, wp_abilities_api_categories_init) can only be fired
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

	/**
	 * Captured `_doing_it_wrong` calls during a test.
	 *
	 * @var array<int,array{function:string,message:string,version:string}>
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

	/**
	 * Asserts that `_doing_it_wrong` was triggered for the expected function.
	 *
	 * @param string      $the_method         Function name expected to trigger `_doing_it_wrong`.
	 * @param string|null $message_contains Optional. String that should be contained in the error message.
	 *
	 * @return void
	 */
	protected function assertDoingItWrongTriggered( string $the_method, ?string $message_contains = null ): void {
		foreach ( $this->doing_it_wrong_log as $entry ) {
			if ( $the_method === $entry['function'] ) {
				// If message check is specified, verify it contains the expected text.
				if ( null !== $message_contains && false === strpos( $entry['message'], $message_contains ) ) {
					continue;
				}
				return;
			}
		}

		if ( null !== $message_contains ) {
			$this->fail(
				sprintf(
					'Failed asserting that _doing_it_wrong() was triggered for %s with message containing "%s".',
					$the_method,
					$message_contains
				)
			);
		} else {
			$this->fail( sprintf( 'Failed asserting that _doing_it_wrong() was triggered for %s.', $the_method ) );
		}
	}
}
