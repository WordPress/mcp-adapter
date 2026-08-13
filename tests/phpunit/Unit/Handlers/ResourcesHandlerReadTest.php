<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Domain\Continuation\McpContinuationContext;
use WP\MCP\Domain\Continuation\McpExecutionResult;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerReadTest extends TestCase {

	public function test_continuation_context_and_result_pass_through_unchanged(): void {
		$received_context = null;
		$expected         = McpExecutionResult::input_required(
			array( 'choose' => array( 'method' => 'elicitation/create' ) )
		);
		$resource         = McpResource::fromArray(
			array(
				'uri'        => 'test://continuing-resource',
				'name'       => 'Continuing resource',
				'handler'    => static function ( array $arguments, McpContinuationContext $context ) use ( &$received_context, $expected ): McpExecutionResult {
					$received_context = $context;

					return $expected;
				},
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );

		$context = new McpContinuationContext( array( 'choose' => 'one' ) );
		$result  = ( new ResourcesHandler( $this->makeServer( array(), array( $resource ) ) ) )->read_resource(
			array( 'uri' => 'test://continuing-resource' ),
			1,
			$context
		);

		$this->assertSame( $context, $received_context );
		$this->assertSame( $expected, $result );
	}

	public function test_missing_uri_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array() ) );

		$this->assertSame( -32602, $result['error']['code'] );
		$this->assertNotEmpty( $result['error']['message'] );
	}

	public function test_unknown_resource_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer();
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://missing' ) ) );

		$this->assertSame( -32002, $result['error']['code'] );
		$this->assertNotEmpty( $result['error']['message'] );
	}

	public function test_successful_read_returns_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) ) );

		$contents = $result['contents'];
		$this->assertNotEmpty( $contents );
		$this->assertArrayHasKey( 'text', $contents[0] );
	}

	public function test_read_resource_returns_blob_contents_for_blob_data(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-blob-content' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-blob-content' ) )
		);

		$contents = $result['contents'];
		$this->assertNotEmpty( $contents );

		// Verify blob content.
		$blob = $contents[0]['blob'];
		$this->assertNotEmpty( $blob );

		// Verify mimeType is preserved.
		$this->assertSame( 'application/octet-stream', $contents[0]['mimeType'] );
	}

	public function test_read_resource_handles_multiple_content_items(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-multiple-contents' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-multiple-contents' ) )
		);

		$contents = $result['contents'];
		$this->assertCount( 2, $contents, 'Should have 2 content items' );

		// Verify content.
		$this->assertSame( 'First content part', $contents[0]['text'] );
		$this->assertSame( 'Second content part', $contents[1]['text'] );

		// Verify URIs are preserved.
		$this->assertSame( 'WordPress://local/resource-multi/part1', $contents[0]['uri'] );
		$this->assertSame( 'WordPress://local/resource-multi/part2', $contents[1]['uri'] );
	}

	public function test_read_resource_returns_text_with_custom_mimetype(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-text-with-mimetype' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-text-with-mimetype' ) )
		);

		$contents = $result['contents'];
		$this->assertNotEmpty( $contents );

		// Verify mimeType is preserved.
		$this->assertSame( 'application/json', $contents[0]['mimeType'] );

		// Verify content.
		$this->assertSame( '{"key": "value"}', $contents[0]['text'] );
	}

	public function test_read_resource_wraps_plain_string_as_text(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-plain-string' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-plain-string' ) )
		);

		$contents = $result['contents'];
		$this->assertNotEmpty( $contents );

		// Verify content is the plain string.
		$this->assertSame( 'plain string content', $contents[0]['text'] );

		// Verify URI is the resource URI.
		$this->assertSame( 'WordPress://local/resource-plain-string', $contents[0]['uri'] );
	}

	public function test_read_resource_with_lowercased_scheme_echoes_advertised_uri(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-plain-string' ) );
		$handler = new ResourcesHandler( $server );

		// The resource is advertised with a mixed-case scheme (WordPress://...). A client
		// that canonicalizes the scheme to lowercase per RFC 3986 3.1 still resolves it, and
		// the response must echo the advertised URI, not the request's lowercased scheme, so
		// contents[].uri stays consistent with resources/list.
		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'wordpress://local/resource-plain-string' ) ) // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- The lowercase scheme is the point of the test.
		);

		$contents = $result['contents'];
		$this->assertNotEmpty( $contents );
		$this->assertSame(
			'WordPress://local/resource-plain-string',
			$contents[0]['uri'],
			'Read response must echo the advertised URI, not the client-lowercased scheme.'
		);
	}

	public function test_pre_resource_read_filter_can_modify_params(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$received_params = null;
		$received_uri    = null;
		$filter          = static function ( array $params, string $uri ) use ( &$received_params, &$received_uri ): array {
			$received_params = $params;
			$received_uri    = $uri;

			return $params;
		};
		add_filter( 'mcp_adapter_pre_resource_read', $filter, 10, 2 );

		$handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertIsArray( $received_params );
		$this->assertSame( 'WordPress://local/resource-1', $received_uri );

		remove_filter( 'mcp_adapter_pre_resource_read', $filter );
	}

	public function test_pre_resource_read_filter_can_short_circuit_with_wp_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return new \WP_Error( 'blocked', 'Resource access blocked' );
		};
		add_filter( 'mcp_adapter_pre_resource_read', $filter );

		$result = $handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertStringContainsString( 'Resource access blocked', $result['error']['message'] );

		remove_filter( 'mcp_adapter_pre_resource_read', $filter );
	}

	public function test_resource_read_result_filter_can_modify_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter_was_called = false;
		$filter            = static function ( $contents ) use ( &$filter_was_called ) {
			$filter_was_called = true;

			return $contents;
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertTrue( $filter_was_called );

		remove_filter( 'mcp_adapter_resource_read_result', $filter );
	}

	public function test_read_resource_wraps_non_array_result_as_json(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns an object (associative array without uri/text keys).
		$this->register_ability_in_hook(
			'test/resource-object-result',
			array(
				'label'               => 'Resource Object Result',
				'description'         => 'Returns an object result',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array(
						'status' => 'ok',
						'count'  => 42,
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-object-result',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array( 'test/resource-object-result' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-object-result' ) )
		);

		$contents = $result['contents'];
		$this->assertNotEmpty( $contents );

		// Verify content is JSON-encoded.
		$text = $contents[0]['text'];
		$this->assertJson( $text );
		$decoded = json_decode( $text, true );
		$this->assertSame( 'ok', $decoded['status'] );
		$this->assertSame( 42, $decoded['count'] );

		// Clean up.
		wp_unregister_ability( 'test/resource-object-result' );
	}

	public function test_read_resource_with_throwing_result_filter_triggers_catch_block(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			throw new \RuntimeException( 'Filter exploded' );
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertStringContainsString( 'Failed to read resource', $result['error']['message'] );

		remove_filter( 'mcp_adapter_resource_read_result', $filter );
	}

	public function test_read_resource_preserves_meta_on_text_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'      => 'ui://example/app',
					'mimeType' => 'text/html;profile=mcp-app',
					'text'     => '<!doctype html>',
					'_meta'    => array( 'ui' => array( 'prefersBorder' => true ) ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$contents = $result['contents'];
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $contents[0]['_meta'] );
	}

	public function test_read_resource_with_list_meta_omits_it_from_the_wire(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'   => 'WordPress://local/resource-1',
					'text'  => 'body',
					'_meta' => array( 'a', 'b' ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$contents = $result['contents'];

		// MCP declares _meta as a JSON object; a list would serialize as `"_meta": ["a","b"]`.
		$this->assertArrayNotHasKey( '_meta', $contents[0] );
	}

	public function test_read_resource_with_blob_only_item_preserves_meta(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'mimeType' => 'application/pdf',
					'blob'     => 'ZGF0YQ==',
					'_meta'    => array( 'pages' => 3 ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$contents = $result['contents'];
		$this->assertSame( 'WordPress://local/resource-1', $contents[0]['uri'] );
		$this->assertSame( array( 'pages' => 3 ), $contents[0]['_meta'] );
	}
}
