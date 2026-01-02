<?php
/**
 * Interface for objects that support array transformation.
 *
 * @package WP\MCP\Core\Contracts
 * @since   n.e.x.t
 */

declare( strict_types=1 );

namespace WP\MCP\Core\Contracts;

/**
 * Interface for Data Transfer Objects that can be converted to/from arrays.
 *
 * This interface provides a consistent pattern for DTO serialization,
 * enabling easy conversion between PHP objects and array representations
 * for JSON encoding, configuration storage, and data transfer.
 *
 * @since n.e.x.t
 */
interface ArrayTransformableInterface {

	/**
	 * Converts the object to an array representation.
	 *
	 * The returned array should contain all public/significant properties
	 * with null values typically omitted for cleaner output.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> The array representation of this object.
	 */
	public function to_array(): array;

	/**
	 * Creates an instance from array data.
	 *
	 * Implementations should validate required fields and throw
	 * InvalidArgumentException for missing or invalid data.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<string, mixed> $data The array data to create from.
	 * @return static The created instance.
	 *
	 * @throws \InvalidArgumentException If required fields are missing or invalid.
	 */
	public static function from_array( array $data );
}
