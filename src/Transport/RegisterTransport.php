<?php
declare(strict_types=1);

namespace WP\MCP\Transport;

use WP\MCP\Registry\Server;

class RegisterTransport {
	protected static array $registry = [];

	public static function register( string $key, string $class ): void {
		self::$registry[ $key ] = $class;
	}

	public static function get( string $key ): string {
		return self::$registry[ $key ] ?? Stdio::class;
	}
}
