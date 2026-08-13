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
use WP\MCP\Domain\Continuation\McpContinuationContext;
use WP\MCP\Domain\Continuation\McpExecutionResult;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;

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
	 * @param mixed $params Request parameters or a lossless transport carrier.
	 * @param mixed $request_id The request ID (for JSON-RPC) - string, number, or null.
	 * @param string $transport_name Transport name for observability.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 *
	 * @return array
	 */
	public function route_request(
		string $method,
		$params,
		$request_id = 0,
		string $transport_name = 'unknown',
		?HttpRequestContext $http_context = null,
		?string $protocol_version = null
	): array {
		// Track request start time.
		$start_time = microtime( true );

		$is_wire_params = $params instanceof JsonRpcRequestParams;
		$params_present = $is_wire_params ? $params->is_present() : true;
		$wire_params    = $is_wire_params ? $params->get_value() : $params;
		$params         = $this->callback_params( $wire_params );
		if ( ! is_array( $params ) ) {
			$params = array();
		}

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

		try {
			$protocol = $this->resolve_protocol_context( $method, $params, $protocol_version, $request_id );
			if ( is_array( $protocol ) ) {
				return $this->record_error_result( $protocol, $common_tags, $component_tags, $start_time );
			}

			if ( ! $this->method_is_supported( $method, $protocol ) ) {
				$error = $this->error_object( McpErrorFactory::method_not_found( $request_id, $method ) );
				return $this->record_error_result(
					$this->normalize_protocol_error( $protocol, $method, $request_id, $error ),
					$common_tags,
					$component_tags,
					$start_time
				);
			}

			$request_error = $this->validate_request(
				$protocol,
				$method,
				$wire_params,
				$request_id,
				$is_wire_params,
				$params_present
			);
			if ( null !== $request_error ) {
				return $this->record_error_result(
					$this->normalize_protocol_error( $protocol, $method, $request_id, $request_error ),
					$common_tags,
					$component_tags,
					$start_time
				);
			}

			$continuation = new McpContinuationContext(
				isset( $params['inputResponses'] ) && is_array( $params['inputResponses'] ) ? $params['inputResponses'] : array(),
				isset( $params['requestState'] ) && is_string( $params['requestState'] ) ? $params['requestState'] : null,
				$protocol->get_client_capabilities()
			);

			$handler_result = $this->dispatch(
				$protocol,
				$method,
				$params,
				$request_id,
				$continuation,
				$http_context,
				$new_session_id
			);

			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			if ( is_array( $handler_result ) && isset( $handler_result['error'] ) && is_array( $handler_result['error'] ) ) {
				$error                  = $this->normalize_protocol_error( $protocol, $method, $request_id, $handler_result['error'] );
				$result                 = array( 'error' => $error );
				$tags                   = array_merge( $common_tags, $component_tags, array( 'status' => 'error' ) );
				$tags['error_code']     = $error['code'] ?? McpErrorFactory::INTERNAL_ERROR;
				$tags['failure_reason'] = $error['message'] ?? 'Unknown error';
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

				return $result;
			}
			if ( $handler_result instanceof McpExecutionResult && $handler_result->is_input_required() ) {
				$missing_capability = $this->find_missing_input_request_capability(
					$handler_result->get_input_requests(),
					$protocol->get_client_capabilities()
				);
				if ( null !== $missing_capability ) {
					$error_response = McpErrorFactory::missing_required_client_capability(
						$request_id,
						array( $missing_capability => new \stdClass() )
					);
					return $this->record_error_result(
						$this->normalize_protocol_error(
							$protocol,
							$method,
							$request_id,
							$this->error_object( $error_response )
						),
						$common_tags,
						$component_tags,
						$start_time
					);
				}
			}

			$result = $this->encode_result( $protocol, $method, $handler_result );
			$this->validate_result_response( $protocol, $method, $request_id, $result );
			if ( null !== $new_session_id ) {
				$component_tags['new_session_id'] = $new_session_id;
				$result['_session_id']            = $new_session_id;
			}

			$status = isset( $result['isError'] ) && true === $result['isError'] ? 'error' : 'success';
			if ( 'error' === $status && ! isset( $component_tags['failure_reason'] ) ) {
				$content = $result['content'][0] ?? null;
				if ( is_array( $content ) && isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$component_tags['failure_reason'] = $content['text'];
				}
			}
			$tags = array_merge( $common_tags, $component_tags, array( 'status' => $status ) );
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			return $result;
		} catch ( \WP\McpSchema\Runtime\ValidationException $exception ) {
			$this->context->error_handler->log(
				$exception->getMessage(),
				array(
					'method'           => $method,
					'protocol_version' => $protocol_version,
				)
			);
			$error = $this->error_object( McpErrorFactory::internal_error( $request_id, 'Response failed MCP schema validation.' ) );
			if ( isset( $protocol ) && $protocol instanceof McpProtocolContext ) {
				$error = $this->normalize_protocol_error( $protocol, $method, $request_id, $error );
			}
			return $this->record_error_result(
				$error,
				$common_tags,
				$component_tags,
				$start_time
			);
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
			$error = $this->error_object( McpErrorFactory::internal_error( $request_id, 'Handler error occurred' ) );
			if ( isset( $protocol ) && $protocol instanceof McpProtocolContext ) {
				$error = $this->normalize_protocol_error( $protocol, $method, $request_id, $error );
			}
			return array( 'error' => $error );
		}
	}

	/**
	 * Resolve the exact request protocol or return an error object.
	 *
	 * @param string      $method Method name.
	 * @param array       $params Request params.
	 * @param string|null $explicit_version Transport-selected legacy revision.
	 * @param string|int|null $request_id JSON-RPC request ID.
	 * @return \WP\MCP\Core\McpProtocolContext|array<string, mixed>
	 */
	private function resolve_protocol_context( string $method, array $params, ?string $explicit_version, $request_id ) {
		if ( 'initialize' === $method ) {
			$requested = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] )
				? $params['protocolVersion']
				: '';
			return McpProtocolContext::for_version( McpVersionNegotiator::negotiate( $requested ) );
		}

		$metadata_version = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
		$requested        = is_string( $metadata_version ) ? $metadata_version : $explicit_version;

		if ( null === $requested && 'server/discover' === $method ) {
			$requested = McpVersionNegotiator::MODERN_PROTOCOL_VERSION;
		}
		if ( null === $requested ) {
			$requested = McpVersionNegotiator::LEGACY_PROTOCOL_VERSION;
		}

		if ( ! McpVersionNegotiator::is_supported( $requested ) ) {
			$error = $this->error_object(
				McpErrorFactory::unsupported_protocol_version( $request_id, $requested, McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS )
			);
			return $this->normalize_protocol_error(
				McpProtocolContext::for_version( McpVersionNegotiator::MODERN_PROTOCOL_VERSION ),
				$method,
				$request_id,
				$error
			);
		}

		$capabilities = $params['_meta']['io.modelcontextprotocol/clientCapabilities'] ?? array();
		if ( ! is_array( $capabilities ) ) {
			$capabilities = array();
		}

		return McpProtocolContext::for_version( $requested, $capabilities );
	}

	private function method_is_supported( string $method, McpProtocolContext $protocol ): bool {
		$common = array(
			'tools/list',
			'tools/call',
			'resources/list',
			'resources/templates/list',
			'resources/read',
			'prompts/list',
			'prompts/get',
		);
		if ( in_array( $method, $common, true ) ) {
			return true;
		}
		if ( $protocol->is_modern() ) {
			return 'server/discover' === $method;
		}

		return in_array( $method, array( 'initialize', 'ping', 'tools/list/all' ), true );
	}

	/**
	 * Validate an exact request envelope before handler execution.
	 *
	 * @param mixed           $params Raw or programmatic request params.
	 * @param string|int|null $request_id JSON-RPC request ID.
	 * @return array<string, mixed>|null
	 */
	private function validate_request(
		McpProtocolContext $protocol,
		string $method,
		$params,
		$request_id,
		bool $preserve_wire_identity = false,
		bool $params_present = true
	): ?array {
		$type_names = $protocol->is_modern()
			? array(
				'server/discover'          => 'DiscoverRequest',
				'tools/list'               => 'ListToolsRequest',
				'tools/call'               => 'CallToolRequest',
				'resources/list'           => 'ListResourcesRequest',
				'resources/templates/list' => 'ListResourceTemplatesRequest',
				'resources/read'           => 'ReadResourceRequest',
				'prompts/list'             => 'ListPromptsRequest',
				'prompts/get'              => 'GetPromptRequest',
			)
			: array(
				'initialize'               => 'InitializeRequest',
				'ping'                     => 'PingRequest',
				'tools/list'               => 'ListToolsRequest',
				'tools/list/all'           => 'ListToolsRequest',
				'tools/call'               => 'CallToolRequest',
				'resources/list'           => 'ListResourcesRequest',
				'resources/templates/list' => 'ListResourceTemplatesRequest',
				'resources/read'           => 'ReadResourceRequest',
				'prompts/list'             => 'ListPromptsRequest',
				'prompts/get'              => 'GetPromptRequest',
			);

		$type_name = $type_names[ $method ] ?? null;
		if ( null === $type_name ) {
			return $this->error_object( McpErrorFactory::method_not_found( $request_id, $method ) );
		}

		$wire            = array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'method'  => 'tools/list/all' === $method ? 'tools/list' : $method,
		);
		$requires_params = $protocol->is_modern() || in_array( $method, array( 'initialize', 'tools/call', 'resources/read', 'prompts/get' ), true );
		if ( ( $preserve_wire_identity && $params_present ) || ( ! $preserve_wire_identity && ( $requires_params || ! empty( $params ) ) ) ) {
			$wire['params'] = $preserve_wire_identity
				? $params
				: $this->normalize_public_request_params( $method, is_array( $params ) ? $params : array() );
		}

		try {
			$protocol->get_schema()->type( $type_name )->fromValue( $wire );
		} catch ( \WP\McpSchema\Runtime\ValidationException $exception ) {
			return $this->error_object( McpErrorFactory::invalid_params( $request_id, $exception->getMessage() ) );
		}

		return null;
	}

	/**
	 * Apply revision-specific protocol error policy and serialize the complete
	 * JSON-RPC error envelope through the selected catalog.
	 *
	 * @param string|int|null    $request_id Request ID.
	 * @param array<string,mixed> $error Handler error object.
	 * @return array<string,mixed>
	 */
	private function normalize_protocol_error( McpProtocolContext $protocol, string $method, $request_id, array $error ): array {
		$code = isset( $error['code'] ) && is_numeric( $error['code'] ) ? (int) $error['code'] : McpErrorFactory::INTERNAL_ERROR;
		if (
			( 'tools/call' === $method && McpErrorFactory::TOOL_NOT_FOUND === $code )
			|| ( 'prompts/get' === $method && McpErrorFactory::PROMPT_NOT_FOUND === $code )
			|| ( $protocol->is_modern() && 'resources/read' === $method && McpErrorFactory::RESOURCE_NOT_FOUND === $code )
		) {
			$error = McpErrorFactory::create_error(
				McpErrorFactory::INVALID_PARAMS,
				(string) ( $error['message'] ?? 'Invalid params' ),
				$error['data'] ?? null
			);
		}
		$code = isset( $error['code'] ) && is_numeric( $error['code'] ) ? (int) $error['code'] : McpErrorFactory::INTERNAL_ERROR;

		$envelope = array(
			'jsonrpc' => '2.0',
			'error'   => $error,
		);
		if ( null !== $request_id ) {
			$envelope['id'] = $request_id;
		}
		$type_name = 'JSONRPCErrorResponse';
		if ( $protocol->is_modern() ) {
			$type_name = array(
				McpErrorFactory::HEADER_MISMATCH => 'HeaderMismatchError',
				McpErrorFactory::MISSING_REQUIRED_CLIENT_CAPABILITY => 'MissingRequiredClientCapabilityError',
				McpErrorFactory::UNSUPPORTED_PROTOCOL_VERSION => 'UnsupportedProtocolVersionError',
			)[ $code ] ?? 'JSONRPCErrorResponse';
		}

		$record = $protocol->get_schema()->type( $type_name )->fromValue( $envelope );
		$wire   = $record->toWireArray();

		return isset( $wire['error'] ) && is_array( $wire['error'] ) ? $wire['error'] : $error;
	}

	/**
	 * Dispatch revision-neutral handlers.
	 *
	 * @param string|null $new_session_id Newly created session ID.
	 * @param string|int|null $request_id JSON-RPC request ID.
	 * @return mixed
	 */
	private function dispatch(
		McpProtocolContext $protocol,
		string $method,
		array $params,
		$request_id,
		McpContinuationContext $continuation,
		?HttpRequestContext $http_context,
		?string &$new_session_id
	) {
		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize_with_session( $params, $request_id, $http_context, $new_session_id );
			case 'server/discover':
				return $this->discover_result();
			case 'ping':
				return $this->context->system_handler->ping();
			case 'tools/list':
				return $this->context->tools_handler->list_tools();
			case 'tools/list/all':
				return $this->context->tools_handler->list_all_tools();
			case 'tools/call':
				return $this->context->tools_handler->call_tool( $params, $request_id, $continuation );
			case 'resources/list':
				return $this->context->resources_handler->list_resources();
			case 'resources/templates/list':
				return $this->context->resources_handler->list_resource_templates();
			case 'resources/read':
				return $this->context->resources_handler->read_resource( $params, $request_id, $continuation );
			case 'prompts/list':
				return $this->context->prompts_handler->list_prompts();
			case 'prompts/get':
				return $this->context->prompts_handler->get_prompt( $params, $request_id, $continuation );
		}

		return McpErrorFactory::method_not_found( $request_id, $method );
	}

	/** @return array<string, mixed> */
	private function discover_result(): array {
		return array(
			'supportedVersions' => McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS,
			'capabilities'      => $this->server_capabilities(),
			'instructions'      => $this->context->mcp_server->get_server_description(),
		);
	}

	/** @return array<string, mixed> */
	private function server_capabilities(): array {
		return array(
			'prompts'   => new \stdClass(),
			'resources' => new \stdClass(),
			'tools'     => new \stdClass(),
		);
	}

	/**
	 * Encode one handler outcome through the exact selected schema catalog.
	 *
	 * @param mixed $handler_result Revision-neutral handler result.
	 * @return array<string, mixed>
	 */
	private function encode_result( McpProtocolContext $protocol, string $method, $handler_result ): array {
		if ( $handler_result instanceof McpExecutionResult && $handler_result->is_input_required() ) {
			return $this->encode_input_required( $protocol, $method, $handler_result );
		}
		if ( $handler_result instanceof McpExecutionResult ) {
			$handler_result = $handler_result->get_value();
		}
		if ( ! is_array( $handler_result ) ) {
			throw new \UnexpectedValueException( sprintf( 'Handler for %s returned %s.', $method, gettype( $handler_result ) ) );
		}

		$type_names = array(
			'initialize'               => 'InitializeResult',
			'server/discover'          => 'DiscoverResult',
			'ping'                     => 'EmptyResult',
			'tools/list'               => 'ListToolsResult',
			'tools/list/all'           => 'ListToolsResult',
			'tools/call'               => 'CallToolResult',
			'resources/list'           => 'ListResourcesResult',
			'resources/templates/list' => 'ListResourceTemplatesResult',
			'resources/read'           => 'ReadResourceResult',
			'prompts/list'             => 'ListPromptsResult',
			'prompts/get'              => 'GetPromptResult',
		);
		$type_name  = $type_names[ $method ];
		$wire       = $handler_result;

		if ( $protocol->is_modern() ) {
			$wire['resultType'] = 'complete';
			$wire['_meta']      = array(
				'io.modelcontextprotocol/serverInfo' => array(
					'name'    => $this->context->mcp_server->get_server_name(),
					'version' => $this->context->mcp_server->get_server_version(),
				),
			);
			if ( in_array( $method, array( 'server/discover', 'tools/list', 'resources/list', 'resources/templates/list', 'resources/read', 'prompts/list' ), true ) ) {
				$wire['ttlMs']      = 0;
				$wire['cacheScope'] = 'private';
			}
		}

		$wire   = $this->normalize_public_result( $method, $wire, $protocol->is_modern() );
		$record = $protocol->get_schema()->type( $type_name )->fromValue( empty( $wire ) ? new \stdClass() : $wire );

		return $this->public_result_array( $record->toWireArray() );
	}

	/**
	 * Validate the complete JSON-RPC success envelope without changing the
	 * transport-facing bare-result compatibility contract.
	 *
	 * @param string|int|null    $request_id JSON-RPC request ID.
	 * @param array<string,mixed> $result Encoded result.
	 */
	private function validate_result_response( McpProtocolContext $protocol, string $method, $request_id, array $result ): void {
		$type_name = 'JSONRPCResultResponse';
		if ( $protocol->is_modern() ) {
			$type_name = array(
				'server/discover'          => 'DiscoverResultResponse',
				'tools/list'               => 'ListToolsResultResponse',
				'tools/call'               => 'CallToolResultResponse',
				'resources/list'           => 'ListResourcesResultResponse',
				'resources/templates/list' => 'ListResourceTemplatesResultResponse',
				'resources/read'           => 'ReadResourceResultResponse',
				'prompts/list'             => 'ListPromptsResultResponse',
				'prompts/get'              => 'GetPromptResultResponse',
			)[ $method ] ?? 'JSONRPCResultResponse';
		}

		$protocol->get_schema()->type( $type_name )->fromValue(
			array(
				'jsonrpc' => '2.0',
				'id'      => $request_id,
				'result'  => empty( $result ) ? new \stdClass() : $result,
			)
		);
	}

	/** @return array<string, mixed> */
	private function encode_input_required( McpProtocolContext $protocol, string $method, McpExecutionResult $result ): array {
		if ( ! $protocol->is_modern() || ! in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
			throw new \UnexpectedValueException( 'Input-required results are not supported for this method or protocol revision.' );
		}

		$wire = array( 'resultType' => 'input_required' );
		if ( ! empty( $result->get_input_requests() ) ) {
			$wire['inputRequests'] = $result->get_input_requests();
		}
		if ( null !== $result->get_request_state() ) {
			$wire['requestState'] = $result->get_request_state();
		}
		$wire['_meta'] = array(
			'io.modelcontextprotocol/serverInfo' => array(
				'name'    => $this->context->mcp_server->get_server_name(),
				'version' => $this->context->mcp_server->get_server_version(),
			),
		);

		$record = $protocol->get_schema()->type( 'InputRequiredResult' )->fromValue( $this->normalize_input_required_result( $wire ) );

		return $this->public_result_array( $record->toWireArray() );
	}

	/**
	 * @param array<string, mixed> $input_requests Embedded MCP requests.
	 * @param array<string, mixed> $capabilities Per-request client capabilities.
	 */
	private function find_missing_input_request_capability( array $input_requests, array $capabilities ): ?string {
		foreach ( $input_requests as $input_request ) {
			if ( ! is_array( $input_request ) || ! isset( $input_request['method'] ) || ! is_string( $input_request['method'] ) ) {
				continue;
			}
			$required = array(
				'elicitation/create'     => 'elicitation',
				'sampling/createMessage' => 'sampling',
				'roots/list'             => 'roots',
			)[ $input_request['method'] ] ?? null;
			if ( null !== $required && ! array_key_exists( $required, $capabilities ) ) {
				return $required;
			}
		}

		return null;
	}

	/**
	 * Convert callback-facing objects to arrays after lossless schema validation.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function callback_params( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = $this->callback_params( $item );
		}
		return $result;
	}

	/**
	 * Normalize only documented object-shaped request fields for PHP callers.
	 *
	 * Raw transport requests bypass this compatibility boundary and retain their
	 * exact JSON object/list identity for schema validation.
	 *
	 * @param array<string,mixed> $params Programmatic request params.
	 * @return array<string,mixed>
	 */
	private function normalize_public_request_params( string $method, array $params ): array {
		if ( 'initialize' === $method ) {
			$this->normalize_empty_object_field( $params, 'capabilities' );
			$this->normalize_capabilities( $params, 'capabilities' );
			$this->normalize_empty_object_field( $params, 'clientInfo' );
		}

		$this->normalize_request_meta( $params );
		if ( in_array( $method, array( 'tools/call', 'prompts/get' ), true ) ) {
			$this->normalize_empty_object_field( $params, 'arguments' );
		}
		if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
			$this->normalize_empty_object_field( $params, 'inputResponses' );
		}

		return $params;
	}

	/** @param array<string,mixed> $params */
	private function normalize_request_meta( array &$params ): void {
		$this->normalize_empty_object_field( $params, '_meta' );
		if ( ! isset( $params['_meta'] ) || ! is_array( $params['_meta'] ) ) {
			return;
		}
		$meta =& $params['_meta'];
		$this->normalize_empty_object_field( $meta, 'io.modelcontextprotocol/clientCapabilities' );
		$this->normalize_empty_object_field( $meta, 'io.modelcontextprotocol/clientInfo' );
		$this->normalize_capabilities( $meta, 'io.modelcontextprotocol/clientCapabilities' );
	}

	/** @param array<string,mixed> $container */
	private function normalize_capabilities( array &$container, string $key ): void {
		if ( ! isset( $container[ $key ] ) || ! is_array( $container[ $key ] ) ) {
			return;
		}
		$capabilities =& $container[ $key ];
		foreach ( array( 'elicitation', 'experimental', 'roots', 'sampling', 'tasks' ) as $capability ) {
			$this->normalize_empty_object_field( $capabilities, $capability );
		}
		if ( ! isset( $capabilities['elicitation'] ) || ! is_array( $capabilities['elicitation'] ) ) {
			return;
		}

		$this->normalize_empty_object_field( $capabilities['elicitation'], 'form' );
		$this->normalize_empty_object_field( $capabilities['elicitation'], 'url' );
	}

	/**
	 * Normalize only exact result fields whose public PHP shape predates the
	 * descriptor model. Opaque payloads such as structuredContent stay untouched.
	 *
	 * @param array<string,mixed> $wire Result data.
	 * @return array<string,mixed>
	 */
	private function normalize_public_result( string $method, array $wire, bool $modern ): array {
		$this->normalize_empty_object_field( $wire, '_meta' );
		if ( 'initialize' === $method || 'server/discover' === $method ) {
			$this->normalize_empty_object_field( $wire, 'capabilities' );
			$this->normalize_capabilities( $wire, 'capabilities' );
			$this->normalize_empty_object_field( $wire, 'serverInfo' );
		}
		if ( ! $modern && 'tools/call' === $method ) {
			$this->normalize_empty_object_field( $wire, 'structuredContent' );
		}
		if ( isset( $wire['_meta'] ) && is_array( $wire['_meta'] ) ) {
			$this->normalize_empty_object_field( $wire['_meta'], 'io.modelcontextprotocol/serverInfo' );
		}
		if ( 'tools/list' === $method && isset( $wire['tools'] ) && is_array( $wire['tools'] ) ) {
			foreach ( $wire['tools'] as &$tool ) {
				if ( ! is_array( $tool ) ) {
					continue;
				}

				$this->normalize_tool_definition( $tool );
			}
			unset( $tool );
		}
		if ( 'resources/list' === $method && isset( $wire['resources'] ) && is_array( $wire['resources'] ) ) {
			foreach ( $wire['resources'] as &$resource ) {
				if ( ! is_array( $resource ) ) {
					continue;
				}

				$this->normalize_empty_object_field( $resource, '_meta' );
				$this->normalize_empty_object_field( $resource, 'annotations' );
			}
			unset( $resource );
		}
		if ( 'prompts/list' === $method && isset( $wire['prompts'] ) && is_array( $wire['prompts'] ) ) {
			foreach ( $wire['prompts'] as &$prompt ) {
				if ( ! is_array( $prompt ) ) {
					continue;
				}

				$this->normalize_empty_object_field( $prompt, '_meta' );
			}
			unset( $prompt );
		}

		return $wire;
	}

	/** @param array<string,mixed> $tool */
	private function normalize_tool_definition( array &$tool ): void {
		foreach ( array( '_meta', 'annotations', 'execution', 'inputSchema', 'outputSchema' ) as $field ) {
			$this->normalize_empty_object_field( $tool, $field );
		}
		foreach ( array( 'inputSchema', 'outputSchema' ) as $schema_field ) {
			if ( ! isset( $tool[ $schema_field ] ) || ! is_array( $tool[ $schema_field ] ) ) {
				continue;
			}
			foreach ( array( '$defs', 'definitions', 'patternProperties', 'properties' ) as $object_field ) {
				$this->normalize_empty_object_field( $tool[ $schema_field ], $object_field );
			}
		}
	}

	/** @param array<string,mixed> $wire @return array<string,mixed> */
	private function normalize_input_required_result( array $wire ): array {
		$this->normalize_empty_object_field( $wire, '_meta' );
		if ( isset( $wire['_meta'] ) && is_array( $wire['_meta'] ) ) {
			$this->normalize_empty_object_field( $wire['_meta'], 'io.modelcontextprotocol/serverInfo' );
		}
		$this->normalize_empty_object_field( $wire, 'inputRequests' );
		if ( isset( $wire['inputRequests'] ) && is_array( $wire['inputRequests'] ) ) {
			foreach ( $wire['inputRequests'] as &$request ) {
				if ( ! is_array( $request ) ) {
					continue;
				}
				$this->normalize_empty_object_field( $request, 'params' );
				if ( ! isset( $request['params'] ) || ! is_array( $request['params'] ) ) {
					continue;
				}

				$params =& $request['params'];
				$this->normalize_empty_object_field( $params, 'requestedSchema' );
				if ( isset( $params['requestedSchema'] ) && is_array( $params['requestedSchema'] ) ) {
					$this->normalize_empty_object_field( $params['requestedSchema'], 'properties' );
				}
				if ( 'sampling/createMessage' === ( $request['method'] ?? null ) ) {
					foreach ( array( 'metadata', 'modelPreferences', 'toolChoice' ) as $object_field ) {
						$this->normalize_empty_object_field( $params, $object_field );
					}
					if ( isset( $params['modelPreferences']['hints'] ) && is_array( $params['modelPreferences']['hints'] ) ) {
						foreach ( $params['modelPreferences']['hints'] as &$hint ) {
							if ( array() !== $hint ) {
								continue;
							}

							$hint = new \stdClass();
						}
						unset( $hint );
					}
					if ( isset( $params['tools'] ) && is_array( $params['tools'] ) ) {
						foreach ( $params['tools'] as &$tool ) {
							if ( ! is_array( $tool ) ) {
								continue;
							}

							$this->normalize_tool_definition( $tool );
						}
						unset( $tool );
					}
					if ( isset( $params['messages'] ) && is_array( $params['messages'] ) ) {
						foreach ( $params['messages'] as &$message ) {
							if ( ! is_array( $message ) ) {
								continue;
							}
							$this->normalize_empty_object_field( $message, '_meta' );
							if ( ! array_key_exists( 'content', $message ) ) {
								continue;
							}

							$this->normalize_sampling_content( $message['content'] );
						}
						unset( $message );
					}
				}
				if ( ! ( 'roots/list' === ( $request['method'] ?? null ) ) ) {
					continue;
				}

				$this->normalize_empty_object_field( $params, '_meta' );
			}
			unset( $request );
		}

		return $wire;
	}

	/** @param mixed $content */
	private function normalize_sampling_content( &$content ): void {
		if ( ! is_array( $content ) ) {
			return;
		}
		if ( $this->is_list_array( $content ) ) {
			foreach ( $content as &$block ) {
				$this->normalize_sampling_content( $block );
			}
			unset( $block );
			return;
		}

		$this->normalize_empty_object_field( $content, '_meta' );
		$this->normalize_empty_object_field( $content, 'annotations' );
		if ( 'tool_use' === ( $content['type'] ?? null ) ) {
			$this->normalize_empty_object_field( $content, 'input' );
		}
		if ( 'resource' === ( $content['type'] ?? null ) && isset( $content['resource'] ) && is_array( $content['resource'] ) ) {
			$this->normalize_empty_object_field( $content['resource'], '_meta' );
		}
		if ( ! ( 'tool_result' === ( $content['type'] ?? null ) ) || ! isset( $content['content'] ) || ! is_array( $content['content'] ) ) {
			return;
		}

		foreach ( $content['content'] as &$result_block ) {
			$this->normalize_sampling_content( $result_block );
		}
		unset( $result_block );
	}

	/** @param array<string,mixed> $container */
	private function normalize_empty_object_field( array &$container, string $key ): void {
		if ( ! isset( $container[ $key ] ) || array() !== $container[ $key ] ) {
			return;
		}

		$container[ $key ] = new \stdClass();
	}

	/**
	 * Preserve empty JSON objects while keeping historical non-empty nested
	 * response records accessible as associative arrays to custom transports.
	 *
	 * @param mixed $value Wire value.
	 * @return mixed
	 */
	private function public_result_value( $value ) {
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			if ( empty( $properties ) ) {
				return $value;
			}
			if ( $this->is_list_array( $properties ) ) {
				return $value;
			}
			$value = $properties;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = $this->public_result_value( $item );
		}

		return $result;
	}

	/** @param array<mixed> $value */
	private function is_list_array( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/** @param array<string,mixed> $wire @return array<string,mixed> */
	private function public_result_array( array $wire ): array {
		$result = $this->public_result_value( $wire );

		return is_array( $result ) ? $result : array();
	}

	/** @param array<string, mixed> $response Full error envelope or error object. */
	private function error_object( array $response ): array {
		$error = $response['error'] ?? $response;
		return is_array( $error ) ? $error : array(
			'code'    => McpErrorFactory::INTERNAL_ERROR,
			'message' => 'Internal error',
		);
	}

	/**
	 * Record and return a router error shape.
	 *
	 * @param array<string, mixed> $error Error object.
	 * @param array<string, mixed> $common_tags Common tags.
	 * @param array<string, mixed> $component_tags Component tags.
	 * @return array{error: array<string, mixed>}
	 */
	private function record_error_result( array $error, array $common_tags, array $component_tags, float $start_time ): array {
		$duration               = ( microtime( true ) - $start_time ) * 1000;
		$tags                   = array_merge( $common_tags, $component_tags, array( 'status' => 'error' ) );
		$tags['error_code']     = $error['code'] ?? McpErrorFactory::INTERNAL_ERROR;
		$tags['failure_reason'] = $error['message'] ?? 'Unknown error';
		$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

		return array( 'error' => $error );
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
	 * Converts InitializeResult DTO to array and adds session management.
	 *
	 * @param array $params The request parameters.
	 * @param mixed $request_id The request ID.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 * @param string|null $new_session_id Newly created session id, if any.
	 *
	 * @return array<string, mixed>
	 */
	private function handle_initialize_with_session( array $params, $request_id, ?HttpRequestContext $http_context, ?string &$new_session_id = null ): array {
		// Extract client protocol version from params, defaulting to empty string if missing.
		$client_version = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';

		// Get the revision-neutral initialize result from the handler.
		$init_result = $this->context->initialize_handler->handle( $client_version );

		// Handle session creation if HTTP context is provided.
		// InitializeResult never has errors - errors would be thrown as exceptions.
		if ( $http_context && ! $http_context->session_id ) {
			$session_params                    = $params;
			$session_params['protocolVersion'] = (string) ( $init_result['protocolVersion'] ?? McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );
			$session_result                    = HttpSessionValidator::create_session_with_error_handler( $session_params, $this->context->error_handler );

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
