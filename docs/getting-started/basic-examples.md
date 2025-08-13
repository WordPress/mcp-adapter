# Basic Examples

This guide provides simple, working examples to help you understand how to create different types of MCP components
using the WordPress MCP Adapter.

## Example 1: Simple Tool - Post Creator

Let's create a tool that allows AI agents to create WordPress posts:

```php
<?php
// Register the ability
add_action( 'abilities_api_init', function() {
    wp_register_ability( 'my-plugin/create-post', [
        'label' => 'Create Post',
        'description' => 'Creates a new WordPress post with the specified content',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'The post title',
                    'minLength' => 1,
                    'maxLength' => 200
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The post content (HTML allowed)',
                    'minLength' => 1
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Post status',
                    'enum' => ['draft', 'publish'],
                    'default' => 'draft'
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category name (optional)'
                ]
            ],
            'required' => ['title', 'content']
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the created post'
                ],
                'post_url' => [
                    'type' => 'string',
                    'description' => 'The URL of the created post'
                ],
                'edit_url' => [
                    'type' => 'string',
                    'description' => 'The admin edit URL'
                ]
            ]
        ],
        'execute_callback' => function( $input ) {
            $post_data = [
                'post_title'   => sanitize_text_field( $input['title'] ),
                'post_content' => wp_kses_post( $input['content'] ),
                'post_status'  => in_array( $input['status'], ['draft', 'publish'] ) ? $input['status'] : 'draft',
                'post_type'    => 'post'
            ];
            
            // Handle category if provided
            if ( ! empty( $input['category'] ) ) {
                $category = get_category_by_slug( sanitize_title( $input['category'] ) );
                if ( ! $category ) {
                    // Create category if it doesn't exist
                    $category_id = wp_create_category( $input['category'] );
                } else {
                    $category_id = $category->term_id;
                }
                $post_data['post_category'] = [ $category_id ];
            }
            
            $post_id = wp_insert_post( $post_data );
            
            if ( is_wp_error( $post_id ) ) {
                throw new Exception( 'Failed to create post: ' . $post_id->get_error_message() );
            }
            
            return [
                'post_id' => $post_id,
                'post_url' => get_permalink( $post_id ),
                'edit_url' => get_edit_post_link( $post_id, 'raw' )
            ];
        },
        'permission_callback' => function() {
            return current_user_can( 'publish_posts' );
        }
    ]);
});

// Set up the MCP server
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'content-manager',
        'my-plugin',
        'mcp',
        'Content Management Server',
        'MCP server for content creation and management',
        '1.0.0',
        [ \WP\MCP\Transport\Http\RestTransport::class ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        [ 'my-plugin/create-post' ] // Expose as tool
    );
});
```

### Testing the Tool

```bash
# Create a draft post
curl -X POST "https://yoursite.com/wp-json/my-plugin/mcp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "method": "tools/call",
    "params": {
      "name": "my-plugin--create-post",
      "arguments": {
        "title": "My First AI-Created Post",
        "content": "<p>This post was created by an AI agent using MCP!</p>",
        "status": "draft",
        "category": "AI Content"
      }
    }
  }'
```

## Example 2: Resource - Site Statistics

Resources provide data access without complex parameters. Here's a site statistics resource:

```php
<?php
// Register the ability as a resource
add_action( 'abilities_api_init', function() {
    wp_register_ability( 'my-plugin/site-stats', [
        'label' => 'Site Statistics',
        'description' => 'Provides comprehensive statistics about the WordPress site',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'detailed' => [
                    'type' => 'boolean',
                    'description' => 'Include detailed breakdown by post type',
                    'default' => false
                ]
            ]
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'site_info' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'wordpress_version' => ['type' => 'string']
                    ]
                ],
                'content_stats' => [
                    'type' => 'object',
                    'properties' => [
                        'total_posts' => ['type' => 'integer'],
                        'total_pages' => ['type' => 'integer'],
                        'total_comments' => ['type' => 'integer'],
                        'post_types' => [
                            'type' => 'object',
                            'description' => 'Detailed breakdown by post type (if detailed=true)'
                        ]
                    ]
                ],
                'user_stats' => [
                    'type' => 'object',
                    'properties' => [
                        'total_users' => ['type' => 'integer'],
                        'user_roles' => ['type' => 'object']
                    ]
                ]
            ]
        ],
        'execute_callback' => function( $input ) {
            $detailed = $input['detailed'] ?? false;
            
            // Site information
            $site_info = [
                'name' => get_bloginfo( 'name' ),
                'url' => get_site_url(),
                'description' => get_bloginfo( 'description' ),
                'wordpress_version' => get_bloginfo( 'version' )
            ];
            
            // Content statistics
            $content_stats = [
                'total_posts' => wp_count_posts( 'post' )->publish,
                'total_pages' => wp_count_posts( 'page' )->publish,
                'total_comments' => wp_count_comments()['approved']
            ];
            
            // Detailed post type breakdown if requested
            if ( $detailed ) {
                $post_types = get_post_types( ['public' => true], 'objects' );
                $post_type_counts = [];
                foreach ( $post_types as $post_type ) {
                    $counts = wp_count_posts( $post_type->name );
                    $post_type_counts[ $post_type->name ] = [
                        'label' => $post_type->label,
                        'published' => $counts->publish ?? 0,
                        'draft' => $counts->draft ?? 0,
                        'total' => array_sum( (array) $counts )
                    ];
                }
                $content_stats['post_types'] = $post_type_counts;
            }
            
            // User statistics
            $user_count = count_users();
            $user_stats = [
                'total_users' => $user_count['total_users'],
                'user_roles' => $user_count['avail_roles']
            ];
            
            return [
                'site_info' => $site_info,
                'content_stats' => $content_stats,
                'user_stats' => $user_stats
            ];
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        }
    ]);
});

// Set up the MCP server with the resource
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'site-info-server',
        'my-plugin',
        'info',
        'Site Information Server',
        'Provides site statistics and information',
        '1.0.0',
        [ \WP\MCP\Transport\Http\RestTransport::class ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        [], // No tools
        [ 'my-plugin/site-stats' ], // Expose as resource
        [] // No prompts
    );
});
```

### Testing the Resource

```bash
# Get basic site statistics
curl -X POST "https://yoursite.com/wp-json/my-plugin/info" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "resources/read",
    "params": {
      "uri": "my-plugin--site-stats"
    }
  }'

# Get detailed statistics
curl -X POST "https://yoursite.com/wp-json/my-plugin/info" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "resources/read",
    "params": {
      "uri": "my-plugin--site-stats?detailed=true"
    }
  }'
```

## Example 3: Prompt - SEO Recommendations

Prompts provide structured guidance for AI agents. Here's an SEO recommendation prompt:

```php
<?php
// Register the ability as a prompt
add_action( 'abilities_api_init', function() {
    wp_register_ability( 'my-plugin/seo-recommendations', [
        'label' => 'SEO Recommendations',
        'description' => 'Generates SEO recommendations based on site analysis',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'Specific post ID to analyze (optional)'
                ],
                'focus_area' => [
                    'type' => 'string',
                    'description' => 'Specific SEO area to focus on',
                    'enum' => ['content', 'technical', 'keywords', 'all'],
                    'default' => 'all'
                ]
            ]
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'analysis_summary' => ['type' => 'string'],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string'],
                            'priority' => ['type' => 'string'],
                            'recommendation' => ['type' => 'string'],
                            'implementation' => ['type' => 'string']
                        ]
                    ]
                ],
                'next_steps' => ['type' => 'string']
            ]
        ],
        'execute_callback' => function( $input ) {
            $focus_area = $input['focus_area'] ?? 'all';
            $post_id = $input['post_id'] ?? null;
            
            $recommendations = [];
            $analysis_context = '';
            
            if ( $post_id ) {
                $post = get_post( $post_id );
                if ( ! $post ) {
                    throw new Exception( 'Post not found' );
                }
                $analysis_context = "Analysis for post: \"{$post->post_title}\"";
                
                // Post-specific recommendations
                if ( in_array( $focus_area, ['content', 'all'] ) ) {
                    $content_length = str_word_count( strip_tags( $post->post_content ) );
                    if ( $content_length < 300 ) {
                        $recommendations[] = [
                            'category' => 'Content',
                            'priority' => 'High',
                            'recommendation' => 'Increase content length to at least 300 words',
                            'implementation' => 'Add more detailed information, examples, or explanations to reach the recommended word count'
                        ];
                    }
                    
                    if ( empty( get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ) ) {
                        $recommendations[] = [
                            'category' => 'Content',
                            'priority' => 'Medium',
                            'recommendation' => 'Add a meta description',
                            'implementation' => 'Write a compelling 150-160 character meta description that summarizes the post content'
                        ];
                    }
                }
            } else {
                $analysis_context = 'Site-wide SEO analysis';
                
                // Site-wide recommendations
                if ( in_array( $focus_area, ['technical', 'all'] ) ) {
                    // Check if sitemap exists
                    $sitemap_exists = wp_remote_get( get_site_url() . '/sitemap.xml' );
                    if ( is_wp_error( $sitemap_exists ) || wp_remote_retrieve_response_code( $sitemap_exists ) !== 200 ) {
                        $recommendations[] = [
                            'category' => 'Technical',
                            'priority' => 'High',
                            'recommendation' => 'Create an XML sitemap',
                            'implementation' => 'Install an SEO plugin like Yoast or RankMath to generate sitemaps automatically'
                        ];
                    }
                    
                    // Check for HTTPS
                    if ( ! is_ssl() ) {
                        $recommendations[] = [
                            'category' => 'Technical',
                            'priority' => 'High',
                            'recommendation' => 'Enable HTTPS/SSL',
                            'implementation' => 'Contact your hosting provider to install an SSL certificate and configure WordPress to use HTTPS'
                        ];
                    }
                }
                
                if ( in_array( $focus_area, ['content', 'all'] ) ) {
                    // Check for recent content
                    $recent_posts = get_posts( [
                        'numberposts' => 1,
                        'post_status' => 'publish',
                        'date_query' => [
                            'after' => '30 days ago'
                        ]
                    ] );
                    
                    if ( empty( $recent_posts ) ) {
                        $recommendations[] = [
                            'category' => 'Content',
                            'priority' => 'Medium',
                            'recommendation' => 'Publish fresh content regularly',
                            'implementation' => 'Create a content calendar and aim to publish at least one new post per month'
                        ];
                    }
                }
            }
            
            // Default recommendations if none found
            if ( empty( $recommendations ) ) {
                $recommendations[] = [
                    'category' => 'General',
                    'priority' => 'Low',
                    'recommendation' => 'SEO analysis shows good optimization',
                    'implementation' => 'Continue monitoring and maintaining current SEO practices'
                ];
            }
            
            $summary = $analysis_context . ". Found " . count( $recommendations ) . " optimization opportunities.";
            $next_steps = "Implement high-priority recommendations first, then work through medium and low priority items. Monitor search rankings and traffic after changes.";
            
            return [
                'analysis_summary' => $summary,
                'recommendations' => $recommendations,
                'next_steps' => $next_steps
            ];
        },
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        }
    ]);
});

// Set up the MCP server with the prompt
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'seo-advisor',
        'my-plugin',
        'seo',
        'SEO Advisory Server',
        'Provides SEO analysis and recommendations',
        '1.0.0',
        [ \WP\MCP\Transport\Http\RestTransport::class ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        [], // No tools
        [], // No resources
        [ 'my-plugin/seo-recommendations' ] // Expose as prompt
    );
});
```

### Testing the Prompt

```bash
# Get site-wide SEO recommendations
curl -X POST "https://yoursite.com/wp-json/my-plugin/seo" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "prompts/get",
    "params": {
      "name": "my-plugin--seo-recommendations"
    }
  }'

# Get recommendations for a specific post
curl -X POST "https://yoursite.com/wp-json/my-plugin/seo" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "prompts/get",
    "params": {
      "name": "my-plugin--seo-recommendations",
      "arguments": {
        "post_id": 123
      }
    }
  }'

# Focus on technical SEO only
curl -X POST "https://yoursite.com/wp-json/my-plugin/seo" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "prompts/get",
    "params": {
      "name": "my-plugin--seo-recommendations",
      "arguments": {
        "focus_area": "technical"
      }
    }
  }'
```

## Combining Multiple Components

You can create a single server that exposes the same ability in different ways:

```php
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'complete-server',
        'my-plugin',
        'complete',
        'Complete MCP Server',
        'Demonstrates tools, resources, and prompts together',
        '1.0.0',
        [ \WP\MCP\Transport\Http\RestTransport::class ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        [ 'my-plugin/create-post' ],              // Tools
        [ 'my-plugin/site-stats' ],               // Resources
        [ 'my-plugin/seo-recommendations' ]       // Prompts
    );
});
```

## Error Handling Examples

Add proper error handling to your abilities:

```php
'execute_callback' => function( $input ) {
    try {
        // Validate input
        if ( empty( $input['title'] ) ) {
            throw new InvalidArgumentException( 'Title is required' );
        }
        
        // Perform operation
        $result = wp_insert_post( $post_data );
        
        if ( is_wp_error( $result ) ) {
            throw new Exception( 'WordPress error: ' . $result->get_error_message() );
        }
        
        return $result;
        
    } catch ( InvalidArgumentException $e ) {
        // Client error - invalid input
        throw $e;
    } catch ( Exception $e ) {
        // Server error - log and re-throw
        error_log( 'MCP Error in ' . __FUNCTION__ . ': ' . $e->getMessage() );
        throw new Exception( 'Operation failed. Please try again.' );
    }
}
```

## Observability and Monitoring

The MCP Adapter automatically tracks metrics for all operations. You can customize observability by providing a custom handler:

```php
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'monitored-server',
        'my-plugin',
        'monitored',
        'Monitored MCP Server',
        'Server with custom observability',
        '1.0.0',
        [ \WP\MCP\Transport\Http\RestTransport::class ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        [ 'my-plugin/create-post' ],
        [],
        [],
        \WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler::class // Custom observability
    );
});
```

Metrics include request counts, execution timing, error rates, and permission events. For production environments, consider implementing a custom observability handler that integrates with your monitoring systems.

## Next Steps

- **Learn about [Advanced Abilities](../guides/creating-abilities.md)** for more complex implementations
- **Explore [Custom Transports](../guides/custom-transports.md)** for specialized communication needs
- **Check out [Creating Abilities](../guides/creating-abilities.md)** with full implementation guide
- **Read the [Architecture Guide](../architecture/overview.md)** to understand the system design

These basic examples should give you a solid foundation for building your own MCP integrations!
