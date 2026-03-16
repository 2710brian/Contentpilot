<?php

namespace AEBG\API;

use AEBG\Core\UsageTracker;

/**
 * Dashboard Controller Class
 *
 * Handles REST API endpoints for the activity dashboard
 *
 * @package AEBG\API
 */
class DashboardController extends \WP_REST_Controller {
	/**
	 * DashboardController constructor.
	 */
	public function __construct() {
		// Ensure dashboard tables exist before registering routes
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureDashboardTables();
		}
		
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			'aebg/v1',
			'/dashboard/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'get_stats_permissions_check' ],
				'args'                => [
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'user_id'  => [
						'sanitize_callback' => 'absint',
					],
					'group_by' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/dashboard/token-usage',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_token_usage' ],
				'permission_callback' => [ $this, 'get_stats_permissions_check' ],
				'args'                => [
					'page'      => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page'  => [
						'default'           => 50,
						'sanitize_callback' => 'absint',
					],
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'user_id'  => [
						'sanitize_callback' => 'absint',
					],
					'model'     => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'batch_id'  => [
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/dashboard/cost-breakdown',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_cost_breakdown' ],
				'permission_callback' => [ $this, 'get_stats_permissions_check' ],
				'args'                => [
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'user_id'  => [
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/dashboard/product-replacements',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_product_replacements' ],
				'permission_callback' => [ $this, 'get_stats_permissions_check' ],
				'args'                => [
					'page'      => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page'  => [
						'default'           => 50,
						'sanitize_callback' => 'absint',
					],
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_id'   => [
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/dashboard/activity',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_activity' ],
				'permission_callback' => [ $this, 'get_stats_permissions_check' ],
				'args'                => [
					'page'      => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page'  => [
						'default'           => 50,
						'sanitize_callback' => 'absint',
					],
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type'      => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/dashboard/generations',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_generations' ],
				'permission_callback' => [ $this, 'get_stats_permissions_check' ],
				'args'                => [
					'page'      => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page'  => [
						'default'           => 50,
						'sanitize_callback' => 'absint',
					],
					'date_from' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'    => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Check if a given request has access to get stats.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return bool|\WP_Error
	 */
	public function get_stats_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_stats( $request ) {
		global $wpdb;

		$date_from = $request->get_param( 'date_from' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to   = $request->get_param( 'date_to' ) ?: date( 'Y-m-d' );
		$user_id   = $request->get_param( 'user_id' );
		$group_by  = $request->get_param( 'group_by' ) ?: 'daily';

		// Get period data
		$period_data = $this->get_period_data( $date_from, $date_to, $user_id );

		// Get previous period for comparison
		$days_diff = ( strtotime( $date_to ) - strtotime( $date_from ) ) / DAY_IN_SECONDS;
		$prev_date_to = date( 'Y-m-d', strtotime( $date_from . ' -1 day' ) );
		$prev_date_from = date( 'Y-m-d', strtotime( $prev_date_to . ' -' . $days_diff . ' days' ) );
		$previous_period_data = $this->get_period_data( $prev_date_from, $prev_date_to, $user_id );

		// Calculate efficiency metrics
		$efficiency = UsageTracker::calculate_efficiency_metrics( $period_data );
		$comparison = UsageTracker::compare_periods( $period_data, $previous_period_data );

		// Get trends
		$trends = $this->get_trends( $date_from, $date_to, $user_id, $group_by );

		// Get breakdowns
		$breakdowns = $this->get_breakdowns( $date_from, $date_to, $user_id );

		// Calculate total cost including image costs
		$total_cost_with_images = $period_data['total_cost'] + $period_data['image_cost'];
		
		$data = [
			'period' => [
				'date_from' => $date_from,
				'date_to'   => $date_to,
			],
			'overview' => [
				'total_generations'        => $period_data['total_generations'],
				'successful_generations'   => $period_data['successful_generations'],
				'failed_generations'      => $period_data['failed_generations'],
				'success_rate'            => $period_data['total_generations'] > 0 
					? round( ( $period_data['successful_generations'] / $period_data['total_generations'] ) * 100, 2 )
					: 0,
				'total_cost'              => round( $total_cost_with_images, 2 ),
				'avg_cost_per_generation' => $efficiency['avg_cost_per_generation'],
				'avg_cost_per_batch'      => $period_data['total_batches'] > 0
					? round( $total_cost_with_images / $period_data['total_batches'], 2 )
					: 0,
				'total_tokens'            => $period_data['total_tokens'],
				'total_prompt_tokens'     => $period_data['total_prompt_tokens'],
				'total_completion_tokens' => $period_data['total_completion_tokens'],
				'avg_tokens_per_generation' => $period_data['total_generations'] > 0
					? round( $period_data['total_tokens'] / $period_data['total_generations'], 0 )
					: 0,
				'tokens_per_word'         => $efficiency['tokens_per_word'],
				'avg_generation_time'     => $period_data['avg_generation_time'],
				'product_replacements'    => $period_data['total_replacements'],
				'total_images'            => $period_data['total_images'],
				'image_cost'              => round( $period_data['image_cost'], 2 ),
				'cost_efficiency_trend'   => $comparison['trend'],
				'cost_change_percent'     => $comparison['change_percent'],
				'primary_model'           => $this->get_primary_model( $date_from, $date_to, $user_id ),
				'model_usage'             => $this->get_model_usage_breakdown( $date_from, $date_to, $user_id ),
			],
			'trends'    => $trends,
			'breakdowns' => $breakdowns,
			'comparison' => $comparison,
		];

		$response = new \WP_REST_Response( $data, 200 );
		$response->set_headers( [
			'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
		] );

		return $response;
	}

	/**
	 * Get period data.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @param int|null $user_id User ID filter.
	 * @return array Period data.
	 */
	private function get_period_data( $date_from, $date_to, $user_id = null ) {
		global $wpdb;

		$api_table = $wpdb->prefix . 'aebg_api_usage';
		$activity_table = $wpdb->prefix . 'aebg_generation_activity';
		$replacements_table = $wpdb->prefix . 'aebg_product_replacements';
		$batches_table = $wpdb->prefix . 'aebg_batches';

		$where_user = $user_id ? $wpdb->prepare( ' AND user_id = %d', $user_id ) : '';

		// API usage stats (exclude image generation - images don't use tokens)
		$api_query = "SELECT 
				SUM(total_cost) as total_cost,
				SUM(total_tokens) as total_tokens,
				SUM(prompt_tokens) as prompt_tokens,
				SUM(completion_tokens) as completion_tokens,
				COUNT(*) as total_requests
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s
			AND request_type != 'image_generation'";
		
		if ( $user_id ) {
			$api_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$api_stats = $wpdb->get_row( $wpdb->prepare(
			$api_query,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		), ARRAY_A );

		// Generation activity stats
		$activity_query = "SELECT 
				COUNT(*) as total_generations,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_generations,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_generations,
				AVG(duration_seconds) as avg_generation_time,
				SUM(content_length_words) as total_words
			FROM {$activity_table}
			WHERE started_at >= %s AND started_at <= %s";
		
		if ( $user_id ) {
			$activity_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$activity_stats = $wpdb->get_row( $wpdb->prepare(
			$activity_query,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		), ARRAY_A );

		// Product replacements
		$replacements_query = "SELECT COUNT(*) FROM {$replacements_table}
			WHERE created_at >= %s AND created_at <= %s";
		
		if ( $user_id ) {
			$replacements_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$replacements_count = $wpdb->get_var( $wpdb->prepare(
			$replacements_query,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		// Batches
		$batches_query = "SELECT COUNT(*) FROM {$batches_table}
			WHERE created_at >= %s AND created_at <= %s";
		
		if ( $user_id ) {
			$batches_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$batches_count = $wpdb->get_var( $wpdb->prepare(
			$batches_query,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		// Image generation stats
		$image_stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT 
				COUNT(*) as total_images,
				SUM(total_cost) as image_cost
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s 
			AND request_type = 'image_generation'",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		), ARRAY_A );
		
		if ( $user_id ) {
			$image_query = $wpdb->prepare(
				"SELECT 
					COUNT(*) as total_images,
					SUM(total_cost) as image_cost
				FROM {$api_table}
				WHERE created_at >= %s AND created_at <= %s 
				AND request_type = 'image_generation'
				AND user_id = %d",
				$date_from . ' 00:00:00',
				$date_to . ' 23:59:59',
				$user_id
			);
			$image_stats = $wpdb->get_row( $image_query, ARRAY_A );
		}
		
		return [
			'total_cost'              => (float) ( $api_stats['total_cost'] ?? 0 ),
			'total_tokens'            => (int) ( $api_stats['total_tokens'] ?? 0 ),
			'total_prompt_tokens'     => (int) ( $api_stats['prompt_tokens'] ?? 0 ),
			'total_completion_tokens' => (int) ( $api_stats['completion_tokens'] ?? 0 ),
			'total_requests'          => (int) ( $api_stats['total_requests'] ?? 0 ),
			'total_images'            => (int) ( $image_stats['total_images'] ?? 0 ),
			'image_cost'              => (float) ( $image_stats['image_cost'] ?? 0 ),
			'total_generations'       => (int) ( $activity_stats['total_generations'] ?? 0 ),
			'successful_generations'  => (int) ( $activity_stats['successful_generations'] ?? 0 ),
			'failed_generations'      => (int) ( $activity_stats['failed_generations'] ?? 0 ),
			'avg_generation_time'     => (float) ( $activity_stats['avg_generation_time'] ?? 0 ),
			'total_words'             => (int) ( $activity_stats['total_words'] ?? 0 ),
			'total_replacements'      => (int) $replacements_count,
			'total_batches'           => (int) $batches_count,
		];
	}

	/**
	 * Get trends data.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @param int|null $user_id User ID filter.
	 * @param string $group_by Group by (daily, weekly, monthly).
	 * @return array Trends data.
	 */
	private function get_trends( $date_from, $date_to, $user_id = null, $group_by = 'daily' ) {
		global $wpdb;

		$api_table = $wpdb->prefix . 'aebg_api_usage';
		$activity_table = $wpdb->prefix . 'aebg_generation_activity';

		$date_format = $group_by === 'daily' ? '%Y-%m-%d' : ( $group_by === 'weekly' ? '%Y-%u' : '%Y-%m' );

		// Cost trends (include all request types including image generation)
		$cost_query = "SELECT 
				DATE_FORMAT(created_at, %s) as date,
				SUM(total_cost) as cost,
				COUNT(*) as requests
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s";
		
		if ( $user_id ) {
			$cost_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$cost_query .= " GROUP BY DATE_FORMAT(created_at, %s) ORDER BY date ASC";
		
		$cost_trends = $wpdb->get_results( $wpdb->prepare(
			$cost_query,
			$date_format,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
			$date_format
		), ARRAY_A );

		// Token trends
		$token_query = "SELECT 
				DATE_FORMAT(created_at, %s) as date,
				SUM(total_tokens) as tokens
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s";
		
		if ( $user_id ) {
			$token_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$token_query .= " GROUP BY DATE_FORMAT(created_at, %s) ORDER BY date ASC";
		
		$token_trends = $wpdb->get_results( $wpdb->prepare(
			$token_query,
			$date_format,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
			$date_format
		), ARRAY_A );

		// Generation trends
		$generation_query = "SELECT 
				DATE_FORMAT(started_at, %s) as date,
				COUNT(*) as generations,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM {$activity_table}
			WHERE started_at >= %s AND started_at <= %s";
		
		if ( $user_id ) {
			$generation_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$generation_query .= " GROUP BY DATE_FORMAT(started_at, %s) ORDER BY date ASC";
		
		$generation_trends = $wpdb->get_results( $wpdb->prepare(
			$generation_query,
			$date_format,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
			$date_format
		), ARRAY_A );

		// Average cost per generation trend
		// Create a map of costs by date for faster lookup
		$cost_by_date = [];
		foreach ( $cost_trends as $cost ) {
			$cost_by_date[ $cost['date'] ] = (float) $cost['cost'];
		}
		
		$avg_cost_trends = [];
		foreach ( $generation_trends as $gen ) {
			$date = $gen['date'];
			$cost_for_date = isset( $cost_by_date[ $date ] ) ? $cost_by_date[ $date ] : 0;
			$generations = (int) ( $gen['generations'] ?? 0 );
			
			$avg_cost_trends[] = [
				'date' => $date,
				'avg_cost_per_gen' => $generations > 0 ? round( $cost_for_date / $generations, 4 ) : 0,
			];
		}

		return [
			'generations' => $generation_trends,
			'costs'       => $cost_trends,
			'tokens'      => $token_trends,
			'avg_cost_per_generation' => $avg_cost_trends,
		];
	}

	/**
	 * Get breakdowns data.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @param int|null $user_id User ID filter.
	 * @return array Breakdowns data.
	 */
	private function get_breakdowns( $date_from, $date_to, $user_id = null ) {
		global $wpdb;

		$api_table = $wpdb->prefix . 'aebg_api_usage';
		$activity_table = $wpdb->prefix . 'aebg_generation_activity';

		// By model
		$model_query = "SELECT 
				model,
				SUM(total_cost) as cost,
				SUM(total_tokens) as tokens,
				COUNT(*) as requests
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s";
		
		if ( $user_id ) {
			$model_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$model_query .= " GROUP BY model ORDER BY cost DESC";
		
		$by_model = $wpdb->get_results( $wpdb->prepare(
			$model_query,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		), ARRAY_A );

		// Calculate avg cost per request for each model
		foreach ( $by_model as &$model ) {
			$model['avg_cost_per_request'] = $model['requests'] > 0 
				? round( $model['cost'] / $model['requests'], 4 )
				: 0;
		}

		// By user
		$user_query = "SELECT 
				user_id,
				SUM(total_cost) as cost,
				COUNT(*) as generations
			FROM {$activity_table}
			WHERE started_at >= %s AND started_at <= %s";
		
		if ( $user_id ) {
			$user_query .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		}
		
		$user_query .= " GROUP BY user_id ORDER BY cost DESC LIMIT 10";
		
		$by_user = $wpdb->get_results( $wpdb->prepare(
			$user_query,
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		), ARRAY_A );

		// Add user names
		foreach ( $by_user as &$user ) {
			$user_data = get_userdata( $user['user_id'] );
			$user['user_name'] = $user_data ? $user_data->display_name : 'Unknown';
			$user['avg_cost_per_generation'] = $user['generations'] > 0
				? round( $user['cost'] / $user['generations'], 4 )
				: 0;
		}

		return [
			'by_model' => $by_model,
			'by_user'  => $by_user,
		];
	}

	/**
	 * Get token usage data.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_token_usage( $request ) {
		global $wpdb;

		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		$user_id   = $request->get_param( 'user_id' );
		$model     = $request->get_param( 'model' );
		$batch_id  = $request->get_param( 'batch_id' );

		$offset = ( $page - 1 ) * $per_page;
		$table_name = $wpdb->prefix . 'aebg_api_usage';

		$where = [ '1=1' ];
		$params = [];

		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		if ( $model ) {
			$where[]  = 'model = %s';
			$params[] = $model;
		}

		if ( $batch_id ) {
			$where[]  = 'batch_id = %d';
			$params[] = $batch_id;
		}

		$where_clause = implode( ' AND ', $where );

		// Get total count
		$total_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
		if ( ! empty( $params ) ) {
			$total_query = $wpdb->prepare( $total_query, $params );
		}
		$total = (int) $wpdb->get_var( $total_query );

		// Get data
		$data_query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$data_query = $wpdb->prepare( $data_query, $params );

		$results = $wpdb->get_results( $data_query, ARRAY_A );

		$data = [];
		foreach ( $results as $row ) {
			$data[] = [
				'id'               => (int) $row['id'],
				'timestamp'        => $row['created_at'],
				'model'            => $row['model'],
				'prompt_tokens'    => (int) $row['prompt_tokens'],
				'completion_tokens' => (int) $row['completion_tokens'],
				'total_tokens'     => (int) $row['total_tokens'],
				'cost'             => (float) $row['total_cost'],
				'batch_id'         => $row['batch_id'] ? (int) $row['batch_id'] : null,
				'post_id'          => $row['post_id'] ? (int) $row['post_id'] : null,
				'user_id'          => (int) $row['user_id'],
			];
		}

		$response = new \WP_REST_Response( [
			'data'     => $data,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		], 200 );

		return $response;
	}

	/**
	 * Get cost breakdown.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_cost_breakdown( $request ) {
		$date_from = $request->get_param( 'date_from' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) );
		$date_to   = $request->get_param( 'date_to' ) ?: date( 'Y-m-d' );
		$user_id   = $request->get_param( 'user_id' );

		$period_data = $this->get_period_data( $date_from, $date_to, $user_id );
		$breakdowns = $this->get_breakdowns( $date_from, $date_to, $user_id );

		// Calculate period cost
		$days = ( strtotime( $date_to ) - strtotime( $date_from ) ) / DAY_IN_SECONDS;
		$daily_avg = $days > 0 ? $period_data['total_cost'] / $days : 0;
		$estimated_monthly = $daily_avg * 30;

		$data = [
			'total_cost'        => round( $period_data['total_cost'], 2 ),
			'period_cost'       => round( $period_data['total_cost'], 2 ),
			'avg_cost_per_generation' => $period_data['total_generations'] > 0
				? round( $period_data['total_cost'] / $period_data['total_generations'], 4 )
				: 0,
			'cost_per_token'    => $period_data['total_tokens'] > 0
				? round( $period_data['total_cost'] / $period_data['total_tokens'], 6 )
				: 0,
			'by_model'          => $breakdowns['by_model'],
			'by_user'           => $breakdowns['by_user'],
			'efficiency_trend'   => 'improving', // TODO: Calculate actual trend
		];

		$response = new \WP_REST_Response( $data, 200 );
		return $response;
	}

	/**
	 * Get product replacements.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_product_replacements( $request ) {
		global $wpdb;

		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		$post_id   = $request->get_param( 'post_id' );

		$offset = ( $page - 1 ) * $per_page;
		$table_name = $wpdb->prefix . 'aebg_product_replacements';

		$where = [ '1=1' ];
		$params = [];

		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		if ( $post_id ) {
			$where[]  = 'post_id = %d';
			$params[] = $post_id;
		}

		$where_clause = implode( ' AND ', $where );

		// Get total count
		$total_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
		if ( ! empty( $params ) ) {
			$total_query = $wpdb->prepare( $total_query, $params );
		}
		$total = (int) $wpdb->get_var( $total_query );

		// Get data
		$data_query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$data_query = $wpdb->prepare( $data_query, $params );

		$results = $wpdb->get_results( $data_query, ARRAY_A );

		$data = [];
		foreach ( $results as $row ) {
			$post = get_post( $row['post_id'] );
			$user = get_userdata( $row['user_id'] );

			$data[] = [
				'id'              => (int) $row['id'],
				'post_id'        => (int) $row['post_id'],
				'post_title'     => $post ? $post->post_title : 'Unknown',
				'old_product_name' => $row['old_product_name'],
				'new_product_name' => $row['new_product_name'],
				'product_number' => $row['product_number'] ? (int) $row['product_number'] : null,
				'user_id'        => (int) $row['user_id'],
				'user_name'      => $user ? $user->display_name : 'Unknown',
				'created_at'     => $row['created_at'],
			];
		}

		$response = new \WP_REST_Response( [
			'data'     => $data,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		], 200 );

		return $response;
	}

	/**
	 * Get activity feed.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_activity( $request ) {
		global $wpdb;

		$date_from = $request->get_param( 'date_from' ) ?: date( 'Y-m-d', strtotime( '-7 days' ) );
		$date_to   = $request->get_param( 'date_to' ) ?: date( 'Y-m-d' );
		$type      = $request->get_param( 'type' );
		$per_page  = (int) $request->get_param( 'per_page' ) ?: 50;

		$activity = [];

		// Get generations
		if ( ! $type || $type === 'generation' ) {
			$activity_table = $wpdb->prefix . 'aebg_generation_activity';
			$generations = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$activity_table}
				WHERE started_at >= %s AND started_at <= %s
				ORDER BY started_at DESC
				LIMIT %d",
				$date_from . ' 00:00:00',
				$date_to . ' 23:59:59',
				$per_page
			), ARRAY_A );

			foreach ( $generations as $gen ) {
				$post = $gen['post_id'] ? get_post( $gen['post_id'] ) : null;
				$user = get_userdata( $gen['user_id'] );

				$activity[] = [
					'id'         => 'gen_' . $gen['id'],
					'type'       => 'generation',
					'status'     => $gen['status'],
					'post_id'    => $gen['post_id'] ? (int) $gen['post_id'] : null,
					'post_title' => $post ? $post->post_title : 'Unknown',
					'user_id'    => (int) $gen['user_id'],
					'user_name'  => $user ? $user->display_name : 'Unknown',
					'duration'   => $gen['duration_seconds'] ? round( $gen['duration_seconds'], 2 ) : null,
					'tokens'     => $gen['total_tokens'] ? (int) $gen['total_tokens'] : null,
					'cost'       => $gen['total_cost'] ? round( $gen['total_cost'], 4 ) : null,
					'timestamp'  => $gen['started_at'],
				];
			}
		}

		// Get product replacements
		if ( ! $type || $type === 'product_replacement' ) {
			$replacements_table = $wpdb->prefix . 'aebg_product_replacements';
			$replacements = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$replacements_table}
				WHERE created_at >= %s AND created_at <= %s
				ORDER BY created_at DESC
				LIMIT %d",
				$date_from . ' 00:00:00',
				$date_to . ' 23:59:59',
				$per_page
			), ARRAY_A );

			foreach ( $replacements as $rep ) {
				$post = get_post( $rep['post_id'] );
				$user = get_userdata( $rep['user_id'] );

				$activity[] = [
					'id'            => 'rep_' . $rep['id'],
					'type'          => 'product_replacement',
					'post_id'       => (int) $rep['post_id'],
					'post_title'    => $post ? $post->post_title : 'Unknown',
					'old_product'   => $rep['old_product_name'],
					'new_product'   => $rep['new_product_name'],
					'user_id'       => (int) $rep['user_id'],
					'user_name'     => $user ? $user->display_name : 'Unknown',
					'timestamp'     => $rep['created_at'],
				];
			}
		}

		// Sort by timestamp
		usort( $activity, function( $a, $b ) {
			return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
		} );

		$response = new \WP_REST_Response( [
			'data' => array_slice( $activity, 0, $per_page ),
		], 200 );

		return $response;
	}

	/**
	 * Get generations data for table.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_generations( $request ) {
		global $wpdb;

		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		$status    = $request->get_param( 'status' );

		$offset = ( $page - 1 ) * $per_page;
		$table_name = $wpdb->prefix . 'aebg_generation_activity';

		// Check if table exists - try to query it
		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;
		
		if ( ! $table_exists ) {
			// Try to create the table
			if ( class_exists( 'AEBG\\Installer' ) ) {
				\AEBG\Installer::ensureGenerationActivityTable();
				// Check again
				$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;
			}
			
			if ( ! $table_exists ) {
				// Table doesn't exist, return empty response
				return new \WP_REST_Response( [
					'data'     => [],
					'total'    => 0,
					'page'     => $page,
					'per_page' => $per_page,
					'error'    => 'Table does not exist. Please ensure the plugin is properly installed.',
				], 200 );
			}
		}

		$where = [];
		$params = [];

		if ( $date_from && ! empty( trim( $date_from ) ) ) {
			$where[]  = 'started_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}

		if ( $date_to && ! empty( trim( $date_to ) ) ) {
			$where[]  = 'started_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		if ( $status && ! empty( trim( $status ) ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Get total count
		$total_query = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";
		if ( ! empty( $params ) ) {
			$total_query = $wpdb->prepare( $total_query, $params );
		}
		$total = (int) $wpdb->get_var( $total_query );

		// Get data
		$data_query = "SELECT * FROM {$table_name} {$where_clause} ORDER BY started_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, [ $per_page, $offset ] );
		$data_query = $wpdb->prepare( $data_query, $query_params );

		$results = $wpdb->get_results( $data_query, ARRAY_A );

		$data = [];
		foreach ( $results as $row ) {
			$post = $row['post_id'] ? get_post( $row['post_id'] ) : null;
			$user = get_userdata( $row['user_id'] );

			$data[] = [
				'id'               => (int) $row['id'],
				'post_id'          => $row['post_id'] ? (int) $row['post_id'] : null,
				'post_title'       => $post ? $post->post_title : 'Unknown',
				'status'           => $row['status'],
				'duration_seconds' => $row['duration_seconds'] ? round( $row['duration_seconds'], 2 ) : null,
				'total_cost'       => $row['total_cost'] ? round( $row['total_cost'], 4 ) : 0,
				'total_tokens'     => $row['total_tokens'] ? (int) $row['total_tokens'] : 0,
				'user_id'          => (int) $row['user_id'],
				'user_name'        => $user ? $user->display_name : 'Unknown',
				'started_at'       => $row['started_at'],
			];
		}

		$response = new \WP_REST_Response( [
			'data'     => $data,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		], 200 );

		return $response;
	}

	/**
	 * Get primary model (most used model).
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @param int|null $user_id User ID filter.
	 * @return string Primary model name.
	 */
	private function get_primary_model( $date_from, $date_to, $user_id = null ) {
		global $wpdb;

		$api_table = $wpdb->prefix . 'aebg_api_usage';

		$query = "SELECT model, COUNT(*) as usage_count
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s
			AND request_type != 'image_generation'";

		$params = [
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
		];

		if ( $user_id ) {
			$query .= ' AND user_id = %d';
			$params[] = $user_id;
		}

		$query .= " GROUP BY model ORDER BY usage_count DESC LIMIT 1";

		$result = $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A );

		return $result ? $result['model'] : 'N/A';
	}

	/**
	 * Get model usage breakdown.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @param int|null $user_id User ID filter.
	 * @return array Model usage breakdown.
	 */
	private function get_model_usage_breakdown( $date_from, $date_to, $user_id = null ) {
		global $wpdb;

		$api_table = $wpdb->prefix . 'aebg_api_usage';

		$query = "SELECT 
				model,
				request_type,
				COUNT(*) as request_count,
				SUM(total_tokens) as total_tokens,
				SUM(total_cost) as total_cost
			FROM {$api_table}
			WHERE created_at >= %s AND created_at <= %s";

		$params = [
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59',
		];

		if ( $user_id ) {
			$query .= ' AND user_id = %d';
			$params[] = $user_id;
		}

		$query .= " GROUP BY model, request_type ORDER BY total_cost DESC";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		$breakdown = [];
		foreach ( $results as $row ) {
			$model = $row['model'];
			$type = $row['request_type'];
			
			if ( ! isset( $breakdown[ $model ] ) ) {
				$breakdown[ $model ] = [
					'model' => $model,
					'total_requests' => 0,
					'total_tokens' => 0,
					'total_cost' => 0,
					'by_type' => [],
				];
			}
			
			$breakdown[ $model ]['total_requests'] += (int) $row['request_count'];
			$breakdown[ $model ]['total_tokens'] += (int) $row['total_tokens'];
			$breakdown[ $model ]['total_cost'] += (float) $row['total_cost'];
			$breakdown[ $model ]['by_type'][ $type ] = [
				'requests' => (int) $row['request_count'],
				'tokens' => (int) $row['total_tokens'],
				'cost' => (float) $row['total_cost'],
			];
		}

		// Convert to array and sort by total cost
		$breakdown = array_values( $breakdown );
		usort( $breakdown, function( $a, $b ) {
			return $b['total_cost'] <=> $a['total_cost'];
		} );

		return $breakdown;
	}
}

