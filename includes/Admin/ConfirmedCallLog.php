<?php
/**
 * Record of confirmed Ability calls, for the site owner.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

use WP_Query;
use WP_User;

/**
 * Keeps one entry per confirmed call: what the person was asked, how and by whom it
 * was approved, which agent user ran it on whose behalf, and how it ended.
 *
 * Entries are posts of a private post type with no author: the people involved are
 * kept as IDs and names in post meta, so an entry reads the same after an agent or
 * its parent is deleted or its content is reassigned (WordPress/ai#1068 deletes and
 * reassigns agents together with their parent). The input itself is never stored,
 * only its SHA-256 and the summary the person saw.
 *
 * Agent users come from the Agent Users experiment in WordPress/ai#1068, through its
 * public helpers `wpai_is_agent_user()` and `wpai_get_agent_parent()`. Without that
 * plugin every caller is recorded as a person.
 *
 * Experimental.
 *
 * @internal
 * @since n.e.x.t
 */
final class ConfirmedCallLog {

	/**
	 * Post type that holds the entries.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'mcp_confirmed_call';

	/**
	 * Default number of entries kept.
	 *
	 * @var int
	 */
	private const DEFAULT_SIZE = 500;

	/**
	 * Meta key prefix.
	 *
	 * @var string
	 */
	private const META_PREFIX = '_mcp_confirmed_';

	/**
	 * Register the post type, the listeners and the Tools page.
	 */
	public static function register(): void {
		if ( has_action( 'mcp_adapter_tool_call_confirmed', array( self::class, 'record' ) ) ) {
			return;
		}
		if ( did_action( 'init' ) ) {
			self::register_post_type();
		} else {
			add_action( 'init', array( self::class, 'register_post_type' ) );
		}
		add_action( 'mcp_adapter_tool_call_confirmed', array( self::class, 'record' ), 10, 2 );
		add_action( 'mcp_adapter_tool_call_confirmation_finished', array( self::class, 'finish' ), 10, 2 );
		add_action( 'admin_menu', array( ConfirmedCallLogPage::class, 'add_page' ) );
	}

	/**
	 * Register the private post type.
	 */
	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			'mcp_confirmed_call',
			array(
				'label'            => __( 'Confirmed agent changes', 'mcp-adapter' ),
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => false,
				'supports'         => array( 'title' ),
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Fields stored with each entry, as meta keys without their prefix.
	 *
	 * @return string[]
	 */
	public static function fields(): array {
		return array( 'confirmation_id', 'ability', 'tool', 'input_hash', 'summary', 'channel', 'user_id', 'user_name', 'is_agent', 'parent_id', 'parent_name', 'approved_by', 'approver_name', 'follow_up_of', 'issued_at', 'confirmed_at', 'finished_at', 'status' );
	}

	/**
	 * Whether confirmed calls are recorded.
	 */
	private static function enabled(): bool {
		/**
		 * Filters whether confirmed MCP calls are recorded for the site owner.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param bool $enabled Default true.
		 */
		return true === apply_filters( 'mcp_adapter_confirmed_call_log_enabled', true );
	}

	/**
	 * Record a confirmed call as it starts.
	 *
	 * @param array<string, mixed> $confirmation Payload of `mcp_adapter_tool_call_confirmed`.
	 * @param string               $tool_name    MCP tool name.
	 */
	public static function record( array $confirmation, string $tool_name ): void {
		if ( ! self::enabled() ) {
			return;
		}
		self::register_post_type();

		$user_id     = (int) ( $confirmation['user_id'] ?? 0 );
		$user        = get_userdata( $user_id );
		$is_agent    = $user_id > 0 && function_exists( 'wpai_is_agent_user' ) && (bool) wpai_is_agent_user( $user_id );
		$parent      = $is_agent && function_exists( 'wpai_get_agent_parent' ) ? wpai_get_agent_parent( $user_id ) : null;
		$approved_by = (int) ( $confirmation['approved_by'] ?? 0 );
		$approver    = $approved_by > 0 ? get_userdata( $approved_by ) : false;

		$fields = array(
			'confirmation_id' => (string) ( $confirmation['id'] ?? '' ),
			'ability'         => (string) ( $confirmation['ability'] ?? '' ),
			'tool'            => $tool_name,
			'input_hash'      => (string) ( $confirmation['input_hash'] ?? '' ),
			'summary'         => (string) ( $confirmation['summary'] ?? '' ),
			'channel'         => (string) ( $confirmation['channel'] ?? '' ),
			'user_id'         => $user_id,
			'user_name'       => $user instanceof WP_User ? $user->display_name : '',
			'is_agent'        => $is_agent ? 1 : 0,
			'parent_id'       => $parent instanceof WP_User ? $parent->ID : 0,
			'parent_name'     => $parent instanceof WP_User ? $parent->display_name : '',
			'approved_by'     => $approved_by,
			'approver_name'   => $approver instanceof WP_User ? $approver->display_name : '',
			'follow_up_of'    => (string) ( $confirmation['follow_up_of'] ?? '' ),
			'issued_at'       => (int) ( $confirmation['issued_at'] ?? 0 ),
			'confirmed_at'    => (int) ( $confirmation['confirmed_at'] ?? time() ),
			'finished_at'     => 0,
			'status'          => 'running',
		);

		$meta = array();
		foreach ( $fields as $key => $value ) {
			$meta[ self::META_PREFIX . $key ] = $value;
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_author' => 0,
					'post_title'  => $fields['ability'],
					'meta_input'  => $meta,
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return;
		}

		self::prune();
	}

	/**
	 * Record how a confirmed call ended.
	 *
	 * @param string $confirmation_id Confirmation identifier.
	 * @param string $outcome         'success', 'error' or 'failed'.
	 */
	public static function finish( string $confirmation_id, string $outcome ): void {
		$post_id = self::find( $confirmation_id );
		if ( 0 === $post_id ) {
			return;
		}
		update_post_meta( $post_id, self::META_PREFIX . 'status', in_array( $outcome, array( 'success', 'error', 'failed' ), true ) ? $outcome : 'failed' );
		update_post_meta( $post_id, self::META_PREFIX . 'finished_at', time() );
	}

	/**
	 * Entries a user may see, newest first.
	 *
	 * Users who can manage options see every entry. Everyone else sees the calls they
	 * made, the calls their agent users made, and the calls they approved.
	 *
	 * @param int $viewer User ID.
	 * @param int $limit  Most entries returned.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function entries( int $viewer, int $limit = 100 ): array {
		if ( $viewer <= 0 ) {
			return array();
		}
		self::register_post_type();

		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, $limit ),
			'orderby'        => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'no_found_rows'  => true,
		);
		if ( ! user_can( $viewer, 'manage_options' ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by the log size.
				'relation' => 'OR',
				array(
					'key'   => self::META_PREFIX . 'user_id',
					'value' => $viewer,
				),
				array(
					'key'   => self::META_PREFIX . 'parent_id',
					'value' => $viewer,
				),
				array(
					'key'   => self::META_PREFIX . 'approved_by',
					'value' => $viewer,
				),
			);
		}

		$entries = array();
		foreach ( self::ids( $args ) as $post_id ) {
			$entry = array();
			foreach ( self::fields() as $field ) {
				$entry[ $field ] = get_post_meta( $post_id, self::META_PREFIX . $field, true );
			}
			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Entry post for a confirmation, or 0.
	 *
	 * @param string $confirmation_id Confirmation identifier.
	 */
	private static function find( string $confirmation_id ): int {
		if ( '' === $confirmation_id ) {
			return 0;
		}
		$ids = self::ids(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_PREFIX . 'confirmation_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded by the log size.
				'meta_value'     => $confirmation_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded by the log size.
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		return array() === $ids ? 0 : $ids[0];
	}

	/**
	 * Post IDs for a query over entries.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 *
	 * @return list<int>
	 */
	private static function ids( array $args ): array {
		$args['fields']                 = 'ids';
		$args['update_post_term_cache'] = false;
		$posts                          = ( new WP_Query( $args ) )->posts;

		$ids = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			$ids[] = $post instanceof \WP_Post ? $post->ID : (int) $post;
		}

		return $ids;
	}

	/**
	 * Delete the oldest entries beyond the log size.
	 */
	private static function prune(): void {
		/**
		 * Filters how many confirmed-call entries are kept.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $size Entries kept. Default 500.
		 */
		$size = max( 1, (int) apply_filters( 'mcp_adapter_confirmed_call_log_size', self::DEFAULT_SIZE ) );

		$old = self::ids(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'offset'         => $size,
				'no_found_rows'  => true,
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
			)
		);
		foreach ( $old as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
}
