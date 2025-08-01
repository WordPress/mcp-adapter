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

	/**
	 * Create one or more transport instances for a given server.
	 *
	 * @param string|array $transport_args_or_class Transport class name(s) or config array(s).
	 * @param array $server_context Server context, must include at least 'server_id' and optionally 'existing_transports'.
	 * @return array<string, object> Array of transport instances keyed by class or alias.
	 */
	public static function create_transports( $transport_args_or_class, array $server_context ): array {
		$instances = [];

		// Normalize input to array.
		$items = is_array( $transport_args_or_class ) ? $transport_args_or_class : [ $transport_args_or_class ];

		foreach ( $items as $item ) {
			// Support both direct class names or [ key => class/config ].
			if ( is_string( $item ) ) {
				$class = $item;
				$instance = new $class( self::get_server_from_context( $server_context ) );
				$instances[ $class ] = $instance;

			} elseif ( is_array( $item ) ) {
				foreach ( $item as $key => $class ) {
					$instance = new $class( self::get_server_from_context( $server_context ) );
					$instances[ $key ] = $instance;
				}
			}
		}

		return $instances;
	}

	protected static function get_server_from_context( array $context ): Server {
		if ( isset( $context['server'] ) && $context['server'] instanceof Server ) {
			return $context['server'];
		}

		throw new \InvalidArgumentException( 'Server context must include a valid Server instance under "server".' );
	}
}
