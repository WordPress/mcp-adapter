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
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\Tool;
use WP\McpSchema\Server\Tools\ToolInputSchema;

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
		return array(
			'result' => 'success',
			'input'  => $arguments['input'] ?? 'none',
		);
	}

	public function has_permission( array $arguments ): bool {
		return true;
	}
}

// Test prompt builder that throws exception during build
class ExceptionPromptBuilder extends McpPromptBuilder {

	protected function configure(): void {
		throw new \RuntimeException( 'Builder exception during configure' );
	}

	public function handle( array $arguments ): array {
		return array();
	}

	public function has_permission( array $arguments ): bool {
		return true;
	}
}

/**
 * Test McpComponentRegistry functionality.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
final class McpComponentRegistryTest extends TestCase {

	private McpComponentRegistry $registry;
	private McpServer $server;

	public function set_up(): void {
		parent::set_up();

		// Enable component registration recording for tests
		add_filter( 'mcp_adapter_observability_record_component_registration', '__return_true' );

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
			new DummyObservabilityHandler()
		);
	}

	public function tear_down(): void {
		// Remove the filter to ensure clean state
		remove_filter( 'mcp_adapter_observability_record_component_registration', '__return_true' );
		parent::tear_down();
	}

	public function test_register_tools_with_valid_ability(): void {
		$this->registry->register_tools( array( 'test/always-allowed' ) );

		$tools = $this->registry->get_tools();
		$this->assertCount( 1, $tools );
		$this->assertArrayHasKey( 'test-always-allowed', $tools );

		$tool = $tools['test-always-allowed'];
		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\Tool::class, $tool );
		$this->assertEquals( 'test-always-allowed', $tool->getName() );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );

		// Verify status is 'success'
		$success_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_tools_with_invalid_ability(): void {
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
		$failure_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'failed' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $failure_event );
	}

	public function test_register_tools_skips_non_strings(): void {
		$this->registry->register_tools( array( 123, null, array(), 'test/always-allowed' ) );

		$tools = $this->registry->get_tools();
		$this->assertCount( 1, $tools ); // Only the valid string should be processed
		$this->assertArrayHasKey( 'test-always-allowed', $tools );
	}

	public function test_add_tool_direct(): void {
		// Create a tool directly
		$tool = new Tool(
			'direct-tool',
			new ToolInputSchema(),
			'Direct Tool Title',
			'Direct Tool'
		);

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
		$success_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );
	}

	public function test_register_resources_with_valid_ability(): void {
		$this->registry->register_resources( array( 'test/resource' ) );

		$resources = $this->registry->get_resources();
		$this->assertCount( 1, $resources );

		// Get the first resource
		$resource = array_values( $resources )[0];
		$this->assertInstanceOf( \WP\McpSchema\Server\Resources\Resource::class, $resource );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );

		// Verify status is 'success'
		$success_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_resources_with_invalid_ability(): void {
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
		$failure_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'failed' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $failure_event );
	}

	public function test_register_prompts_with_valid_ability(): void {
		$this->registry->register_prompts( array( 'test/prompt' ) );

		$prompts = $this->registry->get_prompts();
		$this->assertCount( 1, $prompts );

		// Get the first prompt
		$prompt = array_values( $prompts )[0];
		$this->assertInstanceOf( \WP\McpSchema\Server\Prompts\Prompt::class, $prompt );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );

		// Verify status is 'success'
		$success_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_prompts_with_builder_class(): void {
		$this->registry->register_prompts( array( TestRegistryPrompt::class ) );

		$prompts = $this->registry->get_prompts();
		$this->assertCount( 1, $prompts );
		$this->assertArrayHasKey( 'test-registry-prompt', $prompts );

		$prompt = $prompts['test-registry-prompt'];
		$this->assertInstanceOf( \WP\McpSchema\Server\Prompts\Prompt::class, $prompt );
		$this->assertNotNull( $this->registry->get_prompt_builder( 'test-registry-prompt' ) );

		// Verify observability event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.component.registration', $event_names );

		// Verify status is 'success'
		$success_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );

		// Verify no errors were logged
		$this->assertEmpty( DummyErrorHandler::$logs );
	}

	public function test_register_prompts_with_invalid_ability(): void {
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
		$failure_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'failed' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $failure_event );
	}

	public function test_get_tool_by_name(): void {
		$this->registry->register_tools( array( 'test/always-allowed' ) );

		$tool = $this->registry->get_tool( 'test-always-allowed' );
		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\Tool::class, $tool );
		$this->assertEquals( 'test-always-allowed', $tool->getName() );

		$nonexistent = $this->registry->get_tool( 'nonexistent' );
		$this->assertNull( $nonexistent );
	}

	public function test_get_resource_by_uri(): void {
		$this->registry->register_resources( array( 'test/resource' ) );

		$resources = $this->registry->get_resources();
		$this->assertNotEmpty( $resources );

		$resource_uri = array_keys( $resources )[0];
		$resource     = $this->registry->get_resource( $resource_uri );
		$this->assertInstanceOf( \WP\McpSchema\Server\Resources\Resource::class, $resource );

		$nonexistent = $this->registry->get_resource( 'nonexistent://resource' );
		$this->assertNull( $nonexistent );
	}

	public function test_get_prompt_by_name(): void {
		$this->registry->register_prompts( array( 'test/prompt' ) );

		$prompt = $this->registry->get_prompt( 'test-prompt' );
		$this->assertInstanceOf( \WP\McpSchema\Server\Prompts\Prompt::class, $prompt );

		$nonexistent = $this->registry->get_prompt( 'nonexistent' );
		$this->assertNull( $nonexistent );
	}

	public function test_registry_handles_mixed_component_types(): void {
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
		$event_names       = array_column( $events, 'event' );
		$registered_events = array_filter(
			$event_names,
			static function ( $event ) {
				return 'mcp.component.registration' === $event;
			}
		);
		$this->assertCount( 4, $registered_events ); // 1 tool + 1 resource + 2 prompts

		// Verify all are successful registrations
		$success_events = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertCount( 4, $success_events );
	}

	public function test_register_resources_with_missing_uri(): void {
		// Register a resource ability without URI in meta
		$this->register_ability_in_hook(
			'test/resource-no-uri',
			array(
				'label'               => 'Resource No URI',
				'description'         => 'A resource without URI',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					// No 'uri' key - this will cause WP_Error
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		// Clear previous events
		DummyObservabilityHandler::$events = array();
		DummyErrorHandler::$logs           = array();

		// Register the resource without URI
		$this->registry->register_resources( array( 'test/resource-no-uri' ) );

		// Resource should not be registered
		$resources = $this->registry->get_resources();
		$this->assertCount( 0, $resources );

		// Verify error was logged
		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$log_messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertStringContainsString( 'Resource URI not found', implode( ' ', $log_messages ) );

		// Verify failure event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$failure_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event']
					&& isset( $event['tags']['status'] )
					&& 'failed' === $event['tags']['status']
					&& isset( $event['tags']['component_type'] )
					&& 'resource' === $event['tags']['component_type'];
			}
		);
		$this->assertNotEmpty( $failure_event );

		// Clean up
		wp_unregister_ability( 'test/resource-no-uri' );
	}

	public function test_register_prompts_with_builder_exception(): void {
		// Clear previous events
		DummyObservabilityHandler::$events = array();
		DummyErrorHandler::$logs           = array();

		// Register a prompt builder that throws exception
		$this->registry->register_prompts( array( ExceptionPromptBuilder::class ) );

		// Prompt should not be registered
		$prompts = $this->registry->get_prompts();
		$this->assertCount( 0, $prompts );

		// Verify error was logged
		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$log_messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertStringContainsString( 'Failed to build prompt from class', implode( ' ', $log_messages ) );
		$this->assertStringContainsString( 'Builder exception', implode( ' ', $log_messages ) );

		// Verify failure event was recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
		$failure_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event']
					&& isset( $event['tags']['status'] )
					&& 'failed' === $event['tags']['status']
					&& isset( $event['tags']['component_type'] )
					&& 'prompt' === $event['tags']['component_type']
					&& isset( $event['tags']['failure_reason'] )
					&& 'builder_exception' === $event['tags']['failure_reason'];
			}
		);
		$this->assertNotEmpty( $failure_event );
	}

	// Note: DTO schema validation is handled by the php-mcp-schema DTO constructors.

	public function test_register_resources_skips_non_strings(): void {
		$this->registry->register_resources( array( 123, null, array(), 'test/resource' ) );

		$resources = $this->registry->get_resources();
		$this->assertCount( 1, $resources ); // Only the valid string should be processed
	}

	public function test_register_prompts_skips_non_strings(): void {
		$this->registry->register_prompts( array( 123, null, array(), 'test/prompt' ) );

		$prompts = $this->registry->get_prompts();
		$this->assertCount( 1, $prompts ); // Only the valid string should be processed
	}

	public function test_registry_with_observability_disabled(): void {
		// Remove the filter to disable observability recording
		remove_filter( 'mcp_adapter_observability_record_component_registration', '__return_true' );

		// Create registry
		$registry_no_observability = new McpComponentRegistry(
			$this->server,
			new DummyErrorHandler(),
			new DummyObservabilityHandler()
		);

		// Clear events from previous tests
		DummyObservabilityHandler::$events = array();

		// Register a tool
		$registry_no_observability->register_tools( array( 'test/always-allowed' ) );

		$tools = $registry_no_observability->get_tools();
		$this->assertCount( 1, $tools );

		// Verify NO observability events were recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertEmpty( $events );

		// Re-enable the filter for subsequent tests
		add_filter( 'mcp_adapter_observability_record_component_registration', '__return_true' );
	}

	/**
	 * Test that registry continues processing other tools when one tool name is invalid.
	 *
	 * This verifies that:
	 * 1. Invalid tool names don't break MCP functionality
	 * 2. Invalid tool names don't break the WordPress site
	 * 3. Errors are logged for invalid tools
	 * 4. Other valid tools are still registered
	 */
	public function test_register_tools_continues_after_invalid_tool_name(): void {
		// Register two test abilities.
		$this->register_ability_in_hook(
			'test/first-valid-tool',
			array(
				'label'               => 'First Valid Tool',
				'description'         => 'First tool for continue test',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'ok';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		$this->register_ability_in_hook(
			'test/will-be-invalid',
			array(
				'label'               => 'Will Be Invalid Tool',
				'description'         => 'This tool name will be invalidated by filter',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'ok';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		$this->register_ability_in_hook(
			'test/second-valid-tool',
			array(
				'label'               => 'Second Valid Tool',
				'description'         => 'Second tool for continue test',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'ok';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		// Filter that invalidates the middle tool's name by returning invalid characters.
		$filter_callback = static function ( $name ) {
			if ( 'test-will-be-invalid' === $name ) {
				return 'invalid name with spaces!!!'; // Invalid: contains spaces and special chars
			}
			return $name;
		};
		add_filter( 'mcp_adapter_tool_name', $filter_callback );

		// Clear previous logs and events.
		DummyErrorHandler::$logs           = array();
		DummyObservabilityHandler::$events = array();

		// Register all three tools - the middle one will fail.
		$this->registry->register_tools(
			array(
				'test/first-valid-tool',
				'test/will-be-invalid',
				'test/second-valid-tool',
			)
		);

		// Verify both valid tools were registered (invalid one was skipped).
		$tools = $this->registry->get_tools();
		$this->assertCount( 2, $tools, 'Both valid tools should be registered despite one invalid tool' );
		$this->assertArrayHasKey( 'test-first-valid-tool', $tools );
		$this->assertArrayHasKey( 'test-second-valid-tool', $tools );
		$this->assertArrayNotHasKey( 'test-will-be-invalid', $tools );

		// Verify error was logged for the invalid tool.
		$this->assertNotEmpty( DummyErrorHandler::$logs, 'Error should be logged for invalid tool' );
		$log_messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertStringContainsString( 'invalid', implode( ' ', $log_messages ) );

		// Verify observability events: 2 successes and 1 failure.
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$success_events = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event']
					&& isset( $event['tags']['status'] )
					&& 'success' === $event['tags']['status'];
			}
		);
		$this->assertCount( 2, $success_events, 'Two tools should have successful registration events' );

		$failure_events = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.component.registration' === $event['event']
					&& isset( $event['tags']['status'] )
					&& 'failed' === $event['tags']['status'];
			}
		);
		$this->assertCount( 1, $failure_events, 'One tool should have a failed registration event' );

		// Clean up.
		remove_filter( 'mcp_adapter_tool_name', $filter_callback );
		wp_unregister_ability( 'test/first-valid-tool' );
		wp_unregister_ability( 'test/will-be-invalid' );
		wp_unregister_ability( 'test/second-valid-tool' );
	}
}
