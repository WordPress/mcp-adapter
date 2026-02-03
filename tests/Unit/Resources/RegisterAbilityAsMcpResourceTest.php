<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;

final class RegisterAbilityAsMcpResourceTest extends TestCase {

		public function test_make_builds_resource_from_ability(): void {
			$ability  = wp_get_ability( 'test/resource' );
			$this->assertNotNull( $ability, 'Ability test/resource should be registered' );
			$resource = RegisterAbilityAsMcpResource::make( $ability );
			$this->assertInstanceOf( ResourceDto::class, $resource );
			$arr = $resource->toArray();
			$this->assertSame( 'WordPress://local/resource-1', $arr['uri'] );
			$this->assertNull( $resource->get_meta() );
		}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/resource-with-annotations' );
		$this->assertNotNull( $ability, 'Ability test/resource-with-annotations should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// Verify MCP-format annotations.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'audience', $arr['annotations'] );
		$this->assertArrayHasKey( 'lastModified', $arr['annotations'] );
		$this->assertArrayHasKey( 'priority', $arr['annotations'] );

		// Verify values.
		$this->assertIsArray( $arr['annotations']['audience'] );
		$this->assertContains( 'user', $arr['annotations']['audience'] );
		$this->assertContains( 'assistant', $arr['annotations']['audience'] );
		$this->assertSame( '2024-01-15T10:30:00Z', $arr['annotations']['lastModified'] );
		$this->assertSame( 0.8, $arr['annotations']['priority'] );
	}

	public function test_partial_annotations_are_included(): void {
		$ability = wp_get_ability( 'test/resource-partial-annotations' );
		$this->assertNotNull( $ability, 'Ability test/resource-partial-annotations should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// Verify only provided annotations are present.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'priority', $arr['annotations'] );
		$this->assertSame( 0.5, $arr['annotations']['priority'] );
		$this->assertArrayNotHasKey( 'audience', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'lastModified', $arr['annotations'] );
	}

	public function test_empty_annotations_are_not_included(): void {
		$ability = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// Verify annotations field is not present when empty.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_get_uri_trims_whitespace_from_meta(): void {
		$ability = wp_get_ability( 'test/resource-whitespace-uri' );
		$this->assertNotNull( $ability, 'Ability test/resource-whitespace-uri should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();
		$this->assertSame( 'WordPress://local/resource-whitespace', $arr['uri'] );
	}

	public function test_new_meta_structure_maps_all_fields(): void {
		$ability = wp_get_ability( 'test/resource-new-meta' );
		$this->assertNotNull( $ability, 'Ability test/resource-new-meta should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// Verify core fields.
		$this->assertSame( 'test/resource-new-meta', $arr['name'] );
		$this->assertSame( 'WordPress://local/resource-new-meta', $arr['uri'] );
		$this->assertSame( 'Resource New Meta', $arr['title'] );
		$this->assertSame( 'A resource using standardized mcp.* meta structure', $arr['description'] );

		// Verify mimeType.
		$this->assertArrayHasKey( 'mimeType', $arr );
		$this->assertSame( 'text/plain', $arr['mimeType'] );

		// Verify size.
		$this->assertArrayHasKey( 'size', $arr );
		$this->assertSame( 1024, $arr['size'] );

		// Verify annotations.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertContains( 'user', $arr['annotations']['audience'] );
		$this->assertSame( 0.7, $arr['annotations']['priority'] );

		// Verify icons.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/resource-icon.png', $arr['icons'][0]['src'] );

		// Verify _meta passthrough.
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'custom_field', $arr['_meta'] );
		$this->assertSame( 'custom_value', $arr['_meta']['custom_field'] );
	}

	public function test_invalid_uri_returns_wp_error(): void {
		$ability = wp_get_ability( 'test/resource-invalid-uri' );
		$this->assertNotNull( $ability, 'Ability test/resource-invalid-uri should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );

		$this->assertWPError( $resource );
		$this->assertSame( 'resource_uri_invalid', $resource->get_error_code() );
	}

	public function test_invalid_mimetype_is_silently_skipped(): void {
		$ability = wp_get_ability( 'test/resource-invalid-mimetype' );
		$this->assertNotNull( $ability, 'Ability test/resource-invalid-mimetype should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// mimeType should NOT be present (invalid format was skipped).
		$this->assertArrayNotHasKey( 'mimeType', $arr );
	}

	public function test_size_field_is_included(): void {
		$ability = wp_get_ability( 'test/resource-with-size' );
		$this->assertNotNull( $ability, 'Ability test/resource-with-size should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		$this->assertArrayHasKey( 'size', $arr );
		$this->assertSame( 2048, $arr['size'] );
	}

	public function test_icons_are_included_from_new_meta_structure(): void {
		$ability = wp_get_ability( 'test/resource-with-icons' );
		$this->assertNotNull( $ability, 'Ability test/resource-with-icons should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/resource-icon.svg', $arr['icons'][0]['src'] );
		$this->assertSame( 'image/svg+xml', $arr['icons'][0]['mimeType'] );
		$this->assertSame( 'light', $arr['icons'][0]['theme'] );
	}

	public function test_resource_name_filter_is_applied(): void {
		$ability = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );

		$filter_callback = static function ( string $name, \WP_Ability $ability ): string {
			return 'filtered-' . $name;
		};

		add_filter( 'mcp_adapter_resource_name', $filter_callback, 10, 2 );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();
		$this->assertSame( 'filtered-test/resource', $arr['name'] );

		remove_filter( 'mcp_adapter_resource_name', $filter_callback, 10 );
	}

	public function test_resource_uri_filter_is_applied(): void {
		$ability = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );

		$filter_callback = static function ( string $uri, \WP_Ability $ability ): string {
			return str_replace( 'local', 'filtered', $uri );
		};

		add_filter( 'mcp_adapter_resource_uri', $filter_callback, 10, 2 );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();
		$this->assertSame( 'WordPress://filtered/resource-1', $arr['uri'] );

		remove_filter( 'mcp_adapter_resource_uri', $filter_callback, 10 );
	}

	public function test_invalid_uri_filter_result_returns_wp_error(): void {
		$ability = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );

		$filter_callback = static function ( string $uri, \WP_Ability $ability ): string {
			return 'invalid-no-scheme';
		};

		add_filter( 'mcp_adapter_resource_uri', $filter_callback, 10, 2 );

		$resource = RegisterAbilityAsMcpResource::make( $ability );

		$this->assertWPError( $resource );
		$this->assertSame( 'mcp_resource_uri_filter_invalid', $resource->get_error_code() );

		remove_filter( 'mcp_adapter_resource_uri', $filter_callback, 10 );
	}

	public function test_invalid_annotations_are_dropped_with_doing_it_wrong(): void {
		$ability = wp_get_ability( 'test/resource-invalid-annotations-new-meta' );
		$this->assertNotNull( $ability, 'Ability test/resource-invalid-annotations-new-meta should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );

		// Resource should still be created successfully (graceful degradation).
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// Annotations should NOT be present (all dropped due to validation errors).
		$this->assertArrayNotHasKey( 'annotations', $arr );

		// Verify _doing_it_wrong was triggered.
		$this->assertDoingItWrongTriggered( 'WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource::get_data' );
	}

	public function test_mixed_valid_invalid_annotations_drops_all(): void {
		$ability = wp_get_ability( 'test/resource-mixed-annotations' );
		$this->assertNotNull( $ability, 'Ability test/resource-mixed-annotations should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );

		// Resource should still be created successfully.
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// ALL annotations should be dropped even though priority was valid.
		// This is because we drop all if ANY are invalid.
		$this->assertArrayNotHasKey( 'annotations', $arr );

		// Verify _doing_it_wrong was triggered.
		$this->assertDoingItWrongTriggered( 'WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource::get_data' );
	}

	public function test_valid_annotations_are_preserved(): void {
		$ability = wp_get_ability( 'test/resource-new-meta' );
		$this->assertNotNull( $ability, 'Ability test/resource-new-meta should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// Valid annotations should be preserved.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'audience', $arr['annotations'] );
		$this->assertArrayHasKey( 'priority', $arr['annotations'] );
		$this->assertArrayHasKey( 'lastModified', $arr['annotations'] );

		// Verify values are correct.
		$this->assertContains( 'user', $arr['annotations']['audience'] );
		$this->assertSame( 0.7, $arr['annotations']['priority'] );
		$this->assertSame( '2025-01-15T10:30:00Z', $arr['annotations']['lastModified'] );
	}

	public function test_missing_uri_returns_wp_error(): void {
		$ability = wp_get_ability( 'test/resource-missing-uri' );
		$this->assertNotNull( $ability, 'Ability test/resource-missing-uri should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );

		$this->assertWPError( $resource );
		$this->assertSame( 'resource_uri_not_found', $resource->get_error_code() );
	}

	public function test_valid_mimetype_is_accepted(): void {
		$ability = wp_get_ability( 'test/resource-valid-mimetype' );
		$this->assertNotNull( $ability, 'Ability test/resource-valid-mimetype should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability );
		$this->assertNotWPError( $resource );

		$arr = $resource->toArray();

		// mimeType should be present with valid format.
		$this->assertArrayHasKey( 'mimeType', $arr );
		$this->assertSame( 'application/json', $arr['mimeType'] );
	}
}
