<?php

declare(strict_types=1);

// Standalone CLI harness: WordPress globals are intentionally stubbed and raw
// JSON/STDERR/file inclusion are the behavior under measurement.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
// phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.config_settings_opcache_get_status
// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;

final class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/** @return null */
	public function get_error_data() {
		return null;
	}
}

/** @param mixed $value @param mixed ...$args @return mixed */
function apply_filters( string $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $value;
}

/** @param mixed $value */
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $text;
}

/** @param mixed $value @return string|false */
function wp_json_encode( $value ) {
	return json_encode( $value );
}

$autoload   = $argv[1] ?? '';
$iterations = isset( $argv[2] ) ? (int) $argv[2] : 500;
if ( $autoload === '' || ! is_file( $autoload ) || $iterations < 1 ) {
	fwrite( STDERR, "Usage: php measure.php /path/to/vendor/autoload.php [iterations]\n" );
	exit( 2 );
}

$started = hrtime( true );
require $autoload;

$make_server = static function (): McpServer {
	$tool = McpTool::fromArray(
		array(
			'name'        => 'measurement-tool',
			'inputSchema' => array( 'type' => 'object' ),
			'handler'     => static function (): array {
				return array( 'ok' => true );
			},
			'permission'  => static function (): bool {
				return true;
			},
		)
	);
	if ( ! $tool instanceof McpTool ) {
		throw new \RuntimeException( 'Could not construct measurement tool' );
	}

	return new McpServer(
		'measurement',
		'measurement/v1',
		'server',
		'Measurement server',
		'Artifact registration measurement',
		'1.0.0',
		array(),
		null,
		null,
		array( $tool )
	);
};

$make_server();
$first_finished = hrtime( true );

$warm_started = hrtime( true );
for ( $index = 0; $index < $iterations; ++$index ) {
	$make_server();
}
$warm_finished = hrtime( true );

$warm_seconds = ( $warm_finished - $warm_started ) / 1000000000;
$opcache      = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
echo json_encode(
	array(
		'php'                   => PHP_VERSION,
		'iterations'            => $iterations,
		'first_registration_us' => round( ( $first_finished - $started ) / 1000, 3 ),
		'warm_registration_us'  => round( ( $warm_finished - $warm_started ) / 1000 / $iterations, 3 ),
		'registrations_per_s'   => round( $iterations / $warm_seconds ),
		'loaded_files'          => count( get_included_files() ),
		'memory_bytes'          => memory_get_usage( true ),
		'peak_memory_bytes'     => memory_get_peak_usage( true ),
		'opcache_loaded'        => extension_loaded( 'Zend OPcache' ),
		'opcache_enabled'       => is_array( $opcache ) && ( $opcache['opcache_enabled'] ?? false ),
	),
	JSON_UNESCAPED_SLASHES
), "\n";
