<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\Dto;

use WP\MCP\Infrastructure\Dto\MetaStripper;
use WP\MCP\Tests\TestCase;

final class MetaStripperTest extends TestCase {

	public function test_strip_array_recursively_removes_mcp_adapter_meta(): void {
		$input = array(
			'_meta' => array(
				'mcp_adapter' => array(
					'ability' => 'top-level',
				),
				'keep'        => array(
					'foo' => 'bar',
				),
			),
			'tools' => array(
				array(
					'name'  => 'tool-a',
					'_meta' => array(
						'mcp_adapter' => array(
							'ability' => 'tool-a',
						),
					),
				),
				array(
					'name'   => 'tool-b',
					'nested' => array(
						'x' => array(
							'_meta' => array(
								'mcp_adapter' => array(
									'ability' => 'tool-b',
								),
							),
						),
					),
				),
			),
			'drop_me' => array(
				'_meta' => array(
					'mcp_adapter' => array(
						'only' => true,
					),
				),
			),
		);

		$result = MetaStripper::strip_array( $input );

		$this->assertNoMcpAdapterMeta( $result );
		$this->assertArrayHasKey( '_meta', $result );
		$this->assertArrayHasKey( 'keep', $result['_meta'] );
		$this->assertArrayNotHasKey( 'mcp_adapter', $result['_meta'] );
		$this->assertArrayNotHasKey( '_meta', $result['drop_me'] );
	}

	/**
	 * @param mixed $value
	 */
	private function assertNoMcpAdapterMeta( $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( isset( $value['_meta'] ) && is_array( $value['_meta'] ) ) {
			$this->assertArrayNotHasKey( 'mcp_adapter', $value['_meta'] );
		}

		foreach ( $value as $child ) {
			$this->assertNoMcpAdapterMeta( $child );
		}
	}
}

