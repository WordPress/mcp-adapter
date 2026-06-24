=== MCP Adapter ===
Contributors:      wordpressdotorg
Tags:              mcp, ai, abilities-api, model-context-protocol
Requires at least: 6.9
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        0.5.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Expose WordPress abilities as Model Context Protocol (MCP) tools, resources, and prompts for AI agents.

== Description ==

Part of the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative.

The MCP Adapter bridges WordPress's Abilities API with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io) specification, providing a standardized way for AI agents to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

**Features:**

* **Ability-to-MCP Conversion** – Automatically converts WordPress abilities into MCP tools, resources, and prompts.
* **Multi-Server Management** – Create and manage multiple MCP servers with unique configurations.
* **Extensible Transport Layer** – Built-in HTTP and STDIO transports, plus support for custom transport protocols.
* **Flexible Error Handling** – Default WordPress-compatible error logging with support for custom, server-specific handlers.
* **Observability** – Zero-overhead metrics tracking with configurable handlers.
* **Permission Control** – Granular, configurable permission checking for all exposed functionality.

== Installation ==

Install MCP Adapter as a WordPress plugin from the [latest GitHub release](https://github.com/WordPress/mcp-adapter/releases/latest), or see the [README](https://github.com/WordPress/mcp-adapter#installation) for WP-CLI and wp-env options.

Plugin developers can also add it as a Composer dependency: `composer require wordpress/mcp-adapter`

Requires WordPress 6.9 or newer (the Abilities API is included in core).

== Changelog ==

For the full changelog, see the [releases on GitHub](https://github.com/WordPress/mcp-adapter/releases).
