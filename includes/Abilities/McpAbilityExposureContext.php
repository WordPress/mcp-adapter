<?php
/**
 * Value object describing the context of an MCP ability exposure check.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP\MCP\Core\McpServer;

/**
 * Describes *when* and *for whom* the built-in discover / get-info /
 * execute tools are asking whether an ability is exposed via MCP.
 *
 * Passed as the third argument to the `mcp_adapter_is_ability_exposed`
 * filter so integrators can make exposure decisions that vary by:
 *
 *   - the MCP server handling the current request (multiple servers can
 *     be registered per site, each with its own scope),
 *   - the authenticated principal and its WordPress roles,
 *   - the current site (multisite / multi-tenant),
 *   - the *path* by which the decision is being asked (listing vs.
 *     inspecting vs. executing an ability — see the PATH_* constants).
 *
 * Treat instances as immutable: exposure decisions and any caches built
 * on top of them should be keyed by the full context, not by a subset.
 *
 * @since 0.6.0
 */
final class McpAbilityExposureContext {

	/**
	 * Exposure path: the discover-abilities listing.
	 *
	 * @var string
	 */
	public const PATH_DISCOVER = 'discover';

	/**
	 * Exposure path: the get-ability-info inspection.
	 *
	 * @var string
	 */
	public const PATH_GET_INFO = 'get_info';

	/**
	 * Exposure path: the execute-ability invocation gate.
	 *
	 * Note: exposure is not authorization. An ability that is "exposed"
	 * via this path still has its own `permission_callback` checked by
	 * the execute-ability tool before the ability actually runs.
	 *
	 * @var string
	 */
	public const PATH_EXECUTE = 'execute';

	/**
	 * The MCP server handling the current request, if any.
	 *
	 * Null when the ability is invoked outside of an MCP request
	 * (e.g. WP-CLI, cron, or a direct `wp_get_ability( ... )->execute()`
	 * call), in which case per-server allowlists should fall back to
	 * the ability's default (`$is_exposed`).
	 *
	 * @var \WP\MCP\Core\McpServer|null
	 */
	public ?McpServer $server;

	/**
	 * The authenticated principal's user ID. Zero if unauthenticated.
	 *
	 * @var int
	 */
	public int $principal_id;

	/**
	 * The authenticated principal's WordPress roles.
	 *
	 * @var array<int, string>
	 */
	public array $roles;

	/**
	 * The current site ID (`get_current_blog_id()`). Non-zero on both
	 * multisite and single-site installs.
	 *
	 * @var int
	 */
	public int $site_id;

	/**
	 * The exposure path — one of the PATH_* constants above.
	 *
	 * @var string
	 */
	public string $exposure_path;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer|null $server        The current server, or null.
	 * @param int                         $principal_id  User ID (0 if not logged in).
	 * @param array<int, string>          $roles         Role slugs.
	 * @param int                         $site_id       Current site ID.
	 * @param string                      $exposure_path One of the PATH_* constants.
	 */
	public function __construct(
		?McpServer $server,
		int $principal_id,
		array $roles,
		int $site_id,
		string $exposure_path
	) {
		$this->server        = $server;
		$this->principal_id  = $principal_id;
		$this->roles         = $roles;
		$this->site_id       = $site_id;
		$this->exposure_path = $exposure_path;
	}
}
