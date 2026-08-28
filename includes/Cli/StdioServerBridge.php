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

/** Exposes one MCP server over newline-delimited STDIO. */
class StdioServerBridge {

	/** @var \WP\MCP\Core\McpServer */
	private McpServer $server;

	/** @var \WP\MCP\Transport\Infrastructure\McpWireOrchestrator */
	private McpWireOrchestrator $orchestrator;

	/** @var bool */
	private bool $is_running = false;

	/** @var array<string, mixed>|null */
	private ?array $client_params_2025_11_25 = null;

	/** Constructor. */
	public function __construct( McpServer $server ) {
		$this->server       = $server;
		$this->orchestrator = new McpWireOrchestrator( $server->create_transport_context() );
	}

	/** Serve requests until EOF or stop(). */
	public function serve(): void {
		/**
		 * Filters whether the STDIO transport is enabled.
		 *
		 * @since 0.3.0
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

			fwrite( STDOUT, $response . "\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- STDIO is the protocol transport.
			fflush( STDOUT );
		}

		$this->log_to_stderr( 'MCP STDIO Bridge stopped' );
	}

	/** Log to stderr without contaminating the wire stream. */
	private function log_to_stderr( string $message ): void {
		fwrite( STDERR, "[MCP STDIO Bridge] $message\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- STDERR is the diagnostic channel.
	}

	/** Process one raw STDIO line. */
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
	 * Encode one exact response.
	 *
	 * @param \WP\McpSchema\Record|array<string, mixed> $response Response.
	 */
	private function encode_response( $response ): string {
		$json = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $json ) {
			return $json;
		}

		return sprintf( '{"jsonrpc":"2.0","error":{"code":%d,"message":"Internal error"},"id":null}', McpErrorFactory::INTERNAL_ERROR );
	}

	/** Stop serving. */
	public function stop(): void {
		$this->is_running = false;
	}

	/** Get the exposed server. */
	public function get_server(): McpServer {
		return $this->server;
	}
}
