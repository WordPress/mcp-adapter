<?php
/**
 * RegisterAbilityAsMcpTool class for converting WordPress abilities to MCP tools.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Domain\Utils\SchemaTransformer;
use WP\McpSchema\Server\Tools\Tool;
use WP_Ability;

/**
 * RegisterAbilityAsMcpTool class.
 *
 * This class registers a WordPress ability as an MCP tool.
 *
 * @package McpAdapter
 */
class RegisterAbilityAsMcpTool {
	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private WP_Ability $ability;

	/**
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool|\WP_Error Returns a Tool DTO or WP_Error if validation fails.
	 */
	public static function make( WP_Ability $ability ) {
		$tool = new self( $ability );

		return $tool->get_tool();
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function __construct( WP_Ability $ability ) {
		$this->ability = $ability;
	}

	/**
	 * Get the MCP tool data array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_data(): array {
		// Transform input schema to MCP-compatible object format
		$input_transform = SchemaTransformer::transform_to_object_schema(
			$this->ability->get_input_schema()
		);

		$tool_data = array(
			'name'        => str_replace( '/', '-', trim( $this->ability->get_name() ) ),
			'description' => trim( $this->ability->get_description() ),
			'inputSchema' => $input_transform['schema'],
		);

		// Add optional title from ability label.
		$label = $this->ability->get_label();
		$label = trim( $label );
		if ( ! empty( $label ) ) {
			$tool_data['title'] = $label;
		}

		// Add optional output schema, transformed to object format if needed.
		$output_schema    = $this->ability->get_output_schema();
		$output_transform = null;
		if ( ! empty( $output_schema ) ) {
			$output_transform          = SchemaTransformer::transform_to_object_schema(
				$output_schema,
				'result'
			);
			$tool_data['outputSchema'] = $output_transform['schema'];
		}

		// Map annotations from ability meta to MCP format using unified mapper.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
			$mcp_annotations = McpAnnotationMapper::map( $ability_meta['annotations'], 'tool' );
			if ( ! empty( $mcp_annotations ) ) {
				$tool_data['annotations'] = $mcp_annotations;
			}
		}

		// Set annotations.title from label if annotations exist but don't have a title.
		if ( ! empty( $label ) && isset( $tool_data['annotations'] ) && ! isset( $tool_data['annotations']['title'] ) ) {
			$tool_data['annotations']['title'] = $label;
		}

		// Store transformation metadata as internal metadata (stripped before responding to clients).
		$tool_data['_meta'] = array(
			'mcp_adapter' => array(
				'ability'                   => $this->ability->get_name(),
				'input_schema_transformed'  => (bool) ( $input_transform['was_transformed'] ?? false ),
				'input_schema_wrapper'      => $input_transform['wrapper_property'] ?? 'input',
				'output_schema_transformed' => (bool) ( $output_transform['was_transformed'] ?? false ),
				'output_schema_wrapper'     => $output_transform['wrapper_property'] ?? 'result',
			),
		);

		// If no transformations happened, avoid storing unnecessary metadata.
		if ( ! $tool_data['_meta']['mcp_adapter']['input_schema_transformed'] && ! $tool_data['_meta']['mcp_adapter']['output_schema_transformed'] ) {
			unset( $tool_data['_meta']['mcp_adapter']['input_schema_transformed'] );
			unset( $tool_data['_meta']['mcp_adapter']['input_schema_wrapper'] );
			unset( $tool_data['_meta']['mcp_adapter']['output_schema_transformed'] );
			unset( $tool_data['_meta']['mcp_adapter']['output_schema_wrapper'] );
		}

		// Prepare schema arrays for php-mcp-schema DTO expectations (properties map values must be objects).
		$tool_data['inputSchema'] = $this->prepare_schema_for_dto( $tool_data['inputSchema'] );
		if ( isset( $tool_data['outputSchema'] ) && is_array( $tool_data['outputSchema'] ) ) {
			$tool_data['outputSchema'] = $this->prepare_schema_for_dto( $tool_data['outputSchema'] );
		}

		return $tool_data;
	}

	/**
	 * Get the MCP Tool DTO instance.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool|\WP_Error The Tool DTO instance or WP_Error if validation fails.
	 */
	private function get_tool() {
		try {
			return Tool::fromArray( $this->get_data() );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'mcp_tool_schema_invalid',
				$e->getMessage()
			);
		}
	}

	/**
	 * Prepare a JSON Schema array for DTO conversion.
	 *
	 * The php-mcp-schema library expects JSON Schema `properties` values to be objects, not arrays.
	 * This method recursively converts nested schema arrays into stdClass objects where needed.
	 *
	 * @param array $schema The JSON schema array.
	 *
	 * @return array The schema with properties values converted to objects.
	 */
	private function prepare_schema_for_dto( array $schema ): array {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$converted_properties = array();
			foreach ( $schema['properties'] as $prop_name => $prop_schema ) {
				if ( is_array( $prop_schema ) ) {
					$prop_schema = $this->prepare_schema_for_dto( $prop_schema );
				}
				$converted_properties[ $prop_name ] = (object) $prop_schema;
			}
			$schema['properties'] = $converted_properties;
		}

		return $schema;
	}
}
