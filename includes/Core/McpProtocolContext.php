<?php
/**
 * Request-scoped MCP protocol context.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

/**
 * Carries the protocol revision selected for one request.
 *
 * The protocol version and schema revision are separate concepts. Every
 * supported protocol version maps explicitly to one exact DTO tree.
 *
 * @since n.e.x.t
 */
final class McpProtocolContext {

	/**
	 * Initialize-based protocol lifecycle.
	 *
	 * @var string
	 */
	private const LIFECYCLE_INITIALIZE = 'initialize';

	/**
	 * Discover-based protocol lifecycle.
	 *
	 * @var string
	 */
	private const LIFECYCLE_DISCOVER = 'discover';

	/**
	 * Session-based request mode.
	 *
	 * @var string
	 */
	private const REQUEST_MODE_SESSION = 'session';

	/**
	 * Stateless request mode.
	 *
	 * @var string
	 */
	private const REQUEST_MODE_STATELESS = 'stateless';

	/**
	 * Protocol version 2024-11-05.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2024_11_05 = '2024-11-05';

	/**
	 * Protocol version 2025-06-18.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2025_06_18 = '2025-06-18';

	/**
	 * Protocol version 2025-11-25.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2025_11_25 = '2025-11-25';

	/**
	 * Protocol version 2026-07-28.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2026_07_28 = '2026-07-28';

	/**
	 * Schema revision 2025-11-25.
	 *
	 * @var string
	 */
	public const SCHEMA_REVISION_2025_11_25 = '2025-11-25';

	/**
	 * Schema revision 2026-07-28.
	 *
	 * @var string
	 */
	public const SCHEMA_REVISION_2026_07_28 = '2026-07-28';

	/**
	 * Request metadata key carrying the protocol revision.
	 *
	 * @var string
	 */
	public const REQUEST_PROTOCOL_VERSION_META_KEY = 'io.modelcontextprotocol/protocolVersion';

	/**
	 * Request metadata key carrying per-request client capabilities.
	 *
	 * @var string
	 */
	public const REQUEST_CLIENT_CAPABILITIES_META_KEY = 'io.modelcontextprotocol/clientCapabilities';

	/**
	 * Explicit protocol profile registry, in preferred order within each lifecycle.
	 *
	 * This is the sole source of truth for protocol support. Protocol versions
	 * are opaque identifiers; their order and behavior must never be inferred
	 * through numeric or lexical comparisons.
	 *
	 * @var array<string, array{schema_revision: string, lifecycle: string, request_mode: string, required_request_metadata: array<int, string>, supported_methods: array<int, string>|null}>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const PROTOCOL_REGISTRY = array(
		self::PROTOCOL_VERSION_2026_07_28 => array(
			'schema_revision'           => self::SCHEMA_REVISION_2026_07_28,
			'lifecycle'                 => self::LIFECYCLE_DISCOVER,
			'request_mode'              => self::REQUEST_MODE_STATELESS,
			'required_request_metadata' => array(
				self::REQUEST_PROTOCOL_VERSION_META_KEY,
				self::REQUEST_CLIENT_CAPABILITIES_META_KEY,
			),
			'supported_methods'         => array( 'tools/call' ),
		),
		self::PROTOCOL_VERSION_2025_11_25 => array(
			'schema_revision'           => self::SCHEMA_REVISION_2025_11_25,
			'lifecycle'                 => self::LIFECYCLE_INITIALIZE,
			'request_mode'              => self::REQUEST_MODE_SESSION,
			'required_request_metadata' => array(),
			'supported_methods'         => null,
		),
		self::PROTOCOL_VERSION_2025_06_18 => array(
			'schema_revision'           => self::SCHEMA_REVISION_2025_11_25,
			'lifecycle'                 => self::LIFECYCLE_INITIALIZE,
			'request_mode'              => self::REQUEST_MODE_SESSION,
			'required_request_metadata' => array(),
			'supported_methods'         => null,
		),
		self::PROTOCOL_VERSION_2024_11_05 => array(
			'schema_revision'           => self::SCHEMA_REVISION_2025_11_25,
			'lifecycle'                 => self::LIFECYCLE_INITIALIZE,
			'request_mode'              => self::REQUEST_MODE_SESSION,
			'required_request_metadata' => array(),
			'supported_methods'         => null,
		),
	);

	/**
	 * Selected protocol version.
	 *
	 * @var string
	 */
	private string $protocol_version;

	/**
	 * Create a request protocol context.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $protocol_version Selected protocol version.
	 *
	 * @throws \InvalidArgumentException If the protocol version is unsupported.
	 */
	public function __construct( string $protocol_version ) {
		if ( ! isset( self::PROTOCOL_REGISTRY[ $protocol_version ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unsupported protocol version: %s', '' !== $protocol_version ? $protocol_version : '(empty)' )
			);
		}

		$this->protocol_version = $protocol_version;
	}

	/**
	 * Create a context for protocol version 2025-11-25.
	 *
	 * @since n.e.x.t
	 */
	public static function for_2025_11_25(): self {
		return new self( self::PROTOCOL_VERSION_2025_11_25 );
	}

	/**
	 * Get the selected protocol version.
	 *
	 * @since n.e.x.t
	 */
	public function get_protocol_version(): string {
		return $this->protocol_version;
	}

	/**
	 * Get the exact php-mcp-schema revision used for this request.
	 *
	 * @since n.e.x.t
	 */
	public function get_schema_revision(): string {
		return $this->get_profile()['schema_revision'];
	}

	/**
	 * Whether this profile uses the initialize lifecycle.
	 *
	 * @since n.e.x.t
	 */
	public function uses_initialize_lifecycle(): bool {
		return self::LIFECYCLE_INITIALIZE === $this->get_profile()['lifecycle'];
	}

	/**
	 * Whether this profile uses the discover lifecycle.
	 *
	 * @since n.e.x.t
	 */
	public function uses_discover_lifecycle(): bool {
		return self::LIFECYCLE_DISCOVER === $this->get_profile()['lifecycle'];
	}

	/**
	 * Whether requests use session state.
	 *
	 * @since n.e.x.t
	 */
	public function uses_sessions(): bool {
		return self::REQUEST_MODE_SESSION === $this->get_profile()['request_mode'];
	}

	/**
	 * Whether requests are stateless.
	 *
	 * @since n.e.x.t
	 */
	public function is_stateless(): bool {
		return self::REQUEST_MODE_STATELESS === $this->get_profile()['request_mode'];
	}

	/**
	 * Get metadata keys required on every request for this profile.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string>
	 */
	public function get_required_request_metadata_keys(): array {
		return $this->get_profile()['required_request_metadata'];
	}

	/**
	 * Whether the Adapter currently supports a method for this profile.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $method MCP method name.
	 */
	public function supports_method( string $method ): bool {
		$supported_methods = $this->get_profile()['supported_methods'];

		return null === $supported_methods || in_array( $method, $supported_methods, true );
	}

	/**
	 * Get initialize-lifecycle protocol versions in explicit preference order.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string>
	 */
	public static function get_initialize_protocol_versions(): array {
		return self::get_protocol_versions_for_lifecycle( self::LIFECYCLE_INITIALIZE );
	}

	/**
	 * Get discover-lifecycle protocol versions in explicit preference order.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string>
	 */
	public static function get_discover_protocol_versions(): array {
		return self::get_protocol_versions_for_lifecycle( self::LIFECYCLE_DISCOVER );
	}

	/**
	 * Get this context's registry profile.
	 *
	 * @return array{schema_revision: string, lifecycle: string, request_mode: string, required_request_metadata: array<int, string>, supported_methods: array<int, string>|null}
	 */
	private function get_profile(): array {
		return self::PROTOCOL_REGISTRY[ $this->protocol_version ];
	}

	/**
	 * Get registered protocol versions for one lifecycle.
	 *
	 * @param string $lifecycle Protocol lifecycle.
	 * @return array<int, string>
	 */
	private static function get_protocol_versions_for_lifecycle( string $lifecycle ): array {
		$versions = array();

		foreach ( self::PROTOCOL_REGISTRY as $protocol_version => $profile ) {
			if ( $lifecycle !== $profile['lifecycle'] ) {
				continue;
			}

			$versions[] = $protocol_version;
		}

		return $versions;
	}
}
