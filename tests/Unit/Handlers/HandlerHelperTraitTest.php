<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Tests\TestCase;

final class HandlerHelperTraitTest extends TestCase {

	/**
	 * Test class that uses the HandlerHelperTrait for testing purposes.
	 */
	private $trait_user;

	public function setUp(): void {
		parent::setUp();

		// Create an anonymous class that uses the trait
		$this->trait_user = new class() {
			use HandlerHelperTrait;

			// Make protected methods public for testing
			public function test_extract_params( array $data ): array {
				return $this->extract_params( $data );
			}

			public function test_create_error_response( int $code, string $message, int $request_id = 0 ): array {
				return $this->create_error_response( $code, $message, $request_id );
			}

			public function test_extract_error( array $factory_response ): array {
				return $this->extract_error( $factory_response );
			}

			public function test_missing_parameter_error( string $param_name, int $request_id = 0 ): array {
				return $this->missing_parameter_error( $param_name, $request_id );
			}

			public function test_permission_denied_error( string $denied_resource, int $request_id = 0 ): array {
				return $this->permission_denied_error( $denied_resource, $request_id );
			}

			public function test_internal_error( string $message, int $request_id = 0 ): array {
				return $this->internal_error( $message, $request_id );
			}

			public function test_create_success_response( $data ): array {
				return $this->create_success_response( $data );
			}
		};
	}

	public function test_extract_params_with_nested_params(): void {
		$input = array(
			'params' => array(
				'name'      => 'test-tool',
				'arguments' => array( 'key' => 'value' ),
			),
		);

		$result = $this->trait_user->test_extract_params( $input );

		$this->assertSame(
			array(
				'name'      => 'test-tool',
				'arguments' => array( 'key' => 'value' ),
			),
			$result
		);
	}

	public function test_extract_params_with_direct_params(): void {
		$input = array(
			'name'      => 'test-tool',
			'arguments' => array( 'key' => 'value' ),
		);

		$result = $this->trait_user->test_extract_params( $input );

		$this->assertSame( $input, $result );
	}

	public function test_extract_params_with_empty_nested_params(): void {
		$input = array(
			'params' => array(),
			'name'   => 'fallback-tool',
		);

		$result = $this->trait_user->test_extract_params( $input );

		$this->assertSame( array(), $result );
	}

	public function test_create_error_response(): void {
		$result = $this->trait_user->test_create_error_response( 123, 'Test error message', 456 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 123, $result['error']['code'] );
		$this->assertSame( 'Test error message', $result['error']['message'] );
	}

	public function test_extract_error_from_factory_response(): void {
		$factory_response = array(
			'error' => array(
				'code'    => 100,
				'message' => 'Factory error',
			),
		);

		$result = $this->trait_user->test_extract_error( $factory_response );

		$this->assertSame(
			array(
				'code'    => 100,
				'message' => 'Factory error',
			),
			$result
		);
	}

	public function test_extract_error_from_plain_response(): void {
		$plain_response = array(
			'code'    => 200,
			'message' => 'Plain error',
		);

		$result = $this->trait_user->test_extract_error( $plain_response );

		$this->assertSame( $plain_response, $result );
	}

	public function test_missing_parameter_error(): void {
		$result = $this->trait_user->test_missing_parameter_error( 'required_param', 789 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertArrayHasKey( 'message', $result['error'] );
		$this->assertStringContainsString( 'required_param', $result['error']['message'] );
	}

	public function test_permission_denied_error(): void {
		$result = $this->trait_user->test_permission_denied_error( 'test-resource', 999 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertArrayHasKey( 'message', $result['error'] );
		$this->assertStringContainsString( 'test-resource', $result['error']['message'] );
	}

	public function test_internal_error(): void {
		$result = $this->trait_user->test_internal_error( 'Internal server error', 111 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertArrayHasKey( 'message', $result['error'] );
		$this->assertSame( 'Internal error: Internal server error', $result['error']['message'] );
	}

	public function test_create_success_response(): void {
		$data   = array(
			'status' => 'success',
			'data'   => array( 'id' => 123 ),
		);
		$result = $this->trait_user->test_create_success_response( $data );

		$this->assertArrayHasKey( 'result', $result );
		$this->assertSame( $data, $result['result'] );
	}

	public function test_create_success_response_with_string(): void {
		$data   = 'success message';
		$result = $this->trait_user->test_create_success_response( $data );

		$this->assertArrayHasKey( 'result', $result );
		$this->assertSame( $data, $result['result'] );
	}

	public function test_error_responses_have_consistent_structure(): void {
		$errors = array(
			$this->trait_user->test_missing_parameter_error( 'test_param' ),
			$this->trait_user->test_permission_denied_error( 'test_resource' ),
			$this->trait_user->test_internal_error( 'test_message' ),
			$this->trait_user->test_create_error_response( 500, 'custom_error' ),
		);

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			$this->assertIsInt( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );
		}
	}
}
