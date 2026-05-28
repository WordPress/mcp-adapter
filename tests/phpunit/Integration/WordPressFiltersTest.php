<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class WordPressFiltersTest extends TestCase {

	public function test_validation_toggle_filter_is_respected(): void {
		add_filter( 'mcp_adapter_validation_enabled', '__return_false' );

		$server = new McpServer(
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

		$this->assertFalse( $server->is_mcp_validation_enabled() );

		remove_filter( 'mcp_adapter_validation_enabled', '__return_false' );
	}

	public function test_server_config_filter_can_rewrite_string_fields(): void {
		add_filter(
			'mcp_adapter_server_config',
			static function ( array $config ): array {
				$config['server_id']          = 'rewritten-id';
				$config['server_name']        = 'Rewritten Name';
				$config['server_description'] = 'Rewritten description';
				return $config;
			}
		);

		$server = new McpServer(
			'original-id',
			'mcp/v1',
			'/mcp',
			'Original Name',
			'Original description',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertSame( 'rewritten-id', $server->get_server_id() );
		$this->assertSame( 'Rewritten Name', $server->get_server_name() );
		$this->assertSame( 'Rewritten description', $server->get_server_description() );

		remove_all_filters( 'mcp_adapter_server_config' );
	}

	public function test_server_config_filter_receives_server_id_as_second_arg(): void {
		$captured = null;
		add_filter(
			'mcp_adapter_server_config',
			static function ( array $config, string $server_id ) use ( &$captured ): array {
				$captured = $server_id;
				return $config;
			},
			10,
			2
		);

		new McpServer(
			'unique-srv-id',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertSame( 'unique-srv-id', $captured );

		remove_all_filters( 'mcp_adapter_server_config' );
	}

	public function test_server_config_filter_returning_non_array_falls_back_to_constructor_defaults(): void {
		add_filter(
			'mcp_adapter_server_config',
			static fn () => 'invalid_non_array_return'
		);

		$server = new McpServer(
			'fallback-id',
			'mcp/v1',
			'/mcp',
			'Fallback Name',
			'Fallback description',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertSame( 'fallback-id', $server->get_server_id() );
		$this->assertSame( 'Fallback Name', $server->get_server_name() );

		remove_all_filters( 'mcp_adapter_server_config' );
	}

	public function test_server_config_filter_dropping_a_key_falls_back_to_supplied_default(): void {
		add_filter(
			'mcp_adapter_server_config',
			static function ( array $config ): array {
				unset( $config['server_name'] );
				return $config;
			}
		);

		$server = new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Kept Name',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertSame( 'Kept Name', $server->get_server_name() );

		remove_all_filters( 'mcp_adapter_server_config' );
	}

	public function test_server_config_filter_fires_for_each_server_constructed(): void {
		$captured_ids = array();
		add_filter(
			'mcp_adapter_server_config',
			static function ( array $config, string $server_id ) use ( &$captured_ids ): array {
				$captured_ids[] = $server_id;
				return $config;
			},
			10,
			2
		);

		new McpServer(
			'first',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		new McpServer(
			'second',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertSame( array( 'first', 'second' ), $captured_ids );

		remove_all_filters( 'mcp_adapter_server_config' );
	}
}
