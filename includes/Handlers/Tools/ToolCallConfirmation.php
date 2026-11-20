<?php
/**
 * Confirmation before destructive Ability calls.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

use WP\MCP\Admin\ConfirmationApprovalPage;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP_Ability;
use WP_Error;
use WP_User;

/**
 * Asks the person before a destructive Ability call runs.
 *
 * A call that needs confirmation does not run. It answers with what it would do and
 * a short-lived, single-use confirmation bound to the user, the server, the tool, the
 * target Ability and a digest of its exact input. Only a later call that carries that
 * confirmation back, with the same input, runs. A retry of that confirmed call, inside
 * the confirmation's lifetime, gets the first call's result back instead of running
 * again.
 *
 * Three channels carry the question, strongest first:
 *
 * - URL elicitation (MCP 2026-07-28, client declares `url`): the person opens a
 *   wp-admin page as themselves, or as the agent user's parent, and approves there.
 *   This proves that a person answered.
 * - Form elicitation (MCP 2026-07-28, client declares `form`): the client asks the
 *   person and returns the answer. The model never sees the confirmation.
 * - The conversation, for every other client: a tool execution error tells the agent
 *   what to ask and which token to send back. This proves that the agent was told to
 *   ask, not that a person answered, and `mcp_adapter_tool_call_confirmation_allows_chat`
 *   can turn it off.
 *
 * A confirmation may also cover a short, explicit list of follow-up calls named in
 * the question (install a plugin, then activate that plugin). Each is bound to the
 * same user and to one Ability and input.
 *
 * Confirmation never widens what a user may do: it runs after the tool's permission
 * check and after `mcp_adapter_pre_tool_call`, so a call that would be refused is
 * refused before anyone is asked.
 *
 * Experimental: names, defaults and messages may change while the Adapter is 0.x.
 *
 * @internal Used by ToolsHandler and ConfirmationApprovalPage.
 * @since n.e.x.t
 */
final class ToolCallConfirmation {

	/**
	 * Top-level tool argument that carries a conversation confirmation token.
	 *
	 * @var string
	 */
	public const TOKEN_ARGUMENT = '_mcp_confirmation_token';

	/**
	 * Input request key used for the elicitation question.
	 *
	 * @var string
	 */
	public const INPUT_KEY = 'mcp_adapter_confirmation';

	/**
	 * Channel names stored with each confirmation.
	 */
	public const CHANNEL_CHAT = 'chat';
	public const CHANNEL_FORM = 'form';
	public const CHANNEL_URL  = 'url';

	/**
	 * Prefix that marks request state issued by this class.
	 *
	 * @var string
	 */
	private const STATE_PREFIX = 'mcp-adapter-confirmation.';

	/**
	 * Transient key prefixes: pending confirmations, used ones with their result, follow-up grants.
	 */
	private const PENDING_PREFIX = 'mcp_adapter_confirm_';
	private const USED_PREFIX    = 'mcp_adapter_confirm_used_';
	private const FOLLOW_PREFIX  = 'mcp_adapter_confirm_follow_';

	/**
	 * Default confirmation lifetime in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = 300;

	/**
	 * Most follow-up calls one confirmation may cover.
	 *
	 * @var int
	 */
	private const MAX_FOLLOW_UPS = 3;

	/**
	 * Largest serialized result kept for retries, in bytes.
	 *
	 * @var int
	 */
	private const MAX_STORED_RESULT = 524288;

	/**
	 * Built-in tool that runs other Abilities by name.
	 *
	 * @var string
	 */
	private const EXECUTE_ABILITY = 'mcp-adapter/execute-ability';

	/**
	 * Whether request state was issued by this class.
	 *
	 * @param string|null $state Client-supplied request state.
	 */
	public static function is_confirmation_state( ?string $state ): bool {
		return null !== $state && 1 === preg_match( '/\A' . preg_quote( self::STATE_PREFIX, '/' ) . '[a-f0-9]{32}\z/', $state );
	}

	/**
	 * Remove the confirmation token argument so Abilities never see it.
	 *
	 * @param array<mixed> $args Tool arguments, changed in place.
	 *
	 * @return string|null The token, '' for a malformed one, or null when none was sent.
	 */
	public static function take_token( array &$args ): ?string {
		if ( ! array_key_exists( self::TOKEN_ARGUMENT, $args ) ) {
			return null;
		}
		$token = $args[ self::TOKEN_ARGUMENT ];
		unset( $args[ self::TOKEN_ARGUMENT ] );

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Decide whether this call may run now.
	 *
	 * @param \WP\MCP\Core\McpServer                  $server    The MCP server.
	 * @param \WP\MCP\Domain\Tools\McpTool            $tool      The ability-backed tool being called.
	 * @param string                                  $tool_name The MCP tool name.
	 * @param array<mixed>                            $args      Final tool arguments, without the token.
	 * @param string|null                             $token     Conversation token sent with the call.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context   This request's protocol context.
	 *
	 * @return \WP\MCP\Handlers\Tools\ConfirmedCall|\WP\MCP\Domain\Tools\McpInputRequired|\WP_Error|array<string, mixed>|null
	 *         Null: run, no confirmation involved. ConfirmedCall: run, then pass the result to remember().
	 *         Array: the stored result of the same confirmed call, return it as is.
	 */
	public static function check( McpServer $server, McpTool $tool, string $tool_name, array $args, ?string $token, McpToolCallContext $context ) {
		$target = self::target( $tool, $args );
		if ( null === $target ) {
			return null;
		}
		[ $ability, $input ] = $target;

		if ( ! self::is_required( $ability, $input, $tool_name, $server ) ) {
			return null;
		}

		$binding = self::binding( $server->get_server_id(), $tool_name, $ability->get_name(), $input );

		$state = $context->request_state();
		if ( self::is_confirmation_state( $state ) ) {
			return self::continue_question( substr( (string) $state, strlen( self::STATE_PREFIX ) ), $binding, $context, $tool_name, $server );
		}

		if ( null !== $token ) {
			return self::redeem_token( $token, $binding, $tool_name, $server );
		}

		$follow_up = self::use_follow_up( $binding, $tool_name, $server );
		if ( null !== $follow_up ) {
			return $follow_up;
		}

		return self::ask( $ability, $input, $binding, $context );
	}

	/**
	 * Keep the result of a confirmed call for retries of the same call.
	 *
	 * @param \WP\MCP\Handlers\Tools\ConfirmedCall $call     The confirmed call.
	 * @param array<string, mixed>                 $response The logical response returned to the client.
	 */
	public static function remember( ConfirmedCall $call, array $response ): void {
		if ( isset( $response['error'] ) ) {
			$outcome = 'failed';
		} else {
			$outcome = true === ( $response['isError'] ?? false ) ? 'error' : 'success';
		}

		$used = get_transient( self::USED_PREFIX . $call->id() );
		if ( is_array( $used ) ) {
			if ( 'failed' === $outcome ) {
				// A protocol error carries this request's id; never replay it to another request.
				$used['status'] = 'failed';
			} elseif ( strlen( serialize( $response ) ) > self::MAX_STORED_RESULT ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Measures what the transient will store.
				$used['status'] = 'unstored';
			} else {
				$used['status'] = 'done';
				$used['result'] = $response;
			}
			self::store_until( self::USED_PREFIX . $call->id(), $used, (int) $used['expires'] );
		}

		/**
		 * Fires once a confirmed Ability call has run.
		 *
		 * Pairs with `mcp_adapter_tool_call_confirmed` through the confirmation id. Retries
		 * that get the stored result back do not fire it again.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $id      Confirmation identifier.
		 * @param string $outcome 'success', 'error' (the tool reported an error) or 'failed' (the call did not complete).
		 */
		do_action( 'mcp_adapter_tool_call_confirmation_finished', $call->id(), $outcome );
	}

	/**
	 * What the approval page shows, for the one user who may answer it.
	 *
	 * @param string $id      Confirmation identifier from the approval link.
	 * @param int    $user_id The user who opened the link.
	 *
	 * @return array{summary: string, expires: int, requested_by: int, answered: bool}|\WP_Error
	 */
	public static function approval_request( string $id, int $user_id ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $id ) ) {
			return self::link_expired();
		}
		$record = self::load( self::PENDING_PREFIX . $id );
		if ( null === $record || self::CHANNEL_URL !== $record['channel'] ) {
			return self::link_expired();
		}
		if ( $user_id <= 0 || (int) ( $record['approver'] ?? 0 ) !== $user_id ) {
			return new WP_Error(
				'mcp_adapter_confirmation_forbidden',
				__( 'Only the person this request was sent to can answer it.', 'mcp-adapter' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'summary'      => (string) $record['summary'],
			'expires'      => (int) $record['expires'],
			'requested_by' => (int) $record['binding']['user'],
			'answered'     => ! empty( $record['approved_by'] ) || ! empty( $record['declined'] ),
		);
	}

	/**
	 * Record the approver's answer from the approval page.
	 *
	 * @param string $id      Confirmation identifier from the approval link.
	 * @param int    $user_id The user answering.
	 * @param bool   $approve True to approve, false to decline.
	 *
	 * @return true|\WP_Error
	 */
	public static function answer( string $id, int $user_id, bool $approve ) {
		$request = self::approval_request( $id, $user_id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		if ( $request['answered'] ) {
			return new WP_Error( 'mcp_adapter_confirmation_answered', __( 'This request was already answered.', 'mcp-adapter' ), array( 'status' => 409 ) );
		}

		$record = self::load( self::PENDING_PREFIX . $id );
		if ( null === $record ) {
			return self::link_expired();
		}
		if ( $approve ) {
			$record['approved_by'] = $user_id;
		} else {
			$record['declined'] = true;
		}
		$record['answered_at'] = time();

		if ( ! self::store_until( self::PENDING_PREFIX . $id, $record, (int) $record['expires'] ) ) {
			return self::link_expired();
		}

		return true;
	}

	/**
	 * Resolve the Ability a call would run and the input it would run with.
	 *
	 * For the built-in execute-ability tool this is the Ability named in its
	 * arguments, so the default server asks about the real operation.
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $tool The tool.
	 * @param array<mixed>                 $args Tool arguments.
	 *
	 * @return array{0: \WP_Ability, 1: mixed}|null
	 */
	private static function target( McpTool $tool, array $args ): ?array {
		$ability = $tool->get_ability();
		if ( null === $ability ) {
			return null;
		}

		if ( self::EXECUTE_ABILITY !== $ability->get_name() ) {
			// Match McpTool::execute(): unwrap a transformed schema, then normalize.
			$meta  = $tool->get_adapter_meta();
			$input = $args;
			if ( true === ( $meta['input_schema_transformed'] ?? false ) ) {
				$wrapper = is_string( $meta['input_schema_wrapper'] ?? null ) && '' !== $meta['input_schema_wrapper'] ? $meta['input_schema_wrapper'] : 'input';
				$input   = $args[ $wrapper ] ?? null;
			}

			return array( $ability, AbilityArgumentNormalizer::normalize( $ability, $input ) );
		}

		$name = $args['ability_name'] ?? null;
		if ( ! is_string( $name ) || '' === $name || ! wp_has_ability( $name ) ) {
			return null;
		}
		$inner = wp_get_ability( $name );
		if ( ! $inner instanceof WP_Ability ) {
			return null;
		}

		return array( $inner, AbilityArgumentNormalizer::normalize( $inner, $args['parameters'] ?? null ) );
	}

	/**
	 * Whether this call needs the person's confirmation.
	 *
	 * @param \WP_Ability            $ability   Target Ability.
	 * @param mixed                  $input     Target input.
	 * @param string                 $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer $server    The MCP server.
	 */
	private static function is_required( WP_Ability $ability, $input, string $tool_name, McpServer $server ): bool {
		$meta        = $ability->get_meta();
		$annotations = is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : array();
		$destructive = true === ( $annotations['destructive'] ?? null ) && true !== ( $annotations['readonly'] ?? null );

		/**
		 * Filters whether an Ability call over MCP needs the person's confirmation first.
		 *
		 * Runs after the tool's permission check, so returning true never lets a user do
		 * more than they already may. Default: true for Abilities annotated destructive
		 * (and not read-only) when the current user is an agent user (WordPress/ai#1068).
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param bool                   $required  Whether confirmation is required.
		 * @param \WP_Ability            $ability   The Ability that would run.
		 * @param mixed                  $input     The input it would run with.
		 * @param string                 $tool_name The MCP tool name.
		 * @param \WP\MCP\Core\McpServer $server    The MCP server.
		 */
		return true === apply_filters( 'mcp_adapter_tool_call_requires_confirmation', $destructive && self::is_agent( get_current_user_id() ), $ability, $input, $tool_name, $server );
	}

	/**
	 * Whether a user is an agent user, through the Agent Users experiment when it is active.
	 *
	 * @param int $user_id User ID.
	 */
	private static function is_agent( int $user_id ): bool {
		return $user_id > 0 && function_exists( 'wpai_is_agent_user' ) && (bool) wpai_is_agent_user( $user_id );
	}

	/**
	 * The user who may approve this call on the approval page, or 0 for none.
	 *
	 * A person approves their own calls. An agent user's calls are approved by its
	 * parent (WordPress/ai#1068); an agent without a resolvable parent gets no link.
	 *
	 * @param \WP_Ability $ability Target Ability.
	 * @param mixed       $input   Target input.
	 */
	private static function approver( WP_Ability $ability, $input ): int {
		$user_id  = get_current_user_id();
		$approver = $user_id;
		if ( self::is_agent( $user_id ) ) {
			$parent   = function_exists( 'wpai_get_agent_parent' ) ? wpai_get_agent_parent( $user_id ) : null;
			$approver = $parent instanceof WP_User ? $parent->ID : 0;
		}

		/**
		 * Filters who may approve this call on the wp-admin approval page.
		 *
		 * Return 0 to offer no approval link. Never return an agent user.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param int         $approver User ID of the approver, or 0.
		 * @param \WP_Ability $ability  The Ability that would run.
		 * @param mixed       $input    The input it would run with.
		 */
		$approver = (int) apply_filters( 'mcp_adapter_tool_call_confirmation_approver', $approver, $ability, $input );

		return $approver > 0 && ! self::is_agent( $approver ) ? $approver : 0;
	}

	/**
	 * Issue a confirmation and return the question for the strongest available channel.
	 *
	 * @param \WP_Ability                             $ability Target Ability.
	 * @param mixed                                   $input   Target input.
	 * @param array<string, mixed>                    $binding What the confirmation is bound to.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context Request context.
	 *
	 * @return \WP\MCP\Domain\Tools\McpInputRequired|\WP_Error
	 */
	private static function ask( WP_Ability $ability, $input, array $binding, McpToolCallContext $context ) {
		$approver = $context->client_supports_elicitation( 'url' ) ? self::approver( $ability, $input ) : 0;
		if ( $approver > 0 ) {
			$channel = self::CHANNEL_URL;
		} elseif ( $context->client_supports_elicitation( 'form' ) ) {
			$channel = self::CHANNEL_FORM;
		} else {
			$channel = self::CHANNEL_CHAT;

			/**
			 * Filters whether a confirmation may be asked for in the conversation.
			 *
			 * A conversation confirmation shows that the agent was told to ask, not that a
			 * person answered. Return false to accept only confirmations collected by the
			 * MCP client (form elicitation) or on the approval page (URL elicitation).
			 *
			 * Experimental.
			 *
			 * @since n.e.x.t
			 *
			 * @param bool        $allowed Whether conversation confirmation is allowed. Default true.
			 * @param \WP_Ability $ability The Ability that would run.
			 * @param mixed       $input   The input it would run with.
			 */
			if ( true !== apply_filters( 'mcp_adapter_tool_call_confirmation_allows_chat', true, $ability, $input ) ) {
				return new WP_Error(
					'mcp_adapter_confirmation_unavailable',
					__( 'This change needs a yes from the person, and this site only accepts that answer from the MCP client itself. This client cannot ask, so nothing ran.', 'mcp-adapter' ),
					array( 'ability' => $ability->get_name() )
				);
			}
		}

		$follow_ups = self::follow_ups( $ability, $input, $binding );
		$described  = self::summary( $ability, $input, $follow_ups );
		$summary    = $described['shown'];
		$id         = bin2hex( random_bytes( 16 ) );
		$ttl        = self::ttl();
		$expires    = time() + $ttl;
		$record     = array(
			'binding'     => $binding,
			'channel'     => $channel,
			// What the question shows, kept only until the confirmation is used or expires.
			'summary'     => $summary,
			// What may outlive it: the Ability's own sentence, never the default built from the input.
			'described'   => array(
				'ability_label'  => $ability->get_label(),
				'summary'        => $described['ability'],
				'summary_source' => $described['source'],
				'covers'         => array_column( $follow_ups, 'summary' ),
			),
			'follow_ups'  => $follow_ups,
			'approver'    => $approver,
			'approved_by' => 0,
			'issued_at'   => time(),
			'expires'     => $expires,
		);
		if ( ! set_transient( self::PENDING_PREFIX . $id, $record, $ttl ) ) {
			return new WP_Error( 'mcp_adapter_confirmation_storage_failed', __( 'The confirmation could not be saved, so nothing ran.', 'mcp-adapter' ) );
		}

		if ( self::CHANNEL_CHAT !== $channel ) {
			return new McpInputRequired( array( self::INPUT_KEY => self::question( $record, $id ) ), self::STATE_PREFIX . $id );
		}

		return new WP_Error(
			'mcp_adapter_confirmation_required',
			sprintf(
				/* translators: 1: what the call would do, 2: argument name, 3: token, 4: lifetime in minutes */
				__( 'This change needs a yes from the person before it runs. Nothing has changed yet. Ask them, in plain words, whether to go ahead with: %1$s. If they say yes, call this tool again with exactly the same arguments and add "%2$s": "%3$s" at the top level of the arguments. The token works once, only for this call, for %4$d minutes. If they say no, do not call it again.', 'mcp-adapter' ),
				$summary,
				self::TOKEN_ARGUMENT,
				$id,
				(int) ceil( $ttl / 60 )
			),
			array(
				'confirmation_token' => $id,
				'token_argument'     => self::TOKEN_ARGUMENT,
				'summary'            => $summary,
				'ability'            => $ability->get_name(),
				'expires_at'         => gmdate( 'c', $expires ),
			)
		);
	}

	/**
	 * Redeem a conversation token, or replay the result of the call it already ran.
	 *
	 * @param string                 $token     Token sent with the call.
	 * @param array<string, mixed>   $binding   What this call is.
	 * @param string                 $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer $server    The MCP server.
	 *
	 * @return \WP\MCP\Handlers\Tools\ConfirmedCall|\WP_Error|array<string, mixed>
	 */
	private static function redeem_token( string $token, array $binding, string $tool_name, McpServer $server ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $token ) ) {
			return self::unknown();
		}

		$record = self::load( self::PENDING_PREFIX . $token );
		if ( null === $record ) {
			return self::replay( $token, $binding, false );
		}
		if ( self::CHANNEL_CHAT !== $record['channel'] ) {
			return self::unknown();
		}
		if ( $record['binding'] !== $binding ) {
			return self::mismatch();
		}

		return self::consume( self::PENDING_PREFIX . $token, $token, $record, $tool_name, $server );
	}

	/**
	 * Continue a form or URL elicitation round.
	 *
	 * @param string                                  $id        Confirmation identifier from request state.
	 * @param array<string, mixed>                    $binding   What this call is.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context   Request context.
	 * @param string                                  $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer                  $server    The MCP server.
	 *
	 * @return \WP\MCP\Handlers\Tools\ConfirmedCall|\WP\MCP\Domain\Tools\McpInputRequired|\WP_Error|array<string, mixed>
	 */
	private static function continue_question( string $id, array $binding, McpToolCallContext $context, string $tool_name, McpServer $server ) {
		$record = self::load( self::PENDING_PREFIX . $id );
		if ( null === $record ) {
			return self::replay( $id, $binding, false );
		}
		if ( ! in_array( $record['channel'], array( self::CHANNEL_FORM, self::CHANNEL_URL ), true ) ) {
			return self::unknown();
		}
		if ( $record['binding'] !== $binding ) {
			return self::mismatch();
		}

		$responses = $context->input_responses();
		$answer    = $responses->{self::INPUT_KEY} ?? null;
		$action    = $answer instanceof \stdClass && isset( $answer->action ) && is_string( $answer->action ) ? $answer->action : null;

		if ( self::CHANNEL_URL === $record['channel'] ) {
			// In URL mode "accept" only means the person agreed to open the page.
			// The page records the real answer.
			if ( ! empty( $record['declined'] ) || in_array( $action, array( 'decline', 'cancel' ), true ) ) {
				return self::declined( $id );
			}
			if ( (int) $record['approved_by'] > 0 ) {
				return self::consume( self::PENDING_PREFIX . $id, $id, $record, $tool_name, $server );
			}

			return new McpInputRequired( array( self::INPUT_KEY => self::question( $record, $id ) ), self::STATE_PREFIX . $id );
		}

		if ( null === $action ) {
			// No answer to this question yet: ask it again with the same state.
			return new McpInputRequired( array( self::INPUT_KEY => self::question( $record, $id ) ), self::STATE_PREFIX . $id );
		}

		$content  = $answer->content ?? null;
		$accepted = 'accept' === $action
			&& $content instanceof \stdClass
			&& property_exists( $content, 'confirm' )
			&& true === $content->confirm;

		if ( ! $accepted ) {
			return self::declined( $id );
		}

		return self::consume( self::PENDING_PREFIX . $id, $id, $record, $tool_name, $server );
	}

	/**
	 * Run a follow-up call covered by an earlier confirmation, or replay it.
	 *
	 * @param array<string, mixed>   $binding   What this call is.
	 * @param string                 $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer $server    The MCP server.
	 *
	 * @return \WP\MCP\Handlers\Tools\ConfirmedCall|\WP_Error|array<string, mixed>|null Null when no confirmation covers it.
	 */
	private static function use_follow_up( array $binding, string $tool_name, McpServer $server ) {
		$target = self::follow_up_binding( $binding );
		$key    = self::follow_up_key( $target );
		$grant  = self::load( self::FOLLOW_PREFIX . $key );
		if ( null !== $grant && $grant['binding'] === $target ) {
			return self::consume( self::FOLLOW_PREFIX . $key, 'f' . $key, $grant, $tool_name, $server );
		}

		// A retry of a follow-up that already ran gets its result back.
		$used = get_transient( self::USED_PREFIX . 'f' . $key );
		if ( is_array( $used ) && ( $used['binding'] ?? null ) === $target ) {
			return self::replay( 'f' . $key, $target, false );
		}

		return null;
	}

	/**
	 * Use a confirmation once. Only the request that deletes it may run.
	 *
	 * @param string                 $pending_key Transient holding the confirmation.
	 * @param string                 $used_id     Key of the used-confirmation record.
	 * @param array<string, mixed>   $record      Stored confirmation.
	 * @param string                 $tool_name   MCP tool name.
	 * @param \WP\MCP\Core\McpServer $server      The MCP server.
	 *
	 * @return \WP\MCP\Handlers\Tools\ConfirmedCall|\WP_Error|array<string, mixed>
	 */
	private static function consume( string $pending_key, string $used_id, array $record, string $tool_name, McpServer $server ) {
		if ( ! delete_transient( $pending_key ) ) {
			// Another request used it first: wait for its result.
			return self::replay( $used_id, $record['binding'], true );
		}

		self::store_until(
			self::USED_PREFIX . $used_id,
			array(
				'binding' => $record['binding'],
				'status'  => 'running',
				'expires' => (int) $record['expires'],
			),
			(int) $record['expires']
		);

		foreach ( $record['follow_ups'] ?? array() as $follow_up ) {
			self::store_until(
				self::FOLLOW_PREFIX . self::follow_up_key( $follow_up['binding'] ),
				array(
					'binding'      => $follow_up['binding'],
					'channel'      => $record['channel'],
					'summary'      => $follow_up['summary'],
					'described'    => array(
						'ability_label'  => $follow_up['label'],
						'summary'        => $follow_up['summary'],
						'summary_source' => 'ability',
						'covers'         => array(),
					),
					'approved_by'  => (int) ( $record['approved_by'] ?? 0 ),
					'follow_up_of' => $used_id,
					'issued_at'    => (int) $record['issued_at'],
					'expires'      => (int) $record['expires'],
				),
				(int) $record['expires']
			);
		}

		/**
		 * Fires when a confirmed Ability call is about to run.
		 *
		 * The record never carries the input, so that this broadcast action cannot leak
		 * call arguments to every listener: only its digest, the Ability's label, and a
		 * summary when the Ability wrote its own sentence (`summary_source` is
		 * 'ability'). The default question text is built from the input, so it is never
		 * passed on (`summary` is null, `summary_source` is 'default'). Use it to log that
		 * the person said yes, and to what. Retries that get a stored result back do not
		 * fire it again.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param array                  $confirmation {
		 *     @type string      $id             Confirmation identifier, the key its result is stored under.
		 *     @type string      $ability        Ability name.
		 *     @type string      $ability_label  Ability label.
		 *     @type string      $input_hash     SHA-256 of the canonical input JSON.
		 *     @type string|null $summary        The Ability's own sentence the person confirmed, or null.
		 *     @type string      $summary_source 'ability' (from the Ability or the summary filter) or 'default'.
		 *     @type string[]    $covers         Sentences of the follow-ups this confirmation also covers.
		 *     @type string      $channel        'url', 'form' or 'chat'.
		 *     @type int         $user_id        User the call runs as.
		 *     @type int         $approved_by    User who approved on the approval page, 0 for other channels.
		 *     @type string|null $follow_up_of   Confirmation this follow-up was covered by, or null.
		 *     @type int         $issued_at      Unix time the question was asked.
		 *     @type int         $confirmed_at   Unix time the confirmation was used.
		 * }
		 * @param string                 $tool_name The MCP tool name.
		 * @param \WP\MCP\Core\McpServer $server    The MCP server.
		 */
		do_action(
			'mcp_adapter_tool_call_confirmed',
			array(
				'id'             => $used_id,
				'ability'        => $record['binding']['ability'],
				'ability_label'  => (string) ( $record['described']['ability_label'] ?? '' ),
				'input_hash'     => $record['binding']['input'],
				'summary'        => $record['described']['summary'] ?? null,
				'summary_source' => (string) ( $record['described']['summary_source'] ?? 'default' ),
				'covers'         => $record['described']['covers'] ?? array(),
				'channel'        => $record['channel'],
				'user_id'        => $record['binding']['user'],
				'approved_by'    => (int) ( $record['approved_by'] ?? 0 ),
				'follow_up_of'   => $record['follow_up_of'] ?? null,
				'issued_at'      => (int) $record['issued_at'],
				'confirmed_at'   => time(),
			),
			$tool_name,
			$server
		);

		return new ConfirmedCall( $used_id );
	}

	/**
	 * The stored result of a confirmed call that already ran.
	 *
	 * @param string               $used_id          Key of the used-confirmation record.
	 * @param array<string, mixed> $binding          What this call is.
	 * @param bool                 $wait_for_missing Whether a missing record may still appear (lost a race).
	 *
	 * @return \WP_Error|array<string, mixed>
	 */
	private static function replay( string $used_id, array $binding, bool $wait_for_missing ) {
		/**
		 * Filters how long a retry waits for a confirmed call that is still running, in seconds.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $seconds Seconds to wait, 0 to 30. Default 5.
		 */
		$deadline = microtime( true ) + max( 0, min( 30, (int) apply_filters( 'mcp_adapter_tool_call_confirmation_replay_wait', 5 ) ) );

		while ( true ) {
			$key = self::USED_PREFIX . $used_id;
			if ( ! wp_using_ext_object_cache() ) {
				// Read the row again instead of this request's cached copy.
				wp_cache_delete( '_transient_' . $key, 'options' );
			}
			$used = get_transient( $key );

			if ( is_array( $used ) ) {
				if ( ( $used['binding'] ?? null ) !== $binding ) {
					return self::mismatch();
				}
				$status = $used['status'] ?? '';
				if ( 'done' === $status && is_array( $used['result'] ?? null ) ) {
					return $used['result'];
				}
				if ( 'failed' === $status ) {
					return new WP_Error( 'mcp_adapter_confirmation_failed_before', __( 'This confirmed call already ran once and did not finish cleanly. Nothing ran a second time. Check the site before asking the person again.', 'mcp-adapter' ) );
				}
				if ( 'unstored' === $status ) {
					return new WP_Error( 'mcp_adapter_confirmation_result_not_kept', __( 'This confirmed call already ran once. Its result was too large to keep, and nothing ran a second time. Check the site for the outcome.', 'mcp-adapter' ) );
				}
			} elseif ( ! $wait_for_missing ) {
				return self::unknown();
			}

			if ( microtime( true ) >= $deadline ) {
				return is_array( $used )
					? new WP_Error( 'mcp_adapter_confirmation_running', __( 'This confirmed call is still running. Nothing ran a second time. Check again in a moment.', 'mcp-adapter' ) )
					: self::unknown();
			}
			usleep( 250000 );
		}
	}

	/**
	 * Follow-up calls this confirmation also covers, each bound to one Ability and input.
	 *
	 * @param \WP_Ability          $ability Target Ability.
	 * @param mixed                $input   Target input.
	 * @param array<string, mixed> $binding What the main call is.
	 *
	 * @return list<array{binding: array<string, mixed>, summary: string, label: string}>
	 */
	private static function follow_ups( WP_Ability $ability, $input, array $binding ): array {
		$meta     = $ability->get_meta();
		$callback = is_array( $meta['mcp'] ?? null ) ? ( $meta['mcp']['confirmation_follow_ups'] ?? null ) : null;
		$declared = is_callable( $callback ) ? call_user_func( $callback, $input ) : array();

		/**
		 * Filters the follow-up calls one confirmation also covers.
		 *
		 * Each entry names one Ability, its exact input and the sentence the person sees,
		 * for example installing a plugin covering activating that same plugin. A covered
		 * call runs once, for the same user, without a second question. Anything else is
		 * asked as usual. At most three entries are kept. An Ability can also declare
		 * them with a `meta.mcp.confirmation_follow_ups` callback that receives the input.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param array       $follow_ups List of { ability: string, input: mixed, summary: string }.
		 * @param \WP_Ability $ability    The Ability that would run.
		 * @param mixed       $input      The input it would run with.
		 */
		$declared = apply_filters( 'mcp_adapter_tool_call_confirmation_follow_ups', is_array( $declared ) ? $declared : array(), $ability, $input );

		$follow_ups = array();
		foreach ( is_array( $declared ) ? $declared : array() as $entry ) {
			if ( count( $follow_ups ) >= self::MAX_FOLLOW_UPS ) {
				break;
			}
			if ( ! is_array( $entry ) || ! is_string( $entry['ability'] ?? null ) || ! is_string( $entry['summary'] ?? null ) || '' === trim( $entry['summary'] ) ) {
				continue;
			}
			$follow_ability = wp_has_ability( $entry['ability'] ) ? wp_get_ability( $entry['ability'] ) : null;
			if ( ! $follow_ability instanceof WP_Ability ) {
				continue;
			}
			$follow_input   = AbilityArgumentNormalizer::normalize( $follow_ability, $entry['input'] ?? null );
			$follow_binding = self::follow_up_binding( self::binding( (string) $binding['server'], '', $follow_ability->get_name(), $follow_input ) );
			if ( self::follow_up_binding( $binding ) === $follow_binding ) {
				continue;
			}
			$follow_ups[] = array(
				'binding' => $follow_binding,
				'summary' => trim( $entry['summary'] ),
				'label'   => $follow_ability->get_label(),
			);
		}

		return $follow_ups;
	}

	/**
	 * What a confirmation is bound to.
	 *
	 * @param string $server  Server ID.
	 * @param string $tool    MCP tool name.
	 * @param string $ability Ability name.
	 * @param mixed  $input   Ability input.
	 *
	 * @return array<string, mixed>
	 */
	private static function binding( string $server, string $tool, string $ability, $input ): array {
		return array(
			'user'    => get_current_user_id(),
			'blog'    => get_current_blog_id(),
			'server'  => $server,
			'tool'    => $tool,
			'ability' => $ability,
			'input'   => self::digest( $input ),
		);
	}

	/**
	 * Follow-ups are bound to the Ability and input, whichever tool on the server reaches it.
	 *
	 * @param array<string, mixed> $binding Full binding.
	 *
	 * @return array<string, mixed>
	 */
	private static function follow_up_binding( array $binding ): array {
		unset( $binding['tool'] );

		return $binding;
	}

	/**
	 * Storage key for a follow-up grant.
	 *
	 * @param array<string, mixed> $follow_up_binding Follow-up binding.
	 */
	private static function follow_up_key( array $follow_up_binding ): string {
		return substr( hash( 'sha256', (string) wp_json_encode( $follow_up_binding ) ), 0, 32 );
	}

	/**
	 * Decline and forget a confirmation.
	 *
	 * @param string $id Confirmation identifier.
	 */
	private static function declined( string $id ): WP_Error {
		delete_transient( self::PENDING_PREFIX . $id );

		return new WP_Error( 'mcp_adapter_confirmation_declined', __( 'The person did not confirm this change, so nothing ran. Do not try it again unless they ask.', 'mcp-adapter' ) );
	}

	/**
	 * Load an unexpired record.
	 *
	 * @param string $key Transient key.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function load( string $key ): ?array {
		$record = get_transient( $key );
		if ( ! is_array( $record ) || ! isset( $record['binding'], $record['channel'], $record['expires'] ) || ! is_array( $record['binding'] ) ) {
			return null;
		}
		if ( (int) $record['expires'] <= time() ) {
			delete_transient( $key );

			return null;
		}

		return $record;
	}

	/**
	 * Store a record until a fixed Unix time, so it expires with its confirmation.
	 *
	 * @param string               $key     Transient key.
	 * @param array<string, mixed> $value   Record.
	 * @param int                  $expires Unix time.
	 */
	private static function store_until( string $key, array $value, int $expires ): bool {
		$remaining = $expires - time();
		if ( $remaining <= 0 ) {
			delete_transient( $key );

			return false;
		}

		return set_transient( $key, $value, $remaining );
	}

	/**
	 * The elicitation question for a pending confirmation.
	 *
	 * @param array<string, mixed> $record Pending confirmation.
	 * @param string               $id     Confirmation identifier.
	 *
	 * @return array<string, mixed>
	 */
	private static function question( array $record, string $id ): array {
		$summary = (string) $record['summary'];

		if ( self::CHANNEL_URL === $record['channel'] ) {
			return array(
				'method' => 'elicitation/create',
				'params' => array(
					'mode'    => 'url',
					'message' => sprintf(
						/* translators: %s: what the call would do */
						__( "Allow this change? Open the page to approve or decline it.\n\n%s", 'mcp-adapter' ),
						$summary
					),
					'url'     => ConfirmationApprovalPage::url( $id ),
				),
			);
		}

		return array(
			'method' => 'elicitation/create',
			'params' => array(
				'mode'            => 'form',
				'message'         => sprintf(
					/* translators: %s: what the call would do */
					__( "Allow this change?\n\n%s", 'mcp-adapter' ),
					$summary
				),
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'confirm' => array(
							'type'  => 'boolean',
							'title' => __( 'Allow this change', 'mcp-adapter' ),
						),
					),
					'required'   => array( 'confirm' ),
				),
			),
		);
	}

	/**
	 * What the person is asked to confirm, and the part of it that may be kept.
	 *
	 * The default sentence shows the Ability and its input, because the person has to
	 * see what will change. It is only shown: since it is built from the input, it is
	 * never kept beyond the pending confirmation or passed to hooks. A sentence the
	 * Ability writes itself (`meta.mcp.confirmation_summary` or the summary filter) may be.
	 *
	 * @param \WP_Ability                                                                $ability    Target Ability.
	 * @param mixed                                                                      $input      Target input.
	 * @param list<array{binding: array<string, mixed>, summary: string, label: string}> $follow_ups Covered follow-ups.
	 *
	 * @return array{shown: string, ability: string|null, source: string}
	 */
	private static function summary( WP_Ability $ability, $input, array $follow_ups ): array {
		$json = (string) wp_json_encode( $input );
		if ( strlen( $json ) > 500 ) {
			$json = substr( $json, 0, 500 ) . '...';
		}
		$default = sprintf( '%1$s (%2$s) with %3$s', $ability->get_label(), $ability->get_name(), $json );

		$meta     = $ability->get_meta();
		$callback = is_array( $meta['mcp'] ?? null ) ? ( $meta['mcp']['confirmation_summary'] ?? null ) : null;
		$own      = is_callable( $callback ) ? call_user_func( $callback, $input ) : null;
		$summary  = is_string( $own ) && '' !== trim( $own ) ? trim( $own ) : $default;

		/**
		 * Filters the sentence a person is asked to confirm.
		 *
		 * Abilities that can predict their effect ("Move the page About us to the trash")
		 * should say so here, or with a `meta.mcp.confirmation_summary` callback that
		 * receives the input. Such a sentence is treated as the Ability's own: it is passed
		 * to `mcp_adapter_tool_call_confirmed` and may be logged, so keep secrets out of it.
		 * The default sentence is built from the input and is only shown. The confirmation
		 * stays bound to the full input whatever the summary shows. Covered follow-ups are
		 * appended after this filter, so they are always named.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param string      $summary Ability sentence, or the default: label, name and input JSON.
		 * @param \WP_Ability $ability The Ability that would run.
		 * @param mixed       $input   The input it would run with.
		 */
		$summary = apply_filters( 'mcp_adapter_tool_call_confirmation_summary', $summary, $ability, $input );
		$summary = is_string( $summary ) && '' !== trim( $summary ) ? $summary : $default;
		$source  = $default === $summary ? 'default' : 'ability';

		$shown = $summary;
		if ( array() !== $follow_ups ) {
			$shown .= "\n\n" . __( 'The same yes also covers:', 'mcp-adapter' ) . "\n- " . implode( "\n- ", array_column( $follow_ups, 'summary' ) );
		}

		return array(
			'shown'   => $shown,
			'ability' => 'ability' === $source ? $summary : null,
			'source'  => $source,
		);
	}

	/**
	 * Confirmation lifetime in seconds, between 30 seconds and one hour.
	 */
	private static function ttl(): int {
		/**
		 * Filters how long a confirmation stays valid, in seconds.
		 *
		 * Its stored result and any follow-ups it covers expire at the same time.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $ttl Lifetime in seconds. Default 300.
		 */
		$ttl = (int) apply_filters( 'mcp_adapter_tool_call_confirmation_ttl', self::DEFAULT_TTL );

		return max( 30, min( HOUR_IN_SECONDS, $ttl ) );
	}

	/**
	 * SHA-256 of the input with object keys sorted at every depth.
	 *
	 * @param mixed $input Input.
	 */
	private static function digest( $input ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $input ) ) );
	}

	/**
	 * Sort associative array keys recursively; list order is meaningful and kept.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_values( $value ) !== $value ) {
			ksort( $value, SORT_STRING );
		}

		return array_map( array( self::class, 'canonical' ), $value );
	}

	/**
	 * Error for an unknown or expired confirmation.
	 */
	private static function unknown(): WP_Error {
		return new WP_Error(
			'mcp_adapter_confirmation_unknown',
			__( 'This confirmation is unknown or expired, so nothing ran this time. Check whether the change already happened before asking the person again.', 'mcp-adapter' )
		);
	}

	/**
	 * Error for an approval link that no longer works.
	 */
	private static function link_expired(): WP_Error {
		return new WP_Error(
			'mcp_adapter_confirmation_link_expired',
			__( 'This approval link has expired or is not valid. Nothing will run. Ask the agent to try again if you still want the change.', 'mcp-adapter' ),
			array( 'status' => 410 )
		);
	}

	/**
	 * Error for a confirmation given for a different call.
	 */
	private static function mismatch(): WP_Error {
		return new WP_Error(
			'mcp_adapter_confirmation_mismatch',
			__( 'This confirmation was given for a different call, so nothing ran. Send exactly the arguments the person confirmed, or call without the token to ask them about this one.', 'mcp-adapter' )
		);
	}
}
