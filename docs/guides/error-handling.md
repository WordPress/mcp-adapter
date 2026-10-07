# Error handling

MCP Adapter separates error logging from protocol error creation.

- `McpErrorHandlerInterface` receives diagnostic events.
- `McpErrorFactory` creates revision-neutral JSON-RPC error arrays.
- `McpWireOrchestrator` validates those arrays against the selected revision and applies revision-specific HTTP status policy.

## Error handlers

Implement `McpErrorHandlerInterface` to send diagnostics to your logging system:

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

final class CustomErrorHandler implements McpErrorHandlerInterface {
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[%s] %s %s', strtoupper( $type ), $message, wp_json_encode( $context ) )
		);
	}
}
```

The built-in handlers are:

- `ErrorLogMcpErrorHandler`, which writes structured context to the PHP error log; and
- `NullMcpErrorHandler`, which intentionally discards log events.

## Protocol errors

Factory methods return a complete JSON-RPC error array:

```php
$error = McpErrorFactory::tool_not_found( 123, 'missing-tool' );
```

Common methods include:

```php
McpErrorFactory::parse_error( $id, $details );
McpErrorFactory::invalid_request( $id, $details );
McpErrorFactory::method_not_found( $id, $method );
McpErrorFactory::invalid_params( $id, $details );
McpErrorFactory::internal_error( $id, $details );
McpErrorFactory::missing_parameter( $id, $parameter );
McpErrorFactory::tool_not_found( $id, $tool );
McpErrorFactory::prompt_not_found( $id, $prompt );
McpErrorFactory::resource_not_found( $id, $resource_uri, $revision );
McpErrorFactory::permission_denied( $id, $details );
McpErrorFactory::unauthorized( $id, $details );
McpErrorFactory::unsupported_protocol_version( $id, $requested, $supported );
```

Request IDs may be strings, integers, or `null`. Protocol-facing code must pass the selected revision to `resource_not_found()` because MCP 2025 uses `-32002` and MCP 2026 uses `-32602`. Missing tools and prompts use standard Invalid Params (`-32602`) in both supported revisions.

## Handler boundary

Handlers receive a validated generated request record and `WP\MCP\Core\McpRequestContext`. They return logical result data or an error array. The selected schema constructs the final result and JSON-RPC response records.

Custom HTTP transports should delegate to `HttpRequestHandler`. Other transports should call `McpWireOrchestrator::decode()` and `process()` before serializing the returned record. Do not route unvalidated method and parameter arrays directly.

## Invalid handler results

If a handler returns output that fails final schema validation, the client receives JSON-RPC error `-32603` with the message `Internal error: The server produced an invalid result.` This identifies a server-side response failure. It is separate from invalid client parameters and ordinary tool execution failures represented by `isError: true`.

The configured error handler receives an `Invalid handler result` diagnostic with the request ID, revision, component context, exception message, and schema pointer when available. The pointer may identify a containing structure such as `/content/0`. Request argument values and result payloads are not attached. The observability handler receives one failed `mcp.request` event with `failure_reason: invalid_handler_result`.

For custom servers, passing `null` for either handler discards that handler's output. The Adapter's default server uses `ErrorLogMcpErrorHandler` for diagnostics and a no-op observability handler. See [enabling error logging and request events](observability.md#enabling-error-logging-and-request-events) to configure both. The built-in logging handlers write to the PHP error log; its location depends on the site's PHP and WordPress logging configuration.

## HTTP status

The built-in HTTP path uses the selected revision when mapping protocol errors. For example, Invalid Params remains a JSON-RPC response with HTTP 200 in the 2025 revision and uses HTTP 400 in the 2026 revision. Custom HTTP transports that delegate to `HttpRequestHandler` inherit the same behavior.

## JSON-RPC validation

`JsonRpcRequestDecoder` performs one identity-preserving JSON decode and rejects malformed JSON, batches, excessive depth, integers outside PHP's native range, and non-finite numbers. `McpWireOrchestrator` then checks method availability, revision metadata, transport headers, schema hydration, and response encoding.
