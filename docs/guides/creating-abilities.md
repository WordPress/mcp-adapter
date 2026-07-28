# Creating Abilities for MCP

This guide covers how to create WordPress abilities for MCP (Model Context Protocol) integration, including tools, resources, and prompts.

## System Overview

WordPress abilities can be registered as different MCP components:
- **Tools**: Execute actions and return results
- **Resources**: Provide access to data or content
- **Prompts**: Generate structured messages for language models

**Annotations** provide behavior hints to MCP clients. Where you write them depends on the component type: Tools read `meta.annotations`, Resources read `meta.mcp.annotations`, and Prompts take no descriptor annotations at all. See [MCP Annotations](#mcp-annotations).

## MCP Exposure

WordPress abilities are NOT accessible via the default MCP server by default. Set the high-level `meta.public` flag to `true` to make an ability available to clients, including MCP. An explicit `meta.mcp.public` value overrides that default for MCP only.

```php
'meta' => [
    'public' => true, // Expose to clients, including MCP
    'mcp' => [
        'type'   => 'tool' // Optional: 'tool' (default), 'resource', or 'prompt'
    ],
    'annotations' => [...] // Optional MCP annotations (Tools; Resources use mcp.annotations)
]
```

> **Note**: How far `meta.public` reaches depends on the WordPress version. WordPress core starts applying `meta.public` to the REST API (`meta.show_in_rest`) in version 7.1. On WordPress 6.9 and 7.0, the MCP Adapter honors `meta.public` for MCP exposure, but REST API access still requires setting `meta.show_in_rest` to `true`.

To keep an otherwise public ability out of MCP, opt out explicitly:

```php
'meta' => [
    'public' => true,
    'mcp' => [
        'public' => false,
    ],
]
```

To expose an ability only through MCP without opting into other client channels, leave `meta.public` unset and opt into MCP explicitly:

```php
'meta' => [
    'mcp' => [
        'public' => true,
    ],
]
```

### MCP Type

The `type` parameter specifies how the ability should be exposed in the MCP server:
- **`tool`** (default): Exposed as a callable tool via the default server's discovery
- **`resource`**: Exposed as a resource (requires `uri` in meta)
- **`prompt`**: Exposed as a prompt (requires `arguments` in meta)

If not specified, abilities default to `type: 'tool'`.

## Ability categories (required)

Every ability **must** declare a `category`, and that category must already be registered when `wp_register_ability()` runs. If you omit `category` or use one that isn't registered, `wp_register_ability()` returns `null` and the ability never appears. There is no `WP_Error`; core calls `_doing_it_wrong()`, which only surfaces a PHP notice when `WP_DEBUG` is on. With `WP_DEBUG` off (typical on production) the failure looks completely silent.

WordPress core registers two categories you can use right away: `site` and `user`.

To use your own category, register it first on the `wp_abilities_api_categories_init` hook. Categories register on this hook; abilities register on `wp_abilities_api_init` — a separate, later hook. Register the category before any ability that references it:

```php
add_action( 'wp_abilities_api_categories_init', function () {
    wp_register_ability_category( 'my-plugin', [
        'label'       => 'My Plugin',
        'description' => 'Abilities provided by My Plugin.',
    ] );
} );
```

Then reference the slug in your abilities: `'category' => 'my-plugin'`.

## Basic Ability Structure

```php
wp_register_ability('my-plugin/my-ability', [
    'label' => 'My Ability',
    'description' => 'What this ability does',
    'category' => 'site',         // Required. Must be a registered category (core: 'site', 'user').
    'input_schema' => [...],      // For tools (supports both object and flattened schemas)
    'output_schema' => [...],     // Optional for tools
    'execute_callback' => 'my_callback',
    'permission_callback' => 'my_permission_check',
    'meta' => [
        'public' => true,             // Expose to clients, including MCP
        'annotations' => [...],       // MCP annotations (Tools only)
        'mcp' => [
            'type'        => 'tool',  // 'tool', 'resource', or 'prompt'
            'uri'         => '...',   // For resources
            'annotations' => [...],   // For resources
            'arguments'   => [...],   // For prompts
        ]
    ]
]);
```

Note that Tools and Resources read annotations from different places, and Prompts have no
descriptor annotations at all. The [MCP Annotations](#mcp-annotations) section covers this.

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

Annotations provide behavior hints to MCP clients about how to handle your abilities. **Annotations are type-specific** — Tools use a different vocabulary than Resources, and each type reads them from a different place.

| Component | Where to write them | What you write |
|---|---|---|
| **Tool** descriptor | `meta.annotations` | `readonly`, `destructive`, `idempotent` (Abilities API names), plus `openWorldHint` and `title` |
| **Resource** descriptor | `meta.mcp.annotations` | `audience`, `priority`, `lastModified` |
| **Prompt** descriptor | *not supported* | — |
| **Content block** (tool result or prompt message) | `annotations` on the block itself | `audience`, `priority`, `lastModified` |

Tool annotations are the one case where the name you write differs from the name that goes on the wire: the adapter maps `readonly` → `readOnlyHint`, `destructive` → `destructiveHint`, `idempotent` → `idempotentHint`. `openWorldHint` and `title` have no Abilities API equivalent, so they are written and emitted under the same name.

Two things to watch:

- Resources also accept a top-level `meta.annotations`, but that location is **deprecated as of 0.5.0** and logs a deprecation notice. Write `meta.mcp.annotations` instead. The same applies to `meta.uri`, `meta.mimeType` and `meta.size` — prefer `meta.mcp.*`.
- The MCP `Prompt` object has **no** `annotations` field, so a Prompt ability's `meta.annotations` is ignored entirely — it is neither emitted nor logged. Annotate the message content blocks instead.

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

#### For Resources: MCP Format Only, Under `mcp`

Resources use MCP format directly — there are no WordPress equivalents — and read from `meta.mcp.annotations`:

```php
'meta' => [
    'mcp' => [
        'type' => 'resource',
        'annotations' => [
            'audience' => ['user', 'assistant'],      // MCP format (no WordPress equivalent)
            'lastModified' => '2024-01-15T10:30:00Z', // MCP format (no WordPress equivalent)
            'priority' => 0.8                         // MCP format (no WordPress equivalent)
        ]
    ]
]
```

A top-level `meta.annotations` on a Resource still works, but is deprecated as of 0.5.0 and logs a notice.

#### For Prompts: No Descriptor Annotations

The MCP `Prompt` object has no `annotations` field, so there is nothing to write on a Prompt ability's meta. Annotate the message content blocks your callback returns — see [Message Content Annotations](#message-content-annotations-mcp-specification).

### Tool Annotations (ToolAnnotations)

Write them in `meta.annotations`, using the Abilities API names where one exists:

```php
'meta' => [
    'annotations' => [
        'readonly' => true,           // Tool doesn't modify data
        'destructive' => false,       // Tool doesn't delete/destroy data
        'idempotent' => true,         // Same input → same output
        'openWorldHint' => false,     // Works with predefined data only
        'title' => 'Custom Title'     // Display title (optional)
    ]
]
```

**Field Names — Written vs Emitted:**

| What you write | What MCP receives | Meaning |
|---|---|---|
| `readonly` (bool) | `readOnlyHint` | Tool doesn't modify data |
| `destructive` (bool) | `destructiveHint` | Tool may delete or destroy data |
| `idempotent` (bool) | `idempotentHint` | Same input always produces same output |
| `openWorldHint` (bool) | `openWorldHint` | Tool can work with arbitrary/unknown data |
| `title` (string) | `title` | Custom display title for the tool |

`readonly`, `destructive` and `idempotent` are the WordPress Abilities API's own annotation names. WordPress core defines them on every ability, validates that `meta.annotations` is an array, and reads them back in its REST layer — so writing them keeps your ability consistent for every Abilities API consumer, not just MCP.

Writing the MCP names (`readOnlyHint`, `destructiveHint`, `idempotentHint`) directly also works. If both are present, the Abilities API name wins.

### Tool Result Annotations

The annotations above describe the tool itself and belong on its descriptor, in the ability's `meta.annotations`. A content block a tool *returns* is a different object, and it takes the content vocabulary — the same `audience`, `priority` and `lastModified` that Resources and Prompts use:

```php
'execute_callback' => function() {
    return [
        'type'        => 'resource',
        'annotations' => [
            'audience' => ['user'],  // who the block is for
            'priority' => 0.8,       // 0.0 (lowest) to 1.0 (highest)
        ],
        'resource'   => [
            'uri'  => 'wordpress://report/latest',
            'text' => 'Report body',
        ],
    ];
},
```

A tool hint written on a result is dropped: `readOnlyHint` and its siblings describe a tool, and a content block is not one. Values outside what MCP allows — a `priority` beyond 0.0–1.0, an `audience` role other than `user` or `assistant`, a `lastModified` that is not a valid timestamp — cause the annotations to be dropped as a group and logged, and the result is still returned.

### Resource Annotations (Annotations)

Resources use the MCP content annotation schema, written under `meta.mcp.annotations`:

```php
'meta' => [
    'mcp' => [
        'type' => 'resource',
        'annotations' => [
            'audience' => ['user', 'assistant'],      // Intended audience
            'lastModified' => '2024-01-15T10:30:00Z', // ISO 8601 timestamp
            'priority' => 0.8                         // 0.0 (lowest) to 1.0 (highest)
        ]
    ]
]
```

**Supported Resource Annotation Fields:**
- `audience` (array): Intended roles - `["user"]`, `["assistant"]`, or both
- `lastModified` (string): ISO 8601 timestamp of last modification
- `priority` (float): Relative importance (0.0 = lowest, 1.0 = highest)

Content blocks — whether returned from a tool or carried in a prompt message — use these same three fields, written on the block itself.

### Annotation Usage by Component Type

- **Tools**: Two places. `meta.annotations` describes the tool's behavior and execution characteristics on its descriptor; a returned content block takes content annotations on the block.
- **Resources**: One place, `meta.mcp.annotations`, for content metadata and access patterns.
- **Prompts**: One place, the message content blocks. The descriptor has no annotations field.

### Complete Annotation Example

```php
// Tool with WordPress Abilities API format (RECOMMENDED)
wp_register_ability('my-plugin/analyze-data', [
    'label' => 'Data Analyzer',
    'description' => 'Analyze data with various algorithms',
    'category' => 'site',
    'input_schema' => [...],
    'execute_callback' => 'analyze_data_callback',
    'permission_callback' => function() { return current_user_can('read'); },
    'meta' => [
        'public' => true,
        'annotations' => [
            'readonly' => true,              // WordPress format → readOnlyHint
            'destructive' => false,          // WordPress format → destructiveHint
            'idempotent' => true,            // WordPress format → idempotentHint
            'openWorldHint' => false,        // No WordPress equivalent
            'title' => 'Data Analysis Tool'  // No WordPress equivalent
        ],
        'mcp' => [
            'type' => 'tool'
        ]
    ]
]);

// Resource with Resource-specific annotations
wp_register_ability('my-plugin/user-data', [
    'label' => 'User Data Resource',
    'description' => 'Access to user profile data',
    'category' => 'user',
    'execute_callback' => 'get_user_data',
    'permission_callback' => function() { return current_user_can('read'); },
    'meta' => [
        'public' => true,
        'mcp' => [
            'type' => 'resource',
            'uri' => 'wordpress://users/profile',
            'annotations' => [
                'audience' => ['assistant'],     // For AI use only
                'priority' => 0.9,              // High importance
                'lastModified' => date('c')      // ISO 8601 timestamp
            ]
        ]
    ]
]);

// Prompt — no descriptor annotations; annotate the message content instead
wp_register_ability('my-plugin/review-prompt', [
    'label' => 'Code Review Prompt',
    'description' => 'Generate structured code review prompts',
    'category' => 'site',
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
        'public' => true,
        'mcp' => [
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
    'category' => 'site',
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
        'public' => true, // Expose to clients, including MCP
        'annotations' => [
            'readonly' => false,       // Tool modifies data (WordPress format)
            'destructive' => false,    // Tool doesn't delete data (WordPress format)
            'idempotent' => false      // Multiple calls create multiple posts (WordPress format)
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
    'category' => 'site',
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
        'public' => true,
        'annotations' => [
            'readonly' => true,
            'idempotent' => false  // Count may change over time
        ]
    ]
]);
```

**Note**: With flattened schemas:
- The callback receives the unwrapped input value directly (e.g., `'post'` instead of `['input' => 'post']`)
- The callback should return the unwrapped output value (e.g., `42` instead of `['result' => 42]`)
- The adapter automatically handles wrapping/unwrapping for MCP clients

## Creating Resources

Resources provide access to data or content. They require a `uri` and should set `type: 'resource'`, both under `meta.mcp`:

```php
wp_register_ability('my-plugin/site-config', [
    'label' => 'Site Configuration',
    'description' => 'WordPress site configuration and settings',
    'category' => 'site',
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
        'public' => true, // Expose to clients, including MCP
        'mcp' => [
            'type' => 'resource', // Mark as resource for auto-discovery
            'uri'  => 'wordpress://site/config',
            'annotations' => [
                'audience' => ['user', 'assistant'], // For both users and AI
                'priority' => 0.8,                  // High priority resource
                'lastModified' => '2024-01-15T10:30:00Z' // Last update timestamp
            ]
        ]
    ]
]);
```

A resource is content addressed by its URI, so `resources/read` sends no arguments. Leave `input_schema` off a resource-only ability: there is nothing for it to describe, and the ability is called with no input.

An ability registered as both a tool and a resource still needs its `input_schema` for the tool side. A read then invokes it with an empty input, so make sure the callbacks handle that.

### Returning Structured Resource Contents

The example above returns a plain array, which the adapter JSON-encodes into a single text content item. To control the response yourself, return a list of content items instead. Each item may set `uri`, `mimeType`, one of `text` or `blob`, and `_meta`:

```php
'execute_callback' => function() {
    return [
        [
            'uri'      => 'ui://my-plugin/app',
            'mimeType' => 'text/html;profile=mcp-app',
            'text'     => '<!doctype html>...',
            '_meta'    => [
                'ui' => ['prefersBorder' => true],
            ],
        ],
    ];
},
```

`_meta` travels with the resource but is not part of its body. MCP Apps UI resources use it for CSP config and rendering hints.

What you write there is a request to the client, not a control the adapter applies. The adapter checks only that `_meta` can serialize as a JSON object and forwards it as written; it does not interpret the keys, and a client is free to ignore any of them. A CSP declared here is enforced by whatever renders the resource, so treat it as a hint to that renderer rather than as a boundary around your own content.

MCP declares `_meta` as a JSON object, so it must be a non-empty PHP associative array — a sequential array (including an empty one) would serialize as a JSON array. This holds wherever the adapter emits `_meta`: resource contents, content blocks, and the `_meta` a tool, resource or prompt declares under `mcp._meta`. A value that would not serialize as an object is dropped, and whatever it travelled with is still returned. Key names may carry an optional reverse-DNS prefix (`com.example/hint`); prefixes whose second label is `modelcontextprotocol` or `mcp` are reserved by the specification.

### Serving an Image or Binary File as a Resource

Binary contents go in `blob` instead of `text`. **The adapter does not encode them for you** — write base64 yourself:

```php
wp_register_ability('my-plugin/site-logo', [
    'label' => 'Site Logo',
    'description' => 'The site logo as a PNG image',
    'category' => 'site',
    'execute_callback' => function() {
        $path = get_attached_file(get_theme_mod('custom_logo'));

        return [
            [
                'blob'     => base64_encode(file_get_contents($path)),
                'mimeType' => 'image/png',
            ],
        ];
    },
    'permission_callback' => function() {
        return current_user_can('read');
    },
    'meta' => [
        'public' => true,
        'mcp' => [
            'type'     => 'resource',
            'uri'      => 'wordpress://site/logo',
            // Declared here, `resources/list` tells a client this is a PNG
            // before it ever reads the resource.
            'mimeType' => 'image/png',
        ],
    ],
]);
```

An item that omits `uri` inherits the resource's own, so `blob` and `mimeType` are all a binary item needs. Return several items to serve several files from one resource.

> **Watch the direction of encoding.** A resource's `blob` must arrive already base64-encoded, but a tool's image result takes **raw** bytes in `results` and the adapter encodes them — see [Returning an Image](#returning-an-image). Base64-encoding both, or neither, is the usual mistake.

Only the **first** item is inspected to decide whether you returned content items or a single payload: it must carry `uri`, `text` or `blob`. If it does not, the whole list is JSON-encoded into one text block instead, siblings included.

Embedding a resource in a tool result is a general content-block capability, not the route to an MCP App. An MCP App is predeclared: register the UI resource so it appears in `resources/list` under its `ui://` URI, then bind it from the tool descriptor with `mcp._meta.ui.resourceUri`. The host fetches the template itself over `resources/read` and renders it in a sandboxed frame, and the tool result carries only data. A tool result that embeds the UI resource instead is rendered as plain text.

Tools can return the same contents embedded in a `resource` content block. Two levels each carry their own `_meta`: the content block, and the resource contents nested inside it. The flat form is a content item with a `type` tag added, so its `_meta` describes the resource exactly as it does above:

```php
return [
    'type'  => 'resource',
    'uri'   => 'ui://my-plugin/app',
    'text'  => '<!doctype html>...',
    '_meta' => ['ui' => ['prefersBorder' => true]],  // resource contents
];
```

Write the nested form to address both levels. `annotations` describes the block in either form, because resource contents have no `annotations` field:

```php
return [
    'type'        => 'resource',
    'annotations' => ['audience' => ['user']],
    '_meta'       => ['block' => 'level'],           // content block
    'resource'    => [
        'uri'   => 'ui://my-plugin/app',
        'text'  => '<!doctype html>...',
        '_meta' => ['ui' => ['prefersBorder' => true]],  // resource contents
    ],
];
```

### Returning an Image

A tool returns an image by marking the result `type` as `image` and putting the **raw bytes** in `results`. The adapter base64-encodes them into the `data` field MCP expects, so do not encode them yourself:

```php
'execute_callback' => function() {
    return [
        'type'     => 'image',
        'results'  => file_get_contents( $path ),  // raw bytes, not base64
        'mimeType' => 'image/png',
        'annotations' => ['audience' => ['user']],
        '_meta'    => ['block' => 'level'],
    ];
},
```

`mimeType` defaults to `image/png` when omitted. `annotations` and `_meta` describe the content block, exactly as in the `resource` form above.

`results` is the only key the image branch reads. A result marked `type: 'image'` that carries the encoded bytes under `data` instead is returned as ordinary tool data — a JSON text block — and the adapter logs a warning naming the tool.

## Building a Tool with a UI (MCP Apps)

[MCP Apps](https://modelcontextprotocol.io/seps/1865-mcp-apps-interactive-user-interfaces-for-mcp) lets a tool render an interactive HTML interface in the client instead of returning text the model reads aloud. It takes **two abilities**:

1. a **resource** holding the HTML template, published under a `ui://` URI;
2. a **tool** that points at that URI from its descriptor and returns only data.

The host fetches the template itself over `resources/read`, renders it in a sandboxed iframe, and feeds it the tool's result. Keeping the two apart is deliberate: the host can prefetch and security-review the template before the tool ever runs.

### Step 1: Register the UI resource

```php
wp_register_ability('my-plugin/sales-dashboard-ui', [
    'label' => 'Sales Dashboard UI',
    'description' => 'HTML template that renders the sales dashboard',
    'category' => 'site',
    'execute_callback' => function() {
        return [
            [
                'uri'      => 'ui://my-plugin/sales-dashboard',
                'mimeType' => 'text/html;profile=mcp-app',
                'text'     => file_get_contents(
                    plugin_dir_path(__FILE__) . 'ui/sales-dashboard.html'
                ),
                '_meta'    => [
                    'ui' => [
                        'prefersBorder' => true,
                        'csp' => [
                            'connectDomains'  => ['https://api.example.com'],
                            'resourceDomains' => ['https://cdn.example.com'],
                        ],
                    ],
                ],
            ],
        ];
    },
    'permission_callback' => function() {
        return current_user_can('view_woocommerce_reports');
    },
    'meta' => [
        'public' => true,
        'mcp' => [
            'type'     => 'resource',
            'uri'      => 'ui://my-plugin/sales-dashboard',
            'mimeType' => 'text/html;profile=mcp-app',
        ],
    ],
]);
```

The `mimeType` carries an RFC 2045 parameter (`;profile=mcp-app`) and is emitted exactly as written, on both the `resources/list` descriptor and the contents.

`_meta.ui` is how the template states its rendering needs. The specification defines four keys:

| Key | Type | Purpose |
|---|---|---|
| `csp` | object | Domains the frame may reach: `connectDomains`, `resourceDomains`, `frameDomains`, `baseUriDomains` |
| `permissions` | object | Browser capabilities to request — camera, microphone, geolocation, clipboardWrite |
| `domain` | string | A dedicated sandbox origin for the frame |
| `prefersBorder` | bool | Whether the host should draw a visual boundary |

These are requests to the host, not controls the adapter enforces. It checks only that `_meta` serializes as a JSON object and forwards it verbatim — a client is free to ignore any key, and the CSP is applied by whatever renders the frame.

### Step 2: Point a tool at it

```php
wp_register_ability('my-plugin/get-sales-summary', [
    'label' => 'Get Sales Summary',
    'description' => 'Sales totals for a date range, rendered as a dashboard',
    'category' => 'site',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'days' => ['type' => 'integer', 'default' => 30],
        ],
    ],
    'execute_callback' => function($input) {
        // Return plain data. The template renders it; do not embed HTML here.
        return [
            'total_revenue' => my_plugin_revenue_since($input['days'] ?? 30),
            'order_count'   => my_plugin_order_count_since($input['days'] ?? 30),
        ];
    },
    'permission_callback' => function() {
        return current_user_can('view_woocommerce_reports');
    },
    'meta' => [
        'public' => true,
        'annotations' => [
            'readonly' => true,
        ],
        'mcp' => [
            '_meta' => [
                'ui' => [
                    'resourceUri' => 'ui://my-plugin/sales-dashboard',
                    'visibility'  => ['model', 'app'],
                ],
            ],
        ],
    ],
]);
```

`meta.mcp._meta` is passed through to the tool's descriptor in `tools/list` untouched, which is what makes the binding possible.

`visibility` governs both who sees the tool and who may call it — `model` the language model, `app` the rendered UI. It defaults to `['model', 'app']`, so the example above states the default explicitly for clarity. The case worth reaching for is `['app']`: a host must leave such a tool out of the model's tool list entirely, which lets the dashboard call back for a refresh or a drill-down without those operations cluttering what the model sees.

The same `meta.mcp._meta` passthrough exists on all three component types — tool, resource and prompt — for any metadata you need to reach a client. As everywhere else, a value that would not serialize as a JSON object is dropped.

> **Do not embed the UI in the tool result.** Returning the `ui://` resource as a `resource` content block is a rejected alternative in the specification, not a shortcut — hosts render it as plain text. The tool result carries data; the template is fetched separately.

MCP Apps is an optional extension a client negotiates. A client that does not support it still gets your tool and its data normally, so the tool must stand on its own without the UI.

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
    'category' => 'site',
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
        'public' => true, // Expose to clients, including MCP
        'mcp' => [
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
    'category' => 'site',
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
        'public' => true, // Expose to clients, including MCP
        'mcp' => [
            'type'   => 'prompt' // Mark as prompt for auto-discovery
        ]
    ]
]);
```

### Messages Carrying Resources and Images

A message's `content` is not limited to text. Five block types are accepted — `text`, `image`, `audio`, `resource` and `resource_link` — so a prompt can hand the model a file, a screenshot or a pointer alongside its instructions:

```php
'execute_callback' => function($input) {
    $post = get_post($input['post_id']);

    return [
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => 'Review the post below against our style guide.',
                ],
            ],

            // An embedded resource: the content travels with the message.
            [
                'role' => 'user',
                'content' => [
                    'type' => 'resource',
                    'resource' => [
                        'uri'      => 'wordpress://post/' . $post->ID,
                        'mimeType' => 'text/markdown',
                        'text'     => $post->post_content,
                    ],
                    'annotations' => ['audience' => ['assistant']],
                ],
            ],

            // A resource link: a pointer the client fetches if it wants to.
            [
                'role' => 'user',
                'content' => [
                    'type'     => 'resource_link',
                    'uri'      => 'wordpress://style-guide',
                    'name'     => 'Editorial style guide',
                    'mimeType' => 'text/markdown',
                    'size'     => 4096,
                ],
            ],

            // An image: base64 in `data`.
            [
                'role' => 'user',
                'content' => [
                    'type'     => 'image',
                    'data'     => base64_encode(file_get_contents($screenshot)),
                    'mimeType' => 'image/png',
                ],
            ],
        ],
    ];
},
```

Three details decide whether these arrive intact:

- **An embedded `resource` needs a non-empty `uri`, plus `text` or `blob`.** These are the same resource contents a resource ability returns, and they take `_meta` the same way. A block missing them is delivered as a text block holding the block's JSON, with a warning logged — the surrounding messages are unaffected, so one bad block never costs the whole prompt.
- **`image` and `audio` blocks take base64 in `data`**, not raw bytes, and `mimeType` is required on both. This differs from a tool's image result, which takes raw bytes in `results`.
- **`resource_link` carries a pointer, not content** — no `text` or `blob`. Both `uri` and `name` are required. Its `size` is the byte count of the target; a value that is not a positive number is dropped rather than emitted, so a count read from stored data as `"4096"` still works.

Any block the schema refuses — a `resource_link` with no `name`, an `image` with no `mimeType` — is delivered as a text block carrying its JSON rather than failing the response, and the reason is logged.

`annotations` and `_meta` sit on the block itself in every case, exactly as on a tool result.

> `resource_link` and `audio` are prompt-message block types. A tool result only recognises `resource` and `image`; anything else it returns becomes ordinary tool data in a JSON text block.

### Prompt Annotations Summary

**Message Content Annotations** (in message `content.annotations`) are the only annotations a prompt has:
- Apply to individual messages within the prompt
- Provide metadata for specific message content
- Support: `audience`, `priority`, `lastModified`

There is no template-level equivalent. The MCP `Prompt` object carries no `annotations` field, so writing `meta.annotations` on a prompt ability has no effect — the value is not emitted, and no warning is logged.

### Key Points for Prompts

1. **Use `input_schema`** instead of `meta.mcp.arguments` - it provides validation and is automatically converted to MCP format
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
