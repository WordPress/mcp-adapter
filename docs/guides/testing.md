# Testing the MCP Adapter

This guide explains how to run and write tests for the MCP Adapter using `wp-env`.

## Prerequisites

- Node.js 20.x (NVM recommended)
- Docker
- Git

See [CONTRIBUTING.md](../../CONTRIBUTING.md#prerequisites) for full setup requirements.

## Test Layout

- `tests/Unit/*`: fast unit tests for pure PHP logic and MCP handlers
- `tests/Integration/*`: WordPress-integration tests that exercise filters, permissions, routing, and transport layers
- `tests/Fixtures/*`: test doubles (dummy error/observability handlers, abilities, transport)

## Running Tests

The MCP Adapter uses `wp-env` to provide a containerized WordPress environment with all dependencies. This eliminates the need for manual database setup or WordPress installation.

### Starting the Test Environment

First, ensure the wp-env environment is running:

```bash
npm run wp-env start
```

This starts a WordPress instance at http://localhost:8888 with all required dependencies.

### Running All Tests

Run the full PHPUnit test suite:

```bash
npm run test:php
```

This executes both unit and integration tests in the wp-env container.

### Running Specific Tests

You can pass PHPUnit arguments to the test script using `--`:

```bash
# Run a specific test by name
npm run test:php -- --filter test_execute_with_public_mcp_filtering

# Run a specific test file
npm run test:php -- tests/Unit/Handlers/ToolsHandlerCallTest.php

# Run tests matching a pattern
npm run test:php -- --filter "Tools.*"
```

### Test Coverage

To generate code coverage reports, restart the environment with Xdebug coverage mode enabled:

```bash
# Enable coverage mode
npm run wp-env start -- --xdebug=coverage

# Run tests (coverage will be generated)
npm run test:php
```

Coverage reports will be generated:
- HTML report: `tests/_output/html/index.html` (open in your browser)
- Clover XML: `tests/_output/php-coverage.xml` (for CI/CD tools)

## Observability and Error Handling

The test suite includes fixtures for verifying observability and error handling:

**DummyObservabilityHandler** (`tests/Fixtures/DummyObservabilityHandler.php`)
- Captures `record_event()` calls with event names, tags, and optional timing data
- Stores events in `$events` array for test assertions
- Used to verify that requests, successes, errors, and timings are properly tracked

**DummyErrorHandler** (`tests/Fixtures/DummyErrorHandler.php`)
- Captures `log()` calls with messages, context, and error types
- Stores logs in `$logs` array for test assertions
- Used to verify error handling and logging behavior

Tests verify that error responses adhere to JSON-RPC 2.0 format: `{ jsonrpc, id, error: { code, message, data? } }`

## Writing New Tests

- Place unit tests under `tests/Unit/.../*Test.php`
- Place integration tests under `tests/Integration/.../*Test.php`
- Use fixtures in `tests/Fixtures` or create your own test doubles
- Follow the Arrange-Act-Assert (AAA) pattern
- Mock external dependencies using PHPUnit mocks
- Test files should mirror the source structure with a `Test.php` suffix

Example test structure:

```php
<?php
namespace WP\MCP\Tests\Unit\Handlers;

use PHPUnit\Framework\TestCase;

class MyHandlerTest extends TestCase {
    public function test_something(): void {
        // Arrange
        $handler = new MyHandler();

        // Act
        $result = $handler->handle($request, $server);

        // Assert
        $this->assertSame($expected, $result);
    }
}
```

## Troubleshooting

### Environment Issues

If wp-env fails to start:

```bash
# Stop and clean the environment
npm run wp-env stop
npm run wp-env clean

# Restart
npm run wp-env start
```

### Test Failures

- **Class not found**: This typically occurs after adding new classes, pulling changes, or switching branches. Regenerate the Composer autoloader to resolve:
  ```bash
  npm run wp-env run tests-cli --env-cwd=wp-content/plugins/mcp-adapter/ composer dump-autoload
  ```
  The `--env-cwd` flag sets the working directory inside the Docker container to ensure Composer operates on the plugin's `composer.json`.

- **Permission errors**: Ensure Docker has the necessary permissions to mount volumes
- **Port conflicts**: wp-env uses ports 8888 and 8889 by default. If these are in use, stop other services or configure different ports in `.wp-env.json`

### Accessing the Test Environment

- WordPress site: http://localhost:8888
- Admin dashboard: http://localhost:8888/wp-admin/ (admin/password)
- Run WP-CLI commands: `npm run wp-env run tests-cli YOUR_COMMAND`

## Continuous Integration

The repository has comprehensive CI testing via GitHub Actions (`.github/workflows/test.yml`):

**Test Matrix:**
- PHP versions: 8.4, 8.3, 8.2, 8.1, 8.0, 7.4
- WordPress versions: latest, trunk
- Coverage: Enabled for PHP 8.4 + WordPress latest (uploaded to Codecov)

**Automated Checks:**
- PHPUnit tests via `npm run test:php`
- PHPCS coding standards
- PHPStan static analysis (Level 8)

All tests run automatically on pull requests and pushes to trunk.
