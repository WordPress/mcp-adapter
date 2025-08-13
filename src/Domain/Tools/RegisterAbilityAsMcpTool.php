<?php
/**
 * RegisterAbilityAsMcpTool class for converting WordPress abilities to MCP tools.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use InvalidArgumentException;
use WP\MCP\Core\McpServer;
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
	 * The ability name.
	 *
	 * @var string
	 */
	private string $ability_name;

	/**
	 * The WordPress ability instance.
	 *
	 * @var WP_Ability|null
	 */
	private ?WP_Ability $ability;

	/**
	 * The MCP server.
	 *
	 * @var McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Make a new instance of the class.
	 *
	 * @param string    $ability_name The ability name.
	 * @param McpServer $mcp_server The MCP server.
	 *
	 * @return McpTool returns a new instance of McpTool.
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public static function make( string $ability_name, McpServer $mcp_server ): McpTool {
		$tool = new self( $ability_name, $mcp_server );

		return $tool->get_tool();
	}

	/**
	 * Constructor.
	 *
	 * @param string    $ability_name The ability name.
	 * @param McpServer $mcp_server The MCP server instance.
	 */
	public function __construct( string $ability_name, McpServer $mcp_server ) {
		$this->ability_name = $ability_name;
		$this->mcp_server   = $mcp_server;
		$this->ability      = wp_get_ability( $ability_name );
	}

	/**
	 * Get the MCP tool data array.
	 *
	 * @return array
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public function get_data(): array {
		if ( ! $this->is_ability() ) {
			throw new InvalidArgumentException( 'WordPress ability does not exist or could not be loaded' );
		}

		$tool_data = array(
			'ability'     => $this->ability->get_name(),
			'name'        => $this->get_name(),
			'description' => $this->ability->get_description(),
			'inputSchema' => $this->ability->get_input_schema(),
		);

		// Add optional title from ability label.
		$label = $this->ability->get_label();
		if ( ! empty( $label ) ) {
			$tool_data['title'] = $label;
		}

		// Add optional output schema.
		$output_schema = $this->ability->get_output_schema();
		if ( ! empty( $output_schema ) ) {
			$tool_data['outputSchema'] = $output_schema;
		}

		// get annotations from ability meta.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) ) {
			$tool_data['annotations'] = $ability_meta['annotations'];
		}

		return $tool_data;
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	private function get_name(): string {
		return str_replace( '/', '-', $this->ability_name );
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
	 * Get the MCP tool instance.
	 *
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 * @return McpTool The validated MCP tool instance.
	 */
	public function get_tool(): McpTool {
		return McpTool::from_array( $this->get_data(), $this->mcp_server );
	}
}
