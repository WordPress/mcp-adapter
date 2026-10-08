<?php
/**
 * Raw HTTP Origin validation for both supported MCP revisions.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\McpSchema\Schemas;
use WP_REST_Request;

/**
 * Proves that a present Origin must match the WordPress installation on every HTTP method.
 *
 * @since n.e.x.t
 */
final class HttpOriginValidationTest extends TestCase {

	private const HOME = 'http://example.org';

	/** @var \WP\MCP\Core\McpServer */
	private McpServer $server;

	/** @var \WP\MCP\Transport\Infrastructure\HttpRequestHandler */
	private HttpRequestHandler $http;

	/** Set up one server with an ordinary Ability-backed tool on a known home URL. */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 1 );
		$this->set_url( 'home', self::HOME );
		$this->set_url( 'siteurl', self::HOME );

		$this->server = $this->makeServer( array( 'test/always-allowed' ) );
		$this->http   = new HttpRequestHandler( $this->server->create_transport_context() );
	}

	/** A request without an Origin header is accepted under both revisions. */
	public function test_missing_origin_is_accepted_for_both_revisions(): void {
		$session_id = $this->initialize_session( null );

		$this->assertSame( 200, $this->tools_list_2025( $session_id, null )['status'] );
		$this->assertSame( 200, $this->tools_list_2026( null )['status'] );
	}

	/** An empty Origin header is treated as absent. */
	public function test_empty_origin_is_accepted_for_both_revisions(): void {
		$session_id = $this->initialize_session( '' );

		$this->assertSame( 200, $this->tools_list_2025( $session_id, '' )['status'] );
		$this->assertSame( 200, $this->tools_list_2026( '' )['status'] );
	}

	/** The origin of home_url() is accepted under both revisions. */
	public function test_home_origin_is_accepted_for_both_revisions(): void {
		$session_id = $this->initialize_session( self::HOME );

		$this->assertSame( 200, $this->tools_list_2025( $session_id, self::HOME )['status'] );
		$this->assertSame( 200, $this->tools_list_2026( self::HOME )['status'] );
	}

	/** The origin of site_url() is accepted when it differs from home_url(). */
	public function test_site_url_origin_is_accepted_when_it_differs_from_home(): void {
		$this->set_url( 'siteurl', 'http://cms.example.net/wordpress' );

		$session_id = $this->initialize_session( 'http://cms.example.net' );

		$this->assertSame( 200, $this->tools_list_2025( $session_id, 'http://cms.example.net' )['status'] );
		$this->assertSame( 200, $this->tools_list_2026( 'http://cms.example.net' )['status'] );
		$this->assertSame( 200, $this->tools_list_2026( self::HOME )['status'] );
	}

	/** Default ports, scheme case, and host case do not affect the comparison. */
	public function test_default_port_and_case_are_normalized(): void {
		$this->set_url( 'home', 'https://example.org' );

		foreach ( array( 'https://example.org', 'https://example.org:443', 'HTTPS://Example.ORG' ) as $origin ) {
			$this->assertSame( 200, $this->tools_list_2026( $origin )['status'], $origin );
		}

		$this->assertSame( 200, $this->tools_list_2026( 'http://example.org:80' )['status'], 'site_url() keeps the http origin' );
	}

	/** A configured non-default port must be matched exactly. */
	public function test_configured_port_is_part_of_the_origin(): void {
		$this->set_url( 'home', 'http://localhost:8888' );
		$this->set_url( 'siteurl', 'http://localhost:8888' );

		$this->assertSame( 200, $this->tools_list_2026( 'http://localhost:8888' )['status'] );
		$this->assert_origin_rejected( $this->tools_list_2026( 'http://localhost' ) );
		$this->assert_origin_rejected( $this->tools_list_2026( 'http://localhost:8889' ) );
	}

	/**
	 * Origins that differ in host, scheme, or port are rejected under both revisions.
	 *
	 * @dataProvider provide_mismatched_origins
	 *
	 * @param string $origin Origin header value.
	 */
	public function test_mismatched_origin_is_rejected_for_both_revisions( string $origin ): void {
		$session_id = $this->initialize_session( null );

		$this->assert_origin_rejected( $this->tools_list_2025( $session_id, $origin ) );
		$this->assert_origin_rejected( $this->tools_list_2026( $origin ) );
	}

	/** @return array<string, array{0: string}> */
	public static function provide_mismatched_origins(): array {
		return array(
			'different host'          => array( 'http://evil.example' ),
			'subdomain'               => array( 'http://www.example.org' ),
			'different scheme'        => array( 'https://example.org' ),
			'different port'          => array( 'http://example.org:8080' ),
			'opaque null origin'      => array( 'null' ),
			'origin with path'        => array( 'http://example.org/wp-admin' ),
			'origin with trailing /'  => array( 'http://example.org/' ),
			'origin with query'       => array( 'http://example.org?x=1' ),
			'origin with fragment'    => array( 'http://example.org#x' ),
			'origin with userinfo'    => array( 'http://user@example.org' ),
			'two comma-joined values' => array( 'http://example.org, http://evil.example' ),
			'host only'               => array( 'example.org' ),
		);
	}

	/** A rejected initialize does not create a 2025 session. */
	public function test_rejected_initialize_creates_no_session(): void {
		$response = $this->http_post( $this->initialize_payload(), array( 'Origin' => 'http://evil.example' ) );

		$this->assert_origin_rejected( $response );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response['headers'] );
	}

	/** The check runs before 2025 session termination, so a rejected DELETE leaves the session intact. */
	public function test_2025_delete_is_rejected_before_session_termination(): void {
		$session_id = $this->initialize_session( null );

		$rejected = $this->send( 'DELETE', Schemas::V2025_11_25, 'http://evil.example', $session_id );
		$this->assertSame( 403, $rejected->get_status() );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $rejected->get_data()['error']['code'] );
		$this->assertSame( 200, $this->tools_list_2025( $session_id, null )['status'] );

		$this->assertSame( 200, $this->send( 'DELETE', Schemas::V2025_11_25, self::HOME, $session_id )->get_status() );
		$this->assertSame( 404, $this->tools_list_2025( $session_id, null )['status'] );
	}

	/** GET and DELETE check the Origin before method handling under both revisions. */
	public function test_get_and_delete_check_origin_for_both_revisions(): void {
		foreach ( array( Schemas::V2025_11_25, Schemas::V2026_07_28 ) as $revision ) {
			foreach ( array( 'GET', 'DELETE' ) as $method ) {
				$label    = $method . ' ' . $revision;
				$rejected = $this->send( $method, $revision, 'http://evil.example', null );
				$this->assertSame( 403, $rejected->get_status(), $label );
				$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $rejected->get_data()['error']['code'], $label );
			}

			$this->assertSame( 405, $this->send( 'GET', $revision, self::HOME, null )->get_status(), 'GET ' . $revision );
			$this->assertSame( 405, $this->send( 'GET', $revision, null, null )->get_status(), 'GET ' . $revision );
		}

		$this->assertSame( 405, $this->send( 'DELETE', Schemas::V2026_07_28, self::HOME, null )->get_status() );
	}

	/** Origins added through the filter are accepted; the filter receives the server. */
	public function test_filter_adds_exact_origins(): void {
		$received = null;
		$filter   = static function ( array $origins, McpServer $server ) use ( &$received ): array {
			$received  = $server;
			$origins[] = 'https://app.example.com/dashboard';
			$origins[] = 'http://localhost:3000';

			return $origins;
		};
		add_filter( 'mcp_adapter_allowed_http_origins', $filter, 10, 2 );
		try {
			$session_id = $this->initialize_session( 'https://app.example.com' );
			$legacy     = $this->tools_list_2025( $session_id, 'https://app.example.com:443' );
			$modern     = $this->tools_list_2026( 'http://localhost:3000' );
			$home       = $this->tools_list_2026( self::HOME );
			$other_port = $this->tools_list_2026( 'http://localhost:3001' );
		} finally {
			remove_filter( 'mcp_adapter_allowed_http_origins', $filter, 10 );
		}

		$this->assertSame( $this->server, $received );
		$this->assertSame( 200, $legacy['status'] );
		$this->assertSame( 200, $modern['status'] );
		$this->assertSame( 200, $home['status'] );
		$this->assert_origin_rejected( $other_port );
	}

	/** The filter can remove the default origins. */
	public function test_filter_can_remove_default_origins(): void {
		$filter = static fn(): array => array();
		add_filter( 'mcp_adapter_allowed_http_origins', $filter );
		try {
			$rejected  = $this->tools_list_2026( self::HOME );
			$no_origin = $this->tools_list_2026( null );
		} finally {
			remove_filter( 'mcp_adapter_allowed_http_origins', $filter );
		}

		$this->assert_origin_rejected( $rejected );
		$this->assertSame( 200, $no_origin['status'] );
	}

	/**
	 * A filter value that is not an array of strings rejects every present Origin and is logged.
	 *
	 * @dataProvider provide_invalid_filter_values
	 *
	 * @param mixed $value Filter return value.
	 */
	public function test_invalid_filter_value_fails_closed( $value ): void {
		$filter = static fn() => $value;
		add_filter( 'mcp_adapter_allowed_http_origins', $filter );
		try {
			$home      = $this->tools_list_2026( self::HOME );
			$no_origin = $this->tools_list_2026( null );
		} finally {
			remove_filter( 'mcp_adapter_allowed_http_origins', $filter );
		}

		$this->assert_origin_rejected( $home );
		$this->assertSame( 200, $no_origin['status'] );
		$notices = array_filter(
			DummyErrorHandler::$logs,
			static fn( array $log ): bool => false !== strpos( $log['message'], 'mcp_adapter_allowed_http_origins' )
		);
		$this->assertCount( 1, $notices );
		$this->assertSame( array( 'HttpOriginValidator::is_allowed' ), array_values( $notices )[0]['context'] );
	}

	/** @return array<string, array{0: mixed}> */
	public static function provide_invalid_filter_values(): array {
		return array(
			'string'                  => array( self::HOME ),
			'null'                    => array( null ),
			'false'                   => array( false ),
			'array with a non-string' => array( array( self::HOME, 42 ) ),
			'nested array'            => array( array( array( self::HOME ) ) ),
		);
	}

	/** The built-in REST route applies the check after authentication and before MCP processing. */
	public function test_rest_route_rejects_mismatched_origin(): void {
		new HttpTransport( $this->server->create_transport_context() );
		do_action( 'rest_api_init' );

		$statuses = array();
		foreach ( array( 'http://evil.example', self::HOME, null ) as $origin ) {
			$request = new WP_REST_Request( 'POST', '/mcp/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $this->initialize_payload() ) );
			if ( null !== $origin ) {
				$request->set_header( 'Origin', $origin );
			}

			$response   = rest_do_request( $request );
			$statuses[] = $response->get_status();
			if ( 403 !== $response->get_status() ) {
				continue;
			}

			$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response->get_data()['error']['code'] );
			$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response->get_headers() );
		}

		$this->assertSame( array( 403, 200, 200 ), $statuses );
	}

	/** The Origin header is captured on the request context. */
	public function test_request_context_captures_origin_header(): void {
		$request = new WP_REST_Request( 'POST', '/mcp' );
		$this->assertNull( ( new HttpRequestContext( $request ) )->origin_header );

		$request->set_header( 'Origin', 'https://example.org' );
		$this->assertSame( 'https://example.org', ( new HttpRequestContext( $request ) )->origin_header );
	}

	/**
	 * Assert the HTTP 403 JSON-RPC permission error used for a rejected Origin.
	 *
	 * @param array{status: int, data: array<string, mixed>, headers: array<string, string>} $response Response.
	 */
	private function assert_origin_rejected( array $response ): void {
		$this->assertSame( 403, $response['status'] );
		$this->assertSame( '2.0', $response['data']['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $response['data'] );
		$this->assertNull( $response['data']['id'] );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response['data']['error']['code'] );
		$this->assertStringContainsString( 'Invalid Origin header', $response['data']['error']['message'] );
	}

	/** Initialize a 2025 session and return its ID. */
	private function initialize_session( ?string $origin ): string {
		$response = $this->http_post( $this->initialize_payload(), $this->origin_headers( $origin ) );
		$this->assertSame( 200, $response['status'] );
		$this->assertArrayHasKey( 'Mcp-Session-Id', $response['headers'] );

		return $response['headers']['Mcp-Session-Id'];
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>} */
	private function tools_list_2025( string $session_id, ?string $origin ): array {
		return $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
			),
			array_merge(
				$this->origin_headers( $origin ),
				array(
					'Mcp-Session-Id'       => $session_id,
					'MCP-Protocol-Version' => Schemas::V2025_11_25,
				)
			)
		);
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>} */
	private function tools_list_2026( ?string $origin ): array {
		return $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
						'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
						'io.modelcontextprotocol/clientInfo'         => array(
							'name'    => 'origin-test',
							'version' => '1.0',
						),
					),
				),
			),
			array_merge(
				$this->origin_headers( $origin ),
				array(
					'MCP-Protocol-Version' => Schemas::V2026_07_28,
					'Mcp-Method'           => 'tools/list',
				)
			)
		);
	}

	/** @return array<string, mixed> */
	private function initialize_payload(): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => Schemas::V2025_11_25,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'origin-test',
					'version' => '1.0',
				),
			),
		);
	}

	/**
	 * Pin the home or site URL for this test.
	 *
	 * The test environment may define WP_HOME and WP_SITEURL, which override the stored options.
	 *
	 * @param string $option Either home or siteurl.
	 * @param string $url    URL to return.
	 */
	private function set_url( string $option, string $url ): void {
		remove_all_filters( 'pre_option_' . $option );
		add_filter( 'pre_option_' . $option, static fn(): string => $url );
	}

	/** @return array<string, string> */
	private function origin_headers( ?string $origin ): array {
		return null === $origin ? array() : array( 'Origin' => $origin );
	}

	/** Send a bodyless GET or DELETE request. */
	private function send( string $method, string $revision, ?string $origin, ?string $session_id ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mcp' );
		$request->set_header( 'MCP-Protocol-Version', $revision );
		if ( null !== $origin ) {
			$request->set_header( 'Origin', $origin );
		}
		if ( null !== $session_id ) {
			$request->set_header( 'Mcp-Session-Id', $session_id );
		}

		return $this->http->handle_request( new HttpRequestContext( $request ) );
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>} */
	private function http_post( array $payload, array $headers ): array {
		$request = new WP_REST_Request( 'POST', '/mcp' );
		$request->set_body( (string) wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$response = $this->http->handle_request( new HttpRequestContext( $request ) );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

		return array(
			'status'  => $response->get_status(),
			'data'    => is_array( $data ) ? $data : array(),
			'headers' => $response->get_headers(),
		);
	}
}
