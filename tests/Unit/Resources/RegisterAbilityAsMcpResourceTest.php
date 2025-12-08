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

		public function test_empty_annotations_are_not_included(): void {
			$ability = wp_get_ability( 'test/resource' );
			$this->assertNotNull( $ability, 'Ability test/resource should be registered' );

			$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
			$this->assertNotWPError( $resource );

			$arr = $resource->to_array();

			// Verify annotations field is not present when empty.
			$this->assertArrayNotHasKey( 'annotations', $arr );
		}

		public function test_get_uri_trims_whitespace_from_meta(): void {
			$ability = wp_get_ability( 'test/resource-whitespace-uri' );
			$this->assertNotNull( $ability, 'Ability test/resource-whitespace-uri should be registered' );

			$resource = RegisterAbilityAsMcpResource::make( $ability, $this->makeServer() );
			$this->assertNotWPError( $resource );

			$arr = $resource->to_array();
			$this->assertSame( 'WordPress://local/resource-whitespace', $arr['uri'] );
		}
}
