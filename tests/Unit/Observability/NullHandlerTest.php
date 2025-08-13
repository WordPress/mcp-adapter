<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Observability;

use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class NullHandlerTest extends TestCase
{
    public function test_record_event_and_timing_are_callable(): void
    {
        NullMcpObservabilityHandler::record_event('mcp.test', ['k' => 'v']);
        NullMcpObservabilityHandler::record_timing('mcp.test.timing', 1.23, ['a' => 'b']);
        $this->assertTrue(true);
    }
}


