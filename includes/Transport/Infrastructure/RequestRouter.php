<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Record;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\DiscoverRequest;
use WP\McpSchema\Record\GetPromptRequest;
use WP\McpSchema\Record\InitializeRequest;
use WP\McpSchema\Record\ListPromptsRequest;
use WP\McpSchema\Record\ListResourceTemplatesRequest;
use WP\McpSchema\Record\ListResourcesRequest;
use WP\McpSchema\Record\ListToolsRequest;
use WP\McpSchema\Record\PingRequest;
use WP\McpSchema\Record\ReadResourceRequest;

/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * Extracted from AbstractMcpTransport to be reusable across
 * all transport implementations via dependency injection.
 */
class RequestRouter {

	/**
	 * The transport context.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	private McpTransportContext $context;

	/**
	 * Captures the selected request's tags while its caller completes projection.
	 *
	 * @since 0.7.0
	 * @var (callable(array<string, mixed>): void)|null
	 */
	private $capture_observation = null;

	/**
	 * Request whose base-router event belongs to the active completion scope.
	 *
	 * @since 0.7.0
	 * @var \WP\McpSchema\Record|null
	 */
	private ?Record $capture_request = null;

	/**
	 * Include response projection in the request's single completion event.
	 *
	 * @internal
	 * @since 0.7.0
	 * @param \WP\McpSchema\Record $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @param string $transport_name Transport name.
	 * @param callable(\WP\McpSchema\Record|array<string, mixed>): \WP\McpSchema\Record $complete Response projector.
	 * @return \WP\McpSchema\Record|array<string, mixed> Final response or contained routing error.
	 */
	public function route_request_with_completion( Record $request, McpRequestContext $request_context, string $transport_name, callable $complete ) {
		$start_time                = microtime( true );
		$tags                      = $this->request_observability_tags( $request, $request_context, $transport_name );
		$previous                  = $this->capture_observation;
		$previous_request          = $this->capture_request;
		$this->capture_request     = $request;
		$this->capture_observation = static function ( array $observed_tags ) use ( &$tags ): void {
			$tags = $observed_tags;
		};
		$projecting                = false;
		$projection_failure        = null;
		try {
			// Discovery has never passed through the overridable logical router.
			$result     = $request instanceof DiscoverRequest
				? $this->create_discover_data()
				: $this->route_request( $request, $request_context, $transport_name );
			$projecting = true;
			$result     = $complete( $result );
		} catch ( \Throwable $exception ) {
			if ( $projecting ) {
				$projection_failure = $exception;
			} else {
				$tags['error_type']     = get_class( $exception );
				$tags['error_category'] = $this->categorize_error( $exception );
			}
			$result = McpErrorFactory::internal_error( $request->get( 'id' ), $projecting ? 'The server produced an invalid result.' : 'Handler error occurred' );
		} finally {
			$this->capture_observation = $previous;
			$this->capture_request     = $previous_request;
		}

		$this->record_request_completion( $tags, $result, $start_time, $projection_failure );
		return $result;
	}

	/**
	 * Initialize the request router.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $context The transport context.
	 */
	public function __construct(
		McpTransportContext $context
	) {
		$this->context = $context;
	}

	/**
	 * Route a request to the appropriate handler.
	 *
	 * @param \WP\McpSchema\Record $request Validated exact request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @param string $transport_name Transport name for observability.
	 * @return \WP\McpSchema\Record|array<string, mixed>
	 */
	public function route_request( Record $request, McpRequestContext $request_context, string $transport_name = 'unknown' ) {
		$capture = $this->capture_request === $request ? $this->capture_observation : null;
		if ( null !== $capture ) {
			$this->capture_observation = null;
			$this->capture_request     = null;
		}
		$method_value = $request->get( 'method' );
		$request_id   = $request->get( 'id' );
		if ( ! is_string( $method_value ) ) {
			return McpErrorFactory::invalid_request( $request_id, 'Validated request has no method.' );
		}
		$start_time = microtime( true );
		$tags       = $this->request_observability_tags( $request, $request_context, $transport_name );
		try {
			$result = $this->dispatch( $method_value, $request, $request_context, $request_id );
		} catch ( \Throwable $exception ) {
			$tags['error_type']     = get_class( $exception );
			$tags['error_category'] = $this->categorize_error( $exception );
			$result                 = McpErrorFactory::internal_error( $request_id, 'Handler error occurred' );
		}

		if ( null !== $capture ) {
			$capture( $tags );
		} else {
			$this->record_request_completion( $tags, $result, $start_time );
		}
		return $result;
	}

	/**
	 * Collect request and component identity without argument values.
	 *
	 * @since 0.7.0
	 * @param \WP\McpSchema\Record $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @param string $transport_name Transport name.
	 * @return array<string, mixed> Correlation tags.
	 */
	private function request_observability_tags( Record $request, McpRequestContext $request_context, string $transport_name ): array {
		$method         = $request->get( 'method' );
		$params         = $this->observability_params( $request );
		$transport_meta = $request_context->transport_metadata();
		return array_merge(
			array(
				'method'     => $method,
				'transport'  => $transport_name,
				'server_id'  => $this->context->mcp_server->get_server_id(),
				'params'     => $this->sanitize_params_for_logging( $params ),
				'request_id' => $request->get( 'id' ),
				'session_id' => $transport_meta['session_id'] ?? null,
				'revision'   => $request_context->revision(),
			),
			is_string( $method ) ? $this->resolve_component_observability_context( $method, $params ) : array()
		);
	}

	/**
	 * Record one final outcome and optional projection diagnostic.
	 *
	 * @since 0.7.0
	 * @param array<string, mixed> $tags Correlated request tags.
	 * @param \WP\McpSchema\Record|array<string, mixed> $result Result or response.
	 * @param float $start_time Request start time in seconds.
	 * @param \Throwable|null $projection_failure Final projection failure.
	 */
	private function record_request_completion( array $tags, $result, float $start_time, ?\Throwable $projection_failure = null ): void {
		$duration = ( microtime( true ) - $start_time ) * 1000;
		$tags     = array_merge( $tags, $this->result_observability_tags( $result ) );
		if ( null !== $projection_failure ) {
			$tags['failure_reason'] = FailureReason::INVALID_HANDLER_RESULT;
			$tags['error_type']     = get_class( $projection_failure );
			$tags['error_category'] = 'validation';
			$this->log_projection_failure( $projection_failure, $tags );
		}
		try {
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );
		} catch ( \Throwable $exception ) {
			// A telemetry failure must not replace the request's response.
			return;
		}
	}

	/**
	 * Derive status from either a direct handler result or a projected response.
	 *
	 * @since 0.7.0
	 * @param \WP\McpSchema\Record|array<string, mixed> $result Result or response.
	 * @return array<string, mixed> Outcome tags.
	 */
	private function result_observability_tags( $result ): array {
		$error = $this->record_field( $result, 'error' );
		if ( $error instanceof Record || $error instanceof \stdClass || is_array( $error ) ) {
			return array(
				'status'         => 'error',
				'error_code'     => $this->record_field( $error, 'code' ) ?? McpErrorFactory::INTERNAL_ERROR,
				'failure_reason' => $this->record_field( $error, 'message' ) ?? 'Unknown error',
			);
		}

		if ( $result instanceof Record && $result->has( 'result' ) ) {
			$result = $result->get( 'result' );
		}
		$tags = array( 'status' => 'success' );
		if ( ( $result instanceof Record || $result instanceof \stdClass || is_array( $result ) ) && true === $this->record_field( $result, 'isError' ) ) {
			$tags['status'] = 'error';
			$content        = $this->record_field( $result, 'content' );
			$first          = is_array( $content ) ? ( $content[0] ?? null ) : null;
			if ( $first instanceof Record || $first instanceof \stdClass || is_array( $first ) ) {
				$text = $this->record_field( $first, 'text' );
				if ( is_string( $text ) ) {
					$tags['failure_reason'] = $text;
				}
			}
		}

		return $tags;
	}

	/**
	 * Send projection diagnostics to the configured logger without result payloads.
	 *
	 * @since 0.7.0
	 * @param \Throwable $exception Projection failure.
	 * @param array<string, mixed> $tags Correlated request tags.
	 */
	private function log_projection_failure( \Throwable $exception, array $tags ): void {
		$tags['exception_message'] = $exception->getMessage();
		if ( $exception instanceof \WP\McpSchema\Exception\ValidationException ) {
			$tags['schema_pointer'] = $exception->getPointer();
		}
		try {
			$this->context->error_handler->log( 'Invalid handler result', $tags );
		} catch ( \Throwable $logging_error ) {
			// Preserve the original failure when an integration's logger throws.
			return;
		}
	}

	/**
	 * Dispatch one validated concrete request to its handler.
	 *
	 * @param string $method Validated method name.
	 * @param \WP\McpSchema\Record $request Concrete validated request record.
	 * @param \WP\MCP\Core\McpRequestContext $context Selected request context.
	 * @param string|int $request_id Validated JSON-RPC request ID.
	 *
	 * @return \WP\McpSchema\Record|array<string, mixed>
	 */
	private function dispatch( string $method, Record $request, McpRequestContext $context, $request_id ) {
		switch ( $method ) {
			case 'initialize':
				return $request instanceof InitializeRequest
					? $this->context->initialize_handler->handle( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid initialize request record.' );
			case 'ping':
				return $request instanceof PingRequest
					? $this->context->system_handler->ping( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid ping request record.' );
			case 'tools/list':
				return $request instanceof ListToolsRequest
					? $this->context->tools_handler->list_tools( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid tools/list request record.' );
			case 'tools/call':
				return $request instanceof CallToolRequest
					? $this->context->tools_handler->call_tool( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid tools/call request record.' );
			case 'resources/list':
				return $request instanceof ListResourcesRequest
					? $this->context->resources_handler->list_resources( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid resources/list request record.' );
			case 'resources/templates/list':
				return $request instanceof ListResourceTemplatesRequest
					? $this->context->resources_handler->list_resource_templates( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid resources/templates/list request record.' );
			case 'resources/read':
				return $request instanceof ReadResourceRequest
					? $this->context->resources_handler->read_resource( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid resources/read request record.' );
			case 'prompts/list':
				return $request instanceof ListPromptsRequest
					? $this->context->prompts_handler->list_prompts( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid prompts/list request record.' );
			case 'prompts/get':
				return $request instanceof GetPromptRequest
					? $this->context->prompts_handler->get_prompt( $request, $context )
					: McpErrorFactory::invalid_request( $request_id, 'Invalid prompts/get request record.' );
			default:
				return $this->create_method_not_found_error( $method, $request_id );
		}
	}

	/**
	 * Build logical discovery data from server configuration.
	 *
	 * @return array<string, mixed> Supported revisions, capabilities, and server instructions.
	 */
	private function create_discover_data(): array {
		return array(
			'supportedVersions' => McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS,
			'capabilities'      => array(
				'prompts'   => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false ),
				'tools'     => array( 'listChanged' => false ),
			),
			'instructions'      => $this->context->mcp_server->get_server_description(),
		);
	}

	/**
	 * Collect limited request fields and argument names for observability.
	 *
	 * @param \WP\McpSchema\Record $request Validated request record.
	 *
	 * @return array<string, mixed> Selected fields with argument values replaced by null.
	 */
	private function observability_params( Record $request ): array {
		$params = $request->get( 'params' );
		if ( ! $params instanceof Record && ! $params instanceof \stdClass ) {
			return array();
		}

		$result = array();
		foreach ( array( 'name', 'protocolVersion', 'uri' ) as $field ) {
			$value = $this->record_field( $params, $field );
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$result[ $field ] = $value;
		}

		$client_info = $this->record_field( $params, 'clientInfo' );
		$client_name = $client_info instanceof Record || $client_info instanceof \stdClass
			? $this->record_field( $client_info, 'name' )
			: null;
		if ( is_string( $client_name ) ) {
			$result['clientInfo'] = array( 'name' => $client_name );
		}

		$arguments = $this->record_field( $params, 'arguments' );
		if ( $arguments instanceof \stdClass ) {
			$arguments = get_object_vars( $arguments );
		}
		if ( is_array( $arguments ) ) {
			$result['arguments'] = array_fill_keys( array_keys( $arguments ), null );
		}

		return $result;
	}

	/**
	 * Read one field from a generated record or JSON object.
	 *
	 * @param \WP\McpSchema\Record|\stdClass|array<string, mixed> $record Record-like value.
	 * @param string $field Field name to read.
	 *
	 * @return mixed
	 */
	private function record_field( $record, string $field ) {
		if ( is_array( $record ) ) {
			return $record[ $field ] ?? null;
		}
		if ( $record instanceof Record ) {
			return $record->has( $field ) ? $record->get( $field ) : null;
		}

		return property_exists( $record, $field ) ? $record->{$field} : null;
	}

	/**
	 * Resolve per-component observability tags for a request.
	 *
	 * @param string $method MCP method name.
	 * @param array $params Request parameters (root or nested under `params`).
	 *
	 * @return array<string, mixed>
	 */
	private function resolve_component_observability_context( string $method, array $params ): array {
		switch ( $method ) {
			case 'tools/call':
				$tool_name = $params['name'] ?? null;
				$tool_name = is_string( $tool_name ) ? trim( $tool_name ) : null;

				if ( null === $tool_name || '' === $tool_name ) {
					return array();
				}

				$mcp_tool = $this->context->mcp_server->get_mcp_tool( $tool_name );
				if ( $mcp_tool ) {
					return $mcp_tool->get_observability_context();
				}

				return array(
					'component_type' => 'tool',
					'tool_name'      => $tool_name,
				);

			case 'prompts/get':
				$prompt_name = $params['name'] ?? null;
				$prompt_name = is_string( $prompt_name ) ? trim( $prompt_name ) : null;

				if ( null === $prompt_name || '' === $prompt_name ) {
					return array();
				}

				$mcp_prompt = $this->context->mcp_server->get_mcp_prompt( $prompt_name );
				if ( $mcp_prompt ) {
					return $mcp_prompt->get_observability_context();
				}

				return array(
					'component_type' => 'prompt',
					'prompt_name'    => $prompt_name,
				);

			case 'resources/read':
				$resource_uri = $params['uri'] ?? null;
				$resource_uri = is_string( $resource_uri ) ? trim( $resource_uri ) : null;

				if ( null === $resource_uri || '' === $resource_uri ) {
					return array();
				}

				$mcp_resource = $this->context->mcp_server->get_mcp_resource( $resource_uri );
				if ( $mcp_resource ) {
					return $mcp_resource->get_observability_context();
				}

				return array(
					'component_type' => 'resource',
					'resource_uri'   => $resource_uri,
				);
		}

		return array();
	}

	/**
	 * Sanitize request params for logging to remove sensitive data and limit size.
	 *
	 * @param array $params The request parameters to sanitize.
	 *
	 * @return array Sanitized parameters safe for logging.
	 */
	private function sanitize_params_for_logging( array $params ): array {
		// Return early for empty parameters.
		if ( empty( $params ) ) {
			return array();
		}

		$sanitized = array();

		// Extract only safe, useful fields for observability
		$safe_fields = array( 'name', 'protocolVersion', 'uri' );

		foreach ( $safe_fields as $field ) {
			if ( ! isset( $params[ $field ] ) || ! is_scalar( $params[ $field ] ) ) {
				continue;
			}

			$sanitized[ $field ] = $params[ $field ];
		}

		// Add clientInfo name if available (useful for debugging)
		if ( isset( $params['clientInfo']['name'] ) ) {
			$sanitized['client_name'] = $params['clientInfo']['name'];
		}

		// Add arguments count for tool calls (but not the actual arguments to avoid logging sensitive data).
		// Also filter out sensitive-looking keys to avoid leaking secret names.
		if ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) {
			$sanitized['arguments_count'] = count( $params['arguments'] );

			// Filter argument keys to exclude sensitive-looking ones.
			$safe_keys = array();
			foreach ( array_keys( $params['arguments'] ) as $arg_key ) {
				// @todo Replace this with a less-coupled way to access `McpObservabilityHelperTrait:is_sensitive_key()`.
				if ( ErrorLogMcpObservabilityHandler::is_sensitive_key( (string) $arg_key ) ) {
					$safe_keys[] = '[REDACTED]';
				} else {
					$safe_keys[] = $arg_key;
				}
			}
			$sanitized['arguments_keys'] = $safe_keys;
		}

		return $sanitized;
	}

	/**
	 * Create a method not found error with generic format.
	 *
	 * @param string $method The method that was not found.
	 * @param mixed $request_id The request ID.
	 *
	 * @return array<string, mixed>
	 */
	private function create_method_not_found_error( string $method, $request_id ): array {
		return McpErrorFactory::method_not_found( $request_id, $method );
	}

	/**
	 * Categorize an exception into a general error category.
	 *
	 * @param \Throwable $exception The exception to categorize.
	 *
	 * @return string
	 */
	private function categorize_error( \Throwable $exception ): string {
		$error_categories = array(
			\ArgumentCountError::class       => 'arguments',
			\TypeError::class                => 'type',
			\InvalidArgumentException::class => 'validation',
			\LogicException::class           => 'logic',
			\RuntimeException::class         => 'execution',
			\Error::class                    => 'system',
		);

		foreach ( $error_categories as $class => $category ) {
			if ( $exception instanceof $class ) {
				return $category;
			}
		}

		return 'unknown';
	}
}
