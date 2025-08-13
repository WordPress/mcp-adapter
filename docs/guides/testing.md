# Testing the MCP Adapter

This guide explains how to run and write tests for the MCP Adapter. The suite supports two modes:

- Fast unit mode (no WordPress DB needed)
- Full WordPress integration mode (uses the WP test suite)

## Prerequisites

- PHP 8.1+
- Composer
- Optional (for full WP tests): a local MySQL/MariaDB server or a WordPress test DB

## Test Layout

- `tests/Unit/*`: fast unit tests for pure PHP logic and MCP handlers
- `tests/Integration/*`: WordPress-integration tests that exercise filters, permissions, and routing
- `tests/Fixtures/*`: test doubles (dummy error/observability handlers, abilities, transport)

## Running Tests

### 1) Fast Unit Mode (recommended for local dev)

No database required. A lightweight bootstrap provides minimal WordPress shims.

```bash
# from mcp-adapter/
MCP_ADAPTER_FAST_UNIT=1 composer test
```

What this does:
- Loads Composer autoloaders and Abilities API
- Provides shims for `add_filter()`, `apply_filters()`, `wp_set_current_user()`, `is_user_logged_in()`, i18n, etc.
- Runs the entire suite from `tests/Unit` and `tests/Integration`

Tip: Run a single file or test via PHPUnit’s filter:

```bash
MCP_ADAPTER_FAST_UNIT=1 vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Handlers/ToolsHandlerCallTest.php
MCP_ADAPTER_FAST_UNIT=1 vendor/bin/phpunit -c phpunit.xml.dist --filter test_image_result_is_converted_to_base64_with_mime_type
```

### 2) Full WordPress Integration Mode

This uses the official WP test suite and requires a DB.

```bash
# Install WP test suite
composer test:install

# Run tests (will load WordPress bootstrap)
composer test
```

If you see “Error establishing a database connection”, ensure your DB is running and credentials in `bin/install-wp-tests.sh` (or env variables) are correct.

## Observability and Error Handling

The suite verifies that:
- Request counts, successes, errors, and timings are recorded
- Error envelopes adhere to JSON-RPC shape: `{ jsonrpc, id, error: { code, message, data? } }`

Fixtures:
- `DummyObservabilityHandler` captures `increment()` and `timing()` calls
- `DummyErrorHandler` collects logs for assertions

## Handlers and Routing Covered

- Initialize: protocolVersion, serverInfo, capabilities, and instructions
- Tools: list, list-all (available=true), call (permission errors, exceptions, image/text responses)
- Resources: list, templates, read, subscribe/unsubscribe
- Prompts: list, get
- System: ping, setLoggingLevel, complete, listRoots
- Transport: routing, unknown method, cursor compatibility, metric tags

## Writing New Tests

- Place unit tests under `tests/Unit/.../*Test.php`
- Favor fast unit mode while developing
- For behavior depending on WordPress state (e.g., current user), in fast mode use:
  - `wp_set_current_user(1);`
  - `add_filter('mcp_validation_enabled', '__return_false');`
- Use fixtures in `tests/Fixtures` or create your own test doubles

## Coverage

You can enable coverage locally with Xdebug:

```bash
XDEBUG_MODE=coverage MCP_ADAPTER_FAST_UNIT=1 vendor/bin/phpunit -c phpunit.xml.dist --coverage-text
```

## Troubleshooting

- DB connection error: use fast unit mode or start a local DB and rerun `composer test:install`
- Class not found in tests: run `composer dump-autoload`
- WP hook callback missing (e.g. `__return_false`): fast unit mode shims these; ensure `MCP_ADAPTER_FAST_UNIT=1` is set

## CI Recommendations

- Default to fast unit mode for speed
- Optionally add a matrix job that runs the full WP suite on a stable WordPress version


