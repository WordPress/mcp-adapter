# Ability → Tool Conversion Contract

This document provides a comprehensive technical reference for how WordPress abilities are converted to MCP tools. It is intended for plugin maintainers, code reviewers, and developers who need to understand the exact transformation rules.

> **For ability authors:** See [Creating Abilities](creating-abilities.md) for a user-friendly guide with examples.

## Overview

The MCP Adapter converts WordPress abilities registered via `wp_register_ability()` into MCP-compliant `Tool` objects per the [MCP 2025-11-25 specification](https://modelcontextprotocol.io/specification/2025-11-25/server/tools).

**Key files:**
- `includes/Domain/Tools/RegisterAbilityAsMcpTool.php` — Main converter
- `includes/Domain/Utils/McpNameSanitizer.php` — Name sanitization
- `includes/Domain/Utils/SchemaTransformer.php` — Schema normalization
- `includes/Domain/Utils/McpAnnotationMapper.php` — Annotations mapping
- `includes/Domain/Tools/ToolMetadataHelper.php` — Metadata access

---

## Tool Name Derivation

### MCP Specification Requirements

Per MCP 2025-11-25, tool names must follow these rules:

| Rule | Value |
|------|-------|
| Length | 1–128 characters |
| Allowed characters | `A-Za-z0-9_.-` (letters, digits, underscore, hyphen, dot) |
| Case sensitivity | Case-sensitive |
| Uniqueness | SHOULD be unique within a server |

Regex pattern: `^[a-zA-Z0-9_.-]{1,128}$`

### Sanitization Algorithm

The `McpNameSanitizer::sanitize_name()` method implements best-effort sanitization:

```
1. TRIM whitespace from both ends
2. REPLACE `/` with `-` (forward slash not allowed in MCP)
3. VALIDATE against MCP rules:
   └─► VALID? → Return as-is ✓

4. If INVALID → SANITIZE:
   ├─ Transliterate accents using remove_accents() (é→e, ü→u, ñ→n)
   ├─ Replace remaining invalid chars with `-`
   ├─ Collapse consecutive `-` runs into single `-`
   └─ Trim leading/trailing `-` and `_`

5. If LENGTH > 128 → TRUNCATE:
   └─ Keep first 115 chars + `-` + 12-char MD5 hash of original
   └─ Result: max 115 + 1 + 12 = 128 chars

6. FINAL VALIDATION:
   ├─► VALID? → Return sanitized name
   └─► STILL INVALID/EMPTY? → Return WP_Error, skip tool registration
```

### Sanitization Examples

**Note:** WordPress abilities follow strict naming rules (`^[a-z0-9-]+/[a-z0-9-]+$`), so in practice the sanitizer primarily handles the `/` → `-` transformation. The examples below show what the algorithm *can* handle defensively for forward compatibility and edge cases:

| Input | Sanitized Output | Notes |
|-------|------------------|-------|
| `my-plugin/create-post` | `my-plugin-create-post` | **Typical case:** `/` replaced with `-` |
| `créer-post` | `creer-post` | Accents transliterated (defensive) |
| `über café naïve` | `uber-cafe-naive` | Accents + spaces (defensive) |
| `my tool!@#$` | `my-tool` | Invalid chars (defensive) |
| `a` × 150 chars | `aaa...aaa-abc123def456` | Truncated with hash |
| `!!!` | `WP_Error` | Unsalvageable (empty after sanitization) |

### Filter Hook

After sanitization, the name passes through a filter:

```php
/**
 * Filters the MCP tool name derived from an ability.
 *
 * @since n.e.x.t
 *
 * @param string      $name    The sanitized tool name.
 * @param \WP_Ability $ability The source ability instance.
 */
$filtered_name = apply_filters( 'mcp_adapter_tool_name', $sanitized_name, $ability );
```

**Important:** If the filter returns an invalid name (fails MCP validation), the tool registration fails with `WP_Error`.

### Collision Behavior

Name collisions are handled by `McpComponentRegistry`:
- Default behavior: First-registered wins
- Collisions are logged as warnings
- Name normalization can cause collisions (e.g., `foo/bar` and `foo-bar` both become `foo-bar`)

---

## Schema Transformation

### MCP ToolInputSchema Specification

Per MCP 2025-11-25, `ToolInputSchema` is a **restricted subset** of JSON Schema with only these fields:

| Field | Required | Description |
|-------|----------|-------------|
| `$schema` | No | JSON Schema dialect (defaults to 2020-12) |
| `type` | Yes | Must be literal `"object"` |
| `properties` | No | Map of property name → JSON Schema object |
| `required` | No | Array of required property names |

**Key limitation:** `additionalProperties` is NOT part of MCP ToolInputSchema. Any such field is silently dropped.

### SchemaTransformer Behavior

The `SchemaTransformer::transform_to_object_schema()` method ensures all schemas are MCP-compliant:

#### Case 1: Empty or Null Schema (No Arguments)

```php
// Input: null or []
// Output:
[
    'schema' => ['type' => 'object'],
    'was_transformed' => false,
    'wrapper_property' => null
]
```

MCP clients see `{ "type": "object" }` which, per JSON Schema defaults, accepts any properties.

#### Case 2: Schema Missing `type` Field

```php
// Input: ['properties' => ['foo' => ['type' => 'string']]]
// Output: Same array with 'type' => 'object' added
[
    'schema' => [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string']]
    ],
    'was_transformed' => false,
    'wrapper_property' => null
]
```

The transformer adds `type: object` automatically since it's the only valid value.

#### Case 3: Already Object Type (No Transformation)

```php
// Input:
['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]

// Output: Passed through unchanged
[
    'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    'was_transformed' => false,
    'wrapper_property' => null
]
```

#### Case 4: Flattened Schema (Wrapping Required)

```php
// Input: ['type' => 'string', 'description' => 'User name']

// Output: Wrapped in object with 'input' property
[
    'schema' => [
        'type' => 'object',
        'properties' => [
            'input' => ['type' => 'string', 'description' => 'User name']
        ],
        'required' => ['input']
    ],
    'was_transformed' => true,
    'wrapper_property' => 'input'
]
```

For output schemas, the wrapper property is `'result'` instead of `'input'`.

### DTO Preparation

Before creating the `Tool` DTO, schemas are prepared via `prepare_schema_for_dto()`:

```php
// php-mcp-schema expects properties values to be objects (stdClass), not arrays
// This method recursively converts nested schema arrays
$schema['properties'] = [
    'name' => (object) ['type' => 'string'],  // stdClass, not array
    'age'  => (object) ['type' => 'number']
];
```

---

## Annotations Mapping

### MCP Annotation Types

MCP 2025-11-25 defines **two distinct annotation types**:

| Type | Used By | Fields |
|------|---------|--------|
| `ToolAnnotations` | Tools only | `title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` |
| `Annotations` (shared) | Resources, Prompts, Content | `audience`, `priority`, `lastModified` |

**Important:** Tools use `ToolAnnotations`, NOT shared `Annotations`. The MCP Adapter only maps tool-specific fields for tools.

### McpAnnotationMapper Behavior

The `McpAnnotationMapper::map()` method handles field mapping and value normalization:

#### Field Mapping (Tools)

| WordPress Format | MCP Format | Description |
|-----------------|------------|-------------|
| `readonly` | `readOnlyHint` | Tool doesn't modify data |
| `destructive` | `destructiveHint` | Tool may delete/destroy data |
| `idempotent` | `idempotentHint` | Same input → same output |
| *(no equivalent)* | `openWorldHint` | Can interact with external entities |
| *(no equivalent)* | `title` | Human-readable title |

WordPress format takes precedence when both are provided.

#### Boolean Normalization (Strict)

Boolean fields use strict parsing to avoid PHP's loose casting issues:

```php
// Accepted values (normalize to true):
true, 1, '1', 'true', 'TRUE', 'True'

// Accepted values (normalize to false):
false, 0, '0', 'false', 'FALSE', 'False'

// Rejected values (field dropped):
'yes', 'no', '', null, [], 2, -1, 'on', 'off'
```

**Why strict?** PHP's `(bool)` cast incorrectly converts `'false'` to `true` (non-empty string).

#### String Normalization

String fields (e.g., `title`) must be non-empty after trimming:

```php
// Accepted: 'My Tool', ' My Tool ' (trimmed)
// Rejected: '', '   ', null, [], 123 (dropped)
```

#### Validation

After mapping, annotations can optionally be validated via `McpValidator::get_tool_annotation_validation_errors()`:

- Boolean fields must be actual booleans
- String fields must be non-empty strings
- Unknown fields are ignored (forward compatibility)

---

## Internal Metadata (`_meta`)

### Purpose

The adapter stores debug/transformation metadata in `_meta['mcp_adapter']`. This metadata is:
- **Internal only** — stripped before sending responses to clients
- **Useful for debugging** — tracks transformation decisions
- **Accessible via `ToolMetadataHelper`** — for adapter-internal use

### Structure

```php
$tool_data['_meta'] = [
    'mcp_adapter' => [
        'ability' => 'my-plugin/create-post',  // Always present

        // Only present when input schema was transformed (wrapped):
        'input_schema_transformed' => true,
        'input_schema_wrapper' => 'input',

        // Only present when output schema exists AND was transformed:
        'output_schema_transformed' => true,
        'output_schema_wrapper' => 'result',
    ]
];
```

### MetaStripper Behavior

The `MetaStripper::strip_array()` method removes adapter metadata before client responses:

1. Removes `_meta['mcp_adapter']` key (the adapter's internal data)
2. If `_meta` becomes empty after removal, removes `_meta` entirely
3. Recursively processes nested structures

```php
// Before stripping:
['name' => 'my-tool', 'inputSchema' => [...], '_meta' => ['mcp_adapter' => [...]]]

// After stripping (mcp_adapter was only key):
['name' => 'my-tool', 'inputSchema' => [...]]

// If _meta had other keys, they would remain:
['name' => 'my-tool', '_meta' => ['other_source' => [...]]]
```

### ToolMetadataHelper API

```php
use WP\MCP\Domain\Tools\ToolMetadataHelper;

// Get the source ability name
$ability_name = ToolMetadataHelper::get_ability_name($tool);  // 'my-plugin/create-post'

// Check if input schema was wrapped
$was_wrapped = ToolMetadataHelper::is_input_transformed($tool);  // true/false

// Get wrapper property name
$wrapper = ToolMetadataHelper::get_input_wrapper($tool);  // 'input' (default)

// Same for output schema
$output_wrapped = ToolMetadataHelper::is_output_transformed($tool);
$output_wrapper = ToolMetadataHelper::get_output_wrapper($tool);  // 'result' (default)
```

---

## Complete Conversion Examples

### Example 1: Tool with No Arguments

```php
// Ability registration:
wp_register_ability('my-plugin/get-site-info', [
    'label' => 'Get Site Info',
    'description' => 'Returns basic site information',
    'execute_callback' => 'get_site_info_callback',
    'permission_callback' => '__return_true',
    'meta' => ['mcp' => ['public' => true]]
]);

// Resulting MCP Tool:
{
    "name": "my-plugin-get-site-info",
    "title": "Get Site Info",
    "description": "Returns basic site information",
    "inputSchema": {
        "type": "object"
    }
}
```

### Example 2: Tool with Scalar Input (Flattened Schema)

```php
// Ability registration:
wp_register_ability('my-plugin/count-posts', [
    'label' => 'Count Posts',
    'description' => 'Count posts by type',
    'input_schema' => [
        'type' => 'string',
        'description' => 'Post type',
        'enum' => ['post', 'page']
    ],
    'execute_callback' => 'count_posts_callback',
    'permission_callback' => '__return_true',
    'meta' => ['mcp' => ['public' => true]]
]);

// Resulting MCP Tool:
{
    "name": "my-plugin-count-posts",
    "title": "Count Posts",
    "description": "Count posts by type",
    "inputSchema": {
        "type": "object",
        "properties": {
            "input": {
                "type": "string",
                "description": "Post type",
                "enum": ["post", "page"]
            }
        },
        "required": ["input"]
    }
}

// Internal _meta (stripped from client response):
{
    "_meta": {
        "mcp_adapter": {
            "ability": "my-plugin/count-posts",
            "input_schema_transformed": true,
            "input_schema_wrapper": "input"
        }
    }
}
```

### Example 3: Tool with Annotation Hints

```php
// Ability registration:
wp_register_ability('my-plugin/delete-post', [
    'label' => 'Delete Post',
    'description' => 'Permanently delete a post',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'description' => 'Post ID']
        ],
        'required' => ['post_id']
    ],
    'execute_callback' => 'delete_post_callback',
    'permission_callback' => fn() => current_user_can('delete_posts'),
    'meta' => [
        'mcp' => ['public' => true],
        'annotations' => [
            'readonly' => false,      // WordPress format → readOnlyHint
            'destructive' => true,    // WordPress format → destructiveHint
            'idempotent' => true,     // WordPress format → idempotentHint
            'title' => 'Post Deleter' // MCP format (no WordPress equivalent)
        ]
    ]
]);

// Resulting MCP Tool:
{
    "name": "my-plugin-delete-post",
    "title": "Delete Post",
    "description": "Permanently delete a post",
    "inputSchema": {
        "type": "object",
        "properties": {
            "post_id": {
                "type": "integer",
                "description": "Post ID"
            }
        },
        "required": ["post_id"]
    },
    "annotations": {
        "readOnlyHint": false,
        "destructiveHint": true,
        "idempotentHint": true,
        "title": "Post Deleter"
    }
}
```

### Example 4: Tool with Long Name (Truncation Demo)

WordPress ability names follow strict rules (`^[a-z0-9-]+/[a-z0-9-]+$`), so the sanitization algorithm primarily handles the `/` → `-` transformation. However, for very long names that exceed MCP's 128-character limit, the truncation algorithm applies:

```php
// Ability registration with a very long name (140 characters):
wp_register_ability('my-enterprise-platform-plugin/extremely-long-and-very-descriptive-action-name-that-fully-describes-what-this-ability-does-in-complete-detail', [
    'label' => 'Long Action',
    'description' => 'An ability with a very long name',
    'execute_callback' => 'long_action_callback',
    'permission_callback' => '__return_true',
    'meta' => ['mcp' => ['public' => true]]
]);

// Name sanitization steps:
// 1. Original: 'my-enterprise-platform-plugin/extremely-long-and-very-...' (140 chars)
// 2. Replace /: 'my-enterprise-platform-plugin-extremely-long-and-very-...' (140 chars)
// 3. Valid charset: Yes (only lowercase, hyphens, alphanumeric)
// 4. Length > 128: TRUNCATE
//    - Keep first 115 chars: 'my-enterprise-platform-plugin-extremely-long-and-very-descriptive-action-name-that-fully-describes-what-this-abilit'
//    - Append '-' + 12-char MD5 hash of original: '-097e209d832c'
//    - Result: 115 + 1 + 12 = 128 chars

// Resulting MCP Tool:
{
    "name": "my-enterprise-platform-plugin-extremely-long-and-very-descriptive-action-name-that-fully-describes-what-this-abilit-097e209d832c",
    "title": "Long Action",
    "description": "An ability with a very long name",
    "inputSchema": {
        "type": "object"
    }
}
```

### Example 5: Customizing Tool Names with Filter

Use the `mcp_adapter_tool_name` filter to customize the derived tool name:

```php
// Ability registration:
wp_register_ability('my-plugin/create-post', [
    'label' => 'Create Post',
    'description' => 'Creates a new post',
    'execute_callback' => 'create_post_callback',
    'permission_callback' => '__return_true',
    'meta' => ['mcp' => ['public' => true]]
]);

// Filter to add vendor prefix:
add_filter('mcp_adapter_tool_name', function($name, $ability) {
    if (str_starts_with($ability->get_name(), 'my-plugin/')) {
        return 'acme.' . $name;  // Dots are valid in MCP tool names
    }
    return $name;
}, 10, 2);

// Resulting MCP Tool:
{
    "name": "acme.my-plugin-create-post",
    "title": "Create Post",
    "description": "Creates a new post",
    "inputSchema": {
        "type": "object"
    }
}
```

**Warning:** If the filter returns an invalid name (wrong characters or length > 128), the tool registration will fail with `WP_Error`.

---

## Error Handling

### Tool Registration Failures

The converter returns `WP_Error` in these cases:

| Error Code | Cause |
|------------|-------|
| `mcp_name_invalid` | Name is unsalvageable (empty after sanitization) |
| `mcp_tool_name_filter_invalid` | Filter returned invalid name |
| `mcp_tool_schema_invalid` | DTO construction failed |

### Graceful Degradation

- Invalid annotation values are **dropped** (not rejected)
- Schema transformation issues are **logged** but don't fail registration
- Filter errors cause tool to be **skipped** with error logged

---

## MCP Compliance Notes

### Supported Tool Fields (2025-11-25)

| Field | Status | Notes |
|-------|--------|-------|
| `name` | ✅ Supported | Sanitized from ability name |
| `title` | ✅ Supported | From ability label |
| `description` | ✅ Supported | From ability description |
| `inputSchema` | ✅ Supported | Transformed to object type |
| `outputSchema` | ✅ Supported | Optional, transformed if present |
| `annotations` | ✅ Supported | ToolAnnotations fields only |
| `_meta` | ✅ Supported | Internal, stripped before response |
| `execution` | ❌ Not supported | Added in 2025-11-25, not mapped |
| `icons` | ❌ Not supported | Added in 2025-11-25, not mapped |

### Specification References

- [MCP Tools Specification](https://modelcontextprotocol.io/specification/2025-11-25/server/tools)
- [Tool Names](https://modelcontextprotocol.io/specification/2025-11-25/server/tools#tool-names)
- [ToolInputSchema](https://modelcontextprotocol.io/specification/2025-11-25/server/tools#tool-definition)
- [ToolAnnotations](https://modelcontextprotocol.io/specification/2025-11-25/server/tools#tool-annotations)

---

## See Also

- [Creating Abilities](creating-abilities.md) — User guide for ability authors
- [Architecture Overview](../architecture/overview.md) — System design
- [Error Handling](error-handling.md) — Error response format