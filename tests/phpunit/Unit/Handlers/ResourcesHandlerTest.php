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
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Server\Resources\DTO\ListResourcesResult;
use WP\McpSchema\Server\Resources\DTO\ReadResourceResult;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;
use WP_Error;

/**
 * Test ResourcesHandler functionality.
 */
final class ResourcesHandlerTest extends TestCase {

	public function test_list_resources_returns_dto(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->list_resources();

		$this->assertInstanceOf( ListResourcesResult::class, $result );
	}

	public function test_list_resources_returns_registered_resources(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->list_resources();

		// Use DTO getter methods
		$resources = $result->getResources();
		$this->assertNotEmpty( $resources );
		$this->assertContainsOnlyInstancesOf( ResourceDto::class, $resources );
	}

	public function test_list_resources_returns_empty_array_when_no_resources(): void {
		$server  = $this->makeServer( array(), array(), array() );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->list_resources();

		// Use DTO getter methods
		$resources = $result->getResources();
		$this->assertIsArray( $resources );
		$this->assertEmpty( $resources );
	}

	public function test_read_resource_missing_uri_returns_error(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array() ) );

		// Missing uri is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );
	}

	public function test_read_resource_not_found_returns_error(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource(
			array(
				'params' => array(
					'uri' => 'nonexistent://resource',
				),
			)
		);

		// Resource not found is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );
	}

	public function test_read_resource_with_wp_error_from_get_ability(): void {
		wp_set_current_user( 1 );

		// Register a resource ability, then unregister it after the server is created.
		// Wrapper-backed execution keeps the ability instance, so runtime lookup is not required.
		$this->register_ability_in_hook(
			'test/resource-to-remove',
			array(
				'label'               => 'Resource To Remove',
				'description'         => 'A resource whose ability will be removed',
				'category'            => 'test',
				'execute_callback'    => static function (): string {
					return 'ok';
				},
				'permission_callback' => static function (): bool {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://test/resource-to-remove',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array( 'test/resource-to-remove' ), array() );
		$handler = new ResourcesHandler( $server );

		// Now unregister the ability after the wrapper was created.
		wp_unregister_ability( 'test/resource-to-remove' );

		$result = $handler->read_resource(
			array(
				'params' => array(
					'uri' => 'WordPress://test/resource-to-remove',
				),
			)
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );
		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( 'ok', $contents[0]->getText() );
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
					return new WP_Error( 'test_error', 'Test error message' );
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

		$server    = $this->makeServer( array(), array( 'test/wp-error-resource-execute' ), array() );
		$handler   = new ResourcesHandler( $server );
		$resources = $server->get_resources();
		$this->assertNotEmpty( $resources, 'test/wp-error-resource-execute should be registered' );

		$resource_uri = array_keys( $resources )[0];

		$result = $handler->read_resource(
			array(
				'params' => array(
					'uri' => $resource_uri,
				),
			)
		);

		// WP_Error from execute is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );

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

		$server    = $this->makeServer( array(), array( 'test/resource-execute-exception' ), array() );
		$handler   = new ResourcesHandler( $server );
		$resources = $server->get_resources();
		$this->assertNotEmpty( $resources, 'test/resource-execute-exception should be registered' );

		$resource_uri = array_keys( $resources )[0];

		$result = $handler->read_resource(
			array(
				'params' => array(
					'uri' => $resource_uri,
				),
			)
		);

		// Exception is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );

		// Clean up
		wp_unregister_ability( 'test/resource-execute-exception' );
	}

	public function test_read_resource_success_returns_contents(): void {
		wp_set_current_user( 1 );

		$server    = $this->makeServer( array(), array( 'test/resource' ), array() );
		$handler   = new ResourcesHandler( $server );
		$resources = $server->get_resources();
		$this->assertNotEmpty( $resources, 'test/resource should be registered' );

		$resource_uri = array_keys( $resources )[0];

		$result = $handler->read_resource(
			array(
				'params' => array(
					'uri' => $resource_uri,
				),
			)
		);

		// Successful read returns ReadResourceResult DTO
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		// Use DTO getter methods
		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
	}
}
