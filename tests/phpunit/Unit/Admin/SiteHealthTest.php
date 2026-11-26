<?php
/**
 * Tests for the MCP Adapter section in Site Health.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Admin;

use WP\MCP\Admin\SiteHealth;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Servers\DefaultServerFactory;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

final class SiteHealthTest extends TestCase {

	private const PUBLIC_ABILITY     = 'site-health-test/public';
	private const PRIVATE_ABILITY    = 'site-health-test/private';
	private const MCP_HIDDEN_ABILITY = 'site-health-test/mcp-hidden';

	/**
	 * The original $_SERVER['HTTPS'] value, or null when it was not set.
	 *
	 * @var string|null
	 */
	private ?string $original_https = null;

	public function setUp(): void {
		parent::setUp();
		$this->original_https = isset( $_SERVER['HTTPS'] ) ? (string) $_SERVER['HTTPS'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved only to restore it in tearDown().
		$this->reset_adapter();
	}

	public function tearDown(): void {
		foreach ( array( self::PUBLIC_ABILITY, self::PRIVATE_ABILITY, self::MCP_HIDDEN_ABILITY ) as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				continue;
			}

			wp_unregister_ability( $name );
		}

		if ( null === $this->original_https ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->original_https;
		}

		remove_all_filters( 'mcp_adapter_create_default_server' );
		remove_all_filters( 'wp_is_application_passwords_available' );
		$this->reset_adapter();

		parent::tearDown();
	}

	public function test_section_is_added_through_the_debug_information_filter(): void {
		$info = apply_filters( 'debug_information', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertArrayHasKey( SiteHealth::SECTION, $info );
		$this->assertSame( 'MCP Adapter', $info[ SiteHealth::SECTION ]['label'] );
		$this->assertSame( McpAdapter::VERSION, $info[ SiteHealth::SECTION ]['fields']['version']['debug'] );
		$this->assertSame( 'enabled', $info[ SiteHealth::SECTION ]['fields']['default_server']['debug'] );
	}

	public function test_every_field_has_a_plain_value_and_debug_value(): void {
		$this->register_test_abilities();

		foreach ( SiteHealth::get_section()['fields'] as $key => $field ) {
			$this->assertIsString( $field['label'], $key );
			$this->assertArrayHasKey( 'debug', $field, $key );

			foreach ( array( $field['value'], $field['debug'] ) as $value ) {
				foreach ( (array) $value as $sub_value ) {
					$this->assertIsString( $sub_value, $key );
					$this->assertSame( wp_strip_all_tags( $sub_value ), $sub_value, $key );
				}
			}
		}
	}

	public function test_default_server_is_listed_with_its_endpoint(): void {
		$fields = SiteHealth::get_section()['fields'];

		$this->assertArrayHasKey( 'server_mcp-adapter-default-server', $fields );

		$debug = $fields['server_mcp-adapter-default-server']['debug'];
		$this->assertSame( 'mcp-adapter-default-server', $debug['id'] );
		$this->assertSame( rest_url( 'mcp/mcp-adapter-default-server' ), $debug['endpoint'] );
		$this->assertSame( HttpTransport::class, $debug['transports'] );
		$this->assertSame( '3', $debug['tools'] );
	}

	public function test_endpoint_follows_plain_permalinks(): void {
		add_filter( 'pre_option_permalink_structure', '__return_empty_string' );

		$debug = SiteHealth::get_section()['fields']['server_mcp-adapter-default-server']['debug'];

		remove_filter( 'pre_option_permalink_structure', '__return_empty_string' );

		$this->assertStringContainsString( 'rest_route=', $debug['endpoint'] );
		$this->assertStringContainsString( '/mcp/mcp-adapter-default-server', rawurldecode( $debug['endpoint'] ) );
	}

	public function test_public_ability_is_listed_as_exposed(): void {
		$this->register_test_abilities();

		$exposed = SiteHealth::get_section()['fields']['abilities_exposed']['debug'];

		$this->assertStringContainsString( self::PUBLIC_ABILITY, $exposed );
		$this->assertStringNotContainsString( self::PRIVATE_ABILITY, $exposed );
		$this->assertStringNotContainsString( self::MCP_HIDDEN_ABILITY, $exposed );
		$this->assertStringNotContainsString( 'mcp-adapter/', $exposed );
	}

	public function test_non_public_abilities_are_counted_as_not_exposed_with_a_reason(): void {
		$before = $this->not_exposed_counts();

		$this->register_test_abilities();

		$after = $this->not_exposed_counts();

		$this->assertSame( $before['total'] + 2, $after['total'] );
		$this->assertSame( $before['not public'] + 1, $after['not public'] );
		$this->assertSame( $before['mcp.public false'] + 1, $after['mcp.public false'] );
	}

	public function test_ability_fields_are_omitted_when_the_default_server_was_not_created(): void {
		// An error handler that is not a valid class makes create_server() return a WP_Error.
		$filter = static function ( array $config ): array {
			$config['error_handler'] = 'Not\\A\\Real\\Handler';
			return $config;
		};
		add_filter( 'mcp_adapter_default_server_config', $filter );
		$this->setExpectedIncorrectUsage( 'WP\\MCP\\Servers\\DefaultServerFactory::create' );

		$fields = SiteHealth::get_section()['fields'];

		remove_filter( 'mcp_adapter_default_server_config', $filter );
		$this->assertSame( 'enabled, not created', $fields['default_server']['debug'] );
		$this->assertArrayNotHasKey( 'abilities_exposed', $fields );
		$this->assertArrayNotHasKey( 'abilities_not_exposed', $fields );
	}

	public function test_ability_fields_are_omitted_when_the_default_server_is_disabled(): void {
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		$fields = SiteHealth::get_section()['fields'];

		$this->assertSame( 'disabled (mcp_adapter_create_default_server filter)', $fields['default_server']['debug'] );
		$this->assertArrayNotHasKey( 'server_mcp-adapter-default-server', $fields );
		$this->assertArrayNotHasKey( 'abilities_exposed', $fields );
		$this->assertArrayNotHasKey( 'abilities_not_exposed', $fields );
		$this->assertSame( 'none', $fields['servers']['debug'] );
	}

	public function test_application_passwords_reported_as_available(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$field = SiteHealth::get_section()['fields']['application_passwords'];

		$this->assertSame( 'available', $field['debug'] );
	}

	public function test_application_passwords_reported_as_turned_off_by_a_filter(): void {
		// HTTPS makes them supported, so only the filter can turn them off.
		$_SERVER['HTTPS'] = 'on';
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$field = SiteHealth::get_section()['fields']['application_passwords'];

		$this->assertSame( 'not available (wp_is_application_passwords_available filter)', $field['debug'] );
	}

	public function test_application_passwords_reported_as_unsupported_without_https(): void {
		// wp-env runs as a local environment, where they are always supported, so call the builder directly.
		$method = new \ReflectionMethod( SiteHealth::class, 'get_application_passwords_field' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$field = $method->invoke( null, false, false );

		$this->assertSame( 'not available (no HTTPS, not a local environment)', $field['debug'] );
	}

	public function test_building_the_section_twice_does_not_register_servers_twice(): void {
		SiteHealth::get_section();
		$count = count( McpAdapter::instance()->get_servers() );

		SiteHealth::get_section();

		$this->assertSame( $count, count( McpAdapter::instance()->get_servers() ) );
	}

	/**
	 * Parses the "not exposed" debug value into counts.
	 *
	 * @return array{total: int, "not public": int, "mcp.public false": int}
	 */
	private function not_exposed_counts(): array {
		$debug = SiteHealth::get_section()['fields']['abilities_not_exposed']['debug'];

		$counts = array(
			'total'            => (int) $debug,
			'not public'       => 0,
			'mcp.public false' => 0,
		);

		foreach ( array( 'not public', 'mcp.public false' ) as $reason ) {
			if ( ! preg_match( '/' . preg_quote( $reason, '/' ) . ': (\d+)/', $debug, $matches ) ) {
				continue;
			}

			$counts[ $reason ] = (int) $matches[1];
		}

		return $counts;
	}

	/**
	 * Registers one exposed and two hidden abilities.
	 */
	private function register_test_abilities(): void {
		$args = static function ( array $meta ): array {
			return array(
				'label'               => 'Site Health test',
				'description'         => 'Site Health test ability',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			);
		};

		$this->register_ability_in_hook( self::PUBLIC_ABILITY, $args( array( 'mcp' => array( 'public' => true ) ) ) );
		$this->register_ability_in_hook( self::PRIVATE_ABILITY, $args( array() ) );
		$this->register_ability_in_hook(
			self::MCP_HIDDEN_ABILITY,
			$args(
				array(
					'public' => true,
					'mcp'    => array( 'public' => false ),
				)
			)
		);
	}

	/**
	 * Clears the adapter's servers and initialized flag, so each test builds them fresh.
	 */
	private function reset_adapter(): void {
		// The adapter hooks the factory when it initializes; unhook it so the default server filter applies again.
		remove_action( 'mcp_adapter_init', array( DefaultServerFactory::class, 'create' ) );

		$adapter    = McpAdapter::instance();
		$reflection = new \ReflectionClass( $adapter );

		$servers = $reflection->getProperty( 'servers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$servers->setAccessible( true );
		}
		$servers->setValue( $adapter, array() );

		$initialized = $reflection->getProperty( 'initialized' );
		if ( PHP_VERSION_ID < 80100 ) {
			$initialized->setAccessible( true );
		}
		$initialized->setValue( null, false );

		$created = new \ReflectionProperty( DefaultServerFactory::class, 'created_server_id' );
		if ( PHP_VERSION_ID < 80100 ) {
			$created->setAccessible( true );
		}
		$created->setValue( null, null );
	}
}
