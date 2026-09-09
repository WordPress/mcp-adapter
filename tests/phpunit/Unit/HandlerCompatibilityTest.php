<?php
/**
 * Adapter handler compatibility contracts.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Record;
use WP\McpSchema\Schemas;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\GetPromptRequest;
use WP\McpSchema\Record\ListPromptsRequest;
use WP\McpSchema\Record\ListResourcesRequest;
use WP\McpSchema\Record\ListToolsRequest;
use WP\McpSchema\Record\ReadResourceRequest;
use WP_Error;

/** Protects handler hooks, failure containment, and WordPress result normalization. */
final class HandlerCompatibilityTest extends TestCase {

	/**
	 * Result metadata is validated before serverInfo is added, preserving JSON objects.
	 *
	 * @dataProvider result_metadata_provider
	 * @param mixed $meta Handler metadata.
	 * @param bool $valid Whether the schema accepts the metadata.
	 */
	public function test_modern_prompt_result_metadata( $meta, bool $valid ): void {
		$before = wp_json_encode( $meta );
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'result-meta',
				'handler'    => static fn(): array => array( 'text' => 'hello', '_meta' => $meta ),
				'permission' => '__return_true',
			)
		);
		$server  = $this->makeServer( array(), array(), array( $prompt ) );
		$message = (object) array(
			'jsonrpc' => '2.0',
			'id'      => 30,
			'method'  => 'prompts/get',
			'params'  => (object) array(
				'name'  => 'result-meta',
				'_meta' => (object) array(
					'io.modelcontextprotocol/protocolVersion'     => Schemas::V2026_07_28,
					'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
				),
			),
		);
		$outcome = ( new McpWireOrchestrator( $server->create_transport_context() ) )->process( $message, 'test' );
		$wire    = json_decode( wp_json_encode( $outcome['response'] ) );
		$this->assertSame( $before, wp_json_encode( $meta ), 'Response assembly must not mutate provider metadata.' );
		if ( ! $valid ) {
			$this->assertObjectHasProperty( 'error', $wire );
			$this->assertSame( -32603, $wire->error->code );
			$this->assertSame( 'Internal error: Invalid handler result', $wire->error->message );
			$this->assertObjectNotHasProperty( 'result', $wire );
			return;
		}

		$this->assertObjectHasProperty( 'result', $wire );
		$this->assertSame( 'hello', $wire->result->messages[0]->content->text );
		$this->assertInstanceOf( \stdClass::class, $wire->result->_meta );
		$this->assertSame( 'Srv', $wire->result->_meta->{'io.modelcontextprotocol/serverInfo'}->name );
		$this->assertSame( '0.0.1', $wire->result->_meta->{'io.modelcontextprotocol/serverInfo'}->version );
		foreach ( (array) $meta as $key => $value ) {
			if ( 'io.modelcontextprotocol/serverInfo' === $key ) {
				continue;
			}
			$this->assertSame( wp_json_encode( $value ), wp_json_encode( $wire->result->_meta->{$key} ) );
		}
	}

	/** @return array<string, array{0: mixed, 1: bool}> */
	public static function result_metadata_provider(): array {
		return array(
			'null counts as absent' => array( null, true ),
			'empty array'          => array( array(), true ),
			'empty object'         => array( new \stdClass(), true ),
			'associative array'    => array( array( 'vendor' => array( 'value' => 7 ) ), true ),
			'object'               => array( (object) array( 'vendor' => (object) array( 'value' => 7 ) ), true ),
			'numeric object key'   => array( (object) array( '0' => 'keep' ), true ),
			'existing serverInfo'  => array( array( 'io.modelcontextprotocol/serverInfo' => array( 'name' => 'provider', 'version' => '1' ) ), true ),
			'invalid serverInfo'   => array( array( 'io.modelcontextprotocol/serverInfo' => 'bad' ), false ),
			'list'                 => array( array( 'bad-list' ), false ),
			'string'               => array( 'bad', false ),
			'number'               => array( 42, false ),
			'boolean'              => array( false, false ),
		);
	}

	/** Recognized malformed prompt shapes fail at the wire instead of becoming fallback text. */
	public function test_malformed_prompt_shapes_fail_in_both_revisions(): void {
		foreach ( array(
			array( 'messages' => 'bad' ),
			array( 'texts' => 'bad' ),
			array( 'text' => 5 ),
			array( 'text' => 'ok', 'description' => 5 ),
			array( 'text' => 'ok', 'annotations' => 'bad' ),
			array( 'text' => 'ok', '_meta' => array( 'bad-list' ) ),
		) as $shape ) {
			$prompt = McpPrompt::fromArray( array( 'name' => 'invalid-shape', 'handler' => static fn(): array => $shape, 'permission' => '__return_true' ) );
			$server = $this->makeServer( array(), array(), array( $prompt ) );
			foreach ( array( Schemas::V2025_11_25, Schemas::V2026_07_28 ) as $revision ) {
				$params = array( 'name' => 'invalid-shape' );
				if ( Schemas::V2026_07_28 === $revision ) {
					$params['_meta'] = array(
						'io.modelcontextprotocol/protocolVersion'     => $revision,
						'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
					);
				}
				$error = $this->wire_error( $server, 'prompts/get', 20, $params );
				$this->assertSame( -32603, $error['code'] );
				$this->assertSame( 'Internal error: Invalid handler result', $error['message'] );
			}
		}
	}

	/** Message conveniences retain extra keys and reindex associative message lists. */
	public function test_prompt_messages_retain_extra_keys_and_result_meta(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'message-keys',
				'handler'    => static fn(): array => array(
					'messages' => array( 'key' => array( 'content' => 'hello', 'extra' => 1, '_meta' => array( 'message' => true ) ) ),
					'_meta'    => array( 'result' => true ),
				),
				'permission' => '__return_true',
			)
		);
		$server  = $this->makeServer( array(), array(), array( $prompt ) );
		$request = $this->schema()->fromArray( GetPromptRequest::class, array( 'jsonrpc' => '2.0', 'id' => 21, 'method' => 'prompts/get', 'params' => array( 'name' => 'message-keys' ) ) );
		$result  = ( new PromptsHandler( $server ) )->get_prompt( $request, $this->request_context( $server ) );
		$this->assertSame( array( 0 ), array_keys( $result['messages'] ) );
		$this->assertSame( 1, $result['messages'][0]['extra'] );
		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertSame( 'hello', $result['messages'][0]['content']['text'] );
		$this->assertTrue( $result['messages'][0]['_meta']['message'] );
		$this->assertTrue( $result['_meta']['result'] );
	}

	/** A recursive fallback value produces an execution error rather than invented JSON. */
	public function test_prompt_json_encoding_failure_is_contained(): void {
		$recursive         = new \stdClass();
		$recursive->child  = $recursive;
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'recursive-prompt',
				'handler'    => static fn(): array => array( 'custom' => $recursive ),
				'permission' => '__return_true',
			)
		);
		$server = $this->makeServer( array(), array(), array( $prompt ) );
		$error  = $this->wire_error( $server, 'prompts/get', 22, array( 'name' => 'recursive-prompt' ) );
		$this->assertSame( -32603, $error['code'] );
		$this->assertStringContainsString( 'Prompt execution failed', $error['message'] );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}


	/** Non-array list-filter results fall back to the original lists and are logged. */
	public function test_invalid_list_filters_fall_back_to_original_components(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array( 'test/resource' ), array( 'test/prompt' ) );
		$schema  = $this->schema();
		$context = $this->request_context( $server );
		$filter  = static fn(): string => 'invalid';
		foreach ( array( 'mcp_adapter_tools_list', 'mcp_adapter_resources_list', 'mcp_adapter_prompts_list' ) as $hook ) {
			add_filter( $hook, $filter );
		}

		try {
			$tools = ( new ToolsHandler( $server ) )->list_tools(
				$schema->fromArray(
					ListToolsRequest::class,
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/list',
					)
				),
				$context
			);
			$resources = ( new ResourcesHandler( $server ) )->list_resources(
				$schema->fromArray(
					ListResourcesRequest::class,
					array(
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'resources/list',
					)
				),
				$context
			);
			$prompts = ( new PromptsHandler( $server ) )->list_prompts(
				$schema->fromArray(
					ListPromptsRequest::class,
					array(
						'jsonrpc' => '2.0',
						'id'      => 3,
						'method'  => 'prompts/list',
					)
				),
				$context
			);
		} finally {
			foreach ( array( 'mcp_adapter_tools_list', 'mcp_adapter_resources_list', 'mcp_adapter_prompts_list' ) as $hook ) {
				remove_filter( $hook, $filter );
			}
		}

		$this->assertCount( 1, $tools['tools'] );
		$this->assertCount( 1, $resources['resources'] );
		$this->assertCount( 1, $prompts['prompts'] );
		$this->assertCount( 3, DummyErrorHandler::$logs );
		$this->assertSame(
			array( 'mcp_adapter_tools_list', 'mcp_adapter_resources_list', 'mcp_adapter_prompts_list' ),
			array_column( array_column( DummyErrorHandler::$logs, 'context' ), 'filter' )
		);
	}

	/** Tool pre/result hooks can modify values and WP_Error short-circuits execution. */
	public function test_tool_execution_hooks_modify_and_short_circuit(): void {
		$executions = 0;
		$tool       = McpTool::fromArray(
			array(
				'name'        => 'hooked-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static function ( array $arguments ) use ( &$executions ): array {
					++$executions;

					return $arguments;
				},
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server  = $this->makeServer( array( $tool ) );
		$handler = new ToolsHandler( $server );
		$request = $this->schema()->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'hooked-tool',
					'arguments' => array( 'original' => true ),
				),
			)
		);
		$pre    = static fn(): array => array( 'modified' => true );
		$result = static function ( array $value ): array {
			$value['filtered'] = true;

			return $value;
		};
		add_filter( 'mcp_adapter_pre_tool_call', $pre );
		add_filter( 'mcp_adapter_tool_call_result', $result );
		try {
			$response = $handler->call_tool( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_pre_tool_call', $pre );
			remove_filter( 'mcp_adapter_tool_call_result', $result );
		}

		$this->assertSame( 1, $executions );
		$this->assertTrue( $response['structuredContent']->modified );
		$this->assertTrue( $response['structuredContent']->filtered );

		$block = static fn(): WP_Error => new WP_Error( 'blocked', 'Blocked before execution' );
		add_filter( 'mcp_adapter_pre_tool_call', $block );
		try {
			$blocked = $handler->call_tool( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_pre_tool_call', $block );
		}

		$this->assertSame( 1, $executions );
		$this->assertTrue( $blocked['isError'] );
		$this->assertSame( 'Blocked before execution', $blocked['content'][0]['text'] );
	}

	/** Resource results preserve advertised URI, text/blob variants, and metadata as given. */
	public function test_resource_result_normalization_preserves_identity_and_metadata(): void {
		$resource = McpResource::fromArray(
			array(
				'name'       => 'Mixed resource',
				'uri'        => 'Fixture://Mixed/Resource',
				'handler'    => static fn(): array => array(
					array(
						'text'     => 'hello',
						'mimeType' => 'text/plain',
						'_meta'    => array( 'ui' => array( 'border' => true ) ),
					),
					array(
						'blob'  => 'YmxvYg==',
						'_meta' => array( 'invalid-list-meta' ),
					),
				),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$server   = $this->makeServer( array(), array( $resource ) );
		$request  = $this->schema()->fromArray(
			ReadResourceRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'fixture://Mixed/Resource' ),
			)
		);
		$response = ( new ResourcesHandler( $server ) )->read_resource( $request, $this->request_context( $server ) );

		$this->assertCount( 2, $response['contents'] );
		$this->assertSame( 'Fixture://Mixed/Resource', $response['contents'][0]['uri'] );
		$this->assertSame( 'hello', $response['contents'][0]['text'] );
		$this->assertTrue( $response['contents'][0]['_meta']['ui']['border'] );
		$this->assertSame( 'YmxvYg==', $response['contents'][1]['blob'] );
		$this->assertSame( array( 'invalid-list-meta' ), $response['contents'][1]['_meta'] );

		// The list-shaped _meta is not repaired; the schema rejects the whole result on the wire.
		$error = $this->wire_error( $server, 'resources/read', 5, array( 'uri' => 'fixture://Mixed/Resource' ) );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
		$this->assertSame( 'Internal error: Invalid handler result', $error['message'] );
	}

	/** Invalid prompt roles, content types, and metadata are not repaired; the wire result fails. */
	public function test_prompt_result_normalization_degrades_invalid_role_and_content(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'normalizing-prompt',
				'handler'    => static fn(): array => array(
					'messages' => array(
						array(
							'role'    => 'system',
							'content' => array(
								'type'  => 'unsupported',
								'text'  => 'fallback text',
								'_meta' => array( 'invalid-list-meta' ),
							),
						),
					),
				),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$server   = $this->makeServer( array(), array(), array( $prompt ) );
		$request  = $this->schema()->fromArray(
			GetPromptRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'prompts/get',
				'params'  => array(
					'name'      => 'normalizing-prompt',
					'arguments' => array(),
				),
			)
		);
		$response = ( new PromptsHandler( $server ) )->get_prompt( $request, $this->request_context( $server ) );

		$this->assertSame( 'system', $response['messages'][0]['role'] );
		$this->assertSame( 'unsupported', $response['messages'][0]['content']['type'] );
		$this->assertSame( array( 'invalid-list-meta' ), $response['messages'][0]['content']['_meta'] );
		$this->assertSame( array(), DummyErrorHandler::$logs );

		$error = $this->wire_error( $server, 'prompts/get', 6, array( 'name' => 'normalizing-prompt' ) );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
		$this->assertSame( 'Internal error: Invalid handler result', $error['message'] );
	}

	/**
	 * Run one request through the wire orchestrator and return its error member.
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return array{code: int, message: string}
	 */
	private function wire_error( McpServer $server, string $method, int $id, array $params ): array {
		$message = json_decode(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);
		$this->assertInstanceOf( \stdClass::class, $message );

		$outcome  = ( new McpWireOrchestrator( $server->create_transport_context() ) )->process(
			$message,
			'test',
			array(),
			array( 'capabilities' => array() )
		);
		$response = $outcome['response'];
		$this->assertInstanceOf( Record::class, $response );
		$data = $this->record_array( $response );
		$this->assertArrayHasKey( 'error', $data );

		return array(
			'code'    => (int) $data['error']['code'],
			'message' => (string) $data['error']['message'],
		);
	}

	/** Prompt filters retain mutation and fail-closed short-circuit semantics. */
	public function test_prompt_execution_filters_modify_and_short_circuit(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'filtered-prompt',
				'handler'    => static fn( array $arguments ): array => array( 'text' => 'handler:' . ( $arguments['value'] ?? '' ) ),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$server  = $this->makeServer( array(), array(), array( $prompt ) );
		$handler = new PromptsHandler( $server );
		$request = $this->schema()->fromArray(
			GetPromptRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 10,
				'method'  => 'prompts/get',
				'params'  => array( 'name' => 'filtered-prompt', 'arguments' => array( 'value' => 'original' ) ),
			)
		);
		$pre    = static fn(): array => array( 'value' => 'modified' );
		$result = static function ( array $value ): array {
			$value['text'] .= ':filtered';

			return $value;
		};
		add_filter( 'mcp_adapter_pre_prompt_get', $pre );
		add_filter( 'mcp_adapter_prompt_get_result', $result );
		try {
			$response = $handler->get_prompt( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_pre_prompt_get', $pre );
			remove_filter( 'mcp_adapter_prompt_get_result', $result );
		}
		$this->assertSame( 'handler:modified:filtered', $response['messages'][0]['content']['text'] );

		$block = static fn(): WP_Error => new WP_Error( 'blocked', 'Prompt blocked' );
		add_filter( 'mcp_adapter_pre_prompt_get', $block );
		try {
			$blocked = $handler->get_prompt( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_pre_prompt_get', $block );
		}
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $blocked['error']['code'] );
		$this->assertStringContainsString( 'Prompt blocked', $blocked['error']['message'] );

		$error_result = static fn(): WP_Error => new WP_Error( 'failed', 'Prompt result failed' );
		add_filter( 'mcp_adapter_prompt_get_result', $error_result );
		try {
			$failed = $handler->get_prompt( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_prompt_get_result', $error_result );
		}
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $failed['error']['code'] );
		$this->assertStringContainsString( 'Prompt result failed', $failed['error']['message'] );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	/** Prompt convenience result shapes all normalize to canonical messages. */
	public function test_prompt_convenience_result_shapes_remain_supported(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'        => 'shape-prompt',
				'description' => 'Default description',
				'handler'     => static fn(): array => array( 'text' => 'unused' ),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$server  = $this->makeServer( array(), array(), array( $prompt ) );
		$handler = new PromptsHandler( $server );
		$request = $this->schema()->fromArray(
			GetPromptRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 11,
				'method'  => 'prompts/get',
				'params'  => array( 'name' => 'shape-prompt', 'arguments' => array() ),
			)
		);
		$shape  = array();
		$filter = static function () use ( &$shape ): array {
			return $shape;
		};
		add_filter( 'mcp_adapter_prompt_get_result', $filter );

		try {
			$shape = array(
				'text'        => 'plain text',
				'description' => 'Filtered description',
				'annotations' => array( 'audience' => array( 'user' ) ),
			);
			$text = $handler->get_prompt( $request, $this->request_context( $server ) );
			$this->assertSame( 'plain text', $text['messages'][0]['content']['text'] );
			$this->assertSame( 'Filtered description', $text['description'] );
			$this->assertSame( array( 'user' ), $text['messages'][0]['content']['annotations']['audience'] );

			$shape  = array( 'role' => 'assistant', 'content' => 'single content' );
			$single = $handler->get_prompt( $request, $this->request_context( $server ) );
			$this->assertSame( 'assistant', $single['messages'][0]['role'] );
			$this->assertSame( 'single content', $single['messages'][0]['content']['text'] );

			// Every entry is carried as given; a non-string text is left for the schema to reject.
			$shape = array( 'texts' => array( 'one', 2, 'two' ), 'role' => 'assistant' );
			$multi = $handler->get_prompt( $request, $this->request_context( $server ) );
			$this->assertCount( 3, $multi['messages'] );
			$this->assertSame( 2, $multi['messages'][1]['content']['text'] );
			$this->assertSame( 'two', $multi['messages'][2]['content']['text'] );

			$shape    = array( 'custom' => 123 );
			$fallback = $handler->get_prompt( $request, $this->request_context( $server ) );
			$this->assertStringContainsString( '"custom": 123', $fallback['messages'][0]['content']['text'] );

			// A non-array message is not skipped; it stays in place for the schema to reject.
			$shape   = array( 'messages' => array( 'invalid message' ) );
			$invalid = $handler->get_prompt( $request, $this->request_context( $server ) );
			$this->assertSame( array( 'invalid message' ), $invalid['messages'] );

			// An empty message list is emitted as given; the schema and the official client accept it.
			$shape = array( 'messages' => array() );
			$empty = $handler->get_prompt( $request, $this->request_context( $server ) );
			$this->assertSame( array(), $empty['messages'] );
		} finally {
			remove_filter( 'mcp_adapter_prompt_get_result', $filter );
		}

		$this->assertNotEmpty( \WP\MCP\Tests\Fixtures\DummyObservabilityHandler::$events );
		$this->assertSame( array(), DummyErrorHandler::$logs );
	}

	/** Prompt resource content keeps block and nested metadata as given. */
	public function test_prompt_resource_content_preserves_distinct_metadata_levels(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'resource-prompt',
				'handler'    => static fn(): array => array(
					'messages' => array(
						array(
							'role'    => 'assistant',
							'content' => array(
								'type'     => 'resource',
								'_meta'    => array( 'block' => true ),
								'resource' => array(
									'uri'   => 'fixture://prompt-resource',
									'text'  => 'resource body',
									'_meta' => array( 'invalid-list-meta' ),
								),
							),
						),
					),
				),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$server   = $this->makeServer( array(), array(), array( $prompt ) );
		$request  = $this->schema()->fromArray(
			GetPromptRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 12,
				'method'  => 'prompts/get',
				'params'  => array( 'name' => 'resource-prompt', 'arguments' => array() ),
			)
		);
		$response = ( new PromptsHandler( $server ) )->get_prompt( $request, $this->request_context( $server ) );
		$content  = $response['messages'][0]['content'];

		$this->assertSame( 'resource', $content['type'] );
		$this->assertTrue( $content['_meta']['block'] );
		$this->assertSame( 'fixture://prompt-resource', $content['resource']['uri'] );
		$this->assertSame( array( 'invalid-list-meta' ), $content['resource']['_meta'] );
	}

	/** Missing prompts retain their protocol error contract. */
	public function test_missing_prompt_returns_protocol_error(): void {
		$server   = $this->makeServer();
		$request  = $this->schema()->fromArray(
			GetPromptRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 13,
				'method'  => 'prompts/get',
				'params'  => array( 'name' => 'missing-prompt', 'arguments' => array() ),
			)
		);
		$response = ( new PromptsHandler( $server ) )->get_prompt( $request, $this->request_context( $server ) );

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
	}

	/** Resource hooks mutate callback input and fail closed on WP_Error or exceptions. */
	public function test_resource_execution_filters_modify_and_fail_closed(): void {
		$seen     = array();
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'fixture://filtered-resource',
				'handler'    => static function ( array $arguments ) use ( &$seen ): string {
					$seen = $arguments;

					return 'handler result';
				},
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$server  = $this->makeServer( array(), array( $resource ) );
		$handler = new ResourcesHandler( $server );
		$request = $this->schema()->fromArray(
			ReadResourceRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 14,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => 'fixture://filtered-resource' ),
			)
		);
		$pre    = static function ( array $arguments ): array {
			$arguments['modified'] = true;

			return $arguments;
		};
		$result = static fn(): string => 'filtered result';
		add_filter( 'mcp_adapter_pre_resource_read', $pre );
		add_filter( 'mcp_adapter_resource_read_result', $result );
		try {
			$response = $handler->read_resource( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_pre_resource_read', $pre );
			remove_filter( 'mcp_adapter_resource_read_result', $result );
		}
		$this->assertTrue( $seen['modified'] );
		$this->assertSame( 'filtered result', $response['contents'][0]['text'] );

		$block = static fn(): WP_Error => new WP_Error( 'blocked', 'Resource blocked' );
		add_filter( 'mcp_adapter_pre_resource_read', $block );
		try {
			$blocked = $handler->read_resource( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_pre_resource_read', $block );
		}
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $blocked['error']['code'] );

		$error_result = static fn(): WP_Error => new WP_Error( 'failed', 'Resource result failed' );
		add_filter( 'mcp_adapter_resource_read_result', $error_result );
		try {
			$failed = $handler->read_resource( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_resource_read_result', $error_result );
		}
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $failed['error']['code'] );
		$this->assertStringContainsString( 'Resource result failed', $failed['error']['message'] );

		$throwing_result = static function (): void {
			throw new \RuntimeException( 'Resource filter threw' );
		};
		add_filter( 'mcp_adapter_resource_read_result', $throwing_result );
		try {
			$thrown = $handler->read_resource( $request, $this->request_context( $server ) );
		} finally {
			remove_filter( 'mcp_adapter_resource_read_result', $throwing_result );
		}
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $thrown['error']['code'] );
		$this->assertStringContainsString( 'Failed to read resource', $thrown['error']['message'] );
	}

	/** Tool result compatibility forms remain normalized and fail closed. */
	public function test_tool_result_compatibility_forms_remain_supported(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'result-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static fn(): array => array( 'base' => true ),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$server  = $this->makeServer( array( $tool ) );
		$handler = new ToolsHandler( $server );
		$request = $this->schema()->fromArray(
			CallToolRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 15,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'result-tool', 'arguments' => array() ),
			)
		);
		$shape  = null;
		$filter = static function () use ( &$shape ) {
			return $shape;
		};
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		try {
			$shape  = new WP_Error( 'failed', 'Tool result failed' );
			$failed = $handler->call_tool( $request, $this->request_context( $server ) );
			$this->assertTrue( $failed['isError'] );
			$this->assertSame( 'Tool result failed', $failed['content'][0]['text'] );

			$shape  = array( 'success' => false, 'error' => 'Legacy failure' );
			$legacy = $handler->call_tool( $request, $this->request_context( $server ) );
			$this->assertTrue( $legacy['isError'] );
			$this->assertSame( 'Legacy failure', $legacy['content'][0]['text'] );

			$shape  = (object) array( 'objectValue' => true );
			$object = $handler->call_tool( $request, $this->request_context( $server ) );
			$this->assertInstanceOf( \stdClass::class, $object['structuredContent'] );
			$this->assertTrue( $object['structuredContent']->objectValue );

			$shape        = new \stdClass();
			$empty_object = $handler->call_tool( $request, $this->request_context( $server ) );
			$this->assertInstanceOf( \stdClass::class, $empty_object['structuredContent'] );
			$this->assertSame( '{}', $empty_object['content'][0]['text'] );
			$this->assertSame( '{}', wp_json_encode( $empty_object['structuredContent'] ) );

			$shape  = 'scalar value';
			$scalar = $handler->call_tool( $request, $this->request_context( $server ) );
			$this->assertSame( 'scalar value', $scalar['structuredContent']->result );

			$shape = array(
				'type'     => 'resource',
				'uri'      => 'fixture://blob',
				'blob'     => 'YmxvYg==',
				'mimeType' => 'application/octet-stream',
				'_meta'    => array( 'resource' => true ),
			);
			$blob  = $handler->call_tool( $request, $this->request_context( $server ) );
			$this->assertSame( 'resource', $blob['content'][0]['type'] );
			$this->assertSame( 'YmxvYg==', $blob['content'][0]['resource']['blob'] );
			$this->assertTrue( $blob['content'][0]['resource']['_meta']['resource'] );
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $filter );
		}
	}

	/** Handler permission failures retain tool-result versus protocol-error semantics. */
	public function test_handler_permission_failures_remain_fail_closed(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'denied-tool',
				'handler' => static fn(): array => array( 'should-not-run' => true ),
			)
		);
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'fixture://denied',
				'handler' => static fn(): string => 'should not run',
			)
		);
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'denied-prompt',
				'handler' => static fn(): array => array( 'text' => 'should not run' ),
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$this->assertInstanceOf( McpResource::class, $resource );
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$server  = $this->makeServer( array( $tool ), array( $resource ), array( $prompt ) );
		$schema  = $this->schema();
		$context = $this->request_context( $server );

		$tool_result = ( new ToolsHandler( $server ) )->call_tool(
			$schema->fromArray(
				CallToolRequest::class,
				array(
					'jsonrpc' => '2.0',
					'id'      => 7,
					'method'  => 'tools/call',
					'params'  => array( 'name' => 'denied-tool', 'arguments' => array() ),
				)
			),
			$context
		);
		$resource_result = ( new ResourcesHandler( $server ) )->read_resource(
			$schema->fromArray(
				ReadResourceRequest::class,
				array(
					'jsonrpc' => '2.0',
					'id'      => 8,
					'method'  => 'resources/read',
					'params'  => array( 'uri' => 'fixture://denied' ),
				)
			),
			$context
		);
		$prompt_result = ( new PromptsHandler( $server ) )->get_prompt(
			$schema->fromArray(
				GetPromptRequest::class,
				array(
					'jsonrpc' => '2.0',
					'id'      => 9,
					'method'  => 'prompts/get',
					'params'  => array( 'name' => 'denied-prompt', 'arguments' => array() ),
				)
			),
			$context
		);

		$this->assertTrue( $tool_result['isError'] );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $resource_result['error']['code'] );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $prompt_result['error']['code'] );
	}
}
