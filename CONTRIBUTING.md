# Contributing to the MCP Adapter canonical plugin

Thank you for your interest in contributing to the MCP Adapter canonical plugin! This contains all the documentation for getting started and contributing to the plugin and will eventually be a part of the [AI Team Handbook](https://make.wordpress.org/ai/handbook/).

## How to Contribute

Please [report (non-security) issues](https://github.com/WordPress/mcp-adapter/issues) and [open pull requests](https://github.com/WordPress/mcp-adapter/pulls) on GitHub. See below for information on reporting potential [security/privacy vulnerabilities](#reporting-security-issues).

Join the `#core-ai` channel [on WordPress Slack](http://wordpress.slack.com) ([sign up here](http://chat.wordpress.org)).

## Coding standards

In general, all code must follow the [WordPress Coding Standards and best practices](https://developer.wordpress.org/coding-standards/). All code in the Performance Lab plugin must follow these requirements:

- **WordPress**: As of MCP Adapter v0.1.0, released {@todo}, the plugin's minimum WordPress version requirement is 6.8.
- **PHP**: The minimum required version right now is 7.4. This is subject to change and will be brought in sync with the WordPress core minimum PHP version requirement closer to release.

We include [several tools](#useful-commands) to help ensure your code meets contribution

## Guidelines

- As with all WordPress projects, we want to ensure a welcoming environment for everyone. With that in mind, all contributors are expected to follow our [Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/).

- All WordPress projects are [licensed under the GPLv2+](/LICENSE), and all contributions to Gutenberg will be released under the GPLv2+ license. You maintain copyright over any contribution you make, and by submitting a pull request, you are agreeing to release that contribution under the GPLv2+ license.

## Reporting Security Issues

Please see [SECURITY.md] (@TODO).

## Local Setup

### Prerequisites

- Node.js: 20.x (NVM recommended)
- Docker
- Git
- Composer: (if you prefer to run the Composer tools locally)

You can use Docker and the `wp-env` tool to set up a local development environment, instead of manually installing the specific testing versions of WordPress, PHP, and Composer. For more information, see the [wp-env documentation](https://developer.wordpress.org/block-editor/packages/packages-env/).

### Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/WordPress/mcp-adapter.git
   ```

2. Change into the project folder and install the development dependencies:

   ```bash
   ## If you're using NVM, make sure to use the correct Node.js version:
   nvm use

   ## Then install the NPM dependencies:
   npm install

   # Then install the composer dependencies:
   composer install
   ```

### Useful Commands

#### Installing Dependencies

- `composer install`: Install PHP dependencies.
- `npm install`: Install JavaScript dependencies.

#### Linting and Formatting

- `npm run lint:php`: Runs PHPCS linting on the PHP code.
- `npm run lint:php:fix`: Autofixes PHPCS linting issues.
- `npm run format`: Formats non-PHP files using Prettier.

