<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Tools;

use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\Tool;

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

		$dto = $mcp_tool->get_component();
		$this->assertInstanceOf( Tool::class, $dto );

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

	public function test_fluent_api_executes_handler_and_checks_permission(): void {
		$tool = McpTool::create( 'mcptool-direct' )
			->description( 'Direct callable tool' )
			->inputSchema( array(
				'type'       => 'object',
				'properties' => array(
					'value' => array( 'type' => 'string' ),
				),
			) )
			->handler( static function ( $args ) {
				return array( 'uppercased' => strtoupper( $args['value'] ?? '' ) );
			} )
			->permission( fn() => true );

		$this->assertTrue( $tool->check_permission( array( 'value' => 'hello' ) ) );

		$result = $tool->execute( array( 'value' => 'hello' ) );
		$this->assertNotWPError( $result );
		$this->assertSame( array( 'uppercased' => 'HELLO' ), $result );
	}

	public function test_fluent_api_uses_permission_callback(): void {
		$tool = McpTool::create( 'mcptool-direct-permission' )
			->description( 'Direct callable tool with permission callback' )
			->handler( static function () {
				return array( 'ok' => true );
			} )
			->permission( static function ( $args ) {
				return isset( $args['allowed'] ) && true === $args['allowed'];
			} );

		$this->assertTrue( $tool->check_permission( array( 'allowed' => true ) ) );
		$this->assertFalse( $tool->check_permission( array( 'allowed' => false ) ) );
		$this->assertFalse( $tool->check_permission( array() ) );
	}

	// =========================================================================
	// New Fluent API Tests
	// =========================================================================

	public function test_create_builds_minimal_tool(): void {
		$tool = McpTool::create( 'minimal-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) );

		$dto = $tool->get_component();

		$this->assertInstanceOf( Tool::class, $dto );
		$this->assertSame( 'minimal-tool', $dto->getName() );
		$this->assertNull( $dto->getTitle() );
		$this->assertNull( $dto->getDescription() );

		// Input schema defaults to object type
		$input_schema = $dto->getInputSchema()->toArray();
		$this->assertSame( 'object', $input_schema['type'] );
	}

	public function test_create_with_all_fluent_setters(): void {
		$tool = McpTool::create( 'full-featured-tool' )
			->title( 'Full Featured Tool' )
			->description( 'A comprehensive test tool' )
			->inputSchema( array(
				'type'       => 'object',
				'properties' => array(
					'text' => array( 'type' => 'string' ),
				),
				'required'   => array( 'text' ),
			) )
			->outputSchema( array(
				'type'       => 'object',
				'properties' => array(
					'result' => array( 'type' => 'string' ),
				),
			) )
			->meta( array( 'custom_key' => 'custom_value' ) )
			->handler( fn( $args ) => array( 'result' => strtoupper( $args['text'] ) ) )
			->permission( fn( $args ) => true );

		$dto  = $tool->get_component();
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

	public function test_create_with_annotation_helpers(): void {
		$tool = McpTool::create( 'annotated-tool' )
			->description( 'A tool with annotations' )
			->readOnly()
			->idempotent()
			->handler( fn( $args ) => array( 'ok' => true ) );

		$dto  = $tool->get_component();
		$data = $dto->toArray();

		$this->assertArrayHasKey( 'annotations', $data );
		$this->assertTrue( $data['annotations']['readOnlyHint'] );
		$this->assertTrue( $data['annotations']['idempotentHint'] );
	}

	public function test_create_destructive_tool(): void {
		$tool = McpTool::create( 'destructive-tool' )
			->description( 'Deletes data' )
			->destructive()
			->openWorld()
			->handler( fn( $args ) => array( 'deleted' => true ) );

		$dto  = $tool->get_component();
		$data = $dto->toArray();

		$this->assertArrayHasKey( 'annotations', $data );
		$this->assertTrue( $data['annotations']['destructiveHint'] );
		$this->assertTrue( $data['annotations']['openWorldHint'] );
	}

	public function test_create_with_all_annotations(): void {
		$tool = McpTool::create( 'all-annotations-tool' )
			->annotations( array(
				'title'           => 'Custom Annotation Title',
				'readOnlyHint'    => false,
				'destructiveHint' => true,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			) )
			->handler( fn( $args ) => array( 'ok' => true ) );

		$dto  = $tool->get_component();
		$data = $dto->toArray();

		$this->assertSame( 'Custom Annotation Title', $data['annotations']['title'] );
		$this->assertFalse( $data['annotations']['readOnlyHint'] );
		$this->assertTrue( $data['annotations']['destructiveHint'] );
		$this->assertTrue( $data['annotations']['idempotentHint'] );
		$this->assertFalse( $data['annotations']['openWorldHint'] );
	}

	public function test_create_executes_handler(): void {
		$tool = McpTool::create( 'executable-tool' )
			->inputSchema( array(
				'type'       => 'object',
				'properties' => array(
					'name' => array( 'type' => 'string' ),
				),
			) )
			->handler( fn( $args ) => array( 'greeting' => 'Hello, ' . ( $args['name'] ?? 'World' ) ) );

		$result = $tool->execute( array( 'name' => 'Claude' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'greeting' => 'Hello, Claude' ), $result );
	}

	public function test_create_checks_permission(): void {
		$tool = McpTool::create( 'permission-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) )
			->permission( fn( $args ) => ( $args['secret'] ?? '' ) === 'password123' );

		$this->assertTrue( $tool->check_permission( array( 'secret' => 'password123' ) ) );
		$this->assertFalse( $tool->check_permission( array( 'secret' => 'wrong' ) ) );
		$this->assertFalse( $tool->check_permission( array() ) );
	}

	public function test_no_default_permission_denies_access(): void {
		$tool = McpTool::create( 'no-permission-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) );

		// Without explicit permission callback, access should be denied.
		$result = $tool->check_permission( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	public function test_explicit_permission_allows_access(): void {
		$tool = McpTool::create( 'public-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) )
			->permission( fn() => true );

		// Explicit permission callback allowing access.
		$this->assertTrue( $tool->check_permission( array() ) );
		$this->assertTrue( $tool->check_permission( array( 'any' => 'value' ) ) );
	}

	public function test_create_observability_context(): void {
		$tool = McpTool::create( 'observable-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) );

		// Trigger build by getting component
		$tool->get_component();

		$context = $tool->get_observability_context();

		$this->assertSame( 'tool', $context['component_type'] );
		$this->assertSame( 'observable-tool', $context['tool_name'] );
		$this->assertSame( 'fluent', $context['source'] );
	}

	// =========================================================================
	// fromArray Tests
	// =========================================================================

	public function test_fromArray_creates_tool(): void {
		$tool = McpTool::fromArray( array(
			'name'        => 'array-tool',
			'title'       => 'Array Tool',
			'description' => 'Created from array',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'value' => array( 'type' => 'string' ),
				),
			),
			'handler'     => fn( $args ) => array( 'uppercased' => strtoupper( $args['value'] ?? '' ) ),
			'permission'  => fn( $args ) => true,
			'annotations' => array( 'readOnlyHint' => true ),
		) );

		$dto  = $tool->get_component();
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
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'name' );

		McpTool::fromArray( array(
			'handler' => fn( $args ) => array( 'ok' => true ),
		) );
	}

	public function test_fromArray_requires_handler(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'handler' );

		McpTool::fromArray( array(
			'name' => 'missing-handler-tool',
		) );
	}

	public function test_fromArray_with_meta(): void {
		$tool = McpTool::fromArray( array(
			'name'    => 'meta-tool',
			'meta'    => array( 'version' => '1.0.0' ),
			'handler' => fn( $args ) => array( 'ok' => true ),
		) );

			$dto  = $tool->get_component();
			$data = $dto->toArray();

			$this->assertArrayHasKey( '_meta', $data );
			$this->assertSame( '1.0.0', $data['_meta']['version'] );
	}

	// =========================================================================
	// get_name Tests
	// =========================================================================

	public function test_get_name_returns_title_when_set(): void {
		$tool = McpTool::create( 'tool-id' )
			->title( 'Human Readable Title' )
			->handler( fn( $args ) => array( 'ok' => true ) );

		$this->assertSame( 'Human Readable Title', $tool->get_name() );
	}

	public function test_get_name_returns_name_when_no_title(): void {
		$tool = McpTool::create( 'tool-id' )
			->handler( fn( $args ) => array( 'ok' => true ) );

		$this->assertSame( 'tool-id', $tool->get_name() );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	public function test_execute_catches_handler_exceptions(): void {
		$tool = McpTool::create( 'throwing-tool' )
			->handler( fn( $args ) => throw new \RuntimeException( 'Handler exploded' ) );

		$result = $tool->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_execution_failed', $result->get_error_code() );
		$this->assertSame( 'Handler exploded', $result->get_error_message() );
	}

	public function test_check_permission_catches_exceptions(): void {
		$tool = McpTool::create( 'throwing-permission-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) )
			->permission( fn( $args ) => throw new \RuntimeException( 'Permission check exploded' ) );

		$result = $tool->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_permission_check_failed', $result->get_error_code() );
		$this->assertSame( 'Permission check exploded', $result->get_error_message() );
	}

	// =========================================================================
	// Result Normalization Tests
	// =========================================================================

	public function test_execute_wraps_scalar_results(): void {
		$tool = McpTool::create( 'scalar-result-tool' )
			->handler( fn( $args ) => 'just a string' );

		$result = $tool->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'result' => 'just a string' ), $result );
	}

	public function test_execute_preserves_array_results(): void {
		$tool = McpTool::create( 'array-result-tool' )
			->handler( fn( $args ) => array( 'custom' => 'response', 'items' => array( 1, 2, 3 ) ) );

		$result = $tool->execute( array() );

		$this->assertSame( array( 'custom' => 'response', 'items' => array( 1, 2, 3 ) ), $result );
	}

	// =========================================================================
	// Secure-by-Default Behavior Tests
	// =========================================================================

	/**
	 * Verify that no default handler is set.
	 * Tools must explicitly configure a handler or ability.
	 */
	public function test_no_default_handler_returns_error(): void {
		$tool = McpTool::create( 'no-handler-tool' )
			->permission( fn() => true );

		$result = $tool->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_tool_no_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'No tool execution strategy', $result->get_error_message() );
	}

	/**
	 * Verify that no default permission callback is set.
	 * Tools must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$tool = McpTool::create( 'no-permission-tool' )
			->handler( fn( $args ) => array( 'ok' => true ) );

		$result = $tool->check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}
}
