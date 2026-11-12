<?php
/**
 * Request context extension lookups.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Tests\TestCase;

/** Covers how a request context reports the extensions a client declared. */
final class McpRequestContextTest extends TestCase {

	private const UI_EXTENSION = 'io.modelcontextprotocol/ui';

	/** A declared extension returns its settings as an independent copy. */
	public function test_client_extension_returns_declared_settings(): void {
		$context = $this->context_with_capabilities(
			array(
				'extensions' => array(
					self::UI_EXTENSION => array( 'mimeTypes' => array( 'text/html;profile=mcp-app' ) ),
				),
			)
		);

		$settings = $context->client_extension( self::UI_EXTENSION );
		$this->assertInstanceOf( \stdClass::class, $settings );
		$expected = array( 'mimeTypes' => array( 'text/html;profile=mcp-app' ) );
		$this->assertSame( $expected, get_object_vars( $settings ) );

		$settings->added = true;
		$this->assertSame( $expected, get_object_vars( $context->client_extension( self::UI_EXTENSION ) ) );
	}

	/** A settings object with no members still counts as declared. */
	public function test_client_extension_accepts_empty_settings(): void {
		$context = $this->context_with_capabilities( array( 'extensions' => array( self::UI_EXTENSION => new \stdClass() ) ) );

		$this->assertInstanceOf( \stdClass::class, $context->client_extension( self::UI_EXTENSION ) );
		$this->assertTrue( $context->client_supports_extension( self::UI_EXTENSION ) );
	}

	/**
	 * Missing or malformed declarations return null.
	 *
	 * @dataProvider undeclared_extension_provider
	 * @param array<string, mixed> $capabilities Client capabilities.
	 */
	public function test_client_extension_returns_null_when_not_declared( array $capabilities ): void {
		$context = $this->context_with_capabilities( $capabilities );

		$this->assertNull( $context->client_extension( self::UI_EXTENSION ) );
		$this->assertFalse( $context->client_supports_extension( self::UI_EXTENSION ) );
	}

	/**
	 * Capabilities that do not declare the UI extension.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function undeclared_extension_provider(): array {
		return array(
			'no capabilities'          => array( array() ),
			'no extensions'            => array( array( 'sampling' => new \stdClass() ) ),
			'extensions is a list'     => array( array( 'extensions' => array( 'io.modelcontextprotocol/ui' ) ) ),
			'other extension only'     => array( array( 'extensions' => array( 'io.example/other' => new \stdClass() ) ) ),
			'settings are not objects' => array( array( 'extensions' => array( self::UI_EXTENSION => true ) ) ),
		);
	}

	/**
	 * Build a context whose capabilities decode the way the wire decoder produces them.
	 *
	 * @param array<string, mixed> $capabilities Client capabilities.
	 */
	private function context_with_capabilities( array $capabilities ): McpRequestContext {
		$decoded = json_decode( (string) wp_json_encode( (object) $capabilities ) );
		$this->assertInstanceOf( \stdClass::class, $decoded );

		return new McpRequestContext( $this->schema(), $decoded, null, 'test' );
	}
}
