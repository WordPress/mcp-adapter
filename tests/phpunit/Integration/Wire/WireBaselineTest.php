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
		);

		foreach ( $lines as $fixture => $line ) {
			$output = (string) $handle->invoke( $bridge, $line );
			$this->assert_stdio_fixture( $fixture, $output );
		}
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

		$data = $response->get_data();
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
		$this->handle_fixture(
			'wire/' . $name . '.json',
			array( 'line' => $output_line )
		);
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
