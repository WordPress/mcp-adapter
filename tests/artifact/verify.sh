#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
artifact_root="$(mktemp -d "${TMPDIR:-/tmp}/mcp-adapter-artifact.XXXXXX")"
trap 'rm -rf "$artifact_root"' EXIT

php_executable="${PHP_BINARY:-$(command -v php)}"
composer_executable="$(command -v composer)"
staging="$artifact_root/mcp-adapter"
extracted="$artifact_root/extracted"
archive="$project_root/mcp-adapter.zip"

mkdir -p "$staging" "$extracted"
cp -R "$project_root/includes" "$staging/includes"
cp "$project_root/LICENSE.md" "$staging/LICENSE.md"
cp "$project_root/README.md" "$staging/README.md"
cp "$project_root/readme.txt" "$staging/readme.txt"
cp "$project_root/mcp-adapter.php" "$staging/mcp-adapter.php"
cp "$project_root/composer.json" "$staging/composer.json"
cp "$project_root/composer.lock" "$staging/composer.lock"
cp -R "$project_root/vendor" "$staging/vendor"

COMPOSER_DISABLE_NETWORK=1 "$php_executable" "$composer_executable" install \
	--working-dir="$staging" \
	--no-dev \
	--classmap-authoritative \
	--no-interaction \
	--no-progress
"$php_executable" "$composer_executable" check-platform-reqs \
	--working-dir="$staging" \
	--no-dev

rm "$staging/composer.json" "$staging/composer.lock"
rm -f "$archive"
(
	cd "$artifact_root"
	zip -X -qr "$archive" mcp-adapter
)
unzip -q "$archive" -d "$extracted"

"$php_executable" "$project_root/tests/artifact/smoke.php" \
	"$extracted/mcp-adapter/vendor/autoload.php"

for forbidden_path in \
	vendor/phpunit \
	vendor/phpstan \
	vendor/squizlabs \
	tests \
	composer.json \
	composer.lock
do
	if [[ -e "$extracted/mcp-adapter/$forbidden_path" ]]; then
		printf 'Development-only artifact path found: %s\n' "$forbidden_path" >&2
		exit 1
	fi
done

digest="$("$php_executable" -r 'echo hash_file("sha256", $argv[1]);' "$archive")"
bytes="$(wc -c < "$archive" | tr -d ' ')"
files="$(unzip -Z1 "$archive" | sed '/\/$/d' | wc -l | tr -d ' ')"
php_files="$(unzip -Z1 "$archive" | sed '/\/$/d' | grep -Ec '\.php$')"
printf 'verified plugin sha256=%s bytes=%s files=%s php_files=%s\n' \
	"$digest" "$bytes" "$files" "$php_files"
