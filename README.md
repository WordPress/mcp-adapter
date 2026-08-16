# MCP Adapter

Part of the [**AI Building Blocks for WordPress** initiative](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

The official WordPress package for MCP integration that exposes WordPress abilities as [Model Context Protocol (MCP)](https://modelcontextprotocol.io) tools, resources, and prompts for AI agents.

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/WordPress/mcp-adapter)

## Overview

MCP Adapter bridges WordPress's [Abilities API](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/) with the [MCP specification](https://modelcontextprotocol.io/specification/2025-11-25/), giving AI agents a standardized way to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

## Features

- **Ability-to-MCP conversion** — WordPress abilities automatically become MCP [tools](https://modelcontextprotocol.io/specification/2025-06-18/server/tools.md), [resources](https://modelcontextprotocol.io/specification/2025-06-18/server/resources.md), and [prompts](https://modelcontextprotocol.io/specification/2025-06-18/server/prompts.md)
- **Multi-server management** — run multiple MCP servers, each with its own transports, abilities, and handlers
- **HTTP and STDIO transports**, plus a `McpTransportInterface` for custom protocols — see [Custom Transports](docs/guides/custom-transports.md)
- **Pluggable error handling and observability** — swap in your own logging or monitoring via `McpErrorHandlerInterface` and `McpObservabilityHandlerInterface` — see [Error Handling](docs/guides/error-handling.md) and [Observability](docs/guides/observability.md)
- **Granular permissions** — per-server transport authentication and per-ability permission checks — see [Transport Permissions](docs/guides/transport-permissions.md)

## Architecture

See the [Architecture Overview](docs/architecture/overview.md) for the full component breakdown.

## Installation

MCP Adapter is a canonical plugin designed to be installed and activated on a user's site. Plugins that depend on MCP Adapter should check for its presence.

For detailed installation instructions, see the [Installation Guide](docs/getting-started/installation.md) for all methods.

## Getting Started

Follow the [Quick Start Guide](docs/getting-started/README.md) to register your first ability and expose it via MCP, or jump straight to the [Basic Examples](docs/getting-started/basic-examples.md) for complete tool, resource, and prompt samples.

WordPress abilities are private by default. Set `meta.public` (or `meta.mcp.public`) to `true` to expose one, then reach it through the [default server's](docs/guides/default-server.md) three meta-tools (`discover-abilities`, `get-ability-info`, `execute-ability`) — see [Creating Abilities](docs/guides/creating-abilities.md) for the full opt-in model.

## Connecting MCP Clients

Connect via WP-CLI over STDIO, or point an HTTP client at `/wp-json/mcp/mcp-adapter-default-server`. Configuration examples for Claude Desktop and other MCP clients — both direct STDIO and HTTP-via-proxy — are in the [CLI Usage Guide](docs/guides/cli-usage.md).

## Migration

- [Migration Guide: v0.5.0](docs/migration/v0.5.0.md) — Breaking changes and upgrade instructions
- [Migration Guide: v0.3.0](docs/migration/v0.3.0.md) — Transport, observability, and hook name changes

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, coding standards, and the contribution workflow, and the [Testing Guide](docs/guides/testing.md) for running the test suite.

## License
[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
