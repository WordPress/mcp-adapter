<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Plugin;
use WP\MCP\Tests\TestCase;

final class PluginTest extends TestCase {

	public function test_plugin_clone_triggers_doing_it_wrong(): void {
		$plugin = Plugin::instance();

		// Attempt to clone the plugin
		@clone $plugin;

		// Verify _doing_it_wrong was called
		// __FUNCTION__ returns '__clone' not the full class name
		$this->assertNotEmpty( $this->doing_it_wrong_log, 'Expected _doing_it_wrong to be called when cloning plugin. Captured: ' . wp_json_encode( $this->doing_it_wrong_log ) );
		$this->assertDoingItWrongTriggered( '__clone', 'should not be cloned' );
	}

	public function test_plugin_wakeup_triggers_doing_it_wrong(): void {
		$plugin = Plugin::instance();

		// Attempt to unserialize the plugin
		$serialized = serialize( $plugin );
		@unserialize( $serialized );

		// Verify _doing_it_wrong was called
		// __FUNCTION__ returns '__wakeup' not the full class name
		$this->assertNotEmpty( $this->doing_it_wrong_log, 'Expected _doing_it_wrong to be called when unserializing plugin. Captured: ' . wp_json_encode( $this->doing_it_wrong_log ) );
		$this->assertDoingItWrongTriggered( '__wakeup', 'De-serializing' );
	}
}

