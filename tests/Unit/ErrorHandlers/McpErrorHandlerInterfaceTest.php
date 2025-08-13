<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\ErrorHandlers;

use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Tests\TestCase;

final class McpErrorHandlerInterfaceTest extends TestCase
{
    public function test_error_log_handler_implements_interface(): void
    {
        $handler = new ErrorLogMcpErrorHandler();
        $this->assertInstanceOf(McpErrorHandlerInterface::class, $handler);
    }

    public function test_null_handler_implements_interface(): void
    {
        $handler = new NullMcpErrorHandler();
        $this->assertInstanceOf(McpErrorHandlerInterface::class, $handler);
    }

    public function test_error_log_handler_can_log(): void
    {
        $handler = new ErrorLogMcpErrorHandler();
        
        // This should not throw an exception
        $handler->log('Test message');
        $handler->log('Test with context', ['key' => 'value']);
        $handler->log('Test with type', [], 'info');
        
        $this->assertTrue(true); // If we get here, no exceptions were thrown
    }

    public function test_null_handler_can_log(): void
    {
        $handler = new NullMcpErrorHandler();
        
        // This should not throw an exception and should do nothing
        $handler->log('Test message');
        $handler->log('Test with context', ['key' => 'value']);
        $handler->log('Test with type', [], 'warning');
        
        $this->assertTrue(true); // If we get here, no exceptions were thrown
    }

    public function test_interface_method_signature(): void
    {
        $reflection = new \ReflectionClass(McpErrorHandlerInterface::class);
        $method = $reflection->getMethod('log');
        
        $this->assertSame('log', $method->getName());
        $this->assertSame(3, $method->getNumberOfParameters());
        
        $parameters = $method->getParameters();
        
        // Check first parameter (message)
        $this->assertSame('message', $parameters[0]->getName());
        $this->assertSame('string', $parameters[0]->getType()->getName());
        $this->assertFalse($parameters[0]->isOptional());
        
        // Check second parameter (context)
        $this->assertSame('context', $parameters[1]->getName());
        $this->assertSame('array', $parameters[1]->getType()->getName());
        $this->assertTrue($parameters[1]->isOptional());
        
        // Check third parameter (type)
        $this->assertSame('type', $parameters[2]->getName());
        $this->assertSame('string', $parameters[2]->getType()->getName());
        $this->assertTrue($parameters[2]->isOptional());
    }
}
