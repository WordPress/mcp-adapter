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

	/** Build image content. */
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

	/** Build audio content. */
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

	/** Build embedded text resource content. */
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

	/** Build embedded blob resource content. */
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

	/** Build text content used for an error. */
	public static function error_text( string $message, ?array $annotations = null, ?array $_meta = null ): array {
		return self::text( $message, $annotations, $_meta );
	}

	/** Build text content. */
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
	 * Build text content from JSON-encodable data.
	 *
	 * @param mixed $data JSON-encodable data.
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

	/** Build the embedded wrapper. */
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

	/** Remove optional null fields while preserving false, zero, and empty strings. */
	private static function without_nulls( array $data ): array {
		return array_filter( $data, static fn( $value ): bool => null !== $value );
	}
}
