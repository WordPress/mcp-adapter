<?php

namespace WP\MCP\Adapter;

class AbilityToTool {

	private static function transform_ability_name( string $ability_name ): string {
		return 'ability-' . str_replace( '/', '-', $ability_name );
	}

	public static function make( string $ability_name, $tool_type = 'read', array $annotations = array() ): ?array {

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return null;
		}

		$annotations = array_merge(
			$annotations,
			array(
				'title' => $ability->get_label(),
			)
		);

		return array(
			'name'                => self::transform_ability_name( $ability_name ),
			'description'         => $ability->get_description(),
			'type'                => $tool_type,
			'inputSchema'         => $ability->get_input_schema(),
			'outputSchema'        => $ability->get_output_schema(),
			'callback'            => [ $ability, 'execute' ],
			'permission_callback' => [ $ability, 'has_permission' ],
			'annotations'         => $annotations,
		);
	}
}
