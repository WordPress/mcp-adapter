<?php
/**
 * Tests for StdioServerBridge class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Cli;

use WP\MCP\Cli\StdioServerBridge;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;

/**
 * Test StdioServerBridge behavior across sequential requests in one process.
 */
final class StdioServerBridgeTest extends TestCase {

	/**
	 * Verify that a long-lived STDIO session observes database writes made outside
	 * the current PHP process instead of returning stale in-memory object cache data.
	 */
	public function test_handle_request_flushes_runtime_object_cache_between_requests(): void {
		global $wpdb;

		wp_set_current_user( 1 );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Cache freshness test',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, 'mcp_stdio_freshness', 'before' );

		$tool = McpTool::fromArray(
			array(
				'name'        => 'read-post-meta',
				'description' => 'Reads post meta from WordPress.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'handler'     => static fn(): array => array(
					'value'   => (string) get_post_meta( $post_id, 'mcp_stdio_freshness', true ),
					'user_id' => get_current_user_id(),
				),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );

		$bridge = new StdioServerBridge( $this->makeServer( array( $tool ) ) );

		$init = $this->invoke_handle_request(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Schemas::V2025_11_25,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);
		$this->assertArrayHasKey( 'result', $init );

		$first = $this->invoke_handle_request(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'read-post-meta',
					'arguments' => new \stdClass(),
				),
			)
		);
		$this->assertSame( 'before', $first['result']['structuredContent']['value'] ?? null );
		$this->assertSame( 1, $first['result']['structuredContent']['user_id'] ?? null );

		// Simulate another process (HTTP, cron, or another WP-CLI command) updating
		// postmeta directly in the database without touching this process's runtime cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentionally bypasses object cache to simulate an external process write.
		$updated = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => 'after' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Direct test fixture update.
			array(
				'post_id'  => $post_id,
				'meta_key' => 'mcp_stdio_freshness',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		$this->assertSame( 1, $updated );

		// Confirm the runtime cache still holds the stale value before the next STDIO request.
		$this->assertSame( 'before', get_post_meta( $post_id, 'mcp_stdio_freshness', true ) );

		$second = $this->invoke_handle_request(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'read-post-meta',
					'arguments' => new \stdClass(),
				),
			)
		);
		$this->assertSame( 'after', $second['result']['structuredContent']['value'] ?? null );
		$this->assertSame( 1, $second['result']['structuredContent']['user_id'] ?? null );
	}

	/**
	 * Invoke the private handle_request() method on StdioServerBridge.
	 *
	 * @param \WP\MCP\Cli\StdioServerBridge $bridge  Bridge under test.
	 * @param array<string, mixed>          $payload JSON-RPC payload.
	 *
	 * @return array<string, mixed>
	 */
	private function invoke_handle_request( StdioServerBridge $bridge, array $payload ): array {
		$method = new \ReflectionMethod( $bridge, 'handle_request' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$raw  = $method->invoke( $bridge, (string) wp_json_encode( $payload ) );
		$data = json_decode( (string) $raw, true );

		return is_array( $data ) ? $data : array();
	}
}
