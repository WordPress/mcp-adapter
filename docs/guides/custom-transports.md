# Custom transports

Use a custom transport when the built-in HTTP and WP-CLI STDIO transports do
not fit the connection you need. For authentication or authorization changes on
the built-in HTTP endpoint, use [transport permissions](transport-permissions.md)
instead.

Custom transports implement `McpTransportInterface`. REST transports implement
`McpRestTransportInterface`:

```php
interface McpTransportInterface {
	public function __construct( McpTransportContext $context );
	public function register_routes(): void;
}

interface McpRestTransportInterface extends McpTransportInterface {
	public function check_permission( WP_REST_Request $request );
	public function handle_request( WP_REST_Request $request ): WP_REST_Response;
}
```

## Custom REST transport

Delegate MCP processing to `HttpRequestHandler`. It owns the exact 2025 session
lifecycle, the sessionless 2026 lifecycle, Origin checks, revision selection,
header validation, schema hydration, response encoding, and HTTP status.

```php
use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

final class CustomRestTransport implements McpRestTransportInterface {
	private HttpRequestHandler $request_handler;

	public function __construct( McpTransportContext $context ) {
		$this->request_handler = new HttpRequestHandler( $context );
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 16 );
	}

	public function register_routes(): void {
		$server = $this->request_handler->get_transport_context()->mcp_server;

		register_rest_route(
			$server->get_server_route_namespace(),
			$server->get_server_route(),
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission( \WP_REST_Request $request ) {
		unset( $request );

		return current_user_can( 'read' );
	}

	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->request_handler->handle_request( new HttpRequestContext( $request ) );
	}
}
```

Register the transport when creating a server:

```php
add_action(
	'mcp_adapter_init',
	static function ( $adapter ): void {
		$adapter->create_server(
			'custom-server',
			'my-plugin',
			'custom-mcp',
			'Custom MCP Server',
			'MCP over a custom WordPress REST route',
			'1.0.0',
			array( CustomRestTransport::class ),
			null
		);
	}
);
```

## Non-HTTP transports

Use `McpWireOrchestrator` for a message queue, socket, or another transport that
already supplies raw JSON:

```php
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;

$wire    = new McpWireOrchestrator( $transport_context );
$message = $wire->decode( $raw_json );
$outcome = $wire->process(
	$message,
	'QUEUE',
	$transport_metadata,
	$client_params_2025_11_25
);

$response = $outcome['response'];
```

The transport owns authentication, delivery, and connection state. For a 2025
connection, retain the successful initialize parameters and pass them as the
fourth argument on later requests. A 2026 request carries its revision and
client capabilities in `_meta`, so no connection-scoped MCP session is used.

Do not invoke `RequestRouter` with raw arrays. The orchestrator must select and
validate the exact request record before dispatch.

## See also

- [Transport permissions](transport-permissions.md)
- [Error handling](error-handling.md)
- [Architecture overview](../architecture/overview.md)
