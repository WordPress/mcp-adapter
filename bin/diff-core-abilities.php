<?php
/**
 * Reports drift between the plugin's copies of WordPress core files and upstream.
 *
 * Every PHP file under `core/` mirrors a path under `src/wp-includes/` in
 * wordpress-develop (see core/README.md). This script normalizes each copy —
 * reversing the two mechanical transforms the README defines (the `WP_MCP_` prefix
 * on the class name and the file name, and the per-ability `wp_has_ability()`
 * guards) and the known differences it lists — and compares it with its upstream
 * counterpart: a differing file is
 * drift and fails the check, while a file with no upstream counterpart is reported
 * as plugin-first (staged) and passes.
 *
 * A file whose class core ships must match upstream exactly after normalization.
 * When the first ability staged inside such a file lands, extend this check to
 * accept pure additions relative to upstream instead of an exact match.
 *
 * Usage:
 *   php bin/diff-core-abilities.php [--wp-develop=/path/to/wordpress-develop] [--ref=trunk]
 *
 * With --wp-develop the comparison uses that local checkout (at whatever commit it
 * has checked out); without it each upstream file is fetched from the
 * WordPress/wordpress-develop repository on GitHub at --ref (default: trunk).
 */

declare( strict_types = 1 );

$options    = getopt( '', array( 'wp-develop::', 'ref::' ) );
$wp_develop = isset( $options['wp-develop'] ) && is_string( $options['wp-develop'] ) ? rtrim( $options['wp-develop'], '/' ) : '';
$ref        = isset( $options['ref'] ) && is_string( $options['ref'] ) ? $options['ref'] : 'trunk';
$core_dir   = dirname( __DIR__ ) . '/core';

if ( '' !== $wp_develop && ! is_dir( $wp_develop . '/src/wp-includes' ) ) {
	fwrite( STDERR, "'{$wp_develop}' is not a wordpress-develop checkout (no src/wp-includes).\n" );
	exit( 1 );
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $core_dir, FilesystemIterator::SKIP_DOTS ) );
$paths = array();

foreach ( $files as $file ) {
	if ( 'php' === strtolower( $file->getExtension() ) ) {
		$paths[] = substr( $file->getPathname(), strlen( $core_dir ) + 1 );
	}
}

if ( array() === $paths ) {
	fwrite( STDERR, "No PHP files found under '{$core_dir}'.\n" );
	exit( 1 );
}

sort( $paths );

/**
 * Returns the upstream file contents, '' when the file does not exist upstream, or null on failure.
 *
 * @param string $relative_path Path relative to src/wp-includes/.
 * @param string $wp_develop    Local wordpress-develop checkout, or '' to fetch from GitHub.
 * @param string $ref           Git ref to fetch when no local checkout is given.
 */
function mcp_adapter_fetch_upstream( string $relative_path, string $wp_develop, string $ref ): ?string {
	if ( '' !== $wp_develop ) {
		$upstream_file = $wp_develop . '/src/wp-includes/' . $relative_path;

		if ( ! file_exists( $upstream_file ) ) {
			return '';
		}

		$contents = file_get_contents( $upstream_file );

		return false === $contents ? null : $contents;
	}

	$url     = 'https://raw.githubusercontent.com/WordPress/wordpress-develop/' . rawurlencode( $ref ) . '/src/wp-includes/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative_path ) ) );
	$context = stream_context_create(
		array(
			'http' => array(
				'timeout'       => 30,
				'ignore_errors' => true,
				'user_agent'    => 'mcp-adapter/diff-core-abilities',
			),
		)
	);

	$contents = file_get_contents( $url, false, $context );
	$status   = 0;

	foreach ( $http_response_header ?? array() as $header ) {
		if ( 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $matches ) ) {
			$status = (int) $matches[1];
		}
	}

	if ( 200 === $status && false !== $contents ) {
		return $contents;
	}

	if ( 404 === $status ) {
		return '';
	}

	fwrite( STDERR, "Could not fetch '{$url}' (HTTP {$status}).\n" );

	return null;
}

/**
 * Reverses the documented differences between a copy and upstream (see "Known
 * differences" in core/README.md), which go beyond the two mechanical transforms.
 *
 * @param string $relative_path The copy's path relative to core/.
 * @param string $contents      The plugin copy's contents.
 */
function mcp_adapter_reverse_known_differences( string $relative_path, string $contents ): string {
	if ( 'abilities/class-wp-mcp-abilities-settings.php' !== $relative_path ) {
		return $contents;
	}

	// `core/settings-get` checks `manage_options` in a permission_callback: WordPress
	// before 7.2 does not know the `required_capabilities` argument core registers it with.
	$contents = preg_replace(
		"#^(\t+)'permission_callback' => function \\(\\): bool \\{\n\\1\treturn current_user_can\\( 'manage_options' \\);\n\\1\\},$#m",
		"\$1'required_capabilities' => array( 'manage_options' ),",
		$contents,
		1,
		$count
	);

	$start = null === $contents || 1 !== $count ? false : strpos( $contents, "'core/settings-get',\n" );
	$end   = false === $start ? false : strpos( $contents, "\n\t\t);\n", $start );

	if ( false === $start || false === $end ) {
		// Not the expected shape: leave it, so the comparison reports the drift.
		return null === $contents ? '' : $contents;
	}

	// Re-align the registration arguments to the longer `required_capabilities` key, as upstream.
	$arguments = substr( $contents, $start, $end - $start );
	preg_match_all( "#^\t{4}('[a-z_]+') +=> #m", $arguments, $keys );
	$width     = max( array_map( 'strlen', $keys[1] ) );
	$arguments = preg_replace_callback(
		"#^\t{4}('[a-z_]+') +=> #m",
		static function ( array $match ) use ( $width ): string {
			return "\t\t\t\t" . str_pad( $match[1], $width ) . ' => ';
		},
		$arguments
	);

	return substr( $contents, 0, $start ) . $arguments . substr( $contents, $end );
}

/**
 * Reverses the two mechanical transforms, returning the copy as it would land in core.
 *
 * @param string $contents The plugin copy's contents.
 */
function mcp_adapter_normalize_copy( string $contents ): string {
	// Transform 1: the plugin's class prefix back to core's.
	$contents = str_replace( 'WP_MCP_Abilities_', 'WP_Abilities_', $contents );

	// Transform 2: unwrap the per-ability wp_has_ability() guards in register().
	// Each guard wraps exactly one registration call (see core/README.md).
	$normalized = preg_replace(
		"#^(\t+)if \\( ! wp_has_ability\\( '[^']+' \\) \\) \\{\n\\1\t(\\\$this->register_[a-z0-9_]+\\(\\);)\n\\1\\}$#m",
		'$1$2',
		$contents
	);

	return null === $normalized ? $contents : $normalized;
}

$drifted = array();
$staged  = array();
$failed  = array();

foreach ( $paths as $relative_path ) {
	// The prefix transform applies to the file name too: the upstream file keeps core's name.
	$upstream_path = str_replace( 'class-wp-mcp-abilities-', 'class-wp-abilities-', $relative_path );
	$upstream      = mcp_adapter_fetch_upstream( $upstream_path, $wp_develop, $ref );

	if ( null === $upstream ) {
		$failed[] = $relative_path;
		continue;
	}

	if ( '' === $upstream ) {
		$staged[] = $relative_path;
		echo "STAGED  core/{$relative_path} — plugin-first, no upstream counterpart yet\n";
		continue;
	}

	$copy = file_get_contents( $core_dir . '/' . $relative_path );

	if ( false !== $copy && mcp_adapter_normalize_copy( mcp_adapter_reverse_known_differences( $relative_path, $copy ) ) === $upstream ) {
		echo "OK      core/{$relative_path}\n";
		continue;
	}

	$drifted[] = $relative_path;
	echo "DRIFT   core/{$relative_path} — differs from src/wp-includes/{$upstream_path} after normalization\n";
}

echo "\n";

if ( array() !== $failed ) {
	fwrite( STDERR, 'Could not check: ' . implode( ', ', $failed ) . "\n" );
	exit( 1 );
}

if ( array() !== $drifted ) {
	$source = '' !== $wp_develop ? $wp_develop : "wordpress-develop@{$ref}";
	fwrite( STDERR, count( $drifted ) . " file(s) drifted from {$source}. Re-copy them from upstream and re-apply the two mechanical transforms and the known differences (see core/README.md); never patch core-owned lines in the plugin otherwise.\n" );
	exit( 1 );
}

echo 'All copies match upstream' . ( array() !== $staged ? ' (' . count( $staged ) . ' staged plugin-first)' : '' ) . ".\n";
