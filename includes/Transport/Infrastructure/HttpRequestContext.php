<?php
/**
 * Exact-revision MCP HTTP request context for WordPress.
 *
 * @package McpAdapter
 */
declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * A simple data carrier for HTTP request context.
 * Properties are public for easy access but should be treated as read-only after construction.
 */
class HttpRequestContext {

	/**
	 * The original WordPress REST request object.
	 *
	 * @var \WP_REST_Request<array<string, mixed>>
	 */
	public \WP_REST_Request $request;

	/**
	 * The HTTP method of the request.
	 *
	 * @var string
	 */
	public string $method;


	/**
	 * The Mcp-Session-Id header from the request.
	 *
	 * @var string|null
	 */
	public ?string $session_id;

	/**
	 * The undecoded request body.
	 *
	 * @var string
	 */
	public string $raw_body;

	/** @var array<string, string> */
	public array $headers;

	/**
	 * The MCP-Protocol-Version header from the request.
	 *
	 * @since 0.5.0
	 *
	 * @var string|null
	 */
	public ?string $protocol_version;

	/**
	 * The Accept header from the request.
	 *
	 * @var string|null
	 */
	public ?string $accept_header;

	/** @var string|null Origin header used only for DNS-rebinding protection. */
	public ?string $origin_header;

	/**
	 * Constructor.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The original request object.
	 */
	public function __construct( \WP_REST_Request $request ) {
		$this->request          = $request;
		$this->method           = $request->get_method();
		$this->session_id       = $request->get_header( 'Mcp-Session-Id' );
		$this->protocol_version = $request->get_header( 'Mcp-Protocol-Version' );
		$this->accept_header    = $request->get_header( 'accept' );
		$this->origin_header    = $request->get_header( 'origin' );
		$this->raw_body         = 'POST' === $this->method ? ( $request->get_body() ?? '' ) : '';
		$this->headers          = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$key = str_replace( '_', '-', strtolower( (string) $name ) );
			if (
				! in_array( $key, array( 'mcp-protocol-version', 'mcp-method', 'mcp-name', 'mcp-session-id' ), true )
				&& 0 !== strpos( $key, 'mcp-param-' )
			) {
				continue;
			}

			$value = is_array( $values ) ? reset( $values ) : $values;
			if ( ! is_string( $value ) ) {
				continue;
			}

			$this->headers[ $key ] = $value;
		}
	}
}
