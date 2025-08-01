<?php //phpcs:ignore
declare(strict_types=1);

namespace WP\MCP\Resources;

use WP\MCP\Resources\Interfaces\ResourcesInterface;

/**
 * Class for managing MCP Site Resources functionality.
 */
class SiteResources implements ResourcesInterface {

	/**
	 * Get the resource definitions.
	 *
	 * @return array Array of resource definitions.
	 */
	public function get_resources(): array {
		$resources = array();

		// Current user information resource.
		$resources[] = array(
			'args'     => array(
				'uri'         => 'wordpress://core/current-user',
				'name'        => 'Current User Information',
				'description' => 'Get information about the currently logged-in user',
				'mimeType'    => 'application/json',
			),
			'callback' => array( $this, 'get_current_user' ),
		);

		// Current user sites resource.
		$resources[] = array(
			'args'     => array(
				'uri'         => 'wordpress://core/user-sites',
				'name'        => 'Current User Sites',
				'description' => 'Get list of sites accessible to the current user',
				'mimeType'    => 'application/json',
			),
			'callback' => array( $this, 'get_user_sites' ),
		);

		return $resources;
	}

	/**
	 * Get current user information.
	 *
	 * @param array $params Request parameters.
	 * @return array Current user data.
	 */
	public function get_current_user( array $params ): array {
		$current_user = wp_get_current_user();

		if ( ! $current_user->exists() ) {
			return array(
				'error' => 'No user is currently logged in',
			);
		}

		return array(
			'id'              => $current_user->ID,
			'username'        => $current_user->user_login,
			'email'           => $current_user->user_email,
			'display_name'    => $current_user->display_name,
			'first_name'      => $current_user->first_name,
			'last_name'       => $current_user->last_name,
			'nickname'        => $current_user->nickname,
			'roles'           => $current_user->roles,
			'capabilities'    => array_keys( $current_user->allcaps ),
			'registered_date' => $current_user->user_registered,
			'is_super_admin'  => is_super_admin( $current_user->ID ),
			'avatar_url'      => get_avatar_url( $current_user->ID ),
		);
	}

	/**
	 * Get current user sites.
	 *
	 * @param array $params Request parameters.
	 * @return array User sites data.
	 */
	public function get_user_sites( array $params ): array {
		$current_user = wp_get_current_user();

		if ( ! $current_user->exists() ) {
			return array(
				'error' => 'No user is currently logged in',
			);
		}

		$sites = array();

		if ( is_multisite() ) {
			$user_blogs = get_ordered_blogs_of_user( $current_user->ID );
			foreach ( $user_blogs as $blog ) {
				$blog_details = get_blog_details( $blog->userblog_id );
				if ( $blog_details ) {
					// Get user role on this site.
					$user_role = new \WP_User( $current_user->ID );
					$user_role->for_site( $blog->userblog_id );

					$sites[] = array(
						'blog_id'      => $blog->userblog_id,
						'site_url'     => $blog_details->siteurl,
						'site_name'    => $blog_details->blogname,
						'domain'       => $blog_details->domain,
						'path'         => $blog_details->path,
						'user_role'    => ! empty( $user_role->roles ) ? $user_role->roles[0] : 'subscriber',
						'is_public'    => (bool) $blog_details->public,
						'last_updated' => $blog_details->last_updated,
					);
				}
			}
		} else {
			// Single site - return current site info.
			$site_url  = get_site_url();
			$site_path = parse_url( $site_url, PHP_URL_PATH );

			$sites[] = array(
				'blog_id'      => get_current_blog_id(),
				'site_url'     => $site_url,
				'site_name'    => get_bloginfo( 'name' ),
				'domain'       => parse_url( $site_url, PHP_URL_HOST ),
				'path'         => $site_path ? $site_path : '/',
				'user_role'    => ! empty( $current_user->roles ) ? $current_user->roles[0] : 'subscriber',
				'is_public'    => (bool) get_option( 'blog_public', 1 ),
				'last_updated' => get_lastpostdate(),
			);
		}

		return array(
			'user_id'    => $current_user->ID,
			'site_count' => count( $sites ),
			'sites'      => $sites,
		);
	}
}
