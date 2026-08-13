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

$manifest_directory = dirname($manifests[0]);
$plugin_directory = dirname($manifests[0], 3);
$resolved_plugin_directory = realpath($plugin_directory);
if (false === $resolved_plugin_directory) {
	fwrite(STDERR, "Could not resolve the extracted plugin directory.\n");
	exit(1);
}

$plugin_prefix = $plugin_directory . DIRECTORY_SEPARATOR;
$resolved_plugin_prefix = $resolved_plugin_directory . DIRECTORY_SEPARATOR;

/*
 * The autoloader requires classmap targets lazily and filemap targets eagerly,
 * and neither code path checks that the file exists first. Both manifests must
 * therefore be verified. The filemap is only written when a shipped package
 * declares a "files" autoload, so its absence is not an error.
 */
$manifest_names = array(
	"jetpack_autoload_classmap.php" => true,
	"jetpack_autoload_filemap.php"  => false,
);

$missing_paths = array();
$mapped_paths = array();
$verified_entries = 0;

foreach ($manifest_names as $manifest_name => $is_required) {
	$manifest_path = $manifest_directory . DIRECTORY_SEPARATOR . $manifest_name;
	if (!is_file($manifest_path)) {
		if ($is_required) {
			fwrite(STDERR, "Missing manifest in the plugin ZIP: {$manifest_name}\n");
			exit(1);
		}

		continue;
	}

	$entries = require $manifest_path;
	if (!is_array($entries)) {
		fwrite(STDERR, "{$manifest_name} did not return an array.\n");
		exit(1);
	}

	foreach ($entries as $entry_key => $entry) {
		if (!is_array($entry) || !isset($entry["path"]) || !is_string($entry["path"])) {
			fwrite(STDERR, "Invalid {$manifest_name} entry for {$entry_key}.\n");
			exit(1);
		}

		$resolved_path = realpath($entry["path"]);
		if (false !== $resolved_path && is_file($resolved_path) && 0 === strpos($resolved_path, $resolved_plugin_prefix)) {
			$mapped_paths[$resolved_path] = true;
			++$verified_entries;
			continue;
		}

		$display_path = $entry["path"];
		if (0 === strpos($display_path, $plugin_prefix)) {
			$display_path = substr($display_path, strlen($plugin_prefix));
		} elseif (0 === strpos($display_path, $resolved_plugin_prefix)) {
			$display_path = substr($display_path, strlen($resolved_plugin_prefix));
		}
		$missing_paths[$display_path][] = $entry_key;
	}
}

/*
 * Guard the opposite failure as well. An over-broad "exclude-from-classmap"
 * pattern drops plugin classes from the manifest instead of adding dangling
 * paths, which every existence check above would still pass. Every PSR-4 file
 * under includes/ has to appear in the manifests.
 */
$includes_directory = $resolved_plugin_directory . DIRECTORY_SEPARATOR . "includes";
if (!is_dir($includes_directory)) {
	fwrite(STDERR, "The plugin ZIP does not contain an includes directory.\n");
	exit(1);
}

$unmapped_paths = array();
$includes_files = 0;
$includes_iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($includes_directory, FilesystemIterator::SKIP_DOTS)
);

foreach ($includes_iterator as $include_file) {
	if (!$include_file->isFile() || "php" !== strtolower($include_file->getExtension())) {
		continue;
	}

	++$includes_files;
	$resolved_include = realpath($include_file->getPathname());
	if (false !== $resolved_include && isset($mapped_paths[$resolved_include])) {
		continue;
	}

	$unmapped_paths[] = substr($include_file->getPathname(), strlen($resolved_plugin_prefix));
}

if (0 === $includes_files) {
	fwrite(STDERR, "The includes directory in the plugin ZIP contains no PHP files.\n");
	exit(1);
}

$failed = false;

if (array() !== $missing_paths) {
	$failed = true;
	fwrite(STDERR, "Jetpack manifests reference files missing from the plugin ZIP:\n");
	foreach ($missing_paths as $missing_path => $entry_keys) {
		fwrite(STDERR, "- {$missing_path} (" . implode(", ", $entry_keys) . ")\n");
	}
}

if (array() !== $unmapped_paths) {
	$failed = true;
	sort($unmapped_paths);
	fwrite(STDERR, "Plugin files are missing from the Jetpack manifests:\n");
	foreach ($unmapped_paths as $unmapped_path) {
		fwrite(STDERR, "- {$unmapped_path}\n");
	}
}

if ($failed) {
	exit(1);
}

printf(
	"Verified %d Jetpack manifest entries and %d includes files in %s.\n",
	$verified_entries,
	$includes_files,
	basename($argv[2])
);
' "$extract_directory" "$archive"
