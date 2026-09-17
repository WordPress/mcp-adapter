<?php
/**
 * Revision-neutral MCP content block builders.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Builds neutral arrays that are validated with the selected result schema.
 *
 * @since 0.5.0
 */
final class ContentBlockHelper {

	/**
	 * Build revision-neutral image content.
	 *
	 * @param string $data Base64-encoded image data.
	 * @param string $mime_type Media type of the image.
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 *
	 * @return array<string, mixed> Content block for validation through the selected result schema.
	 */
	public static function image( string $data, string $mime_type, ?array $annotations = null, ?array $_meta = null ): array {
		return self::without_nulls(
			array(
				'type'        => 'image',
				'data'        => $data,
				'mimeType'    => $mime_type,
				'annotations' => $annotations,
				'_meta'       => McpValidator::normalize_meta( $_meta ),
			)
		);
	}

	/**
	 * Build revision-neutral audio content.
	 *
	 * @param string $data Base64-encoded audio data.
	 * @param string $mime_type Media type of the audio.
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 *
	 * @return array<string, mixed> Content block for validation through the selected result schema.
	 */
	public static function audio( string $data, string $mime_type, ?array $annotations = null, ?array $_meta = null ): array {
		return self::without_nulls(
			array(
				'type'        => 'audio',
				'data'        => $data,
				'mimeType'    => $mime_type,
				'annotations' => $annotations,
				'_meta'       => McpValidator::normalize_meta( $_meta ),
			)
		);
	}

	/**
	 * Wrap resource contents in an embedded-resource content block.
	 *
	 * @since 0.6.0 Added the optional $resource_meta parameter.
	 *
	 * @param string $uri Resource identifier.
	 * @param string $text Text content of the resource.
	 * @param string|null $mime_type Optional resource media type.
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 * @param array<mixed>|null $resource_meta Optional metadata for the nested resource contents.
	 *
	 * @return array<string, mixed> Embedded-resource block with separate outer and resource metadata.
	 */
	public static function embedded_text_resource(
		string $uri,
		string $text,
		?string $mime_type = null,
		?array $annotations = null,
		?array $_meta = null,
		?array $resource_meta = null
	): array {
		return self::embedded_resource(
			self::without_nulls(
				array(
					'uri'      => $uri,
					'text'     => $text,
					'mimeType' => $mime_type,
					'_meta'    => McpValidator::normalize_meta( $resource_meta ),
				)
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Wrap resource contents in an embedded-resource content block.
	 *
	 * @since 0.6.0 Added the optional $resource_meta parameter.
	 *
	 * @param string $uri Resource identifier.
	 * @param string $blob Base64-encoded resource contents.
	 * @param string|null $mime_type Optional resource media type.
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 * @param array<mixed>|null $resource_meta Optional metadata for the nested resource contents.
	 *
	 * @return array<string, mixed> Embedded-resource block with separate outer and resource metadata.
	 */
	public static function embedded_blob_resource(
		string $uri,
		string $blob,
		?string $mime_type = null,
		?array $annotations = null,
		?array $_meta = null,
		?array $resource_meta = null
	): array {
		return self::embedded_resource(
			self::without_nulls(
				array(
					'uri'      => $uri,
					'blob'     => $blob,
					'mimeType' => $mime_type,
					'_meta'    => McpValidator::normalize_meta( $resource_meta ),
				)
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Build text content describing an error.
	 *
	 * @param string $message Error text.
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 *
	 * @return array<string, mixed> Text block; does not itself mark a tool result as an error.
	 */
	public static function error_text( string $message, ?array $annotations = null, ?array $_meta = null ): array {
		return self::text( $message, $annotations, $_meta );
	}

	/**
	 * Build revision-neutral text content.
	 *
	 * @param string $text Content text.
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 *
	 * @return array<string, mixed> Text content block.
	 */
	public static function text( string $text, ?array $annotations = null, ?array $_meta = null ): array {
		return self::without_nulls(
			array(
				'type'        => 'text',
				'text'        => $text,
				'annotations' => $annotations,
				'_meta'       => McpValidator::normalize_meta( $_meta ),
			)
		);
	}

	/**
	 * Encode a value as the text of a content block.
	 *
	 * @param mixed $data JSON-encodable value.
	 * @param int $flags JSON encoding flags passed to wp_json_encode().
	 * @param array<mixed>|null $annotations Optional content annotations.
	 * @param array<mixed>|null $_meta Optional metadata for the outer content block.
	 *
	 * @return array<string, mixed> Text content block; uses "{}" when JSON encoding fails.
	 */
	public static function json_text( $data, int $flags = 0, ?array $annotations = null, ?array $_meta = null ): array {
		$json = wp_json_encode( $data, $flags );
		if ( false === $json ) {
			$json = '{}';
		}

		return self::text( $json, $annotations, $_meta );
	}

	/**
	 * Normalize neutral block arrays.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_array_list( array $blocks ): array {
		return array_values( $blocks );
	}

	/**
	 * Build the wrapper around embedded resource contents.
	 *
	 * @param array<string, mixed> $resource_data Text or blob resource contents.
	 * @param array<mixed>|null $annotations Optional outer-block annotations.
	 * @param array<mixed>|null $_meta Optional outer-block metadata.
	 *
	 * @return array<string, mixed> Embedded-resource content block.
	 */
	private static function embedded_resource( array $resource_data, ?array $annotations, ?array $_meta ): array {
		return self::without_nulls(
			array(
				'type'        => 'resource',
				'resource'    => $resource_data,
				'annotations' => $annotations,
				'_meta'       => McpValidator::normalize_meta( $_meta ),
			)
		);
	}

	/**
	 * Remove optional null fields without reindexing the remaining values.
	 *
	 * @param array<string, mixed> $data Content or resource fields.
	 *
	 * @return array<string, mixed> Fields retaining false, zero, and empty-string values.
	 */
	private static function without_nulls( array $data ): array {
		return array_filter( $data, static fn( $value ): bool => null !== $value );
	}
}
