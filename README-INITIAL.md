# MCP Adapter
[*Part of the **AI Building Blocks for WordPress** initiative*
](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)
## Overview
* **Purpose:** Implement the Model Context Protocol (MCP) standard to expose WordPress abilities to AI assistants and LLMs.
* **Scope:** Protocol translation, transport implementation, and security controls. Builds on the Abilities API for capability discovery.
* **Audience:** Site administrators, AI tool developers, and anyone connecting WordPress to AI assistants.

## Design Goals
1. **Standards-based** – Implements the open MCP protocol specification.
2. **Bidirectional** – WordPress as both MCP server (expose abilities) and client (connect to other tools).
3. **Secure by default** – Application passwords and capability-based permissions.
4. **Transport flexible** – Support HTTP (REST API) and stdio (WP-CLI) transports.

## Architecture
* **MCP Server** – Exposes WordPress abilities to external AI assistants
* **MCP Client** – Connects WordPress to other MCP-enabled tools (future)
* **Transport Layer** – HTTP, stdio via WP-CLI

## Current Status
| Milestone | State |
|-----------|-------|
| Placeholder repository | **created** |
| MCP server specification | in progress |
| Abilities API integration | planned |
| Community feedback (#core-ai Slack) | planned |

## How to Get Involved
* **Discuss:** `#core-ai` channel on WordPress Slack.
* **Learn:** Explore the [MCP specification](https://modelcontextprotocol.io).
* **Test:** Try the prototype once released.

## License
[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
