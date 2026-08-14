<?php
/**
 * ContentBlockHelper - Factory for creating MCP content block arrays.
 *
 * This helper provides convenience methods for constructing content block arrays
 * in the shape the php-mcp-schema library emits. It simplifies the creation of
 * text, image, audio, and embedded-resource blocks.
 *
 * @package WP\MCP\Domain\Utils
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Helper class for creating MCP content block arrays.
 *
 * Provides static factory methods that return content blocks as plain arrays.
 * These blocks are used in tool call results, prompt messages, and resource
 * contents throughout the MCP protocol. Each factory returns a revision-neutral
 * array. Validation happens once, at the response boundary, when the encoder
 * hydrates the whole payload through the schema catalog.
 *
 * Every `_meta` argument passes through {@see McpValidator::normalize_meta()}, so a
 * PHP list is omitted instead of being serialized where MCP declares a JSON object.
 *
 * @since 0.5.0
 */
final class ContentBlockHelper {

	/**
	 * Creates an image content block.
	 *
	 * @param string $data Base64-encoded image data.
	 * @param string $mime_type The MIME type of the image (e.g., 'image/png').
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> The created image content block.
	 */
	public static function image( string $data, string $mime_type, ?array $annotations = null, ?array $_meta = null ): array {
		return self::decorate(
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
	 * Creates an audio content block.
	 *
	 * @param string $data Base64-encoded audio data.
	 * @param string $mime_type The MIME type of the audio (e.g., 'audio/mp3').
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> The created audio content block.
	 */
	public static function audio( string $data, string $mime_type, ?array $annotations = null, ?array $_meta = null ): array {
		return self::decorate(
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
	 * Creates an embedded resource content block with text resource contents.
	 *
	 * Use this for embedding text-based resources (files, documents, etc.) in content.
	 *
	 * The block has two levels that each carry their own `_meta`: the content
	 * block wrapper and the resource contents nested inside it. `$_meta` sets the
	 * wrapper's; `$resource_meta` sets the contents'. They are distinct fields in
	 * the spec and are not interchangeable.
	 *
	 * @since 0.6.0 Added the optional $resource_meta parameter.
	 *
	 * @param string $uri The URI of the resource.
	 * @param string $text The text content of the resource.
	 * @param string|null $mime_type Optional MIME type of the resource.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 * @param array|null $resource_meta Optional metadata for the nested resource contents.
	 *
	 * @return array<string, mixed> The created embedded resource content block.
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

		$contents_meta = McpValidator::normalize_meta( $resource_meta );
		if ( null !== $contents_meta ) {
			$resource['_meta'] = $contents_meta;
		}

		return self::decorate(
			array(
				'type'     => 'resource',
				'resource' => $resource,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Creates an embedded resource content block with blob resource contents.
	 *
	 * Use this for embedding binary resources (images, PDFs, etc.) in content.
	 *
	 * The block has two levels that each carry their own `_meta`: the content
	 * block wrapper and the resource contents nested inside it. `$_meta` sets the
	 * wrapper's; `$resource_meta` sets the contents'. They are distinct fields in
	 * the spec and are not interchangeable.
	 *
	 * @since 0.6.0 Added the optional $resource_meta parameter.
	 *
	 * @param string $uri The URI of the resource.
	 * @param string $blob Base64-encoded binary data.
	 * @param string|null $mime_type Optional MIME type of the resource.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 * @param array|null $resource_meta Optional metadata for the nested resource contents.
	 *
	 * @return array<string, mixed> The created embedded resource content block.
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

		$contents_meta = McpValidator::normalize_meta( $resource_meta );
		if ( null !== $contents_meta ) {
			$resource['_meta'] = $contents_meta;
		}

		return self::decorate(
			array(
				'type'     => 'resource',
				'resource' => $resource,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Creates a text content block for error messages.
	 *
	 * Convenience method for creating text content specifically for error responses.
	 * This is semantically equivalent to text() but makes the intent clearer in code.
	 *
	 * @param string $message The error message.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> The created text content block.
	 */
	public static function error_text( string $message, ?array $annotations = null, ?array $_meta = null ): array {
		return self::text( $message, $annotations, $_meta );
	}

	/**
	 * Creates a text content block.
	 *
	 * @param string $text The text content.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> The created text content block.
	 */
	public static function text( string $text, ?array $annotations = null, ?array $_meta = null ): array {
		return self::decorate(
			array(
				'type' => 'text',
				'text' => $text,
			),
			$annotations,
			$_meta
		);
	}

	/**
	 * Creates a text content block with JSON-encoded data.
	 *
	 * Convenience method for creating text content from structured data.
	 * The data is encoded as JSON and wrapped in a text content block.
	 *
	 * @param mixed $data The data to JSON-encode.
	 * @param int $flags JSON encoding flags (default: 0).
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata for the content block.
	 *
	 * @return array<string, mixed> The created text content block.
	 */
	public static function json_text( $data, int $flags = 0, ?array $annotations = null, ?array $_meta = null ): array {
		$json = wp_json_encode( $data, $flags );
		if ( false === $json ) {
			$json = '{}';
		}

		return self::text( $json, $annotations, $_meta );
	}

	/**
	 * Returns a list of content blocks in their array representations.
	 *
	 * The factories above already return arrays, so this is a pass-through. It is
	 * kept because call sites and third-party code use it at the serialization
	 * boundary when preparing content blocks for JSON output.
	 *
	 * @param array<int, array<string, mixed>> $blocks Array of content blocks.
	 *
	 * @return array<int, array<string, mixed>> Array of content block arrays.
	 */
	public static function to_array_list( array $blocks ): array {
		return $blocks;
	}

	/**
	 * Adds the optional annotations and _meta fields to a content block.
	 *
	 * Both fields are omitted when absent rather than emitted as null. The
	 * protocol types reject a null in a field that is typed, so a key that has
	 * no value must not be present at all.
	 *
	 * @param array<string, mixed>      $block       The block being built.
	 * @param array<string, mixed>|null $annotations Optional annotations for the client.
	 * @param array<string, mixed>|null $_meta       Optional metadata for the block.
	 *
	 * @return array<string, mixed> The block with its optional fields applied.
	 */
	private static function decorate( array $block, ?array $annotations, ?array $_meta ): array {
		if ( null !== $annotations && ! empty( $annotations ) ) {
			$clean = McpAnnotationMapper::sanitize( $annotations, 'resource', (string) ( $block['type'] ?? '' ) );
			if ( ! empty( $clean ) ) {
				$block['annotations'] = $clean;
			}
		}

		$meta = McpValidator::normalize_meta( $_meta );
		if ( null !== $meta ) {
			$block['_meta'] = $meta;
		}

		return $block;
	}
}
