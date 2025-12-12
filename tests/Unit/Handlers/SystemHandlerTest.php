<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Client\Roots\ListRootsResult;
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;
use WP\McpSchema\Common\Protocol\Result;
use WP\McpSchema\Server\Core\CompleteResult;

final class SystemHandlerTest extends TestCase {

	public function test_ping_returns_empty_array(): void {
		$handler = new SystemHandler();
		$result  = $handler->ping();
		$this->assertInstanceOf( Result::class, $result );
		$this->assertSame( array(), $result->toArray() );
	}

	public function test_set_logging_level_missing_level_returns_error(): void {
		$handler = new SystemHandler();
		$res     = $handler->set_logging_level( array( 'params' => array() ) );
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $res );
		$this->assertArrayHasKey( 'error', $res->toArray() );
	}

	public function test_complete_and_roots_list_return_expected_shapes(): void {
		$handler = new SystemHandler();

		$completion = $handler->complete();
		$this->assertInstanceOf( CompleteResult::class, $completion );
		$this->assertArrayHasKey( 'completion', $completion->toArray() );

		$roots = $handler->list_roots();
		$this->assertInstanceOf( ListRootsResult::class, $roots );
		$this->assertArrayHasKey( 'roots', $roots->toArray() );
	}
}
