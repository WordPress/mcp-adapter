# Creating Abilities for MCP

This guide covers how to create WordPress abilities for MCP (Model Context Protocol) integration, including tools, resources, and prompts.

## System Overview

WordPress abilities can be registered as different MCP components:
- **Tools**: Execute actions and return results
- **Resources**: Provide access to data or content
- **Prompts**: Generate structured messages for language models

** Full Annotation Support**: All component types support MCP annotations through the ability's `meta.annotations` field to provide behavior hints to MCP clients.

## MCP Exposure

WordPress abilities are NOT accessible via default MCP server by default. To make an ability available through the default MCP server, you must explicitly add `mcp.public: true` to the ability's metadata.

```php
'meta' => [
    'mcp' => [
        'public' => true,  // Required for MCP access
        'type'   => 'tool' // Optional: 'tool' (default), 'resource', or 'prompt'
    ],
    'annotations' => [...] // Optional MCP annotations
]
```

### MCP Type

The `type` parameter specifies how the ability should be exposed in the MCP server:
- **`tool`** (default): Exposed as a callable tool via the default server's discovery
- **`resource`**: Exposed as a resource (requires `uri` in meta)
- **`prompt`**: Exposed as a prompt (requires `arguments` in meta)

If not specified, abilities default to `type: 'tool'`.

## Basic Ability Structure

```php
wp_register_ability('my-plugin/my-ability', [
    'label' => 'My Ability',
    'description' => 'What this ability does',
    'input_schema' => [...],      // For tools
    'output_schema' => [...],     // Optional for tools
    'execute_callback' => 'my_callback',
    'permission_callback' => 'my_permission_check',
    'meta' => [
        'annotations' => [...],   // MCP annotations
        'uri' => '...',          // For resources
        'arguments' => [...],    // For prompts
        'mcp' => [
            'public' => true,    // Expose via MCP (required for MCP access)
            'type'   => 'tool',  // 'tool', 'resource', or 'prompt'
        ]
    ]
]);
```

## MCP Annotations

Annotations provide behavior hints to MCP clients about how to handle your abilities. **All component types** (Tools, Resources, and Prompts) support annotations through the `meta.annotations` field:

```php
'meta' => [
    'annotations' => [
        'priority' => 1.0,              // Execution priority (higher = more important)
        'readOnlyHint' => true,          // Component doesn't modify data
        'destructiveHint' => false,      // Component doesn't delete/destroy data
        'idempotentHint' => true,        // Same input always produces same output
        'openWorldHint' => false,        // Component works with predefined data only
    ]
]
```

### Standard MCP Annotations

**Universal Annotations** (supported by all component types):
- `priority` (float): Execution priority (default: 1.0, higher = more important)
- `readOnlyHint` (bool): Indicates read-only operations
- `destructiveHint` (bool): Warns about destructive operations  
- `idempotentHint` (bool): Same input produces same output
- `openWorldHint` (bool): Can work with arbitrary/unknown data

**Resource-Specific Annotations** (as per MCP specification):
- `audience` (array): Intended audience (`["user", "assistant"]`)
- `lastModified` (string): ISO 8601 timestamp of last modification

### Annotation Usage by Component Type

- **Tools**: Use annotations to describe tool behavior and execution characteristics
- **Resources**: Use annotations for content metadata and access patterns  
- **Prompts**: Support two types of annotations (template-level and message content-level)

### Complete Annotation Example

```php
// Tool with comprehensive annotations
wp_register_ability('my-plugin/analyze-data', [
    'label' => 'Data Analyzer',
    'description' => 'Analyze data with various algorithms',
    'input_schema' => [...],
    'execute_callback' => 'analyze_data_callback',
    'permission_callback' => function() { return current_user_can('read'); },
    'meta' => [
        'annotations' => [
            'priority' => 2.0,              // High priority
            'readOnlyHint' => true,          // Read-only operation
            'destructiveHint' => false,      // Safe operation
            'idempotentHint' => true,        // Consistent results
            'openWorldHint' => false         // Works with known data
        ]
    ]
]);

// Resource with MCP-specific annotations
wp_register_ability('my-plugin/user-data', [
    'label' => 'User Data Resource',
    'description' => 'Access to user profile data',
    'execute_callback' => 'get_user_data',
    'permission_callback' => function() { return current_user_can('read'); },
    'meta' => [
        'uri' => 'wordpress://users/profile',
        'annotations' => [
            'audience' => ['assistant'],     // For AI use only
            'priority' => 0.9,              // High importance
            'lastModified' => date('c'),     // ISO 8601 timestamp
            'readOnlyHint' => true
        ]
    ]
]);

// Prompt with behavior annotations
wp_register_ability('my-plugin/review-prompt', [
    'label' => 'Code Review Prompt',
    'description' => 'Generate structured code review prompts',
    'execute_callback' => 'generate_review_prompt',
    'permission_callback' => function() { return current_user_can('edit_posts'); },
    'meta' => [
        'arguments' => [
            ['name' => 'code', 'description' => 'Code to review', 'required' => true]
        ],
        'annotations' => [
            'priority' => 1.5,              // Above average priority
            'readOnlyHint' => true,          // Doesn't modify data
            'idempotentHint' => true,        // Consistent output
            'openWorldHint' => true          // Can handle any code
        ]
    ]
]);
```

## Creating Tools

Tools execute actions and return results:

```php
wp_register_ability('my-plugin/create-post', [
    'label' => 'Create Post',
    'description' => 'Create a new WordPress post with the given title and content',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'title' => [
                'type' => 'string',
                'description' => 'Post title'
            ],
            'content' => [
                'type' => 'string', 
                'description' => 'Post content'
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'publish'],
                'default' => 'draft'
            ]
        ],
        'required' => ['title', 'content']
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
            'status' => ['type' => 'string']
        ]
    ],
    'execute_callback' => function($input) {
        $post_id = wp_insert_post([
            'post_title' => $input['title'],
            'post_content' => $input['content'],
            'post_status' => $input['status'] ?? 'draft'
        ]);
        
        return [
            'post_id' => $post_id,
            'url' => get_permalink($post_id),
            'status' => get_post_status($post_id)
        ];
    },
    'permission_callback' => function() {
        return current_user_can('publish_posts');
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
```

## Creating Resources

Resources provide access to data or content. They require a `uri` in the meta field and should set `type: 'resource'` in the MCP configuration:

```php
wp_register_ability('my-plugin/site-config', [
    'label' => 'Site Configuration',
    'description' => 'WordPress site configuration and settings',
    'execute_callback' => function() {
        return [
            'site_name' => get_bloginfo('name'),
            'site_url' => get_site_url(),
            'admin_email' => get_option('admin_email'),
            'timezone' => get_option('timezone_string'),
            'date_format' => get_option('date_format')
        ];
    },
    'permission_callback' => function() {
        return current_user_can('manage_options');
    },
    'meta' => [
        'uri' => 'wordpress://site/config',
        'annotations' => [
            'readOnlyHint' => true,
            'idempotentHint' => true,
            'audience' => ['user', 'assistant'],
            'priority' => 0.8,
            'lastModified' => '2024-01-15T10:30:00Z'
        ],
        'mcp' => [
            'public' => true,      // Expose this ability via MCP
            'type'   => 'resource' // Mark as resource for auto-discovery
        ]
    ]
]);
```

## Creating Prompts

Prompts generate structured messages for language models. They use `input_schema` to define parameters, which are automatically converted to MCP prompt arguments format. Prompts should set `type: 'prompt'` in the MCP configuration.

### Input Schema for Prompts

Prompts use standard JSON Schema `input_schema` to define their parameters. The MCP Adapter automatically converts this to the MCP prompt `arguments` format:

```php
// Your definition (JSON Schema):
'input_schema' => [
    'type' => 'object',
    'properties' => [
        'code' => ['type' => 'string', 'description' => 'Code to review']
    ],
    'required' => ['code']
]

// Automatically converted to MCP format:
'arguments' => [
    ['name' => 'code', 'description' => 'Code to review', 'required' => true]
]
```

### Complete Prompt Example

```php
wp_register_ability('my-plugin/code-review', [
    'label' => 'Code Review Prompt',
    'description' => 'Generate a code review prompt with specific focus areas',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => [
                'type' => 'string',
                'description' => 'Code to review'
            ],
            'focus' => [
                'type' => 'array',
                'description' => 'Areas to focus on during review',
                'items' => ['type' => 'string'],
                'default' => ['security', 'performance']
            ]
        ],
        'required' => ['code']
    ],
    'execute_callback' => function($input) {
        $code = $input['code'];
        $focus = $input['focus'] ?? ['security', 'performance'];

        return [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => "Please review this code focusing on: " . implode(', ', $focus) . "\n\n```\n" . $code . "\n```"
                    ]
                ]
            ]
        ];
    },
    'permission_callback' => function() {
        return current_user_can('edit_posts');
    },
    'meta' => [
        'annotations' => [
            'readOnlyHint' => true,      // Template doesn't modify data
            'idempotentHint' => true     // Consistent prompt generation
        ],
        'mcp' => [
            'public' => true,   // Expose this ability via MCP
            'type'   => 'prompt' // Mark as prompt for auto-discovery
        ]
    ]
]);
```

### Message Content Annotations (MCP Specification)

You can also annotate the generated message content according to the [MCP specification](https://modelcontextprotocol.io/specification/2025-06-18/server/prompts#promptmessage):

```php
wp_register_ability('my-plugin/analysis-prompt', [
    'label' => 'Analysis Prompt',
    'description' => 'Generate analysis prompts with content annotations',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'data' => [
                'type' => 'string',
                'description' => 'Data to analyze'
            ]
        ],
        'required' => ['data']
    ],
    'execute_callback' => function($input) {
        $data = $input['data'] ?? '';

        return [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => "Analyze this data: " . $data,
                        'annotations' => [
                            'audience' => ['assistant'],           // For AI use only
                            'priority' => 0.9,                   // High priority content
                            'lastModified' => date('c')           // ISO 8601 timestamp
                        ]
                    ]
                ],
                [
                    'role' => 'assistant',
                    'content' => [
                        'type' => 'text',
                        'text' => "I'll analyze the provided data...",
                        'annotations' => [
                            'audience' => ['user'],              // For user display
                            'priority' => 0.7
                        ]
                    ]
                ]
            ]
        ];
    },
    'permission_callback' => function($input) {
        return current_user_can('read');
    },
    'meta' => [
        'annotations' => [
            'readOnlyHint' => true,
            'openWorldHint' => true              // Can handle any data type
        ],
        'mcp' => [
            'public' => true,   // Expose this ability via MCP
            'type'   => 'prompt' // Mark as prompt for auto-discovery
        ]
    ]
]);
```

### Prompt Annotations Summary

**Template-Level Annotations** (in `meta.annotations`):
- Apply to the prompt template itself
- Describe the prompt's behavior characteristics
- Support all standard MCP annotations (readOnlyHint, idempotentHint, etc.)

**Message Content Annotations** (in message `content.annotations`):
- Apply to individual messages within the prompt
- Provide metadata for specific message content
- Support: `audience`, `priority`, `lastModified`

### Key Points for Prompts

1. **Use `input_schema`** instead of `meta.arguments` - it provides validation and is automatically converted to MCP format
2. **Callbacks receive validated input** - the Abilities API validates against your schema
3. **Return MCP message format** - prompts must return `{ messages: [...] }` structure
4. **Set `type: 'prompt'`** in `meta.mcp` for proper auto-discovery

## Permission and Security

> **💡 Two-Layer Security**: Abilities have their own permissions (fine-grained), but [transport permissions](transport-permissions.md) act as a gatekeeper for the entire server. If transport blocks a user, they can't access ANY abilities regardless of individual ability permissions.

### Permission Callback Examples

```php
// Allow only administrators
'permission_callback' => function() {
    return current_user_can('manage_options');
}

// Allow editors and above
'permission_callback' => function() {
    return current_user_can('edit_others_posts');
}

// Custom permission check
'permission_callback' => function($input) {
    return current_user_can('edit_posts') && wp_verify_nonce($input['nonce'], 'my_action');
}
```

## Best Practices

### Schema Design
- Use clear, descriptive field names
- Provide detailed descriptions for all properties
- Define appropriate data types and constraints
- Mark required fields explicitly

### Error Handling
- Return meaningful error messages
- Use appropriate HTTP status codes
- Include context information for debugging

### Performance
- Keep tool execution lightweight
- Cache expensive operations
- Use appropriate database queries
- Consider pagination for large datasets

## Next Steps

- **Configure [Transport Permissions](transport-permissions.md)** to control server-wide access
- **Review [Error Handling](error-handling.md)** for advanced error management strategies
- **Check [Architecture Overview](../architecture/overview.md)** to understand system design