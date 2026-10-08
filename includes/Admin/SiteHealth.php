<?php
/**
 * Read-only MCP Adapter section for Tools > Site Health > Info.
 *
 * @package WP\MCP\Admin
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

use WP\MCP\Abilities\McpAbilityExposure;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Transport\Contracts\McpRestTransportInterface;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - SiteHealth
 *
 * Adds an "MCP Adapter" section to the Site Health Info screen through the
 * core `debug_information` filter. The section only reads state: the adapter
 * version, the registered servers and their endpoints, which abilities the
 * default server exposes, and whether Application Passwords are available.
 *
 * Each field has a translated `value` for the screen and an untranslated
 * `debug` value for the "Copy site info to clipboard" button.
 *
 * @since 0.8.0
 */
final class SiteHealth {

	/**
	 * Key of the section in the debug information array.
	 *
	 * @var string
	 */
	public const SECTION = 'mcp-adapter';

	/**
	 * How many exposed ability names to list before summarizing the rest.
	 *
	 * @var int
	 */
	public const MAX_LISTED_ABILITIES = 50;

	/**
	 * Abilities in this namespace are the default server's own tools, not abilities it exposes.
	 *
	 * @var string
	 */
	private const ADAPTER_NAMESPACE = 'mcp-adapter/';

	/**
	 * Adds the MCP Adapter section to the Site Health debug information.
	 *
	 * @since 0.8.0
	 *
	 * @param mixed $info Debug information sections, keyed by section.
	 *
	 * @return mixed The debug information with the MCP Adapter section added.
	 */
	public static function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$info[ self::SECTION ] = self::get_section();

		return $info;
	}

	/**
	 * Builds the MCP Adapter section.
	 *
	 * @since 0.8.0
	 *
	 * @return array{label: string, description: string, fields: array<string, array{label: string, value: string|array<string, string>, debug: string|array<string, string>}>}
	 */
	public static function get_section(): array {
		$adapter = McpAdapter::instance();

		// Servers are created on `rest_api_init`, which does not run on admin screens.
		// `init()` runs once per request and returns early when it already ran.
		$adapter->init();

		$default_enabled = $adapter->is_default_server_enabled();

		$fields = array(
			'version'               => array(
				'label' => __( 'Version', 'mcp-adapter' ),
				'value' => McpAdapter::VERSION,
				'debug' => McpAdapter::VERSION,
			),
			'default_server'        => array(
				'label' => __( 'Default server', 'mcp-adapter' ),
				'value' => $default_enabled ? __( 'Enabled', 'mcp-adapter' ) : __( 'Disabled by the mcp_adapter_create_default_server filter', 'mcp-adapter' ),
				'debug' => $default_enabled ? 'enabled' : 'disabled (mcp_adapter_create_default_server filter)',
			),
			'application_passwords' => self::get_application_passwords_field(
				wp_is_application_passwords_available(),
				wp_is_application_passwords_supported()
			),
		);

		$servers = $adapter->get_servers();

		if ( empty( $servers ) ) {
			$fields['servers'] = array(
				'label' => __( 'Servers', 'mcp-adapter' ),
				'value' => __( 'No MCP servers are registered', 'mcp-adapter' ),
				'debug' => 'none',
			);
		}

		foreach ( $servers as $server ) {
			$fields[ 'server_' . $server->get_server_id() ] = self::get_server_field( $server );
		}

		if ( $default_enabled ) {
			$fields = array_merge( $fields, self::get_ability_fields() );
		}

		return array(
			'label'       => __( 'MCP Adapter', 'mcp-adapter' ),
			'description' => __( 'Read-only diagnostics for the MCP Adapter: its servers, their endpoints, the abilities the default server exposes, and whether clients can sign in with Application Passwords.', 'mcp-adapter' ),
			'fields'      => $fields,
		);
	}

	/**
	 * Builds the field for one registered MCP server.
	 *
	 * @param \WP\MCP\Core\McpServer $server The server.
	 *
	 * @return array{label: string, value: array<string, string>, debug: array<string, string>}
	 */
	private static function get_server_field( McpServer $server ): array {
		$transports = $server->get_mcp_transports();
		$endpoint   = self::has_rest_transport( $transports ) ? self::get_endpoint_url( $server ) : '';

		$transport_names = empty( $transports ) ? 'none' : implode( ', ', $transports );
		$tools           = $server->count_tools();
		$resources       = $server->count_resources();
		$prompts         = $server->count_prompts();

		return array(
			'label' => sprintf(
				/* translators: %s: MCP server name. */
				__( 'Server: %s', 'mcp-adapter' ),
				$server->get_server_name()
			),
			'value' => array(
				__( 'ID', 'mcp-adapter' )         => $server->get_server_id(),
				__( 'Endpoint', 'mcp-adapter' )   => '' !== $endpoint ? $endpoint : __( 'None, the server has no HTTP transport', 'mcp-adapter' ),
				__( 'Transports', 'mcp-adapter' ) => empty( $transports ) ? __( 'None', 'mcp-adapter' ) : $transport_names,
				__( 'Tools', 'mcp-adapter' )      => number_format_i18n( $tools ),
				__( 'Resources', 'mcp-adapter' )  => number_format_i18n( $resources ),
				__( 'Prompts', 'mcp-adapter' )    => number_format_i18n( $prompts ),
			),
			'debug' => array(
				'id'         => $server->get_server_id(),
				'endpoint'   => '' !== $endpoint ? $endpoint : 'none',
				'transports' => $transport_names,
				'tools'      => (string) $tools,
				'resources'  => (string) $resources,
				'prompts'    => (string) $prompts,
			),
		);
	}

	/**
	 * Builds the fields about abilities exposed and not exposed through the default server.
	 *
	 * @return array<string, array{label: string, value: string, debug: string}>
	 */
	private static function get_ability_fields(): array {
		$exposed     = array();
		$not_exposed = array(
			'not_public'       => 0,
			'mcp_public_false' => 0,
			'invalid_mcp_meta' => 0,
		);

		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();

			if ( 0 === strpos( $name, self::ADAPTER_NAMESPACE ) ) {
				continue;
			}

			if ( McpAbilityExposure::is_public( $ability ) ) {
				$type      = self::get_mcp_type( $ability->get_meta() );
				$exposed[] = 'tool' === $type ? $name : sprintf( '%s (%s)', $name, $type );
				continue;
			}

			++$not_exposed[ self::get_not_exposed_reason( $ability->get_meta() ) ];
		}

		sort( $exposed, SORT_STRING );

		return array(
			'abilities_exposed'     => self::get_exposed_field( $exposed ),
			'abilities_not_exposed' => self::get_not_exposed_field( $not_exposed ),
		);
	}

	/**
	 * Builds the field that lists the exposed abilities.
	 *
	 * @param list<string> $exposed Sorted names of the exposed abilities.
	 *
	 * @return array{label: string, value: string, debug: string}
	 */
	private static function get_exposed_field( array $exposed ): array {
		$label = __( 'Abilities exposed by the default server', 'mcp-adapter' );
		$total = count( $exposed );

		if ( 0 === $total ) {
			return array(
				'label' => $label,
				'value' => __( 'None. No registered ability is marked public for MCP.', 'mcp-adapter' ),
				'debug' => 'none',
			);
		}

		$listed = implode( ', ', array_slice( $exposed, 0, self::MAX_LISTED_ABILITIES ) );
		$hidden = $total - self::MAX_LISTED_ABILITIES;

		$value = number_format_i18n( $total ) . ': ' . $listed;
		$debug = $total . ': ' . $listed;

		if ( $hidden > 0 ) {
			$value .= ' ' . sprintf(
				/* translators: %s: number of exposed abilities not listed. */
				_n( 'and %s more', 'and %s more', $hidden, 'mcp-adapter' ),
				number_format_i18n( $hidden )
			);
			$debug .= sprintf( ' and %d more', $hidden );
		}

		return array(
			'label' => $label,
			'value' => $value,
			'debug' => $debug,
		);
	}

	/**
	 * Builds the field that counts the registered abilities the default server does not expose.
	 *
	 * @param array{not_public: int, mcp_public_false: int, invalid_mcp_meta: int} $not_exposed Counts by reason.
	 *
	 * @return array{label: string, value: string, debug: string}
	 */
	private static function get_not_exposed_field( array $not_exposed ): array {
		$total   = array_sum( $not_exposed );
		$reasons = array();
		$debug   = array();

		if ( $not_exposed['not_public'] > 0 ) {
			$reasons[] = sprintf(
				/* translators: %s: number of abilities. */
				__( '%s not marked public', 'mcp-adapter' ),
				number_format_i18n( $not_exposed['not_public'] )
			);
			$debug[] = sprintf( 'not public: %d', $not_exposed['not_public'] );
		}

		if ( $not_exposed['mcp_public_false'] > 0 ) {
			$reasons[] = sprintf(
				/* translators: %s: number of abilities. */
				__( '%s with mcp.public set to false', 'mcp-adapter' ),
				number_format_i18n( $not_exposed['mcp_public_false'] )
			);
			$debug[] = sprintf( 'mcp.public false: %d', $not_exposed['mcp_public_false'] );
		}

		if ( $not_exposed['invalid_mcp_meta'] > 0 ) {
			$reasons[] = sprintf(
				/* translators: %s: number of abilities. */
				__( '%s with invalid mcp metadata', 'mcp-adapter' ),
				number_format_i18n( $not_exposed['invalid_mcp_meta'] )
			);
			$debug[] = sprintf( 'invalid mcp meta: %d', $not_exposed['invalid_mcp_meta'] );
		}

		$value = number_format_i18n( $total );
		$copy  = (string) $total;

		if ( ! empty( $reasons ) ) {
			$value .= ' (' . implode( ', ', $reasons ) . ')';
			$copy  .= ' (' . implode( ', ', $debug ) . ')';
		}

		return array(
			'label' => __( 'Registered abilities not exposed', 'mcp-adapter' ),
			'value' => $value,
			'debug' => $copy,
		);
	}

	/**
	 * Builds the Application Passwords field.
	 *
	 * @param bool $available Whether Application Passwords are available, from wp_is_application_passwords_available().
	 * @param bool $supported Whether the site supports them, from wp_is_application_passwords_supported().
	 *
	 * @return array{label: string, value: string, debug: string}
	 */
	private static function get_application_passwords_field( bool $available, bool $supported ): array {
		$label = __( 'Application Passwords', 'mcp-adapter' );

		if ( $available ) {
			return array(
				'label' => $label,
				'value' => __( 'Available', 'mcp-adapter' ),
				'debug' => 'available',
			);
		}

		if ( ! $supported ) {
			return array(
				'label' => $label,
				'value' => __( 'Not available: the site does not use HTTPS and is not a local environment', 'mcp-adapter' ),
				'debug' => 'not available (no HTTPS, not a local environment)',
			);
		}

		return array(
			'label' => $label,
			'value' => __( 'Not available: turned off by the wp_is_application_passwords_available filter', 'mcp-adapter' ),
			'debug' => 'not available (wp_is_application_passwords_available filter)',
		);
	}

	/**
	 * Returns the MCP component type an exposed ability is registered as.
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 *
	 * @return string 'tool', 'resource' or 'prompt'.
	 */
	private static function get_mcp_type( array $meta ): string {
		$type = is_array( $meta['mcp'] ?? null ) ? ( $meta['mcp']['type'] ?? 'tool' ) : 'tool';

		return in_array( $type, array( 'resource', 'prompt' ), true ) ? $type : 'tool';
	}

	/**
	 * Returns why an ability that is not exposed through MCP is not exposed.
	 *
	 * Mirrors the order of checks in McpAbilityExposure::is_meta_public().
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 *
	 * @return 'not_public'|'mcp_public_false'|'invalid_mcp_meta'
	 */
	private static function get_not_exposed_reason( array $meta ): string {
		$mcp_meta = $meta['mcp'] ?? array();

		if ( ! is_array( $mcp_meta ) ) {
			return 'invalid_mcp_meta';
		}

		if ( isset( $mcp_meta['public'] ) ) {
			return 'mcp_public_false';
		}

		return 'not_public';
	}

	/**
	 * Whether any of the transports serves the server over the REST API.
	 *
	 * @param array<string> $transports Transport class names.
	 */
	private static function has_rest_transport( array $transports ): bool {
		foreach ( $transports as $transport ) {
			if ( is_subclass_of( $transport, McpRestTransportInterface::class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds the full endpoint URL of a server.
	 *
	 * Uses rest_url(), so subdirectory installs and plain permalinks come out right.
	 *
	 * @param \WP\MCP\Core\McpServer $server The server.
	 */
	private static function get_endpoint_url( McpServer $server ): string {
		return rest_url(
			trim( $server->get_server_route_namespace(), '/' ) . '/' . ltrim( $server->get_server_route(), '/' )
		);
	}
}
