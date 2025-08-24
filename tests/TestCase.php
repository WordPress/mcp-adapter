<?php
/**
 * Test base class.
 *
 * @package WP\MCP\Tests
 */

declare(strict_types=1);

namespace WP\MCP\Tests;

use WP\MCP\Tests\Fixtures\DummyAbility;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillsTestCase;

abstract class TestCase extends PolyfillsTestCase {

	/**
	 * Clean up abilities after each test class finishes.
	 */
	public static function tear_down_after_class(): void {
		// Clean up any abilities registered by this test class to avoid
		// duplicate registration notices.
		DummyAbility::unregister_all();
		parent::tear_down_after_class();
	}
}
