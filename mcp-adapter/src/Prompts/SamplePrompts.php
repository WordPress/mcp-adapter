<?php //phpcs:ignore
/**
 * Sample MCP Prompts for WordPress.
 *
 * @package WpcomMcp
 */

declare( strict_types=1 );

namespace WP\MCP\Prompts;

use WP\MCP\Prompts\Interfaces\PromptsInterface;

/**
 * Sample prompts for WordPress operations.
 */
class SamplePrompts implements PromptsInterface {

	/**
	 * Get the prompts.
	 *
	 * @return array The prompts.
	 */
	public function get_prompts(): array {
		return array(
			array(
				'args'     => array(
					'name'        => 'analyze_website_performance',
					'description' => 'Analyze website performance data and provide optimization recommendations',
					'arguments'   => array(
						array(
							'name'        => 'time_period',
							'description' => 'Time period to analyze (e.g., "last 7 days", "last month")',
							'required'    => true,
							'type'        => 'string',
						),
						array(
							'name'        => 'focus_areas',
							'description' => 'Specific areas to focus on (e.g., speed, SEO, accessibility)',
							'required'    => false,
							'type'        => 'array',
						),
					),
				),
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => 'Analyze the website performance for {{time_period}}. {{#focus_areas}}Focus specifically on these areas: {{focus_areas}}.{{/focus_areas}} Provide detailed insights and actionable recommendations for improving performance.',
						),
					),
				),
			),
			array(
				'args'     => array(
					'name'        => 'content_strategy_advisor',
					'description' => 'Provide content strategy recommendations based on site analysis',
					'arguments'   => array(
						array(
							'name'        => 'target_audience',
							'description' => 'Description of the target audience',
							'required'    => true,
							'type'        => 'string',
						),
						array(
							'name'        => 'business_goals',
							'description' => 'Primary business goals (e.g., lead generation, brand awareness)',
							'required'    => true,
							'type'        => 'string',
						),
						array(
							'name'        => 'current_content_topics',
							'description' => 'Current content topics and themes',
							'required'    => false,
							'type'        => 'array',
						),
					),
				),
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => array(
							'type' => 'text',
							'text' => 'You are a content strategy expert specializing in WordPress websites. Provide actionable, data-driven recommendations.',
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => 'Create a comprehensive content strategy for a website targeting {{target_audience}} with the primary business goal of {{business_goals}}. {{#current_content_topics}}Current content focuses on: {{current_content_topics}}.{{/current_content_topics}} Provide specific content recommendations, publishing frequency, and key topics to cover.',
						),
					),
				),
			),
			array(
				'args'     => array(
					'name'        => 'security_audit_summary',
					'description' => 'Generate a security audit summary with recommendations',
					'arguments'   => array(
						array(
							'name'        => 'scan_results',
							'description' => 'Security scan results data',
							'required'    => true,
							'type'        => 'object',
						),
						array(
							'name'        => 'priority_level',
							'description' => 'Priority level for recommendations (high, medium, low)',
							'required'    => false,
							'type'        => 'string',
						),
					),
				),
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => 'Based on these security scan results: {{scan_results}}, provide a comprehensive security audit summary. {{#priority_level}}Focus on {{priority_level}} priority items.{{/priority_level}} Include specific action items, potential risks, and implementation steps for each recommendation.',
						),
					),
				),
			),
			array(
				'args'     => array(
					'name'        => 'plugin_compatibility_check',
					'description' => 'Analyze plugin compatibility and suggest alternatives',
					'arguments'   => array(
						array(
							'name'        => 'wordpress_version',
							'description' => 'Current WordPress version',
							'required'    => true,
							'type'        => 'string',
						),
						array(
							'name'        => 'plugins_list',
							'description' => 'List of currently installed plugins',
							'required'    => true,
							'type'        => 'array',
						),
						array(
							'name'        => 'issues_found',
							'description' => 'Any compatibility issues or conflicts found',
							'required'    => false,
							'type'        => 'array',
						),
					),
				),
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => array(
							'type' => 'text',
							'text' => 'You are a WordPress plugin expert. Analyze compatibility issues and provide practical solutions.',
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							'type' => 'text',
							'text' => 'Analyze plugin compatibility for WordPress {{wordpress_version}} with these installed plugins: {{plugins_list}}. {{#issues_found}}Known issues: {{issues_found}}.{{/issues_found}} Provide recommendations for updates, alternatives, or configuration changes to ensure optimal compatibility and performance.',
						),
					),
				),
			),
		);
	}
}
