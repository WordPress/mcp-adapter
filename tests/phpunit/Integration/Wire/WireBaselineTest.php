<?php
/**
 * Byte-level wire baseline for the 2025-11-25 protocol era.
 *
 * @package mcp-adapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration\Wire;

use WP\MCP\Cli\StdioServerBridge;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

/**
 * Captures the exact JSON bytes the adapter emits today, so later commits can
 * prove wire identity against these fixtures instead of re-deriving intent.
 *
 * Each exchange is compared byte-for-byte against a committed fixture in
 * tests/phpunit/Fixtures/wire/. Session UUIDs are the only normalized value
 * ({{SESSION_ID}}). Regenerate all fixtures by running the suite with the
 * WIRE_FIXTURES=update environment variable, then review the diff.
 */
final class WireBaselineTest extends TestCase {

	private const REST_ROUTE = '/mcp/v1/mcp';

	/**
	 * The server under test.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $server;

	/**
	 * Admin user for transport permission.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		$this->server = new McpServer(
			'wire-srv',
			'mcp/v1',
			'/mcp',
			'Wire Baseline Server',
			'Server used for byte-level wire fixtures',
			'0.0.1',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array(
				'test/always-allowed',
				'test/image',
				'test/embedded-text-resource',
				'test/embedded-blob-resource',
				'test/meta-leak',
				'test/execute-exception',
				'test/permission-denied',
				'test/with-icons',
				'test/with-custom-meta',
				'test/annotated-ability',
			),
			array(
				'test/resource',
				'test/resource-new-meta',
				'test/resource-blob-content',
				'test/resource-multiple-contents',
				'test/resource-plain-string',
			),
			array(
				'test/prompt',
				'test/prompt-with-annotations',
				'test/prompt-explicit-args',
			)
		);

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	// -------------------------------------------------------------------------
	// HTTP exchanges
	// -------------------------------------------------------------------------

	public function test_http_initialize_and_session_lifecycle(): void {
		$response   = $this->dispatch_http( $this->jsonrpc( 1, 'initialize', array( 'protocolVersion' => '2025-11-25' ) ) );
		$session_id = $this->assert_wire_fixture( 'http-initialize', $response );

		$this->assertNotNull( $session_id, 'initialize must issue a session id header' );

		// Re-initializing while presenting the session does not mint a new one.
		$again = $this->dispatch_http(
			$this->jsonrpc( 2, 'initialize', array( 'protocolVersion' => '2025-11-25' ) ),
			array( 'Mcp-Session-Id' => $session_id )
		);
		$this->assert_wire_fixture( 'http-initialize-existing-session', $again );

		// DELETE terminates the session.
		$terminated = $this->dispatch_http( null, array( 'Mcp-Session-Id' => $session_id ), 'DELETE' );
		$this->assert_wire_fixture( 'http-session-delete', $terminated );
	}

	public function test_http_ping(): void {
		$session = $this->start_session();
		$this->assert_wire_fixture(
			'http-ping',
			$this->dispatch_http( $this->jsonrpc( 7, 'ping', array() ), array( 'Mcp-Session-Id' => $session ) )
		);
	}

	public function test_http_ping_without_session(): void {
		$this->assert_wire_fixture(
			'http-ping-no-session',
			$this->dispatch_http( $this->jsonrpc( 8, 'ping', array() ) )
		);
	}

	public function test_http_tools_list(): void {
		$session = $this->start_session();
		$this->assert_wire_fixture(
			'http-tools-list',
			$this->dispatch_http( $this->jsonrpc( 10, 'tools/list', array() ), array( 'Mcp-Session-Id' => $session ) )
		);
	}

	public function test_http_tools_call_variants(): void {
		$session = $this->start_session();
		$calls   = array(
			'http-tools-call-text'            => array( 'test-always-allowed', array() ),
			'http-tools-call-image'           => array( 'test-image', array() ),
			'http-tools-call-embedded-text'   => array( 'test-embedded-text-resource', array() ),
			'http-tools-call-embedded-blob'   => array( 'test-embedded-blob-resource', array() ),
			'http-tools-call-meta-redaction'  => array( 'test-meta-leak', array() ),
			'http-tools-call-execute-error'   => array( 'test-execute-exception', array() ),
			'http-tools-call-permission-deny' => array( 'test-permission-denied', array() ),
		);

		$id = 20;
		foreach ( $calls as $fixture => $call ) {
			[ $tool, $arguments ] = $call;
			$this->assert_wire_fixture(
				$fixture,
				$this->dispatch_http(
					$this->jsonrpc(
						$id,
						'tools/call',
						array(
							'name'      => $tool,
							'arguments' => $arguments,
						)
					),
					array( 'Mcp-Session-Id' => $session )
				)
			);
			++$id;
		}

		$this->assert_wire_fixture(
			'http-tools-call-unknown-tool',
			$this->dispatch_http(
				$this->jsonrpc( 40, 'tools/call', array( 'name' => 'test-not-registered' ) ),
				array( 'Mcp-Session-Id' => $session )
			)
		);

		$this->assert_wire_fixture(
			'http-tools-call-missing-name',
			$this->dispatch_http(
				$this->jsonrpc( 41, 'tools/call', array() ),
				array( 'Mcp-Session-Id' => $session )
			)
		);
	}

	public function test_http_a5_input_identity_and_list_result(): void {
		$this->assert_wire_fixture(
			'http-scalar-request',
			$this->dispatch_http_raw( '"hello"' )
		);

		$this->assert_wire_fixture(
			'http-numeric-key-request-object',
			$this->dispatch_http_raw( '{"0":{"jsonrpc":"2.0","id":42,"method":"ping"}}' )
		);

		$this->assert_wire_fixture(
			'http-empty-batch',
			$this->dispatch_http_raw( '[]' )
		);

		$session = $this->start_session();
		$this->assert_wire_fixture(
			'http-tools-call-list-arguments',
			$this->dispatch_http_raw(
				'{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"test-always-allowed","arguments":[1,2]}}',
				array( 'Mcp-Session-Id' => $session )
			)
		);

		$filter = static function (): array {
			return array(
				array( 'id' => 1 ),
				array( 'id' => 2 ),
			);
		};
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		try {
			$this->assert_wire_fixture(
				'http-tools-call-list-result',
				$this->dispatch_http(
					$this->jsonrpc( 43, 'tools/call', array( 'name' => 'test-always-allowed' ) ),
					array( 'Mcp-Session-Id' => $session )
				)
			);
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $filter );
		}
	}

	public function test_http_resources(): void {
		$session = $this->start_session();

		$this->assert_wire_fixture(
			'http-resources-list',
			$this->dispatch_http( $this->jsonrpc( 50, 'resources/list', array() ), array( 'Mcp-Session-Id' => $session ) )
		);

		$this->assert_wire_fixture(
			'http-resources-templates-list',
			$this->dispatch_http( $this->jsonrpc( 51, 'resources/templates/list', array() ), array( 'Mcp-Session-Id' => $session ) )
		);

		$reads = array(
			'http-resources-read-text'         => 'WordPress://local/resource-1',
			'http-resources-read-blob'         => 'WordPress://local/resource-blob-content',
			'http-resources-read-multiple'     => 'WordPress://local/resource-multiple-contents',
			'http-resources-read-plain-string' => 'WordPress://local/resource-plain-string',
		);

		$id = 60;
		foreach ( $reads as $fixture => $uri ) {
			$this->assert_wire_fixture(
				$fixture,
				$this->dispatch_http(
					$this->jsonrpc( $id, 'resources/read', array( 'uri' => $uri ) ),
					array( 'Mcp-Session-Id' => $session )
				)
			);
			++$id;
		}

		$this->assert_wire_fixture(
			'http-resources-read-unknown',
			$this->dispatch_http(
				$this->jsonrpc( 70, 'resources/read', array( 'uri' => 'WordPress://local/does-not-exist' ) ),
				array( 'Mcp-Session-Id' => $session )
			)
		);
	}

	public function test_http_prompts(): void {
		$session = $this->start_session();

		$this->assert_wire_fixture(
			'http-prompts-list',
			$this->dispatch_http( $this->jsonrpc( 80, 'prompts/list', array() ), array( 'Mcp-Session-Id' => $session ) )
		);

		$this->assert_wire_fixture(
			'http-prompts-get',
			$this->dispatch_http(
				$this->jsonrpc(
					81,
					'prompts/get',
					array(
						'name'      => 'test-prompt',
						'arguments' => array( 'code' => 'echo 1;' ),
					)
				),
				array( 'Mcp-Session-Id' => $session )
			)
		);

		$this->assert_wire_fixture(
			'http-prompts-get-missing-required',
			$this->dispatch_http(
				$this->jsonrpc( 82, 'prompts/get', array( 'name' => 'test-prompt' ) ),
				array( 'Mcp-Session-Id' => $session )
			)
		);

		$this->assert_wire_fixture(
			'http-prompts-get-unknown',
			$this->dispatch_http(
				$this->jsonrpc( 83, 'prompts/get', array( 'name' => 'test-not-a-prompt' ) ),
				array( 'Mcp-Session-Id' => $session )
			)
		);
	}

	public function test_http_envelope_and_error_shapes(): void {
		$session = $this->start_session();

		// Batch of two requests returns an array response.
		$batch = array(
			$this->jsonrpc( 90, 'ping', array() ),
			$this->jsonrpc( 91, 'tools/list', array() ),
		);
		$this->assert_wire_fixture(
			'http-batch',
			$this->dispatch_http( $batch, array( 'Mcp-Session-Id' => $session ) )
		);

		// Notification only: HTTP 202, empty body.
		$notification = array(
			'jsonrpc' => '2.0',
			'method'  => 'notifications/initialized',
		);
		$this->assert_wire_fixture(
			'http-notification-only',
			$this->dispatch_http( $notification, array( 'Mcp-Session-Id' => $session ) )
		);

		// Unknown method.
		$this->assert_wire_fixture(
			'http-unknown-method',
			$this->dispatch_http( $this->jsonrpc( 92, 'no/such-method', array() ), array( 'Mcp-Session-Id' => $session ) )
		);

		// Structurally invalid JSON-RPC message.
		$this->assert_wire_fixture(
			'http-invalid-jsonrpc',
			$this->dispatch_http( array( 'id' => 93 ), array( 'Mcp-Session-Id' => $session ) )
		);

		// Unparseable body.
		$this->assert_wire_fixture(
			'http-parse-error',
			$this->dispatch_http_raw( '{"jsonrpc": "2.0", "id": 94, ', array( 'Mcp-Session-Id' => $session ) )
		);

		// Unsupported protocol version header.
		$this->assert_wire_fixture(
			'http-unsupported-protocol-header',
			$this->dispatch_http(
				$this->jsonrpc( 95, 'ping', array() ),
				array(
					'Mcp-Session-Id'       => $session,
					'Mcp-Protocol-Version' => '1999-01-01',
				)
			)
		);

		// GET is reserved for SSE and returns 405.
		$this->assert_wire_fixture(
			'http-get-sse-reserved',
			$this->dispatch_http( null, array( 'Mcp-Session-Id' => $session ), 'GET' )
		);
	}

	public function test_http_2026_discovery_agreement_and_method_gates(): void {
		$this->assert_wire_fixture(
			'http-2026-discover',
			$this->dispatch_modern_http( 100, 'server/discover' )
		);

		$this->assert_wire_fixture(
			'http-2026-ping-method-not-found',
			$this->dispatch_modern_http( 101, 'ping' )
		);

		$this->assert_wire_fixture(
			'http-2026-missing-version-header',
			$this->dispatch_http(
				$this->jsonrpc( 102, 'tools/list', $this->modern_params() )
			)
		);

		$this->assert_wire_fixture(
			'http-2026-header-mismatch',
			$this->dispatch_modern_http( 103, 'tools/list', array(), '2025-11-25' )
		);

		$this->assert_wire_fixture(
			'http-2026-unsupported-version',
			$this->dispatch_modern_http( 104, 'tools/list', array(), '1900-01-01', '1900-01-01' )
		);
	}

	public function test_http_2026_advertised_operation_flows(): void {
		$this->assert_wire_fixture( 'http-2026-tools-list', $this->dispatch_modern_http( 110, 'tools/list' ) );
		$this->assert_wire_fixture(
			'http-2026-tools-call',
			$this->dispatch_modern_http(
				111,
				'tools/call',
				array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				)
			)
		);
		$this->assert_wire_fixture(
			'http-2026-tools-call-unknown',
			$this->dispatch_modern_http( 112, 'tools/call', array( 'name' => 'test-not-registered' ) )
		);

		$this->assert_wire_fixture( 'http-2026-resources-list', $this->dispatch_modern_http( 120, 'resources/list' ) );
		$this->assert_wire_fixture( 'http-2026-resource-templates-list', $this->dispatch_modern_http( 121, 'resources/templates/list' ) );
		$this->assert_wire_fixture(
			'http-2026-resources-read',
			$this->dispatch_modern_http( 122, 'resources/read', array( 'uri' => 'WordPress://local/resource-1' ) )
		);
		$this->assert_wire_fixture(
			'http-2026-resources-read-unknown',
			$this->dispatch_modern_http( 123, 'resources/read', array( 'uri' => 'WordPress://local/does-not-exist' ) )
		);

		$this->assert_wire_fixture( 'http-2026-prompts-list', $this->dispatch_modern_http( 130, 'prompts/list' ) );
		$this->assert_wire_fixture(
			'http-2026-prompts-get',
			$this->dispatch_modern_http(
				131,
				'prompts/get',
				array(
					'name'      => 'test-prompt',
					'arguments' => array( 'code' => 'echo 1;' ),
				)
			)
		);
		$this->assert_wire_fixture(
			'http-2026-prompts-get-unknown',
			$this->dispatch_modern_http( 132, 'prompts/get', array( 'name' => 'test-not-a-prompt' ) )
		);
	}

	public function test_http_2026_stateless_continuation_round_trips(): void {
		$permission_checks = 0;
		$this->register_continuation_components( $permission_checks );
		$capabilities = array( 'elicitation' => array() );

		$tool_params = array(
			'name'      => 'a7-confirm-tool',
			'arguments' => array( 'city' => 'Bucharest' ),
		);
		$tool_initial = $this->dispatch_modern_http( 150, 'tools/call', $tool_params, '2026-07-28', '2026-07-28', $capabilities );
		$tool_state   = $this->request_state_from_http( $tool_initial );
		$this->assert_wire_fixture( 'http-2026-tools-call-input-required', $tool_initial );

		$tool_retry                   = $tool_params;
		$tool_retry['requestState']   = $tool_state;
		$tool_retry['inputResponses'] = $this->accepted_input_responses();
		$tool_complete                = $this->dispatch_modern_http( 151, 'tools/call', $tool_retry, '2026-07-28', '2026-07-28', $capabilities );
		$this->assert_wire_fixture( 'http-2026-tools-call-resumed', $tool_complete );
		$this->assertSame( 2, $permission_checks, 'Tool permission must run on the initial request and the retry.' );

		$tampered_state                   = $tool_state;
		$tampered_state                   = substr( $tampered_state, 0, -1 ) . ( 'A' === substr( $tampered_state, -1 ) ? 'B' : 'A' );
		$tampered_retry                   = $tool_retry;
		$tampered_retry['requestState']   = $tampered_state;
		$this->assert_wire_fixture(
			'http-2026-tools-call-tampered-state',
			$this->dispatch_modern_http( 152, 'tools/call', $tampered_retry, '2026-07-28', '2026-07-28', $capabilities )
		);

		$cross_request                         = $tool_retry;
		$cross_request['arguments']['city']    = 'Cluj';
		$this->assert_wire_fixture(
			'http-2026-tools-call-cross-request-state',
			$this->dispatch_modern_http( 153, 'tools/call', $cross_request, '2026-07-28', '2026-07-28', $capabilities )
		);
		$this->assertSame( 2, $permission_checks, 'Rejected state must not reach the permission callback.' );

		$this->assert_wire_fixture(
			'http-2026-tools-call-missing-input-capability',
			$this->dispatch_modern_http( 154, 'tools/call', $tool_params )
		);
		$this->assertSame( 3, $permission_checks, 'The initial callback runs before its requested capabilities can be derived.' );

		$missing_state                   = $tool_params;
		$missing_state['inputResponses'] = $this->accepted_input_responses();
		$this->assert_wire_fixture(
			'http-2026-tools-call-input-responses-without-state',
			$this->dispatch_modern_http( 155, 'tools/call', $missing_state, '2026-07-28', '2026-07-28', $capabilities )
		);
		$this->assertSame( 3, $permission_checks, 'Continuation validation must precede permission checks.' );

		$resource_params = array( 'uri' => 'WordPress://local/a7-continuation-resource' );
		$resource_initial = $this->dispatch_modern_http( 160, 'resources/read', $resource_params, '2026-07-28', '2026-07-28', $capabilities );
		$resource_state   = $this->request_state_from_http( $resource_initial );
		$this->assert_wire_fixture( 'http-2026-resources-read-input-required', $resource_initial );

		$resource_retry                   = $resource_params;
		$resource_retry['requestState']   = $resource_state;
		$resource_retry['inputResponses'] = $this->accepted_input_responses();
		$this->assert_wire_fixture(
			'http-2026-resources-read-resumed',
			$this->dispatch_modern_http( 161, 'resources/read', $resource_retry, '2026-07-28', '2026-07-28', $capabilities )
		);

		$prompt_params = array(
			'name'      => 'a7-confirm-prompt',
			'arguments' => array( 'topic' => 'MRTR' ),
		);
		$prompt_initial = $this->dispatch_modern_http( 170, 'prompts/get', $prompt_params, '2026-07-28', '2026-07-28', $capabilities );
		$prompt_state   = $this->request_state_from_http( $prompt_initial );
		$this->assert_wire_fixture( 'http-2026-prompts-get-input-required', $prompt_initial );

		$prompt_retry                   = $prompt_params;
		$prompt_retry['requestState']   = $prompt_state;
		$prompt_retry['inputResponses'] = $this->accepted_input_responses();
		$this->assert_wire_fixture(
			'http-2026-prompts-get-resumed',
			$this->dispatch_modern_http( 171, 'prompts/get', $prompt_retry, '2026-07-28', '2026-07-28', $capabilities )
		);

		$session = $this->start_session();
		$this->assert_wire_fixture(
			'http-legacy-tools-call-input-required-looking-data',
			$this->dispatch_http(
				$this->jsonrpc( 180, 'tools/call', $tool_params ),
				array( 'Mcp-Session-Id' => $session )
			)
		);
		$legacy_continuation                 = $tool_params;
		$legacy_continuation['requestState'] = $tool_state;
		$this->assert_wire_fixture(
			'http-legacy-tools-call-continuation-rejected',
			$this->dispatch_http(
				$this->jsonrpc( 181, 'tools/call', $legacy_continuation ),
				array( 'Mcp-Session-Id' => $session )
			)
		);

		$input_required_filter = static fn(): array => array(
			'resultType'   => 'input_required',
			'requestState' => 'ordinary business data',
		);
		add_filter( 'mcp_adapter_tool_call_result', $input_required_filter );
		try {
			$this->assert_wire_fixture(
				'http-2026-tools-call-non-opt-in-input-required-looking-data',
				$this->dispatch_modern_http(
					182,
					'tools/call',
					array( 'name' => 'test-always-allowed' ),
					'2026-07-28',
					'2026-07-28',
					$capabilities
				)
			);
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $input_required_filter );
		}
	}

	// -------------------------------------------------------------------------
	// STDIO exchanges
	// -------------------------------------------------------------------------

	public function test_stdio_exchanges(): void {
		$bridge = new StdioServerBridge( $this->server );
		$handle = new \ReflectionMethod( StdioServerBridge::class, 'handle_request' );
		$handle->setAccessible( true );

		$lines = array(
			'stdio-initialize'      => wp_json_encode( $this->jsonrpc( 1, 'initialize', array( 'protocolVersion' => '2025-11-25' ) ) ),
			'stdio-tools-list'      => wp_json_encode( $this->jsonrpc( 2, 'tools/list', array() ) ),
			'stdio-tools-call-text' => wp_json_encode(
				$this->jsonrpc(
					3,
					'tools/call',
					array(
						'name'      => 'test-always-allowed',
						'arguments' => array(),
					)
				)
			),
			'stdio-parse-error'     => '{"jsonrpc": "2.0", "id": 4,',
			'stdio-list-arguments'  => '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"test-always-allowed","arguments":[1,2]}}',
		);

		foreach ( $lines as $fixture => $line ) {
			$output = (string) $handle->invoke( $bridge, $line );
			$this->assert_stdio_fixture( $fixture, $output );
		}
	}

	public function test_stdio_2026_exchanges_on_the_legacy_stream(): void {
		$bridge = new StdioServerBridge( $this->server );
		$handle = new \ReflectionMethod( StdioServerBridge::class, 'handle_request' );
		$handle->setAccessible( true );

		$legacy_initialize = (string) wp_json_encode( $this->jsonrpc( 140, 'initialize', array( 'protocolVersion' => '2025-11-25' ) ) );
		$handle->invoke( $bridge, $legacy_initialize );

		$lines = array(
			'stdio-2026-discover'   => wp_json_encode( $this->jsonrpc( 141, 'server/discover', $this->modern_params() ) ),
			'stdio-2026-tools-list' => wp_json_encode( $this->jsonrpc( 142, 'tools/list', $this->modern_params() ) ),
			'stdio-legacy-ping-after-2026' => wp_json_encode( $this->jsonrpc( 143, 'ping', array() ) ),
		);

		foreach ( $lines as $fixture => $line ) {
			$output = (string) $handle->invoke( $bridge, $line );
			$this->assert_stdio_fixture( $fixture, $output );
		}
	}

	public function test_stdio_2026_stateless_continuation(): void {
		$permission_checks = 0;
		$this->register_continuation_components( $permission_checks );

		$bridge = new StdioServerBridge( $this->server );
		$handle = new \ReflectionMethod( StdioServerBridge::class, 'handle_request' );
		$handle->setAccessible( true );

		$params = $this->modern_params(
			array(
				'name'      => 'a7-confirm-tool',
				'arguments' => array( 'city' => 'Bucharest' ),
			),
			'2026-07-28',
			array( 'elicitation' => array() )
		);
		$initial = (string) $handle->invoke( $bridge, wp_json_encode( $this->jsonrpc( 190, 'tools/call', $params ) ) );
		$this->assert_stdio_fixture( 'stdio-2026-tools-call-input-required', $initial );

		$initial_data = json_decode( $initial, true );
		$this->assertIsArray( $initial_data );
		$this->assertIsString( $initial_data['result']['requestState'] ?? null );
		$params['requestState']   = $initial_data['result']['requestState'];
		$params['inputResponses'] = $this->accepted_input_responses();

		$complete = (string) $handle->invoke( $bridge, wp_json_encode( $this->jsonrpc( 191, 'tools/call', $params ) ) );
		$this->assert_stdio_fixture( 'stdio-2026-tools-call-resumed', $complete );
		$this->assertSame( 2, $permission_checks );
	}

	// -------------------------------------------------------------------------
	// Harness
	// -------------------------------------------------------------------------

	/**
	 * Build a JSON-RPC request array.
	 *
	 * @param int|string           $id     Request id.
	 * @param string               $method Method name.
	 * @param array<string, mixed> $params Params.
	 *
	 * @return array<string, mixed>
	 */
	private function jsonrpc( $id, string $method, array $params ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => $method,
			'params'  => $params,
		);
	}

	/**
	 * Build modern per-request metadata around operation-specific params.
	 *
	 * @param array<string, mixed> $params Operation params.
	 * @param string               $version Declared request revision.
	 * @param array<string, mixed> $client_capabilities Per-request optional capabilities.
	 *
	 * @return array<string, mixed>
	 */
	private function modern_params( array $params = array(), string $version = '2026-07-28', array $client_capabilities = array() ): array {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => $version,
			'io.modelcontextprotocol/clientCapabilities' => $client_capabilities,
		);

		return $params;
	}

	/**
	 * Dispatch one modern HTTP request with explicit body/header versions.
	 *
	 * @param int|string           $id Request id.
	 * @param string               $method Method name.
	 * @param array<string, mixed> $params Operation params.
	 * @param string               $header_version HTTP revision header.
	 * @param string               $body_version Body metadata revision.
	 * @param array<string, mixed> $client_capabilities Per-request optional capabilities.
	 */
	private function dispatch_modern_http( $id, string $method, array $params = array(), string $header_version = '2026-07-28', string $body_version = '2026-07-28', array $client_capabilities = array() ): \WP_REST_Response {
		return $this->dispatch_http(
			$this->jsonrpc( $id, $method, $this->modern_params( $params, $body_version, $client_capabilities ) ),
			array( 'Mcp-Protocol-Version' => $header_version )
		);
	}

	/**
	 * Run initialize and return the issued session id.
	 */
	private function start_session(): string {
		$response = $this->dispatch_http( $this->jsonrpc( 1, 'initialize', array( 'protocolVersion' => '2025-11-25' ) ) );
		$headers  = $response->get_headers();
		$session  = $headers['Mcp-Session-Id'] ?? null;
		$this->assertIsString( $session, 'initialize did not issue a session id' );

		return $session;
	}

	/**
	 * Dispatch a JSON body through the real REST route.
	 *
	 * @param array<mixed>|null     $body    Body to JSON-encode, or null for no body.
	 * @param array<string, string> $headers Extra headers.
	 * @param string                $method  HTTP method.
	 */
	private function dispatch_http( ?array $body, array $headers = array(), string $method = 'POST' ): \WP_REST_Response {
		return $this->dispatch_http_raw( null === $body ? null : (string) wp_json_encode( $body ), $headers, $method );
	}

	/**
	 * Dispatch a raw body string through the real REST route.
	 *
	 * @param string|null           $raw_body Raw request body.
	 * @param array<string, string> $headers  Extra headers.
	 * @param string                $method   HTTP method.
	 */
	private function dispatch_http_raw( ?string $raw_body, array $headers = array(), string $method = 'POST' ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, self::REST_ROUTE );
		if ( null !== $raw_body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $raw_body );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$response = rest_do_request( $request );

		// rest_do_request() stops at dispatch(); the real HTTP path continues
		// through serve_request(), which applies rest_post_dispatch — the hook
		// the adapter uses to attach the Mcp-Session-Id response header. Apply
		// it here exactly as WP_REST_Server::serve_request() does, so fixtures
		// capture the same headers a live client sees.
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		return $response;
	}

	/**
	 * Assert one HTTP exchange against its fixture (or update it).
	 *
	 * Returns the session id issued by this exchange, when present, so callers
	 * can chain requests.
	 */
	private function assert_wire_fixture( string $name, \WP_REST_Response $response ): ?string {
		$headers    = $response->get_headers();
		$session_id = isset( $headers['Mcp-Session-Id'] ) && is_string( $headers['Mcp-Session-Id'] )
			? $headers['Mcp-Session-Id']
			: null;

		$data = $this->normalize_dynamic_request_state( $response->get_data() );
		$body = null === $data ? '' : (string) wp_json_encode( $data );

		$captured = array(
			'status'         => $response->get_status(),
			'session_header' => null === $session_id ? null : '{{SESSION_ID}}',
			'body'           => $body,
		);

		$this->handle_fixture( 'wire/' . $name . '.json', $captured );

		return $session_id;
	}

	/**
	 * Assert one STDIO exchange against its fixture (or update it).
	 */
	private function assert_stdio_fixture( string $name, string $output_line ): void {
		if ( false !== strpos( $output_line, '"requestState":"mcp1.' ) ) {
			$decoded = json_decode( $output_line, true );
			if ( is_array( $decoded ) ) {
				$output_line = (string) wp_json_encode( $this->normalize_dynamic_request_state( $decoded ) );
			}
		}

		$this->handle_fixture(
			'wire/' . $name . '.json',
			array( 'line' => $output_line )
		);
	}

	/**
	 * Register direct components used only by A7 wire exchanges.
	 */
	private function register_continuation_components( int &$permission_checks ): void {
		$permission = static function () use ( &$permission_checks ): bool {
			++$permission_checks;

			return true;
		};
		$input_required = fn( string $state ): array => array(
			'resultType'    => 'input_required',
			'inputRequests' => array( 'confirm' => $this->elicitation_input_request() ),
			'requestState'  => $state,
		);

		$tool = McpTool::fromArray(
			array(
				'name'                    => 'a7-confirm-tool',
				'description'             => 'A direct tool that requires confirmation.',
				'inputSchema'             => array(
					'type'       => 'object',
					'properties' => array( 'city' => array( 'type' => 'string' ) ),
					'required'   => array( 'city' ),
				),
				'handler'                 => static function ( array $arguments, ?array $continuation = null ) use ( $input_required ): array {
					if ( null === $continuation ) {
						return $input_required( 'tool:' . $arguments['city'] );
					}

					return array(
						'completed' => true,
						'city'      => $arguments['city'],
						'state'     => $continuation['requestState'] ?? null,
						'action'    => $continuation['inputResponses']['confirm']['action'] ?? null,
					);
				},
				'permission'              => $permission,
				'supports_input_required' => true,
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );

		$resource = McpResource::fromArray(
			array(
				'uri'                     => 'WordPress://local/a7-continuation-resource',
				'name'                    => 'A7 continuation resource',
				'handler'                 => static function ( array $params, ?array $continuation = null ) use ( $input_required ) {
					if ( null === $continuation ) {
						return $input_required( 'resource:' . $params['uri'] );
					}

					return 'resource resumed with ' . ( $continuation['inputResponses']['confirm']['action'] ?? 'missing' );
				},
				'permission'              => static fn(): bool => true,
				'supports_input_required' => true,
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );

		$prompt = McpPrompt::fromArray(
			array(
				'name'                    => 'a7-confirm-prompt',
				'description'             => 'A direct prompt that requires confirmation.',
				'arguments'               => array(
					array(
						'name'     => 'topic',
						'required' => true,
					),
				),
				'handler'                 => static function ( array $arguments, ?array $continuation = null ) use ( $input_required ): array {
					if ( null === $continuation ) {
						return $input_required( 'prompt:' . $arguments['topic'] );
					}

					return array(
						'messages' => array(
							array(
								'role'    => 'assistant',
								'content' => array(
									'type' => 'text',
									'text' => 'Prompt resumed with ' . ( $continuation['inputResponses']['confirm']['action'] ?? 'missing' ),
								),
							),
						),
					);
				},
				'permission'              => static fn(): bool => true,
				'supports_input_required' => true,
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );

		$registry_property = new \ReflectionProperty( McpServer::class, 'component_registry' );
		$registry_property->setAccessible( true );
		$registry = $registry_property->getValue( $this->server );
		$this->assertInstanceOf( \WP\MCP\Core\McpComponentRegistry::class, $registry );
		$registry->register_tools( array( $tool ) );
		$registry->register_resources( array( $resource ) );
		$registry->register_prompts( array( $prompt ) );
	}

	/** @return array<string, mixed> */
	private function elicitation_input_request(): array {
		return array(
			'method' => 'elicitation/create',
			'params' => array(
				'mode'            => 'form',
				'message'         => 'Confirm this operation',
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'confirmed' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'confirmed' ),
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function accepted_input_responses(): array {
		return array(
			'confirm' => array(
				'action'  => 'accept',
				'content' => array( 'confirmed' => true ),
			),
		);
	}

	private function request_state_from_http( \WP_REST_Response $response ): string {
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$result = $data['result'] ?? null;
		if ( $result instanceof \stdClass ) {
			$result = get_object_vars( $result );
		}
		$this->assertIsArray( $result );
		$this->assertIsString( $result['requestState'] ?? null );

		return $result['requestState'];
	}

	/**
	 * Replace only Adapter-signed state, preserving all other exact wire data.
	 *
	 * @param mixed $value Wire value.
	 * @return mixed
	 */
	private function normalize_dynamic_request_state( $value ) {
		if ( is_string( $value ) && 0 === strpos( $value, 'mcp1.' ) ) {
			return '{{REQUEST_STATE}}';
		}

		if ( $value instanceof \stdClass ) {
			$result = new \stdClass();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$result->{$key} = $this->normalize_dynamic_request_state( $item );
			}

			return $result;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->normalize_dynamic_request_state( $item );
		}

		return $value;
	}

	/**
	 * Compare captured wire data against the committed fixture, or rewrite the
	 * fixture when WIRE_FIXTURES=update is set.
	 *
	 * @param string               $relative Fixture path below tests/phpunit/Fixtures/.
	 * @param array<string, mixed> $captured Captured exchange data.
	 */
	private function handle_fixture( string $relative, array $captured ): void {
		$path = dirname( __DIR__, 2 ) . '/Fixtures/' . $relative;

		if ( 'update' === getenv( 'WIRE_FIXTURES' ) ) {
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents(
				$path,
				(string) wp_json_encode( $captured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
			);
			$this->assertFileExists( $path );

			return;
		}

		$this->assertFileExists( $path, sprintf( 'Missing wire fixture %s — run with WIRE_FIXTURES=update to create it.', $relative ) );

		$expected = json_decode( (string) file_get_contents( $path ), true );
		$this->assertSame(
			$expected,
			$captured,
			sprintf( 'Wire output for %s no longer matches the committed baseline.', $relative )
		);
	}
}
