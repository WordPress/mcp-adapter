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

		$dto = $prompt->build();

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

		$result = $prompt->handle( array( 'value' => 21 ) );

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

		$this->assertTrue( $prompt->has_permission( array( 'allowed' => true ) ) );
		$this->assertFalse( $prompt->has_permission( array( 'allowed' => false ) ) );
		$this->assertFalse( $prompt->has_permission( array() ) );
	}

	public function test_fluent_api_default_permission_allows_all(): void {
		$prompt = McpPrompt::create( 'no-permission-test' )
			->handler( fn( $args ) => array() );

		$this->assertTrue( $prompt->has_permission( array() ) );
		$this->assertTrue( $prompt->has_permission( array( 'any' => 'value' ) ) );
	}

	public function test_fluent_api_required_argument_shorthand(): void {
		$prompt = McpPrompt::create( 'required-arg-test' )
			->requiredArgument( 'input', 'Required input' )
			->argument( 'optional', 'Optional input' )
			->handler( fn( $args ) => array() );

		$dto       = $prompt->build();
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

		$dto = $prompt->build();
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
					'nested'     => array( 'a' => 1 ),
				)
			);

		$dto = $prompt->build();
		$arr = $dto->toArray();

		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'custom_value', $arr['_meta']['custom_key'] );
		$this->assertSame( array( 'a' => 1 ), $arr['_meta']['nested'] );
		// Internal adapter key should also be present.
		$this->assertTrue( $arr['_meta']['mcp_adapter']['fluent'] );
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

		$dto = $prompt->build();

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

		$result = $prompt->handle( array( 'n' => 10 ) );

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

		$this->assertTrue( $prompt->has_permission( array( 'secret' => 'password' ) ) );
		$this->assertFalse( $prompt->has_permission( array( 'secret' => 'wrong' ) ) );
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

		$dto = $prompt->build();
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

		$builder = $server->get_prompt_builder( 'fluent-server-test' );
		$this->assertNotNull( $builder );

		$result = $builder->handle( array() );
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

		$builder = $server->get_prompt_builder( 'array-server-test' );
		$this->assertNotNull( $builder );

		$result = $builder->handle( array() );
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

		$this->assertSame( 'getter-test', $prompt->get_name() );
		$this->assertSame( 'Getter Title', $prompt->get_title() );
		$this->assertSame( 'Getter Description', $prompt->get_description() );
		$this->assertCount( 1, $prompt->get_arguments() );
		$this->assertSame( 'arg1', $prompt->get_arguments()[0]['name'] );
		$this->assertCount( 1, $prompt->get_icons() );
		$this->assertSame( 'value', $prompt->get_meta()['key'] );
	}

	public function test_defaults_for_optional_fields(): void {
		$prompt = McpPrompt::create( 'minimal-test' )
			->handler( fn( $args ) => array() );

		$this->assertSame( 'minimal-test', $prompt->get_name() );
		$this->assertNull( $prompt->get_title() );
		$this->assertNull( $prompt->get_description() );
		$this->assertEmpty( $prompt->get_arguments() );
		$this->assertEmpty( $prompt->get_icons() );
		$this->assertEmpty( $prompt->get_meta() );
	}
}
