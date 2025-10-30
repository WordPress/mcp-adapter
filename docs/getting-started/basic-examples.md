# Basic Examples

This guide provides simple, working examples for creating MCP tools, resources, and prompts using the WordPress MCP Adapter.

## Example 1: Tool - Create Post

Tools execute actions and return results. Here's a simple post creation tool:

```php
<?php
// Register the ability
add_action( 'wp_abilities_api_init', function() {
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
        },
        'meta' => [
            'annotations' => [
                'priority' => 2.0,
                'readOnlyHint' => false,
                'destructiveHint' => false
            ],
            'mcp' => [
                'public' => true  // Expose this ability via MCP
            ]
        ]
    ]);
});
```

The ability is automatically available via the default MCP server at `/wp-json/mcp/mcp-adapter-default-server`.

### Testing the Tool

```bash
# Create a draft post using WP-CLI
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"my-plugin-create-post","arguments":{"title":"My First MCP Post","content":"This post was created using MCP!","status":"draft"}}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

## Example 2: Resource - Site Configuration

Resources provide access to data. They require a `uri` in the ability meta:

```php
<?php
// Register the ability as a resource
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/site-config', [
        'label' => 'Site Configuration',
        'description' => 'WordPress site configuration and settings',
        'execute_callback' => function() {
            return [
                'site_name' => get_bloginfo( 'name' ),
                'site_url' => get_site_url(),
                'admin_email' => get_option( 'admin_email' ),
                'timezone' => get_option( 'timezone_string' ),
                'date_format' => get_option( 'date_format' ),
                'wordpress_version' => get_bloginfo( 'version' )
            ];
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
        'meta' => [
            'uri' => 'wordpress://site/config',  // Required for resources
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
                'audience' => ['user', 'assistant'],
                'priority' => 0.8
            ],
            'mcp' => [
                'public' => true,      // Expose this ability via MCP
                'type'   => 'resource' // Mark as resource for auto-discovery
            ]
        ]
    ]);
});
```

The ability is automatically available via the default MCP server.

### Testing the Resource

```bash
# Read the site configuration resource
echo '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"wordpress://site/config"}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# List all available resources
echo '{"jsonrpc":"2.0","id":1,"method":"resources/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

## Example 3: Prompt - Code Review

Prompts generate structured messages for language models:

```php
<?php
// Register the ability as a prompt
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/code-review', [
        'label' => 'Code Review Prompt',
        'description' => 'Generate a code review prompt with specific focus areas',
        'execute_callback' => function( $input ) {
            $code = $input['code'] ?? '';
            $focus = $input['focus'] ?? ['security', 'performance'];
            
            return [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => "Please review this code focusing on: " . implode(', ', $focus) . "\n\n```\n" . $code . "\n```",
                            'annotations' => [
                                'audience' => ['assistant'],
                                'priority' => 0.9
                            ]
                        ]
                    ]
                ]
            ];
        },
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'meta' => [
            'arguments' => [
                [
                    'name' => 'code',
                    'description' => 'Code to review',
                    'required' => true
                ],
                [
                    'name' => 'focus',
                    'description' => 'Areas to focus on during review',
                    'required' => false
                ]
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true
            ],
            'mcp' => [
                'public' => true,   // Expose this ability via MCP
                'type'   => 'prompt' // Mark as prompt for auto-discovery
            ]
        ]
    ]);
});
```

The ability is automatically available via the default MCP server.

### Testing the Prompt

```bash
# Get a code review prompt
echo '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"my-plugin-code-review","arguments":{"code":"function hello() { console.log(\"world\"); }","focus":["security","performance"]}}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# List all available prompts
echo '{"jsonrpc":"2.0","id":1,"method":"prompts/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

## Key Points

### Default Server
The MCP Adapter automatically creates a default server that exposes all registered abilities:
- **Endpoint**: `/wp-json/mcp/mcp-adapter-default-server`
- **Server ID**: `mcp-adapter-default-server`
- **Automatic Registration**: All abilities become available immediately

### Component Types
- **Tools**: Execute actions (like `tools/call`)
- **Resources**: Provide data access (like `resources/read`) - require `meta.uri`
- **Prompts**: Generate messages (like `prompts/get`) - return `messages` array

### Annotations
All MCP components may include metadata in `meta.annotations`, which hint at how clients should treat them.
For full details on annotations, their semantics, and usage guidelines, see the Annotations section of the MCP schema spec: https://modelcontextprotocol.io/specification/2025-06-18/schema#annotations

### Testing
Use WP-CLI with the default server:
```bash
# List all available tools
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# List all available resources  
echo '{"jsonrpc":"2.0","id":1,"method":"resources/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# List all available prompts
echo '{"jsonrpc":"2.0","id":1,"method":"prompts/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

## Next Steps

- **[Creating Abilities](../guides/creating-abilities.md)** - Complete implementation guide
- **[Error Handling](../guides/error-handling.md)** - Custom logging and monitoring  
- **[Architecture Overview](../architecture/overview.md)** - System design

These examples provide a foundation for building MCP integrations with WordPress abilities.
