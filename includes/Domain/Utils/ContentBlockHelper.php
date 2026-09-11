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
 * Every field is carried as given. The schema package decides whether the
 * block fits the selected revision. `mimeType` and `_meta` values are therefore
 * not typed here: a decoded JSON object arrives as stdClass and must reach the
 * schema unchanged, because casting it to an array turns numeric-string keys
 * into a list.
 *
 * @since 0.5.0
 */
final class ContentBlockHelper {

	/**
	 * Build image content.
	 *
	 * @param string $data Base64 image data.
	 * @param mixed $mime_type MIME type, carried as given.
	 * @param array<string, mixed>|null $annotations Optional annotations.
	 * @param array<string, mixed>|\stdClass|null $_meta Optional block metadata, carried as given.
	 * @return array<string, mixed>
	 */
	public static function image( string $data, $mime_type, ?array $annotations = null, $_meta = null ): array {
		return self::without_nulls(
			array(
				'type'        => 'image',
				'data'        => $data,
				'mimeType'    => $mime_type,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Build embedded text resource content.
	 *
	 * @param string $uri Resource URI.
	 * @param string $text Resource text.
	 * @param mixed $mime_type MIME type, carried as given.
	 * @param array<string, mixed>|null $annotations Optional block annotations.
	 * @param array<string, mixed>|\stdClass|null $_meta Optional block metadata, carried as given.
	 * @param array<string, mixed>|\stdClass|null $resource_meta Optional resource metadata, carried as given.
	 * @return array<string, mixed>
	 */
	public static function embedded_text_resource(
		string $uri,
		string $text,
		$mime_type = null,
		?array $annotations = null,
		$_meta = null,
		$resource_meta = null
	): array {
		return self::embedded_resource(
			self::without_nulls(
				array(
					'uri'      => $uri,
					'text'     => $text,
					'mimeType' => $mime_type,
					'_meta'    => $resource_meta,
				)
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Build embedded blob resource content.
	 *
	 * @param string $uri Resource URI.
	 * @param string $blob Base64 resource data.
	 * @param mixed $mime_type MIME type, carried as given.
	 * @param array<string, mixed>|null $annotations Optional block annotations.
	 * @param array<string, mixed>|\stdClass|null $_meta Optional block metadata, carried as given.
	 * @param array<string, mixed>|\stdClass|null $resource_meta Optional resource metadata, carried as given.
	 * @return array<string, mixed>
	 */
	public static function embedded_blob_resource(
		string $uri,
		string $blob,
		$mime_type = null,
		?array $annotations = null,
		$_meta = null,
		$resource_meta = null
	): array {
		return self::embedded_resource(
			self::without_nulls(
				array(
					'uri'      => $uri,
					'blob'     => $blob,
					'mimeType' => $mime_type,
					'_meta'    => $resource_meta,
				)
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Build text content.
	 *
	 * @param string $text Text.
	 * @param array<string, mixed>|null $annotations Optional annotations.
	 * @param array<string, mixed>|\stdClass|null $_meta Optional block metadata, carried as given.
	 * @return array<string, mixed>
	 */
	public static function text( string $text, ?array $annotations = null, $_meta = null ): array {
		return self::without_nulls(
			array(
				'type'        => 'text',
				'text'        => $text,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Build the embedded wrapper.
	 *
	 * @param array<string, mixed> $resource_data Resource contents.
	 * @param array<string, mixed>|null $annotations Optional block annotations.
	 * @param array<string, mixed>|\stdClass|null $_meta Optional block metadata, carried as given.
	 * @return array<string, mixed>
	 */
	private static function embedded_resource( array $resource_data, ?array $annotations, $_meta ): array {
		return self::without_nulls(
			array(
				'type'        => 'resource',
				'resource'    => $resource_data,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/** Remove optional null fields while preserving false, zero, and empty strings. */
	private static function without_nulls( array $data ): array {
		return array_filter( $data, static fn( $value ): bool => null !== $value );
	}
}
