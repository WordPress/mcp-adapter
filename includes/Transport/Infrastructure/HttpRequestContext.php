<?php
/**
 * MCP HTTP Transport for WordPress - MCP 2025-11-25 Compliant
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
	 * The JSON-decoded body of the request.
	 *
	 * @var mixed
	 */
	public $body;

	/** Whether the POST body was valid JSON. */
	public bool $body_is_valid_json = true;

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
		$this->body             = null;
		if ( 'POST' !== $this->method ) {
			return;
		}

		$this->body = JsonRpcRequestDecoder::decode( (string) $request->get_body(), $this->body_is_valid_json );
	}
}
