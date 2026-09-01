<?php
/**
 * Contract for MCP component classes (tools, resources, prompts).
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Contracts;

use WP\McpSchema\Record;
use WP\McpSchema\Schema;

/**
 * Interface McpComponentInterface.
 *
 * Classes implementing this interface encapsulate:
 * - revision-neutral protocol configuration projected through an exact schema, and
 * - MCP Adapter internal metadata and execution wiring (ability-backed OR direct-callable).
 *
 * This keeps protocol records free of internal adapter fields, while still
 * providing a uniform execution and permission-check surface for handlers.
 *
 * @internal
 *
 * @since 0.5.0
 */
interface McpComponentInterface {

	/**
	 * Get the validated protocol record for one exact revision.
	 *
	 * The record is used only for protocol serialization and MUST NOT include
	 * internal adapter metadata or execution wiring.
	 *
	 * @param \WP\McpSchema\Schema $schema Selected exact schema.
	 * @return \WP\McpSchema\Record Protocol-only record.
	 * @since n.e.x.t
	 *
	 */
	public function get_protocol_record( Schema $schema ): Record;

	/**
	 * Check whether the component has a valid projection for one revision.
	 *
	 * @param \WP\McpSchema\Schema $schema Selected exact schema.
	 * @return bool
	 * @since n.e.x.t
	 */
	public function is_available_for( Schema $schema ): bool;

	/**
	 * Get the cached projection failure for diagnostics.
	 *
	 * @param string $revision Exact revision.
	 * @return \Throwable|null
	 * @since n.e.x.t
	 */
	public function get_projection_error( string $revision ): ?\Throwable;

	/**
	 * Execute the component using the configured strategy.
	 *
	 * Implementations MUST execute via either:
	 * - an attached WordPress ability, or
	 * - a direct callable handler (for non-ability registrations).
	 *
	 * @param mixed $arguments Component arguments (typically an associative array).
	 *
	 * @return mixed Execution result.
	 * @since 0.5.0
	 *
	 */
	public function execute( $arguments );

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
	 * This metadata MUST NOT be stored on protocol records and MUST NOT be exposed to MCP clients.
	 *
	 * @return array<string, mixed> Internal metadata.
	 * @since 0.5.0
	 *
	 */
	public function get_adapter_meta(): array;

	/**
	 * Get observability context tags for logging/metrics.
	 *
	 * @return array<string, mixed> Observability tags (component_type, source, etc.).
	 * @since 0.5.0
	 *
	 */
	public function get_observability_context(): array;
}
