<?php
/**
 * Revision projection support for neutral MCP components.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use WP\McpSchema\Record;
use WP\McpSchema\Schema;

/**
 * Caches successful and failed immutable schema projections by exact revision.
 *
 * @since 0.7.0
 */
trait RevisionProjectionTrait {

	/**
	 * Revision-neutral fields used to construct protocol records.
	 *
	 * @var array<string, mixed>
	 */
	private array $protocol_data = array();

	/**
	 * Successful protocol projections cached by schema revision.
	 *
	 * @var array<string, \WP\McpSchema\Record>
	 */
	private array $protocol_records = array();

	/**
	 * Projection failures cached by schema revision.
	 *
	 * @var array<string, \Throwable>
	 */
	private array $projection_errors = array();

	/**
	 * Store revision-neutral component data.
	 *
	 * @param array<string, mixed> $protocol_data Neutral component data.
	 */
	private function initialize_protocol_data( array $protocol_data ): void {
		$this->protocol_data = $protocol_data;
	}

	/**
	 * Return a defensive neutral projection source.
	 *
	 * @return array<string, mixed>
	 */
	private function protocol_data(): array {
		return $this->protocol_data;
	}

	/**
	 * Project one record class through one exact schema.
	 *
	 * @template T of \WP\McpSchema\Record
	 * @param \WP\McpSchema\Schema $schema Selected schema.
	 * @param class-string<T> $record_class Generated record class.
	 * @param array<string, mixed> $data Revision-specific projection data.
	 * @return T
	 */
	private function project_record( Schema $schema, string $record_class, array $data ): Record {
		$revision = $schema->version();
		if ( isset( $this->protocol_records[ $revision ] ) ) {
			$record = $this->protocol_records[ $revision ];
			if ( ! $record instanceof $record_class ) {
				throw new \LogicException( 'Cached projection has an unexpected record type.' );
			}

			return $record;
		}

		if ( isset( $this->projection_errors[ $revision ] ) ) {
			throw $this->projection_errors[ $revision ];
		}

		try {
			$record = $schema->fromArray( $record_class, $data );
		} catch ( \Throwable $throwable ) {
			$this->projection_errors[ $revision ] = $throwable;
			throw $throwable;
		}

		if ( ! $record instanceof Record ) {
			throw new \LogicException( 'Schema projection did not produce an MCP record.' );
		}

		$this->protocol_records[ $revision ] = $record;

		return $record;
	}

	/**
	 * Get the clean protocol record for one revision.
	 *
	 * @param \WP\McpSchema\Schema $schema Selected schema.
	 */
	abstract public function get_protocol_record( Schema $schema ): Record;

	/**
	 * Check whether this component projects successfully into the selected schema.
	 *
	 * @since 0.7.0
	 *
	 * @param \WP\McpSchema\Schema $schema Selected revision catalog.
	 *
	 * @return bool Whether a protocol record can be produced.
	 */
	public function is_available_for( Schema $schema ): bool {
		try {
			$this->get_protocol_record( $schema );
		} catch ( \Throwable $throwable ) {
			return false;
		}

		return true;
	}

	/**
	 * Return a cached projection error for diagnostics.
	 *
	 * @param string $revision Exact revision.
	 * @since 0.7.0
	 */
	public function get_projection_error( string $revision ): ?\Throwable {
		return $this->projection_errors[ $revision ] ?? null;
	}

	/**
	 * Cache a projection failure produced by Adapter-owned validation.
	 *
	 * @param string $revision Schema revision that rejected the component.
	 * @param \Throwable $throwable Failure retained for subsequent availability checks.
	 *
	 * @return void
	 */
	private function remember_projection_error( string $revision, \Throwable $throwable ): void {
		$this->projection_errors[ $revision ] = $throwable;
	}
}
