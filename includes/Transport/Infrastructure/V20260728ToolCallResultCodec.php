<?php
/**
 * Modern tools/call result codec.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Domain\Tools\ToolCallOutcome;
use WP\McpSchema\V20260728\Server\Tools\DTO\CallToolResult;

/**
 * Encodes the supported tools/call subset through the 2026-07-28 DTO tree.
 *
 * @internal
 * @since n.e.x.t
 */
final class V20260728ToolCallResultCodec implements ToolCallResultCodecInterface {

	/**
	 * {@inheritDoc}
	 */
	public function encode( ToolCallOutcome $outcome ): array {
		if ( ToolCallOutcome::RESULT_TYPE_COMPLETE !== $outcome->get_result_type() ) {
			throw new \WP\MCP\Transport\Infrastructure\ToolCallCodecException( 'Multi round-trip tool results are not supported.' );
		}

		$data = array(
			'resultType' => ToolCallOutcome::RESULT_TYPE_COMPLETE,
			'content'    => $outcome->get_content(),
			'isError'    => $outcome->is_error(),
		);

		if ( $outcome->has_structured_content() ) {
			$data['structuredContent'] = $outcome->get_structured_content();
		}

		$result = CallToolResult::fromArray( $data )->toArray();

		// The generated DTO omits null optional fields. Preserve an explicit JSON
		// null because the 2026 schema allows every JSON value here.
		if ( $outcome->has_structured_content() && null === $outcome->get_structured_content() ) {
			$result['structuredContent'] = null;
		}

		return $result;
	}
}
