<?php
/**
 * HTTP request handler for exact MCP revisions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates HTTP methods, legacy sessions, and protocol response delivery.
 *
 * POST requests pass through the shared wire orchestrator. Legacy DELETE
 * requests terminate a session; unsupported HTTP methods return 405.
 */
class HttpRequestHandler {

	/**
	 * Server and handler dependencies shared by HTTP processing.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	public McpTransportContext $transport_context;

	/**
	 * Revision selection, validation, and response projection boundary.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpWireOrchestrator
	 */
	private McpWireOrchestrator $orchestrator;

	/**
	 * Initialize HTTP processing with the server's transport dependencies.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $transport_context Server and handler dependencies.
	 */
	public function __construct( McpTransportContext $transport_context ) {
		$this->transport_context = $transport_context;
		$this->orchestrator      = new McpWireOrchestrator( $transport_context );
	}

	/**
	 * Get the dependency context used by this HTTP handler.
	 *
	 * @since 0.5.0
	 *
	 * @return \WP\MCP\Transport\Infrastructure\McpTransportContext Transport dependencies.
	 */
	public function get_transport_context(): McpTransportContext {
		return $this->transport_context;
	}

	/**
	 * Dispatch an HTTP request to protocol processing or session termination.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context Request body, method, and header values.
	 *
	 * @return \WP_REST_Response Protocol response, session-termination response, or method rejection.
	 */
	public function handle_request( HttpRequestContext $context ): \WP_REST_Response {
		$action = $this->orchestrator->http_method_action( $context->method, $context->protocol_version );
		if ( 'process' === $action ) {
			return $this->handle_post( $context );
		}

		if ( 'terminate-session' === $action ) {
			$result = HttpSessionValidator::terminate_session_with_error_handler( $context, $this->transport_context->error_handler );
			if ( true !== $result ) {
				return new \WP_REST_Response( $result, McpErrorFactory::get_http_status_for_error( $result ) );
			}

			return new \WP_REST_Response( null, 200 );
		}

		return new \WP_REST_Response( null, 405 );
	}

	/**
	 * Decode and process an HTTP POST using its selected protocol lifecycle.
	 *
	 * Validates and restores legacy session context before dispatch, and creates
	 * a session after successful legacy initialization. Modern requests use
	 * per-request context without creating or restoring an MCP session.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context Raw request and HTTP metadata.
	 *
	 * @return \WP_REST_Response Encoded protocol data, an early error, or an empty notification response.
	 */
	private function handle_post( HttpRequestContext $context ): \WP_REST_Response {
		try {
			$message = $this->orchestrator->decode( $context->raw_body );
		} catch ( \UnexpectedValueException | \RangeException $exception ) {
			return new \WP_REST_Response( McpErrorFactory::invalid_request( null, $exception->getMessage() ), 400 );
		} catch ( \Throwable $throwable ) {
			return new \WP_REST_Response( McpErrorFactory::parse_error( null, $throwable->getMessage() ), 400 );
		}

		$method                   = isset( $message->method ) && is_string( $message->method ) ? $message->method : null;
		$notification             = '2.0' === ( $message->jsonrpc ?? null ) && null !== $method && ! property_exists( $message, 'id' );
		$raw_id                   = $message->id ?? null;
		$safe_id                  = is_string( $raw_id ) || is_int( $raw_id ) ? $raw_id : null;
		$client_params_2025_11_25 = null;

		if ( $this->orchestrator->requires_2025_11_25_http_session( $message, $context->protocol_version ) ) {
			$session_validation = HttpSessionValidator::validate_session_with_error_handler(
				$context,
				$this->transport_context->error_handler,
				$safe_id
			);
			if ( true !== $session_validation ) {
				return new \WP_REST_Response( $notification ? null : $session_validation, McpErrorFactory::get_http_status_for_error( $session_validation ) );
			}

			$session = SessionManager::get_session( get_current_user_id(), (string) $context->session_id );
			if ( ! is_array( $session ) || ! is_array( $session['client_params'] ?? null ) ) {
				$error = McpErrorFactory::session_not_found( $safe_id, 'Session context is unavailable' );
				return new \WP_REST_Response( $notification ? null : $error, 404 );
			}
			$client_params_2025_11_25 = $session['client_params'];

			$header_error = $this->validate_session_protocol_version( $context->protocol_version, $client_params_2025_11_25, $safe_id );
			if ( null !== $header_error ) {
				return new \WP_REST_Response( $notification ? null : $header_error, 400 );
			}
		}

		$processed = $this->orchestrator->process(
			$message,
			'HTTP',
			array(
				'headers'          => $context->headers,
				'protocol_version' => $context->protocol_version,
				'session_id'       => $context->session_id,
			),
			$client_params_2025_11_25
		);

		if ( null === $processed['response'] ) {
			return new \WP_REST_Response( null, 202 );
		}

		$new_session_id = null;
		if ( 'initialize' === $method && $processed['response'] instanceof Record && is_array( $processed['initializeParams'] ) ) {
			$session = HttpSessionValidator::create_session_with_error_handler(
				$processed['initializeParams'],
				$this->transport_context->error_handler,
				$safe_id
			);
			if ( is_array( $session ) ) {
				return new \WP_REST_Response( $session, McpErrorFactory::get_http_status_for_error( $session ) );
			}
			$new_session_id = $session;
		}

		$data   = $processed['response'] instanceof Record ? $processed['response']->jsonSerialize() : $processed['response'];
		$status = $this->orchestrator->http_response_status(
			$processed['response'],
			$processed['context'],
			$message,
			$context->protocol_version
		);
		// Rejected notifications retain their HTTP error status without a JSON-RPC response body.
		$response = new \WP_REST_Response( $processed['notification'] ? null : $data, $processed['notification'] ? max( 400, $status ) : $status );
		if ( null !== $new_session_id ) {
			$response->header( 'Mcp-Session-Id', $new_session_id );
		}

		return $response;
	}

	/**
	 * Check the MCP-Protocol-Version header against the version negotiated at initialization.
	 *
	 * The header must match the negotiated identifier whenever it is sent. It may
	 * be omitted only for sessions negotiated under a revision that predates the
	 * header, see {@see McpVersionNegotiator::PROTOCOL_VERSION_HEADER_SINCE}.
	 *
	 * @param string|null          $header_version Request header value.
	 * @param array<string, mixed> $client_params  Stored initialize params.
	 * @param string|int|null      $request_id     Readable JSON-RPC request ID.
	 * @return array<string, mixed>|null Error payload, or null when the header is acceptable.
	 * @since 0.7.0
	 */
	private function validate_session_protocol_version( ?string $header_version, array $client_params, $request_id ): ?array {
		$negotiated = McpWireOrchestrator::negotiated_protocol_version( $client_params );

		if ( null === $header_version ) {
			return McpVersionNegotiator::requires_protocol_version_header( $negotiated )
				? McpErrorFactory::invalid_request( $request_id, sprintf( 'MCP-Protocol-Version header is required for a %s session', $negotiated ) )
				: null;
		}

		return $header_version === $negotiated
			? null
			: McpErrorFactory::invalid_request( $request_id, sprintf( 'MCP-Protocol-Version must be %s for this session', $negotiated ) );
	}
}
