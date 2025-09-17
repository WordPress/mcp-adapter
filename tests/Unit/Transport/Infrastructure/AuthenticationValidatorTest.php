<?php
/**
 * Tests for AuthenticationValidator class.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\AuthenticationValidator;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;

/**
 * AuthenticationValidator test case.
 */
class AuthenticationValidatorTest extends TestCase {
	/**
	 * Test check_permission with custom callback.
	 */
	public function test_check_permission_with_custom_callback(): void {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_method' )->willReturn( 'POST' );
		$request->method( 'get_header' )->willReturnMap(
			array(
				array( 'origin', 'https://example.com' ),
				array( 'Mcp-Session-Id', null ),
				array( 'accept', null ),
			)
		);
		$request->method( 'get_json_params' )->willReturn( array() );

		$context = new HttpRequestContext( $request );

		// Custom callback that returns true
		$custom_callback = static function ( $req ) {
			return true;
		};

		$result = AuthenticationValidator::check_permission( $context, $custom_callback );

		$this->assertTrue( $result );
	}
}
