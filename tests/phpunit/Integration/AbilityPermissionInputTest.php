<?php
/**
 * Regression coverage for ability input reaching MCP permission callbacks.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\TestCase;
use WP_Ability;
use WP_Error;

/**
 * Exercise the same ability through Core execution and each adapter permission path.
 *
 * @group permissions
 */
class AbilityPermissionInputTest extends TestCase {

	/**
	 * The ability registered by the current test.
	 *
	 * @var string
	 */
	private string $ability_name = 'test/permission-input';

	/**
	 * Authenticate the execute-ability path without replacing permission checks.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	/**
	 * Remove the per-test ability.
	 */
	public function tearDown(): void {
		if ( wp_has_ability( $this->ability_name ) ) {
			wp_unregister_ability( $this->ability_name );
		}
		parent::tearDown();
	}

	/**
	 * Adapter entry points that check ability permissions directly.
	 *
	 * @return array<string,array{string}>
	 */
	public static function permission_paths(): array {
		return array(
			'tool'            => array( 'tool' ),
			'prompt'          => array( 'prompt' ),
			'execute-ability' => array( 'execute-ability' ),
		);
	}

	/**
	 * Empty MCP arguments should apply the same top-level default as Core.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_permission_callback_receives_schema_default( string $path ): void {
		$seen    = array();
		$default = array( 'id' => 123 );
		$ability = $this->make_ability(
			array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'default'    => $default,
			),
			static function ( $input ) use ( &$seen ): bool {
				$seen[] = $input;
				return true;
			}
		);

		$this->assertSame( 'executed', $ability->execute( null ) );
		$this->assertSame( array( $default ), $seen );
		$seen = array();

		$this->assertTrue( $this->check_permission( $path, $ability, array() ) );
		$this->assertSame( array( $default ), $seen );
	}

	/**
	 * Numeric strings should reach a strict callback as schema-typed integers.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_permission_callback_receives_coerced_input( string $path ): void {
		if ( ! method_exists( WP_Ability::class, 'prepare_input' ) ) {
			$this->markTestSkipped( 'Input coercion requires WordPress 7.2 or later.' );
		}

		$read_id = static function ( array $input ): int {
			return $input['id'];
		};
		$ability = $this->make_ability(
			array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
			static function ( array $input ) use ( $read_id ): bool {
				return 123 === $read_id( $input );
			}
		);
		$input   = array( 'id' => '123' );

		$this->assertSame( 'executed', $ability->execute( $input ) );
		$this->assertTrue( $this->check_permission( $path, $ability, $input ) );
	}

	/**
	 * Invalid input should be rejected before the permission callback runs.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_invalid_input_does_not_reach_permission_callback( string $path ): void {
		$calls   = 0;
		$ability = $this->make_ability(
			array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);
		$input   = array( 'id' => 'not-an-integer' );

		$core_result = $ability->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $core_result );
		$this->assertSame( 'ability_invalid_input', $core_result->get_error_code() );
		$this->assertSame( 0, $calls );

		$result = $this->check_permission( $path, $ability, $input );
		$this->assertSame( 0, $calls, 'Invalid input reached the permission callback.' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * An already-valid input must still honor a denied permission.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_valid_input_preserves_permission_denial( string $path ): void {
		$ability = $this->make_ability(
			array( 'type' => 'object' ),
			static function (): bool {
				return false;
			}
		);

		$this->assertFalse( $this->check_permission( $path, $ability, array() ) );
	}

	/**
	 * Preserve a permission error rather than replacing it with a generic error.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_valid_input_preserves_permission_error( string $path ): void {
		$error   = new WP_Error( 'permission_fixture_denied', 'Fixture permission denied.' );
		$ability = $this->make_ability(
			array( 'type' => 'object' ),
			static function () use ( $error ): WP_Error {
				return $error;
			}
		);

		$this->assertSame( $error, $this->check_permission( $path, $ability, array() ) );
	}

	/**
	 * A normalization error must stop before the permission callback.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_normalization_error_is_preserved( string $path ): void {
		global $wp_version;
		if ( version_compare( $wp_version, '7.1', '<' ) ) {
			$this->markTestSkipped( 'The input normalization filter requires WordPress 7.1 or later.' );
		}

		$calls   = 0;
		$error   = new WP_Error( 'fixture_normalization_failed', 'Fixture normalization failed.' );
		$ability = $this->make_ability(
			array( 'type' => 'object' ),
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);
		$filter  = static function ( $input, string $name ) use ( $ability, $error ) {
			return $ability->get_name() === $name ? $error : $input;
		};
		add_filter( 'wp_ability_normalize_input', $filter, 10, 2 );
		try {
			$this->assertSame( $error, $this->check_permission( $path, $ability, array() ) );
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'wp_ability_normalize_input', $filter, 10 );
		}
	}

	/**
	 * Coercion must not hide a collision in an array with unique items.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_coercion_error_does_not_reach_permission_callback( string $path ): void {
		if ( ! method_exists( WP_Ability::class, 'prepare_input' ) ) {
			$this->markTestSkipped( 'Input coercion requires WordPress 7.2 or later.' );
		}

		$calls   = 0;
		$ability = $this->make_ability(
			array(
				'type'       => 'object',
				'properties' => array(
					'ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'uniqueItems' => true,
					),
				),
			),
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);
		$input   = array( 'ids' => array( '1', 1 ) );

		$this->assertTrue( $ability->validate_input( $input ) );
		$core_result = $ability->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $core_result );
		$this->assertSame( 'ability_invalid_input', $core_result->get_error_code() );

		$result = $this->check_permission( $path, $ability, $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	/**
	 * A normalization filter must run once before the permission callback.
	 *
	 * @dataProvider permission_paths
	 * @param string $path Adapter entry point.
	 */
	public function test_input_is_prepared_once_before_permission_check( string $path ): void {
		global $wp_version;
		if ( version_compare( $wp_version, '7.1', '<' ) ) {
			$this->markTestSkipped( 'The input normalization filter requires WordPress 7.1 or later.' );
		}

		$calls   = 0;
		$seen    = array();
		$ability = $this->make_ability(
			array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
			static function ( $input ) use ( &$seen ): bool {
				$seen[] = $input;
				return true;
			}
		);
		$filter  = static function ( $input, string $name ) use ( $ability, &$calls ) {
			if ( $ability->get_name() === $name ) {
				++$calls;
				++$input['id'];
			}
			return $input;
		};
		add_filter( 'wp_ability_normalize_input', $filter, 10, 2 );
		try {
			$this->assertTrue( $this->check_permission( $path, $ability, array( 'id' => 1 ) ) );
			$this->assertSame( array( array( 'id' => 2 ) ), $seen );
			$this->assertSame( 1, $calls );
		} finally {
			remove_filter( 'wp_ability_normalize_input', $filter, 10 );
		}
	}

	/**
	 * Register a real ability exposed through the execute-ability entry point.
	 *
	 * @param array    $schema     Input schema.
	 * @param callable $permission Permission callback.
	 * @return \WP_Ability
	 */
	private function make_ability( array $schema, callable $permission ): WP_Ability {
		$this->register_ability_in_hook(
			$this->ability_name,
			array(
				'label'               => 'Permission input fixture',
				'description'         => 'Checks permission input across adapter entry points.',
				'category'            => 'test',
				'input_schema'        => $schema,
				'permission_callback' => $permission,
				'execute_callback'    => static function (): string {
					return 'executed';
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		return wp_get_ability( $this->ability_name );
	}

	/**
	 * Invoke a public adapter permission entry point.
	 *
	 * @param string     $path    Entry point.
	 * @param \WP_Ability $ability Registered ability.
	 * @param array      $input   MCP arguments.
	 * @return bool|\WP_Error
	 */
	private function check_permission( string $path, WP_Ability $ability, array $input ) {
		if ( 'execute-ability' === $path ) {
			return ExecuteAbilityAbility::check_permission(
				array(
					'ability_name' => $ability->get_name(),
					'parameters'   => $input,
				)
			);
		}

		$component = 'tool' === $path ? McpTool::fromAbility( $ability ) : McpPrompt::fromAbility( $ability );
		$this->assertNotInstanceOf( WP_Error::class, $component );

		return $component->check_permission( $input );
	}
}
