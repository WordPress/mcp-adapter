<?php
/**
 * The owner's record of confirmed calls.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Admin\ConfirmedCallLog;
use WP\MCP\Admin\ConfirmedCallLogPage;
use WP\MCP\Handlers\Tools\ToolCallConfirmation;

/** The logging half of C4: who changed what, after whose yes, and how it ended. */
final class ConfirmedCallLogTest extends ToolCallConfirmationTestCase {

	public function setUp(): void {
		parent::setUp();
		ConfirmedCallLog::register();
	}

	/** A call approved on the page is recorded with the agent, its parent, the approver and the result. */
	public function test_approved_agent_call_is_recorded(): void {
		$parent = $this->make_agent_with_parent();
		wp_update_user(
			array(
				'ID'           => $this->user_id,
				'display_name' => 'Helper',
			)
		);
		wp_update_user(
			array(
				'ID'           => $parent,
				'display_name' => 'Jane',
			)
		);
		$asked = $this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), array(), array( 'url' ) )['result'];
		$id    = self::id_from_state( $asked['requestState'] );
		ToolCallConfirmation::answer( $id, $parent, true );
		$retry = array(
			'requestState'   => $asked['requestState'],
			'inputResponses' => array( ToolCallConfirmation::INPUT_KEY => array( 'action' => 'accept' ) ),
		);
		$this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $retry, array( 'url' ) );
		// A retry gets the stored result and is not recorded again.
		$this->call_modern( 'test/confirm-delete', array( 'id' => 7 ), $retry, array( 'url' ) );

		$entries = ConfirmedCallLog::entries( $parent );
		$this->assertCount( 1, $entries );
		$entry = $entries[0];
		$this->assertSame( $id, $entry['confirmation_id'] );
		$this->assertSame( 'test/confirm-delete', $entry['ability'] );
		$this->assertSame( 'url', $entry['channel'] );
		$this->assertSame( 'success', $entry['status'] );
		$this->assertSame( 'Helper', $entry['user_name'] );
		$this->assertSame( '1', (string) $entry['is_agent'] );
		$this->assertSame( $parent, (int) $entry['parent_id'] );
		$this->assertSame( 'Jane', $entry['parent_name'] );
		$this->assertSame( $parent, (int) $entry['approved_by'] );
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $entry['input_hash'] );
		$this->assertSame( 'Delete a thing', $entry['ability_label'] );
		$this->assertSame( '', $entry['summary'] );
		$this->assertSame( 'default', $entry['summary_source'] );
		$this->assertGreaterThan( 0, (int) $entry['finished_at'] );
	}

	/** A default question is recorded without any part of the input. */
	public function test_log_entry_never_holds_the_input(): void {
		global $wpdb;
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token = $this->token_from( $this->call_ability( 'test/confirm-uninstall', array( 'plugin' => 'zebra-quokka-7731' ) ) );
		$this->call_ability( 'test/confirm-uninstall', array( 'plugin' => 'zebra-quokka-7731', ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		$entries = ConfirmedCallLog::entries( $this->user_id );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'default', $entries[0]['summary_source'] );
		$this->assertSame( 'Uninstall a plugin', $entries[0]['ability_label'] );
		foreach ( array( 'zebra', 'quokka', '7731' ) as $part ) {
			$this->assertStringNotContainsString( $part, (string) wp_json_encode( $entries[0] ) );
		}
		wp_cache_flush();
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", '%quokka%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s OR post_content LIKE %s", '%quokka%', '%quokka%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 0, self::stored_rows_containing( 'quokka' ) );
	}

	/** A sentence the Ability wrote itself is logged as the Ability's. */
	public function test_ability_sentence_is_logged(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token = $this->token_from( $this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello' ) ) );
		$this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello', ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		$entry = ConfirmedCallLog::entries( $this->user_id )[0];
		$this->assertSame( 'Switch on the plugin hello', $entry['summary'] );
		$this->assertSame( 'ability', $entry['summary_source'] );

		ob_start();
		ConfirmedCallLogPage::render();
		$this->assertStringContainsString( 'Switch on the plugin hello', (string) ob_get_clean() );
	}

	/** A confirmed call that fails is recorded as such. */
	public function test_failed_call_is_recorded_as_an_error(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token               = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );
		self::$during_delete = static function (): void {
			throw new \RuntimeException( 'Disk full.' );
		};
		$this->assertTrue( $this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) )['isError'] );

		$entries = ConfirmedCallLog::entries( $this->user_id );
		$this->assertSame( 'error', $entries[0]['status'] );
		$this->assertSame( 'chat', $entries[0]['channel'] );
		$this->assertSame( '0', (string) $entries[0]['is_agent'] );
	}

	/** Covered follow-ups are recorded and point at the yes that covered them. */
	public function test_follow_up_points_at_its_confirmation(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		$token = $this->token_from( $this->call_ability( 'test/confirm-install', array( 'plugin' => 'hello' ) ) );
		$this->call_ability( 'test/confirm-install', array( 'plugin' => 'hello', ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->call_ability( 'test/confirm-activate', array( 'plugin' => 'hello' ) );

		$entries = ConfirmedCallLog::entries( $this->user_id );
		$this->assertCount( 2, $entries );
		$this->assertSame( 'test/confirm-activate', $entries[0]['ability'] );
		$this->assertSame( $token, $entries[0]['follow_up_of'] );
		$this->assertSame( 'Activate the plugin hello', $entries[0]['summary'] );
		$this->assertSame( 'ability', $entries[0]['summary_source'] );
		$this->assertSame( array( 'Activate the plugin hello' ), $entries[1]['covers'] );
	}

	/** Owners see everything; others see their own calls, their agents' and the ones they approved. */
	public function test_entries_are_scoped_to_the_viewer(): void {
		$parent = $this->make_agent_with_parent();
		$token  = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );
		$this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		$this->assertCount( 1, ConfirmedCallLog::entries( $parent ) );
		$this->assertCount( 1, ConfirmedCallLog::entries( $this->user_id ) );
		$this->assertCount( 0, ConfirmedCallLog::entries( self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		$this->assertCount( 1, ConfirmedCallLog::entries( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
		$this->assertCount( 0, ConfirmedCallLog::entries( 0 ) );
	}

	/** The Tools page shows the parent what its agent changed. */
	public function test_page_shows_the_parent_what_changed(): void {
		$parent = $this->make_agent_with_parent();
		wp_update_user(
			array(
				'ID'           => $parent,
				'display_name' => 'Jane',
			)
		);
		$token = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 7 ) ) );
		$this->call_ability( 'test/confirm-delete', array( 'id' => 7, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );

		wp_set_current_user( $parent );
		ob_start();
		ConfirmedCallLogPage::render();
		$page = (string) ob_get_clean();

		$this->assertStringContainsString( 'agent of Jane', $page );
		$this->assertStringContainsString( 'Delete a thing', $page );
		$this->assertStringContainsString( 'are not kept', $page );
		$this->assertStringContainsString( 'Agent reported a yes in the chat', $page );
		$this->assertStringContainsString( 'Done', $page );
	}

	/** The log keeps its size and can be turned off. */
	public function test_size_limit_and_switch(): void {
		add_filter( 'mcp_adapter_tool_call_requires_confirmation', '__return_true' );
		add_filter(
			'mcp_adapter_confirmed_call_log_size',
			static function (): int {
				return 2;
			}
		);
		$tokens = array();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$tokens[] = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => $id ) ) );
			$this->call_ability( 'test/confirm-delete', array( 'id' => $id, ToolCallConfirmation::TOKEN_ARGUMENT => end( $tokens ) ) );
		}
		$entries = ConfirmedCallLog::entries( $this->user_id );
		$this->assertCount( 2, $entries );
		$this->assertSame( array( $tokens[2], $tokens[1] ), array_column( $entries, 'confirmation_id' ) );

		add_filter( 'mcp_adapter_confirmed_call_log_enabled', '__return_false' );
		$token = $this->token_from( $this->call_ability( 'test/confirm-delete', array( 'id' => 4 ) ) );
		$this->call_ability( 'test/confirm-delete', array( 'id' => 4, ToolCallConfirmation::TOKEN_ARGUMENT => $token ) );
		$this->assertSame( $tokens[2], ConfirmedCallLog::entries( $this->user_id )[0]['confirmation_id'] );
	}
}
