<?php
/**
 * Tests for ServerConfig DTO.
 *
 * @package WP\MCP\Tests\Unit\Core
 * @since   n.e.x.t
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\Contracts\ArrayTransformableInterface;
use WP\MCP\Core\ServerConfig;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

/**
 * Tests for ServerConfig DTO.
 *
 * @since n.e.x.t
 */
final class ServerConfigTest extends TestCase {

	/**
	 * Create a minimal valid configuration array.
	 *
	 * @return array<string, mixed>
	 */
	private function create_valid_config(): array {
		return array(
			'server_id'              => 'test-server',
			'server_route_namespace' => 'mcp/v1',
			'server_route'           => 'test-route',
			'server_name'            => 'Test Server',
			'server_description'     => 'A test server for unit testing',
			'server_version'         => '1.0.0',
			'mcp_transports'         => array( HttpTransport::class ),
		);
	}

	// =========================================================================
	// Interface implementation tests
	// =========================================================================

	public function test_serverConfig_implementsArrayTransformableInterface(): void {
		$config = ServerConfig::from_array( $this->create_valid_config() );
		$this->assertInstanceOf( ArrayTransformableInterface::class, $config );
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	public function test_constructor_withAllParameters_createsInstance(): void {
		$permission_callback = static function () {
			return true;
		};

		$config = new ServerConfig(
			'srv-id',
			'ns/v1',
			'route',
			'Server Name',
			'Server Description',
			'2.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array( 'tool1', 'tool2' ),
			array( 'resource1' ),
			array( 'prompt1' ),
			$permission_callback
		);

		$this->assertSame( 'srv-id', $config->get_server_id() );
		$this->assertSame( 'ns/v1', $config->get_server_route_namespace() );
		$this->assertSame( 'route', $config->get_server_route() );
		$this->assertSame( 'Server Name', $config->get_server_name() );
		$this->assertSame( 'Server Description', $config->get_server_description() );
		$this->assertSame( '2.0.0', $config->get_server_version() );
		$this->assertSame( array( HttpTransport::class ), $config->get_mcp_transports() );
		$this->assertSame( DummyErrorHandler::class, $config->get_error_handler() );
		$this->assertSame( DummyObservabilityHandler::class, $config->get_observability_handler() );
		$this->assertSame( array( 'tool1', 'tool2' ), $config->get_tools() );
		$this->assertSame( array( 'resource1' ), $config->get_resources() );
		$this->assertSame( array( 'prompt1' ), $config->get_prompts() );
		$this->assertSame( $permission_callback, $config->get_transport_permission_callback() );
	}

	public function test_constructor_withOptionalParametersOmitted_usesDefaults(): void {
		$config = new ServerConfig(
			'srv-id',
			'ns/v1',
			'route',
			'Server Name',
			'Server Description',
			'1.0.0',
			array( HttpTransport::class )
		);

		$this->assertNull( $config->get_error_handler() );
		$this->assertNull( $config->get_observability_handler() );
		$this->assertSame( array(), $config->get_tools() );
		$this->assertSame( array(), $config->get_resources() );
		$this->assertSame( array(), $config->get_prompts() );
		$this->assertNull( $config->get_transport_permission_callback() );
	}

	// =========================================================================
	// from_array tests
	// =========================================================================

	public function test_from_array_withValidData_createsInstance(): void {
		$data   = $this->create_valid_config();
		$config = ServerConfig::from_array( $data );

		$this->assertSame( 'test-server', $config->get_server_id() );
		$this->assertSame( 'mcp/v1', $config->get_server_route_namespace() );
		$this->assertSame( 'test-route', $config->get_server_route() );
		$this->assertSame( 'Test Server', $config->get_server_name() );
		$this->assertSame( 'A test server for unit testing', $config->get_server_description() );
		$this->assertSame( '1.0.0', $config->get_server_version() );
		$this->assertSame( array( HttpTransport::class ), $config->get_mcp_transports() );
	}

	public function test_from_array_withOptionalFields_setsValues(): void {
		$data                          = $this->create_valid_config();
		$data['error_handler']         = DummyErrorHandler::class;
		$data['observability_handler'] = DummyObservabilityHandler::class;
		$data['tools']                 = array( 'tool-a', 'tool-b' );
		$data['resources']             = array( 'resource-x' );
		$data['prompts']               = array( 'prompt-y' );

		$config = ServerConfig::from_array( $data );

		$this->assertSame( DummyErrorHandler::class, $config->get_error_handler() );
		$this->assertSame( DummyObservabilityHandler::class, $config->get_observability_handler() );
		$this->assertSame( array( 'tool-a', 'tool-b' ), $config->get_tools() );
		$this->assertSame( array( 'resource-x' ), $config->get_resources() );
		$this->assertSame( array( 'prompt-y' ), $config->get_prompts() );
	}

	public function test_from_array_withCallable_setsCallback(): void {
		$callback = static function () {
			return true;
		};

		$data                                   = $this->create_valid_config();
		$data['transport_permission_callback']  = $callback;

		$config = ServerConfig::from_array( $data );

		$this->assertSame( $callback, $config->get_transport_permission_callback() );
	}

	public function test_from_array_withMissingRequiredField_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing required field(s): server_id' );

		$data = $this->create_valid_config();
		unset( $data['server_id'] );

		ServerConfig::from_array( $data );
	}

	public function test_from_array_withMultipleMissingFields_reportsAll(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'server_id' );
		$this->expectExceptionMessage( 'server_name' );

		ServerConfig::from_array( array() );
	}

	public function test_from_array_withInvalidTransportClass_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not exist' );

		$data                   = $this->create_valid_config();
		$data['mcp_transports'] = array( 'NonExistentTransportClass' );

		ServerConfig::from_array( $data );
	}

	public function test_from_array_withNonImplementingTransport_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must implement' );

		$data                   = $this->create_valid_config();
		$data['mcp_transports'] = array( \stdClass::class );

		ServerConfig::from_array( $data );
	}

	public function test_from_array_withInvalidErrorHandler_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must implement' );

		$data                  = $this->create_valid_config();
		$data['error_handler'] = \stdClass::class;

		ServerConfig::from_array( $data );
	}

	public function test_from_array_withInvalidObservabilityHandler_throwsException(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must implement' );

		$data                          = $this->create_valid_config();
		$data['observability_handler'] = \stdClass::class;

		ServerConfig::from_array( $data );
	}

	// =========================================================================
	// to_array tests
	// =========================================================================

	public function test_to_array_withAllFields_includesAll(): void {
		$config = new ServerConfig(
			'srv-id',
			'ns/v1',
			'route',
			'Server Name',
			'Server Description',
			'2.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array( 'tool1' ),
			array( 'resource1' ),
			array( 'prompt1' )
		);

		$array = $config->to_array();

		$this->assertSame( 'srv-id', $array['server_id'] );
		$this->assertSame( 'ns/v1', $array['server_route_namespace'] );
		$this->assertSame( 'route', $array['server_route'] );
		$this->assertSame( 'Server Name', $array['server_name'] );
		$this->assertSame( 'Server Description', $array['server_description'] );
		$this->assertSame( '2.0.0', $array['server_version'] );
		$this->assertSame( array( HttpTransport::class ), $array['mcp_transports'] );
		$this->assertSame( DummyErrorHandler::class, $array['error_handler'] );
		$this->assertSame( DummyObservabilityHandler::class, $array['observability_handler'] );
		$this->assertSame( array( 'tool1' ), $array['tools'] );
		$this->assertSame( array( 'resource1' ), $array['resources'] );
		$this->assertSame( array( 'prompt1' ), $array['prompts'] );
	}

	public function test_to_array_withNullErrorHandler_omitsKey(): void {
		$config = new ServerConfig(
			'srv-id',
			'ns/v1',
			'route',
			'Server Name',
			'Description',
			'1.0.0',
			array( HttpTransport::class ),
			null,
			null
		);

		$array = $config->to_array();

		$this->assertArrayNotHasKey( 'error_handler', $array );
		$this->assertArrayNotHasKey( 'observability_handler', $array );
	}

	public function test_to_array_includesEmptyArrays(): void {
		$config = new ServerConfig(
			'srv-id',
			'ns/v1',
			'route',
			'Server Name',
			'Description',
			'1.0.0',
			array( HttpTransport::class )
		);

		$array = $config->to_array();

		$this->assertArrayHasKey( 'tools', $array );
		$this->assertArrayHasKey( 'resources', $array );
		$this->assertArrayHasKey( 'prompts', $array );
		$this->assertSame( array(), $array['tools'] );
		$this->assertSame( array(), $array['resources'] );
		$this->assertSame( array(), $array['prompts'] );
	}

	public function test_to_array_omitsCallable(): void {
		$config = new ServerConfig(
			'srv-id',
			'ns/v1',
			'route',
			'Server Name',
			'Description',
			'1.0.0',
			array( HttpTransport::class ),
			null,
			null,
			array(),
			array(),
			array(),
			static function () {
				return true;
			}
		);

		$array = $config->to_array();

		$this->assertArrayNotHasKey( 'transport_permission_callback', $array );
	}

	// =========================================================================
	// Round-trip tests
	// =========================================================================

	public function test_roundTrip_fromArrayToArrayFromArray_preservesData(): void {
		$original_data = array(
			'server_id'              => 'round-trip-server',
			'server_route_namespace' => 'test/v2',
			'server_route'           => 'rt-route',
			'server_name'            => 'Round Trip Server',
			'server_description'     => 'Testing round-trip serialization',
			'server_version'         => '3.0.0',
			'mcp_transports'         => array( HttpTransport::class ),
			'error_handler'          => DummyErrorHandler::class,
			'observability_handler'  => DummyObservabilityHandler::class,
			'tools'                  => array( 'tool-a', 'tool-b' ),
			'resources'              => array( 'resource-x' ),
			'prompts'                => array( 'prompt-y', 'prompt-z' ),
		);

		$config     = ServerConfig::from_array( $original_data );
		$array      = $config->to_array();
		$config2    = ServerConfig::from_array( $array );
		$final_data = $config2->to_array();

		// Compare values regardless of key order (to_array adds optional fields at end)
		ksort( $original_data );
		ksort( $final_data );
		$this->assertSame( $original_data, $final_data );
	}

	// =========================================================================
	// Getter tests
	// =========================================================================

	public function test_getters_returnCorrectValues(): void {
		$permission_callback = static function () {
			return false;
		};

		$config = new ServerConfig(
			'getter-test',
			'getter-ns',
			'getter-route',
			'Getter Server',
			'Getter Description',
			'4.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array( 'g-tool' ),
			array( 'g-resource' ),
			array( 'g-prompt' ),
			$permission_callback
		);

		// Test each getter
		$this->assertSame( 'getter-test', $config->get_server_id() );
		$this->assertSame( 'getter-ns', $config->get_server_route_namespace() );
		$this->assertSame( 'getter-route', $config->get_server_route() );
		$this->assertSame( 'Getter Server', $config->get_server_name() );
		$this->assertSame( 'Getter Description', $config->get_server_description() );
		$this->assertSame( '4.0.0', $config->get_server_version() );
		$this->assertSame( array( HttpTransport::class ), $config->get_mcp_transports() );
		$this->assertSame( DummyErrorHandler::class, $config->get_error_handler() );
		$this->assertSame( DummyObservabilityHandler::class, $config->get_observability_handler() );
		$this->assertSame( array( 'g-tool' ), $config->get_tools() );
		$this->assertSame( array( 'g-resource' ), $config->get_resources() );
		$this->assertSame( array( 'g-prompt' ), $config->get_prompts() );
		$this->assertSame( $permission_callback, $config->get_transport_permission_callback() );
	}
}
