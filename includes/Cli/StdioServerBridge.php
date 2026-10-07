<?php
/**
 * STDIO bridge for exact MCP revisions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Cli;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Record;

/**
 * Exposes an MCP server through newline-delimited JSON on STDIN and STDOUT.
 *
 * Delegates protocol validation and response projection to McpWireOrchestrator.
 * Retains initialization parameters for legacy requests; modern requests carry
 * their own context. Diagnostics are written to STDERR.
 */
final class StdioServerBridge {

	/**
	 * Server whose components and configuration are exposed.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $server;

	/**
	 * Shared protocol validation, dispatch, and response projection boundary.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpWireOrchestrator
	 */
	private McpWireOrchestrator $orchestrator;

	/**
	 * Whether the serving loop should read another request.
	 *
	 * @var bool
	 */
	private bool $is_running = false;

	/**
	 * Parameters retained from successful legacy initialization, or null before initialization.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $client_params_2025_11_25 = null;

	/**
	 * Initialize the bridge and its protocol orchestrator.
	 *
	 * @param \WP\MCP\Core\McpServer $server Server to expose.
	 */
	public function __construct( McpServer $server ) {
		$this->server       = $server;
		$this->orchestrator = new McpWireOrchestrator( $server->create_transport_context() );
	}

	/**
	 * Read requests from STDIN and write JSON responses to STDOUT.
	 *
	 * Runs until EOF or until stop() clears the loop flag. Notifications produce
	 * no response line. Per-request failures are converted to protocol errors.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the STDIO transport is disabled by its filter.
	 */
	public function serve(): void {
		/**
		 * Filters whether the STDIO transport is enabled.
		 *
		 * Returning false disables serving before the read loop starts.
		 *
		 * @since 0.3.0
		 *
		 * @param bool $enabled Whether serving is enabled. Default true.
		 */
		if ( ! apply_filters( 'mcp_adapter_enable_stdio_transport', true ) ) {
			throw new \RuntimeException( 'The STDIO transport is disabled. Enable it by setting the "mcp_adapter_enable_stdio_transport" filter to true.' );
		}

		$this->is_running = true;
		$this->log_to_stderr( sprintf( 'MCP STDIO Bridge started for server: %s', $this->server->get_server_id() ) );

		while ( $this->is_running ) {
			$input = fgets( STDIN );
			if ( false === $input ) {
				break;
			}

			$input = rtrim( $input, "\r\n" );
			if ( '' === $input ) {
				continue;
			}

			try {
				$response = $this->handle_request( $input );
			} catch ( \Throwable $throwable ) {
				$this->log_to_stderr( 'Error processing request: ' . $throwable->getMessage() );
				$response = $this->encode_response( McpErrorFactory::internal_error( null, 'Internal error' ) );
			}

			if ( '' === $response ) {
				continue;
			}

			fwrite( STDOUT, $response . "\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDIO is the protocol transport.
			fflush( STDOUT );
		}

		$this->log_to_stderr( 'MCP STDIO Bridge stopped' );
	}

	/**
	 * Write a diagnostic message without adding data to the protocol stream.
	 *
	 * @param string $message Diagnostic message.
	 *
	 * @return void
	 */
	private function log_to_stderr( string $message ): void {
		fwrite( STDERR, "[MCP STDIO Bridge] $message\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR is the diagnostic channel.
	}

	/**
	 * Process one JSON request or notification from the STDIO stream.
	 *
	 * Stores initialization parameters only after a successful legacy response.
	 * Decode failures are converted to error responses before dispatch.
	 *
	 * @param string $json_input Raw JSON without its line delimiter.
	 *
	 * @return string Encoded response, or an empty string when no response is required.
	 */
	private function handle_request( string $json_input ): string {
		try {
			$message = $this->orchestrator->decode( $json_input );
		} catch ( \UnexpectedValueException | \RangeException $exception ) {
			return $this->encode_response( McpErrorFactory::invalid_request( null, $exception->getMessage() ) );
		} catch ( \Throwable $throwable ) {
			return $this->encode_response( McpErrorFactory::parse_error( null, $throwable->getMessage() ) );
		}

		$processed = $this->orchestrator->process( $message, 'STDIO', array(), $this->client_params_2025_11_25 );
		if ( $processed['notification'] ) {
			return '';
		}

		if ( is_array( $processed['initializeParams'] ) && $processed['response'] instanceof Record ) {
			$this->client_params_2025_11_25 = $processed['initializeParams'];
		}

		return null === $processed['response'] ? '' : $this->encode_response( $processed['response'] );
	}

	/**
	 * Encode a response record or an early protocol error array.
	 *
	 * @param \WP\McpSchema\Record|array<string, mixed> $response Response to serialize.
	 *
	 * @return string JSON without a trailing newline; a fixed internal-error envelope if encoding fails.
	 */
	private function encode_response( $response ): string {
		$json = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $json ) {
			return $json;
		}

		return sprintf( '{"jsonrpc":"2.0","error":{"code":%d,"message":"Internal error"},"id":null}', McpErrorFactory::INTERNAL_ERROR );
	}

	/**
	 * Prevent the serving loop from starting another read.
	 *
	 * Does not interrupt an already-blocked STDIN read or an in-progress request.
	 *
	 * @return void
	 */
	public function stop(): void {
		$this->is_running = false;
	}

	/**
	 * Get the server exposed by this bridge.
	 *
	 * @return \WP\MCP\Core\McpServer Exposed server.
	 */
	public function get_server(): McpServer {
		return $this->server;
	}
}
