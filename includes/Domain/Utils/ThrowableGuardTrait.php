<?php
/**
 * Throwable guard for component execution and permission callbacks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use WP_Error;

/**
 * Converts a throwable raised by a callback into a WP_Error.
 *
 * Components run ability, builder, and direct callbacks through this guard so a
 * failure inside user code becomes a recoverable WP_Error at the component
 * boundary instead of escaping into the handler.
 *
 * @internal
 *
 * @since n.e.x.t
 */
trait ThrowableGuardTrait {

	/**
	 * Run a callback and turn any throwable into a WP_Error.
	 *
	 * @template T
	 * @param string        $error_code Error code for the WP_Error when the callback throws.
	 * @param callable(): T $callback   Callback to run.
	 * @return T|\WP_Error The callback result, or a WP_Error carrying the message and class of the throwable.
	 */
	private static function guard( string $error_code, callable $callback ) {
		try {
			return $callback();
		} catch ( \Throwable $throwable ) {
			return new WP_Error(
				$error_code,
				$throwable->getMessage(),
				array( 'error_type' => get_class( $throwable ) )
			);
		}
	}
}
