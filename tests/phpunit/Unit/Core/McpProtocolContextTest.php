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

	public function test_legacy_versions_select_legacy_schema_tree(): void {
		$context = new McpProtocolContext( '2024-11-05' );

		$this->assertSame( '2024-11-05', $context->get_protocol_version() );
		$this->assertSame( McpProtocolContext::LEGACY_SCHEMA_REVISION, $context->get_schema_revision() );
		$this->assertFalse( $context->is_modern() );
	}

	public function test_modern_revision_selects_modern_schema_tree(): void {
		$context = new McpProtocolContext( McpProtocolContext::MODERN_SCHEMA_REVISION );

		$this->assertSame( McpProtocolContext::MODERN_SCHEMA_REVISION, $context->get_protocol_version() );
		$this->assertSame( McpProtocolContext::MODERN_SCHEMA_REVISION, $context->get_schema_revision() );
		$this->assertTrue( $context->is_modern() );
	}

	public function test_default_context_remains_legacy(): void {
		$context = McpProtocolContext::legacy_default();

		$this->assertSame( McpProtocolContext::LEGACY_SCHEMA_REVISION, $context->get_protocol_version() );
		$this->assertFalse( $context->is_modern() );
	}

	public function test_empty_protocol_version_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		new McpProtocolContext( '' );
	}
}
