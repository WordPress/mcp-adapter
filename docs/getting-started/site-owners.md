# Connect an AI assistant to your WordPress site

This guide is for site owners who installed MCP Adapter from WordPress.org and want an AI assistant such as Claude, Cursor or VS Code to work with their site. If you are a developer who wants to expose your own plugin's features, read [Creating Abilities](../guides/creating-abilities.md) instead.

It takes about ten minutes: create an Application Password, add your site to your AI client, and test the connection with one prompt.

## What MCP Adapter does, and what it does not

MCP Adapter is the connection between your site and an AI client. On its own it adds no AI features and no new screens in wp-admin.

- **It adds one address to your site.** AI clients connect to your site's address followed by `/wp-json/mcp/mcp-adapter-default-server`. Use the **Site Address (URL)** from **Settings > General**, so a site at `https://example.com/blog` becomes `https://example.com/blog/wp-json/mcp/mcp-adapter-default-server`. The examples below use `https://example.com`.
- **What the assistant can do comes from abilities.** An ability is one action registered by WordPress or by a plugin, such as "get site info" or "create a draft post". Only abilities marked as public can be reached over MCP. MCP Adapter does not decide which abilities your site has; your plugins do.
- **The assistant acts as a WordPress user.** You connect with a username and an Application Password, and every action runs with that user's role and permissions. An assistant connected as an Editor cannot do what an Editor cannot do.

When a client connects, it sees three tools from MCP Adapter: one to list the public abilities on your site, one to read the details of an ability, and one to run it. If no plugin on your site has made an ability public yet, the list is empty. That is expected, and the [troubleshooting section](#connected-but-the-assistant-finds-nothing-to-do) explains what to do.

## Before you start

You need:

- WordPress 6.9 or newer, with MCP Adapter installed and active.
- **HTTPS on your site.** WordPress only offers Application Passwords on sites served over HTTPS, except on local development sites.
- A WordPress user for the assistant. Using a separate user with the lowest role that can do the work (for example Editor rather than Administrator) keeps its actions easy to tell apart and easy to switch off.
- For most desktop clients, [Node.js](https://nodejs.org) installed on your computer, because the connection runs through a small helper started with `npx`.

## Step 1: Create an Application Password

1. In wp-admin, go to **Users > Profile** (or edit the user the assistant will use).
2. Scroll to **Application Passwords**.
3. Type a name you will recognize later, such as "Claude on my laptop", and click **Add New Application Password**.
4. Copy the password that appears. WordPress shows it only once. The spaces in it are part of the format and can stay.

If there is no Application Passwords section, see [Application Passwords are missing](#application-passwords-are-missing).

## Step 2: Add your site to your AI client

You need three things from Step 1: your site's address, the username, and the Application Password.

### Desktop apps (Claude Desktop, Cursor, VS Code, Windsurf and others)

Most desktop clients read a JSON file that lists MCP servers. In Claude Desktop, open it from **Settings > Developer > Edit Config**; in Cursor it is `~/.cursor/mcp.json`. Add this entry, with your own values:

```json
{
  "mcpServers": {
    "my-wordpress-site": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Save the file and restart the app. [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) is a small helper that runs on your computer and passes the client's requests to your site.

### Clients that connect over HTTP directly (Claude Code and others)

Clients that accept a URL and a custom header can skip the helper. The header is `Authorization: Basic` followed by your username and Application Password, joined by a colon and encoded in base64 as one line. On macOS or Linux:

```bash
printf 'your-username:xxxx xxxx xxxx xxxx xxxx xxxx' | base64 | tr -d '\n'
```

Then, for example in Claude Code:

```bash
claude mcp add --transport http my-wordpress-site \
  https://example.com/wp-json/mcp/mcp-adapter-default-server \
  --header "Authorization: Basic PASTE_THE_BASE64_HERE"
```

### A local site with WP-CLI

If the site runs on your own computer and you have WP-CLI, the client can start MCP Adapter directly, with no password. See [Using with MCP Clients](../guides/cli-usage.md#using-with-mcp-clients).

### Web apps (claude.ai, ChatGPT)

The web versions of Claude and ChatGPT add connectors through an OAuth sign-in, and they have no field for an Application Password. WordPress does not include an OAuth server and MCP Adapter does not add one, so these apps cannot connect to MCP Adapter on their own today. Use a desktop client, or a plugin that adds an OAuth sign-in to your site.

## Step 3: Test the connection

Start a new chat and ask:

> List the WordPress abilities you can use on my site.

The assistant should call the discover tool and answer with the abilities it found, or tell you the list is empty. Either answer means the connection works. An error means it does not; see below.

## Troubleshooting

### 401 Unauthorized

The site did not accept the username and password.

- Check the username. Use the login name or the email address of the user, not the display name.
- Check that the Application Password was copied in full and has not been revoked under **Users > Profile**.
- If both are right, your server may be removing the `Authorization` header before WordPress sees it. This happens on some Apache setups. The standard WordPress `.htaccess` file includes a line that passes the header through; if your `.htaccess` was replaced, or your host uses a different setup, ask your host to pass the `Authorization` header to PHP.

### Application Passwords are missing

If **Users > Profile** has no Application Passwords section:

- Make sure the site loads over `https://`. WordPress hides Application Passwords on sites without HTTPS.
- Some security plugins and some hosts turn Application Passwords off. Check your security plugin's settings, or ask your host.

### 404 or "rest_no_route"

- Check that MCP Adapter is active under **Plugins**.
- If your site uses plain permalinks (**Settings > Permalinks**), `/wp-json/` addresses do not work. Use `https://example.com/?rest_route=/mcp/mcp-adapter-default-server` instead.

### Connected, but the assistant finds nothing to do

The assistant sees the three MCP Adapter tools, but the list of abilities is empty or short. MCP Adapter only shows abilities that a plugin has marked as public, so:

- Install or activate a plugin that registers abilities for what you want to do, or ask the developer of a plugin you use whether it supports the Abilities API.
- If an ability is listed but fails with a permission error, the connected user's role is not allowed to do that action. Connect with a user that has the right role.

### The client cannot start the server

If a desktop client shows an error such as "npx: command not found", Node.js is not installed or the app cannot find it. Install Node.js from [nodejs.org](https://nodejs.org) and restart the app.

## Staying in control

- Every Application Password is listed under **Users > Profile**, with when it was last used. Click **Revoke** to cut a client off at once.
- Create one password per client and device, so you can revoke one without touching the others.
- Abilities change your live site the same way an action in wp-admin does. Try new abilities on a staging copy first, and keep regular backups.
