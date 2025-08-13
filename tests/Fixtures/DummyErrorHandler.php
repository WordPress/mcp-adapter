<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

final class DummyErrorHandler implements McpErrorHandlerInterface
{
    /** @var array<int, array{message:string,context:array,type:string}> */
    public static array $logs = [];

    public static function reset(): void
    {
        self::$logs = [];
    }

    public function log(string $message, array $context = [], string $type = 'error'): void
    {
        self::$logs[] = [
            'message' => $message,
            'context' => $context,
            'type' => $type,
        ];
    }
}


