<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP_Ability;

final class DummyAbility
{
    public static function register_all(): void
    {
        // AlwaysAllowed: returns text array
        wp_register_ability(
            'test/always-allowed',
            array(
                'label' => 'Always Allowed',
                'description' => 'Returns a simple payload',
                'input_schema' => array('type' => 'object'),
                'output_schema' => array(),
                'execute_callback' => function (array $input) {
                    return array('ok' => true, 'echo' => $input);
                },
                'permission_callback' => function (array $input) {
                    return true;
                },
                'meta' => array(
                    'annotations' => array('group' => 'tests'),
                ),
            )
        );

        // PermissionDenied: has_permission false
        wp_register_ability(
            'test/permission-denied',
            array(
                'label' => 'Permission Denied',
                'description' => 'Permission denied ability',
                'input_schema' => array('type' => 'object'),
                'execute_callback' => function (array $input) {
                    return array('should' => 'not run');
                },
                'permission_callback' => function (array $input) {
                    return false;
                },
            )
        );

        // Exception in permission
        wp_register_ability(
            'test/permission-exception',
            array(
                'label' => 'Permission Exception',
                'description' => 'Throws in permission',
                'input_schema' => array('type' => 'object'),
                'execute_callback' => function (array $input) {
                    return array('never' => 'executed');
                },
                'permission_callback' => function (array $input) {
                    throw new \RuntimeException('nope');
                },
            )
        );

        // Exception in execute
        wp_register_ability(
            'test/execute-exception',
            array(
                'label' => 'Execute Exception',
                'description' => 'Throws in execute',
                'input_schema' => array('type' => 'object'),
                'execute_callback' => function (array $input) {
                    throw new \RuntimeException('boom');
                },
                'permission_callback' => function (array $input) {
                    return true;
                },
            )
        );

        // Image ability: returns image payload
        wp_register_ability(
            'test/image',
            array(
                'label' => 'Image Tool',
                'description' => 'Returns image bytes',
                'input_schema' => array('type' => 'object'),
                'execute_callback' => function (array $input) {
                    return array(
                        'type' => 'image',
                        'results' => "\x89PNG\r\n",
                        'mimeType' => 'image/png',
                    );
                },
                'permission_callback' => function (array $input) {
                    return true;
                },
            )
        );

        // Resource ability with URI in meta
        wp_register_ability(
            'test/resource',
            array(
                'label' => 'Resource',
                'description' => 'A text resource',
                'input_schema' => array('type' => 'object'),
                'execute_callback' => function (array $input) {
                    return 'content';
                },
                'permission_callback' => function (array $input) {
                    return true;
                },
                'meta' => array(
                    'uri' => 'WordPress://local/resource-1',
                    'annotations' => array('group' => 'tests'),
                ),
            )
        );

        // Prompt ability with arguments
        wp_register_ability(
            'test/prompt',
            array(
                'label' => 'Prompt',
                'description' => 'A sample prompt',
                'input_schema' => array('type' => 'object'),
                'execute_callback' => function (array $input) {
                    return array(
                        'messages' => array(
                            array(
                                'role' => 'assistant',
                                'content' => array('type' => 'text', 'text' => 'hi')
                            ),
                        ),
                    );
                },
                'permission_callback' => function (array $input) {
                    return true;
                },
                'meta' => array(
                    'arguments' => array(
                        array('name' => 'code', 'description' => 'Code to review', 'required' => true),
                    ),
                ),
            )
        );
    }

    public static function unregister_all(): void
    {
        $names = array(
            'test/always-allowed',
            'test/permission-denied',
            'test/permission-exception',
            'test/execute-exception',
            'test/image',
            'test/resource',
            'test/prompt',
        );

        // Ensure abilities API is initialized so the registry exists
        if ( ! did_action( 'abilities_api_init' ) ) {
            do_action( 'abilities_api_init' );
        }

        foreach ( $names as $name ) {
            if ( wp_get_ability( $name ) instanceof WP_Ability ) {
                wp_unregister_ability( $name );
            }
        }
    }
}


