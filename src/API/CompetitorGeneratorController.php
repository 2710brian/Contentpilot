<?php

namespace AEBG\API;

use AEBG\Core\Generator;
use AEBG\Core\CompetitorProductFetcher;
use AEBG\Core\CompetitorProductConverter;
use AEBG\Core\CompetitorScraper;
use AEBG\Core\CompetitorAnalyzer;
use AEBG\Core\Datafeedr;
use AEBG\Core\Logger;
use AEBG\Core\ActionSchedulerHelper;
use AEBG\Core\StepHandler;
use AEBG\Core\CheckpointManager;

/**
 * Competitor Generator Controller Class
 *
 * Handles REST API endpoints for competitor-based post generation
 *
 * @package AEBG\API
 */
class CompetitorGeneratorController extends \WP_REST_Controller {
	
	/**
	 * CompetitorGeneratorController constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		// Scan competitor products endpoint
		register_rest_route(
			'aebg/v1',
			'/scan-competitor-products',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'scan_competitor_products' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'competitor_url' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function( $param ) {
							return filter_var( $param, FILTER_VALIDATE_URL ) !== false;
						},
						'sanitize_callback' => 'esc_url_raw',
					],
					'template_id'    => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => function( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => 'absint',
					],
					'scan_method'    => [
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => function( $param ) {
							return in_array( $param, [ 'scrape', 'ai_analysis' ], true );
						},
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'scrape',
					],
				],
			]
		);

		// Generate from competitor endpoint
		register_rest_route(
			'aebg/v1',
			'/scan-progress/(?P<id>[a-zA-Z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_scan_progress' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'id' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		register_rest_route(
			'aebg/v1',
			'/generate-from-competitor',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate_from_competitor' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'title'            => [
						'required' => true,
						'type'     => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'competitor_url'   => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function( $param ) {
							return filter_var( $param, FILTER_VALIDATE_URL ) !== false;
						},
						'sanitize_callback' => 'esc_url_raw',
					],
					'template_id'      => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => function( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => 'absint',
					],
					'approved_products' => [
						'required' => true,
						'type'     => 'array',
					],
					'settings'         => [
						'required' => false,
						'type'     => 'object',
						'default'  => [],
					],
				],
			]
		);

		// Find missing products via market analysis endpoint
		register_rest_route(
			'aebg/v1',
			'/find-missing-products',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'find_missing_products' ],
				'permission_callback' => [ $this, 'permissions_check' ],
				'args'                => [
					'found_products' => [
						'required' => true,
						'type'     => 'array',
					],
					'competitor_url' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function( $param ) {
							return filter_var( $param, FILTER_VALIDATE_URL ) !== false;
						},
						'sanitize_callback' => 'esc_url_raw',
					],
					'missing_count' => [
						'required' => true,
						'type'     => 'integer',
						'validate_callback' => function( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => 'absint',
					],
					'country' => [
						'required' => false,
						'type'     => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'  => 'Denmark',
					],
					'language' => [
						'required' => false,
						'type'     => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'  => 'Danish',
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
	 * Scan competitor products with progress tracking
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function scan_competitor_products( $request ) {
		$competitor_url = $request->get_param( 'competitor_url' );
		$template_id    = $request->get_param( 'template_id' );
		$scan_method    = $request->get_param( 'scan_method' ) ?? 'scrape';
		
		Logger::info( 'Scanning competitor products', [
			'url' => $competitor_url,
			'template_id' => $template_id,
			'scan_method' => $scan_method,
		] );
		
		// Validate template to determine required product count
		$generator = new Generator( [] );
		$template_validation = $generator->validateTemplateProductCount( $template_id, 0 );
		
		if ( is_wp_error( $template_validation ) ) {
			return $template_validation;
		}
		
		$required_count = $template_validation['required_count'] ?? 0;
		
		if ( $required_count <= 0 ) {
			return new \WP_Error( 
				'no_product_variables', 
				__( 'Template does not contain any product variables. Please use a template with {product-X} variables.', 'aebg' ),
				[ 'status' => 400 ]
			);
		}
		
		// Create progress tracking ID
		$progress_id = 'scan_' . wp_generate_password( 12, false );
		$progress_key = 'aebg_scan_progress_' . $progress_id;
		
		// Route to appropriate scan method
		if ( $scan_method === 'ai_analysis' ) {
			return $this->scan_with_ai_analysis( $competitor_url, $template_id, $required_count, $progress_key );
		} else {
			return $this->scan_with_scraping( $competitor_url, $template_id, $required_count, $progress_key );
		}
	}
	
	/**
	 * Scan using scraping method (current/default method)
	 *
	 * @param string $competitor_url Competitor URL
	 * @param int    $template_id Template ID
	 * @param int    $required_count Required product count
	 * @param string $progress_key Progress tracking key
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function scan_with_scraping( $competitor_url, $template_id, $required_count, $progress_key ) {
		// Initialize progress
		set_transient( $progress_key, [
			'progress' => 0,
			'step' => 'Initializing scan...',
			'message' => 'Preparing to fetch competitor page',
			'completed' => false,
			'error' => null,
		], 300 ); // 5 minute TTL
		
		// Step 1: Scrape URL (10-30%)
		set_transient( $progress_key, [
			'progress' => 10,
			'step' => 'Fetching page...',
			'message' => 'Connecting to competitor website',
			'completed' => false,
			'error' => null,
		], 300 );
		
		$scraper = new CompetitorScraper();
		$scraped_data = $scraper->scrape( $competitor_url );
		
		if ( is_wp_error( $scraped_data ) ) {
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'Failed to fetch page',
				'completed' => true,
				'error' => $scraped_data->get_error_message(),
			], 300 );
			return $scraped_data;
		}
		
		// Step 2: Analyzing with AI (30-80%)
		set_transient( $progress_key, [
			'progress' => 30,
			'step' => 'Analyzing content...',
			'message' => 'Extracting products using AI',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Pass required count to analyzer so it knows how many products to extract
		$analyzer = new CompetitorAnalyzer();
		$products = $analyzer->analyze( $scraped_data['content'], [
			'required_count' => $required_count,
		] );
		
		if ( is_wp_error( $products ) ) {
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'Failed to analyze content',
				'completed' => true,
				'error' => $products->get_error_message(),
			], 300 );
			return $products;
		}
		
		if ( empty( $products ) || ! is_array( $products ) ) {
			$error = new \WP_Error( 
				'no_products_found', 
				__( 'No products found on competitor page. Please check the URL and try again.', 'aebg' ),
				[ 'status' => 404 ]
			);
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'No products found',
				'completed' => true,
				'error' => 'No products found on competitor page',
			], 300 );
			return $error;
		}
		
		// Continue with product conversion and Datafeedr search (shared logic)
		return $this->process_products_for_display( $products, $required_count, $progress_key );
	}
	
	/**
	 * Scan using AI Analysis method (direct AI analysis with URL)
	 *
	 * @param string $competitor_url Competitor URL
	 * @param int    $template_id Template ID
	 * @param int    $required_count Required product count
	 * @param string $progress_key Progress tracking key
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function scan_with_ai_analysis( $competitor_url, $template_id, $required_count, $progress_key ) {
		// Initialize progress
		set_transient( $progress_key, [
			'progress' => 0,
			'step' => 'Initializing AI Analysis...',
			'message' => 'Preparing direct AI analysis',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Step 1: Fetch page content (10-20%)
		set_transient( $progress_key, [
			'progress' => 10,
			'step' => 'Fetching page...',
			'message' => 'Downloading page content for AI analysis',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Fetch page HTML (we still need to get the content, but send it more directly to AI)
		$scraper = new CompetitorScraper();
		$scraped_data = $scraper->scrape( $competitor_url );
		
		if ( is_wp_error( $scraped_data ) ) {
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'Failed to fetch page',
				'completed' => true,
				'error' => $scraped_data->get_error_message(),
			], 300 );
			return $scraped_data;
		}
		
		// Step 2: Direct AI Analysis (20-80%)
		set_transient( $progress_key, [
			'progress' => 20,
			'step' => 'AI Analysis...',
			'message' => 'Analyzing website directly with AI',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Use direct AI analysis method
		$analyzer = new CompetitorAnalyzer();
		$products = $analyzer->analyzeDirectly( $competitor_url, $scraped_data, [
			'required_count' => $required_count,
		] );
		
		if ( is_wp_error( $products ) ) {
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'Failed to analyze with AI',
				'completed' => true,
				'error' => $products->get_error_message(),
			], 300 );
			return $products;
		}
		
		if ( empty( $products ) || ! is_array( $products ) ) {
			$error = new \WP_Error( 
				'no_products_found', 
				__( 'No products found on competitor page. Please check the URL and try again.', 'aebg' ),
				[ 'status' => 404 ]
			);
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'No products found',
				'completed' => true,
				'error' => 'No products found on competitor page',
			], 300 );
			return $error;
		}
		
		// Continue with product conversion and Datafeedr search (shared logic)
		return $this->process_products_for_display( $products, $required_count, $progress_key );
	}
	
	/**
	 * Process products for display (shared between both scan methods)
	 * Handles conversion and Datafeedr search
	 *
	 * @param array  $products Extracted products
	 * @param int    $required_count Required product count
	 * @param string $progress_key Progress tracking key
	 * @return \WP_REST_Response
	 */
	private function process_products_for_display( $products, $required_count, $progress_key ) {
		// Step 3: Converting products (80-95%)
		set_transient( $progress_key, [
			'progress' => 80,
			'step' => 'Processing products...',
			'message' => 'Converting products to display format',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Convert products to generator format
		$converter = new CompetitorProductConverter();
		$converted_products = $converter->convertProducts( $products, 0 );
		
		if ( empty( $converted_products ) ) {
			$error = new \WP_Error( 
				'conversion_failed', 
				__( 'Failed to convert products to generator format.', 'aebg' ),
				[ 'status' => 500 ]
			);
			set_transient( $progress_key, [
				'progress' => 0,
				'step' => 'Error',
				'message' => 'Conversion failed',
				'completed' => true,
				'error' => 'Failed to convert products',
			], 300 );
			return $error;
		}
		
		// Step 4: Search Datafeedr for matching products (85-95%)
		set_transient( $progress_key, [
			'progress' => 85,
			'step' => 'Searching affiliate networks...',
			'message' => 'Finding products in Datafeedr',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Search Datafeedr for each product
		$datafeedr = new Datafeedr();
		$display_products = [];
		$total_products = count( $converted_products );
		
		foreach ( $converted_products as $index => $product ) {
			$product_name = $product['name'] ?? '';
			$datafeedr_match = null;
			
			if ( ! empty( $product_name ) ) {
				// Search Datafeedr for this product using the search method
				$search_results = $datafeedr->search( $product_name, 1 ); // Get top 1 match
				
				if ( ! is_wp_error( $search_results ) && ! empty( $search_results ) && is_array( $search_results ) ) {
					$match = $search_results[0];
					$datafeedr_match = [
						'name' => $match['name'] ?? '',
						'price' => $match['price'] ?? '',
						'affiliate_url' => $match['affiliate_url'] ?? $match['url'] ?? '',
						'network' => $match['network'] ?? $match['network_name'] ?? '',
						'merchant' => $match['merchant'] ?? $match['merchant_name'] ?? '',
						'image' => $match['image'] ?? $match['image_url'] ?? '',
					];
				}
			}
			
			$display_products[] = [
				'name' => $product_name,
				'price' => $product['price'] ?? '',
				'finalprice' => $product['finalprice'] ?? $product['final_price'] ?? $product['sale_price'] ?? '',
				'salediscount' => $product['salediscount'] ?? $product['discount'] ?? 0,
				'merchant' => $product['merchant'] ?? '',
				'affiliate_link' => $product['affiliate_url'] ?? $product['url'] ?? '',
				'network' => $product['network'] ?? '',
				'rating' => $product['rating'] ?? '',
				'description' => $product['description'] ?? '',
				'image' => $product['image'] ?? $product['image_url'] ?? '',
				'position' => $index + 1,
				'datafeedr_match' => $datafeedr_match,
				// Store full product data for later use
				'_full_data' => $product,
			];
			
			// Update progress
			$progress = 85 + ( ( $index + 1 ) / $total_products ) * 10;
			set_transient( $progress_key, [
				'progress' => min( 95, (int) $progress ),
				'step' => 'Searching affiliate networks...',
				'message' => sprintf( 'Found matches for %d of %d products', $index + 1, $total_products ),
				'completed' => false,
				'error' => null,
			], 300 );
		}
		
		// Step 5: Finalize (95-100%)
		set_transient( $progress_key, [
			'progress' => 95,
			'step' => 'Finalizing...',
			'message' => 'Preparing product list',
			'completed' => false,
			'error' => null,
		], 300 );
		
		// Mark as completed
		$found_count = count( $display_products );
		$has_shortage = $found_count < $required_count;
		$shortage_count = $has_shortage ? ( $required_count - $found_count ) : 0;
		
		set_transient( $progress_key, [
			'progress' => 100,
			'step' => 'Complete',
			'message' => 'Products extracted successfully',
			'completed' => true,
			'error' => null,
			'products' => $display_products,
			'required_count' => $required_count,
			'found_count' => $found_count,
			'has_shortage' => $has_shortage,
			'shortage_count' => $shortage_count,
		], 300 );
		
		$response = new \WP_REST_Response( [
			'success'       => true,
			'products'      => $display_products,
			'required_count' => $required_count,
			'found_count'   => $found_count,
			'has_shortage'  => $has_shortage,
			'shortage_count' => $shortage_count,
			'message'       => sprintf( 
				__( 'Found %d products. Template requires %d products.', 'aebg' ),
				$found_count,
				$required_count
			),
		], 200 );
		
		return $response;
	}

	/**
	 * Get scan progress
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_scan_progress( $request ) {
		$progress_id = $request->get_param( 'id' );
		$progress_key = 'aebg_scan_progress_' . $progress_id;
		
		$progress = get_transient( $progress_key );
		
		if ( false === $progress ) {
			return new \WP_Error( 
				'progress_not_found', 
				__( 'Progress not found. The scan may have expired.', 'aebg' ),
				[ 'status' => 404 ]
			);
		}
		
		$response = new \WP_REST_Response( [
			'success' => true,
			'progress' => $progress['progress'] ?? 0,
			'step' => $progress['step'] ?? 'Unknown',
			'message' => $progress['message'] ?? '',
			'completed' => $progress['completed'] ?? false,
			'error' => $progress['error'] ?? null,
			'products' => $progress['products'] ?? null,
			'required_count' => $progress['required_count'] ?? null,
			'found_count' => $progress['found_count'] ?? null,
		], 200 );
		
		return $response;
	}

	/**
	 * Generate post from approved competitor products using step-by-step method
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function generate_from_competitor( $request ) {
		global $wpdb;
		
		$title            = $request->get_param( 'title' );
		$competitor_url   = $request->get_param( 'competitor_url' );
		$template_id      = $request->get_param( 'template_id' );
		$approved_products = $request->get_param( 'approved_products' );
		$settings         = $request->get_param( 'settings' ) ?? [];
		
		Logger::info( 'Generating post from competitor products (step-by-step)', [
			'title' => $title,
			'url' => $competitor_url,
			'template_id' => $template_id,
			'products_count' => count( $approved_products ),
		] );
		
		// Validate inputs
		if ( empty( $title ) ) {
			return new \WP_Error( 'missing_title', __( 'Title is required.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		if ( empty( $approved_products ) || ! is_array( $approved_products ) ) {
			return new \WP_Error( 'missing_products', __( 'Approved products are required.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Extract full product data from approved products
		$products = [];
		foreach ( $approved_products as $product ) {
			if ( isset( $product['_full_data'] ) && is_array( $product['_full_data'] ) ) {
				$products[] = $product['_full_data'];
			} else {
				// If _full_data not available, reconstruct from display data
				$products[] = [
					'name' => $product['name'] ?? '',
					'short_name' => $product['name'] ?? '',
					'price' => $product['price'] ?? '',
					'url' => $product['affiliate_link'] ?? '',
					'affiliate_url' => $product['affiliate_link'] ?? '',
					'merchant' => $product['merchant'] ?? '',
					'network' => $product['network'] ?? '',
					'rating' => $product['rating'] ?? '',
					'description' => $product['description'] ?? '',
					'image' => $product['image'] ?? '',
					'image_url' => $product['image'] ?? '',
				];
			}
		}
		
		if ( empty( $products ) ) {
			return new \WP_Error( 'invalid_products', __( 'No valid products provided.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Get API key and model from settings
		$plugin_settings = get_option( 'aebg_settings', [] );
		$api_key = $settings['api_key'] ?? $plugin_settings['api_key'] ?? $plugin_settings['openai_api_key'] ?? '';
		$ai_model = $settings['ai_model'] ?? $settings['model'] ?? $plugin_settings['ai_model'] ?? $plugin_settings['model'] ?? 'gpt-3.5-turbo';
		
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key is not configured.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Merge settings
		$final_settings = wp_parse_args( $settings, [
			'post_type' => 'post',
			'post_status' => 'draft',
			'num_products' => count( $products ),
			'template_id' => $template_id,
			'api_key' => $api_key,
			'ai_model' => $ai_model,
		] );
		
		// Create a batch for this single post (like bulk generation)
		$wpdb->insert(
			$wpdb->prefix . 'aebg_batches',
			[
				'user_id'     => get_current_user_id(),
				'template_id' => $template_id,
				'settings'    => json_encode( $final_settings ),
				'status'      => 'scheduled',
				'total_items' => 1,
				'created_at'  => current_time( 'mysql' ),
			]
		);
		$batch_id = $wpdb->insert_id;
		
		if ( ! $batch_id ) {
			Logger::error( 'Failed to create batch for competitor generation', [
				'title' => $title,
				'db_error' => $wpdb->last_error,
			] );
			return new \WP_Error( 'database_error', __( 'Failed to create batch in database.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		// Create batch item
		$wpdb->insert(
			$wpdb->prefix . 'aebg_batch_items',
			[
				'batch_id'     => $batch_id,
				'source_title' => $title,
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql' ),
			]
		);
		$item_id = $wpdb->insert_id;
		
		if ( ! $item_id ) {
			Logger::error( 'Failed to create batch item for competitor generation', [
				'title' => $title,
				'batch_id' => $batch_id,
				'db_error' => $wpdb->last_error,
			] );
			return new \WP_Error( 'database_error', __( 'Failed to create batch item in database.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		// Store competitor products in initial checkpoint state
		// This will be available to Step 2 to skip ProductFinder
		CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_1_ANALYZE_TITLE, [
			'title' => $title,
			'template_id' => (int) $template_id,
			'settings' => $final_settings,
			'ai_model' => $ai_model,
			'author_id' => get_current_user_id(),
			'competitor_products' => $products, // Store competitor products for Step 2
		] );
		
		// Schedule Step 1 using step-by-step system (same as bulk generation)
		$use_step_by_step = get_option( 'aebg_use_step_by_step_actions', true );
		
		if ( $use_step_by_step ) {
			$hook = StepHandler::get_step_hook( StepHandler::get_first_step() );
			if ( ! $hook ) {
				Logger::error( 'Step 1 hook not found', [ 'item_id' => $item_id ] );
				return new \WP_Error( 'hook_not_found', __( 'Failed to schedule generation step.', 'aebg' ), [ 'status' => 500 ] );
			}
			
			$group = 'aebg_generation_' . $item_id;
			$action_id = ActionSchedulerHelper::schedule_action( 
				$hook,
				[ $item_id ],
				$group,
				1, // 1 second delay
				true // Make action unique
			);
			
			if ( $action_id <= 0 ) {
				Logger::error( 'Failed to schedule Step 1', [ 'item_id' => $item_id ] );
				return new \WP_Error( 'schedule_failed', __( 'Failed to schedule generation.', 'aebg' ), [ 'status' => 500 ] );
			}
			
			Logger::info( 'Scheduled Step 1 for competitor generation', [
				'item_id' => $item_id,
				'action_id' => $action_id,
			] );
		} else {
			// Legacy mode (shouldn't happen if step-by-step is enabled, but handle it)
			Logger::warning( 'Step-by-step disabled, using legacy mode for competitor generation', [ 'item_id' => $item_id ] );
			return new \WP_Error( 'legacy_not_supported', __( 'Competitor generation requires step-by-step mode to be enabled.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		$response = new \WP_REST_Response( [
			'success'  => true,
			'item_id'  => $item_id,
			'batch_id' => $batch_id,
			'message'  => __( 'Post generation scheduled successfully. It will be processed step-by-step in the background.', 'aebg' ),
		], 200 );
		
		return $response;
	}

	/**
	 * Find missing products through market analysis
	 *
	 * @param \WP_REST_Request $request Full data about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function find_missing_products( $request ) {
		$found_products = $request->get_param( 'found_products' );
		$competitor_url = $request->get_param( 'competitor_url' );
		$missing_count = $request->get_param( 'missing_count' );
		$country = $request->get_param( 'country' ) ?? 'Denmark';
		$language = $request->get_param( 'language' ) ?? 'Danish';
		
		Logger::info( 'Finding missing products via market analysis', [
			'found_count' => count( $found_products ),
			'missing_count' => $missing_count,
			'competitor_url' => $competitor_url,
		] );
		
		// Validate inputs
		if ( empty( $found_products ) || ! is_array( $found_products ) ) {
			return new \WP_Error( 'invalid_products', __( 'Found products array is required.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		if ( empty( $competitor_url ) ) {
			return new \WP_Error( 'missing_url', __( 'Competitor URL is required.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		if ( $missing_count <= 0 ) {
			return new \WP_Error( 'invalid_count', __( 'Missing count must be greater than 0.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Perform market analysis
		$analyzer = new CompetitorAnalyzer();
		$result = $analyzer->findMissingProductsByMarketAnalysis(
			$found_products,
			$competitor_url,
			$missing_count,
			[
				'country' => $country,
				'language' => $language,
			]
		);
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		// Convert Datafeedr matches to display format (similar to process_products_for_display)
		$display_recommendations = [];
		
		foreach ( $result['recommendations'] ?? [] as $rec_data ) {
			$recommendation = $rec_data['recommendation'] ?? [];
			$best_match = $rec_data['best_match'] ?? null;
			
			if ( ! $best_match ) {
				continue; // Skip recommendations without Datafeedr matches
			}
			
			// best_match is already formatted by search_advanced() -> format_products()
			// It has normalized fields: 'network', 'affiliate_url', 'merchant', 'image_url', etc.
			$product_name = $best_match['name'] ?? $recommendation['product_name'] ?? $recommendation['name'] ?? '';
			
			if ( empty( $product_name ) ) {
				continue; // Skip if no product name
			}
			
			// Build datafeedr_match structure for display
			$datafeedr_match = [
				'name' => $best_match['name'] ?? '',
				'price' => $best_match['price'] ?? '',
				'affiliate_url' => $best_match['affiliate_url'] ?? $best_match['url'] ?? '',
				'network' => $best_match['network'] ?? '',
				'merchant' => $best_match['merchant'] ?? '',
				'image' => $best_match['image_url'] ?? $best_match['image'] ?? '',
			];
			
			// Build display product (similar to process_products_for_display format)
			$display_recommendations[] = [
				'name' => $product_name,
				'price' => $best_match['price'] ?? '',
				'finalprice' => $best_match['finalprice'] ?? $best_match['final_price'] ?? $best_match['sale_price'] ?? '',
				'salediscount' => $best_match['salediscount'] ?? $best_match['discount'] ?? 0,
				'merchant' => $best_match['merchant'] ?? '',
				'affiliate_link' => $best_match['affiliate_url'] ?? $best_match['url'] ?? '',
				'network' => $best_match['network'] ?? '',
				'rating' => $best_match['rating'] ?? 0,
				'description' => $best_match['description'] ?? '',
				'image' => $best_match['image_url'] ?? $best_match['image'] ?? '',
				'position' => count( $display_recommendations ) + 1,
				'datafeedr_match' => $datafeedr_match,
				'recommendation_reason' => $recommendation['reason'] ?? '',
				// Store full product data for later use (generator format)
				'_full_data' => [
					'name' => $product_name,
					'short_name' => $product_name,
					'price' => $best_match['price'] ?? '',
					'finalprice' => $best_match['finalprice'] ?? $best_match['final_price'] ?? $best_match['sale_price'] ?? '',
					'salediscount' => $best_match['salediscount'] ?? $best_match['discount'] ?? 0,
					'url' => $best_match['affiliate_url'] ?? $best_match['url'] ?? '',
					'affiliate_url' => $best_match['affiliate_url'] ?? $best_match['url'] ?? '',
					'merchant' => $best_match['merchant'] ?? '',
					'network' => $best_match['network'] ?? '',
					'rating' => $best_match['rating'] ?? 0,
					'description' => $best_match['description'] ?? '',
					'image' => $best_match['image_url'] ?? $best_match['image'] ?? '',
					'image_url' => $best_match['image_url'] ?? $best_match['image'] ?? '',
				],
			];
		}
		
		Logger::info( 'Final display recommendations count', [
			'total' => count( $display_recommendations ),
		] );
		
		// Log first recommendation structure for debugging
		if ( ! empty( $display_recommendations ) ) {
			Logger::debug( 'First recommendation structure', [
				'keys' => array_keys( $display_recommendations[0] ),
				'has_name' => isset( $display_recommendations[0]['name'] ),
				'has_datafeedr_match' => isset( $display_recommendations[0]['datafeedr_match'] ),
				'name' => $display_recommendations[0]['name'] ?? 'missing',
			] );
		}
		
		$response_data = [
			'success' => true,
			'category' => $result['category'] ?? 'unknown',
			'market_insights' => $result['market_insights'] ?? '',
			'recommendations' => $display_recommendations,
			'total_found' => count( $display_recommendations ),
			'message' => sprintf(
				__( 'Found %d product recommendations through market analysis.', 'aebg' ),
				count( $display_recommendations )
			),
		];
		
		Logger::debug( 'Sending REST response', [
			'recommendations_count' => count( $display_recommendations ),
			'response_keys' => array_keys( $response_data ),
		] );
		
		$response = new \WP_REST_Response( $response_data, 200 );
		
		return $response;
	}
}

