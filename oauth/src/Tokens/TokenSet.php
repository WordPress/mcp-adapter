<?php
/**
 * Issued OAuth credentials.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Token endpoint response independent of the token format.
 */
final class TokenSet {

	/**
	 * Access token.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Token type.
	 *
	 * @var string
	 */
	private $token_type;

	/**
	 * Refresh token, when one was issued.
	 *
	 * @var string|null
	 */
	private $refresh_token;

	/**
	 * Access token lifetime in seconds.
	 *
	 * @var int|null
	 */
	private $expires_in;

	/**
	 * Granted scope string.
	 *
	 * @var string|null
	 */
	private $scope;

	/**
	 * Constructor.
	 *
	 * @param string      $access_token  Access token.
	 * @param string      $token_type    Token type.
	 * @param string|null $refresh_token Refresh token.
	 * @param int|null    $expires_in    Access token lifetime in seconds.
	 * @param string|null $scope         Granted scope string.
	 */
	public function __construct( string $access_token, string $token_type = 'Bearer', ?string $refresh_token = null, ?int $expires_in = null, ?string $scope = null ) {
		$this->access_token  = $access_token;
		$this->token_type    = $token_type;
		$this->refresh_token = $refresh_token;
		$this->expires_in    = $expires_in;
		$this->scope         = $scope;
	}

	/**
	 * Builds the successful token endpoint response.
	 *
	 * @return array<string, int|string>
	 */
	public function to_response_data(): array {
		$data = array(
			'access_token' => $this->access_token,
			'token_type'   => $this->token_type,
		);

		if ( null !== $this->refresh_token ) {
			$data['refresh_token'] = $this->refresh_token;
		}
		if ( null !== $this->expires_in ) {
			$data['expires_in'] = $this->expires_in;
		}
		if ( null !== $this->scope ) {
			$data['scope'] = $this->scope;
		}

		return $data;
	}
}
