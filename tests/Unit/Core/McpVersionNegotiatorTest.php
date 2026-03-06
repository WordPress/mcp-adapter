<?php
/**
 * Tests for the MCP protocol version negotiator.
 *
 * @package WP\MCP\Tests\Unit\Core
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\McpConstants;

/**
 * @since n.e.x.t.
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
		foreach ( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS as $version ) {
			$data[ $version ] = array( $version );
		}
		return $data;
	}

	/**
	 * Test that negotiating with an unsupported version returns the latest supported version.
	 */
	public function test_negotiate_withUnsupportedVersion_returnsLatest(): void {
		$latest = McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS[0];

		$this->assertSame( $latest, McpVersionNegotiator::negotiate( '9999-99-99' ) );
	}

	/**
	 * Test that negotiating with an empty string returns the latest supported version.
	 */
	public function test_negotiate_withEmptyString_returnsLatest(): void {
		$latest = McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS[0];

		$this->assertSame( $latest, McpVersionNegotiator::negotiate( '' ) );
	}

	/**
	 * Test that is_supported returns true for a supported version.
	 *
	 * @dataProvider data_supported_versions
	 *
	 * @param string $version A supported protocol version.
	 */
	public function test_is_supported_withSupportedVersion_returnsTrue( string $version ): void {
		$this->assertTrue( McpVersionNegotiator::is_supported( $version ) );
	}

	/**
	 * Test that is_supported returns false for an unsupported version.
	 */
	public function test_is_supported_withUnsupportedVersion_returnsFalse(): void {
		$this->assertFalse( McpVersionNegotiator::is_supported( '9999-99-99' ) );
	}

	/**
	 * Test that the latest supported version matches the schema package constant.
	 *
	 * McpConstants::LATEST_PROTOCOL_VERSION comes from the php-mcp-schema vendor
	 * package. If that package updates its constant but SUPPORTED_PROTOCOL_VERSIONS
	 * is not updated, this test will catch the drift.
	 */
	public function test_latestSupportedVersion_matchesMcpConstantsLatest(): void {
		$this->assertSame(
			McpConstants::LATEST_PROTOCOL_VERSION,
			McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS[0],
			'SUPPORTED_PROTOCOL_VERSIONS[0] must match McpConstants::LATEST_PROTOCOL_VERSION. '
			. 'If the php-mcp-schema package was updated, add the new version to SUPPORTED_PROTOCOL_VERSIONS.'
		);
	}

	/**
	 * Test that SUPPORTED_PROTOCOL_VERSIONS contains exactly the expected set.
	 *
	 * This explicit assertion prevents silent test-suite shrinkage: if a version
	 * is accidentally removed from the constant the data-provider-based tests
	 * would simply run fewer cases without failing. Locking the list here forces
	 * a deliberate test update whenever versions are added or removed.
	 */
	public function test_supported_versions_containsExactExpectedSet(): void {
		$expected = array(
			'2025-11-25',
			'2025-06-18',
			'2024-11-05',
		);

		$this->assertSame(
			$expected,
			McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS,
			'SUPPORTED_PROTOCOL_VERSIONS does not match the expected set. '
			. 'If a version was intentionally added or removed, update this test.'
		);
	}

	/**
	 * Test that the first element in SUPPORTED_PROTOCOL_VERSIONS is the latest version.
	 *
	 * The constant must be ordered newest-first so that index [0] is always
	 * the latest protocol version the server supports.
	 */
	public function test_supported_versions_firstElementIsLatest(): void {
		$versions = McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS;

		$this->assertNotEmpty( $versions, 'SUPPORTED_PROTOCOL_VERSIONS must not be empty.' );

		$sorted = $versions;
		usort(
			$sorted,
			static function ( string $a, string $b ): int {
				return strcmp( $b, $a );
			}
		);

		$this->assertSame(
			$sorted[0],
			$versions[0],
			'The first element of SUPPORTED_PROTOCOL_VERSIONS must be the latest (newest) version.'
		);
	}
}
