<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class WordPressFiltersTest extends TestCase {

	public function test_validation_toggle_filter_is_respected(): void {
		add_filter( 'mcp_adapter_validation_enabled', '__return_false' );

		$server = new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertFalse( $server->is_mcp_validation_enabled() );

		remove_filter( 'mcp_adapter_validation_enabled', '__return_false' );
	}

	/**
	 * A 3-argument callback is the documented signature on McpServer. Domain
	 * factories must pass the extra args (null when no server is in scope) so
	 * ability conversion does not raise ArgumentCountError and drop tools.
	 */
	public function test_validation_filter_three_argument_callback_does_not_break_component_conversion(): void {
		$callback = static function ( $enabled, $server_id, $server ) {
			return false;
		};
		add_filter( 'mcp_adapter_validation_enabled', $callback, 10, 3 );

		try {
			$server = $this->makeServer(
				array( 'test/always-allowed' ),
				array( 'test/resource' ),
				array( 'test/prompt' )
			);

			$this->assertNotEmpty( $server->get_tools() );
			$this->assertNotEmpty( $server->get_resources() );
			$this->assertNotEmpty( $server->get_prompts() );

			$tool = McpTool::fromArray(
				array(
					'name'    => 'filter-arity-tool',
					'handler' => static function () {
						return array( 'ok' => true );
					},
				)
			);
			$this->assertNotWPError( $tool );

			$resource = McpResource::fromArray(
				array(
					'uri'     => 'WordPress://local/filter-arity',
					'handler' => static function () {
						return 'ok';
					},
				)
			);
			$this->assertNotWPError( $resource );

			$prompt = McpPrompt::fromArray(
				array(
					'name'    => 'filter-arity-prompt',
					'handler' => static function () {
						return array();
					},
				)
			);
			$this->assertNotWPError( $prompt );

			$builder = new class() extends McpPromptBuilder {
				protected function configure(): void {
					$this->name        = 'filter-arity-builder-prompt';
					$this->description = 'Exercises the validation filter from fromBuilder';
				}

				public function handle( array $arguments ): array {
					return array();
				}
			};

			$built = McpPrompt::fromBuilder( $builder );
			$this->assertNotWPError( $built );
		} finally {
			remove_filter( 'mcp_adapter_validation_enabled', $callback );
		}
	}
}
