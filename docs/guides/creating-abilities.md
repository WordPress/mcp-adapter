# Creating Abilities for MCP

This guide covers how to create WordPress abilities for MCP (Model Context Protocol) integration, including tools, resources, and prompts.

## System Overview

WordPress abilities can be registered as different MCP components:
- **Tools**: Execute actions and return results
- **Resources**: Provide access to data or content
- **Prompts**: Generate structured messages for language models

**Full annotation support**: All component types support MCP annotations through the ability's `meta.annotations` field to provide behavior hints to MCP clients.

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
    'input_schema' => [...],      // For tools (supports both object and flattened schemas)
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

## Tool naming

When abilities are registered on a custom server as MCP tools, the adapter must transform the ability name into an MCP-compliant tool name. The MCP specification (2025-11-25) restricts tool names to the characters `A-Za-z0-9_.-` with a maximum length of 128.

WordPress abilities commonly use namespaced names with forward slashes (e.g., `my-plugin/my-tool`), which are not valid in MCP. The adapter handles this automatically via `McpNameSanitizer::sanitize_name()`.

### Registration Name vs MCP Name

The name you pass to `wp_register_ability()` is the **registration name**. The name MCP clients see is the **MCP tool name**, produced by sanitization:

| Registration Name | MCP Tool Name |
|-------------------|---------------|
| `my-plugin/my-tool` | `my-plugin-my-tool` |
| `fluent/get-posts` | `fluent-get-posts` |
| `café/résumé-tool` | `cafe-resume-tool` |

### Sanitization Pipeline

The full sanitization pipeline applied to tool names:

1. **Trim** whitespace from both ends
2. **Replace `/` with `-`** (forward slashes are not allowed in MCP names)
3. **Early return** if the name is already valid after slash replacement
4. **Transliterate accents** to ASCII equivalents (e.g., `é` → `e`, `ü` → `u`) via WordPress `remove_accents()`
5. **Replace remaining invalid characters** with `-`
6. **Collapse consecutive hyphens** into a single `-`
7. **Trim leading/trailing** hyphens and underscores
8. **Truncate long names**: if longer than 128 characters, truncate to 115 characters and append `-` plus a 12-character MD5 hash for uniqueness
9. **Reject empty results**: if nothing remains after sanitization, return a `WP_Error`

### Customizing Tool Names

You can override the sanitized name using the `mcp_adapter_tool_name` filter. The filter receives the sanitized name and the source `WP_Ability` instance:

```php
add_filter( 'mcp_adapter_tool_name', function ( string $name, \WP_Ability $ability ): string {
    // Use a custom name for a specific ability.
    if ( 'my-plugin/legacy-tool' === $ability->get_name() ) {
        return 'my-legacy-tool';
    }
    return $name;
}, 10, 2 );
```

The filter result is validated after application — if it returns an invalid MCP name, the tool registration fails with an error.

> **Note:** This naming transformation applies to **tools created from WordPress abilities** (via `McpTool::fromAbility()`). The default server exposes abilities indirectly through its built-in meta-tools (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`), so ability names pass through as-is in that context. Prompts use the same `McpNameSanitizer` logic. Resources use URIs as identifiers and are not affected by tool name sanitization.

For advanced details, see the source: `includes/Domain/Utils/McpNameSanitizer.php`.

## Input and Output Schemas

The MCP Adapter supports two schema formats for `input_schema` and `output_schema`:

### Object Schemas (Recommended)

The standard format uses JSON Schema objects with properties:

```php
'input_schema' => [
    'type' => 'object',
    'properties' => [
        'name' => [
            'type' => 'string',
            'description' => 'User name'
        ],
        'age' => [
            'type' => 'number',
            'minimum' => 0
        ]
    ],
    'required' => ['name']
]
```

### Flattened Schemas (Simplified)

For simple single-value inputs, you can use flattened schemas. These are automatically converted to MCP-compatible object format:

```php
// Simple string input
'input_schema' => [
    'type' => 'string',
    'description' => 'Post type to query',
    'enum' => ['post', 'page', 'attachment']
]

// This is automatically transformed to:
[
    'type' => 'object',
    'properties' => [
        'input' => [
            'type' => 'string',
            'description' => 'Post type to query',
            'enum' => ['post', 'page', 'attachment']
        ]
    ],
    'required' => ['input']
]
```

#### Supported Flattened Types

All JSON Schema primitive types are supported:
- `string` - text values
- `number` - numeric values (including decimals)
- `integer` - whole numbers
- `boolean` - true/false values
- `array` - lists of values

#### Flattened Schema Examples

```php
// Number with constraints
'input_schema' => [
    'type' => 'number',
    'description' => 'Maximum number of posts',
    'minimum' => 1,
    'maximum' => 100,
    'default' => 10
]

// Boolean flag
'input_schema' => [
    'type' => 'boolean',
    'description' => 'Include draft posts'
]

// Array of strings
'input_schema' => [
    'type' => 'array',
    'description' => 'List of post IDs',
    'items' => ['type' => 'integer'],
    'minItems' => 1
]
```

### Output Schemas

Output schemas follow the same patterns as input schemas, supporting both object and flattened formats:

#### Object Output Schemas

```php
'output_schema' => [
    'type' => 'object',
    'properties' => [
        'post_id' => [
            'type' => 'integer',
            'description' => 'Created post ID'
        ],
        'url' => [
            'type' => 'string',
            'description' => 'Post permalink'
        ],
        'status' => [
            'type' => 'string',
            'description' => 'Post status'
        ]
    ]
]
```

#### Flattened Output Schemas

For simple single-value outputs, you can use flattened schemas. These are automatically converted to MCP-compatible object format using `"result"` as the wrapper property:

```php
// Simple string output
'output_schema' => [
    'type' => 'string',
    'description' => 'Generated post slug'
]

// This is automatically transformed to:
[
    'type' => 'object',
    'properties' => [
        'result' => [
            'type' => 'string',
            'description' => 'Generated post slug'
        ]
    ],
    'required' => ['result']
]
```

#### Output Schema Examples

```php
// Number output
'output_schema' => [
    'type' => 'integer',
    'description' => 'Total number of posts found'
]

// Boolean output
'output_schema' => [
    'type' => 'boolean',
    'description' => 'Whether the operation succeeded'
]

// Array output
'output_schema' => [
    'type' => 'array',
    'description' => 'List of post titles',
    'items' => ['type' => 'string']
]
```

**Important**: When using flattened output schemas, your callback should return the unwrapped value directly. The adapter automatically wraps it in `{result: <value>}` for MCP clients:

```php
// With flattened output schema: ['type' => 'string']
'execute_callback' => function($input) {
    return 'my-post-slug';  // Return unwrapped value
}

// MCP client receives: {result: 'my-post-slug'}
```

#### When to Use Each Format

**Use Object Schemas when:**
- Your ability accepts/returns multiple parameters or fields
- You need complex validation or nested structures
- You want descriptive parameter names
- Your output contains multiple related values (e.g., `{post_id, url, status}`)

**Use Flattened Schemas when:**
- Your ability accepts/returns a single, simple value
- The input/output is straightforward (e.g., a string, number, boolean, or array)
- You want to simplify the API for basic operations
- Your output is a single primitive value (e.g., a count, a slug, a boolean flag)

**Note**: All schema metadata (descriptions, constraints, enums, etc.) is preserved during the automatic transformation from flattened to object format. Input schemas use `"input"` as the wrapper property, while output schemas use `"result"`.

## MCP Annotations

Annotations provide behavior hints to MCP clients about how to handle your abilities. **Annotations are type-specific** - Tools use different annotations than Resources and Prompts.

### Annotation Format: WordPress Abilities API vs MCP

**Best Practice: Use WordPress Abilities API Format**

The MCP Adapter automatically converts WordPress Abilities API annotation names to MCP format. **It's recommended to use the WordPress Abilities API format** when available for consistency across the WordPress ecosystem.

#### For Tools: WordPress Format Preferred

```php
// ✅ RECOMMENDED: WordPress Abilities API format
'meta' => [
    'annotations' => [
        'readonly' => true,        // Auto-converted to readOnlyHint
        'destructive' => false,    // Auto-converted to destructiveHint
        'idempotent' => true,      // Auto-converted to idempotentHint
        'openWorldHint' => false,  // No WordPress equivalent, use MCP format
        'title' => 'My Tool'       // No WordPress equivalent, use MCP format
    ]
]

// ✅ ALSO VALID: Direct MCP format
'meta' => [
    'annotations' => [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => true,
        'openWorldHint' => false,
        'title' => 'My Tool'
    ]
]
```

**Tool Annotation Mapping Table:**

| WordPress Format | MCP Format | Description |
|-----------------|------------|-------------|
| `readonly` | `readOnlyHint` | Tool doesn't modify data |
| `destructive` | `destructiveHint` | Tool may delete/destroy data |
| `idempotent` | `idempotentHint` | Same input → same output |
| *(no equivalent)* | `openWorldHint` | Can work with arbitrary data |
| *(no equivalent)* | `title` | Custom display title |

**Why Use WordPress Format?**
- **Consistency**: Matches WordPress Abilities API conventions
- **Familiarity**: WordPress developers already know these terms
- **Future-proof**: Additional WordPress formats may be added
- **Interoperability**: Works with other WordPress Abilities API consumers

#### For Resources & Prompts: MCP Format Only

Resources and Prompts use MCP format directly - there are no WordPress equivalents:

```php
'meta' => [
    'annotations' => [
        'audience' => ['user', 'assistant'],      // MCP format (no WordPress equivalent)
        'lastModified' => '2024-01-15T10:30:00Z', // MCP format (no WordPress equivalent)
        'priority' => 0.8                         // MCP format (no WordPress equivalent)
    ]
]
```

### Tool Annotations (ToolAnnotations)

Tools support these MCP specification annotations:

```php
'meta' => [
    'annotations' => [
        'readOnlyHint' => true,       // Tool doesn't modify data
        'destructiveHint' => false,   // Tool doesn't delete/destroy data
        'idempotentHint' => true,     // Same input → same output
        'openWorldHint' => false,     // Works with predefined data only
        'title' => 'Custom Title'     // Display title (optional)
    ]
]
```

**Supported Tool Annotation Fields:**
- `readOnlyHint` (bool): Tool doesn't modify data
- `destructiveHint` (bool): Tool may delete or destroy data
- `idempotentHint` (bool): Same input always produces same output
- `openWorldHint` (bool): Tool can work with arbitrary/unknown data
- `title` (string): Custom display title for the tool

**WordPress → MCP Field Conversion**: For backward compatibility, Tools support WordPress-format field names that are automatically converted:
- `readonly` → `readOnlyHint`
- `destructive` → `destructiveHint`
- `idempotent` → `idempotentHint`

### Resource & Prompt Annotations (Annotations)

Resources and Prompts share the same annotation schema per MCP specification:

```php
'meta' => [
    'annotations' => [
        'audience' => ['user', 'assistant'],      // Intended audience
        'lastModified' => '2024-01-15T10:30:00Z', // ISO 8601 timestamp
        'priority' => 0.8                         // 0.0 (lowest) to 1.0 (highest)
    ]
]
```

**Supported Resource & Prompt Annotation Fields:**
- `audience` (array): Intended roles - `["user"]`, `["assistant"]`, or both
- `lastModified` (string): ISO 8601 timestamp of last modification
- `priority` (float): Relative importance (0.0 = lowest, 1.0 = highest)

### Annotation Usage by Component Type

- **Tools**: Use annotations to describe tool behavior and execution characteristics
- **Resources**: Use annotations for content metadata and access patterns  
- **Prompts**: Support two types of annotations (template-level and message content-level)

### Complete Annotation Example

```php
// Tool with WordPress Abilities API format (RECOMMENDED)
wp_register_ability('my-plugin/analyze-data', [
    'label' => 'Data Analyzer',
    'description' => 'Analyze data with various algorithms',
    'input_schema' => [...],
    'execute_callback' => 'analyze_data_callback',
    'permission_callback' => function() { return current_user_can('read'); },
    'meta' => [
        'annotations' => [
            'readonly' => true,              // WordPress format → readOnlyHint
            'destructive' => false,          // WordPress format → destructiveHint
            'idempotent' => true,            // WordPress format → idempotentHint
            'openWorldHint' => false,        // No WordPress equivalent
            'title' => 'Data Analysis Tool'  // No WordPress equivalent
        ],
        'mcp' => [
            'public' => true,
            'type' => 'tool'
        ]
    ]
]);

// Resource with Resource-specific annotations
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
            'lastModified' => date('c')      // ISO 8601 timestamp
        ],
        'mcp' => [
            'public' => true,
            'type' => 'resource'
        ]
    ]
]);

// Prompt with Prompt-specific annotations
wp_register_ability('my-plugin/review-prompt', [
    'label' => 'Code Review Prompt',
    'description' => 'Generate structured code review prompts',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Code to review']
        ],
        'required' => ['code']
    ],
    'execute_callback' => 'generate_review_prompt',
    'permission_callback' => function() { return current_user_can('edit_posts'); },
    'meta' => [
        'annotations' => [
            'audience' => ['user', 'assistant'], // For both user and AI
            'priority' => 0.8,                  // High priority
            'lastModified' => date('c')          // Current timestamp
        ],
        'mcp' => [
            'public' => true,
            'type' => 'prompt'
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
            'readonly' => false,       // Tool modifies data (WordPress format)
            'destructive' => false,    // Tool doesn't delete data (WordPress format)
            'idempotent' => false      // Multiple calls create multiple posts (WordPress format)
        ],
        'mcp' => [
            'public' => true  // Expose this ability via MCP
        ]
    ]
]);
```

#### Tool with Flattened Schemas

For simple tools that accept and return single values, you can use flattened schemas:

```php
wp_register_ability('my-plugin/count-posts', [
    'label' => 'Count Posts',
    'description' => 'Count posts of a specific type',
    'input_schema' => [
        'type' => 'string',
        'description' => 'Post type to count',
        'enum' => ['post', 'page', 'attachment']
    ],
    'output_schema' => [
        'type' => 'integer',
        'description' => 'Total number of posts found'
    ],
    'execute_callback' => function($input) {
        // $input is the unwrapped string value (e.g., 'post')
        $count = wp_count_posts($input);
        // Return unwrapped integer value
        return $count->publish;
    },
    'permission_callback' => function() {
        return current_user_can('read');
    },
    'meta' => [
        'annotations' => [
            'readonly' => true,
            'idempotent' => false  // Count may change over time
        ],
        'mcp' => [
            'public' => true
        ]
    ]
]);
```

**Note**: With flattened schemas:
- The callback receives the unwrapped input value directly (e.g., `'post'` instead of `['input' => 'post']`)
- The callback should return the unwrapped output value (e.g., `42` instead of `['result' => 42]`)
- The adapter automatically handles wrapping/unwrapping for MCP clients

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
            'audience' => ['user', 'assistant'], // For both users and AI
            'priority' => 0.8,                  // High priority resource
            'lastModified' => '2024-01-15T10:30:00Z' // Last update timestamp
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
            'audience' => ['user'],         // For user-facing prompts
            'priority' => 0.7               // Standard priority
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
            'audience' => ['assistant'],        // For AI analysis only
            'priority' => 0.9,                 // High priority analysis
            'lastModified' => date('c')         // Current timestamp
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
- Support Prompt-specific annotations: `audience`, `priority`, `lastModified`

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