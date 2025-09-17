<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Tools;

use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpToolTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function test_make_builds_tool_from_ability(): void {
		$ability = wp_get_ability( 'test/always-allowed' );
		$tool    = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$arr     = $tool->to_array();
		$this->assertSame( 'test-always-allowed', $arr['name'] );
		$this->assertArrayHasKey( 'inputSchema', $arr );
	}
}
