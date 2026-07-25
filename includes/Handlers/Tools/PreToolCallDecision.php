<?php
/**
 * Pre-tool-call middleware decision.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

use WP\McpSchema\Server\Tools\DTO\CallToolResult;

/**
 * Represents an explicit pre-tool-call middleware outcome.
 *
 * @since 0.5.0
 */
final class PreToolCallDecision {
	/**
	 * Execute the tool with arguments.
	 *
	 * @var string
	 */
	private const PROCEED = 'proceed';

	/**
	 * Return a result without executing the tool.
	 *
	 * @var string
	 */
	private const COMPLETE = 'complete';

	/**
	 * Return an error without executing the tool.
	 *
	 * @var string
	 */
	private const REJECT = 'reject';

	/**
	 * The decision type.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The arguments associated with a proceed decision.
	 *
	 * @var array
	 */
	private array $args;

	/**
	 * The result associated with a complete decision.
	 *
	 * @var \WP\McpSchema\Server\Tools\DTO\CallToolResult|null
	 */
	private ?CallToolResult $result;

	/**
	 * The error associated with a reject decision.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $error;

	/**
	 * Constructor.
	 *
	 * @param string                                                $type   The decision type.
	 * @param array                                                 $args   The tool arguments.
	 * @param \WP\McpSchema\Server\Tools\DTO\CallToolResult|null $result The completed result.
	 * @param \WP_Error|null                                       $error  The rejection error.
	 */
	private function __construct( string $type, array $args = array(), ?CallToolResult $result = null, ?\WP_Error $error = null ) {
		$this->type   = $type;
		$this->args   = $args;
		$this->result = $result;
		$this->error  = $error;
	}

	/**
	 * Continue execution with the supplied arguments.
	 *
	 * @since 0.5.0
	 *
	 * @param array $args Tool arguments.
	 *
	 * @return self
	 */
	public static function proceed( array $args ): self {
		return new self( self::PROCEED, $args );
	}

	/**
	 * Complete the current call without executing the tool.
	 *
	 * @since 0.5.0
	 *
	 * @param \WP\McpSchema\Server\Tools\DTO\CallToolResult $result The completed call result.
	 *
	 * @return self
	 */
	public static function complete( CallToolResult $result ): self {
		return new self( self::COMPLETE, array(), $result );
	}

	/**
	 * Reject the current call without executing the tool.
	 *
	 * @since 0.5.0
	 *
	 * @param \WP_Error $error The rejection error.
	 *
	 * @return self
	 */
	public static function reject( \WP_Error $error ): self {
		return new self( self::REJECT, array(), null, $error );
	}

	/**
	 * Whether this decision continues execution.
	 *
	 * @return bool
	 */
	public function should_proceed(): bool {
		return self::PROCEED === $this->type;
	}

	/**
	 * Whether this decision completes the call.
	 *
	 * @return bool
	 */
	public function should_complete(): bool {
		return self::COMPLETE === $this->type;
	}

	/**
	 * Get the tool arguments for a proceed decision.
	 *
	 * @return array
	 */
	public function get_args(): array {
		return $this->args;
	}

	/**
	 * Get the result for a complete decision.
	 *
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult
	 */
	public function get_result(): CallToolResult {
		if ( null === $this->result ) {
			throw new \LogicException( 'A complete decision must include a result.' );
		}

		return $this->result;
	}

	/**
	 * Get the error for a reject decision.
	 *
	 * @return \WP_Error
	 */
	public function get_error(): \WP_Error {
		if ( null === $this->error ) {
			throw new \LogicException( 'A reject decision must include an error.' );
		}

		return $this->error;
	}
}
