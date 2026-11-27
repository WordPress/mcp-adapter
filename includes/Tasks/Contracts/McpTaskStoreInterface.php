<?php
/**
 * Storage contract for MCP tasks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tasks\Contracts;

/**
 * Persists task state between the request that creates a task, the background
 * run and the requests that poll for its result.
 *
 * A task is a flat array of scalars and arrays, see {@see \WP\MCP\Tasks\McpTaskManager::create()}.
 *
 * @since 0.8.0
 */
interface McpTaskStoreInterface {

	/**
	 * Store a new task.
	 *
	 * The task must be readable through get() as soon as this returns true.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $task Task data including its `id`.
	 *
	 * @return bool False when the task could not be stored or the ID is taken.
	 */
	public function create( array $task ): bool;

	/**
	 * Read a task.
	 *
	 * @since 0.8.0
	 *
	 * @param string $task_id Task ID.
	 *
	 * @return array<string, mixed>|null Task data, or null when it does not exist.
	 */
	public function get( string $task_id ): ?array;

	/**
	 * Apply changes to a task only while it is in one of the expected statuses.
	 *
	 * The check and the write must be atomic, so two processes cannot both
	 * move the same task out of the same status.
	 *
	 * @since 0.8.0
	 *
	 * @param string               $task_id  Task ID.
	 * @param list<string>         $expected Statuses the task may currently be in.
	 * @param array<string, mixed> $changes  Fields to merge into the task.
	 *
	 * @return array<string, mixed>|null The updated task, or null when nothing was changed.
	 */
	public function transition( string $task_id, array $expected, array $changes ): ?array;

	/**
	 * Delete a task.
	 *
	 * @since 0.8.0
	 *
	 * @param string $task_id Task ID.
	 */
	public function delete( string $task_id ): void;
}
