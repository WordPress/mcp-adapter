<?php
/**
 * WordPress MCP Tool class for representing MCP tools according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Core\McpServer;

/**
 * Represents an MCP tool according to the Model Context Protocol specification.
 *
 * Tools enable models to interact with external systems, such as querying databases,
 * calling APIs, or performing computations. Each tool is uniquely identified by a name
 * and includes metadata describing its schema.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/server/tools
 */
class McpTool {

	/**
	 * Ability name for the tool, used for registration.
	 *
	 * @var string
	 */
	private string $ability;

	/**
	 * Unique identifier for the tool.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Optional human-readable name of the tool for display purposes.
	 *
	 * @var string|null
	 */
	private ?string $title;

	/**
	 * Human-readable description of functionality.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * JSON Schema defining expected parameters.
	 *
	 * @var array
	 */
	private array $input_schema;

	/**
	 * Optional JSON Schema defining expected output structure.
	 *
	 * @var array|null
	 */
	private ?array $output_schema;

	/**
	 * Optional properties describing tool behavior.
	 *
	 * @var array
	 */
	private array $annotations;

	/**
	 * Internal metadata used by the server (not exposed to MCP clients).
	 *
	 * @var array
	 */
	private array $metadata;

	/**
	 * The MCP server instance this tool belongs to.
	 *
	 * @var \WP\MCP\Core\McpServer|null
	 */
	private ?McpServer $mcp_server = null;

	/**
	 * Constructor for McpTool.
	 *
	 * @param string      $ability The ability name.
	 * @param string      $name Unique identifier for the tool.
	 * @param string      $description Human-readable description of functionality.
	 * @param array       $input_schema JSON Schema defining expected parameters.
	 * @param string|null $title Optional human-readable name for display.
	 * @param array|null  $output_schema Optional JSON Schema for output structure.
	 * @param array       $annotations Optional properties describing tool behavior.
	 * @param array       $metadata Internal metadata used by the server (not returned to clients).
	 */
	public function __construct(
		string $ability,
		string $name,
		string $description,
		array $input_schema,
		?string $title = null,
		?array $output_schema = null,
		array $annotations = array(),
		array $metadata = array()
	) {
		$this->ability       = $ability;
		$this->name          = $name;
		$this->title         = $title;
		$this->description   = $description;
		$this->input_schema  = $input_schema;
		$this->output_schema = $output_schema;
		$this->annotations   = $annotations;
		$this->metadata      = $metadata;
	}

	/**
	 * Get the ability name.
	 *
	 * @return \WP_Ability|\WP_Error WP_Ability instance on success, WP_Error on failure.
	 */
	public function get_ability() {
		$ability = wp_get_ability( $this->ability );
		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: ability name */
					esc_html__( "WordPress ability '%s' does not exist.", 'mcp-adapter' ),
					esc_html( $this->ability )
				)
			);
		}
		return $ability;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get the tool title.
	 *
	 * @return string|null
	 */
	public function get_title(): ?string {
		return $this->title;
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Get the input schema.
	 *
	 * @return array
	 */
	public function get_input_schema(): array {
		return $this->input_schema;
	}

	/**
	 * Get the output schema.
	 *
	 * @return array|null
	 */
	public function get_output_schema(): ?array {
		return $this->output_schema;
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
	 * Get internal metadata for server-side processing.
	 *
	 * @return array
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * Set the tool title.
	 *
	 * @param string|null $title The title to set.
	 *
	 * @return void
	 */
	public function set_title( ?string $title ): void {
		$this->title = $title;
	}

	/**
	 * Set the tool description.
	 *
	 * @param string $description The description to set.
	 *
	 * @return void
	 */
	public function set_description( string $description ): void {
		$this->description = $description;
	}

	/**
	 * Set the input schema.
	 *
	 * @param array $input_schema The input schema to set.
	 *
	 * @return void
	 */
	public function set_input_schema( array $input_schema ): void {
		$this->input_schema = $input_schema;
	}

	/**
	 * Set the output schema.
	 *
	 * @param array|null $output_schema The output schema to set.
	 *
	 * @return void
	 */
	public function set_output_schema( ?array $output_schema ): void {
		$this->output_schema = $output_schema;
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
	 * Set internal metadata.
	 *
	 * @param array $metadata Internal metadata values.
	 *
	 * @return void
	 */
	public function set_metadata( array $metadata ): void {
		$this->metadata = $metadata;
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
	 * Get the MCP server instance this tool belongs to.
	 *
	 * @return \WP\MCP\Core\McpServer
	 */
	public function get_mcp_server(): McpServer {
		if ( null === $this->mcp_server ) {
			throw new \RuntimeException( 'MCP server has not been set on this tool instance.' );
		}

		return $this->mcp_server;
	}

	/**
	 * Set the MCP server instance this tool belongs to.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server instance.
	 *
	 * @return void
	 */
	public function set_mcp_server( McpServer $mcp_server ): void {
		$this->mcp_server = $mcp_server;
	}

	/**
	 * Convert the tool to an array representation according to MCP specification.
	 *
	 * @return array
	 */
	public function to_array(): array {
		$input_schema_for_json = empty( $this->input_schema )
			? array( 'type' => 'object' )
			: $this->input_schema;

		$tool_data = array(
			'name'        => $this->name,
			'description' => $this->description,
			'inputSchema' => $input_schema_for_json,
		);

		if ( ! is_null( $this->title ) ) {
			$tool_data['title'] = $this->title;
		}

		if ( ! is_null( $this->output_schema ) ) {
			$tool_data['outputSchema'] = $this->output_schema;
		}

		if ( ! empty( $this->annotations ) ) {
			$tool_data['annotations'] = $this->annotations;
		}

		return $tool_data;
	}

	/**
	 * Create an McpTool instance from an array.
	 *
	 * @param array     $data Array containing tool data.
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server instance.
	 *
	 * @return self|\WP_Error Returns a new McpTool instance or WP_Error if validation fails.
	 */
	public static function from_array( array $data, McpServer $mcp_server ) {
		$tool = new self(
			$data['ability'] ?? '',
			$data['name'] ?? '',
			$data['description'] ?? '',
			$data['inputSchema'] ?? array(),
			$data['title'] ?? null,
			$data['outputSchema'] ?? null,
			$data['annotations'] ?? array(),
			$data['_metadata'] ?? array()
		);
		$tool->set_mcp_server( $mcp_server );

		return $tool->validate( "McpTool::from_array::{$data['name']}" );
	}

	/**
	 * Validate the tool according to MCP specification requirements.
	 * Uses the centralized McpToolValidator for consistent validation.
	 *
	 * @param string $context Optional context for error messages.
	 *
	 * @return self|\WP_Error Returns the validated tool instance or WP_Error if validation fails.
	 */
	public function validate( string $context = '' ) {
		if ( null === $this->mcp_server ) {
			return new \WP_Error(
				'tool_missing_mcp_server',
				esc_html__( 'MCP server must be set before validating a tool.', 'mcp-adapter' )
			);
		}

		if ( ! $this->mcp_server->is_mcp_validation_enabled() ) {
			return $this;
		}

		$context_to_use    = $context ?: "McpTool::{$this->name}";
		$validation_result = McpToolValidator::validate_tool_instance( $this, $context_to_use );

		if ( is_wp_error( $validation_result ) ) {
			return $validation_result;
		}

		return $this;
	}
}
