<?php
/**
 * Additive component result contract for complete and input-required outcomes.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Continuation;

/**
 * Allows direct component callbacks to request stateless modern continuation.
 */
final class McpExecutionResult {

	/** @var bool */
	private bool $input_required;

	/** @var mixed */
	private $value;

	/** @var array<string, mixed> */
	private array $input_requests;

	/** @var string|null */
	private ?string $request_state;

	/**
	 * @param bool                 $input_required Whether more client input is required.
	 * @param mixed                $value Completed callback value.
	 * @param array<string, mixed> $input_requests Embedded MCP client requests.
	 * @param string|null          $request_state Opaque callback-owned state.
	 */
	private function __construct( bool $input_required, $value, array $input_requests, ?string $request_state ) {
		$this->input_required = $input_required;
		$this->value          = $value;
		$this->input_requests = $input_requests;
		$this->request_state  = $request_state;
	}

	/** @param mixed $value */
	public static function complete( $value ): self {
		return new self( false, $value, array(), null );
	}

	/**
	 * @param array<string, mixed> $input_requests Embedded requests keyed by request ID.
	 * @param string|null          $request_state Opaque state returned on retry.
	 */
	public static function input_required( array $input_requests = array(), ?string $request_state = null ): self {
		if ( empty( $input_requests ) && null === $request_state ) {
			throw new \InvalidArgumentException( 'Input-required results need input requests or request state.' );
		}

		return new self( true, null, $input_requests, $request_state );
	}

	public function is_input_required(): bool {
		return $this->input_required;
	}

	/** @return mixed */
	public function get_value() {
		return $this->value;
	}

	/** @return array<string, mixed> */
	public function get_input_requests(): array {
		return $this->input_requests;
	}

	public function get_request_state(): ?string {
		return $this->request_state;
	}
}
