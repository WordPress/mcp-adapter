<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;
use WP\MCP\Infrastructure\Protocol\ContinuationManager;
use WP\MCP\Infrastructure\Protocol\V20260728WireEncoder;
use WP\MCP\Infrastructure\Protocol\WireEncoder;

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

	/** @var \WP\MCP\Infrastructure\Protocol\ContinuationManager */
	private ContinuationManager $continuation_manager;

	/**
	 * Initialize the request router.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $context The transport context.
	 */
	public function __construct(
		McpTransportContext $context
	) {
		$this->context              = $context;
		$this->continuation_manager = new ContinuationManager( $context->mcp_server->get_server_id() );
	}

	/**
	 * Route a request to the appropriate handler.
	 *
	 * @since n.e.x.t Adds identity-preserving request input and out-of-band session output.
	 *
	 * @param string $method The MCP method name.
	 * @param array $params The request parameters.
	 * @param mixed $request_id The request ID (for JSON-RPC) - string, number, or null.
	 * @param string $transport_name Transport name for observability.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 * @param \stdClass|null $request_identity Identity-preserving JSON-RPC request object.
	 * @param string|null $new_session_id Newly created session id, if any.
	 * @param \WP\MCP\Core\McpProtocolContext|null $protocol_context Already-resolved request revision. Defaults to legacy for custom transports.
	 *
	 * @return array
	 */
	public function route_request( string $method, array $params, $request_id = 0, string $transport_name = 'unknown', ?HttpRequestContext $http_context = null, ?\stdClass $request_identity = null, ?string &$new_session_id = null, ?McpProtocolContext $protocol_context = null ): array {
		// Track request start time.
		$start_time = microtime( true );

		$new_session_id   = null;
		$protocol_context = $protocol_context ?? McpProtocolContext::for_revision( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );
		$wire_encoder     = $this->context->mcp_server->get_wire_encoder_for_revision( $protocol_context->revision() );
		$is_modern        = McpVersionNegotiator::is_modern( $protocol_context->revision() );
		$component_tags   = $this->resolve_component_observability_context( $method, $params );
		$continuation     = null;

		// Common tags for all metrics.
		$common_tags = array(
			'method'           => $method,
			'transport'        => $transport_name,
			'server_id'        => $this->context->mcp_server->get_server_id(),
			'params'           => $this->sanitize_params_for_logging( $params ),
			'request_id'       => $request_id,
			'session_id'       => ! $is_modern && $http_context ? $http_context->session_id : null,
			'protocol_version' => $protocol_context->revision(),
		);

		try {
			$handler_result = null;
			if ( $wire_encoder instanceof V20260728WireEncoder ) {
				try {
					$wire_encoder->validate_request_metadata( $params );
				} catch ( \WP\McpSchema\Runtime\ValidationException $exception ) {
					$invalid_params = McpErrorFactory::invalid_params( $request_id, $exception->getMessage() );
					$handler_result = array( 'error' => $invalid_params['error'] );
				}

				if ( null === $handler_result && $this->continuation_manager->has_continuation_fields( $params ) ) {
					if ( ! $this->continuation_manager->supports_method( $method ) ) {
						$invalid_params = McpErrorFactory::invalid_params( $request_id, 'This method does not accept continuation data' );
						$handler_result = array( 'error' => $invalid_params['error'] );
					} else {
						try {
							$validated_params = $wire_encoder->continuation_request_params( $method, $params );
							$continuation     = $this->continuation_manager->resume( $method, $validated_params, $request_identity );
						} catch ( \WP\McpSchema\Runtime\ValidationException | \InvalidArgumentException $exception ) {
							$invalid_params = McpErrorFactory::invalid_params( $request_id, $exception->getMessage() );
							$handler_result = array( 'error' => $invalid_params['error'] );
						}
					}
				}
			} elseif ( $this->continuation_manager->has_continuation_fields( $params ) ) {
				$invalid_params = McpErrorFactory::invalid_params( $request_id, 'Continuation data requires MCP 2026-07-28' );
				$handler_result = array( 'error' => $invalid_params['error'] );
			}

			$handlers = array(
				'tools/list'               => fn() => $this->context->tools_handler->list_tools( $wire_encoder ),
				'tools/call'               => fn() => $this->context->tools_handler->call_tool( $params, $request_id, $request_identity, $wire_encoder, $continuation ),
				'resources/list'           => fn() => $this->context->resources_handler->list_resources( $wire_encoder ),
				'resources/templates/list' => fn() => $this->context->resources_handler->list_resource_templates( $wire_encoder ),
				'resources/read'           => fn() => $this->context->resources_handler->read_resource( $params, $request_id, $wire_encoder, $continuation ),
				'prompts/list'             => fn() => $this->context->prompts_handler->list_prompts( $wire_encoder ),
				'prompts/get'              => fn() => $this->context->prompts_handler->get_prompt( $params, $request_id, $wire_encoder, $continuation ),
			);

			if ( $wire_encoder instanceof WireEncoder ) {
				$handlers['initialize']     = function () use ( $params, $request_id, $http_context, $wire_encoder, &$new_session_id ) {
					return $this->handle_initialize_with_session( $params, $request_id, $http_context, $wire_encoder, $new_session_id );
				};
				$handlers['ping']           = fn() => $this->context->system_handler->ping();
				$handlers['tools/list/all'] = fn() => $this->context->tools_handler->list_all_tools( $wire_encoder );
			} elseif ( $wire_encoder instanceof V20260728WireEncoder ) {
				$handlers['server/discover'] = fn() => $this->context->initialize_handler->discover( $wire_encoder );
			} else {
				throw new \LogicException( 'Resolved MCP revision has no matching request encoder.' );
			}

			if ( null === $handler_result ) {
				$handler_result = isset( $handlers[ $method ] ) ? $handlers[ $method ]() : $this->create_method_not_found_error( $method, $request_id );
			}

			if ( ! isset( $handler_result['error'] ) && 'input_required' === ( $handler_result['resultType'] ?? null ) ) {
				if ( ! $wire_encoder instanceof V20260728WireEncoder ) {
					$unexpected_error = McpErrorFactory::internal_error( $request_id, 'Input-required results require MCP 2026-07-28' );
					$handler_result   = array( 'error' => $unexpected_error['error'] );
				} else {
					try {
						$input_requests = $handler_result['inputRequests'] ?? array();
						if ( ! is_array( $input_requests ) ) {
							throw new \InvalidArgumentException( 'inputRequests must be an object-shaped array.' );
						}

						$meta                 = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
						$client_capabilities  = isset( $meta['io.modelcontextprotocol/clientCapabilities'] ) && is_array( $meta['io.modelcontextprotocol/clientCapabilities'] )
							? $meta['io.modelcontextprotocol/clientCapabilities']
							: array();
						$missing_capabilities = $this->continuation_manager->missing_capabilities( $input_requests, $client_capabilities );

						if ( ! empty( $missing_capabilities ) ) {
							$capability_error = $wire_encoder->missing_required_client_capability_error( $request_id, $missing_capabilities );
							$handler_result   = array( 'error' => $capability_error['error'] );
						} else {
							$prepared_result = $this->continuation_manager->prepare_result( $method, $params, $request_identity, $handler_result );
							$handler_result  = $wire_encoder->input_required_result( $prepared_result );
						}
					} catch ( \WP\McpSchema\Runtime\ValidationException | \InvalidArgumentException $exception ) {
						$this->context->error_handler->log(
							'Invalid MCP input-required result returned by a component.',
							array(
								'method'   => $method,
								'revision' => $protocol_context->revision(),
								'error'    => $exception->getMessage(),
							)
						);

						$unexpected_error = McpErrorFactory::internal_error( $request_id, 'Invalid input-required result' );
						$handler_result   = array( 'error' => $unexpected_error['error'] );
					}
				}
			}

			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Handlers return revision-neutral arrays in wire shape: either a
			// result or a JSON-RPC error envelope carrying an `error` key. No
			// wire-shape result type has a top-level `error` key, so its
			// presence identifies the error outcome. A handler that violates
			// its `array` return type raises a TypeError handled by the catch
			// below.
			if ( isset( $handler_result['error'] ) ) {
				// Normalize to transport-level shape: only the JSON-RPC error object.
				// The JSON-RPC envelope is created by the transport boundary.
				$error                  = is_array( $handler_result['error'] ) ? $handler_result['error'] : array();
				$result                 = array( 'error' => $handler_result['error'] );
				$tags                   = array_merge( $common_tags, $component_tags, array( 'status' => 'error' ) );
				$tags['error_code']     = $error['code'] ?? null;
				$tags['failure_reason'] = $error['message'] ?? null;
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

				return $result;
			}

			// Success result already in wire shape.
			$result = $handler_result;

			if ( null !== $new_session_id ) {
				$component_tags['new_session_id'] = $new_session_id;
			}

			$status = 'success';
			if ( true === ( $handler_result['isError'] ?? null ) ) {
				$status = 'error';

				if ( ! isset( $component_tags['failure_reason'] ) ) {
					$content = $handler_result['content'] ?? null;
					if ( is_array( $content ) && isset( $content[0] ) && is_array( $content[0] ) && 'text' === ( $content[0]['type'] ?? null ) && isset( $content[0]['text'] ) ) {
						$component_tags['failure_reason'] = $content[0]['text'];
					}
				}
			}

			$tags = array_merge( $common_tags, $component_tags, array( 'status' => $status ) );
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

			return array( 'error' => $unexpected_error['error'] );
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
	 * Handle initialize requests with session management.
	 *
	 * Adds session management around the initialize result array.
	 *
	 * @param array $params The request parameters.
	 * @param mixed $request_id The request ID.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 * @param \WP\MCP\Infrastructure\Protocol\WireEncoder $encoder Legacy request encoder.
	 * @param string|null $new_session_id Newly created session id, if any.
	 *
	 * @return array<string, mixed> Initialize result or JSON-RPC error envelope, in wire shape.
	 */
	private function handle_initialize_with_session( array $params, $request_id, ?HttpRequestContext $http_context, WireEncoder $encoder, ?string &$new_session_id = null ): array {
		// Extract client protocol version from params, defaulting to empty string if missing.
		$client_version = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';

		// Get the initialize response from the handler (wire-shape array).
		$init_result = $this->context->initialize_handler->handle( $client_version, $encoder );

		// Handle session creation if HTTP context is provided.
		// The initialize result never carries errors - errors would be thrown as exceptions.
		if ( $http_context && ! $http_context->session_id ) {
			$negotiated_version = isset( $init_result['protocolVersion'] ) && is_string( $init_result['protocolVersion'] )
				? $init_result['protocolVersion']
				: McpVersionNegotiator::LEGACY_PROTOCOL_VERSION;
			$session_result     = HttpSessionValidator::create_session_with_error_handler( $params, $this->context->error_handler, $negotiated_version );

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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape.
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
