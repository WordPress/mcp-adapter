<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;
use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Common\Content\DTO\TextContent;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;

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
	 * @param string $method The MCP method name.
	 * @param array $params The request parameters.
	 * @param mixed $request_id The request ID (for JSON-RPC) - string, number, or null.
	 * @param string $transport_name Transport name for observability.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 *
	 * @return array
	 */
	public function route_request( string $method, array $params, $request_id = 0, string $transport_name = 'unknown', ?HttpRequestContext $http_context = null ): array {
		// Track request start time.
		$start_time = microtime( true );

		$new_session_id = null;
		$component_tags = $this->resolve_component_observability_context( $method, $params );

		// Common tags for all metrics.
		$common_tags = array(
			'method'     => $method,
			'transport'  => $transport_name,
			'server_id'  => $this->context->mcp_server->get_server_id(),
			'params'     => $this->sanitize_params_for_logging( $params ),
			'request_id' => $request_id,
			'session_id' => $http_context ? $http_context->session_id : null,
		);

		$handlers = array(
			'initialize'     => function () use ( $params, $request_id, $http_context, &$new_session_id ) {
				return $this->handle_initialize_with_session( $params, $request_id, $http_context, $new_session_id );
			},
			'ping'           => fn() => $this->context->system_handler->ping(),
			'tools/list'     => fn() => $this->context->tools_handler->list_tools(),
			'tools/list/all' => fn() => $this->context->tools_handler->list_all_tools(),
			'tools/call'     => fn() => $this->context->tools_handler->call_tool( $params, $request_id ),
			'resources/list' => fn() => $this->context->resources_handler->list_resources(),
			'resources/read' => fn() => $this->context->resources_handler->read_resource( $params, $request_id ),
			'prompts/list'   => fn() => $this->context->prompts_handler->list_prompts(),
			'prompts/get'    => fn() => $this->context->prompts_handler->get_prompt( $params, $request_id ),
		);

		try {
			$handler_result = isset( $handlers[ $method ] ) ? $handlers[ $method ]() : $this->create_method_not_found_error( $method, $request_id );

			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Handle DTO results from migrated handlers.
			// DTOs are converted to arrays at the serialization boundary (here).
			if ( $handler_result instanceof JSONRPCErrorResponse ) {
				// Normalize to transport-level shape: only the JSON-RPC error object.
				// The JSON-RPC envelope is created by the transport boundary.
				$result                 = array( 'error' => $handler_result->getError()->toArray() );
				$tags                   = array_merge( $common_tags, $component_tags, array( 'status' => 'error' ) );
				$tags['error_code']     = $handler_result->getError()->getCode();
				$tags['failure_reason'] = $handler_result->getError()->getMessage();
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

				return $result;
			}

			if ( $handler_result instanceof AbstractDataTransferObject ) {
				// Success DTO (ListToolsResult, CallToolResult, etc.) - convert to array.
				// Note: If a future schema version ever returns nested DTO objects inside `toArray()`,
				// we may need to add a deep normalizer at this boundary (before JSON serialization)
				// to prevent placeholder `{}` objects in client output.
				$raw_result = $handler_result->toArray();
				$result     = $raw_result;

				if ( null !== $new_session_id ) {
					$component_tags['new_session_id'] = $new_session_id;
					$result['_session_id']            = $new_session_id;
				}

				$status = 'success';
				if ( $handler_result instanceof CallToolResult && true === $handler_result->getIsError() ) {
					$status = 'error';

					if ( ! isset( $component_tags['failure_reason'] ) ) {
						$content = $handler_result->getContent();
						if ( isset( $content[0] ) && $content[0] instanceof TextContent ) {
							$component_tags['failure_reason'] = $content[0]->getText();
						}
					}
				}

				$tags = array_merge( $common_tags, $component_tags, array( 'status' => $status ) );
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

				return $result;
			}

			// Handlers should only return schema DTOs.
			$actual_type = is_object( $handler_result ) ? get_class( $handler_result ) : gettype( $handler_result );
			$this->context->error_handler->log(
				sprintf( 'Handler for method "%s" returned unexpected type: %s', $method, $actual_type ),
				array(
					'method'      => $method,
					'actual_type' => $actual_type,
				)
			);
			$unexpected_error   = McpErrorFactory::internal_error( $request_id, 'Handler returned invalid response type.' );
			$result             = array( 'error' => $unexpected_error->getError()->toArray() );
			$tags               = array_merge( $common_tags, $component_tags, array( 'status' => 'error' ) );
			$tags['error_code'] = $unexpected_error->getError()->getCode();
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			return $result;
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
			$unexpected_error = McpErrorFactory::internal_error( $request_id, 'Handler error occurred' );

			return array( 'error' => $unexpected_error->getError()->toArray() );
		}
	}

	/**
	 * Resolve per-component observability tags for a request.
	 *
	 * This replaces legacy approaches that derived tags from DTO `_meta`.
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
				if ( McpObservabilityHelperTrait::is_sensitive_key( (string) $arg_key ) ) {
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
	 * Handle initialize requests with session management.
	 *
	 * Converts InitializeResult DTO to array and adds session management.
	 *
	 * @param array $params The request parameters.
	 * @param mixed $request_id The request ID.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 * @param string|null $new_session_id Newly created session id, if any.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject
	 */
	private function handle_initialize_with_session( array $params, $request_id, ?HttpRequestContext $http_context, ?string &$new_session_id = null ): AbstractDataTransferObject {
		// Extract client protocol version from params, defaulting to empty string if missing.
		$client_version = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';

		// Get the initialize response from the handler (returns InitializeResult DTO).
		$init_result = $this->context->initialize_handler->handle( $client_version );

		// Handle session creation if HTTP context is provided.
		// InitializeResult DTO never has errors - errors would be thrown as exceptions.
		if ( $http_context && ! $http_context->session_id ) {
			$session_result = HttpSessionValidator::create_session( $params );

			if ( is_array( $session_result ) ) {
				$error = $session_result['error'] ?? array();

				return McpErrorFactory::create_error_response(
					$request_id,
					isset( $error['code'] ) ? (int) $error['code'] : McpErrorFactory::INTERNAL_ERROR,
					(string) ( $error['message'] ?? __( 'Failed to create session', 'mcp-adapter' ) ),
					$error['data'] ?? null
				);
			}

			$new_session_id = $session_result;
		}

		return $init_result;
	}

	/**
	 * Create a method not found error with generic format.
	 *
	 * @param string $method The method that was not found.
	 * @param mixed $request_id The request ID.
	 *
	 * @return \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse
	 */
	private function create_method_not_found_error( string $method, $request_id ): JSONRPCErrorResponse {
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
