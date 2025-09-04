<?php
/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Core\McpServer;
use WP_Ability;

/**
 * Converts WordPress abilities to MCP prompts according to the specification.
 *
 * This class extracts prompt data and arguments from ability metadata.
 * The ability meta can contain prompt-specific information like arguments.
 *
 * Example ability meta structure:
 * array(
 *     'arguments' => array(
 *         array('name' => 'code', 'description' => 'Code to review', 'required' => true)
 *     ),
 *     'annotations' => array(...)
 * )
 */
class RegisterAbilityAsMcpPrompt {
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
	 * @return \WP\MCP\Domain\Prompts\McpPrompt Returns prompt instance if valid
	 * @throws \InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public static function make( WP_Ability $ability, McpServer $mcp_server ): McpPrompt {
		$prompt = new self( $ability, $mcp_server );

		return $prompt->get_prompt();
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
	 * Get the MCP prompt data array.
	 *
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	private function get_data(): array {
		$prompt_data = array(
			'ability' => $this->ability->get_name(),
			'name'    => str_replace( '/', '-', $this->ability->get_name() ),
		);

		// Add optional title from ability label
		$label = $this->ability->get_label();
		if ( ! empty( $label ) ) {
			$prompt_data['title'] = $label;
		}

		// Add optional description
		$description = $this->ability->get_description();
		if ( ! empty( $description ) ) {
			$prompt_data['description'] = $description;
		}

		// Get arguments from ability meta
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['arguments'] ) && is_array( $ability_meta['arguments'] ) ) {
			$prompt_data['arguments'] = $ability_meta['arguments'];
		}

		return $prompt_data;
	}

	/**
	 * Get the MCP prompt instance.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt MCP prompt instance.
	 * @throws \InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	private function get_prompt(): McpPrompt {
		return McpPrompt::from_array( $this->get_data(), $this->mcp_server );
	}
}
