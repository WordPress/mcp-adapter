# Ability → Resource Conversion Contract

This document provides a comprehensive technical reference for how WordPress abilities are converted to MCP resources. It is intended for plugin maintainers, code reviewers, and developers who need to understand the exact transformation rules.

> **For ability authors:** See [Creating Abilities](creating-abilities.md) for a user-friendly guide with examples.

## Overview

The MCP Adapter converts WordPress abilities registered via `wp_register_ability()` into MCP-compliant `Resource` objects per the [MCP 2025-11-25 specification](https://modelcontextprotocol.io/specification/2025-11-25/server/resources).

**Key files:**
- `includes/Domain/Resources/RegisterAbilityAsMcpResource.php` — Main converter
- `includes/Domain/Resources/McpResource.php` — Wrapper (execution + adapter metadata)
- `includes/Domain/Utils/McpAnnotationMapper.php` — Annotations mapping
- `includes/Domain/Utils/McpValidator.php` — Field validation
- `includes/Handlers/Resources/ResourcesHandler.php` — Request handling

---

## Resource vs Tool: Key Differences

Understanding the difference between Resources and Tools is essential for correct implementation:

| Aspect | **Tool** | **Resource** |
|--------|----------|--------------|
| Lookup method | By `name` (`tools/call { name: "..." }`) | By `uri` (`resources/read { uri: "..." }`) |
| Name restrictions | `[A-Za-z0-9_.-]`, 1-128 chars | **None** |
| Name sanitization | Required | Not required |
| Primary identifier | `name` | `uri` |
| Discovery | `tools/list` | `resources/list` |
| Execution | `tools/call` | `resources/read` |
| Response type | `CallToolResult` with `content[]` | `ReadResourceResult` with `contents[]` |
| Error handling | `isError: true` for execution errors | JSON-RPC errors only |

---

## Standardized Meta Structure

All MCP-specific ability metadata MUST be under the `mcp` namespace:

```php
'meta' => array(
    'mcp' => array(
        // Required
        'public' => true,                                 // Expose via MCP
        'type'   => 'resource',                           // Mark as resource
        'uri'    => 'WordPress://local/resource-1',       // RFC 3986 format (REQUIRED)

        // Optional
        'mimeType'    => 'text/plain',                    // MIME type of content
        'size'        => 1024,                            // Content size in bytes
        'annotations' => array(
            'audience'     => array( 'user', 'assistant' ),
            'priority'     => 0.8,
            'lastModified' => '2024-01-15T10:30:00Z',
        ),
        'icons'       => array(
            array(
                'src'      => 'https://example.com/icon.png',
                'mimeType' => 'image/png',
                'sizes'    => array( '32x32' ),
            ),
        ),
        '_meta'       => array(
            'custom_vendor' => array( 'feature_flag' => true ),
        ),
    ),
),
```

### Deprecation Notice

Top-level meta keys are **deprecated** as of n.e.x.t but still work with `_doing_it_wrong` notices:

| Deprecated | New Location | Notes |
|------------|--------------|-------|
| `meta.uri` | `meta.mcp.uri` | Required for resources |
| `meta.mimeType` | `meta.mcp.mimeType` | Optional |
| `meta.annotations` | `meta.mcp.annotations` | Optional |

**Migration example:**

```php
// ❌ DEPRECATED: Top-level keys (triggers _doing_it_wrong notice)
'meta' => array(
    'uri' => 'WordPress://local/resource-1',
    'annotations' => array( 'priority' => 0.8 ),
    'mcp' => array( 'public' => true, 'type' => 'resource' ),
)

// ✅ RECOMMENDED: All MCP params under mcp.* namespace
'meta' => array(
    'mcp' => array(
        'public' => true,
        'type'   => 'resource',
        'uri'    => 'WordPress://local/resource-1',
        'annotations' => array( 'priority' => 0.8 ),
    ),
)
```

---

## Field Mapping

### MCP Resource Fields

Per MCP 2025-11-25, a Resource object has these fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `uri` | string | **Yes** | Unique identifier (RFC 3986 format) |
| `name` | string | **Yes** | Resource name (no character restrictions) |
| `title` | string | No | Human-readable display name |
| `description` | string | No | Resource description for LLM hints |
| `mimeType` | string | No | MIME type of content (e.g., `text/plain`) |
| `size` | integer | No | Content size in bytes |
| `icons` | array | No | Array of icon objects for UI |
| `annotations` | object | No | Shared annotations (audience, priority, lastModified) |
| `_meta` | object | No | User-provided passthrough metadata |

### Source Mapping

| MCP Field | Source | Notes |
|-----------|--------|-------|
| `name` | `ability->get_name()` | Passed through directly (no sanitization) |
| `uri` | `meta['mcp']['uri']` | Validated RFC 3986, filterable |
| `title` | `ability->get_label()` | Included when non-empty |
| `description` | `ability->get_description()` | Included when non-empty |
| `mimeType` | `meta['mcp']['mimeType']` | Validated `type/subtype` format |
| `size` | `meta['mcp']['size']` | Must be positive integer |
| `icons` | `meta['mcp']['icons']` | Each icon validated individually |
| `annotations` | `meta['mcp']['annotations']` | Via `McpAnnotationMapper` |
| `_meta` | `meta['mcp']['_meta']` + adapter meta | User keys merged with adapter internal |

### Why Resources Don't Need Name Sanitization

Unlike tools (where `name` is the lookup identifier with strict charset rules), resources:

1. Are looked up by **URI**, not by name
2. Have **no charset restrictions** in the MCP specification
3. Use `name` for display/organizational purposes only

This means the ability name is passed through directly without transformation:

```php
// Ability name → Resource name (unchanged)
'my-plugin/site-config' → 'my-plugin/site-config'
```

---

## Validation Rules

### URI Validation (Fail-Fast)

The resource URI is **required** and must be RFC 3986 compliant:

```php
// Valid URIs:
'WordPress://local/resource-1'
'file:///path/to/file.txt'
'https://example.com/resource'
'custom-scheme://authority/path'

// Invalid URIs (cause WP_Error):
'no-scheme'               // Missing scheme
''                        // Empty string
'/relative/path'          // Missing scheme
```

**Validation regex:** `^[a-zA-Z][a-zA-Z0-9+.-]*:.+`

**Error behavior:** Missing or invalid URI returns `WP_Error` with code:
- `resource_uri_not_found` — URI not present in meta
- `resource_uri_invalid` — URI doesn't match RFC 3986 format
- `mcp_resource_uri_filter_invalid` — Filter returned invalid URI

### MIME Type Validation (Silent Skip)

MIME types must follow `type/subtype` format:

```php
// Valid MIME types:
'text/plain'
'application/json'
'image/png'
'text/x-rust'

// Invalid MIME types (silently skipped):
'invalid'                 // Missing subtype
'text'                    // Missing subtype
'text/'                   // Empty subtype
```

**Validation regex:** `^[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*$`

**Error behavior:** Invalid MIME type is silently skipped (field not included in output).

### Size Validation (Silent Skip)

Size must be a positive integer representing bytes:

```php
// Valid:
1024                      // 1 KB
1000000                   // ~1 MB

// Valid format but skipped (zero bytes not useful):
0                         // Empty resource

// Invalid (silently skipped):
-1                        // Negative
'1024'                    // String (must be integer)
1.5                       // Float
```

**Error behavior:** Zero or invalid size is silently skipped (zero-byte resources are not useful).

### Annotations Validation (Drop All + Notice)

Annotations are validated per MCP specification. If **any** annotation field is invalid, **all** annotations are dropped:

| Field | Validation | Examples |
|-------|------------|----------|
| `audience` | Non-empty array of `"user"` or `"assistant"` | `['user']`, `['user', 'assistant']` |
| `priority` | Number between 0.0 and 1.0 (inclusive) | `0.5`, `1.0`, `0` |
| `lastModified` | Valid ISO 8601 timestamp | `'2024-01-15T10:30:00Z'` |

**Why drop all?** Annotations are semantic hints that work together. Partial/invalid annotations could mislead LLM clients about resource usage.

**Error behavior:** Invalid annotations are dropped and `_doing_it_wrong()` is triggered (only fires in `WP_DEBUG` mode).

### Icons Validation (Filter Invalid)

Each icon is validated individually. Invalid icons are filtered out (graceful degradation):

| Field | Required | Validation |
|-------|----------|------------|
| `src` | **Yes** | Valid URL (http/https) or data: URI |
| `mimeType` | No | One of: `image/png`, `image/jpeg`, `image/jpg`, `image/svg+xml`, `image/webp` |
| `sizes` | No | Array of WxH strings (e.g., `"48x48"`) or `"any"` |
| `theme` | No | `"light"` or `"dark"` |

**Error behavior:** Invalid icons are logged as warnings and excluded. Valid icons are preserved.

---

## Filter Hooks

### Resource Name Filter

```php
/**
 * Filters the MCP resource name derived from an ability.
 *
 * Unlike tools, resource names have no charset restrictions.
 *
 * @since n.e.x.t
 *
 * @param string      $name    The resource name (ability name).
 * @param \WP_Ability $ability The source ability instance.
 */
$name = apply_filters( 'mcp_adapter_resource_name', $name, $ability );
```

**Example: Add vendor prefix**

```php
add_filter( 'mcp_adapter_resource_name', function( $name, $ability ) {
    if ( str_starts_with( $ability->get_name(), 'my-plugin/' ) ) {
        return 'acme/' . $name;
    }
    return $name;
}, 10, 2 );
```

**Note:** Filter return value must be a non-empty string. Invalid values fall back to original name.

### Resource URI Filter

```php
/**
 * Filters the MCP resource URI derived from an ability.
 *
 * @since n.e.x.t
 *
 * @param string      $uri     The validated resource URI.
 * @param \WP_Ability $ability The source ability instance.
 */
$uri = apply_filters( 'mcp_adapter_resource_uri', $uri, $ability );
```

**Example: Transform URI scheme**

```php
add_filter( 'mcp_adapter_resource_uri', function( $uri, $ability ) {
    // Change WordPress:// to custom://
    return str_replace( 'WordPress://', 'custom://', $uri );
}, 10, 2 );
```

**Warning:** If the filter returns an invalid URI (fails RFC 3986 validation), registration fails with `WP_Error`.

---

## Resource Content: Return Shapes

When the ability's `execute_callback` is invoked via `resources/read`, the adapter normalizes the return value into MCP-compliant `ReadResourceResult`.

### MCP ReadResourceResult Structure

```json
{
  "contents": [
    {
      "uri": "WordPress://local/resource-1",
      "mimeType": "text/plain",
      "text": "Resource content here"
    }
  ]
}
```

The `contents` array contains either `TextResourceContents` or `BlobResourceContents` objects.

### TextResourceContents

For text-based content:

```php
// TextResourceContents structure:
[
    'uri'      => 'WordPress://local/resource-1',  // Required
    'text'     => 'Content as string',             // Required
    'mimeType' => 'text/plain',                    // Optional
]
```

### BlobResourceContents

For binary content (base64-encoded):

```php
// BlobResourceContents structure:
[
    'uri'      => 'WordPress://local/resource-1',  // Required
    'blob'     => 'base64-encoded-data',           // Required
    'mimeType' => 'application/octet-stream',      // Optional
]
```

### Supported Return Formats

The adapter handles these return formats from `execute_callback`:

#### 1. Plain String (Simplest)

```php
'execute_callback' => function() {
    return 'Simple text content';
}

// Normalized to:
[
    TextResourceContents::fromArray([
        'uri'  => '<resource-uri>',
        'text' => 'Simple text content',
    ])
]
```

#### 2. Single Content Item (Structured)

```php
'execute_callback' => function() {
    return [
        'text'     => 'Content with metadata',
        'mimeType' => 'text/markdown',
    ];
}

// Normalized to:
[
    TextResourceContents::fromArray([
        'uri'      => '<resource-uri>',
        'text'     => 'Content with metadata',
        'mimeType' => 'text/markdown',
    ])
]
```

#### 3. Multiple Content Items

```php
'execute_callback' => function() {
    return [
        [
            'uri'  => 'WordPress://local/part1',
            'text' => 'First part',
        ],
        [
            'uri'  => 'WordPress://local/part2',
            'text' => 'Second part',
        ],
    ];
}

// Normalized to array of TextResourceContents
```

#### 4. Binary Content (Blob)

```php
'execute_callback' => function() {
    $image_data = file_get_contents( '/path/to/image.png' );
    return [
        'blob'     => base64_encode( $image_data ),
        'mimeType' => 'image/png',
    ];
}

// Normalized to:
[
    BlobResourceContents::fromArray([
        'uri'      => '<resource-uri>',
        'blob'     => '<base64-data>',
        'mimeType' => 'image/png',
    ])
]
```

#### 5. Associative Array/Object (JSON-wrapped)

```php
'execute_callback' => function() {
    return [
        'status'  => 'ok',
        'count'   => 42,
        'items'   => [ 'a', 'b', 'c' ],
    ];
}

// Normalized to:
[
    TextResourceContents::fromArray([
        'uri'  => '<resource-uri>',
        'text' => '{"status":"ok","count":42,"items":["a","b","c"]}',
    ])
]
```

### Content Detection Logic

The adapter uses this logic to determine content type:

```
1. Is $contents a string?
   └─► Yes: Wrap as TextResourceContents with resource URI

2. Is $contents an array?
   ├─► Has 'blob' key? → BlobResourceContents
   ├─► Has 'text' key? → TextResourceContents
   ├─► Is array of arrays with 'uri' or 'text'? → Multiple contents
   └─► Otherwise: JSON-encode as TextResourceContents

3. Other types (objects, null, etc.)
   └─► JSON-encode as TextResourceContents
```

---

## Performance: resources/list vs resources/read

### resources/list (Metadata Only)

The `resources/list` method returns resource **metadata only**. The ability's `execute_callback` is **NOT called**.

```php
// resources/list response (metadata from RegisterAbilityAsMcpResource):
{
    "resources": [
        {
            "uri": "WordPress://local/resource-1",
            "name": "my-plugin/resource",
            "title": "My Resource",
            "description": "Resource description",
            "mimeType": "text/plain"
        }
    ]
}
```

**Why this matters:**
- Resource listing is fast (no content generation)
- Expensive computations only happen on `resources/read`
- Clients can discover available resources without triggering side effects

### resources/read (Content Execution)

The `resources/read` method calls the ability's `execute_callback` to generate content:

```php
// resources/read request:
{ "uri": "WordPress://local/resource-1" }

// Triggers: ability->execute()

// resources/read response:
{
    "contents": [
        {
            "uri": "WordPress://local/resource-1",
            "text": "Generated content from execute_callback"
        }
    ]
}
```

**Important:** Keep `execute_callback` lightweight. For expensive operations:
- Use caching (transients, object cache)
- Implement lazy loading
- Consider pagination for large datasets

---

## Adapter Metadata

The adapter keeps ability linkage and other internal wiring on wrapper objects, not on Resource DTO `_meta`.

`_meta` on the Resource DTO is treated as user-provided metadata (from `ability.meta.mcp._meta`) and is passed through unchanged.

---

## Complete Conversion Examples

### Example 1: Basic Resource

```php
// Ability registration:
wp_register_ability( 'my-plugin/site-config', [
    'label'       => 'Site Configuration',
    'description' => 'WordPress site settings and configuration',
    'execute_callback' => function() {
        return [
            'site_name'   => get_bloginfo( 'name' ),
            'site_url'    => get_site_url(),
            'admin_email' => get_option( 'admin_email' ),
        ];
    },
    'permission_callback' => function() {
        return current_user_can( 'manage_options' );
    },
    'meta' => [
        'mcp' => [
            'public' => true,
            'type'   => 'resource',
            'uri'    => 'WordPress://site/config',
        ],
    ],
]);

// Resulting MCP Resource (resources/list):
{
    "uri": "WordPress://site/config",
    "name": "my-plugin/site-config",
    "title": "Site Configuration",
    "description": "WordPress site settings and configuration"
}

// Content (resources/read):
{
    "contents": [{
        "uri": "WordPress://site/config",
        "text": "{\"site_name\":\"My Site\",\"site_url\":\"https://example.com\",\"admin_email\":\"admin@example.com\"}"
    }]
}
```

### Example 2: Resource with All Options

```php
wp_register_ability( 'my-plugin/user-profile', [
    'label'       => 'User Profile',
    'description' => 'Current user profile data',
    'execute_callback' => function() {
        $user = wp_get_current_user();
        return "# User Profile\n\nName: {$user->display_name}\nEmail: {$user->user_email}";
    },
    'permission_callback' => function() {
        return is_user_logged_in();
    },
    'meta' => [
        'mcp' => [
            'public' => true,
            'type'   => 'resource',
            'uri'    => 'WordPress://users/profile',
            'mimeType' => 'text/markdown',
            'size'   => 256,
            'annotations' => [
                'audience'     => [ 'user', 'assistant' ],
                'priority'     => 0.8,
                'lastModified' => '2024-01-15T10:30:00Z',
            ],
            'icons' => [
                [
                    'src'      => 'https://example.com/icons/user.png',
                    'mimeType' => 'image/png',
                    'sizes'    => [ '32x32' ],
                ],
            ],
            '_meta' => [
                'vendor'  => 'my-plugin',
                'version' => '1.0.0',
            ],
        ],
    ],
]);

// Resulting MCP Resource:
{
    "uri": "WordPress://users/profile",
    "name": "my-plugin/user-profile",
    "title": "User Profile",
    "description": "Current user profile data",
    "mimeType": "text/markdown",
    "size": 256,
    "annotations": {
        "audience": ["user", "assistant"],
        "priority": 0.8,
        "lastModified": "2024-01-15T10:30:00Z"
    },
    "icons": [{
        "src": "https://example.com/icons/user.png",
        "mimeType": "image/png",
        "sizes": ["32x32"]
    }],
    "_meta": {
        "vendor": "my-plugin",
        "version": "1.0.0"
    }
}
```

### Example 3: Resource with Binary Content

```php
wp_register_ability( 'my-plugin/logo-image', [
    'label'       => 'Site Logo',
    'description' => 'The site logo image',
    'execute_callback' => function() {
        $logo_id  = get_theme_mod( 'custom_logo' );
        $logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
        $logo_data = file_get_contents( $logo_url );

        return [
            'blob'     => base64_encode( $logo_data ),
            'mimeType' => 'image/png',
        ];
    },
    'permission_callback' => '__return_true',
    'meta' => [
        'mcp' => [
            'public'   => true,
            'type'     => 'resource',
            'uri'      => 'WordPress://site/logo',
            'mimeType' => 'image/png',
        ],
    ],
]);

// Content (resources/read):
{
    "contents": [{
        "uri": "WordPress://site/logo",
        "blob": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJ...",
        "mimeType": "image/png"
    }]
}
```

### Example 4: Resource with Multiple Content Parts

```php
wp_register_ability( 'my-plugin/readme', [
    'label'       => 'Plugin README',
    'description' => 'Plugin documentation in multiple parts',
    'execute_callback' => function() {
        return [
            [
                'uri'  => 'WordPress://plugin/readme/overview',
                'text' => '# Overview\n\nPlugin description here.',
            ],
            [
                'uri'  => 'WordPress://plugin/readme/installation',
                'text' => '# Installation\n\n1. Upload the plugin...',
            ],
            [
                'uri'  => 'WordPress://plugin/readme/changelog',
                'text' => '# Changelog\n\n## 1.0.0\n- Initial release',
            ],
        ];
    },
    'permission_callback' => '__return_true',
    'meta' => [
        'mcp' => [
            'public'   => true,
            'type'     => 'resource',
            'uri'      => 'WordPress://plugin/readme',
            'mimeType' => 'text/markdown',
        ],
    ],
]);

// Content (resources/read):
{
    "contents": [
        {"uri": "WordPress://plugin/readme/overview", "text": "# Overview..."},
        {"uri": "WordPress://plugin/readme/installation", "text": "# Installation..."},
        {"uri": "WordPress://plugin/readme/changelog", "text": "# Changelog..."}
    ]
}
```

---

## Error Handling

### Registration Errors

| Error Code | Cause | Resolution |
|------------|-------|------------|
| `resource_uri_not_found` | No URI in meta | Add `meta.mcp.uri` |
| `resource_uri_invalid` | URI doesn't match RFC 3986 | Fix URI format (must have scheme) |
| `mcp_resource_uri_filter_invalid` | Filter returned invalid URI | Fix filter callback |
| `mcp_resource_schema_invalid` | DTO construction failed | Check all field types |

### Runtime Errors (resources/read)

| Scenario | Response |
|----------|----------|
| Resource not found | JSON-RPC error `-32002` |
| Permission denied | JSON-RPC error `-32403` |
| Ability execution error | JSON-RPC error `-32603` |

**Note:** Unlike tools, resources don't have `isError: true` concept. All errors are protocol-level JSON-RPC errors.

---

## MCP Compliance Notes

### Supported Resource Fields (2025-11-25)

| Field | Status | Notes |
|-------|--------|-------|
| `uri` | ✅ Supported | Required, RFC 3986 validated |
| `name` | ✅ Supported | From ability name (no sanitization) |
| `title` | ✅ Supported | From ability label |
| `description` | ✅ Supported | From ability description |
| `mimeType` | ✅ Supported | Validated format |
| `size` | ✅ Supported | Positive integer (bytes) |
| `icons` | ✅ Supported | From `meta.mcp.icons` |
| `annotations` | ✅ Supported | Shared Annotations (not ToolAnnotations) |
| `_meta` | ✅ Supported | User passthrough; adapter keys stripped |

### Supported ResourceContents Types

| Type | Status | Notes |
|------|--------|-------|
| `TextResourceContents` | ✅ Supported | For text content |
| `BlobResourceContents` | ✅ Supported | For binary content (base64) |

### Specification References

- [MCP Resources Specification](https://modelcontextprotocol.io/specification/2025-11-25/server/resources)
- [Resource Type Definition](https://modelcontextprotocol.io/specification/2025-11-25/server/resources#resource-types)
- [ResourceContents Types](https://modelcontextprotocol.io/specification/2025-11-25/server/resources#resource-contents)
- [Shared Annotations](https://modelcontextprotocol.io/specification/2025-11-25/basic/types#annotations)

---

## See Also

- [Creating Abilities](creating-abilities.md) — User guide for ability authors
- [Ability → Tool Conversion](ability-tool-conversion.md) — Tool conversion contract
- [Architecture Overview](../architecture/overview.md) — System design
- [Error Handling](error-handling.md) — Error response format
