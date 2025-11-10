<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpResourceTest extends TestCase {

	public function test_make_builds_resource_from_ability(): void {
		$ability  = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );
		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$arr      = $resource->to_array();
		$this->assertSame( 'WordPress://local/resource-1', $arr['uri'] );
		$this->assertSame( $ability, $resource->get_ability() );
	}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/resource-with-annotations' );
		$this->assertNotNull( $ability, 'Ability test/resource-with-annotations should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $resource );

		$arr = $resource->to_array();

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

		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $resource );

		$arr = $resource->to_array();

		// Verify only provided annotations are present.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'priority', $arr['annotations'] );
		$this->assertSame( 0.5, $arr['annotations']['priority'] );
		$this->assertArrayNotHasKey( 'audience', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'lastModified', $arr['annotations'] );
	}

	public function test_invalid_annotations_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/resource-invalid-annotations' );
		$this->assertNotNull( $ability, 'Ability test/resource-invalid-annotations should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $resource );

		$arr = $resource->to_array();

		// Verify invalid annotations are filtered out.
		if ( isset( $arr['annotations'] ) ) {
			// Invalid role should be filtered
			if ( isset( $arr['annotations']['audience'] ) ) {
				$this->assertNotContains( 'invalid-role', $arr['annotations']['audience'] );
			}
			// Invalid date should be filtered
			$this->assertArrayNotHasKey( 'lastModified', $arr['annotations'] );
			// Priority should be clamped to 1.0 (was 2.0)
			if ( isset( $arr['annotations']['priority'] ) ) {
				$this->assertLessThanOrEqual( 1.0, $arr['annotations']['priority'] );
			}
			// Unknown field should be filtered
			$this->assertArrayNotHasKey( 'invalidField', $arr['annotations'] );
		}
	}

	public function test_empty_annotations_are_not_included(): void {
		$ability = wp_get_ability( 'test/resource' );
		$this->assertNotNull( $ability, 'Ability test/resource should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $resource );

		$arr = $resource->to_array();

		// Verify annotations field is not present when empty.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_priority_is_clamped_to_valid_range(): void {
		$ability = wp_get_ability( 'test/resource-invalid-annotations' );
		$this->assertNotNull( $ability, 'Ability test/resource-invalid-annotations should be registered' );

		$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $resource );

		$arr = $resource->to_array();

		// Priority was 2.0, should be clamped to 1.0.
		if ( isset( $arr['annotations']['priority'] ) ) {
			$this->assertGreaterThanOrEqual( 0.0, $arr['annotations']['priority'] );
			$this->assertLessThanOrEqual( 1.0, $arr['annotations']['priority'] );
		}
	}
}
