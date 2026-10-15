<?php
/**
 * Suggests registered ability names close to a name that does not exist.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

/**
 * Class AbilityNameSuggester
 *
 * When an MCP client asks for an ability that is not registered, the client
 * has usually guessed the name: the namespace spelled another way
 * (`rank-math/` for `rankmath/`), the words of the action in another order
 * (`redirection-create` for `create-redirection`), or a plural. Answering
 * only "not found" leaves it to guess again, and some models keep guessing
 * for a hundred calls or more. Naming the closest real abilities lets it
 * take the right one or stop.
 *
 * Only abilities exposed through MCP are suggested, so every suggestion is
 * one the client can call.
 *
 * @since n.e.x.t
 */
final class AbilityNameSuggester {

	/**
	 * Lowest score a registered name needs to be suggested.
	 *
	 * Below this the names share too little to be the one the client meant,
	 * and a client spends a call on whatever it is handed.
	 *
	 * @var float
	 */
	public const MIN_SCORE = 0.6;

	/**
	 * Lowest action score a registered name needs, whatever its namespace.
	 *
	 * A shared namespace alone (`core/get-opening-hours` against
	 * `core/get-user-info`) can lift an unrelated ability over MIN_SCORE, and
	 * a client handed it keeps guessing around it.
	 *
	 * @var float
	 */
	public const MIN_ACTION_SCORE = 0.6;

	/**
	 * Longest name worth scoring. Real ability names are far shorter; a longer
	 * one gets no suggestions instead of an expensive comparison per ability.
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 200;

	/**
	 * Verbs that only read. A read ability is never suggested for a request
	 * that writes, or the other way round: `core/get-site-info` is not what a
	 * client asking for `core/set-site-info` needs.
	 *
	 * @var string[]
	 */
	private const READ_VERBS = array(
		'get',
		'list',
		'read',
		'find',
		'search',
		'view',
		'fetch',
		'show',
		'count',
		'check',
		'query',
		'export',
		'discover',
	);

	/**
	 * Verbs that change something.
	 *
	 * @var string[]
	 */
	private const WRITE_VERBS = array(
		'set',
		'create',
		'update',
		'delete',
		'add',
		'remove',
		'edit',
		'save',
		'insert',
		'publish',
		'unpublish',
		'install',
		'uninstall',
		'activate',
		'deactivate',
		'enable',
		'disable',
		'upload',
		'import',
		'replace',
		'assign',
		'move',
		'duplicate',
		'restore',
		'trash',
		'approve',
		'reject',
		'send',
		'schedule',
		'register',
		'write',
		'put',
		'patch',
		'post',
	);

	/**
	 * Returns the registered, MCP-exposed ability names closest to a name.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $requested The ability name the client asked for.
	 * @param int    $limit     The most names to return.
	 *
	 * @return string[] Ability names, closest first. Empty when none is close.
	 */
	public static function suggest( string $requested, int $limit = 3 ): array {
		// Normalized for scoring only: `RankMath/Create-Redirection` and
		// ` rankmath/create-redirection ` should still lead to the real name.
		$normalized = strtolower( trim( $requested ) );
		if ( '' === $normalized || strlen( $normalized ) > self::MAX_NAME_LENGTH || $limit < 1 || ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$wanted     = self::split( $normalized );
		$wanted_dir = self::direction( $wanted[1] );
		$scores     = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof \WP_Ability || ! McpAbilityExposure::is_public( $ability ) ) {
				continue;
			}

			$name = $ability->get_name();
			if ( $name === $requested ) {
				continue;
			}

			$candidate = self::split( strtolower( $name ) );

			$candidate_dir = self::direction( $candidate[1] );
			if ( null !== $wanted_dir && null !== $candidate_dir && $wanted_dir !== $candidate_dir ) {
				continue;
			}

			$action = self::action_score( $wanted[1], $candidate[1] );
			if ( $action < self::MIN_ACTION_SCORE ) {
				continue;
			}

			$score = 0.7 * $action + 0.3 * self::similarity( self::squash( $wanted[0] ), self::squash( $candidate[0] ) );
			if ( $score < self::MIN_SCORE ) {
				continue;
			}

			$scores[ $name ] = $score;
		}

		arsort( $scores );

		return array_slice( array_keys( $scores ), 0, $limit );
	}

	/**
	 * Scores how close two actions (the part after the slash) are, from 0 to 1.
	 *
	 * The action carries most of the weight of a suggestion: a client usually
	 * knows what it wants to do and misremembers the prefix.
	 *
	 * @param string $a One action.
	 * @param string $b The other.
	 *
	 * @return float
	 */
	private static function action_score( string $a, string $b ): float {
		return max(
			self::similarity( self::squash( $a ), self::squash( $b ) ),
			self::word_overlap( $a, $b )
		);
	}

	/**
	 * Whether an action reads or writes, from its first known verb.
	 *
	 * @param string $action An action such as `get-site-info`.
	 *
	 * @return string|null 'read', 'write', or null when no known verb is in it.
	 */
	private static function direction( string $action ): ?string {
		foreach ( preg_split( '/[-_.\s]+/', $action ) ?: array() as $word ) {
			if ( in_array( $word, self::READ_VERBS, true ) ) {
				return 'read';
			}
			if ( in_array( $word, self::WRITE_VERBS, true ) ) {
				return 'write';
			}
		}

		return null;
	}

	/**
	 * Splits a name into its namespace and action.
	 *
	 * @param string $name An ability name such as `vendor/action`.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function split( string $name ): array {
		$slash = strpos( $name, '/' );
		if ( false === $slash ) {
			return array( '', $name );
		}

		return array( substr( $name, 0, $slash ), substr( $name, $slash + 1 ) );
	}

	/**
	 * Removes the separators that names spell differently (`rank-math`, `rank_math`, `rankmath`).
	 *
	 * @param string $part A namespace or action.
	 *
	 * @return string
	 */
	private static function squash( string $part ): string {
		return str_replace( array( '-', '_', '.', ' ' ), '', $part );
	}

	/**
	 * Edit-distance similarity of two strings, from 0 to 1.
	 *
	 * @param string $a One string.
	 * @param string $b The other.
	 *
	 * @return float
	 */
	private static function similarity( string $a, string $b ): float {
		if ( $a === $b ) {
			return 1.0;
		}

		// levenshtein() is limited to 255 characters before PHP 8.
		// $a and $b differ here, so at least one is not empty.
		$a = substr( $a, 0, 255 );
		$b = substr( $b, 0, 255 );

		return max( 0.0, 1 - levenshtein( $a, $b ) / max( strlen( $a ), strlen( $b ) ) );
	}

	/**
	 * Share of words two actions have in common, so word order does not matter.
	 *
	 * @param string $a One action, such as `create-redirection`.
	 * @param string $b The other, such as `redirection-create`.
	 *
	 * @return float
	 */
	private static function word_overlap( string $a, string $b ): float {
		$words_a = array_unique( array_filter( preg_split( '/[-_.\s]+/', $a ) ?: array() ) );
		$words_b = array_unique( array_filter( preg_split( '/[-_.\s]+/', $b ) ?: array() ) );
		if ( array() === $words_a || array() === $words_b ) {
			return 0.0;
		}

		$shared = count( array_intersect( $words_a, $words_b ) );
		$total  = count( array_unique( array_merge( $words_a, $words_b ) ) );

		return $shared / $total;
	}
}
