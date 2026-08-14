<?php
/**
 * Tests for the per-request protocol context.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use LogicException;
use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Tests\TestCase;

final class McpProtocolContextTest extends TestCase {

	public function test_default_uses_the_newest_supported_revision(): void {
		$context = McpProtocolContext::default();

		$this->assertSame( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS[0], $context->revision() );
	}

	public function test_catalog_matches_the_requested_revision(): void {
		$context = McpProtocolContext::for_revision( '2025-11-25' );

		$this->assertSame( '2025-11-25', $context->revision() );
		$this->assertSame( '2025-11-25', $context->catalog()->revision() );
	}

	public function test_the_catalog_is_selected_once_per_revision(): void {
		$first  = McpProtocolContext::for_revision( '2025-11-25' );
		$second = McpProtocolContext::for_revision( '2025-11-25' );

		$this->assertSame( $first, $second );
		$this->assertSame( $first->catalog(), $second->catalog() );
	}

	public function test_a_second_revision_gets_its_own_context(): void {
		$legacy = McpProtocolContext::for_revision( '2025-11-25' );
		$modern = McpProtocolContext::for_revision( '2026-07-28' );

		$this->assertNotSame( $legacy, $modern );
		$this->assertSame( '2026-07-28', $modern->catalog()->revision() );
	}

	public function test_an_unknown_revision_is_rejected(): void {
		$this->expectException( LogicException::class );

		McpProtocolContext::for_revision( '1999-01-01' );
	}
}
