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
 * @since n.e.x.t
 */
final class HttpSessionStorageLimitTest extends TestCase {

	/**
	 * Open initialization fields all share the storage limit, without breaking an existing session.
	 *
	 * @since n.e.x.t
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
			'clientInfo'      => array( 'name' => 'storage-test', 'version' => '1' ),
		);
		$request = new WP_REST_Request( 'POST', '/mcp-storage-test/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $params ) ) );
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

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$request->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => $params ) ) );
			$response = rest_do_request( $request );
			$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( -32603, $data['error']['code'] );
			$this->assertSame( 2, $data['id'] );
			$this->assertArrayNotHasKey( 'Mcp-Session-Id', $response->get_headers() );
			$this->assertSame( $before, SessionManager::get_all_user_sessions( $user_id ) );
		}

		$request->set_header( 'Mcp-Session-Id', $session_id );
		$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		$request->set_body( '{"jsonrpc":"2.0","id":3,"method":"ping"}' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	/**
	 * Attack surfaces that remain unbounded in the protocol schema.
	 *
	 * @since n.e.x.t
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
