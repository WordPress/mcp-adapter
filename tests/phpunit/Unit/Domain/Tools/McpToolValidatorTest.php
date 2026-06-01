<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Domain\Tools;

use WP\MCP\Domain\Tools\McpToolValidator;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;

/**
 * Tests for McpToolValidator class.
 *
 * @covers \WP\MCP\Domain\Tools\McpToolValidator
 */
final class McpToolValidatorTest extends TestCase {

	// =========================================================================
	// validate_tool_dto Tests
	// =========================================================================

	public function test_validate_tool_dto_with_valid_tool(): void {
		$tool = ToolDto::fromArray(
			array(
				'name'        => 'test-tool',
				'inputSchema' => array( 'type' => 'object' ),
			)
		);

		$result = McpToolValidator::validate_tool_dto( $tool );
		$this->assertTrue( $result );
	}

	public function test_validate_tool_dto_rejects_invalid_name(): void {
		$tool = ToolDto::fromArray(
			array(
				'name'        => 'invalid/name',
				'inputSchema' => array( 'type' => 'object' ),
			)
		);

		$result = McpToolValidator::validate_tool_dto( $tool );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_tool_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Tool name', $result->get_error_message() );
	}

	public function test_validate_tool_dto_rejects_invalid_icons(): void {
		$tool = ToolDto::fromArray(
			array(
				'name'        => 'test-tool',
				'inputSchema' => array( 'type' => 'object' ),
				'icons'       => array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
					array( 'src' => 'invalid-url' ), // Invalid src
				),
			)
		);

		$result = McpToolValidator::validate_tool_dto( $tool );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Icon at index 1', $result->get_error_message() );
	}

	public function test_validate_tool_dto_validates_input_schema(): void {
		$tool = ToolDto::fromArray(
			array(
				'name'        => 'test-tool',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'test_prop' => array( 'type' => 'string' ),
					),
					'required'   => array( 'nonexistent_prop' ), // Required prop not in properties
				),
			)
		);

		$result = McpToolValidator::validate_tool_dto( $tool );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'inputSchema', $result->get_error_message() );
		$this->assertStringContainsString( 'does not exist in properties', $result->get_error_message() );
	}

	public function test_validate_tool_dto_validates_output_schema(): void {
		$tool = ToolDto::fromArray(
			array(
				'name'         => 'test-tool',
				'inputSchema'  => array( 'type' => 'object' ),
				'outputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'result' => array( 'type' => 'string' ),
					),
					'required'   => array( 'missing_field' ), // Required prop not in properties
				),
			)
		);

		$result = McpToolValidator::validate_tool_dto( $tool );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'outputSchema', $result->get_error_message() );
		$this->assertStringContainsString( 'does not exist in properties', $result->get_error_message() );
	}

	// =========================================================================
	// get_validation_errors Tests
	// =========================================================================

	public function test_get_validation_errors_with_valid_tool_data(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'description' => 'A test tool',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_with_missing_name(): void {
		$tool_data = array(
			'inputSchema' => array( 'type' => 'object' ),
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name is required', $errors[0] );
	}

	public function test_get_validation_errors_accepts_optional_description(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'inputSchema' => array( 'type' => 'object' ),
			// No description - should be valid per MCP 2025-11-25 spec
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_rejects_invalid_description_type(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'description' => 123, // Invalid: should be string
			'inputSchema' => array( 'type' => 'object' ),
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'description must be a string', $errors[0] );
	}

	public function test_get_validation_errors_with_missing_input_schema(): void {
		$tool_data = array(
			'name' => 'test-tool',
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'inputSchema', $errors[0] );
	}

	// =========================================================================
	// get_tool_annotation_validation_errors Tests
	// =========================================================================

	public function test_get_tool_annotation_validation_errors_with_valid_annotations(): void {
		$annotations = array(
			'title'           => 'My Tool',
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);

		$errors = McpToolValidator::get_tool_annotation_validation_errors( $annotations );
		$this->assertEmpty( $errors );
	}

	public function test_get_tool_annotation_validation_errors_rejects_non_boolean_hints(): void {
		$annotations = array(
			'readOnlyHint' => 'true', // Invalid: should be boolean
		);

		$errors = McpToolValidator::get_tool_annotation_validation_errors( $annotations );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'readOnlyHint must be a boolean', $errors[0] );
	}

	public function test_get_tool_annotation_validation_errors_rejects_empty_title(): void {
		$annotations = array(
			'title' => '   ', // Invalid: empty after trim
		);

		$errors = McpToolValidator::get_tool_annotation_validation_errors( $annotations );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'title must be a non-empty string', $errors[0] );
	}

	public function test_get_tool_annotation_validation_errors_ignores_unknown_fields(): void {
		$annotations = array(
			'title'        => 'My Tool',
			'unknownField' => 'some value', // Should be ignored
		);

		$errors = McpToolValidator::get_tool_annotation_validation_errors( $annotations );
		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// get_execution_validation_errors Tests
	// =========================================================================

	public function test_get_execution_validation_errors_with_valid_execution(): void {
		$execution = array(
			'taskSupport' => 'optional',
		);

		$errors = McpToolValidator::get_execution_validation_errors( $execution );
		$this->assertEmpty( $errors );
	}

	public function test_get_execution_validation_errors_accepts_all_valid_task_support_values(): void {
		$valid_values = array( 'forbidden', 'optional', 'required' );

		foreach ( $valid_values as $value ) {
			$errors = McpToolValidator::get_execution_validation_errors( array( 'taskSupport' => $value ) );
			$this->assertEmpty( $errors, "Value '$value' should be valid" );
		}
	}

	public function test_get_execution_validation_errors_rejects_invalid_task_support(): void {
		$execution = array(
			'taskSupport' => 'invalid-value',
		);

		$errors = McpToolValidator::get_execution_validation_errors( $execution );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'taskSupport must be one of', $errors[0] );
	}

	public function test_get_execution_validation_errors_rejects_non_string_task_support(): void {
		$execution = array(
			'taskSupport' => true, // Invalid: should be string
		);

		$errors = McpToolValidator::get_execution_validation_errors( $execution );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'taskSupport must be a string', $errors[0] );
	}

	public function test_get_execution_validation_errors_rejects_non_array(): void {
		$errors = McpToolValidator::get_execution_validation_errors( 'not-an-array' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'execution must be an object/array', $errors[0] );
	}

	// =========================================================================
	// Icons Validation Tests
	// =========================================================================

	public function test_get_validation_errors_with_valid_icons(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'inputSchema' => array( 'type' => 'object' ),
			'icons'       => array(
				array(
					'src'      => 'https://example.com/icon.png',
					'mimeType' => 'image/png',
					'sizes'    => array( '48x48' ),
				),
			),
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_rejects_invalid_icons(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'inputSchema' => array( 'type' => 'object' ),
			'icons'       => 'not-an-array', // Invalid
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'icons must be an array', $errors[0] );
	}

	// =========================================================================
	// _meta Validation Tests
	// =========================================================================

	public function test_get_validation_errors_with_valid_meta(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'inputSchema' => array( 'type' => 'object' ),
			'_meta'       => array( 'key' => 'value' ),
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_rejects_invalid_meta(): void {
		$tool_data = array(
			'name'        => 'test-tool',
			'inputSchema' => array( 'type' => 'object' ),
			'_meta'       => 'not-an-array', // Invalid
		);

		$errors = McpToolValidator::get_validation_errors( $tool_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( '_meta must be an object/array', $errors[0] );
	}
}
