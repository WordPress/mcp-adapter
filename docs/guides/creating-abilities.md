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

## Tool Naming Rules

When abilities are converted to MCP tools, the ability name is transformed to comply with [MCP 2025-11-25 naming rules](https://modelcontextprotocol.io/specification/2025-11-25/server/tools#tool-names).

### WordPress Ability Names

WordPress abilities follow strict naming rules enforced by the Abilities API:

```
Pattern: namespace/ability-name
Regex:   ^[a-z0-9-]+/[a-z0-9-]+$
```

- Must have a namespace prefix (e.g., `my-plugin/`)
- Only lowercase alphanumeric characters and dashes
- Forward slash separates namespace from ability name

### MCP Tool Name Requirements

| Rule | Value |
|------|-------|
| Length | 1–128 characters |
| Allowed characters | `A-Za-z0-9_.-` (letters, digits, underscore, hyphen, dot) |
| Case | Case-sensitive |

### Automatic Transformation

Since WordPress ability names are already well-formed, the main transformation is:

```
my-plugin/create-post → my-plugin-create-post
```

The forward slash (`/`) is replaced with a hyphen (`-`) because MCP doesn't allow forward slashes in tool names.

### Collision Warning

Be aware that different ability names can produce the same MCP tool name:

```php
// ❌ These abilities would collide as MCP tools:
'my-plugin/create-post'  // → my-plugin-create-post
'my-plugin-create-post'  // → my-plugin-create-post (if it existed)
```

The first-registered ability wins; subsequent collisions are logged as warnings.

### Customizing Tool Names

Use the `mcp_adapter_tool_name` filter to customize the final name:

```php
add_filter( 'mcp_adapter_tool_name', function( $name, $ability ) {
    // Prefix all tools from your plugin
    if ( str_starts_with( $ability->get_name(), 'my-plugin/' ) ) {
        return 'acme-' . $name;
    }
    return $name;
}, 10, 2 );
```

**Warning:** The filter runs after sanitization. If you return an invalid name (wrong characters or length > 128), the tool registration will fail.

> **Technical details:** See [Ability → Tool Conversion Contract](ability-tool-conversion.md#tool-name-derivation) for the complete sanitization algorithm.

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

### MCP Schema Limitations

MCP `ToolInputSchema` is a **restricted subset** of JSON Schema. Only these fields are supported:

| Field | Description |
|-------|-------------|
| `$schema` | JSON Schema dialect (optional, defaults to 2020-12) |
| `type` | Must be `"object"` |
| `properties` | Map of property definitions |
| `required` | Array of required property names |

**Unsupported fields** like `additionalProperties`, `minProperties`, `patternProperties`, etc. are silently dropped. If you need strict "no extra properties" validation, implement it in your `execute_callback`.

### Tools with No Arguments

For tools that don't require arguments, omit `input_schema` or set it to an empty array. The adapter generates a minimal MCP-compliant schema:

```php
// Option 1: Omit input_schema entirely
wp_register_ability('my-plugin/get-status', [
    'label' => 'Get Status',
    'description' => 'Returns current system status',
    'execute_callback' => 'get_status_callback',
    // No input_schema
]);

// Result: inputSchema = { "type": "object" }
```

### Internal Metadata (`_meta`)

The adapter stores internal transformation metadata in `_meta['mcp_adapter']` for debugging purposes. This metadata is **automatically stripped** before responses are sent to MCP clients and is never visible externally.

> **Technical details:** See [Ability → Tool Conversion Contract](ability-tool-conversion.md#internal-metadata-_meta) for the complete metadata structure.

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

**Important:** Tools only support `ToolAnnotations` fields. Shared annotation fields (`audience`, `priority`, `lastModified`) are **not** valid for tools and will be ignored.

### Annotation Value Validation

Boolean annotation values use **strict parsing** to avoid PHP's loose type casting issues:

```php
// ✅ Accepted boolean values:
true, false                    // PHP booleans
1, 0                           // Integers
'true', 'false', '1', '0'      // Strings (case-insensitive)

// ❌ Rejected values (field is dropped):
'yes', 'no', 'on', 'off'       // Not accepted
'', null                       // Empty values
2, -1                          // Other integers
```

**Why strict?** PHP's default `(bool)` cast incorrectly converts the string `'false'` to `true` (because it's a non-empty string). The adapter uses strict parsing to avoid this common pitfall.

Invalid annotation values are silently dropped rather than causing registration failures. This ensures tools remain functional even with misconfigured annotations.

> **Technical details:** See [Ability → Tool Conversion Contract](ability-tool-conversion.md#annotations-mapping) for the complete validation rules.

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

// Resource with Resource-specific annotations (all params under mcp.*)
wp_register_ability('my-plugin/user-data', [
    'label' => 'User Data Resource',
    'description' => 'Access to user profile data',
    'execute_callback' => 'get_user_data',
    'permission_callback' => function() { return current_user_can('read'); },
    'meta' => [
        'mcp' => [
            'public' => true,
            'type'   => 'resource',
            'uri'    => 'WordPress://users/profile',   // Required: RFC 3986 format
            'annotations' => [
                'audience' => ['assistant'],            // For AI use only
                'priority' => 0.9,                     // High importance
                'lastModified' => date('c')             // ISO 8601 timestamp
            ],
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
        ], true); // Pass true to return WP_Error on failure

        // Return WP_Error directly - adapter converts to isError: true
        if (is_wp_error($post_id)) {
            return $post_id;
        }

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

### Tool Response Structure

When a tool executes successfully, the MCP Adapter returns a `CallToolResult` with both human-readable and machine-readable formats:

```json
{
  "content": [
    {
      "type": "text",
      "text": "{\"post_id\":123,\"url\":\"https://example.com/?p=123\",\"status\":\"draft\"}"
    }
  ],
  "structuredContent": {
    "post_id": 123,
    "url": "https://example.com/?p=123",
    "status": "draft"
  },
  "isError": false
}
```

| Field | Purpose | Format |
|-------|---------|--------|
| `content` | Human/LLM readable output | Array of content blocks (TextContent, ImageContent, etc.) |
| `structuredContent` | Machine-readable output | Raw data structure matching your `output_schema` |
| `isError` | Indicates execution failure | Boolean (false for success, true for errors) |

**Why both formats?**

- **`content`**: Contains JSON-encoded text that LLMs can read and understand. This is the primary output for AI assistants.
- **`structuredContent`**: Contains the raw data structure for programmatic clients that need to parse and process the result directly.

**Image Results**

For tools that return images, the adapter uses `ImageContent` instead of text:

```php
// In your execute_callback:
return [
    'type' => 'image',
    'results' => $image_binary_data,
    'mimeType' => 'image/png'  // Optional, defaults to 'image/png'
];
```

This produces:

```json
{
  "content": [
    {
      "type": "image",
      "data": "base64-encoded-image-data...",
      "mimeType": "image/png"
    }
  ],
  "isError": false
}
```

**Error Results**

When a tool encounters an error, return a `WP_Error` object. The MCP Adapter automatically converts it to a proper MCP error response with `isError: true`:

```php
// In your execute_callback - return WP_Error for errors
'execute_callback' => function($input) {
    $post_id = wp_insert_post($postarr, true);

    if (is_wp_error($post_id)) {
        return $post_id; // Return the WP_Error directly
    }

    // Or create your own WP_Error:
    if (empty($input['title'])) {
        return new WP_Error('empty_title', 'Post title cannot be empty.');
    }

    return ['post_id' => $post_id, ...];
}
```

This produces:

```json
{
  "content": [
    {
      "type": "text",
      "text": "Post title cannot be empty."
    }
  ],
  "isError": true
}
```

## Creating Resources

Resources provide access to data or content. They require a `uri` in the meta field and should set `type: 'resource'` in the MCP configuration.

### Resource Meta Structure

All MCP-specific metadata must be under the `mcp.*` namespace:

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
        'mcp' => [
            'public' => true,                           // Expose via MCP (required)
            'type'   => 'resource',                     // Mark as resource
            'uri'    => 'WordPress://site/config',      // Required: RFC 3986 format
            'mimeType' => 'application/json',           // Optional: content type
            'size'   => 256,                            // Optional: bytes
            'annotations' => [                          // Optional: MCP annotations
                'audience' => ['user', 'assistant'],
                'priority' => 0.8,
                'lastModified' => '2024-01-15T10:30:00Z'
            ],
        ]
    ]
]);
```

### Resource Meta Fields

| Field | Required | Description |
|-------|----------|-------------|
| `mcp.public` | **Yes** | Must be `true` to expose via MCP |
| `mcp.type` | **Yes** | Must be `'resource'` |
| `mcp.uri` | **Yes** | Unique identifier (RFC 3986 format, e.g., `WordPress://...`) |
| `mcp.mimeType` | No | MIME type of content (e.g., `text/plain`, `application/json`) |
| `mcp.size` | No | Content size in bytes (for UI display) |
| `mcp.annotations` | No | MCP annotations (`audience`, `priority`, `lastModified`) |
| `mcp.icons` | No | Array of icon objects for UI display |
| `mcp._meta` | No | Custom metadata to pass through to MCP clients |

### Deprecation Notice

Top-level meta keys (`meta.uri`, `meta.annotations`) are **deprecated**. They still work but trigger `_doing_it_wrong` notices:

```php
// ❌ DEPRECATED: Top-level keys
'meta' => [
    'uri' => 'WordPress://site/config',         // Deprecated
    'annotations' => [...],                      // Deprecated
    'mcp' => ['public' => true, 'type' => 'resource']
]

// ✅ RECOMMENDED: All params under mcp.* namespace
'meta' => [
    'mcp' => [
        'public' => true,
        'type' => 'resource',
        'uri' => 'WordPress://site/config',     // New location
        'annotations' => [...]                   // New location
    ]
]
```

### Resource URI Requirements

URIs must be RFC 3986 compliant with a scheme:

```php
// ✅ Valid URIs:
'WordPress://site/config'
'file:///path/to/resource'
'https://example.com/resource'
'custom-scheme://authority/path'

// ❌ Invalid URIs (will fail registration):
'site/config'           // Missing scheme
'/path/to/resource'     // Missing scheme
''                      // Empty
```

### Resource Field Mapping

Resources are mapped from ability properties:

| MCP Field | Source | Notes |
|-----------|--------|-------|
| `name` | `ability->get_name()` | No sanitization (unlike tools) |
| `title` | `ability->get_label()` | Human-readable display name |
| `description` | `ability->get_description()` | Used as LLM hint |
| `uri` | `meta.mcp.uri` | Required, RFC 3986 validated |
| `mimeType` | `meta.mcp.mimeType` | Validated `type/subtype` format |

> **Technical details:** See [Ability → Resource Conversion Contract](ability-resource-conversion.md) for complete mapping rules and validation behavior.

### Resource Return Shapes

When `resources/read` is called, your `execute_callback` is invoked. The adapter normalizes return values into MCP-compliant `ReadResourceResult`.

#### Plain String (Simplest)

```php
'execute_callback' => function() {
    return 'Simple text content';
}
// → TextResourceContents with text = 'Simple text content'
```

#### Structured Content

```php
'execute_callback' => function() {
    return [
        'text'     => '# Markdown Content\n\nWith formatting.',
        'mimeType' => 'text/markdown',
    ];
}
// → TextResourceContents with mimeType preserved
```

#### Binary Content (Blob)

For binary data, return base64-encoded content with `blob` key:

```php
'execute_callback' => function() {
    $image_data = file_get_contents('/path/to/image.png');
    return [
        'blob'     => base64_encode($image_data),
        'mimeType' => 'image/png',
    ];
}
// → BlobResourceContents with base64 data
```

#### Multiple Content Parts

Return array of content items for multi-part resources:

```php
'execute_callback' => function() {
    return [
        ['uri' => 'WordPress://doc/part1', 'text' => 'Part 1 content'],
        ['uri' => 'WordPress://doc/part2', 'text' => 'Part 2 content'],
    ];
}
// → Array of TextResourceContents
```

#### Object/Array Results

Associative arrays are automatically JSON-encoded:

```php
'execute_callback' => function() {
    return ['status' => 'ok', 'count' => 42];
}
// → TextResourceContents with text = '{"status":"ok","count":42}'
```

### Performance Note

**Important:** `resources/list` returns metadata only — your `execute_callback` is **NOT called** during listing. Content is only generated when `resources/read` is called.

This means:
- Resource discovery is fast (no content generation)
- Expensive operations only run when content is requested
- You can safely register many resources without performance impact

For expensive `execute_callback` operations, consider:
- Using WordPress transients for caching
- Implementing lazy loading
- Adding pagination for large datasets

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

You can also annotate the generated message content according to the [MCP specification](https://modelcontextprotocol.io/specification/2025-11-25/server/prompts#promptmessage):

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

**Always use `WP_Error` for errors in your `execute_callback`:**

```php
'execute_callback' => function($input) {
    // ✅ CORRECT: Return WP_Error objects
    if (!$input['title']) {
        return new WP_Error('empty_title', 'Title is required.');
    }

    $result = wp_insert_post($postarr, true);
    if (is_wp_error($result)) {
        return $result; // Pass through WP_Error from WordPress functions
    }

    // ❌ WRONG: Don't manually construct error arrays
    // return ['error' => ['code' => 'x', 'message' => 'y']];
    // This bypasses MCP error handling and returns success: true with nested error!

    return ['post_id' => $result];
}
```

**Why `WP_Error` matters for MCP:**

The MCP specification distinguishes between:
- **Protocol errors** (tool not found, server error) → JSON-RPC error response
- **Tool execution errors** (validation failed, permission denied) → `CallToolResult` with `isError: true`

When you return a `WP_Error`, the MCP Adapter:
1. Detects it via `is_wp_error()`
2. Extracts the error code and message
3. Returns a proper MCP `CallToolResult` with `isError: true`
4. Allows LLM clients to understand and self-correct based on the error

**Best practices:**
- Return meaningful error messages that help users understand what went wrong
- Use descriptive error codes (e.g., `'invalid_post_type'`, `'permission_denied'`)
- Pass through `WP_Error` objects from WordPress functions directly
- Include context in error messages when helpful

### Performance
- Keep tool execution lightweight
- Cache expensive operations
- Use appropriate database queries
- Consider pagination for large datasets

## Next Steps

- **Configure [Transport Permissions](transport-permissions.md)** to control server-wide access
- **Review [Error Handling](error-handling.md)** for advanced error management strategies
- **Check [Architecture Overview](../architecture/overview.md)** to understand system design