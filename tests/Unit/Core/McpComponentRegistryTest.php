<?php
/**
 * Tests for McpComponentRegistry class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpComponentRegistry;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

// Test prompt builder for registry testing
class TestRegistryPrompt extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'test-registry-prompt';
		$this->title       = 'Test Registry Prompt';
		$this->description = 'A test prompt for registry testing';
		$this->arguments   = array(
			$this->create_argument( 'input', 'Test input', true ),
		);
	}

	public function handle( array $arguments ): array {
		return array( 'result' => 'success', 'input' => $arguments['input'] ?? 'none' );
	}

	public function has_permission( array $arguments ): bool {
		return true;
	}
}

/**
 * Test McpComponentRegistry functionality.
 */
final class McpComponentRegistryTest extends TestCase {

	private McpComponentRegistry $registry;
	private McpServer $server;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function set_up(): void {
		parent::set_up();

		$this->server = new McpServer(
			'test-server',
			'mcp/v1',
			'/test-mcp',
			'Test Server',
			'Test server for component registry',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);

		$this->registry = new McpComponentRegistry(
			$this->server,
			new DummyErrorHandler(),
			new DummyObservabilityHandler(),
			false // Disable validation for simpler testing
		);
	}

	public function test_register_tools_with_valid_ability(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_tools( array( 'test/always-allowed' ) );

		$tools = $this->registry->get_tools();
		$this->assertCount( 1, $tools );
		$this->assertArrayHasKey( 'test-always-allowed', $tools );

		$tool = $tools['test-always-allowed'];
		$this->assertInstanceOf( \WP\MCP\Domain\Tools\McpTool::class, $tool );
		$this->assertEquals( 'test-always-allowed', $tool->get_name() );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'success'
		$success_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'success';
		} );
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_tools_with_invalid_ability(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_tools( array( 'nonexistent/ability' ) );

		$tools = $this->registry->get_tools();
		$this->assertCount( 0, $tools ); // No tools should be registered

		// Verify error was logged
		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$log_messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertStringContainsString( 'nonexistent/ability', implode( ' ', $log_messages ) );

		// Verify failure event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'failed'
		$failure_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'failed';
		} );
		$this->assertNotEmpty( $failure_event );
	}

	public function test_register_tools_skips_non_strings(): void {
		DummyObservabilityHandler::reset();

		$this->registry->register_tools( array( 123, null, array(), 'test/always-allowed' ) );

		$tools = $this->registry->get_tools();
		$this->assertCount( 1, $tools ); // Only the valid string should be processed
		$this->assertArrayHasKey( 'test-always-allowed', $tools );
	}

	public function test_add_tool_direct(): void {
		DummyObservabilityHandler::reset();

		// Create a tool directly
		$tool = new McpTool(
			'test/direct-tool',
			'direct-tool',
			'Direct Tool',
			array( 'type' => 'object' ),
			'Direct Tool Title'
		);
		$tool->set_mcp_server( $this->server );

		$this->registry->add_tool( $tool );

		$tools = $this->registry->get_tools();
		$this->assertCount( 1, $tools );
		$this->assertArrayHasKey( 'direct-tool', $tools );

		$retrieved_tool = $this->registry->get_tool( 'direct-tool' );
		$this->assertSame( $tool, $retrieved_tool );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'success'
		$success_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'success';
		} );
		$this->assertNotEmpty( $success_event );
	}

	public function test_register_resources_with_valid_ability(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_resources( array( 'test/resource' ) );

		$resources = $this->registry->get_resources();
		$this->assertCount( 1, $resources );

		// Get the first resource
		$resource = array_values( $resources )[0];
		$this->assertInstanceOf( \WP\MCP\Domain\Resources\McpResource::class, $resource );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'success'
		$success_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'success';
		} );
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_resources_with_invalid_ability(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_resources( array( 'nonexistent/resource' ) );

		$resources = $this->registry->get_resources();
		$this->assertCount( 0, $resources );

		// Verify error was logged
		$this->assertNotEmpty( DummyErrorHandler::$logs );

		// Verify failure event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'failed'
		$failure_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'failed';
		} );
		$this->assertNotEmpty( $failure_event );
	}

	public function test_register_prompts_with_valid_ability(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_prompts( array( 'test/prompt' ) );

		$prompts = $this->registry->get_prompts();
		$this->assertCount( 1, $prompts );

		// Get the first prompt
		$prompt = array_values( $prompts )[0];
		$this->assertInstanceOf( \WP\MCP\Domain\Prompts\McpPrompt::class, $prompt );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'success'
		$success_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'success';
		} );
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_prompts_with_builder_class(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_prompts( array( TestRegistryPrompt::class ) );

		$prompts = $this->registry->get_prompts();
		$this->assertCount( 1, $prompts );
		$this->assertArrayHasKey( 'test-registry-prompt', $prompts );

		$prompt = $prompts['test-registry-prompt'];
		$this->assertInstanceOf( \WP\MCP\Domain\Prompts\McpPrompt::class, $prompt );
		$this->assertTrue( $prompt->is_builder_based() );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'success'
		$success_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'success';
		} );
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_prompts_with_invalid_ability(): void {
		DummyObservabilityHandler::reset();
		DummyErrorHandler::reset();

		$this->registry->register_prompts( array( 'nonexistent/prompt' ) );

		$prompts = $this->registry->get_prompts();
		$this->assertCount( 0, $prompts );

		// Verify error was logged
		$this->assertNotEmpty( DummyErrorHandler::$logs );

		// Verify failure event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );
		
		// Verify status is 'failed'
		$failure_event = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'failed';
		} );
		$this->assertNotEmpty( $failure_event );
	}

	public function test_get_tool_by_name(): void {
		$this->registry->register_tools( array( 'test/always-allowed' ) );

		$tool = $this->registry->get_tool( 'test-always-allowed' );
		$this->assertInstanceOf( \WP\MCP\Domain\Tools\McpTool::class, $tool );
		$this->assertEquals( 'test-always-allowed', $tool->get_name() );

		$nonexistent = $this->registry->get_tool( 'nonexistent' );
		$this->assertNull( $nonexistent );
	}

	public function test_get_resource_by_uri(): void {
		$this->registry->register_resources( array( 'test/resource' ) );

		$resources = $this->registry->get_resources();
		$this->assertNotEmpty( $resources );

		$resource_uri = array_keys( $resources )[0];
		$resource = $this->registry->get_resource( $resource_uri );
		$this->assertInstanceOf( \WP\MCP\Domain\Resources\McpResource::class, $resource );

		$nonexistent = $this->registry->get_resource( 'nonexistent://resource' );
		$this->assertNull( $nonexistent );
	}

	public function test_get_prompt_by_name(): void {
		$this->registry->register_prompts( array( 'test/prompt' ) );

		$prompt = $this->registry->get_prompt( 'test-prompt' );
		$this->assertInstanceOf( \WP\MCP\Domain\Prompts\McpPrompt::class, $prompt );

		$nonexistent = $this->registry->get_prompt( 'nonexistent' );
		$this->assertNull( $nonexistent );
	}

	public function test_registry_handles_mixed_component_types(): void {
		DummyObservabilityHandler::reset();

		// Register multiple component types
		$this->registry->register_tools( array( 'test/always-allowed' ) );
		$this->registry->register_resources( array( 'test/resource' ) );
		$this->registry->register_prompts( array( 'test/prompt', TestRegistryPrompt::class ) );

		// Verify all components are registered
		$this->assertCount( 1, $this->registry->get_tools() );
		$this->assertCount( 1, $this->registry->get_resources() );
		$this->assertCount( 2, $this->registry->get_prompts() );

		// Verify multiple observability events were recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$registered_events = array_filter( $event_names, function( $event ) {
			return $event === 'mcp.component.registration';
		} );
		$this->assertCount( 4, $registered_events ); // 1 tool + 1 resource + 2 prompts
		
		// Verify all are successful registrations
		$success_events = array_filter( $events, function( $event ) {
			return $event['event'] === 'mcp.component.registration' && isset( $event['tags']['status'] ) && $event['tags']['status'] === 'success';
		} );
		$this->assertCount( 4, $success_events );
	}

	public function test_registry_with_validation_enabled(): void {
		// Create registry with validation enabled
		$registry_with_validation = new McpComponentRegistry(
			$this->server,
			new DummyErrorHandler(),
			new DummyObservabilityHandler(),
			true // Enable validation
		);

		DummyObservabilityHandler::reset();

		// This should still work with valid abilities
		$registry_with_validation->register_tools( array( 'test/always-allowed' ) );

		$tools = $registry_with_validation->get_tools();
		$this->assertCount( 1, $tools );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
	}
}
