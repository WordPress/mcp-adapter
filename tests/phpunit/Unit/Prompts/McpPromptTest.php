<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Continuation\McpContinuationContext;
use WP\MCP\Domain\Continuation\McpExecutionResult;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Tests\TestCase;
use WP_Error;

/**
 * Tests for McpPrompt array configuration.
 */
final class McpPromptTest extends TestCase {


	// =========================================================================
	// fromArray Tests
	// =========================================================================

	public function test_fromArray_creates_prompt(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'        => 'test-array',
				'title'       => 'Test Array Prompt',
				'description' => 'A prompt created from array config',
				'arguments'   => array(
					array(
						'name'        => 'code',
						'description' => 'The code to review',
						'required'    => true,
					),
					array(
						'name'        => 'language',
						'description' => 'Programming language',
					),
				),
				'handler'     => static function ( array $args ): array {
					return array(
						'result' => 'success',
						'args'   => $args,
					);
				},
			)
		);

		$data = $prompt->get_protocol_data();

		$this->assertSame( 'test-array', $data['name'] );
		$this->assertSame( 'Test Array Prompt', $data['title'] );
		$this->assertSame( 'A prompt created from array config', $data['description'] );

		$arguments = $data['arguments'];
		$this->assertCount( 2, $arguments );
		$this->assertSame( 'code', $arguments[0]['name'] );
		$this->assertTrue( $arguments[0]['required'] );
		$this->assertSame( 'language', $arguments[1]['name'] );
		$this->assertArrayNotHasKey( 'required', $arguments[1] );
	}

	public function test_fromArray_handler_is_executed(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'handler-test',
				'handler' => static function ( array $args ): array {
					return array(
						'received' => $args,
						'computed' => $args['value'] * 2,
					);
				},
			)
		);

		$result = $prompt->execute( array( 'value' => 21 ) );

		$this->assertSame( 21, $result['received']['value'] );
		$this->assertSame( 42, $result['computed'] );
	}

	public function test_execute_passes_continuation_to_opt_in_handler_and_preserves_result(): void {
		$context = new McpContinuationContext( array(), 'prompt-state' );
		$prompt  = McpPrompt::fromArray(
			array(
				'name'    => 'continuation-prompt',
				'handler' => static function ( array $arguments, ?McpContinuationContext $continuation ): McpExecutionResult {
					return McpExecutionResult::input_required( array(), $continuation ? $continuation->get_request_state() : null );
				},
			)
		);

		$result = $prompt->execute( array(), $context );

		$this->assertInstanceOf( McpExecutionResult::class, $result );
		$this->assertSame( 'prompt-state', $result->get_request_state() );
	}

	public function test_fromArray_permission_callback(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'permission-test',
				'handler'    => static fn( $args ) => array(),
				'permission' => static function ( array $args ): bool {
					return $args['allowed'] ?? false;
				},
			)
		);

		$this->assertTrue( $prompt->check_permission( array( 'allowed' => true ) ) );
		$this->assertFalse( $prompt->check_permission( array( 'allowed' => false ) ) );
		$this->assertFalse( $prompt->check_permission( array() ) );
	}

	public function test_fromArray_no_permission_denies_access(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'no-permission-test',
				'handler' => static fn( $args ) => array(),
			)
		);

		// Without explicit permission callback, access should be denied.
		$result = $prompt->check_permission( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	public function test_fromArray_explicit_permission_allows_access(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'explicit-permission-test',
				'handler'    => static fn( $args ) => array(),
				'permission' => static fn() => true,
			)
		);

		$this->assertTrue( $prompt->check_permission( array() ) );
		$this->assertTrue( $prompt->check_permission( array( 'any' => 'value' ) ) );
	}

	public function test_fromArray_with_icons(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'icons-test',
				'handler' => static fn( $args ) => array(),
				'icons'   => array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
				),
			)
		);

		$arr = $prompt->get_protocol_data();

		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/icon.png', $arr['icons'][0]['src'] );
	}

	public function test_fromArray_with_meta(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'meta-test',
				'handler' => static fn( $args ) => array(),
				'meta'    => array(
					'custom_key'  => 'custom_value',
					'mcp_adapter' => array( 'allowed' => true ),
					'nested'      => array( 'a' => 1 ),
				),
			)
		);

		$arr = $prompt->get_protocol_data();

		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'custom_value', $arr['_meta']['custom_key'] );
		$this->assertSame( array( 'a' => 1 ), $arr['_meta']['nested'] );
		$this->assertSame( array( 'allowed' => true ), $arr['_meta']['mcp_adapter'] );
	}

	public function test_fromArray_returns_WP_Error_without_name(): void {
		$result = McpPrompt::fromArray(
			array(
				'handler' => static fn( $args ) => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_prompt_missing_name', $result->get_error_code() );
	}

	public function test_fromArray_returns_WP_Error_without_handler(): void {
		$result = McpPrompt::fromArray(
			array(
				'name' => 'no-handler',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_prompt_missing_handler', $result->get_error_code() );
	}

	public function test_fromArray_with_icons_and_meta(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'full-config-test',
				'handler' => static fn( $args ) => array(),
				'icons'   => array(
					array(
						'src'      => 'https://example.com/icon.svg',
						'mimeType' => 'image/svg+xml',
					),
				),
				'meta'    => array(
					'vendor' => 'test',
				),
			)
		);

		$arr = $prompt->get_protocol_data();

		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'test', $arr['_meta']['vendor'] );
	}

	// =========================================================================
	// Server Registration Tests
	// =========================================================================

	public function test_fromArray_prompt_can_be_registered_with_server(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'array-server-test',
				'title'   => 'Array Server Test',
				'handler' => static fn( $args ) => array( 'source' => 'array' ),
			)
		);

		$server = $this->makeServer( array(), array(), array( $prompt ) );

		$prompts = $server->get_prompts();
		$this->assertArrayHasKey( 'array-server-test', $prompts );

		$mcp_prompt = $server->get_mcp_prompt( 'array-server-test' );
		$this->assertNotNull( $mcp_prompt );

		$result = $mcp_prompt->execute( array() );
		$this->assertSame( 'array', $result['source'] );
	}

	// =========================================================================
	// Interface Implementation Tests
	// =========================================================================

	public function test_getters_return_correct_values(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'        => 'getter-test',
				'title'       => 'Getter Title',
				'description' => 'Getter Description',
				'arguments'   => array(
					array(
						'name'        => 'arg1',
						'description' => 'First argument',
						'required'    => true,
					),
				),
				'icons'       => array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
				),
				'meta'        => array( 'key' => 'value' ),
				'handler'     => static fn( $args ) => array(),
			)
		);

		$arr = $prompt->get_protocol_data();
		$this->assertSame( 'getter-test', $arr['name'] );
		$this->assertSame( 'Getter Title', $arr['title'] );
		$this->assertSame( 'Getter Description', $arr['description'] );
		$this->assertCount( 1, $arr['arguments'] );
		$this->assertSame( 'arg1', $arr['arguments'][0]['name'] );
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'value', $arr['_meta']['key'] );
	}

	public function test_defaults_for_optional_fields(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'minimal-test',
				'handler' => static fn( $args ) => array(),
			)
		);

		$data = $prompt->get_protocol_data();
		$this->assertSame( 'minimal-test', $data['name'] );
		$this->assertArrayNotHasKey( 'title', $data );
		$this->assertArrayNotHasKey( 'description', $data );
		$this->assertArrayNotHasKey( 'arguments', $data );
		$this->assertArrayNotHasKey( 'icons', $data );
		$this->assertArrayNotHasKey( '_meta', $data );
	}

	// =========================================================================
	// Secure-by-Default Behavior Tests
	// =========================================================================

	/**
	 * Verify that no default permission callback is set.
	 * Prompts must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'no-permission-prompt',
				'handler' => static fn( $args ) => array(),
			)
		);

		$result = $prompt->check_permission( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	public function test_execute_catches_handler_exceptions(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'throwing-prompt',
				'handler' => static function ( $args ) {
					throw new \RuntimeException( 'Handler exploded' );
				},
			)
		);

		$result = $prompt->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_execution_failed', $result->get_error_code() );
		$this->assertSame( 'Handler exploded', $result->get_error_message() );
	}

	public function test_check_permission_catches_exceptions(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'throwing-permission-prompt',
				'handler'    => static fn( $args ) => array(),
				'permission' => static function () {
					throw new \RuntimeException( 'Permission check exploded' );
				},
			)
		);

		$result = $prompt->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_permission_check_failed', $result->get_error_code() );
		$this->assertSame( 'Permission check exploded', $result->get_error_message() );
	}

	public function test_fromArray_returns_wp_error_when_arguments_throw(): void {
		// Pass invalid argument data that causes an exception during argument processing.
		// The 'name' field is required for prompt arguments; accessing it without the key throws.
		$result = McpPrompt::fromArray(
			array(
				'name'      => 'invalid-arguments-prompt',
				'handler'   => static fn( $args ) => array(),
				'arguments' => array(
					array(
						// Missing 'name' field - accessing $arg['name'] will throw an Undefined array key error.
						'description' => 'An argument without a name',
						'required'    => true,
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_prompt_dto_creation_failed', $result->get_error_code() );
		// PHP 8 throws "Undefined array key 'name'", PHP 7.4 returns NULL triggering "Expected string, got NULL".
		$message = $result->get_error_message();
		$this->assertTrue(
			false !== strpos( $message, 'name' ) || false !== strpos( $message, 'NULL' ),
			"Expected error message to contain 'name' or 'NULL', got: {$message}"
		);
	}

	public function test_fromArray_observability_context(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'observable-prompt',
				'handler' => static fn( $args ) => array(),
			)
		);

		$context = $prompt->get_observability_context();

		$this->assertSame( 'prompt', $context['component_type'] );
		$this->assertSame( 'observable-prompt', $context['prompt_name'] );
		$this->assertSame( 'array', $context['source'] );
	}
}
