<?php
/** Tests for Client ID Metadata Document registration. */
declare( strict_types=1 );
namespace WP\OAuth\Tests;

use WP\OAuth\Clients\Cimd\CimdApproval;
use WP\OAuth\Clients\Cimd\CimdDocument;
use WP\OAuth\Clients\Cimd\CimdFetcher;
use WP\OAuth\Clients\Cimd\CimdFetcherInterface;
use WP\OAuth\Clients\Cimd\CimdResolver;
use WP\OAuth\Clients\Client;
use WP\OAuth\Grants\AuthorizedGrant;
use WP\OAuth\Server;
use WP\OAuth\Tokens\ApplicationPasswordConnectionStore;
use WP_Application_Passwords;
use WP_UnitTestCase;

final class CimdTest extends WP_UnitTestCase {
	private const CLIENT_ID = 'https://client.example/oauth/client.json';

	/** Tests normalization of the supported public-client profile. */
	public function test_normalizes_the_supported_public_client_profile(): void {
		$document = CimdDocument::normalize(
			self::CLIENT_ID,
			array(
				'client_id'     => self::CLIENT_ID,
				'client_name'   => 'Example app',
				'redirect_uris' => array( 'https://client.example/callback' ),
			)
		);
		$this->assertIsArray( $document );
		$this->assertSame( 'none', $document['token_endpoint_auth_method'] );
		$this->assertSame( array( 'authorization_code' ), $document['grant_types'] );
		$this->assertSame( 'cimd', $document['source'] );
	}

	/** Tests stable UUID derivation for core's Application Password record. */
	public function test_url_client_id_has_a_stable_application_password_uuid(): void {
		$first = Client::application_password_id( self::CLIENT_ID );
		$this->assertTrue( wp_is_uuid( $first ) );
		$this->assertSame( $first, Client::application_password_id( self::CLIENT_ID ) );
	}

	/** Tests rejection of malformed protocol metadata arrays. */
	public function test_rejects_malformed_protocol_arrays(): void {
		$document = array(
			'client_id'     => self::CLIENT_ID,
			'client_name'   => 'Example app',
			'redirect_uris' => array( 'https://client.example/callback' ),
			'grant_types'   => 'authorization_code',
		);
		$this->assertWPError( CimdDocument::normalize( self::CLIENT_ID, $document ) );
	}

	/** Tests that special-purpose addresses fail before an HTTP request. */
	public function test_fetcher_rejects_special_purpose_addresses_before_http(): void {
		$result = ( new CimdFetcher() )->fetch( 'https://100.64.0.1/client.json' );
		$this->assertWPError( $result );
		$this->assertSame( 'oauth_server_cimd_address', $result->get_error_code() );
	}

	/**
	 * Tests rejection of unsafe client ID URLs.
	 *
	 * @dataProvider provide_invalid_urls
	 * @param string $url Unsafe candidate URL.
	 */
	public function test_rejects_unsafe_client_id_urls( string $url ): void {
		$this->assertWPError( CimdDocument::validate_url( $url ) );
	}

	/**
	 * Provides unsafe client ID URLs.
	 *
	 * @return array<string, array{0: string}> Unsafe URL cases.
	 */
	public static function provide_invalid_urls(): array {
		return array(
			'plain HTTP'       => array( 'http://client.example/client.json' ),
			'origin root'      => array( 'https://client.example/' ),
			'query'            => array( 'https://client.example/client.json?v=1' ),
			'fragment'         => array( 'https://client.example/client.json#x' ),
			'credentials'      => array( 'https://user@client.example/client.json' ),
			'non-default port' => array( 'https://client.example:8443/client.json' ),
			'dot segment'      => array( 'https://client.example/a/../client.json' ),
		);
	}

	/** Tests that CIMD clients keep their exact URL in one permanent record. */
	public function test_repository_keeps_exact_url_and_updates_one_permanent_record(): void {
		$repository = Server::instance()->clients();
		$metadata   = array(
			'client_id'                  => self::CLIENT_ID,
			'client_name'                => 'Example app',
			'redirect_uris'              => array( 'https://client.example/callback' ),
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'source'                     => 'cimd',
		);
		$first      = $repository->save_cimd( self::CLIENT_ID, $metadata, time() + 900, false );
		$this->assertInstanceOf( Client::class, $first );
		$this->assertSame( self::CLIENT_ID, $first->get_id() );
		$this->assertTrue( $first->is_cimd() );
		$this->assertFalse( $first->is_trusted() );

		$metadata['client_name'] = 'Renamed app';
		$second                  = $repository->save_cimd( self::CLIENT_ID, $metadata, time() + 900, true );
		$this->assertInstanceOf( Client::class, $second );
		$this->assertSame( 'Renamed app', $second->get_name() );
		$this->assertSame( $first->get_generation(), $second->get_generation(), 'A display-name-only change does not invalidate grants.' );
		$this->assertTrue( $second->is_trusted() );
	}

	/** Tests that security metadata changes invalidate grants and connections. */
	public function test_security_metadata_change_bumps_generation(): void {
		$repository = Server::instance()->clients();
		$metadata   = CimdDocument::normalize(
			self::CLIENT_ID,
			array(
				'client_id'     => self::CLIENT_ID,
				'client_name'   => 'App',
				'redirect_uris' => array( 'https://client.example/one' ),
			)
		);
		$this->assertIsArray( $metadata );
		$first      = $repository->save_cimd( self::CLIENT_ID, $metadata, time() + 900, false );
		$user       = self::factory()->user->create_and_get();
		$connection = ( new ApplicationPasswordConnectionStore() )->create( new AuthorizedGrant( $user, self::CLIENT_ID, 'App', '', $metadata ) );
		$this->assertNotWPError( $connection );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
		$metadata['redirect_uris'] = array( 'https://client.example/two' );
		$second                    = $repository->save_cimd( self::CLIENT_ID, $metadata, time() + 900, false );
		$this->assertNotSame( $first->get_generation(), $second->get_generation() );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $user->ID ) );
	}

	/** Tests that first registration waits for an administrator's approval. */
	public function test_resolver_requires_approval_then_uses_permanent_record(): void {
		$fetcher  = new class() implements CimdFetcherInterface {
			/** @var int */
			public $calls = 0;

			/**
			 * Returns deterministic metadata for resolver tests.
			 *
			 * @param string $url Exact client ID URL.
			 * @return array{metadata: array<string, mixed>, expires: int} Metadata and expiry.
			 */
			public function fetch( string $url ) {
				++$this->calls;
				return array(
					'metadata' => array(
						'client_id'                  => $url,
						'client_name'                => 'Fetched app',
						'redirect_uris'              => array( 'https://client.example/callback' ),
						'grant_types'                => array( 'authorization_code' ),
						'response_types'             => array( 'code' ),
						'token_endpoint_auth_method' => 'none',
						'source'                     => 'cimd',
					),
					'expires'  => time() + 900,
				);
			}
		};
		$admin    = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$resolver = new CimdResolver( Server::instance()->clients(), $fetcher );
		$approval = $resolver->resolve( self::CLIENT_ID, $admin->ID );

		$this->assertInstanceOf( CimdApproval::class, $approval );
		$this->assertNull( Server::instance()->clients()->get( self::CLIENT_ID ), 'Fetching metadata does not register the client before approval.' );
		$first  = $resolver->approve( $approval );
		$second = $resolver->resolve( self::CLIENT_ID, $admin->ID );

		$this->assertInstanceOf( Client::class, $first );
		$this->assertInstanceOf( Client::class, $second );
		$this->assertSame( 1, $fetcher->calls );
		$this->assertSame( self::CLIENT_ID, $second->get_id() );
	}

	/** Tests that a non-administrator cannot fetch an unknown CIMD client. */
	public function test_unknown_client_requires_administrator_before_fetch(): void {
		$fetcher  = new class() implements CimdFetcherInterface {
			/** @var int */
			public $calls = 0;

			/**
			 * Records an unexpected fetch.
			 *
			 * @param string $url Exact client ID URL.
			 * @return \WP_Error Test failure response.
			 */
			public function fetch( string $url ) {
				++$this->calls;
				return new \WP_Error( 'unexpected_fetch', $url );
			}
		};
		$user     = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$resolver = new CimdResolver( Server::instance()->clients(), $fetcher );
		$result   = $resolver->resolve( self::CLIENT_ID, $user->ID );

		$this->assertWPError( $result );
		$this->assertSame( 'oauth_server_cimd_approval_required', $result->get_error_code() );
		$this->assertSame( 0, $fetcher->calls );
	}

	/** Tests that security changes remain pending until renewed approval. */
	public function test_security_change_waits_for_administrator_reapproval(): void {
		$repository = Server::instance()->clients();
		$original   = CimdDocument::normalize(
			self::CLIENT_ID,
			array(
				'client_id'     => self::CLIENT_ID,
				'client_name'   => 'App',
				'redirect_uris' => array( 'https://client.example/old' ),
			)
		);
		$this->assertIsArray( $original );
		$repository->save_cimd( self::CLIENT_ID, $original, time() - 1, false );

		$fetcher  = new class() implements CimdFetcherInterface {
			/**
			 * Returns changed security metadata.
			 *
			 * @param string $url Exact client ID URL.
			 * @return array{metadata: array<string, mixed>, expires: int} Metadata and expiry.
			 */
			public function fetch( string $url ) {
				return array(
					'metadata' => array(
						'client_id'                  => $url,
						'client_name'                => 'App',
						'redirect_uris'              => array( 'https://client.example/new' ),
						'grant_types'                => array( 'authorization_code' ),
						'response_types'             => array( 'code' ),
						'token_endpoint_auth_method' => 'none',
						'source'                     => 'cimd',
					),
					'expires'  => time() + 900,
				);
			}
		};
		$admin    = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$resolver = new CimdResolver( $repository, $fetcher );
		$approval = $resolver->resolve( self::CLIENT_ID, $admin->ID );

		$this->assertInstanceOf( CimdApproval::class, $approval );
		$this->assertTrue( $approval->is_update() );
		$this->assertSame( array( 'https://client.example/old' ), $repository->get( self::CLIENT_ID )->get_redirect_uris(), 'The changed metadata is not stored before approval.' );

		$updated = $resolver->approve( $approval );
		$this->assertInstanceOf( Client::class, $updated );
		$this->assertSame( array( 'https://client.example/new' ), $updated->get_redirect_uris() );
	}
}
