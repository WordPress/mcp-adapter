<?php
/** Secure client ID metadata fetcher. */

declare( strict_types=1 );
namespace WP\OAuth\Clients\Cimd;

use WP_Error;
defined( 'ABSPATH' ) || exit;

/** Fetches a small JSON document while pinning DNS and refusing proxies. */
final class CimdFetcher implements CimdFetcherInterface {
	private const MAX_BYTES = 5120;

	// RFC 6890 special-purpose ranges that must never be metadata publishers.
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive for array commas.
	private const BLOCKED_CIDRS = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.88.99.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'255.255.255.255/32',
		'::/128',
		'::/96',
		'::1/128',
		'::ffff:0:0/96',
		'64:ff9b::/96',
		'64:ff9b:1::/48',
		'100::/64',
		'2001::/23',
		'2001:db8::/32',
		'2002::/16',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'ff00::/8',
		'3fff::/20',
		'5f00::/16',
	);

	/**
	 * Fetches and validates a metadata document using a DNS-pinned request.
	 *
	 * @param string $url Exact client ID URL.
	 * @return array{metadata: array<string, mixed>, expires: int}|WP_Error Verified metadata and expiry, or a failure.
	 */
	public function fetch( string $url ) {
		$valid = CimdDocument::validate_url( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'oauth_server_cimd_transport', __( 'Client metadata cannot be fetched safely on this server.', 'mcp-adapter' ) );
		}
		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return new WP_Error( 'oauth_server_cimd_proxy', __( 'Client metadata fetching does not use configured HTTP proxies.', 'mcp-adapter' ) );
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ips  = $this->resolve_public_ips( $host );
		if ( is_wp_error( $ips ) ) {
			return $ips;
		}
		$used_curl = false;
		/**
		 * Pins the request to the DNS answers validated above.
		 *
		 * @param resource $handle      cURL handle.
		 * @param array    $args        WordPress HTTP request arguments.
		 * @param string   $request_url URL being requested.
		 */
		$pin = static function ( $handle, $args, $request_url ) use ( $url, $host, $ips, &$used_curl ): void {
			if ( $request_url !== $url ) {
				return;
			}
			$used_curl = true;
			// cURL is required here because CURLOPT_RESOLVE pins every allowed DNS answer.
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $handle, CURLOPT_PROXY, '' );
			curl_setopt( $handle, CURLOPT_NOPROXY, '*' );
			if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
				curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
			}
			curl_setopt(
				$handle,
				CURLOPT_RESOLVE,
				array_map(
					/**
					 * Formats an address for CURLOPT_RESOLVE.
					 *
					 * @param string $ip Public IP address.
					 */
					static function ( string $ip ) use ( $host ): string {
						return $host . ':443:' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip );
					},
					$ips
				)
			);
			// phpcs:enable
		};
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		// A custom cURL hook applies stricter SSRF controls than the VIP wrapper supports.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'sslverify'           => true,
				'headers'             => array( 'Accept' => 'application/json, application/*+json' ),
			)
		);
		remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
		if ( ! $used_curl ) {
			return new WP_Error( 'oauth_server_cimd_transport', __( 'The HTTP request did not use the pinned cURL transport.', 'mcp-adapter' ) );
		}
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'oauth_server_cimd_fetch', $response->get_error_message(), array( 'transient' => true ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'oauth_server_cimd_status', __( 'The client metadata publisher returned an unexpected status.', 'mcp-adapter' ), array( 'transient' => 429 === $status || $status >= 500 ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'oauth_server_cimd_size', __( 'The client metadata document is too large.', 'mcp-adapter' ) );
		}
		$type = strtolower( trim( explode( ';', self::header_value( wp_remote_retrieve_header( $response, 'content-type' ) ) )[0] ) );
		if ( 'application/json' !== $type && '+json' !== substr( $type, -5 ) ) {
			return new WP_Error( 'oauth_server_cimd_type', __( 'The client metadata response is not JSON.', 'mcp-adapter' ) );
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'oauth_server_cimd_json', __( 'The client metadata response is not a JSON object.', 'mcp-adapter' ) );
		}
		$metadata = CimdDocument::normalize( $url, $decoded );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$ttl   = 900;
		$cache = self::header_value( wp_remote_retrieve_header( $response, 'cache-control' ) );
		if ( preg_match( '/(?:^|,)\s*max-age=(\d+)/i', $cache, $match ) ) {
			$ttl = (int) $match[1];
		}
		return array(
			'metadata' => $metadata,
			'expires'  => time() + max( 300, min( 3600, $ttl ) ),
		);
	}

	/**
	 * Normalizes a header that the HTTP API may return once or multiple times.
	 *
	 * @param string|string[] $value Header value.
	 * @return string Combined header value.
	 */
	private static function header_value( $value ): string {
		return is_array( $value ) ? implode( ',', $value ) : $value;
	}

	/**
	 * Resolves every public address for a metadata publisher.
	 *
	 * @param string $host Publisher hostname.
	 * @return string[]|WP_Error Public IP addresses, or a resolution failure.
	 */
	private function resolve_public_ips( string $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$records = array( $host );
		} else {
			$records = array();
			foreach ( dns_get_record( $host, DNS_A | DNS_AAAA ) ?: array() as $record ) {
				$ip = (string) ( $record['ip'] ?? $record['ipv6'] ?? '' );
				if ( '' === $ip ) {
					continue;
				}

				$records[] = $ip;
			}
		}
		$records = array_values( array_unique( $records ) );
		foreach ( $records as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new WP_Error( 'oauth_server_cimd_address', __( 'The client metadata host resolves to a non-public address.', 'mcp-adapter' ) );
			}
		}
		return empty( $records ) ? new WP_Error( 'oauth_server_cimd_dns', __( 'The client metadata host could not be resolved.', 'mcp-adapter' ), array( 'transient' => true ) ) : $records;
	}

	/**
	 * Whether an IP is outside every special-purpose network.
	 *
	 * @param string $ip IP address.
	 */
	private static function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		foreach ( self::BLOCKED_CIDRS as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks a v4 or v6 address against a CIDR without integer-size assumptions.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr Network in CIDR notation.
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		[ $network, $prefix ] = explode( '/', $cidr, 2 );
		$address_bytes        = inet_pton( $ip );
		$network_bytes        = inet_pton( $network );
		if ( false === $address_bytes || false === $network_bytes || strlen( $address_bytes ) !== strlen( $network_bytes ) ) {
			return false;
		}
		$bits       = (int) $prefix;
		$full_bytes = intdiv( $bits, 8 );
		if ( substr( $address_bytes, 0, $full_bytes ) !== substr( $network_bytes, 0, $full_bytes ) ) {
			return false;
		}
		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}
		$mask = 0xff << 8 - $remaining;
		return ( ord( $address_bytes[ $full_bytes ] ) & $mask ) === ( ord( $network_bytes[ $full_bytes ] ) & $mask );
	}
}
