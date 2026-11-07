<?php
/**
 * Confirmation before destructive Ability calls, through the wire.
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

/** Exercises the confirm half of C4 (WordPress/mcp-adapter#402). */
final class ToolCallConfirmationTest extends TestCase {

	/** @var int Executions of the destructive test Ability. */
	private static int $runs = 0;

	/** @var int */
	private int $user_id;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/Fixtures/agent-user-functions.php';
	}

	public function setUp(): void {
		parent::setUp();
		self::$runs    = 0;
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$this->register_ability_in_hook(
			'test/confirm-delete',
			array(
				'label'               => 'Delete a thing',
				'description'         => 'Destructive test Ability.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => static function ( array $input ): array {
					++self::$runs;
					return array( 'deleted' => $input['id'] );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'mcp'         => array( 'public' => true ),
				),
			)
		);
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
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Nothing changes for people: a destructive call by a human user runs as today. */
	public function test_people_keep_todays_behaviour(): void {
		$result = $this->call_delete( array( 'id' => 7 ) );

		$this->assertFalse( $result['isError'] );
		$this->assertSame( 1, self::$runs );
	}

	/** Agent users are asked before destructive calls by default, and only destructive ones. */
	public function test_agent_users_are_asked_before_destructive_calls(): void {
		update_user_meta( $this->user_id, 'wpai_agent', '1' );

		$result = $this->call_delete( array( 'id' => 7 ) );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( ToolCallConfirmation::TOKEN_ARGUMENT, $result['content'][0]['text'] );
		$this->assertSame( 0, self::$runs );

		$read = $this->tool( 'test/confirm-read' );
		$this->assertFalse( $this->call( $this->makeServer( array( $read ) ), $read->get_name(), array( 'id' => 7 ) )['result']['isError'] );
	}

	/** A conversation token runs exactly the asked call, once. */
	public function test_conversation_token_runs_the_call_once(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );

		$asked = $this->call_delete( array( 'id' => 7 ) );
		$this->assertTrue( $asked['isError'] );
		$this->assertStringContainsString( 'Nothing has changed yet', $asked['content'][0]['text'] );
		$this->assertStringContainsString( 'Delete a thing (test/confirm-delete)', $asked['content'][0]['text'] );
		$token = $this->token_from( $asked );
		$this->assertSame( 0, self::$runs );

		$confirmed = $this->call_delete( array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertFalse( $confirmed['isError'], (string) wp_json_encode( $confirmed ) );
		$this->assertSame( array( 'deleted' => 7 ), $confirmed['structuredContent'] );
		$this->assertSame( 1, self::$runs );

		$replayed = $this->call_delete( array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertTrue( $replayed['isError'] );
		$this->assertStringContainsString( 'already used', $replayed['content'][0]['text'] );
		$this->assertSame( 1, self::$runs );
	}

	/** A token covers its own input and user only, and a wrong use does not spend it. */
	public function test_token_is_bound_to_input_and_user(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token = $this->token_from( $this->call_delete( array( 'id' => 7 ) ) );

		$other_input = $this->call_delete( array( 'id' => 8, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertTrue( $other_input['isError'] );
		$this->assertStringContainsString( 'different call', $other_input['content'][0]['text'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$other_user = $this->call_delete( array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertTrue( $other_user['isError'] );
		$this->assertStringContainsString( 'different call', $other_user['content'][0]['text'] );
		$this->assertSame( 0, self::$runs );

		wp_set_current_user( $this->user_id );
		$this->assertFalse( $this->call_delete( array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) )['isError'] );
		$this->assertSame( 1, self::$runs );
	}

	/** On the default server the question is about the Ability execute-ability would run. */
	public function test_default_server_asks_about_the_inner_ability(): void {
		$asked_about = array();
		add_filter(
			'mcp_adapter_tool_call_requires_confirmation',
			static function ( bool $required, \WP_Ability $ability, $input ) use ( &$asked_about ): bool {
				$asked_about[] = array( $ability->get_name(), $input );
				return 'test/confirm-delete' === $ability->get_name();
			},
			10,
			3
		);
		$server = $this->makeServer( array( 'mcp-adapter/execute-ability' ) );
		$args   = array(
			'ability_name' => 'test/confirm-delete',
			'parameters'   => array( 'id' => 7 ),
		);

		$asked = $this->call( $server, 'mcp-adapter-execute-ability', $args )['result'];
		$this->assertTrue( $asked['isError'] );
		$this->assertSame( array( array( 'test/confirm-delete', array( 'id' => 7 ) ) ), $asked_about );
		$this->assertSame( 0, self::$runs );

		$args[ ToolCallConfirmation::TOKEN_ARGUMENT ] = $this->token_from( $asked );
		$confirmed                                    = $this->call( $server, 'mcp-adapter-execute-ability', $args )['result'];
		$this->assertFalse( $confirmed['isError'], (string) wp_json_encode( $confirmed ) );
		$this->assertTrue( $confirmed['structuredContent']['success'] );
		$this->assertSame( 1, self::$runs );
	}

	/** Nobody is asked about a call the user may not make. */
	public function test_permission_is_checked_before_asking(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->call_delete( array( 'id' => 7 ) );
		$this->assertTrue( $result['isError'] );
		$this->assertStringNotContainsString( ToolCallConfirmation::TOKEN_ARGUMENT, $result['content'][0]['text'] );
		$this->assertSame( 0, self::$runs );
	}

	/** A client with form elicitation asks the person itself; the model never sees a token. */
	public function test_elicitation_client_asks_the_person(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$server = $this->delete_server();
		$name   = $this->tool( 'test/confirm-delete' )->get_name();

		$asked = $this->call( $server, $name, array( 'id' => 7 ), array(), Schemas::V2026_07_28, true )['result'];
		$this->assertSame( 'input_required', $asked['resultType'] );
		$question = $asked['inputRequests'][ ToolCallConfirmation::INPUT_KEY ];
		$this->assertSame( 'elicitation/create', $question['method'] );
		$this->assertSame( 'boolean', $question['params']['requestedSchema']['properties']['confirm']['type'] );
		$this->assertStringContainsString( 'Delete a thing', $question['params']['message'] );
		$this->assertTrue( ToolCallConfirmation::is_confirmation_state( $asked['requestState'] ) );
		$this->assertSame( 0, self::$runs );

		$answer    = array(
			'requestState'   => $asked['requestState'],
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => self::yes() ),
		);
		$confirmed = $this->call( $server, $name, array( 'id' => 7 ), $answer, Schemas::V2026_07_28, true )['result'];
		$this->assertSame( 'complete', $confirmed['resultType'] );
		$this->assertSame( array( 'deleted' => 7 ), $confirmed['structuredContent'] );
		$this->assertSame( 1, self::$runs );

		$replayed = $this->call( $server, $name, array( 'id' => 7 ), $answer, Schemas::V2026_07_28, true )['result'];
		$this->assertTrue( $replayed['isError'] );
		$this->assertSame( 1, self::$runs );
	}

	/** A missing answer repeats the question; a no ends it. */
	public function test_elicitation_missing_answer_and_decline(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$server = $this->delete_server();
		$name   = $this->tool( 'test/confirm-delete' )->get_name();
		$state  = $this->call( $server, $name, array( 'id' => 7 ), array(), Schemas::V2026_07_28, true )['result']['requestState'];

		$again = $this->call( $server, $name, array( 'id' => 7 ), array( 'requestState' => $state ), Schemas::V2026_07_28, true )['result'];
		$this->assertSame( 'input_required', $again['resultType'] );
		$this->assertSame( $state, $again['requestState'] );

		$no       = array(
			'requestState'   => $state,
			'inputResponses' => array(
				ToolCallConfirmation::INPUT_KEY => array(
					'action'  => 'accept',
					'content' => array( 'confirm' => false ),
				),
			),
		);
		$declined = $this->call( $server, $name, array( 'id' => 7 ), $no, Schemas::V2026_07_28, true )['result'];
		$this->assertTrue( $declined['isError'] );
		$this->assertStringContainsString( 'did not confirm', $declined['content'][0]['text'] );

		$yes_after_no = array(
			'requestState'   => $state,
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => self::yes() ),
		);
		$this->assertTrue( $this->call( $server, $name, array( 'id' => 7 ), $yes_after_no, Schemas::V2026_07_28, true )['result']['isError'] );
		$this->assertSame( 0, self::$runs );
	}

	/** An elicitation state cannot be replayed as a conversation token. */
	public function test_elicitation_state_is_not_a_conversation_token(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$server = $this->delete_server();
		$name   = $this->tool( 'test/confirm-delete' )->get_name();
		$state  = $this->call( $server, $name, array( 'id' => 7 ), array(), Schemas::V2026_07_28, true )['result']['requestState'];
		$id     = substr( $state, strlen( 'mcp-adapter-confirmation.' ) );

		$result = $this->call_delete( array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $id ) );
		$this->assertTrue( $result['isError'] );
		$this->assertSame( 0, self::$runs );
	}

	/** A site can accept only confirmations the client collects. */
	public function test_site_can_refuse_conversation_confirmation(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		add_filter( 'mcp_adapter_tool_call_confirmation_allows_chat', '__return_false' );

		$result = $this->call_delete( array( 'id' => 7 ) );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'cannot ask', $result['content'][0]['text'] );
		$this->assertStringNotContainsString( ToolCallConfirmation::TOKEN_ARGUMENT, $result['content'][0]['text'] );
		$this->assertSame( 0, self::$runs );

		// The same site still accepts the client's own question.
		$asked = $this->call( $this->delete_server(), $this->tool( 'test/confirm-delete' )->get_name(), array( 'id' => 7 ), array(), Schemas::V2026_07_28, true )['result'];
		$this->assertSame( 'input_required', $asked['resultType'] );
	}

	/** The confirmed action carries what was confirmed, never the raw input. */
	public function test_confirmed_action_reports_without_input(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$events = array();
		add_action(
			'mcp_adapter_tool_call_confirmed',
			static function ( array $confirmation, string $tool_name ) use ( &$events ): void {
				$events[] = array( $confirmation, $tool_name );
			},
			10,
			2
		);

		$token = $this->token_from( $this->call_delete( array( 'id' => 7 ) ) );
		$this->assertSame( array(), $events );
		$this->call_delete( array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		$this->assertCount( 1, $events );
		list( $confirmation, $tool_name ) = $events[0];
		$this->assertSame( $this->tool( 'test/confirm-delete' )->get_name(), $tool_name );
		$this->assertSame( 'test/confirm-delete', $confirmation['ability'] );
		$this->assertSame( 'chat', $confirmation['channel'] );
		$this->assertSame( $this->user_id, $confirmation['user_id'] );
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $confirmation['input_hash'] );
		$this->assertArrayNotHasKey( 'input', $confirmation );
	}

	/** Other continuations on Ability-backed tools are still rejected. */
	public function test_foreign_continuation_is_still_rejected(): void {
		$name     = $this->tool( 'test/confirm-delete' )->get_name();
		$response = $this->call( $this->delete_server(), $name, array( 'id' => 7 ), array( 'requestState' => 'someone-else' ), Schemas::V2026_07_28, true );
		$this->assertSame( -32602, $response['error']['code'] );
		$this->assertSame( 0, self::$runs );
	}

	/** Call the destructive tool over the 2025-11-25 revision, where no client can elicit. */
	private function call_delete( array $arguments ): array {
		return $this->call( $this->delete_server(), $this->tool( 'test/confirm-delete' )->get_name(), $arguments )['result'];
	}

	private function delete_server(): McpServer {
		return $this->makeServer( array( $this->tool( 'test/confirm-delete' ) ) );
	}

	private function tool( string $ability ): McpTool {
		$tool = McpTool::fromAbility( wp_get_ability( $ability ) );
		$this->assertInstanceOf( McpTool::class, $tool );
		return $tool;
	}

	private function token_from( array $result ): string {
		$this->assertSame( 1, preg_match( '/"' . preg_quote( ToolCallConfirmation::TOKEN_ARGUMENT, '/' ) . '": "([a-f0-9]{32})"/', $result['content'][0]['text'], $match ), (string) wp_json_encode( $result ) );
		return $match[1];
	}

	private static function yes(): array {
		return array(
			'action'  => 'accept',
			'content' => array( 'confirm' => true ),
		);
	}

	/** Send one tools/call through a newly constructed orchestrator. */
	private function call( McpServer $server, string $name, array $arguments, array $extra = array(), string $revision = Schemas::V2025_11_25, bool $elicitation = false ): array {
		$params = array_merge(
			array(
				'name'      => $name,
				'arguments' => (object) $arguments,
			),
			$extra
		);
		if ( Schemas::V2026_07_28 === $revision ) {
			$params['_meta'] = array(
				'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
				'io.modelcontextprotocol/clientCapabilities' => $elicitation ? array( 'elicitation' => array( 'form' => new \stdClass() ) ) : new \stdClass(),
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
