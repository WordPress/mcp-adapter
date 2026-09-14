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
				$expected_user = getenv( 'MCP_ADAPTER_EXPECT_USER' );
				$user_id       = get_current_user_id();

				if ( 'authenticated' === $expected_user && ( 1 !== $user_id || ! current_user_can( 'read' ) ) ) {
					\WP_CLI::error( 'WP-CLI global --user was not applied to the MCP server user context.' );
				}

				if ( 'anonymous' === $expected_user && 0 !== $user_id ) {
					\WP_CLI::error( 'MCP server user context should be anonymous when WP-CLI global --user is omitted.' );
				}

				return false;
			}
		);
	}
);
