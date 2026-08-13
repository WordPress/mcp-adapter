#!/usr/bin/env bash

set -euo pipefail

archive="${1:-mcp-adapter.zip}"
if [[ ! -f "$archive" ]]; then
	echo "Plugin ZIP not found: $archive" >&2
	exit 1
fi

extract_directory=$(mktemp -d "${TMPDIR:-/tmp}/mcp-adapter-zip.XXXXXX")
trap 'rm -rf "$extract_directory"' EXIT

unzip -q "$archive" -d "$extract_directory"

# Keep the inline PHP isolated from shell expansion.
# shellcheck disable=SC2016
php -r '
$manifests = glob($argv[1] . "/*/vendor/composer/jetpack_autoload_classmap.php");
if (false === $manifests || 1 !== count($manifests)) {
	fwrite(STDERR, "Expected exactly one Jetpack classmap manifest in the plugin ZIP.\n");
	exit(1);
}

$plugin_directory = dirname($manifests[0], 3);
$resolved_plugin_directory = realpath($plugin_directory);
if (false === $resolved_plugin_directory) {
	fwrite(STDERR, "Could not resolve the extracted plugin directory.\n");
	exit(1);
}

$plugin_prefix = $plugin_directory . DIRECTORY_SEPARATOR;
$resolved_plugin_prefix = $resolved_plugin_directory . DIRECTORY_SEPARATOR;
$classmap = require $manifests[0];
if (!is_array($classmap)) {
	fwrite(STDERR, "Jetpack classmap manifest did not return an array.\n");
	exit(1);
}

$missing_paths = array();
foreach ($classmap as $class_name => $entry) {
	if (!is_array($entry) || !isset($entry["path"]) || !is_string($entry["path"])) {
		fwrite(STDERR, "Invalid Jetpack classmap entry for {$class_name}.\n");
		exit(1);
	}

	$resolved_path = realpath($entry["path"]);
	if (false !== $resolved_path && is_file($resolved_path) && 0 === strpos($resolved_path, $resolved_plugin_prefix)) {
		continue;
	}

	$display_path = $entry["path"];
	if (0 === strpos($display_path, $plugin_prefix)) {
		$display_path = substr($display_path, strlen($plugin_prefix));
	} elseif (0 === strpos($display_path, $resolved_plugin_prefix)) {
		$display_path = substr($display_path, strlen($resolved_plugin_prefix));
	}
	$missing_paths[$display_path][] = $class_name;
}

if (array() !== $missing_paths) {
	fwrite(STDERR, "Jetpack classmap references files missing from the plugin ZIP:\n");
	foreach ($missing_paths as $missing_path => $class_names) {
		fwrite(STDERR, "- {$missing_path} (" . implode(", ", $class_names) . ")\n");
	}
	exit(1);
}

printf("Verified %d Jetpack classmap entries in %s.\n", count($classmap), basename($argv[2]));
' "$extract_directory" "$archive"
