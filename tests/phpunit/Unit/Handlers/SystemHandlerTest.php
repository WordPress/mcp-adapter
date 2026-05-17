<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Protocol\DTO\Result;

final class SystemHandlerTest extends TestCase {

	public function test_ping_returns_empty_array(): void {
		$handler = new SystemHandler();
		$result  = $handler->ping();
		$this->assertInstanceOf( Result::class, $result );
		$this->assertSame( array(), $result->toArray() );
	}
}
