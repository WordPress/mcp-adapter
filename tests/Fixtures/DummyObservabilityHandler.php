<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

final class DummyObservabilityHandler implements McpObservabilityHandlerInterface {

	/** @var array<int, array{event:string,tags:array,duration_ms:?float}> */
	public static array $events = array();

	public static function reset(): void {
		self::$events = array();
	}

	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		self::$events[] = array(
			'event'       => $event,
			'tags'        => $tags,
			'duration_ms' => $duration_ms,
		);
	}
}
