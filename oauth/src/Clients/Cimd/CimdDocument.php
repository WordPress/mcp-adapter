<?php
/** Client ID metadata document validation. */

declare( strict_types=1 );

namespace WP\OAuth\Clients\Cimd;

use WP\OAuth\Clients\Client;
use WP\OAuth\Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Validates the deliberately small CIMD profile supported by this server. */
final class CimdDocument {

	/**
	 * Validates the URL form required for a client ID metadata document.
	 *
	 * @param string $url Candidate client ID URL.
	 * @return true|WP_Error True when valid, otherwise the validation failure.
	 */
	public static function validate_url( string $url ) {
		if ( strlen( $url ) > 2048 || false !== strpos( $url, '#' ) ) {
			return new WP_Error( 'oauth_server_cimd_url', __( 'The client metadata URL is not valid.', 'mcp-adapter' ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return new WP_Error( 'oauth_server_cimd_url', __( 'Client metadata IDs must be HTTPS URLs with a non-root path and no credentials, query, fragment, or non-default port.', 'mcp-adapter' ) );
		}
		$path = rawurldecode( (string) $parts['path'] );
		if ( 1 === preg_match( '#(?:^|/)\.\.?(/|$)#', $path ) || untrailingslashit( $url ) === Server::issuer() ) {
			return new WP_Error( 'oauth_server_cimd_url', __( 'The client metadata URL contains a disallowed path.', 'mcp-adapter' ) );
		}
		return true;
	}

	/**
	 * Validates and normalizes a decoded metadata document.
	 *
	 * @param string               $url      Exact client ID URL.
	 * @param array<string, mixed> $document Decoded JSON object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function normalize( string $url, array $document ) {
		if ( ( $document['client_id'] ?? null ) !== $url ) {
			return new WP_Error( 'oauth_server_cimd_mismatch', __( 'The metadata document client_id does not exactly match its URL.', 'mcp-adapter' ) );
		}
		if ( ! isset( $document['client_name'] ) || ! is_string( $document['client_name'] ) ) {
			return new WP_Error( 'oauth_server_cimd_document', __( 'The metadata document must contain a string client_name.', 'mcp-adapter' ) );
		}
		$name = sanitize_text_field( $document['client_name'] );
		$uris = $document['redirect_uris'] ?? null;
		if ( '' === $name || ! is_array( $uris ) || empty( $uris ) ) {
			return new WP_Error( 'oauth_server_cimd_document', __( 'The metadata document must contain a client_name and redirect_uris.', 'mcp-adapter' ) );
		}
		$clean_uris = array();
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || is_wp_error( Client::validate_redirect_uri( $uri ) ) ) {
				return new WP_Error( 'oauth_server_cimd_redirect', __( 'The metadata document contains an invalid redirect URI.', 'mcp-adapter' ) );
			}
			$clean_uris[] = $uri;
		}

		$methods   = isset( $document['token_endpoint_auth_methods'] ) ? $document['token_endpoint_auth_methods'] : array( $document['token_endpoint_auth_method'] ?? 'none' );
		$grants    = $document['grant_types'] ?? array( 'authorization_code' );
		$responses = $document['response_types'] ?? array( 'code' );
		if ( ! self::is_string_list( $methods ) || ! self::is_string_list( $grants ) || ! self::is_string_list( $responses ) || ! in_array( 'none', $methods, true ) || ! in_array( 'authorization_code', $grants, true ) || ! in_array( 'code', $responses, true ) || array_key_exists( 'client_secret', $document ) || array_key_exists( 'client_secret_expires_at', $document ) ) {
			return new WP_Error( 'oauth_server_cimd_profile', __( 'This server only accepts public metadata clients using authorization code, PKCE, and no client secret.', 'mcp-adapter' ) );
		}

		$document['client_name']                = $name;
		$document['redirect_uris']              = array_values( array_unique( $clean_uris ) );
		$document['grant_types']                = array_values( array_map( 'strval', $grants ) );
		$document['response_types']             = array_values( array_map( 'strval', $responses ) );
		$document['token_endpoint_auth_method'] = 'none';
		$document['source']                     = 'cimd';
		unset( $document['token_endpoint_auth_methods'] );
		return $document;
	}

	/**
	 * Whether a value is a JSON array containing only strings.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return bool Whether the value is an array containing only strings.
	 */
	private static function is_string_list( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}
}
