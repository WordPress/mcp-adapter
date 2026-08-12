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

		$legacy = ( new V20251125ToolCallResultCodec() )->encode( $outcome );
		$modern = ( new V20260728ToolCallResultCodec() )->encode( $outcome );

		$this->assertArrayNotHasKey( 'resultType', $legacy );
		$this->assertSame( 'complete', $modern['resultType'] );
		$this->assertSame( $legacy['content'], $modern['content'] );
		$this->assertSame( array( 'ok' => true ), $legacy['structuredContent'] );
		$this->assertSame( array( 'ok' => true ), $modern['structuredContent'] );
	}

	/**
	 * Modern structuredContent accepts every JSON value shape.
	 *
	 * @dataProvider provide_modern_structured_content_values
	 *
	 * @param mixed $value Structured JSON value.
	 */
	public function test_modern_codec_preserves_arbitrary_json_structured_content( $value ): void {
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
	 * Values supported by modern structuredContent.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function provide_modern_structured_content_values(): array {
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
	 * Legacy structuredContent must remain a JSON object.
	 *
	 * @dataProvider provide_non_object_legacy_values
	 *
	 * @param mixed $value Non-object JSON value.
	 */
	public function test_legacy_codec_rejects_non_object_structured_content( $value ): void {
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
	 * Values that cannot be represented as legacy JSON objects.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function provide_non_object_legacy_values(): array {
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
			ToolCallResultCodecFactory::for_context( McpProtocolContext::legacy_default() )
		);
		$this->assertInstanceOf(
			V20260728ToolCallResultCodec::class,
			ToolCallResultCodecFactory::for_context( new McpProtocolContext( McpProtocolContext::MODERN_SCHEMA_REVISION ) )
		);
	}
}
