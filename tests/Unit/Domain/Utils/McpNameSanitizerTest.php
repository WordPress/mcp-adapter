<?php
/**
 * Tests for McpNameSanitizer class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\McpNameSanitizer;
use WP\MCP\Tests\TestCase;
use WP_Error;

/**
 * Test McpNameSanitizer functionality.
 *
 * Tests the best-effort sanitization logic for MCP tool/prompt names
 * per MCP 2025-11-25 specification.
 */
final class McpNameSanitizerTest extends TestCase {

	// Basic Sanitization Tests

	public function test_sanitize_valid_name_unchanged(): void {
		// Already valid names should pass through unchanged.
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( 'my-tool' ) );
		$this->assertSame( 'foo_bar', McpNameSanitizer::sanitize_name( 'foo_bar' ) );
		$this->assertSame( 'tool123', McpNameSanitizer::sanitize_name( 'tool123' ) );
		$this->assertSame( 'foo.bar', McpNameSanitizer::sanitize_name( 'foo.bar' ) );
	}

	public function test_sanitize_replaces_slash(): void {
		// Forward slash should be replaced with hyphen.
		$this->assertSame( 'posts-create', McpNameSanitizer::sanitize_name( 'posts/create' ) );
		$this->assertSame( 'namespace-tool-action', McpNameSanitizer::sanitize_name( 'namespace/tool/action' ) );
	}

	public function test_sanitize_replaces_spaces(): void {
		// Spaces should be replaced with hyphens.
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( 'my tool' ) );
		$this->assertSame( 'create-new-post', McpNameSanitizer::sanitize_name( 'create new post' ) );
	}

	public function test_sanitize_collapses_runs_when_sanitizing(): void {
		// Consecutive hyphens are only collapsed when sanitization is needed.
		// If the name is already valid per MCP spec, it passes through unchanged.
		// This happens when invalid chars are replaced with hyphens.
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( 'my@@tool' ) );
		$this->assertSame( 'foo-bar', McpNameSanitizer::sanitize_name( 'foo@@@bar' ) );
	}

	public function test_sanitize_valid_names_with_multiple_hyphens_unchanged(): void {
		// Names with multiple hyphens are valid per MCP spec and pass through.
		$this->assertSame( 'my--tool', McpNameSanitizer::sanitize_name( 'my--tool' ) );
		$this->assertSame( '-my-tool-', McpNameSanitizer::sanitize_name( '-my-tool-' ) );
	}

	public function test_sanitize_trims_edges_when_sanitizing(): void {
		// Leading/trailing hyphens are only trimmed when sanitization is needed.
		// This happens after invalid chars are replaced with hyphens.
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( '@@my-tool@@' ) );
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( '  my-tool  ' ) );
	}

	public function test_sanitize_trims_whitespace(): void {
		// Leading/trailing whitespace should be trimmed first.
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( '  my-tool  ' ) );
		$this->assertSame( 'my-tool', McpNameSanitizer::sanitize_name( "\tmy-tool\n" ) );
	}

	// Length Handling Tests

	public function test_sanitize_truncates_long_names(): void {
		// Names > 128 chars should be truncated with hash suffix.
		$long_name = str_repeat( 'a', 200 );
		$result    = McpNameSanitizer::sanitize_name( $long_name );

		$this->assertIsString( $result );
		$this->assertSame( 128, strlen( $result ), 'Result should be exactly 128 characters' );
		$this->assertStringStartsWith( str_repeat( 'a', 115 ), $result );
		// Last 13 chars should be hyphen + 12-char hash.
		$this->assertMatchesRegularExpression( '/-[a-f0-9]{12}$/', $result );
	}

	public function test_sanitize_preserves_truncation_uniqueness(): void {
		// Different long names should produce different hashes.
		$name1 = str_repeat( 'a', 200 );
		$name2 = str_repeat( 'a', 199 ) . 'b';

		$result1 = McpNameSanitizer::sanitize_name( $name1 );
		$result2 = McpNameSanitizer::sanitize_name( $name2 );

		$this->assertIsString( $result1 );
		$this->assertIsString( $result2 );
		$this->assertNotSame( $result1, $result2, 'Different inputs should produce different truncated names' );

		// Both should be 128 chars.
		$this->assertSame( 128, strlen( $result1 ) );
		$this->assertSame( 128, strlen( $result2 ) );
	}

	public function test_sanitize_max_length_boundary(): void {
		// Exactly 128 chars should not be truncated.
		$name_128 = str_repeat( 'a', 128 );
		$this->assertSame( $name_128, McpNameSanitizer::sanitize_name( $name_128 ) );

		// 129 chars should be truncated.
		$name_129 = str_repeat( 'a', 129 );
		$result   = McpNameSanitizer::sanitize_name( $name_129 );
		$this->assertIsString( $result );
		$this->assertSame( 128, strlen( $result ) );
	}

	// Error Cases

	public function test_sanitize_returns_error_for_empty(): void {
		// Empty string should return WP_Error.
		$result = McpNameSanitizer::sanitize_name( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_name_invalid', $result->get_error_code() );
	}

	public function test_sanitize_returns_error_for_whitespace_only(): void {
		// Whitespace-only should return WP_Error.
		$result = McpNameSanitizer::sanitize_name( '   ' );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_name_invalid', $result->get_error_code() );
	}

	public function test_sanitize_returns_error_for_only_invalid_chars(): void {
		// String with only invalid characters should return WP_Error.
		$result = McpNameSanitizer::sanitize_name( '!!!' );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_name_invalid', $result->get_error_code() );
	}

	public function test_sanitize_only_hyphens_is_valid(): void {
		// '---' is valid per MCP spec (only contains valid chars), so it passes through.
		$result = McpNameSanitizer::sanitize_name( '---' );
		$this->assertIsString( $result );
		$this->assertSame( '---', $result );
	}

	public function test_sanitize_returns_error_for_only_invalid_trimmed_to_empty(): void {
		// String with only invalid chars that become hyphens and get trimmed to empty.
		$result = McpNameSanitizer::sanitize_name( '@@@' );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_name_invalid', $result->get_error_code() );
	}

	// Unicode/Accent Handling Tests

	public function test_sanitize_transliterates_accents(): void {
		// Accented characters should be transliterated to ASCII.
		$this->assertSame( 'creer-post', McpNameSanitizer::sanitize_name( 'créer-post' ) );
		$this->assertSame( 'resume', McpNameSanitizer::sanitize_name( 'résumé' ) );
	}

	public function test_sanitize_transliterates_mixed_unicode(): void {
		// Mixed unicode should be transliterated.
		$this->assertSame( 'uber-cafe-naive', McpNameSanitizer::sanitize_name( 'über-café-naïve' ) );
	}

	public function test_sanitize_transliterates_german_umlaut(): void {
		// German umlauts should transliterate.
		$this->assertSame( 'Munchen', McpNameSanitizer::sanitize_name( 'München' ) );
		// Note: WordPress remove_accents() converts ß to 's', not 'ss'.
		$this->assertSame( 'strase', McpNameSanitizer::sanitize_name( 'straße' ) );
	}

	public function test_sanitize_transliterates_spanish(): void {
		// Spanish characters should transliterate.
		$this->assertSame( 'nino', McpNameSanitizer::sanitize_name( 'niño' ) );
		$this->assertSame( 'espanol', McpNameSanitizer::sanitize_name( 'español' ) );
	}

	// Edge Cases

	public function test_sanitize_preserves_leading_numbers(): void {
		// Names starting with numbers should be preserved.
		$this->assertSame( '123tool', McpNameSanitizer::sanitize_name( '123tool' ) );
		$this->assertSame( '123tool', McpNameSanitizer::sanitize_name( '!!!123tool' ) );
	}

	public function test_sanitize_handles_mixed_invalid_chars(): void {
		// Mixed invalid characters should be sanitized.
		$this->assertSame( 'my-tool-name', McpNameSanitizer::sanitize_name( 'my@tool#name' ) );
		$this->assertSame( 'foo-bar-baz', McpNameSanitizer::sanitize_name( 'foo$bar%baz' ) );
	}

	public function test_sanitize_preserves_dots_and_underscores(): void {
		// Dots and underscores are valid and should be preserved.
		$this->assertSame( 'foo.bar_baz', McpNameSanitizer::sanitize_name( 'foo.bar_baz' ) );
		$this->assertSame( 'api.v2.endpoint', McpNameSanitizer::sanitize_name( 'api.v2.endpoint' ) );
	}

	public function test_sanitize_complex_real_world_name(): void {
		// Real-world complex name with multiple issues.
		$this->assertSame(
			'my-plugin-create-post',
			McpNameSanitizer::sanitize_name( '  my-plugin/create post  ' )
		);
	}

	// Constants Tests

	public function test_constants_are_correct(): void {
		// Verify constants match MCP spec and truncation logic.
		$this->assertSame( 128, McpNameSanitizer::MAX_LENGTH );
		$this->assertSame( 12, McpNameSanitizer::HASH_LENGTH );
		$this->assertSame( 115, McpNameSanitizer::TRUNCATE_LENGTH );
		// Verify truncate + separator + hash = max length.
		$this->assertSame(
			McpNameSanitizer::MAX_LENGTH,
			McpNameSanitizer::TRUNCATE_LENGTH + 1 + McpNameSanitizer::HASH_LENGTH
		);
	}
}
