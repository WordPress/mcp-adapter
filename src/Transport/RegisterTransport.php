<?php
declare(strict_types=1);

namespace WP\MCP\Transport;

class RegisterTransport {
	protected static array $registry = [];

	public static function register( string $key, string $class ): void {
		self::$registry[ $key ] = $class;
	}

	public static function get( string $key ): string {
		return self::$registry[ $key ] ?? Stdio::class;
	}
}
