<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\TestCase;

/**
 * Regression coverage for the timing of the default server's ability hooks.
 *
 * `wp_abilities_api_categories_init` and `wp_abilities_api_init` fire during
 * the WordPress `init` action. Wiring their handlers inside the deferred
 * `rest_api_init` initialization missed both, leaving the default abilities
 * unregistered (see issue #117). The hooks are now wired during `instance()`
 * so they catch the abilities-api init pass.
 */
final class McpAdapterDefaultAbilityHooksTest extends TestCase {

	public function test_default_ability_hooks_are_registered_at_instance_time(): void {
		$adapter = McpAdapter::instance();

		$this->assertNotFalse(
			has_action(
				'wp_abilities_api_categories_init',
				array( $adapter, 'register_default_category' )
			),
			'register_default_category should be hooked to wp_abilities_api_categories_init at instance() time'
		);

		$this->assertNotFalse(
			has_action(
				'wp_abilities_api_init',
				array( $adapter, 'register_default_abilities' )
			),
			'register_default_abilities should be hooked to wp_abilities_api_init at instance() time'
		);
	}
}
