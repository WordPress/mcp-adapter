<?php
/**
 * Tests for the MCP protocol version negotiator.
 *
 * @package WP\MCP\Tests\Unit\Core
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Tests\TestCase;

/**
 * @since 0.5.0
 */
final class McpVersionNegotiatorTest extends TestCase {

	public function test_negotiate_echoes_the_legacy_initialize_revision(): void {
		$this->assertSame(
			McpVersionNegotiator::LEGACY_PROTOCOL_VERSION,
			McpVersionNegotiator::negotiate( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION )
		);
	}

	/**
	 * The modern revision is stateless and does not use initialize. A client
	 * attempting to initialize with any other revision must receive the one
	 * legacy revision this server can negotiate.
	 *
	 * @dataProvider data_non_legacy_initialize_versions
	 */
	public function test_negotiate_falls_back_to_legacy_for_non_legacy_initialize_versions( string $version ): void {
		$this->assertSame( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION, McpVersionNegotiator::negotiate( $version ) );
	}

	/** @return array<string, array{string}> */
	public function data_non_legacy_initialize_versions(): array {
		return array(
			'modern revision'      => array( McpVersionNegotiator::MODERN_PROTOCOL_VERSION ),
			'unsupported revision' => array( '9999-99-99' ),
			'missing revision'     => array( '' ),
		);
	}

	/** @dataProvider data_supported_versions */
	public function test_is_supported_accepts_each_exact_runtime_revision( string $version ): void {
		$this->assertTrue( McpVersionNegotiator::is_supported( $version ) );
		$this->assertSame( $version, McpVersionNegotiator::schema_revision( $version ) );
	}

	/** @return array<string, array{string}> */
	public function data_supported_versions(): array {
		return array(
			'2026 stateless' => array( McpVersionNegotiator::MODERN_PROTOCOL_VERSION ),
			'2025 session'   => array( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION ),
		);
	}

	public function test_supported_versions_are_exact_and_newest_first(): void {
		$this->assertSame(
			array(
				'2026-07-28',
				'2025-11-25',
			),
			McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS
		);
	}

	public function test_is_supported_rejects_unadvertised_revision(): void {
		$this->assertFalse( McpVersionNegotiator::is_supported( '2025-06-18' ) );
	}

	public function test_schema_revision_rejects_unadvertised_revision(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported MCP protocol version: 2025-06-18' );

		McpVersionNegotiator::schema_revision( '2025-06-18' );
	}
}
