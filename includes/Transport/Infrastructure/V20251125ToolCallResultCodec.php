<?php
/**
 * Legacy tools/call result codec.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Domain\Tools\ToolCallOutcome;
use WP\McpSchema\V20251125\Server\Tools\DTO\CallToolResult;

/**
 * Encodes tool outcomes through the 2025-11-25 DTO tree.
 *
 * @internal
 * @since n.e.x.t
 */
final class V20251125ToolCallResultCodec implements ToolCallResultCodecInterface {

	/**
	 * {@inheritDoc}
	 */
	public function encode( ToolCallOutcome $outcome ): array {
		if ( ToolCallOutcome::RESULT_TYPE_COMPLETE !== $outcome->get_result_type() ) {
			throw new \WP\MCP\Transport\Infrastructure\ToolCallCodecException( 'Multi round-trip tool results are not supported.' );
		}

		$data = array(
			'content' => $outcome->get_content(),
			'isError' => $outcome->is_error(),
		);

		if ( $outcome->has_structured_content() ) {
			$structured_content = $outcome->get_structured_content();
			if ( ! is_array( $structured_content ) || self::is_list( $structured_content ) ) {
				throw new \WP\MCP\Transport\Infrastructure\ToolCallCodecException( 'The 2025-11-25 protocol requires structuredContent to be a JSON object.' );
			}

			$data['structuredContent'] = $structured_content;
		}

		return CallToolResult::fromArray( $data )->toArray();
	}

	/**
	 * Whether a PHP array has JSON-list keys.
	 *
	 * Kept compatible with PHP 7.4, where array_is_list() is unavailable.
	 * Empty arrays encode as JSON arrays and are therefore lists at this wire
	 * boundary, even though a producer may have intended an empty object.
	 *
	 * @param array<mixed> $value Value to inspect.
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
