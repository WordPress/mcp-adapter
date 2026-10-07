<?php
/**
 * Error factory contract tests.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;

/** Protects stable JSON-RPC envelopes, revision mappings, and HTTP statuses. */
final class McpErrorFactoryTest extends TestCase {

	/** Error envelopes preserve safe IDs and omit absent data. */
	public function test_error_envelopes_preserve_ids_and_optional_data(): void {
		$without_data = McpErrorFactory::create_error_response( 'request-7', -32000, 'Failed' );
		$this->assertSame( '2.0', $without_data['jsonrpc'] );
		$this->assertSame( 'request-7', $without_data['id'] );
		$this->assertSame( -32000, $without_data['error']['code'] );
		$this->assertSame( 'Failed', $without_data['error']['message'] );
		$this->assertArrayNotHasKey( 'data', $without_data['error'] );

		$with_data = McpErrorFactory::create_error_response( null, -32000, 'Failed', array( 'reason' => 'test' ) );
		$this->assertArrayHasKey( 'id', $with_data );
		$this->assertNull( $with_data['id'] );
		$this->assertSame( array( 'reason' => 'test' ), $with_data['error']['data'] );
	}

	/** Named errors retain their codes, messages, and contextual data. */
	public function test_named_errors_retain_contract_fields(): void {
		$missing_tool = McpErrorFactory::tool_not_found( 1, 'missing-tool' );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $missing_tool['error']['code'] );
		$this->assertStringContainsString( 'missing-tool', $missing_tool['error']['message'] );

		$legacy_resource = McpErrorFactory::resource_not_found( 2, 'file:///missing', Schemas::V2025_11_25 );
		$modern_resource = McpErrorFactory::resource_not_found( 2, 'file:///missing', Schemas::V2026_07_28 );
		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $legacy_resource['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $modern_resource['error']['code'] );
		$this->assertSame( array( 'uri' => 'file:///missing' ), $modern_resource['error']['data'] );

		$unsupported = McpErrorFactory::unsupported_protocol_version( 3, '2099-01-01', McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS );
		$this->assertSame( McpErrorFactory::UNSUPPORTED_VERSION, $unsupported['error']['code'] );
		$this->assertSame( '2099-01-01', $unsupported['error']['data']['requested'] );
		$this->assertSame( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS, $unsupported['error']['data']['supported'] );
	}

	/**
	 * Every public protocol error code keeps its HTTP mapping.
	 *
	 * @dataProvider http_status_cases
	 *
	 * @param mixed $code Error code.
	 */
	public function test_http_status_mapping( $code, int $expected ): void {
		$this->assertSame( $expected, McpErrorFactory::mcp_error_to_http_status( $code ) );
		$this->assertSame(
			$expected,
			McpErrorFactory::get_http_status_for_error(
				array(
					'error' => array( 'code' => $code ),
				)
			)
		);
	}

	/** @return array<string, array{0: mixed, 1: int}> */
	public function http_status_cases(): array {
		return array(
			'parse error'         => array( McpErrorFactory::PARSE_ERROR, 400 ),
			'invalid request'     => array( McpErrorFactory::INVALID_REQUEST, 400 ),
			'header mismatch'     => array( McpErrorFactory::HEADER_MISMATCH, 400 ),
			'unsupported version' => array( McpErrorFactory::UNSUPPORTED_VERSION, 400 ),
			'unauthorized'        => array( McpErrorFactory::UNAUTHORIZED, 401 ),
			'permission denied'   => array( McpErrorFactory::PERMISSION_DENIED, 403 ),
			'resource missing'    => array( McpErrorFactory::RESOURCE_NOT_FOUND, 404 ),
			'tool missing'        => array( McpErrorFactory::TOOL_NOT_FOUND, 404 ),
			'prompt missing'      => array( McpErrorFactory::PROMPT_NOT_FOUND, 404 ),
			'session missing'     => array( McpErrorFactory::SESSION_NOT_FOUND, 404 ),
			'method missing'      => array( McpErrorFactory::METHOD_NOT_FOUND, 404 ),
			'internal error'      => array( McpErrorFactory::INTERNAL_ERROR, 500 ),
			'server error'        => array( McpErrorFactory::SERVER_ERROR, 500 ),
			'timeout'             => array( McpErrorFactory::TIMEOUT_ERROR, 504 ),
			'invalid params'      => array( McpErrorFactory::INVALID_PARAMS, 200 ),
			'unknown'             => array( -31999, 200 ),
			'numeric string'      => array( (string) McpErrorFactory::PARSE_ERROR, 400 ),
			'non-numeric string'  => array( 'invalid', 200 ),
		);
	}
}
