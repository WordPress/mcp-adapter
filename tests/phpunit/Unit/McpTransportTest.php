<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

final class McpTransportTest extends TestCase {

	public function test_transport_helper_trait_normalizes_class_name(): void {
		$server    = $this->makeServer();
		$context   = $this->createTransportContext( $server );
		$transport = new DummyTransport( $context );

		$ref    = new \ReflectionClass( $transport );
		$method = $ref->getMethod( 'get_transport_name' );
		$method->setAccessible( true );
		$name = $method->invoke( $transport );

		$this->assertIsString( $name );
		$this->assertNotSame( '', $name );
	}

	public function test_transport_routes_requests_successfully_with_metrics(): void {
		$server    = $this->makeServer( array( 'test/always-allowed' ) );
		$context   = $this->createTransportContext( $server );
		$transport = new DummyTransport( $context );

		$res = $transport->test_route_request( 'tools/list', array() );
		$this->assertIsArray( $res );
		$this->assertArrayHasKey( 'tools', $res );

		// metrics (unified event name with status tag)
		$this->assertNotEmpty( DummyObservabilityHandler::$events );
		$event_metrics = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request', $event_metrics );

		// Verify duration and status are included
		$success_event = array_filter(
			DummyObservabilityHandler::$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );
		$first_success = reset( $success_event );
		$this->assertNotNull( $first_success['duration_ms'] );
	}

	public function test_transport_handles_unknown_methods_with_error_metrics(): void {
		$server    = $this->makeServer();
		$context   = $this->createTransportContext( $server );
		$transport = new DummyTransport( $context );

		$res = $transport->test_route_request( 'unknown/method', array() );
		$this->assertArrayHasKey( 'error', $res );

		// Verify error event was recorded with duration and status tag
		$this->assertNotEmpty( DummyObservabilityHandler::$events );
		$event_metrics = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request', $event_metrics );

		// Verify status is 'error'
		$error_event = array_filter(
			DummyObservabilityHandler::$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'error' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $error_event );
	}

	private function createTransportContext( McpServer $server ): McpTransportContext {
		// Create handlers
		$initialize_handler = new InitializeHandler( $server );
		$tools_handler      = new ToolsHandler( $server );
		$resources_handler  = new ResourcesHandler( $server );
		$prompts_handler    = new PromptsHandler( $server );
		$system_handler     = new SystemHandler();

		// Create the context - the router will be created automatically
		return new McpTransportContext(
			array(
				'mcp_server'            => $server,
				'initialize_handler'    => $initialize_handler,
				'tools_handler'         => $tools_handler,
				'resources_handler'     => $resources_handler,
				'prompts_handler'       => $prompts_handler,
				'system_handler'        => $system_handler,
				'observability_handler' => new DummyObservabilityHandler(),
			)
		);
	}
}
