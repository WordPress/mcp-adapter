<?php
/**
 * Demo plugin main class.
 *
 * @package MCP\Demo
 */

declare(strict_types=1);

namespace WP\MCP\Demo;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Demo\Admin\McpAdminPage;
use WP\MCP\Demo\Admin\DashboardWidget;

/**
 * MCP Adapter Demo Plugin
 */
final class DemoPlugin {
	/**
	 * The one true instance.
	 *
	 * @var ?static
	 */
	private static $instance;

	/**
	 * Get instance.
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Setup the demo plugin.
	 */
	private function setup(): void {
		// Initialize admin interface
		if ( is_admin() ) {
			$admin_page = new McpAdminPage();
			$admin_page->init();
			
			$dashboard_widget = new DashboardWidget();
			$dashboard_widget->init();
		}
		
		// Register demo abilities during the correct action
		add_action( 'abilities_api_init', array( $this, 'register_demo_abilities' ) );
		
		// Set up demo MCP servers
		add_action( 'mcp_adapter_init', array( $this, 'setup_demo_servers' ) );
		
		// Load examples early so their hooks are registered before mcp_adapter_init fires
		$this->load_examples();
	}
	
	/**
	 * Register demo abilities during abilities_api_init.
	 */
	public function register_demo_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Content Management Server abilities
		wp_register_ability(
			'demo-content/get-featured-posts',
			array(
				'label'               => 'Get Featured Posts',
				'description'         => 'Get posts marked as featured or sticky',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Number of posts to return',
							'default'     => 5,
							'maximum'     => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$limit = min( intval( $args['limit'] ?? 5 ), 20 );
					$posts = get_posts( array(
						'numberposts' => $limit,
						'post_status' => 'publish',
						'orderby'     => 'date',
						'order'       => 'DESC',
					) );

					$result = array();
					foreach ( $posts as $post ) {
						$result[] = array(
							'id'      => $post->ID,
							'title'   => $post->post_title,
							'excerpt' => wp_trim_words( $post->post_excerpt ?: $post->post_content, 20 ),
							'url'     => get_permalink( $post ),
						);
					}
					return array( 'posts' => $result );
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		wp_register_ability(
			'demo-content/update-post-status',
			array(
				'label'               => 'Update Post Status',
				'description'         => 'Update the status of a WordPress post',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'Post ID to update',
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish', 'private', 'pending' ),
							'description' => 'New post status',
						),
					),
					'required' => array( 'post_id', 'status' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$result = wp_update_post( array(
						'ID'          => $args['post_id'],
						'post_status' => $args['status'],
					) );
					return array( 'success' => ! is_wp_error( $result ) );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Analytics Server abilities
		wp_register_ability(
			'demo-analytics/get-site-metrics',
			array(
				'label'               => 'Get Site Metrics',
				'description'         => 'Get basic site metrics and statistics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'metrics' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					return array(
						'metrics' => array(
							'total_posts'    => wp_count_posts()->publish,
							'total_pages'    => wp_count_posts( 'page' )->publish,
							'total_comments' => wp_count_comments()->approved,
							'total_users'    => count_users()['total_users'],
						),
					);
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		wp_register_ability(
			'demo-analytics/get-popular-posts',
			array(
				'label'               => 'Get Popular Posts',
				'description'         => 'Get posts with most comments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'default'     => 10,
							'maximum'     => 50,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$limit = min( intval( $args['limit'] ?? 10 ), 50 );
					$posts = get_posts( array(
						'numberposts' => $limit,
						'orderby'     => 'comment_count',
						'order'       => 'DESC',
						'post_status' => 'publish',
					) );

					$result = array();
					foreach ( $posts as $post ) {
						$result[] = array(
							'id'            => $post->ID,
							'title'         => $post->post_title,
							'url'           => get_permalink( $post ),
							'comment_count' => intval( $post->comment_count ),
						);
					}
					return array( 'posts' => $result );
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		// Resource abilities for Content Management Server
		wp_register_ability(
			'demo-content/resource-recent-posts',
			array(
				'label'               => 'Recent Posts Resource',
				'description'         => 'Get recent posts as a resource',
				'meta'                => array(
					'uri' => 'mcp://content-server/recent-posts',
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'contents' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$posts = get_posts( array(
						'numberposts' => 10,
						'post_status' => 'publish',
					) );
					return array(
						'contents' => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $posts ),
							),
						),
					);
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		wp_register_ability(
			'demo-content/resource-categories',
			array(
				'label'               => 'Categories Resource',
				'description'         => 'Get WordPress categories as a resource',
				'meta'                => array(
					'uri' => 'mcp://content-server/categories',
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'contents' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$categories = get_categories();
					return array(
						'contents' => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $categories ),
							),
						),
					);
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		// Resource abilities for Analytics Server
		wp_register_ability(
			'demo-analytics/resource-engagement-data',
			array(
				'label'               => 'Engagement Data Resource',
				'description'         => 'Get site engagement data as a resource',
				'meta'                => array(
					'uri' => 'mcp://analytics-server/engagement-data',
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'contents' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$engagement_data = array(
						'total_posts'    => wp_count_posts()->publish,
						'total_comments' => wp_count_comments()->approved,
						'avg_comments_per_post' => wp_count_posts()->publish > 0 ? 
							round( wp_count_comments()->approved / wp_count_posts()->publish, 2 ) : 0,
					);
					return array(
						'contents' => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $engagement_data ),
							),
						),
					);
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		wp_register_ability(
			'demo-analytics/resource-traffic-sources',
			array(
				'label'               => 'Traffic Sources Resource',
				'description'         => 'Get traffic source information as a resource',
				'meta'                => array(
					'uri' => 'mcp://analytics-server/traffic-sources',
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'contents' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$traffic_data = array(
						'direct'     => '45%',
						'search'     => '35%',
						'social'     => '15%',
						'referral'   => '5%',
						'note'       => 'Simulated traffic data for demo purposes',
					);
					return array(
						'contents' => array(
							array(
								'type' => 'text',
								'text' => wp_json_encode( $traffic_data ),
							),
						),
					);
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		// Prompt abilities for Content Management Server
		wp_register_ability(
			'demo-content/prompt-post-ideas',
			array(
				'label'               => 'Generate Post Ideas',
				'description'         => 'Generate blog post ideas based on categories and topics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category' => array(
							'type'        => 'string',
							'description' => 'Content category or topic',
							'default'     => 'general',
						),
						'count' => array(
							'type'        => 'integer',
							'description' => 'Number of ideas to generate',
							'default'     => 5,
							'maximum'     => 10,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array( 'type' => 'string' ),
						'suggestions' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$category = sanitize_text_field( $args['category'] ?? 'general' );
					$count = min( intval( $args['count'] ?? 5 ), 10 );
					
					$base_ideas = array(
						'How to optimize your ' . $category . ' strategy',
						'The ultimate guide to ' . $category,
						'Common mistakes in ' . $category . ' and how to avoid them',
						'Latest trends in ' . $category . ' for 2024',
						'Beginner\'s guide to ' . $category,
						'Advanced ' . $category . ' techniques',
						'Case study: Success in ' . $category,
						'Tools and resources for ' . $category,
						'Future of ' . $category . ' industry',
						'Expert insights on ' . $category,
					);
					
					$suggestions = array_slice( $base_ideas, 0, $count );
					
					return array(
						'prompt' => 'Here are ' . $count . ' blog post ideas for the ' . $category . ' category:',
						'suggestions' => $suggestions,
					);
				},
				'permission_callback' => function () {
					return true;
				},
			)
		);

		wp_register_ability(
			'demo-content/prompt-content-audit',
			array(
				'label'               => 'Content Audit Checklist',
				'description'         => 'Generate a content audit checklist for WordPress sites',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'focus_area' => array(
							'type'        => 'string',
							'enum'        => array( 'seo', 'readability', 'engagement', 'technical' ),
							'description' => 'Area to focus the audit on',
							'default'     => 'seo',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array( 'type' => 'string' ),
						'checklist' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$focus = sanitize_text_field( $args['focus_area'] ?? 'seo' );
					
					$checklists = array(
						'seo' => array(
							'Check title tags are unique and descriptive',
							'Verify meta descriptions are compelling',
							'Ensure proper heading structure (H1, H2, H3)',
							'Review internal linking strategy',
							'Check for broken links',
							'Verify image alt text',
							'Review URL structure',
							'Check for duplicate content',
						),
						'readability' => array(
							'Review paragraph length and structure',
							'Check for clear headings and subheadings',
							'Ensure proper use of bullet points and lists',
							'Review sentence length and complexity',
							'Check for consistent tone and voice',
							'Verify proper formatting and typography',
							'Review content flow and transitions',
							'Check for grammar and spelling errors',
						),
						'engagement' => array(
							'Review call-to-action placement',
							'Check for engaging introductions',
							'Verify social sharing options',
							'Review comment moderation and responses',
							'Check for multimedia content integration',
							'Review related posts suggestions',
							'Verify newsletter signup placement',
							'Check for interactive elements',
						),
						'technical' => array(
							'Check page loading speed',
							'Verify mobile responsiveness',
							'Review image optimization',
							'Check for proper schema markup',
							'Verify SSL certificate',
							'Review caching configuration',
							'Check for 404 errors',
							'Verify backup systems',
						),
					);
					
					return array(
						'prompt' => 'Content audit checklist focused on ' . $focus . ':',
						'checklist' => $checklists[ $focus ] ?? $checklists['seo'],
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Prompt abilities for Analytics Server
		wp_register_ability(
			'demo-analytics/prompt-performance-report',
			array(
				'label'               => 'Performance Report Template',
				'description'         => 'Generate a site performance report template with current metrics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'period' => array(
							'type'        => 'string',
							'enum'        => array( 'weekly', 'monthly', 'quarterly' ),
							'description' => 'Reporting period',
							'default'     => 'monthly',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array( 'type' => 'string' ),
						'template' => array( 'type' => 'string' ),
						'metrics' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$period = sanitize_text_field( $args['period'] ?? 'monthly' );
					
					$current_metrics = array(
						'total_posts'    => wp_count_posts()->publish,
						'total_pages'    => wp_count_posts( 'page' )->publish,
						'total_comments' => wp_count_comments()->approved,
						'total_users'    => count_users()['total_users'],
					);
					
					$template = "# " . ucfirst( $period ) . " Performance Report\n\n" .
								"## Site Overview\n" .
								"- Published Posts: {$current_metrics['total_posts']}\n" .
								"- Published Pages: {$current_metrics['total_pages']}\n" .
								"- Approved Comments: {$current_metrics['total_comments']}\n" .
								"- Total Users: {$current_metrics['total_users']}\n\n" .
								"## Key Metrics\n" .
								"- Traffic Growth: [To be updated]\n" .
								"- Engagement Rate: [To be updated]\n" .
								"- Conversion Rate: [To be updated]\n\n" .
								"## Content Performance\n" .
								"- Top Performing Posts: [To be updated]\n" .
								"- Most Shared Content: [To be updated]\n\n" .
								"## Recommendations\n" .
								"- [Action item 1]\n" .
								"- [Action item 2]\n" .
								"- [Action item 3]";
					
					return array(
						'prompt' => 'Generated ' . $period . ' performance report template with current site metrics:',
						'template' => $template,
						'metrics' => $current_metrics,
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
			)
		);

		wp_register_ability(
			'demo-analytics/prompt-growth-insights',
			array(
				'label'               => 'Growth Insights Generator',
				'description'         => 'Generate growth insights and recommendations based on site data',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'focus' => array(
							'type'        => 'string',
							'enum'        => array( 'content', 'engagement', 'seo', 'social' ),
							'description' => 'Focus area for growth insights',
							'default'     => 'content',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'prompt' => array( 'type' => 'string' ),
						'insights' => array( 'type' => 'array' ),
						'actions' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$focus = sanitize_text_field( $args['focus'] ?? 'content' );
					
					$insights_map = array(
						'content' => array(
							'insights' => array(
								'Your site has ' . wp_count_posts()->publish . ' published posts',
								'Average comments per post: ' . (wp_count_posts()->publish > 0 ? 
									round( wp_count_comments()->approved / wp_count_posts()->publish, 1 ) : 0),
								'Content consistency drives engagement',
							),
							'actions' => array(
								'Maintain regular publishing schedule',
								'Focus on evergreen content topics',
								'Repurpose high-performing content',
								'Create content series or themes',
							),
						),
						'engagement' => array(
							'insights' => array(
								'Total approved comments: ' . wp_count_comments()->approved,
								'User base: ' . count_users()['total_users'] . ' registered users',
								'Engagement builds community loyalty',
							),
							'actions' => array(
								'Respond promptly to comments',
								'Ask questions in your content',
								'Create interactive content',
								'Encourage user-generated content',
							),
						),
						'seo' => array(
							'insights' => array(
								'Content volume supports SEO authority',
								'Internal linking opportunities available',
								'Regular updates improve search rankings',
							),
							'actions' => array(
								'Optimize title tags and meta descriptions',
								'Improve internal linking structure',
								'Focus on long-tail keywords',
								'Update and refresh old content',
							),
						),
						'social' => array(
							'insights' => array(
								'Quality content drives social shares',
								'Consistent posting builds audience',
								'Visual content performs better on social',
							),
							'actions' => array(
								'Create shareable graphics for posts',
								'Engage with your audience on social platforms',
								'Share behind-the-scenes content',
								'Use relevant hashtags strategically',
							),
						),
					);
					
					$data = $insights_map[ $focus ] ?? $insights_map['content'];
					
					return array(
						'prompt' => 'Growth insights and recommendations for ' . $focus . ':',
						'insights' => $data['insights'],
						'actions' => $data['actions'],
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
			)
		);
	}
	
	/**
	 * Set up demo MCP servers.
	 */
	public function setup_demo_servers( McpAdapter $adapter ): void {
		// Server 1: Content Management Server
		$adapter->create_server(
			'content-server',
			'mcp',
			'content',
			'Content Management Server',
			'Demo MCP server for content management operations',
			'1.0.0',
			array(
				\WP\MCP\Transport\Http\RestTransport::class,
			),
			null,
			null,
			array( 
				'demo-content/get-featured-posts',
				'demo-content/update-post-status',
			),
			array(
				'demo-content/resource-recent-posts',
				'demo-content/resource-categories',
			),
			array(
				'demo-content/prompt-post-ideas',
				'demo-content/prompt-content-audit',
			),
			function() { return true; } // Public access
		);

		// Server 2: Analytics Server
		$adapter->create_server(
			'analytics-server',
			'mcp',
			'analytics',
			'Analytics Server',
			'Demo MCP server for site analytics and metrics',
			'1.0.0',
			array(
				\WP\MCP\Transport\Http\RestTransport::class,
			),
			null,
			null,
			array(
				'demo-analytics/get-site-metrics',
				'demo-analytics/get-popular-posts',
			),
			array(
				'demo-analytics/resource-engagement-data',
				'demo-analytics/resource-traffic-sources',
			),
			array(
				'demo-analytics/prompt-performance-report',
				'demo-analytics/prompt-growth-insights',
			),
			function() { return current_user_can( 'read' ); }
		);
	}
	
	/**
	 * Load examples.
	 */
	private function load_examples(): void {
		$plugin_dir = plugin_dir_path( __DIR__ );
		
		// Load server examples
		$server_examples_file = $plugin_dir . 'examples/server-usage.php';
		if ( file_exists( $server_examples_file ) ) {
			include_once $server_examples_file;
		}
		
		// Load client examples  
		$client_examples_file = $plugin_dir . 'examples/client-usage.php';
		if ( file_exists( $client_examples_file ) ) {
			include_once $client_examples_file;
		}
	}
}