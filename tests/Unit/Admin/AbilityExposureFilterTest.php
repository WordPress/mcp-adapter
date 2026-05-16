<?php
/**
 * AbilityExposureFilter unit tests.
 *
 * @package WP\MCP\Tests\Unit\Admin
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Admin;

use WP\MCP\Admin\AbilityExposureFilter;
use WP\MCP\Tests\TestCase;

/**
 * Class - AbilityExposureFilterTest
 *
 * Validates the wp_register_ability_args filter behavior driven by the
 * option set on the settings page.
 */
final class AbilityExposureFilterTest extends TestCase {

	/**
	 * Reset the option between cases.
	 */
	protected function set_up(): void {
		parent::set_up();
		delete_option( AbilityExposureFilter::OPTION );
	}

	public function test_empty_option_does_not_modify_args(): void {
		$filter = new AbilityExposureFilter();
		$args   = array( 'label' => 'X' );
		$out    = $filter->maybe_expose( $args, 'core/get-posts' );
		$this->assertSame( $args, $out );
	}

	public function test_opted_in_ability_receives_public_meta(): void {
		update_option( AbilityExposureFilter::OPTION, array( 'core/get-posts' ) );
		$filter = new AbilityExposureFilter();
		$out    = $filter->maybe_expose( array( 'label' => 'X' ), 'core/get-posts' );
		$this->assertSame( true, $out['meta']['mcp']['public'] );
	}

	public function test_non_opted_in_ability_is_unchanged(): void {
		update_option( AbilityExposureFilter::OPTION, array( 'core/get-posts' ) );
		$filter = new AbilityExposureFilter();
		$args   = array( 'label' => 'X', 'description' => 'Y' );
		$out    = $filter->maybe_expose( $args, 'core/get-pages' );
		$this->assertSame( $args, $out );
	}

	public function test_mcp_adapter_namespace_is_skipped(): void {
		update_option( AbilityExposureFilter::OPTION, array( 'mcp-adapter/discover-abilities' ) );
		$filter = new AbilityExposureFilter();
		$args   = array( 'label' => 'X' );
		$out    = $filter->maybe_expose( $args, 'mcp-adapter/discover-abilities' );
		$this->assertSame( $args, $out );
	}

	public function test_existing_meta_is_preserved(): void {
		update_option( AbilityExposureFilter::OPTION, array( 'core/get-posts' ) );
		$filter = new AbilityExposureFilter();
		$args   = array(
			'label' => 'X',
			'meta'  => array(
				'readonly' => true,
				'mcp'      => array(
					'priority' => 5,
				),
			),
		);
		$out = $filter->maybe_expose( $args, 'core/get-posts' );
		$this->assertTrue( $out['meta']['readonly'] );
		$this->assertSame( 5, $out['meta']['mcp']['priority'] );
		$this->assertTrue( $out['meta']['mcp']['public'] );
	}
}
