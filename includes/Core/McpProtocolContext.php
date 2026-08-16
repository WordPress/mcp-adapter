<?php
/**
 * Per-request protocol context: negotiated revision plus its schema catalog.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\McpSchema\Contract\RevisionSchema;
use WP\McpSchema\Schemas;

/**
 * Holds the negotiated protocol revision and the schema catalog for it.
 *
 * The catalog is selected once per revision and reused for every encode in
 * the request. Catalog construction is the expensive part (roughly 1.5 MB for
 * the first revision loaded, 244 KB for a second), so instances are memoised
 * per revision string.
 *
 * This is a Core layer class — no WordPress function calls.
 *
 * @since n.e.x.t
 */
final class McpProtocolContext {

	/**
	 * Memoised instances, keyed by revision string.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = array();

	/**
	 * The negotiated protocol revision.
	 *
	 * @var string
	 */
	private string $revision;

	/**
	 * The schema catalog for the negotiated revision.
	 *
	 * @var \WP\McpSchema\Contract\RevisionSchema
	 */
	private RevisionSchema $catalog;

	/**
	 * Constructor.
	 *
	 * @param string         $revision The negotiated protocol revision.
	 * @param \WP\McpSchema\Contract\RevisionSchema $catalog  The schema catalog for that revision.
	 */
	private function __construct( string $revision, RevisionSchema $catalog ) {
		$this->revision = $revision;
		$this->catalog  = $catalog;
	}

	/**
	 * Returns the context for a protocol revision.
	 *
	 * @param string $revision A revision string, e.g. '2025-11-25'.
	 *
	 * @return self
	 *
	 * @throws \LogicException When the schema package has no catalog for the revision.
	 */
	public static function for_revision( string $revision ): self {
		if ( ! isset( self::$instances[ $revision ] ) ) {
			self::$instances[ $revision ] = new self( $revision, Schemas::revision( $revision ) );
		}

		return self::$instances[ $revision ];
	}

	/**
	 * Returns the context for the newest revision this server supports.
	 *
	 * Used where no negotiation has happened yet, such as registration-time
	 * validation.
	 *
	 * @return self
	 */
	public static function default(): self {
		return self::for_revision( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS[0] );
	}

	/**
	 * The negotiated protocol revision.
	 *
	 * @return string
	 */
	public function revision(): string {
		return $this->revision;
	}

	/**
	 * The schema catalog for the negotiated revision.
	 *
	 * @return \WP\McpSchema\Contract\RevisionSchema
	 */
	public function catalog(): RevisionSchema {
		return $this->catalog;
	}

	/**
	 * Clears the memoised instances.
	 *
	 * Test seam only; production code never needs this.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instances = array();
	}
}
