<?php
/**
 * Request-local input supplied to direct tool callbacks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Core\McpRequestContext;

/**
 * Keeps untrusted client input separate from ordinary tool arguments.
 *
 * @since n.e.x.t
 */
final class McpToolCallContext {

	/** @var \WP\MCP\Core\McpRequestContext */
	private McpRequestContext $request;

	/** @var string JSON preserves empty maps and prevents callback mutation. */
	private string $responses;

	/** @var string|null */
	private ?string $request_state;

	/** @var bool */
	private bool $continuation;

	/**
	 * @param \WP\MCP\Core\McpRequestContext $request       Per-request protocol context.
	 * @param \stdClass                      $responses     Schema-valid client answers keyed by input request identifier.
	 * @param string|null                    $request_state Untrusted client-supplied state, not verified by the Adapter.
	 * @param bool                           $continuation  Whether the client supplied any continuation field.
	 *
	 * @throws \JsonException If the responses cannot be encoded.
	 *
	 * @internal Constructed by the Adapter after protocol schema validation.
	 * @since n.e.x.t
	 */
	public function __construct( McpRequestContext $request, \stdClass $responses, ?string $request_state, bool $continuation ) {
		$this->request       = $request;
		$this->responses     = json_encode( $responses, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve strict JSON failures and exact values in client input.
		$this->request_state = $request_state;
		$this->continuation  = $continuation;
	}

	/**
	 * Get the exact request revision.
	 *
	 * @since n.e.x.t
	 */
	public function revision(): string {
		return $this->request->revision();
	}

	/**
	 * Get this request's declared client capabilities.
	 *
	 * @since n.e.x.t
	 */
	public function client_capabilities(): \stdClass {
		return $this->request->client_capabilities();
	}

	/**
	 * Get only this request's schema-valid answers; the author must validate their meaning.
	 *
	 * @throws \JsonException If the stored responses cannot be decoded.
	 *
	 * @since n.e.x.t
	 */
	public function input_responses(): \stdClass {
		return json_decode( $this->responses, false, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Get the opaque client-supplied state. Verify it before trusting it.
	 *
	 * @since n.e.x.t
	 */
	public function request_state(): ?string {
		return $this->request_state;
	}

	/**
	 * Whether the client supplied continuation fields; this is not proof of a prior request.
	 *
	 * @since n.e.x.t
	 */
	public function is_continuation(): bool {
		return $this->continuation;
	}
}
