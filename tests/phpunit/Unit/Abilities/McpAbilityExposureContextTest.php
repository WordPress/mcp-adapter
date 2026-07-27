<?php
/**
 * Tests for the McpAbilityExposureContext value object.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\McpAbilityExposureContext;
use WP\MCP\Tests\TestCase;

final class McpAbilityExposureContextTest extends TestCase {

	public function test_path_constants_are_stable(): void {
		// These string values are part of the public contract of the
		// `mcp_adapter_is_ability_exposed` filter — downstream code may
		// compare `$context->exposure_path` to string literals. Do not
		// change these without treating it as a BC break.
		$this->assertSame( 'discover', McpAbilityExposureContext::PATH_DISCOVER );
		$this->assertSame( 'get_info', McpAbilityExposureContext::PATH_GET_INFO );
		$this->assertSame( 'execute', McpAbilityExposureContext::PATH_EXECUTE );
	}

	public function test_construction_populates_all_fields(): void {
		$context = new McpAbilityExposureContext(
			null,
			42,
			array( 'editor', 'contributor' ),
			7,
			McpAbilityExposureContext::PATH_EXECUTE
		);

		$this->assertNull( $context->server );
		$this->assertSame( 42, $context->principal_id );
		$this->assertSame( array( 'editor', 'contributor' ), $context->roles );
		$this->assertSame( 7, $context->site_id );
		$this->assertSame( McpAbilityExposureContext::PATH_EXECUTE, $context->exposure_path );
	}
}
