<?php //phpcs:ignore
declare(strict_types=1);

namespace WP\MCP\Utils;

use WP\MCP\Utils\ErrorHandler;
use WP\MCP\Registry;
use Exception;
use WP_REST_Request;

/**
 * Handle Tools Call message.
 */
class HandleToolsCall {

	/**
	 * Handle tool call request.
	 *
	 * @param array $message The message.
	 *
	 * @return array
	 */
	public static function run( array $message ): array {
		$tool_name = $message['params']['name'] ?? $message['name'] ?? '';
		$args      = $message['params']['arguments'] ?? $message['arguments'] ?? array();

		// Get the tool callbacks.
		$tools_callbacks = Registry::instance()->get_tools_callbacks();

		// Check if the tool exists.
		if ( ! isset( $tools_callbacks[ $tool_name ] ) ) {
			return array(
				'error' => ErrorHandler::tool_not_found( 0, $tool_name ),
			);
		}

		// Get the tool callback.
		$tool_callback = $tools_callbacks[ $tool_name ];

		// Handle REST API alias if present.
		if ( isset( $tool_callback['rest_alias'] ) ) {
			try {
				$rest_alias = $tool_callback['rest_alias'];
				$route      = $rest_alias['route'];
				$method     = $rest_alias['method'];

				// Substitute route parameters with actual values from arguments.
				$route = self::substitute_route_params( $route, $args );

				$request = new WP_REST_Request( $method, $route );

				// Execute preCallback if it exists to transform the arguments.
				$processed_params = array(
					'args' => $args,
				);

				if ( isset( $rest_alias['preCallback'] ) && is_callable( $rest_alias['preCallback'] ) ) {
					try {
						$processed_params = call_user_func( $rest_alias['preCallback'], $args );
					} catch ( Exception $e ) {
						return array(
							'error' => ErrorHandler::create_error_response(
								0,
								ErrorHandler::INTERNAL_ERROR,
								'Error in preCallback',
								$e->getMessage()
							),
						);
					}
				}

				// Set any custom headers if they were set by the preCallback.
				if ( isset( $processed_params['headers'] ) && is_array( $processed_params['headers'] ) ) {
					foreach ( $processed_params['headers'] as $header => $value ) {
						$request->set_header( $header, $value );
					}
				}

				// Set the raw body if it was set by the preCallback.
				if ( isset( $processed_params['body'] ) ) {
					$request->set_body( $processed_params['body'] );
				}

				// Use the processed args, falling back to original args if not set.
				$final_args = $processed_params['args'] ?? $args;

				// Set the arguments as query parameters or body parameters based on method.
				if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
					// For GET and DELETE, use query parameters.
					foreach ( $final_args as $key => $value ) {
						$request->set_query_params( array_merge( $request->get_query_params(), array( $key => $value ) ) );
					}
				} elseif ( ! isset( $processed_params['body'] ) ) {
					// For POST, PUT, PATCH, use body parameters.
					// Only set params if we don't have a raw body from preCallback.
					foreach ( $final_args as $key => $value ) {
						$request->set_param( $key, $value );
					}
				}

				$rest_response = rest_do_request( $request );

				if ( $rest_response->is_error() ) {
					// Handle REST API error.
					return array(
						'error' => ErrorHandler::create_error_response(
							0,
							ErrorHandler::REST_API_ERROR,
							'REST API error occurred',
							$rest_response->as_error()->get_error_message()
						),
					);
				} else {
					return $rest_response->get_data();
				}
			} catch ( Exception $e ) {
				ErrorHandler::log(
					'REST API tool execution failed',
					array(
						'tool'      => $tool_name,
						'exception' => $e->getMessage(),
					)
				);
				return array(
					'error' => ErrorHandler::create_error_response(
						0,
						ErrorHandler::REST_API_ERROR,
						'Error executing REST API',
						$e->getMessage()
					),
				);
			}
		} else {
			// Check permissions first.
			if ( isset( $tool_callback['permission_callback'] ) && is_callable( $tool_callback['permission_callback'] ) ) {
				$permission_result = call_user_func( $tool_callback['permission_callback'], $args );
				if ( ! $permission_result ) {
					return array(
						'error' => array(
							'code'    => 'rest_forbidden',
							'message' => 'Permission denied',
							'data'    => array( 'status' => 403 ),
						),
					);
				}
			}

			// Execute the tool callback.
			try {
				$result = call_user_func( $tool_callback['callback'], $args );
				return $result;
			} catch ( Exception $e ) {
				ErrorHandler::log(
					'Tool execution failed',
					array(
						'tool'      => $tool_name,
						'exception' => $e->getMessage(),
					)
				);
				return array(
					'error' => ErrorHandler::create_error_response(
						0,
						ErrorHandler::INTERNAL_ERROR,
						'Error executing tool',
						$e->getMessage()
					),
				);
			}
		}
	}

	/**
	 * Substitute route parameters with actual values from arguments.
	 *
	 * @param string $route The route pattern with named parameters.
	 * @param array  $args  The arguments containing parameter values.
	 *
	 * @return string The route with parameters substituted.
	 */
	private static function substitute_route_params( string $route, array $args ): string {
		// Find all named capture groups in the route pattern.
		if ( preg_match_all( '/\(\?P<([^>]+)>[^)]+\)/', $route, $matches ) ) {
			$param_names = $matches[1];

			// Replace each parameter with its value from args if available.
			foreach ( $param_names as $param_name ) {
				$replacement_value = null;

				// Special case for wpcom_site - use current blog ID.
				if ( 'wpcom_site' === $param_name ) {
					$replacement_value = (string) get_current_blog_id();
				} elseif ( isset( $args[ $param_name ] ) ) {
					$replacement_value = (string) $args[ $param_name ];
				}

				if ( null !== $replacement_value ) {
					$pattern = '/\(\?P<' . preg_quote( $param_name, '/' ) . '>[^)]+\)/';
					$route   = preg_replace( $pattern, $replacement_value, $route );
				}
			}
		}

		return $route;
	}
}
