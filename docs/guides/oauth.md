# OAuth sign-in for MCP clients

The MCP Adapter plugin runs a small OAuth authorization server. An MCP client that supports OAuth, such as Claude, ChatGPT, Cursor or VS Code, then connects with only the endpoint URL: the person signs in to WordPress in their browser and approves the app. They do not create or paste an Application Password.

Application Passwords keep working next to OAuth, and so do plugins that accept their own bearer tokens, such as JWT plugins: a bearer token this site did not sign is left to them.

## Who runs it

Only the standalone MCP Adapter plugin starts OAuth. A plugin that bundles MCP Adapter as a Composer library does not, so bundling the library never adds an authorization server to a site.

If another copy of this code already started on the site, such as a copy bundled under a prefixed namespace, later copies stand aside. The first copy to start fires the `mcp_adapter_oauth_init` action, and every copy checks it before starting.

## What a token can do

OAuth here only signs the app in. A token acts as the WordPress user who approved the connection, and that user's capabilities decide what it can do, exactly as in wp-admin. There are no OAuth scopes and no per-connection permissions. The scope name `general` is advertised because clients expect one; it is never enforced.

A token works on the whole REST API of the site where it was approved, not on one route. That covers the default server, every server registered through `mcp_adapter_init`, whatever its route, and core routes such as `/wp/v2/users/me`. Each server's transport permission check still applies on top; see [Transport Permissions](transport-permissions.md).

On multisite, a connection belongs to the site where it was approved. A token approved on one site is refused on the others, but abilities that act across the network can still reach other sites where the user has the capability, and the consent screen says so.

## Requirements

OAuth turns on by itself when all of these are true:

- The site address uses HTTPS. A site whose environment type is `local` is exempt.
- Permalinks use any structure other than Plain.
- The site is at the root of its domain. Sites in a subdirectory are not supported yet.

`\WP\MCP\OAuth\Module::unmet_requirements()` returns one sentence for each requirement the site does not meet.

Apps that identify themselves with a Client ID Metadata Document need PHP's curl extension, no `WP_PROXY_HOST`, and no `WP_HTTP_BLOCK_EXTERNAL`. Otherwise the server stops advertising that option, and those apps fall back to dynamic registration. The document's host must have a public IPv4 address.

## Connecting

1. Add `https://your-site/wp-json/mcp/mcp-adapter-default-server` to the client as a remote (Streamable HTTP) MCP server, with no credentials. Any other MCP Adapter server URL works the same way.
2. The client opens the browser. Log in to WordPress if asked.
3. The consent screen shows the app's name, the address it returns to, and the account it will act as. Choose **Approve**.

The return address deserves a look. An app that proves its identity with a Client ID Metadata Document is shown with its verified host. An app that registered itself is shown as self-registered, because anyone can pick any name. When the app returns to `localhost` or to its own scheme, such as Cursor's `cursor://`, the approval goes to an app on the person's computer, and the consent screen warns to approve only a connection they just started.

## How long a connection lasts

An access token lasts one hour. The client then uses its refresh token to get a new pair. Each refresh token works once, and a used one coming back revokes the whole connection, since that means it was copied.

A connection survives as long as the client refreshes at least once every 30 days. A client that is used regularly stays connected until it is revoked.

## Disconnecting

- **Users → Profile → MCP connections** lists each app with a **Revoke** button. Anyone who can edit a user can revoke that user's connections from the Edit User screen.
- Changing the user's password disconnects all of that user's apps.
- Removing a user from a site deletes their connections on that site.
- Changing the `AUTH_SALT` or `AUTH_KEY` constants disconnects every app on the site.

## Endpoints

| URL | Purpose |
| --- | --- |
| `/.well-known/oauth-protected-resource/wp-json/<route>` | Protected resource metadata (RFC 9728) for any REST URL. Every REST `401` response names this URL in its `WWW-Authenticate` header. |
| `/.well-known/oauth-authorization-server/mcp-adapter/oauth` | Authorization server metadata (RFC 8414). The issuer is `https://your-site/mcp-adapter/oauth`. |
| `/mcp-adapter/oauth/authorize` | Authorization endpoint. It forwards to the consent screen in wp-admin. |
| `POST /wp-json/mcp-adapter/v1/oauth/token` | Token endpoint: `authorization_code` with PKCE S256, and `refresh_token`. |
| `POST /wp-json/mcp-adapter/v1/oauth/register` | Dynamic client registration (RFC 7591). Registration stores nothing; the client ID is signed. |

Only public clients are supported: there are no client secrets, and PKCE with S256 is required. Tokens are accepted only in the `Authorization: Bearer` header.

An app's return address must be HTTPS, plain HTTP on `localhost`, `127.0.0.1` or `[::1]` (any port), or the app's own scheme, such as `cursor://anysphere.cursor-mcp/oauth/callback` or `com.example.app:/callback`. Schemes the browser handles itself, such as `javascript:`, `data:` and `file:`, are refused. The MCP specification allows only HTTPS and localhost; app schemes are accepted so that Cursor can connect, and PKCE keeps a code intercepted by another app useless.

### nginx

WordPress serves the `/.well-known/oauth-*` URLs itself, so the web server must pass them to `index.php`. The standard WordPress rules for Apache do. On nginx, a rule that serves `/.well-known/` as static files, or that denies paths starting with a dot, stops discovery. Add this before such rules:

```nginx
location ^~ /.well-known/oauth- {
    try_files $uri /index.php?$args;
}
```

## Hooks

| Hook | Type | Default | Purpose |
| --- | --- | --- | --- |
| `mcp_adapter_oauth_enabled` | filter | `true` | Return `false` to refuse OAuth and keep only Application Passwords. It cannot turn OAuth on when a requirement is missing. |
| `mcp_adapter_oauth_access_token_ttl` | filter | `3600` | Access token lifetime in seconds. Minimum 60. |
| `mcp_adapter_oauth_refresh_token_ttl` | filter | 30 days | How long a connection survives without a refresh, in seconds. Minimum one hour. |
| `mcp_adapter_oauth_init` | action | | Fires once OAuth has registered its hooks. Other copies of this code check it and do not start. |

## Storage

Each connection is one user meta row, `{prefix}mcp_adapter_oauth_grant_{id}`, which holds the app's name and return address, digests of the current tokens, and timestamps. Tokens themselves are never stored. They are signed with keys derived from the site's auth salt.

Connections are not Application Passwords. An Application Password never expires, and core keeps all of a user's Application Passwords in one serialized value that it rewrites without locking, so detecting a reused refresh token there could not be done safely. One row per connection allows a compare-and-swap on every rotation.
