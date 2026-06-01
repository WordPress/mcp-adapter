<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Plugin;
use WP\MCP\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_plugin_clone_triggers_doing_it_wrong(): void {
		$plugin = Plugin::instance();

		// Attempt to clone the plugin
		$this->setExpectedIncorrectUsage( '__clone' );
		clone $plugin;
	}

	public function test_plugin_wakeup_triggers_doing_it_wrong(): void {
		$plugin = Plugin::instance();

		// Attempt to unserialize the plugin
		$serialized = serialize( $plugin );
		$this->setExpectedIncorrectUsage( '__wakeup' );
		unserialize( $serialized );
	}
}
