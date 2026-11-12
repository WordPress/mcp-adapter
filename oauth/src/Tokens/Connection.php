<?php
/**
 * Stored OAuth connection.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Token-format-neutral connection state.
 */
final class Connection {

	/** @var int */
	private $user_id;
	/** @var string */
	private $id;
	/** @var array<string, mixed> */
	private $data;

	/**
	 * @param int                  $user_id User ID.
	 * @param string               $id      Connection ID.
	 * @param array<string, mixed> $data    Connection state.
	 */
	public function __construct( int $user_id, string $id, array $data ) {
		$this->user_id = $user_id;
		$this->id      = $id;
		$this->data    = $data;
	}

	public function get_user_id(): int {
		return $this->user_id;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_client_id(): string {
		return (string) ( $this->data['client_id'] ?? '' );
	}

	public function get_resource(): string {
		return (string) ( $this->data['resource'] ?? '' );
	}

	/** @return array<string, mixed> */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Returns a copy carrying replacement state.
	 *
	 * @param array<string, mixed> $updates State updates.
	 */
	public function with( array $updates ): self {
		return new self( $this->user_id, $this->id, array_merge( $this->data, $updates ) );
	}
}
