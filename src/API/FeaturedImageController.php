<?php

namespace AEBG\API;

use AEBG\Core\ImageProcessor;

/**
 * Featured Image Controller Class
 *
 * Handles REST API endpoints for featured image regeneration.
 *
 * @package AEBG\API
 */
class FeaturedImageController extends \WP_REST_Controller {

	/**
	 * FeaturedImageController constructor.
	 */
	public function __construct() {
		$this->namespace = 'aebg/v1';
		$this->rest_base = 'featured-image';
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		// Regenerate image endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/regenerate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'regenerate_image' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => function( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					],
					'style' => [
						'required' => false,
						'type'     => 'string',
						'default'   => 'realistic photo',
					],
					'image_model' => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'dall-e-3',
						'enum'     => [ 'dall-e-3', 'dall-e-2' ],
					],
					'image_size' => [
						'required' => false,
						'type'     => 'string',
						'default'  => '1024x1024',
						'enum'     => [ '1024x1024', '1792x1024', '1024x1792' ],
					],
					'image_quality' => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'standard',
						'enum'     => [ 'standard', 'hd' ],
					],
					'custom_prompt' => [
						'required' => false,
						'type'     => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'use_custom_prompt' => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);

		// Get status endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status/(?P<job_id>[a-zA-Z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'job_id' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		// Estimate cost and time endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/estimate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'estimate_cost_time' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'image_model' => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'dall-e-3',
						'enum'     => [ 'dall-e-3', 'dall-e-2' ],
					],
					'image_quality' => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'standard',
						'enum'     => [ 'standard', 'hd' ],
					],
					'image_size' => [
						'required' => false,
						'type'     => 'string',
						'default'  => '1024x1024',
						'enum'     => [ '1024x1024', '1792x1024', '1024x1792' ],
					],
				],
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
		// Allow admins even if capability not set
		if ( ! current_user_can( 'aebg_generate_content' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'aebg' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Schedule featured image regeneration via Action Scheduler.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function regenerate_image( $request ) {
		$params = $request->get_json_params();

		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$style = isset( $params['style'] ) ? sanitize_text_field( $params['style'] ) : 'realistic photo';
		$image_model = isset( $params['image_model'] ) ? sanitize_text_field( $params['image_model'] ) : 'dall-e-3';
		$image_size = isset( $params['image_size'] ) ? sanitize_text_field( $params['image_size'] ) : '1024x1024';
		$image_quality = isset( $params['image_quality'] ) ? sanitize_text_field( $params['image_quality'] ) : 'standard';
		$custom_prompt = isset( $params['custom_prompt'] ) ? sanitize_textarea_field( $params['custom_prompt'] ) : null;
		$use_custom_prompt = isset( $params['use_custom_prompt'] ) ? (bool) $params['use_custom_prompt'] : false;

		// Validate post exists and user can edit
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'invalid_post', 'Invalid post or insufficient permissions', [ 'status' => 403 ] );
		}

		// Check if post has a title
		if ( empty( $post->post_title ) ) {
			return new \WP_Error( 'no_title', 'Post must have a title to generate featured image', [ 'status' => 400 ] );
		}

		// Get settings
		$settings = get_option( 'aebg_settings', [] );
		$api_key = $settings['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', 'OpenAI API key not configured', [ 'status' => 400 ] );
		}

		// Generate unique job ID
		$job_id = 'fi_' . time() . '_' . wp_generate_password( 8, false );

		// Store job data in transient
		$job_data = [
			'post_id'           => $post_id,
			'style'             => $style,
			'image_model'       => $image_model,
			'image_size'        => $image_size,
			'image_quality'     => $image_quality,
			'custom_prompt'     => $custom_prompt,
			'use_custom_prompt' => $use_custom_prompt,
			'created_at'        => time(),
		];

		set_transient( 'aebg_featured_image_job_' . $job_id, $job_data, HOUR_IN_SECONDS );

		// Initialize status
		$this->update_job_status( $job_id, 'pending', 0, 'Scheduled for processing...' );

		// Schedule Action Scheduler action (non-blocking)
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new \WP_Error( 'action_scheduler_unavailable', 'Action Scheduler is not available', [ 'status' => 500 ] );
		}

		$action_id = as_schedule_single_action(
			time(), // Run immediately
			'aebg_regenerate_featured_image',
			[ $job_id ],
			'aebg_featured_image',
			true // Unique - prevents duplicates
		);

		if ( ! $action_id ) {
			return new \WP_Error( 'schedule_failed', 'Failed to schedule regeneration', [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'job_id'    => $job_id,
				'action_id' => $action_id,
				'status'    => 'pending',
				'message'   => 'Regeneration scheduled. Processing will begin shortly...',
			],
		] );
	}

	/**
	 * Get regeneration status.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$job_id = $request->get_param( 'job_id' );

		$status_data = get_transient( 'aebg_featured_image_status_' . $job_id );

		if ( ! $status_data ) {
			return new \WP_Error( 'job_not_found', 'Job not found', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => $status_data,
		] );
	}

	/**
	 * Estimate cost and time for image generation.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function estimate_cost_time( $request ) {
		$params = $request->get_json_params();

		$image_model = isset( $params['image_model'] ) ? sanitize_text_field( $params['image_model'] ) : 'dall-e-3';
		$image_quality = isset( $params['image_quality'] ) ? sanitize_text_field( $params['image_quality'] ) : 'standard';
		$image_size = isset( $params['image_size'] ) ? sanitize_text_field( $params['image_size'] ) : '1024x1024';

		// Reuse calculation methods (duplicated from GeneratorV2Controller)
		$cost_per_image = $this->calculate_image_cost( 1, $image_model, $image_quality );
		$time_seconds = $this->estimate_image_time( $image_model, $image_quality );

		// Adjust time for larger images
		if ( $image_size === '1792x1024' || $image_size === '1024x1792' ) {
			$time_seconds += 3;
		}

		// Format time
		$time_formatted = $time_seconds < 60
			? '~' . $time_seconds . ' seconds'
			: '~' . ceil( $time_seconds / 60 ) . ' minutes';

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'cost_per_image'         => $cost_per_image,
				'estimated_time_seconds' => $time_seconds,
				'estimated_time_formatted' => $time_formatted,
				'model'                  => $image_model,
				'quality'                => $image_quality,
				'size'                   => $image_size,
			],
		] );
	}

	/**
	 * Calculate image generation cost.
	 * Duplicated from GeneratorV2Controller for reuse.
	 *
	 * @param int    $num_images Number of images.
	 * @param string $image_model Image model (dall-e-3 or dall-e-2).
	 * @param string $quality Image quality (standard or hd).
	 * @return float
	 */
	private function calculate_image_cost( $num_images, $image_model, $quality ) {
		// DALL-E and Nano Banana pricing (as of 2024) - Reused from GeneratorV2Controller
		$costs = [
			'dall-e-3' => [
				'standard' => 0.04,
				'hd'       => 0.08,
			],
			'dall-e-2' => [
				'standard' => 0.02,
				'hd'       => 0.02, // DALL-E 2 doesn't have HD option
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
	 * Estimate image generation time.
	 * Duplicated from GeneratorV2Controller for reuse.
	 *
	 * @param string $image_model Image model.
	 * @param string $quality Image quality.
	 * @return int Time in seconds.
	 */
	private function estimate_image_time( $image_model, $quality ) {
		// Base time per image (seconds) - Reused from GeneratorV2Controller
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
	 * Update job status.
	 *
	 * @param string $job_id Job ID.
	 * @param string $status Status (pending, processing, completed, failed).
	 * @param int    $progress Progress percentage (0-100).
	 * @param string $message Status message.
	 * @param array  $data Additional data.
	 */
	private function update_job_status( $job_id, $status, $progress, $message, $data = [] ) {
		$status_data = [
			'status'     => $status,
			'progress'   => $progress,
			'message'    => $message,
			'updated_at' => time(),
		] + $data;

		set_transient( 'aebg_featured_image_status_' . $job_id, $status_data, HOUR_IN_SECONDS );
	}
}

