<?php
declare(strict_types=1);

namespace WP\MCP\Transport;

use WP\MCP\Registry\Server;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Generic STDIO transport for MCP over REST.
 */
class Stdio extends Base {
	protected string $namespace = 'mcp/v1';
	protected string $route_base = 'mcp';

	public function __construct( Server $mcp ) {
		parent::__construct( $mcp );
		add_action( 'rest_api_init', [ $this, 'register_routes' ], 10_000 );
	}

	protected function get_route_args(): array {
		return [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_request' ],
			'permission_callback' => [ $this, 'check_permission' ],
		];
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->route_base . '/' . $this->mcp->get_server_url(),
			$this->get_route_args()
		);
	}

	public function check_permission(): bool|WP_Error {
		return is_user_logged_in();
	}

	public function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$message = $request->get_json_params();

		if ( empty( $message ) || ! isset( $message['method'] ) ) {
			return new WP_Error(
				'invalid_request',
				'Invalid request: method parameter is required',
				[ 'status' => 400 ]
			);
		}

		$method = $message['method'];
		$params = $message['params'] ?? $message;

		$result = $this->route_request( $method, $params );

		if ( isset( $result['error'] ) ) {
			return $this->format_error_response( $result );
		}

		return $this->format_success_response( $result );
	}

	/**
	 * Create a method not found error (WordPress format).
	 *
	 * @param string $method The method that was not found.
	 * @param int $request_id The request ID (unused in WordPress format).
	 *
	 * @return array
	 */
	protected function create_method_not_found_error( string $method, int $request_id ): array {
		return array(
			'error' => array(
				'code'    => 'invalid_method',
				'message' => 'Invalid method: ' . $method,
				'data'    => array( 'status' => 400 ),
			),
		);
	}

	/**
	 * Handle exceptions that occur during request processing (WordPress format)
	 *
	 * @param \Throwable $exception The exception.
	 * @param int $request_id The request ID (unused in WordPress format).
	 *
	 * @return array
	 */
	protected function handle_exception( \Throwable $exception, int $request_id ): array {
		return array(
			'error' => array(
				'code'    => 'handler_error',
				'message' => 'Handler error occurred: ' . $exception->getMessage(),
				'data'    => array( 'status' => 500 ),
			),
		);
	}

	/**
	 * Format a successful response (WordPress format)
	 *
	 * @param array $result The result data.
	 * @param int $request_id The request ID (unused in WordPress format).
	 *
	 * @return WP_REST_Response
	 */
	protected function format_success_response( array $result, int $request_id = 0 ): WP_REST_Response {
		return rest_ensure_response( $result );
	}

	/**
	 * Format an error response (WordPress format)
	 *
	 * @param array $error The error data.
	 * @param int   $request_id The request ID (unused in WordPress format).
	 *
	 * @return \WP_Error
	 */
	protected function format_error_response( array $error, int $request_id = 0 ): WP_Error {
		$error_details = $error['error'] ?? $error;

		return new WP_Error(
			$error_details['code'] ?? 'handler_error',
			( $error_details['message'] ?? 'Handler error occurred' ) . ' [DEBUG: ' . wp_json_encode( $error_details ) . ']',
			array( 'status' => $error_details['data']['status'] ?? 500 )
		);
	}

}
