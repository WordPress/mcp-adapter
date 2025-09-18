<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

class DummyErrorHandler implements McpErrorHandlerInterface {

	/** @var array<int, array{message:string,context:array,type:string}> */
	public static array $logs = array();

	public static function reset(): void {
		self::$logs = array();
	}

	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		self::$logs[] = array(
			'message' => $message,
			'context' => $context,
			'type'    => $type,
		);
	}
}
