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
	 * MCP session ID for DeepWiki and similar servers.
	 *
	 * @var string|null
	 */
	private ?string $session_id = null;

	/**
	 * Transport protocol (sse or mcp).
	 *
	 * @var string
	 */
	private string $transport = 'mcp';

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

		// Detect transport protocol from URL
		if ( strpos( $server_url, '/sse' ) !== false ) {
			$this->transport = 'sse';
		} elseif ( strpos( $server_url, '/mcp' ) !== false ) {
			$this->transport = 'mcp';
		}


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
				$error_msg = 'MCP Initialize Request Failed: ' . $response->get_error_message();
				error_log( $error_msg );
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

			// Handle successful response
			if ( $response ) {
				$this->connected = true;
				$this->capabilities = $response['capabilities'] ?? array();
				
				// Extract session ID if provided (for DeepWiki compatibility)
				if ( isset( $response['sessionId'] ) ) {
					$this->session_id = $response['sessionId'];
				}
				
			} else {
				return false;
			}

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

		// Set headers based on transport protocol
		if ( $this->transport === 'sse' ) {
			$headers = array(
				'Content-Type' => 'application/json',
				'Accept'       => 'text/event-stream',
				'Cache-Control' => 'no-cache',
			);
			error_log( 'MCP Client: Using SSE transport headers' );
		} else {
			$headers = array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json, text/event-stream',
			);
			
			// Only add session ID for /mcp transport, but NOT for initialize requests
			if ( $method === 'initialize' && ! $this->session_id ) {
				$this->session_id = $this->generate_session_id();
			}
			
			// DeepWiki requires that initialize requests do NOT include session ID
			if ( $this->session_id && $method !== 'initialize' ) {
				$headers['Mcp-Session-Id'] = $this->session_id;
			}
		}

		// Use POST for both SSE and MCP (based on working curl test)
		$timeout = $this->config['timeout'] ?? 30;
		
		// For SSE, use shorter timeout since it's a streaming connection
		if ( $this->transport === 'sse' ) {
			$timeout = min( $timeout, 5 ); // Max 5 seconds for SSE initial response
		}
		
		$args = array(
			'method'    => 'POST',
			'headers'   => $headers,
			'body'      => wp_json_encode( $request_body ),
			'timeout'   => $timeout,
			'sslverify' => $this->config['ssl_verify'] ?? true,
		);
		

		// Add authentication if configured
		if ( isset( $this->config['auth'] ) ) {
			$args['headers'] = array_merge( $args['headers'], $this->get_auth_headers() );
		}

		
		// Use cURL directly for SSE to handle streaming properly
		if ( $this->transport === 'sse' ) {
			$response = $this->send_sse_request( $this->server_url, $args );
		} else {
			$response = wp_remote_post( $this->server_url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$http_code = wp_remote_retrieve_response_code( $response );
		
		// Handle response based on transport protocol and content
		if ( $this->transport === 'sse' || strpos( $response_body, 'event: message' ) !== false ) {
			$decoded = $this->parse_sse_response( $response_body );
			if ( ! $decoded ) {
				return new \WP_Error( 'sse_parse_error', 'Failed to parse SSE response' );
			}
		} else {
			$decoded = json_decode( $response_body, true );
		}

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

	/**
	 * Send SSE request using cURL for proper streaming support.
	 *
	 * @param string $url Request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error Response array or error.
	 */
	private function send_sse_request( string $url, array $args ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error( 'curl_missing', 'cURL is required for SSE support' );
		}

		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $args['body'],
			CURLOPT_TIMEOUT        => $args['timeout'],
			CURLOPT_SSL_VERIFYPEER => $args['sslverify'],
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
		) );

		// Set headers
		$curl_headers = array();
		foreach ( $args['headers'] as $key => $value ) {
			$curl_headers[] = $key . ': ' . $value;
		}
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );


		$response_body = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_error = curl_error( $ch );
		curl_close( $ch );

		if ( $curl_error ) {
			return new \WP_Error( 'curl_error', $curl_error );
		}

		// Return in WordPress HTTP response format
		return array(
			'response' => array( 'code' => $http_code ),
			'body'     => $response_body,
		);
	}

	/**
	 * Generate a unique session ID for MCP requests.
	 *
	 * @return string Generated session ID.
	 */
	private function generate_session_id(): string {
		// Try different session ID formats for DeepWiki compatibility
		return uniqid();
	}

	/**
	 * Parse Server-Sent Events response format.
	 *
	 * @param string $sse_body SSE response body.
	 * @return array|null Parsed JSON data or null if no message found.
	 */
	private function parse_sse_response( string $sse_body ): ?array {
		
		$lines = explode( "\n", $sse_body );
		$current_event = null;
		$current_data = '';
		$parsed_messages = array();
		
		foreach ( $lines as $line ) {
			$line = trim( $line );
			
			if ( strpos( $line, 'event: ' ) === 0 ) {
				$current_event = substr( $line, 7 );
			} elseif ( strpos( $line, 'data: ' ) === 0 ) {
				$current_data = substr( $line, 6 );
				
				// If this is a message event with JSON data, parse it
				if ( $current_event === 'message' && ! empty( $current_data ) ) {
					$decoded = json_decode( $current_data, true );
					if ( $decoded ) {
						// For initialize responses, return the result
						if ( isset( $decoded['result'] ) ) {
							return $decoded['result'];
						}
						// For other responses, return the full decoded response
						return $decoded;
					}
				}
			} elseif ( empty( $line ) ) {
				// Empty line indicates end of event
				$current_event = null;
				$current_data = '';
			}
		}
		
		return null;
	}
}