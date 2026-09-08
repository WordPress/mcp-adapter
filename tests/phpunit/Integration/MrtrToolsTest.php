<?php
/**
 * MRTR behavior through the exact request and response boundary.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Schemas;

/** Exercises direct-tool continuations without bypassing normal tool dispatch. */
final class MrtrToolsTest extends TestCase {

	/** Existing one-argument user callbacks work without declaring context. */
	public function test_one_argument_callable_receives_original_arguments(): void {
		$tool   = $this->tool(
			static function ( array $args ): array {
				return $args;
			}
		);
		$result = $this->call( $this->makeServer( array( $tool ) ), array( 'arguments' => array( 'value' => 7 ) ), 1 )['result'];
		$this->assertSame( array( 'value' => 7 ), $result['structuredContent'] );
	}

	/** Ability callbacks receive only domain input and cannot consume MRTR fields. */
	public function test_ability_callback_contract_is_preserved(): void {
		$calls          = 0;
		$argument_count = null;
		$this->register_ability_in_hook(
			'test/mrtr-boundary',
			array(
				'label'               => 'Ability boundary',
				'description'         => 'Checks the ordinary Ability callback contract.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'value' => array( 'type' => 'integer' ) ),
					'required'   => array( 'value' ),
				),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( array $args ) use ( &$calls, &$argument_count ): array {
					++$calls;
					$argument_count = func_num_args();
					return $args;
				},
			)
		);
		$tool = McpTool::fromAbility( wp_get_ability( 'test/mrtr-boundary' ) );
		$this->assertInstanceOf( McpTool::class, $tool );
		$server = $this->makeServer( array( $tool ) );
		$params = array(
			'name'      => $tool->get_name(),
			'arguments' => array( 'value' => 9 ),
		);
		$result = $this->call( $server, $params, 1 )['result'];
		$this->assertSame( array( 'value' => 9 ), $result['structuredContent'] );
		$this->assertSame( 1, $argument_count );
		foreach ( array( array( 'inputResponses' => array( 'choice' => self::answer() ) ), array( 'requestState' => 'state' ) ) as $extra ) {
			$this->assertSame( -32602, $this->call( $server, array_merge( $params, $extra ), 2 )['error']['code'] );
		}
		$this->assertSame( 1, $calls );
	}

	/** Tokenless requests deliver only this attempt's answers without merging arguments. */
	public function test_tokenless_input_and_current_request_context(): void {
		$tool   = $this->tool(
			static function ( array $args, McpToolCallContext $context ) {
				$answers = $context->input_responses();
				if ( ! $context->is_continuation() ) {
					return new McpInputRequired( array( 'choice' => self::question() ) );
				}
				$answers->mutated = true;
				return array(
					'args'    => $args,
					'answers' => $context->input_responses(),
					'state'   => $context->request_state(),
				);
			}
		);
		$server = $this->makeServer( array( $tool ) );
		$first  = $this->call( $server, array(), 1 )['result'];
		$this->assertSame( 'input_required', $first['resultType'] );
		$this->assertArrayNotHasKey( 'requestState', $first );
		$this->assertArrayNotHasKey( 'content', $first );
		$second = $this->call(
			$server,
			array(
				'arguments'      => array( 'original' => true ),
				'inputResponses' => array( 'choice' => self::answer() ),
			),
			2
		)['result'];
		$this->assertSame( 'complete', $second['resultType'] );
		$this->assertSame( array( 'original' => true ), $second['structuredContent']['args'] );
		$this->assertSame( array( 'choice' => self::answer() ), $second['structuredContent']['answers'] );
		$this->assertNull( $second['structuredContent']['state'] );
		$third = $this->call( $server, array( 'inputResponses' => array( 'other' => array( 'action' => 'cancel' ) ) ), 3 )['result'];
		$this->assertSame( array( 'other' => array( 'action' => 'cancel' ) ), $third['structuredContent']['answers'] );
	}

	/** State belongs to the author and is neither decoded nor authenticated by the Adapter. */
	public function test_opaque_state_is_preserved_and_author_can_reject_it(): void {
		$tool   = $this->tool(
			static function ( array $args, McpToolCallContext $context ) {
				if ( ! $context->is_continuation() ) {
					return new McpInputRequired( array(), 'provider-state' );
				}
				if ( 'provider-state' !== $context->request_state() ) {
					return new \WP_Error( 'provider_rejected_state', 'The provider rejected this state.' );
				}
				return array( 'state' => $context->request_state() );
			}
		);
		$server = $this->makeServer( array( $tool ) );
		$first  = $this->call( $server, array(), 1 )['result'];
		$this->assertSame( 'provider-state', $first['requestState'] );
		$this->assertArrayNotHasKey( 'inputRequests', $first );
		$this->assertSame( 'provider-state', $this->call( $server, array( 'requestState' => 'provider-state' ), 2 )['result']['structuredContent']['state'] );
		$this->assertTrue( $this->call( $server, array( 'requestState' => 'tampered' ), 3 )['result']['isError'] );
	}

	/** Only the explicit object is a control result, for direct tools. */
	public function test_array_result_is_data_and_empty_control_result_is_rejected(): void {
		$data   = array(
			'resultType'    => 'input_required',
			'inputRequests' => array( 'arbitrary' => 'data' ),
			'requestState'  => 'domain',
		);
		$tool   = $this->tool(
			static function () use ( $data ): array {
				return $data;
			}
		);
		$result = $this->call( $this->makeServer( array( $tool ) ), array(), 1 )['result'];
		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( $data, $result['structuredContent'] );
		$this->expectException( \InvalidArgumentException::class );
		new McpInputRequired();
	}

	/** Invalid protocol fields fail before reaching author code. */
	public function test_malformed_input_is_rejected_before_callback(): void {
		$calls  = 0;
		$tool   = $this->tool(
			static function () use ( &$calls ): array {
				++$calls;
				return array();
			}
		);
		$server = $this->makeServer( array( $tool ) );
		foreach ( array( array( 'requestState' => array() ), array( 'inputResponses' => 'invalid' ), array( 'inputResponses' => array( 'q' => array( 'action' => 'invalid' ) ) ) ) as $extra ) {
			$this->assertSame( -32602, $this->call( $server, $extra, 1 )['error']['code'] );
		}
		$this->assertSame( 0, $calls );
	}

	/** Invalid outgoing requests are rejected by the exact schema. */
	public function test_invalid_outgoing_request_is_not_sent(): void {
		$tool = $this->tool(
			static function () {
				return new McpInputRequired( array( 'q' => array( 'method' => 'not/a/method' ) ) );
			}
		);
		$this->assertSame( -32603, $this->call( $this->makeServer( array( $tool ) ), array(), 1 )['error']['code'] );
	}

	/** Capabilities are checked per request, including implicit form support. */
	public function test_form_capability_modes_and_revocation(): void {
		$server = $this->makeServer( array( $this->tool() ) );
		foreach ( array( array( 'elicitation' => new \stdClass() ), array( 'elicitation' => array( 'form' => new \stdClass() ) ) ) as $caps ) {
			$response = $this->call( $server, array( '_meta' => $this->meta( $caps ) ), 1 );
			$this->assertSame( 'input_required', $response['result']['resultType'] );
		}
		foreach ( array( array(), array( 'elicitation' => array( 'url' => new \stdClass() ) ) ) as $caps ) {
			$response = $this->call( $server, array( '_meta' => $this->meta( $caps ) ), 2 );
			$this->assertSame( -32021, $response['error']['code'] );
			$this->assertArrayHasKey( 'form', $response['error']['data']['requiredCapabilities']['elicitation'] );
		}
		$this->call( $server, array(), 3 );
		$response = $this->call(
			$server,
			array(
				'_meta' => $this->meta( array() ),
			),
			4
		);
		$this->assertSame( -32021, $response['error']['code'] );
	}

	/** Ordinary results and legacy completed calls retain their existing meaning. */
	public function test_direct_tools_and_legacy_boundaries(): void {
		$data     = array(
			'resultType'    => 'input_required',
			'inputRequests' => array( 'domain' => 'data' ),
		);
		$ordinary = $this->tool(
			static function () use ( $data ): array {
				return $data;
			}
		);
		$server   = $this->makeServer( array( $ordinary ) );
		$result   = $this->call( $server, array(), 1 )['result'];
		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( $data, $result['structuredContent'] );
		$this->assertSame( $data, $this->call( $server, array( 'requestState' => 'author-owned' ), 2 )['result']['structuredContent'] );

		$server = $this->makeServer( array( $this->tool() ) );
		$this->assertSame( -32603, $this->call( $server, array(), 3, Schemas::V2025_11_25 )['error']['code'] );
		$this->assertSame( -32602, $this->call( $server, array( 'requestState' => 'unissued' ), 4, Schemas::V2025_11_25 )['error']['code'] );
		$complete = $this->tool(
			static function (): array {
				return array( 'done' => true );
			}
		);
		$result   = $this->call( $this->makeServer( array( $complete ) ), array(), 5, Schemas::V2025_11_25 )['result'];
		$this->assertArrayNotHasKey( 'resultType', $result );
		$this->assertSame( array( 'done' => true ), $result['structuredContent'] );
	}

	/** Only elicitation input requests are supported; sampling and roots are rejected. */
	public function test_non_elicitation_requests_are_rejected(): void {
		foreach ( array( 'roots/list', 'sampling/createMessage' ) as $method ) {
			$request = array( 'method' => $method );
			if ( 'sampling/createMessage' === $method ) {
				$request['params'] = array(
					'maxTokens' => 10,
					'messages'  => array(
						array(
							'role'    => 'user',
							'content' => array(
								'type' => 'text',
								'text' => 'Sample.',
							),
						),
					),
				);
			}
			$tool     = $this->tool(
				static function () use ( $request ) {
					return new McpInputRequired( array( 'q' => $request ) );
				}
			);
			$caps     = array(
				'roots'    => new \stdClass(),
				'sampling' => new \stdClass(),
			);
			$response = $this->call( $this->makeServer( array( $tool ) ), array( '_meta' => $this->meta( $caps ) ), 1 );
			$this->assertSame( -32603, $response['error']['code'], $method );
		}
	}

	/** Build a direct callable that normally asks one question. */
	private function tool( ?callable $handler = null ): McpTool {
		$tool = McpTool::fromArray(
			array(
				'name'       => 'choose',
				'permission' => '__return_true',
				'handler'    => $handler ?? static function () {
					return new McpInputRequired( array( 'choice' => self::question() ) ); },
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		return $tool;
	}

	/** Supply capabilities for exactly one modern request. */
	private function meta( array $capabilities ): array {
		return array(
			'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
			'io.modelcontextprotocol/clientCapabilities' => (object) $capabilities,
		);
	}

	/** Gathering input never bypasses the ordinary Ability's domain contract. */
	public function test_callable_wrapper_preserves_ability_validation_and_permissions(): void {
		$executions = 0;
		$allowed    = true;
		$bad_output = false;
		$this->register_ability_in_hook(
			'test/mrtr-strict',
			array(
				'label'               => 'Strict MRTR target',
				'description'         => 'Validate final domain inputs.',
				'category'            => 'test',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'choice' => array(
							'type' => 'string',
							'enum' => array( 'yes' ),
						),
					),
					'required'             => array( 'choice' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array( 'value' => array( 'type' => 'string' ) ),
					'required'             => array( 'value' ),
					'additionalProperties' => false,
				),
				'permission_callback' => static function ( array $input ) use ( &$allowed ): bool {
					return $allowed && 'yes' === $input['choice']; },
				'execute_callback'    => static function ( array $input ) use ( &$executions, &$bad_output ): array {
					++$executions;
					return $bad_output ? array() : array( 'value' => $input['choice'] );
				},
			)
		);
		$tool   = $this->tool(
			static function ( array $args, McpToolCallContext $context ) {
				$responses = $context->input_responses();
				if ( ! isset( $responses->choice ) ) {
					return new McpInputRequired( array( 'choice' => self::question() ) );
				}
				return wp_get_ability( 'test/mrtr-strict' )->execute( array( 'choice' => $responses->choice->content->choice ) );
			}
		);
		$server = $this->makeServer( array( $tool ) );
		$this->call( $server, array(), 1 );
		$this->assertSame( 0, $executions );
		$retry   = array(
			'inputResponses' => array( 'choice' => self::answer() ),
		);
		$invalid = $retry;
		$invalid['inputResponses']['choice']['content']['choice'] = 'no';
		$result = $this->call( $server, $invalid, 2 )['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'invalid input', $result['content'][0]['text'] );
		$this->assertSame( 0, $executions );
		$result = $this->call( $server, $retry, 3 )['result'];
		$this->assertSame( array( 'value' => 'yes' ), $result['structuredContent'] );
		$this->assertSame( 1, $executions );
		$allowed = false;
		$this->assertTrue( $this->call( $server, $retry, 4 )['result']['isError'] );
		$this->assertSame( 1, $executions );
		$allowed    = true; // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the permission callback through its reference capture.
		$bad_output = true; // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the execution callback through its reference capture.
		$result     = $this->call( $server, $retry, 5 )['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'invalid output', $result['content'][0]['text'] );
	}

	/** Workflow permission is checked again before exposing or consuming answers. */
	public function test_workflow_permission_is_rechecked_on_retry(): void {
		$allowed    = true;
		$executions = 0;
		$tool       = McpTool::fromArray(
			array(
				'name'       => 'choose',
				'permission' => static function () use ( &$allowed ): bool {
					return $allowed; },
				'handler'    => static function () use ( &$executions ) {
					++$executions;
					return new McpInputRequired( array( 'choice' => self::question() ) ); },
			)
		);
		$server     = $this->makeServer( array( $tool ) );
		$this->call( $server, array(), 1 );
		$allowed = false; // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the permission callback through its reference capture.
		$result  = $this->call(
			$server,
			array(
				'inputResponses' => array( 'choice' => self::answer() ),
			),
			2
		)['result'];
		$this->assertTrue( $result['isError'] );
		$this->assertSame( 1, $executions );
	}

	/** Fractional form answers survive both protocol and requested-schema validation. */
	public function test_fractional_elicitation_answer_is_preserved(): void {
		$tool     = $this->tool(
			static function ( array $args, McpToolCallContext $context ) {
				$responses = $context->input_responses();
				if ( isset( $responses->quantity ) ) {
					return array( 'quantity' => $responses->quantity->content->quantity );
				}
				$question                              = self::question();
				$question['params']['requestedSchema'] = array(
					'type'       => 'object',
					'properties' => array(
						'quantity' => array(
							'type'    => 'number',
							'minimum' => 0,
						),
					),
					'required'   => array( 'quantity' ),
				);
				return new McpInputRequired( array( 'quantity' => $question ) );
			}
		);
		$server   = $this->makeServer( array( $tool ) );
		$response = $this->call(
			$server,
			array(
				'inputResponses' => array(
					'quantity' => array(
						'action'  => 'accept',
						'content' => array( 'quantity' => 1.5 ),
					),
				),
			),
			2
		);
		$this->assertArrayHasKey( 'result', $response, (string) wp_json_encode( $response ) );
		$this->assertSame( 1.5, $response['result']['structuredContent']['quantity'] );
	}

	/** Unimplemented MRTR methods reject continuation fields instead of executing. */
	public function test_continuation_parameters_are_rejected_outside_supported_tool_calls(): void {
		$server = $this->makeServer();
		foreach ( array( Schemas::V2025_11_25, Schemas::V2026_07_28 ) as $revision ) {
			foreach ( array( 'tools/list', 'prompts/get', 'resources/read' ) as $method ) {
				$response = $this->call(
					$server,
					array(
						'uri'          => 'file:///example',
						'requestState' => 'unissued',
					),
					1,
					$revision,
					$method
				);
				$this->assertArrayHasKey( 'error', $response );
				$this->assertSame( -32602, $response['error']['code'] );
			}
		}
	}

	/** One small form with an independently specified allowed answer. */
	private static function question(): array {
		return array(
			'method' => 'elicitation/create',
			'params' => array(
				'mode'            => 'form',
				'message'         => 'Choose an answer.',
				'requestedSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'choice' => array(
							'type' => 'string',
							'enum' => array( 'yes', 'no' ),
						),
					),
					'required'   => array( 'choice' ),
				),
			),
		);
	}

	/** A valid answer to question(). */
	private static function answer(): array {
		return array(
			'action'  => 'accept',
			'content' => array( 'choice' => 'yes' ),
		);
	}

	/** Send one raw message through a newly constructed orchestrator. */
	private function call( McpServer $server, array $extra, int $id, string $revision = Schemas::V2026_07_28, string $method = 'tools/call' ): array {
		$params = array_merge(
			array(
				'name'      => 'choose',
				'arguments' => new \stdClass(),
				'_meta'     => array(
					'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
					'io.modelcontextprotocol/clientCapabilities' => array( 'elicitation' => array( 'form' => new \stdClass() ) ),
				),
			),
			$extra
		);
		if ( Schemas::V2025_11_25 === $revision ) {
			unset( $params['_meta'] );
		}
		$wire     = new McpWireOrchestrator( $server->create_transport_context() );
		$message  = $wire->decode(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);
		$response = $wire->process( $message, 'STDIO', array(), Schemas::V2025_11_25 === $revision ? array( 'capabilities' => new \stdClass() ) : null )['response'];
		return json_decode( (string) wp_json_encode( $response ), true );
	}
}
