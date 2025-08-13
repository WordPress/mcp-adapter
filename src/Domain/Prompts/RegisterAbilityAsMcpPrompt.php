<?php
/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

namespace WP\MCP\Domain\Prompts;

use InvalidArgumentException;
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
	 * @return McpPrompt Returns prompt instance if valid
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public static function make( string $ability_name, McpServer $mcp_server ): McpPrompt {
		$prompt = new self( $ability_name, $mcp_server );

		return $prompt->get_prompt();
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
	 * Get the prompt name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->transform_ability_name( $this->ability_name );
	}

	/**
	 * Get the MCP prompt data array.
	 *
	 * @return array
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public function get_data(): array {
		if ( ! $this->ability ) {
			throw new InvalidArgumentException( 'WordPress ability does not exist or could not be loaded' );
		}

		$prompt_data = array(
			'ability' => $this->ability->get_name(),
			'name'    => $this->get_name(),
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
	 * Transform ability name to MCP prompt name format.
	 * Converts slashes to dashes for MCP compatibility.
	 *
	 * @param string $ability_name The ability name.
	 *
	 * @return string
	 */
	private function transform_ability_name( string $ability_name ): string {
		return str_replace( '/', '-', $ability_name );
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
	 * Validate the MCP prompt data and throw exception if invalid.
	 * Uses the centralized McpPromptValidator for consistent validation.
	 *
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 * @return void
	 */
	public function validate_mcp_prompt(): void {
		if ( ! $this->is_ability() ) {
			throw new InvalidArgumentException( 'WordPress ability does not exist or could not be loaded' );
		}

		$prompt_data = $this->get_data();
		McpPromptValidator::validate_prompt_data( $prompt_data, "RegisterAbilityAsMcpPrompt::{$this->ability_name}" );
	}

	/**
	 * Get validation errors for debugging purposes.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public function get_validation_errors(): array {
		if ( ! $this->is_ability() ) {
			return array( 'WordPress ability does not exist or could not be loaded' );
		}

		$prompt_data = $this->get_data();

		return McpPromptValidator::get_validation_errors( $prompt_data );
	}

	/**
	 * Get the MCP prompt instance.
	 *
	 * @return McpPrompt MCP prompt instance.
	 * @throws InvalidArgumentException If WordPress ability doesn't exist or validation fails.
	 */
	public function get_prompt(): McpPrompt {
		return McpPrompt::from_array( $this->get_data(), $this->mcp_server );
	}
}
