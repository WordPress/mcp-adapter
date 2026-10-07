<?php
/**
 * Observability at the final response boundary.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\MCP\Transport\Infrastructure\RequestRouter;
use WP\McpSchema\Record;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Schemas;

/** @since 0.7.0 */
final class McpWireObservabilityTest extends TestCase {

	/** Completed results, execution errors, and projection errors each emit one final event. */
	public function test_final_outcomes_preserve_error_semantics(): void {
		$tool     = McpTool::fromArray(
			array(
				'name'       => 'ok',
				'handler'    => static fn(): array => array( 'done' => true ),
				'permission' => '__return_true',
			)
		);
		$prompt   = McpPrompt::fromArray(
			array(
				'name'       => 'bad',
				'handler'    => static fn(): array => array( 'text' => 5 ),
				'permission' => '__return_true',
			)
		);
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'fixture://bad',
				'handler'    => static fn(): array => array(
					array(
						'text'  => 'body',
						'_meta' => 'bad',
					),
				),
				'permission' => '__return_true',
			)
		);
		$server   = $this->makeServer( array( $tool, 'test/permission-denied' ), array( $resource ), array( $prompt ) );
		$wire     = new McpWireOrchestrator( $server->create_transport_context() );

		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $revision ) {
			$cases = array(
				array( 'tools/call', array( 'name' => 'ok' ), 'success', false ),
				array( 'tools/call', array( 'name' => 'test-permission-denied' ), 'error', false ),
				array( 'tools/call', array( 'name' => 'missing' ), 'error', false ),
				array( 'prompts/get', array( 'name' => 'bad' ), 'error', true ),
				array( 'resources/read', array( 'uri' => 'fixture://bad' ), 'error', true ),
			);
			if ( Schemas::V2026_07_28 === $revision ) {
				$cases[] = array( 'server/discover', array(), 'success', false );
			}
			foreach ( $cases as [ $method, $params, $status, $projection_failed ] ) {
				DummyObservabilityHandler::reset();
				DummyErrorHandler::reset();
				$response = $this->process( $wire, $revision, $method, $params );
				$this->assertCount( 1, DummyObservabilityHandler::$events );
				$event = DummyObservabilityHandler::$events[0];
				$this->assertSame( 'mcp.request', $event['event'] );
				$this->assertSame( $status, $event['tags']['status'], $revision . ' ' . $method . ' ' . wp_json_encode( $params ) );
				$this->assertSame( $revision, $event['tags']['revision'] );
				$this->assertSame( $method, $event['tags']['method'] );
				$this->assertSame( 'STDIO', $event['tags']['transport'] );
				$this->assertSame( 'srv', $event['tags']['server_id'] );
				$this->assertSame( 19, $event['tags']['request_id'] );
				$this->assertGreaterThanOrEqual( 0, $event['duration_ms'] );
				if ( $projection_failed ) {
					$this->assertSame( 'invalid_handler_result', $event['tags']['failure_reason'] );
					$this->assertSame( -32603, $event['tags']['error_code'] );
					$this->assertSame( 'validation', $event['tags']['error_category'] );
					$this->assertSame( 'Internal error: The server produced an invalid result.', $response['error']['message'] );
					$this->assertCount( 1, DummyErrorHandler::$logs );
					$this->assertArrayHasKey( 'schema_pointer', DummyErrorHandler::$logs[0]['context'] );
				} elseif ( 'test-permission-denied' === ( $params['name'] ?? null ) ) {
					$this->assertTrue( $response['result']['isError'] );
					$this->assertNotEmpty( $event['tags']['failure_reason'] );
				} elseif ( 'missing' === ( $params['name'] ?? null ) ) {
					$this->assertSame( -32602, $event['tags']['error_code'] );
				} else {
					$this->assertSame( array(), DummyErrorHandler::$logs );
				}
			}
		}
	}

	/** Nested requests do not consume the outer completion or publish its status early. */
	public function test_nested_requests_have_independent_completion_events(): void {
		$wire     = null;
		$revision = Schemas::V2026_07_28;
		$inner    = McpTool::fromArray(
			array(
				'name'       => 'inner',
				'handler'    => static fn(): array => array( 'done' => true ),
				'permission' => '__return_true',
			)
		);
		$outer    = McpTool::fromArray(
			array(
				'name'       => 'outer',
				'permission' => '__return_true',
				'handler'    => function () use ( &$wire, $revision ): array {
					$this->assertSame( array(), DummyObservabilityHandler::$events );
					$this->process( $wire, $revision, 'tools/call', array( 'name' => 'inner' ), 20 );
					$this->assertCount( 1, DummyObservabilityHandler::$events );
					return array(
						'type'    => 'image',
						'results' => 'bytes',
						'_meta'   => 'bad',
					);
				},
			)
		);
		$server   = $this->makeServer( array( $inner, $outer ) );
		$wire     = new McpWireOrchestrator( $server->create_transport_context() );
		DummyObservabilityHandler::reset();
		$this->process( $wire, $revision, 'tools/call', array( 'name' => 'outer' ) );
		$this->assertCount( 2, DummyObservabilityHandler::$events );
		$events = DummyObservabilityHandler::$events;
		$this->assertSame( 'inner', $events[0]['tags']['tool_name'] );
		$this->assertSame( 'success', $events[0]['tags']['status'] );
		$this->assertSame( 20, $events[0]['tags']['request_id'] );
		$this->assertSame( 'outer', $events[1]['tags']['tool_name'] );
		$this->assertSame( 'error', $events[1]['tags']['status'] );
		$this->assertSame( 19, $events[1]['tags']['request_id'] );
		$this->assertGreaterThanOrEqual( $events[0]['duration_ms'], $events[1]['duration_ms'] );
	}

	/** Failing integration telemetry cannot replace the original projection error. */
	public function test_telemetry_exceptions_do_not_change_the_client_error(): void {
		$tool                           = McpTool::fromArray(
			array(
				'name'       => 'bad',
				'handler'    => static fn(): array => array(
					'type'    => 'image',
					'results' => 'bytes',
					'_meta'   => 'bad',
				),
				'permission' => '__return_true',
			)
		);
		$server                         = $this->makeServer( array( $tool ) );
		$context                        = $server->create_transport_context();
		$logger                         = new class() implements McpErrorHandlerInterface {
			public int $calls = 0;
			public function log( string $message, array $context = array(), string $type = 'error' ): void {
				++$this->calls;
				throw new \RuntimeException( 'Logger unavailable' );
			}
		};
		$observer                       = new class() implements McpObservabilityHandlerInterface {
			public int $calls = 0;
			public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
				++$this->calls;
				throw new \RuntimeException( 'Telemetry unavailable' );
			}
		};
		$context->error_handler         = $logger;
		$context->observability_handler = $observer;
		$wire                           = new McpWireOrchestrator( $context );
		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $revision ) {
			$response = $this->process( $wire, $revision, 'tools/call', array( 'name' => 'bad' ) );
			$this->assertSame( -32603, $response['error']['code'] );
			$this->assertSame( 'Internal error: The server produced an invalid result.', $response['error']['message'] );
			$this->assertArrayNotHasKey( 'data', $response['error'] );
		}
		$this->assertSame( 2, $logger->calls );
		$this->assertSame( 2, $observer->calls );
	}

	/** Injected routers may return their own result or modify the parent's logical result. */
	public function test_injected_router_overrides_keep_logical_results(): void {
		$tool                    = McpTool::fromArray(
			array(
				'name'       => 'ok',
				'handler'    => static fn(): array => array( 'done' => true ),
				'permission' => '__return_true',
			)
		);
		$server                  = $this->makeServer( array( $tool ) );
		$context                 = $server->create_transport_context();
		$router                  = new class( $context ) extends RequestRouter {
			public bool $delegate          = false;
			public bool $route_other_first = false;
			public int $calls              = 0;
			public function route_request( Record $request, McpRequestContext $request_context, string $transport_name = 'unknown' ) {
				++$this->calls;
				if ( ! $this->delegate ) {
					return array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'custom',
							),
						),
					);
				}
				if ( $this->route_other_first ) {
					$other = $request_context->schema()->fromArray(
						CallToolRequest::class,
						array(
							'jsonrpc' => '2.0',
							'id'      => 20,
							'method'  => 'tools/call',
							'params'  => array( 'name' => 'ok' ),
						)
					);
					parent::route_request( $other, $request_context, $transport_name );
				}
				$result = parent::route_request( $request, $request_context, $transport_name );
				if ( ! is_array( $result ) ) {
					throw new \LogicException( 'Overrides must receive logical handler results.' );
				}
				$result['content'][0]['_meta'] = 'bad';
				return $result;
			}
		};
		$context->request_router = $router;
		$wire                    = new McpWireOrchestrator( $context );
		foreach ( array( false, true ) as $delegate ) {
			$router->delegate = $delegate;
			DummyObservabilityHandler::reset();
			DummyErrorHandler::reset();
			$response = $this->process( $wire, Schemas::V2025_11_25, 'tools/call', array( 'name' => 'ok' ) );
			$this->assertCount( 1, DummyObservabilityHandler::$events );
			if ( $delegate ) {
				$this->assertSame( 'Internal error: The server produced an invalid result.', $response['error']['message'] );
				$this->assertSame( 'invalid_handler_result', DummyObservabilityHandler::$events[0]['tags']['failure_reason'] );
			} else {
				$this->assertSame( 'custom', $response['result']['content'][0]['text'] );
				$this->assertSame( 'success', DummyObservabilityHandler::$events[0]['tags']['status'] );
			}
		}
		$this->assertSame( 2, $router->calls );

		$router->route_other_first = true;
		DummyObservabilityHandler::reset();
		$this->process( $wire, Schemas::V2025_11_25, 'tools/call', array( 'name' => 'ok' ) );
		$this->assertCount( 2, DummyObservabilityHandler::$events );
		$this->assertSame( 20, DummyObservabilityHandler::$events[0]['tags']['request_id'] );
		$this->assertSame( 'success', DummyObservabilityHandler::$events[0]['tags']['status'] );
		$this->assertSame( 19, DummyObservabilityHandler::$events[1]['tags']['request_id'] );
		$this->assertSame( 'error', DummyObservabilityHandler::$events[1]['tags']['status'] );
		$router->route_other_first = false;

		// The completed scope cannot suppress or project a later direct routing call.
		DummyObservabilityHandler::reset();
		$request_context = $this->request_context( $server );
		$request         = $request_context->schema()->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 30,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'ok' ),
			)
		);
		$result          = $router->route_request( $request, $request_context );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertCount( 1, DummyObservabilityHandler::$events );
	}

	/** Discovery bypasses custom logical routers while retaining final telemetry. */
	public function test_discovery_bypasses_injected_router(): void {
		$server = $this->makeServer();
		$context = $server->create_transport_context();
		$router = new class( $context ) extends RequestRouter {
			public int $calls = 0;
			public function route_request( Record $request, McpRequestContext $request_context, string $transport_name = 'unknown' ) {
				++$this->calls;
				throw new \LogicException( 'Discovery must bypass custom routing.' );
			}
		};
		$context->request_router = $router;
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();
		$response = $this->process( new McpWireOrchestrator( $context ), Schemas::V2026_07_28, 'server/discover', array() );
		$this->assertArrayHasKey( 'supportedVersions', $response['result'] );
		$this->assertSame( 0, $router->calls );
		$this->assertCount( 1, DummyObservabilityHandler::$events );
		$this->assertSame( 'success', DummyObservabilityHandler::$events[0]['tags']['status'] );
		$this->assertSame( 'server/discover', DummyObservabilityHandler::$events[0]['tags']['method'] );
	}

	/** @return array<string, mixed> */
	private function process( McpWireOrchestrator $wire, string $revision, string $method, array $params, int $id = 19 ): array {
		if ( Schemas::V2026_07_28 === $revision ) {
			$params['_meta'] = array(
				'io.modelcontextprotocol/protocolVersion' => $revision,
				'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
			);
		}
		$message = json_decode(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);
		$outcome = $wire->process( $message, 'STDIO', array(), array( 'capabilities' => array() ) );
		return json_decode( wp_json_encode( $outcome['response'] ), true );
	}
}
