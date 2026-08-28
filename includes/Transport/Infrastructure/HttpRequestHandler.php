<?php
/**
 * HTTP request handler for exact MCP revisions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record;
use WP\McpSchema\Schemas;

defined( 'ABSPATH' ) || exit;

/** Handles HTTP lifecycle, sessions, and raw-wire responses. */
class HttpRequestHandler {

	/** @var \WP\MCP\Transport\Infrastructure\McpTransportContext */
	public McpTransportContext $transport_context;

	/** @var \WP\MCP\Transport\Infrastructure\McpWireOrchestrator */
	private McpWireOrchestrator $orchestrator;

	/** Constructor. */
	public function __construct( McpTransportContext $transport_context ) {
		$this->transport_context = $transport_context;
		$this->orchestrator      = new McpWireOrchestrator( $transport_context );
	}

	/** Get transport context. */
	public function get_transport_context(): McpTransportContext {
		return $this->transport_context;
	}

	/** Route one HTTP request. */
	public function handle_request( HttpRequestContext $context ): \WP_REST_Response {
		if ( ! $this->is_valid_origin( $context->origin_header ) ) {
			return new \WP_REST_Response( McpErrorFactory::permission_denied( null, 'Invalid Origin header' ), 403 );
		}

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

	/** Handle one raw POST body. */
	private function handle_post( HttpRequestContext $context ): \WP_REST_Response {
		try {
			$message = $this->orchestrator->decode( $context->raw_body );
		} catch ( \UnexpectedValueException | \RangeException $exception ) {
			return new \WP_REST_Response( McpErrorFactory::invalid_request( null, $exception->getMessage() ), 400 );
		} catch ( \Throwable $throwable ) {
			return new \WP_REST_Response( McpErrorFactory::parse_error( null, $throwable->getMessage() ), 400 );
		}

		$method                   = isset( $message->method ) && is_string( $message->method ) ? $message->method : null;
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
				return new \WP_REST_Response( $session_validation, McpErrorFactory::get_http_status_for_error( $session_validation ) );
			}

			if ( Schemas::V2025_11_25 !== $context->protocol_version ) {
				$error = McpErrorFactory::invalid_request( $safe_id, 'MCP-Protocol-Version must be 2025-11-25 for an established 2025 session' );
				return new \WP_REST_Response( $error, 400 );
			}

			$session = SessionManager::get_session( get_current_user_id(), (string) $context->session_id );
			if ( ! is_array( $session ) || ! is_array( $session['client_params'] ?? null ) ) {
				$error = McpErrorFactory::session_not_found( $safe_id, 'Session context is unavailable' );
				return new \WP_REST_Response( $error, 404 );
			}
			$client_params_2025_11_25 = $session['client_params'];
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

		$data     = $processed['response'] instanceof Record ? $processed['response']->jsonSerialize() : $processed['response'];
		$status   = $this->orchestrator->http_response_status(
			$processed['response'],
			$processed['context'],
			$message,
			$context->protocol_version
		);
		$response = new \WP_REST_Response( $data, $status );
		if ( null !== $new_session_id ) {
			$response->header( 'Mcp-Session-Id', $new_session_id );
		}

		return $response;
	}

	/** Validate a present browser Origin against this WordPress installation. */
	private function is_valid_origin( ?string $origin ): bool {
		if ( null === $origin || '' === $origin ) {
			return true;
		}

		/**
		 * Filters exact HTTP origins allowed to call MCP endpoints.
		 *
		 * @since n.e.x.t
		 *
		 * @param list<string> $origins Allowed origins.
		 * @param \WP\MCP\Core\McpServer $server MCP server.
		 */
		$allowed = apply_filters(
			'mcp_adapter_allowed_http_origins',
			array( home_url(), site_url(), rest_url() ),
			$this->transport_context->mcp_server
		);
		if ( ! is_array( $allowed ) ) {
			return false;
		}

		$normalized = $this->normalize_origin( $origin, true );
		if ( null === $normalized ) {
			return false;
		}
		foreach ( $allowed as $candidate ) {
			if ( is_string( $candidate ) && $normalized === $this->normalize_origin( $candidate, false ) ) {
				return true;
			}
		}

		return false;
	}

	/** Normalize an origin to scheme, host, and effective port. */
	private function normalize_origin( string $url, bool $strict_origin ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		if (
			$strict_origin
			&& (
				isset( $parts['user'] )
				|| isset( $parts['pass'] )
				|| isset( $parts['query'] )
				|| isset( $parts['fragment'] )
				|| ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] )
			)
		) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return sprintf( '%s://%s:%d', $scheme, $host, $port );
	}
}
