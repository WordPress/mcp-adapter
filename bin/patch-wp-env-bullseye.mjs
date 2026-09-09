#!/usr/bin/env node
/**
 * Point the Debian bullseye apt sources in wp-env's WordPress image at
 * archive.debian.org.
 *
 * Debian 11 (bullseye) reached end of life on 2026-08-31 and left the regular
 * mirrors. The official wordpress:php7.4 and wordpress:php8.0 images are the
 * last two built on bullseye, so `apt-get update` fails while wp-env builds
 * them and the PHP 7.4 and 8.0 test jobs never start. wp-env already rewrites
 * the sources for stretch and buster; this adds the same rewrite for bullseye
 * until a released wp-env carries it.
 *
 * Upstream fix: https://github.com/WordPress/gutenberg/pull/82478, merged on
 * 2026-09-05 and not in wp-env 11.14.0. The `wp-env` and `wp-env:test` npm
 * scripts run this before every wp-env command because `.npmrc` blocks
 * lifecycle hooks. Remove the script and those two calls once the installed
 * wp-env contains the bullseye rewrite; until then the script detects that
 * case and stays silent.
 *
 * Idempotent, and a no-op for every other PHP version: the inserted `sed`
 * calls match nothing in the sources list of the newer Debian images.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const template = join(
	dirname(
		createRequire( import.meta.url ).resolve(
			'@wordpress/env/package.json'
		)
	),
	'lib/runtime/docker/docker-config.js'
);

// Last line of wp-env's own buster rewrite. The bullseye rewrite goes after it.
const anchor = "RUN sed -i '/buster-updates/d' /etc/apt/sources.list";

// bullseye-security is dropped, not repointed: archive.debian.org does not
// serve it yet.
const patch = `

# bullseye (https://www.debian.org/News/2026/20260831)
RUN sed -i 's|deb.debian.org/debian bullseye|archive.debian.org/debian bullseye|g' /etc/apt/sources.list
RUN sed -i '/bullseye-security/d' /etc/apt/sources.list
RUN sed -i '/bullseye-updates/d' /etc/apt/sources.list`;

const source = readFileSync( template, 'utf8' );

if ( source.includes( 'archive.debian.org/debian bullseye' ) ) {
	// Already patched, or wp-env now ships the rewrite. Stay quiet: this runs
	// before every wp-env command.
} else if ( source.includes( anchor ) ) {
	writeFileSync( template, source.replace( anchor, anchor + patch ) );
	console.log( `Patched bullseye apt sources into ${ template }.` );
} else {
	// A warning, not an error: wp-env commands that never build the PHP 7.4
	// or 8.0 image must still work.
	console.warn(
		`::warning::Could not patch bullseye apt sources into ${ template }; wp-env internals changed. PHP 7.4 and 8.0 environments will fail to build.`
	);
}
