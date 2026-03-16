<?php

namespace AEBG\API;

use AEBG\Core\Generator;
use AEBG\Core\TemplateManager;
use AEBG\Core\BatchScheduler;
use AEBG\Core\Logger;

/**
 * Generator V2 Controller Class
 *
 * Handles REST API endpoints for the step-by-step Generator V2 interface
 *
 * @package AEBG\API
 */
class GeneratorV2Controller extends \WP_REST_Controller {
	
	/**
	 * GeneratorV2Controller constructor.
	 */
	public function __construct() {
		$this->namespace = 'aebg/v1';
		$this->rest_base = 'generator-v2';
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		// Get templates list
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/templates',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_templates' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);

		// Analyze template for product count
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/templates/(?P<id>\d+)/analyze',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'analyze_template' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'id' => [
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		// Calculate costs
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calculate-costs',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'calculate_costs' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);

		// Generate content
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/generate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate_content' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	/**
	 * Check if a given request has access.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return bool|\WP_Error
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'aebg_generate_content' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'aebg' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Get templates list.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_templates( $request ) {
		$templates = get_posts([
			'post_type' => 'elementor_library',
			'posts_per_page' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
			'post_status' => 'publish',
		]);

		$formatted_templates = [];
		foreach ( $templates as $template ) {
			$template_type = get_post_meta( $template->ID, '_elementor_template_type', true );
			$type_icon = '📄';
			if ( $template_type === 'page' ) $type_icon = '📋';
			if ( $template_type === 'section' ) $type_icon = '🔧';

			// Get last used date (from batches table via join)
			global $wpdb;
			$last_used = null;
			
			// Check if tables exist before querying
			$batches_table = $wpdb->prefix . 'aebg_batches';
			$items_table = $wpdb->prefix . 'aebg_batch_items';
			
			$batches_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables 
				WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$batches_table
			) );
			
			$items_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables 
				WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$items_table
			) );
			
			if ( $batches_exists && $items_exists ) {
				$last_used = $wpdb->get_var( $wpdb->prepare(
					"SELECT MAX(b.created_at) 
					FROM {$wpdb->prefix}aebg_batches b
					INNER JOIN {$wpdb->prefix}aebg_batch_items bi ON b.id = bi.batch_id
					WHERE b.template_id = %d",
					$template->ID
				) );
			}

			$last_used_text = 'Never';
			if ( $last_used ) {
				$last_used_time = strtotime( $last_used );
				$now = time();
				$diff = $now - $last_used_time;
				
				if ( $diff < 3600 ) {
					$last_used_text = 'Just now';
				} elseif ( $diff < 86400 ) {
					$last_used_text = floor( $diff / 3600 ) . ' hours ago';
				} elseif ( $diff < 604800 ) {
					$last_used_text = floor( $diff / 86400 ) . ' days ago';
				} else {
					$last_used_text = date( 'M j, Y', $last_used_time );
				}
			}

			// Try to get product count from template analysis
			$product_count = null;
			if ( class_exists( 'AEBG\\Core\\TemplateManager' ) ) {
				$template_manager = new TemplateManager();
				$analysis = $template_manager->analyzeTemplate( $template->ID );
				if ( ! is_wp_error( $analysis ) && isset( $analysis['total_slots'] ) ) {
					$product_count = $analysis['total_slots'];
				}
			}

			$formatted_templates[] = [
				'id' => $template->ID,
				'name' => $template->post_title,
				'type' => $template_type ?: 'page',
				'typeIcon' => $type_icon,
				'productCount' => $product_count,
				'lastUsed' => $last_used_text,
			];
		}

		return rest_ensure_response( $formatted_templates );
	}

	/**
	 * Analyze template for product count.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function analyze_template( $request ) {
		$template_id = (int) $request['id'];

		if ( ! class_exists( 'AEBG\\Core\\TemplateManager' ) ) {
			return new \WP_Error(
				'template_manager_not_found',
				'TemplateManager class not found',
				[ 'status' => 500 ]
			);
		}

		$template_manager = new TemplateManager();
		$analysis = $template_manager->analyzeTemplate( $template_id );

		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}

		$product_count = isset( $analysis['total_slots'] ) ? $analysis['total_slots'] : 0;

		return rest_ensure_response( [
			'template_id' => $template_id,
			'product_count' => $product_count,
			'analysis' => $analysis,
		] );
	}

	/**
	 * Calculate costs for generation.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function calculate_costs( $request ) {
		$params = $request->get_json_params();

		$num_posts = isset( $params['num_posts'] ) ? (int) $params['num_posts'] : 0;
		$ai_model = isset( $params['ai_model'] ) ? sanitize_text_field( $params['ai_model'] ) : 'gpt-3.5-turbo';
		$content_length = isset( $params['content_length'] ) ? (int) $params['content_length'] : 1500;
		$num_products = isset( $params['num_products'] ) ? (int) $params['num_products'] : 7;
		$generate_images = isset( $params['generate_images'] ) ? (bool) $params['generate_images'] : false;
		$image_model = isset( $params['image_model'] ) ? sanitize_text_field( $params['image_model'] ) : 'dall-e-3';
		$image_quality = isset( $params['image_quality'] ) ? sanitize_text_field( $params['image_quality'] ) : 'standard';
		$generate_featured_images = isset( $params['generate_featured_images'] ) ? (bool) $params['generate_featured_images'] : false;

		// Calculate text generation cost
		$text_cost = $this->calculate_text_cost( $num_posts, $ai_model, $content_length, $num_products );

		// Calculate image generation cost
		$image_cost = 0;
		$num_images = 0;
		if ( $generate_featured_images ) {
			$num_images = $num_posts;
			$image_cost = $this->calculate_image_cost( $num_images, $image_model, $image_quality );
		}

		$total_cost = $text_cost + $image_cost;

		// Estimate time (rough estimates)
		$text_time_per_post = $this->estimate_text_time( $ai_model, $content_length, $num_products );
		$image_time_per_image = $this->estimate_image_time( $image_model, $image_quality );
		
		$total_time_seconds = ( $text_time_per_post * $num_posts ) + ( $image_time_per_image * $num_images );
		$total_time_minutes = ceil( $total_time_seconds / 60 );

		return rest_ensure_response( [
			'text_generation' => [
				'cost_per_post' => $text_cost / max( $num_posts, 1 ),
				'total_cost' => $text_cost,
				'model' => $ai_model,
				'num_posts' => $num_posts,
			],
			'image_generation' => [
				'cost_per_image' => $num_images > 0 ? $image_cost / $num_images : 0,
				'total_cost' => $image_cost,
				'model' => $image_model,
				'quality' => $image_quality,
				'num_images' => $num_images,
			],
			'total_cost' => $total_cost,
			'estimated_time_seconds' => $total_time_seconds,
			'estimated_time_minutes' => $total_time_minutes,
		] );
	}

	/**
	 * Calculate text generation cost.
	 *
	 * @param int $num_posts Number of posts.
	 * @param string $ai_model AI model to use.
	 * @param int $content_length Target content length.
	 * @param int $num_products Number of products per post.
	 * @return float
	 */
	private function calculate_text_cost( $num_posts, $ai_model, $content_length, $num_products ) {
		// Cost per 1K tokens (as of 2024)
		$costs_per_1k = [
			'gpt-4' => [ 'input' => 0.03, 'output' => 0.06 ],
			'gpt-4-turbo' => [ 'input' => 0.01, 'output' => 0.03 ],
			'gpt-4o' => [ 'input' => 0.005, 'output' => 0.015 ],
			'gpt-3.5-turbo' => [ 'input' => 0.0005, 'output' => 0.0015 ],
		];

		$model_costs = isset( $costs_per_1k[ $ai_model ] ) ? $costs_per_1k[ $ai_model ] : $costs_per_1k['gpt-3.5-turbo'];

		// Estimate tokens per post
		// Input: title + context + product data (~500 tokens) + prompt overhead (~200 tokens)
		$input_tokens_per_post = 500 + ( $num_products * 100 ); // Rough estimate
		
		// Output: content length / 4 (roughly 4 chars per token)
		$output_tokens_per_post = ceil( $content_length / 4 );

		// Calculate cost per post
		$input_cost_per_post = ( $input_tokens_per_post / 1000 ) * $model_costs['input'];
		$output_cost_per_post = ( $output_tokens_per_post / 1000 ) * $model_costs['output'];
		$cost_per_post = $input_cost_per_post + $output_cost_per_post;

		return $cost_per_post * $num_posts;
	}

	/**
	 * Calculate image generation cost.
	 *
	 * @param int $num_images Number of images.
	 * @param string $image_model Image model (dall-e-3 or dall-e-2).
	 * @param string $quality Image quality (standard or hd).
	 * @return float
	 */
	private function calculate_image_cost( $num_images, $image_model, $quality ) {
		// DALL-E and Nano Banana pricing (as of 2024)
		$costs = [
			'dall-e-3' => [
				'standard' => 0.04,
				'hd' => 0.08,
			],
			'dall-e-2' => [
				'standard' => 0.02,
				'hd' => 0.02, // DALL-E 2 doesn't have HD option
			],
			'nano-banana'     => [ 'standard' => 0.02, 'hd' => 0.02 ],
			'nano-banana-2'   => [ 'standard' => 0.02, 'hd' => 0.02 ],
			'nano-banana-pro' => [ 'standard' => 0.04, 'hd' => 0.04 ],
		];

		$model_costs = isset( $costs[ $image_model ] ) ? $costs[ $image_model ] : $costs['dall-e-3'];
		$cost_per_image = isset( $model_costs[ $quality ] ) ? $model_costs[ $quality ] : $model_costs['standard'];

		return $cost_per_image * $num_images;
	}

	/**
	 * Estimate text generation time.
	 *
	 * @param string $ai_model AI model.
	 * @param int $content_length Content length.
	 * @param int $num_products Number of products.
	 * @return int Time in seconds.
	 */
	private function estimate_text_time( $ai_model, $content_length, $num_products ) {
		// Base time per post (seconds)
		$base_time = 10;

		// Model-specific adjustments
		$model_multipliers = [
			'gpt-4' => 1.5,
			'gpt-4-turbo' => 1.2,
			'gpt-4o' => 1.0,
			'gpt-3.5-turbo' => 0.8,
		];

		$multiplier = isset( $model_multipliers[ $ai_model ] ) ? $model_multipliers[ $ai_model ] : 1.0;

		// Content length factor (longer content = more time)
		$length_factor = 1 + ( ( $content_length - 1000 ) / 2000 );

		// Product count factor (more products = more time)
		$product_factor = 1 + ( ( $num_products - 5 ) * 0.1 );

		return ceil( $base_time * $multiplier * $length_factor * $product_factor );
	}

	/**
	 * Estimate image generation time.
	 *
	 * @param string $image_model Image model.
	 * @param string $quality Image quality.
	 * @return int Time in seconds.
	 */
	private function estimate_image_time( $image_model, $quality ) {
		// Base time per image (seconds)
		$base_time = 15;

		// Model-specific adjustments
		if ( $image_model === 'dall-e-2' ) {
			$base_time = 10;
		}
		if ( in_array( $image_model, [ 'nano-banana', 'nano-banana-2', 'nano-banana-pro' ], true ) ) {
			$base_time = $image_model === 'nano-banana-pro' ? 15 : 8;
		}

		// Quality adjustments
		if ( $quality === 'hd' ) {
			$base_time *= 1.5;
		}

		return ceil( $base_time );
	}

	/**
	 * Generate content (schedule batch).
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_content( $request ) {
		$params = $request->get_json_params();

		// Validate required fields
		if ( empty( $params['titles'] ) || ! is_array( $params['titles'] ) ) {
			return new \WP_Error(
				'missing_titles',
				'Titles are required',
				[ 'status' => 400 ]
			);
		}

		if ( empty( $params['template'] ) ) {
			return new \WP_Error(
				'missing_template',
				'Template is required',
				[ 'status' => 400 ]
			);
		}

		// Prepare settings
		$settings = [
			'template_id' => (int) $params['template'],
			'num_products' => isset( $params['num_products'] ) ? (int) $params['num_products'] : 7,
			'post_type' => isset( $params['post_type'] ) ? sanitize_text_field( $params['post_type'] ) : 'post',
			'post_status' => isset( $params['post_status'] ) ? sanitize_text_field( $params['post_status'] ) : 'draft',
			'ai_model' => isset( $params['ai_model'] ) ? sanitize_text_field( $params['ai_model'] ) : 'gpt-3.5-turbo',
			'creativity' => isset( $params['creativity'] ) ? (float) $params['creativity'] : 0.7,
			'content_length' => isset( $params['content_length'] ) ? (int) $params['content_length'] : 1500,
			'generate_featured_images' => isset( $params['generate_featured_images'] ) ? (bool) $params['generate_featured_images'] : false,
			'featured_image_style' => isset( $params['featured_image_style'] ) ? sanitize_text_field( $params['featured_image_style'] ) : 'realistic photo',
			'image_model' => isset( $params['image_model'] ) ? sanitize_text_field( $params['image_model'] ) : 'dall-e-3',
			'image_size' => isset( $params['image_size'] ) ? sanitize_text_field( $params['image_size'] ) : '1024x1024',
			'image_quality' => isset( $params['image_quality'] ) ? sanitize_text_field( $params['image_quality'] ) : 'standard',
			'auto_categories' => isset( $params['auto_categories'] ) ? (bool) $params['auto_categories'] : true,
			'auto_tags' => isset( $params['auto_tags'] ) ? (bool) $params['auto_tags'] : true,
			'include_meta' => isset( $params['include_meta'] ) ? (bool) $params['include_meta'] : true,
			'include_schema' => isset( $params['include_schema'] ) ? (bool) $params['include_schema'] : false,
		];

		// Sanitize titles
		$titles = array_map( 'sanitize_text_field', $params['titles'] );
		$titles = array_filter( $titles ); // Remove empty titles

		if ( empty( $titles ) ) {
			return new \WP_Error(
				'no_valid_titles',
				'No valid titles provided',
				[ 'status' => 400 ]
			);
		}

		// Schedule batch using BatchScheduler
		if ( ! class_exists( 'AEBG\\Core\\BatchScheduler' ) ) {
			return new \WP_Error(
				'batch_scheduler_not_found',
				'BatchScheduler class not found',
				[ 'status' => 500 ]
			);
		}

		$batch_scheduler = new BatchScheduler();
		$batch_id = $batch_scheduler->schedule_batch( $settings, $titles );

		if ( is_wp_error( $batch_id ) ) {
			return $batch_id;
		}

		Logger::info( 'Generator V2 batch scheduled', [
			'batch_id' => $batch_id,
			'num_titles' => count( $titles ),
			'settings' => $settings,
		] );

		return rest_ensure_response( [
			'batch_id' => $batch_id,
			'message' => 'Batch scheduled successfully',
			'num_posts' => count( $titles ),
		] );
	}
}

