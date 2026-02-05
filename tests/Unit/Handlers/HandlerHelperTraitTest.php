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
}
