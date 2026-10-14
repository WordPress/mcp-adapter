<?php
/**
 * Tests for the answers to an ability name that does not exist or is not exposed.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\AbilityNameSuggester;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Abilities\GetAbilityInfoAbility;
use WP\MCP\Tests\TestCase;
use WP_Error;

/**
 * Agents guess ability names. These tests pin the answer they get: say
 * plainly the ability is not on the site, name the closest abilities they
 * can call, and never send the site owner after a setting that does not
 * exist. No _doing_it_wrong notice is expected anywhere here (#331).
 */
final class AbilityNameSuggesterTest extends TestCase {

	/** @var string[] */
	private array $registered = array();

	public function setUp(): void {
		parent::setUp();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->register( 'rankmath/create-redirection', true );
		$this->register( 'rankmath/list-redirections', true );
		$this->register( 'rankmath/delete-redirection', false );
		$this->register( 'acme/hidden-redirection', false );
	}

	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				continue;
			}
			wp_unregister_ability( $name );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function register( string $name, bool $mcp_public ): void {
		$this->register_ability_in_hook(
			$name,
			array(
				'label'               => $name,
				'description'         => 'Fixture ability.',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array( 'ok' => true );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => $mcp_public ) ),
			)
		);
		$this->registered[] = $name;
	}

	public function test_another_spelling_of_the_namespace_suggests_the_real_name(): void {
		$this->assertSame( 'rankmath/create-redirection', AbilityNameSuggester::suggest( 'rank-math/create-redirection' )[0] ?? null );
	}

	public function test_words_in_another_order_suggest_the_real_name(): void {
		$this->assertContains( 'rankmath/create-redirection', AbilityNameSuggester::suggest( 'rank-math/redirection-create' ) );
	}

	public function test_only_abilities_exposed_through_mcp_are_suggested(): void {
		$suggestions = AbilityNameSuggester::suggest( 'rankmath/delete-redirections' );

		$this->assertNotContains( 'rankmath/delete-redirection', $suggestions );
		$this->assertNotContains( 'acme/hidden-redirection', $suggestions );
	}

	public function test_nothing_is_suggested_when_no_name_is_close(): void {
		$this->assertSame( array(), AbilityNameSuggester::suggest( 'core/create-page' ) );
	}

	public function test_suggestions_are_limited(): void {
		$this->assertLessThanOrEqual( 1, count( AbilityNameSuggester::suggest( 'rankmath/create-redirection-rule', 1 ) ) );
	}

	public function test_unknown_name_names_the_closest_abilities_without_a_notice(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'rank-math/create-redirection',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'not found on this WordPress site', $result->get_error_message() );
		$this->assertStringContainsString( 'rankmath/create-redirection', $result->get_error_message() );
		$this->assertContains( 'rankmath/create-redirection', $result->get_error_data()['suggestions'] );
	}

	public function test_unknown_name_with_nothing_close_points_at_discovery(): void {
		$result = GetAbilityInfoAbility::check_permission( array( 'ability_name' => 'core/create-page' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'mcp-adapter/discover-abilities', $result->get_error_message() );
		$this->assertSame( array(), $result->get_error_data()['suggestions'] );
	}

	public function test_execute_path_gives_the_same_answer(): void {
		$result = ExecuteAbilityAbility::execute(
			array(
				'ability_name' => 'rank-math/create-redirection',
				'parameters'   => array(),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'rankmath/create-redirection', $result['error'] );
	}

	public function test_registered_but_not_exposed_says_there_is_no_site_setting(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'rankmath/delete-redirection',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_not_public_mcp', $result->get_error_code() );
		$this->assertStringContainsString( 'is registered on this WordPress site', $result->get_error_message() );
		$this->assertStringContainsString( 'no setting', $result->get_error_message() );
	}
}
