<?php
/**
 * Confirmation before destructive Ability calls, through the wire.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Handlers\Tools\ToolCallConfirmation;
use WP\McpSchema\Schemas;

/** The confirm half of C4 (WordPress/mcp-adapter#402): asking, binding, replay and follow-ups. */
final class ToolCallConfirmationTest extends ToolCallConfirmationTestCase {

	/** Nothing changes for people: a destructive call by a human user runs as today. */
	public function test_people_keep_todays_behaviour(): void {
		$result = $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) );

		$this->assertFalse( $result['isError'] );
		$this->assertSame( 1, self::runs() );
	}

	/** Agent users are asked before destructive calls by default, and only destructive ones. */
	public function test_agent_users_are_asked_before_destructive_calls(): void {
		update_user_meta( $this->user_id, 'wpai_agent', '1' );

		$result = $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( ToolCallConfirmation::TOKEN_ARGUMENT, $result['content'][0]['text'] );
		$this->assertSame( 0, self::runs() );

		$this->assertFalse( $this->call_ability( 'test/confirm-read', array( 'id' => 7 ) )['isError'] );
	}

	/** A conversation token runs exactly the asked call, once. */
	public function test_conversation_token_runs_the_call_once(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );

		$asked = $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) );
		$this->assertTrue( $asked['isError'] );
		$this->assertStringContainsString( 'Nothing has changed yet', $asked['content'][0]['text'] );
		$this->assertStringContainsString( 'Delete a thing (test/confirm-delete)', $asked['content'][0]['text'] );
		$token = $this->token_from( $asked );
		$this->assertSame( 0, self::runs() );

		$confirmed = $this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertFalse( $confirmed['isError'], (string) wp_json_encode( $confirmed ) );
		$this->assertSame( array( 'deleted' => 7, 'run' => 1 ), $confirmed['structuredContent'] );
		$this->assertSame( 1, self::runs() );
	}

	/** Retries of the same confirmed call get the first result back and never run it again. */
	public function test_retries_get_the_first_result(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token     = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );
		$arguments = array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token );

		$first  = $this->call_ability( 'test/confirm-delete', $arguments );
		$second = $this->call_ability( 'test/confirm-delete', $arguments );
		$third  = $this->call_ability( 'test/confirm-delete', $arguments );

		$this->assertFalse( $first['isError'] );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
		$this->assertSame( 1, self::runs() );

		// The stored result answers only the confirmed call.
		$other = $this->call_ability( 'test/confirm-delete', array( 'id' => 8, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertStringContainsString( 'different call', $other['content'][0]['text'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$other_user = $this->call_ability( 'test/confirm-delete', $arguments );
		$this->assertStringContainsString( 'different call', $other_user['content'][0]['text'] );
		$this->assertSame( 1, self::runs() );
	}

	/** A retry that arrives while the confirmed call is still running does not run it a second time. */
	public function test_retry_during_the_run_does_not_run_twice(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		add_filter(
			'mcp_adapter_tool_call_confirmation_replay_wait',
			static function (): int {
				return 0;
			}
		);
		$token     = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );
		$arguments = array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token );
		$inner     = null;
		// The client times out and retries while the first request is still inside the Ability.
		self::$during_delete = function () use ( &$inner, $arguments ): void {
			self::$during_delete = null;
			$inner               = $this->call_ability( 'test/confirm-delete', $arguments );
		};

		$first = $this->call_ability( 'test/confirm-delete', $arguments );
		$this->assertFalse( $first['isError'] );
		$this->assertIsArray( $inner );
		$this->assertTrue( $inner['isError'] );
		$this->assertStringContainsString( 'still running', $inner['content'][0]['text'] );
		$this->assertSame( 1, self::runs() );

		$this->assertSame( $first, $this->call_ability( 'test/confirm-delete', $arguments ) );
		$this->assertSame( 1, self::runs() );
	}

	/** A token covers its own input and user only, and a wrong use does not spend it. */
	public function test_token_is_bound_to_input_and_user(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );

		$other_input = $this->call_ability( 'test/confirm-delete', array( 'id' => 8, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertTrue( $other_input['isError'] );
		$this->assertStringContainsString( 'different call', $other_input['content'][0]['text'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$other_user = $this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertTrue( $other_user['isError'] );
		$this->assertStringContainsString( 'different call', $other_user['content'][0]['text'] );
		$this->assertSame( 0, self::runs() );

		wp_set_current_user( $this->user_id );
		$this->assertFalse( $this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) )['isError'] );
		$this->assertSame( 1, self::runs() );
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
		$this->assertSame( 0, self::runs() );

		$args[ ToolCallConfirmation::TOKEN_ARGUMENT ] = $this->token_from( $asked );
		$confirmed                                    = $this->call( $server, 'mcp-adapter-execute-ability', $args )['result'];
		$this->assertFalse( $confirmed['isError'], (string) wp_json_encode( $confirmed ) );
		$this->assertTrue( $confirmed['structuredContent']['success'] );
		$this->assertSame( 1, self::runs() );
	}

	/** Nobody is asked about a call the user may not make. */
	public function test_permission_is_checked_before_asking(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) );
		$this->assertTrue( $result['isError'] );
		$this->assertStringNotContainsString( ToolCallConfirmation::TOKEN_ARGUMENT, $result['content'][0]['text'] );
		$this->assertSame( 0, self::runs() );
	}

	/** A client with form elicitation asks the person itself, and a retry gets the same result. */
	public function test_form_elicitation_asks_the_person(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );

		$asked = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ) )['result'];
		$this->assertSame( 'input_required', $asked['resultType'] );
		$question = $asked['inputRequests'][ ToolCallConfirmation::INPUT_KEY ];
		$this->assertSame( 'form', $question['params']['mode'] );
		$this->assertSame( 'boolean', $question['params']['requestedSchema']['properties']['confirm']['type'] );
		$this->assertStringContainsString( 'Delete a thing', $question['params']['message'] );
		$this->assertTrue( ToolCallConfirmation::is_confirmation_state( $asked['requestState'] ) );
		$this->assertSame( 0, self::runs() );

		$answer    = array(
			'requestState'   => $asked['requestState'],
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => self::yes() ),
		);
		$confirmed = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $answer )['result'];
		$this->assertSame( 'complete', $confirmed['resultType'] );
		$this->assertSame( array( 'deleted' => 7, 'run' => 1 ), $confirmed['structuredContent'] );

		$this->assertSame( $confirmed, $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $answer )['result'] );
		$this->assertSame( 1, self::runs() );
	}

	/** A missing answer repeats the question; a no ends it. */
	public function test_form_missing_answer_and_decline(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$state = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ) )['result']['requestState'];

		$again = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array( 'requestState' => $state ) )['result'];
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
		$declined = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $no )['result'];
		$this->assertTrue( $declined['isError'] );
		$this->assertStringContainsString( 'did not confirm', $declined['content'][0]['text'] );

		$yes_after_no = array(
			'requestState'   => $state,
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => self::yes() ),
		);
		$this->assertTrue( $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $yes_after_no )['result']['isError'] );
		$this->assertSame( 0, self::runs() );
	}

	/** An elicitation state cannot be replayed as a conversation token. */
	public function test_elicitation_state_is_not_a_conversation_token(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$state = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ) )['result']['requestState'];

		$result = $this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => self::id_from_state( $state ) ) );
		$this->assertTrue( $result['isError'] );
		$this->assertSame( 0, self::runs() );
	}

	/** A site can accept only confirmations the client or the approval page collects. */
	public function test_site_can_refuse_conversation_confirmation(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		add_filter( 'mcp_adapter_tool_call_confirmation_allows_chat', '__return_false' );

		$result = $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'cannot ask', $result['content'][0]['text'] );
		$this->assertStringNotContainsString( ToolCallConfirmation::TOKEN_ARGUMENT, $result['content'][0]['text'] );
		$this->assertSame( 0, self::runs() );

		$this->assertSame( 'form', $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ) )['result']['inputRequests'][ ToolCallConfirmation::INPUT_KEY ]['params']['mode'] );
		$this->assertSame( 'url', $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result']['inputRequests'][ ToolCallConfirmation::INPUT_KEY ]['params']['mode'] );
	}

	/** One yes covers the follow-up named in the question, for that target only. */
	public function test_confirmation_covers_the_named_follow_up(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );

		$asked = $this->call_ability( 'test/confirm-install', array( 'plugin' => 'hello' ) );
		$text  = $asked['content'][0]['text'];
		$this->assertStringContainsString( 'The same yes also covers:', $text );
		$this->assertStringContainsString( 'Activate the plugin hello', $text );
		$this->assertStringNotContainsString( 'Ignored', $text, 'Follow-ups for unknown Abilities are dropped.' );

		// Nothing is covered before the person said yes.
		$this->assertTrue( $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello' ) )['isError'] );

		$this->call_ability( 'test/confirm-install', array( 'plugin' => 'hello', ToolCallConfirmation::TOKEN_ARGUMENT => $this->token_from( $asked ) ) );
		$this->assertSame( 1, self::runs( 'test/confirm-install' ) );

		$activated = $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello' ) );
		$this->assertFalse( $activated['isError'], (string) wp_json_encode( $activated ) );
		$this->assertSame( 1, self::runs( 'test/confirm-activate' ) );

		// A retry of the follow-up gets its result back.
		$this->assertSame( $activated, $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello' ) ) );
		$this->assertSame( 1, self::runs( 'test/confirm-activate' ) );

		// A different target or a different Ability is still asked.
		$this->assertTrue( $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'other' ) )['isError'] );
		$this->assertTrue( $this->call_ability( 'test/confirm-uninstall', array( 'plugin' => 'hello' ) )['isError'] );
		$this->assertSame( 0, self::runs( 'test/confirm-uninstall' ) );
		$this->assertSame( 1, self::runs( 'test/confirm-activate' ) );
	}

	/** A follow-up is covered for the user who confirmed only, and the filter can withdraw it. */
	public function test_follow_up_is_bound_to_the_user_and_filterable(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token = $this->token_from( $this->call_ability( 'test/confirm-install', array( 'plugin' => 'hello' ) ) );
		$this->call_ability( 'test/confirm-install', array( 'plugin' => 'hello', ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello' ) )['isError'] );
		$this->assertSame( 0, self::runs( 'test/confirm-activate' ) );

		wp_set_current_user( $this->user_id );
		add_filter( 'mcp_adapter_tool_call_confirmation_follow_ups', '__return_empty_array' );
		$asked = $this->call_ability( 'test/confirm-install', array( 'plugin' => 'second' ) );
		$this->assertStringNotContainsString( 'also covers', $asked['content'][0]['text'] );
		$this->call_ability( 'test/confirm-install', array( 'plugin' => 'second', ToolCallConfirmation::TOKEN_ARGUMENT => $this->token_from( $asked ) ) );
		$this->assertTrue( $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'second' ) )['isError'] );
	}

	/** The confirmed action carries what was confirmed, never the raw input, and fires once. */
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

		$token = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );
		$this->assertSame( array(), $events );
		$this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		$this->assertCount( 1, $events );
		[ $confirmation, $tool_name ] = $events[0];
		$this->assertSame( $this->tool( 'test/confirm-delete' )->get_name(), $tool_name );
		$this->assertSame( $token, $confirmation['id'] );
		$this->assertSame( 'test/confirm-delete', $confirmation['ability'] );
		$this->assertSame( 'chat', $confirmation['channel'] );
		$this->assertSame( $this->user_id, $confirmation['user_id'] );
		$this->assertSame( 0, $confirmation['approved_by'] );
		$this->assertNull( $confirmation['follow_up_of'] );
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $confirmation['input_hash'] );
		$this->assertArrayNotHasKey( 'input', $confirmation );
	}

	/** Other continuations on Ability-backed tools are still rejected. */
	public function test_foreign_continuation_is_still_rejected(): void {
		$response = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array( 'requestState' => 'someone-else' ) );
		$this->assertSame( -32602, $response['error']['code'] );
		$this->assertSame( 0, self::runs() );
		$this->assertSame( -32602, $this->call( $this->makeServer( array( $this->tool( 'test/confirm-delete' ) ) ), $this->tool( 'test/confirm-delete' )->get_name(), array( 'id' => 7 ), array( 'requestState' => 'mcp-adapter-confirmation.' . str_repeat( 'a', 32 ) ), Schemas::V2025_11_25 )['error']['code'] );
	}
}
