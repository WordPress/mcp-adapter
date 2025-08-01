<?php
// phpcs:ignore
declare(strict_types=1);

namespace WP\MCP\Utils;

/**
 * Utility to substitute arguments in prompt message templates.
 */
class HandlePromptGet {

	/**
	 * Process and return a prompt with arguments substituted into message content.
	 *
	 * @param array $prompt    The prompt definition array (should contain 'description').
	 * @param array $messages  The message array (each item with 'content' and 'text').
	 * @param array $arguments Associative array of argument values to substitute.
	 *
	 * @return array Returns a new array with updated messages and original description.
	 */
	public static function run( array $prompt, array $messages, array $arguments ): array {
		foreach ( $messages as $message_key => $message ) {
			if (
				isset( $message['content']['type'], $message['content']['text'] ) &&
				'text' === $message['content']['type']
			) {
				foreach ( $arguments as $argument_key => $argument_value ) {
					$messages[ $message_key ]['content']['text'] = str_replace(
						'{{' . $argument_key . '}}',
						(string) $argument_value,
						$messages[ $message_key ]['content']['text']
					);
				}
			}
		}

		return [
			'description' => $prompt['description'] ?? '',
			'messages'    => $messages,
		];
	}
}
