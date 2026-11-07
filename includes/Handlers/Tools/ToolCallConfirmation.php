<?php
/**
 * Confirmation before destructive Ability calls.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP_Ability;
use WP_Error;

/**
 * Asks the person before a destructive Ability call runs.
 *
 * A call that needs confirmation does not run. It answers with what it would do and
 * a short-lived, single-use confirmation bound to the user, the server, the tool, the
 * target Ability and a digest of its exact input. Only a later call that carries that
 * confirmation back, with the same input, runs.
 *
 * Two channels carry the question:
 *
 * - Form elicitation, when the request uses MCP 2026-07-28 and the client declares
 *   form elicitation. The client shows the question to the person and returns the
 *   answer; the model never sees the confirmation.
 * - The conversation, for every other client. The call returns a tool execution error
 *   whose text tells the agent what to ask and which token to send back. This proves
 *   that the agent was told to ask, not that a person answered. Sites that need more
 *   can turn this channel off with `mcp_adapter_tool_call_confirmation_allows_chat`.
 *
 * Confirmation never widens what a user may do: it runs after the tool's permission
 * check and after `mcp_adapter_pre_tool_call`, so a call that would be refused is
 * refused before anyone is asked.
 *
 * Experimental: names, defaults and messages may change while the Adapter is 0.x.
 *
 * @internal Used by ToolsHandler.
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
	 * Prefix that marks request state issued by this class.
	 *
	 * @var string
	 */
	private const STATE_PREFIX = 'mcp-adapter-confirmation.';

	/**
	 * Transient key prefix for pending confirmations.
	 *
	 * @var string
	 */
	private const STORE_PREFIX = 'mcp_adapter_confirm_';

	/**
	 * Default confirmation lifetime in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = 300;

	/**
	 * Channel names stored with each confirmation.
	 */
	private const CHANNEL_CHAT        = 'chat';
	private const CHANNEL_ELICITATION = 'elicitation';

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
	 * @return \WP\MCP\Domain\Tools\McpInputRequired|\WP_Error|null Null when the call may run.
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

		$binding = array(
			'user'    => get_current_user_id(),
			'blog'    => get_current_blog_id(),
			'server'  => $server->get_server_id(),
			'tool'    => $tool_name,
			'ability' => $ability->get_name(),
			'input'   => self::digest( $input ),
		);

		$state = $context->request_state();
		if ( self::is_confirmation_state( $state ) ) {
			return self::continue_elicitation( substr( (string) $state, strlen( self::STATE_PREFIX ) ), $binding, $context, $tool_name, $server );
		}

		if ( null !== $token ) {
			return self::redeem_token( $token, $binding, $tool_name, $server );
		}

		return self::ask( $ability, $input, $binding, $context );
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
			return array( $ability, $args );
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

		// Agent users (WordPress/ai#1068) are asked by default; people keep today's behaviour.
		$is_agent = function_exists( 'wpai_is_agent_user' ) && (bool) wpai_is_agent_user( get_current_user_id() );

		/**
		 * Filters whether an Ability call over MCP needs the person's confirmation first.
		 *
		 * Runs after the tool's permission check, so returning true never lets a user do
		 * more than they already may. Default: true for Abilities annotated destructive
		 * (and not read-only) when the current user is an agent user.
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
		return true === apply_filters( 'mcp_adapter_tool_call_requires_confirmation', $destructive && $is_agent, $ability, $input, $tool_name, $server );
	}

	/**
	 * Issue a confirmation and return the question for the current channel.
	 *
	 * @param \WP_Ability                              $ability Target Ability.
	 * @param mixed                                    $input   Target input.
	 * @param array<string, mixed>                     $binding What the confirmation is bound to.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext  $context Request context.
	 *
	 * @return \WP\MCP\Domain\Tools\McpInputRequired|\WP_Error
	 */
	private static function ask( WP_Ability $ability, $input, array $binding, McpToolCallContext $context ) {
		$summary     = self::summary( $ability, $input );
		$elicitation = $context->client_supports_elicitation( 'form' );

		if ( ! $elicitation ) {
			/**
			 * Filters whether a confirmation may be asked for in the conversation.
			 *
			 * A conversation confirmation shows that the agent was told to ask, not that a
			 * person answered. Return false to accept only confirmations the MCP client
			 * collects from the person itself (form elicitation).
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

		$id      = bin2hex( random_bytes( 16 ) );
		$ttl     = self::ttl();
		$expires = time() + $ttl;
		$record  = array(
			'binding'   => $binding,
			'channel'   => $elicitation ? self::CHANNEL_ELICITATION : self::CHANNEL_CHAT,
			'summary'   => $summary,
			'issued_at' => time(),
			'expires'   => $expires,
		);
		if ( ! set_transient( self::STORE_PREFIX . $id, $record, $ttl ) ) {
			return new WP_Error( 'mcp_adapter_confirmation_storage_failed', __( 'The confirmation could not be saved, so nothing ran.', 'mcp-adapter' ) );
		}

		if ( $elicitation ) {
			return new McpInputRequired( array( self::INPUT_KEY => self::question( $summary ) ), self::STATE_PREFIX . $id );
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
	 * Redeem a conversation token.
	 *
	 * @param string                 $token     Token sent with the call.
	 * @param array<string, mixed>   $binding   What this call is.
	 * @param string                 $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer $server    The MCP server.
	 *
	 * @return \WP_Error|null Null when the call may run.
	 */
	private static function redeem_token( string $token, array $binding, string $tool_name, McpServer $server ): ?WP_Error {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $token ) ) {
			return self::unknown();
		}

		$record = self::load( $token );
		if ( null === $record || self::CHANNEL_CHAT !== $record['channel'] ) {
			return self::unknown();
		}
		if ( $record['binding'] !== $binding ) {
			return self::mismatch();
		}

		return self::consume( $token, $record, $tool_name, $server );
	}

	/**
	 * Continue an elicitation round.
	 *
	 * @param string                                  $id        Confirmation identifier from request state.
	 * @param array<string, mixed>                    $binding   What this call is.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context   Request context.
	 * @param string                                  $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer                  $server    The MCP server.
	 *
	 * @return \WP\MCP\Domain\Tools\McpInputRequired|\WP_Error|null Null when the call may run.
	 */
	private static function continue_elicitation( string $id, array $binding, McpToolCallContext $context, string $tool_name, McpServer $server ) {
		$record = self::load( $id );
		if ( null === $record || self::CHANNEL_ELICITATION !== $record['channel'] ) {
			return self::unknown();
		}
		if ( $record['binding'] !== $binding ) {
			return self::mismatch();
		}

		$responses = $context->input_responses();
		$answer    = $responses->{self::INPUT_KEY} ?? null;
		if ( ! $answer instanceof \stdClass || ! isset( $answer->action ) ) {
			// No answer to this question yet: ask it again with the same state.
			return new McpInputRequired( array( self::INPUT_KEY => self::question( (string) $record['summary'] ) ), self::STATE_PREFIX . $id );
		}

		$content  = $answer->content ?? null;
		$accepted = 'accept' === $answer->action
			&& $content instanceof \stdClass
			&& property_exists( $content, 'confirm' )
			&& true === $content->confirm;

		if ( ! $accepted ) {
			delete_transient( self::STORE_PREFIX . $id );

			return new WP_Error( 'mcp_adapter_confirmation_declined', __( 'The person did not confirm this change, so nothing ran. Do not try it again unless they ask.', 'mcp-adapter' ) );
		}

		return self::consume( $id, $record, $tool_name, $server );
	}

	/**
	 * Use a confirmation once. Only the request that deletes it may run.
	 *
	 * @param string                 $id        Confirmation identifier.
	 * @param array<string, mixed>   $record    Stored confirmation.
	 * @param string                 $tool_name MCP tool name.
	 * @param \WP\MCP\Core\McpServer $server    The MCP server.
	 */
	private static function consume( string $id, array $record, string $tool_name, McpServer $server ): ?WP_Error {
		if ( ! delete_transient( self::STORE_PREFIX . $id ) ) {
			return self::unknown();
		}

		/**
		 * Fires when a confirmed Ability call is about to run.
		 *
		 * The record carries a digest of the input, never the input itself, so that this
		 * broadcast action cannot leak call arguments to every listener. Use it to log
		 * that the person said yes, and to what.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param array                  $confirmation {
		 *     @type string $ability     Ability name.
		 *     @type string $input_hash  SHA-256 of the canonical input JSON.
		 *     @type string $summary     What the person was asked to confirm.
		 *     @type string $channel     'elicitation' or 'chat'.
		 *     @type int    $user_id     User the call runs as.
		 *     @type int    $issued_at   Unix time the question was asked.
		 *     @type int    $confirmed_at Unix time the confirmation was used.
		 * }
		 * @param string                 $tool_name The MCP tool name.
		 * @param \WP\MCP\Core\McpServer $server    The MCP server.
		 */
		do_action(
			'mcp_adapter_tool_call_confirmed',
			array(
				'ability'      => $record['binding']['ability'],
				'input_hash'   => $record['binding']['input'],
				'summary'      => $record['summary'],
				'channel'      => $record['channel'],
				'user_id'      => $record['binding']['user'],
				'issued_at'    => $record['issued_at'],
				'confirmed_at' => time(),
			),
			$tool_name,
			$server
		);

		return null;
	}

	/**
	 * Load an unexpired confirmation record.
	 *
	 * @param string $id Confirmation identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function load( string $id ): ?array {
		$record = get_transient( self::STORE_PREFIX . $id );
		if ( ! is_array( $record ) || ! isset( $record['binding'], $record['channel'], $record['expires'] ) ) {
			return null;
		}
		if ( (int) $record['expires'] <= time() ) {
			delete_transient( self::STORE_PREFIX . $id );

			return null;
		}

		return $record;
	}

	/**
	 * The form question shown by the client.
	 *
	 * @param string $summary What the call would do.
	 *
	 * @return array<string, mixed>
	 */
	private static function question( string $summary ): array {
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
	 * A plain description of what the call would do.
	 *
	 * @param \WP_Ability $ability Target Ability.
	 * @param mixed       $input   Target input.
	 */
	private static function summary( WP_Ability $ability, $input ): string {
		$json = (string) wp_json_encode( $input );
		if ( strlen( $json ) > 500 ) {
			$json = substr( $json, 0, 500 ) . '...';
		}
		$default = sprintf( '%1$s (%2$s) with %3$s', $ability->get_label(), $ability->get_name(), $json );

		/**
		 * Filters the sentence a person is asked to confirm.
		 *
		 * Abilities that can predict their effect ("Delete the page About us and its 3
		 * revisions") should say so here. The confirmation stays bound to the full input
		 * whatever the summary shows.
		 *
		 * Experimental.
		 *
		 * @since n.e.x.t
		 *
		 * @param string      $summary Default summary: label, name and input JSON.
		 * @param \WP_Ability $ability The Ability that would run.
		 * @param mixed       $input   The input it would run with.
		 */
		$summary = apply_filters( 'mcp_adapter_tool_call_confirmation_summary', $default, $ability, $input );

		return is_string( $summary ) && '' !== trim( $summary ) ? $summary : $default;
	}

	/**
	 * Confirmation lifetime in seconds, between 30 seconds and one hour.
	 */
	private static function ttl(): int {
		/**
		 * Filters how long a confirmation stays valid, in seconds.
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
	 * Error for an unknown, expired or used confirmation.
	 */
	private static function unknown(): WP_Error {
		return new WP_Error(
			'mcp_adapter_confirmation_unknown',
			__( 'This confirmation is unknown, expired or already used, so nothing ran this time. Check whether the change already happened before asking the person again.', 'mcp-adapter' )
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
