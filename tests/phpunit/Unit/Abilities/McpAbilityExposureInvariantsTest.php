<?php
/**
 * Cross-cutting invariants that the `mcp_adapter_is_ability_exposed`
 * filter must satisfy across all three built-in MCP ability tools.
 *
 * These invariants are the security contract of the filter. Regressions
 * here would let one tool's exposure decision drift from another's, or
 * let downstreams silently observe inconsistent context shapes across
 * discover / get-info / execute — either of which could result in an
 * ability being exposed to the wrong caller.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Abilities\GetAbilityInfoAbility;
use WP\MCP\Abilities\McpAbilityExposureContext;
use WP\MCP\Tests\TestCase;

/**
 * Verifies the shared shape and semantics of the exposure filter.
 */
final class McpAbilityExposureInvariantsTest extends TestCase {

	/**
	 * User ID for authenticated tests.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'exposureuser',
				'user_pass'  => 'testpass',
				'user_email' => 'exposure@example.com',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );

		$this->register_ability_in_hook(
			'test/exposure-invariant',
			array(
				'label'               => 'Exposure Invariant Test',
				'description'         => 'Ability probed by all three built-in tools.',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array( 'ok' => true ); },
				'permission_callback' => static function () {
					return true; },
				// Statically public so all three tools reach the filter.
				'meta'                => array( 'mcp' => array( 'public' => true, 'type' => 'tool' ) ),
			)
		);
	}

	public function tear_down(): void {
		wp_unregister_ability( 'test/exposure-invariant' );
		wp_set_current_user( 0 );
		wp_delete_user( $this->user_id );
		parent::tear_down();
	}

	/**
	 * INVARIANT: all three built-in tools call the same filter, with a
	 * context of the same shape and only `exposure_path` differing.
	 *
	 * If this ever fails, one of the tools has diverged and downstream
	 * exposure filters can no longer trust that their view is uniform
	 * across discover / get-info / execute.
	 */
	public function test_exposure_filter_receives_identical_context_shape_across_all_three_tools(): void {
		$captured = array();
		$filter   = static function ( $is_exposed, $ability, $context ) use ( &$captured ) {
			if ( 'test/exposure-invariant' === $ability->get_name() ) {
				$captured[ $context->exposure_path ] = $context;
			}
			return $is_exposed;
		};
		add_filter( 'mcp_adapter_is_ability_exposed', $filter, 10, 3 );

		try {
			DiscoverAbilitiesAbility::execute( array() );
			GetAbilityInfoAbility::check_permission( array( 'ability_name' => 'test/exposure-invariant' ) );
			ExecuteAbilityAbility::check_permission(
				array(
					'ability_name' => 'test/exposure-invariant',
					'parameters'   => new \stdClass(),
				)
			);
		} finally {
			remove_filter( 'mcp_adapter_is_ability_exposed', $filter, 10 );
		}

		// All three exposure paths must have been recorded.
		$this->assertArrayHasKey( McpAbilityExposureContext::PATH_DISCOVER, $captured );
		$this->assertArrayHasKey( McpAbilityExposureContext::PATH_GET_INFO, $captured );
		$this->assertArrayHasKey( McpAbilityExposureContext::PATH_EXECUTE, $captured );

		// Each context is the value-object type and carries the expected path.
		foreach ( $captured as $path => $context ) {
			$this->assertInstanceOf( McpAbilityExposureContext::class, $context );
			$this->assertSame( $path, $context->exposure_path );
		}

		// Non-path fields must be identical across the three tools —
		// the request context is the same, only the exposure path differs.
		$reference = $captured[ McpAbilityExposureContext::PATH_DISCOVER ];
		foreach ( array( McpAbilityExposureContext::PATH_GET_INFO, McpAbilityExposureContext::PATH_EXECUTE ) as $other_path ) {
			$other = $captured[ $other_path ];
			$this->assertSame( $reference->server, $other->server, "server drifts between discover and {$other_path}" );
			$this->assertSame( $reference->principal_id, $other->principal_id, "principal_id drifts between discover and {$other_path}" );
			$this->assertSame( $reference->roles, $other->roles, "roles drifts between discover and {$other_path}" );
			$this->assertSame( $reference->site_id, $other->site_id, "site_id drifts between discover and {$other_path}" );
		}

		// Sanity: the context reflects the actual authenticated user.
		$this->assertSame( $this->user_id, $reference->principal_id );
		$this->assertContains( 'administrator', $reference->roles );
	}
}
