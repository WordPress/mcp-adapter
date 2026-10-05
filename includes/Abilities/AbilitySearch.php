<?php
/**
 * Ranked, paged search over the abilities exposed by the default MCP server.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP_Ability;
use WP_Error;

/**
 * Searches public MCP tool abilities by keyword.
 *
 * The result size depends on `limit`, not on how many abilities the site registers, so
 * discovery stays small on sites with hundreds of abilities. Each hit carries a compact
 * input signature and its true safety annotations, so an agent can often execute without
 * a separate `mcp-adapter-get-ability-info` call.
 *
 * @internal
 *
 * @since n.e.x.t
 */
final class AbilitySearch {
	use McpAbilityHelperTrait;

	/**
	 * Default number of results per page.
	 *
	 * @since n.e.x.t
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * Highest accepted `limit`.
	 *
	 * @since n.e.x.t
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Highest accepted query length in bytes.
	 */
	private const MAX_QUERY_BYTES = 500;

	/**
	 * Score per matched term, by field. A hit in the name outranks a hit in the description.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const FIELD_WEIGHTS = array(
		'name'        => 8,
		'label'       => 4,
		'category'    => 2,
		'description' => 1,
	);

	/**
	 * Bonus when the whole query appears in the name or label.
	 */
	private const PHRASE_BONUS = 10;

	/**
	 * Highest number of enum values an input signature lists before it truncates.
	 */
	private const SIGNATURE_ENUM_CAP = 6;

	/**
	 * Shortest query word that counts as a term. Shorter words match almost any text as a substring.
	 */
	private const MIN_TERM_LENGTH = 3;

	/**
	 * Query words that match almost every ability and do not help ranking.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const STOPWORDS = array( 'and', 'all', 'for', 'from', 'into', 'please', 'the', 'this', 'with' );

	/**
	 * Searches the public tool abilities.
	 *
	 * An empty query returns the first page in name order, plus a category overview.
	 * A query returns the abilities that match every term. When no ability matches every
	 * term, it returns the abilities that match at least one term, and lists the terms each
	 * one missed in `partial_matches`. When nothing matches, it adds the category overview.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<string, mixed> $input Tool arguments: query, category, limit, offset.
	 *
	 * @return array<string, mixed>|\WP_Error The search result, or an error for invalid arguments.
	 */
	public static function search( array $input ) {
		$query    = $input['query'] ?? '';
		$category = $input['category'] ?? '';
		$limit    = $input['limit'] ?? self::DEFAULT_LIMIT;
		$offset   = $input['offset'] ?? 0;

		if ( ! is_string( $query ) || strlen( $query ) > self::MAX_QUERY_BYTES ) {
			return new WP_Error( 'invalid_query', sprintf( 'query must be a string of at most %d bytes. Use an empty string to browse.', self::MAX_QUERY_BYTES ) );
		}
		if ( ! is_string( $category ) ) {
			return new WP_Error( 'invalid_category', 'category must be a string.' );
		}
		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_LIMIT || ! is_int( $offset ) || $offset < 0 ) {
			return new WP_Error( 'invalid_pagination', sprintf( 'limit must be an integer from 1 to %d, and offset a nonnegative integer.', self::MAX_LIMIT ) );
		}

		$abilities = self::public_tool_abilities();
		if ( '' !== $category ) {
			$abilities = array_values(
				array_filter(
					$abilities,
					static fn ( WP_Ability $ability ): bool => $ability->get_category() === $category
				)
			);
		}

		$phrase  = strtolower( trim( $query ) );
		$terms   = self::terms( $phrase );
		$full    = array();
		$partial = array();
		foreach ( $abilities as $ability ) {
			$match = self::match( $ability, $phrase, $terms );
			if ( array() === $match['unmatched'] ) {
				$full[] = $match;
			} elseif ( $match['score'] > 0 ) {
				$partial[] = $match;
			}
		}
		$matches = array() === $full ? $partial : $full;

		usort(
			$matches,
			static function ( array $left, array $right ): int {
				$ranking = $right['score'] <=> $left['score'];

				return 0 !== $ranking ? $ranking : strcmp( $left['ability']->get_name(), $right['ability']->get_name() );
			}
		);

		$total           = count( $matches );
		$results         = array();
		$partial_matches = array();
		foreach ( array_slice( $matches, $offset, $limit ) as $match ) {
			$results[] = self::summarize( $match['ability'] );
			if ( array() === $match['unmatched'] ) {
				continue;
			}

			$partial_matches[] = array(
				'name'            => $match['ability']->get_name(),
				'unmatched_terms' => $match['unmatched'],
			);
		}

		$response = array(
			'query'     => trim( $query ),
			'total'     => $total,
			'offset'    => $offset,
			'limit'     => $limit,
			'has_more'  => $offset + count( $results ) < $total,
			'abilities' => $results,
		);
		if ( array() !== $partial_matches ) {
			$response['partial_matches'] = $partial_matches;
		}
		if ( array() === $terms || 0 === $total ) {
			$response['categories'] = self::overview( self::public_tool_abilities() );
		}
		if ( 0 === $total ) {
			$response['next_step'] = 'No ability matched. Use words from a category or an ability label below, or browse with an empty query.';
		}

		return $response;
	}

	/**
	 * Returns the abilities the default server exposes as tools.
	 *
	 * @return list<\WP_Ability> The public abilities with MCP type `tool`.
	 */
	private static function public_tool_abilities(): array {
		$abilities = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! self::is_ability_mcp_public( $ability ) || 'tool' !== self::get_ability_mcp_type( $ability ) ) {
				continue;
			}

			$abilities[] = $ability;
		}

		return $abilities;
	}

	/**
	 * Splits a lowercase query into unique terms, without short words, stopwords, and plural suffixes.
	 *
	 * @param string $phrase The lowercase query.
	 *
	 * @return list<string> The query terms. Empty for an empty query.
	 */
	private static function terms( string $phrase ): array {
		$words = preg_split( '/[^a-z0-9]+/', $phrase, -1, PREG_SPLIT_NO_EMPTY );
		$words = false === $words ? array() : $words;
		$terms = array();
		foreach ( $words as $word ) {
			if ( strlen( $word ) < self::MIN_TERM_LENGTH || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}

			$terms[] = self::singular( $word );
		}

		// A query of only short words or stopwords still has something to match on.
		if ( array() === $terms ) {
			$terms = array_map( array( self::class, 'singular' ), $words );
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Removes a plural suffix, so "plugins" matches "plugin" and "categories" matches "category".
	 *
	 * Terms match fields by substring, so the singular form also matches the plural form.
	 *
	 * @param string $word A lowercase word.
	 *
	 * @return string The word without its plural suffix.
	 */
	private static function singular( string $word ): string {
		if ( strlen( $word ) > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 );
		}
		if ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Scores one ability against the query terms.
	 *
	 * @param \WP_Ability  $ability The ability.
	 * @param string       $phrase  The lowercase query.
	 * @param list<string> $terms   The query terms.
	 *
	 * @return array{ability: \WP_Ability, score: int, unmatched: list<string>} The match.
	 */
	private static function match( WP_Ability $ability, string $phrase, array $terms ): array {
		$fields = array(
			'name'        => strtolower( $ability->get_name() ),
			'label'       => strtolower( $ability->get_label() ),
			'category'    => strtolower( $ability->get_category() ),
			'description' => strtolower( $ability->get_description() ),
		);

		$score     = 0;
		$unmatched = array();
		foreach ( $terms as $term ) {
			$term_score = 0;
			foreach ( $fields as $field => $value ) {
				if ( false === strpos( $value, $term ) ) {
					continue;
				}

				$term_score += self::FIELD_WEIGHTS[ $field ];
			}

			if ( 0 === $term_score ) {
				$unmatched[] = $term;
			}
			$score += $term_score;
		}

		if ( '' !== $phrase && $score > 0 && ( false !== strpos( $fields['name'], $phrase ) || false !== strpos( $fields['label'], $phrase ) ) ) {
			$score += self::PHRASE_BONUS;
		}

		return array(
			'ability'   => $ability,
			'score'     => $score,
			'unmatched' => $unmatched,
		);
	}

	/**
	 * Builds the search hit for one ability.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return array<string, mixed> The search hit.
	 */
	private static function summarize( WP_Ability $ability ): array {
		$annotations = $ability->get_meta()['annotations'] ?? array();
		$annotations = is_array( $annotations ) ? $annotations : array();

		return array(
			'name'        => $ability->get_name(),
			'label'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'category'    => $ability->get_category(),
			'input'       => self::input_signature( $ability->get_input_schema() ),
			'annotations' => array_keys( array_filter( $annotations, static fn ( $value ): bool => true === $value ) ),
		);
	}

	/**
	 * Counts the abilities in each category.
	 *
	 * @param list<\WP_Ability> $abilities The abilities to count.
	 *
	 * @return list<array{category: string, label: string, count: int}> One row per category, in slug order.
	 */
	private static function overview( array $abilities ): array {
		// Read the keyed registry: wp_get_ability_category() raises a notice for a missing slug.
		$categories = wp_get_ability_categories();
		$rows       = array();
		foreach ( $abilities as $ability ) {
			$slug = $ability->get_category();
			if ( ! isset( $rows[ $slug ] ) ) {
				$rows[ $slug ] = array(
					'category' => $slug,
					'label'    => isset( $categories[ $slug ] ) ? $categories[ $slug ]->get_label() : $slug,
					'count'    => 0,
				);
			}

			++$rows[ $slug ]['count'];
		}
		ksort( $rows, SORT_STRING );

		return array_values( $rows );
	}

	/**
	 * Builds a one-line input signature, for example `id* (integer), status (enum: draft|publish)`.
	 *
	 * Required properties carry `*`. Nested shapes, constraints, and descriptions come from
	 * `get-ability-info`.
	 *
	 * @param array<string, mixed> $schema The ability input schema.
	 *
	 * @return string The signature, or `no input` when the schema has no properties.
	 */
	private static function input_signature( array $schema ): string {
		$properties = $schema['properties'] ?? array();
		if ( ! is_array( $properties ) || array() === $properties ) {
			return 'no input';
		}

		$required = is_array( $schema['required'] ?? null ) ? $schema['required'] : array();
		$parts    = array();
		foreach ( $properties as $name => $property ) {
			$marker  = in_array( $name, $required, true ) ? '*' : '';
			$parts[] = $name . $marker . ' (' . self::type_token( is_array( $property ) ? $property : array() ) . ')';
		}

		return implode( ', ', $parts );
	}

	/**
	 * Renders one property schema as a short type token.
	 *
	 * @param array<string, mixed> $property The property schema.
	 *
	 * @return string The type token, for example `integer`, `string|null`, `integer[]`, or `enum: asc|desc`.
	 */
	private static function type_token( array $property ): string {
		if ( isset( $property['enum'] ) && is_array( $property['enum'] ) && array() !== $property['enum'] ) {
			$values = array_map(
				static fn ( $value ): string => is_scalar( $value ) ? (string) $value : gettype( $value ),
				array_slice( $property['enum'], 0, self::SIGNATURE_ENUM_CAP )
			);

			return 'enum: ' . implode( '|', $values ) . ( count( $property['enum'] ) > self::SIGNATURE_ENUM_CAP ? '|…' : '' );
		}

		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $composite ) {
			if ( isset( $property[ $composite ] ) ) {
				return $composite;
			}
		}

		$type = $property['type'] ?? 'mixed';
		if ( is_array( $type ) ) {
			return implode( '|', array_map( 'strval', $type ) );
		}

		if ( 'array' === $type && is_array( $property['items'] ?? null ) && is_string( $property['items']['type'] ?? null ) ) {
			return $property['items']['type'] . '[]';
		}

		return (string) $type;
	}
}
