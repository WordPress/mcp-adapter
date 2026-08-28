# Dual-revision Adapter artifact evidence

`npm run plugin-zip` builds a production-only staging tree from the reviewed
Composer lock, removes development dependencies, generates an authoritative
classmap, creates `mcp-adapter.zip`, extracts it, and runs both exact MCP
revisions through that extracted runtime.

The verifier rejects development packages, Composer source files, removed DTO
symbols, compatibility aliases, and any schema source ref other than `a0fb1ee`.
The extracted smoke covers:

- 2025 initialize, initialized notification, ping, tool/resource/prompt
  discovery, and tool execution; and
- 2026 server/discover plus representative tool, resource, and prompt discovery
  and execution.

The same extracted smoke passes on host PHP 8.4.21 and the cached `php:7.4-cli`
image running PHP 7.4.33.

## Production package comparison

The comparison baseline is exact Adapter commit
`19bc31f850abd6a0dbb49d63e56d5b6d7386aecf` with its locked production
dependencies: Jetpack Autoloader `v5.0.23` and DTO schema package `v0.1.3` at
`b2fcf97`. Both artifacts contain only production files and authoritative
classmaps.

| Measure | DTO baseline | Dual-revision candidate | Change |
| --- | ---: | ---: | ---: |
| ZIP bytes | 470,420 | 420,263 | -10.7% |
| ZIP files | 312 | 327 | +15 |
| Adapter and schema PHP bytes | 951,490 | 1,104,015 | +16.0% |
| Adapter and schema PHP files | 251 | 259 | +8 |
| Authoritative classmap entries | 257 | 265 | +8 |

The first uncorrected `wp-scripts plugin-zip` candidate was rejected: it exposed
development dependencies and measured about 30 MB across 8,570 files. It is not
a handoff artifact.

## Registration measurements

`measure.php` constructs one direct tool and one server. The cold measure
includes authoritative autoload plus first component registration. Each warm
iteration constructs a fresh tool and server, which intentionally validates and
caches both exact catalog projections. Values are medians of five isolated
processes with 1,000 warm iterations each.

| PHP 8.4.21, CLI OPcache disabled | DTO baseline | Dual-revision candidate |
| --- | ---: | ---: |
| First registration | 1,369.500 us | 2,774.583 us |
| Warm registration | 3.114 us | 15.814 us |
| Warm registrations/second | 321,178 | 63,235 |
| Loaded files | 35 | 41 |
| Allocated/peak memory | 4 MiB | 8 MiB |

| PHP 7.4.33, OPcache not loaded | DTO baseline | Dual-revision candidate |
| --- | ---: | ---: |
| First registration | 3,709.542 us | 5,401.333 us |
| Warm registration | 2.704 us | 14.301 us |
| Loaded files | 21 | 27 |
| Allocated/peak memory | 4 MiB | 8 MiB |

The dual-catalog startup path is about five to six times slower per fresh
registration, but remains under 17 microseconds warm in this harness. It is a
server/component registration cost, not a per-request catalog-selection cost.
CLI OPcache was not enabled, and no 32-bit runtime was available for this
measurement.
