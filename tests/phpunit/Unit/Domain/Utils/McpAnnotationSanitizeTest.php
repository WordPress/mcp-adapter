<?php
/**
 * Tests for annotation sanitisation and the schema pins that keep it honest.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;

final class McpAnnotationSanitizeTest extends TestCase {

	public function test_valid_tool_annotations_pass_through(): void {
		$result = McpAnnotationMapper::sanitize(
			array(
				'title'        => 'Readable',
				'readOnlyHint' => true,
			),
			'tool',
			'demo'
		);

		$this->assertSame(
			array(
				'title'        => 'Readable',
				'readOnlyHint' => true,
			),
			$result
		);
	}

	public function test_an_unknown_key_is_dropped(): void {
		$this->setExpectedIncorrectUsage( 'WP\MCP\Domain\Utils\McpAnnotationMapper::sanitize' );

		$result = McpAnnotationMapper::sanitize(
			array(
				'title'      => 'Kept',
				'customHint' => true,
			),
			'tool',
			'demo'
		);

		$this->assertSame( array( 'title' => 'Kept' ), $result );
	}

	public function test_a_mistyped_key_is_dropped(): void {
		$this->setExpectedIncorrectUsage( 'WP\MCP\Domain\Utils\McpAnnotationMapper::sanitize' );

		$result = McpAnnotationMapper::sanitize(
			array(
				'title'        => 'Kept',
				'readOnlyHint' => 'not-a-boolean',
			),
			'tool',
			'demo'
		);

		$this->assertSame( array( 'title' => 'Kept' ), $result );
	}

	public function test_a_key_belonging_to_another_feature_is_dropped(): void {
		$this->setExpectedIncorrectUsage( 'WP\MCP\Domain\Utils\McpAnnotationMapper::sanitize' );

		// audience is a resource annotation; tools use ToolAnnotations.
		$result = McpAnnotationMapper::sanitize(
			array(
				'title'    => 'Kept',
				'audience' => array( 'user' ),
			),
			'tool',
			'demo'
		);

		$this->assertSame( array( 'title' => 'Kept' ), $result );
	}

	public function test_unknown_audience_roles_are_dropped(): void {
		$result = McpAnnotationMapper::sanitize(
			array( 'audience' => array( 'user', 'nobody' ) ),
			'resource',
			'WordPress://local/thing'
		);

		$this->assertSame( array( 'audience' => array( 'user' ) ), $result );
	}

	/**
	 * The whitelist above is written once and applied to both protocol
	 * revisions. That is only sound while both revisions declare the annotation
	 * types identically. These pins fail the moment that stops being true, so a
	 * revision that adds a field cannot silently have it dropped.
	 */
	public function test_both_revisions_declare_the_same_annotation_types(): void {
		$legacy = Schemas::v20251125();
		$modern = Schemas::v20260728();

		$this->assertSame(
			$legacy->type( 'ToolAnnotations' )->fingerprint(),
			$modern->type( 'ToolAnnotations' )->fingerprint(),
			'ToolAnnotations differs between revisions; the shared annotation whitelist is no longer safe.'
		);
		$this->assertSame(
			$legacy->type( 'Annotations' )->fingerprint(),
			$modern->type( 'Annotations' )->fingerprint(),
			'Annotations differs between revisions; the shared annotation whitelist is no longer safe.'
		);
	}

	/**
	 * McpErrorFactory names its standard codes from the 2025 constants. That is
	 * only sound while every revision agrees on them, which JSON-RPC requires
	 * but the schema package is free to change.
	 */
	public function test_both_revisions_agree_on_the_json_rpc_constants(): void {
		$legacy = new \ReflectionClass( \WP\McpSchema\Generated\V20251125Constants::class );
		$modern = new \ReflectionClass( \WP\McpSchema\Generated\V20260728Constants::class );

		$modern_constants = $modern->getConstants();

		foreach ( array( 'JSONRPC_VERSION', 'PARSE_ERROR', 'INVALID_REQUEST', 'METHOD_NOT_FOUND', 'INVALID_PARAMS', 'INTERNAL_ERROR' ) as $name ) {
			$this->assertArrayHasKey( $name, $modern_constants, $name . ' is missing from the 2026 revision.' );
			$this->assertSame(
				$legacy->getConstant( $name ),
				$modern_constants[ $name ],
				$name . ' differs between revisions; McpErrorFactory can no longer name it from one revision.'
			);
		}
	}
}
