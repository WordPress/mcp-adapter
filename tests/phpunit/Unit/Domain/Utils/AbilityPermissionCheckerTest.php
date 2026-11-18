<?php
/**
 * Compatibility coverage for abilities exposing public input preparation.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\AbilityPermissionChecker;
use WP\MCP\Tests\TestCase;
use WP_Ability;
use WP_Error;

/**
 * Exercise the public preparation contract on every supported Core version.
 */
final class AbilityPermissionCheckerTest extends TestCase {

	/**
	 * Permission checks receive the prepared value and still honor a denial.
	 */
	public function test_prepared_input_reaches_permission_callback(): void {
		$seen    = array();
		$ability = $this->make_ability(
			array( 'id' => 123 ),
			static function ( $input ) use ( &$seen ): bool {
				$seen[] = $input;
				return false;
			}
		);

		$this->assertFalse( AbilityPermissionChecker::check( $ability, array( 'id' => '123' ) ) );
		$this->assertSame( array( array( 'id' => 123 ) ), $seen );
	}

	/**
	 * A preparation error is preserved without invoking the permission callback.
	 */
	public function test_preparation_error_stops_permission_callback(): void {
		$error   = new WP_Error( 'fixture_preparation_failed', 'Fixture preparation failed.' );
		$calls   = 0;
		$ability = $this->make_ability(
			$error,
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		$this->assertSame( $error, AbilityPermissionChecker::check( $ability, array( 'id' => '123' ) ) );
		$this->assertSame( 0, $calls );
	}

	/**
	 * Supply the public preparation API independently of the installed Core version.
	 *
	 * @param mixed    $prepared_input Prepared value or error.
	 * @param callable $permission     Permission callback.
	 * @return \WP_Ability
	 */
	private function make_ability( $prepared_input, callable $permission ): WP_Ability {
		$ability = new class(
			'test/preparation-contract',
			array(
				'label'               => 'Preparation contract fixture',
				'description'         => 'Checks the public input preparation contract.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'permission_callback' => $permission,
				'execute_callback'    => '__return_null',
			)
		) extends WP_Ability {
			/**
			 * Prepared value supplied by the test.
			 *
			 * @var mixed
			 */
			public $prepared_input;

			/**
			 * Return the supplied preparation result.
			 *
			 * @param mixed $input Raw ability input.
			 * @return mixed
			 */
			public function prepare_input( $input = null ) {
				return $this->prepared_input;
			}
		};

		$ability->prepared_input = $prepared_input;

		return $ability;
	}
}
