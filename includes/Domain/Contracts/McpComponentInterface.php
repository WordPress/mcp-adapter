<?php
/**
 * Contract for MCP component classes (tools, resources, prompts).
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Contracts;

use WP\MCP\Domain\Continuation\McpContinuationContext;

/**
 * Interface McpComponentInterface.
 *
 * Classes implementing this interface encapsulate:
 * - clean, revision-neutral protocol data that is safe to expose to MCP clients, and
 * - MCP Adapter internal metadata and execution wiring (ability-backed OR direct-callable).
 *
 * This keeps protocol data free of internal adapter fields, while still
 * providing a uniform execution and permission-check surface for handlers.
 *
 * @internal
 *
 * @since 0.5.0
 */
interface McpComponentInterface {

	/**
	 * Get clean, revision-neutral protocol data for MCP responses.
	 *
	 * This data is used only for protocol serialization and MUST NOT include
	 * internal adapter metadata or execution wiring.
	 *
	 * @return array<string, mixed> Protocol-only data.
	 * @since 0.5.0
	 *
	 */
	public function get_protocol_data(): array;

	/**
	 * Execute the component using the configured strategy.
	 *
	 * Implementations MUST execute via either:
	 * - an attached WordPress ability, or
	 * - a direct callable handler (for non-ability registrations).
	 *
	 * @param mixed $arguments Component arguments (typically an associative array).
	 * @param \WP\MCP\Domain\Continuation\McpContinuationContext|null $continuation Optional stateless continuation data.
	 *
	 * @return mixed Execution result.
	 * @since 0.5.0
	 *
	 */
	public function execute( $arguments, ?McpContinuationContext $continuation = null );

	/**
	 * Check whether execution is permitted for the current request.
	 *
	 * Implementations MUST check permissions via either:
	 * - the attached WordPress ability, or
	 * - a direct permission callback (for non-ability registrations).
	 *
	 * @param mixed $arguments Component arguments (typically an associative array).
	 *
	 * @return bool|\WP_Error True when permitted, false or WP_Error otherwise.
	 * @since 0.5.0
	 *
	 */
	public function check_permission( $arguments );

	/**
	 * Get MCP Adapter internal metadata for this component.
	 *
	 * This metadata MUST NOT be stored in protocol data and MUST NOT be exposed to MCP clients.
	 *
	 * @return array<string, mixed> Internal metadata.
	 * @since 0.5.0
	 *
	 */
	public function get_adapter_meta(): array;

	/**
	 * Get observability context tags for logging/metrics.
	 *
	 * This replaces legacy approaches that derived observability tags from protocol `_meta`.
	 *
	 * @return array<string, mixed> Observability tags (component_type, source, etc.).
	 * @since 0.5.0
	 *
	 */
	public function get_observability_context(): array;
}
