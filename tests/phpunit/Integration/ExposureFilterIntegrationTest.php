<?php
/**
 * End-to-end tests for the `mcp_adapter_is_ability_exposed` filter as
 * seen from an actual tool dispatch through `ToolsHandler::call_tool()`.
 *
 * Confirms that the current-server holder wired in `ToolsHandler` is
 * observable from inside the exposure filter — i.e. the filter receives
 * the real `McpServer` instance during a tool call, not a synthetic
 * value produced by direct helper invocation.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Abilities\McpAbilityExposureContext;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\TestCase;

final class ExposureFilterIntegrationTest extends TestCase {

	/**
	 * User ID for authenticated tests.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'exposureintuser',
				'user_pass'  => 'testpass',
				'user_email' => 'exposureint@example.com',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		McpAdapter::instance()->set_current_server( null );
		wp_set_current_user( 0 );
		wp_delete_user( $this->user_id );
		parent::tear_down();
	}

	public function test_call_tool_threads_actual_server_into_exposure_filter(): void {
		// Use the already-registered `test/always-allowed` fixture (has
		// `mcp.public => true`), which is guaranteed to be present in
		// `wp_get_abilities()` in every test run — avoids any
		// registration-timing ambiguity.
		$this->assertTrue(
			wp_has_ability( 'test/always-allowed' ),
			'Sanity: fixture ability must be registered before this test runs.'
		);

		$server = $this->makeServer( array( 'mcp-adapter/discover-abilities' ) );

		$captured_server = 'unset';
		$captured_path   = null;
		$fire_count      = 0;
		$saw_names       = array();
		$filter          = function ( $is_exposed, $ability, $context ) use ( &$captured_server, &$captured_path, &$fire_count, &$saw_names ) {
			++$fire_count;
			$saw_names[] = $ability->get_name();
			if ( 'test/always-allowed' === $ability->get_name() ) {
				$captured_server = $context->server;
				$captured_path   = $context->exposure_path;
			}
			return $is_exposed;
		};
		add_filter( 'mcp_adapter_is_ability_exposed', $filter, 10, 3 );

		$result = null;
		try {
			$handler = new ToolsHandler( $server );
			$result  = $handler->call_tool(
				array(
					'params' => array(
						// McpNameSanitizer replaces "/" with "-" for the tool name;
// the *ability* name still contains the slash but the MCP tool
// name the client invokes does not.
'name'      => 'mcp-adapter-discover-abilities',
						'arguments' => array(),
					),
				)
			);
		} finally {
			remove_filter( 'mcp_adapter_is_ability_exposed', $filter, 10 );
		}

		// Diagnostic assertion first — surfaces the actual state in CI
		// output if the filter never fired (rather than a bare "unset"
		// mismatch that hides the root cause).
		$this->assertGreaterThan(
			0,
			$fire_count,
			sprintf(
				'Exposure filter never fired. call_tool returned %s. Abilities seen by filter: %s',
				null === $result ? 'null' : get_class( $result ),
				empty( $saw_names ) ? '(none)' : implode( ', ', $saw_names )
			)
		);

		$this->assertContains(
			'test/always-allowed',
			$saw_names,
			sprintf(
				'Filter fired %d times but never for test/always-allowed. Names seen: %s',
				$fire_count,
				implode( ', ', $saw_names )
			)
		);

		$this->assertInstanceOf(
			McpServer::class,
			$captured_server,
			'The exposure filter must receive the real McpServer when invoked through ToolsHandler::call_tool(), not null.'
		);
		$this->assertSame(
			$server,
			$captured_server,
			'The exposure filter must receive the same McpServer instance the ToolsHandler was constructed with.'
		);
		$this->assertSame(
			McpAbilityExposureContext::PATH_DISCOVER,
			$captured_path,
			'The exposure path must reflect the built-in tool that triggered the check.'
		);
	}

	public function test_call_tool_clears_current_server_after_dispatch(): void {
		$this->assertNull(
			McpAdapter::instance()->get_current_server(),
			'Sanity: nothing should be set before dispatch.'
		);

		$server  = $this->makeServer( array( 'mcp-adapter/discover-abilities' ) );
		$handler = new ToolsHandler( $server );
		$handler->call_tool(
			array(
				'params' => array(
					// McpNameSanitizer replaces "/" with "-" for the tool name;
// the *ability* name still contains the slash but the MCP tool
// name the client invokes does not.
'name'      => 'mcp-adapter-discover-abilities',
					'arguments' => array(),
				),
			)
		);

		$this->assertNull(
			McpAdapter::instance()->get_current_server(),
			'ToolsHandler must clear the current-server holder after dispatch so subsequent, unrelated calls do not observe stale server state.'
		);
	}
}
