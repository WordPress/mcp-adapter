<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Resources\Resource;

final class McpResourceTest extends TestCase {

	public function test_from_ability_builds_clean_resource_dto_and_adapter_meta(): void {
		$ability = wp_get_ability( 'test/resource-new-meta' );
		$this->assertNotNull( $ability, 'Ability test/resource-new-meta should be registered' );

		$mcp_resource = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $mcp_resource );
		$this->assertInstanceOf( McpResource::class, $mcp_resource );

		$dto = $mcp_resource->get_component();
		$this->assertInstanceOf( Resource::class, $dto );

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

	public function test_permission_callback_supports_zero_arg_callable(): void {
		$mcp_resource = McpResource::fromArray( array(
			'uri'        => 'WordPress://local/custom',
			'title'      => 'Custom',
			'handler'    => static function ( $args ) {
				return $args;
			},
			'permission' => static function (): bool {
				return true;
			},
		) );

		$this->assertTrue( $mcp_resource->check_permission( array( 'anything' => true ) ) );
	}

	public function test_fromArray_meta_preserves_all_keys(): void {
		$mcp_resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/meta-test',
			'meta'    => array(
				'mcp_adapter' => array( 'should_not' => 'leak' ),
				'foo'         => 'bar',
			),
			'handler' => static function ( $args ) {
				return $args;
			},
		) );

		$dto = $mcp_resource->get_component();
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
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/minimal',
			'handler' => fn() => 'content',
		) );

		$dto = $resource->get_component();

		$this->assertInstanceOf( Resource::class, $dto );
		$this->assertSame( 'WordPress://local/minimal', $dto->getUri() );
		// Name defaults to URI when not provided
		$this->assertSame( 'WordPress://local/minimal', $dto->getName() );
	}

	public function test_fromArray_with_all_options(): void {
		$resource = McpResource::fromArray( array(
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
			'handler'     => fn() => 'full content',
			'permission'  => fn() => true,
		) );

		$dto  = $resource->get_component();
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
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'uri' );

		McpResource::fromArray( array(
			'handler' => fn() => 'content',
		) );
	}

	public function test_fromArray_requires_handler(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'handler' );

		McpResource::fromArray( array(
			'uri' => 'WordPress://local/no-handler',
		) );
	}

	public function test_fromArray_validates_uri(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'RFC 3986' );

		McpResource::fromArray( array(
			'uri'     => 'invalid-no-scheme',
			'handler' => fn() => 'content',
		) );
	}

	public function test_fromArray_executes_handler(): void {
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/executable',
			'handler' => fn( $args ) => 'Hello, ' . ( $args['name'] ?? 'World' ),
		) );

		$result = $resource->execute( array( 'name' => 'Claude' ) );

		$this->assertSame( 'Hello, Claude', $result );
	}

	public function test_fromArray_checks_permission(): void {
		$resource = McpResource::fromArray( array(
			'uri'        => 'WordPress://local/permission-test',
			'handler'    => fn() => 'content',
			'permission' => fn( $args ) => ( $args['token'] ?? '' ) === 'secret',
		) );

		$this->assertTrue( $resource->check_permission( array( 'token' => 'secret' ) ) );
		$this->assertFalse( $resource->check_permission( array( 'token' => 'wrong' ) ) );
		$this->assertFalse( $resource->check_permission( array() ) );
	}

	public function test_fromArray_observability_context(): void {
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/observable',
			'handler' => fn() => 'content',
		) );

		$context = $resource->get_observability_context();

		$this->assertSame( 'resource', $context['component_type'] );
		$this->assertSame( 'WordPress://local/observable', $context['resource_uri'] );
		$this->assertSame( 'array', $context['source'] );
	}

	public function test_get_name_returns_title_when_set(): void {
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/named',
			'title'   => 'Human Readable Title',
			'handler' => fn() => 'content',
		) );

		$this->assertSame( 'Human Readable Title', $resource->get_name() );
	}

	public function test_get_name_returns_name_when_no_title(): void {
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/named',
			'name'    => 'resource-name',
			'handler' => fn() => 'content',
		) );

		$this->assertSame( 'resource-name', $resource->get_name() );
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	public function test_execute_catches_handler_exceptions(): void {
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/throwing',
			'handler' => fn() => throw new \RuntimeException( 'Handler exploded' ),
		) );

		$result = $resource->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_execution_failed', $result->get_error_code() );
		$this->assertSame( 'Handler exploded', $result->get_error_message() );
	}

	public function test_check_permission_catches_exceptions(): void {
		$resource = McpResource::fromArray( array(
			'uri'        => 'WordPress://local/throwing-permission',
			'handler'    => fn() => 'content',
			'permission' => fn() => throw new \RuntimeException( 'Permission check exploded' ),
		) );

		$result = $resource->check_permission( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_permission_check_failed', $result->get_error_code() );
		$this->assertSame( 'Permission check exploded', $result->get_error_message() );
	}

	// =========================================================================
	// Secure-by-Default Behavior Tests
	// =========================================================================

	/**
	 * Verify that no default permission callback is set.
	 * Resources must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$resource = McpResource::fromArray( array(
			'uri'     => 'WordPress://local/no-permission',
			'handler' => fn( $args ) => 'content',
		) );

		$result = $resource->check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}
}
