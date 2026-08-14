<?php
/**
 * Tests for the MCP 2026-07-28 wire encoder.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Protocol;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Infrastructure\Protocol\V20260728WireEncoder;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Runtime\ValidationException;

final class V20260728WireEncoderTest extends TestCase {

	private function encoder(): V20260728WireEncoder {
		return new V20260728WireEncoder(
			McpProtocolContext::for_revision( '2026-07-28' ),
			new DummyErrorHandler(),
			array(
				'name'    => 'Modern Test Server',
				'version' => '2.0.0',
			)
		);
	}

	public function test_cacheable_result_has_exact_modern_enrichment(): void {
		$result = $this->encoder()->list_tools_result( array( 'tools' => array() ) );

		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( 0, $result['ttlMs'] );
		$this->assertSame( 'private', $result['cacheScope'] );
		$this->assertSame(
			array(
				'name'    => 'Modern Test Server',
				'version' => '2.0.0',
			),
			$result['_meta']['io.modelcontextprotocol/serverInfo']
		);
	}

	public function test_non_cacheable_result_omits_cache_fields(): void {
		$result = $this->encoder()->call_tool_result(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'ok',
					),
				),
			)
		);

		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertArrayNotHasKey( 'ttlMs', $result );
		$this->assertArrayNotHasKey( 'cacheScope', $result );
	}

	public function test_input_required_result_uses_exact_modern_union_arm(): void {
		$result = $this->encoder()->input_required_result(
			array(
				'resultType'    => 'input_required',
				'inputRequests' => array(
					'confirm' => array(
						'method' => 'elicitation/create',
						'params' => array(
							'mode'            => 'form',
							'message'         => 'Confirm',
							'requestedSchema' => array(
								'type'       => 'object',
								'properties' => array(),
							),
						),
					),
				),
				'requestState'  => 'sealed',
			)
		);

		$this->assertSame( 'input_required', $result['resultType'] );
		$this->assertSame( 'sealed', $result['requestState'] );
		$this->assertArrayHasKey( 'confirm', $result['inputRequests'] );
		$this->assertArrayNotHasKey( 'ttlMs', $result );
		$this->assertArrayNotHasKey( 'cacheScope', $result );
	}

	public function test_continuation_request_params_are_schema_validated_and_normalized(): void {
		$params = $this->encoder()->continuation_request_params(
			'tools/call',
			array(
				'name'           => 'weather',
				'arguments'      => array( 'city' => 'Bucharest' ),
				'inputResponses' => array(
					'confirm' => array(
						'action'  => 'accept',
						'content' => array( 'confirmed' => true ),
					),
				),
				'requestState'   => 'sealed',
				'_meta'          => array(
					'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities' => array(
						'elicitation' => array(),
					),
				),
			)
		);

		$this->assertSame( 'sealed', $params['requestState'] );
		$this->assertSame( 'accept', $params['inputResponses']['confirm']['action'] );
	}

	public function test_request_metadata_is_validated_by_the_modern_schema(): void {
		$this->encoder()->validate_request_metadata(
			array(
				'_meta' => array(
					'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities' => array(),
				),
			)
		);

		$this->addToAssertionCount( 1 );
	}

	public function test_missing_client_capabilities_fail_metadata_validation(): void {
		$this->expectException( ValidationException::class );

		$this->encoder()->validate_request_metadata(
			array(
				'_meta' => array(
					'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
				),
			)
		);
	}

	public function test_typed_protocol_errors_are_schema_validated(): void {
		$encoder = $this->encoder();

		$header = $encoder->header_mismatch_error( 1 );
		$this->assertSame( -32020, $header['error']['code'] );

		$unsupported = $encoder->unsupported_protocol_version_error( 2, '1900-01-01', array( '2026-07-28', '2025-11-25' ) );
		$this->assertSame( -32022, $unsupported['error']['code'] );
		$this->assertSame( '1900-01-01', $unsupported['error']['data']['requested'] );

		$capability = $encoder->missing_required_client_capability_error(
			3,
			array( 'elicitation' => array( 'form' => array() ) )
		);
		$this->assertSame( -32021, $capability['error']['code'] );
		$this->assertArrayHasKey( 'elicitation', $capability['error']['data']['requiredCapabilities'] );
	}
}
