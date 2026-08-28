<?php
/**
 * Immutable exact-revision MCP request context.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\McpSchema\Schema;

/**
 * Carries revision, peer, and transport state for one request.
 *
 * @since n.e.x.t
 */
final class McpRequestContext {

	/** @var string */
	private string $revision;

	/** @var \WP\McpSchema\Schema */
	private Schema $schema;

	/** @var \stdClass */
	private \stdClass $client_capabilities;

	/** @var \stdClass|null */
	private ?\stdClass $client_info;

	/** @var string */
	private string $transport;

	/** @var array<string, mixed> */
	private array $transport_metadata;

	/**
	 * Constructor.
	 *
	 * @param string               $revision           Exact MCP revision.
	 * @param \WP\McpSchema\Schema $schema             Selected schema.
	 * @param \stdClass            $client_capabilities Client capabilities.
	 * @param \stdClass|null       $client_info         Client identity when supplied.
	 * @param string               $transport           Transport name.
	 * @param array<string, mixed> $transport_metadata  Transport-owned metadata.
	 * @since n.e.x.t
	 */
	public function __construct(
		string $revision,
		Schema $schema,
		\stdClass $client_capabilities,
		?\stdClass $client_info,
		string $transport,
		array $transport_metadata = array()
	) {
		if ( $revision !== $schema->version() ) {
			throw new \InvalidArgumentException( 'Request revision must match the selected schema.' );
		}

		$this->revision            = $revision;
		$this->schema              = $schema;
		$this->client_capabilities = self::copy_object( $client_capabilities );
		$this->client_info         = null === $client_info ? null : self::copy_object( $client_info );
		$this->transport           = $transport;
		$this->transport_metadata  = self::copy_array( $transport_metadata );
	}

	/**
	 * Get the exact revision.
	 *
	 * @since n.e.x.t
	 */
	public function revision(): string {
		return $this->revision;
	}

	/**
	 * Get the selected schema.
	 *
	 * @since n.e.x.t
	 */
	public function schema(): Schema {
		return $this->schema;
	}

	/**
	 * Get a defensive copy of client capabilities.
	 *
	 * @since n.e.x.t
	 */
	public function client_capabilities(): \stdClass {
		return self::copy_object( $this->client_capabilities );
	}

	/**
	 * Get a defensive copy of client identity.
	 *
	 * @since n.e.x.t
	 */
	public function client_info(): ?\stdClass {
		return null === $this->client_info ? null : self::copy_object( $this->client_info );
	}

	/**
	 * Get the transport name.
	 *
	 * @since n.e.x.t
	 */
	public function transport(): string {
		return $this->transport;
	}

	/**
	 * Get transport metadata.
	 *
	 * @return array<string, mixed>
	 * @since n.e.x.t
	 */
	public function transport_metadata(): array {
		return self::copy_array( $this->transport_metadata );
	}

	/** Deep-copy one array while preserving its key identity. */
	private static function copy_array( array $value ): array {
		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = self::copy_value( $item );
		}

		return $copy;
	}

	/** Deep-copy one JSON object. */
	private static function copy_object( \stdClass $source ): \stdClass {
		$copy = new \stdClass();
		foreach ( get_object_vars( $source ) as $key => $item ) {
			$copy->{$key} = self::copy_value( $item );
		}

		return $copy;
	}

	/**
	 * Deep-copy mutable JSON-compatible values while preserving lists and objects.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function copy_value( $value ) {
		if ( $value instanceof \stdClass ) {
			$copy = new \stdClass();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$copy->{$key} = self::copy_value( $item );
			}

			return $copy;
		}

		if ( is_array( $value ) ) {
			$copy = array();
			foreach ( $value as $key => $item ) {
				$copy[ $key ] = self::copy_value( $item );
			}

			return $copy;
		}

		return $value;
	}
}
