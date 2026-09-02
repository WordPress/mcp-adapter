<?php
/**
 * Unit coverage for exact-revision projection and request context.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Record;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\Error;
use WP\McpSchema\Record\GetPromptRequest;
use WP\McpSchema\Record\HeaderMismatchError;
use WP\McpSchema\Record\InitializeRequest;
use WP\McpSchema\Record\ListPromptsRequest;
use WP\McpSchema\Record\ListResourcesRequest;
use WP\McpSchema\Record\ListToolsRequest;
use WP\McpSchema\Record\ReadResourceRequest;
use WP\McpSchema\Record\Tool;
use WP\McpSchema\Schemas;

/** Covers neutral inputs, isolated projections, defaults, and immutable context. */
final class DualRevisionProjectionTest extends TestCase {

	/** Exact supported identifiers and initialization counter-proposal are finite. */
	public function test_version_negotiator_supports_only_exact_revisions(): void {
		$this->assertSame(
			array( Schemas::V2026_07_28, Schemas::V2025_11_25 ),
			McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS
		);
		$this->assertTrue( McpVersionNegotiator::is_supported( Schemas::V2025_11_25 ) );
		$this->assertTrue( McpVersionNegotiator::is_supported( Schemas::V2026_07_28 ) );
		$this->assertFalse( McpVersionNegotiator::is_supported( '2025-06-18' ) );
		$this->assertSame( Schemas::V2025_11_25, McpVersionNegotiator::negotiate( Schemas::V2026_07_28 ) );
	}

	/** Removed standardized Tool fields remain internal dead weight, not 2026 output. */
	public function test_tool_projects_removed_execution_only_to_2025(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'revision-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'execution'   => array( 'taskSupport' => 'forbidden' ),
				'handler'     => static fn(): array => array( 'ok' => true ),
				'permission'  => '__return_true',
			)
		);

		$this->assertInstanceOf( McpTool::class, $tool );
		$schema_2025 = $this->schema( Schemas::V2025_11_25 );
		$schema_2026 = $this->schema( Schemas::V2026_07_28 );
		$this->assertNotNull( $tool->get_protocol_record( $schema_2025 )->getExecution() );
		$this->assertNull( $tool->get_protocol_record( $schema_2026 )->getExecution() );
		$this->assertFalse( $tool->get_protocol_record( $schema_2026 )->has( 'execution' ) );
	}

	/** One failed modern projection does not remove the valid legacy projection. */
	public function test_invalid_modern_header_annotation_is_revision_isolated(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'header-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'region' => array(
							'type'         => 'number',
							'x-mcp-header' => 'Region',
						),
					),
				),
				'handler'     => static fn(): array => array( 'ok' => true ),
				'permission'  => '__return_true',
			)
		);

		$this->assertInstanceOf( McpTool::class, $tool );
		$this->assertTrue( $tool->is_available_for( $this->schema( Schemas::V2025_11_25 ) ) );
		$this->assertFalse( $tool->is_available_for( $this->schema( Schemas::V2026_07_28 ) ) );
		$this->assertNotNull( $tool->get_projection_error( Schemas::V2026_07_28 ) );
	}

	/** Header annotations are reachable only through plain properties chains. */
	public function test_header_annotation_reachability_matches_runtime_paths(): void {
		$annotation = array(
			'type'         => 'string',
			'x-mcp-header' => 'Region',
		);
		foreach (
			array(
				'$defs' => array(
					'hidden' => array(
						'type'       => 'object',
						'properties' => array( 'region' => $annotation ),
					),
				),
				'oneOf' => array(
					array(
						'type'       => 'object',
						'properties' => array( 'region' => $annotation ),
					),
				),
				'items' => array(
					'type'       => 'object',
					'properties' => array( 'region' => $annotation ),
				),
			) as $keyword => $branch
		) {
			$tool = McpTool::fromArray(
				array(
					'name'        => 'unreachable-' . trim( $keyword, '$' ),
					'inputSchema' => array(
						'type'   => 'object',
						$keyword => $branch,
					),
					'handler'     => static fn(): array => array(),
				)
			);
			$this->assertInstanceOf( McpTool::class, $tool );
			$this->assertFalse( $tool->is_available_for( $this->schema( Schemas::V2026_07_28 ) ), $keyword );
		}

		$nested = McpTool::fromArray(
			array(
				'name'        => 'nested-header',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array(
							'type'       => 'object',
							'properties' => array( 'region' => $annotation ),
						),
					),
				),
				'handler'     => static fn(): array => array(),
			)
		);
		$this->assertInstanceOf( McpTool::class, $nested );
		$this->assertTrue( $nested->is_available_for( $this->schema( Schemas::V2026_07_28 ) ) );
	}

	/** Optional prompt description is omitted rather than projected as null. */
	public function test_direct_prompt_without_description_projects_to_both_revisions(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'minimal-prompt',
				'handler'    => static fn(): array => array( 'text' => 'ok' ),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$server = $this->makeServer( array(), array(), array( $prompt ) );
		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $revision ) {
			$record = $server->get_prompt( 'minimal-prompt', $this->schema( $revision ) );
			$this->assertNotNull( $record );
			$this->assertFalse( $record->has( 'description' ) );
		}
	}

	/** Invalid data under both catalogs is globally skipped and logged. */
	public function test_registration_rejects_component_only_when_every_projection_fails(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'invalid-annotations',
				'inputSchema' => array( 'type' => 'object' ),
				'annotations' => array( 'readOnlyHint' => 'not-a-boolean' ),
				'handler'     => static fn(): array => array(),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );

		$server = $this->makeServer( array( $tool ) );
		$this->assertSame( 0, $server->count_tools() );
		$this->assertNotEmpty( \WP\MCP\Tests\Fixtures\DummyErrorHandler::$logs );
	}

	/** Ordinary Ability configuration projects without author-owned revision branches. */
	public function test_ordinary_ability_projects_to_both_revisions(): void {
		$server = $this->makeServer( array( 'test/always-allowed' ) );
		$tool   = $server->get_mcp_tool( 'test-always-allowed' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( Tool::class, $tool->get_protocol_record( $this->schema( Schemas::V2025_11_25 ) ) );
		$this->assertInstanceOf( Tool::class, $tool->get_protocol_record( $this->schema( Schemas::V2026_07_28 ) ) );
	}

	/** Existing list-filter arguments remain and selected schema is appended third. */
	public function test_tools_list_filter_receives_selected_schema_as_third_argument(): void {
		$server   = $this->makeServer( array( 'test/always-allowed' ) );
		$handler  = new ToolsHandler( $server );
		$versions = array();
		$callback = static function ( array $tools, $filtered_server, $schema ) use ( &$versions ): array {
			$versions[] = $schema->version();

			return $tools;
		};
		add_filter( 'mcp_adapter_tools_list', $callback, 10, 3 );

		$schema_2025  = $server->get_schemas()->forVersion( Schemas::V2025_11_25 );
		$schema_2026  = $server->get_schemas()->forVersion( Schemas::V2026_07_28 );
		$request_2025 = $schema_2025->fromArray(
			ListToolsRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
			)
		);
		$request_2026 = $schema_2026->fromArray(
			ListToolsRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'     => Schemas::V2026_07_28,
						'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
					),
				),
			)
		);

		$handler->list_tools( $request_2025, $this->request_context( $server, Schemas::V2025_11_25 ) );
		$handler->list_tools( $request_2026, $this->request_context( $server, Schemas::V2026_07_28 ) );
		remove_filter( 'mcp_adapter_tools_list', $callback, 10 );

		$this->assertSame( array( Schemas::V2025_11_25, Schemas::V2026_07_28 ), $versions );
	}

	/** Initialize/resource/prompt filters preserve leading arguments and selected schema. */
	public function test_other_result_filters_receive_selected_schema(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ), array( 'test/prompt' ) );
		$schema  = $server->get_schemas()->forVersion( Schemas::V2025_11_25 );
		$context = $this->request_context( $server );
		$seen    = array();
		$filter  = static function ( $value, $filtered_server, $filtered_schema ) use ( &$seen ) {
			$seen[] = array( current_filter(), $filtered_server, $filtered_schema->version() );

			return $value;
		};
		foreach ( array( 'mcp_adapter_initialize_response', 'mcp_adapter_resources_list', 'mcp_adapter_prompts_list' ) as $hook ) {
			add_filter( $hook, $filter, 10, 3 );
		}
		try {
			$initialize = $schema->fromArray(
				InitializeRequest::class,
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => Schemas::V2025_11_25,
						'capabilities'    => array(),
						'clientInfo'      => array(
							'name'    => 'test',
							'version' => '1',
						),
					),
				)
			);
			( new InitializeHandler( $server ) )->handle( $initialize, $context );
			( new ResourcesHandler( $server ) )->list_resources(
				$this->request_2025_11_25( ListResourcesRequest::class, 'resources/list', 2 ),
				$context
			);
			( new PromptsHandler( $server ) )->list_prompts(
				$this->request_2025_11_25( ListPromptsRequest::class, 'prompts/list', 3 ),
				$context
			);
		} finally {
			foreach ( array( 'mcp_adapter_initialize_response', 'mcp_adapter_resources_list', 'mcp_adapter_prompts_list' ) as $hook ) {
				remove_filter( $hook, $filter, 10 );
			}
		}

		$this->assertSame(
			array( 'mcp_adapter_initialize_response', 'mcp_adapter_resources_list', 'mcp_adapter_prompts_list' ),
			array_column( $seen, 0 )
		);
		foreach ( $seen as $call ) {
			$this->assertSame( $server, $call[1] );
			$this->assertSame( Schemas::V2025_11_25, $call[2] );
		}
	}

	/** Existing pre/post execution hooks remain on all component kinds. */
	public function test_execution_hooks_remain_source_compatible(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array( 'test/resource' ), array( 'test/prompt' ) );
		$context = $this->request_context( $server );
		$hooks   = array(
			'mcp_adapter_pre_tool_call',
			'mcp_adapter_tool_call_result',
			'mcp_adapter_pre_resource_read',
			'mcp_adapter_resource_read_result',
			'mcp_adapter_pre_prompt_get',
			'mcp_adapter_prompt_get_result',
		);
		$seen    = array();
		$filter  = static function ( $value ) use ( &$seen ) {
			$seen[] = current_filter();

			return $value;
		};
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $filter );
		}
		try {
			( new ToolsHandler( $server ) )->call_tool(
				$this->request_2025_11_25(
					CallToolRequest::class,
					'tools/call',
					4,
					array(
						'name'      => 'test-always-allowed',
						'arguments' => array(),
					)
				),
				$context
			);
			( new ResourcesHandler( $server ) )->read_resource(
				$this->request_2025_11_25(
					ReadResourceRequest::class,
					'resources/read',
					5,
					array( 'uri' => 'WordPress://local/resource-1' )
				),
				$context
			);
			( new PromptsHandler( $server ) )->get_prompt(
				$this->request_2025_11_25(
					GetPromptRequest::class,
					'prompts/get',
					6,
					array(
						'name'      => 'test-prompt',
						'arguments' => array( 'code' => 'echo 1;' ),
					)
				),
				$context
			);
		} finally {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $filter );
			}
		}

		$this->assertSame( $hooks, $seen );
	}

	/** Context copies nested objects/lists and derives its exact revision from the schema. */
	public function test_request_context_is_deeply_immutable_and_exact(): void {
		$capabilities         = new \stdClass();
		$capabilities->custom = (object) array( 'values' => array( 1, 2 ) );
		$metadata             = array( 'nested' => (object) array( 'value' => 'original' ) );
		$schema               = $this->schema( Schemas::V2026_07_28 );
		$context              = new McpRequestContext(
			$schema,
			$capabilities,
			null,
			'test',
			$metadata
		);

		$copy                           = $context->client_capabilities();
		$copy->custom->values[0]        = 99;
		$metadata_copy                  = $context->transport_metadata();
		$metadata_copy['nested']->value = 'changed';
		$this->assertSame( 1, $context->client_capabilities()->custom->values[0] );
		$this->assertSame( 'original', $context->transport_metadata()['nested']->value );
		$this->assertSame( Schemas::V2026_07_28, $context->revision() );
	}

	/** Error factory emits canonical inner shapes and modern resource mapping. */
	public function test_error_factory_outputs_revision_aware_arrays(): void {
		$unsupported = McpErrorFactory::unsupported_protocol_version( 1, 'unknown', McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS );
		$this->assertSame( McpErrorFactory::UNSUPPORTED_VERSION, $unsupported['error']['code'] );
		$this->assertSame( 'unknown', $unsupported['error']['data']['requested'] );

		$result_2025_11_25 = McpErrorFactory::resource_not_found( 1, 'file:///missing', Schemas::V2025_11_25 );
		$result_2026_07_28 = McpErrorFactory::resource_not_found( 1, 'file:///missing', Schemas::V2026_07_28 );
		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $result_2025_11_25['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $result_2026_07_28['error']['code'] );
	}

	/** Nominal errors and integral-float literals retain their Adapter HTTP meaning. */
	public function test_nominal_error_with_integral_float_code_maps_to_http_status(): void {
		$server   = $this->makeServer();
		$schema   = $server->get_schemas()->forVersion( Schemas::V2026_07_28 );
		$response = $schema->fromArray(
			HeaderMismatchError::class,
			array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => (float) McpErrorFactory::HEADER_MISMATCH,
					'message' => 'Header mismatch',
				),
			)
		);

		$this->assertInstanceOf( Error::class, $response->getError() );
		$this->assertSame( (float) McpErrorFactory::HEADER_MISMATCH, $response->getError()->getCode() );

		$context      = new McpRequestContext( $schema, new \stdClass(), null, 'HTTP' );
		$orchestrator = new McpWireOrchestrator( $server->create_transport_context() );
		$this->assertSame(
			400,
			$orchestrator->http_response_status( $response, $context, new \stdClass(), Schemas::V2026_07_28 )
		);
	}

	/** Neutral content helpers preserve the two distinct metadata levels. */
	public function test_content_helpers_build_identity_safe_neutral_arrays(): void {
		$block = ContentBlockHelper::embedded_text_resource(
			'file:///readme',
			'hello',
			'text/plain',
			null,
			array( 'block' => true ),
			array( 'resource' => true )
		);

		$this->assertSame( 'resource', $block['type'] );
		$this->assertTrue( $block['_meta']['block'] );
		$this->assertTrue( $block['resource']['_meta']['resource'] );
		$this->assertArrayNotHasKey( '_meta', ContentBlockHelper::text( 'hello', null, array( 'list' ) ) );
	}

	/**
	 * @param class-string<\WP\McpSchema\Record> $record_class Request record class.
	 * @return \WP\McpSchema\Record
	 */
	private function request_2025_11_25( string $record_class, string $method, int $id, array $params = array() ): Record {
		$data = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => $method,
		);
		if ( ! empty( $params ) ) {
			$data['params'] = $params;
		}

		return $this->schema()->fromArray( $record_class, $data );
	}
}
