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

	public function test_2025_11_25_factory_exposes_its_exact_revision(): void {
		$context = McpProtocolContext::for_2025_11_25();

		$this->assertSame( McpProtocolContext::PROTOCOL_VERSION_2025_11_25, $context->get_protocol_version() );
		$this->assertSame( McpProtocolContext::SCHEMA_REVISION_2025_11_25, $context->get_schema_revision() );
	}

	public function test_unknown_protocol_version_cannot_select_a_schema_revision(): void {
		$context = new McpProtocolContext( '2099-01-01' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '2099-01-01' );

		$context->get_schema_revision();
	}

	public function test_empty_protocol_version_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		new McpProtocolContext( '' );
	}
}
