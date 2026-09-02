# Production artifact verification

`npm run plugin-zip` builds a production-only staging tree from `composer.lock`,
removes development dependencies, generates an authoritative classmap, creates
`mcp-adapter.zip`, extracts it, and runs both supported MCP revisions through
the extracted runtime.

The verifier rejects development packages and Composer source files. The smoke
test covers:

- 2025 initialize, initialized notification, ping, tool/resource/prompt
  discovery, and tool execution; and
- 2026 server/discover plus representative tool, resource, and prompt discovery
  and execution.
