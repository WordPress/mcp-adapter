<?php
/**
 * Revision-independent result of one tool execution.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

/**
 * Carries a classified tool result before protocol-specific DTO encoding.
 *
 * Content blocks remain plain arrays so each protocol codec can hydrate its
 * own exact-revision DTO tree at the transport serialization boundary.
 *
 * @internal
 * @since n.e.x.t
 */
final class ToolCallOutcome {

	/**
	 * Wire-ready content blocks.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $content;

	/**
	 * Structured tool result.
	 *
	 * @var mixed
	 */
	private $structured_content;

	/**
	 * Whether structured content was supplied.
	 *
	 * This distinguishes an omitted field from an explicit JSON null value.
	 *
	 * @var bool
	 */
	private bool $has_structured_content;

	/**
	 * Whether tool execution failed.
	 *
	 * @var bool
	 */
	private bool $is_error;

	/**
	 * Create a classified tool-call outcome.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<int, array<string, mixed>> $content Content blocks.
	 * @param mixed                            $structured_content Structured content.
	 * @param bool                             $has_structured_content Whether structured content is present.
	 * @param bool                             $is_error Whether execution failed.
	 */
	private function __construct( array $content, $structured_content, bool $has_structured_content, bool $is_error ) {
		$this->content                = $content;
		$this->structured_content     = $structured_content;
		$this->has_structured_content = $has_structured_content;
		$this->is_error               = $is_error;
	}

	/**
	 * Create a completed tool-call outcome.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<int, array<string, mixed>> $content Content blocks.
	 * @param mixed                            $structured_content Structured content.
	 * @param bool                             $has_structured_content Whether structured content is present.
	 */
	public static function complete( array $content, $structured_content = null, bool $has_structured_content = false ): self {
		return new self( $content, $structured_content, $has_structured_content, false );
	}

	/**
	 * Create a completed tool execution error.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $message Client-visible failure message.
	 */
	public static function error( string $message ): self {
		return new self(
			array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			null,
			false,
			true
		);
	}

	/**
	 * Get wire-ready content blocks.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_content(): array {
		return $this->content;
	}

	/**
	 * Get structured content.
	 *
	 * @since n.e.x.t
	 *
	 * @return mixed
	 */
	public function get_structured_content() {
		return $this->structured_content;
	}

	/**
	 * Whether structured content is present.
	 *
	 * @since n.e.x.t
	 */
	public function has_structured_content(): bool {
		return $this->has_structured_content;
	}

	/**
	 * Whether tool execution failed.
	 *
	 * @since n.e.x.t
	 */
	public function is_error(): bool {
		return $this->is_error;
	}

	/**
	 * Get a client-visible text failure reason, when available.
	 *
	 * @since n.e.x.t
	 */
	public function get_failure_reason(): ?string {
		$first_block = $this->content[0] ?? null;
		if ( ! is_array( $first_block ) || 'text' !== ( $first_block['type'] ?? null ) || ! isset( $first_block['text'] ) || ! is_string( $first_block['text'] ) ) {
			return null;
		}

		return $first_block['text'];
	}
}
