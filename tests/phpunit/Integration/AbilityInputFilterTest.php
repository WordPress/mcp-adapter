<?php
/**
 * Regression coverage for input filters around ability permission checks.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\GetPromptRequest;
use WP_Error;

/**
 * Exercise input enrichment through the real tool and prompt handlers.
 *
 * @group permissions
 */
class AbilityInputFilterTest extends TestCase {

	private const ABILITY_NAME = 'test/input-filter';

	/** @var array<string,array> Inputs observed by each callback. */
	private array $seen = array(
		'permission' => array(),
		'filter'     => array(),
		'execute'    => array(),
	);

	/** @var array<array{string,callable}> Filters to remove after each test. */
	private array $filters = array();

	/** Remove test registrations and filters. */
	public function tearDown(): void {
		foreach ( $this->filters as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback, 10 );
		}
		if ( wp_has_ability( self::ABILITY_NAME ) ) {
			wp_unregister_ability( self::ABILITY_NAME );
		}
		parent::tearDown();
	}

	/**
	 * Handlers with a pre-execution argument filter.
	 *
	 * @return array<string,array{string}>
	 */
	public static function handler_paths(): array {
		return array(
			'tool'   => array( 'tool' ),
			'prompt' => array( 'prompt' ),
		);
	}

	/**
	 * Missing required input must fail before the late filter can fill it.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_required_input_cannot_be_supplied_by_late_filter( string $path ): void {
		$this->add_late_filter(
			$path,
			static function ( array $input ): array {
				$input['id'] = 'server-id';
				return $input;
			}
		);

		$result = $this->call_handler( $path, array() );

		$this->assertStringContainsString( 'id', $this->assert_handler_error( $path, $result ) );
		$this->assertSame( array(), $this->seen['permission'] );
		$this->assertSame( array(), $this->seen['filter'] );
		$this->assertSame( array(), $this->seen['execute'] );
	}

	/**
	 * Normalization supplies required input to both permission checks and execution.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_normalization_supplies_required_input_before_permissions( string $path ): void {
		$this->add_missing_id_normalizer();
		$this->add_late_filter( $path );

		$result   = $this->call_handler(
			$path,
			array(),
			static function ( array $input ): bool {
				return 'server-id' === $input['id'];
			}
		);
		$expected = array( 'id' => 'server-id' );

		$this->assertEmpty( $result['error'] ?? null );
		$this->assertFalse( $result['isError'] ?? false );
		$this->assertSame( array( $expected, $expected ), $this->seen['permission'] );
		$this->assertSame( array( $expected ), $this->seen['execute'] );
		$this->assertCount( 1, $this->seen['filter'] );
	}

	/**
	 * An ability-scoped normalizer preserves an existing client value.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_normalization_preserves_client_input_and_other_abilities( string $path ): void {
		$this->add_missing_id_normalizer();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the Core hook for a different ability.
		$this->assertSame( array(), apply_filters( 'wp_ability_normalize_input', array(), 'test/another-ability' ) );
		$input = array( 'id' => 'client-id' );

		$result = $this->call_handler( $path, $input );

		$this->assertEmpty( $result['error'] ?? null );
		$this->assertFalse( $result['isError'] ?? false );
		$this->assertSame( array( $input, $input ), $this->seen['permission'] );
		$this->assertSame( array( $input ), $this->seen['execute'] );
	}

	/**
	 * Denied requests must stop before the late filter and execution.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_permission_denial_stops_late_filter( string $path ): void {
		$this->add_late_filter( $path );
		$input = array( 'id' => 'client-id' );

		$result = $this->call_handler( $path, $input, '__return_false' );

		$this->assert_handler_error( $path, $result );
		$this->assertSame( array( $input ), $this->seen['permission'] );
		$this->assertSame( array(), $this->seen['filter'] );
		$this->assertSame( array(), $this->seen['execute'] );
	}

	/**
	 * Failed normalization results for both handlers.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public static function failed_normalization_cases(): array {
		return array(
			'tool error'     => array( 'tool', true ),
			'prompt error'   => array( 'prompt', true ),
			'tool invalid'   => array( 'tool', false ),
			'prompt invalid' => array( 'prompt', false ),
		);
	}

	/**
	 * Normalization cannot bypass validation or pass errors to permissions.
	 *
	 * @dataProvider failed_normalization_cases
	 * @param string $path         Handler to exercise.
	 * @param bool   $return_error Whether normalization returns WP_Error.
	 */
	public function test_failed_normalization_stops_permissions( string $path, bool $return_error ): void {
		$this->require_normalization_hook();
		$this->add_test_filter(
			'wp_ability_normalize_input',
			static function ( $input, string $name ) use ( $return_error ) {
				if ( self::ABILITY_NAME !== $name ) {
					return $input;
				}
				return $return_error
					? new WP_Error( 'fixture_normalization_error', 'Fixture normalization failed.' )
					: array( 'id' => array( 'invalid' ) );
			},
			2
		);
		$this->add_late_filter( $path );

		$result  = $this->call_handler( $path, array() );
		$message = $this->assert_handler_error( $path, $result );

		$this->assertStringContainsString( $return_error ? 'Fixture normalization failed.' : 'id', $message );
		$this->assertSame( array(), $this->seen['permission'] );
		$this->assertSame( array(), $this->seen['filter'] );
		$this->assertSame( array(), $this->seen['execute'] );
	}

	/**
	 * Late filters can still short-circuit an otherwise authorized request.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_late_filter_error_stops_execution( string $path ): void {
		$this->add_late_filter(
			$path,
			static function (): WP_Error {
				return new WP_Error( 'fixture_filter_error', 'Fixture filter failed.' );
			}
		);
		$input  = array( 'id' => 'client-id' );
		$result = $this->call_handler( $path, $input );

		$expected = 'tool' === $path ? 'Fixture filter failed.' : 'Internal error: Fixture filter failed.';
		$this->assertSame( $expected, $this->assert_handler_error( $path, $result ) );
		$this->assertSame( array( $input ), $this->seen['permission'] );
		$this->assertCount( 1, $this->seen['filter'] );
		$this->assertSame( array(), $this->seen['execute'] );
	}

	/**
	 * Core must check permissions again after a late filter changes the input.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_late_filter_change_is_authorized_again( string $path ): void {
		$this->add_late_filter(
			$path,
			static function (): array {
				return array( 'id' => 'denied-id' );
			}
		);

		$result = $this->call_handler(
			$path,
			array( 'id' => 'client-id' ),
			static function ( array $input ): bool {
				return 'client-id' === $input['id'];
			}
		);

		$this->assert_handler_error( $path, $result );
		$this->assertSame( array( array( 'id' => 'client-id' ), array( 'id' => 'denied-id' ) ), $this->seen['permission'] );
		$this->assertCount( 1, $this->seen['filter'] );
		$this->assertSame( array(), $this->seen['execute'] );
	}

	/**
	 * Core must validate late filter output before another permission check.
	 *
	 * @dataProvider handler_paths
	 * @param string $path Handler to exercise.
	 */
	public function test_late_filter_invalid_input_is_rejected( string $path ): void {
		$this->add_late_filter( $path, '__return_empty_array' );
		$input  = array( 'id' => 'client-id' );
		$result = $this->call_handler( $path, $input );

		$this->assertStringContainsString( 'id', $this->assert_handler_error( $path, $result ) );
		$this->assertSame( array( $input ), $this->seen['permission'] );
		$this->assertCount( 1, $this->seen['filter'] );
		$this->assertSame( array(), $this->seen['execute'] );
	}

	/** Skip only the cases that need the Core normalization hook. */
	private function require_normalization_hook(): void {
		global $wp_version;
		if ( ! version_compare( $wp_version, '7.1', '<' ) ) {
			return;
		}

		$this->markTestSkipped( 'The input normalization filter requires WordPress 7.1 or later.' );
	}

	/** Apply the documented ability-scoped, missing-value-only migration. */
	private function add_missing_id_normalizer(): void {
		$this->require_normalization_hook();
		$this->add_test_filter(
			'wp_ability_normalize_input',
			static function ( $input, string $name ) {
				if ( self::ABILITY_NAME !== $name || ! is_array( $input ) ) {
					return $input;
				}
				if ( ! array_key_exists( 'id', $input ) ) {
					$input['id'] = 'server-id';
				}
				return $input;
			},
			2
		);
	}

	/**
	 * Record the late filter input and optionally transform it.
	 *
	 * @param string        $path     Handler to exercise.
	 * @param callable|null $callback Optional transformation.
	 */
	private function add_late_filter( string $path, ?callable $callback = null ): void {
		$this->add_test_filter(
			'tool' === $path ? 'mcp_adapter_pre_tool_call' : 'mcp_adapter_pre_prompt_get',
			function ( array $input ) use ( $callback ) {
				$this->seen['filter'][] = $input;
				return null === $callback ? $input : $callback( $input );
			}
		);
	}

	/**
	 * Register a filter and retain it for cleanup.
	 *
	 * @param string   $hook          Filter name.
	 * @param callable $callback      Filter callback.
	 * @param int      $accepted_args Number of callback arguments.
	 */
	private function add_test_filter( string $hook, callable $callback, int $accepted_args = 1 ): void {
		add_filter( $hook, $callback, 10, $accepted_args );
		$this->filters[] = array( $hook, $callback );
	}

	/**
	 * Register an ability and send typed MCP arguments through its real handler.
	 *
	 * @param string   $path       Handler to exercise.
	 * @param array    $input      Client arguments.
	 * @param callable|null $permission Ability permission callback.
	 * @return array Handler result.
	 */
	private function call_handler( string $path, array $input, ?callable $permission = null ): array {
		$this->register_ability_in_hook(
			self::ABILITY_NAME,
			array(
				'label'               => 'Input filter fixture',
				'description'         => 'Checks input enrichment and permission order.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'string' ) ),
					'required'   => array( 'id' ),
				),
				'permission_callback' => function ( $input ) use ( $permission ): bool {
					$this->seen['permission'][] = $input;
					return null === $permission || $permission( $input );
				},
				'execute_callback'    => function ( $input ): array {
					$this->seen['execute'][] = $input;
					return array( 'text' => $input['id'] );
				},
				'meta'                => array( 'mcp' => array( 'type' => $path ) ),
			)
		);
		$ability = wp_get_ability( self::ABILITY_NAME );

		if ( 'tool' === $path ) {
			$component = McpTool::fromAbility( $ability );
			$this->assertNotWPError( $component );
			$server  = $this->makeServer( array( $component ) );
			$request = $this->schema()->fromArray(
				CallToolRequest::class,
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $component->get_name(),
						'arguments' => (object) $input,
					),
				)
			);
			return ( new ToolsHandler( $server ) )->call_tool( $request, $this->request_context( $server ) );
		}

		$component = McpPrompt::fromAbility( $ability );
		$this->assertNotWPError( $component );
		$server  = $this->makeServer( array(), array(), array( $component ) );
		$request = $this->schema()->fromArray(
			GetPromptRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'prompts/get',
				'params'  => array(
					'name'      => $component->get_name(),
					'arguments' => (object) $input,
				),
			)
		);
		return ( new PromptsHandler( $server ) )->get_prompt( $request, $this->request_context( $server ) );
	}

	/**
	 * Assert the handler's error format and return its diagnostic text.
	 *
	 * @param string $path   Handler exercised.
	 * @param array  $result Handler result.
	 * @return string Error message.
	 */
	private function assert_handler_error( string $path, array $result ): string {
		if ( 'tool' === $path ) {
			$this->assertTrue( $result['isError'] ?? false );
			return $result['content'][0]['text'];
		}
		$this->assertArrayHasKey( 'error', $result );
		return $result['error']['message'];
	}
}
