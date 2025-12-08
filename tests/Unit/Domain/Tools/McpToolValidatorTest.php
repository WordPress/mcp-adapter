<?php
/**
 * Tests for McpToolValidator class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Tools;

use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolValidator;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Tests\TestCase;

/**
 * Test McpToolValidator functionality.
 */
final class McpToolValidatorTest extends TestCase {

	public function test_validate_tool_data_with_valid_data(): void {
		$valid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool for validation',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'param1' => array( 'type' => 'string' ),
					'param2' => array( 'type' => 'number' ),
				),
				'required'   => array( 'param1' ),
			),
			'title'       => 'Test Tool',
			'annotations' => array( 'readOnlyHint' => true ),
		);

		$result = McpToolValidator::validate_tool_data( $valid_tool_data, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_validate_tool_data_with_missing_name(): void {
		$invalid_tool_data = array(
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool validation failed', $result->get_error_message() );
		$this->assertStringContainsString( 'Tool name is required', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_invalid_name(): void {
		$invalid_tool_data = array(
			'name'        => 'invalid name with spaces!',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool name is required and must only contain letters, numbers, hyphens (-), and underscores (_)', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_missing_description(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool description is required', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_invalid_input_schema(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => 'not-an-object',
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool inputSchema must be a valid JSON schema object', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_invalid_input_schema_type(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array(
				'type' => 'string', // Should be 'object' for input schemas
			),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'must use type', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_invalid_output_schema(): void {
		$invalid_tool_data = array(
			'name'         => 'test-tool',
			'description'  => 'A test tool',
			'inputSchema'  => array( 'type' => 'object' ),
			'outputSchema' => 'not-an-object',
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool outputSchema must be a valid JSON schema object', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_invalid_annotations(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => 'not-an-array',
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool annotations must be an array if provided', $result->get_error_message() );
	}

	public function test_validate_tool_name_with_valid_names(): void {
		$valid_names = array(
			'simple-tool',
			'tool_with_underscores',
			'tool123',
			'a',
			'very-long-tool-name-that-is-still-under-255-characters',
		);

		foreach ( $valid_names as $name ) {
			$this->assertTrue( McpValidator::validate_tool_or_prompt_name( $name ), "Name '{$name}' should be valid" );
		}
	}

	public function test_validate_tool_name_with_invalid_names(): void {
		$invalid_names = array(
			'',                           // Empty
			'tool with spaces',           // Spaces
			'tool@invalid',              // Special characters
			'tool.invalid',              // Dots
			str_repeat( 'a', 256 ),      // Too long
		);

		foreach ( $invalid_names as $name ) {
			$this->assertFalse( McpValidator::validate_tool_or_prompt_name( $name ), "Name '{$name}' should be invalid" );
		}
	}

	public function test_validate_tool_instance_with_valid_tool(): void {
		$server = $this->makeServer();

		$tool = new McpTool(
			'test/valid-tool',
			'valid-tool',
			'A valid test tool',
			array( 'type' => 'object' ),
			'Valid Tool'
		);
		$tool->set_mcp_server( $server );

		$result = McpToolValidator::validate_tool_instance( $tool, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_validate_tool_requires_server_before_validation(): void {
		$tool = new McpTool(
			'test/missing-server',
			'missing-server',
			'A tool without a server',
			array( 'type' => 'object' )
		);

		$result = $tool->validate();
		$this->assertWPError( $result );
		$this->assertSame( 'tool_missing_mcp_server', $result->get_error_code() );
	}

	public function test_validate_tool_uniqueness_method_exists(): void {
		// Test that the uniqueness validation method exists and is callable
		$server = $this->makeServer();
		$tool   = new McpTool(
			'test/test-tool',
			'test-tool',
			'Test tool',
			array( 'type' => 'object' )
		);
		$tool->set_mcp_server( $server );

		// The method should exist and be callable
		$this->assertTrue( method_exists( McpToolValidator::class, 'validate_tool_uniqueness' ) );

		// Should return true for unique tool
		$result = McpToolValidator::validate_tool_uniqueness( $tool, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_get_validation_errors_returns_array(): void {
		$invalid_data = array(
			'name'        => '',
			'description' => '',
			'inputSchema' => null,
		);

		$errors = McpToolValidator::get_validation_errors( $invalid_data );

		$this->assertIsArray( $errors );
		$this->assertNotEmpty( $errors );
		$this->assertGreaterThan( 2, count( $errors ) ); // Should have multiple validation errors
	}

	public function test_is_valid_tool_data_with_valid_data(): void {
		$valid_data = array(
			'name'        => 'valid-tool',
			'description' => 'A valid tool',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$this->assertTrue( empty( McpToolValidator::get_validation_errors( $valid_data ) ) );
	}

	public function test_is_valid_tool_data_with_invalid_data(): void {
		$invalid_data = array(
			'name'        => '',
			'description' => '',
		);

		$this->assertFalse( empty( McpToolValidator::get_validation_errors( $invalid_data ) ) );
	}

	public function test_validate_tool_data_with_complex_schema(): void {
		$complex_tool_data = array(
			'name'         => 'complex-tool',
			'description'  => 'A complex tool with detailed schema',
			'inputSchema'  => array(
				'type'       => 'object',
				'properties' => array(
					'stringParam' => array(
						'type'        => 'string',
						'description' => 'A string parameter',
					),
					'numberParam' => array(
						'type'    => 'number',
						'minimum' => 0,
					),
					'arrayParam'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'stringParam', 'numberParam' ),
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'result' => array( 'type' => 'string' ),
					'status' => array( 'type' => 'boolean' ),
				),
			),
		);

		$result = McpToolValidator::validate_tool_data( $complex_tool_data );
		$this->assertTrue( $result );
	}

	public function test_validate_tool_data_with_invalid_required_field_reference(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'param1' => array( 'type' => 'string' ),
				),
				'required'   => array( 'param1', 'nonexistent_param' ), // References non-existent property
			),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'does not exist in properties', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_valid_mcp_annotations(): void {
		$valid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool with MCP annotations',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
				'title'           => 'Test Annotation',
			),
		);

		$result = McpToolValidator::validate_tool_data( $valid_tool_data );
		$this->assertTrue( $result );
	}

	public function test_validate_tool_data_with_unknown_annotation_field_name(): void {
		// Unknown fields should be ignored (filtered out by mapper before validation)
		$valid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'readonly' => true, // Unknown field, should be ignored
			),
		);

		$result = McpToolValidator::validate_tool_data( $valid_tool_data );

		$this->assertTrue( $result, 'Unknown annotation fields should be ignored' );
	}

	public function test_validate_tool_data_with_invalid_annotation_boolean_type(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'readOnlyHint' => 'yes', // Should be boolean, not string
			),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool annotation field readOnlyHint must be a boolean', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_invalid_annotation_string_type(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'title' => 123, // Should be string, not number
			),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool annotation field title must be a string', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_empty_annotation_title(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'title' => '   ', // Empty/whitespace-only string
			),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool annotation field title must be a non-empty string', $result->get_error_message() );
	}

	public function test_validate_tool_data_with_multiple_annotation_errors(): void {
		$invalid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'readonly'      => true,    // Invalid field name
				'readOnlyHint'  => 'yes',   // Invalid type
				'invalidField'  => true,    // Unknown field
			),
		);

		$result = McpToolValidator::validate_tool_data( $invalid_tool_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_validation_failed', $result->get_error_code() );

		$error_message = $result->get_error_message();
		// Unknown fields are ignored, only type errors are reported
		$this->assertStringContainsString( 'Tool annotation field readOnlyHint must be a boolean', $error_message );
	}

	public function test_validate_tool_data_with_partial_mcp_annotations(): void {
		// Should be valid - not all annotation fields are required
		$valid_tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
			'annotations' => array(
				'readOnlyHint' => true,
			),
		);

		$result = McpToolValidator::validate_tool_data( $valid_tool_data );
		$this->assertTrue( $result );
	}
}
