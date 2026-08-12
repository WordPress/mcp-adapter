<?php
/**
 * Tests for the MCP protocol version negotiator.
 *
 * @package WP\MCP\Tests\Unit\Core
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\V20251125\Common\McpConstants;

/**
 * @since 0.5.0
 */
final class McpVersionNegotiatorTest extends TestCase {

	/**
	 * Test that negotiating with each supported version echoes back the client version.
	 *
	 * @dataProvider data_supported_versions
	 *
	 * @param string $version A supported protocol version.
	 */
	public function test_negotiate_withSupportedVersion_returnsClientVersion( string $version ): void {
		$this->assertSame( $version, McpVersionNegotiator::negotiate( $version ) );
	}

	/**
	 * Data provider for supported protocol versions.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_supported_versions(): array {
		$data = array();
		foreach ( McpProtocolContext::get_initialize_protocol_versions() as $version ) {
			$data[ $version ] = array( $version );
		}
		return $data;
	}

	/**
	 * Test that negotiating with an unsupported version returns the latest supported version.
	 */
	public function test_negotiate_withUnsupportedVersion_returnsLatest(): void {
		$this->assertSame( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, McpVersionNegotiator::negotiate( '9999-99-99' ) );
	}

	/**
	 * Test that negotiating with an empty string returns the latest supported version.
	 */
	public function test_negotiate_withEmptyString_returnsLatest(): void {
		$this->assertSame( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, McpVersionNegotiator::negotiate( '' ) );
	}

	/**
	 * Test that is_supported returns true for a supported version.
	 *
	 * @dataProvider data_supported_versions
	 *
	 * @param string $version A supported protocol version.
	 */
	public function test_is_supported_for_initialize_with_supported_version_returns_true( string $version ): void {
		$this->assertTrue( McpVersionNegotiator::is_supported_for_initialize( $version ) );
	}

	/**
	 * Test that is_supported returns false for an unsupported version.
	 */
	public function test_is_supported_for_initialize_with_unsupported_version_returns_false(): void {
		$this->assertFalse( McpVersionNegotiator::is_supported_for_initialize( '9999-99-99' ) );
	}

	public function test_discover_version_is_not_treated_as_an_initialize_version(): void {
		$this->assertFalse( McpVersionNegotiator::is_supported_for_initialize( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 ) );
		$this->assertSame(
			McpProtocolContext::PROTOCOL_VERSION_2025_11_25,
			McpVersionNegotiator::negotiate( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 )
		);
	}

	/**
	 * Test that the latest negotiated version matches its exact schema tree.
	 *
	 * Revision constants describe their exact DTO tree. They do not determine
	 * whether the Adapter supports a newer protocol lifecycle.
	 */
	public function test_latest_negotiated_version_matches_2025_11_25_schema_tree(): void {
		$this->assertSame(
			McpConstants::LATEST_PROTOCOL_VERSION,
			McpProtocolContext::get_initialize_protocol_versions()[0],
			'The latest negotiated lifecycle must match the V20251125 schema tree.'
		);
	}

	/**
	 * Test that initialize negotiation uses exactly the registry's initialize subset.
	 */
	public function test_initialize_versions_contain_exact_expected_set(): void {
		$expected = array(
			'2025-11-25',
			'2025-06-18',
			'2024-11-05',
		);

		$this->assertSame(
			$expected,
			McpProtocolContext::get_initialize_protocol_versions(),
			'Initialize protocol versions do not match the expected registry subset.'
		);
	}
}
