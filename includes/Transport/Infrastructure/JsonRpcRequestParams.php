<?php
/**
 * Lossless transport request params carrier.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Keeps params presence and raw JSON object/list identity until validation.
 *
 * @since n.e.x.t
 */
final class JsonRpcRequestParams {

	/** @var mixed */
	private $value;

	private bool $present;

	/** @param mixed $value Raw decoded params value. */
	public function __construct( bool $present, $value = null ) {
		$this->present = $present;
		$this->value   = $value;
	}

	public function is_present(): bool {
		return $this->present;
	}

	/** @return mixed */
	public function get_value() {
		return $this->value;
	}
}
