<?php
/**
 * Task storage in the options table.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tasks;

use WP\MCP\Tasks\Contracts\McpTaskStoreInterface;

/**
 * Stores each task as one non-autoloaded option.
 *
 * Options are durable, unlike transients under an object cache, and are
 * scoped to the current site on multisite.
 *
 * @since 0.8.0
 */
final class OptionsTaskStore implements McpTaskStoreInterface {

	/**
	 * Option name prefix, followed by the task ID.
	 *
	 * @var string
	 */
	private const OPTION_PREFIX = 'mcp_adapter_task_';

	/**
	 * {@inheritDoc}
	 */
	public function create( array $task ): bool {
		$task_id = $task['id'] ?? null;
		if ( ! is_string( $task_id ) || '' === $task_id ) {
			return false;
		}

		return add_option( self::OPTION_PREFIX . $task_id, $task, '', false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $task_id ): ?array {
		$stored = get_option( self::OPTION_PREFIX . $task_id, null );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		$task = array();
		foreach ( $stored as $field => $value ) {
			$task[ (string) $field ] = $value;
		}

		return $task;
	}

	/**
	 * {@inheritDoc}
	 */
	public function transition( string $task_id, array $expected, array $changes ): ?array {
		$database = $GLOBALS['wpdb'] ?? null;
		if ( ! $database instanceof \wpdb ) {
			return null;
		}

		$name = self::OPTION_PREFIX . $task_id;

		// Another process may have written since this one cached the option.
		wp_cache_delete( $name, 'options' );
		$current = $this->get( $task_id );
		if ( null === $current || ! in_array( $current['status'] ?? null, $expected, true ) ) {
			return null;
		}

		$updated = array_merge( $current, $changes );

		// The options API cannot write conditionally. Matching on the previous
		// value makes this a compare-and-swap, so only one process wins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above; the cache is cleared below.
		$rows = $database->update(
			$database->options,
			array( 'option_value' => maybe_serialize( $updated ) ),
			array(
				'option_name'  => $name,
				'option_value' => maybe_serialize( $current ),
			)
		);
		wp_cache_delete( $name, 'options' );

		return 1 === $rows ? $updated : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $task_id ): void {
		delete_option( self::OPTION_PREFIX . $task_id );
	}
}
