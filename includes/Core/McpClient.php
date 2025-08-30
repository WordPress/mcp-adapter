<?php
/**
 * MCP Client for connecting to external MCP servers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

/**
 * MCP Client for connecting to external MCP servers.
 * 
 * Mirrors the McpServer architecture for consistency.
 */
class McpClient {

	/**
	 * Client identifier.
	 *
	 * @var string
	 */
	private string $client_id;

	/**
	 * Server URL.
	 *
	 * @var string
	 */
	private string $server_url;

	/**
	 * Client configuration.
	 *
	 * @var array
	 */
	private array $config;

	/**
	 * Error handler.
	 *
	 * @var McpErrorHandlerInterface
	 */
	public McpErrorHandlerInterface $error_handler;

	/**
	 * Observability handler.
	 *
	 * @var McpObservabilityHandlerInterface
	 */
	public McpObservabilityHandlerInterface $observability_handler;

	/**
	 * Connection status.
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * Discovered server capabilities.
	 *
	 * @var array
	 */
	private array $capabilities = array();

	/**
	 * Constructor.
	 *
	 * @param string                           $client_id             Client identifier.
	 * @param string                           $server_url            MCP server URL.
	 * @param array                            $config                Client configuration.
	 * @param McpErrorHandlerInterface         $error_handler         Error handler.
	 * @param McpObservabilityHandlerInterface $observability_handler Observability handler.
	 */
	public function __construct(
		string $client_id,
		string $server_url,
		array $config,
		McpErrorHandlerInterface $error_handler,
		McpObservabilityHandlerInterface $observability_handler
	) {
		$this->client_id             = $client_id;
		$this->server_url            = rtrim( $server_url, '/' );
		$this->config                = $config;
		$this->error_handler         = $error_handler;
		$this->observability_handler = $observability_handler;

		// Auto-connect on construction
		$this->connect();
	}

	/**
	 * Connect to the MCP server.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function connect(): bool {
		$start_time = microtime( true );

		try {
			// Simple handshake request
			$response = $this->send_request( 'initialize', array(
				'protocolVersion' => '2025-06-18',
				'capabilities'    => array(
					'roots'    => array( 'listChanged' => true ),
					'sampling' => array(),
				),
				'clientInfo' => array(
					'name'    => 'WordPress MCP Client',
					'version' => '1.0.0',
				),
			) );

			if ( is_wp_error( $response ) ) {
				$this->error_handler->log(
					'Failed to connect to MCP server',
					array(
						'client_id'  => $this->client_id,
						'server_url' => $this->server_url,
						'error'      => $response->get_error_message(),
					)
				);
				return false;
			}

			$this->connected = true;
			$this->capabilities = $response['capabilities'] ?? array();

			// Record connection
			$duration = ( microtime( true ) - $start_time ) * 1000;
			$this->observability_handler::record_event(
				'mcp.client.connected',
				array( 'client_id' => $this->client_id )
			);
			$this->observability_handler::record_timing(
				'mcp.client.connect_duration',
				$duration,
				array( 'client_id' => $this->client_id )
			);

			return true;

		} catch ( \Throwable $e ) {
			$this->error_handler->log(
				'Exception during MCP client connection',
				array(
					'client_id' => $this->client_id,
					'exception' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Send a request to the MCP server.
	 *
	 * @param string $method     MCP method.
	 * @param array  $params     Request parameters.
	 * @param int    $request_id Request ID.
	 * @return array|\WP_Error Response or error.
	 */
	public function send_request( string $method, array $params = array(), int $request_id = 0 ) {
		static $request_counter = 1;
		
		if ( 0 === $request_id ) {
			$request_id = $request_counter++;
		}

		$request_body = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => $request_id,
		);

		$args = array(
			'method'    => 'POST',
			'headers'   => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'      => wp_json_encode( $request_body ),
			'timeout'   => $this->config['timeout'] ?? 30,
			'sslverify' => $this->config['ssl_verify'] ?? true,
		);

		// Add authentication if configured
		if ( isset( $this->config['auth'] ) ) {
			$args['headers'] = array_merge( $args['headers'], $this->get_auth_headers() );
		}

		$response = wp_remote_post( $this->server_url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'json_error', 'Invalid JSON response' );
		}

		if ( isset( $decoded['error'] ) ) {
			return new \WP_Error(
				$decoded['error']['code'] ?? 'server_error',
				$decoded['error']['message'] ?? 'Unknown server error'
			);
		}

		return $decoded['result'] ?? $decoded;
	}

	/**
	 * Get authentication headers.
	 *
	 * @return array Auth headers.
	 */
	private function get_auth_headers(): array {
		$auth = $this->config['auth'];
		$headers = array();

		switch ( $auth['type'] ?? 'none' ) {
			case 'bearer':
				if ( isset( $auth['token'] ) ) {
					$headers['Authorization'] = 'Bearer ' . $auth['token'];
				}
				break;

			case 'api_key':
				if ( isset( $auth['key'] ) ) {
					$header_name = $auth['header'] ?? 'X-API-Key';
					$headers[ $header_name ] = $auth['key'];
				}
				break;

			case 'basic':
				if ( isset( $auth['username'], $auth['password'] ) ) {
					$credentials = base64_encode( $auth['username'] . ':' . $auth['password'] );
					$headers['Authorization'] = 'Basic ' . $credentials;
				}
				break;
		}

		return $headers;
	}

	/**
	 * Execute a remote tool.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array|\WP_Error Tool result or error.
	 */
	public function call_tool( string $tool_name, array $arguments = array() ) {
		return $this->send_request( 'tools/call', array(
			'name'      => $tool_name,
			'arguments' => $arguments,
		) );
	}

	/**
	 * Read a remote resource.
	 *
	 * @param string $resource_uri Resource URI.
	 * @return array|\WP_Error Resource data or error.
	 */
	public function read_resource( string $resource_uri ) {
		return $this->send_request( 'resources/read', array(
			'uri' => $resource_uri,
		) );
	}

	/**
	 * Get a remote prompt.
	 *
	 * @param string $prompt_name Prompt name.
	 * @param array  $arguments   Prompt arguments.
	 * @return array|\WP_Error Prompt data or error.
	 */
	public function get_prompt( string $prompt_name, array $arguments = array() ) {
		return $this->send_request( 'prompts/get', array(
			'name'      => $prompt_name,
			'arguments' => $arguments,
		) );
	}

	/**
	 * List available tools.
	 *
	 * @return array|\WP_Error Tools list or error.
	 */
	public function list_tools() {
		return $this->send_request( 'tools/list' );
	}

	/**
	 * List available resources.
	 *
	 * @return array|\WP_Error Resources list or error.
	 */
	public function list_resources() {
		return $this->send_request( 'resources/list' );
	}

	/**
	 * List available prompts.
	 *
	 * @return array|\WP_Error Prompts list or error.
	 */
	public function list_prompts() {
		return $this->send_request( 'prompts/list' );
	}

	/**
	 * Get client ID.
	 *
	 * @return string Client ID.
	 */
	public function get_client_id(): string {
		return $this->client_id;
	}

	/**
	 * Get server URL.
	 *
	 * @return string Server URL.
	 */
	public function get_server_url(): string {
		return $this->server_url;
	}

	/**
	 * Check if connected.
	 *
	 * @return bool True if connected.
	 */
	public function is_connected(): bool {
		return $this->connected;
	}

	/**
	 * Get discovered capabilities.
	 *
	 * @return array Server capabilities.
	 */
	public function get_capabilities(): array {
		return $this->capabilities;
	}
}