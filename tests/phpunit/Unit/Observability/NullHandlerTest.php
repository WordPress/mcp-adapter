<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Observability;

use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class NullHandlerTest extends TestCase {

	public function test_record_event_is_callable(): void {
		$handler = new NullMcpObservabilityHandler();
		$handler->record_event( 'mcp.test', array( 'k' => 'v' ) );
		$handler->record_event( 'mcp.test.timing', array( 'a' => 'b' ), 1.23 );
		$this->assertTrue( true );
	}
}
