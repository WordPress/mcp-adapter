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

		// Resource ability with URI in meta
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
					'uri'         => 'WordPress://local/resource-1',
					'annotations' => array( 'group' => 'tests' ),
					'mcp'         => array(
						'public' => true, // Expose via MCP for testing
						'type'   => 'resource', // Explicitly mark as resource
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
			'test/resource-whitespace-uri',
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
