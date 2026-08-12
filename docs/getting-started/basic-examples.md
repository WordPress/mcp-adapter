# Basic Examples

This guide provides simple, working examples for creating MCP tools, resources, and prompts using the WordPress MCP Adapter.

> **Every ability needs a `category`.** It must be a registered category, or `wp_register_ability()` returns `null` and the ability never appears (no `WP_Error`, just a `_doing_it_wrong()` notice you'll miss unless `WP_DEBUG` is on). Core provides `site` and `user`; register custom categories on the `wp_abilities_api_categories_init` hook first. See [Ability categories](../guides/creating-abilities.md#ability-categories-required).

## Example 1: Tool - Create Post

Tools execute actions and return results. Here's a simple post creation tool:

```php
<?php
// Register the ability
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/create-post', [
        'label' => 'Create Post',
        'description' => 'Creates a new WordPress post with the specified content',
        'category' => 'site',
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
            if ( empty( $input['title'] ) ) {
                return new \WP_Error( 'missing_title', 'Post title is required.' );
            }

            $post_data = array(
                'post_title'   => sanitize_text_field( $input['title'] ),
                'post_content' => wp_kses_post( $input['content'] ),
                'post_status'  => in_array( $input['status'], array( 'draft', 'publish' ), true ) ? $input['status'] : 'draft',
                'post_type'    => 'post',
            );
            
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
            'public' => true, // Expose to clients, including MCP
            'annotations' => [
                'readonly'    => false, // Abilities API name; emitted as readOnlyHint
                'destructive' => false  // Abilities API name; emitted as destructiveHint
            ]
        ]
    ]);
});
```

The ability is automatically available via the default MCP server at `/wp-json/mcp/mcp-adapter-default-server`.

> **Note**: How far `meta.public` reaches depends on the WordPress version. WordPress core starts applying `meta.public` to the REST API (`meta.show_in_rest`) in version 7.1. On WordPress 6.9 and 7.0, the MCP Adapter honors `meta.public` for MCP exposure, but REST API access still requires setting `meta.show_in_rest` to `true`.

### Testing the Tool

```bash
# Create a draft post using WP-CLI
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"my-plugin-create-post","arguments":{"title":"My First MCP Post","content":"This post was created using MCP!","status":"draft"}}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

## Example 2: Resource - Site Configuration

Resources provide access to data. They require a `uri` under `meta.mcp`:

```php
<?php
// Register the ability as a resource
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/site-config', [
        'label' => 'Site Configuration',
        'description' => 'WordPress site configuration and settings',
        'category' => 'site',
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
            'public' => true, // Expose to clients, including MCP
            'mcp' => [
                'type' => 'resource', // Mark as resource for auto-discovery
                'uri'  => 'wordpress://site/config', // Required for resources
                'annotations' => [
                    'audience' => ['user', 'assistant'],
                    'priority' => 0.8
                ]
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
        'category' => 'site',
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
            'public' => true, // Expose to clients, including MCP
            'mcp' => [
                'type' => 'prompt', // Mark as prompt for auto-discovery
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
                ]
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
- **Resources**: Provide data access (like `resources/read`) - require `meta.mcp.uri`
- **Prompts**: Generate messages (like `prompts/get`) - return `messages` array

### Annotations
Annotations hint at how clients should treat a component, and each type reads them from a different place:

- **Tools**: `meta.annotations` — write the Abilities API names `readonly`, `destructive`, `idempotent`, which the adapter maps to `readOnlyHint`, `destructiveHint`, `idempotentHint`. `openWorldHint` and `title` have no Abilities API equivalent, so write those under their MCP names.
- **Resources**: `meta.mcp.annotations` — `audience`, `priority`, and `lastModified` for MCP 2025-06-18 or later
- **Prompts**: no descriptor annotations; annotate the message content blocks instead

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
