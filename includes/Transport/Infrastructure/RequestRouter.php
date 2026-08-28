<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;
use WP\McpSchema\Record;
use WP\McpSchema\Record\CallToolRequest;
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
		$method_value = $request->get( 'method' );
		$request_id   = $request->get( 'id' );
		if ( ! is_string( $method_value ) ) {
			return McpErrorFactory::invalid_request( $request_id, 'Validated request has no method.' );
		}
		$method = $method_value;
		$params = $this->request_params( $request );

		// Track request start time.
		$start_time = microtime( true );

		$component_tags = $this->resolve_component_observability_context( $method, $params );
		$transport_meta = $request_context->transport_metadata();

		// Common tags for all metrics.
		$common_tags = array(
			'method'     => $method,
			'transport'  => $transport_name,
			'server_id'  => $this->context->mcp_server->get_server_id(),
			'params'     => $this->sanitize_params_for_logging( $params ),
			'request_id' => $request_id,
			'session_id' => $transport_meta['session_id'] ?? null,
			'revision'   => $request_context->revision(),
		);

		try {
			$handler_result = $this->dispatch( $method, $request, $request_context, $request_id );

			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			if ( is_array( $handler_result ) && isset( $handler_result['error'] ) ) {
				$result                 = $handler_result;
				$tags                   = array_merge( $common_tags, $component_tags, array( 'status' => 'error' ) );
				$tags['error_code']     = $handler_result['error']['code'] ?? McpErrorFactory::INTERNAL_ERROR;
				$tags['failure_reason'] = $handler_result['error']['message'] ?? 'Unknown error';
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

				return $result;
			}

			$status = is_array( $handler_result ) && true === ( $handler_result['isError'] ?? false ) ? 'error' : 'success';
			if ( 'error' === $status && ! isset( $component_tags['failure_reason'] ) && is_array( $handler_result ) ) {
				$content = $handler_result['content'][0] ?? null;
				if ( is_array( $content ) && isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$component_tags['failure_reason'] = $content['text'];
				}
			}

			$tags = array_merge( $common_tags, $component_tags, array( 'status' => $status ) );
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			return $handler_result;
		} catch ( \Throwable $exception ) {
			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Track exception with categorization.
			$tags = array_merge(
				$common_tags,
				$component_tags,
				array(
					'status'         => 'error',
					'error_type'     => get_class( $exception ),
					'error_category' => $this->categorize_error( $exception ),
				)
			);
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			// Create error response from exception.
			return McpErrorFactory::internal_error( $request_id, 'Handler error occurred' );
		}
	}

	/**
	 * Dispatch one validated concrete request to its handler.
	 *
	 * @param string|int $request_id Validated JSON-RPC request ID.
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

	/** @return array<string, mixed> */
	private function request_params( Record $request ): array {
		$params = $request->get( 'params' );
		if ( $params instanceof Record ) {
			$params = $params->jsonSerialize();
		}
		if ( $params instanceof \stdClass ) {
			$params = json_decode( (string) wp_json_encode( $params ), true );
		}

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Resolve per-component observability tags for a request.
	 *
	 * This replaces legacy approaches that derived tags from protocol `_meta`.
	 *
	 * @param string $method MCP method name.
	 * @param array $params Request parameters (root or nested under `params`).
	 *
	 * @return array<string, mixed>
	 */
	private function resolve_component_observability_context( string $method, array $params ): array {
		$request_params = $params['params'] ?? $params;

		if ( ! is_array( $request_params ) ) {
			$request_params = array();
		}

		switch ( $method ) {
			case 'tools/call':
				$tool_name = $request_params['name'] ?? null;
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
				$prompt_name = $request_params['name'] ?? null;
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
				$resource_uri = $request_params['uri'] ?? null;
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
