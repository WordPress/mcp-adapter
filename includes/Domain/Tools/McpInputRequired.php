<?php
/**
 * Logical request for another round of client input.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

/**
 * Carries input requests and optional opaque state owned by the tool author.
 *
 * @since n.e.x.t
 */
final class McpInputRequired {

	/** @var array<string, mixed> */
	private array $requests;

	/** @var string|null */
	private ?string $request_state;

	/**
	 * @param array<string, mixed> $input_requests Server-to-client request map.
	 * @param string|null          $request_state  Opaque state to echo on retry; protection is the author's responsibility.
	 *
	 * @throws \InvalidArgumentException If neither input requests nor request state is supplied.
	 *
	 * @since n.e.x.t
	 */
	public function __construct( array $input_requests = array(), ?string $request_state = null ) {
		if ( array() === $input_requests && null === $request_state ) {
			throw new \InvalidArgumentException( 'Input-required results need input requests or request state.' );
		}
		$this->requests      = $input_requests;
		$this->request_state = $request_state;
	}

	/**
	 * Get the server-to-client request map.
	 *
	 * @return array<string, mixed>
	 *
	 * @since n.e.x.t
	 */
	public function input_requests(): array {
		return $this->requests;
	}

	/**
	 * Get author-supplied state without encoding or interpreting it.
	 *
	 * @since n.e.x.t
	 */
	public function request_state(): ?string {
		return $this->request_state;
	}
}
