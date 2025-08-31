<?php
/**
 * Example of a monitoring transport wrapper for detailed request/response logging.
 *
 * @package McpAdapter
 */

/**
 * Custom transport that wraps RestTransport with monitoring
 */
class MonitoredRestTransport extends \WP\MCP\Transport\Http\RestTransport {
    
    /**
     * Override the handle method to add monitoring
     */
    public function handle( \WP\MCP\Transport\Infrastructure\McpTransportContext $context ): \WP_REST_Response {
        $start_time = microtime( true );
        
        // Log incoming request
        $this->log_request( $context );
        
        // Call the parent handler
        $response = parent::handle( $context );
        
        // Log outgoing response
        $this->log_response( $context, $response, microtime( true ) - $start_time );
        
        return $response;
    }
    
    /**
     * Log incoming MCP request
     */
    private function log_request( \WP\MCP\Transport\Infrastructure\McpTransportContext $context ): void {
        $request_data = array(
            'method' => $context->get_request_method(),
            'endpoint' => $context->get_endpoint(),
            'body' => $context->get_request_body(),
            'headers' => $context->get_request_headers(),
            'user_id' => get_current_user_id(),
            'timestamp' => current_time( 'mysql' ),
            'request_id' => wp_generate_uuid4(),
        );
        
        // Store request for correlation with response
        wp_cache_set( 'mcp_request_' . $request_data['request_id'], $request_data, 'mcp_monitoring', 300 );
        
        // Log detailed request info
        error_log( 'MCP Request: ' . wp_json_encode( $request_data ) );
        
        // Track request metrics
        $this->track_metric( 'mcp.request.received', 1, array(
            'method' => $request_data['method'],
            'endpoint' => $request_data['endpoint'],
        ) );
    }
    
    /**
     * Log outgoing MCP response
     */
    private function log_response( \WP\MCP\Transport\Infrastructure\McpTransportContext $context, \WP_REST_Response $response, float $execution_time ): void {
        $response_data = array(
            'status_code' => $response->get_status(),
            'response_size' => strlen( wp_json_encode( $response->get_data() ) ),
            'execution_time' => $execution_time,
            'timestamp' => current_time( 'mysql' ),
        );
        
        // Log response info
        error_log( 'MCP Response: ' . wp_json_encode( $response_data ) );
        
        // Track performance metrics
        $this->track_metric( 'mcp.request.duration', $execution_time, array(
            'status' => $response->get_status(),
        ) );
        
        $this->track_metric( 'mcp.response.size', $response_data['response_size'], array(
            'status' => $response->get_status(),
        ) );
        
        // Track error rates
        if ( $response->get_status() >= 400 ) {
            $this->track_metric( 'mcp.request.error', 1, array(
                'status_code' => $response->get_status(),
            ) );
        }
    }
    
    /**
     * Track custom metrics (integrate with your monitoring system)
     */
    private function track_metric( string $metric_name, $value, array $tags = array() ): void {
        // Example: Send to StatsD, DataDog, or other monitoring service
        
        // Store in WordPress options for simple analytics
        $metrics = get_option( 'mcp_metrics', array() );
        $key = $metric_name . '_' . date( 'Y-m-d-H' ); // Hourly buckets
        
        if ( ! isset( $metrics[ $key ] ) ) {
            $metrics[ $key ] = array( 'count' => 0, 'sum' => 0, 'tags' => $tags );
        }
        
        $metrics[ $key ]['count']++;
        $metrics[ $key ]['sum'] += $value;
        
        // Keep only last 7 days of metrics
        $cutoff = date( 'Y-m-d-H', strtotime( '-7 days' ) );
        $metrics = array_filter( $metrics, function( $key ) use ( $cutoff ) {
            $timestamp = substr( $key, strrpos( $key, '_' ) + 1 );
            return $timestamp >= $cutoff;
        }, ARRAY_FILTER_USE_KEY );
        
        update_option( 'mcp_metrics', $metrics );
    }
}

// Use the monitored transport in your server
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'monitored-server',
        'mcp',
        'monitored',
        'Fully Monitored Server',
        'Server with complete request/response monitoring',
        '1.0.0',
        array(
            MonitoredRestTransport::class, // Use custom monitored transport
        ),
        null,
        null,
        array( 'mcp-adapter/get-site-info' ),
        array(),
        array()
    );
} );