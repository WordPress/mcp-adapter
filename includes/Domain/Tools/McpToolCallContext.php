<?php
/**
 * Request-local input supplied to direct tool callbacks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Core\McpRequestContext;
use WP\McpSchema\Schemas;

/**
 * Keeps untrusted client input separate from ordinary tool arguments.
 *
 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
	 */
	public function revision(): string {
		return $this->request->revision();
	}

	/**
	 * Get this request's declared client capabilities.
	 *
	 * @since 0.7.0
	 */
	public function client_capabilities(): \stdClass {
		return $this->request->client_capabilities();
	}

	/**
	 * Whether this tool call can request elicitation in the given mode.
	 *
	 * Requires the Adapter's MRTR revision and the client's declared support.
	 * An empty elicitation capability object declares form support only.
	 *
	 * @param string $mode Elicitation mode: 'form' or 'url'. Other values return false.
	 *
	 * @since 0.7.0
	 */
	public function client_supports_elicitation( string $mode = 'form' ): bool {
		if ( Schemas::V2026_07_28 !== $this->revision() || ! in_array( $mode, array( 'form', 'url' ), true ) ) {
			return false;
		}

		$elicitation = $this->client_capabilities()->elicitation ?? null;
		return $elicitation instanceof \stdClass
			&& ( isset( $elicitation->{$mode} ) || ( 'form' === $mode && array() === get_object_vars( $elicitation ) ) );
	}

	/**
	 * Get only this request's schema-valid answers; the author must validate their meaning.
	 *
	 * @throws \JsonException If the stored responses cannot be decoded.
	 *
	 * @since 0.7.0
	 */
	public function input_responses(): \stdClass {
		return json_decode( $this->responses, false, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Get the opaque client-supplied state. Verify it before trusting it.
	 *
	 * @since 0.7.0
	 */
	public function request_state(): ?string {
		return $this->request_state;
	}

	/**
	 * Whether the client supplied continuation fields; this is not proof of a prior request.
	 *
	 * @since 0.7.0
	 */
	public function is_continuation(): bool {
		return $this->continuation;
	}
}
