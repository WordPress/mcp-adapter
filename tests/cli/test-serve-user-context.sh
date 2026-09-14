#!/bin/sh

set -eu

help_output="$(wp mcp-adapter serve --help 2>&1)"

case "$help_output" in
	*"already registered"*)
		printf '%s\n' "$help_output" >&2
		exit 1
		;;
esac

run_serve() {
	expected_user="$1"
	shift

	if serve_output="$(MCP_ADAPTER_EXPECT_USER="$expected_user" wp mcp-adapter serve --server=mcp-adapter-default-server --require=tests/cli/assert-serve-user-context.php "$@" 2>&1)"; then
		printf '%s\n' "$serve_output" >&2
		exit 1
	fi

	case "$serve_output" in
		*"The STDIO transport is disabled."*)
			;;
		*)
			printf '%s\n' "$serve_output" >&2
			exit 1
			;;
	esac
}

# Global --user selects the authenticated context used for required capabilities.
run_serve authenticated --user=1
run_serve anonymous
