<?php
/**
 * The consent screen of the authorization endpoint.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A hidden wp-admin screen where a signed-in user approves or denies an app.
 *
 * It lives in wp-admin, as core's `authorize-application.php` does, so WordPress handles
 * login (including two-factor plugins), requires the admin auth cookie, sends no-cache
 * headers and refuses to be framed. Any user who can read the site's dashboard can
 * approve an app; the app then acts with that user's capabilities.
 *
 * The request is validated in the order OAuth requires. Until the client and its return
 * address are known to match, errors are shown here and never sent anywhere. Later
 * errors are shown with a link back to the app instead of an automatic redirect, because
 * anyone can register a client with any return address, and an automatic redirect from
 * this site would make it an open redirector.
 *
 * Approval is a form post protected by a nonce bound to the exact request, so the app's
 * metadata document is fetched only once, when the screen loads.
 *
 * @since n.e.x.t
 */
final class AuthorizeScreen {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'mcp-adapter-oauth-authorize';

	/**
	 * Request fields carried from the screen to the decision, in signing order.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const FIELDS = array( 'client_id', 'client_name', 'redirect_uri', 'code_challenge', 'state', 'verified' );

	/**
	 * What the screen shows: an `error` (with an optional `return` link) or a `request`.
	 *
	 * @var array<string, mixed>
	 */
	private static array $view = array();

	/**
	 * Registers the menu hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
	}

	/**
	 * Adds the hidden screen. Hooked to `admin_menu`.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		$hook = add_submenu_page( '', self::page_title(), '', 'read', self::SLUG, array( self::class, 'render' ) );
		if ( false === $hook ) {
			return;
		}

		add_action( 'load-' . $hook, array( self::class, 'load' ) );
	}

	/**
	 * Validates the request or applies the decision, before any output.
	 *
	 * @return void
	 */
	public static function load(): void {
		global $title;

		// A page without a parent menu has no title core can look up, so the browser tab
		// would be empty and admin-header.php would pass null to strip_tags().
		$title = self::page_title(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Sets the admin page title, as core's own screens do.
		nocache_headers();

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decide() verifies the nonce; protocol values must stay exact.
			self::decide( wp_unslash( $_POST ) );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Showing the form changes nothing; protocol values must stay exact.
		self::$view = self::validate( wp_unslash( $_GET ) );
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<div class="wrap">';
		if ( isset( self::$view['request'] ) ) {
			self::render_consent( self::$view['request'] );
		} else {
			self::render_error( (string) ( self::$view['error'] ?? __( 'This connection request is not valid.', 'mcp-adapter' ) ), self::$view['return'] ?? null );
		}
		echo '</div>';
	}

	/**
	 * Validates an authorization request.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 * @return array<string, mixed> The view: `request` fields, or an `error`.
	 */
	private static function validate( array $query ): array {
		$client_id = self::text( $query, 'client_id' );
		if ( '' === $client_id ) {
			return array( 'error' => __( 'The request does not say which app is connecting.', 'mcp-adapter' ) );
		}

		$client = Clients::resolve( $client_id );
		if ( is_wp_error( $client ) ) {
			return array( 'error' => $client->get_error_message() );
		}

		$redirect = Clients::match_redirect( $client['redirect_uris'], isset( $query['redirect_uri'] ) ? self::text( $query, 'redirect_uri' ) : null );
		if ( null === $redirect ) {
			return array( 'error' => __( 'The app asked to return to an address it has not registered.', 'mcp-adapter' ) );
		}

		// From here on the return address is trusted, so the app can be told what failed.
		$state = self::text( $query, 'state' );
		if ( 'code' !== self::text( $query, 'response_type' ) ) {
			return self::failure( $redirect, $state, 'unsupported_response_type', __( 'The app asked for a sign-in flow this site does not support.', 'mcp-adapter' ) );
		}

		$challenge = self::text( $query, 'code_challenge' );
		if ( 'S256' !== self::text( $query, 'code_challenge_method' ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
			return self::failure( $redirect, $state, 'invalid_request', __( 'The app did not send a valid PKCE S256 code challenge.', 'mcp-adapter' ) );
		}

		if ( isset( $query['resource'] ) && ! Module::accepts_resource( self::text( $query, 'resource' ) ) ) {
			return self::failure( $redirect, $state, 'invalid_target', __( 'The app asked for access to a resource that is not on this site.', 'mcp-adapter' ) );
		}

		return array(
			'request' => array(
				'client_id'      => $client['id'],
				'client_name'    => $client['name'],
				'redirect_uri'   => $redirect,
				'code_challenge' => $challenge,
				'state'          => $state,
				'verified'       => $client['verified'] ? '1' : '',
			),
		);
	}

	/**
	 * Applies an approve or deny decision.
	 *
	 * @param array<string, mixed> $post The posted form.
	 * @return void
	 */
	private static function decide( array $post ): void {
		$request = array();
		foreach ( self::FIELDS as $field ) {
			$request[ $field ] = self::text( $post, $field );
		}

		if ( false === wp_verify_nonce( self::text( $post, '_wpnonce' ), self::nonce_action( $request ) ) ) {
			self::$view = array( 'error' => __( 'This approval form has expired or was changed. Start the connection again from the app.', 'mcp-adapter' ) );

			return;
		}

		if ( ! isset( $post['approve'] ) ) {
			self::redirect( self::callback_url( $request['redirect_uri'], $request['state'], array( 'error' => 'access_denied' ) ) );
		}

		$user   = wp_get_current_user();
		$secret = Tokens::secret();
		$now    = time();
		$id     = Grants::create(
			$user,
			array(
				'client_id'    => $request['client_id'],
				'client_name'  => $request['client_name'],
				'redirect_uri' => $request['redirect_uri'],
				'challenge'    => $request['code_challenge'],
				'gen'          => 0,
				'code'         => Tokens::digest( $secret ),
				'created'      => $now,
				'used'         => $now,
				'pw'           => Tokens::password_binding( $user ),
			)
		);
		if ( null === $id ) {
			self::$view = array( 'error' => __( 'The connection could not be saved. Try again.', 'mcp-adapter' ) );

			return;
		}

		$code = Tokens::sign(
			Tokens::CODE,
			array(
				'u' => $user->ID,
				'g' => $id,
				'x' => $now + Grants::CODE_TTL,
				's' => $secret,
			)
		);
		self::redirect( self::callback_url( $request['redirect_uri'], $request['state'], array( 'code' => $code ) ) );
	}

	/**
	 * Prints the consent form.
	 *
	 * @param array<string, string> $request The validated request.
	 * @return void
	 */
	private static function render_consent( array $request ): void {
		$user      = wp_get_current_user();
		$return_to = Clients::return_label( $request['redirect_uri'] );
		$identity  = '1' === $request['verified']
			/* translators: %s: host name that publishes the app's details. */
			? sprintf( __( 'The app\'s details are published by %s.', 'mcp-adapter' ), (string) wp_parse_url( $request['client_id'], PHP_URL_HOST ) )
			: __( 'The app registered itself, so WordPress cannot confirm who made it.', 'mcp-adapter' );

		/* translators: %s: app name. */
		echo '<h1>' . esc_html( sprintf( __( 'Connect %s?', 'mcp-adapter' ), $request['client_name'] ) ) . '</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: app name, 2: site name, 3: user display name. */
				__( '%1$s wants to use %2$s as you (%3$s).', 'mcp-adapter' ),
				$request['client_name'],
				get_bloginfo( 'name' ),
				$user->display_name
			)
		) . '</p>';
		echo '<p>' . esc_html__( 'It will be able to do anything your account can do through the WordPress REST API, until you disconnect it.', 'mcp-adapter' );
		if ( is_multisite() ) {
			echo ' ' . esc_html__( 'That includes the other sites in this network where your account has access.', 'mcp-adapter' );
		}
		echo '</p>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html( $identity ) . '</li>';
		/* translators: %s: host name the browser returns to. */
		echo '<li>' . esc_html( sprintf( __( 'After you decide, your browser returns to %s.', 'mcp-adapter' ), $return_to ) ) . '</li>';
		echo '<li>' . esc_html__( 'You can disconnect it at any time from your profile.', 'mcp-adapter' ) . '</li>';
		echo '</ul>';

		if ( Clients::is_loopback( $request['redirect_uri'] ) || Clients::is_app_scheme( $request['redirect_uri'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This app receives the approval on your own computer. Approve only if you just started this connection yourself.', 'mcp-adapter' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '">';
		wp_nonce_field( self::nonce_action( $request ), '_wpnonce', false );
		foreach ( self::FIELDS as $field ) {
			echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $request[ $field ] ) . '" />';
		}
		echo '<p class="submit">';
		echo '<button type="submit" name="approve" value="1" class="button button-primary">' . esc_html__( 'Approve', 'mcp-adapter' ) . '</button> ';
		echo '<button type="submit" name="deny" value="1" class="button">' . esc_html__( 'Deny', 'mcp-adapter' ) . '</button>';
		echo '</p></form>';
	}

	/**
	 * Prints an error, with a link back to the app when its address is trusted.
	 *
	 * @param string      $message What went wrong.
	 * @param string|null $return_url The app's error callback, or null.
	 * @return void
	 */
	private static function render_error( string $message, ?string $return_url ): void {
		echo '<h1>' . esc_html__( 'This connection cannot be completed', 'mcp-adapter' ) . '</h1>';
		echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		if ( null === $return_url ) {
			return;
		}

		// esc_url() drops schemes WordPress does not know, such as an app's own `cursor://`.
		$protocols = array( 'http', 'https', (string) wp_parse_url( $return_url, PHP_URL_SCHEME ) );
		/* translators: %s: host name of the app. */
		echo '<p><a class="button" href="' . esc_url( $return_url, $protocols ) . '">' . esc_html( sprintf( __( 'Return to %s', 'mcp-adapter' ), Clients::return_label( $return_url ) ) ) . '</a></p>';
	}

	/**
	 * An error view that links back to the app with an OAuth error.
	 *
	 * @param string $redirect    The trusted return address.
	 * @param string $state       The request's state.
	 * @param string $code        The OAuth error code.
	 * @param string $description What to show the user.
	 * @return array<string, mixed> The view.
	 */
	private static function failure( string $redirect, string $state, string $code, string $description ): array {
		return array(
			'error'  => $description,
			'return' => self::callback_url( $redirect, $state, array( 'error' => $code ) ),
		);
	}

	/**
	 * The app's return address with the response parameters, `state` and `iss` added.
	 *
	 * @param string                $redirect The return address.
	 * @param string                $state    The request's state; omitted when empty.
	 * @param array<string, string> $params   The response parameters.
	 * @return string The URL.
	 */
	private static function callback_url( string $redirect, string $state, array $params ): string {
		if ( '' !== $state ) {
			$params['state'] = $state;
		}
		$params['iss'] = Module::issuer();

		return $redirect . ( false === strpos( $redirect, '?' ) ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Sends the browser back to the app and ends the request.
	 *
	 * @param string $url The callback URL.
	 * @return never
	 */
	private static function redirect( string $url ): void {
		// Not wp_safe_redirect(): the app's return address is on another host by design.
		wp_redirect( $url, 303, 'MCP Adapter' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Validated against the client's registered addresses.
		exit;
	}

	/**
	 * The nonce action, bound to the exact request shown on the screen.
	 *
	 * @param array<string, string> $request The request fields.
	 * @return string The action.
	 */
	private static function nonce_action( array $request ): string {
		$values = array();
		foreach ( self::FIELDS as $field ) {
			$values[] = (string) ( $request[ $field ] ?? '' );
		}

		return 'mcp_adapter_oauth_' . hash( 'sha256', (string) wp_json_encode( $values ) );
	}

	/**
	 * The screen's title.
	 *
	 * @return string The translated title.
	 */
	private static function page_title(): string {
		return __( 'Connect an app', 'mcp-adapter' );
	}

	/**
	 * A string parameter, or an empty string.
	 *
	 * @param array<string, mixed> $values The parameters.
	 * @param string               $key    The name.
	 * @return string The value.
	 */
	private static function text( array $values, string $key ): string {
		return isset( $values[ $key ] ) && is_string( $values[ $key ] ) ? $values[ $key ] : '';
	}
}
