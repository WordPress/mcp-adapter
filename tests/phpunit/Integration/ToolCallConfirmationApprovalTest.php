<?php
/**
 * The wp-admin approval link for URL elicitation.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Admin\ConfirmationApprovalPage;
use WP\MCP\Handlers\Tools\ToolCallConfirmation;
use WPDieException;

/** A person approves on a page they open as themselves, which proves a person answered. */
final class ToolCallConfirmationApprovalTest extends ToolCallConfirmationTestCase {

	public function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_REQUEST['confirmation'], $_REQUEST['_wpnonce'], $_POST['decision'], $_POST['_wpnonce'] );
		parent::tearDown();
	}

	/** An agent user's call is approved by its parent on the page, then the continuation runs. */
	public function test_parent_approves_an_agent_call_on_the_page(): void {
		$parent = $this->make_agent_with_parent();
		$events = array();
		add_action(
			'mcp_adapter_tool_call_confirmed',
			static function ( array $confirmation ) use ( &$events ): void {
				$events[] = $confirmation;
			}
		);

		$asked = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'form', 'url' ) )['result'];
		$this->assertSame( 'input_required', $asked['resultType'] );
		$question = $asked['inputRequests'][ ToolCallConfirmation::INPUT_KEY ]['params'];
		$id       = self::id_from_state( $asked['requestState'] );
		$this->assertSame( 'url', $question['mode'] );
		$this->assertSame( ConfirmationApprovalPage::url( $id ), $question['url'] );
		$this->assertStringContainsString( 'admin-post.php', $question['url'] );
		$this->assertStringContainsString( 'Delete a thing', $question['message'] );

		// Opening the page is not approving: the client retries and is asked again.
		$opened = array(
			'requestState'   => $asked['requestState'],
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => array( 'action' => 'accept' ) ),
		);
		$this->assertSame( 'input_required', $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $opened, array( 'form', 'url' ) )['result']['resultType'] );
		$this->assertSame( 0, self::runs() );

		$this->assertTrue( ToolCallConfirmation::answer( $id, $parent, true ) );

		$confirmed = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $opened, array( 'form', 'url' ) )['result'];
		$this->assertSame( 'complete', $confirmed['resultType'], (string) wp_json_encode( $confirmed ) );
		$this->assertSame( 1, self::runs() );
		$this->assertSame( 'url', $events[0]['channel'] );
		$this->assertSame( $parent, $events[0]['approved_by'] );
		$this->assertSame( $this->user_id, $events[0]['user_id'] );
	}

	/** Nobody but the approver can see or answer the request: not another user, not the agent. */
	public function test_only_the_approver_can_answer(): void {
		$parent = $this->make_agent_with_parent();
		$id     = self::id_from_state( $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result']['requestState'] );

		$stranger = self::factory()->user->create( array( 'role' => 'administrator' ) );
		foreach ( array( $stranger, $this->user_id, 0 ) as $user ) {
			$this->assertSame( 'mcp_adapter_confirmation_forbidden', ToolCallConfirmation::answer( $id, $user, true )->get_error_code() );
		}
		$this->assertFalse( ToolCallConfirmation::approval_request( $id, $parent )['answered'] );
	}

	/** A person calling for themselves approves their own call. */
	public function test_people_approve_their_own_calls(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$id = self::id_from_state( $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result']['requestState'] );

		$this->assertTrue( ToolCallConfirmation::answer( $id, $this->user_id, true ) );
	}

	/** An agent with no parent gets no link; the next channel is used instead. */
	public function test_agent_without_parent_gets_no_link(): void {
		update_user_meta( $this->user_id, 'wpai_agent', '1' );

		$asked = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'form', 'url' ) )['result'];
		$this->assertSame( 'form', $asked['inputRequests'][ ToolCallConfirmation::INPUT_KEY ]['params']['mode'] );
	}

	/** Expired links fail, and the call does not run. */
	public function test_expired_links_fail(): void {
		$parent = $this->make_agent_with_parent();
		$asked  = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result'];
		$id     = self::id_from_state( $asked['requestState'] );

		$record            = get_transient( 'mcp_adapter_confirm_' . $id );
		$record['expires'] = time() - 1;
		set_transient( 'mcp_adapter_confirm_' . $id, $record, 60 );

		$this->assertSame( 'mcp_adapter_confirmation_link_expired', ToolCallConfirmation::answer( $id, $parent, true )->get_error_code() );
		$retry = array(
			'requestState'   => $asked['requestState'],
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => array( 'action' => 'accept' ) ),
		);
		$this->assertTrue( $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $retry, array( 'url' ) )['result']['isError'] );
		$this->assertSame( 0, self::runs() );
	}

	/** Declining on the page ends the request. */
	public function test_declining_on_the_page(): void {
		$parent = $this->make_agent_with_parent();
		$asked  = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result'];
		$this->assertTrue( ToolCallConfirmation::answer( self::id_from_state( $asked['requestState'] ), $parent, false ) );

		$result = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array( 'requestState' => $asked['requestState'] ), array( 'url' ) )['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'did not confirm', $result['content'][0]['text'] );
		$this->assertSame( 0, self::runs() );
	}

	/** The page shows the summary to the approver and needs a nonce to answer. */
	public function test_page_shows_the_summary_and_checks_the_nonce(): void {
		$parent = $this->make_agent_with_parent();
		$id     = self::id_from_state( $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result']['requestState'] );
		wp_set_current_user( $parent );
		$_REQUEST['confirmation'] = $id;

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$page                      = $this->page_output();
		$this->assertStringContainsString( 'Delete a thing (test/confirm-delete)', $page );
		$this->assertStringContainsString( 'name="_wpnonce"', $page );
		$this->assertStringContainsString( 'value="approve"', $page );

		// No nonce: refused, nothing approved.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['decision']         = 'approve';
		$this->page_output();
		$this->assertFalse( ToolCallConfirmation::approval_request( $id, $parent )['answered'] );

		// Another signed-in user with their own valid nonce is still refused.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ConfirmationApprovalPage::nonce_action( $id ) );
		$this->assertStringContainsString( 'Only the person', $this->page_output() );
		$this->assertFalse( ToolCallConfirmation::approval_request( $id, $parent )['answered'] );

		wp_set_current_user( $parent );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ConfirmationApprovalPage::nonce_action( $id ) );
		$this->assertStringContainsString( 'Approved', $this->page_output() );
		$this->assertTrue( ToolCallConfirmation::approval_request( $id, $parent )['answered'] );
	}

	/** Run the page handler and return what it rendered. */
	private function page_output(): string {
		try {
			ConfirmationApprovalPage::handle();
		} catch ( WPDieException $die ) {
			return $die->getMessage();
		}
		$this->fail( 'The page should end with wp_die().' );
	}
}
