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
		$mcp_resource = McpResource::create( 'WordPress://local/custom' )
			->title( 'Custom' )
			->handler(
				static function ( $args ) {
					return $args;
				}
			)
			->permission(
				static function (): bool {
					return true;
				}
			);

		$this->assertTrue( $mcp_resource->check_permission( array( 'anything' => true ) ) );
	}

	public function test_fluent_meta_allows_mcp_adapter_key(): void {
		$mcp_resource = McpResource::create( 'WordPress://local/meta-test' )
			->meta(
				array(
					'mcp_adapter' => array( 'should_not' => 'leak' ),
					'foo'         => 'bar',
				)
			)
			->handler(
				static function ( $args ) {
					return $args;
				}
			);

		$dto = $mcp_resource->get_component();
			$arr = $dto->toArray();

			$this->assertArrayHasKey( '_meta', $arr );
			$this->assertArrayHasKey( 'foo', $arr['_meta'] );
			$this->assertSame( 'bar', $arr['_meta']['foo'] );
			$this->assertSame( array( 'should_not' => 'leak' ), $arr['_meta']['mcp_adapter'] );
		}

	// =========================================================================
	// Secure-by-Default Behavior Tests
	// =========================================================================

	/**
	 * Verify that no default handler is set.
	 * Resources must explicitly configure a handler or ability.
	 */
	public function test_no_default_handler_returns_error(): void {
		$resource = McpResource::create( 'WordPress://local/no-handler' )
			->permission( fn() => true );

		$result = $resource->execute( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_resource_no_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'No resource execution strategy', $result->get_error_message() );
	}

	/**
	 * Verify that no default permission callback is set.
	 * Resources must explicitly configure permissions for security.
	 */
	public function test_no_default_permission_returns_error(): void {
		$resource = McpResource::create( 'WordPress://local/no-permission' )
			->handler( fn( $args ) => 'content' );

		$result = $resource->check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
		$this->assertArrayHasKey( 'failure_reason', $result->get_error_data() );
		$this->assertSame( 'no_permission_strategy', $result->get_error_data()['failure_reason'] );
	}
}
