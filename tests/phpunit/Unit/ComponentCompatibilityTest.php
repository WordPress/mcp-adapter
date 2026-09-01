<?php
/**
 * Adapter-owned component compatibility contracts.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;
use WP_Error;

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused compatibility fixture for this test class.
final class CompatibilityPromptBuilder extends McpPromptBuilder {

	public static int $configure_count = 0;

	protected function configure(): void {
		++self::$configure_count;
		$this->name        = 'builder-compatibility';
		$this->title       = 'Builder compatibility';
		$this->description = 'Exercises the deprecated but supported builder extension point.';
		$this->add_argument( 'code', 'Code to inspect', true );
		$this->set_meta( array( 'fixture' => true ) );
	}

	public function handle( array $arguments ): array {
		return array( 'text' => 'handled:' . ( $arguments['code'] ?? '' ) );
	}

	public function has_permission( array $arguments ): bool {
		return true === ( $arguments['allow'] ?? false );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused failing builder fixture for registry containment.
final class ThrowingCompatibilityPromptBuilder extends McpPromptBuilder {

	protected function configure(): void {
		throw new \RuntimeException( 'Builder configuration failed' );
	}

	public function handle( array $arguments ): array {
		return $arguments;
	}
}

/** Protects component behavior that remains independent of schema representation. */
final class ComponentCompatibilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		CompatibilityPromptBuilder::$configure_count = 0;
	}

	/** Builder prompts remain registered, executable, permission-aware, and idempotent. */
	public function test_builder_prompt_extension_point_remains_compatible(): void {
		$builder = new CompatibilityPromptBuilder();
		$first   = $builder->build();
		$second  = $builder->build();

		$this->assertSame( 1, CompatibilityPromptBuilder::$configure_count );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $first['arguments'] );
		$this->assertTrue( $first['_meta']['fixture'] );

		$server     = $this->makeServer( array(), array(), array( $builder ) );
		$registered = $server->get_mcp_prompt( 'builder-compatibility' );
		$this->assertNotNull( $registered );
		$this->assertSame( $builder, $server->get_prompt_builder( 'builder-compatibility' ) );
		$this->assertFalse( $registered->check_permission( array( 'allow' => false ) ) );
		$this->assertTrue( $registered->check_permission( array( 'allow' => true ) ) );
		$this->assertSame( array( 'text' => 'handled:echo 1;' ), $registered->execute( array( 'code' => 'echo 1;' ) ) );

		$record = $server->get_prompt( 'builder-compatibility', $this->schema( Schemas::V2026_07_28 ) );
		$this->assertNotNull( $record );
		$this->assertSame( 'builder-compatibility', $record->getName() );
	}

	/** Direct components deny by default and contain callback exceptions as WP_Error. */
	public function test_direct_components_default_deny_and_contain_exceptions(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'throwing-tool',
				'handler' => static function (): void {
					throw new \RuntimeException( 'tool failed' );
				},
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$this->assertInstanceOf( WP_Error::class, $tool->check_permission( array() ) );
		$this->assertSame( 'mcp_execution_failed', $tool->execute( array() )->get_error_code() );
		$this->assertSame( 'array', $tool->get_observability_context()['source'] );

		$resource = McpResource::fromArray(
			array(
				'uri'     => 'fixture://resource',
				'handler' => static function (): void {
					throw new \RuntimeException( 'resource failed' );
				},
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$this->assertInstanceOf( WP_Error::class, $resource->check_permission( array() ) );
		$this->assertSame( 'mcp_execution_failed', $resource->execute( array() )->get_error_code() );
		$this->assertSame( 'resource', $resource->get_observability_context()['component_type'] );

		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'throwing-prompt',
				'handler' => static function (): void {
					throw new \RuntimeException( 'prompt failed' );
				},
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$this->assertInstanceOf( WP_Error::class, $prompt->check_permission( array() ) );
		$this->assertSame( 'mcp_execution_failed', $prompt->execute( array() )->get_error_code() );
		$this->assertSame( 'prompt', $prompt->get_observability_context()['component_type'] );
	}

	/** Direct component optional fields survive neutral storage and exact projection. */
	public function test_direct_components_preserve_optional_protocol_fields(): void {
		$icon = array(
			'src'      => 'https://example.com/icon.png',
			'mimeType' => 'image/png',
			'sizes'    => array( '32x32' ),
			'theme'    => 'light',
		);
		$tool = McpTool::fromArray(
			array(
				'name'         => 'complete-tool',
				'title'        => 'Complete Tool',
				'description'  => 'All optional fields',
				'inputSchema'  => array( 'type' => 'object' ),
				'outputSchema' => array( 'type' => 'object' ),
				'icons'        => array( $icon ),
				'meta'         => array( 'vendor' => true ),
				'annotations'  => array( 'readOnlyHint' => true ),
				'execution'    => array( 'taskSupport' => 'forbidden' ),
				'handler'      => static fn(): array => array( 'ok' => true ),
				'permission'   => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$this->assertTrue( $tool->check_permission( array() ) );
		$tool_data = $this->record_array( $tool->get_protocol_record( $this->schema( Schemas::V2025_11_25 ) ) );
		$this->assertSame( 'Complete Tool', $tool_data['title'] );
		$this->assertTrue( $tool_data['_meta']['vendor'] );
		$this->assertTrue( $tool_data['annotations']['readOnlyHint'] );
		$this->assertSame( 'forbidden', $tool_data['execution']['taskSupport'] );

		$resource = McpResource::fromArray(
			array(
				'name'        => 'complete-resource',
				'uri'         => 'fixture://complete-resource',
				'title'       => 'Complete Resource',
				'description' => 'All optional fields',
				'mimeType'    => 'text/plain; charset=utf-8',
				'size'        => 42,
				'icons'       => array( $icon ),
				'meta'        => array( 'vendor' => true ),
				'annotations' => array( 'audience' => array( 'user' ) ),
				'handler'     => static fn(): string => 'content',
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$this->assertTrue( $resource->check_permission( array() ) );
		$resource_data = $this->record_array( $resource->get_protocol_record( $this->schema( Schemas::V2026_07_28 ) ) );
		$this->assertSame( 42, $resource_data['size'] );
		$this->assertSame( 'text/plain; charset=utf-8', $resource_data['mimeType'] );
		$this->assertTrue( $resource_data['_meta']['vendor'] );

		$prompt = McpPrompt::fromArray(
			array(
				'name'        => 'complete-prompt',
				'title'       => 'Complete Prompt',
				'description' => 'All optional fields',
				'arguments'   => array(
					array( 'name' => 'value', 'description' => 'Input', 'required' => true ),
				),
				'icons'       => array( $icon ),
				'meta'        => array( 'vendor' => true ),
				'handler'     => static fn(): array => array( 'text' => 'prompt' ),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$this->assertTrue( $prompt->check_permission( array() ) );
		$prompt_data = $this->record_array( $prompt->get_protocol_record( $this->schema( Schemas::V2026_07_28 ) ) );
		$this->assertSame( 'Complete Prompt', $prompt_data['title'] );
		$this->assertTrue( $prompt_data['arguments'][0]['required'] );
		$this->assertTrue( $prompt_data['_meta']['vendor'] );
	}

	/** Invalid entries are isolated and duplicate resources remain first-wins. */
	public function test_registry_isolates_invalid_entries_and_keeps_first_duplicate(): void {
		$first = McpResource::fromArray(
			array(
				'uri'        => 'Fixture://resource',
				'handler'    => static fn(): string => 'first',
				'permission' => '__return_true',
			)
		);
		$second = McpResource::fromArray(
			array(
				'uri'        => 'Fixture://resource',
				'handler'    => static fn(): string => 'second',
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $first );
		$this->assertInstanceOf( McpResource::class, $second );

		$server = $this->makeServer( array(), array( 42, $first, $second ) );
		$this->assertSame( 1, $server->count_resources() );
		$this->assertCount( 2, DummyErrorHandler::$logs );

		$stored = $server->get_mcp_resource( 'fixture://resource' );
		$this->assertNotNull( $stored );
		$this->assertSame( 'first', $stored->execute( array() ) );
	}

	/** Ability naming and URI filters remain effective at the translation boundary. */
	public function test_ability_component_filters_remain_effective(): void {
		$tool_filter = static fn(): string => 'filtered-tool';
		$uri_filter  = static fn(): string => 'filtered://resource';
		add_filter( 'mcp_adapter_tool_name', $tool_filter );
		add_filter( 'mcp_adapter_resource_uri', $uri_filter );

		try {
			$server = $this->makeServer( array( 'test/always-allowed' ), array( 'test/resource' ) );
		} finally {
			remove_filter( 'mcp_adapter_tool_name', $tool_filter );
			remove_filter( 'mcp_adapter_resource_uri', $uri_filter );
		}

		$this->assertNotNull( $server->get_mcp_tool( 'filtered-tool' ) );
		$this->assertNotNull( $server->get_mcp_resource( 'filtered://resource' ) );
		$this->assertCount( 1, $server->get_tools( $this->schema( Schemas::V2025_11_25 ) ) );
		$this->assertCount( 1, $server->get_resources( $this->schema( Schemas::V2026_07_28 ) ) );
	}

	/** Registry failures are isolated, logged, and emitted through opt-in observability. */
	public function test_registry_failure_containment_and_observability(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$tool = McpTool::fromArray(
			array(
				'name'        => 'registry-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static fn(): array => array( 'first' => true ),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$record = '__return_true';
		add_filter( 'mcp_adapter_observability_record_component_registration', $record );

		try {
			$server = $this->makeServer(
				array( 'missing/tool', 42, $tool, $tool ),
				array( 'missing/resource', 42 ),
				array( ThrowingCompatibilityPromptBuilder::class, 'missing/prompt', 42, CompatibilityPromptBuilder::class )
			);
		} finally {
			remove_filter( 'mcp_adapter_observability_record_component_registration', $record );
		}

		$this->assertSame( 1, $server->count_tools() );
		$this->assertSame( 0, $server->count_resources() );
		$this->assertSame( 1, $server->count_prompts() );
		$this->assertNotNull( $server->get_mcp_tool( 'registry-tool' ) );
		$this->assertNotNull( $server->get_mcp_prompt( 'builder-compatibility' ) );
		$this->assertGreaterThanOrEqual( 7, count( DummyErrorHandler::$logs ) );

		$statuses = array_column( array_column( \WP\MCP\Tests\Fixtures\DummyObservabilityHandler::$events, 'tags' ), 'status' );
		$this->assertContains( 'success', $statuses );
		$this->assertContains( 'failed', $statuses );
		$this->assertContains( 'mcp.component.registration', array_column( \WP\MCP\Tests\Fixtures\DummyObservabilityHandler::$events, 'event' ) );
	}

	/** Factory validation and callback exceptions remain contained at component boundaries. */
	public function test_factory_validation_and_callback_exception_boundaries(): void {
		$this->assertSame( 'mcp_tool_missing_name', McpTool::fromArray( array( 'handler' => '__return_true' ) )->get_error_code() );
		$this->assertSame( 'mcp_tool_missing_handler', McpTool::fromArray( array( 'name' => 'missing-handler' ) )->get_error_code() );
		$this->assertSame( 'mcp_resource_missing_uri', McpResource::fromArray( array( 'handler' => '__return_true' ) )->get_error_code() );
		$this->assertSame( 'mcp_resource_missing_handler', McpResource::fromArray( array( 'uri' => 'fixture://missing-handler' ) )->get_error_code() );
		$this->assertSame( 'mcp_resource_invalid_uri', McpResource::fromArray( array( 'uri' => 'invalid', 'handler' => '__return_true' ) )->get_error_code() );
		$this->assertSame( 'mcp_prompt_missing_name', McpPrompt::fromArray( array( 'handler' => '__return_true' ) )->get_error_code() );
		$this->assertSame( 'mcp_prompt_missing_handler', McpPrompt::fromArray( array( 'name' => 'missing-handler' ) )->get_error_code() );

		$throwing_permission = static function (): bool {
			throw new \RuntimeException( 'permission failed' );
		};
		$tool = McpTool::fromArray(
			array(
				'name'       => 'permission-tool',
				'handler'    => static fn(): string => 'scalar tool result',
				'permission' => $throwing_permission,
			)
		);
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'fixture://permission-resource',
				'handler'    => static fn(): string => 'resource result',
				'permission' => $throwing_permission,
			)
		);
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'permission-prompt',
				'handler'    => static fn(): string => 'scalar prompt result',
				'permission' => $throwing_permission,
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$this->assertInstanceOf( McpResource::class, $resource );
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$this->assertSame( 'mcp_permission_check_failed', $tool->check_permission( array() )->get_error_code() );
		$this->assertSame( 'mcp_permission_check_failed', $resource->check_permission( array() )->get_error_code() );
		$this->assertSame( 'mcp_permission_check_failed', $prompt->check_permission( array() )->get_error_code() );
		$this->assertSame( array( 'result' => 'scalar tool result' ), $tool->execute( array() ) );
		$this->assertSame( array( 'result' => 'scalar prompt result' ), $prompt->execute( array() ) );
	}

	/** Builder construction, execution, and permission exceptions become inspectable WP_Error values. */
	public function test_builder_exceptions_are_contained(): void {
		$build_failure = new class() implements McpPromptBuilderInterface {
			public function build(): array {
				throw new \RuntimeException( 'build failed' );
			}

			public function get_name(): string {
				return 'build-failure';
			}

			public function get_title(): ?string {
				return null;
			}

			public function get_description(): ?string {
				return null;
			}

			public function get_arguments(): array {
				return array();
			}

			public function handle( array $arguments ): array {
				return $arguments;
			}

			public function has_permission( array $arguments ): bool {
				return true;
			}
		};
		$build_error = McpPrompt::fromBuilder( $build_failure );
		$this->assertInstanceOf( WP_Error::class, $build_error );
		$this->assertSame( 'mcp_prompt_builder_failed', $build_error->get_error_code() );

		$callback_failure = new class() implements McpPromptBuilderInterface {
			public function build(): array {
				return array( 'name' => 'callback-failure' );
			}

			public function get_name(): string {
				return 'callback-failure';
			}

			public function get_title(): ?string {
				return null;
			}

			public function get_description(): ?string {
				return null;
			}

			public function get_arguments(): array {
				return array();
			}

			public function handle( array $arguments ): array {
				throw new \RuntimeException( 'handle failed' );
			}

			public function has_permission( array $arguments ): bool {
				throw new \RuntimeException( 'builder permission failed' );
			}
		};
		$prompt = McpPrompt::fromBuilder( $callback_failure );
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$this->assertSame( 'mcp_execution_failed', $prompt->execute( array() )->get_error_code() );
		$this->assertSame( 'mcp_permission_check_failed', $prompt->check_permission( array() )->get_error_code() );
		$this->assertSame( 'builder', $prompt->get_adapter_meta()['source'] );
	}

	/** Ability callback exceptions and adapter metadata remain available on translated tools. */
	public function test_ability_tool_exceptions_and_metadata_remain_available(): void {
		$execute_ability    = wp_get_ability( 'test/execute-exception' );
		$permission_ability = wp_get_ability( 'test/permission-exception' );
		$this->assertNotNull( $execute_ability );
		$this->assertNotNull( $permission_ability );

		$execute_tool    = McpTool::fromAbility( $execute_ability );
		$permission_tool = McpTool::fromAbility( $permission_ability );
		$this->assertInstanceOf( McpTool::class, $execute_tool );
		$this->assertInstanceOf( McpTool::class, $permission_tool );

		$execution_error  = $execute_tool->execute( array() );
		$permission_error = $permission_tool->check_permission( array() );
		$this->assertInstanceOf( WP_Error::class, $execution_error );
		$this->assertInstanceOf( WP_Error::class, $permission_error );
		$this->assertStringContainsString( 'boom', $execution_error->get_error_message() );
		$this->assertStringContainsString( 'nope', $permission_error->get_error_message() );
		$this->assertSame( 'test/execute-exception', $execute_tool->get_adapter_meta()['ability'] );
	}
}
