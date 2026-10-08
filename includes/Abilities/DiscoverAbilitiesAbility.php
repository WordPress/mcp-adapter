<?php
/**
 * Ability for discovering available WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP_Error;

/**
 * Discover Abilities - Searches the WordPress abilities exposed through MCP.
 *
 * This ability provides discovery functionality for the MCP protocol. It returns a
 * ranked, paged result set, so the response size does not grow with the catalog.
 *
 * SECURITY CONSIDERATIONS:
 * - This ability exposes information about all registered abilities in the system
 * - Only abilities with effective MCP public exposure will be returned
 * - Requires proper WordPress capability checks for secure operation
 *
 * @see https://developer.wordpress.org/apis/security/ for detailed security guidance
 */
final class DiscoverAbilitiesAbility {
	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/discover-abilities',
			array(
				'label'               => 'Discover Abilities',
				'description'         => 'Searches the abilities that this site exposes through MCP, by keyword, and returns a ranked page of matches. Each match includes a short input signature and the safety annotations of the ability. An empty query lists all exposed abilities in name order. Matching compares words literally with ability names, labels, categories, and descriptions, so synonyms do not match. When no ability matches every word, the results are the abilities that match some of the words. Results are not filtered by the permissions of the current user. The returned names are the identifiers that mcp-adapter-get-ability-info and mcp-adapter-execute-ability accept.',
				'category'            => 'mcp-adapter',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'Words that describe the operation, for example "create post" or "list plugins". Each word matches literally: WordPress terms such as post, page, user, comment, and plugin match, but synonyms such as article or website do not. Words shorter than 3 characters and common words such as "the" are ignored, and plural endings are removed. Empty or omitted lists all exposed abilities.',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Category slug, for example "site". Limits the results to abilities in this category. The categories field of an empty-query response lists the slugs.',
						),
						'limit'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => AbilitySearch::MAX_LIMIT,
							'description' => sprintf( 'Maximum number of matches in the page. Default %d.', AbilitySearch::DEFAULT_LIMIT ),
						),
						'offset'   => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Number of ranked matches to skip, for paging. Default 0.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'query'           => array(
							'type'        => 'string',
							'description' => 'The query, without leading and trailing spaces.',
						),
						'total'           => array(
							'type'        => 'integer',
							'description' => 'Number of matching abilities before paging.',
						),
						'offset'          => array( 'type' => 'integer' ),
						'limit'           => array( 'type' => 'integer' ),
						'has_more'        => array(
							'type'        => 'boolean',
							'description' => 'Whether more matches exist after this page.',
						),
						'abilities'       => array(
							'type'        => 'array',
							'description' => 'The matches in this page, best match first.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'label'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'category'    => array(
										'type'        => 'string',
										'description' => 'Category slug.',
									),
									'input'       => array(
										'type'        => 'string',
										'description' => 'Top-level input parameters as "name (type)"; "*" marks a required parameter, and "no input" means the ability takes none. Nested shapes, constraints, and parameter descriptions are in the input schema from mcp-adapter-get-ability-info.',
									),
									'annotations' => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => 'The annotations that the ability sets to true, for example readonly, destructive, or idempotent. An empty list means that none is set to true.',
									),
								),
								'required'   => array( 'name', 'label', 'description' ),
							),
						),
						'partial_matches' => array(
							'type'        => 'array',
							'description' => 'The matches in this page that miss some query words, with the missed words. Present only when no ability matches every word.',
						),
						'categories'      => array(
							'type'        => 'array',
							'description' => 'Number of exposed abilities per category, with the category slug and label. Present for an empty query and when nothing matches.',
						),
						'next_step'       => array( 'type' => 'string' ),
					),
					'required'   => array( 'abilities' ),
				),
				'permission_callback' => array( self::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the discover abilities functionality.
	 *
	 * Note: Permission checks are handled by the WP_Ability::execute() framework method
	 * before this callback is invoked.
	 *
	 * @see \WP\MCP\Abilities\AbilitySearch::search()
	 * @see \WP_Ability::execute()
	 *
	 * @param array|null $input Search arguments: query, category, limit, offset.
	 *
	 * @return array|\WP_Error The ranked, paged search result, or an error for invalid arguments.
	 */
	public static function execute( $input = array() ) {
		return AbilitySearch::search( is_array( $input ) ? $input : array() );
	}

	/**
	 * Check permissions for discovering abilities.
	 *
	 * Validates user capabilities and caller identity.
	 *
	 * @param array $input Input parameters (unused for this ability).
	 *
	 * @return bool|\WP_Error True if the user has permission to discover abilities.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the ability callback.
	public static function check_permission( $input = array() ) {
		// Verify caller identity - ensure user is authenticated
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', 'User must be authenticated to access this ability' );
		}

		/**
		 * Filters the capability required to discover available abilities.
		 *
		 * This capability is checked before listing all registered WordPress abilities
		 * through the mcp-adapter-discover-abilities tool.
		 *
		 * @since 0.3.0
		 *
		 * @param string $capability The required capability. Default 'read'.
		 */
		$required_capability = apply_filters( 'mcp_adapter_discover_abilities_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is determined dynamically via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf( 'User lacks required capability: %s', $required_capability )
			);
		}

		return true;
	}
}
