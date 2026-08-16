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
 * The catalog is narrowed to the legacy revision once, here, which is why the
 * accessors are callable at all. The modern era has a sibling encoder behind
 * the shared interface.
 *
 * The adapter never hand-assembles wire bytes. Hydration through the schema
 * package is what produces them, and hydration validates as it goes, so
 * validation is not a separate step that can be switched off. Measured cost is
 * roughly 12 microseconds per record.
 *
 * @since n.e.x.t
 */
final class WireEncoder extends AbstractWireEncoder {

	/**
	 * The catalog, narrowed to a concrete revision.
	 *
	 * @var \WP\McpSchema\Generated\V20251125Schema
	 */
	private V20251125Schema $catalog;

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

		parent::__construct( $context, $error_handler );
		$this->catalog = $catalog;
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
}
