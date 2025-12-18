<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

final class DummyAbility {

	/**
	 * Registers the 'test' category for dummy abilities.
	 *
	 * MUST be called during the 'wp_abilities_api_categories_init' action.
	 * Does not check if category already exists - if it does, test isolation has failed.
	 *
	 * @return void
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			'test',
			array(
				'label'       => 'Test',
				'description' => 'Test abilities for unit tests',
			)
		);
	}

	/**
	 * Registers all dummy abilities for testing.
	 *
	 * Sets up action hooks to register category and abilities at the correct times:
	 * - Category registration during 'wp_abilities_api_categories_init'
	 * - Abilities registration during 'wp_abilities_api_init'
	 *
	 * Then fires the hooks if they haven't been fired yet.
	 * Does not check if abilities already exist - if they do, test isolation has failed.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		// Hook category registration to the proper action
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );

		// Fire categories init hook if not already fired
		if ( ! did_action( 'wp_abilities_api_categories_init' ) ) {
			do_action( 'wp_abilities_api_categories_init' );
		}

		// Hook abilities registration to the proper action
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		// Fire abilities init hook if not already fired
		if ( did_action( 'wp_abilities_api_init' ) ) {
			return;
		}

		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Registers all the dummy abilities.
	 *
	 * This method should be called during the 'wp_abilities_api_init' action.
	 *
	 * @return void
	 */
	public static function register_abilities(): void {

		// AlwaysAllowed: returns text array
		wp_register_ability(
			'test/always-allowed',
			array(
				'label'               => 'Always Allowed',
				'description'         => 'Returns a simple payload',
				'category'            => 'test',
				'output_schema'       => array(),
				'execute_callback'    => static function () {
					return array(
						'ok'   => true,
						'echo' => array(),
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'annotations' => array( 'group' => 'tests' ),
					'mcp'         => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// PermissionDenied: has_permission false
		wp_register_ability(
			'test/permission-denied',
			array(
				'label'               => 'Permission Denied',
				'description'         => 'Permission denied ability',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array( 'should' => 'not run' );
				},
				'permission_callback' => static function () {
					return false;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Exception in permission
		wp_register_ability(
			'test/permission-exception',
			array(
				'label'               => 'Permission Exception',
				'description'         => 'Throws in permission',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'never' => 'executed' );
				},
				'permission_callback' => static function ( array $input ) {
					throw new \RuntimeException( 'nope' );
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Exception in execute
		wp_register_ability(
			'test/execute-exception',
			array(
				'label'               => 'Execute Exception',
				'description'         => 'Throws in execute',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					throw new \RuntimeException( 'boom' );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Image ability: returns image payload
		wp_register_ability(
			'test/image',
			array(
				'label'               => 'Image Tool',
				'description'         => 'Returns image bytes',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array(
						'type'     => 'image',
						'results'  => "\x89PNG\r\n",
						'mimeType' => 'image/png',
					);
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Tool ability: returns an EmbeddedResource-style payload (text).
		wp_register_ability(
			'test/embedded-text-resource',
			array(
				'label'               => 'Embedded Text Resource Tool',
				'description'         => 'Returns an embedded text resource payload',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array(
						'type'     => 'resource',
						'uri'      => 'WordPress://local/tool-embedded-text',
						'text'     => 'hello from embedded resource',
						'mimeType' => 'text/plain',
					);
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Tool ability: returns an EmbeddedResource-style payload (blob).
		wp_register_ability(
			'test/embedded-blob-resource',
			array(
				'label'               => 'Embedded Blob Resource Tool',
				'description'         => 'Returns an embedded blob resource payload',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array(
						'type'     => 'resource',
						'uri'      => 'WordPress://local/tool-embedded-blob',
						'blob'     => base64_encode( 'blob-bytes' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'mimeType' => 'application/octet-stream',
					);
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Tool ability: returns nested internal _meta to verify it is never exposed to clients.
		wp_register_ability(
			'test/meta-leak',
			array(
				'label'               => 'Meta Leak Tool',
				'description'         => 'Returns a payload containing internal adapter metadata for redaction tests',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array(
						'ok'     => true,
						'_meta'  => array(
							'mcp_adapter' => array(
								'should_not' => 'leak',
							),
							'keep'        => 'top',
						),
						'nested' => array(
							'value' => 123,
							'_meta' => array(
								'mcp_adapter' => array(
									'should_not' => 'leak',
								),
								'keep'        => 'nested',
							),
						),
					);
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		// Resource ability with URI in meta (using standardized mcp.* structure)
		wp_register_ability(
			'test/resource',
			array(
				'label'               => 'Resource',
				'description'         => 'A text resource',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
						'type'   => 'resource', // Explicitly mark as resource
						'uri'    => 'WordPress://local/resource-1',
					),
				),
			)
		);

		// Resource ability with extra whitespace around URI for normalization tests
		wp_register_ability(
			'test/resource-whitespace-uri',
			array(
				'label'               => 'Resource With Whitespace URI',
				'description'         => 'Resource whose URI includes leading/trailing spaces',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'uri' => '  WordPress://local/resource-whitespace  ',
				),
			)
		);

		// Prompt ability with arguments
		wp_register_ability(
			'test/prompt',
			array(
				'label'               => 'Prompt',
				'description'         => 'A sample prompt',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'Code to review',
						),
					),
					'required'   => array( 'code' ),
				),
				'execute_callback'    => static function ( array $input ) {
					return array(
						'messages' => array(
							array(
								'role'    => 'assistant',
								'content' => array(
									'type' => 'text',
									'text' => 'hi',
								),
							),
						),
					);
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
						'type'   => 'prompt', // Explicitly mark as prompt
					),
				),
			)
		);

		// Test abilities for annotation mapping tests
		wp_register_ability(
			'test/annotated-ability',
			array(
				'label'               => 'Annotated Ability',
				'description'         => 'Test ability with annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'input_schema'        => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		wp_register_ability(
			'test/null-annotations',
			array(
				'label'               => 'Null Annotations',
				'description'         => 'Test ability with null annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'input_schema'        => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => null,
						'destructive' => null,
						'idempotent'  => false,
					),
				),
			)
		);

		wp_register_ability(
			'test/with-instructions',
			array(
				'label'               => 'With Instructions',
				'description'         => 'Test ability with instructions',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'input_schema'        => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations' => array(
						'instructions' => 'These are instructions',
						'readonly'     => true,
					),
				),
			)
		);

		wp_register_ability(
			'test/mcp-native',
			array(
				'label'               => 'MCP Native',
				'description'         => 'Test ability with MCP-native annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'input_schema'        => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations' => array(
						'openWorldHint' => true,
						'title'         => 'Custom Annotation Title',
						'readonly'      => false,
					),
				),
			)
		);

		wp_register_ability(
			'test/no-annotations',
			array(
				'label'               => 'No Annotations',
				'description'         => 'Test ability without annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'input_schema'        => array( 'type' => 'object' ),
			)
		);

		wp_register_ability(
			'test/all-null-annotations',
			array(
				'label'               => 'All Null Annotations',
				'description'         => 'Test ability with all null annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'input_schema'        => array( 'type' => 'object' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => null,
						'destructive' => null,
						'idempotent'  => null,
					),
				),
			)
		);

		// Resource with annotations
		wp_register_ability(
			'test/resource-with-annotations',
			array(
				'label'               => 'Resource With Annotations',
				'description'         => 'A resource with MCP annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'uri'         => 'WordPress://local/resource-annotated',
					'annotations' => array(
						'audience'     => array( 'user', 'assistant' ),
						'lastModified' => '2024-01-15T10:30:00Z',
						'priority'     => 0.8,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		// Resource with partial annotations
		wp_register_ability(
			'test/resource-partial-annotations',
			array(
				'label'               => 'Resource Partial Annotations',
				'description'         => 'A resource with only some annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'uri'         => 'WordPress://local/resource-partial',
					'annotations' => array(
						'priority' => 0.5,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		// Resource with invalid annotations (should be filtered)
		wp_register_ability(
			'test/resource-invalid-annotations',
			array(
				'label'               => 'Resource Invalid Annotations',
				'description'         => 'A resource with invalid annotations',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'uri'         => 'WordPress://local/resource-invalid',
					'annotations' => array(
						'audience'     => array( 'invalid-role' ), // Invalid role
						'lastModified' => 'not-a-date',            // Invalid date
						'priority'     => 2.0,                      // Out of range
						'invalidField' => 'should-be-filtered',    // Unknown field
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		// Prompt with annotations
		wp_register_ability(
			'test/prompt-with-annotations',
			array(
				'label'               => 'Prompt With Annotations',
				'description'         => 'A prompt with MCP annotations',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'Code to review',
						),
					),
				),
				'execute_callback'    => static function ( array $input ) {
					return array(
						'messages' => array(
							array(
								'role'    => 'assistant',
								'content' => array(
									'type' => 'text',
									'text' => 'hi',
								),
							),
						),
					);
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'annotations' => array(
						'audience'     => array( 'user' ),
						'lastModified' => '2024-01-15T10:30:00Z',
						'priority'     => 0.9,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with partial annotations
		wp_register_ability(
			'test/prompt-partial-annotations',
			array(
				'label'               => 'Prompt Partial Annotations',
				'description'         => 'A prompt with only some annotations',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'annotations' => array(
						'audience' => array( 'assistant' ),
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with invalid annotations (should be filtered)
		wp_register_ability(
			'test/prompt-invalid-annotations',
			array(
				'label'               => 'Prompt Invalid Annotations',
				'description'         => 'A prompt with invalid annotations',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'annotations' => array(
						'audience'     => array( 'user', 'invalid-role' ), // Mixed valid and invalid roles
						'lastModified' => 'not-a-date',                      // Invalid date
						'priority'     => -1.0,                              // Out of range
						'invalidField' => 'should-be-filtered',              // Unknown field
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with flattened string schema (should wrap as single 'input' argument).
		wp_register_ability(
			'test/prompt-flattened-string',
			array(
				'label'               => 'Prompt Flattened String',
				'description'         => 'A prompt with flattened string schema',
				'category'            => 'test',
				'input_schema'        => array(
					'type'        => 'string',
					'description' => 'The code to review',
				),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with flattened array schema (should wrap as single 'input' argument).
		wp_register_ability(
			'test/prompt-flattened-array',
			array(
				'label'               => 'Prompt Flattened Array',
				'description'         => 'A prompt with flattened array schema',
				'category'            => 'test',
				'input_schema'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'List of items to process',
				),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with property titles (should map title to PromptArgument.title).
		wp_register_ability(
			'test/prompt-with-titles',
			array(
				'label'               => 'Prompt With Titles',
				'description'         => 'A prompt with JSON Schema property titles',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'code'     => array(
							'type'        => 'string',
							'title'       => 'Source Code',
							'description' => 'The code to review',
						),
						'language' => array(
							'type'        => 'string',
							'title'       => 'Programming Language',
							'description' => 'The programming language',
						),
					),
					'required'   => array( 'code' ),
				),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with mixed required/optional arguments.
		wp_register_ability(
			'test/prompt-mixed-required',
			array(
				'label'               => 'Prompt Mixed Required',
				'description'         => 'A prompt with mixed required and optional arguments',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'topic'  => array(
							'type'        => 'string',
							'description' => 'The topic (required)',
						),
						'tone'   => array(
							'type'        => 'string',
							'description' => 'The tone (optional)',
						),
						'length' => array(
							'type'        => 'integer',
							'description' => 'The length (optional)',
						),
					),
					'required'   => array( 'topic' ),
				),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with empty object schema (no properties).
		wp_register_ability(
			'test/prompt-empty-object',
			array(
				'label'               => 'Prompt Empty Object',
				'description'         => 'A prompt with empty object schema',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Prompt with no input_schema.
		wp_register_ability(
			'test/prompt-no-schema',
			array(
				'label'               => 'Prompt No Schema',
				'description'         => 'A prompt with no input schema',
				'category'            => 'test',
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		// Tool with valid icons (MCP 2025-11-25)
		wp_register_ability(
			'test/with-icons',
			array(
				'label'               => 'Tool With Icons',
				'description'         => 'A tool with MCP icons',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'icons'  => array(
							array(
								'src'      => 'https://example.com/icon.png',
								'mimeType' => 'image/png',
								'sizes'    => array( '32x32' ), // sizes is an array per MCP spec.
								'theme'    => 'light',
							),
							array(
								'src'      => 'https://example.com/icon-dark.svg',
								'mimeType' => 'image/svg+xml',
								'theme'    => 'dark',
							),
						),
					),
				),
			)
		);

		// Tool with some invalid icons (should filter out invalid, keep valid)
		wp_register_ability(
			'test/with-mixed-icons',
			array(
				'label'               => 'Tool With Mixed Icons',
				'description'         => 'A tool with some valid and some invalid icons',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'icons'  => array(
							array(
								'src'      => 'https://example.com/valid-icon.png',
								'mimeType' => 'image/png',
							),
							array(
								// Missing src - invalid
								'mimeType' => 'image/png',
							),
							array(
								'src'      => 'https://example.com/another-valid.svg',
								'mimeType' => 'image/svg+xml',
							),
						),
					),
				),
			)
		);

		// Tool with custom _meta passthrough
		wp_register_ability(
			'test/with-custom-meta',
			array(
				'label'               => 'Tool With Custom Meta',
				'description'         => 'A tool with custom _meta passed through',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'_meta'  => array(
							'custom_vendor'  => array(
								'feature_flag' => true,
								'version'      => '1.0',
							),
							'another_vendor' => 'some-value',
						),
					),
				),
			)
		);

		// Tool with both icons and custom _meta
		wp_register_ability(
			'test/with-icons-and-meta',
			array(
				'label'               => 'Tool With Icons And Meta',
				'description'         => 'A tool with both icons and custom _meta',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'icons'  => array(
							array(
								'src'      => 'https://example.com/combined-icon.png',
								'mimeType' => 'image/png',
								'sizes'    => array( '48x48' ), // sizes is an array per MCP spec.
							),
						),
						'_meta'  => array(
							'vendor_info' => array(
								'custom_data' => 'test-value',
							),
						),
					),
				),
			)
		);

		// Resource using standardized mcp.* meta structure (new pattern)
		wp_register_ability(
			'test/resource-new-meta',
			array(
				'label'               => 'Resource New Meta',
				'description'         => 'A resource using standardized mcp.* meta structure',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'      => true,
						'type'        => 'resource',
						'uri'         => 'WordPress://local/resource-new-meta',
						'mimeType'    => 'text/plain',
						'size'        => 1024,
						'annotations' => array(
							'audience'     => array( 'user' ),
							'priority'     => 0.7,
							'lastModified' => '2025-01-15T10:30:00Z',
						),
						'icons'       => array(
							array(
								'src'      => 'https://example.com/resource-icon.png',
								'mimeType' => 'image/png',
								'sizes'    => array( '32x32' ),
							),
						),
						'_meta'       => array(
							'custom_field' => 'custom_value',
						),
					),
				),
			)
		);

		// Resource with invalid URI (no scheme) - should return WP_Error
		wp_register_ability(
			'test/resource-invalid-uri',
			array(
				'label'               => 'Resource Invalid URI',
				'description'         => 'A resource with invalid URI for testing validation',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'no-scheme-invalid-uri',
					),
				),
			)
		);

		// Resource with invalid mimeType - should silently skip mimeType
		wp_register_ability(
			'test/resource-invalid-mimetype',
			array(
				'label'               => 'Resource Invalid MimeType',
				'description'         => 'A resource with invalid mimeType for testing validation',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'   => true,
						'type'     => 'resource',
						'uri'      => 'WordPress://local/resource-invalid-mimetype',
						'mimeType' => 'not//valid',  // Invalid mime type.
					),
				),
			)
		);

		// Resource with size field
		wp_register_ability(
			'test/resource-with-size',
			array(
				'label'               => 'Resource With Size',
				'description'         => 'A resource with size field',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-with-size',
						'size'   => 2048,
					),
				),
			)
		);

		// Resource with INVALID annotations in new meta structure (for validation testing)
		// All annotations should be dropped with _doing_it_wrong notice
		wp_register_ability(
			'test/resource-invalid-annotations-new-meta',
			array(
				'label'               => 'Resource Invalid Annotations New Meta',
				'description'         => 'A resource with invalid annotations using new meta structure',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'      => true,
						'type'        => 'resource',
						'uri'         => 'WordPress://local/resource-invalid-annotations-new',
						'annotations' => array(
							'audience'     => array( 'admin', 'superuser' ), // Invalid roles (should be 'user' or 'assistant')
							'lastModified' => 'yesterday',                   // Invalid ISO 8601 timestamp
							'priority'     => 2.5,                           // Out of range (should be 0.0-1.0)
						),
					),
				),
			)
		);

		// Resource with MIXED valid/invalid annotations - should drop ALL because one is invalid
		wp_register_ability(
			'test/resource-mixed-annotations',
			array(
				'label'               => 'Resource Mixed Annotations',
				'description'         => 'A resource with one valid and one invalid annotation',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'      => true,
						'type'        => 'resource',
						'uri'         => 'WordPress://local/resource-mixed-annotations',
						'annotations' => array(
							'priority'     => 0.5,                           // Valid
							'lastModified' => 'not-valid-timestamp',         // Invalid - should cause ALL to be dropped
						),
					),
				),
			)
		);

		// Resource with icons (using new meta structure)
		wp_register_ability(
			'test/resource-with-icons',
			array(
				'label'               => 'Resource With Icons',
				'description'         => 'A resource with icons using new meta structure',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-with-icons',
						'icons'  => array(
							array(
								'src'      => 'https://example.com/resource-icon.svg',
								'mimeType' => 'image/svg+xml',
								'sizes'    => array( 'any' ),
								'theme'    => 'light',
							),
						),
					),
				),
			)
		);

		// Resource with missing URI in meta (should fail conversion).
		wp_register_ability(
			'test/resource-missing-uri',
			array(
				'label'               => 'Resource Missing URI',
				'description'         => 'A resource with no URI defined',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						// No uri key - should fail
					),
				),
			)
		);

		// Resource with valid mimeType (for acceptance testing).
		wp_register_ability(
			'test/resource-valid-mimetype',
			array(
				'label'               => 'Resource Valid MimeType',
				'description'         => 'A resource with valid mimeType',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'   => true,
						'type'     => 'resource',
						'uri'      => 'WordPress://local/resource-valid-mimetype',
						'mimeType' => 'application/json',
					),
				),
			)
		);

		// Resource that returns blob content (for BlobResourceContents testing).
		wp_register_ability(
			'test/resource-blob-content',
			array(
				'label'               => 'Resource Blob Content',
				'description'         => 'A resource returning blob data',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array(
						array(
							'uri'      => 'WordPress://local/resource-blob',
							'blob'     => base64_encode( 'binary-data-here' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
							'mimeType' => 'application/octet-stream',
						),
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-blob-content',
					),
				),
			)
		);

		// Resource that returns multiple content items.
		wp_register_ability(
			'test/resource-multiple-contents',
			array(
				'label'               => 'Resource Multiple Contents',
				'description'         => 'A resource returning multiple content items',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array(
						array(
							'uri'      => 'WordPress://local/resource-multi/part1',
							'text'     => 'First content part',
							'mimeType' => 'text/plain',
						),
						array(
							'uri'      => 'WordPress://local/resource-multi/part2',
							'text'     => 'Second content part',
							'mimeType' => 'text/plain',
						),
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-multiple-contents',
					),
				),
			)
		);

		// Resource returning text with custom mimeType.
		wp_register_ability(
			'test/resource-text-with-mimetype',
			array(
				'label'               => 'Resource Text With MimeType',
				'description'         => 'A resource returning text with custom mimeType',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array(
						array(
							'uri'      => 'WordPress://local/resource-json',
							'text'     => '{"key": "value"}',
							'mimeType' => 'application/json',
						),
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-text-with-mimetype',
					),
				),
			)
		);

		// Resource returning plain string (non-array, for wrapping test).
		wp_register_ability(
			'test/resource-plain-string',
			array(
				'label'               => 'Resource Plain String',
				'description'         => 'A resource returning a plain string',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'plain string content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-plain-string',
					),
				),
			)
		);

		// =========================================================================
		// Prompt Icons and _meta Test Abilities (MCP 2025-11-25)
		// =========================================================================

		// Prompt with valid icons.
		wp_register_ability(
			'test/prompt-with-icons',
			array(
				'label'               => 'Prompt With Icons',
				'description'         => 'A prompt with MCP icons',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
						'icons'  => array(
							array(
								'src'      => 'https://example.com/prompt-icon.png',
								'mimeType' => 'image/png',
								'sizes'    => array( '32x32' ),
								'theme'    => 'light',
							),
							array(
								'src'      => 'https://example.com/prompt-icon-dark.svg',
								'mimeType' => 'image/svg+xml',
								'theme'    => 'dark',
							),
						),
					),
				),
			)
		);

		// Prompt with some invalid icons (should filter out invalid, keep valid).
		wp_register_ability(
			'test/prompt-with-mixed-icons',
			array(
				'label'               => 'Prompt With Mixed Icons',
				'description'         => 'A prompt with some valid and some invalid icons',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
						'icons'  => array(
							array(
								'src'      => 'https://example.com/valid-prompt-icon.png',
								'mimeType' => 'image/png',
							),
							array(
								// Missing src - invalid.
								'mimeType' => 'image/png',
							),
							array(
								'src'      => 'https://example.com/another-valid-prompt.svg',
								'mimeType' => 'image/svg+xml',
							),
						),
					),
				),
			)
		);

		// Prompt with custom _meta passthrough.
		wp_register_ability(
			'test/prompt-with-custom-meta',
			array(
				'label'               => 'Prompt With Custom Meta',
				'description'         => 'A prompt with custom _meta passed through',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
						'_meta'  => array(
							'custom_vendor'  => array(
								'feature_flag' => true,
								'version'      => '1.0',
							),
							'another_vendor' => 'some-value',
						),
					),
				),
			)
		);

		// Prompt with both icons and custom _meta.
		wp_register_ability(
			'test/prompt-with-icons-and-meta',
			array(
				'label'               => 'Prompt With Icons And Meta',
				'description'         => 'A prompt with both icons and custom _meta',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
						'icons'  => array(
							array(
								'src'      => 'https://example.com/combined-prompt-icon.png',
								'mimeType' => 'image/png',
								'sizes'    => array( '48x48' ),
							),
						),
						'_meta'  => array(
							'vendor_info' => array(
								'custom_data' => 'test-value',
							),
						),
					),
				),
			)
		);

		// =========================================================================
		// Explicit mcp.arguments Test Abilities
		// =========================================================================

		// Prompt with explicit mcp.arguments (no input_schema).
		wp_register_ability(
			'test/prompt-explicit-args',
			array(
				'label'               => 'Prompt Explicit Args',
				'description'         => 'A prompt with explicit mcp.arguments and no input_schema',
				'category'            => 'test',
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'    => true,
						'type'      => 'prompt',
						'arguments' => array(
							array(
								'name'        => 'code',
								'title'       => 'Source Code',
								'description' => 'The code to review',
								'required'    => true,
							),
							array(
								'name'        => 'language',
								'description' => 'Programming language (optional)',
							),
						),
					),
				),
			)
		);

		// Prompt with explicit mcp.arguments that OVERRIDE input_schema.
		wp_register_ability(
			'test/prompt-explicit-args-override',
			array(
				'label'               => 'Prompt Explicit Args Override',
				'description'         => 'A prompt where mcp.arguments override input_schema',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'schema_field' => array(
							'type'        => 'string',
							'description' => 'This should NOT appear in arguments',
						),
					),
					'required'   => array( 'schema_field' ),
				),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'    => true,
						'type'      => 'prompt',
						'arguments' => array(
							array(
								'name'        => 'explicit_field',
								'title'       => 'Explicit Field',
								'description' => 'This should appear instead of schema_field',
								'required'    => true,
							),
						),
					),
				),
			)
		);

		// Prompt with empty mcp.arguments array (should fall back to input_schema).
		wp_register_ability(
			'test/prompt-empty-explicit-args',
			array(
				'label'               => 'Prompt Empty Explicit Args',
				'description'         => 'A prompt with empty mcp.arguments, should use input_schema',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'fallback_field' => array(
							'type'        => 'string',
							'description' => 'This should appear because mcp.arguments is empty',
						),
					),
					'required'   => array( 'fallback_field' ),
				),
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'    => true,
						'type'      => 'prompt',
						'arguments' => array(), // Empty array - should fall back to input_schema.
					),
				),
			)
		);

		// Prompt with invalid mcp.arguments (missing name) - should return WP_Error.
		wp_register_ability(
			'test/prompt-invalid-explicit-args-no-name',
			array(
				'label'               => 'Prompt Invalid Args No Name',
				'description'         => 'A prompt with invalid mcp.arguments (missing name)',
				'category'            => 'test',
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'    => true,
						'type'      => 'prompt',
						'arguments' => array(
							array(
								// Missing 'name' field - should fail validation.
								'description' => 'This argument has no name',
								'required'    => true,
							),
						),
					),
				),
			)
		);

		// Prompt with invalid mcp.arguments (non-array argument) - should return WP_Error.
		wp_register_ability(
			'test/prompt-invalid-explicit-args-not-array',
			array(
				'label'               => 'Prompt Invalid Args Not Array',
				'description'         => 'A prompt with invalid mcp.arguments (argument is not an array)',
				'category'            => 'test',
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'    => true,
						'type'      => 'prompt',
						'arguments' => array(
							'not-an-array', // Invalid - should be an array.
						),
					),
				),
			)
		);

		// Prompt with explicit args containing all optional fields.
		wp_register_ability(
			'test/prompt-explicit-args-all-fields',
			array(
				'label'               => 'Prompt Explicit Args All Fields',
				'description'         => 'A prompt with explicit arguments including all optional fields',
				'category'            => 'test',
				'execute_callback'    => static function ( array $input ) {
					return array( 'messages' => array() );
				},
				'permission_callback' => static function ( array $input ) {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public'    => true,
						'type'      => 'prompt',
						'arguments' => array(
							array(
								'name'        => 'full_arg',
								'title'       => 'Full Argument',
								'description' => 'An argument with all fields populated',
								'required'    => true,
							),
							array(
								'name' => 'minimal_arg',
								// Only name - all optional fields omitted.
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Unregisters all dummy abilities and the test category.
	 *
	 * Also removes the action hooks to prevent duplicate registrations.
	 * Does not check if abilities/category exist - if they don't, test setup has failed.
	 *
	 * @return void
	 */
	public static function unregister_all(): void {
		// Remove action hooks to prevent re-registration
		remove_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		remove_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		// Unregister all abilities
		$names = array(
			'test/always-allowed',
			'test/permission-denied',
			'test/permission-exception',
			'test/execute-exception',
			'test/image',
			'test/resource',
			'test/prompt',
			'test/annotated-ability',
			'test/null-annotations',
			'test/with-instructions',
			'test/mcp-native',
			'test/no-annotations',
			'test/all-null-annotations',
			'test/resource-with-annotations',
			'test/resource-partial-annotations',
			'test/resource-invalid-annotations',
			'test/prompt-with-annotations',
			'test/prompt-partial-annotations',
			'test/prompt-invalid-annotations',
			'test/prompt-flattened-string',
			'test/prompt-flattened-array',
			'test/prompt-with-titles',
			'test/prompt-mixed-required',
			'test/prompt-empty-object',
			'test/prompt-no-schema',
			'test/resource-whitespace-uri',
			'test/embedded-text-resource',
			'test/embedded-blob-resource',
			'test/meta-leak',
			'test/with-icons',
			'test/with-mixed-icons',
			'test/with-custom-meta',
			'test/with-icons-and-meta',
			'test/resource-new-meta',
			'test/resource-invalid-uri',
			'test/resource-invalid-mimetype',
			'test/resource-with-size',
			'test/resource-invalid-annotations-new-meta',
			'test/resource-mixed-annotations',
			'test/resource-with-icons',
			'test/resource-missing-uri',
			'test/resource-valid-mimetype',
			'test/resource-blob-content',
			'test/resource-multiple-contents',
			'test/resource-text-with-mimetype',
			'test/resource-plain-string',
			'test/prompt-explicit-args',
			'test/prompt-explicit-args-override',
			'test/prompt-empty-explicit-args',
			'test/prompt-invalid-explicit-args-no-name',
			'test/prompt-invalid-explicit-args-not-array',
			'test/prompt-explicit-args-all-fields',
			'test/prompt-with-icons',
			'test/prompt-with-mixed-icons',
			'test/prompt-with-custom-meta',
			'test/prompt-with-icons-and-meta',
		);

		foreach ( $names as $name ) {
			wp_unregister_ability( $name );
		}

		// Clean up the test category
		wp_unregister_ability_category( 'test' );
	}

	/**
	 * Unregisters only the test category.
	 *
	 * Useful for cleanup when abilities were not registered but category was.
	 *
	 * @return void
	 */
	public static function unregister_category(): void {
		wp_unregister_ability_category( 'test' );
	}
}
