<?php
/**
 * Interface for MCP transport protocols.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Contracts;

use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP_Error;

/**
 * Interface for MCP transport protocols.
 *
 * This interface defines the contract for all MCP transport implementations,
 * allowing different communication protocols (REST, Streamable, etc.) to be
 * plugged into the MCP server architecture.
 */
interface McpTransportInterface {

	/**
	 * Initialize the transport with provided context.
	 *
	 * @param McpTransportContext $context Dependency injection container.
	 */
	public function __construct( McpTransportContext $context );

	/**
	 * Check if the user has permission to access the MCP API.
	 *
	 * @return bool|WP_Error True if allowed, WP_Error or false if not.
	 */
	public function check_permission(): WP_Error|bool;

	/**
	 * Handle incoming requests.
	 *
	 * The specific implementation depends on the transport protocol.
	 *
	 * @param mixed $request The request object (type varies by transport).
	 * @return mixed Transport-specific response format.
	 */
	public function handle_request( mixed $request ): mixed;

	/**
	 * Register transport-specific routes.
	 *
	 * Called during WordPress REST API initialization to register
	 * endpoints for this transport.
	 */
	public function register_routes(): void;
}
