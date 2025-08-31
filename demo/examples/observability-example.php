<?php
/**
 * Example of custom observability for MCP operations.
 *
 * @package MCP\Demo
 */

// Example: Simple custom observability handler
/*
class SimpleMcpObservabilityHandler implements \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface {
    
    public static function record_event( string $event_name, array $context = array() ): void {
        error_log( "MCP Event: {$event_name} - " . wp_json_encode( $context ) );
    }
    
    public static function record_timing( string $metric_name, float $duration, array $tags = array() ): void {
        error_log( "MCP Timing: {$metric_name} took {$duration}ms - " . wp_json_encode( $tags ) );
    }
}

// Example: How to use custom observability with servers (not active)
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'monitored-server',
        'mcp',
        'monitored',
        'Monitored Server',
        'Server with custom observability tracking',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        SimpleMcpObservabilityHandler::class, // custom observability
        array(),
        array(),
        array()
    );
} );
*/