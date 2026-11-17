<?php
/** Pending CIMD site approval. */

declare( strict_types=1 );

namespace WP\OAuth\Clients\Cimd;

use WP\OAuth\Clients\Client;

defined( 'ABSPATH' ) || exit;

/** Immutable verified metadata awaiting administrator approval. */
final class CimdApproval {

	/** @var string */
	private $client_id;

	/** @var array<string, mixed> */
	private $metadata;

	/** @var int */
	private $expires;

	/** @var bool */
	private $trusted;

	/** @var bool */
	private $update;

	/**
	 * Constructor.
	 *
	 * @param string               $client_id Exact metadata document URL.
	 * @param array<string, mixed> $metadata  Verified metadata document.
	 * @param int                  $expires   Metadata cache expiry timestamp.
	 * @param bool                 $trusted   Whether site policy trusts the publisher.
	 * @param bool                 $update    Whether this changes an existing registration.
	 */
	public function __construct( string $client_id, array $metadata, int $expires, bool $trusted, bool $update ) {
		$this->client_id = $client_id;
		$this->metadata  = $metadata;
		$this->expires   = $expires;
		$this->trusted   = $trusted;
		$this->update    = $update;
	}

	/**
	 * Gets the exact client identifier.
	 *
	 * @return string Client metadata document URL.
	 */
	public function get_client_id(): string {
		return $this->client_id;
	}

	/**
	 * Gets the verified metadata.
	 *
	 * @return array<string, mixed> Verified metadata document.
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * Gets the metadata cache expiry.
	 *
	 * @return int Expiry timestamp.
	 */
	public function get_expires(): int {
		return $this->expires;
	}

	/**
	 * Whether site policy trusts the publisher.
	 *
	 * @return bool Whether the publisher is trusted.
	 */
	public function is_trusted(): bool {
		return $this->trusted;
	}

	/**
	 * Whether approval replaces security metadata for an existing client.
	 *
	 * @return bool Whether this is an update.
	 */
	public function is_update(): bool {
		return $this->update;
	}

	/**
	 * Builds an unpersisted client for request validation and display.
	 *
	 * @return Client Preview client.
	 */
	public function to_client(): Client {
		return new Client(
			$this->client_id,
			(string) $this->metadata['client_name'],
			array_map( 'strval', $this->metadata['redirect_uris'] ),
			null,
			time(),
			$this->metadata,
			'',
			$this->expires,
			$this->trusted
		);
	}

	/**
	 * Converts the approval to transient-safe data.
	 *
	 * @return array<string, mixed> Serializable approval data.
	 */
	public function to_array(): array {
		return array(
			'client_id' => $this->client_id,
			'metadata'  => $this->metadata,
			'expires'   => $this->expires,
			'trusted'   => $this->trusted,
			'update'    => $this->update,
		);
	}

	/**
	 * Rebuilds an approval from trusted server-side transient data.
	 *
	 * @param array<string, mixed> $data Stored approval data.
	 * @return self|null Approval, or null when the data is malformed.
	 */
	public static function from_array( array $data ): ?self {
		if ( ! isset( $data['client_id'], $data['metadata'], $data['expires'] ) || ! is_string( $data['client_id'] ) || ! is_array( $data['metadata'] ) ) {
			return null;
		}

		return new self( $data['client_id'], $data['metadata'], (int) $data['expires'], (bool) ( $data['trusted'] ?? false ), (bool) ( $data['update'] ?? false ) );
	}
}
