<?php

namespace AEBG\API;

/**
 * Competitor Tracking Controller Class
 *
 * Handles REST API endpoints for competitor tracking
 *
 * @package AEBG\API
 */
class CompetitorTrackingController extends \WP_REST_Controller {
	/**
	 * CompetitorTrackingController constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			'aebg/v1',
			'/competitors',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_competitors' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'page'     => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page' => [
						'default'           => 20,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/competitors',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_competitor' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_competitor' ],
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

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_competitor' ],
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

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_competitor' ],
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

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)/scrapes',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_scrapes' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'id'      => [
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					],
					'per_page' => [
						'default'           => 20,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)/products',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_products' ],
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

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)/changes',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_changes' ],
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

		register_rest_route(
			'aebg/v1',
			'/competitors/(?P<id>\d+)/scrape',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'trigger_scrape' ],
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
	}

	/**
	 * Check if a given request has access.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return bool|\WP_Error
	 */
	public function permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get all competitors.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_competitors( $request ) {
		global $wpdb;
		
		// Get all competitors (not just active) for admin display
		$competitors = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}aebg_competitors ORDER BY name ASC",
			ARRAY_A
		);
		
		// Decode JSON fields and ensure all fields are properly formatted
		foreach ( $competitors as &$competitor ) {
			$competitor_id = (int) $competitor['id'];
			
			if ( ! empty( $competitor['extraction_config'] ) ) {
				$competitor['extraction_config'] = json_decode( $competitor['extraction_config'], true );
			}
			// Ensure boolean fields are integers (for JSON compatibility)
			$competitor['is_active'] = (int) $competitor['is_active'];
			// Ensure numeric fields are integers
			$competitor['scraping_interval'] = (int) $competitor['scraping_interval'];
			$competitor['scrape_count'] = (int) $competitor['scrape_count'];
			$competitor['error_count'] = (int) $competitor['error_count'];
			
			// Check if there's a scrape in progress
			$processing_scrape = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_competitor_scrapes 
				WHERE competitor_id = %d AND status = 'processing'",
				$competitor_id
			) );
			$competitor['is_scraping'] = (int) $processing_scrape > 0;
			
			// Get product count from latest completed scrape
			$product_count = $wpdb->get_var( $wpdb->prepare(
				"SELECT product_count FROM {$wpdb->prefix}aebg_competitor_scrapes 
				WHERE competitor_id = %d AND status = 'completed' 
				ORDER BY id DESC LIMIT 1",
				$competitor_id
			) );
			$competitor['product_count'] = $product_count ? (int) $product_count : 0;
			
			// Get changes count (total changes for this competitor)
			$changes_count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_competitor_changes 
				WHERE competitor_id = %d",
				$competitor_id
			) );
			$competitor['changes_count'] = (int) $changes_count;
		}
		
		// WordPress REST API automatically serializes WP_REST_Response to JSON
		// The data array will be returned directly in the response body
		return rest_ensure_response( $competitors );
	}

	/**
	 * Create competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_competitor( $request ) {
		$params = $request->get_json_params();
		
		$name = sanitize_text_field( $params['name'] ?? '' );
		$url = esc_url_raw( $params['url'] ?? '' );
		$interval = absint( $params['scraping_interval'] ?? 3600 );
		
		if ( empty( $name ) || empty( $url ) ) {
			return new \WP_Error( 'missing_fields', __( 'Name and URL are required.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		$result = \AEBG\Core\CompetitorTrackingManager::add_competitor( get_current_user_id(), $name, $url, $interval );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		$competitor = \AEBG\Core\CompetitorTrackingManager::get_competitor( $result );
		
		$response = new \WP_REST_Response( $competitor, 201 );
		return $response;
	}

	/**
	 * Get competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_competitor( $request ) {
		$competitor_id = (int) $request['id'];
		$competitor = \AEBG\Core\CompetitorTrackingManager::get_competitor( $competitor_id );
		
		if ( ! $competitor ) {
			return new \WP_Error( 'not_found', __( 'Competitor not found.', 'aebg' ), [ 'status' => 404 ] );
		}
		
		$response = new \WP_REST_Response( $competitor, 200 );
		return $response;
	}

	/**
	 * Update competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_competitor( $request ) {
		$competitor_id = (int) $request['id'];
		$params = $request->get_json_params();
		
		$data = [];
		if ( isset( $params['name'] ) ) {
			$data['name'] = sanitize_text_field( $params['name'] );
		}
		if ( isset( $params['url'] ) ) {
			$data['url'] = esc_url_raw( $params['url'] );
		}
		if ( isset( $params['scraping_interval'] ) ) {
			$data['scraping_interval'] = absint( $params['scraping_interval'] );
		}
		if ( isset( $params['is_active'] ) ) {
			$data['is_active'] = (int) $params['is_active'];
		}
		
		$result = \AEBG\Core\CompetitorTrackingManager::update_competitor( $competitor_id, $data );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		$competitor = \AEBG\Core\CompetitorTrackingManager::get_competitor( $competitor_id );
		
		$response = new \WP_REST_Response( $competitor, 200 );
		return $response;
	}

	/**
	 * Delete competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_competitor( $request ) {
		$competitor_id = (int) $request['id'];
		
		$result = \AEBG\Core\CompetitorTrackingManager::delete_competitor( $competitor_id );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		$response = new \WP_REST_Response( [ 'message' => __( 'Competitor deleted successfully.', 'aebg' ) ], 200 );
		return $response;
	}

	/**
	 * Get scrapes for competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_scrapes( $request ) {
		global $wpdb;
		
		$competitor_id = (int) $request['id'];
		$per_page = absint( $request['per_page'] ?? 20 );
		
		$scrapes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d 
			ORDER BY created_at DESC 
			LIMIT %d",
			$competitor_id,
			$per_page
		), ARRAY_A );
		
		$response = new \WP_REST_Response( $scrapes, 200 );
		return $response;
	}

	/**
	 * Get products for competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_products( $request ) {
		global $wpdb;
		
		$competitor_id = (int) $request['id'];
		
		// Get latest scrape ID
		$latest_scrape_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d AND status = 'completed' 
			ORDER BY id DESC LIMIT 1",
			$competitor_id
		) );
		
		if ( ! $latest_scrape_id ) {
			return new \WP_REST_Response( [], 200 );
		}
		
		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitor_products 
			WHERE competitor_id = %d AND scrape_id = %d 
			ORDER BY position ASC",
			$competitor_id,
			$latest_scrape_id
		), ARRAY_A );
		
		$response = new \WP_REST_Response( $products, 200 );
		return $response;
	}

	/**
	 * Get changes for competitor.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_changes( $request ) {
		global $wpdb;
		
		$competitor_id = (int) $request['id'];
		
		$changes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitor_changes 
			WHERE competitor_id = %d 
			ORDER BY created_at DESC 
			LIMIT 50",
			$competitor_id
		), ARRAY_A );
		
		$response = new \WP_REST_Response( $changes, 200 );
		return $response;
	}

	/**
	 * Trigger manual scrape.
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function trigger_scrape( $request ) {
		$competitor_id = (int) $request['id'];
		
		// Get competitor to check if it exists
		$competitor = \AEBG\Core\CompetitorTrackingManager::get_competitor( $competitor_id );
		if ( ! $competitor ) {
			return new \WP_Error( 'competitor_not_found', __( 'Competitor not found.', 'aebg' ), [ 'status' => 404 ] );
		}
		
		// For manual triggers, we want immediate execution (delay = 0)
		// Use enqueue_immediate_action for instant processing
		$action_id = \AEBG\Core\CompetitorTrackingManager::trigger_manual_scrape( $competitor_id );
		
		if ( ! $action_id ) {
			// Log the error for debugging
			\AEBG\Core\Logger::error( 'Failed to trigger manual scrape', [
				'competitor_id' => $competitor_id,
				'competitor' => $competitor,
			] );
			return new \WP_Error( 'schedule_failed', __( 'Failed to schedule scrape. Please check error logs.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		$response = new \WP_REST_Response( [ 
			'message' => __( 'Scrape scheduled successfully.', 'aebg' ),
			'action_id' => $action_id,
		], 200 );
		return $response;
	}
}

