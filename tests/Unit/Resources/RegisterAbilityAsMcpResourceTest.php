<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpResourceTest extends TestCase {

	public function test_make_builds_resource_from_ability(): void {
		$ability  = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );
		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$arr      = $resource->to_array();
		$this->assertSame( 'WordPress://local/resource-1', $arr['uri'] );
		$this->assertSame( $ability, $resource->get_ability() );
	}
}
