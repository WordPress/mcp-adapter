<?php
/**
 * Tests for stateless MCP continuation state.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Protocol;

use WP\MCP\Infrastructure\Protocol\ContinuationManager;
use WP\MCP\Tests\TestCase;

final class ContinuationManagerTest extends TestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	public function test_sealed_state_round_trips_and_ignores_unrequested_responses(): void {
		$manager = new ContinuationManager( 'test-server', static fn(): int => 1000 );
		$params  = $this->tool_params();
		$result  = $manager->prepare_result(
			'tools/call',
			$params,
			$this->identity( 1, $params ),
			array(
				'resultType'    => 'input_required',
				'inputRequests' => array(
					'confirm' => $this->elicitation_request(),
				),
				'requestState'  => 'callback-state',
			)
		);

		$this->assertStringStartsWith( 'mcp1.', $result['requestState'] );

		$retry                   = $this->tool_params();
		$retry['requestState']   = $result['requestState'];
		$retry['inputResponses'] = array(
			'confirm' => array(
				'action'  => 'accept',
				'content' => array( 'confirmed' => true ),
			),
			'extra'   => array( 'action' => 'cancel' ),
		);

		$continuation = $manager->resume( 'tools/call', $retry, $this->identity( 2, $retry ) );

		$this->assertSame( 'callback-state', $continuation['requestState'] );
		$this->assertSame( array( 'confirm' ), array_keys( $continuation['inputResponses'] ) );
		$this->assertTrue( $continuation['inputResponses']['confirm']['content']['confirmed'] );
	}

	public function test_canonical_origin_digest_ignores_json_object_key_order(): void {
		$manager = new ContinuationManager( 'test-server', static fn(): int => 1000 );
		$params  = $this->tool_params();
		$result  = $manager->prepare_result(
			'tools/call',
			$params,
			$this->identity( 1, $params ),
			array(
				'resultType'   => 'input_required',
				'requestState' => 'state',
			)
		);

		$retry = array(
			'_meta'       => $params['_meta'],
			'arguments'   => array(
				'nested'   => array(
					'beta'  => 2,
					'alpha' => 1,
				),
				'location' => 'Bucharest',
			),
			'name'        => 'weather',
			'requestState' => $result['requestState'],
		);

		$continuation = $manager->resume( 'tools/call', $retry, $this->identity( 2, $retry ) );
		$this->assertSame( 'state', $continuation['requestState'] );
	}

	public function test_tampering_user_origin_and_expiry_are_rejected(): void {
		$now     = 1000;
		$manager = new ContinuationManager( 'test-server', static function () use ( &$now ): int {
			return $now;
		} );
		$params  = $this->tool_params();
		$result  = $manager->prepare_result(
			'tools/call',
			$params,
			$this->identity( 1, $params ),
			array(
				'resultType'   => 'input_required',
				'requestState' => 'state',
			)
		);

		$retry                 = $params;
		$retry['requestState'] = $result['requestState'];

		$tampered                 = $retry;
		$tampered['requestState'] = substr( $retry['requestState'], 0, -1 ) . ( 'A' === substr( $retry['requestState'], -1 ) ? 'B' : 'A' );
		$this->assert_resume_rejected( $manager, $tampered, 'signature' );

		$other_origin                         = $retry;
		$other_origin['arguments']['location'] = 'Cluj';
		$this->assert_resume_rejected( $manager, $other_origin, 'does not match' );

		$other_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_user );
		$this->assert_resume_rejected( $manager, $retry, 'does not match' );

		wp_set_current_user( $this->user_id );
		$now = 2000;
		$this->assert_resume_rejected( $manager, $retry, 'expired' );
	}

	public function test_input_request_capabilities_are_derived_per_request(): void {
		$manager = new ContinuationManager( 'test-server' );
		$requests = array(
			'form'     => $this->elicitation_request(),
			'url'      => array(
				'method' => 'elicitation/create',
				'params' => array(
					'mode'    => 'url',
					'message' => 'Continue in the browser',
					'url'     => 'https://example.com/continue',
				),
			),
			'sampling' => array(
				'method' => 'sampling/createMessage',
				'params' => array(
					'messages'       => array(),
					'maxTokens'      => 10,
					'tools'          => array(),
					'includeContext' => 'thisServer',
				),
			),
			'roots'    => array(
				'method' => 'roots/list',
				'params' => array(),
			),
		);

		$missing = $manager->missing_capabilities(
			$requests,
			array(
				'elicitation' => array( 'form' => array() ),
				'sampling'    => array(),
			)
		);

		$this->assertArrayNotHasKey( 'form', $missing['elicitation'] );
		$this->assertArrayHasKey( 'url', $missing['elicitation'] );
		$this->assertArrayHasKey( 'tools', $missing['sampling'] );
		$this->assertArrayHasKey( 'context', $missing['sampling'] );
		$this->assertArrayHasKey( 'roots', $missing );

		$this->assertSame(
			array(),
			$manager->missing_capabilities(
				$requests,
				array(
					'elicitation' => array(
						'form' => array(),
						'url'  => array(),
					),
					'sampling'    => array(
						'tools'   => array(),
						'context' => array(),
					),
					'roots'       => array(),
				)
			)
		);
	}

	public function test_unauthenticated_users_cannot_create_continuation_state(): void {
		wp_set_current_user( 0 );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'authenticated WordPress user' );

		$params = $this->tool_params();
		( new ContinuationManager( 'test-server' ) )->prepare_result(
			'tools/call',
			$params,
			$this->identity( 1, $params ),
			array(
				'resultType'   => 'input_required',
				'requestState' => 'state',
			)
		);
	}

	/** @return array<string, mixed> */
	private function tool_params(): array {
		return array(
			'name'      => 'weather',
			'arguments' => array(
				'location' => 'Bucharest',
				'nested'   => array(
					'alpha' => 1,
					'beta'  => 2,
				),
			),
			'_meta'     => array(
				'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
				'io.modelcontextprotocol/clientCapabilities' => array(),
			),
		);
	}

	/** @return array<string, mixed> */
	private function elicitation_request(): array {
		return array(
			'method' => 'elicitation/create',
			'params' => array(
				'mode'            => 'form',
				'message'         => 'Confirm the operation',
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'confirmed' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'confirmed' ),
				),
			),
		);
	}

	/** @param array<string, mixed> $params */
	private function identity( int $id, array $params ): \stdClass {
		$identity = json_decode(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => 'tools/call',
					'params'  => $params,
				)
			)
		);

		$this->assertInstanceOf( \stdClass::class, $identity );

		return $identity;
	}

	/** @param array<string, mixed> $params */
	private function assert_resume_rejected( ContinuationManager $manager, array $params, string $message ): void {
		try {
			$manager->resume( 'tools/call', $params, $this->identity( 2, $params ) );
			$this->fail( 'Expected the continuation retry to be rejected.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertStringContainsString( $message, $exception->getMessage() );
		}
	}
}
