<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Tools;

use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;
use WP_Error;

final class McpToolTest extends TestCase {


	// =========================================================================
	// fromAbility Tests
	// =========================================================================

	public function test_fromAbility_builds_mcp_tool_and_preserves_user_meta(): void {
		$this->register_ability_in_hook(
			'test/mcptool-from-ability',
			array(
				'label'               => 'McpTool From Ability',
				'description'         => 'Test MCP tool',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array( 'ok' => true );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'_meta' => array(
							'public_meta_key' => 'public_meta_value',
						),
					),
				),
			)
		);

		$ability = wp_get_ability( 'test/mcptool-from-ability' );
		$this->assertNotNull( $ability );

		$mcp_tool = McpTool::fromAbility( $ability );
		$this->assertNotWPError( $mcp_tool );

		$dto = $mcp_tool->get_protocol_dto();
		$this->assertInstanceOf( ToolDto::class, $dto );

		$data = $dto->toArray();

		// User-provided _meta is preserved.
		$this->assertArrayHasKey( '_meta', $data );
		$this->assertSame( 'public_meta_value', $data['_meta']['public_meta_key'] );

		// McpTool keeps adapter meta internally.
		$adapter_meta = $mcp_tool->get_adapter_meta();
		$this->assertSame( 'test/mcptool-from-ability', $adapter_meta['ability'] );

		wp_unregister_ability( 'test/mcptool-from-ability' );
	}

	public function test_execute_unwraps_input_and_wraps_output_when_transformed(): void {
		$this->register_ability_in_hook(
			'test/mcptool-flat-schemas',
			array(
				'label'               => 'McpTool Flat Schemas',
				'description'         => 'Flat input/output schemas',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'string' ),
				'output_schema'       => array( 'type' => 'string' ),
				'execute_callback'    => static function ( $input ) {
					return $input;
				},
				'permission_callback' => static function () {
					return true;
				},
			)
		);

		$ability = wp_get_ability( 'test/mcptool-flat-schemas' );
		$this->assertNotNull( $ability );

		$mcp_tool = McpTool::fromAbility( $ability );
		$this->assertNotWPError( $mcp_tool );

		$result = $mcp_tool->execute( array( 'input' => 'hello' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( array( 'result' => 'hello' ), $result );

		wp_unregister_ability( 'test/mcptool-flat-schemas' );
	}

	public function test_check_permission_unwraps_input_when_transformed(): void {
		$this->register_ability_in_hook(
			'test/mcptool-flat-permission',
			array(
				'label'               => 'McpTool Flat Permission',
				'description'         => 'Flat input schema permission test',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'string' ),
				'execute_callback'    => static function ( $input ) {
					return $input;
				},
				'permission_callback' => static function ( $input ) {
					return 'allowed' === $input;
				},
			)
		);

		$ability = wp_get_ability( 'test/mcptool-flat-permission' );
		$this->assertNotNull( $ability );

		$mcp_tool = McpTool::fromAbility( $ability );
		$this->assertNotWPError( $mcp_tool );

		$this->assertTrue( $mcp_tool->check_permission( array( 'input' => 'allowed' ) ) );
		$this->assertFalse( $mcp_tool->check_permission( array( 'input' => 'denied' ) ) );

		wp_unregister_ability( 'test/mcptool-flat-permission' );
	}

	public function test_fromArray_executes_handler_and_checks_permission(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'mcptool-direct',
				'description' => 'Direct callable tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array( 'type' => 'string' ),
					),
				),
				'handler'     => static function ( $args ) {
					return array( 'uppercased' => strtoupper( $args['value'] ?? '' ) );
				},
				'permission'  => static fn() => true,
			)
		);

		$this->assertTrue( $tool->check_permission( array( 'value' => 'hello' ) ) );

		$result = $tool->execute( array( 'value' => 'hello' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( array( 'uppercased' => 'HELLO' ), $result );
	}

	public function test_fromArray_uses_permission_callback(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'mcptool-direct-permission',
				'description' => 'Direct callable tool with permission callback',
				'handler'     => static function () {
					return array( 'ok' => true );
				},
				'permission'  => static function ( $args ) {
					return isset( $args['allowed'] ) && true === $args['allowed'];
				},
			)
		);

		$this->assertTrue( $tool->check_permission( array( 'allowed' => true ) ) );
		$this->assertFalse( $tool->check_permission( array( 'allowed' => false ) ) );
		$this->assertFalse( $tool->check_permission( array() ) );
	}

	// =========================================================================
	// fromArray Tests
	// =========================================================================

	public function test_fromArray_builds_minimal_tool(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'minimal-tool',
				'handler' => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$dto = $tool->get_protocol_dto();

		$this->assertInstanceOf( ToolDto::class, $dto );
		$this->assertSame( 'minimal-tool', $dto->getName() );
		$this->assertNull( $dto->getTitle() );
		$this->assertNull( $dto->getDescription() );

		// Input schema defaults to object type
		$input_schema = $dto->getInputSchema()->toArray();
		$this->assertSame( 'object', $input_schema['type'] );
	}

	public function test_fromArray_with_all_options(): void {
		$tool = McpTool::fromArray(
			array(
				'name'         => 'full-featured-tool',
				'title'        => 'Full Featured Tool',
				'description'  => 'A comprehensive test tool',
				'inputSchema'  => array(
					'type'       => 'object',
					'properties' => array(
						'text' => array( 'type' => 'string' ),
					),
					'required'   => array( 'text' ),
				),
				'outputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'result' => array( 'type' => 'string' ),
					),
				),
				'meta'         => array( 'custom_key' => 'custom_value' ),
				'handler'      => static fn( $args ) => array( 'result' => strtoupper( $args['text'] ) ),
				'permission'   => static fn( $args ) => true,
			)
		);

		$dto  = $tool->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertSame( 'full-featured-tool', $dto->getName() );
		$this->assertSame( 'Full Featured Tool', $dto->getTitle() );
		$this->assertSame( 'A comprehensive test tool', $dto->getDescription() );

		// Check input schema
		$input_schema = $dto->getInputSchema()->toArray();
		$this->assertSame( 'object', $input_schema['type'] );
		$this->assertArrayHasKey( 'text', $input_schema['properties'] );
		$this->assertContains( 'text', $input_schema['required'] );

		// Check output schema
		$this->assertArrayHasKey( 'outputSchema', $data );

		// Check custom meta preserved
		$this->assertArrayHasKey( '_meta', $data );
		$this->assertSame( 'custom_value', $data['_meta']['custom_key'] );
	}

	public function test_fromArray_with_annotations(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'annotated-tool',
				'description' => 'A tool with annotations',
				'annotations' => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'handler'     => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$dto  = $tool->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertArrayHasKey( 'annotations', $data );
		$this->assertTrue( $data['annotations']['readOnlyHint'] );
		$this->assertTrue( $data['annotations']['idempotentHint'] );
	}

	public function test_fromArray_with_destructive_annotations(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'destructive-tool',
				'description' => 'Deletes data',
				'annotations' => array(
					'destructiveHint' => true,
					'openWorldHint'   => true,
				),
				'handler'     => static fn( $args ) => array( 'deleted' => true ),
			)
		);

		$dto  = $tool->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertArrayHasKey( 'annotations', $data );
		$this->assertTrue( $data['annotations']['destructiveHint'] );
		$this->assertTrue( $data['annotations']['openWorldHint'] );
	}

	public function test_fromArray_with_all_annotations(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'all-annotations-tool',
				'annotations' => array(
					'title'           => 'Custom Annotation Title',
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
				'handler'     => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$dto  = $tool->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertSame( 'Custom Annotation Title', $data['annotations']['title'] );
		$this->assertFalse( $data['annotations']['readOnlyHint'] );
		$this->assertTrue( $data['annotations']['destructiveHint'] );
		$this->assertTrue( $data['annotations']['idempotentHint'] );
		$this->assertFalse( $data['annotations']['openWorldHint'] );
	}

	public function test_fromArray_executes_handler(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'executable-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string' ),
					),
				),
				'handler'     => static fn( $args ) => array( 'greeting' => 'Hello, ' . ( $args['name'] ?? 'World' ) ),
			)
		);

		$result = $tool->execute( array( 'name' => 'Claude' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'greeting' => 'Hello, Claude' ), $result );
	}

	public function test_fromArray_checks_permission(): void {
		$tool = McpTool::fromArray(
			array(
				'name'       => 'permission-tool',
				'handler'    => static fn( $args ) => array( 'ok' => true ),
				'permission' => static fn( $args ) => ( $args['secret'] ?? '' ) === 'password123',
			)
		);

		$this->assertTrue( $tool->check_permission( array( 'secret' => 'password123' ) ) );
		$this->assertFalse( $tool->check_permission( array( 'secret' => 'wrong' ) ) );
		$this->assertFalse( $tool->check_permission( array() ) );
	}

	public function test_no_permission_callback_denies_access(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'no-permission-tool',
				'handler' => static fn( $args ) => array( 'ok' => true ),
			)
		);

		// Without explicit permission callback, access should be denied.
		$result = $tool->check_permission( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	public function test_explicit_permission_allows_access(): void {
		$tool = McpTool::fromArray(
			array(
				'name'       => 'public-tool',
				'handler'    => static fn( $args ) => array( 'ok' => true ),
				'permission' => static fn() => true,
			)
		);

		// Explicit permission callback allowing access.
		$this->assertTrue( $tool->check_permission( array() ) );
		$this->assertTrue( $tool->check_permission( array( 'any' => 'value' ) ) );
	}

	public function test_fromArray_observability_context(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'observable-tool',
				'handler' => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$context = $tool->get_observability_context();

		$this->assertSame( 'tool', $context['component_type'] );
		$this->assertSame( 'observable-tool', $context['tool_name'] );
		$this->assertSame( 'array', $context['source'] );
	}

	public function test_fromArray_creates_tool(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'array-tool',
				'title'       => 'Array Tool',
				'description' => 'Created from array',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array( 'type' => 'string' ),
					),
				),
				'handler'     => static fn( $args ) => array( 'uppercased' => strtoupper( $args['value'] ?? '' ) ),
				'permission'  => static fn( $args ) => true,
				'annotations' => array( 'readOnlyHint' => true ),
			)
		);

		$dto  = $tool->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertSame( 'array-tool', $dto->getName() );
		$this->assertSame( 'Array Tool', $dto->getTitle() );
		$this->assertSame( 'Created from array', $dto->getDescription() );
		$this->assertTrue( $data['annotations']['readOnlyHint'] );

		// Execute
		$result = $tool->execute( array( 'value' => 'hello' ) );
		$this->assertSame( array( 'uppercased' => 'HELLO' ), $result );
	}

	public function test_fromArray_requires_name(): void {
		$result = McpTool::fromArray(
			array(
				'handler' => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_tool_missing_name', $result->get_error_code() );
	}

	public function test_fromArray_requires_handler(): void {
		$result = McpTool::fromArray(
			array(
				'name' => 'missing-handler-tool',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_tool_missing_handler', $result->get_error_code() );
	}

	public function test_fromArray_with_meta(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'meta-tool',
				'meta'    => array( 'version' => '1.0.0' ),
				'handler' => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$dto  = $tool->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertArrayHasKey( '_meta', $data );
		$this->assertSame( '1.0.0', $data['_meta']['version'] );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	public function test_execute_catches_handler_exceptions(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'throwing-tool',
				'handler' => static function ( $args ) {
					throw new \RuntimeException( 'Handler exploded' );
				},
			)
		);

		$result = $tool->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_execution_failed', $result->get_error_code() );
		$this->assertSame( 'Handler exploded', $result->get_error_message() );
	}

	public function test_check_permission_catches_exceptions(): void {
		$tool = McpTool::fromArray(
			array(
				'name'       => 'throwing-permission-tool',
				'handler'    => static fn( $args ) => array( 'ok' => true ),
				'permission' => static function ( $args ) {
					throw new \RuntimeException( 'Permission check exploded' );
				},
			)
		);

		$result = $tool->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_permission_check_failed', $result->get_error_code() );
		$this->assertSame( 'Permission check exploded', $result->get_error_message() );
	}

	public function test_fromArray_returns_wp_error_when_annotations_throw(): void {
		// Pass invalid annotations data that causes ToolAnnotations::fromArray() to throw.
		// The 'readOnlyHint' field expects a bool, not a string.
		$result = McpTool::fromArray(
			array(
				'name'        => 'invalid-annotations-tool',
				'handler'     => static fn( $args ) => array( 'ok' => true ),
				'annotations' => array(
					'readOnlyHint' => 'not-a-boolean', // This will cause ToolAnnotations::fromArray() to throw.
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_tool_dto_creation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Expected bool', $result->get_error_message() );
	}

	// =========================================================================
	// Result Normalization Tests
	// =========================================================================

	public function test_execute_wraps_scalar_results(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'scalar-result-tool',
				'handler' => static fn( $args ) => 'just a string',
			)
		);

		$result = $tool->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'result' => 'just a string' ), $result );
	}

	public function test_execute_preserves_array_results(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'array-result-tool',
				'handler' => static fn( $args ) => array(
					'custom' => 'response',
					'items'  => array( 1, 2, 3 ),
				),
			)
		);

		$result = $tool->execute( array() );

		$this->assertSame(
			array(
				'custom' => 'response',
				'items'  => array( 1, 2, 3 ),
			),
			$result
		);
	}

	// =========================================================================
	// Secure-by-Default Behavior Tests
	// =========================================================================

	/**
	 * Verify that no default permission callback is set.
	 * Tools must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$tool = McpTool::fromArray(
			array(
				'name'    => 'no-permission-tool',
				'handler' => static fn( $args ) => array( 'ok' => true ),
			)
		);

		$result = $tool->check_permission( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}
}
