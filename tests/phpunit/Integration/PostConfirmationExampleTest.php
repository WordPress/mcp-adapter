<?php
/**
 * The documented confirmation wrapper through real dispatch and WordPress storage.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Schemas;

/** Executes the guide verbatim with a no-write Ability and actual options. */
final class PostConfirmationExampleTest extends TestCase {

	/** @var bool */
	private bool $allowed = true;

	/** @var int */
	private int $executions = 0;

	/** @var int */
	private int $permission_checks = 0;

	/** @var list<string> */
	private array $approval_keys = array();

	/** @var \WP\MCP\Core\McpServer */
	private McpServer $server;

	/** @var int */
	private int $sequence = 0;

	/** Register only a stub Ability; the tool is extracted from the guide. */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 1 );
		$this->register_ability_in_hook(
			'my-plugin/create-post',
			array(
				'label'               => 'Post confirmation test',
				'description'         => 'Returns approved input without creating posts.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
					),
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'permission_callback' => function (): bool {
					++$this->permission_checks;
					return $this->allowed;
				},
				'execute_callback'    => function ( array $input ): array {
					++$this->executions;
					return array(
						'post_id' => 123,
						'title'   => $input['title'],
					);
				},
			)
		);
		$guide = file_get_contents( dirname( __DIR__, 3 ) . '/docs/guides/mrtr.md' );
		$this->assertIsString( $guide );
		$this->assertSame( 1, preg_match( '/```php\n(.*?)\n```/s', $guide, $matches ) );
		$make_create_post_tool = null;
		eval( $matches[1] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute the repository's documented example verbatim, never user input.
		$this->assertIsCallable( $make_create_post_tool );
		$tool = $make_create_post_tool(
			static function (): string {
				return 'verified-test-client';
			}
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$this->server = $this->makeServer( array( $tool ) );
		add_action( 'added_option', array( $this, 'record_approval' ) );
	}

	/** Track only rows created by this example so cleanup cannot affect other data. */
	public function record_approval( string $name ): void {
		if ( 0 !== strpos( $name, 'my_plugin_post_approval_' ) ) {
			return;
		}

		$this->approval_keys[] = $name;
	}

	/** Remove this test's pending approvals and hook. */
	public function tear_down(): void {
		remove_action( 'added_option', array( $this, 'record_approval' ) );
		foreach ( $this->approval_keys as $key ) {
			delete_option( $key );
		}
		wp_unregister_ability( 'my-plugin/create-post' );
		parent::tear_down();
	}

	/** No approval is persisted for clients that cannot receive the question. */
	public function test_unsupported_clients_do_not_create_pending_rows(): void {
		foreach ( array( array(), array( 'elicitation' => array( 'url' => new \stdClass() ) ) ) as $capabilities ) {
			$response = $this->call( array(), $capabilities );
			$this->assertTrue( $response['result']['isError'] );
			$this->assertStringContainsString( 'requires MCP 2026-07-28 with form elicitation', $response['result']['content'][0]['text'] );
		}
		$legacy = $this->call( array(), array( 'elicitation' => new \stdClass() ), Schemas::V2025_11_25 );
		$this->assertTrue( $legacy['result']['isError'] );
		$this->assertSame( array(), $this->approval_keys );
		$this->assertSame( 0, $this->executions );
	}

	/** Ability permission denial precedes the question and any approval storage. */
	public function test_denied_initial_request_creates_no_approval(): void {
		$this->allowed = false;
		$response      = $this->call();
		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( array(), $this->approval_keys );
		$this->assertSame( 0, $this->executions );
	}

	/** Revoked permissions leave the pending approval unconsumed; a later allowed retry can use it. */
	public function test_denied_retry_does_not_consume_approval(): void {
		$first = $this->call()['result'];
		$this->assertSame( 'input_required', $first['resultType'] );
		$state         = $first['requestState'];
		$key           = 'my_plugin_post_approval_' . $state;
		$this->allowed = false;
		$denied        = $this->call( $this->answer( $state ) );
		$this->assertTrue( $denied['result']['isError'] );
		$this->assertIsArray( get_option( $key ) );
		$this->assertSame( 0, $this->executions );
		$this->allowed = true;
		$completed     = $this->call( $this->answer( $state ) );
		$this->assertFalse( $completed['result']['isError'] );
		$this->assertSame( 1, $this->executions );
		$this->assertFalse( get_option( $key ) );
		$replayed = $this->call( $this->answer( $state ) );
		$this->assertTrue( $replayed['result']['isError'] );
		$this->assertStringContainsString( 'unknown, expired, or already used', $replayed['result']['content'][0]['text'] );
		$this->assertStringContainsString( 'Check whether the draft exists before restarting', $replayed['result']['content'][0]['text'] );
		$this->assertSame( 1, $this->executions );
	}

	/** Valid input is established before the Ability's permission callback sees it. */
	public function test_invalid_domain_input_does_not_create_approval(): void {
		$response = $this->call(
			array(
				'arguments' => array(
					'title'   => 123,
					'content' => 'Body',
				),
			)
		);
		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( array(), $this->approval_keys );
		$this->assertSame( 0, $this->executions );
		$this->assertSame( 0, $this->permission_checks );
	}

	/** @return array<string, mixed> A complete confirmation retry. */
	private function answer( string $state ): array {
		return array(
			'requestState'   => $state,
			'inputResponses' => array(
				'allow_post_creation' => array(
					'action'  => 'accept',
					'content' => array( 'allow' => true ),
				),
			),
		);
	}

	/** @return array<string, mixed> Send a fresh request through the exact wire boundary. */
	private function call( array $extra = array(), ?array $capabilities = null, string $revision = Schemas::V2026_07_28 ): array {
		$params = array_merge(
			array(
				'name'      => 'create-post-with-confirmation',
				'arguments' => array(
					'title'   => 'Hello',
					'content' => 'Body',
				),
			),
			$extra
		);
		if ( Schemas::V2026_07_28 === $revision ) {
			$params['_meta'] = array(
				'io.modelcontextprotocol/protocolVersion' => $revision,
				'io.modelcontextprotocol/clientCapabilities' => (object) ( $capabilities ?? array( 'elicitation' => new \stdClass() ) ),
			);
		}
		$wire           = new McpWireOrchestrator( $this->server->create_transport_context() );
		$message        = $wire->decode(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => ++$this->sequence,
					'method'  => 'tools/call',
					'params'  => $params,
				)
			)
		);
		$legacy_context = Schemas::V2025_11_25 === $revision ? array( 'capabilities' => (object) $capabilities ) : null;
		return json_decode( (string) wp_json_encode( $wire->process( $message, 'STDIO', array(), $legacy_context )['response'] ), true );
	}
}
