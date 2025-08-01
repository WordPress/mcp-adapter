<?php

namespace WP\MCP;

use WP\MCP\Prompts\RegisterPrompt;
use WP\MCP\Prompts\Interfaces\PromptsInterface;

class PromptsRegistry {
	/**
	 * Register a set of prompts for an MCP server.
	 *
	 * @param array $prompt_classes Array of class names implementing PromptsInterface.
	 * @param array $server_context Optional server context (e.g., server ID, existing prompts).
	 * @return array Registered prompts.
	 */
	public static function register( array $prompt_classes, array $server_context = [] ): array {
		$registered_prompts = [];

		foreach ( $prompt_classes as $prompt_class ) {
			if ( ! is_subclass_of( $prompt_class, PromptsInterface::class ) ) {
				// Optionally log or warn here.
				continue;
			}

			$prompts = RegisterPrompt::create_prompts( $prompt_class, null, $server_context );

			foreach ( $prompts as $name => $prompt ) {
				$registered_prompts[ $name ] = $prompt;
			}
		}

		return $registered_prompts;
	}
}
