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

		Registry::instance()->create_server(
			'default',
			'mcp/v1',
			'WordPress MCP Server',
			'MCP Adapter Server for Core WordPress',
			[
				SitesTools::class,
				AbilityToTool::make( 'core/posts-search' ),
			],
			[
				SiteResources::class,
			],
			[
				SamplePrompts::class,
			],
			'default'
		);

		return $instance;
	}
}

