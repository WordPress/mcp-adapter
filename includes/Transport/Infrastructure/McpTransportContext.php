<?php
/**
 * Transport context object for dependency injection.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

/**
 * Transport context object for dependency injection.
 *
 * Contains all dependencies needed by transport implementations,
 * promoting loose coupling and easier testing.
 *
 * Note: The request_router parameter is optional. If not provided,
 * a RequestRouter instance will be automatically created with this
 * context as its dependency.
 */
class McpTransportContext {

	/**
	 * Required property keys for the constructor array.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const REQUIRED_KEYS = array(
		'mcp_server',
		'initialize_handler',
		'tools_handler',
		'resources_handler',
		'prompts_handler',
		'system_handler',
		'observability_handler',
	);

	/**
	 * Optional property keys for the constructor array.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const OPTIONAL_KEYS = array(
		'request_router',
		'transport_permission_callback',
		'error_handler',
	);

	/**
	 * The MCP server instance.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	public McpServer $mcp_server;

	/**
	 * The initialize handler.
	 *
	 * @var \WP\MCP\Handlers\Initialize\InitializeHandler
	 */
	public InitializeHandler $initialize_handler;

	/**
	 * The tools handler.
	 *
	 * @var \WP\MCP\Handlers\Tools\ToolsHandler
	 */
	public ToolsHandler $tools_handler;

	/**
	 * The resources handler.
	 *
	 * @var \WP\MCP\Handlers\Resources\ResourcesHandler
	 */
	public ResourcesHandler $resources_handler;

	/**
	 * The prompts handler.
	 *
	 * @var \WP\MCP\Handlers\Prompts\PromptsHandler
	 */
	public PromptsHandler $prompts_handler;

	/**
	 * The system handler.
	 *
	 * @var \WP\MCP\Handlers\System\SystemHandler
	 */
	public SystemHandler $system_handler;

	/**
	 * The observability handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface
	 */
	public McpObservabilityHandlerInterface $observability_handler;

	/**
	 * The error handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 */
	public McpErrorHandlerInterface $error_handler;

	/**
	 * The request router service.
	 */
	public RequestRouter $request_router;

	/**
	 * Optional custom permission callback for transport-level authentication.
	 *
	 * @var callable|callable-string|null
	 */
	public $transport_permission_callback;

	/**
	 * Initialize the transport context.
	 *
	 * @param array{
	 *   mcp_server: \WP\MCP\Core\McpServer,
	 *   initialize_handler: \WP\MCP\Handlers\Initialize\InitializeHandler,
	 *   tools_handler: \WP\MCP\Handlers\Tools\ToolsHandler,
	 *   resources_handler: \WP\MCP\Handlers\Resources\ResourcesHandler,
	 *   prompts_handler: \WP\MCP\Handlers\Prompts\PromptsHandler,
	 *   system_handler: \WP\MCP\Handlers\System\SystemHandler,
	 *   observability_handler: \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface,
	 *   request_router?: \WP\MCP\Transport\Infrastructure\RequestRouter,
	 *   transport_permission_callback?: callable|null,
	 *   error_handler?: \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 * } $properties Properties to set on the context.
	 * Note: request_router is optional and will be auto-created if not provided.
	 *
	 * @throws \InvalidArgumentException If required keys are missing or unknown keys are present.
	 *
	 * @since n.e.x.t
	 */
	public function __construct( array $properties ) {
		$this->validate_properties( $properties );

		// Assign required properties.
		$this->mcp_server            = $properties['mcp_server'];
		$this->initialize_handler    = $properties['initialize_handler'];
		$this->tools_handler         = $properties['tools_handler'];
		$this->resources_handler     = $properties['resources_handler'];
		$this->prompts_handler       = $properties['prompts_handler'];
		$this->system_handler        = $properties['system_handler'];
		$this->observability_handler = $properties['observability_handler'];

		// Assign optional properties (error_handler defaults to the server's handler).
		$this->error_handler = $properties['error_handler'] ?? $properties['mcp_server']->get_error_handler();

		$this->transport_permission_callback = $properties['transport_permission_callback'] ?? null;

		// Create request_router if not provided.
		$this->request_router = $properties['request_router'] ?? new RequestRouter( $this );
	}

	/**
	 * Validate that the properties array contains all required keys and no unknown keys.
	 *
	 * @param array<string, mixed> $properties Properties to validate.
	 *
	 * @throws \InvalidArgumentException If required keys are missing or unknown keys are present.
	 *
	 * @since n.e.x.t
	 */
	private function validate_properties( array $properties ): void {
		$provided_keys = array_keys( $properties );
		$allowed_keys  = array_merge( self::REQUIRED_KEYS, self::OPTIONAL_KEYS );

		// Check for unknown keys.
		$unknown_keys = array_diff( $provided_keys, $allowed_keys );
		if ( ! empty( $unknown_keys ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Unknown properties provided to McpTransportContext: %1$s. Allowed properties: %2$s.',
					esc_html( implode( ', ', $unknown_keys ) ),
					esc_html( implode( ', ', $allowed_keys ) )
				)
			);
		}

		// Check for missing required keys.
		$missing_keys = array_diff( self::REQUIRED_KEYS, $provided_keys );
		if ( ! empty( $missing_keys ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Missing required properties for McpTransportContext: %s.',
					esc_html( implode( ', ', $missing_keys ) )
				)
			);
		}
	}
}
