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
 * @since n.e.x.t
 */
trait RevisionProjectionTrait {

	/** @var array<string, mixed> */
	private array $protocol_data = array();

	/** @var array<string, \WP\McpSchema\Record> */
	private array $protocol_records = array();

	/** @var array<string, \Throwable> */
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
	 * Return a cached projection error for diagnostics.
	 *
	 * @param string $revision Exact revision.
	 * @since n.e.x.t
	 */
	public function get_projection_error( string $revision ): ?\Throwable {
		return $this->projection_errors[ $revision ] ?? null;
	}

	/** Cache an Adapter-owned projection failure. */
	private function remember_projection_error( string $revision, \Throwable $throwable ): void {
		$this->projection_errors[ $revision ] = $throwable;
	}
}
