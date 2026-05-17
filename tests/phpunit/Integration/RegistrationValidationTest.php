<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;

final class RegistrationValidationTest extends TestCase {

	public function test_invalid_tool_registration_is_logged_and_skipped(): void {
		// Reset logs
		DummyErrorHandler::reset();

		// Enabled validation
		add_filter( 'mcp_adapter_validation_enabled', '__return_true' );

		// Force an invalid name that sanitizer won't fix (because it's post-sanitization filter)
		$invalid_name_callback = fn() => 'invalid name';
		add_filter( 'mcp_adapter_tool_name', $invalid_name_callback );

		$ability_name = 'test/invalid-mcp-tool';
		$this->register_ability_in_hook(
			$ability_name,
			array(
				'label'               => 'Invalid Tool',
				'description'         => 'A tool with invalid MCP name',
				'category'            => 'mcp-adapter',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => fn() => array( 'ok' => true ),
				'permission_callback' => fn() => true,
			)
		);

		// Verify ability is actually registered in WordPress
		$ability = \wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, 'Ability should be registered in WP' );

		// Creating a server that includes this tool.
		$server = $this->makeServer( array( $ability_name ) );

		// The tool should NOT be in the registry because name resolution failed.
		$tools = $server->get_tools();
		$this->assertArrayNotHasKey( 'invalid name', $tools );
		$this->assertArrayNotHasKey( $ability_name, $tools );

		// Verify error was logged
		$found_error  = false;
		$log_messages = array();
		foreach ( DummyErrorHandler::$logs as $log ) {
			$log_messages[] = $log['message'];
			if ( strpos( $log['message'], 'Filter returned invalid MCP tool name' ) !== false ) {
				$found_error = true;
				break;
			}
		}
		$this->assertTrue( $found_error, 'Validation error should be logged.' );

		remove_filter( 'mcp_adapter_tool_name', $invalid_name_callback );
		remove_filter( 'mcp_adapter_validation_enabled', '__return_true' );
	}

	public function test_validation_disabled_allows_invalid_dto_creation_if_possible(): void {
		// Disabled validation (default)
		add_filter( 'mcp_adapter_validation_enabled', '__return_false' );

		$ability_name = 'test/invalid-but-allowed-tool';
		$this->register_ability_in_hook(
			$ability_name,
			array(
				'label'               => 'Invalid But Allowed',
				'description'         => 'A tool for testing disabled validation',
				'category'            => 'mcp-adapter',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => fn() => array( 'ok' => true ),
				'permission_callback' => fn() => true,
			)
		);

		$ability = \wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, 'Ability should be registered in WP' );

		$server = $this->makeServer( array( $ability_name ) );

		// Even with validation disabled, our current implementation SANITIZES names
		// so it's hard to get a truly "invalid" DTO through without it being fixed or rejected by Tool::fromArray.
		// But we can verify that the tool IS registered (after sanitization).
		$tools = $server->get_tools();
		$this->assertNotEmpty( $tools, 'Tools should not be empty' );

		remove_filter( 'mcp_adapter_validation_enabled', '__return_false' );
	}
}
