<?php
/**
 * Contract for revision-bound MCP wire encoders.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Protocol;

/**
 * Encodes revision-neutral Adapter arrays for one exact MCP revision.
 *
 * Era-specific operations such as legacy initialize and modern discovery stay
 * on their concrete encoders. This interface contains only the operations
 * implemented by both supported revisions.
 *
 * @since n.e.x.t
 */
interface WireEncoderInterface {

	/**
	 * The exact protocol revision emitted by this encoder.
	 */
	public function revision(): string;

	/** @param array<string, mixed> $data */
	public function list_tools_result( array $data ): array;

	/** @param array<string, mixed> $data */
	public function call_tool_result( array $data ): array;

	/** @param array<string, mixed> $data */
	public function list_resources_result( array $data ): array;

	/** @param array<string, mixed> $data */
	public function list_resource_templates_result( array $data ): array;

	/** @param array<string, mixed> $data */
	public function read_resource_result( array $data ): array;

	/** @param array<string, mixed> $data */
	public function list_prompts_result( array $data ): array;

	/** @param array<string, mixed> $data */
	public function get_prompt_result( array $data ): array;

	/**
	 * @param array<string, mixed> $data    Tool data.
	 * @param string               $subject Tool identity for diagnostics.
	 *
	 * @return array<string, mixed>|null
	 */
	public function try_tool( array $data, string $subject = '' ): ?array;

	/**
	 * @param array<string, mixed> $data    Resource data.
	 * @param string               $subject Resource identity for diagnostics.
	 *
	 * @return array<string, mixed>|null
	 */
	public function try_resource( array $data, string $subject = '' ): ?array;

	/**
	 * @param array<string, mixed> $data    Prompt data.
	 * @param string               $subject Prompt identity for diagnostics.
	 *
	 * @return array<string, mixed>|null
	 */
	public function try_prompt( array $data, string $subject = '' ): ?array;
}
