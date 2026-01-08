<?php
/**
 * Tests for FailureReason class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Observability;

use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\MCP\Tests\TestCase;

/**
 * Test FailureReason taxonomy functionality.
 */
final class FailureReasonTest extends TestCase {

	/**
	 * Test that all() returns all defined constants.
	 */
	public function test_all_returns_all_constants(): void {
		$all = FailureReason::all();

		$this->assertIsArray( $all );
		$this->assertNotEmpty( $all );

		// Verify key constants are included.
		$this->assertContains( FailureReason::ABILITY_NOT_FOUND, $all );
		$this->assertContains( FailureReason::DUPLICATE_URI, $all );
		$this->assertContains( FailureReason::BUILDER_EXCEPTION, $all );
		$this->assertContains( FailureReason::NO_PERMISSION_STRATEGY, $all );
		$this->assertContains( FailureReason::PERMISSION_DENIED, $all );
		$this->assertContains( FailureReason::PERMISSION_CHECK_FAILED, $all );
		$this->assertContains( FailureReason::NOT_FOUND, $all );
		$this->assertContains( FailureReason::EXECUTION_FAILED, $all );
	}

	/**
	 * Test that is_valid() returns true for valid values.
	 */
	public function test_is_valid_returns_true_for_valid_values(): void {
		$this->assertTrue( FailureReason::is_valid( FailureReason::ABILITY_NOT_FOUND ) );
		$this->assertTrue( FailureReason::is_valid( FailureReason::DUPLICATE_URI ) );
		$this->assertTrue( FailureReason::is_valid( FailureReason::PERMISSION_DENIED ) );
		$this->assertTrue( FailureReason::is_valid( 'not_found' ) );
		$this->assertTrue( FailureReason::is_valid( 'execution_failed' ) );
	}

	/**
	 * Test that is_valid() returns false for invalid values.
	 */
	public function test_is_valid_returns_false_for_invalid_values(): void {
		$this->assertFalse( FailureReason::is_valid( 'unknown_reason' ) );
		$this->assertFalse( FailureReason::is_valid( 'some_random_string' ) );
		$this->assertFalse( FailureReason::is_valid( '' ) );
		$this->assertFalse( FailureReason::is_valid( 'ABILITY_NOT_FOUND' ) ); // Case-sensitive.
	}

	/**
	 * Test constant values are stable strings.
	 */
	public function test_constant_values_are_strings(): void {
		$this->assertSame( 'ability_not_found', FailureReason::ABILITY_NOT_FOUND );
		$this->assertSame( 'duplicate_uri', FailureReason::DUPLICATE_URI );
		$this->assertSame( 'builder_exception', FailureReason::BUILDER_EXCEPTION );
		$this->assertSame( 'no_permission_strategy', FailureReason::NO_PERMISSION_STRATEGY );
		$this->assertSame( 'ability_conversion_failed', FailureReason::ABILITY_CONVERSION_FAILED );
		$this->assertSame( 'permission_denied', FailureReason::PERMISSION_DENIED );
		$this->assertSame( 'permission_check_failed', FailureReason::PERMISSION_CHECK_FAILED );
		$this->assertSame( 'not_found', FailureReason::NOT_FOUND );
		$this->assertSame( 'execution_failed', FailureReason::EXECUTION_FAILED );
		$this->assertSame( 'execution_exception', FailureReason::EXECUTION_EXCEPTION );
		$this->assertSame( 'missing_parameter', FailureReason::MISSING_PARAMETER );
		$this->assertSame( 'invalid_parameter', FailureReason::INVALID_PARAMETER );
	}

	/**
	 * Test all() returns unique values.
	 */
	public function test_all_values_are_unique(): void {
		$all    = FailureReason::all();
		$unique = array_unique( $all );

		$this->assertCount( count( $all ), $unique, 'All failure reason values should be unique' );
	}

	/**
	 * Test all() returns consistent count.
	 */
	public function test_all_returns_expected_count(): void {
		// Currently 12 failure reasons defined.
		$this->assertCount( 12, FailureReason::all() );
	}
}
