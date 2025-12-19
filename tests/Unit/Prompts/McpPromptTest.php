<?php

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\Prompt;

/**
 * Tests for McpPrompt fluent builder and array configuration.
 */
final class McpPromptTest extends TestCase {

	// =========================================================================
	// Fluent API Tests
	// =========================================================================

	public function test_fluent_api_creates_prompt(): void {
		$prompt = McpPrompt::create( 'test-fluent' )
			->title( 'Test Fluent Prompt' )
			->description( 'A prompt created with fluent API' )
			->argument( 'code', 'The code to review', true )
			->argument( 'language', 'Programming language' )
			->handler(
				function ( array $args ): array {
					return array( 'result' => 'success', 'args' => $args );
				}
			);

		$dto = $prompt->get_component();

		$this->assertInstanceOf( Prompt::class, $dto );
		$this->assertSame( 'test-fluent', $dto->getName() );
		$this->assertSame( 'Test Fluent Prompt', $dto->getTitle() );
		$this->assertSame( 'A prompt created with fluent API', $dto->getDescription() );

		$arguments = $dto->getArguments();
		$this->assertCount( 2, $arguments );
		$this->assertSame( 'code', $arguments[0]->getName() );
		$this->assertTrue( $arguments[0]->getRequired() );
		$this->assertSame( 'language', $arguments[1]->getName() );
		$this->assertNull( $arguments[1]->getRequired() );
	}

	public function test_fluent_api_handler_is_executed(): void {
		$prompt = McpPrompt::create( 'handler-test' )
			->handler(
				function ( array $args ): array {
					return array(
						'received' => $args,
						'computed' => $args['value'] * 2,
					);
				}
			);

		$result = $prompt->execute( array( 'value' => 21 ) );

		$this->assertSame( 21, $result['received']['value'] );
		$this->assertSame( 42, $result['computed'] );
	}

	public function test_fluent_api_permission_callback(): void {
		$prompt = McpPrompt::create( 'permission-test' )
			->handler( fn( $args ) => array() )
			->permission(
				function ( array $args ): bool {
					return $args['allowed'] ?? false;
				}
			);

		$this->assertTrue( $prompt->check_permission( array( 'allowed' => true ) ) );
		$this->assertFalse( $prompt->check_permission( array( 'allowed' => false ) ) );
		$this->assertFalse( $prompt->check_permission( array() ) );
	}

	public function test_fluent_api_no_default_permission_denies_access(): void {
		$prompt = McpPrompt::create( 'no-permission-test' )
			->handler( fn( $args ) => array() );

		// Without explicit permission callback, access should be denied.
		$result = $prompt->check_permission( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	public function test_fluent_api_explicit_permission_allows_access(): void {
		$prompt = McpPrompt::create( 'explicit-permission-test' )
			->handler( fn( $args ) => array() )
			->permission( fn() => true );

		$this->assertTrue( $prompt->check_permission( array() ) );
		$this->assertTrue( $prompt->check_permission( array( 'any' => 'value' ) ) );
	}

	public function test_fluent_api_required_argument_shorthand(): void {
		$prompt = McpPrompt::create( 'required-arg-test' )
			->argument( 'input', 'Required input', true )
			->argument( 'optional', 'Optional input' )
			->handler( fn( $args ) => array() );

		$dto       = $prompt->get_component();
		$arguments = $dto->getArguments();

		$this->assertCount( 2, $arguments );
		$this->assertTrue( $arguments[0]->getRequired() );
		$this->assertNull( $arguments[1]->getRequired() );
	}

	public function test_fluent_api_with_icons(): void {
		$prompt = McpPrompt::create( 'icons-test' )
			->handler( fn( $args ) => array() )
			->icons(
				array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
				)
			);

		$dto = $prompt->get_component();
		$arr = $dto->toArray();

		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/icon.png', $arr['icons'][0]['src'] );
	}

	public function test_fluent_api_with_meta(): void {
		$prompt = McpPrompt::create( 'meta-test' )
			->handler( fn( $args ) => array() )
			->meta(
				array(
					'custom_key' => 'custom_value',
					'mcp_adapter' => array( 'allowed' => true ),
					'nested'     => array( 'a' => 1 ),
				)
			);

		$dto = $prompt->get_component();
		$arr = $dto->toArray();

		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'custom_value', $arr['_meta']['custom_key'] );
		$this->assertSame( array( 'a' => 1 ), $arr['_meta']['nested'] );
		$this->assertSame( array( 'allowed' => true ), $arr['_meta']['mcp_adapter'] );
	}

	// =========================================================================
	// Array Configuration Tests
	// =========================================================================

	public function test_array_config_creates_prompt(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'        => 'test-array',
				'title'       => 'Test Array Prompt',
				'description' => 'A prompt created from array config',
				'arguments'   => array(
					array(
						'name'        => 'input',
						'description' => 'Input value',
						'required'    => true,
					),
				),
				'handler'     => function ( array $args ): array {
					return array( 'result' => 'from-array' );
				},
			)
		);

		$dto = $prompt->get_component();

		$this->assertSame( 'test-array', $dto->getName() );
		$this->assertSame( 'Test Array Prompt', $dto->getTitle() );
		$this->assertSame( 'A prompt created from array config', $dto->getDescription() );

		$arguments = $dto->getArguments();
		$this->assertCount( 1, $arguments );
		$this->assertSame( 'input', $arguments[0]->getName() );
		$this->assertTrue( $arguments[0]->getRequired() );
	}

	public function test_array_config_handler_is_executed(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'array-handler-test',
				'handler' => function ( array $args ): array {
					return array( 'doubled' => $args['n'] * 2 );
				},
			)
		);

		$result = $prompt->execute( array( 'n' => 10 ) );

		$this->assertSame( 20, $result['doubled'] );
	}

	public function test_array_config_with_permission(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'array-permission-test',
				'handler'    => fn( $args ) => array(),
				'permission' => function ( array $args ): bool {
					return $args['secret'] === 'password';
				},
			)
		);

		$this->assertTrue( $prompt->check_permission( array( 'secret' => 'password' ) ) );
		$this->assertFalse( $prompt->check_permission( array( 'secret' => 'wrong' ) ) );
	}

	public function test_array_config_throws_without_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Prompt configuration must include a "name" field' );

		McpPrompt::fromArray(
			array(
				'handler' => fn( $args ) => array(),
			)
		);
	}

	public function test_array_config_throws_without_handler(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Prompt configuration must include a callable "handler" field' );

		McpPrompt::fromArray(
			array(
				'name' => 'no-handler',
			)
		);
	}

	public function test_array_config_with_icons_and_meta(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'    => 'full-config-test',
				'handler' => fn( $args ) => array(),
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

		$dto = $prompt->get_component();
		$arr = $dto->toArray();

		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'test', $arr['_meta']['vendor'] );
	}

	// =========================================================================
	// Server Registration Tests
	// =========================================================================

	public function test_fluent_prompt_can_be_registered_with_server(): void {
		$fluent_prompt = McpPrompt::create( 'fluent-server-test' )
			->title( 'Fluent Server Test' )
			->handler( fn( $args ) => array( 'source' => 'fluent' ) );

		$server = $this->makeServer( array(), array(), array( $fluent_prompt ) );

		$prompts = $server->get_prompts();
		$this->assertArrayHasKey( 'fluent-server-test', $prompts );

		$mcp_prompt = $server->get_mcp_prompt( 'fluent-server-test' );
		$this->assertNotNull( $mcp_prompt );

		$result = $mcp_prompt->execute( array() );
		$this->assertSame( 'fluent', $result['source'] );
	}

	public function test_array_config_can_be_registered_with_server(): void {
		$array_config = array(
			'name'    => 'array-server-test',
			'title'   => 'Array Server Test',
			'handler' => fn( $args ) => array( 'source' => 'array' ),
		);

		$server = $this->makeServer( array(), array(), array( $array_config ) );

		$prompts = $server->get_prompts();
		$this->assertArrayHasKey( 'array-server-test', $prompts );

		$mcp_prompt = $server->get_mcp_prompt( 'array-server-test' );
		$this->assertNotNull( $mcp_prompt );

		$result = $mcp_prompt->execute( array() );
		$this->assertSame( 'array', $result['source'] );
	}

	public function test_mixed_registration_formats(): void {
		$fluent_prompt = McpPrompt::create( 'fluent-mixed' )
			->handler( fn( $args ) => array( 'type' => 'fluent' ) );

		$array_config = array(
			'name'    => 'array-mixed',
			'handler' => fn( $args ) => array( 'type' => 'array' ),
		);

		// Mix: fluent instance, array config, and class name.
		$server = $this->makeServer(
			array(),
			array(),
			array(
				$fluent_prompt,
				$array_config,
				TestPrompt::class, // From existing test file.
			)
		);

		$prompts = $server->get_prompts();

		$this->assertArrayHasKey( 'fluent-mixed', $prompts );
		$this->assertArrayHasKey( 'array-mixed', $prompts );
		$this->assertArrayHasKey( 'test-prompt', $prompts );
	}

	// =========================================================================
	// Interface Implementation Tests
	// =========================================================================

	public function test_getters_return_correct_values(): void {
		$prompt = McpPrompt::create( 'getter-test' )
			->title( 'Getter Title' )
			->description( 'Getter Description' )
			->argument( 'arg1', 'First argument', true )
			->icons(
				array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
				)
			)
			->meta( array( 'key' => 'value' ) )
			->handler( fn( $args ) => array() );

		$this->assertSame( 'Getter Title', $prompt->get_name() );

		$dto = $prompt->get_component();
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
		$prompt = McpPrompt::create( 'minimal-test' )
			->handler( fn( $args ) => array() );

		$this->assertSame( 'minimal-test', $prompt->get_name() );

		$dto = $prompt->get_component();
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
	 * Verify that no default handler is set.
	 * Prompts must explicitly configure a handler or ability.
	 */
	public function test_no_default_handler_returns_error(): void {
		$prompt = McpPrompt::create( 'no-handler-prompt' )
			->permission( fn() => true );

		$result = $prompt->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_prompt_no_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'No prompt execution strategy', $result->get_error_message() );
	}

	/**
	 * Verify that no default permission callback is set.
	 * Prompts must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$prompt = McpPrompt::create( 'no-permission-prompt' )
			->handler( fn( $args ) => array() );

		$result = $prompt->check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}
}
