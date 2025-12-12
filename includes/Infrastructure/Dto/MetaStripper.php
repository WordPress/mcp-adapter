<?php
/**
 * Strip internal mcp-adapter metadata from schema DTO arrays.
 *
 * @package McpAdapter
 */
declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Dto;

use WP\McpSchema\Common\AbstractDataTransferObject;

/**
 * MetaStripper.
 *
 * Removes `_meta['mcp_adapter']` from DTO output arrays so internal adapter metadata
 * is not exposed to MCP clients.
 *
 * @internal
 */
final class MetaStripper {
	/**
	 * Strip internal mcp-adapter metadata from a DTO.
	 *
	 * @param \WP\McpSchema\Common\AbstractDataTransferObject $dto DTO instance.
	 *
	 * @return array<string, mixed>
	 */
	public static function strip( AbstractDataTransferObject $dto ): array {
		return self::strip_array( $dto->toArray() );
	}

	/**
	 * Strip internal adapter metadata from a DTO array.
	 *
	 * @param array<mixed> $data DTO array.
	 *
	 * @return array<mixed>
	 */
	public static function strip_array( array $data ): array {
		if ( isset( $data['_meta'] ) && is_array( $data['_meta'] ) && array_key_exists( 'mcp_adapter', $data['_meta'] ) ) {
			unset( $data['_meta']['mcp_adapter'] );
			if ( empty( $data['_meta'] ) ) {
				unset( $data['_meta'] );
			}
		}

		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$data[ $key ] = self::strip_array( $value );
		}

		return $data;
	}
}
