<?php
// phpcs:ignore
declare(strict_types=1);

namespace WP\MCP\Transport;

use WP\MCP\Server;
use WP\MCP\Utils\ErrorHandler;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * MCP Streamable Transport using JSON-RPC 2.0 via REST.
 */
class Streamable extends Base {

	private int $request_id = 0;

	public function __construct(Server $mcp) {
		parent::__construct($mcp);
		add_action('rest_api_init', [$this, 'register_routes'], 20_002);
	}

	public function register_routes(): void {
		register_rest_route(
			'mcp/v1',
			'/mcp/' . $this->mcp->get_server_url() . '/streamable',
			[
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => [$this, 'handle_request'],
				'permission_callback' => [$this, 'check_permission'],
			]
		);
	}

	public function check_permission($request = null): WP_Error|bool {
		// You can override this in a subclass if needed.
		return current_user_can('read');
	}

	public function handle_request(WP_REST_Request $request): WP_REST_Response {
		if ($request->get_method() === 'OPTIONS') {
			return new WP_REST_Response(null, 204);
		}

		if ($request->get_method() !== 'POST') {
			return new WP_REST_Response(
				ErrorHandler::create_error_response(0, ErrorHandler::INVALID_REQUEST, 'Method not allowed'),
				405
			);
		}

		try {
			$body = $request->get_json_params();
			if (!is_array($body)) {
				return new WP_REST_Response(
					ErrorHandler::parse_error(0, 'Invalid JSON in request body'),
					400
				);
			}

			$messages = isset($body[0]) ? $body : [$body];
			$results  = [];

			foreach ($messages as $message) {
				$validation_result = ErrorHandler::validate_jsonrpc_message($message);
				if ($validation_result !== true) {
					return new WP_REST_Response($validation_result, 400);
				}

				if (isset($message['method'], $message['id'])) {
					$this->request_id = (int) $message['id'];
					$results[] = $this->process_message($message);
				}
			}

			$response_body = count($results) === 1 ? $results[0] : $results;

			return new WP_REST_Response($response_body, 200, [
				'Content-Type'                 => 'application/json',
				'Access-Control-Allow-Origin'  => '*',
				'Access-Control-Allow-Methods' => 'OPTIONS, GET, POST',
			]);

		} catch (\Throwable $e) {
			return new WP_REST_Response(
				ErrorHandler::handle_exception($e, $this->request_id),
				500
			);
		}
	}

	private function process_message(array $message): array {
		$this->request_id = (int) $message['id'];
		$params = $message['params'] ?? [];

		$result = $this->route_request($message['method'], $params, $this->request_id);

		if (isset($result['error'])) {
			return $this->format_error_response($result, $this->request_id);
		}

		return $this->format_success_response($result, $this->request_id);
	}

	protected function create_method_not_found_error(string $method, int $request_id): array {
		return ['error' => ErrorHandler::method_not_found($request_id, $method)['error']];
	}

	protected function handle_exception(\Throwable $exception, int $request_id): array {
		return ErrorHandler::handle_exception($exception, $request_id);
	}

	protected function format_success_response(array $result, int $request_id = 0): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'result'  => $result,
		];
	}

	protected function format_error_response(array $error, int $request_id = 0): array {
		$error_details = $error['error'] ?? $error;

		return [
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'error'   => $error_details,
		];
	}
}
