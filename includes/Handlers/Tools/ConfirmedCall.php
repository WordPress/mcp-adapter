<?php
/**
 * A confirmed call that may run now.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

/**
 * Returned by ToolCallConfirmation::check() when a confirmation was used and the call
 * may run. ToolsHandler hands its result back through ToolCallConfirmation::remember()
 * so that a retry of the same call gets the same answer.
 *
 * @internal
 * @since n.e.x.t
 */
final class ConfirmedCall {

	/** @var string */
	private string $id;

	/**
	 * @param string $id Key of the used-confirmation record.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * Key of the used-confirmation record.
	 */
	public function id(): string {
		return $this->id;
	}
}
