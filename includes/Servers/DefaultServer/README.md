# Default WordPress MCP Server

The Default server provides the standard WordPress MCP implementation with built-in system tools and support for WordPress abilities.

## Architecture

### System Tools vs Ability Tools

The Default server uses a two-tier architecture:

1. **System Tools**: Infrastructure tools that serve the MCP protocol itself
   - Not backed by WordPress abilities
   - Not discoverable through ability discovery
   - Handle discovery, planning, and execution
   - Examples: `discover_abilities`, `get_ability_info`, `execute_ability`

2. **Ability Tools**: Business logic tools backed by WordPress abilities
   - Represent actual WordPress functionality
   - Discoverable through the `discover_abilities` system tool
   - Examples: `wordpress/get_posts`, `wordpress/create_post`

## System Tools

### `discover_abilities`
Lists all available WordPress abilities in the system.

**Input**: None
**Output**: Array of abilities with name, label, and description

### `get_ability_info`
Get detailed information about a specific WordPress ability.

**Input**:
- `ability_name` (string): Full name of the ability

**Output**: Detailed ability information including schemas

### `execute_ability`
Execute a WordPress ability with provided parameters.

**Input**:
- `ability_name` (string): Full name of the ability to execute
- `parameters` (object): Parameters to pass to the ability

**Output**: Execution results with success status and data/error
