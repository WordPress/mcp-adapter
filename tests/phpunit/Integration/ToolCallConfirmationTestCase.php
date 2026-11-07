<?php
/**
 * Shared fixtures for the tool call confirmation tests.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Handlers\Tools\ToolCallConfirmation;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Schemas;

/** Destructive test Abilities, a wire helper and agent users with parents. */
abstract class ToolCallConfirmationTestCase extends TestCase {

	/** @var array<string, int> Executions per test Ability. */
	protected static array $runs = array();

	/** @var callable|null Runs inside test/confirm-delete, for re-entrant retries. */
	protected static $during_delete = null;

	/** @var int */
	protected int $user_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/Fixtures/agent-user-functions.php';
	}

	public function setUp(): void {
		parent::setUp();
		self::$runs          = array();
		self::$during_delete = null;
		$this->user_id       = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$this->register_destructive(
			'test/confirm-delete',
			'Delete a thing',
			array( 'id' => array( 'type' => 'integer' ) ),
			static function ( array $input ): array {
				if ( null !== self::$during_delete ) {
					( self::$during_delete )();
				}
				return array(
					'deleted' => $input['id'],
					'run'     => self::$runs['test/confirm-delete'],
				);
			}
		);
		foreach ( array( 'install', 'activate', 'uninstall' ) as $verb ) {
			$this->register_destructive(
				'test/confirm-' . $verb,
				ucfirst( $verb ) . ' a plugin',
				array( 'plugin' => array( 'type' => 'string' ) ),
				static function ( array $input ) use ( $verb ): array {
					return array(
						$verb => $input['plugin'],
						'run' => self::$runs[ 'test/confirm-' . $verb ],
					);
				},
				'install' === $verb ? static function ( $input ): array {
					return array(
						array(
							'ability' => 'test/confirm-activate',
							'input'   => array( 'plugin' => $input['plugin'] ),
							'summary' => 'Activate the plugin ' . $input['plugin'],
						),
						array(
							'ability' => 'test/not-registered',
							'input'   => array(),
							'summary' => 'Ignored: no such Ability',
						),
						array(
							'ability' => 'test/confirm-uninstall',
							'input'   => array( 'plugin' => $input['plugin'] ),
							'summary' => '',
						),
					);
				} : null
			);
		}
		$this->register_ability_in_hook(
			'test/confirm-read',
			array(
				'label'               => 'Read a thing',
				'description'         => 'Read-only test Ability.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function (): array {
					return array( 'ok' => true );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
					'mcp'         => array( 'public' => true ),
				),
			)
		);
	}

	public function tearDown(): void {
		self::$during_delete = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Register one destructive Ability that counts its runs. */
	private function register_destructive( string $name, string $label, array $properties, callable $execute, ?callable $follow_ups = null ): void {
		$mcp = array( 'public' => true );
		if ( null !== $follow_ups ) {
			$mcp['confirmation_follow_ups'] = $follow_ups;
		}
		$this->register_ability_in_hook(
			$name,
			array(
				'label'               => $label,
				'description'         => 'Destructive test Ability.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => array_keys( $properties ),
					'additionalProperties' => false,
				),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => static function ( array $input ) use ( $name, $execute ): array {
					self::$runs[ $name ] = ( self::$runs[ $name ] ?? 0 ) + 1;
					return $execute( $input );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'mcp'         => $mcp,
				),
			)
		);
	}

	/** Executions of one test Ability. */
	protected static function runs( string $ability = 'test/confirm-delete' ): int {
		return self::$runs[ $ability ] ?? 0;
	}

	/** Make the current user an agent user with an editor parent; returns the parent ID. */
	protected function make_agent_with_parent(): int {
		$parent = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $this->user_id, 'wpai_agent', '1' );
		update_user_meta( $this->user_id, 'wpai_agent_parent', $parent );

		return $parent;
	}

	/** Call one ability-backed tool over 2025-11-25, where no client can elicit. */
	protected function call_ability( string $ability, array $arguments ): array {
		$tool = $this->tool( $ability );
		return $this->call( $this->makeServer( array( $tool ) ), $tool->get_name(), $arguments )['result'];
	}

	/** Call one ability-backed tool over 2026-07-28 with the given elicitation modes. */
	protected function call_modern( string $ability, array $arguments, array $extra = array(), array $modes = array( 'form' ) ): array {
		$tool = $this->tool( $ability );
		return $this->call( $this->makeServer( array( $tool ) ), $tool->get_name(), $arguments, $extra, Schemas::V2026_07_28, $modes );
	}

	protected function tool( string $ability ): McpTool {
		$tool = McpTool::fromAbility( wp_get_ability( $ability ) );
		$this->assertInstanceOf( McpTool::class, $tool );
		return $tool;
	}

	protected function token_from( array $result ): string {
		$this->assertSame( 1, preg_match( '/"' . preg_quote( ToolCallConfirmation::TOKEN_ARGUMENT, '/' ) . '": "([a-f0-9]{32})"/', $result['content'][0]['text'], $match ), (string) wp_json_encode( $result ) );
		return $match[1];
	}

	/** The confirmation identifier inside an Adapter-issued request state. */
	protected static function id_from_state( string $state ): string {
		return substr( $state, strlen( 'mcp-adapter-confirmation.' ) );
	}

	protected static function yes(): array {
		return array(
			'action'  => 'accept',
			'content' => array( 'confirm' => true ),
		);
	}

	/** Send one tools/call through a newly constructed orchestrator. */
	protected function call( McpServer $server, string $name, array $arguments, array $extra = array(), string $revision = Schemas::V2025_11_25, array $modes = array() ): array {
		$params = array_merge(
			array(
				'name'      => $name,
				'arguments' => (object) $arguments,
			),
			$extra
		);
		if ( Schemas::V2026_07_28 === $revision ) {
			$elicitation = array();
			foreach ( $modes as $mode ) {
				$elicitation[ $mode ] = new \stdClass();
			}
			$params['_meta'] = array(
				'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
				'io.modelcontextprotocol/clientCapabilities' => array() === $elicitation ? new \stdClass() : array( 'elicitation' => $elicitation ),
			);
		}
		$wire     = new McpWireOrchestrator( $server->create_transport_context() );
		$message  = $wire->decode(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => $params,
				)
			)
		);
		$response = $wire->process( $message, 'STDIO', array(), Schemas::V2025_11_25 === $revision ? array( 'capabilities' => new \stdClass() ) : null )['response'];
		return json_decode( (string) wp_json_encode( $response ), true );
	}
}
