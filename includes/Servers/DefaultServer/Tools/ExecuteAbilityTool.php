<?php
/**
 * System tool for executing WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Servers\DefaultServer\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\Contracts\McpSystemToolInterface;
use WP\MCP\Domain\Tools\McpTool;

/**
 * Execute Ability Tool - Executes a WordPress ability with provided parameters.
 *
 * This is a system tool (not backed by an ability) that provides the primary
 * execution layer for running any registered WordPress ability.
 */
class ExecuteAbilityTool implements McpSystemToolInterface {

	/**
	 * Build the MCP tool instance.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool The constructed MCP tool.
	 */
	public function build( McpServer $server ): McpTool {
		return new McpTool(
			'', // No ability backing - pure system tool
			'execute_ability',
			'Execute a WordPress ability with the provided parameters. This is the primary execution layer that can run any registered ability.',
			$this->get_input_schema(),
			'Execute Ability',
			$this->get_output_schema(),
			array()
		);
	}

	/**
	 * Get the input schema for the tool.
	 *
	 * @return array JSON Schema for input parameters.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'ability_name' => array(
					'type'        => 'string',
					'description' => 'The full name of the ability to execute',
				),
				'parameters'   => array(
					'type'        => 'object',
					'description' => 'Parameters to pass to the ability',
				),
			),
			'required'             => array( 'ability_name', 'parameters' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get the output schema for the tool.
	 *
	 * @return array JSON Schema for output structure.
	 */
	private function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array(
					'description' => 'The result data from the ability execution',
				),
				'error'   => array(
					'type'        => 'string',
					'description' => 'Error message if execution failed',
				),
			),
			'required'   => array( 'success' ),
		);
	}

	/**
	 * Execute the ability execution functionality.
	 *
	 * @param array           $args   Tool execution arguments.
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return array Array containing execution results.
	 */
	public function execute( array $args, McpServer $server ): array {
		$ability_name = $args['ability_name'] ?? '';
		$parameters   = $args['parameters'] ?? array();

		if ( empty( $ability_name ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid params: ability_name is required',
			);
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return array(
				'success' => false,
				'error'   => "Ability '{$ability_name}' not found",
			);
		}

		// Check permissions
		try {
			$has_permission = $ability->has_permission( $parameters );
			if ( true !== $has_permission ) {
				// Track permission denied event
				$server->observability_handler::record_event(
					'mcp.ability.permission_denied',
					array(
						'ability_name' => $ability_name,
						'server_id'    => $server->get_server_id(),
					)
				);

				return array(
					'success' => false,
					'error'   => "Access denied for ability '{$ability_name}'",
				);
			}
		} catch ( \Throwable $e ) {
			$server->error_handler->log(
				'Error running ability permission callback',
				array(
					'ability'   => $ability_name,
					'exception' => $e->getMessage(),
				)
			);

			// Track permission check error event
			$server->observability_handler::record_event(
				'mcp.ability.permission_check_failed',
				array(
					'ability_name' => $ability_name,
					'error_type'   => get_class( $e ),
					'server_id'    => $server->get_server_id(),
				)
			);

			return array(
				'success' => false,
				'error'   => 'Error running ability permission callback',
			);
		}

		// Execute the ability
		try {
			$result = $ability->execute( $parameters );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			}

			// Track successful ability execution
			$server->observability_handler::record_event(
				'mcp.ability.execution_success',
				array(
					'ability_name' => $ability_name,
					'server_id'    => $server->get_server_id(),
				)
			);

			return array(
				'success' => true,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			// Log detailed error server-side for debugging
			$server->error_handler->log(
				'Ability execution failed',
				array(
					'ability_name' => $ability_name,
					'parameters'   => $parameters,
					'error'        => $e->getMessage(),
					'file'         => $e->getFile(),
					'line'         => $e->getLine(),
				)
			);

			// Track ability execution error event
			$server->observability_handler::record_event(
				'mcp.ability.execution_failed',
				array(
					'ability_name'   => $ability_name,
					'error_type'     => get_class( $e ),
					'error_category' => $this->categorize_error( $e ),
					'server_id'      => $server->get_server_id(),
				)
			);

			// Return generic error to client (don't leak internal details)
			return array(
				'success' => false,
				'error'   => 'Ability execution failed',
			);
		}
	}

	/**
	 * Categorize an exception into a general error category.
	 *
	 * @param \Throwable $exception The exception to categorize.
	 *
	 * @return string Error category.
	 */
	private function categorize_error( \Throwable $exception ): string {
		$error_categories = array(
			\ArgumentCountError::class       => 'arguments',
			\Error::class                    => 'system',
			\InvalidArgumentException::class => 'validation',
			\LogicException::class           => 'logic',
			\RuntimeException::class         => 'execution',
			\TypeError::class                => 'type',
		);

		return $error_categories[ get_class( $exception ) ] ?? 'unknown';
	}
}
