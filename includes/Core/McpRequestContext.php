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
 * @since 0.7.0
 */
final class McpRequestContext {

	/**
	 * Selected schema used for request and response records.
	 *
	 * @var \WP\McpSchema\Schema
	 */
	private Schema $schema;

	/**
	 * Negotiated identifier, which may differ from the backing schema revision.
	 *
	 * @var string
	 */
	private string $protocol_version;

	/**
	 * Snapshot of the client capability object.
	 *
	 * @var \stdClass
	 */
	private \stdClass $client_capabilities;

	/**
	 * Snapshot of client identification metadata, when supplied.
	 *
	 * @var \stdClass|null
	 */
	private ?\stdClass $client_info;

	/**
	 * Name of the transport handling this request.
	 *
	 * @var string
	 */
	private string $transport;

	/**
	 * Transport-owned request metadata copied with JSON object/list identity preserved.
	 *
	 * @var array<string, mixed>
	 */
	private array $transport_metadata;

	/**
	 * Constructor.
	 *
	 * @param \WP\McpSchema\Schema $schema             Selected schema.
	 * @param \stdClass            $client_capabilities Client capabilities.
	 * @param \stdClass|null       $client_info         Client identity when supplied.
	 * @param string               $transport           Transport name.
	 * @param array<string, mixed> $transport_metadata  Transport-owned metadata.
	 * @param string|null          $protocol_version    Negotiated protocol version when it differs from the schema revision.
	 * @since 0.7.0
	 */
	public function __construct(
		Schema $schema,
		\stdClass $client_capabilities,
		?\stdClass $client_info,
		string $transport,
		array $transport_metadata = array(),
		?string $protocol_version = null
	) {
		$this->schema              = $schema;
		$this->protocol_version    = $protocol_version ?? $schema->version();
		$this->client_capabilities = self::copy_object( $client_capabilities );
		$this->client_info         = null === $client_info ? null : self::copy_object( $client_info );
		$this->transport           = $transport;
		$this->transport_metadata  = self::copy_array( $transport_metadata );
	}

	/**
	 * Get the exact revision.
	 *
	 * @since 0.7.0
	 */
	public function revision(): string {
		return $this->schema->version();
	}

	/**
	 * Get the negotiated protocol version.
	 *
	 * Equals {@see revision()} unless a legacy identifier is served through the
	 * 2025-11-25 schema, in which case this is the identifier the peer negotiated
	 * and expects on the wire.
	 *
	 * @since 0.7.0
	 */
	public function protocol_version(): string {
		return $this->protocol_version;
	}

	/**
	 * Get the selected schema.
	 *
	 * @since 0.7.0
	 */
	public function schema(): Schema {
		return $this->schema;
	}

	/**
	 * Get a defensive copy of client capabilities.
	 *
	 * @since 0.7.0
	 */
	public function client_capabilities(): \stdClass {
		return self::copy_object( $this->client_capabilities );
	}

	/**
	 * Get a defensive copy of client identity.
	 *
	 * @since 0.7.0
	 */
	public function client_info(): ?\stdClass {
		return null === $this->client_info ? null : self::copy_object( $this->client_info );
	}

	/**
	 * Get the transport name.
	 *
	 * @since 0.7.0
	 */
	public function transport(): string {
		return $this->transport;
	}

	/**
	 * Get transport metadata.
	 *
	 * @return array<string, mixed>
	 * @since 0.7.0
	 */
	public function transport_metadata(): array {
		return self::copy_array( $this->transport_metadata );
	}

	/**
	 * Copy nested JSON values while preserving array keys.
	 *
	 * @param array<mixed> $value Source array.
	 *
	 * @return array<mixed> Copied array; non-JSON object instances retain their identity.
	 */
	private static function copy_array( array $value ): array {
		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = self::copy_value( $item );
		}

		return $copy;
	}

	/**
	 * Copy the properties and nested JSON values of an object.
	 *
	 * @param \stdClass $source Source JSON object.
	 *
	 * @return \stdClass Independent JSON object copy.
	 */
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
