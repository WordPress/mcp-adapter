<?php
/**
 * Tests for exact-revision tools/call codecs.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Domain\Tools\ToolCallOutcome;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\ToolCallCodecException;
use WP\MCP\Transport\Infrastructure\ToolCallResultCodecFactory;
use WP\MCP\Transport\Infrastructure\V20251125ToolCallResultCodec;
use WP\MCP\Transport\Infrastructure\V20260728ToolCallResultCodec;

/**
 * Verifies both schema trees can be loaded and used in one PHP process.
 */
final class ToolCallResultCodecTest extends TestCase {

	/**
	 * A completed object result is encoded through both exact DTO trees.
	 */
	public function test_both_revision_codecs_encode_one_outcome_in_the_same_process(): void {
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

		$result_2025_11_25 = ( new V20251125ToolCallResultCodec() )->encode( $outcome );
		$result_2026_07_28 = ( new V20260728ToolCallResultCodec() )->encode( $outcome );

		$this->assertArrayNotHasKey( 'resultType', $result_2025_11_25 );
		$this->assertSame( 'complete', $result_2026_07_28['resultType'] );
		$this->assertSame( $result_2025_11_25['content'], $result_2026_07_28['content'] );
		$this->assertSame( array( 'ok' => true ), $result_2025_11_25['structuredContent'] );
		$this->assertSame( array( 'ok' => true ), $result_2026_07_28['structuredContent'] );
	}

	/**
	 * The 2026-07-28 structuredContent field accepts every JSON value shape.
	 *
	 * @dataProvider provide_2026_07_28_structured_content_values
	 *
	 * @param mixed $value Structured JSON value.
	 */
	public function test_2026_07_28_codec_preserves_arbitrary_json_structured_content( $value ): void {
		$outcome = ToolCallOutcome::complete(
			array( array( 'type' => 'text', 'text' => 'value' ) ),
			$value,
			true
		);

		$result = ( new V20260728ToolCallResultCodec() )->encode( $outcome );

		$this->assertArrayHasKey( 'structuredContent', $result );
		$this->assertSame( $value, $result['structuredContent'] );
	}

	/**
	 * Values supported by 2026-07-28 structuredContent.
	 *
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
	 * The 2025-11-25 structuredContent field must remain a JSON object.
	 *
	 * @dataProvider provide_non_object_2025_11_25_values
	 *
	 * @param mixed $value Non-object JSON value.
	 */
	public function test_2025_11_25_codec_rejects_non_object_structured_content( $value ): void {
		$outcome = ToolCallOutcome::complete(
			array( array( 'type' => 'text', 'text' => 'value' ) ),
			$value,
			true
		);

		$this->expectException( ToolCallCodecException::class );
		$this->expectExceptionMessage( 'The 2025-11-25 protocol requires structuredContent to be a JSON object.' );

		( new V20251125ToolCallResultCodec() )->encode( $outcome );
	}

	/**
	 * Values that cannot be represented as 2025-11-25 JSON objects.
	 *
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

	/**
	 * MRTR emission remains explicitly unsupported in both revisions.
	 */
	public function test_both_codecs_reject_input_required_outcomes(): void {
		$outcome = ToolCallOutcome::input_required();

		foreach ( array( new V20251125ToolCallResultCodec(), new V20260728ToolCallResultCodec() ) as $codec ) {
			try {
				$codec->encode( $outcome );
				$this->fail( 'The codec should reject input_required.' );
			} catch ( ToolCallCodecException $exception ) {
				$this->assertSame( 'Multi round-trip tool results are not supported.', $exception->getMessage() );
			}
		}
	}

	/**
	 * The selector follows the request's exact schema revision.
	 */
	public function test_factory_selects_codec_from_protocol_context(): void {
		$this->assertInstanceOf(
			V20251125ToolCallResultCodec::class,
			ToolCallResultCodecFactory::for_context( McpProtocolContext::for_2025_11_25() )
		);
		$this->assertInstanceOf(
			V20260728ToolCallResultCodec::class,
			ToolCallResultCodecFactory::for_context( new McpProtocolContext( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 ) )
		);
	}

	public function test_factory_rejects_an_unknown_protocol_version(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '2099-01-01' );

		ToolCallResultCodecFactory::for_context( new McpProtocolContext( '2099-01-01' ) );
	}
}
