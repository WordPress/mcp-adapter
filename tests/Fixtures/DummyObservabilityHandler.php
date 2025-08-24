<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

final class DummyObservabilityHandler implements McpObservabilityHandlerInterface {

	/** @var array<int, array{event:string,tags:array}> */
	public static array $events = array();
	/** @var array<int, array{metric:string,duration:float,tags:array}> */
	public static array $timings = array();


	public static function reset(): void {
		self::$events  = array();
		self::$timings = array();
	}

	public static function record_event( string $event, array $tags = array() ): void {
		self::$events[] = array(
			'event' => $event,
			'tags'  => $tags,
		);
	}

	public static function record_timing( string $metric, float $duration_ms, array $tags = array() ): void {
		self::$timings[] = array(
			'metric'   => $metric,
			'duration' => $duration_ms,
			'tags'     => $tags,
		);
	}
}
