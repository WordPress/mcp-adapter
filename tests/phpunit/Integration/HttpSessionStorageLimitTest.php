<?php
/**
 * Session storage limits at the registered HTTP permission and initialization boundary.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Transport\Infrastructure\SessionManager;
use WP_REST_Request;

/**
 * A subscriber cannot persist oversized initialization data through HTTP.
 *
 * @since 0.7.1
 */
final class HttpSessionStorageLimitTest extends TestCase {

	/**
	 * Open initialization fields all share the storage limit, without breaking an existing session.
	 *
	 * @since 0.7.1
	 * @dataProvider oversized_initialize_provider
	 *
	 * @param string $field Initialization field carrying oversized data.
	 */
	public function test_oversized_initialize_does_not_grow_subscriber_storage( string $field ): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->assertTrue( current_user_can( 'read' ) );
		$this->assertFalse( current_user_can( 'edit_posts' ) );

		new McpServer(
			'storage-limit-test',
			'mcp-storage-test/v1',
			'/mcp',
			'Storage limit test',
			'Tests session storage limits',
			'1',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);
		do_action( 'rest_api_init' );

		$params  = array(
			'protocolVersion' => '2025-11-25',
			'capabilities'    => new \stdClass(),
			'clientInfo'      => array(
				'name'    => 'storage-test',
				'version' => '1',
			),
		);
		$request = new WP_REST_Request( 'POST', '/mcp-storage-test/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => $params,
				)
			)
		);
		$initial = rest_do_request( $request );
		$this->assertSame( 200, $initial->get_status() );
		$session_id = $initial->get_headers()['Mcp-Session-Id'];
		$before     = SessionManager::get_all_user_sessions( $user_id );

		$blob = str_repeat( 'x', 1024 * 1024 );
		if ( 'capabilities' === $field ) {
			$params['capabilities'] = array( 'experimental' => array( 'probe' => array( 'blob' => $blob ) ) );
		} elseif ( 'clientInfo' === $field ) {
			$params['clientInfo']['name'] = $blob;
		} else {
			$params['extra'] = $blob;
		}

		DummyErrorHandler::reset();
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$request->set_body(
				(string) wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'initialize',
						'params'  => $params,
					)
				)
			);
			$response = rest_do_request( $request );
			$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
			// A client error under the 2025 revision: JSON-RPC Invalid params, not a storage failure.
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( -32602, $data['error']['code'] );
			$this->assertStringContainsString( 'exceed the 4096 byte session storage limit', $data['error']['message'] );
			$this->assertSame( 2, $data['id'] );
			$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response->get_headers() );
			$this->assertSame( $before, SessionManager::get_all_user_sessions( $user_id ) );
		}
		$this->assertCount( 3, DummyErrorHandler::$logs );
		$this->assertSame( $user_id, DummyErrorHandler::$logs[0]['context']['user_id'] );
		$this->assertStringNotContainsString( str_repeat( 'x', 100 ), (string) wp_json_encode( DummyErrorHandler::$logs ) );
		DummyErrorHandler::reset();

		$request->set_header( 'Mcp-Session-Id', $session_id );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		$request->set_body( '{"jsonrpc":"2.0","id":3,"method":"ping"}' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	/**
	 * A row bloated by an earlier release is dropped on the next request instead of being rewritten.
	 *
	 * @since 0.7.1
	 */
	public function test_oversized_legacy_row_is_discarded_and_the_user_can_reinitialize(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		new McpServer(
			'storage-recovery-test',
			'mcp-storage-test/v1',
			'/mcp',
			'Storage recovery test',
			'Tests session storage recovery',
			'1',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);
		do_action( 'rest_api_init' );

		$meta_key  = self::session_meta_key();
		$legacy_id = wp_generate_uuid4();
		// Aged past the activity update interval so a validate would normally rewrite the whole row.
		$legacy_map = array(
			$legacy_id => array(
				'created_at'    => time() - HOUR_IN_SECONDS,
				'last_activity' => time() - HOUR_IN_SECONDS,
				'client_params' => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => array( 'experimental' => array( 'blob' => str_repeat( 'x', 1024 * 1024 ) ) ),
					'clientInfo'      => array(
						'name'    => 'legacy',
						'version' => '1',
					),
				),
			),
		);
		$this->assertNotFalse( update_user_meta( $user_id, $meta_key, $legacy_map ) );
		wp_cache_delete( $user_id, 'user_meta' );

		$queries = array();
		$observe = static function ( string $query ) use ( &$queries ): string {
			// The DELETE issued by delete_metadata() selects by meta ID and does not repeat the key.
			if ( false !== strpos( $query, 'usermeta' ) && preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $query, $matches ) ) {
				$queries[] = strtoupper( $matches[1] );
			}

			return $query;
		};

		// wpdb::print_error() appends every failed query here before writing it, in full, to the PHP error log.
		$failed_queries_before = count( $GLOBALS['EZSQL_ERROR'] ?? array() );

		DummyErrorHandler::reset();
		add_filter( 'query', $observe );
		try {
			$request = new WP_REST_Request( 'POST', '/mcp-storage-test/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'Mcp-Session-Id', $legacy_id );
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
			$request->set_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}' );
			$response = rest_do_request( $request );

			$this->assertSame( 404, $response->get_status() );
			$this->assertSame( array( 'DELETE' ), $queries );
			$this->assertFalse( metadata_exists( 'user', $user_id, $meta_key ) );
			$this->assertCount( 1, DummyErrorHandler::$logs );
			$this->assertSame( 'get_all_user_sessions', DummyErrorHandler::$logs[0]['context']['method'] );
			$this->assertStringNotContainsString( str_repeat( 'x', 100 ), (string) wp_json_encode( DummyErrorHandler::$logs ) );

			$request = new WP_REST_Request( 'POST', '/mcp-storage-test/v1/mcp' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 2,
						'method'  => 'initialize',
						'params'  => array(
							'protocolVersion' => '2025-11-25',
							'capabilities'    => new \stdClass(),
							'clientInfo'      => array(
								'name'    => 'fresh',
								'version' => '1',
							),
						),
					)
				)
			);
			$response = rest_do_request( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertArrayHasKey( 'Mcp-Session-Id', $response->get_headers() );
			$this->assertSame( array( 'DELETE', 'INSERT' ), $queries );
			$this->assertLessThan( 1024, strlen( (string) maybe_serialize( get_user_meta( $user_id, $meta_key, true ) ) ) );
			$this->assertSame( $failed_queries_before, count( $GLOBALS['EZSQL_ERROR'] ?? array() ), 'Recovery must not produce a failed query, which WordPress would log in full.' );
		} finally {
			remove_filter( 'query', $observe );
			DummyErrorHandler::reset();
		}
	}

	/**
	 * A spec-valid initialize carrying inline icon data still connects; the icons are not stored.
	 *
	 * @since 0.7.1
	 */
	public function test_initialize_with_data_uri_icons_connects_and_stores_no_icons(): void {
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		new McpServer(
			'storage-icons-test',
			'mcp-storage-test/v1',
			'/mcp',
			'Storage icons test',
			'Tests that client icons are not stored',
			'1',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);
		do_action( 'rest_api_init' );

		// Two icons of about 6 KB each, far over the 4 KiB per-session limit on their own.
		$png_icon = 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n" . str_repeat( 'i', 4500 ) );
		$params   = array(
			'protocolVersion' => '2025-11-25',
			'capabilities'    => new \stdClass(),
			'clientInfo'      => array(
				'name'    => 'icon-client',
				'version' => '1',
				'icons'   => array(
					array(
						'src'      => $png_icon,
						'mimeType' => 'image/png',
						'sizes'    => array( '48x48' ),
					),
					array(
						'src'      => $png_icon,
						'mimeType' => 'image/png',
						'sizes'    => array( '96x96' ),
					),
				),
			),
		);
		$this->assertGreaterThan( 4096, strlen( maybe_serialize( $params['clientInfo']['icons'] ) ) );

		$request = new WP_REST_Request( 'POST', '/mcp-storage-test/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => $params,
				)
			)
		);
		$response = rest_do_request( $request );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'Mcp-Session-Id', $response->get_headers() );

		$sessions = SessionManager::get_all_user_sessions( $user_id );
		$stored   = $sessions[ $response->get_headers()['Mcp-Session-Id'] ]['client_params'];
		$this->assertArrayNotHasKey( 'icons', $stored['clientInfo'] );
		$this->assertSame( 'icon-client', $stored['clientInfo']['name'] );
		$this->assertSame( '2025-11-25', $stored['protocolVersion'] );
		$this->assertStringNotContainsString( 'data:image', maybe_serialize( $sessions ) );

		$request->set_header( 'Mcp-Session-Id', $response->get_headers()['Mcp-Session-Id'] );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		$request->set_body( '{"jsonrpc":"2.0","id":2,"method":"ping"}' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	/**
	 * Attack surfaces that remain unbounded in the protocol schema.
	 *
	 * @since 0.7.1
	 * @return array<string, array{string}>
	 */
	public function oversized_initialize_provider(): array {
		return array(
			'unknown parameter'       => array( 'extra' ),
			'experimental capability' => array( 'capabilities' ),
			'client name'             => array( 'clientInfo' ),
		);
	}
}
