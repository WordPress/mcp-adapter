<?php
/**
 * Request-boundary cache freshness for long-lived STDIO bridges.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Cli\StdioServerBridge;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;

/** Exercises repeated requests without restarting WordPress or the bridge. */
final class StdioCacheInvalidationTest extends TestCase {

	private const OPTION = 'mcp_adapter_test_stdio_cache';

	/** Select the default cache for deterministic database/cache divergence. */
	public function set_up(): void {
		parent::set_up();
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'Native cache regressions require the default, non-persistent object cache.' );
		}
		add_option( self::OPTION, 'before', '', true );
	}

	/** Remove database and runtime state even when an assertion fails. */
	public function tearDown(): void {
		try {
			remove_filter( 'mcp_adapter_stdio_flush_runtime_cache', '__return_false' );
			delete_option( self::OPTION );
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		} finally {
			parent::tearDown();
		}
	}

	/** An external-style update becomes visible on the same initialized connection. */
	public function test_successive_tool_calls_read_current_data(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'cache-reader',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static fn(): array => array( 'value' => get_option( self::OPTION ) ),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$bridge = new StdioServerBridge( $this->makeServer( array( $tool ) ) );
		$this->initialize( $bridge );

		$first = $this->call_tool( $bridge );
		$this->assertSame( 'before', $first['result']['structuredContent']['value'] );
		$this->change_database_without_invalidating_cache();
		$second = $this->call_tool( $bridge );
		$this->assertSame( 'after', $second['result']['structuredContent']['value'] );
	}

	/** A permission callback must not authorize execution using an earlier value. */
	public function test_permission_checks_read_current_data(): void {
		$executions = 0;
		$tool       = McpTool::fromArray(
			array(
				'name'        => 'cache-reader',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static function () use ( &$executions ): array {
					++$executions;
					return array( 'ok' => true );
				},
				'permission'  => static fn(): bool => 'before' === get_option( self::OPTION ),
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$bridge = new StdioServerBridge( $this->makeServer( array( $tool ) ) );
		$this->initialize( $bridge );
		$this->call_tool( $bridge );
		$this->assertSame( 1, $executions );
		$this->change_database_without_invalidating_cache();
		$this->call_tool( $bridge );
		$this->assertSame( 1, $executions, 'A stale permission check allowed another execution.' );
	}

	/** An explicit opt-out preserves the existing cache lifetime. */
	public function test_filter_can_disable_invalidation(): void {
		add_filter( 'mcp_adapter_stdio_flush_runtime_cache', '__return_false' );
		$tool = McpTool::fromArray(
			array(
				'name'        => 'cache-reader',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static fn(): array => array( 'value' => get_option( self::OPTION ) ),
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$bridge = new StdioServerBridge( $this->makeServer( array( $tool ) ) );
		$this->initialize( $bridge );
		$this->call_tool( $bridge );
		$this->change_database_without_invalidating_cache();
		$response = $this->call_tool( $bridge );
		$this->assertSame( 'before', $response['result']['structuredContent']['value'] );
	}

	/** A write must check the current value rather than overwrite an external edit. */
	public function test_write_precondition_reads_current_data(): void {
		$tool = McpTool::fromArray(
			array(
				'name'        => 'cache-reader',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static function () {
					if ( 'before' !== get_option( self::OPTION ) ) {
						return new \WP_Error( 'state_changed', 'The option has changed.' );
					}
					update_option( self::OPTION, 'overwritten' );
					return array( 'written' => true );
				},
				'permission'  => '__return_true',
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$bridge = new StdioServerBridge( $this->makeServer( array( $tool ) ) );
		$this->initialize( $bridge );
		$this->change_database_without_invalidating_cache();
		$response = $this->call_tool( $bridge );
		$this->assertTrue( $response['result']['isError'] );
		$this->assertSame( 'after', get_option( self::OPTION ) );
	}

	/**
	 * Other components share the same freshness boundary.
	 *
	 * @dataProvider provide_read_components
	 * @param string $component Component kind.
	 */
	public function test_resources_and_prompts_read_current_data( string $component ): void {
		$resource = McpResource::fromArray(
			array(
				'uri'        => 'cache://option',
				'name'       => 'cache-option',
				'handler'    => static fn(): array => array(
					array(
						'uri'  => 'cache://option',
						'text' => get_option( self::OPTION ),
					),
				),
				'permission' => '__return_true',
			)
		);
		$prompt   = McpPrompt::fromArray(
			array(
				'name'       => 'cache-prompt',
				'handler'    => static fn(): array => array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => array(
								'type' => 'text',
								'text' => get_option( self::OPTION ),
							),
						),
					),
				),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$this->assertInstanceOf( McpPrompt::class, $prompt );
		$bridge = new StdioServerBridge( $this->makeServer( array(), array( $resource ), array( $prompt ) ) );
		$this->initialize( $bridge );
		$payload = array(
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'resource' === $component ? 'resources/read' : 'prompts/get',
			'params'  => 'resource' === $component ? array( 'uri' => 'cache://option' ) : array( 'name' => 'cache-prompt' ),
		);
		$first   = $this->request( $bridge, $payload );
		$this->assertStringContainsString( '"before"', wp_json_encode( $first['result'] ) );
		$this->change_database_without_invalidating_cache();
		$second = $this->request( $bridge, $payload );
		$this->assertStringContainsString( '"after"', wp_json_encode( $second['result'] ) );
		$this->assertStringNotContainsString( '"before"', wp_json_encode( $second['result'] ) );
	}

	/** @return array<string, array{string}> */
	public static function provide_read_components(): array {
		return array(
			'resource' => array( 'resource' ),
			'prompt'   => array( 'prompt' ),
		);
	}

	/**
	 * Exercise cache doubles and actual STDIN/STDOUT/STDERR in isolated processes.
	 *
	 * @dataProvider provide_backend_scenarios
	 * @param bool|null $external External cache flag.
	 * @param bool      $supported Runtime flushing support.
	 * @param string    $result Backend return mode.
	 * @param bool      $disabled Whether the opt-out filter is installed.
	 */
	public function test_backend_compatibility( ?bool $external, bool $supported, string $result, bool $disabled ): void {
		$process = $this->run_bridge_process(
			array(
				'external'  => $external,
				'supported' => $supported,
				'result'    => $result,
				'disabled'  => $disabled,
			)
		);
		$fails   = ! $disabled && ( ! $external || $supported ) && in_array( $result, array( 'false', 'throw' ), true );
		$flushes = $disabled || ( $external && ! $supported ) ? 0 : 4;
		$this->assertCount( 3, $process['responses'], 'Notifications must never emit a response.' );
		$this->assertSame( array( 1, 42, 'cache-call' ), array_column( $process['responses'], 'id' ) );
		$this->assertSame( $external ? 0 : $flushes, $process['state']['full_calls'] );
		$this->assertSame( $external ? $flushes : 0, $process['state']['runtime_calls'] );
		$this->assertSame( $fails ? 0 : 2, $process['state']['executions'] );
		if ( $external ) {
			$this->assertTrue( $process['state']['shared_entry'], 'Shared persistent data must not be flushed.' );
		}
		if ( ! $fails && $flushes > 0 ) {
			$this->assertFalse( $process['state']['local_entry'] );
		}
		$warnings = substr_count( $process['stderr'], 'does not support runtime flushing' );
		$this->assertSame( $external && ! $supported && ! $disabled ? 1 : 0, $warnings );
		foreach ( $process['responses'] as $response ) {
			if ( $fails ) {
				$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response['error']['code'] );
				$this->assertStringNotContainsString( 'Cache backend test exception', wp_json_encode( $response ) );
			} else {
				$this->assertArrayNotHasKey( 'error', $response );
			}
		}
		if ( ! $fails ) {
			return;
		}

		$this->assertSame( 4, substr_count( $process['stderr'], 'Error resetting runtime object cache:' ) );
	}

	/** @return array<string, array{bool|null, bool, string, bool}> */
	public static function provide_backend_scenarios(): array {
		return array(
			'default cache'            => array( false, false, 'true', false ),
			'null external flag'       => array( null, false, 'true', false ),
			'external runtime'         => array( true, true, 'true', false ),
			'legacy null return'       => array( true, true, 'null', false ),
			'unsupported external'     => array( true, false, 'true', false ),
			'disabled supported'       => array( true, true, 'throw', true ),
			'disabled unsupported'     => array( true, false, 'throw', true ),
			'default explicit failure' => array( false, false, 'false', false ),
			'runtime explicit failure' => array( true, true, 'false', false ),
			'runtime exception'        => array( true, true, 'throw', false ),
		);
	}

	/** Invalid messages retain their errors even when the backend would fail. */
	public function test_invalid_envelopes_do_not_attempt_cache_operations(): void {
		$process = $this->run_bridge_process(
			array(
				'external'  => true,
				'supported' => true,
				'result'    => 'throw',
				'disabled'  => false,
			),
			array(
				'{',
				'[]',
				'{}',
				'{"jsonrpc":"1.0","method":"ping","id":7}',
				'{"jsonrpc":"2.0","method":"ping","id":null}',
				'{"jsonrpc":"2.0","method":7,"id":"bad-method"}',
				'{"jsonrpc":"2.0","method":"ping","id":[]}',
			)
		);
		$this->assertCount( 7, $process['responses'] );
		$this->assertSame( McpErrorFactory::PARSE_ERROR, $process['responses'][0]['error']['code'] );
		foreach ( array_slice( $process['responses'], 1 ) as $response ) {
			$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['error']['code'] );
		}
		$this->assertSame( 7, $process['responses'][3]['id'] );
		$this->assertSame( 'bad-method', $process['responses'][5]['id'] );
		$this->assertSame( 0, $process['state']['runtime_calls'] );
		$this->assertStringNotContainsString( 'Error resetting runtime object cache:', $process['stderr'] );
	}

	/**
	 * @param array<string, mixed> $configuration Backend scenario.
	 * @param list<string>|null    $input Raw messages, or the standard initialized session.
	 * @return array{responses: list<array<string, mixed>>, stderr: string, state: array<string, mixed>}
	 */
	private function run_bridge_process( array $configuration, ?array $input = null ): array {
		$this->assertTrue( function_exists( 'proc_open' ), 'STDIO stream tests require proc_open().' );
		$core_test_class                      = new \ReflectionClass( \WP_UnitTestCase::class );
		$test_root                            = dirname( $core_test_class->getFileName(), 2 );
		$environment                          = getenv();
		$environment['WP_TESTS_SKIP_INSTALL'] = '1';
		$pipes                                = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolate backend functions and capture actual protocol streams.
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/StdioCacheProcess.php', $test_root, wp_json_encode( $configuration ) ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$environment
		);
		$this->assertIsResource( $process );
		$input = $input ?? array(
			'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"cache-test","version":"1.0"}}}',
			'{"jsonrpc":"2.0","method":"notifications/initialized"}',
			'{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"cache-probe","arguments":{}}}',
			'{"jsonrpc":"2.0","id":"cache-call","method":"tools/call","params":{"name":"cache-probe","arguments":{}}}',
		);
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Write test messages to the subprocess STDIN pipe.
		fwrite( $pipes[0], implode( "\n", $input ) . "\n" );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $stdout . $stderr );
		$this->assertSame( 1, preg_match( '/^CACHE_TEST_STATE:(.+)$/m', $stderr, $matches ), $stderr );
		$state = json_decode( $matches[1], true );
		$this->assertIsArray( $state );
		$responses = array();
		foreach ( explode( "\n", trim( $stdout ) ) as $line ) {
			$response = json_decode( $line, true );
			$this->assertIsArray( $response, 'Non-protocol output on STDOUT: ' . $stdout );
			$responses[] = $response;
		}
		return array(
			'responses' => $responses,
			'stderr'    => $stderr,
			'state'     => $state,
		);
	}

	/** Change persistent data without notifying this process's object cache. */
	private function change_database_without_invalidating_cache(): void {
		global $wpdb;
		$this->assertSame( 'before', get_option( self::OPTION ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Deliberately bypass invalidation to simulate another process.
		$this->assertSame( 1, $wpdb->update( $wpdb->options, array( 'option_value' => 'after' ), array( 'option_name' => self::OPTION ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify that only the runtime cache is stale.
		$this->assertSame( 'after', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::OPTION ) ) );
		$this->assertSame( 'before', get_option( self::OPTION ) );
	}

	/** Establish the legacy session once, before subsequent requests. */
	private function initialize( StdioServerBridge $bridge ): void {
		$response = $this->request(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Schemas::V2025_11_25,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'cache-test',
						'version' => '1.0',
					),
				),
			)
		);
		$this->assertSame( Schemas::V2025_11_25, $response['result']['protocolVersion'] );
	}

	/** @return array<string, mixed> */
	private function call_tool( StdioServerBridge $bridge ): array {
		return $this->request(
			$bridge,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'cache-reader',
					'arguments' => new \stdClass(),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $payload Request.
	 * @return array<string, mixed>
	 */
	private function request( StdioServerBridge $bridge, array $payload ): array {
		$method = new \ReflectionMethod( $bridge, 'handle_request' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$response = json_decode( $method->invoke( $bridge, wp_json_encode( $payload ) ), true );
		$this->assertIsArray( $response );
		return $response;
	}
}
