<?php
namespace WP\MCP;

use WP\MCP\Server;

class Loader {

	public static function init(): void {
		Server::register();
	}
}
