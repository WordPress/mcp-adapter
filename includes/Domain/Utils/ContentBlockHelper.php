<?php
/**
 * ContentBlockHelper - Factory for creating revision-neutral MCP content block data.
 *
 * @package WP\MCP\Domain\Utils
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Helper class for creating MCP content block arrays.
 *
 * The schema package validates and hydrates these values at the selected revision's
 * wire boundary. Component and handler code remain independent of generated records.
 *
 * Every `_meta` argument passes through {@see McpValidator::normalize_meta()}, so a
 * PHP list is omitted instead of being serialized where MCP declares a JSON object.
 *
 * @since 0.5.0
 */
final class ContentBlockHelper {

	/**
	 * Create image content.
	 *
	 * @param string $data Base64-encoded image data.
	 * @param string $mime_type The MIME type of the image (e.g., 'image/png').
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> Image content data.
	 */
	public static function image( string $data, string $mime_type, ?array $annotations = null, ?array $_meta = null ): array {
		return self::with_optional_fields(
			array(
				'type'     => 'image',
				'data'     => $data,
				'mimeType' => $mime_type,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Create audio content.
	 *
	 * @param string $data Base64-encoded audio data.
	 * @param string $mime_type The MIME type of the audio (e.g., 'audio/mp3').
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> Audio content data.
	 */
	public static function audio( string $data, string $mime_type, ?array $annotations = null, ?array $_meta = null ): array {
		return self::with_optional_fields(
			array(
				'type'     => 'audio',
				'data'     => $data,
				'mimeType' => $mime_type,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Create embedded text resource content.
	 *
	 * The wrapper and nested resource each have their own `_meta` field.
	 *
	 * @since 0.6.0 Added the optional $resource_meta parameter.
	 *
	 * @param string $uri The URI of the resource.
	 * @param string $text The text content of the resource.
	 * @param string|null $mime_type Optional MIME type of the resource.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 * @param array<string, mixed>|null $resource_meta Optional metadata for the nested resource contents.
	 *
	 * @return array<string, mixed> Embedded resource content data.
	 */
	public static function embedded_text_resource(
		string $uri,
		string $text,
		?string $mime_type = null,
		?array $annotations = null,
		?array $_meta = null,
		?array $resource_meta = null
	): array {
		$resource = array(
			'uri'  => $uri,
			'text' => $text,
		);

		if ( null !== $mime_type ) {
			$resource['mimeType'] = $mime_type;
		}

		$normalized_resource_meta = McpValidator::normalize_meta( $resource_meta );
		if ( null !== $normalized_resource_meta ) {
			$resource['_meta'] = $normalized_resource_meta;
		}

		return self::with_optional_fields(
			array(
				'type'     => 'resource',
				'resource' => $resource,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Create embedded binary resource content.
	 *
	 * The wrapper and nested resource each have their own `_meta` field.
	 *
	 * @since 0.6.0 Added the optional $resource_meta parameter.
	 *
	 * @param string $uri The URI of the resource.
	 * @param string $blob Base64-encoded binary data.
	 * @param string|null $mime_type Optional MIME type of the resource.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 * @param array<string, mixed>|null $resource_meta Optional metadata for the nested resource contents.
	 *
	 * @return array<string, mixed> Embedded resource content data.
	 */
	public static function embedded_blob_resource(
		string $uri,
		string $blob,
		?string $mime_type = null,
		?array $annotations = null,
		?array $_meta = null,
		?array $resource_meta = null
	): array {
		$resource = array(
			'uri'  => $uri,
			'blob' => $blob,
		);

		if ( null !== $mime_type ) {
			$resource['mimeType'] = $mime_type;
		}

		$normalized_resource_meta = McpValidator::normalize_meta( $resource_meta );
		if ( null !== $normalized_resource_meta ) {
			$resource['_meta'] = $normalized_resource_meta;
		}

		return self::with_optional_fields(
			array(
				'type'     => 'resource',
				'resource' => $resource,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Create text content for an error response.
	 *
	 * @param string $message The error message.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> Text content data.
	 */
	public static function error_text( string $message, ?array $annotations = null, ?array $_meta = null ): array {
		return self::text( $message, $annotations, $_meta );
	}

	/**
	 * Create text content.
	 *
	 * @param string $text The text content.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> Text content data.
	 */
	public static function text( string $text, ?array $annotations = null, ?array $_meta = null ): array {
		return self::with_optional_fields(
			array(
				'type' => 'text',
				'text' => $text,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Create text content with JSON-encoded data.
	 *
	 * @param mixed $data The data to JSON-encode.
	 * @param int $flags JSON encoding flags (default: 0).
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> Text content data.
	 */
	public static function json_text( $data, int $flags = 0, ?array $annotations = null, ?array $_meta = null ): array {
		$json = wp_json_encode( $data, $flags );
		if ( false === $json ) {
			$json = '{}';
		}

		return self::text( $json, $annotations, $_meta );
	}

	/**
	 * Return an array of content blocks at compatibility call sites.
	 *
	 * @param list<array<string, mixed>> $blocks Content block data.
	 *
	 * @return list<array<string, mixed>> Content block data.
	 */
	public static function to_array_list( array $blocks ): array {
		return array_values( $blocks );
	}

	/**
	 * Add optional shared content-block fields without storing nulls.
	 *
	 * @param array<string, mixed> $content Required content data.
	 * @param array<string, mixed>|null $annotations Optional annotations.
	 * @param array<string, mixed>|null $_meta Optional metadata.
	 *
	 * @return array<string, mixed> Content data.
	 */
	private static function with_optional_fields( array $content, ?array $annotations, ?array $_meta ): array {
		if ( null !== $annotations ) {
			$content['annotations'] = $annotations;
		}

		$normalized_meta = McpValidator::normalize_meta( $_meta );
		if ( null !== $normalized_meta ) {
			$content['_meta'] = $normalized_meta;
		}

		return $content;
	}
}
