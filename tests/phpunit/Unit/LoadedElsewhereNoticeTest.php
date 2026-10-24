<?php
/**
 * Tests the notice shown when another copy of MCP Adapter is running.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\TestCase;

/**
 * Covers includes/loaded-elsewhere-notice.php.
 */
final class LoadedElsewhereNoticeTest extends TestCase {

	/**
	 * A disposable plugin folder that "bundles" a copy.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Creates the bundling plugin.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->plugin_dir = WP_PLUGIN_DIR . '/mcp-notice-bundler-' . uniqid();
		wp_mkdir_p( $this->plugin_dir . '/vendor/wordpress/mcp-adapter/includes/Core' );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned plugin header.
		file_put_contents( $this->plugin_dir . '/plugin.php', "<?php\n/**\n * Plugin Name: Example Bundler\n */\n" );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned stand-in for a bundled class file.
		file_put_contents( $this->plugin_dir . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php', '<?php' );
		wp_cache_delete( 'plugins', 'plugins' );
	}

	/**
	 * Removes the bundling plugin.
	 */
	public function tear_down(): void {
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Removes only the test-owned folder.
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->plugin_dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->plugin_dir );
		// phpcs:enable
		wp_cache_delete( 'plugins', 'plugins' );
		parent::tear_down();
	}

	/**
	 * The notice names the plugin that carries the running copy, and the version running.
	 */
	public function test_names_the_plugin_that_bundles_the_running_copy(): void {
		$messages = $this->notice_for( $this->plugin_dir . '/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' );

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'Another version of MCP Adapter is already loaded', $messages[0] );
		$this->assertStringContainsString( 'Example Bundler loads its own bundled copy (' . McpAdapter::VERSION . ')', $messages[0] );
	}

	/**
	 * A copy outside the plugin, must-use plugin and theme folders gets the general advice.
	 */
	public function test_unknown_carrier_gets_the_general_advice(): void {
		$messages = $this->notice_for( get_temp_dir() . 'somewhere/McpAdapter.php' );

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'This is usually caused by an outdated plugin bundling its own copy', $messages[0] );
	}

	/**
	 * Registers the notice for a file, runs its deferred init step and returns the logged messages.
	 *
	 * @param string $running_file A file of the running copy.
	 * @return string[]
	 */
	private function notice_for( string $running_file ): array {
		$messages = array();
		$capture  = static function ( $function_name, $message ) use ( &$messages ) {
			if ( McpAdapter::class !== $function_name ) {
				return;
			}
			$messages[] = $message;
		};
		add_action( 'doing_it_wrong_run', $capture, 10, 2 );
		add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		$this->setExpectedIncorrectUsage( McpAdapter::class );

		( require dirname( __DIR__, 3 ) . '/includes/loaded-elsewhere-notice.php' )( $running_file );
		$entries = $GLOBALS['wp_filter']['init']->callbacks[10];
		$notice  = end( $entries )['function'];
		$notice();

		remove_action( 'doing_it_wrong_run', $capture, 10 );
		return $messages;
	}
}
