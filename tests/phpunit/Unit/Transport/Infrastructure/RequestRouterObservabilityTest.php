<?php
/**
 * Request router security and observability contracts.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Schemas;

/** Proves that request metrics remain useful without exposing argument values. */
final class RequestRouterObservabilityTest extends TestCase {

	/** Successful calls record component context and redact sensitive argument keys. */
	public function test_success_metrics_include_context_without_argument_values(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'observability-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'visible' => array( 'type' => 'string' ),
						'api_key' => array( 'type' => 'string' ),
					),
				),
				'handler'     => static fn( array $arguments ): array => $arguments,
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server            = $this->makeServer( array( $tool ) );
		$transport_context = $server->create_transport_context();
		$schema            = $server->get_schema_provider()->for_revision( Schemas::V2025_11_25 );
		$request            = $schema->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 'request-1',
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'observability-tool',
					'arguments' => array(
						'visible' => 'public value',
						'api_key' => 'secret value',
					),
				),
			)
		);
		$context            = new McpRequestContext(
			Schemas::V2025_11_25,
			$schema,
			new \stdClass(),
			null,
			'HTTP',
			array( 'session_id' => 'session-1' )
		);

		$transport_context->request_router->route_request( $request, $context, 'HTTP' );

		$this->assertCount( 1, DummyObservabilityHandler::$events );
		$event = DummyObservabilityHandler::$events[0];
		$this->assertSame( 'mcp.request', $event['event'] );
		$this->assertSame( 'success', $event['tags']['status'] );
		$this->assertSame( 'tools/call', $event['tags']['method'] );
		$this->assertSame( 'HTTP', $event['tags']['transport'] );
		$this->assertSame( Schemas::V2025_11_25, $event['tags']['revision'] );
		$this->assertSame( 'session-1', $event['tags']['session_id'] );
		$this->assertSame( 'tool', $event['tags']['component_type'] );
		$this->assertSame( 'observability-tool', $event['tags']['tool_name'] );
		$this->assertSame( 2, $event['tags']['params']['arguments_count'] );
		$this->assertSame( array( 'visible', '[REDACTED]' ), $event['tags']['params']['arguments_keys'] );
		$this->assertStringNotContainsString( 'public value', wp_json_encode( $event['tags'] ) );
		$this->assertStringNotContainsString( 'secret value', wp_json_encode( $event['tags'] ) );
		$this->assertNotNull( $event['duration_ms'] );
	}

	/** Tool execution failures and protocol failures are both recorded as errors. */
	public function test_error_metrics_distinguish_execution_and_protocol_failures(): void {
		$server            = $this->makeServer( array( 'test/permission-denied' ) );
		$transport_context = $server->create_transport_context();
		$schema            = $server->get_schema_provider()->for_revision( Schemas::V2025_11_25 );
		$context           = new McpRequestContext( Schemas::V2025_11_25, $schema, new \stdClass(), null, 'STDIO' );
		$denied            = $schema->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'test-permission-denied', 'arguments' => array() ),
			)
		);
		$missing           = $schema->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'missing-tool', 'arguments' => array() ),
			)
		);

		$denied_result  = $transport_context->request_router->route_request( $denied, $context, 'STDIO' );
		$missing_result = $transport_context->request_router->route_request( $missing, $context, 'STDIO' );

		$this->assertTrue( $denied_result['isError'] );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $missing_result['error']['code'] );
		$this->assertCount( 2, DummyObservabilityHandler::$events );
		$this->assertSame( 'error', DummyObservabilityHandler::$events[0]['tags']['status'] );
		$this->assertNotEmpty( DummyObservabilityHandler::$events[0]['tags']['failure_reason'] );
		$this->assertSame( 'error', DummyObservabilityHandler::$events[1]['tags']['status'] );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, DummyObservabilityHandler::$events[1]['tags']['error_code'] );
	}
}
