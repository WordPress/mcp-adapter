<?php
/**
 * Tests for the StdioServerBridge class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Cli;

use WP\MCP\Cli\StdioServerBridge;
use WP\MCP\Tests\TestCase;

/**
 * Test that the long-running STDIO server reads current data on each message.
 *
 * @see https://github.com/WordPress/mcp-adapter/issues/409
 */
final class StdioServerBridgeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/read-site-title',
			array(
				'label'               => 'Read Site Title',
				'description'         => 'Returns the site title',
				'category'            => 'test',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function (): array {
					return array( 'title' => get_option( 'blogname' ) );
				},
				'permission_callback' => static function (): bool {
					return true;
				},
			)
		);
	}

	public function test_tool_call_reads_changes_made_by_another_process(): void {
		$bridge = new StdioServerBridge( $this->makeServer( array( 'test/read-site-title' ) ) );
		$this->initialize( $bridge );

		update_option( 'blogname', 'Title before' );
		$this->assertSame( 'Title before', $this->call_read_site_title( $bridge, 2 ) );

		// Another process changes the title: the database changes, but not this process's object cache.
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => 'Title after' ), array( 'option_name' => 'blogname' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypassing the cache is the point of the test.

		$this->assertSame( 'Title after', $this->call_read_site_title( $bridge, 3 ) );
	}

	public function test_each_message_flushes_the_runtime_cache(): void {
		$bridge = new StdioServerBridge( $this->makeServer( array( 'test/read-site-title' ) ) );

		wp_cache_set( 'stale', 'cached value', 'mcp_adapter_test' );
		$this->send(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'ping',
			)
		);

		$this->assertFalse( wp_cache_get( 'stale', 'mcp_adapter_test' ) );
	}

	private function initialize( StdioServerBridge $bridge ): void {
		$this->send(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-06-18',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'stdio-test',
						'version' => '1.0',
					),
				),
			)
		);
		$this->send(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);
	}

	private function call_read_site_title( StdioServerBridge $bridge, int $id ): ?string {
		$response = json_decode(
			$this->send(
				$bridge,
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'test-read-site-title',
						'arguments' => new \stdClass(),
					),
				)
			),
			true
		);

		return $response['result']['structuredContent']['title'] ?? null;
	}

	/**
	 * Send one message through the bridge, as the serving loop does for each STDIN line.
	 *
	 * @param \WP\MCP\Cli\StdioServerBridge $bridge  Bridge under test.
	 * @param array<string, mixed>          $payload JSON-RPC message.
	 *
	 * @return string Encoded response, or an empty string for a notification.
	 */
	private function send( StdioServerBridge $bridge, array $payload ): string {
		$method = new \ReflectionMethod( $bridge, 'handle_request' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (string) $method->invoke( $bridge, (string) wp_json_encode( $payload ) );
	}
}
