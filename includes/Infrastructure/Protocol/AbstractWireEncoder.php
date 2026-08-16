<?php
/**
 * Shared descriptor-backed MCP wire encoding mechanics.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Protocol;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Contract\Type;

/**
 * Holds the behavior shared by the concrete revision encoders.
 *
 * Concrete encoders remain responsible for narrowing the revision catalog and
 * naming every emitted protocol type through a generated accessor.
 *
 * @since n.e.x.t
 */
abstract class AbstractWireEncoder implements WireEncoderInterface {

	/** @var \WP\MCP\Core\McpProtocolContext */
	protected McpProtocolContext $context;

	/** @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface */
	private McpErrorHandlerInterface $error_handler;

	/**
	 * @param \WP\MCP\Core\McpProtocolContext $context Protocol context.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler Encode-failure reporter.
	 */
	protected function __construct( McpProtocolContext $context, McpErrorHandlerInterface $error_handler ) {
		$this->context       = $context;
		$this->error_handler = $error_handler;
	}

	/**
	 * @inheritDoc
	 */
	public function revision(): string {
		return $this->context->revision();
	}

	/**
	 * Hydrate a payload through an exact generated type and return JSON-ready data.
	 *
	 * @template TWire of array<string, mixed>
	 * @template TFields of array<string, mixed>
	 *
	 * @param \WP\McpSchema\Contract\Type<TWire, TFields> $type Exact protocol type.
	 * @param array<string, mixed> $data Revision-neutral payload.
	 *
	 * @return array<string, mixed>
	 */
	protected function encode( Type $type, array $data ): array {
		return $type->fromValue( $data )->toJsonArray();
	}

	/**
	 * Encode one list component, omitting only the invalid component.
	 *
	 * @template TWire of array<string, mixed>
	 * @template TFields of array<string, mixed>
	 *
	 * @param \WP\McpSchema\Contract\Type<TWire, TFields> $type Exact component type.
	 * @param array<string, mixed> $data Component payload.
	 * @param string               $type_name Protocol type name.
	 * @param string               $subject Component identity.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function try_encode( Type $type, array $data, string $type_name, string $subject ): ?array {
		try {
			return $this->encode( $type, $data );
		} catch ( \WP\McpSchema\Runtime\ValidationException $exception ) {
			$this->report_failure( $type_name, $subject, $exception );

			return null;
		}
	}

	/**
	 * Report one component omitted after schema validation.
	 */
	private function report_failure( string $type_name, string $subject, \WP\McpSchema\Runtime\ValidationException $exception ): void {
		$label = '' === $subject ? $type_name : $type_name . ' "' . $subject . '"';

		$this->error_handler->log(
			'MCP component omitted from the response because it does not match the protocol schema.',
			array(
				'type'     => $type_name,
				'subject'  => $subject,
				'revision' => $this->context->revision(),
				'error'    => $exception->getMessage(),
			)
		);

		_doing_it_wrong(
			static::class . '::report_failure',
			sprintf(
				/* translators: 1: protocol type and component name, 2: validation error message. */
				esc_html__( '%1$s was left out of the MCP response because it does not match the protocol schema: %2$s', 'mcp-adapter' ),
				esc_html( $label ),
				esc_html( $exception->getMessage() )
			),
			'n.e.x.t'
		);
	}
}
