<?php
/**
 * RegisterAbilityAsMcpResource class for converting WordPress abilities to MCP resources.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

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
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private WP_Ability $ability;

	/**
	 * The MCP server.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability            $ability    The ability.
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource|\WP_Error Returns resource instance or WP_Error if validation fails.
	 */
	public static function make( WP_Ability $ability, McpServer $mcp_server ) {
		$resource = new self( $ability, $mcp_server );

		return $resource->get_resource();
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability            $ability    The ability.
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server.
	 */
	private function __construct( WP_Ability $ability, McpServer $mcp_server ) {
		$this->mcp_server = $mcp_server;
		$this->ability    = $ability;
	}

	/**
	 * Get the resource URI.
	 *
	 * @return string|\WP_Error URI string or WP_Error if not found in ability meta.
	 */
	public function get_uri() {
		$ability_meta = $this->ability->get_meta();

		// First try to get URI from ability meta
		if ( ! empty( $ability_meta['uri'] ) ) {
			return $ability_meta['uri'];
		}

		// If not found in meta, return error since URI should be provided in ability meta
		return new \WP_Error(
			'resource_uri_not_found',
			sprintf(
				"Resource URI not found in ability meta for '%s'. URI must be provided in ability meta data.",
				$this->ability->get_name()
			)
		);
	}

	/**
	 * Map WordPress ability annotations to MCP Annotations format.
	 *
	 * Converts annotation fields according to MCP specification:
	 * - audience: array of Role values (e.g., ["user", "assistant"])
	 * - lastModified: ISO 8601 formatted string
	 * - priority: number (1 = most important, 0 = least important)
	 *
	 * Filters out null values and invalid fields.
	 * Only returns MCP-compliant annotation fields.
	 *
	 * @param array $ability_annotations WordPress ability annotations.
	 *
	 * @return array MCP-compliant Annotations.
	 */
	private function map_annotations_to_mcp( array $ability_annotations ): array {
		$valid_mcp_fields = array(
			'audience'     => 'array',
			'lastModified' => 'string',
			'priority'     => 'number',
		);

		$mcp_annotations = array();

		foreach ( $valid_mcp_fields as $field => $field_type ) {
			if ( ! isset( $ability_annotations[ $field ] ) ) {
				continue;
			}

			$value = $ability_annotations[ $field ];

			// Validate and normalize audience field.
			if ( 'audience' === $field ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				// Filter valid roles and ensure they're strings.
				$valid_roles       = array( 'user', 'assistant' );
				$filtered_audience = array();
				foreach ( $value as $role ) {
					if ( ! is_string( $role ) || ! in_array( $role, $valid_roles, true ) ) {
						continue;
					}
					$filtered_audience[] = $role;
				}
				if ( ! empty( $filtered_audience ) ) {
					$mcp_annotations[ $field ] = $filtered_audience;
				}
				continue;
			}

			// Validate and normalize lastModified field (ISO 8601 string).
			if ( 'lastModified' === $field ) {
				if ( ! is_string( $value ) || empty( trim( $value ) ) ) {
					continue;
				}
				$trimmed_value = trim( $value );
				// Validate ISO 8601 format - filter out invalid dates.
				if ( ! self::is_valid_iso8601_timestamp( $trimmed_value ) ) {
					continue;
				}
				$mcp_annotations[ $field ] = $trimmed_value;
				continue;
			}

			// Validate and normalize priority field (number between 0 and 1).
			// This is the only remaining valid field after audience and lastModified checks.
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$priority = (float) $value;
			// Clamp priority between 0 and 1 per MCP spec.
			$priority                  = max( 0.0, min( 1.0, $priority ) );
			$mcp_annotations[ $field ] = $priority;
		}

		return $mcp_annotations;
	}

	/**
	 * Check if a string is a valid ISO 8601 timestamp.
	 *
	 * @param string $timestamp The timestamp to validate.
	 *
	 * @return bool True if valid ISO 8601 timestamp, false otherwise.
	 */
	private static function is_valid_iso8601_timestamp( string $timestamp ): bool {
		// Try to parse as DateTime with ISO 8601 format.
		$datetime = \DateTime::createFromFormat( \DateTime::ATOM, $timestamp );
		if ( $datetime && $datetime->format( \DateTime::ATOM ) === $timestamp ) {
			return true;
		}

		// Try alternative ISO 8601 formats.
		$formats = array(
			'Y-m-d\TH:i:s\Z',           // UTC format
			'Y-m-d\TH:i:sP',            // With timezone offset
			'Y-m-d\TH:i:s.u\Z',         // With microseconds UTC
			'Y-m-d\TH:i:s.uP',          // With microseconds and timezone
		);

		foreach ( $formats as $format ) {
			$datetime = \DateTime::createFromFormat( $format, $timestamp );
			if ( $datetime && $datetime->format( $format ) === $timestamp ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the MCP resource data array.
	 *
	 * @return array<string,mixed>|\WP_Error Resource data array or WP_Error if URI is not found.
	 */
	private function get_data() {
		$uri = $this->get_uri();
		if ( is_wp_error( $uri ) ) {
			return $uri;
		}

		$resource_data = array(
			'ability' => $this->ability->get_name(),
			'uri'     => $uri,
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

		// Map annotations from ability meta to MCP format.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
			$mcp_annotations = $this->map_annotations_to_mcp( $ability_meta['annotations'] );
			if ( ! empty( $mcp_annotations ) ) {
				$resource_data['annotations'] = $mcp_annotations;
			}
		}

		return $resource_data;
	}

	/**
	 * Get resource content from the ability.
	 * This method should be implemented based on how abilities provide resource content.
	 *
	 * @return array<string,mixed> Array with 'text', 'blob', and/or 'mimeType' keys
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
	 * Get the MCP resource instance.
	 * Uses the centralized McpResourceValidator for consistent validation.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource|\WP_Error Returns the MCP resource instance or WP_Error if validation fails.
	 */
	private function get_resource() {
		$data = $this->get_data();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return McpResource::from_array( $data, $this->mcp_server );
	}
}
