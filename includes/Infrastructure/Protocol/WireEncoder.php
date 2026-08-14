<?php
/**
 * Encodes revision-neutral arrays into MCP wire arrays.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Protocol;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Contract\Type;
use WP\McpSchema\Generated\V20251125Schema;

/**
 * Turns adapter arrays into wire arrays through the schema catalog.
 *
 * There is one method per protocol payload the adapter emits. Each names its
 * type through the catalog's generated accessor rather than a string, so the
 * type is reachable from the call site, an unknown payload is a static error
 * instead of a runtime one, and the generated field shapes stay available to
 * static analysis.
 *
 * The catalog is narrowed to a concrete revision once, here, which is why the
 * accessors are callable at all. Only one revision is negotiable today; when a
 * second joins the negotiator this class gains a sibling per era behind a
 * shared interface, and the narrowing below becomes the era selection.
 *
 * The adapter never hand-assembles wire bytes. Hydration through the schema
 * package is what produces them, and hydration validates as it goes, so
 * validation is not a separate step that can be switched off. Measured cost is
 * roughly 12 microseconds per record.
 *
 * @since n.e.x.t
 */
final class WireEncoder {

	/**
	 * The protocol context supplying the catalog.
	 *
	 * @var \WP\MCP\Core\McpProtocolContext
	 */
	private McpProtocolContext $context;

	/**
	 * The catalog, narrowed to a concrete revision.
	 *
	 * @var \WP\McpSchema\Generated\V20251125Schema
	 */
	private V20251125Schema $catalog;

	/**
	 * Error handler used to report components that fail to encode.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 */
	private McpErrorHandlerInterface $error_handler;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpProtocolContext       $context       The protocol context.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler Handler for encode failures.
	 *
	 * @throws \InvalidArgumentException When the context carries a revision this encoder cannot emit.
	 */
	public function __construct( McpProtocolContext $context, McpErrorHandlerInterface $error_handler ) {
		$catalog = $context->catalog();

		if ( ! $catalog instanceof V20251125Schema ) {
			throw new \InvalidArgumentException(
				sprintf( 'The %s encoder cannot emit protocol revision %s.', self::class, $context->revision() )
			);
		}

		$this->context       = $context;
		$this->catalog       = $catalog;
		$this->error_handler = $error_handler;
	}

	/**
	 * The negotiated protocol revision this encoder emits for.
	 *
	 * @return string
	 */
	public function revision(): string {
		return $this->context->revision();
	}

	// =========================================================================
	// Result payloads
	// =========================================================================

	/**
	 * Encodes an initialize result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function initialize_result( array $data ): array {
		return $this->encode( $this->catalog->initializeResult(), $data );
	}

	/**
	 * Encodes a tools/list result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function list_tools_result( array $data ): array {
		return $this->encode( $this->catalog->listToolsResult(), $data );
	}

	/**
	 * Encodes a tools/call result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function call_tool_result( array $data ): array {
		return $this->encode( $this->catalog->callToolResult(), $data );
	}

	/**
	 * Encodes a resources/list result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function list_resources_result( array $data ): array {
		return $this->encode( $this->catalog->listResourcesResult(), $data );
	}

	/**
	 * Encodes a resources/templates/list result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function list_resource_templates_result( array $data ): array {
		return $this->encode( $this->catalog->listResourceTemplatesResult(), $data );
	}

	/**
	 * Encodes a resources/read result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function read_resource_result( array $data ): array {
		return $this->encode( $this->catalog->readResourceResult(), $data );
	}

	/**
	 * Encodes a prompts/list result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function list_prompts_result( array $data ): array {
		return $this->encode( $this->catalog->listPromptsResult(), $data );
	}

	/**
	 * Encodes a prompts/get result.
	 *
	 * @param array<string, mixed> $data The result payload.
	 *
	 * @return array<string, mixed> The wire array.
	 */
	public function get_prompt_result( array $data ): array {
		return $this->encode( $this->catalog->getPromptResult(), $data );
	}

	// =========================================================================
	// Components, dropped instead of thrown
	// =========================================================================

	/**
	 * Encodes one tool for a list, returning null when it does not validate.
	 *
	 * @param array<string, mixed> $data    The tool.
	 * @param string               $subject The tool name, for logs.
	 *
	 * @return array<string, mixed>|null The wire array, or null when it did not validate.
	 */
	public function try_tool( array $data, string $subject = '' ): ?array {
		return $this->try_encode( $this->catalog->tool(), $data, 'Tool', $subject );
	}

	/**
	 * Encodes one resource for a list, returning null when it does not validate.
	 *
	 * @param array<string, mixed> $data    The resource.
	 * @param string               $subject The resource URI, for logs.
	 *
	 * @return array<string, mixed>|null The wire array, or null when it did not validate.
	 */
	public function try_resource( array $data, string $subject = '' ): ?array {
		return $this->try_encode( $this->catalog->resource(), $data, 'Resource', $subject );
	}

	/**
	 * Encodes one prompt for a list, returning null when it does not validate.
	 *
	 * @param array<string, mixed> $data    The prompt.
	 * @param string               $subject The prompt name, for logs.
	 *
	 * @return array<string, mixed>|null The wire array, or null when it did not validate.
	 */
	public function try_prompt( array $data, string $subject = '' ): ?array {
		return $this->try_encode( $this->catalog->prompt(), $data, 'Prompt', $subject );
	}

	// =========================================================================
	// Internals
	// =========================================================================

	/**
	 * Hydrates an array as a protocol type and returns its wire form.
	 *
	 * Hydration goes through fromValue() rather than fromArray(). The adapter
	 * builds revision-neutral arrays, which do not satisfy the exact array
	 * shape each generated type declares, while fromValue() accepts an
	 * already-decoded JSON value, which is what these arrays are. Both run the
	 * same hydration, so the accepted input and the emitted bytes are the same.
	 *
	 * @template TWire of array<string, mixed>
	 * @template TFields of array<string, mixed>
	 *
	 * @param \WP\McpSchema\Contract\Type<TWire, TFields> $type The protocol type.
	 * @param array<string, mixed> $data The array to encode.
	 *
	 * @return array<string, mixed> The wire array, readable as nested arrays.
	 *
	 * @throws \WP\McpSchema\Runtime\ValidationException When the array does not match the type.
	 */
	private function encode( Type $type, array $data ): array {
		return $type->fromValue( $data )->toJsonArray();
	}

	/**
	 * Encodes a component, returning null instead of throwing.
	 *
	 * This is the skip-bad-component path. A single malformed component must
	 * never take down a whole list response, so callers building a list use
	 * this and drop what comes back null. The omission is logged and reported
	 * through _doing_it_wrong; MCP has no partial-result signal, so those are
	 * the only trace.
	 *
	 * @template TWire of array<string, mixed>
	 * @template TFields of array<string, mixed>
	 *
	 * @param \WP\McpSchema\Contract\Type<TWire, TFields> $type      The protocol type.
	 * @param array<string, mixed> $data      The array to encode.
	 * @param string               $type_name The type name, for logs.
	 * @param string               $subject   Component identity, for logs.
	 *
	 * @return array<string, mixed>|null The wire array, or null when it did not validate.
	 */
	private function try_encode( Type $type, array $data, string $type_name, string $subject ): ?array {
		try {
			return $this->encode( $type, $data );
		} catch ( \WP\McpSchema\Runtime\ValidationException $exception ) {
			$this->report_failure( $type_name, $subject, $exception );

			return null;
		}
	}

	/**
	 * Reports a component that failed to encode.
	 *
	 * @param string              $type_name Protocol type name.
	 * @param string              $subject   Human-readable identity for logs.
	 * @param \WP\McpSchema\Runtime\ValidationException $exception The validation failure.
	 *
	 * @return void
	 */
	private function report_failure( string $type_name, string $subject, \WP\McpSchema\Runtime\ValidationException $exception ): void {
		$label = '' === $subject ? $type_name : $type_name . ' "' . $subject . '"';

		$this->error_handler->log(
			'MCP component omitted from the response because it does not match the protocol schema.',
			array(
				'type'     => $type_name,
				'subject'  => $subject,
				'revision' => $this->context->revision(),
				'error'    => $exception->getMessage(),
			)
		);

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: 1: protocol type and component name, 2: validation error message. */
				esc_html__( '%1$s was left out of the MCP response because it does not match the protocol schema: %2$s', 'mcp-adapter' ),
				esc_html( $label ),
				esc_html( $exception->getMessage() )
			),
			'n.e.x.t'
		);
	}
}
