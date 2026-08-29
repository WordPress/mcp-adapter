<?php
/**
 * Architecture guards for the total schema-runtime switch.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Schemas;

/** Prevents DTO paths, aliases, validation toggles, and dependency-ref drift. */
final class DualRevisionArchitectureTest extends TestCase {

	/** Production source contains no removed DTO or compatibility path. */
	public function test_production_has_no_removed_schema_paths(): void {
		$root      = dirname( __DIR__, 3 );
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes' ) );
		$forbidden = array(
			'WP\\McpSchema\\Client',
			'WP\\McpSchema\\Common',
			'WP\\McpSchema\\Server',
			'AbstractDataTransferObject',
			'get_protocol_dto',
			'mcp_adapter_validation_enabled',
			'class_alias',
			'JsonRpcResponseBuilder',
			'validate_jsonrpc_message',
			'extract_params',
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local architecture scan.
			$this->assertIsString( $source );
			foreach ( $forbidden as $symbol ) {
				$this->assertStringNotContainsString( $symbol, $source, $file->getPathname() );
			}
		}
	}

	/** Composer locks the exact reviewable schema handoff. */
	public function test_composer_lock_uses_exact_schema_handoff(): void {
		$root = dirname( __DIR__, 3 );
		$lock = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local lock inspection.
		$this->assertIsArray( $lock );

		$package = null;
		foreach ( $lock['packages'] as $candidate ) {
			if ( 'wordpress/php-mcp-schema' === ( $candidate['name'] ?? null ) ) {
				$package = $candidate;
				break;
			}
		}

		$this->assertIsArray( $package );
		$this->assertSame( '3a8f4aef1fefc9e0d1fb422e59411c78ce32edd3', $package['source']['reference'] );
	}

	/** Every exact profile policy slot is a function, never a parallel data map. */
	public function test_exact_wire_profiles_are_function_only(): void {
		$server       = $this->makeServer();
		$orchestrator = new McpWireOrchestrator( $server->create_transport_context() );
		$property     = new \ReflectionProperty( $orchestrator, 'profiles' );
		$property->setAccessible( true );
		$profiles = $property->getValue( $orchestrator );

		$this->assertIsArray( $profiles );
		$this->assertSame( Schemas::supportedVersions(), array_keys( $profiles ) );
		foreach ( $profiles as $profile ) {
			$this->assertIsArray( $profile );
			$this->assertNotEmpty( $profile );
			foreach ( $profile as $policy ) {
				$this->assertInstanceOf( \Closure::class, $policy );
			}
		}
	}
}
