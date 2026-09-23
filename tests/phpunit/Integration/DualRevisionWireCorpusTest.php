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
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\MCP\Transport\Infrastructure\SessionManager;
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

	/**
	 * Reject notifications without emitting JSON-RPC responses, while preserving request errors.
	 *
	 * @since 0.7.0
	 */
	public function test_http_notification_rejections_have_no_response_body(): void {
		$notification = array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' );
		$session_id   = $this->initialize_http_session();
		$headers      = array( 'Mcp-Session-Id' => $session_id, 'MCP-Protocol-Version' => Schemas::V2025_11_25 );

		try {
			$accepted = $this->http_post( $notification, $headers );
			$this->assertSame( 202, $accepted['status'] );
			$this->assertSame( 'null', $accepted['json'] );

			$cases = array(
				array( array( 'MCP-Protocol-Version' => Schemas::V2025_11_25 ), 400 ),
				array( array( 'Mcp-Session-Id' => 'missing-session', 'MCP-Protocol-Version' => Schemas::V2025_11_25 ), 404 ),
				array( array( 'Mcp-Session-Id' => $session_id, 'MCP-Protocol-Version' => '2025-06-18' ), 400 ),
			);
			foreach ( $cases as $case ) {
				$response = $this->http_post( $notification, $case[0] );
				$this->assertSame( $case[1], $response['status'] );
				$this->assertSame( 'null', $response['json'] );
			}

			$request = $this->http_post( $notification + array( 'id' => 7 ), array( 'MCP-Protocol-Version' => Schemas::V2025_11_25 ) );
			$this->assertSame( 400, $request['status'] );
			$this->assertSame( 7, $request['data']['id'] );
			$this->assertArrayHasKey( 'error', $request['data'] );

			$invalid = $this->http_post( array( 'jsonrpc' => '1.0', 'method' => 'notifications/initialized' ), $headers );
			$this->assertSame( 400, $invalid['status'] );
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $invalid['data']['error']['code'] );

			$malformed = $this->http_post_raw( '{"jsonrpc":' );
			$this->assertSame( 400, $malformed['status'] );
			$this->assertSame( McpErrorFactory::PARSE_ERROR, $malformed['data']['error']['code'] );
		} finally {
			SessionManager::delete_session( get_current_user_id(), $session_id );
		}
	}

	/**
	 * Keep modern notification rejection errors out of request-response schema hydration.
	 *
	 * @since 0.7.0
	 */
	public function test_modern_notification_validation_failures_are_empty_http_errors(): void {
		$notification = array(
			'jsonrpc' => '2.0',
			'method'  => 'notifications/example',
			'params'  => array(
				'_meta' => array(
					'io.modelcontextprotocol/protocolVersion' => Schemas::V2026_07_28,
					'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
				),
			),
		);
		$headers  = array( 'MCP-Protocol-Version' => Schemas::V2026_07_28 );
		$response = $this->http_post( $notification, $headers );
		$this->assertSame( 400, $response['status'] );
		$this->assertSame( 'null', $response['json'] );

		$headers['Mcp-Method'] = 'notifications/example';
		$notification['params']['_meta']['io.modelcontextprotocol/clientCapabilities'] = 'invalid';
		$response = $this->http_post( $notification, $headers );
		$this->assertSame( 400, $response['status'] );
		$this->assertSame( 'null', $response['json'] );
		$this->assertSame( '', $this->stdio_notification( $notification ) );
		$this->assertSame( '', $this->stdio_notification( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
	}

	/** Missing client capabilities must be reported as HTTP 400, not a successful response. */
	public function test_http_2026_missing_capability_returns_bad_request(): void {
		$tool = McpTool::fromArray(
			array(
				'name'       => 'request-form',
				'permission' => '__return_true',
				'handler'    => static function () {
					return new McpInputRequired(
						array(
							'confirm' => array(
								'method' => 'elicitation/create',
								'params' => array(
									'mode'            => 'form',
									'message'         => 'Continue?',
									'requestedSchema' => array(
										'type'       => 'object',
										'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
									),
								),
							),
						)
					);
				},
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server     = $this->makeServer( array( $tool ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );
		$response   = $this->http_request_2026_07_28(
			'tools/call',
			901,
			array(
				'name'      => 'request-form',
				'arguments' => new \stdClass(),
			)
		);
		$this->assertSame( 400, $response['status'] );
		$this->assertSame( McpErrorFactory::MISSING_CAPABILITY, $response['data']['error']['code'] );
		$this->assertArrayHasKey( 'form', $response['data']['error']['data']['requiredCapabilities']['elicitation'] );
	}

	/** A direct tool's input_required result crosses the REST boundary without creating a session. */
	public function test_http_2026_input_required_result_is_stateless(): void {
		$tool = McpTool::fromArray(
			array(
				'name'       => 'ask-first',
				'permission' => '__return_true',
				'handler'    => static function () {
					return new McpInputRequired(
						array(
							'confirm' => array(
								'method' => 'elicitation/create',
								'params' => array(
									'mode'            => 'form',
									'message'         => 'Continue?',
									'requestedSchema' => array(
										'type'       => 'object',
										'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
									),
								),
							),
						),
						'author-state'
					);
				},
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server     = $this->makeServer( array( $tool ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );
		$meta       = $this->meta_2026_07_28();
		$meta['io.modelcontextprotocol/clientCapabilities'] = array( 'elicitation' => new \stdClass() );
		$response = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 902,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'ask-first',
					'arguments' => new \stdClass(),
					'_meta'     => $meta,
				),
			),
			array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => 'tools/call',
				'Mcp-Name'             => 'ask-first',
			)
		);
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'input_required', $response['data']['result']['resultType'] );
		$this->assertSame( 'elicitation/create', $response['data']['result']['inputRequests']['confirm']['method'] );
		$this->assertSame( 'author-state', $response['data']['result']['requestState'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response['headers'] );
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

	/** Resource callbacks cannot consume unissued continuation answers. */
	public function test_http_2026_rejects_resource_input_responses(): void {
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

		$this->assertSame( 400, $response['status'] );
		$this->assertSame( -32602, $response['data']['error']['code'] );
		$this->assertNull( $received );
	}

	/** Unknown resources/read params reach neither permission, the pre-read filter, nor the handler. */
	public function test_http_resource_read_forwards_only_protocol_parameters(): void {
		$seen     = array();
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'test://params',
				'handler'    => static function ( array $params ) use ( &$seen ): string {
					$seen['handler'] = $params;
					return 'content';
				},
				'permission' => static function ( array $params ) use ( &$seen ): bool {
					$seen['permission'] = $params;
					return true;
				},
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$server     = $this->makeServer( array(), array( $resource ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );
		add_filter(
			'mcp_adapter_pre_resource_read',
			static function ( array $params ) use ( &$seen ): array {
				$seen['filter'] = $params;
				return $params;
			}
		);

		$modern = $this->http_request_2026_07_28(
			'resources/read',
			24,
			array(
				'uri'         => 'test://params',
				'customParam' => 'ignored',
			)
		);
		$this->assertSame( 200, $modern['status'] );
		$this->assertSame( 'content', $modern['data']['result']['contents'][0]['text'] );
		foreach ( array( 'permission', 'filter', 'handler' ) as $stage ) {
			$this->assertSame( 'test://params', $seen[ $stage ]['uri'], $stage );
			$this->assertArrayNotHasKey( 'customParam', $seen[ $stage ], $stage );
		}

		$seen   = array();
		$legacy = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 25,
				'method'  => 'resources/read',
				'params'  => array(
					'uri'         => 'test://params',
					'customParam' => 'ignored',
				),
			),
			array(
				'Mcp-Session-Id'       => $this->initialize_http_session(),
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 200, $legacy['status'] );
		$this->assertSame( 'content', $legacy['data']['result']['contents'][0]['text'] );
		foreach ( array( 'permission', 'filter', 'handler' ) as $stage ) {
			$this->assertSame( array( 'uri' => 'test://params' ), $seen[ $stage ], $stage );
		}
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

	/** List-shaped tool results keep structuredContent on 2026 and drop it on 2025, whose schema requires an object. */
	public function test_http_list_shaped_structured_content_is_revision_specific(): void {
		$state        = new \stdClass();
		$state->shape = array();
		$tool         = McpTool::fromArray(
			array(
				'name'        => 'list-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static function () use ( $state ) {
					return $state->shape;
				},
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server     = $this->makeServer( array( $tool ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );
		$session_id = $this->initialize_http_session();

		foreach ( array( array( 'a', 'b' ), array() ) as $list ) {
			$state->shape = $list;
			$expected     = (string) wp_json_encode( $list );

			$modern = $this->http_request_2026_07_28(
				'tools/call',
				26,
				array(
					'name'      => 'list-tool',
					'arguments' => new \stdClass(),
				)
			);
			$this->assertSame( 200, $modern['status'], $expected );
			$this->assertSame( $list, $modern['data']['result']['structuredContent'], $expected );
			$this->assertSame( $expected, $modern['data']['result']['content'][0]['text'], $expected );

			$legacy = $this->http_post(
				array(
					'jsonrpc' => '2.0',
					'id'      => 27,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'list-tool',
						'arguments' => new \stdClass(),
					),
				),
				array(
					'Mcp-Session-Id'       => $session_id,
					'MCP-Protocol-Version' => Schemas::V2025_11_25,
				)
			);
			$this->assertSame( 200, $legacy['status'], $expected );
			$this->assertArrayNotHasKey( 'structuredContent', $legacy['data']['result'], $expected );
			$this->assertSame( $expected, $legacy['data']['result']['content'][0]['text'], $expected );
			$this->assertFalse( $legacy['data']['result']['isError'], $expected );
		}
	}

	/** Persisted initialization data can be loaded without schema package classes. */
	public function test_http_session_persists_plain_client_data(): void {
		$params   = array(
			'protocolVersion' => Schemas::V2025_11_25,
			'capabilities'    => array(
				'roots'        => array( 'listChanged' => true ),
				'experimental' => array( 'fixture' => array( 'enabled' => true ) ),
			),
			'clientInfo'      => array(
				'name'    => 'portable-session',
				'version' => '1.0',
				'icons'   => array(
					array(
						'src'      => 'https://example.org/icon.png',
						'mimeType' => 'image/png',
					),
				),
			),
		);
		$response = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => $params,
			)
		);
		$this->assertSame( 200, $response['status'] );
		$session_id = $response['headers']['Mcp-Session-Id'];
		$sessions   = get_user_meta( get_current_user_id(), self::session_meta_key(), true );
		$this->assertSame( $params, $sessions[ $session_id ]['client_params'] );

		// Simulate a rollback where none of the new package classes can be loaded.
		$restored = unserialize( serialize( $sessions ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Trusted test fixture verifies the persistent PHP representation.
		$this->assertSame( $sessions, $restored );
		update_user_meta( get_current_user_id(), self::session_meta_key(), $restored );
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
		$this->assertArrayHasKey( 'result', $ping['data'] );
	}

	/** Callback serializers retain nested metadata and JSON identity on both wires. */
	public function test_http_tool_result_serializers_preserve_json_shapes(): void {
		$numeric_object        = new \stdClass();
		$numeric_object->{'0'} = 'zero';
		$numeric_object->{'1'} = 'one';
		$metadata              = array(
			'id'    => 12,
			'key'   => 'fixture',
			'value' => array(
				'empty'   => new \stdClass(),
				'list'    => array(),
				'null'    => null,
				'numeric' => $numeric_object,
			),
		);
		$fixtures              = array(
			array( 'meta_data' => array( $this->serializable_result( $metadata ) ) ),
			array( 'meta_data' => array( new \ArrayObject( $metadata ) ) ),
			new \ArrayObject( $metadata ),
			$this->serializable_result( new \stdClass() ),
			$this->serializable_result( $numeric_object ),
		);

		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			foreach ( $fixtures as $fixture ) {
				$response = $this->http_tool_result_fixture( $fixture, $version );
				$this->assertSame( 200, $response['status'] );
				$wire = json_decode( $response['json'] );
				$this->assertFalse( $wire->result->isError );
				$this->assertSame( wp_json_encode( $fixture ), $wire->result->content[0]->text );
				$this->assertSame( wp_json_encode( $fixture ), wp_json_encode( $wire->result->structuredContent ) );
			}
		}
	}

	/** Serialized resource objects preserve block and resource metadata ownership. */
	public function test_http_serialized_resource_object_projects_embedded_content(): void {
		$fixture = $this->serializable_result(
			(object) array(
				'type'     => 'resource',
				'_meta'    => (object) array( 'owner' => 'block' ),
				'resource' => (object) array(
					'uri'   => 'fixture://serialized-resource',
					'text'  => 'resource text',
					'_meta' => (object) array( 'owner' => 'resource' ),
				),
			)
		);

		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			$response = $this->http_tool_result_fixture( $fixture, $version );
			$this->assertSame( 200, $response['status'] );
			$content = $response['data']['result']['content'][0];
			$this->assertSame( 'resource', $content['type'] );
			$this->assertSame( 'fixture://serialized-resource', $content['resource']['uri'] );
			$this->assertSame( 'resource text', $content['resource']['text'] );
			$this->assertSame( 'block', $content['_meta']['owner'] );
			$this->assertSame( 'resource', $content['resource']['_meta']['owner'] );
		}
	}

	/** Image bytes are converted to base64 before any JSON encoding. */
	public function test_http_binary_image_results_preserve_content(): void {
		$bytes = "\x89PNG\r\n\x1a\n";
		$image = array(
			'type'     => 'image',
			'results'  => $bytes,
			'mimeType' => 'image/png',
		);

		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			foreach ( array( $image, $this->serializable_result( $image ) ) as $fixture ) {
				$response = $this->http_tool_result_fixture( $fixture, $version );
				$this->assertSame( 200, $response['status'] );
				$content = $response['data']['result']['content'][0];
				$this->assertSame( 'image', $content['type'] );
				$this->assertSame( 'image/png', $content['mimeType'] );
				$this->assertSame( $bytes, base64_decode( $content['data'], true ) );
			}
		}
	}

	/** Invalid callback values cannot be silently encoded as successful empty data. */
	public function test_http_tool_result_json_encoding_fails_closed(): void {
		$cycle        = new \stdClass();
		$cycle->value = $cycle;

		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			foreach ( array( INF, $this->serializable_result( INF ), $cycle ) as $fixture ) {
				$response = $this->http_tool_result_fixture( array( 'value' => $fixture ), $version );
				$this->assertSame( 500, $response['status'] );
				$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response['data']['error']['code'] );
				$this->assertArrayNotHasKey( 'result', $response['data'] );
			}
		}
	}

	/**
	 * Model a provider metadata object without depending on WooCommerce.
	 *
	 * @param mixed $value Serialized callback data.
	 * @return \JsonSerializable
	 */
	private function serializable_result( $value ): \JsonSerializable {
		return new class( $value ) implements \JsonSerializable {
			/** @var mixed */
			private $value;

			/** @param mixed $value Serialized callback data. */
			public function __construct( $value ) {
				$this->value = $value;
			}

			/** @return mixed */
			#[\ReturnTypeWillChange]
			public function jsonSerialize() {
				return $this->value;
			}
		};
	}

	/**
	 * Execute a callback result through exact HTTP result projection.
	 *
	 * @param mixed  $value Callback result.
	 * @param string $version Negotiated revision.
	 * @return array{status: int, data: array<string, mixed>, headers: array<string, string>, json: string}
	 */
	private function http_tool_result_fixture( $value, string $version ): array {
		$filter = static function () use ( $value ) {
			return $value;
		};
		add_filter( 'mcp_adapter_tool_call_result', $filter );
		try {
			$params = array(
				'name'      => 'test-always-allowed',
				'arguments' => new \stdClass(),
			);
			if ( Schemas::V2026_07_28 === $version ) {
				return $this->http_request_2026_07_28( 'tools/call', 42, $params );
			}

			return $this->http_post(
				array(
					'jsonrpc' => '2.0',
					'id'      => 42,
					'method'  => 'tools/call',
					'params'  => $params,
				),
				array(
					'Mcp-Session-Id'       => $this->initialize_http_session(),
					'MCP-Protocol-Version' => $version,
				)
			);
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $filter );
		}
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

	/** An x-mcp-header name containing "_" still finds its header after WordPress folds the name. */
	public function test_http_2026_matches_underscore_tool_parameter_headers(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'tenant-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'tenant' => array(
							'type'         => 'string',
							'x-mcp-header' => 'tenant_id',
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
			'id'      => 15,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'tenant-tool',
				'arguments' => array( 'tenant' => 'acme' ),
				'_meta'     => $this->meta_2026_07_28(),
			),
		);
		$headers = array(
			'MCP-Protocol-Version' => Schemas::V2026_07_28,
			'Mcp-Method'           => 'tools/call',
			'Mcp-Name'             => 'tenant-tool',
			'Mcp-Param-tenant_id'  => 'acme',
		);

		$valid = $this->http_post( $payload, $headers );
		$this->assertSame( 200, $valid['status'] );

		$headers['Mcp-Param-tenant_id'] = 'other';
		$mismatch                       = $this->http_post( $payload, $headers );
		$this->assertSame( 400, $mismatch['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $mismatch['data']['error']['code'] );

		unset( $headers['Mcp-Param-tenant_id'] );
		$missing = $this->http_post( $payload, $headers );
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $missing['data']['error']['code'] );
	}

	/** A stdClass properties node still declares header mirrors, so a missing header blocks execution. */
	public function test_http_2026_validates_parameter_headers_declared_in_stdclass_schema_nodes(): void {
		$executed = false;
		$tool     = McpTool::fromArray(
			array(
				'name'        => 'object-schema-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => (object) array(
						'region' => (object) array(
							'type'         => 'string',
							'x-mcp-header' => 'Region',
						),
					),
				),
				'handler'     => static function ( array $arguments ) use ( &$executed ): array {
					$executed = true;
					return $arguments;
				},
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server     = $this->makeServer( array( $tool ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );

		$params  = array(
			'name'      => 'object-schema-tool',
			'arguments' => array( 'region' => 'eu-west' ),
		);
		$missing = $this->http_request_2026_07_28( 'tools/call', 20, $params );
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $missing['data']['error']['code'] );
		$this->assertFalse( $executed );

		$valid = $this->http_request_2026_07_28( 'tools/call', 21, $params, array( 'Mcp-Param-Region' => 'eu-west' ) );
		$this->assertSame( 200, $valid['status'] );
		$this->assertTrue( $executed );
	}

	/** Integer-declared header mirrors compare numerically; other declarations compare as strings. */
	public function test_http_2026_compares_integer_parameter_headers_numerically(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'counting-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'count' => array(
							'type'         => 'integer',
							'x-mcp-header' => 'Count',
						),
						'label' => array(
							'type'         => 'string',
							'x-mcp-header' => 'Label',
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

		$headers = array(
			'MCP-Protocol-Version' => Schemas::V2026_07_28,
			'Mcp-Method'           => 'tools/call',
			'Mcp-Name'             => 'counting-tool',
		);
		$meta    = (string) wp_json_encode( $this->meta_2026_07_28() );
		$body    = static fn( string $count ): string => '{"jsonrpc":"2.0","id":22,"method":"tools/call","params":{"name":"counting-tool","arguments":{"count":' . $count . '},"_meta":' . $meta . '}}';

		foreach ( array( array( '42.0', '42' ), array( '42', '42.0' ), array( '42.0', '42.0' ), array( '-7', '-7' ) ) as [ $body_count, $header_count ] ) {
			$match = $this->http_post_raw( $body( $body_count ), array_merge( $headers, array( 'Mcp-Param-Count' => $header_count ) ) );
			$this->assertSame( 200, $match['status'], $body_count . ' vs ' . $header_count );
		}

		foreach ( array( '43', ' 42 ', '+42', '4.2e1', '' ) as $header_count ) {
			$mismatch = $this->http_post_raw( $body( '42' ), array_merge( $headers, array( 'Mcp-Param-Count' => $header_count ) ) );
			$this->assertSame( 400, $mismatch['status'], $header_count );
			$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $mismatch['data']['error']['code'], $header_count );
		}

		$oversized = $this->http_post_raw( $body( '9007199254740992' ), array_merge( $headers, array( 'Mcp-Param-Count' => '9007199254740992' ) ) );
		$this->assertSame( 400, $oversized['status'] );

		// A string declaration compares the body's decimal string, so a header 42.0 does not match a body 42.
		$label_body = static fn( string $label ): string => '{"jsonrpc":"2.0","id":23,"method":"tools/call","params":{"name":"counting-tool","arguments":{"label":' . $label . '},"_meta":' . $meta . '}}';
		$string_ok  = $this->http_post_raw( $label_body( '42' ), array_merge( $headers, array( 'Mcp-Param-Label' => '42' ) ) );
		$this->assertSame( 200, $string_ok['status'] );
		$string_bad = $this->http_post_raw( $label_body( '42' ), array_merge( $headers, array( 'Mcp-Param-Label' => '42.0' ) ) );
		$this->assertSame( 400, $string_bad['status'] );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $string_bad['data']['error']['code'] );
	}

	/** Names and URIs are looked up as sent, so a padded name cannot skip the Mcp-Param check. */
	public function test_http_padded_names_and_uris_are_not_trimmed(): void {
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
		$server     = $this->makeServer( array( $tool ), array( 'test/resource' ) );
		$this->http = new HttpRequestHandler( $server->create_transport_context() );

		$padded_name = ' regional-tool ';
		$padded_uri  = ' WordPress://local/resource-1 ';

		// Mcp-Name matches the padded body name, but no Mcp-Param-Region mirror is sent.
		// A trimmed lookup would run the tool without the mirror the specification requires.
		$bypass = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 16,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $padded_name,
					'arguments' => array( 'region' => 'eu-west' ),
					'_meta'     => $this->meta_2026_07_28(),
				),
			),
			array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => 'tools/call',
				'Mcp-Name'             => '=?base64?' . base64_encode( $padded_name ) . '?=', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required MCP header sentinel encoding.
			)
		);
		$this->assertArrayNotHasKey( 'result', $bypass['data'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $bypass['data']['error']['code'] );
		$this->assertStringContainsString( 'Tool not found', $bypass['data']['error']['message'] );

		$resource_2026 = $this->http_request_2026_07_28( 'resources/read', 17, array( 'uri' => $padded_uri ) );
		$this->assertArrayNotHasKey( 'result', $resource_2026['data'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $resource_2026['data']['error']['code'] );
		$this->assertSame( $padded_uri, $resource_2026['data']['error']['data']['uri'] );

		$legacy_headers = array(
			'Mcp-Session-Id'       => $this->initialize_http_session(),
			'MCP-Protocol-Version' => Schemas::V2025_11_25,
		);
		$call_2025      = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 18,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $padded_name,
					'arguments' => array( 'region' => 'eu-west' ),
				),
			),
			$legacy_headers
		);
		$this->assertArrayNotHasKey( 'result', $call_2025['data'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $call_2025['data']['error']['code'] );
		$this->assertStringContainsString( 'Tool not found', $call_2025['data']['error']['message'] );

		$resource_2025 = $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => 19,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => $padded_uri ),
			),
			$legacy_headers
		);
		$this->assertSame( 404, $resource_2025['status'] );
		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $resource_2025['data']['error']['code'] );
		$this->assertSame( $padded_uri, $resource_2025['data']['error']['data']['uri'] );
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

		// A header-selected 2026 request with absent or non-object params must reach the same
		// invalid-params error instead of failing while the metadata is read.
		$malformed_params = array(
			'absent' => array(
				'jsonrpc' => '2.0',
				'id'      => 33,
				'method'  => 'tools/list',
			),
			'null'   => array(
				'jsonrpc' => '2.0',
				'id'      => 34,
				'method'  => 'tools/list',
				'params'  => null,
			),
			'string' => array(
				'jsonrpc' => '2.0',
				'id'      => 35,
				'method'  => 'tools/list',
				'params'  => 'not-an-object',
			),
			'list'   => array(
				'jsonrpc' => '2.0',
				'id'      => 36,
				'method'  => 'tools/list',
				'params'  => array( 1, 2 ),
			),
		);
		foreach ( $malformed_params as $shape => $payload ) {
			$response = $this->http_post(
				$payload,
				array(
					'MCP-Protocol-Version' => Schemas::V2026_07_28,
					'Mcp-Method'           => 'tools/list',
				)
			);
			$this->assertSame( 400, $response['status'], "params shape: {$shape}" );
			$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['data']['error']['code'], "params shape: {$shape}" );
			$this->assertSame( $payload['id'], $response['data']['id'], "params shape: {$shape}" );
		}

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
		$this->assertSame( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS, $unsupported['data']['error']['data']['supported'] );
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

	/** An integral float id such as 1.0 is the integer id 1 on both transports and revisions. */
	public function test_http_and_stdio_accept_integral_float_request_ids(): void {
		$meta = (string) wp_json_encode( $this->meta_2026_07_28() );
		$http = $this->http_post_raw(
			'{"jsonrpc":"2.0","id":1.0,"method":"tools/list","params":{"_meta":' . $meta . '}}',
			array(
				'MCP-Protocol-Version' => Schemas::V2026_07_28,
				'Mcp-Method'           => 'tools/list',
			)
		);
		$this->assertSame( 200, $http['status'] );
		$this->assertSame( 1, $http['data']['id'] );
		$this->assertStringContainsString( '"id":1,', $http['json'] );

		$stdio = $this->stdio_raw( '{"jsonrpc":"2.0","id":7.0,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wire-test","version":"1.0"}}}' );
		$this->assertSame( 7, $stdio['id'] );
		$this->assertSame( Schemas::V2025_11_25, $stdio['result']['protocolVersion'] );

		// Session preflight errors echo the normalized id too.
		$missing_session = $this->http_post_raw(
			'{"jsonrpc":"2.0","id":3.0,"method":"tools/list"}',
			array( 'MCP-Protocol-Version' => Schemas::V2025_11_25 )
		);
		$this->assertSame( 3, $missing_session['data']['id'] );
		$this->assertArrayHasKey( 'error', $missing_session['data'] );
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

	/** Exhausted session update retries fail initialize and reach the configured error handler. */
	public function test_http_initialize_reports_exhausted_session_update_retries(): void {
		$attempts = 0;
		$meta_key = self::session_meta_key();
		$block    = static function ( $check, $object_id, $key ) use ( &$attempts, $meta_key ) {
			if ( $meta_key !== $key ) {
				return $check;
			}
			++$attempts;

			return false;
		};
		add_filter( 'update_user_metadata', $block, 10, 3 );
		try {
			$response = $this->http_post( $this->initialize_payload( 79, Schemas::V2025_11_25 ) );
		} finally {
			remove_filter( 'update_user_metadata', $block, 10 );
		}

		$this->assertSame( 500, $response['status'] );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response['data']['error']['code'] );
		$this->assertSame( 79, $response['data']['id'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response['headers'] );
		$this->assertSame( 5, $attempts );

		$logs = array_values(
			array_filter(
				DummyErrorHandler::$logs,
				static fn( array $log ): bool => 'Failed to persist MCP sessions after exhausting update retries.' === $log['message']
			)
		);
		$this->assertCount( 1, $logs );
		$this->assertSame(
			array(
				'component' => SessionManager::class,
				'method'    => 'mutate_sessions',
				'user_id'   => 1,
				'attempts'  => 5,
			),
			$logs[0]['context']
		);
	}

	/**
	 * Content metadata reaches final schema projection without changing its shape.
	 *
	 * @dataProvider tool_content_metadata_provider
	 * @param array<string, mixed> $shape Tool result shorthand.
	 * @param bool $resource_metadata Whether metadata belongs to resource contents.
	 * @since 0.7.0
	 */
	public function test_http_tool_content_metadata_uses_schema_validation( array $shape, bool $resource_metadata ): void {
		$values = array(
			'string'         => array( 'bad', false ),
			'boolean'        => array( false, false ),
			'number'         => array( 0, false ),
			'list'           => array( array( 'bad' ), false ),
			'array'          => array( array( 'vendor' => true ), true ),
			'numeric object' => array( (object) array( '0' => 'keep' ), true ),
			'null'           => array( null, true ),
		);

		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			foreach ( $values as $label => list( $meta, $valid ) ) {
				$result = $shape;
				if ( $resource_metadata && isset( $result['resource'] ) ) {
					$result['resource']['_meta'] = $meta;
				} else {
					$result['_meta'] = $meta;
				}
				DummyErrorHandler::reset();
				DummyObservabilityHandler::reset();
				$before   = wp_json_encode( $result );
				$response = $this->http_tool_result_fixture( $result, $version );
				$context  = $version . ' ' . $label;
				$this->assertSame( $before, wp_json_encode( $result ), $context );
				$events = array_values( array_filter( DummyObservabilityHandler::$events, static fn( array $event ): bool => 'mcp.request' === $event['event'] && 'tools/call' === $event['tags']['method'] ) );
				$this->assertCount( 1, $events, $context );
				$tags = $events[0]['tags'];
				$this->assertSame( $valid ? 'success' : 'error', $tags['status'], $context );
				$this->assertSame( $version, $tags['revision'], $context );
				$this->assertSame( 42, $tags['request_id'], $context );
				$this->assertSame( 'test-always-allowed', $tags['tool_name'], $context );
				$this->assertNotNull( $events[0]['duration_ms'], $context );


				if ( ! $valid ) {
					$this->assertSame( 500, $response['status'], $context );
					$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response['data']['error']['code'], $context );
					$this->assertSame( 'Internal error: The server produced an invalid result.', $response['data']['error']['message'], $context );
					$this->assertArrayNotHasKey( 'result', $response['data'], $context );
					$this->assertSame( 'invalid_handler_result', $tags['failure_reason'], $context );
					$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $tags['error_code'], $context );
					$this->assertCount( 1, DummyErrorHandler::$logs, $context );
					$log = DummyErrorHandler::$logs[0];
					$this->assertSame( 'Invalid handler result', $log['message'], $context );
					$this->assertSame( 'error', $log['type'], $context );
					$this->assertSame( '/content/0', $log['context']['schema_pointer'], $context );
					$this->assertStringContainsString( 'Value does not match any allowed union member', $log['context']['exception_message'], $context );
					$this->assertSame( $tags, array_intersect_key( $log['context'], $tags ), $context );
					$this->assertArrayNotHasKey( 'result', $log['context'], $context );
					$this->assertArrayNotHasKey( 'arguments', $log['context'], $context );

					continue;
				}

				$this->assertSame( array(), DummyErrorHandler::$logs, $context );
				$this->assertSame( 200, $response['status'], $context );
				$wire    = json_decode( $response['json'], false, 512, JSON_THROW_ON_ERROR );
				$content = $wire->result->content[0];
				$owner   = $resource_metadata ? $content->resource : $content;
				$this->assertFalse( $wire->result->isError, $context );
				if ( null === $meta ) {
					$this->assertObjectNotHasProperty( '_meta', $owner, $context );
					continue;
				}
				$this->assertInstanceOf( \stdClass::class, $owner->_meta, $context );
				$this->assertSame( wp_json_encode( $meta ), wp_json_encode( $owner->_meta ), $context );
			}
		}
	}

	/**
	 * Every image and embedded-resource metadata position accepted by tool handlers.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 * @since 0.7.0
	 */
	public static function tool_content_metadata_provider(): array {
		$cases = array(
			'image' => array( array( 'type' => 'image', 'results' => 'image bytes', 'mimeType' => 'image/png' ), false ),
		);
		foreach ( array( 'text' => 'body', 'blob' => 'Ym9keQ==' ) as $field => $value ) {
			$resource                               = array( 'uri' => 'fixture://metadata', $field => $value );
			$nested                                 = array( 'type' => 'resource', 'resource' => $resource );
			$cases[ 'flat ' . $field ]               = array( array( 'type' => 'resource' ) + $resource, true );
			$cases[ 'nested ' . $field . ' block' ]    = array( $nested, false );
			$cases[ 'nested ' . $field . ' resource' ] = array( $nested, true );
		}

		return $cases;
	}

	/** Server capability containers serialize as JSON objects in initialize and server/discover. */
	public function test_http_capability_objects_serialize_as_json_objects(): void {
		$initialize = $this->http_post( $this->initialize_payload( 80, Schemas::V2025_11_25 ) );
		$discover   = $this->http_request_2026_07_28( 'server/discover', 81, array() );
		$this->assertSame( 200, $initialize['status'] );
		$this->assertSame( 200, $discover['status'] );

		foreach ( array( $initialize['json'], $discover['json'] ) as $json ) {
			$decoded      = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
			$capabilities = $decoded->result->capabilities;
			$this->assertInstanceOf( \stdClass::class, $capabilities );
			foreach ( array( 'prompts', 'resources', 'tools' ) as $capability ) {
				$this->assertInstanceOf( \stdClass::class, $capabilities->{$capability}, $capability );
				$this->assertFalse( $capabilities->{$capability}->listChanged, $capability );
			}
		}

		$initialize_capabilities = json_decode( $initialize['json'], false, 512, JSON_THROW_ON_ERROR )->result->capabilities;
		$this->assertFalse( $initialize_capabilities->resources->subscribe );
	}

	/** Ability callback exceptions become isError tool results and internal protocol errors on both revisions. */
	public function test_http_ability_callback_exceptions_map_to_result_and_protocol_errors(): void {
		$throwing = static function (): void {
			throw new \RuntimeException( 'Execute exception' );
		};
		$this->register_ability_in_hook(
			'test/resource-execute-exception',
			array(
				'label'               => 'Resource execute exception',
				'description'         => 'Throws in execute',
				'category'            => 'test',
				'execute_callback'    => $throwing,
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://test/resource-exception',
					),
				),
			)
		);
		$this->register_ability_in_hook(
			'test/prompt-execute-exception',
			array(
				'label'               => 'Prompt execute exception',
				'description'         => 'Throws in execute',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'input' => array( 'type' => 'string' ) ),
				),
				'execute_callback'    => $throwing,
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		try {
			$server     = $this->makeServer(
				array( 'test/execute-exception', 'test/permission-exception' ),
				array( 'test/resource-execute-exception' ),
				array( 'test/prompt-execute-exception' )
			);
			$this->http = new HttpRequestHandler( $server->create_transport_context() );

			foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
				$session_id = Schemas::V2025_11_25 === $version ? $this->initialize_http_session() : null;
				$arguments  = array( 'arguments' => new \stdClass() );

				$execute = $this->http_request_for( $version, 'tools/call', 82, array( 'name' => 'test-execute-exception' ) + $arguments, $session_id );
				$this->assertSame( 200, $execute['status'], $version );
				$this->assertTrue( $execute['data']['result']['isError'], $version );
				$this->assertSame( 'text', $execute['data']['result']['content'][0]['type'], $version );
				$this->assertStringContainsString( 'boom', $execute['data']['result']['content'][0]['text'], $version );

				$permission = $this->http_request_for( $version, 'tools/call', 83, array( 'name' => 'test-permission-exception' ) + $arguments, $session_id );
				$this->assertSame( 200, $permission['status'], $version );
				$this->assertTrue( $permission['data']['result']['isError'], $version );
				$this->assertStringContainsString( 'nope', $permission['data']['result']['content'][0]['text'], $version );

				$resource = $this->http_request_for( $version, 'resources/read', 84, array( 'uri' => 'WordPress://test/resource-exception' ), $session_id );
				$this->assertSame( 500, $resource['status'], $version );
				$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $resource['data']['error']['code'], $version );
				$this->assertStringContainsString( 'Execute exception', $resource['data']['error']['message'], $version );

				$prompt = $this->http_request_for(
					$version,
					'prompts/get',
					85,
					array(
						'name'      => 'test-prompt-execute-exception',
						'arguments' => array( 'input' => 'x' ),
					),
					$session_id
				);
				$this->assertSame( 500, $prompt['status'], $version );
				$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $prompt['data']['error']['code'], $version );
				$this->assertStringContainsString( 'Execute exception', $prompt['data']['error']['message'], $version );
			}
		} finally {
			wp_unregister_ability( 'test/resource-execute-exception' );
			wp_unregister_ability( 'test/prompt-execute-exception' );
		}
	}

	/** Modern requests ignore 2025 session headers. */
	public function test_http_2026_ignores_session_headers(): void {
		$valid = $this->http_request_2026_07_28(
			'tools/list',
			79,
			array(),
			array(
				'Mcp-Session-Id' => 'ignored',
				'Last-Event-ID'  => 'ignored',
			)
		);
		$this->assertSame( 200, $valid['status'] );
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

	/** A 2025-06-18 proposal is echoed, served by the 2025-11-25 schema, and bound to its own header value. */
	public function test_http_legacy_2025_06_18_session_echoes_identifier_and_requires_matching_header(): void {
		$initialize = $this->http_post( $this->initialize_payload( 'init-legacy', '2025-06-18' ) );
		$this->assertSame( 200, $initialize['status'] );
		$this->assertSame( '2025-06-18', $initialize['data']['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'Mcp-Session-Id', $initialize['headers'] );
		$session_id = $initialize['headers']['Mcp-Session-Id'];

		$list = $this->http_post(
			$this->list_payload( 90 ),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => '2025-06-18',
			)
		);
		$this->assertSame( 200, $list['status'] );
		$this->assertSame( 'test-always-allowed', $list['data']['result']['tools'][0]['name'] );
		$this->assertArrayNotHasKey( 'resultType', $list['data']['result'] );

		$mismatch = $this->http_post(
			$this->list_payload( 91 ),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 400, $mismatch['status'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $mismatch['data']['error']['code'] );
		$this->assertSame( 91, $mismatch['data']['id'] );

		$missing = $this->http_post( $this->list_payload( 92 ), array( 'Mcp-Session-Id' => $session_id ) );
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $missing['data']['error']['code'] );

		$delete = new WP_REST_Request( 'DELETE', '/mcp' );
		$delete->set_header( 'MCP-Protocol-Version', '2025-06-18' );
		$delete->set_header( 'Mcp-Session-Id', $session_id );
		$delete_response = $this->http->handle_request( new HttpRequestContext( $delete ) );
		$this->assertSame( 200, $delete_response->get_status() );
	}

	/** A 2025-11-25 session requires the MCP-Protocol-Version header on every request after initialize. */
	public function test_http_2025_11_25_session_requires_protocol_version_header(): void {
		$session_id = $this->initialize_http_session();

		$missing = $this->http_post( $this->list_payload( 93 ), array( 'Mcp-Session-Id' => $session_id ) );
		$this->assertSame( 400, $missing['status'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $missing['data']['error']['code'] );
		$this->assertSame( 93, $missing['data']['id'] );
		$this->assertStringContainsString( 'required for a 2025-11-25 session', $missing['data']['error']['message'] );

		$present = $this->http_post(
			$this->list_payload( 94 ),
			array(
				'Mcp-Session-Id'       => $session_id,
				'MCP-Protocol-Version' => Schemas::V2025_11_25,
			)
		);
		$this->assertSame( 200, $present['status'] );
	}

	/** Identifiers that predate the header may omit it, but a sent header must still match. */
	public function test_http_legacy_pre_header_sessions_may_omit_protocol_version_header(): void {
		foreach ( array( '2024-11-05' ) as $legacy ) {
			$initialize = $this->http_post( $this->initialize_payload( 'init-' . $legacy, $legacy ) );
			$this->assertSame( 200, $initialize['status'] );
			$this->assertSame( $legacy, $initialize['data']['result']['protocolVersion'] );
			$session_id = $initialize['headers']['Mcp-Session-Id'];

			$without_header = $this->http_post( $this->list_payload( 93 ), array( 'Mcp-Session-Id' => $session_id ) );
			$this->assertSame( 200, $without_header['status'], $legacy );
			$this->assertSame( 'test-always-allowed', $without_header['data']['result']['tools'][0]['name'] );

			$with_header = $this->http_post(
				$this->list_payload( 94 ),
				array(
					'Mcp-Session-Id'       => $session_id,
					'MCP-Protocol-Version' => $legacy,
				)
			);
			$this->assertSame( 200, $with_header['status'], $legacy );

			$mismatch = $this->http_post(
				$this->list_payload( 95 ),
				array(
					'Mcp-Session-Id'       => $session_id,
					'MCP-Protocol-Version' => Schemas::V2025_11_25,
				)
			);
			$this->assertSame( 400, $mismatch['status'], $legacy );
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $mismatch['data']['error']['code'] );
		}
	}

	/** Unknown proposals, and 2025-03-26 whose batching requirement is not met, receive the exact 2025-11-25 counter-proposal. */
	public function test_http_unknown_initialize_proposal_receives_2025_11_25(): void {
		foreach ( array( '2099-01-01', '2025-03-26' ) as $proposed ) {
			$initialize = $this->http_post( $this->initialize_payload( 'init-' . $proposed, $proposed ) );
			$this->assertSame( 200, $initialize['status'], $proposed );
			$this->assertSame( Schemas::V2025_11_25, $initialize['data']['result']['protocolVersion'], $proposed );
		}
	}

	/** A legacy header on a request without a session is not an unsupported version. */
	public function test_http_legacy_header_without_session_requires_initialization(): void {
		$response = $this->http_post( $this->list_payload( 96 ), array( 'MCP-Protocol-Version' => '2025-06-18' ) );
		$this->assertSame( 400, $response['status'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['data']['error']['code'] );
		$this->assertStringContainsString( 'Mcp-Session-Id', $response['data']['error']['message'] );
	}

	/** STDIO echoes a legacy proposal and serves the session through the 2025 schema. */
	public function test_stdio_legacy_initialize_echoes_identifier(): void {
		$initialize = $this->stdio_request( $this->initialize_payload( 97, '2025-06-18' ) );
		$this->assertSame( '2025-06-18', $initialize['result']['protocolVersion'] );
		$this->assertSame(
			'',
			$this->stdio_notification(
				array(
					'jsonrpc' => '2.0',
					'method'  => 'notifications/initialized',
				)
			)
		);

		$list = $this->stdio_request( $this->list_payload( 98 ) );
		$this->assertSame( 'test-always-allowed', $list['result']['tools'][0]['name'] );
		$this->assertArrayNotHasKey( 'resultType', $list['result'] );
	}

	/**
	 * Build one 2025-style initialize payload.
	 *
	 * @param string|int $id Request ID.
	 * @return array<string, mixed>
	 */
	private function initialize_payload( $id, string $protocol_version ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => $protocol_version,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'legacy-test',
					'version' => '1.0',
				),
			),
		);
	}

	/** @return array<string, mixed> */
	private function list_payload( int $id ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => 'tools/list',
		);
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

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>, json: string} */
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

	/**
	 * Send one request under either revision.
	 *
	 * @return array{status: int, data: array<string, mixed>, headers: array<string, string>, json: string}
	 */
	private function http_request_for( string $version, string $method, int $id, array $params, ?string $session_id ): array {
		if ( Schemas::V2026_07_28 === $version ) {
			return $this->http_request_2026_07_28( $method, $id, $params );
		}

		return $this->http_post(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			),
			array(
				'Mcp-Session-Id'       => (string) $session_id,
				'MCP-Protocol-Version' => $version,
			)
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

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>, json: string} */
	private function http_post( array $payload, array $headers = array() ): array {
		return $this->http_post_raw( (string) wp_json_encode( $payload ), $headers );
	}

	/** @return array{status: int, data: array<string, mixed>, headers: array<string, string>, json: string} */
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
			'json'    => (string) wp_json_encode( $response->get_data() ),
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
