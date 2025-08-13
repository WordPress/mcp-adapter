<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

final class DummyObservabilityHandler implements McpObservabilityHandlerInterface
{
    /** @var array<int, array{event:string,tags:array}> */
    public static array $events = [];
    /** @var array<int, array{metric:string,duration:float,tags:array}> */
    public static array $timings = [];


    public static function reset(): void
    {
        self::$events = [];
        self::$timings = [];
    }

    public static function record_event(string $event, array $tags = []): void
    {
        self::$events[] = [
            'event' => $event,
            'tags' => $tags,
        ];
    }

    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void
    {
        self::$timings[] = [
            'metric' => $metric,
            'duration' => $duration_ms,
            'tags' => $tags,
        ];
    }


}
