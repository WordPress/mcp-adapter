<?php
/**
 * Exact MCP schema provider.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\McpSchema\Schema;
use WP\McpSchema\Schemas;

/**
 * Owns one immutable provider for every schema used by an MCP server.
 *
 * @since n.e.x.t
 */
final class McpSchemaProvider {

	/**
	 * Schema package provider.
	 *
	 * @var \WP\McpSchema\Schemas
	 */
	private Schemas $schemas;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 */
	public function __construct() {
		$this->schemas = Schemas::create();
	}

	/**
	 * Select one exact supported revision.
	 *
	 * @param string $revision Exact MCP revision.
	 * @since n.e.x.t
	 */
	public function for_revision( string $revision ): Schema {
		return $this->schemas->forVersion( $revision );
	}

	/**
	 * Return the complete exact supported set.
	 *
	 * @return list<string>
	 * @since n.e.x.t
	 */
	public function supported_revisions(): array {
		return array_values( Schemas::supportedVersions() );
	}
}
