<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\TestCase;

final class McpAdapterOnInitTest extends TestCase {

	private McpAdapter $adapter;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = McpAdapter::instance();

		// Isolate the shared action so do_action() only triggers callbacks
		// registered within each test, regardless of suite execution order.
		remove_all_actions( 'mcp_adapter_init' );
	}

	public function tear_down(): void {
		remove_all_actions( 'mcp_adapter_init' );
		parent::tear_down();
	}

	public function test_on_init_runs_callback_when_our_adapter_dispatches(): void {
		$received = null;
		$this->adapter->on_init(
			static function ( $adapter ) use ( &$received ) {
				$received = $adapter;
			}
		);

		do_action( 'mcp_adapter_init', $this->adapter );

		$this->assertSame( $this->adapter, $received );
	}

	public function test_on_init_skips_callback_when_a_foreign_adapter_dispatches(): void {
		$called = false;
		$this->adapter->on_init(
			static function () use ( &$called ) {
				$called = true;
			}
		);

		// Simulate another vendored copy's adapter dispatching the shared action.
		$foreign = ( new \ReflectionClass( McpAdapter::class ) )->newInstanceWithoutConstructor();
		do_action( 'mcp_adapter_init', $foreign );

		$this->assertFalse(
			$called,
			'on_init() callback must not fire when a foreign adapter instance dispatched the shared action.'
		);
	}
}
