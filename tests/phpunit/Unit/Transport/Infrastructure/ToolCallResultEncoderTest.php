<?php
/**
 * Tests for exact-revision tools/call output.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Domain\Tools\ToolCallOutcome;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\ToolCallResultEncoder;

/**
 * Verifies both schema revisions can encode results in one PHP process.
 */
final class ToolCallResultEncoderTest extends TestCase {

	/**
	 * A completed object result is encoded through both exact DTO trees.
	 */
	public function test_both_revisions_encode_one_outcome_in_the_same_process(): void {
		$outcome = ToolCallOutcome::complete(
			array(
				array(
					'type' => 'text',
					'text' => '{"ok":true}',
				),
			),
			array( 'ok' => true ),
			true
		);

		$result_2025_11_25 = ToolCallResultEncoder::encode( McpProtocolContext::for_2025_11_25(), $outcome );
		$result_2026_07_28 = ToolCallResultEncoder::encode(
			new McpProtocolContext( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 ),
			$outcome
		);

		$this->assertArrayNotHasKey( 'resultType', $result_2025_11_25 );
		$this->assertSame( 'complete', $result_2026_07_28['resultType'] );
		$this->assertSame( $result_2025_11_25['content'], $result_2026_07_28['content'] );
		$this->assertSame( array( 'ok' => true ), $result_2025_11_25['structuredContent'] );
		$this->assertSame( array( 'ok' => true ), $result_2026_07_28['structuredContent'] );
	}

	/**
	 * @dataProvider provide_2026_07_28_structured_content_values
	 *
	 * @param mixed $value Structured JSON value.
	 */
	public function test_2026_07_28_preserves_every_json_structured_content_shape( $value ): void {
		$outcome = ToolCallOutcome::complete(
			array( array( 'type' => 'text', 'text' => 'value' ) ),
			$value,
			true
		);

		$result = ToolCallResultEncoder::encode(
			new McpProtocolContext( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 ),
			$outcome
		);

		$this->assertArrayHasKey( 'structuredContent', $result );
		$this->assertSame( $value, $result['structuredContent'] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_2026_07_28_structured_content_values(): array {
		return array(
			'object'  => array( array( 'key' => 'value' ) ),
			'list'    => array( array( 'one', 'two' ) ),
			'string'  => array( 'value' ),
			'integer' => array( 42 ),
			'float'   => array( 1.5 ),
			'boolean' => array( true ),
			'null'    => array( null ),
		);
	}

	/**
	 * @dataProvider provide_non_object_2025_11_25_values
	 *
	 * @param mixed $value Non-object JSON value.
	 */
	public function test_2025_11_25_rejects_non_object_structured_content( $value ): void {
		$outcome = ToolCallOutcome::complete(
			array( array( 'type' => 'text', 'text' => 'value' ) ),
			$value,
			true
		);

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'The 2025-11-25 protocol requires structuredContent to be a JSON object.' );

		ToolCallResultEncoder::encode( McpProtocolContext::for_2025_11_25(), $outcome );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_non_object_2025_11_25_values(): array {
		return array(
			'empty-array' => array( array() ),
			'list'        => array( array( 'one', 'two' ) ),
			'string'      => array( 'value' ),
			'integer'     => array( 42 ),
			'boolean'     => array( false ),
			'null'        => array( null ),
		);
	}
}
