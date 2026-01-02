<?php
/**
 * Trait for validating required fields in from_array() methods.
 *
 * @package WP\MCP\Core\Traits
 * @since   n.e.x.t
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- All exceptions in this file are for developer diagnostics, not HTML output.

namespace WP\MCP\Core\Traits;

/**
 * Trait for validating required fields in Data Transfer Objects.
 *
 * Provides type-safe value extraction from arrays with clear error messages.
 * Use this trait in DTOs that implement ArrayTransformableInterface.
 *
 * Reports ALL missing fields at once for better developer experience.
 *
 * @since n.e.x.t
 */
trait ValidatesRequiredFieldsTrait {

	/**
	 * Validates that all required fields are present in the data array.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<string, mixed> $data            The input data array.
	 * @param array<int, string>   $required_fields List of required field names.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If any required fields are missing.
	 */
	protected static function assert_required( array $data, array $required_fields ): void {
		$missing = array_filter(
			$required_fields,
			static fn( string $field ): bool => ! array_key_exists( $field, $data )
		);

		if ( count( $missing ) > 0 ) {
			throw new \InvalidArgumentException(
				sprintf(
					'%s: missing required field(s): %s',
					static::class,
					implode( ', ', $missing )
				)
			);
		}
	}

	/**
	 * Asserts a value is a string and returns it.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return string The validated string.
	 *
	 * @throws \InvalidArgumentException If value is not a string.
	 *
	 * @phpstan-assert string $value
	 */
	protected static function as_string( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected string, got %s', gettype( $value ) )
			);
		}
		return $value;
	}

	/**
	 * Returns a value as string or null.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return string|null The validated string or null.
	 *
	 * @throws \InvalidArgumentException If value is not a string or null.
	 */
	protected static function as_string_or_null( $value ): ?string {
		return null === $value ? null : self::as_string( $value );
	}

	/**
	 * Asserts a value is an array and returns it.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return array<string|int, mixed> The validated array.
	 *
	 * @throws \InvalidArgumentException If value is not an array.
	 *
	 * @phpstan-assert array<string|int, mixed> $value
	 */
	protected static function as_array( $value ): array {
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected array, got %s', gettype( $value ) )
			);
		}
		return $value;
	}

	/**
	 * Returns a value as array or empty array if null.
	 *
	 * Useful for optional array parameters with empty array default.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return array<string|int, mixed> The validated array or empty array.
	 *
	 * @throws \InvalidArgumentException If value is not an array or null.
	 */
	protected static function as_array_or_empty( $value ): array {
		return null === $value ? array() : self::as_array( $value );
	}

	/**
	 * Asserts a value is a callable and returns it.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return callable The validated callable.
	 *
	 * @throws \InvalidArgumentException If value is not callable.
	 *
	 * @phpstan-assert callable $value
	 */
	protected static function as_callable( $value ): callable {
		if ( ! is_callable( $value ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected callable, got %s', gettype( $value ) )
			);
		}
		return $value;
	}

	/**
	 * Returns a value as callable or null.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return callable|null The validated callable or null.
	 *
	 * @throws \InvalidArgumentException If value is not callable or null.
	 */
	protected static function as_callable_or_null( $value ): ?callable {
		return null === $value ? null : self::as_callable( $value );
	}

	/**
	 * Asserts a value is a valid class-string and returns it.
	 *
	 * Note: This method validates at runtime that the class exists and implements
	 * the required interface. The return type is `string` but callers should use
	 * PHPStan annotations to narrow the type appropriately.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed       $value          The value to check.
	 * @param string|null $must_implement Optional interface the class must implement.
	 * @return string The validated class-string.
	 *
	 * @throws \InvalidArgumentException If value is not a valid class-string.
	 */
	protected static function as_class_string( $value, ?string $must_implement = null ): string {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Expected class-string, got %s', gettype( $value ) )
			);
		}

		if ( ! class_exists( $value ) && ! interface_exists( $value ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Class or interface "%s" does not exist', $value )
			);
		}

		if ( null !== $must_implement && ! is_a( $value, $must_implement, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Class "%s" must implement %s', $value, $must_implement )
			);
		}

		return $value;
	}

	/**
	 * Returns a value as class-string or null.
	 *
	 * Note: This method validates at runtime that the class exists and implements
	 * the required interface. The return type is `string|null` but callers should use
	 * PHPStan annotations to narrow the type appropriately.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed       $value          The value to check.
	 * @param string|null $must_implement Optional interface the class must implement.
	 * @return string|null The validated class-string or null.
	 *
	 * @throws \InvalidArgumentException If value is not a valid class-string or null.
	 */
	protected static function as_class_string_or_null( $value, ?string $must_implement = null ): ?string {
		return null === $value ? null : self::as_class_string( $value, $must_implement );
	}

	/**
	 * Asserts a value is an array of class-strings and returns it.
	 *
	 * Note: This method validates at runtime that each class exists and implements
	 * the required interface. The return type is `array<int, string>` but callers
	 * should use PHPStan annotations to narrow the type appropriately.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed       $value          The value to check.
	 * @param string|null $must_implement Optional interface each class must implement.
	 * @return array<int, string> The validated array of class-strings.
	 *
	 * @throws \InvalidArgumentException If any value is not a valid class-string.
	 */
	protected static function as_class_string_array( $value, ?string $must_implement = null ): array {
		$array = self::as_array( $value );

		return array_values(
			array_map(
				static fn( $item ): string => self::as_class_string( $item, $must_implement ),
				$array
			)
		);
	}

	/**
	 * Asserts a value is an array of strings and returns it.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return array<int, string> The validated array of strings.
	 *
	 * @throws \InvalidArgumentException If any value is not a string.
	 */
	protected static function as_string_array( $value ): array {
		$array = self::as_array( $value );

		return array_values(
			array_map(
				static fn( $item ): string => self::as_string( $item ),
				$array
			)
		);
	}

	/**
	 * Returns a value as array of strings or empty array if null.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed $value The value to check.
	 * @return array<int, string> The validated array of strings or empty array.
	 *
	 * @throws \InvalidArgumentException If any value is not a string or null.
	 */
	protected static function as_string_array_or_empty( $value ): array {
		return null === $value ? array() : self::as_string_array( $value );
	}
}
