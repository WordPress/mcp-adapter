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
