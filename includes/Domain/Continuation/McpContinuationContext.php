<?php
/**
 * Stateless continuation input passed to opt-in component callbacks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Continuation;

/**
 * Carries client responses and opaque request state for a resumed modern request.
 */
final class McpContinuationContext {

	/** @var array<string, mixed> */
	private array $input_responses;

	/** @var string|null */
	private ?string $request_state;

	/** @var array<string, mixed> */
	private array $client_capabilities;

	/**
	 * @param array<string, mixed> $input_responses Responses keyed by input request ID.
	 * @param string|null          $request_state Opaque callback-owned state.
	 * @param array<string, mixed> $client_capabilities Capabilities declared for this request.
	 */
	public function __construct( array $input_responses = array(), ?string $request_state = null, array $client_capabilities = array() ) {
		$this->input_responses     = $input_responses;
		$this->request_state       = $request_state;
		$this->client_capabilities = $client_capabilities;
	}

	/** @return array<string, mixed> */
	public function get_input_responses(): array {
		return $this->input_responses;
	}

	public function get_request_state(): ?string {
		return $this->request_state;
	}

	/** @return array<string, mixed> */
	public function get_client_capabilities(): array {
		return $this->client_capabilities;
	}

	public function is_resumed(): bool {
		return ! empty( $this->input_responses ) || null !== $this->request_state;
	}
}
