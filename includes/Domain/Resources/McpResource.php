<?php
/**
 * WordPress MCP Resource class for representing MCP resources according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\MCP\Core\McpServer;
use WP_Ability;

/**
 * Represents an MCP resource according to the Model Context Protocol specification.
 *
 * Resources enable models to access external data sources, such as files, databases,
 * or web APIs. Each resource is uniquely identified by a URI and includes metadata
 * describing its content type and structure.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/server/resources
 */
class McpResource {

	/**
	 * The ability name.
	 *
	 * @var string
	 */
	private string $ability;

	/**
	 * Unique identifier for the resource.
	 *
	 * @var string
	 */
	private string $uri;

	/**
	 * Optional human-readable name of the resource for display purposes.
	 *
	 * @var string|null
	 */
	private ?string $name;

	/**
	 * Optional human-readable description of the resource.
	 *
	 * @var string|null
	 */
	private ?string $description;

	/**
	 * Optional MIME type of the resource content.
	 *
	 * @var string|null
	 */
	private ?string $mime_type;

	/**
	 * Text content of the resource (for TextResourceContents).
	 *
	 * @var string|null
	 */
	private ?string $text;

	/**
	 * Base64-encoded binary content of the resource (for BlobResourceContents).
	 *
	 * @var string|null
	 */
	private ?string $blob;

	/**
	 * Optional properties describing resource metadata.
	 *
	 * @var array
	 */
	private array $annotations;

	/**
	 * The MCP server instance this resource belongs to.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Constructor for McpResource.
	 *
	 * @param string      $ability The ability name.
	 * @param string      $uri Unique URI identifier for the resource.
	 * @param string|null $name Optional human-readable name for display.
	 * @param string|null $description Optional human-readable description.
	 * @param string|null $mime_type Optional MIME type of the content.
	 * @param string|null $text Optional text content (mutually exclusive with blob).
	 * @param string|null $blob Optional base64-encoded binary content (mutually exclusive with text).
	 * @param array       $annotations Optional properties describing resource metadata.
	 */
	public function __construct(
		string $ability,
		string $uri,
		?string $name = null,
		?string $description = null,
		?string $mime_type = null,
		?string $text = null,
		?string $blob = null,
		array $annotations = array()
	) {
		$this->ability     = $ability;
		$this->uri         = $uri;
		$this->name        = $name;
		$this->description = $description;
		$this->mime_type   = $mime_type;
		$this->text        = $text;
		$this->blob        = $blob;
		$this->annotations = $annotations;
	}

	/**
	 * Get the resource URI.
	 *
	 * @return string
	 */
	public function get_uri(): string {
		return $this->uri;
	}

	/**
	 * Get the resource name.
	 *
	 * @return string|null
	 */
	public function get_name(): ?string {
		return $this->name;
	}

	/**
	 * Get the resource description.
	 *
	 * @return string|null
	 */
	public function get_description(): ?string {
		return $this->description;
	}

	/**
	 * Get the MIME type.
	 *
	 * @return string|null
	 */
	public function get_mime_type(): ?string {
		return $this->mime_type;
	}

	/**
	 * Get the text content.
	 *
	 * @return string|null
	 */
	public function get_text(): ?string {
		return $this->text;
	}

	/**
	 * Get the blob content.
	 *
	 * @return string|null
	 */
	public function get_blob(): ?string {
		return $this->blob;
	}

	/**
	 * Get the annotations.
	 *
	 * @return array
	 */
	public function get_annotations(): array {
		return $this->annotations;
	}

	/**
	 * Get the ability name.
	 *
	 * @return \WP_Ability|null
	 */
	public function get_ability(): ?WP_Ability {
		return wp_get_ability( $this->ability );
	}

	/**
	 * Set the resource name.
	 *
	 * @param string|null $name The name to set.
	 *
	 * @return void
	 */
	public function set_name( ?string $name ): void {
		$this->name = $name;
	}

	/**
	 * Set the resource description.
	 *
	 * @param string|null $description The description to set.
	 *
	 * @return void
	 */
	public function set_description( ?string $description ): void {
		$this->description = $description;
	}

	/**
	 * Set the MIME type.
	 *
	 * @param string|null $mime_type The MIME type to set.
	 *
	 * @return void
	 */
	public function set_mime_type( ?string $mime_type ): void {
		$this->mime_type = $mime_type;
	}

	/**
	 * Set the text content.
	 *
	 * @param string|null $text The text content to set.
	 *
	 * @return void
	 */
	public function set_text( ?string $text ): void {
		$this->text = $text;
		// Clear blob content if setting text.
		if ( is_null( $text ) ) {
			return;
		}

		$this->blob = null;
	}

	/**
	 * Set the blob content.
	 *
	 * @param string|null $blob The base64-encoded blob content to set.
	 *
	 * @return void
	 */
	public function set_blob( ?string $blob ): void {
		$this->blob = $blob;
		// Clear text content if setting blob.
		if ( is_null( $blob ) ) {
			return;
		}

		$this->text = null;
	}

	/**
	 * Set the annotations.
	 *
	 * @param array $annotations The annotations to set.
	 *
	 * @return void
	 */
	public function set_annotations( array $annotations ): void {
		$this->annotations = $annotations;
	}

	/**
	 * Add an annotation.
	 *
	 * @param string $key The annotation key.
	 * @param mixed  $value The annotation value.
	 *
	 * @return void
	 */
	public function add_annotation( string $key, $value ): void {
		$this->annotations[ $key ] = $value;
	}

	/**
	 * Remove an annotation.
	 *
	 * @param string $key The annotation key to remove.
	 *
	 * @return void
	 */
	public function remove_annotation( string $key ): void {
		unset( $this->annotations[ $key ] );
	}

	/**
	 * Get the MCP server instance this resource belongs to.
	 *
	 * @return \WP\MCP\Core\McpServer
	 */
	public function get_mcp_server(): McpServer {
		return $this->mcp_server;
	}

	/**
	 * Convert the resource to an array representation according to MCP specification.
	 *
	 * @return array
	 */
	public function to_array(): array {
		$resource_data = array(
			'uri' => $this->uri,
		);

		// Add optional fields only if they have values.
		if ( ! is_null( $this->name ) ) {
			$resource_data['name'] = $this->name;
		}

		if ( ! is_null( $this->description ) ) {
			$resource_data['description'] = $this->description;
		}

		if ( ! is_null( $this->mime_type ) ) {
			$resource_data['mimeType'] = $this->mime_type;
		}

		if ( ! is_null( $this->text ) ) {
			$resource_data['text'] = $this->text;
		}

		if ( ! is_null( $this->blob ) ) {
			$resource_data['blob'] = $this->blob;
		}

		if ( ! empty( $this->annotations ) ) {
			$resource_data['annotations'] = $this->annotations;
		}

		return $resource_data;
	}

	/**
	 * Convert the resource to JSON representation.
	 *
	 * @return string
	 */
	public function to_json(): string {
		return wp_json_encode( $this->to_array() );
	}

	/**
	 * Create an McpResource instance from an array.
	 *
	 * @param array     $data Array containing resource data.
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server instance.
	 *
	 * @return self
	 * @throws \InvalidArgumentException If required fields are missing or validation fails.
	 */
	public static function from_array( array $data, McpServer $mcp_server ): self {
		$resource = new self(
			$data['ability'] ?? '',
			$data['uri'] ?? '',
			$data['name'] ?? null,
			$data['description'] ?? null,
			$data['mimeType'] ?? null,
			$data['text'] ?? null,
			$data['blob'] ?? null,
			$data['annotations'] ?? array()
		);

		$resource->mcp_server = $mcp_server;

		return $resource->validate( "McpResource::from_array::{$data['uri']}" );
	}

	/**
	 * Check if this is a text resource.
	 *
	 * @return bool
	 */
	public function is_text_resource(): bool {
		return ! is_null( $this->text ) && is_null( $this->blob );
	}

	/**
	 * Check if this is a blob resource.
	 *
	 * @return bool
	 */
	public function is_blob_resource(): bool {
		return ! is_null( $this->blob ) && is_null( $this->text );
	}

	/**
	 * Validate the resource data.
	 *
	 * @param string $context Optional context for error messages.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate( string $context = '' ): self {
		if ( ! $this->mcp_server->is_mcp_validation_enabled() ) {
			return $this;
		}

		$context_to_use = $context ?: "McpResource::{$this->name}";

		McpResourceValidator::validate_resource_instance( $this, $context_to_use );

		return $this;
	}
}
