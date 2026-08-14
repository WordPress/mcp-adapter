<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Tests\TestCase;

final class SystemHandlerTest extends TestCase {

	public function test_ping_returns_empty_array(): void {
		$handler = new SystemHandler();
		$this->assertSame( array(), $handler->ping() );
	}
}
