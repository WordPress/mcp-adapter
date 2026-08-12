<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
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

		$dto = $prompt->get_protocol_dto();

		$this->assertInstanceOf( PromptDto::class, $dto );
		$this->assertSame( 'test-array', $dto->getName() );
		$this->assertSame( 'Test Array Prompt', $dto->getTitle() );
		$this->assertSame( 'A prompt created from array config', $dto->getDescription() );

		$arguments = $dto->getArguments();
		$this->assertCount( 2, $arguments );
		$this->assertSame( 'code', $arguments[0]->getName() );
		$this->assertTrue( $arguments[0]->getRequired() );
		$this->assertSame( 'language', $arguments[1]->getName() );
		$this->assertNull( $arguments[1]->getRequired() );
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

		$dto = $prompt->get_protocol_dto();
		$arr = $dto->toArray();

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

		$dto = $prompt->get_protocol_dto();
		$arr = $dto->toArray();

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

		$dto = $prompt->get_protocol_dto();
		$arr = $dto->toArray();

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

		$dto = $prompt->get_protocol_dto();
		$this->assertSame( 'getter-test', $dto->getName() );
		$this->assertSame( 'Getter Title', $dto->getTitle() );
		$this->assertSame( 'Getter Description', $dto->getDescription() );
		$this->assertCount( 1, $dto->getArguments() );
		$this->assertSame( 'arg1', $dto->getArguments()[0]->getName() );

		$arr = $dto->toArray();
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

		$dto = $prompt->get_protocol_dto();
		$this->assertSame( 'minimal-test', $dto->getName() );
		$this->assertNull( $dto->getTitle() );
		$this->assertNull( $dto->getDescription() );
		$this->assertNull( $dto->getArguments() );
		$this->assertNull( $dto->getIcons() );
		$this->assertNull( $dto->get_meta() );
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
