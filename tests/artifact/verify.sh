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

reference="$(
	"$php_executable" -r '
		$data = json_decode(file_get_contents($argv[1]), true);
		foreach ($data["packages"] ?? array() as $package) {
			if (($package["name"] ?? "") === "wordpress/php-mcp-schema") {
				echo $package["source"]["reference"] ?? "";
			}
		}
	' "$extracted/mcp-adapter/vendor/composer/installed.json"
)"
if [[ "$reference" != "3a8f4aef1fefc9e0d1fb422e59411c78ce32edd3" ]]; then
	printf 'Unexpected php-mcp-schema artifact ref: %s\n' "$reference" >&2
	exit 1
fi

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

for forbidden_symbol in \
	'WP\\McpSchema\\Client\\' \
	'WP\\McpSchema\\Common\\' \
	'WP\\McpSchema\\Server\\' \
	'AbstractDataTransferObject' \
	'AbstractEnum' \
	'get_protocol_dto' \
	'class_alias'
do
	if grep -RFq "$forbidden_symbol" \
		"$extracted/mcp-adapter/includes" \
		"$extracted/mcp-adapter/vendor/composer"
	then
		printf 'Forbidden production symbol found: %s\n' "$forbidden_symbol" >&2
		exit 1
	fi
done

digest="$("$php_executable" -r 'echo hash_file("sha256", $argv[1]);' "$archive")"
bytes="$(wc -c < "$archive" | tr -d ' ')"
files="$(unzip -Z1 "$archive" | sed '/\/$/d' | wc -l | tr -d ' ')"
php_files="$(unzip -Z1 "$archive" | sed '/\/$/d' | grep -Ec '\.php$')"
printf 'verified plugin sha256=%s bytes=%s files=%s php_files=%s schema_ref=%s\n' \
	"$digest" "$bytes" "$files" "$php_files" "$reference"
