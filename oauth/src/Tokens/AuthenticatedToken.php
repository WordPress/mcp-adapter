<?php
/**
 * Validated token context.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Identity and backend state resolved from a valid token.
 */
final class AuthenticatedToken {

	/**
	 * Authenticated user.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * Client identifier.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Backend-specific credential identifier.
	 *
	 * @var string
	 */
	private $credential_id;

	/**
	 * Backend-specific credential record.
	 *
	 * @var array<string, mixed>
	 */
	private $credential;

	/**
	 * Constructor.
	 *
	 * @param WP_User              $user          Authenticated user.
	 * @param string               $client_id     Client identifier.
	 * @param string               $credential_id Backend credential identifier.
	 * @param array<string, mixed> $credential    Backend credential record.
	 */
	public function __construct( WP_User $user, string $client_id, string $credential_id, array $credential = array() ) {
		$this->user          = $user;
		$this->client_id     = $client_id;
		$this->credential_id = $credential_id;
		$this->credential    = $credential;
	}

	/**
	 * Gets the authenticated user.
	 */
	public function get_user(): WP_User {
		return $this->user;
	}

	/**
	 * Gets the client identifier.
	 */
	public function get_client_id(): string {
		return $this->client_id;
	}

	/**
	 * Gets the backend credential identifier.
	 */
	public function get_credential_id(): string {
		return $this->credential_id;
	}

	/**
	 * Gets the backend credential record.
	 *
	 * @return array<string, mixed>
	 */
	public function get_credential(): array {
		return $this->credential;
	}
}
