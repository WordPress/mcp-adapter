<?php
/**
 * ContentBlockHelper - Factory for creating MCP content block DTOs.
 *
 * This helper provides convenience methods for constructing typed content block DTOs
 * from the php-mcp-schema library. It simplifies the creation of TextContent,
 * ImageContent, AudioContent, and EmbeddedResource instances.
 *
 * @package WP\MCP\Domain\Utils
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use WP\McpSchema\Common\Content\DTO\AudioContent;
use WP\McpSchema\Common\Content\DTO\ImageContent;
use WP\McpSchema\Common\Content\DTO\TextContent;
use WP\McpSchema\Common\Protocol\DTO\Annotations;
use WP\McpSchema\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\Common\Protocol\DTO\EmbeddedResource;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Common\Protocol\Union\ContentBlockInterface;

/**
 * Helper class for creating MCP content block DTOs.
 *
 * Provides static factory methods to create typed content blocks that implement
 * ContentBlockInterface. These DTOs are used in tool call results, prompt messages,
 * and resource contents throughout the MCP protocol.
 *
 * @since n.e.x.t
 */
final class ContentBlockHelper {

	/**
	 * Creates an ImageContent DTO.
	 *
	 * @param string $data Base64-encoded image data.
	 * @param string $mime_type The MIME type of the image (e.g., 'image/png').
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Content\DTO\ImageContent The created ImageContent DTO.
	 */
	public static function image( string $data, string $mime_type, ?Annotations $annotations = null, ?array $_meta = null ): ImageContent {
		return ImageContent::fromArray(
			array(
				'type'        => ImageContent::TYPE,
				'data'        => $data,
				'mimeType'    => $mime_type,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Creates an AudioContent DTO.
	 *
	 * @param string $data Base64-encoded audio data.
	 * @param string $mime_type The MIME type of the audio (e.g., 'audio/mp3').
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Content\DTO\AudioContent The created AudioContent DTO.
	 */
	public static function audio( string $data, string $mime_type, ?Annotations $annotations = null, ?array $_meta = null ): AudioContent {
		return AudioContent::fromArray(
			array(
				'type'        => AudioContent::TYPE,
				'data'        => $data,
				'mimeType'    => $mime_type,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Creates an EmbeddedResource DTO with TextResourceContents.
	 *
	 * Use this for embedding text-based resources (files, documents, etc.) in content.
	 *
	 * @param string $uri The URI of the resource.
	 * @param string $text The text content of the resource.
	 * @param string|null $mime_type Optional MIME type of the resource.
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Protocol\DTO\EmbeddedResource The created EmbeddedResource DTO.
	 */
	public static function embedded_text_resource(
		string $uri,
		string $text,
		?string $mime_type = null,
		?Annotations $annotations = null,
		?array $_meta = null
	): EmbeddedResource {
		$resource = TextResourceContents::fromArray(
			array(
				'uri'      => $uri,
				'text'     => $text,
				'mimeType' => $mime_type,
			)
		);

		return EmbeddedResource::fromArray(
			array(
				'type'        => EmbeddedResource::TYPE,
				'resource'    => $resource,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Creates an EmbeddedResource DTO with BlobResourceContents.
	 *
	 * Use this for embedding binary resources (images, PDFs, etc.) in content.
	 *
	 * @param string $uri The URI of the resource.
	 * @param string $blob Base64-encoded binary data.
	 * @param string|null $mime_type Optional MIME type of the resource.
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Protocol\DTO\EmbeddedResource The created EmbeddedResource DTO.
	 */
	public static function embedded_blob_resource(
		string $uri,
		string $blob,
		?string $mime_type = null,
		?Annotations $annotations = null,
		?array $_meta = null
	): EmbeddedResource {
		$resource = BlobResourceContents::fromArray(
			array(
				'uri'      => $uri,
				'blob'     => $blob,
				'mimeType' => $mime_type,
			)
		);

		return EmbeddedResource::fromArray(
			array(
				'type'        => EmbeddedResource::TYPE,
				'resource'    => $resource,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Creates a TextContent DTO for error messages.
	 *
	 * Convenience method for creating text content specifically for error responses.
	 * This is semantically equivalent to text() but makes the intent clearer in code.
	 *
	 * @param string $message The error message.
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Content\DTO\TextContent The created TextContent DTO.
	 */
	public static function error_text( string $message, ?Annotations $annotations = null, ?array $_meta = null ): TextContent {
		return self::text( $message, $annotations, $_meta );
	}

	/**
	 * Creates a TextContent DTO.
	 *
	 * @param string $text The text content.
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Content\DTO\TextContent The created TextContent DTO.
	 */
	public static function text( string $text, ?Annotations $annotations = null, ?array $_meta = null ): TextContent {
		return TextContent::fromArray(
			array(
				'type'        => TextContent::TYPE,
				'text'        => $text,
				'annotations' => $annotations,
				'_meta'       => $_meta,
			)
		);
	}

	/**
	 * Creates a TextContent DTO with JSON-encoded data.
	 *
	 * Convenience method for creating text content from structured data.
	 * The data is encoded as JSON and wrapped in a TextContent DTO.
	 *
	 * @param mixed $data The data to JSON-encode.
	 * @param int $flags JSON encoding flags (default: 0).
	 * @param \WP\McpSchema\Common\Protocol\DTO\Annotations|null $annotations Optional annotations for the client.
	 * @param array|null $_meta Optional metadata.
	 *
	 * @return \WP\McpSchema\Common\Content\DTO\TextContent The created TextContent DTO.
	 */
	public static function json_text( $data, int $flags = 0, ?Annotations $annotations = null, ?array $_meta = null ): TextContent {
		$json = wp_json_encode( $data, $flags );
		if ( false === $json ) {
			$json = '{}';
		}

		return self::text( $json, $annotations, $_meta );
	}

	/**
	 * Converts an array of ContentBlockInterface DTOs to their array representations.
	 *
	 * Use this at the serialization boundary when preparing content blocks for JSON output.
	 *
	 * @param \WP\McpSchema\Common\Protocol\Union\ContentBlockInterface[] $blocks Array of content block DTOs.
	 *
	 * @return array[] Array of content block arrays.
	 */
	public static function to_array_list( array $blocks ): array {
		return array_map(
			static function ( ContentBlockInterface $block ): array {
				return $block->toArray();
			},
			$blocks
		);
	}
}
