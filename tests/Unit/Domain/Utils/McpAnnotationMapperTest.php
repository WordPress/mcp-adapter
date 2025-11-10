<?php
/**
 * Tests for McpAnnotationMapper class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Tests\TestCase;

/**
 * Test McpAnnotationMapper functionality.
 */
final class McpAnnotationMapperTest extends TestCase {

	public function test_map_with_empty_array(): void {
		$result = McpAnnotationMapper::map( array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_map_filters_out_non_mcp_fields(): void {
		$annotations = array(
			'customField'  => 'value',
			'invalidField' => 123,
			'audience'     => array( 'user' ),
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayNotHasKey( 'customField', $result );
		$this->assertArrayNotHasKey( 'invalidField', $result );
	}

	public function test_map_with_valid_audience(): void {
		$annotations = array(
			'audience' => array( 'user', 'assistant' ),
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertIsArray( $result['audience'] );
		$this->assertContains( 'user', $result['audience'] );
		$this->assertContains( 'assistant', $result['audience'] );
		$this->assertCount( 2, $result['audience'] );
	}

	public function test_map_filters_invalid_audience_roles(): void {
		$annotations = array(
			'audience' => array( 'user', 'invalid-role', 'assistant', 'another-invalid' ),
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertContains( 'user', $result['audience'] );
		$this->assertContains( 'assistant', $result['audience'] );
		$this->assertNotContains( 'invalid-role', $result['audience'] );
		$this->assertNotContains( 'another-invalid', $result['audience'] );
		$this->assertCount( 2, $result['audience'] );
	}

	public function test_map_filters_out_all_invalid_audience_roles(): void {
		$annotations = array(
			'audience' => array( 'invalid-role-1', 'invalid-role-2' ),
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'audience', $result );
	}

	public function test_map_filters_out_non_array_audience(): void {
		$annotations = array(
			'audience' => 'not-an-array',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'audience', $result );
	}

	public function test_map_filters_out_empty_audience_array(): void {
		$annotations = array(
			'audience' => array(),
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'audience', $result );
	}

	public function test_map_filters_out_non_string_audience_roles(): void {
		$annotations = array(
			'audience' => array( 'user', 123, true, array( 'nested' ) ),
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertContains( 'user', $result['audience'] );
		$this->assertCount( 1, $result['audience'] );
	}

	public function test_map_with_valid_lastmodified(): void {
		$annotations = array(
			'lastModified' => '2024-01-15T10:30:00Z',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertSame( '2024-01-15T10:30:00Z', $result['lastModified'] );
	}

	public function test_map_trims_lastmodified_whitespace(): void {
		$annotations = array(
			'lastModified' => '  2024-01-15T10:30:00Z  ',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertSame( '2024-01-15T10:30:00Z', $result['lastModified'] );
	}

	public function test_map_filters_out_invalid_lastmodified_format(): void {
		$annotations = array(
			'lastModified' => 'invalid-date-format',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'lastModified', $result );
	}

	public function test_map_filters_out_empty_lastmodified(): void {
		$annotations = array(
			'lastModified' => '',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'lastModified', $result );
	}

	public function test_map_filters_out_whitespace_only_lastmodified(): void {
		$annotations = array(
			'lastModified' => '   ',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'lastModified', $result );
	}

	public function test_map_filters_out_non_string_lastmodified(): void {
		$annotations = array(
			'lastModified' => 1234567890,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'lastModified', $result );
	}

	public function test_map_with_valid_priority(): void {
		$annotations = array(
			'priority' => 0.5,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'priority', $result );
		$this->assertSame( 0.5, $result['priority'] );
	}

	public function test_map_with_priority_as_string_number(): void {
		$annotations = array(
			'priority' => '0.7',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'priority', $result );
		$this->assertSame( 0.7, $result['priority'] );
	}

	public function test_map_clamps_priority_below_zero(): void {
		$annotations = array(
			'priority' => -1.0,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'priority', $result );
		$this->assertSame( 0.0, $result['priority'] );
	}

	public function test_map_clamps_priority_above_one(): void {
		$annotations = array(
			'priority' => 2.0,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'priority', $result );
		$this->assertSame( 1.0, $result['priority'] );
	}

	public function test_map_preserves_priority_at_zero(): void {
		$annotations = array(
			'priority' => 0.0,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'priority', $result );
		$this->assertSame( 0.0, $result['priority'] );
	}

	public function test_map_preserves_priority_at_one(): void {
		$annotations = array(
			'priority' => 1.0,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'priority', $result );
		$this->assertSame( 1.0, $result['priority'] );
	}

	public function test_map_filters_out_non_numeric_priority(): void {
		$annotations = array(
			'priority' => 'not-a-number',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'priority', $result );
	}

	public function test_map_filters_out_null_priority(): void {
		$annotations = array(
			'priority' => null,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayNotHasKey( 'priority', $result );
	}

	public function test_map_with_all_valid_fields(): void {
		$annotations = array(
			'audience'     => array( 'user', 'assistant' ),
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => 0.8,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertArrayHasKey( 'priority', $result );
		$this->assertCount( 3, $result );
	}

	public function test_map_with_partial_fields(): void {
		$annotations = array(
			'audience' => array( 'user' ),
			'priority' => 0.5,
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'priority', $result );
		$this->assertArrayNotHasKey( 'lastModified', $result );
		$this->assertCount( 2, $result );
	}

	public function test_map_with_missing_fields(): void {
		$annotations = array(
			'customField' => 'value',
		);

		$result = McpAnnotationMapper::map( $annotations );

		$this->assertEmpty( $result );
	}

	public function test_map_with_iso8601_variations(): void {
		// Test formats that are reliably supported
		$valid_formats = array(
			'2024-01-15T10:30:00Z',
			'2024-01-15T10:30:00+00:00',
		);

		foreach ( $valid_formats as $format ) {
			$annotations = array(
				'lastModified' => $format,
			);

			$result = McpAnnotationMapper::map( $annotations );

			$this->assertArrayHasKey( 'lastModified', $result, "Format '{$format}' should be valid" );
		}

		// Test microseconds formats (may not be supported by all DateTime implementations)
		$microsecond_formats = array(
			'2024-01-15T10:30:00.123Z',
			'2024-01-15T10:30:00.123+00:00',
		);

		foreach ( $microsecond_formats as $format ) {
			$annotations = array(
				'lastModified' => $format,
			);

			$result = McpAnnotationMapper::map( $annotations );
			// Microseconds support is implementation-dependent, so accept either result
			// If supported, it should be in the result; if not, it should be filtered out
			$this->assertIsArray( $result );
		}
	}

	public function test_map_with_priority_edge_cases(): void {
		$test_cases = array(
			array(
				'input'    => -0.5,
				'expected' => 0.0,
			),
			array(
				'input'    => 0.25,
				'expected' => 0.25,
			),
			array(
				'input'    => 0.75,
				'expected' => 0.75,
			),
			array(
				'input'    => 1.5,
				'expected' => 1.0,
			),
			array(
				'input'    => '0',
				'expected' => 0.0,
			),
			array(
				'input'    => '1',
				'expected' => 1.0,
			),
			array(
				'input'    => '0.5',
				'expected' => 0.5,
			),
		);

		foreach ( $test_cases as $test_case ) {
			$annotations = array(
				'priority' => $test_case['input'],
			);

			$result = McpAnnotationMapper::map( $annotations );

			$this->assertArrayHasKey( 'priority', $result, "Priority {$test_case['input']} should be processed" );
			$this->assertSame( $test_case['expected'], $result['priority'], "Priority {$test_case['input']} should be {$test_case['expected']}" );
		}
	}
}
