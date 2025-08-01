<?php
/**
 * WordPress MCP Server class for managing server-specific tools, resources, and prompts.
 *
 * @package WpcomMcp
 */

declare( strict_types=1 );

namespace WP\MCP\Registry;

use WP\MCP\Resources\RegisterResource;
use WP\MCP\Prompts\RegisterPrompt;
use WP\MCP\Tools\RegisterTool;
use WP\MCP\Utils\ErrorHandler;
use WP\MCP\Transport\Stdio;
use Exception;

/**
 * WordPress MCP Server - Represents a single MCP server with its tools, resources, and prompts.
 */
class Server {
	/**
	 * Server ID.
	 *
	 * @var string
	 */
	private string $server_id;

	/**
	 * Server URL.
	 *
	 * @var string
	 */
	private string $server_url;

	/**
	 * Server name.
	 *
	 * @var string
	 */
	private string $server_name;

	/**
	 * Server description.
	 *
	 * @var string
	 */
	private string $server_description;

	/**
	 * Tools registered to this server.
	 *
	 * @var array
	 */
	private array $tools = array();

	/**
	 * Resources registered to this server.
	 *
	 * @var array
	 */
	private array $resources = array();

	/**
	 * Prompts registered to this server.
	 *
	 * @var array
	 */
	private array $prompts = array();

	/**
	 * Constructor.
	 *
	 * @param string $server_id Unique identifier for the server.
	 * @param string $server_url Server URL.
	 * @param string $server_name Human-readable server name.
	 * @param string $server_description Server description.
	 * @param array $tools Optional tools to register during construction.
	 * @param array $resources Optional resources to register during construction.
	 * @param array $prompts Optional prompts to register during construction.
	 */
	public function __construct( string $server_id, string $server_url, string $server_name, string $server_description, array $tools = array(), array $resources = array(), array $prompts = array() ) {
		$this->server_id          = $server_id;
		$this->server_url         = $server_url;
		$this->server_name        = $server_name;
		$this->server_description = $server_description;

		// Register tools, resources, and prompts if provided.
		if ( ! empty( $tools ) ) {
			$this->register_tools( $tools );
		}
		if ( ! empty( $resources ) ) {
			$this->register_resources( $resources );
		}
		if ( ! empty( $prompts ) ) {
			$this->register_prompts( $prompts );
		}

		// Initialize the transport.
		new Stdio( $this );
		//new WpcomMcpStreamableTransport( $this );
		//new WpcomMcpInternalTransport( $this );
	}

	/**
	 * Get server ID.
	 *
	 * @return string
	 */
	public function get_server_id(): string {
		return $this->server_id;
	}

	/**
	 * Get server URL.
	 *
	 * @return string
	 */
	public function get_server_url(): string {
		return $this->server_url;
	}

	/**
	 * Get server name.
	 *
	 * @return string
	 */
	public function get_server_name(): string {
		return $this->server_name;
	}

	/**
	 * Get server description.
	 *
	 * @return string
	 */
	public function get_server_description(): string {
		return $this->server_description;
	}

	/**
	 * Register a tool to this server.
	 *
	 * @param array|string $tool_args_or_class Tool arguments array or class name implementing WpcomMcpToolsInterface.
	 *
	 * @return self
	 */
	public function register_tool( $tool_args_or_class ): self {
		// Prepare server context for validation.
		$server_context = array(
			'server_id'      => $this->server_id,
			'existing_tools' => $this->tools,
		);

		// Use RegisterMcpTool to handle all validation and processing.
		$processed_tools = RegisterTool::create_tools( $tool_args_or_class, $server_context );

		// Add the processed tools to this server.
		foreach ( $processed_tools as $tool_name => $tool_data ) {
			$this->tools[ $tool_name ] = $tool_data;
		}

		// Log successful registration if any tools were processed.
		if ( ! empty( $processed_tools ) ) {
			ErrorHandler::log(
				'Successfully registered ' . count( $processed_tools ) . ' tool(s) to server ' . $this->server_id,
				array(
					'server_id'       => $this->server_id,
					'tool_names'      => array_keys( $processed_tools ),
					'tool_input_type' => is_string( $tool_args_or_class ) ? 'class' : 'array',
					'method'          => __METHOD__,
				),
				'debug'
			);
		}

		return $this;
	}

	/**
	 * Register a resource to this server.
	 *
	 * @param array|string $resource_args_or_class Resource arguments array or class name implementing WpcomMcpResourcesInterface.
	 * @param callable|null $callback Resource callback function (required when using array input).
	 *
	 * @return self
	 */
	public function register_resource( $resource_args_or_class, ?callable $callback = null ): self {
		// Prepare server context for validation.
		$server_context = array(
			'server_id'          => $this->server_id,
			'existing_resources' => $this->resources,
		);

		// Use RegisterResource to handle all validation and processing.
		$processed_resources = RegisterResource::create_resources( $resource_args_or_class, $callback, $server_context );

		// Add the processed resources to this server.
		foreach ( $processed_resources as $resource_uri => $resource_data ) {
			$this->resources[ $resource_uri ] = $resource_data;
		}

		// Log successful registration if any resources were processed.
		if ( ! empty( $processed_resources ) ) {
			ErrorHandler::log(
				'Successfully registered ' . count( $processed_resources ) . ' resource(s) to server ' . $this->server_id,
				array(
					'server_id'           => $this->server_id,
					'resource_uris'       => array_keys( $processed_resources ),
					'resource_input_type' => is_string( $resource_args_or_class ) ? 'class' : 'array',
					'method'              => __METHOD__,
				),
				'debug'
			);
		}

		return $this;
	}

	/**
	 * Register a prompt to this server.
	 *
	 * @param array|string $prompt_args_or_class Prompt arguments array or class name implementing WpcomMcpPromptsInterface.
	 * @param array|null   $messages Prompt messages (required when using array input).
	 *
	 * @return self
	 */
	public function register_prompt( $prompt_args_or_class, ?array $messages = null ): self {
		// Prepare server context for validation.
		$server_context = array(
			'server_id'        => $this->server_id,
			'existing_prompts' => $this->prompts,
		);

		// Use RegisterPrompt to handle all validation and processing.
		$processed_prompts = RegisterPrompt::create_prompts( $prompt_args_or_class, $messages, $server_context );

		// Add the processed prompts to this server.
		if ( ! empty( $processed_prompts ) ) {
			foreach ( $processed_prompts as $prompt_name => $prompt_data ) {
				$this->prompts[ $prompt_name ] = $prompt_data;
			}
		}

		// Log successful registration if any prompts were processed.
		if ( ! empty( $processed_prompts ) ) {
			ErrorHandler::log(
				'Successfully registered ' . count( $processed_prompts ) . ' prompt(s) to server ' . $this->server_id,
				array(
					'server_id'         => $this->server_id,
					'prompt_names'      => array_keys( $processed_prompts ),
					'prompt_input_type' => is_string( $prompt_args_or_class ) ? 'class' : 'array',
					'method'            => __METHOD__,
				),
				'debug'
			);
		}

		return $this;
	}

	/**
	 * Register multiple tools at once.
	 *
	 * @param array $tools Array of tools (can be class names or arrays).
	 *
	 * @return self
	 */
	public function register_tools( array $tools ): self {
		if ( empty( $tools ) ) {
			return $this;
		}

		// Validate input array structure.
		foreach ( $tools as $index => $tool ) {
			if ( ! is_string( $tool ) && ! is_array( $tool ) ) {
				ErrorHandler::log(
					'Invalid tool at index ' . $index . '. Tools must be class names or arrays.',
					array(
						'tool_index' => $index,
						'tool_type'  => gettype( $tool ),
						'server_id'  => $this->server_id,
						'method'     => __METHOD__,
					)
				);
				continue;
			}
		}

		// Prepare server context once for the entire batch.
		$server_context = array(
			'server_id'      => $this->server_id,
			'existing_tools' => $this->tools,
		);

		// Process tools efficiently.
		foreach ( $tools as $tool ) {
			try {
				// Use RegisterTool to handle all validation and processing.
				$processed_tools = RegisterTool::create_tools( $tool, $server_context );

				// Add the processed tools to this server.
				foreach ( $processed_tools as $tool_name => $tool_data ) {
					$this->tools[ $tool_name ] = $tool_data;

					// Update existing_tools in context for duplicate checking.
					$server_context['existing_tools'][ $tool_name ] = $tool_data;
				}
			} catch ( Exception $e ) {
				ErrorHandler::log(
					'Failed to register tool during bulk registration: ' . $e->getMessage(),
					array(
						'tool'      => $tool,
						'server_id' => $this->server_id,
						'error'     => $e->getMessage(),
						'method'    => __METHOD__,
					)
				);
			}
		}

		return $this;
	}

	/**
	 * Register multiple resources at once.
	 *
	 * @param array $resources Array of resources (can be class names or arrays).
	 *
	 * @return self
	 */
	public function register_resources( array $resources ): self {
		if ( empty( $resources ) ) {
			return $this;
		}

		// Validate input array structure.
		foreach ( $resources as $index => $resource ) {
			if ( ! is_string( $resource ) && ! is_array( $resource ) ) {
				ErrorHandler::log(
					'Invalid resource at index ' . $index . '. Resources must be class names or arrays.',
					array(
						'resource_index' => $index,
						'resource_type'  => gettype( $resource ),
						'server_id'      => $this->server_id,
						'method'         => __METHOD__,
					)
				);
				continue;
			}
		}

		// Prepare server context once for the entire batch.
		$server_context = array(
			'server_id'          => $this->server_id,
			'existing_resources' => $this->resources,
		);

		// Process resources efficiently.
		foreach ( $resources as $resource ) {
			try {
				$callback = null;
				if ( is_array( $resource ) && isset( $resource['args'] ) && isset( $resource['callback'] ) ) {
					$callback = $resource['callback'];
					$resource = $resource['args'];
				}

				// Use RegisterResource to handle all validation and processing.
				$processed_resources = RegisterResource::create_resources( $resource, $callback, $server_context );

				// Add the processed resources to this server.
				foreach ( $processed_resources as $resource_uri => $resource_data ) {
					$this->resources[ $resource_uri ] = $resource_data;

					// Update existing_resources in context for duplicate checking.
					$server_context['existing_resources'][ $resource_uri ] = $resource_data;
				}
			} catch ( Exception $e ) {
				ErrorHandler::log(
					'Failed to register resource during bulk registration: ' . $e->getMessage(),
					array(
						'resource'  => $resource,
						'server_id' => $this->server_id,
						'error'     => $e->getMessage(),
						'method'    => __METHOD__,
					)
				);
			}
		}

		return $this;
	}

	/**
	 * Register multiple prompts at once.
	 *
	 * @param array $prompts Array of prompts (can be class names or arrays).
	 *
	 * @return self
	 */
	public function register_prompts( array $prompts ): self {
		if ( empty( $prompts ) ) {
			return $this;
		}

		foreach ( $prompts as $prompt ) {
			if ( is_array( $prompt ) && isset( $prompt['args'] ) && isset( $prompt['messages'] ) ) {
				$this->register_prompt( $prompt['args'], $prompt['messages'] );
			} else {
				$this->register_prompt( $prompt );
			}
		}

		return $this;
	}

	/**
	 * Get all tools registered to this server.
	 *
	 * @param bool $filter_enabled Whether to filter out disabled tools.
	 *
	 * @return array
	 */
	public function get_tools(): array {
		return $this->tools;
	}

	/**
	 * Get all resources registered to this server.
	 *
	 * @return array
	 */
	public function get_resources(): array {
		return $this->resources;
	}

	/**
	 * Get all prompts registered to this server.
	 *
	 * @return array
	 */
	public function get_prompts(): array {
		return $this->prompts;
	}

	/**
	 * Get a specific tool by name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return array|null
	 */
	public function get_tool( string $tool_name ): ?array {
		return $this->tools[ $tool_name ] ?? null;
	}

	/**
	 * Get a specific resource by URI.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return array|null
	 */
	public function get_resource( string $resource_uri ): ?array {
		return $this->resources[ $resource_uri ] ?? null;
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return array|null
	 */
	public function get_prompt( string $prompt_name ): ?array {
		return $this->prompts[ $prompt_name ] ?? null;
	}

	/**
	 * Get a specific prompt by name (alias for get_prompt for backward compatibility).
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return array|null
	 */
	public function get_prompt_by_name( string $prompt_name ): ?array {
		return $this->get_prompt( $prompt_name );
	}

	/**
	 * Get prompt messages by prompt name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return array|null
	 */
	public function get_prompt_messages( string $prompt_name ): ?array {
		$prompt = $this->get_prompt( $prompt_name );
		return $prompt['messages'] ?? null;
	}

	/**
	 * Remove a tool from this server.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return bool True if removed, false if not found.
	 */
	public function remove_tool( string $tool_name ): bool {
		if ( isset( $this->tools[ $tool_name ] ) ) {
			unset( $this->tools[ $tool_name ] );

			return true;
		}

		return false;
	}

	/**
	 * Remove a resource from this server.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return bool True if removed, false if not found.
	 */
	public function remove_resource( string $resource_uri ): bool {
		if ( isset( $this->resources[ $resource_uri ] ) ) {
			unset( $this->resources[ $resource_uri ] );

			return true;
		}

		return false;
	}

	/**
	 * Remove a prompt from this server.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return bool True if removed, false if not found.
	 */
	public function remove_prompt( string $prompt_name ): bool {
		if ( isset( $this->prompts[ $prompt_name ] ) ) {
			unset( $this->prompts[ $prompt_name ] );

			return true;
		}

		return false;
	}

	/**
	 * Get server statistics.
	 *
	 * @return array
	 */
	public function get_statistics(): array {
		return array(
			'server_id'       => $this->server_id,
			'server_name'     => $this->server_name,
			'tools_count'     => count( $this->tools ),
			'resources_count' => count( $this->resources ),
			'prompts_count'   => count( $this->prompts ),
		);
	}

	/**
	 * Clear all tools, resources, and prompts from this server.
	 *
	 * @return self
	 */
	public function clear_all(): self {
		$this->tools     = array();
		$this->resources = array();
		$this->prompts   = array();

		return $this;
	}

	/**
	 * Get debug information about tools registration.
	 *
	 * This method provides detailed debugging information about the tools
	 * registration process, including validation status and configuration details.
	 *
	 * @return array Debug information array.
	 */
	public function get_tools_debug_info(): array {
		$debug_info = array(
			'server_id'         => $this->server_id,
			'server_name'       => $this->server_name,
			'total_tools'       => count( $this->tools ),
			'tools_summary'     => array(),
			'validation_issues' => array(),
		);

		foreach ( $this->tools as $tool_name => $tool_data ) {
			$tool_summary = array(
				'name'                    => $tool_data['name'] ?? 'unknown',
				'type'                    => $tool_data['type'] ?? 'unknown',
				'description'             => $tool_data['description'] ?? 'no description',
				'has_callback'            => isset( $tool_data['callback'] ),
				'has_permission_callback' => isset( $tool_data['permission_callback'] ),
				'has_input_schema'        => isset( $tool_data['inputSchema'] ),
				'has_rest_alias'          => isset( $tool_data['rest_alias'] ),
			);

			// Add REST alias info without circular reference risk.
			if ( isset( $tool_data['rest_alias'] ) ) {
				$tool_summary['rest_alias'] = array(
					'route'  => $tool_data['rest_alias']['route'] ?? 'unknown',
					'method' => $tool_data['rest_alias']['method'] ?? 'unknown',
				);
			}

			// Add input schema summary without the full schema (which might contain objects).
			if ( isset( $tool_data['inputSchema'] ) ) {
				$schema                               = $tool_data['inputSchema'];
				$tool_summary['input_schema_summary'] = array(
					'type'             => $schema['type'] ?? 'unknown',
					'properties_count' => isset( $schema['properties'] ) ? count( $schema['properties'] ) : 0,
					'required_count'   => isset( $schema['required'] ) ? count( $schema['required'] ) : 0,
				);
			}

			// Check for potential issues.
			$issues = array();
			if ( ! $tool_summary['has_callback'] && ! $tool_summary['has_rest_alias'] ) {
				$issues[] = 'No callback or REST alias defined';
			}
			if ( ! $tool_summary['has_permission_callback'] ) {
				$issues[] = 'No permission callback defined';
			}
			if ( ! $tool_summary['has_input_schema'] ) {
				$issues[] = 'No input schema defined';
			}

			$tool_summary['issues'] = $issues;
			if ( ! empty( $issues ) ) {
				$debug_info['validation_issues'][ $tool_name ] = $issues;
			}

			$debug_info['tools_summary'][ $tool_name ] = $tool_summary;
		}

		return $debug_info;
	}
}
