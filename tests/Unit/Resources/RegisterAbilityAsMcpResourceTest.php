<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpResourceTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	private function makeServer(): McpServer {
		return new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);
	}

	public function test_make_builds_resource_from_ability(): void {
		$ability  = wp_get_ability( 'test/resource' );
		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$arr      = $resource->to_array();
		$this->assertSame( 'WordPress://local/resource-1', $arr['uri'] );
		$this->assertSame( $ability, $resource->get_ability() );
	}
}
