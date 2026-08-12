<?php
/**
 * Tests for request-scoped MCP protocol context.
 *
 * @package WP\MCP\Tests\Unit\Core
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Tests\TestCase;

/**
 * @since n.e.x.t
 */
final class McpProtocolContextTest extends TestCase {

	/**
	 * @dataProvider provide_protocol_to_schema_revision_mapping
	 */
	public function test_protocol_versions_map_to_exact_schema_revisions( string $protocol_version, string $schema_revision ): void {
		$context = new McpProtocolContext( $protocol_version );

		$this->assertSame( $protocol_version, $context->get_protocol_version() );
		$this->assertSame( $schema_revision, $context->get_schema_revision() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function provide_protocol_to_schema_revision_mapping(): array {
		return array(
			'2024-11-05' => array( McpProtocolContext::PROTOCOL_VERSION_2024_11_05, McpProtocolContext::SCHEMA_REVISION_2025_11_25 ),
			'2025-06-18' => array( McpProtocolContext::PROTOCOL_VERSION_2025_06_18, McpProtocolContext::SCHEMA_REVISION_2025_11_25 ),
			'2025-11-25' => array( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, McpProtocolContext::SCHEMA_REVISION_2025_11_25 ),
			'2026-07-28' => array( McpProtocolContext::PROTOCOL_VERSION_2026_07_28, McpProtocolContext::SCHEMA_REVISION_2026_07_28 ),
		);
	}

	/**
	 * @dataProvider provide_protocol_lifecycle_profiles
	 */
	public function test_protocol_versions_expose_their_lifecycle( string $protocol_version, bool $uses_initialize, bool $uses_discover ): void {
		$context = new McpProtocolContext( $protocol_version );

		$this->assertSame( $uses_initialize, $context->uses_initialize_lifecycle() );
		$this->assertSame( $uses_discover, $context->uses_discover_lifecycle() );
	}

	/**
	 * @return array<string, array{string, bool, bool}>
	 */
	public function provide_protocol_lifecycle_profiles(): array {
		return array(
			'2024-11-05' => array( McpProtocolContext::PROTOCOL_VERSION_2024_11_05, true, false ),
			'2025-06-18' => array( McpProtocolContext::PROTOCOL_VERSION_2025_06_18, true, false ),
			'2025-11-25' => array( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, true, false ),
			'2026-07-28' => array( McpProtocolContext::PROTOCOL_VERSION_2026_07_28, false, true ),
		);
	}

	/**
	 * @dataProvider provide_protocol_transport_profiles
	 */
	public function test_protocol_versions_expose_their_transport_mode( string $protocol_version, bool $uses_sessions, bool $is_stateless ): void {
		$context = new McpProtocolContext( $protocol_version );

		$this->assertSame( $uses_sessions, $context->uses_sessions() );
		$this->assertSame( $is_stateless, $context->is_stateless() );
	}

	/**
	 * @return array<string, array{string, bool, bool}>
	 */
	public function provide_protocol_transport_profiles(): array {
		return array(
			'2024-11-05' => array( McpProtocolContext::PROTOCOL_VERSION_2024_11_05, true, false ),
			'2025-06-18' => array( McpProtocolContext::PROTOCOL_VERSION_2025_06_18, true, false ),
			'2025-11-25' => array( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, true, false ),
			'2026-07-28' => array( McpProtocolContext::PROTOCOL_VERSION_2026_07_28, false, true ),
		);
	}

	public function test_protocol_registry_separates_initialize_and_discover_versions(): void {
		$this->assertSame(
			array(
				McpProtocolContext::PROTOCOL_VERSION_2025_11_25,
				McpProtocolContext::PROTOCOL_VERSION_2025_06_18,
				McpProtocolContext::PROTOCOL_VERSION_2024_11_05,
			),
			McpProtocolContext::get_initialize_protocol_versions()
		);
		$this->assertSame(
			array( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 ),
			McpProtocolContext::get_discover_protocol_versions()
		);
	}

	public function test_stateless_profile_declares_required_request_metadata(): void {
		$context = new McpProtocolContext( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 );

		$this->assertSame(
			array(
				McpProtocolContext::REQUEST_PROTOCOL_VERSION_META_KEY,
				McpProtocolContext::REQUEST_CLIENT_CAPABILITIES_META_KEY,
			),
			$context->get_required_request_metadata_keys()
		);
	}

	public function test_initialize_profile_does_not_require_per_request_metadata(): void {
		$this->assertSame(
			array(),
			McpProtocolContext::for_2025_11_25()->get_required_request_metadata_keys()
		);
	}

	public function test_discover_profile_support_is_intentionally_bounded_to_tools_call(): void {
		$context = new McpProtocolContext( McpProtocolContext::PROTOCOL_VERSION_2026_07_28 );

		$this->assertTrue( $context->supports_method( 'tools/call' ) );
		$this->assertFalse( $context->supports_method( 'server/discover' ) );
		$this->assertFalse( $context->supports_method( 'tools/list' ) );
	}

	public function test_initialize_profiles_keep_existing_method_support(): void {
		$context = McpProtocolContext::for_2025_11_25();

		$this->assertTrue( $context->supports_method( 'initialize' ) );
		$this->assertTrue( $context->supports_method( 'tools/call' ) );
		$this->assertTrue( $context->supports_method( 'tools/list' ) );
	}

	public function test_2025_11_25_factory_exposes_its_exact_revision(): void {
		$context = McpProtocolContext::for_2025_11_25();

		$this->assertSame( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, $context->get_protocol_version() );
		$this->assertSame( McpProtocolContext::SCHEMA_REVISION_2025_11_25, $context->get_schema_revision() );
	}

	public function test_unknown_protocol_version_is_rejected_at_resolution_boundary(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '2099-01-01' );

		new McpProtocolContext( '2099-01-01' );
	}

	public function test_empty_protocol_version_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		new McpProtocolContext( '' );
	}
}
