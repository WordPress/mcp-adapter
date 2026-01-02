<?php
/**
 * Tests for ValidatesRequiredFieldsTrait.
 *
 * @package WP\MCP\Tests\Unit\Core\Traits
 * @since   n.e.x.t
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core\Traits;

use WP\MCP\Core\Traits\ValidatesRequiredFieldsTrait;
use WP\MCP\Tests\TestCase;

/**
 * Test helper class that uses the trait.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class TraitTestHelper {
	use ValidatesRequiredFieldsTrait;

	/**
	 * Expose protected methods for testing.
	 *
	 * @param array<string, mixed> $data            The input data.
	 * @param array<int, string>   $required_fields Required field names.
	 * @return void
	 */
	public static function test_assert_required( array $data, array $required_fields ): void {
		self::assert_required( $data, $required_fields );
	}

	/**
	 * Expose as_string for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return string
	 */
	public static function test_as_string( $value ): string {
		return self::as_string( $value );
	}

	/**
	 * Expose as_string_or_null for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return string|null
	 */
	public static function test_as_string_or_null( $value ): ?string {
		return self::as_string_or_null( $value );
	}

	/**
	 * Expose as_array for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return array<string|int, mixed>
	 */
	public static function test_as_array( $value ): array {
		return self::as_array( $value );
	}

	/**
	 * Expose as_array_or_empty for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return array<string|int, mixed>
	 */
	public static function test_as_array_or_empty( $value ): array {
		return self::as_array_or_empty( $value );
	}

	/**
	 * Expose as_callable for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return callable
	 */
	public static function test_as_callable( $value ): callable {
		return self::as_callable( $value );
	}

	/**
	 * Expose as_callable_or_null for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return callable|null
	 */
	public static function test_as_callable_or_null( $value ): ?callable {
		return self::as_callable_or_null( $value );
	}

	/**
	 * Expose as_class_string for testing.
	 *
	 * @param mixed       $value          Value to check.
	 * @param string|null $must_implement Optional interface requirement.
	 * @return string
	 */
	public static function test_as_class_string( $value, ?string $must_implement = null ): string {
		return self::as_class_string( $value, $must_implement );
	}

	/**
	 * Expose as_class_string_or_null for testing.
	 *
	 * @param mixed       $value          Value to check.
	 * @param string|null $must_implement Optional interface requirement.
	 * @return string|null
	 */
	public static function test_as_class_string_or_null( $value, ?string $must_implement = null ): ?string {
		return self::as_class_string_or_null( $value, $must_implement );
	}

	/**
	 * Expose as_class_string_array for testing.
	 *
	 * @param mixed       $value          Value to check.
	 * @param string|null $must_implement Optional interface requirement.
	 * @return array<int, string>
	 */
	public static function test_as_class_string_array( $value, ?string $must_implement = null ): array {
		return self::as_class_string_array( $value, $must_implement );
	}

	/**
	 * Expose as_string_array for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return array<int, string>
	 */
	public static function test_as_string_array( $value ): array {
		return self::as_string_array( $value );
	}

	/**
	 * Expose as_string_array_or_empty for testing.
	 *
	 * @param mixed $value Value to check.
	 * @return array<int, string>
	 */
	public static function test_as_string_array_or_empty( $value ): array {
		return self::as_string_array_or_empty( $value );
	}
}

/**
 * Test interface for class-string validation.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
interface TestValidationInterface {
}

/**
 * Test class implementing the interface.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class TestValidationClass implements TestValidationInterface {
}

/**
 * Test class NOT implementing the interface.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class TestNonImplementingClass {
}

/**
 * Tests for ValidatesRequiredFieldsTrait.
 *
 * @since n.e.x.t
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
final class ValidatesRequiredFieldsTraitTest extends TestCase {

	// =========================================================================
	// assert_required tests
	// =========================================================================

	public function test_assert_required_withAllFieldsPresent_doesNotThrow(): void {
		$data = array(
			'name'  => 'test',
			'value' => 123,
		);

		// Should not throw
		TraitTestHelper::test_assert_required( $data, array( 'name', 'value' ) );
		$this->assertTrue( true ); // If we get here, no exception was thrown
	}

	public function test_assert_required_withMissingField_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing required field(s): value' );

		$data = array( 'name' => 'test' );
		TraitTestHelper::test_assert_required( $data, array( 'name', 'value' ) );
	}

	public function test_assert_required_withMultipleMissingFields_reportsAll(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing required field(s): name, value' );

		$data = array();
		TraitTestHelper::test_assert_required( $data, array( 'name', 'value' ) );
	}

	public function test_assert_required_withNullValue_doesNotThrow(): void {
		// A null value is still "present" - it has the key
		$data = array( 'name' => null );
		TraitTestHelper::test_assert_required( $data, array( 'name' ) );
		$this->assertTrue( true );
	}

	// =========================================================================
	// as_string tests
	// =========================================================================

	public function test_as_string_withValidString_returnsString(): void {
		$result = TraitTestHelper::test_as_string( 'hello' );
		$this->assertSame( 'hello', $result );
	}

	public function test_as_string_withEmptyString_returnsEmptyString(): void {
		$result = TraitTestHelper::test_as_string( '' );
		$this->assertSame( '', $result );
	}

	public function test_as_string_withInteger_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expected string, got integer' );
		TraitTestHelper::test_as_string( 123 );
	}

	public function test_as_string_withNull_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expected string, got NULL' );
		TraitTestHelper::test_as_string( null );
	}

	public function test_as_string_withArray_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expected string, got array' );
		TraitTestHelper::test_as_string( array() );
	}

	// =========================================================================
	// as_string_or_null tests
	// =========================================================================

	public function test_as_string_or_null_withString_returnsString(): void {
		$result = TraitTestHelper::test_as_string_or_null( 'hello' );
		$this->assertSame( 'hello', $result );
	}

	public function test_as_string_or_null_withNull_returnsNull(): void {
		$result = TraitTestHelper::test_as_string_or_null( null );
		$this->assertNull( $result );
	}

	public function test_as_string_or_null_withInteger_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_string_or_null( 123 );
	}

	// =========================================================================
	// as_array tests
	// =========================================================================

	public function test_as_array_withValidArray_returnsArray(): void {
		$input  = array( 'key' => 'value' );
		$result = TraitTestHelper::test_as_array( $input );
		$this->assertSame( $input, $result );
	}

	public function test_as_array_withEmptyArray_returnsEmptyArray(): void {
		$result = TraitTestHelper::test_as_array( array() );
		$this->assertSame( array(), $result );
	}

	public function test_as_array_withString_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expected array, got string' );
		TraitTestHelper::test_as_array( 'not an array' );
	}

	// =========================================================================
	// as_array_or_empty tests
	// =========================================================================

	public function test_as_array_or_empty_withArray_returnsArray(): void {
		$input  = array( 'key' => 'value' );
		$result = TraitTestHelper::test_as_array_or_empty( $input );
		$this->assertSame( $input, $result );
	}

	public function test_as_array_or_empty_withNull_returnsEmptyArray(): void {
		$result = TraitTestHelper::test_as_array_or_empty( null );
		$this->assertSame( array(), $result );
	}

	public function test_as_array_or_empty_withString_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_array_or_empty( 'not an array' );
	}

	// =========================================================================
	// as_callable tests
	// =========================================================================

	public function test_as_callable_withClosure_returnsClosure(): void {
		$callable = static function () {
			return 'test';
		};
		$result   = TraitTestHelper::test_as_callable( $callable );
		$this->assertSame( $callable, $result );
	}

	public function test_as_callable_withFunctionName_returnsIt(): void {
		$result = TraitTestHelper::test_as_callable( 'strlen' );
		$this->assertSame( 'strlen', $result );
	}

	public function test_as_callable_withArrayCallback_returnsIt(): void {
		$callback = array( TraitTestHelper::class, 'test_as_string' );
		$result   = TraitTestHelper::test_as_callable( $callback );
		$this->assertSame( $callback, $result );
	}

	public function test_as_callable_withNonCallable_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expected callable, got string' );
		TraitTestHelper::test_as_callable( 'not_a_real_function_name_12345' );
	}

	// =========================================================================
	// as_callable_or_null tests
	// =========================================================================

	public function test_as_callable_or_null_withCallable_returnsCallable(): void {
		$callable = static function () {
			return 'test';
		};
		$result   = TraitTestHelper::test_as_callable_or_null( $callable );
		$this->assertSame( $callable, $result );
	}

	public function test_as_callable_or_null_withNull_returnsNull(): void {
		$result = TraitTestHelper::test_as_callable_or_null( null );
		$this->assertNull( $result );
	}

	// =========================================================================
	// as_class_string tests
	// =========================================================================

	public function test_as_class_string_withExistingClass_returnsClassName(): void {
		$result = TraitTestHelper::test_as_class_string( \stdClass::class );
		$this->assertSame( \stdClass::class, $result );
	}

	public function test_as_class_string_withExistingInterface_returnsInterfaceName(): void {
		$result = TraitTestHelper::test_as_class_string( TestValidationInterface::class );
		$this->assertSame( TestValidationInterface::class, $result );
	}

	public function test_as_class_string_withNonExistentClass_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Class or interface "NonExistentClass12345" does not exist' );
		TraitTestHelper::test_as_class_string( 'NonExistentClass12345' );
	}

	public function test_as_class_string_withInteger_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expected class-string, got integer' );
		TraitTestHelper::test_as_class_string( 123 );
	}

	public function test_as_class_string_withInterfaceRequirement_validates(): void {
		$result = TraitTestHelper::test_as_class_string(
			TestValidationClass::class,
			TestValidationInterface::class
		);
		$this->assertSame( TestValidationClass::class, $result );
	}

	public function test_as_class_string_withInterfaceRequirementNotMet_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must implement' );

		TraitTestHelper::test_as_class_string(
			TestNonImplementingClass::class,
			TestValidationInterface::class
		);
	}

	// =========================================================================
	// as_class_string_or_null tests
	// =========================================================================

	public function test_as_class_string_or_null_withClass_returnsClassName(): void {
		$result = TraitTestHelper::test_as_class_string_or_null( \stdClass::class );
		$this->assertSame( \stdClass::class, $result );
	}

	public function test_as_class_string_or_null_withNull_returnsNull(): void {
		$result = TraitTestHelper::test_as_class_string_or_null( null );
		$this->assertNull( $result );
	}

	public function test_as_class_string_or_null_withNonExistentClass_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_class_string_or_null( 'NonExistentClass12345' );
	}

	// =========================================================================
	// as_class_string_array tests
	// =========================================================================

	public function test_as_class_string_array_withValidClasses_returnsArray(): void {
		$input  = array( \stdClass::class, \Exception::class );
		$result = TraitTestHelper::test_as_class_string_array( $input );
		$this->assertSame( $input, $result );
	}

	public function test_as_class_string_array_withEmptyArray_returnsEmptyArray(): void {
		$result = TraitTestHelper::test_as_class_string_array( array() );
		$this->assertSame( array(), $result );
	}

	public function test_as_class_string_array_withInvalidClass_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_class_string_array( array( 'NonExistentClass12345' ) );
	}

	public function test_as_class_string_array_withInterfaceRequirement_validates(): void {
		$input  = array( TestValidationClass::class );
		$result = TraitTestHelper::test_as_class_string_array( $input, TestValidationInterface::class );
		$this->assertSame( $input, $result );
	}

	public function test_as_class_string_array_withNonString_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_class_string_array( 'not an array' );
	}

	// =========================================================================
	// as_string_array tests
	// =========================================================================

	public function test_as_string_array_withValidStrings_returnsArray(): void {
		$input  = array( 'one', 'two', 'three' );
		$result = TraitTestHelper::test_as_string_array( $input );
		$this->assertSame( $input, $result );
	}

	public function test_as_string_array_withEmptyArray_returnsEmptyArray(): void {
		$result = TraitTestHelper::test_as_string_array( array() );
		$this->assertSame( array(), $result );
	}

	public function test_as_string_array_withNonString_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_string_array( array( 'valid', 123 ) );
	}

	public function test_as_string_array_reindexesKeys(): void {
		$input  = array( 'a' => 'one', 'b' => 'two' );
		$result = TraitTestHelper::test_as_string_array( $input );
		$this->assertSame( array( 0 => 'one', 1 => 'two' ), $result );
	}

	// =========================================================================
	// as_string_array_or_empty tests
	// =========================================================================

	public function test_as_string_array_or_empty_withArray_returnsArray(): void {
		$input  = array( 'one', 'two' );
		$result = TraitTestHelper::test_as_string_array_or_empty( $input );
		$this->assertSame( $input, $result );
	}

	public function test_as_string_array_or_empty_withNull_returnsEmptyArray(): void {
		$result = TraitTestHelper::test_as_string_array_or_empty( null );
		$this->assertSame( array(), $result );
	}

	public function test_as_string_array_or_empty_withNonStringInArray_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		TraitTestHelper::test_as_string_array_or_empty( array( 123 ) );
	}
}
