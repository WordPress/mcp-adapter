# Creating Abilities for MCP

This comprehensive guide covers how to create WordPress abilities specifically designed for MCP (Model Context Protocol)
integration. You'll learn advanced patterns, best practices, and optimization techniques for building robust
MCP-compatible abilities.

## Table of Contents

1. [Understanding MCP-Oriented Abilities](#understanding-mcp-oriented-abilities)
2. [Advanced Schema Design](#advanced-schema-design)
3. [Error Handling Strategies](#error-handling-strategies)
4. [Permission and Security](#permission-and-security)
5. [Performance Optimization](#performance-optimization)
6. [Real-World Examples](#real-world-examples)

## Understanding MCP-Oriented Abilities

When designing abilities for MCP, consider how AI agents will interact with your functionality:

### AI-Friendly Design Principles

1. **Clear, Descriptive Schemas**: AI agents rely on schema descriptions to understand functionality
2. **Predictable Input/Output**: Consistent patterns help agents learn your API
3. **Comprehensive Error Messages**: Clear error responses help agents correct their requests
4. **Granular Permissions**: Fine-grained access control for security

### Example: AI-Optimized Content Analysis

```php
<?php
add_action( 'abilities_api_init', function() {
    wp_register_ability( 'content-analyzer/analyze-post', [
        'label' => 'Analyze Post Content',
        'description' => 'Performs comprehensive content analysis including readability, SEO, and engagement metrics. Returns actionable insights for content optimization.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'WordPress post ID to analyze',
                    'minimum' => 1
                ],
                'analysis_types' => [
                    'type' => 'array',
                    'description' => 'Types of analysis to perform',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['readability', 'seo', 'engagement', 'structure', 'keywords']
                    ],
                    'default' => ['readability', 'seo'],
                    'uniqueItems' => true
                ],
                'target_audience' => [
                    'type' => 'string',
                    'description' => 'Target audience reading level',
                    'enum' => ['general', 'academic', 'technical', 'beginner'],
                    'default' => 'general'
                ]
            ],
            'required' => ['post_id'],
            'additionalProperties' => false
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'post_info' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'word_count' => ['type' => 'integer'],
                        'last_modified' => ['type' => 'string', 'format' => 'date-time']
                    ]
                ],
                'analysis_results' => [
                    'type' => 'object',
                    'properties' => [
                        'readability' => [
                            'type' => 'object',
                            'properties' => [
                                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                                'grade_level' => ['type' => 'string'],
                                'reading_time_minutes' => ['type' => 'number'],
                                'recommendations' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string']
                                ]
                            ]
                        ],
                        'seo' => [
                            'type' => 'object',
                            'properties' => [
                                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                                'title_optimization' => ['type' => 'string'],
                                'meta_description' => ['type' => 'string'],
                                'keyword_density' => ['type' => 'object'],
                                'recommendations' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string']
                                ]
                            ]
                        ]
                    ]
                },
                'overall_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'priority_actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'estimated_impact' => ['type' => 'string']
                        ]
                    ]
                ]
            ],
            'required' => ['post_info', 'analysis_results', 'overall_score']
        ],
        'execute_callback' => function( $input ) {
            // Implementation details follow...
            return perform_content_analysis( $input );
        },
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        }
    ]);
});
```

## Advanced Schema Design

### Conditional Schemas

Use conditional schemas for complex input validation:

```php
'input_schema' => [
    'type' => 'object',
    'properties' => [
        'operation_type' => [
            'type' => 'string',
            'enum' => ['create', 'update', 'delete'],
            'description' => 'Type of operation to perform'
        ],
        'post_data' => [
            'type' => 'object',
            'description' => 'Post data (required for create/update operations)'
        ],
        'post_id' => [
            'type' => 'integer',
            'description' => 'Post ID (required for update/delete operations)'
        ]
    ],
    'required' => ['operation_type'],
    'if' => [
        'properties' => ['operation_type' => ['const' => 'create']]
    ],
    'then' => [
        'required' => ['post_data']
    ],
    'else' => [
        'if' => [
            'properties' => ['operation_type' => ['enum' => ['update', 'delete']]]
        ],
        'then' => [
            'required' => ['post_id']
        ]
    ]
]
```

### Reusable Schema Components

Define reusable schema components for consistency:

```php
class ContentSchemas {
    public static function post_data_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 200,
                    'description' => 'Post title'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Post content (HTML allowed)'
                ],
                'excerpt' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'description' => 'Post excerpt'
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'publish', 'private'],
                    'default' => 'draft'
                ],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Category names'
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Tag names'
                ]
            ],
            'required' => ['title', 'content']
        ];
    }
    
    public static function pagination_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'default' => 1,
                    'description' => 'Page number'
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 10,
                    'description' => 'Items per page'
                ]
            ]
        ];
    }
}

// Use in ability registration
'input_schema' => [
    'type' => 'object',
    'properties' => array_merge(
        ContentSchemas::post_data_schema()['properties'],
        ContentSchemas::pagination_schema()['properties']
    )
]
```

## Error Handling Strategies

### Structured Error Responses

Create consistent error responses that AI agents can understand:

```php
class McpAbilityException extends Exception {
    private $error_code;
    private $error_data;
    
    public function __construct( $message, $code = 'ability_error', $data = [], $previous = null ) {
        parent::__construct( $message, 0, $previous );
        $this->error_code = $code;
        $this->error_data = $data;
    }
    
    public function get_error_code() {
        return $this->error_code;
    }
    
    public function get_error_data() {
        return $this->error_data;
    }
    
    public function to_array() {
        return [
            'error' => true,
            'code' => $this->error_code,
            'message' => $this->getMessage(),
            'data' => $this->error_data
        ];
    }
}

// Use in abilities
'execute_callback' => function( $input ) {
    try {
        // Validate business logic
        if ( empty( $input['title'] ) ) {
            throw new McpAbilityException(
                'Post title cannot be empty',
                'invalid_title',
                ['field' => 'title', 'provided_value' => $input['title'] ?? null]
            );
        }
        
        // Attempt operation
        $post_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $post_id ) ) {
            throw new McpAbilityException(
                'Failed to create post: ' . $post_id->get_error_message(),
                'wordpress_error',
                ['wp_error_code' => $post_id->get_error_code()]
            );
        }
        
        return ['post_id' => $post_id, 'success' => true];
        
    } catch ( McpAbilityException $e ) {
        // Re-throw MCP exceptions
        throw $e;
    } catch ( Exception $e ) {
        // Convert unexpected errors
        error_log( 'Unexpected error in ability: ' . $e->getMessage() );
        throw new McpAbilityException(
            'An unexpected error occurred',
            'internal_error',
            ['original_message' => $e->getMessage()]
        );
    }
}
```

### Input Validation Helpers

Create reusable validation functions:

```php
class AbilityValidators {
    public static function validate_post_id( $post_id ) {
        if ( ! is_numeric( $post_id ) || $post_id <= 0 ) {
            throw new McpAbilityException(
                'Invalid post ID provided',
                'invalid_post_id',
                ['provided_id' => $post_id]
            );
        }
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            throw new McpAbilityException(
                'Post not found',
                'post_not_found',
                ['post_id' => $post_id]
            );
        }
        
        return $post;
    }
    
    public static function validate_user_permissions( $capability, $object_id = null ) {
        if ( ! current_user_can( $capability, $object_id ) ) {
            throw new McpAbilityException(
                'Insufficient permissions',
                'permission_denied',
                [
                    'required_capability' => $capability,
                    'object_id' => $object_id,
                    'current_user' => get_current_user_id()
                ]
            );
        }
    }
    
    public static function sanitize_and_validate_content( $content, $max_length = null ) {
        if ( ! is_string( $content ) ) {
            throw new McpAbilityException(
                'Content must be a string',
                'invalid_content_type',
                ['provided_type' => gettype( $content )]
            );
        }
        
        $sanitized = wp_kses_post( $content );
        
        if ( $max_length && strlen( $sanitized ) > $max_length ) {
            throw new McpAbilityException(
                'Content exceeds maximum length',
                'content_too_long',
                ['max_length' => $max_length, 'provided_length' => strlen( $sanitized )]
            );
        }
        
        return $sanitized;
    }
}
```

## Permission and Security

> **💡 Two-Layer Security**: Abilities have their own permissions (fine-grained), but [transport permissions](transport-permissions.md) act as a gatekeeper for the entire server. If transport blocks a user, they can't access ANY abilities regardless of individual ability permissions.

### Role-Based Access Control

Implement sophisticated permission checking:

```php
class McpPermissions {
    public static function can_manage_content( $post_id = null ) {
        // Basic capability check
        if ( ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        
        // If checking specific post
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return false;
            }
            
            // Check if user can edit this specific post
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return false;
            }
            
            // Additional business logic
            if ( $post->post_status === 'publish' && ! current_user_can( 'edit_published_posts' ) ) {
                return false;
            }
        }
        
        return true;
    }
    
    public static function can_access_analytics() {
        // Multiple permission paths
        return current_user_can( 'manage_options' ) || 
               current_user_can( 'view_analytics' ) ||
               user_can( get_current_user_id(), 'subscriber' ) && self::is_content_author();
    }
    
    private static function is_content_author() {
        $user_id = get_current_user_id();
        $post_count = count_user_posts( $user_id, 'post', true );
        return $post_count > 0;
    }
}

// Use in abilities
'permission_callback' => function() {
    return McpPermissions::can_manage_content();
}
```

### Rate Limiting

Implement rate limiting for resource-intensive operations:

```php
class McpRateLimiter {
    private static $cache_group = 'mcp_rate_limits';
    
    public static function check_rate_limit( $ability_name, $max_requests = 60, $window_seconds = 3600 ) {
        $user_id = get_current_user_id();
        $cache_key = "rate_limit_{$ability_name}_{$user_id}";
        
        $current_count = wp_cache_get( $cache_key, self::$cache_group );
        
        if ( $current_count === false ) {
            wp_cache_set( $cache_key, 1, self::$cache_group, $window_seconds );
            return true;
        }
        
        if ( $current_count >= $max_requests ) {
            throw new McpAbilityException(
                'Rate limit exceeded',
                'rate_limit_exceeded',
                [
                    'max_requests' => $max_requests,
                    'window_seconds' => $window_seconds,
                    'current_count' => $current_count
                ]
            );
        }
        
        wp_cache_set( $cache_key, $current_count + 1, self::$cache_group, $window_seconds );
        return true;
    }
}

// Use in execute callback
'execute_callback' => function( $input ) {
    McpRateLimiter::check_rate_limit( 'expensive-operation', 10, 3600 );
    // ... rest of implementation
}
```

## Performance Optimization

### Caching Strategies

Implement intelligent caching for expensive operations:

```php
class AbilityCaching {
    public static function get_cached_result( $cache_key, $callback, $expiration = 3600 ) {
        $cached = wp_cache_get( $cache_key, 'mcp_abilities' );
        
        if ( $cached !== false ) {
            return $cached;
        }
        
        $result = $callback();
        wp_cache_set( $cache_key, $result, 'mcp_abilities', $expiration );
        
        return $result;
    }
    
    public static function invalidate_cache_pattern( $pattern ) {
        // Implementation depends on your caching setup
        // For Redis: use SCAN with pattern
        // For Memcached: keep a registry of keys
    }
}

// Example usage
'execute_callback' => function( $input ) {
    $cache_key = 'post_analysis_' . $input['post_id'] . '_' . md5( serialize( $input ) );
    
    return AbilityCaching::get_cached_result( $cache_key, function() use ( $input ) {
        return perform_expensive_analysis( $input );
    }, 1800 ); // Cache for 30 minutes
}
```

### Async Processing

For long-running tasks, implement async processing:

```php
'execute_callback' => function( $input ) {
    // For immediate response operations
    if ( $input['async'] ?? false ) {
        $job_id = wp_schedule_single_event( time(), 'mcp_async_task', [
            'ability' => 'my-plugin/long-task',
            'input' => $input,
            'user_id' => get_current_user_id()
        ]);
        
        return [
            'job_id' => $job_id,
            'status' => 'queued',
            'message' => 'Task queued for processing',
            'check_status_url' => rest_url( 'my-plugin/mcp/jobs/' . $job_id )
        ];
    }
    
    // Synchronous processing
    return perform_task( $input );
}
```

## Real-World Examples

### Advanced Content Search

```php
add_action( 'abilities_api_init', function() {
    wp_register_ability( 'content-search/semantic-search', [
        'label' => 'Semantic Content Search',
        'description' => 'Performs intelligent content search using semantic similarity, keyword matching, and relevance scoring',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query - can be keywords, phrases, or natural language',
                    'minLength' => 2,
                    'maxLength' => 500
                ],
                'search_types' => [
                    'type' => 'array',
                    'description' => 'Types of content to search',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['posts', 'pages', 'custom_posts', 'comments', 'metadata']
                    ],
                    'default' => ['posts', 'pages']
                ],
                'filters' => [
                    'type' => 'object',
                    'properties' => [
                        'date_range' => [
                            'type' => 'object',
                            'properties' => [
                                'start' => ['type' => 'string', 'format' => 'date'],
                                'end' => ['type' => 'string', 'format' => 'date']
                            ]
                        ],
                        'categories' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ],
                        'tags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ],
                        'author_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer']
                        ],
                        'post_status' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'default' => ['publish']
                        ]
                    ]
                ],
                'scoring' => [
                    'type' => 'object',
                    'properties' => [
                        'title_weight' => ['type' => 'number', 'default' => 2.0],
                        'content_weight' => ['type' => 'number', 'default' => 1.0],
                        'excerpt_weight' => ['type' => 'number', 'default' => 1.5],
                        'tag_weight' => ['type' => 'number', 'default' => 1.2],
                        'recency_boost' => ['type' => 'boolean', 'default' => true]
                    ]
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10
                ],
                'include_excerpts' => ['type' => 'boolean', 'default' => true],
                'highlight_matches' => ['type' => 'boolean', 'default' => true]
            ],
            'required' => ['query']
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'search_info' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'total_results' => ['type' => 'integer'],
                        'search_time_ms' => ['type' => 'number'],
                        'filters_applied' => ['type' => 'array']
                    ]
                ],
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'excerpt' => ['type' => 'string'],
                            'date' => ['type' => 'string', 'format' => 'date-time'],
                            'author' => ['type' => 'string'],
                            'relevance_score' => ['type' => 'number'],
                            'match_highlights' => ['type' => 'array'],
                            'categories' => ['type' => 'array'],
                            'tags' => ['type' => 'array']
                        ]
                    ]
                ],
                'facets' => [
                    'type' => 'object',
                    'description' => 'Aggregated data for filtering',
                    'properties' => [
                        'categories' => ['type' => 'object'],
                        'tags' => ['type' => 'object'],
                        'authors' => ['type' => 'object'],
                        'post_types' => ['type' => 'object']
                    ]
                ]
            ]
        ],
        'execute_callback' => function( $input ) {
            $start_time = microtime( true );
            
            // Build search query
            $search_engine = new SemanticSearchEngine( $input );
            $results = $search_engine->search();
            
            $search_time = ( microtime( true ) - $start_time ) * 1000;
            
            return [
                'search_info' => [
                    'query' => $input['query'],
                    'total_results' => count( $results ),
                    'search_time_ms' => round( $search_time, 2 ),
                    'filters_applied' => $search_engine->get_applied_filters()
                ],
                'results' => $results,
                'facets' => $search_engine->get_facets()
            ];
        },
        'permission_callback' => function() {
            return current_user_can( 'read' );
        }
    ]);
});
```

This guide provides a comprehensive foundation for creating sophisticated, AI-friendly WordPress abilities. The patterns
and examples here can be adapted for any MCP integration scenario.

## Next Steps

- **Configure [Transport Permissions](transport-permissions.md)** to control server-wide access
- **Explore [Custom Transports](custom-transports.md)** to learn about specialized communication protocols
- **Review [Error Handling](error-handling.md)** for advanced error management strategies
- **Check [Architecture Overview](../architecture/overview.md)** to understand system design
