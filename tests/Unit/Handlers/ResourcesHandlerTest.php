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

	public function test_read_resource_with_wp_error_from_get_ability(): void {
		wp_set_current_user( 1 );

		// Create a resource with a non-existent ability name
		$server = $this->makeServer( array(), array(), array() );
		$resource = new \WP\MCP\Domain\Resources\McpResource(
			'nonexistent/ability',
			'WordPress://test/nonexistent-resource',
			'Test Resource',
			'Test description'
		);
		$resource->set_mcp_server( $server );
		// Manually add the invalid resource (bypassing normal registration)
		$registry = $server->get_component_registry();
		$reflection = new \ReflectionClass( $registry );
		$resources_property = $reflection->getProperty( 'resources' );
		$resources_property->setAccessible( true );
		$resources = $resources_property->getValue( $registry );
		$resources['WordPress://test/nonexistent-resource'] = $resource;
		$resources_property->setValue( $registry, $resources );

		$handler = new ResourcesHandler( $server );

		$res = $handler->read_resource(
			array(
				'params' => array(
					'uri' => 'WordPress://test/nonexistent-resource',
				),
			)
		);

		// Should return error
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'ability_retrieval_failed', $res['_metadata']['failure_reason'] );
		$this->assertEquals( 'ability_not_found', $res['_metadata']['error_code'] );
	}

	public function test_read_resource_with_wp_error_from_execute(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns WP_Error
		$this->register_ability_in_hook(
			'test/wp-error-resource-execute',
			array(
				'label'               => 'WP Error Resource Execute',
				'description'         => 'Returns WP_Error from execute',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return new \WP_Error( 'test_error', 'Test error message' );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'uri' => 'WordPress://test/wp-error-resource',
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array( 'test/wp-error-resource-execute' ), array() );
		$handler = new ResourcesHandler( $server );
		$resources = $server->get_resources();
		$this->assertNotEmpty( $resources, 'test/wp-error-resource-execute should be registered' );

		$resource_uri = array_keys( $resources )[0];

		$res = $handler->read_resource(
			array(
				'params' => array(
					'uri' => $resource_uri,
				),
			)
		);

		// Should return error
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'wp_error', $res['_metadata']['failure_reason'] );
		$this->assertEquals( 'test_error', $res['_metadata']['error_code'] );

		// Clean up
		wp_unregister_ability( 'test/wp-error-resource-execute' );
	}

	public function test_read_resource_with_exception(): void {
		wp_set_current_user( 1 );

		// Register an ability that throws exception during execute
		$this->register_ability_in_hook(
			'test/resource-execute-exception',
			array(
				'label'               => 'Resource Execute Exception',
				'description'         => 'Throws exception in execute',
				'category'            => 'test',
				'execute_callback'    => static function () {
					throw new \RuntimeException( 'Execute exception' );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'uri' => 'WordPress://test/resource-exception',
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array( 'test/resource-execute-exception' ), array() );
		$handler = new ResourcesHandler( $server );
		$resources = $server->get_resources();
		$this->assertNotEmpty( $resources, 'test/resource-execute-exception should be registered' );

		$resource_uri = array_keys( $resources )[0];

		$res = $handler->read_resource(
			array(
				'params' => array(
					'uri' => $resource_uri,
				),
			)
		);

		// Should return error
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'execution_failed', $res['_metadata']['failure_reason'] );
		$this->assertArrayHasKey( 'error_type', $res['_metadata'] );

		// Clean up
		wp_unregister_ability( 'test/resource-execute-exception' );
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
