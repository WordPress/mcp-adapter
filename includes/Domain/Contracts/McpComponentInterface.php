<?php
/**
 * Contract for MCP component wrapper classes (tools, resources, prompts).
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Contracts;

use WP\McpSchema\Common\AbstractDataTransferObject;

/**
 * Interface McpComponentInterface.
 *
 * Wrapper classes implementing this interface encapsulate:
 * - a clean protocol DTO (Tool/Resource/Prompt) that is safe to expose to MCP clients, and
 * - MCP Adapter internal metadata and execution wiring (ability-backed OR direct-callable).
 *
 * This keeps protocol DTOs free of internal adapter fields, while still
 * providing a uniform execution and permission-check surface for handlers.
 *
 * @internal
 *
 * @since n.e.x.t
 */
interface McpComponentInterface {

	/**
	 * Get the clean protocol DTO for MCP responses.
	 *
	 * @since n.e.x.t
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject Protocol-only DTO.
	 */
	public function get_component(): AbstractDataTransferObject;

	/**
	 * Get the human-readable component name.
	 *
	 * @since n.e.x.t
	 *
	 * @return string Display name.
	 */
	public function get_name(): string;

	/**
	 * Execute the component using the configured strategy.
	 *
	 * Implementations MUST execute via either:
	 * - an attached WordPress ability, or
	 * - a direct callable handler (for non-ability registrations).
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $arguments Component arguments (typically an associative array).
	 *
	 * @return mixed Execution result.
	 */
	public function execute( $arguments );

	/**
	 * Check whether execution is permitted for the current request.
	 *
	 * Implementations MUST check permissions via either:
	 * - the attached WordPress ability, or
	 * - a direct permission callback (for non-ability registrations).
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $arguments Component arguments (typically an associative array).
	 *
	 * @return bool|\WP_Error True when permitted, false or WP_Error otherwise.
	 */
	public function check_permission( $arguments );

	/**
	 * Get MCP Adapter internal metadata for this component.
	 *
	 * This metadata MUST NOT be stored on protocol DTOs and MUST NOT be exposed to MCP clients.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> Internal metadata.
	 */
	public function get_adapter_meta(): array;

	/**
	 * Get observability context tags for logging/metrics.
	 *
	 * This replaces legacy approaches that derived observability tags from DTO `_meta`.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> Observability tags (component_type, source, etc.).
	 */
	public function get_observability_context(): array;
}
