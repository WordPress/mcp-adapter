<?php
/**
 * Protocol-specific tools/call result encoding.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Domain\Tools\ToolCallOutcome;
use WP\McpSchema\V20251125\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\V20251125\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\V20251125\Common\Protocol\Factory\ContentBlockFactory;
use WP\McpSchema\V20251125\Server\Tools\DTO\CallToolResult as V20251125CallToolResult;
use WP\McpSchema\V20260728\Server\Tools\DTO\CallToolResult as V20260728CallToolResult;

/**
 * Encodes completed tool calls through the exact selected schema DTO.
 *
 * @internal
 * @since n.e.x.t
 */
final class ToolCallResultEncoder {

	/**
	 * Encode an outcome for one request.
	 *
	 * @param \WP\MCP\Core\McpProtocolContext      $context Request protocol context.
	 * @param \WP\MCP\Domain\Tools\ToolCallOutcome $outcome Completed tool-call outcome.
	 *
	 * @return array<string, mixed>
	 * @throws \UnexpectedValueException When the outcome cannot be represented by the selected revision.
	 */
	public static function encode( McpProtocolContext $context, ToolCallOutcome $outcome ): array {
		switch ( $context->get_schema_revision() ) {
			case McpProtocolContext::SCHEMA_REVISION_2025_11_25:
				return self::encode_2025_11_25_dto( $outcome )->toArray();

			case McpProtocolContext::SCHEMA_REVISION_2026_07_28:
				return self::encode_2026_07_28( $outcome );
		}

		throw new \InvalidArgumentException(
			sprintf( 'Unsupported MCP schema revision: %s', $context->get_schema_revision() )
		);
	}

	/**
	 * Encode the documented direct-handler result through the legacy DTO tree.
	 *
	 * @param \WP\MCP\Domain\Tools\ToolCallOutcome $outcome Completed tool-call outcome.
	 * @return \WP\McpSchema\V20251125\Server\Tools\DTO\CallToolResult
	 */
	public static function encode_2025_11_25_dto( ToolCallOutcome $outcome ): V20251125CallToolResult {
		$data = array(
			'content' => array_map( array( self::class, 'hydrate_2025_11_25_content_block' ), $outcome->get_content() ),
			'isError' => $outcome->is_error(),
		);

		if ( $outcome->has_structured_content() ) {
			$structured_content = $outcome->get_structured_content();
			if ( ! is_array( $structured_content ) || self::is_list( $structured_content ) ) {
				throw new \UnexpectedValueException( 'The 2025-11-25 protocol requires structuredContent to be a JSON object.' );
			}

			$data['structuredContent'] = $structured_content;
		}

		return V20251125CallToolResult::fromArray( $data );
	}

	/**
	 * Encode a 2026-07-28 result.
	 *
	 * @param \WP\MCP\Domain\Tools\ToolCallOutcome $outcome Completed tool-call outcome.
	 * @return array<string, mixed>
	 */
	private static function encode_2026_07_28( ToolCallOutcome $outcome ): array {
		$data = array(
			'resultType' => 'complete',
			'content'    => $outcome->get_content(),
			'isError'    => $outcome->is_error(),
		);

		if ( $outcome->has_structured_content() ) {
			$data['structuredContent'] = $outcome->get_structured_content();
		}

		$result = V20260728CallToolResult::fromArray( $data )->toArray();

		// The generated DTO omits optional null fields, but this revision allows
		// every JSON value, including an explicit null.
		if ( $outcome->has_structured_content() && null === $outcome->get_structured_content() ) {
			$result['structuredContent'] = null;
		}

		return $result;
	}

	/**
	 * Hydrate nested resource contents before the generated block factory.
	 *
	 * @param array<string, mixed> $content Content block data.
	 * @return \WP\McpSchema\V20251125\Common\Protocol\Union\ContentBlockInterface
	 */
	private static function hydrate_2025_11_25_content_block( array $content ) {
		if ( 'resource' === ( $content['type'] ?? null ) && isset( $content['resource'] ) && is_array( $content['resource'] ) ) {
			$resource = $content['resource'];
			if ( array_key_exists( 'text', $resource ) ) {
				$content['resource'] = TextResourceContents::fromArray( $resource );
			} elseif ( array_key_exists( 'blob', $resource ) ) {
				$content['resource'] = BlobResourceContents::fromArray( $resource );
			}
		}

		return ContentBlockFactory::fromArray( $content );
	}

	/**
	 * Whether an array encodes as a JSON list.
	 *
	 * @param array<mixed> $value Value to inspect.
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
