<?php
/**
 * Authentication Validator for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles authentication and permission validation for MCP transports.
 *
 * Centralizes authentication logic to eliminate duplication and provide
 * consistent permission checking across transport implementations.
 */
class AuthenticationValidator {

	/**
	 * Check if the user has permission to access the MCP API.
	 *
	 * Uses custom permission callback if provided, otherwise falls back to default.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context                     The HTTP request context.
	 * @param callable|null      $transport_permission_callback Optional custom permission callback.
	 *
	 * @return bool True if permitted, False if denied.
	 */
	public static function check_permission( HttpRequestContext $context, ?callable $transport_permission_callback = null ) {
		// Use custom permission callback if provided
		if ( null !== $transport_permission_callback ) {
			try {
				return call_user_func( $transport_permission_callback, $context->request );
			} catch ( \Throwable $e ) {
				// Fall back to default on callback failure
				return false;
			}
		}

		// Secure default: require logged-in user
		return is_user_logged_in();
	}


	/**
	 * Validate that user is authenticated.
	 *
	 * @return int|array User ID on success, error array on failure.
	 */
	public static function validate_user_authentication() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return McpErrorFactory::invalid_request( 0, 'User not authenticated' );
		}

		return $user_id;
	}
}
