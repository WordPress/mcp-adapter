<?php
/**
 * HTTP Session Validator for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

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
		// Check session header presence
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( 0, 'Missing Mcp-Session-Id header' );
		}

		// Check user authentication
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( 0, 'User not authenticated' );
		}

		// Validate session using SessionManager
		if ( ! SessionManager::validate_session( $user_id, $session_id ) ) {
			return McpErrorFactory::invalid_params( 0, 'Invalid or expired session' );
		}

		return true;
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
			return McpErrorFactory::invalid_request( 0, 'Missing Mcp-Session-Id header' );
		}

		return $session_id;
	}

	/**
	 * Create a new session for the current user with HTTP context awareness.
	 *
	 * Validates user authentication and creates session, providing better error
	 * context than direct SessionManager calls.
	 *
	 * @param array $params The client parameters from initialize request.
	 *
	 * @return string|array Session ID on success, error array on failure.
	 */
	public static function create_session( array $params = array() ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( 0, 'User authentication required for session creation' );
		}

		$session_id = SessionManager::create_session( $user_id, $params );

		if ( ! $session_id ) {
			return McpErrorFactory::internal_error( 0, 'Failed to create session' );
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
		// Validate session header
		$session_id = $context->session_id;
		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( 0, 'Missing Mcp-Session-Id header' );
		}

		// Validate user authentication
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return McpErrorFactory::unauthorized( 0, 'User not authenticated' );
		}

		// Terminate the session
		SessionManager::delete_session( $user_id, $session_id );

		return true;
	}
}
