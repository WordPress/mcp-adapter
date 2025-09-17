<?php
/**
 * Interface for MCP transport protocols.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Contracts;

use WP\MCP\Transport\Infrastructure\McpTransportContext;

/**
 * Base interface for MCP transport protocols.
 *
 * This interface defines the core contract for all MCP transport implementations,
 * providing common functionality for initialization and route registration.
 * Specific transport protocols should extend this interface with their own
 * request handling methods.
 */
interface McpTransportInterface {

	/**
	 * Initialize the transport with provided context.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $context Dependency injection container.
	 */
	public function __construct( McpTransportContext $context );

	/**
	 * Register transport-specific routes.
	 *
	 * Called during WordPress REST API initialization to register
	 * endpoints for this transport.
	 */
	public function register_routes(): void;
}
