<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\TestCase;

final class McpAdapterAbilityExposureTest extends TestCase {

	/**
	 * @dataProvider data_public_ability_exposure
	 *
	 * @param array<string, mixed> $args     Ability registration arguments.
	 * @param array<string, mixed> $expected Expected filtered arguments.
	 */
	public function test_public_ability_exposure_is_inherited( array $args, array $expected ): void {
		$actual = McpAdapter::instance()->inherit_public_ability_exposure( $args );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * @return array<string, array{args: array<string, mixed>, expected: array<string, mixed>}>
	 */
	public function data_public_ability_exposure(): array {
		return array(
			'public ability inherits MCP exposure'  => array(
				'args'     => array(
					'meta' => array(
						'public' => true,
					),
				),
				'expected' => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'public' => true,
						),
					),
				),
			),
			'public ability preserves MCP metadata' => array(
				'args'     => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'type' => 'resource',
						),
					),
				),
				'expected' => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'type'   => 'resource',
							'public' => true,
						),
					),
				),
			),
			'explicit MCP opt-out takes precedence' => array(
				'args'     => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'public' => false,
						),
					),
				),
				'expected' => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'public' => false,
						),
					),
				),
			),
			'explicit MCP opt-in takes precedence'  => array(
				'args'     => array(
					'meta' => array(
						'public' => false,
						'mcp'    => array(
							'public' => true,
						),
					),
				),
				'expected' => array(
					'meta' => array(
						'public' => false,
						'mcp'    => array(
							'public' => true,
						),
					),
				),
			),
			'private ability remains unchanged'     => array(
				'args'     => array(
					'meta' => array(
						'public' => false,
					),
				),
				'expected' => array(
					'meta' => array(
						'public' => false,
					),
				),
			),
			'ability without exposure metadata remains unchanged' => array(
				'args'     => array(
					'meta' => array(
						'annotations' => array(),
					),
				),
				'expected' => array(
					'meta' => array(
						'annotations' => array(),
					),
				),
			),
			'null MCP exposure inherits the public setting' => array(
				'args'     => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'public' => null,
						),
					),
				),
				'expected' => array(
					'meta' => array(
						'public' => true,
						'mcp'    => array(
							'public' => true,
						),
					),
				),
			),
		);
	}
}
