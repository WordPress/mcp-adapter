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

		$wrapper = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $wrapper );
		$this->assertInstanceOf( McpResource::class, $wrapper );

		$dto = $wrapper->get_component();
		$this->assertInstanceOf( Resource::class, $dto );

			$arr = $dto->toArray();
			$this->assertArrayHasKey( '_meta', $arr );
			$this->assertArrayHasKey( 'custom_field', $arr['_meta'] );
			$this->assertSame( 'custom_value', $arr['_meta']['custom_field'] );

			$adapter_meta = $wrapper->get_adapter_meta();
			$this->assertArrayHasKey( 'ability', $adapter_meta );
			$this->assertSame( $ability->get_name(), $adapter_meta['ability'] );
		}

	public function test_ability_backed_execute_and_permission_match_legacy_no_args_behavior(): void {
		$ability = wp_get_ability( 'test/resource-plain-string' );
		$this->assertNotNull( $ability, 'Ability test/resource-plain-string should be registered' );

		$wrapper = McpResource::fromAbility( $ability );
		$this->assertNotWPError( $wrapper );

		$permission = $wrapper->check_permission( array( 'uri' => 'WordPress://local/resource-plain-string' ) );
		$this->assertTrue( $permission );

		$result = $wrapper->execute( array( 'uri' => 'WordPress://local/resource-plain-string' ) );
		$this->assertSame( 'plain string content', $result );
	}

	public function test_permission_callback_supports_zero_arg_callable(): void {
		$wrapper = McpResource::create( 'WordPress://local/custom' )
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

		$this->assertTrue( $wrapper->check_permission( array( 'anything' => true ) ) );
	}

	public function test_fluent_meta_allows_mcp_adapter_key(): void {
		$wrapper = McpResource::create( 'WordPress://local/meta-test' )
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

		$dto = $wrapper->get_component();
			$arr = $dto->toArray();

			$this->assertArrayHasKey( '_meta', $arr );
			$this->assertArrayHasKey( 'foo', $arr['_meta'] );
			$this->assertSame( 'bar', $arr['_meta']['foo'] );
			$this->assertSame( array( 'should_not' => 'leak' ), $arr['_meta']['mcp_adapter'] );
		}
}
