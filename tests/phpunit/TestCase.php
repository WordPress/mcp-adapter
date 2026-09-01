<?php
/**
 * Test base class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\McpSchema\Record;
use WP\McpSchema\Schema;
use WP\McpSchema\Schemas;
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
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

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
	public function tearDown(): void {
		DummyErrorHandler::reset();
		DummyObservabilityHandler::reset();
		parent::tearDown();
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

	/** Select one exact schema from a fresh provider. */
	protected function schema( string $revision = Schemas::V2025_11_25 ): Schema {
		return Schemas::create()->forVersion( $revision );
	}

	/** Build a minimal exact request context. */
	protected function request_context( McpServer $server, string $revision = Schemas::V2025_11_25, string $transport = 'test' ): McpRequestContext {
		return new McpRequestContext(
			$server->get_schemas()->forVersion( $revision ),
			new \stdClass(),
			null,
			$transport
		);
	}

	/** Convert a record to an assertion-friendly associative array. */
	protected function record_array( Record $record ): array {
		$data = json_decode( (string) wp_json_encode( $record ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Resolve the session user_meta key the same way SessionManager does.
	 *
	 * @return string
	 */
	protected static function session_meta_key(): string {
		$method = new \ReflectionMethod( \WP\MCP\Transport\Infrastructure\SessionManager::class, 'session_meta_key' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (string) $method->invoke( null );
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
