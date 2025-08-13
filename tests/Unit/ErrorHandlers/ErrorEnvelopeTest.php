<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\ErrorHandlers;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;

final class ErrorEnvelopeTest extends TestCase
{
    public function test_error_envelopes_have_consistent_shape(): void
    {
        $err = McpErrorFactory::missing_parameter(0, 'name');
        $this->assertArrayHasKey('jsonrpc', $err);
        $this->assertSame('2.0', $err['jsonrpc']);
        $this->assertArrayHasKey('error', $err);
        $this->assertArrayHasKey('code', $err['error']);
        $this->assertArrayHasKey('message', $err['error']);
    }

    public function test_missing_parameter_error(): void
    {
        $err = McpErrorFactory::missing_parameter(123, 'test_param');
        
        $this->assertSame(123, $err['id']);
        $this->assertSame(McpErrorFactory::MISSING_PARAMETER, $err['error']['code']);
        $this->assertStringContainsString('test_param', $err['error']['message']);
    }

    public function test_method_not_found_error(): void
    {
        $err = McpErrorFactory::method_not_found(456, 'test/method');
        
        $this->assertSame(456, $err['id']);
        $this->assertSame(McpErrorFactory::METHOD_NOT_FOUND, $err['error']['code']);
        $this->assertStringContainsString('test/method', $err['error']['message']);
    }

    public function test_internal_error(): void
    {
        $err = McpErrorFactory::internal_error(789, 'Something went wrong');
        
        $this->assertSame(789, $err['id']);
        $this->assertSame(McpErrorFactory::INTERNAL_ERROR, $err['error']['code']);
        $this->assertStringContainsString('Something went wrong', $err['error']['message']);
    }

    public function test_tool_not_found_error(): void
    {
        $err = McpErrorFactory::tool_not_found(101, 'missing-tool');
        
        $this->assertSame(101, $err['id']);
        $this->assertSame(McpErrorFactory::TOOL_NOT_FOUND, $err['error']['code']);
        $this->assertStringContainsString('missing-tool', $err['error']['message']);
    }

    public function test_resource_not_found_error(): void
    {
        $err = McpErrorFactory::resource_not_found(102, 'missing-resource');
        
        $this->assertSame(102, $err['id']);
        $this->assertSame(McpErrorFactory::RESOURCE_NOT_FOUND, $err['error']['code']);
        $this->assertStringContainsString('missing-resource', $err['error']['message']);
    }

    public function test_prompt_not_found_error(): void
    {
        $err = McpErrorFactory::prompt_not_found(103, 'missing-prompt');
        
        $this->assertSame(103, $err['id']);
        $this->assertSame(McpErrorFactory::PROMPT_NOT_FOUND, $err['error']['code']);
        $this->assertStringContainsString('missing-prompt', $err['error']['message']);
    }

    public function test_permission_denied_error(): void
    {
        $err = McpErrorFactory::permission_denied(104, 'Access denied');
        
        $this->assertSame(104, $err['id']);
        $this->assertSame(McpErrorFactory::PERMISSION_DENIED, $err['error']['code']);
        $this->assertStringContainsString('Access denied', $err['error']['message']);
    }

    public function test_unauthorized_error(): void
    {
        $err = McpErrorFactory::unauthorized(105, 'Not logged in');
        
        $this->assertSame(105, $err['id']);
        $this->assertSame(McpErrorFactory::UNAUTHORIZED, $err['error']['code']);
        $this->assertStringContainsString('Not logged in', $err['error']['message']);
    }

    public function test_parse_error(): void
    {
        $err = McpErrorFactory::parse_error(106, 'Invalid JSON');
        
        $this->assertSame(106, $err['id']);
        $this->assertSame(McpErrorFactory::PARSE_ERROR, $err['error']['code']);
        $this->assertStringContainsString('Invalid JSON', $err['error']['message']);
    }

    public function test_invalid_request_error(): void
    {
        $err = McpErrorFactory::invalid_request(107, 'Missing field');
        
        $this->assertSame(107, $err['id']);
        $this->assertSame(McpErrorFactory::INVALID_REQUEST, $err['error']['code']);
        $this->assertStringContainsString('Missing field', $err['error']['message']);
    }

    public function test_invalid_params_error(): void
    {
        $err = McpErrorFactory::invalid_params(108, 'Wrong type');
        
        $this->assertSame(108, $err['id']);
        $this->assertSame(McpErrorFactory::INVALID_PARAMS, $err['error']['code']);
        $this->assertStringContainsString('Wrong type', $err['error']['message']);
    }

    public function test_mcp_disabled_error(): void
    {
        $err = McpErrorFactory::mcp_disabled(109);
        
        $this->assertSame(109, $err['id']);
        $this->assertSame(McpErrorFactory::MCP_DISABLED, $err['error']['code']);
        $this->assertStringContainsString('disabled', $err['error']['message']);
    }

    public function test_jsonrpc_message_validation_valid(): void
    {
        $validMessage = [
            'jsonrpc' => '2.0',
            'method' => 'test',
            'id' => 1
        ];
        
        $result = McpErrorFactory::validate_jsonrpc_message($validMessage);
        $this->assertTrue($result);
    }

    public function test_jsonrpc_message_validation_invalid_version(): void
    {
        $invalidMessage = [
            'jsonrpc' => '1.0',
            'method' => 'test',
            'id' => 1
        ];
        
        $result = McpErrorFactory::validate_jsonrpc_message($invalidMessage);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_jsonrpc_message_validation_missing_method(): void
    {
        $invalidMessage = [
            'jsonrpc' => '2.0',
            'id' => 1
        ];
        
        $result = McpErrorFactory::validate_jsonrpc_message($invalidMessage);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }
}


