<?php
/**
 * Raw HTTP and STDIO corpus for both supported MCP revisions.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Cli\StdioServerBridge;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\McpSchema\Schemas;
use WP_REST_Request;

/** Proves exact positive and cross-revision negative wire behavior. */
final class DualRevisionWireCorpusTest extends TestCase {

	/** @var \WP\MCP\Transport\Infrastructure\HttpRequestHandler */
	private HttpRequestHandler $http;

	/** @var \WP\MCP\Cli\StdioServerBridge */
	private StdioServerBridge $stdio;

	/** Set up one server with an ordinary Ability-backed tool. */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 1 );

		$server      = $this->makeServer( array( 'test/always-allowed', 'test/image' ), array( 'test/resource' ), array( 'test/prompt' ) );
		$this->http  = new HttpRequestHandler( $server->create_transport_context() );
		$this->stdio = new StdioServerBridge( $server );
	}

	/** Prove initialization, session context, ping, and canonical list output. */
	public function test_http_2025_lifecycle_and_tools(): void {
		$initialize = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'init-2025',
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Schemas::V2025_11_25,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'wire-test',
						'version' => '1.0',
					),
				),
			)
		);

		$this->assertSame( 200, $initialize['status'] );
		$this->assertSame( Schemas::V2025_11_25, $initialize['data']['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'Mcp-Session-Id', $initialize['headers'] );
		$session_id  = $initialize['headers']['Mcp-Session-Id'];
		$initialized = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 202, $initialized['status'] );

		$ping = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'ping',
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 200, $ping['status'] );
		$this->assertSame( array(), $ping['data']['result'] );

		$list = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/list',
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 200, $list['status'] );
		$this->assertSame( 'test-always-allowed', $list['data']['result']['tools'][0]['name'] );
		$this->assertArrayNotHasKey( 'resultType', $list['data']['result'] );

		$call = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'test-always-allowed',
					'arguments' => new \stdClass(),
				),
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 200, $call['status'] );
		$this->assertFalse( $call['data']['result']['isError'] );

		$image = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'test-image',
					'arguments' => new \stdClass(),
				),
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 200, $image['status'] );
		$this->assertSame( 'image', $image['data']['result']['content'][0]['type'] );

		$resource = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'WordPress://local/resource-1' ),
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 'content', $resource['data']['result']['contents'][0]['text'] );

		$prompt = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'prompts/get',
				'params'  => array(
					'name'      => 'test-prompt',
					'arguments' => array( 'code' => 'echo 1;' ),
				),
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 'hi', $prompt['data']['result']['messages'][0]['content']['text'] );
	}

	/** A 2025 session cannot dispatch modern discovery. */
	public function test_http_2025_rejects_server_discover(): void {
		$session_id = $this->initialize_http_session();
		$response   = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'server/discover',
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);

		$this->assertSame( 404, $response['status'] );
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['data']['error']['code'] );
	}

	/** Prove stateless discovery and mandatory completed/cache fields. */
	public function test_http_2026_discovery_and_list(): void {
		$discover = $this->http_request_2026_07_28( 'server/discover', 10, array() );
		$this->assertSame( 200, $discover['status'] );
		$this->assertSame( 'complete', $discover['data']['result']['resultType'] );
		$this->assertSame( 0, $discover['data']['result']['ttlMs'] );
		$this->assertSame( 'private', $discover['data']['result']['cacheScope'] );
		$this->assertSame( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS, $discover['data']['result']['supportedVersions'] );

		$list = $this->http_request_2026_07_28( 'tools/list', 11, array() );
		$this->assertSame( 200, $list['status'] );
		$this->assertSame( 'complete', $list['data']['result']['resultType'] );
		$this->assertSame( 0, $list['data']['result']['ttlMs'] );
		$this->assertSame( 'private', $list['data']['result']['cacheScope'] );
		$this->assertSame( 'Srv', $list['data']['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] );
	}

	/** Prove modern resource and prompt discovery/execution plus cache fields. */
	public function test_http_2026_resources_and_prompts(): void {
		$resources = $this->http_request_2026_07_28( 'resources/list', 14, array() );
		$this->assertSame( 'complete', $resources['data']['result']['resultType'] );
		$this->assertSame( 0, $resources['data']['result']['ttlMs'] );
		$this->assertSame( 'private', $resources['data']['result']['cacheScope'] );

		$templates = $this->http_request_2026_07_28( 'resources/templates/list', 15, array() );
		$this->assertSame( array(), $templates['data']['result']['resourceTemplates'] );
		$this->assertSame( 0, $templates['data']['result']['ttlMs'] );

		$read = $this->http_request_2026_07_28(
			'resources/read',
			16,
			array( 'uri' => 'WordPress://local/resource-1' )
		);
		$this->assertSame( 'content', $read['data']['result']['contents'][0]['text'] );
		$this->assertSame( 'private', $read['data']['result']['cacheScope'] );

		$missing = $this->http_request_2026_07_28(
			'resources/read',
			17,
			array( 'uri' => 'WordPress://local/missing' )
		);
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $missing['data']['error']['code'] );

		$prompts = $this->http_request_2026_07_28( 'prompts/list', 18, array() );
		$this->assertSame( 'test-prompt', $prompts['data']['result']['prompts'][0]['name'] );
		$this->assertSame( 0, $prompts['data']['result']['ttlMs'] );

		$prompt = $this->http_request_2026_07_28(
			'prompts/get',
			19,
			array(
				'name'      => 'test-prompt',
				'arguments' => array( 'code' => 'echo 1;' ),
			)
		);
		$this->assertSame( 'hi', $prompt['data']['result']['messages'][0]['content']['text'] );
		$this->assertSame( 'complete', $prompt['data']['result']['resultType'] );
	}

	/** Missing tools and prompts use the standard Invalid Params error in both revisions. */
	public function test_http_missing_tools_and_prompts_use_invalid_params(): void {
		$session_id = $this->initialize_http_session();
		foreach (
			array(
				'tools/call' => array( 'name' => 'missing-tool' ),
				'prompts/get' => array( 'name' => 'missing-prompt' ),
			) as $method => $params
		) {
			$response_2025 = $this->http_post(
				array(
					'jsonrpc' => '2.0',
					'id'      => 190,
					'method'  => $method,
					'params'  => $params,
				),
				array(
					'Mcp-Session-Id'       => $session_id,
					'MCP-Protocol-Version' => Schemas::V2025_11_25,
				)
			);
			$this->assertSame( 200, $response_2025['status'] );
			$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response_2025['data']['error']['code'] );

			$response_2026 = $this->http_request_2026_07_28( $method, 191, $params );
			$this->assertSame( 400, $response_2026['status'] );
			$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response_2026['data']['error']['code'] );
		}
	}

	/** Fractional elicitation answers survive schema hydration into resource callbacks. */
	public function test_http_2026_preserves_fractional_input_responses(): void {
		$received = null;
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'test://fractional-input',
				'handler'    => static function ( array $params ) use ( &$received ): string {
					$received = $params;
					return 'content';
				},
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$server     = $this->makeServer( array(), array( $resource ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );

		$response = $this->http_request_2026_07_28(
			'resources/read',
			191,
			array(
				'uri'            => 'test://fractional-input',
				'inputResponses' => array(
					'quantity' => array(
						'action'  => 'accept',
						'content' => array(
							'amount' => 1.5,
							'count'  => 2,
						),
					),
				),
			)
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertIsArray( $received );
		$this->assertSame( 1.5, $received['inputResponses']['quantity']['content']['amount'] );
		$this->assertSame( 2, $received['inputResponses']['quantity']['content']['count'] );
	}

	/** Prove ordinary Ability execution needs no revision branch. */
	public function test_http_2026_tool_call_executes_ordinary_ability(): void {
		$response = $this->http_request_2026_07_28(
			'tools/call',
			12,
			array(
				'name'      => 'test-always-allowed',
				'arguments' => new \stdClass(),
			)
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'complete', $response['data']['result']['resultType'] );
		$this->assertFalse( $response['data']['result']['isError'] );
		$this->assertTrue( $response['data']['result']['structuredContent']['ok'] );
	}

	/** x-mcp-header arguments are mirrored and compared after decoding. */
	public function test_http_2026_validates_custom_tool_parameter_headers(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'regional-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'region' => array(
							'type'         => 'string',
							'x-mcp-header' => 'Region',
						),
					),
				),
				'handler'     => static fn( array $arguments ): array => $arguments,
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server     = $this->makeServer( array( $tool ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );

		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => 13,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'regional-tool',
				'arguments' => array( 'region' => 'eu-west' ),
				'_meta'     => $this->meta_2026_07_28(),
			),
		);
		$headers = array(
			'MCP-Protocol-Version' => Schemas::V2026_07_28,
			'Mcp-Method'           => 'tools/call',
			'Mcp-Name'             => 'regional-tool',
			'Mcp-Param-Region'     => 'eu-west',
		);

		$valid = $this->http_post( $payload, $headers );
		$this->assertSame( 200, $valid['status'] );

		unset( $headers['Mcp-Param-Region'] );
		$missing = $this->http_post( $payload, $headers );
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $missing['data']['error']['code'] );

		foreach ( array( 'région', "region\x01" ) as $unsafe_value ) {
			$payload['params']['arguments']['region'] = $unsafe_value;
			$headers['Mcp-Param-Region']              = $unsafe_value;
			$unsafe                                   = $this->http_post( $payload, $headers );
			$this->assertSame( 400, $unsafe['status'] );
			$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $unsafe['data']['error']['code'] );
		}

		$payload['params']['arguments']['region'] = 'région';
		$headers['Mcp-Param-Region']              = '=?base64?' . base64_encode( 'région' ) . '?='; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required MCP header sentinel encoding.
		$encoded                                  = $this->http_post( $payload, $headers );
		$this->assertSame( 200, $encoded['status'] );

		$nested = McpTool::fromArray(
			array(
				'name'        => 'nested-regional-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array(
							'type'       => 'object',
							'properties' => array(
								'region' => array(
									'type'         => 'string',
									'x-mcp-header' => 'Region',
								),
							),
						),
					),
				),
				'handler'     => static fn( array $arguments ): array => $arguments,
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $nested );
		$server          = $this->makeServer( array( $nested ) );
		$this->http      = new HttpRequestHandler( $server->create_transport_context() );
		$nested_response = $this->http_request_2026_07_28(
			'tools/call',
			14,
			array(
				'name'      => 'nested-regional-tool',
				'arguments' => array( 'location' => array( 'region' => 'eu-west' ) ),
			),
			array( 'Mcp-Param-Region' => 'eu-west' )
		);
		$this->assertSame( 200, $nested_response['status'] );
	}

	/** Modern requests reject removed and noncanonical methods before dispatch. */
	public function test_http_2026_rejects_ping_and_tools_list_all(): void {
		foreach ( array( 'initialize', 'notifications/initialized', 'ping', 'tools/list/all', 'logging/setLevel', 'roots/list', 'tasks/get', 'elicitation/create' ) as $method ) {
			$response = $this->http_request_2026_07_28( $method, 20, array() );
			$this->assertSame( 404, $response['status'] );
			$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['data']['error']['code'] );
		}
	}

	/** Modern mirrored protocol versions must agree in both directions. */
	public function test_http_2026_rejects_body_header_version_mismatch(): void {
		foreach (
			array(
				array( Schemas::V2025_11_25, Schemas::V2026_07_28 ),
				array( Schemas::V2026_07_28, Schemas::V2025_11_25 ),
			) as $versions
		) {
			$meta = $this->meta_2026_07_28();
			$meta['io.modelcontextprotocol/protocolVersion'] = $versions[0];
			$response                                        = $this->http_post(
				array(
					'jsonrpc' => '2.0',
					'id'      => 24,
					'method'  => 'tools/list',
					'params'  => array( '_meta' => $meta ),
				),
				array(
					'MCP-Protocol-Version' => $versions[1],
					'Mcp-Method'           => 'tools/list',
				)
			);
			$this->assertSame( 400, $response['status'] );
			$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $response['data']['error']['code'] );
		}
	}

	/** A fully modern envelope cannot enter the removed initialization flow. */
	public function test_http_2026_rejects_modern_initialize(): void {
		$response = $this->http_request_2026_07_28( 'initialize', 25, array() );
		$this->assertSame( 404, $response['status'] );
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['data']['error']['code'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response['headers'] );
	}

	/** Modern body/header failures use exact typed error codes. */
	public function test_http_2026_negative_envelopes(): void {
		$meta           = $this->meta_2026_07_28();
		$missing_header = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 30,
				'method'  => 'tools/list',
				'params'  => array( '_meta' => $meta ),
			),
			array( 'MCP-Protocol-Version' => Schemas::V2026_07_28 )
		);
		$this->assertSame( 400, $missing_header['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $missing_header['data']['error']['code'] );

		$encoded_method = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 301,
				'method'  => 'tools/list',
				'params'  => array( '_meta' => $meta ),
			),
			array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => '=?base64?' . base64_encode( 'tools/list' ) . '?=', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Negative MCP header encoding case.
			)
		);
		$this->assertSame( 400, $encoded_method['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $encoded_method['data']['error']['code'] );

		$missing_meta = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 31,
				'method'  => 'tools/list',
				'params'  => new \stdClass(),
			),
			array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => 'tools/list',
			)
		);
		$this->assertSame( 400, $missing_meta['status'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $missing_meta['data']['error']['code'] );

		$unsupported = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 32,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'     => '2099-01-01',
						'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
					),
				),
			),
			array(
				'MCP-Protocol-Version' => '2099-01-01',
				'Mcp-Method'           => 'tools/list',
			)
		);
		$this->assertSame( 400, $unsupported['status'] );
		$this->assertSame( McpErrorFactory::UNSUPPORTED_VERSION, $unsupported['data']['error']['code'] );
		$this->assertSame( '2099-01-01', $unsupported['data']['error']['data']['requested'] );
	}

	/** Batches are rejected on both transports before any handler executes. */
	public function test_http_and_stdio_reject_batches(): void {
		$batch = array(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'resources/list',
			),
		);
		$response = $this->http_post( $batch );
		$stdio    = $this->stdio_request( $batch );

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['data']['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $stdio['error']['code'] );
	}

	/** Malformed JSON and non-object top-level values fail identically on HTTP and STDIO. */
	public function test_http_and_stdio_reject_malformed_envelopes(): void {
		foreach (
			array(
				'{'    => McpErrorFactory::PARSE_ERROR,
				'[]'   => McpErrorFactory::INVALID_REQUEST,
				'null' => McpErrorFactory::INVALID_REQUEST,
			) as $raw => $expected_code
		) {
			$http  = $this->http_post_raw( $raw );
			$stdio = $this->stdio_raw( $raw );

			$this->assertSame( 400, $http['status'] );
			$this->assertSame( $expected_code, $http['data']['error']['code'] );
			$this->assertNull( $http['data']['id'] );
			$this->assertSame( $expected_code, $stdio['error']['code'] );
			$this->assertNull( $stdio['id'] );
		}
	}

	/** Schema-invalid JSON-RPC version and request IDs map to invalid request on both transports. */
	public function test_http_and_stdio_reject_invalid_jsonrpc_records(): void {
		foreach (
			array(
				array(
					'jsonrpc' => '1.0',
					'id'      => 34,
					'method'  => 'tools/list',
					'params'  => array( '_meta' => $this->meta_2026_07_28() ),
				),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1.5,
					'method'  => 'tools/list',
					'params'  => array( '_meta' => $this->meta_2026_07_28() ),
				),
			) as $payload
		) {
			$raw     = (string) wp_json_encode( $payload );
			$headers = array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => 'tools/list',
			);
			$http    = $this->http_post_raw( $raw, $headers );
			$stdio   = $this->stdio_raw( $raw );

			$this->assertSame( 400, $http['status'] );
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $http['data']['error']['code'] );
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $stdio['error']['code'] );
		}
	}

	/** Noncanonical tools/list/all is unavailable in the retained 2025 lifecycle too. */
	public function test_http_2025_rejects_tools_list_all(): void {
		$session_id = $this->initialize_http_session();
		$response   = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 33,
				'method'  => 'tools/list/all',
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);

		$this->assertSame( 404, $response['status'] );
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['data']['error']['code'] );
	}

	/** Native overflow is valid JSON but an invalid JSON-RPC request, not parse error. */
	public function test_http_and_stdio_reject_native_integer_overflow_as_invalid_request(): void {
		foreach (
			array(
				'{"jsonrpc":"2.0","id":9223372036854775808,"method":"tools/list"}',
				'{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"value":1e999}}',
			) as $raw
		) {
			$http = $this->http_post_raw( $raw );
			$this->assertSame( 400, $http['status'] );
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $http['data']['error']['code'] );

			$stdio = $this->stdio_raw( $raw );
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $stdio['error']['code'] );
		}
	}

	/** Session preflight preserves readable IDs and nulls unsafe IDs. */
	public function test_http_2025_missing_session_preserves_only_safe_id(): void {
		foreach ( array( '77', '1.5' ) as $raw_id ) {
			$response = $this->http_post_raw(
				sprintf( '{"jsonrpc":"2.0","id":%s,"method":"tools/list"}', $raw_id ),
				array( 'MCP-Protocol-Version' => Schemas::V2025_11_25 )
			);
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['data']['error']['code'] );
			$this->assertSame( '77' === $raw_id ? 77 : null, $response['data']['id'] );
		}
	}

	/** Failed initialization never creates HTTP session state. */
	public function test_http_failed_initialize_does_not_create_session(): void {
		$filter = static function () {
			return new \WP_Error( 'blocked', 'blocked' );
		};
		add_filter( 'mcp_adapter_initialize_response', $filter );
		try {
			$response = $this->http_post(
				array(
					'jsonrpc' => '2.0',
					'id'      => 78,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => Schemas::V2025_11_25,
						'capabilities'    => new \stdClass(),
						'clientInfo'      => array(
							'name'    => 'blocked',
							'version' => '1.0',
						),
					),
				)
			);
		} finally {
			remove_filter( 'mcp_adapter_initialize_response', $filter );
		}
		$this->assertSame( 500, $response['status'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response['headers'] );
	}

	/** Present Origin must match the WordPress installation. */
	public function test_http_validates_origin_and_ignores_modern_session_headers(): void {
		$valid = $this->http_request_2026_07_28(
			'tools/list',
			79,
			array(),
			array(
				'Origin'         => home_url(),
				'Mcp-Session-Id' => 'ignored',
				'Last-Event-ID'  => 'ignored',
			)
		);
		$this->assertSame( 200, $valid['status'] );

		$invalid = $this->http_request_2026_07_28(
			'tools/list',
			80,
			array(),
			array( 'Origin' => 'https://evil.example' )
		);
		$this->assertSame( 403, $invalid['status'] );
	}

	/** The Origin allowlist filter accepts exact origins and invalid filter values fail closed. */
	public function test_http_origin_allowlist_filter_is_enforced_fail_closed(): void {
		$allow = static fn(): array => array( 'https://trusted.example' );
		add_filter( 'mcp_adapter_allowed_http_origins', $allow );
		try {
			$trusted = $this->http_request_2026_07_28(
				'tools/list',
				84,
				array(),
				array( 'Origin' => 'https://trusted.example' )
			);
		} finally {
			remove_filter( 'mcp_adapter_allowed_http_origins', $allow );
		}
		$this->assertSame( 200, $trusted['status'] );

		$invalid = static fn(): string => 'https://trusted.example';
		add_filter( 'mcp_adapter_allowed_http_origins', $invalid );
		try {
			$rejected = $this->http_request_2026_07_28(
				'tools/list',
				85,
				array(),
				array( 'Origin' => 'https://trusted.example' )
			);
		} finally {
			remove_filter( 'mcp_adapter_allowed_http_origins', $invalid );
		}
		$this->assertSame( 403, $rejected['status'] );
	}

	/** GET and DELETE are unavailable for the sessionless 2026 transport. */
	public function test_http_2026_get_and_delete_are_method_not_allowed(): void {
		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$request = new WP_REST_Request( $method, '/mcp' );
			$request->set_header( 'MCP-Protocol-Version', Schemas::V2026_07_28 );
			$response = $this->http->handle_request( new HttpRequestContext( $request ) );
			$this->assertSame( 405, $response->get_status() );
		}
	}

	/** Legacy GET is rejected while DELETE terminates the exact 2025 session. */
	public function test_http_2025_get_rejected_and_delete_terminates_session(): void {
		$session_id  = $this->initialize_http_session();
		$get         = new WP_REST_Request( 'GET', '/mcp' );
		$get->set_header( 'MCP-Protocol-Version', Schemas::V2025_11_25 );
		$get_response = $this->http->handle_request( new HttpRequestContext( $get ) );
		$this->assertSame( 405, $get_response->get_status() );
		$this->assertNull( $get_response->get_data() );

		$delete = new WP_REST_Request( 'DELETE', '/mcp' );
		$delete->set_header( 'MCP-Protocol-Version', Schemas::V2025_11_25 );
		$delete->set_header( 'Mcp-Session-Id', $session_id );
		$delete_response = $this->http->handle_request( new HttpRequestContext( $delete ) );
		$this->assertSame( 200, $delete_response->get_status() );
		$this->assertNull( $delete_response->get_data() );

		$after_delete = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 81,
				'method'  => 'tools/list',
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 404, $after_delete['status'] );
		$this->assertSame( McpErrorFactory::SESSION_NOT_FOUND, $after_delete['data']['error']['code'] );
	}

	/** Embedded-resource records retain both metadata levels in final JSON for each revision. */
	public function test_wire_serializes_embedded_resource_without_placeholder_objects(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'embedded-resource-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static fn(): array => array(
					'type'      => 'resource',
					'_meta'     => array( 'block' => true ),
					'resource'  => array(
						'uri'      => 'fixture://nested',
						'text'     => 'nested content',
						'mimeType' => 'text/plain',
						'_meta'    => array( 'resource' => true ),
					),
				),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server     = $this->makeServer( array( $tool ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );

		$session_id = $this->initialize_http_session();
		$legacy     = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 82,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'embedded-resource-tool',
					'arguments' => new \stdClass(),
				),
			),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$modern = $this->http_request_2026_07_28(
			'tools/call',
			83,
			array(
				'name'      => 'embedded-resource-tool',
				'arguments' => new \stdClass(),
			)
		);

		foreach ( array( $legacy, $modern ) as $response ) {
			$block = $response['data']['result']['content'][0];
			$this->assertSame( 'resource', $block['type'] );
			$this->assertTrue( $block['_meta']['block'] );
			$this->assertSame( 'fixture://nested', $block['resource']['uri'] );
			$this->assertSame( 'nested content', $block['resource']['text'] );
			$this->assertSame( 'text/plain', $block['resource']['mimeType'] );
			$this->assertTrue( $block['resource']['_meta']['resource'] );
			$this->assertStringNotContainsString( '"resource":{}', (string) wp_json_encode( $response['data'] ) );
		}
	}

	/** STDIO may alternate initialized 2025 lines and self-contained 2026 lines. */
	public function test_stdio_alternates_2025_and_2026_lines(): void {
		$initialize = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 40,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Schemas::V2025_11_25,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'stdio-test',
						'version' => '1.0',
					),
				),
			)
		);
		$this->assertSame( Schemas::V2025_11_25, $initialize['result']['protocolVersion'] );
		$this->assertSame(
			'',
			$this->stdio_notification(
				array(
					'jsonrpc' => '2.0',
					'method'  => 'notifications/initialized',
				)
			)
		);

		$discover = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 41,
				'method'  => 'server/discover',
				'params'  => array( '_meta' => $this->meta_2026_07_28() ),
			)
		);
		$this->assertSame( 'complete', $discover['result']['resultType'] );

		$resources = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 43,
				'method'  => 'resources/list',
				'params'  => array( '_meta' => $this->meta_2026_07_28() ),
			)
		);
		$this->assertSame( 'complete', $resources['result']['resultType'] );
		$prompts = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 44,
				'method'  => 'prompts/list',
				'params'  => array( '_meta' => $this->meta_2026_07_28() ),
			)
		);
		$this->assertSame( 'test-prompt', $prompts['result']['prompts'][0]['name'] );

		$ping = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 42,
				'method'  => 'ping',
			)
		);
		$this->assertSame( array(), $ping['result'] );
	}

	/** STDIO modern negatives have no header layer but retain exact revision errors. */
	public function test_stdio_2026_negative_cases(): void {
		$ping = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 50,
				'method'  => 'ping',
				'params'  => array( '_meta' => $this->meta_2026_07_28() ),
			)
		);
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $ping['error']['code'] );

		$unsupported = $this->stdio_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 51,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'     => '2099-01-01',
						'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
					),
				),
			)
		);
		$this->assertSame( McpErrorFactory::UNSUPPORTED_VERSION, $unsupported['error']['code'] );
	}

	/** The retained bridge control surface and enable filter remain source-compatible. */
	public function test_stdio_control_surface_and_enable_filter(): void {
		$this->assertSame( 'srv', $this->stdio->get_server()->get_server_id() );
		$this->stdio->stop();

		$disable = '__return_false';
		add_filter( 'mcp_adapter_enable_stdio_transport', $disable );
		try {
			$this->stdio->serve();
			$this->fail( 'Disabled STDIO transport unexpectedly started.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'STDIO transport is disabled', $exception->getMessage() );
		} finally {
			remove_filter( 'mcp_adapter_enable_stdio_transport', $disable );
		}
	}

	/** @return array<string, mixed> */
	private function meta_2026_07_28(): array {
		return array(
			'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
			'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'wire-test',
				'version' => '1.0',
			),
		);
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>} */
	private function http_request_2026_07_28( string $method, int $id, array $params, array $extra_headers = array() ): array {
		$params['_meta'] = $this->meta_2026_07_28();
		$headers         = array_merge(
			$extra_headers,
			array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => $method,
			)
		);
		if ( isset( $params['name'] ) ) {
			$headers['Mcp-Name'] = (string) $params['name'];
		} elseif ( isset( $params['uri'] ) ) {
			$headers['Mcp-Name'] = (string) $params['uri'];
		}

		return $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			),
			$headers
		);
	}

	/** Create an HTTP 2025 session and return its ID. */
	private function initialize_http_session(): string {
		$response = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Schemas::V2025_11_25,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'wire-test',
						'version' => '1.0',
					),
				),
			)
		);

		return $response['headers']['Mcp-Session-Id'];
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>} */
	private function http_post( array $payload, array $headers = array() ): array {
		return $this->http_post_raw( (string) wp_json_encode( $payload ), $headers );
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>} */
	private function http_post_raw( string $raw, array $headers = array() ): array {
		$request = new WP_REST_Request( 'POST', '/mcp' );
		$request->set_body( $raw );
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

	/** @return array<string, mixed> */
	private function stdio_request( array $payload ): array {
		return $this->stdio_raw( (string) wp_json_encode( $payload ) );
	}

	/** @return array<string, mixed> */
	private function stdio_raw( string $raw_request ): array {
		$method = new \ReflectionMethod( $this->stdio, 'handle_request' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$raw  = $method->invoke( $this->stdio, $raw_request );
		$data = json_decode( (string) $raw, true );

		return is_array( $data ) ? $data : array();
	}

	/** Return the raw bridge output for one notification. */
	private function stdio_notification( array $payload ): string {
		$method = new \ReflectionMethod( $this->stdio, 'handle_request' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$result = $method->invoke( $this->stdio, (string) wp_json_encode( $payload ) );

		return is_string( $result ) ? $result : '';
	}
}
