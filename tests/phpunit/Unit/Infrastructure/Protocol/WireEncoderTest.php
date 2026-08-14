<?php
/**
 * Tests for the descriptor-backed wire encoder.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Protocol;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Infrastructure\Protocol\WireEncoder;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Runtime\ValidationException;

final class WireEncoderTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		DummyErrorHandler::reset();
	}

	private function encoder(): WireEncoder {
		return new WireEncoder( McpProtocolContext::default(), new DummyErrorHandler() );
	}

	public function test_encode_returns_the_wire_shape(): void {
		$wire = $this->encoder()->list_tools_result(
			array(
				'tools' => array(
					array(
						'name'        => 'demo',
						'inputSchema' => array( 'type' => 'object' ),
					),
				),
			)
		);

		$this->assertSame( 'demo', $wire['tools'][0]['name'] );
		$this->assertSame(
			'{"tools":[{"name":"demo","inputSchema":{"type":"object"}}]}',
			wp_json_encode( $wire )
		);
	}

	public function test_encode_keeps_an_empty_properties_map_as_a_json_object(): void {
		$wire = $this->encoder()->try_tool(
			array(
				'name'        => 'no-arguments',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
			'no-arguments'
		);

		// Populated levels stay readable as arrays; only the empty map has to
		// remain an object, because an empty PHP array would emit [].
		$this->assertIsArray( $wire );
		$this->assertIsArray( $wire['inputSchema'] );
		$this->assertInstanceOf( \stdClass::class, $wire['inputSchema']['properties'] );
		$this->assertStringContainsString( '"properties":{}', (string) wp_json_encode( $wire ) );
	}

	public function test_encode_throws_when_the_payload_does_not_match_the_type(): void {
		$this->expectException( ValidationException::class );

		$this->encoder()->list_tools_result( array( 'tools' => 'not-a-list' ) );
	}

	public function test_try_encode_returns_null_and_logs_instead_of_throwing(): void {
		$this->setExpectedIncorrectUsage( 'WP\MCP\Infrastructure\Protocol\WireEncoder::report_failure' );

		$wire = $this->encoder()->try_tool( array( 'name' => 'broken' ), 'broken' );

		$this->assertNull( $wire );
		$this->assertNotEmpty( DummyErrorHandler::$logs );

		$log = DummyErrorHandler::$logs[0];
		$this->assertSame( 'Tool', $log['context']['type'] );
		$this->assertSame( 'broken', $log['context']['subject'] );
		$this->assertSame( '2025-11-25', $log['context']['revision'] );
	}

	public function test_try_encode_returns_the_wire_shape_when_the_payload_is_valid(): void {
		$wire = $this->encoder()->try_tool(
			array(
				'name'        => 'fine',
				'inputSchema' => array( 'type' => 'object' ),
			),
			'fine'
		);

		$this->assertIsArray( $wire );
		$this->assertSame( 'fine', $wire['name'] );
		$this->assertSame( array(), DummyErrorHandler::$logs );
	}

	public function test_revision_reports_the_negotiated_revision(): void {
		$this->assertSame( '2025-11-25', $this->encoder()->revision() );
	}
}
