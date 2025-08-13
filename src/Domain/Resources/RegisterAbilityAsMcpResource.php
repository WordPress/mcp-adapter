<?php
/**
 * RegisterAbilityAsMcpResource class for converting WordPress abilities to MCP resources.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use InvalidArgumentException;
use WP\MCP\Core\McpServer;
use WP_Ability;

/**
 * Converts WordPress abilities to MCP resources according to the specification.
 *
 * This class extracts resource URI and other properties from ability metadata.
 * The ability meta must contain a 'uri' field with the resource URI.
 *
 * Example ability meta structure:
 * array(
 *     'uri' => 'WordPress://mcp-adapter/my-resource',
 *     'mimeType' => 'text/plain',
 *     'annotations' => array(...)
 * )
 */
class RegisterAbilityAsMcpResource {

	/**
	 * The ability name.
	 *
	 * @var string
	 */
	private string $ability_name;

	/**
	 * The MCP server.
	 *
	 * @var McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * The WordPress ability instance.
	 *
	 * @var WP_Ability|null
	 */
	private ?WP_Ability $ability;

	/**
	 * Make a new instance of the class.
	 *
	 * @param string    $ability_name The ability name.
	 * @param McpServer $mcp_server The MCP server.
	 *
	 * @return McpResource Returns resource instance if valid
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public static function make( string $ability_name, McpServer $mcp_server ): McpResource {
		$resource = new self( $ability_name, $mcp_server );

		return $resource->get_resource();
	}

	/**
	 * Constructor.
	 *
	 * @param string    $ability_name The ability name.
	 * @param McpServer $mcp_server The MCP server.
	 */
	public function __construct( string $ability_name, McpServer $mcp_server ) {
		$this->ability_name = $ability_name;
		$this->mcp_server   = $mcp_server;
		$this->ability      = wp_get_ability( $ability_name );
	}

	/**
	 * Get the resource URI.
	 *
	 * @return string
	 * @throws InvalidArgumentException If URI is not found in ability meta.
	 */
	public function get_uri(): string {
		if ( ! $this->ability ) {
			throw new InvalidArgumentException( 'WordPress ability does not exist or could not be loaded' );
		}

		$ability_meta = $this->ability->get_meta();

		// First try to get URI from ability meta
		if ( ! empty( $ability_meta['uri'] ) ) {
			return $ability_meta['uri'];
		}

		// If not found in meta, throw an error since URI should be provided in ability meta
		throw new InvalidArgumentException( esc_html( "Resource URI not found in ability meta for '{$this->ability_name}'. URI must be provided in ability meta data." ) );
	}

	/**
	 * Get the MCP resource data array.
	 *
	 * @return array
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public function get_data(): array {
		if ( ! $this->is_ability() ) {
			throw new InvalidArgumentException( 'WordPress ability does not exist or could not be loaded' );
		}

		$resource_data = array(
			'ability' => $this->ability->get_name(),
			'uri'     => $this->get_uri(),
		);

		// Add optional name from ability label
		$label = $this->ability->get_label();
		if ( ! empty( $label ) ) {
			$resource_data['name'] = $label;
		}

		// Add optional description
		$description = $this->ability->get_description();
		if ( ! empty( $description ) ) {
			$resource_data['description'] = $description;
		}

		// Get resource content from ability
		$content = $this->get_ability_content();
		if ( isset( $content['text'] ) ) {
			$resource_data['text'] = $content['text'];
		}
		if ( isset( $content['blob'] ) ) {
			$resource_data['blob'] = $content['blob'];
		}
		if ( isset( $content['mimeType'] ) ) {
			$resource_data['mimeType'] = $content['mimeType'];
		}

		// Get annotations from ability meta
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) ) {
			$resource_data['annotations'] = $ability_meta['annotations'];
		}

		return $resource_data;
	}

	/**
	 * Get resource content from the ability.
	 * This method should be implemented based on how abilities provide resource content.
	 *
	 * @return array Array with 'text', 'blob', and/or 'mimeType' keys
	 */
	private function get_ability_content(): array {
		// @todo: Probably this can be improved so it will not be loaded when the resource list is called
		$content = array();

		// Check if ability has resource content methods
		if ( method_exists( $this->ability, 'get_resource_content' ) ) {
			$resource_content = call_user_func( array( $this->ability, 'get_resource_content' ) );
			if ( is_array( $resource_content ) ) {
				return $resource_content;
			}
		}

		// Fallback: try to get content from ability description as text
		$description = $this->ability->get_description();
		if ( ! empty( $description ) ) {
			$content['text']     = $description;
			$content['mimeType'] = 'text/plain';
		}

		return $content;
	}

	/**
	 * Check if the WordPress ability exists and was successfully loaded.
	 *
	 * @return bool
	 */
	public function is_ability(): bool {
		return $this->ability instanceof WP_Ability;
	}

	/**
	 * Validate the MCP resource data and throw exception if invalid.
	 * Uses the centralized McpResourceValidator for consistent validation.
	 *
	 * @return McpResource Returns the MCP resource instance if valid.
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public function get_resource(): McpResource {
		return McpResource::from_array( $this->get_data(), $this->mcp_server );
	}
}
