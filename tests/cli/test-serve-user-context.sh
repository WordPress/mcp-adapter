#!/bin/sh

set -eu

help_output="$(wp mcp-adapter serve --help 2>&1)"

case "$help_output" in
	*"already registered"*)
		printf '%s\n' "$help_output" >&2
		exit 1
		;;
	esac

case "$help_output" in
	*"global \`--user\` flag"*)
		;;
	*)
		printf '%s\n' "$help_output" >&2
		exit 1
		;;
esac

if serve_output="$(wp mcp-adapter serve --server=mcp-adapter-default-server --user=admin --require=tests/cli/assert-serve-user-context.php 2>&1)"; then
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
