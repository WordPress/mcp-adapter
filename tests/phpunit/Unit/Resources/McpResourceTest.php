<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;
use WP_Error;

final class McpResourceTest extends TestCase {


	public function test_from_ability_builds_clean_resource_dto_and_adapter_meta(): void {
		$ability = wp_get_ability( 'test/resource-new-meta' );
		$this->assertNotNull( $ability, 'Ability test/resource-new-meta should be registered' );

		$mcp_resource = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $mcp_resource );
		$this->assertInstanceOf( McpResource::class, $mcp_resource );

		$dto = $mcp_resource->get_protocol_dto();
		$this->assertInstanceOf( ResourceDto::class, $dto );

		$arr = $dto->toArray();
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'custom_field', $arr['_meta'] );
		$this->assertSame( 'custom_value', $arr['_meta']['custom_field'] );

		$adapter_meta = $mcp_resource->get_adapter_meta();
		$this->assertArrayHasKey( 'ability', $adapter_meta );
		$this->assertSame( $ability->get_name(), $adapter_meta['ability'] );
	}

	public function test_ability_backed_execute_and_permission_match_legacy_no_args_behavior(): void {
		$ability = wp_get_ability( 'test/resource-plain-string' );
		$this->assertNotNull( $ability, 'Ability test/resource-plain-string should be registered' );

		$mcp_resource = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $mcp_resource );

		$permission = $mcp_resource->check_permission( array( 'uri' => 'WordPress://local/resource-plain-string' ) );
		$this->assertTrue( $permission );

		$result = $mcp_resource->execute( array( 'uri' => 'WordPress://local/resource-plain-string' ) );
		$this->assertSame( 'plain string content', $result );
	}

	public function test_ability_backed_resource_with_object_schema_receives_empty_input(): void {
		$this->register_ability_in_hook(
			'test/resource-object-schema',
			array(
				'label'               => 'Resource Object Schema',
				'description'         => 'Test MCP resource',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function ( $input ) {
					return array(
						'body'  => 'read',
						'input' => $input,
					);
				},
				'permission_callback' => static function ( array $input ) {
					return array() === $input;
				},
				'meta'                => array(
					'mcp' => array(
						'type' => 'resource',
						'uri'  => 'WordPress://local/resource-object-schema',
					),
				),
			)
		);

		$ability = wp_get_ability( 'test/resource-object-schema' );
		$this->assertNotNull( $ability );

		$mcp_resource = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $mcp_resource );

		$permission = $mcp_resource->check_permission( array( 'uri' => 'WordPress://local/resource-object-schema' ) );
		$this->assertTrue( $permission );

		$result = $mcp_resource->execute( array( 'uri' => 'WordPress://local/resource-object-schema' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'read', $result['body'] );
		$this->assertSame( array(), $result['input'] );

		wp_unregister_ability( 'test/resource-object-schema' );
	}

	public function test_ability_backed_resource_honors_a_top_level_schema_default(): void {
		$this->register_ability_in_hook(
			'test/resource-schema-default',
			array(
				'label'               => 'Resource Schema Default',
				'description'         => 'Test MCP resource',
				'category'            => 'test',
				'input_schema'        => array(
					'type'    => 'object',
					'default' => array( 'scope' => 'site' ),
				),
				'execute_callback'    => static function ( $input ) {
					return $input;
				},
				'permission_callback' => static function ( array $input ) {
					return array( 'scope' => 'site' ) === $input;
				},
				'meta'                => array(
					'mcp' => array(
						'type' => 'resource',
						'uri'  => 'WordPress://local/resource-schema-default',
					),
				),
			)
		);

		$ability = wp_get_ability( 'test/resource-schema-default' );
		$this->assertNotNull( $ability );

		$mcp_resource = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $mcp_resource );

		$permission = $mcp_resource->check_permission( array( 'uri' => 'WordPress://local/resource-schema-default' ) );
		$this->assertTrue( $permission );

		$result = $mcp_resource->execute( array( 'uri' => 'WordPress://local/resource-schema-default' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( array( 'scope' => 'site' ), $result );

		wp_unregister_ability( 'test/resource-schema-default' );
	}

	public function test_permission_callback_supports_zero_arg_callable(): void {
		$mcp_resource = McpResource::fromArray(
			array(
				'uri'        => 'WordPress://local/custom',
				'title'      => 'Custom',
				'handler'    => static function ( $args ) {
					return $args;
				},
				'permission' => static function (): bool {
					return true;
				},
			)
		);

		$this->assertTrue( $mcp_resource->check_permission( array( 'anything' => true ) ) );
	}

	public function test_fromArray_meta_preserves_all_keys(): void {
		$mcp_resource = McpResource::fromArray(
			array(
				'uri'     => 'WordPress://local/meta-test',
				'meta'    => array(
					'mcp_adapter' => array( 'should_not' => 'leak' ),
					'foo'         => 'bar',
				),
				'handler' => static function ( $args ) {
					return $args;
				},
			)
		);

		$dto = $mcp_resource->get_protocol_dto();
		$arr = $dto->toArray();

		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'foo', $arr['_meta'] );
		$this->assertSame( 'bar', $arr['_meta']['foo'] );
		$this->assertSame( array( 'should_not' => 'leak' ), $arr['_meta']['mcp_adapter'] );
	}

	// =========================================================================
	// fromArray Tests
	// =========================================================================

	public function test_fromArray_builds_minimal_resource(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'WordPress://local/minimal',
				'handler' => static fn() => 'content',
			)
		);

		$dto = $resource->get_protocol_dto();

		$this->assertInstanceOf( ResourceDto::class, $dto );
		$this->assertSame( 'WordPress://local/minimal', $dto->getUri() );
		// Name defaults to URI when not provided
		$this->assertSame( 'WordPress://local/minimal', $dto->getName() );
	}

	public function test_fromArray_with_all_options(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'         => 'WordPress://local/full',
				'name'        => 'full-resource',
				'title'       => 'Full Resource',
				'description' => 'A comprehensive test resource',
				'mimeType'    => 'text/plain',
				'size'        => 1024,
				'annotations' => array(
					'audience' => array( 'user' ),
					'priority' => 0.8,
				),
				'meta'        => array( 'version' => '1.0' ),
				'handler'     => static fn() => 'full content',
				'permission'  => static fn() => true,
			)
		);

		$dto  = $resource->get_protocol_dto();
		$data = $dto->toArray();

		$this->assertSame( 'WordPress://local/full', $dto->getUri() );
		$this->assertSame( 'full-resource', $dto->getName() );
		$this->assertSame( 'Full Resource', $dto->getTitle() );
		$this->assertSame( 'A comprehensive test resource', $dto->getDescription() );
		$this->assertSame( 'text/plain', $dto->getMimeType() );
		$this->assertSame( 1024, $dto->getSize() );
		$this->assertArrayHasKey( 'annotations', $data );
		$this->assertArrayHasKey( '_meta', $data );
		$this->assertSame( '1.0', $data['_meta']['version'] );
	}

	public function test_fromArray_requires_uri(): void {
		$result = McpResource::fromArray(
			array(
				'handler' => static fn() => 'content',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_resource_missing_uri', $result->get_error_code() );
	}

	public function test_fromArray_requires_handler(): void {
		$result = McpResource::fromArray(
			array(
				'uri' => 'WordPress://local/no-handler',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_resource_missing_handler', $result->get_error_code() );
	}

	public function test_fromArray_validates_uri(): void {
		$result = McpResource::fromArray(
			array(
				'uri'     => 'invalid-no-scheme',
				'handler' => static fn() => 'content',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_resource_invalid_uri', $result->get_error_code() );
	}

	public function test_fromArray_executes_handler(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'WordPress://local/executable',
				'handler' => static fn( $args ) => 'Hello, ' . ( $args['name'] ?? 'World' ),
			)
		);

		$result = $resource->execute( array( 'name' => 'Claude' ) );

		$this->assertSame( 'Hello, Claude', $result );
	}

	public function test_fromArray_checks_permission(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'WordPress://local/permission-test',
				'handler'    => static fn() => 'content',
				'permission' => static fn( $args ) => ( $args['token'] ?? '' ) === 'secret',
			)
		);

		$this->assertTrue( $resource->check_permission( array( 'token' => 'secret' ) ) );
		$this->assertFalse( $resource->check_permission( array( 'token' => 'wrong' ) ) );
		$this->assertFalse( $resource->check_permission( array() ) );
	}

	public function test_fromArray_observability_context(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'WordPress://local/observable',
				'handler' => static fn() => 'content',
			)
		);

		$context = $resource->get_observability_context();

		$this->assertSame( 'resource', $context['component_type'] );
		$this->assertSame( 'WordPress://local/observable', $context['resource_uri'] );
		$this->assertSame( 'array', $context['source'] );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	public function test_execute_catches_handler_exceptions(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'WordPress://local/throwing',
				'handler' => static function () {
					throw new \RuntimeException( 'Handler exploded' );
				},
			)
		);

		$result = $resource->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_execution_failed', $result->get_error_code() );
		$this->assertSame( 'Handler exploded', $result->get_error_message() );
	}

	public function test_check_permission_catches_exceptions(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'WordPress://local/throwing-permission',
				'handler'    => static fn() => 'content',
				'permission' => static function () {
					throw new \RuntimeException( 'Permission check exploded' );
				},
			)
		);

		$result = $resource->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_permission_check_failed', $result->get_error_code() );
		$this->assertSame( 'Permission check exploded', $result->get_error_message() );
	}

	public function test_fromArray_drops_unparseable_annotation_values_instead_of_failing(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'         => 'WordPress://local/invalid-annotations',
				'handler'     => static fn() => 'content',
				'permission'  => static fn() => true,
				'annotations' => array(
					'audience' => array( 'user' ),
					'priority' => 'not-a-float',
				),
			)
		);

		$this->assertNotWPError( $resource );

		$data = $resource->get_protocol_dto()->toArray();

		$this->assertSame( array( 'audience' => array( 'user' ) ), $data['annotations'] );
	}

	public function test_fromArray_coerces_numeric_string_priority_to_a_number(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'         => 'WordPress://local/string-priority',
				'handler'     => static fn() => 'content',
				'permission'  => static fn() => true,
				'annotations' => array( 'priority' => '0.5' ),
			)
		);

		$this->assertNotWPError( $resource );

		$data = $resource->get_protocol_dto()->toArray();
		$this->assertSame( array( 'priority' => 0.5 ), $data['annotations'] );
		$this->assertStringContainsString( '"priority":0.5', (string) wp_json_encode( $data ) );
	}

	public function test_fromArray_omits_out_of_range_annotations(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'         => 'WordPress://local/out-of-spec',
				'handler'     => static fn() => 'content',
				'permission'  => static fn() => true,
				'annotations' => array( 'priority' => 5 ),
			)
		);

		$this->assertNotWPError( $resource );
		$this->assertArrayNotHasKey( 'annotations', $resource->get_protocol_dto()->toArray() );
	}

	/**
	 * @dataProvider data_loosely_typed_sizes
	 *
	 * @param mixed $size     Caller-supplied size value.
	 * @param int   $expected Expected normalized byte count.
	 */
	public function test_fromArray_coerces_loosely_typed_size_to_an_integer( $size, int $expected ): void {
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'WordPress://local/loose-size',
				'handler'    => static fn() => 'content',
				'permission' => static fn() => true,
				'size'       => $size,
			)
		);

		$this->assertNotWPError( $resource );
		$this->assertSame( $expected, $resource->get_protocol_dto()->getSize() );
	}

	/**
	 * @return array<string, array{mixed, int}>
	 */
	public function data_loosely_typed_sizes(): array {
		return array(
			'numeric string' => array( '1024', 1024 ),
			'zero'           => array( 0, 0 ),
		);
	}

	/**
	 * @dataProvider data_invalid_sizes
	 *
	 * @param mixed $size Caller-supplied size value.
	 */
	public function test_fromArray_drops_invalid_size_instead_of_failing( $size ): void {
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'WordPress://local/bad-size',
				'handler'    => static fn() => 'content',
				'permission' => static fn() => true,
				'size'       => $size,
			)
		);

		$this->assertNotWPError( $resource );
		$this->assertArrayNotHasKey( 'size', $resource->get_protocol_dto()->toArray() );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function data_invalid_sizes(): array {
		return array(
			'fraction' => array( '1.9' ),
		);
	}

	public function test_fromArray_omits_annotations_for_unmodelled_vocabulary(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'         => 'WordPress://local/tool-vocabulary',
				'handler'     => static fn() => 'content',
				'permission'  => static fn() => true,
				'annotations' => array( 'readOnlyHint' => true ),
			)
		);

		$this->assertNotWPError( $resource );

		$data = $resource->get_protocol_dto()->toArray();
		$this->assertArrayNotHasKey( 'annotations', $data );
		$this->assertStringNotContainsString( '"annotations":[]', (string) wp_json_encode( $data ) );
	}

	// =========================================================================
	// Secure-by-Default Behavior Tests
	// =========================================================================

	/**
	 * Verify that no default permission callback is set.
	 * Resources must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'WordPress://local/no-permission',
				'handler' => static fn( $args ) => 'content',
			)
		);

		$result = $resource->check_permission( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}
}
