<?php
/**
 * HTTP Session Validator for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles HTTP-specific session validation logic for MCP transports.
 *
 * Centralizes HTTP request context validation and session management coordination
 * to eliminate duplication across transport implementations.
 */
class HttpSessionValidator {

	/**
	 * Validate session for MCP HTTP requests.
	 *
	 * Performs complete session validation including HTTP headers, user authentication,
	 * and session validity in a single method to reduce method call overhead.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|true Returns true if valid, error array if invalid.
	 */
	public static function validate_session( HttpRequestContext $context ) {
		return self::validate_session_with_error_handler( $context, null );
	}

	/**
	 * Validate a session and report storage failures to an error handler.
	 *
	 * @since 0.6.0
	 * @internal
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext                  $context       The HTTP request context.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures.
	 *
	 * @return array|true Returns true if valid, error array if invalid.
	 */
	public static function validate_session_with_error_handler( HttpRequestContext $context, ?McpErrorHandlerInterface $error_handler ) {
		// Check session header presence
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' );
		}

		// Check user authentication
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User not authenticated' );
		}

		// Validate session using SessionManager
		if ( ! SessionManager::validate_session( $user_id, $session_id, $error_handler ) ) {
			return McpErrorFactory::session_not_found( null, 'Invalid or expired session' );
		}

		return true;
	}

	/**
	 * Validate a legacy HTTP session and return its negotiated revision.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context HTTP request context.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Storage-failure reporter.
	 *
	 * @return string|array<string, mixed> Negotiated revision or error envelope.
	 */
	public static function validate_session_protocol_version( HttpRequestContext $context, ?McpErrorHandlerInterface $error_handler ) {
		$validation = self::validate_session_with_error_handler( $context, $error_handler );
		if ( true !== $validation ) {
			return $validation;
		}

		$user_id          = get_current_user_id();
		$session_id       = (string) $context->session_id;
		$protocol_version = SessionManager::get_protocol_version( $user_id, $session_id );

		if ( null === $protocol_version ) {
			return McpErrorFactory::session_not_found( null, 'Invalid or expired session' );
		}

		return $protocol_version;
	}

	/**
	 * Validate session header presence in HTTP request.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return string|array Session ID on success, error array on failure.
	 */
	public static function validate_session_header( HttpRequestContext $context ) {
		$session_id = $context->session_id;

		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' );
		}

		return $session_id;
	}

	/**
	 * Create a new session for the current user with HTTP context awareness.
	 *
	 * Validates user authentication and creates session, providing better error
	 * context than direct SessionManager calls.
	 *
	 * @param array       $params The client parameters from initialize request.
	 * @param string|null $protocol_version Negotiated legacy revision.
	 *
	 * @return string|array Session ID on success, error array on failure.
	 */
	public static function create_session( array $params = array(), ?string $protocol_version = null ) {
		return self::create_session_with_error_handler( $params, null, $protocol_version );
	}

	/**
	 * Create a session and report storage failures to an error handler.
	 *
	 * @since 0.6.0
	 * @internal
	 *
	 * @param array                                                 $params        The client parameters from initialize request.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures.
	 * @param string|null                                           $protocol_version Negotiated legacy revision.
	 *
	 * @return string|array Session ID on success, error array on failure.
	 */
	public static function create_session_with_error_handler( array $params, ?McpErrorHandlerInterface $error_handler, ?string $protocol_version = null ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User authentication required for session creation' );
		}

		$session_id = SessionManager::create_session( $user_id, $params, $error_handler, $protocol_version );

		if ( ! $session_id ) {
			return McpErrorFactory::internal_error( null, 'Failed to create session' );
		}

		return $session_id;
	}

	/**
	 * Terminate a session with full HTTP context validation.
	 *
	 * Performs complete validation workflow for session termination including
	 * header validation, user authentication, and session cleanup.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|true Returns true on success, error array on failure.
	 */
	public static function terminate_session( HttpRequestContext $context ) {
		return self::terminate_session_with_error_handler( $context, null );
	}

	/**
	 * Terminate a session and report storage failures to an error handler.
	 *
	 * @since 0.6.0
	 * @internal
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext                  $context       The HTTP request context.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures.
	 *
	 * @return array|true Returns true on success, error array on failure.
	 */
	public static function terminate_session_with_error_handler( HttpRequestContext $context, ?McpErrorHandlerInterface $error_handler ) {
		// Validate session header
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( null, 'Missing Mcp-Session-Id header' );
		}

		// Validate user authentication
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( null, 'User not authenticated' );
		}

		// Terminate the session
		SessionManager::delete_session( $user_id, $session_id, $error_handler );

		return true;
	}
}
