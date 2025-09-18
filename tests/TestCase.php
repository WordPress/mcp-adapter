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
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		
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
	 * Clean up abilities after each test class finishes.
	 */
	public static function tear_down_after_class(): void {
		// Clean up any abilities registered by this test class to avoid
		// duplicate registration notices.
		DummyAbility::unregister_all();
		parent::tear_down_after_class();
	}

	/**
	 * Create a test MCP server instance with optional tools, resources, and prompts.
	 *
	 * @param array $tools Optional ability names to register as tools.
	 * @param array $resources Optional ability names to register as resources.
	 * @param array $prompts Optional ability names or builder classes to register as prompts.
	 *
	 * @return McpServer The configured MCP server instance.
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
}
