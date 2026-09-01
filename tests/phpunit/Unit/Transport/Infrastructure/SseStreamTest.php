<?php
/**
 * Tests for SseStream class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\SseStream;

/**
 * Test SseStream functionality.
 */
final class SseStreamTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'mcp_adapter_sse_stream_duration' );
		remove_all_filters( 'mcp_adapter_sse_ping_interval' );

		parent::tear_down();
	}

	public function test_format_comment_is_a_valid_sse_comment_frame(): void {
		$frame = SseStream::format_comment( 'ping' );

		$this->assertSame( ": ping\n\n", $frame );
	}

	public function test_get_stream_duration_defaults_to_thirty_seconds(): void {
		$this->assertSame( 30, SseStream::get_stream_duration() );
	}

	public function test_get_stream_duration_respects_filter(): void {
		add_filter(
			'mcp_adapter_sse_stream_duration',
			static function () {
				return 5;
			}
		);

		$this->assertSame( 5, SseStream::get_stream_duration() );
	}

	public function test_get_stream_duration_clamps_negative_values_to_zero(): void {
		add_filter(
			'mcp_adapter_sse_stream_duration',
			static function () {
				return -10;
			}
		);

		$this->assertSame( 0, SseStream::get_stream_duration() );
	}

	public function test_get_ping_interval_defaults_to_fifteen_seconds(): void {
		$this->assertSame( 15, SseStream::get_ping_interval() );
	}

	public function test_get_ping_interval_clamps_to_at_least_one_second(): void {
		add_filter(
			'mcp_adapter_sse_ping_interval',
			static function () {
				return 0;
			}
		);

		$this->assertSame( 1, SseStream::get_ping_interval() );
	}

	public function test_stream_with_zero_duration_sends_only_the_open_comment(): void {
		// With a zero-second duration, stream() must open (and send) the
		// stream and return immediately instead of entering the keep-alive
		// loop. stream() calls the real flush(), which bypasses ordinary
		// output buffering, so a callback-based buffer is used to both
		// capture and swallow the bytes instead of letting them reach the
		// terminal.
		add_filter(
			'mcp_adapter_sse_stream_duration',
			static function () {
				return 0;
			}
		);

		$captured = '';
		ob_start(
			static function ( string $buffer ) use ( &$captured ): string {
				$captured .= $buffer;

				return '';
			}
		);

		( new SseStream() )->stream();

		ob_end_clean();

		$this->assertSame( SseStream::format_comment( 'stream-open' ), $captured );
	}
}
