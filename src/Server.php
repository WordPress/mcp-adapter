<?php
declare(strict_types=1);

namespace WP\MCP;

use WP\MCP\Tools\SitesTools;
use WP\MCP\Resources\SiteResources;
use WP\MCP\Prompts\SamplePrompts;
use WP\MCP\Adapter\AbilityToTool;
use WP\MCP\Registry;

class Server {
	public static function register(): self {
		$instance = new self();

		$server_id = 'default';
		$tools = array_filter(
			[
				SitesTools::class,
				AbilityToTool::make( 'core/posts-search' ),
			]
		);

		// Check if server exists
		if ( ! Registry::instance()->get_server( $server_id ) ) {
			Registry::instance()->create_server(
				$server_id,
				'mcp/v1',
				'WordPress MCP Server',
				'MCP Adapter Server for Core WordPress',
				$tools,
				[
					SiteResources::class,
				],
				[
					SamplePrompts::class,
				]
			);
		}

		return $instance;
	}
}
