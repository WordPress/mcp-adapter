<?php
/**
 * Example of custom observability for MCP tool usage tracking.
 *
 * @package McpAdapter
 */

// Create a custom observability handler
class CustomMcpObservabilityHandler implements \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface {
    
    /**
     * Record an event with detailed logging
     */
    public static function record_event( string $event_name, array $context = array() ): void {
        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'event' => $event_name,
            'context' => $context,
            'user_id' => get_current_user_id(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        );
        
        // Log to WordPress debug log
        error_log( 'MCP Event: ' . wp_json_encode( $log_entry ) );
        
        // Store in database for analytics
        self::store_event_in_database( $log_entry );
        
        // Send to external monitoring service
        self::send_to_monitoring_service( $log_entry );
    }
    
    /**
     * Store event in custom database table
     */
    private static function store_event_in_database( array $log_entry ): void {
        global $wpdb;
        
        // Create table if it doesn't exist
        $table_name = $wpdb->prefix . 'mcp_events';
        
        $wpdb->insert(
            $table_name,
            array(
                'timestamp' => $log_entry['timestamp'],
                'event_name' => $log_entry['event'],
                'context' => wp_json_encode( $log_entry['context'] ),
                'user_id' => $log_entry['user_id'],
                'ip_address' => $log_entry['ip_address'],
                'user_agent' => $log_entry['user_agent'],
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );
    }
    
    /**
     * Send to external monitoring service (e.g., DataDog, New Relic, etc.)
     */
    private static function send_to_monitoring_service( array $log_entry ): void {
        // Example: Send to a webhook or monitoring API
        wp_remote_post( 'https://your-monitoring-service.com/webhook', array(
            'body' => wp_json_encode( $log_entry ),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . get_option( 'monitoring_api_key' ),
            ),
        ) );
    }
}

// Register servers with custom observability
add_action( 'mcp_adapter_init', function( $adapter ) {
    // Create server with custom observability handler
    $adapter->create_server(
        'monitored-wordpress',
        'mcp',
        'monitored',
        'Monitored WordPress Server',
        'WordPress server with full observability tracking',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null, // default error handler
        CustomMcpObservabilityHandler::class, // custom observability
        array( 'mcp-adapter/get-site-info' ),
        array(),
        array()
    );
} );

// Hook into WordPress actions to track tool usage
add_action( 'init', function() {
    // Track when abilities are executed (if the hook exists)
    if ( has_action( 'wp_ability_executed' ) ) {
        add_action( 'wp_ability_executed', function( $ability_name, $result, $args, $context ) {
            // Only track MCP-related abilities
            if ( strpos( $ability_name, 'mcp_' ) === 0 || strpos( $ability_name, 'mcp-' ) === 0 ) {
                $log_data = array(
                    'ability_name' => $ability_name,
                    'input_args' => $args,
                    'output_size' => is_string( $result ) ? strlen( $result ) : count( (array) $result ),
                    'success' => ! is_wp_error( $result ),
                    'error' => is_wp_error( $result ) ? $result->get_error_message() : null,
                    'context' => $context,
                    'execution_time' => microtime( true ) - ( $context['start_time'] ?? microtime( true ) ),
                );
                
                CustomMcpObservabilityHandler::record_event( 'ability.executed', $log_data );
            }
        }, 10, 4 );
    }
} );

// Create analytics dashboard (admin page)
add_action( 'admin_menu', function() {
    add_submenu_page(
        'options-general.php',
        'MCP Analytics',
        'MCP Analytics',
        'manage_options',
        'mcp-analytics',
        function() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'mcp_events';
            
            // Get recent events
            $recent_events = $wpdb->get_results(
                "SELECT * FROM {$table_name} ORDER BY timestamp DESC LIMIT 100"
            );
            
            // Get usage statistics
            $stats = $wpdb->get_results(
                "SELECT event_name, COUNT(*) as count FROM {$table_name} 
                 WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY) 
                 GROUP BY event_name ORDER BY count DESC"
            );
            
            echo '<div class="wrap">';
            echo '<h1>MCP Tool Usage Analytics</h1>';
            
            echo '<h2>Usage Statistics (Last 7 Days)</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Event</th><th>Count</th></tr></thead>';
            foreach ( $stats as $stat ) {
                echo '<tr><td>' . esc_html( $stat->event_name ) . '</td>';
                echo '<td>' . esc_html( $stat->count ) . '</td></tr>';
            }
            echo '</table>';
            
            echo '<h2>Recent Events</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Timestamp</th><th>Event</th><th>User</th><th>Details</th></tr></thead>';
            foreach ( $recent_events as $event ) {
                $user = get_user_by( 'id', $event->user_id );
                echo '<tr>';
                echo '<td>' . esc_html( $event->timestamp ) . '</td>';
                echo '<td>' . esc_html( $event->event_name ) . '</td>';
                echo '<td>' . esc_html( $user ? $user->display_name : 'Unknown' ) . '</td>';
                echo '<td><pre>' . esc_html( $event->context ) . '</pre></td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '</div>';
        }
    );
} );

// Create the database table on plugin activation
register_activation_hook( WP_MCP_DIR . 'mcp-adapter.php', function() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mcp_events';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        event_name varchar(100) NOT NULL,
        context text,
        user_id bigint(20),
        ip_address varchar(45),
        user_agent text,
        PRIMARY KEY (id),
        KEY event_name (event_name),
        KEY timestamp (timestamp),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
} );