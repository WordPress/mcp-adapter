<?php
/**
 * Tests for HTTP method constants and preserved request dispatch behavior.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP_REST_Request;

/** Verify method handling without changing its existing normalization policy. */
final class HttpRequestMethodTest extends TestCase {

	/** Named constants must retain the established wire method strings. */
	public function test_method_constant_values(): void {
		$this->assertSame( 'POST', HttpRequestContext::METHOD_POST );
		$this->assertSame( 'DELETE', HttpRequestContext::METHOD_DELETE );
	}

	/** WordPress REST requests normalize input method case before the context copies it. */
	public function test_post_methods_preserve_exact_request_body(): void {
		foreach ( array( 'POST', 'post', 'Post' ) as $method ) {
			$request = new WP_REST_Request( $method, '/mcp' );
			$request->set_body( '{"example":"content"}' );
			$context = new HttpRequestContext( $request );
			$this->assertSame( 'POST', $context->method );
			$this->assertSame( '{"example":"content"}', $context->raw_body );
		}
	}

	/** Non-POST requests do not copy their supplied bodies into the MCP context. */
	public function test_non_post_methods_have_empty_context_body(): void {
		foreach ( array( 'GET', 'DELETE', 'PUT', 'PATCH', 'HEAD', 'OPTIONS', '', 'CUSTOM' ) as $method ) {
			$request = new WP_REST_Request( $method, '/mcp' );
			$request->set_body( 'do-not-copy' );
			$context = new HttpRequestContext( $request );
			$this->assertSame( '', $context->raw_body );
		}
	}

	/** Method and body are captured once when the HTTP context is constructed. */
	public function test_request_context_preserves_method_and_body_snapshot(): void {
		$request = new WP_REST_Request( 'POST', '/mcp' );
		$request->set_body( 'original-body' );
		$context = new HttpRequestContext( $request );
		$request->set_method( 'DELETE' );
		$request->set_body( 'later-body' );
		$this->assertSame( 'POST', $context->method );
		$this->assertSame( 'original-body', $context->raw_body );
	}

	/** Preserve POST processing for every currently selected protocol-header input. */
	public function test_post_dispatch_selection_is_unchanged(): void {
		$wire = new McpWireOrchestrator( $this->makeServer()->create_transport_context() );
		foreach ( array( null, '2025-11-25', '2025-06-18', '2026-07-28', 'unknown' ) as $version ) {
			$this->assertSame( 'process', $wire->http_method_action( 'POST', $version ) );
		}
	}

	/** Legacy DELETE selects termination; modern and unknown revisions reject it. */
	public function test_delete_dispatch_selection_is_unchanged(): void {
		$wire = new McpWireOrchestrator( $this->makeServer()->create_transport_context() );
		foreach ( array( null, '2025-11-25', '2025-06-18', '2024-11-05' ) as $version ) {
			$this->assertSame( 'terminate-session', $wire->http_method_action( 'DELETE', $version ) );
		}
		foreach ( array( '2026-07-28', '2025-03-26', 'unknown' ) as $version ) {
			$this->assertSame( 'reject', $wire->http_method_action( 'DELETE', $version ) );
		}
	}

	/** Direct method dispatch remains exact and does not normalize arbitrary input. */
	public function test_rejects_other_or_unnormalized_direct_methods(): void {
		$wire = new McpWireOrchestrator( $this->makeServer()->create_transport_context() );
		foreach ( array( 'GET', 'PUT', 'PATCH', 'HEAD', 'OPTIONS', 'post', 'delete', ' POST ', '', 'UNKNOWN' ) as $method ) {
			$this->assertSame( 'reject', $wire->http_method_action( $method, '2025-11-25' ) );
			$this->assertSame( 'reject', $wire->http_method_action( $method, '2026-07-28' ) );
		}
	}
}
