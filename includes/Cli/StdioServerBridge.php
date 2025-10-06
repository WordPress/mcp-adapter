<?php
/**
 * STDIO Server Bridge for MCP Adapter
 *
 * This service acts as a bridge between the STDIO protocol and MCP servers,
 * allowing any registered server to be exposed via command-line interface.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Cli;

use WP\MCP\Core\McpServer;
use WP\MCP\Transport\Infrastructure\RequestRouter;

/**
 * STDIO Server Bridge - Exposes MCP servers via STDIO protocol
 *
 * This service handles JSON-RPC communication over stdin/stdout and delegates
 * requests to the appropriate MCP server. Unlike transport implementations,
 * this is a presentation layer service that can work with any server.
 */
class StdioServerBridge {

	/**
	 * The MCP server to expose via STDIO.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $server;

	/**
	 * Request router for handling MCP requests.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\RequestRouter
	 */
	private RequestRouter $request_router;

	/**
	 * Whether the bridge is currently running.
	 *
	 * @var bool
	 */
	private bool $is_running = false;

	/**
	 * Initialize the STDIO server bridge.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server to expose.
	 */
	public function __construct( McpServer $server ) {
		$this->server = $server;

		// Create request router using server's infrastructure
		$this->request_router = $this->create_request_router();
	}

	/**
	 * Start the STDIO server bridge.
	 *
	 * This method reads JSON-RPC messages from stdin and writes responses to stdout.
	 * It runs in a loop until terminated or until it receives a shutdown signal.
	 *
	 * @throws \RuntimeException If STDIO transport is disabled.
	 */
	public function serve(): void {
		// Check if STDIO transport is enabled
		$enable_serve = apply_filters( 'mcp_adapter_enable_stdio_transport', true );

		if ( ! $enable_serve ) {
			throw new \RuntimeException(
				'The STDIO transport is disabled. Enable it by setting the "mcp_adapter_enable_stdio_transport" filter to true.'
			);
		}

		$this->is_running = true;

		// Log to stderr to keep stdout clean for MCP messages
		$this->log_to_stderr( sprintf( 'MCP STDIO Bridge started for server: %s', $this->server->get_server_id() ) );

		// Main server loop
		while ( $this->is_running ) {
			try {
				// Read a line from stdin (blocking)
				$input = fgets( STDIN );

				if ( false === $input ) {
					// EOF or error reading from stdin
					break;
				}

				// Trim newline delimiter
				$input = rtrim( $input, "\r\n" );

				if ( empty( $input ) ) {
					// Empty line, continue reading
					continue;
				}

				// Process the request and get response
				$response = $this->handle_request( $input );

				// Write response to stdout with newline delimiter
				if ( ! empty( $response ) ) {
					// Use fwrite() for precise binary-safe JSON-RPC protocol communication.
					// WP_CLI output functions would add formatting/prefixes that break MCP protocol.
					// MCP requires exact control over stdout for machine-to-machine communication.
					fwrite( STDOUT, $response . "\n" ); // phpcs:ignore
					fflush( STDOUT );
				}
			} catch ( \Throwable $e ) {
				// Log errors to stderr
				$this->log_to_stderr( 'Error processing request: ' . $e->getMessage() );

				// Send error response
				$error_response = wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'error'   => array(
							'code'    => -32603,
							'message' => 'Internal error',
							'data'    => array(
								'details' => $e->getMessage(),
							),
						),
						'id'      => null,
					)
				);

				fwrite( STDOUT, $error_response . "\n" ); // phpcs:ignore
				fflush( STDOUT );
			}
		}

		$this->log_to_stderr( 'MCP STDIO Bridge stopped' );
	}

	/**
	 * Stop the STDIO server bridge.
	 */
	public function stop(): void {
		$this->is_running = false;
	}

	/**
	 * Handle a JSON-RPC request string and return a JSON-RPC response string.
	 *
	 * @param string $json_input The JSON-RPC request string.
	 *
	 * @return string The JSON-RPC response string (empty for notifications).
	 */
	private function handle_request( string $json_input ): string {
		try {
			// Parse JSON-RPC request
			$request = json_decode( $json_input, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return $this->create_error_response(
					null,
					-32700,
					'Parse error',
					'Invalid JSON was received by the server.'
				);
			}

			// Validate JSON-RPC structure
			if ( ! is_array( $request ) ) {
				return $this->create_error_response(
					null,
					-32600,
					'Invalid Request',
					'The JSON sent is not a valid Request object.'
				);
			}

			// Check for JSON-RPC version
			if ( ! isset( $request['jsonrpc'] ) || '2.0' !== $request['jsonrpc'] ) {
				return $this->create_error_response(
					$request['id'] ?? null,
					-32600,
					'Invalid Request',
					'The JSON-RPC version must be 2.0.'
				);
			}

			// Extract request components
			$method = $request['method'] ?? null;
			$params = $request['params'] ?? array();
			$id     = $request['id'] ?? null;

			if ( ! is_string( $method ) ) {
				return $this->create_error_response(
					$id,
					-32600,
					'Invalid Request',
					'Method must be a string.'
				);
			}

			// Convert params to array if it's an object
			if ( is_object( $params ) ) {
				$params = (array) $params;
			}

			if ( ! is_array( $params ) ) {
				$params = array();
			}

			// Route the request to the appropriate handler
			$result = $this->request_router->route_request(
				$method,
				$params,
				$id,
				'stdio'
			);

			// If this is a notification (no id), don't send a response
			if ( null === $id ) {
				return '';
			}

			// Format the response
			return $this->format_response( $result, $id );
		} catch ( \Throwable $e ) {
			// Handle unexpected errors
			return $this->create_error_response(
				null,
				-32603,
				'Internal error',
				$e->getMessage()
			);
		}
	}

	/**
	 * Format a handler result as a JSON-RPC response.
	 *
	 * @param array $result The handler result.
	 * @param mixed $id     The request ID.
	 *
	 * @return string The JSON-RPC response string.
	 */
	private function format_response( array $result, $id ): string {
		$response = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
		);

		// Check if result contains an error
		if ( isset( $result['error'] ) ) {
			$error = $result['error'];

			// Ensure error has required fields
			$response['error'] = array(
				'code'    => $error['code'] ?? -32603,
				'message' => $error['message'] ?? 'Internal error',
			);

			// Add data field if present
			if ( isset( $error['data'] ) ) {
				$response['error']['data'] = $error['data'];
			}
		} else {
			// Success response
			$response['result'] = (object) $result;
		}

		$json = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			// Fallback for encoding errors
			return $this->create_error_response(
				$id,
				-32603,
				'Internal error',
				'Failed to encode response as JSON.'
			);
		}

		return $json;
	}

	/**
	 * Create a JSON-RPC error response.
	 *
	 * @param mixed  $id      The request ID (can be null).
	 * @param int    $code    The error code.
	 * @param string $message The error message.
	 * @param string $data    Optional error data.
	 *
	 * @return string The JSON error response string.
	 */
	private function create_error_response( $id, int $code, string $message, string $data = '' ): string {
		$response = array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
			'id'      => $id,
		);

		if ( ! empty( $data ) ) {
			$response['error']['data'] = $data;
		}

		return wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"},"id":null}';
	}

	/**
	 * Create a request router for the server.
	 *
	 * @return \WP\MCP\Transport\Infrastructure\RequestRouter
	 */
	private function create_request_router(): RequestRouter {
		// Create transport context using server's infrastructure
		$context = $this->server->create_transport_context();
		return $context->request_router;
	}

	/**
	 * Log a message to stderr.
	 *
	 * @param string $message The message to log.
	 */
	private function log_to_stderr( string $message ): void {
		fwrite( STDERR, "[MCP STDIO Bridge] $message\n" ); // phpcs:ignore
	}

	/**
	 * Get the server this bridge is exposing.
	 *
	 * @return \WP\MCP\Core\McpServer
	 */
	public function get_server(): McpServer {
		return $this->server;
	}
}
