<?php

declare(strict_types=1);

// Standalone CLI harness: WordPress globals are intentionally stubbed and raw
// JSON/STDERR/file inclusion are required to prove the extracted wire runtime.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
// phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable
// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Schemas;

final class WP_Error {
	/** @var string */
	private $code;
	/** @var string */
	private $message;
	/** @var mixed */
	private $data;

	/** @param mixed $data */
	public function __construct( string $code = '', string $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}

/** @param mixed $value @param mixed ...$args @return mixed */
function apply_filters( string $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $value;
}

function __( string $text, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $text;
}

/** @param mixed $value */
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

/** @param mixed $value @return string|false */
function wp_json_encode( $value ) {
	return json_encode( $value );
}

/** @param mixed $condition */
function artifact_expect( $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Artifact smoke failed: {$message}\n" );
		exit( 1 );
	}
}

$autoload = $argv[1] ?? '';
if ( $autoload === '' || ! is_file( $autoload ) ) {
	fwrite( STDERR, "Usage: php smoke.php /path/to/vendor/autoload.php\n" );
	exit( 2 );
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
artifact_expect( $loader->isClassMapAuthoritative(), 'Composer classmap is not authoritative' );

$tool = McpTool::fromArray(
	array(
		'name'        => 'artifact-smoke',
		'description' => 'Executes the extracted Adapter artifact.',
		'inputSchema' => array( 'type' => 'object' ),
		'handler'     => static function ( array $arguments ): array {
			return array(
				'ok'        => true,
				'arguments' => $arguments,
			);
		},
		'permission'  => static function (): bool {
			return true;
		},
	)
);
artifact_expect( $tool instanceof McpTool, 'McpTool construction failed' );
$resource = McpResource::fromArray(
	array(
		'name'       => 'artifact-resource',
		'uri'        => 'artifact://resource',
		'handler'    => static function (): string {
			return 'artifact resource';
		},
		'permission' => static function (): bool {
			return true;
		},
	)
);
artifact_expect( $resource instanceof McpResource, 'McpResource construction failed' );
$prompt = McpPrompt::fromArray(
	array(
		'name'       => 'artifact-prompt',
		'handler'    => static function (): array {
			return array( 'text' => 'artifact prompt' );
		},
		'permission' => static function (): bool {
			return true;
		},
	)
);
artifact_expect( $prompt instanceof McpPrompt, 'McpPrompt construction failed' );

$server = new McpServer(
	'artifact',
	'artifact/v1',
	'server',
	'Artifact server',
	'Extracted dual-revision runtime smoke',
	'1.0.0',
	array(),
	null,
	null,
	array( $tool ),
	array( $resource ),
	array( $prompt )
);
$wire   = new McpWireOrchestrator( $server->create_transport_context() );

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed>|null $params_2025_11_25
 * @return array<string, mixed>
 */
$run = static function ( array $request, ?array $params_2025_11_25 = null ) use ( $wire ): array {
	$raw      = (string) json_encode( $request, JSON_UNESCAPED_SLASHES );
	$outcome  = $wire->process( $wire->decode( $raw ), 'STDIO', array(), $params_2025_11_25 );
	$response = $outcome['response'];
	artifact_expect( $response instanceof JsonSerializable, 'wire response did not hydrate' );
	/** @var array<string, mixed> $decoded */
	$decoded = json_decode( (string) json_encode( $response ), true );
	return $decoded;
};

/** @param array<string, mixed> $notification @param array<string, mixed>|null $params_2025_11_25 */
$notify = static function ( array $notification, ?array $params_2025_11_25 = null ) use ( $wire ): void {
	$raw     = (string) json_encode( $notification, JSON_UNESCAPED_SLASHES );
	$outcome = $wire->process( $wire->decode( $raw ), 'STDIO', array(), $params_2025_11_25 );
	artifact_expect( null === $outcome['response'], 'notification produced a response' );
};

$params_2025_11_25 = array(
	'protocolVersion' => Schemas::V2025_11_25,
	'capabilities'    => (object) array(),
	'clientInfo'      => array(
		'name'    => 'artifact-client',
		'version' => '1.0.0',
	),
);
$initialize        = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => $params_2025_11_25,
	)
);
artifact_expect(
	( $initialize['result']['protocolVersion'] ?? null ) === Schemas::V2025_11_25,
	'2025 initialize did not counter-propose the supported revision'
);
$notify(
	array(
		'jsonrpc' => '2.0',
		'method'  => 'notifications/initialized',
	),
	$params_2025_11_25
);
$ping = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'ping',
	),
	$params_2025_11_25
);
artifact_expect( isset( $ping['result'] ) && ! isset( $ping['error'] ), '2025 ping failed' );
$result_2025_11_25_list = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 3,
		'method'  => 'tools/list',
	),
	$params_2025_11_25
);
artifact_expect(
	( $result_2025_11_25_list['result']['tools'][0]['name'] ?? null ) === 'artifact-smoke',
	'2025 tools/list did not discover the artifact tool'
);
$result_2025_11_25_call = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 4,
		'method'  => 'tools/call',
		'params'  => array(
			'name'      => 'artifact-smoke',
			'arguments' => (object) array(),
		),
	),
	$params_2025_11_25
);
artifact_expect( ( $result_2025_11_25_call['result']['isError'] ?? true ) === false, '2025 tool execution failed' );
$result_2025_11_25_resources = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 8,
		'method'  => 'resources/list',
	),
	$params_2025_11_25
);
artifact_expect( ( $result_2025_11_25_resources['result']['resources'][0]['uri'] ?? null ) === 'artifact://resource', '2025 resource discovery failed' );
$result_2025_11_25_prompts = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 9,
		'method'  => 'prompts/list',
	),
	$params_2025_11_25
);
artifact_expect( ( $result_2025_11_25_prompts['result']['prompts'][0]['name'] ?? null ) === 'artifact-prompt', '2025 prompt discovery failed' );
$result_2025_11_25_resource = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 12,
		'method'  => 'resources/read',
		'params'  => array( 'uri' => 'artifact://resource' ),
	),
	$params_2025_11_25
);
artifact_expect( ( $result_2025_11_25_resource['result']['contents'][0]['text'] ?? null ) === 'artifact resource', '2025 resource execution failed' );
$result_2025_11_25_prompt = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 13,
		'method'  => 'prompts/get',
		'params'  => array(
			'name'      => 'artifact-prompt',
			'arguments' => (object) array(),
		),
	),
	$params_2025_11_25
);
artifact_expect( ( $result_2025_11_25_prompt['result']['messages'][0]['content']['text'] ?? null ) === 'artifact prompt', '2025 prompt execution failed' );

$meta_2026_07_28 = array(
	'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
	'io.modelcontextprotocol/clientCapabilities' => (object) array(),
);
$discover        = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 5,
		'method'  => 'server/discover',
		'params'  => array( '_meta' => $meta_2026_07_28 ),
	)
);
artifact_expect(
	( $discover['result']['supportedVersions'] ?? array() ) === Schemas::supportedVersions(),
	'2026 discovery did not advertise the exact revision set'
);
$result_2026_07_28_list = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 6,
		'method'  => 'tools/list',
		'params'  => array( '_meta' => $meta_2026_07_28 ),
	)
);
artifact_expect(
	( $result_2026_07_28_list['result']['tools'][0]['name'] ?? null ) === 'artifact-smoke',
	'2026 tools/list did not discover the artifact tool'
);
artifact_expect(
	! array_key_exists( 'execution', $result_2026_07_28_list['result']['tools'][0] ),
	'2026 Tool retained the removed execution field'
);
$result_2026_07_28_call = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 7,
		'method'  => 'tools/call',
		'params'  => array(
			'name'      => 'artifact-smoke',
			'arguments' => (object) array(),
			'_meta'     => $meta_2026_07_28,
		),
	)
);
artifact_expect( ( $result_2026_07_28_call['result']['isError'] ?? true ) === false, '2026 tool execution failed' );
artifact_expect( ( $result_2026_07_28_call['result']['resultType'] ?? null ) === 'complete', '2026 result default is missing' );
artifact_expect( ( $result_2026_07_28_call['result']['structuredContent']['ok'] ?? false ) === true, '2026 structured result is missing' );
$result_2026_07_28_resources = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 10,
		'method'  => 'resources/read',
		'params'  => array(
			'uri'   => 'artifact://resource',
			'_meta' => $meta_2026_07_28,
		),
	)
);
artifact_expect( ( $result_2026_07_28_resources['result']['contents'][0]['text'] ?? null ) === 'artifact resource', '2026 resource execution failed' );
$result_2026_07_28_prompt = $run(
	array(
		'jsonrpc' => '2.0',
		'id'      => 11,
		'method'  => 'prompts/get',
		'params'  => array(
			'name'      => 'artifact-prompt',
			'arguments' => (object) array(),
			'_meta'     => $meta_2026_07_28,
		),
	)
);
artifact_expect( ( $result_2026_07_28_prompt['result']['messages'][0]['content']['text'] ?? null ) === 'artifact prompt', '2026 prompt execution failed' );

echo json_encode(
	array(
		'authoritative' => true,
		'versions'      => Schemas::supportedVersions(),
		'flows'         => array(
			'2025' => array( 'initialize', 'notifications/initialized', 'ping', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' ),
			'2026' => array( 'server/discover', 'tools/list', 'tools/call', 'resources/read', 'prompts/get' ),
		),
	),
	JSON_UNESCAPED_SLASHES
), "\n";
