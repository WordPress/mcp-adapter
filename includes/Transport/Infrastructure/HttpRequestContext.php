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
	 * The associative request representation derived from the identity-preserving body.
	 *
	 * Non-array JSON roots leave this property null.
	 *
	 * @var array|null
	 */
	public ?array $body;

	/**
	 * The exact raw request body bytes.
	 *
	 * @since n.e.x.t
	 *
	 * @var string|null
	 */
	public ?string $raw_body;

	/**
	 * The decoded body with JSON object/list identity preserved.
	 *
	 * JSON objects are stdClass instances and JSON arrays are PHP lists.
	 *
	 * @since n.e.x.t
	 *
	 * @var mixed
	 */
	public $identity_body;

	/**
	 * Whether the POST body failed JSON parsing.
	 *
	 * @since n.e.x.t
	 *
	 * @var bool
	 */
	public bool $body_has_parse_error;

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
		$this->request              = $request;
		$this->method               = $request->get_method();
		$this->session_id           = $request->get_header( 'Mcp-Session-Id' );
		$this->protocol_version     = $request->get_header( 'Mcp-Protocol-Version' );
		$this->accept_header        = $request->get_header( 'accept' );
		$this->raw_body             = null;
		$this->identity_body        = null;
		$this->body                 = null;
		$this->body_has_parse_error = false;

		if ( 'POST' !== $this->method ) {
			return;
		}

		$this->raw_body = (string) $request->get_body();

		try {
			$this->identity_body = JsonRpcRequestDecoder::decode( $this->raw_body );
		} catch ( \JsonException $exception ) {
			$this->body_has_parse_error = true;

			return;
		}

		$associative = JsonRpcRequestDecoder::to_associative( $this->identity_body );
		$this->body  = is_array( $associative ) ? $associative : null;
	}
}
