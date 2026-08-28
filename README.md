# MCP Adapter

Part of the [**AI Building Blocks for WordPress** initiative](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

The official WordPress package for MCP integration that exposes WordPress abilities as [Model Context Protocol (MCP)](https://modelcontextprotocol.io) tools, resources, and prompts for AI agents.

[![WordPress Playground Demo](https://img.shields.io/wordpress/plugin/v/mcp-adapter?logo=wordpress&logoColor=FFFFFF&label=Live%20Demo&labelColor=3858E9&color=3858E9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/mcp-adapter/trunk/.wordpress-org/blueprints/blueprint.json)

[![Test](https://github.com/WordPress/mcp-adapter/actions/workflows/test.yml/badge.svg)](https://github.com/WordPress/mcp-adapter/actions/workflows/test.yml) [![Plugin Check](https://github.com/WordPress/mcp-adapter/actions/workflows/plugin-check.yml/badge.svg)](https://github.com/WordPress/mcp-adapter/actions/workflows/plugin-check.yml) [![Dependency Review](https://github.com/WordPress/mcp-adapter/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/WordPress/mcp-adapter/actions/workflows/dependency-review.yml)

## Overview

MCP Adapter bridges WordPress's [Abilities API](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/) with exact MCP [`2025-11-25`](https://modelcontextprotocol.io/specification/2025-11-25/) and [`2026-07-28`](https://modelcontextprotocol.io/specification/2026-07-28/) revisions, giving AI agents a standardized way to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

## Features

- **Ability-to-MCP conversion** — WordPress abilities automatically become MCP [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools), [resources](https://modelcontextprotocol.io/specification/2026-07-28/server/resources), and [prompts](https://modelcontextprotocol.io/specification/2026-07-28/server/prompts)
- **Multi-server management** — run multiple MCP servers, each with its own transports, abilities, and handlers
- **HTTP and STDIO transports**, plus a `McpTransportInterface` for custom protocols — see [Custom Transports](docs/guides/custom-transports.md)
- **Pluggable error handling and observability** — swap in your own logging or monitoring via `McpErrorHandlerInterface` and `McpObservabilityHandlerInterface` — see [Error Handling](docs/guides/error-handling.md) and [Observability](docs/guides/observability.md)
- **Granular permissions** — per-server transport authentication and per-ability permission checks — see [Transport Permissions](docs/guides/transport-permissions.md)

## Architecture

See the [Architecture Overview](docs/architecture/overview.md) for the full component breakdown.

## Getting Started

Follow the [Quick Start Guide](docs/getting-started/README.md) to register your first ability and expose it via MCP, or jump straight to the [Basic Examples](docs/getting-started/basic-examples.md) for complete tool, resource, and prompt samples.

WordPress abilities are private by default. Set `meta.public` (or `meta.mcp.public`) to `true` to expose one, then reach it through the [default server's](docs/guides/default-server.md) three meta-tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) — see [Creating Abilities](docs/guides/creating-abilities.md) for the full opt-in model.

## Connecting MCP Clients

Connect via WP-CLI over STDIO, or point an HTTP client at `/wp-json/mcp/mcp-adapter-default-server`. Configuration examples for Claude Desktop and other MCP clients — both direct STDIO and HTTP-via-proxy — are in the [CLI Usage Guide](docs/guides/cli-usage.md).

## Migration

- [Dual-revision schema runtime migration](docs/migration/dual-revision-schema-runtime.md)
- [Migration Guide: v0.3.0](docs/migration/v0.3.0.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, coding standards, and the contribution workflow, and the [Testing Guide](docs/guides/testing.md) for running the test suite.

## License
[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
