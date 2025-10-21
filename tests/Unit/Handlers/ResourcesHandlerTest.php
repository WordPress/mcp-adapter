<?php
/**
 * Tests for ResourcesHandler class.
 *
 * @package WP\MCP\Tests
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\TestCase;

/**
 * Test ResourcesHandler functionality.
 */
final class ResourcesHandlerTest extends TestCase {

	public function test_list_resources_returns_registered_resources(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->list_resources();

		$this->assertArrayHasKey( 'resources', $res );
		$this->assertNotEmpty( $res['resources'] );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'resources', $res['_metadata']['component_type'] );
		$this->assertArrayHasKey( 'resources_count', $res['_metadata'] );
	}

	public function test_list_resources_returns_empty_array_when_no_resources(): void {
		$server  = $this->makeServer( array(), array(), array() );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->list_resources();

		$this->assertArrayHasKey( 'resources', $res );
		$this->assertEmpty( $res['resources'] );
		$this->assertEquals( 0, $res['_metadata']['resources_count'] );
	}

	public function test_read_resource_missing_uri_returns_error(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->read_resource( array( 'params' => array() ) );

		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'missing_parameter', $res['_metadata']['failure_reason'] );
	}

	public function test_read_resource_not_found_returns_error(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->read_resource(
			array(
				'params' => array(
					'uri' => 'nonexistent://resource',
				),
			)
		);

		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'not_found', $res['_metadata']['failure_reason'] );
		$this->assertEquals( 'nonexistent://resource', $res['_metadata']['resource_uri'] );
	}

	// Note: Testing ability retrieval failure requires complex mocking
	// that's already covered in integration tests

	// Note: Permission denied scenarios are tested using existing abilities
	// in the tool handler tests and integration tests

	public function test_read_resource_success_returns_contents(): void {
		wp_set_current_user( 1 );

		$server    = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler   = new ResourcesHandler( $server );
		$resources = $server->get_resources();
		$this->assertNotEmpty( $resources, 'test/resource should be registered' );

		$resource_uri = array_keys( $resources )[0];

		$res = $handler->read_resource(
			array(
				'params' => array(
					'uri' => $resource_uri,
				),
			)
		);

		$this->assertArrayHasKey( 'contents', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'resource', $res['_metadata']['component_type'] );
		$this->assertArrayHasKey( 'resource_uri', $res['_metadata'] );
		$this->assertArrayHasKey( 'ability_name', $res['_metadata'] );
	}
}
