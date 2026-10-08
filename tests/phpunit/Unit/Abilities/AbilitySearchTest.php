<?php
/**
 * Tests for AbilitySearch class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\AbilitySearch;
use WP\MCP\Tests\TestCase;
use WP_Error;

/**
 * Test AbilitySearch functionality.
 */
final class AbilitySearchTest extends TestCase {

	/**
	 * Abilities registered by the current test.
	 *
	 * @var list<string>
	 */
	private array $registered = array();

	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			wp_unregister_ability( $name );
		}
		$this->registered = array();

		parent::tearDown();
	}

	public function test_ranks_name_and_label_matches_above_description_matches(): void {
		$this->register( 'test/zebra-describer', 'Unrelated Label', 'Mentions zebra only in the description.' );
		$this->register( 'test/zebra-exporter', 'Export Zebra', 'Exports records.' );

		$result = AbilitySearch::search( array( 'query' => 'zebra' ) );

		$this->assertSame( array( 'test/zebra-exporter', 'test/zebra-describer' ), array_column( $result['abilities'], 'name' ) );
		$this->assertSame( 2, $result['total'] );
		$this->assertArrayNotHasKey( 'partial_matches', $result );
		$this->assertArrayNotHasKey( 'categories', $result );
	}

	public function test_requires_every_term_and_matches_plural_terms(): void {
		$this->register( 'test/list-zebra', 'List Zebra', 'Lists zebra records.' );
		$this->register( 'test/delete-zebra', 'Delete Zebra', 'Deletes zebra records.' );

		$result = AbilitySearch::search( array( 'query' => 'list zebras' ) );

		$this->assertSame( array( 'test/list-zebra' ), array_column( $result['abilities'], 'name' ) );
	}

	public function test_ignores_words_shorter_than_three_characters(): void {
		$this->register( 'test/list-zebra', 'List Zebra', 'Lists zebra records.' );

		$result = AbilitySearch::search( array( 'query' => 'i am zebra' ) );

		$this->assertSame( array( 'test/list-zebra' ), array_column( $result['abilities'], 'name' ) );
		$this->assertArrayNotHasKey( 'partial_matches', $result );
	}

	public function test_query_of_only_ignored_words_browses_like_an_empty_query(): void {
		$this->register( 'test/list-zebra', 'List Zebra', 'Lists zebra records.' );

		$result = AbilitySearch::search( array( 'query' => 'the io' ) );
		$browse = AbilitySearch::search( array( 'query' => '' ) );

		$this->assertSame( $browse['total'], $result['total'] );
		$this->assertSame( $browse['abilities'], $result['abilities'] );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayNotHasKey( 'partial_matches', $result );
	}

	public function test_falls_back_to_partial_matches_and_names_missed_terms(): void {
		$this->register( 'test/list-zebra', 'List Zebra', 'Lists zebra records.' );

		$result = AbilitySearch::search( array( 'query' => 'zebra quokka' ) );

		$this->assertSame( array( 'test/list-zebra' ), array_column( $result['abilities'], 'name' ) );
		$this->assertSame(
			array(
				array(
					'name'            => 'test/list-zebra',
					'unmatched_terms' => array( 'quokka' ),
				),
			),
			$result['partial_matches']
		);
	}

	public function test_no_match_returns_categories_and_next_step(): void {
		$result = AbilitySearch::search( array( 'query' => 'quokka' ) );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['abilities'] );
		$this->assertContains( 'test', array_column( $result['categories'], 'category' ) );
		$this->assertArrayHasKey( 'next_step', $result );
	}

	public function test_excludes_abilities_that_are_not_public_tools(): void {
		$this->register( 'test/zebra-private', 'Zebra Private', 'Private.', array( 'public' => false ) );
		$this->register(
			'test/zebra-resource',
			'Zebra Resource',
			'Resource.',
			array(
				'public' => true,
				'mcp'    => array( 'type' => 'resource' ),
			)
		);

		$result = AbilitySearch::search( array( 'query' => 'zebra' ) );

		$this->assertSame( 0, $result['total'] );
	}

	public function test_empty_query_browses_with_pages_and_categories(): void {
		$all  = AbilitySearch::search( array( 'limit' => AbilitySearch::MAX_LIMIT ) );
		$page = AbilitySearch::search(
			array(
				'query'  => '',
				'limit'  => 2,
				'offset' => 1,
			)
		);

		$this->assertGreaterThan( 3, $all['total'] );
		$this->assertSame( array_slice( array_column( $all['abilities'], 'name' ), 1, 2 ), array_column( $page['abilities'], 'name' ) );
		$this->assertTrue( $page['has_more'] );
		$this->assertSame( $all['total'], array_sum( array_column( $page['categories'], 'count' ) ) );
	}

	public function test_category_restricts_results(): void {
		$result = AbilitySearch::search( array( 'category' => 'no-such-category' ) );

		$this->assertSame( 0, $result['total'] );
	}

	public function test_hit_has_input_signature_and_true_annotations(): void {
		$this->register(
			'test/zebra-signature',
			'Zebra Signature',
			'Signature.',
			array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
				),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'order'  => array( 'enum' => array( 'asc', 'desc' ) ),
					'flag'   => array( 'enum' => array( true, false, null, 3 ) ),
					'tags'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'parent' => array( 'type' => array( 'integer', 'null' ) ),
				),
				'required'   => array( 'id' ),
			)
		);

		$hit = AbilitySearch::search( array( 'query' => 'zebra signature' ) )['abilities'][0];

		$this->assertSame( 'id* (integer), order (enum: asc|desc), flag (enum: true|false|null|3), tags (string[]), parent (integer|null)', $hit['input'] );
		$this->assertSame( array( 'readonly' ), $hit['annotations'] );
		$this->assertSame( 'test', $hit['category'] );
	}

	/**
	 * @dataProvider data_invalid_input
	 *
	 * @param array<string, mixed> $input Invalid search input.
	 */
	public function test_invalid_input_returns_error( array $input ): void {
		$this->assertInstanceOf( WP_Error::class, AbilitySearch::search( $input ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function data_invalid_input(): array {
		return array(
			'non-string query' => array( array( 'query' => 5 ) ),
			'long query'       => array( array( 'query' => str_repeat( 'a', 501 ) ) ),
			'non-string cat'   => array( array( 'category' => array() ) ),
			'zero limit'       => array( array( 'limit' => 0 ) ),
			'large limit'      => array( array( 'limit' => AbilitySearch::MAX_LIMIT + 1 ) ),
			'string limit'     => array( array( 'limit' => '5' ) ),
			'negative offset'  => array( array( 'offset' => -1 ) ),
		);
	}

	/**
	 * Registers a test ability in the `test` category and unregisters it after the test.
	 *
	 * @param string                    $name         Ability name.
	 * @param string                    $label        Ability label.
	 * @param string                    $description  Ability description.
	 * @param array<string, mixed>      $meta         Ability meta.
	 * @param array<string, mixed>|null $input_schema Ability input schema.
	 */
	private function register( string $name, string $label, string $description, array $meta = array( 'public' => true ), ?array $input_schema = null ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => 'test',
			'execute_callback'    => static fn () => array(),
			'permission_callback' => '__return_true',
			'meta'                => $meta,
		);
		if ( null !== $input_schema ) {
			$args['input_schema'] = $input_schema;
		}

		$this->register_ability_in_hook( $name, $args );
		$this->registered[] = $name;
	}
}
