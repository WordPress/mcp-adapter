<?php
/**
 * Validates the user context seen by the serve command in a real WP-CLI run.
 *
 * @package WP\MCP\Tests
 */

\WP_CLI::add_wp_hook(
	'plugins_loaded',
	static function (): void {
		add_filter(
			'mcp_adapter_enable_stdio_transport',
			static function (): bool {
				if ( 1 !== get_current_user_id() || ! current_user_can( 'read' ) ) {
					\WP_CLI::error( 'WP-CLI global --user was not applied to the MCP server user context.' );
				}

				return false;
			}
		);
	}
);
