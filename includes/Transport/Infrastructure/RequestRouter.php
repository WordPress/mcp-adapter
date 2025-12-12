<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\Dto\MetaStripper;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;

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
	 * @param array  $params The request parameters.
	 * @param mixed  $request_id The request ID (for JSON-RPC) - string, number, or null.
	 * @param string $transport_name Transport name for observability.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 *
	 * @return array
	 */
	public function route_request( string $method, array $params, $request_id = 0, string $transport_name = 'unknown', ?HttpRequestContext $http_context = null ): array {
		// Track request start time.
		$start_time = microtime( true );

		$new_session_id = null;

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
			'ping'           => fn() => $this->context->system_handler->ping( $request_id ),
			'tools/list'     => fn() => $this->context->tools_handler->list_tools( $request_id ),
			'tools/list/all' => fn() => $this->context->tools_handler->list_all_tools( $request_id ),
			'tools/call'     => fn() => $this->context->tools_handler->call_tool( $params, $request_id ),
			'resources/list' => fn() => $this->context->resources_handler->list_resources( $request_id ),
			'resources/read' => fn() => $this->context->resources_handler->read_resource( $params, $request_id ),
			'prompts/list'   => fn() => $this->context->prompts_handler->list_prompts( $request_id ),
			'prompts/get'    => fn() => $this->context->prompts_handler->get_prompt( $params, $request_id ),
		);

		try {
			$handler_result = isset( $handlers[ $method ] ) ? $handlers[ $method ]() : $this->create_method_not_found_error( $method, $request_id );

			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Handle DTO results from migrated handlers.
			// DTOs are converted to arrays at the serialization boundary (here).
			if ( $handler_result instanceof JSONRPCErrorResponse ) {
				// JSON-RPC error response DTO - convert to array.
				$result             = $handler_result->toArray();
				$tags               = array_merge( $common_tags, array( 'status' => 'error' ) );
				$tags['error_code'] = $handler_result->getError()->getCode();
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );
				return $result;
			}

			if ( $handler_result instanceof AbstractDataTransferObject ) {
				// Success DTO (ListToolsResult, CallToolResult, etc.) - convert to array.
				$raw_result = $handler_result->toArray();

				// Extract internal adapter metadata from MCP-spec _meta (if present) for observability.
				$metadata = array();
				if ( isset( $raw_result['_meta'] ) && is_array( $raw_result['_meta'] ) && isset( $raw_result['_meta']['mcp_adapter'] ) && is_array( $raw_result['_meta']['mcp_adapter'] ) ) {
					$metadata = $raw_result['_meta']['mcp_adapter'];
				}

				if ( null !== $new_session_id ) {
					$metadata['new_session_id'] = $new_session_id;
				}

				$result = MetaStripper::strip_array( $raw_result );
				if ( null !== $new_session_id ) {
					$result['_session_id'] = $new_session_id;
				}

				$tags = array_merge( $common_tags, $metadata, array( 'status' => 'success' ) );
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );
				return $result;
			}

			// Handlers should only return schema DTOs.
			$unexpected_error   = McpErrorFactory::internal_error( $request_id, 'Handler returned invalid response type.' );
			$result             = $unexpected_error->toArray();
			$tags               = array_merge( $common_tags, array( 'status' => 'error' ) );
			$tags['error_code'] = $unexpected_error->getError()->getCode();
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			return $result;
		} catch ( \Throwable $exception ) {
			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Track exception with categorization.
			$tags = array_merge(
				$common_tags,
				array(
					'status'         => 'error',
					'error_type'     => get_class( $exception ),
					'error_category' => $this->categorize_error( $exception ),
				)
			);
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			// Create error response from exception.
			return McpErrorFactory::internal_error( $request_id, 'Handler error occurred' )->toArray();
		}
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
		// Get the initialize response from the handler (returns InitializeResult DTO).
		$init_result = $this->context->initialize_handler->handle( $request_id );

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
	 * @return \WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse
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
			\Error::class                    => 'system',
			\InvalidArgumentException::class => 'validation',
			\LogicException::class           => 'logic',
			\RuntimeException::class         => 'execution',
			\TypeError::class                => 'type',
		);

		return $error_categories[ get_class( $exception ) ] ?? 'unknown';
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

		// Add arguments count for tool calls (but not the actual arguments to avoid logging sensitive data)
		if ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) {
			$sanitized['arguments_count'] = count( $params['arguments'] );
			$sanitized['arguments_keys']  = array_keys( $params['arguments'] );
		}

		return $sanitized;
	}
}
