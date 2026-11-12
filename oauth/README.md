# OAuth server

An OAuth 2.1 authorization server for WordPress. It uses Application Password records as user-visible connection and revocation anchors, but issues separate OAuth credentials. It lets an application such as an MCP client connect to the site as a user, with that user's permissions, after the user approves it on a consent screen.

This folder is self-contained: it has its own namespace (`WP\OAuth`), loader, and `oauth_server_` prefixes, and nothing in it depends on the rest of the MCP Adapter. The adapter only tells it which routes to protect. That keeps it easy to move into its own plugin or into core later.

## How it works

1. An unauthenticated request to a protected route gets a 401 with a `WWW-Authenticate` challenge pointing at the protected resource metadata (RFC 9728), which points at the authorization server metadata (RFC 8414).
2. The application sends the user to `wp-login.php?action=oauth_authorize` with PKCE (S256) and a `state` value. The user logs in if needed and approves on the consent screen.
3. The application exchanges the code at `/wp-json/oauth/v1/token`. This creates an Application Password record for the user, named after the application, but immediately discards its raw password.
4. The token service issues a one-hour opaque access token and a rotating 30-day refresh token. Only SHA-256 hashes of those random credentials are stored. Neither token is an Application Password or works with Basic auth.
5. A successful refresh rotates both credentials. Reusing a rotated refresh token revokes the connection. Deleting the Application Password record or calling `/wp-json/oauth/v1/revoke` (RFC 7009) also cuts the connection off. Deleting or trashing the client removes all of its connections, for every user.

Protocol endpoints depend on `TokenServiceInterface`, which owns token issuance, authentication, refresh, and revocation. `OpaqueTokenService` is the current strategy. It depends in turn on `ConnectionStoreInterface`; `ApplicationPasswordConnectionStore` is the current persistence implementation. A JWT strategy can therefore replace the opaque token service without moving connection lifecycle or client approval into the token format.

Clients are registered by an administrator under **Settings > OAuth Clients**. Each client is a private `oauth_server_client` post, as in WP-API/OAuth2: the client ID is the post slug, and the redirect URIs and secret hash are post meta.

## Current scope

This stage supports admin-registered clients, authorization code with required PKCE, short-lived access tokens, rotating refresh tokens, and revocation. Access tokens work on every REST route. Later stages add Client ID Metadata Documents, Dynamic Client Registration, and scopes.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `oauth_server_enabled` | filter | Return false to turn the server off. |
| `oauth_server_protected_resources` | filter | REST routes to protect, such as `/mcp/mcp-adapter-default-server`. |
| `oauth_server_token_service` | filter | Replace the opaque token strategy with another `TokenServiceInterface` implementation. |
| `oauth_server_authorization_server_metadata` | filter | RFC 8414 metadata. |
| `oauth_server_protected_resource_metadata` | filter | RFC 9728 metadata for a route. |
| `oauth_server_www_authenticate_challenge` | filter | The challenge sent with 401 responses. |
| `oauth_server_max_sessions_per_client` | filter | Connections one user can have to one client (default 5). |
| `oauth_server_consent_form` | action | Add fields to the consent screen. |

The server also fires core's `application_password_did_authenticate` action when a bearer token is used, so Application Password integrations see OAuth requests too.

## Hosting: /.well-known/ conflicts

The discovery documents are served by WordPress, so requests for `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` must reach it. That needs pretty permalinks. Some hosts route the whole `/.well-known/` prefix to a folder used for certificate renewal, so these paths return a 404 before WordPress runs. **Tools > Site Health** flags this. The fix is a web server change.

Apache, in the vhost or in `.htaccess` above the WordPress block:

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} ^/.well-known/oauth-(protected-resource|authorization-server)(/.*)?$
RewriteRule ^ /index.php [L]
</IfModule>
```

If the host scopes `/.well-known/` with `AllowOverride None`, the vhost must change too.

Nginx, which prefers these prefix matches over a broader `/.well-known/` block:

```nginx
location ^~ /.well-known/oauth-protected-resource {
    try_files $uri /index.php?$args;
}

location ^~ /.well-known/oauth-authorization-server {
    try_files $uri /index.php?$args;
}
```

Purge any CDN or page cache afterwards, and check from outside the server, since the Site Health check runs from the server to itself.

## Testing

The [OAuth + MCP Debugger](https://make.hmn.md/mcp-adapter/oauth/) is a browser client that runs the whole flow and logs every request. Register `https://make.hmn.md/mcp-adapter/oauth/` as a redirect URI for a client, open the debugger, and enter the site's MCP URL and the client ID.

## Credits

This builds on two existing projects:

- [WP-API/OAuth2](https://github.com/WP-API/OAuth2): the login-screen authorization flow, admin client screens, discovery path handling, challenge format, and REST index entry.
- [wp-media/mcp-oauth](https://github.com/wp-media/mcp-oauth): tokens backed by Application Passwords, single-use consent, the per-client session limit, revocation, and the Site Health check and hosting notes above.
