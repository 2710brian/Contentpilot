<?php

namespace AEBG\Core;

use AEBG\Core\DuplicateDetector;
use AEBG\Core\AIPromptProcessor;
use AEBG\Core\PriceComparisonManager;
use AEBG\Core\TimeoutManager;
use AEBG\Core\Logger;
use AEBG\Core\TitleAnalyzer;
use AEBG\Core\ImageProcessor;
use AEBG\Core\FeaturedImageGenerator;
use AEBG\Core\TagGenerator;
use AEBG\Core\CategoryGenerator;
use AEBG\Core\MetaDescriptionGenerator;
use AEBG\Core\SchemaGenerator;
use AEBG\Core\APIClient;
use AEBG\Core\DataUtilities;
use AEBG\Core\ContentFormatter;
use AEBG\Core\ComparisonManager;
use AEBG\Core\Datafeedr;
use AEBG\Core\ProductFinder;
use AEBG\Core\ElementorTemplateProcessor;
use AEBG\Core\ContentGenerator;
use AEBG\Core\PostCreator;
use AEBG\Core\GenerationObserver;
use AEBG\Core\VariableReplacer;
use AEBG\Core\TemplateManipulator;
use AEBG\Core\CheckpointManager;
use AEBG\Core\StepHandler;
use AEBG\Core\CompetitorProductFetcher;


// Manually require TemplateManager if it's not already loaded
if (!class_exists('AEBG\Core\TemplateManager')) {
    $template_manager_path = __DIR__ . '/TemplateManager.php';
    if (file_exists($template_manager_path)) {
        require_once $template_manager_path;
    } else {
        error_log('[AEBG] TemplateManager.php not found at: ' . $template_manager_path);
    }
}

use AEBG\Core\TemplateManager;

/**
 * Generator Class
 * 
 * Lean orchestrator that delegates to specialized modules for content generation.
 * This class coordinates the workflow but delegates all heavy lifting to modules.
 *
 * @package AEBG\Core
 */
class Generator {
	/**
	 * The settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Variables instance.
	 *
	 * @var Variables
	 */
	private $variables;

	/**
	 * Context registry instance.
	 *
	 * @var ContextRegistry
	 */
	private $context_registry;

	/**
	 * AI Prompt Processor instance.
	 *
	 * @var AIPromptProcessor
	 */
	private $ai_processor;

	/**
	 * Job start time for this specific job (not shared across jobs).
	 * This is set when Generator is instantiated and used for timeout checks.
	 *
	 * @var float
	 */
	private $job_start_time;

	/**
	 * The author ID for generated posts.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Product Finder instance.
	 *
	 * @var ProductFinder
	 */
	private $product_finder;

	/**
	 * Elementor Template Processor instance.
	 *
	 * @var ElementorTemplateProcessor
	 */
	private $template_processor;

	/**
	 * Content Generator instance.
	 *
	 * @var ContentGenerator
	 */
	private $content_generator;

	/**
	 * Post Creator instance.
	 *
	 * @var PostCreator
	 */
	private $post_creator;

	/**
	 * Generation Observer instance.
	 *
	 * @var GenerationObserver
	 */
	private $observer;

	/**
	 * Variable Replacer instance.
	 *
	 * @var VariableReplacer
	 */
	private $variable_replacer;

	/**
	 * Generator constructor.
	 *
	 * @param array $settings The settings.
	 * @param float|null $job_start_time Optional job start time. If not provided, uses current time.
	 * @param int|null $author_id Optional. The user ID to use as post author. Defaults to current user.
	 */
	public function __construct( $settings, $job_start_time = null, $author_id = null ) {
		// CRITICAL: Set job-specific start time for timeout checks
		// This ensures each job has its own start time, not a shared constant
		$this->job_start_time = $job_start_time ?? microtime(true);
		// CRITICAL: Store in global IMMEDIATELY for access by other classes (like PriceComparisonManager, ElementorTemplateProcessor)
		// This must be set before any methods are called that might check timeout
		$GLOBALS['aebg_job_start_time'] = $this->job_start_time;
		error_log('[AEBG] Generator::__construct - Job start time set to: ' . $this->job_start_time . ' (current time: ' . microtime(true) . ', difference: ' . round(microtime(true) - $this->job_start_time, 3) . 's)');
		// Use provided author_id, or fall back to current user (for backward compatibility)
		$this->author_id = $author_id !== null ? (int) $author_id : get_current_user_id();
		// error_log('[AEBG] Generator::__construct - Author ID set to: ' . $this->author_id);
		
		// CRITICAL: Set global variable so PriceComparisonManager can access author_id
		// This ensures comparison data can be saved to database even if context doesn't have it
		$GLOBALS['aebg_author_id'] = $this->author_id;
		// Validate and sanitize settings parameter
		if (!is_array($settings)) {
			error_log('[AEBG] Generator constructor - Invalid settings parameter type: ' . gettype($settings));
			$settings = [];
		}
		
		// Ensure required settings exist with defaults
		$default_settings = [
			'num_products' => 5,
			'ai_model' => 'gpt-3.5-turbo',
			'temperature' => 0.7,
			'content_length' => 1000,
			'creativity' => 0.5,
			'post_type' => 'post',
			'post_status' => 'draft'
		];
		
		$this->settings = wp_parse_args($settings, $default_settings);
		
		// Normalize: if 'model' exists but 'ai_model' doesn't, copy it
		if ( isset( $this->settings['model'] ) && ! isset( $this->settings['ai_model'] ) ) {
			$this->settings['ai_model'] = $this->settings['model'];
		}
		
		try {
			// CRITICAL FIX: Create NEW instances for each job instead of reusing global singletons
			// This prevents state accumulation across jobs which causes accumulative timeouts
			// Previous approach reused global instances, causing ContextRegistry to accumulate
			// fields from all previous jobs, making topological sort exponentially slower
			$this->variables = new Variables();
			$this->context_registry = new ContextRegistry();
			
			// Initialize new modular components
			$this->variable_replacer = new VariableReplacer($this->variables);
			$this->product_finder = new ProductFinder();
			// NOTE: template_processor is created in run() after ai_processor is initialized
			// This ensures all dependencies (ai_processor, content_generator) are available
			$this->content_generator = new ContentGenerator($this->variable_replacer, $this->job_start_time);
			$this->post_creator = new PostCreator($this->author_id, $this->job_start_time);
			$this->observer = new GenerationObserver();
			
			// error_log('[AEBG] Generator::__construct - Created fresh Variables and ContextRegistry instances for this job');
			// error_log('[AEBG] Generator::__construct - Initialized modular components');
			// error_log('[AEBG] Generator constructor completed successfully');
		} catch ( \Exception $e ) {
			error_log('[AEBG] Error in Generator constructor: ' . $e->getMessage());
			error_log('[AEBG] Error trace: ' . $e->getTraceAsString());
			throw $e;
		}
	}

	/**
	 * Run the generator with the new refactored workflow.
	 *
	 * @param string $title The title.
	 * @param int    $template_id The template ID.
	 * @param array  $settings Additional settings.
	 * @param int|null $item_id Optional item ID for checkpoint management.
	 * @param array|null $competitor_products Optional pre-provided competitor products (skips ProductFinder steps).
	 * @return int|\WP_Error
	 * @throws \Exception
	 */
	public function run( $title, $template_id, $settings = [], $item_id = null, $competitor_products = null ) {
		$generation_start_time = microtime(true);
		$activity_id = null;
		
		try {
			// Get batch_id from item_id if available
			$batch_id = null;
			if ($item_id) {
				global $wpdb;
				$item = $wpdb->get_row($wpdb->prepare(
					"SELECT batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
					$item_id
				));
				if ($item) {
					$batch_id = $item->batch_id;
				}
			}
			
			// Start tracking generation activity
			$activity_id = \AEBG\Core\UsageTracker::start_generation([
				'batch_id' => $batch_id,
				'batch_item_id' => $item_id,
				'user_id' => $this->author_id ?? get_current_user_id(),
				'template_id' => $template_id,
			]);
			
			// Set global for API usage tracking
			if ($item_id) {
				$GLOBALS['aebg_current_item_id'] = $item_id;
			}
			
			// CRITICAL: Check for existing checkpoint and resume if found
			if ( $item_id && CheckpointManager::canResume( $item_id ) ) {
				$checkpoint = CheckpointManager::loadCheckpoint( $item_id );
				if ( $checkpoint ) {
					error_log( '[AEBG] 🔄 RESUMING from checkpoint: ' . $checkpoint['step'] . ' | item_id=' . $item_id );
					CheckpointManager::incrementResumeCount( $item_id );
					$result = $this->resumeFromCheckpoint( $checkpoint, $item_id );
					
					// Track completion for resumed generation
					if ($activity_id) {
						$post_id = is_wp_error($result) ? null : $result;
						$post_content = $post_id ? get_post_field('post_content', $post_id) : '';
						$word_count = $post_content ? str_word_count(strip_tags($post_content)) : 0;
						
						\AEBG\Core\UsageTracker::complete_generation($activity_id, [
							'post_id' => $post_id,
							'status' => is_wp_error($result) ? 'failed' : 'completed',
							'duration_seconds' => microtime(true) - $generation_start_time,
							'steps_completed' => $this->observer ? count($this->observer->getProgress()['steps'] ?? []) : 0,
							'resume_count' => CheckpointManager::getResumeCount($item_id) ?? 0,
							'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
							'content_length_words' => $word_count,
							'total_cost' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_cost($item_id) : null,
							'total_tokens' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_tokens($item_id) : null,
							'error_message' => is_wp_error($result) ? $result->get_error_message() : null,
						]);
					}
					
					return $result;
				}
			}
			
			// Start generation observation
			$this->observer->startGeneration($title);
			
			// CRITICAL: Cleanup at START of job to ensure fresh state
			// This prevents issues from previous jobs affecting this one
			Logger::debug( 'Generator::run - Starting fresh state cleanup' );
			$this->cleanupAtJobStart();
			$this->observer->recordStep('initialization');
			
			// Check PHP timeout (does not modify server configuration - respects server settings)
			// Individual API request timeouts are handled by cURL, not PHP's execution timeout
			TimeoutManager::check_timeout( TimeoutManager::DEFAULT_TIMEOUT );
			
			// Validate and sanitize parameters
			if (empty($title) || !is_string($title)) {
				$error_msg = 'Invalid title parameter. Title must be a non-empty string.';
				error_log('[AEBG] ' . $error_msg);
				return new \WP_Error('aebg_invalid_title', $error_msg);
			}
			
			if (empty($template_id) || !is_numeric($template_id)) {
				$error_msg = 'Invalid template_id parameter. Template ID must be a valid numeric value.';
				error_log('[AEBG] ' . $error_msg);
				return new \WP_Error('aebg_invalid_template_id', $error_msg);
			}
			
			// Convert template_id to integer for consistency
			$template_id = intval($template_id);
			
			// Validate settings parameter
			if (!is_array($settings)) {
				error_log('[AEBG] Generator::run - Invalid settings parameter type: ' . gettype($settings));
				$settings = [];
			}
			
			// Merge with constructor settings
			$final_settings = wp_parse_args($settings, $this->settings);
			
			// Enhanced logging for debugging
			error_log( '[AEBG] Generator::run started with title: ' . $title . ', template_id: ' . $template_id );
			error_log( '[AEBG] Template ID type: ' . gettype($template_id) );
			error_log( '[AEBG] Template ID value: ' . $template_id );
			error_log( '[AEBG] Final settings: ' . json_encode( $final_settings ) );
			error_log( '[AEBG] Final settings num_products: ' . ($final_settings['num_products'] ?? 'not set') );
			
			$api_key = defined( 'AEBG_AI_API_KEY' ) ? AEBG_AI_API_KEY : ( $final_settings['api_key'] ?? get_option( 'aebg_settings' )['api_key'] ?? '' );
			if ( empty( $api_key ) ) {
				$msg = '[AEBG] OpenAI API key is not set. Please enter your OpenAI API key in the plugin settings.';
				error_log($msg);
				return new \WP_Error('aebg_openai_api_key_missing', $msg);
			}

			// Get AI model from final settings (handle both 'model' and 'ai_model' keys)
			$ai_model = $final_settings['ai_model'] ?? $final_settings['model'] ?? 'gpt-3.5-turbo';
			error_log( '[AEBG] Using AI model: ' . $ai_model );

			// Initialize AI processor if we have API key
			if (!empty($api_key) && !isset($this->ai_processor)) {
				error_log('[AEBG] Initializing AI processor');
				$this->ai_processor = new \AEBG\Core\AIPromptProcessor($this->variables, $this->context_registry, $api_key, $ai_model);
			}

			// CRITICAL: Log elapsed time at start of run
			$run_start_time = $this->job_start_time ?? microtime(true);
			$elapsed_at_start = microtime(true) - $run_start_time;
			error_log('[AEBG] ⏱️ Generator::run - Starting with elapsed time: ' . round($elapsed_at_start, 2) . ' seconds');
			
			// Step 1: Analyze title and extract comprehensive context
			error_log('[AEBG] 📍 CHECKPOINT: Starting Step 1 - analyzeTitle (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			$this->observer->recordStep('analyze_title');
			
			// Use TitleAnalyzer module
			$title_analyzer = new TitleAnalyzer($this->variables, $this->settings);
			$context = $title_analyzer->analyzeTitle($title, $api_key, $ai_model);
			if (is_wp_error($context)) {
				$this->observer->recordError('Title analysis failed', ['error' => $context->get_error_message()]);
				return $context;
			}
			error_log('[AEBG] 📍 CHECKPOINT: Completed Step 1 - analyzeTitle (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			$this->observer->recordMetric('context_extracted', true);
			
			// Save checkpoint after Step 1
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_1_ANALYZE_TITLE, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'settings' => $final_settings,
					'ai_model' => $ai_model,
					'author_id' => $this->author_id,
				] );
			}

			// Step 2: Find products using ProductFinder module (skip if competitor products provided)
			if ( ! empty( $competitor_products ) && is_array( $competitor_products ) ) {
				// Use provided competitor products - skip ProductFinder steps
				error_log('[AEBG] 📍 Using provided competitor products (skipping ProductFinder steps)');
				error_log('[AEBG] Competitor products count: ' . count( $competitor_products ) );
				$this->observer->recordStep('use_competitor_products');
				$this->observer->recordMetric('products_found', count( $competitor_products ) );
				$this->observer->recordMetric('products_selected', count( $competitor_products ) );
				
				$products = $competitor_products; // For checkpoint compatibility
				$selected_products = $competitor_products; // Use directly as selected products
				
				// Update context quantity to match provided products
				$context['quantity'] = count( $selected_products );
				$context['actual_product_count'] = count( $selected_products );
				error_log('[AEBG] Using ' . count( $selected_products ) . ' competitor products directly');
			} else {
				// Normal flow: Find products using ProductFinder module
				error_log('[AEBG] 📍 CHECKPOINT: Starting Step 2 - findProducts (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
				$this->observer->recordStep('find_products');
				
				// CRITICAL: Call heartbeat before long operation
				if (isset($GLOBALS['aebg_heartbeat_callback']) && is_callable($GLOBALS['aebg_heartbeat_callback'])) {
					call_user_func($GLOBALS['aebg_heartbeat_callback']);
				}
				
				// CRITICAL: Log before calling findProducts
				error_log('[AEBG] ===== ABOUT TO CALL findProducts =====');
				error_log('[AEBG] ⏱️ Elapsed time before findProducts: ' . round(microtime(true) - $run_start_time, 2) . 's');
				$find_products_start = microtime(true);
				
				$products = $this->product_finder->findProducts($context, $title, $api_key, $ai_model);
				
				// CRITICAL: Call heartbeat after long operation
				if (isset($GLOBALS['aebg_heartbeat_callback']) && is_callable($GLOBALS['aebg_heartbeat_callback'])) {
					call_user_func($GLOBALS['aebg_heartbeat_callback']);
				}
				
				$find_products_elapsed = microtime(true) - $find_products_start;
				// CRITICAL: Log immediately after findProducts returns
				error_log('[AEBG] ===== findProducts RETURNED =====');
				error_log('[AEBG] ⏱️ findProducts took ' . round($find_products_elapsed, 2) . ' seconds');
				error_log('[AEBG] ⏱️ Total elapsed time after findProducts: ' . round(microtime(true) - $run_start_time, 2) . 's');
				error_log('[AEBG] findProducts result type: ' . (is_wp_error($products) ? 'WP_Error' : (is_array($products) ? 'array (' . count($products) . ' items)' : gettype($products))));
				
				if (is_wp_error($products)) {
					error_log('[AEBG] Product search failed: ' . $products->get_error_message());
					$this->observer->recordError('Product search failed', ['error' => $products->get_error_message()]);
					return new \WP_Error('aebg_product_search_failed', 'Failed to search for products: ' . $products->get_error_message());
				}
				
				$this->observer->recordMetric('products_found', count($products));

				// Debug: Log the context and quantity information
				error_log('[AEBG] Context quantity: ' . ($context['quantity'] ?? 'not set'));
				error_log('[AEBG] Final settings num_products: ' . ($final_settings['num_products'] ?? 'not set'));
				error_log('[AEBG] Total products found: ' . count($products));
				
				// CRITICAL: Log that we're past findProducts and continuing
				error_log('[AEBG] ===== PAST findProducts - CONTINUING TO STEP 3 =====');
				
				// Check if any products were found
				if (empty($products)) {
					$error_msg = 'No products found for the search term "' . $title . '". Please try a different search term or check your Datafeedr configuration.';
					error_log('[AEBG] ' . $error_msg);
					return new \WP_Error('aebg_no_products_found', $error_msg);
				}
				
				// Save checkpoint after Step 2
				if ( $item_id ) {
					CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_2_FIND_PRODUCTS, [
						'title' => $title,
						'template_id' => $template_id,
						'context' => $context,
						'products' => $products,
						'settings' => $final_settings,
						'ai_model' => $ai_model,
						'author_id' => $this->author_id,
					] );
				}

				// Step 3: Use ProductFinder to select best products
				error_log('[AEBG] 📍 CHECKPOINT: Starting Step 3 - selectProducts (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
				$this->observer->recordStep('select_products');
				$select_products_start = microtime(true);
				
				$selected_products = $this->product_finder->selectProducts($products, $context, $api_key, $ai_model);
				$select_products_elapsed = microtime(true) - $select_products_start;
				error_log('[AEBG] ⏱️ selectProducts took ' . round($select_products_elapsed, 2) . ' seconds');
				if (is_wp_error($selected_products)) {
					error_log('[AEBG] Product selection failed, using fallback selection: ' . $selected_products->get_error_message());
					$this->observer->recordError('Product selection failed, using fallback', ['error' => $selected_products->get_error_message()]);
					$selected_products = $this->product_finder->fallbackProductSelection($products, $context);
				}
				
				// Optimize product names once to save tokens during content generation
				error_log('[AEBG] Optimizing product names to save tokens...');
				$optimize_start = microtime(true);
				$selected_products = $this->product_finder->optimizeProductNames($selected_products, $api_key, $ai_model);
				$optimize_elapsed = microtime(true) - $optimize_start;
				error_log('[AEBG] ⏱️ optimizeProductNames took ' . round($optimize_elapsed, 2) . ' seconds');
				
				$this->observer->recordMetric('products_selected', count($selected_products));
			}
		
		// Debug: Log the final selection
		error_log('[AEBG] Final selected products count: ' . count($selected_products));
		
		// Check if any products were selected
		if (empty($selected_products)) {
			$error_msg = 'No products could be selected for the search term "' . $title . '". This may be due to insufficient product data or AI selection criteria.';
			error_log('[AEBG] ' . $error_msg);
			return new \WP_Error('aebg_no_products_selected', $error_msg);
		}
		
		// CRITICAL: Adjust context quantity to match actual product count
		// This prevents template from referencing products that don't exist (e.g., product-7 when only 6 exist)
		$actual_product_count = count($selected_products);
		$requested_quantity = $context['quantity'] ?? $actual_product_count;
		
		if ($actual_product_count < $requested_quantity) {
			error_log('[AEBG] ⚠️ WARNING: Only found ' . $actual_product_count . ' products, but requested ' . $requested_quantity . '. Adjusting context quantity to match actual count.');
			$context['quantity'] = $actual_product_count;
			$context['actual_product_count'] = $actual_product_count;
		} else {
			$context['actual_product_count'] = $actual_product_count;
		}
		
		error_log('[AEBG] Final context quantity: ' . $context['quantity'] . ' (actual products: ' . $actual_product_count . ')');

		// Save checkpoint after Step 3
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_SELECT_PRODUCTS, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'products' => $products,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}

		// Step 3.5: Discover merchants for selected products (like old version)
		error_log('[AEBG] 📍 CHECKPOINT: Starting Step 3.5 - discoverMerchantsForProducts (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
		$merchant_discovery_start = microtime(true);
		$selected_products = $this->product_finder->discoverMerchantsForProducts($selected_products, $context);
		$merchant_discovery_elapsed = microtime(true) - $merchant_discovery_start;
		error_log('[AEBG] Merchant discovery completed for ' . count($selected_products) . ' products in ' . round($merchant_discovery_elapsed, 2) . ' seconds');
		
		// Step 3.6: Process price comparison for selected products (like old version)
		error_log('[AEBG] 📍 CHECKPOINT: Starting Step 3.6 - processPriceComparisonForProducts (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
		error_log('[AEBG] About to call processPriceComparisonForProducts with ' . count($selected_products) . ' products');
		
		// CRITICAL: Ensure author_id is in context so comparison data can be saved to database
		if (!isset($context['author_id']) && $this->author_id) {
			$context['author_id'] = $this->author_id;
		}
		
		$price_comparison_start = microtime(true);
		$selected_products = $this->product_finder->processPriceComparisonForProducts($selected_products, $context);
		$price_comparison_elapsed = microtime(true) - $price_comparison_start;
		error_log('[AEBG] processPriceComparisonForProducts returned after ' . round($price_comparison_elapsed, 2) . ' seconds');
		error_log('[AEBG] Return value type: ' . gettype($selected_products));
		error_log('[AEBG] Return value count: ' . (is_array($selected_products) ? count($selected_products) : 'N/A'));
		error_log('[AEBG] Price comparison processing completed for ' . count($selected_products) . ' products in ' . round($price_comparison_elapsed, 2) . ' seconds');
		error_log('[AEBG] 📍 CHECKPOINT: Completed Step 3.6 - processPriceComparisonForProducts (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');

		// Save checkpoint after Step 3.6
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_6_PRICE_COMPARISON, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}

		// Step 3.7: Process product images (download and insert into media library)
		error_log('[AEBG] 📍 CHECKPOINT: Starting Step 3.7 - processProductImages (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
		
		// CRITICAL: Check timeout before image processing (image downloads can hang)
		$elapsed = microtime(true) - $this->job_start_time;
		$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
		if ($elapsed > ($max_time - 60)) { // Reserve 60 seconds for remaining steps
			error_log('[AEBG] ⚠️ Skipping image processing - approaching timeout (elapsed: ' . round($elapsed, 2) . 's, max: ' . $max_time . 's)');
		} else if (!empty($selected_products) && is_array($selected_products)) {
			error_log('[AEBG] Processing product images for ' . count($selected_products) . ' products');
			// Note: We'll process images again after post creation to ensure proper association
			// Use ImageProcessor module with error handling
			try {
				$image_processing_start = microtime(true);
				$selected_products = ImageProcessor::processProductImages($selected_products, 0);
				$image_processing_elapsed = microtime(true) - $image_processing_start;
				error_log('[AEBG] Product image processing completed in ' . round($image_processing_elapsed, 2) . ' seconds');
			} catch (\Throwable $e) {
				error_log('[AEBG] ERROR in image processing: ' . $e->getMessage());
				error_log('[AEBG] ERROR trace: ' . $e->getTraceAsString());
				// Continue with unprocessed products - don't halt entire generation
			}
		} else {
			error_log('[AEBG] Skipping image processing - no products or invalid product array');
		}
		error_log('[AEBG] 📍 CHECKPOINT: Completed Step 3.7 - processProductImages (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');

		// Save checkpoint after Step 3.7
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_7_PROCESS_IMAGES, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}

		// Step 4: Process Elementor template with full context
		error_log('[AEBG] 📍 CHECKPOINT: Starting Step 4 - processElementorTemplate (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
		error_log('[AEBG] Starting Elementor template processing for template ID: ' . $template_id);
		error_log('[AEBG] Template ID type: ' . gettype($template_id));
		error_log('[AEBG] Template ID value: ' . $template_id);
		
		// Check if template exists
		if ($template_id) {
			$template_post = get_post($template_id);
			if ($template_post) {
				// error_log('[AEBG] Template post found: ' . $template_post->post_title);
				$elementor_data = get_post_meta($template_id, '_elementor_data', true);
				// error_log('[AEBG] Elementor data exists: ' . (!empty($elementor_data) ? 'yes' : 'no'));
			} else {
				error_log('[AEBG] Template post not found for ID: ' . $template_id);
			}
		} else {
			error_log('[AEBG] No template ID provided');
		}
		
		// CRITICAL: Clear context registry BEFORE creating template processor
		// This ensures clean state for each job (prevents state accumulation)
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
			error_log('[AEBG] Generator::run: Cleared context registry before template processing');
		}
		
		// Use ElementorTemplateProcessor module
		// CRITICAL: Create template processor here (not in constructor) because:
		// 1. ai_processor is created earlier in run() (line 265)
		// 2. This ensures all dependencies are available
		// 3. Each job gets a fresh instance with clean context_registry
		error_log('[AEBG] Creating ElementorTemplateProcessor instance...');
		$this->template_processor = new ElementorTemplateProcessor($this->context_registry, $this->ai_processor, $this->variable_replacer, $this->content_generator);
		error_log('[AEBG] ElementorTemplateProcessor instance created');
		
		// Set global AI model for timeout threshold calculations in ElementorTemplateProcessor
		$GLOBALS['aebg_ai_model'] = $ai_model;
		
		$template_processing_start = microtime(true);
		// error_log('[AEBG] ⏱️ About to call processTemplate - elapsed so far: ' . round(microtime(true) - $run_start_time, 2) . 's');
		$processed_template = $this->template_processor->processTemplate($template_id, $title, $selected_products, $context, $api_key, $ai_model);
		$template_processing_elapsed = microtime(true) - $template_processing_start;
		error_log('[AEBG] ⏱️ processTemplate returned after ' . round($template_processing_elapsed, 2) . ' seconds');
		if (is_wp_error($processed_template)) {
			error_log('[AEBG] Template processing failed: ' . $processed_template->get_error_message());
			$this->observer->recordError('Template processing failed', ['error' => $processed_template->get_error_message()]);
			$processed_template = null;
		} else {
			error_log('[AEBG] 📍 CHECKPOINT: Completed Step 4 - processElementorTemplate (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			error_log('[AEBG] Template processing completed successfully in ' . round($template_processing_elapsed, 2) . ' seconds');
			error_log('[AEBG] Processed template type: ' . gettype($processed_template));
			$this->observer->recordStep('process_template', ['template_id' => $template_id]);
			
			// Save checkpoint after Step 4
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_PROCESS_TEMPLATE, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'selected_products' => $selected_products,
					'processed_template' => $processed_template,
					'settings' => $final_settings,
					'ai_model' => $ai_model,
					'author_id' => $this->author_id,
				] );
			}
		}

			// Step 5: Generate final content with full context
			// CRITICAL FIX: Skip generateContent if we have a processed template - it's not used anyway!
			// The createPost method sets $post_content to empty string if processed_template exists
			// This prevents hanging on API calls that aren't even needed
			$content = '';
			if (!$processed_template || is_wp_error($processed_template)) {
				error_log('[AEBG] No processed template found, generating content for post body');
				
				// Check execution time before making API call
				// CRITICAL: Use job-specific start time, not shared constant
				$elapsed = microtime(true) - $this->job_start_time;
				$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
				if ($elapsed > $max_time) {
					error_log('[AEBG] ⚠️ Skipping generateContent - approaching timeout (elapsed: ' . round($elapsed, 2) . 's, max: ' . $max_time . 's)');
					$content = ''; // Use empty content to prevent timeout
				} else {
					// Use ContentGenerator module
					$content = $this->content_generator->generateContent($title, $selected_products, $context, $api_key, $ai_model);
					if (is_wp_error($content)) {
						error_log('[AEBG] generateContent failed: ' . $content->get_error_message() . ' - using empty content');
						$this->observer->recordError('Content generation failed', ['error' => $content->get_error_message()]);
						$content = ''; // Use empty content on error
					} else {
						// Step 5.5: Replace product variables in the generated content
						$content = $this->variable_replacer->replaceVariablesInPrompt($content, $title, $selected_products, $context);
						error_log('[AEBG] Content after variable replacement: ' . substr($content, 0, 500) . '...');
						$this->observer->recordStep('generate_content', ['content_length' => strlen($content)]);
					}
				}
			} else {
				error_log('[AEBG] Processed template exists - skipping generateContent (not needed, Elementor handles content)');
			}

			// Save checkpoint after Step 5 (if content was generated)
			if ( $item_id && !empty($content) ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_5_GENERATE_CONTENT, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'selected_products' => $selected_products,
					'processed_template' => $processed_template ?? null,
					'content' => $content,
					'settings' => $final_settings,
					'ai_model' => $ai_model,
					'author_id' => $this->author_id,
				] );
			}

			// Step 5.5: Generate merchant comparison data BEFORE post creation
			// CRITICAL: This ensures comparison data is available when Elementor renders the post
			// Elementor rendering happens during createPost, and shortcodes need comparison data
			error_log('[AEBG] 📍 CHECKPOINT: Starting Step 5.5 - processMerchantComparisons BEFORE post creation (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			if (!empty($selected_products) && is_array($selected_products)) {
				// Generate comparison data with null post_id (will be updated after post creation)
				$this->processMerchantComparisons(null, $selected_products, $this->author_id);
			}
			error_log('[AEBG] 📍 CHECKPOINT: Completed Step 5.5 - processMerchantComparisons BEFORE post creation (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');

			// Save checkpoint after Step 5.5
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_5_5_MERCHANT_COMPARISONS, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'selected_products' => $selected_products,
					'processed_template' => $processed_template ?? null,
					'content' => $content ?? '',
					'settings' => $final_settings,
					'ai_model' => $ai_model,
					'author_id' => $this->author_id,
				] );
			}

			// Step 6: Create post with processed template
			error_log('[AEBG] 📍 CHECKPOINT: Starting Step 6 - createPost (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			error_log('[AEBG] ===== ABOUT TO CREATE POST =====');
			error_log('[AEBG] Title: ' . $title);
			error_log('[AEBG] Has processed_template: ' . ($processed_template ? 'yes' : 'no'));
			error_log('[AEBG] Has content: ' . (!empty($content) ? 'yes (' . strlen($content) . ' chars)' : 'no'));
			error_log('[AEBG] Selected products count: ' . (is_array($selected_products) ? count($selected_products) : '0'));
			
			// CRITICAL: Check timeout before creating post using job-specific start time
			$elapsed = microtime(true) - $this->job_start_time;
			$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
			if ($elapsed > $max_time) {
				error_log('[AEBG] ⚠️ CRITICAL: Approaching timeout before createPost (elapsed: ' . round($elapsed, 2) . 's, max: ' . $max_time . 's)');
				error_log('[AEBG] ⚠️ Aborting post creation to prevent timeout');
				return new \WP_Error('aebg_timeout', 'Generation timeout: approaching action scheduler limit before post creation');
			}
			error_log('[AEBG] Time check before createPost: elapsed=' . round($elapsed, 2) . 's, remaining=' . round($max_time - $elapsed, 2) . 's');
			
			error_log('[AEBG] Calling createPost...');
			$this->observer->recordStep('create_post');
			$create_post_start = microtime(true);
			// Use PostCreator module
			$new_post_id = $this->post_creator->createPost($title, $content, $processed_template, $final_settings, $selected_products);
			$create_post_elapsed = microtime(true) - $create_post_start;
			error_log('[AEBG] createPost completed in ' . round($create_post_elapsed, 2) . ' seconds');
			$this->observer->recordMetric('post_creation_time', $create_post_elapsed);
			
			error_log('[AEBG] ===== POST CREATION RETURNED =====');
			error_log('[AEBG] Return value type: ' . gettype($new_post_id));
			error_log('[AEBG] Return value: ' . var_export($new_post_id, true));
			
			if (is_wp_error($new_post_id)) {
				error_log('[AEBG] ERROR: Post creation returned WP_Error: ' . $new_post_id->get_error_message());
				
				// Track generation failure
				if ($activity_id) {
					\AEBG\Core\UsageTracker::complete_generation($activity_id, [
						'status' => 'failed',
						'duration_seconds' => microtime(true) - $generation_start_time,
						'steps_completed' => $this->observer ? count($this->observer->getProgress()['steps'] ?? []) : 0,
						'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
						'error_message' => $new_post_id->get_error_message(),
						'total_cost' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_cost($item_id) : null,
						'total_tokens' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_tokens($item_id) : null,
					]);
				}
				
				// Clear global
				if (isset($GLOBALS['aebg_current_item_id'])) {
					unset($GLOBALS['aebg_current_item_id']);
				}
				
				return $new_post_id;
			}
			
			if (empty($new_post_id) || !is_numeric($new_post_id) || $new_post_id <= 0) {
				error_log('[AEBG] ERROR: Post creation returned invalid ID: ' . var_export($new_post_id, true));
				
				// Track generation failure
				if ($activity_id) {
					\AEBG\Core\UsageTracker::complete_generation($activity_id, [
						'status' => 'failed',
						'duration_seconds' => microtime(true) - $generation_start_time,
						'steps_completed' => $this->observer ? count($this->observer->getProgress()['steps'] ?? []) : 0,
						'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
						'error_message' => 'Post creation returned invalid post ID',
						'total_cost' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_cost($item_id) : null,
						'total_tokens' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_tokens($item_id) : null,
					]);
				}
				
				// Clear global
				if (isset($GLOBALS['aebg_current_item_id'])) {
					unset($GLOBALS['aebg_current_item_id']);
				}
				
				return new \WP_Error('aebg_invalid_post_id', 'Post creation returned invalid post ID: ' . var_export($new_post_id, true));
			}
			
			error_log('[AEBG] Post created successfully with ID: ' . $new_post_id);
			error_log('[AEBG] 📍 CHECKPOINT: Completed Step 6 - createPost (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			error_log('[AEBG] ⏱️ Generator::run - Total elapsed time: ' . round(microtime(true) - $run_start_time, 2) . ' seconds');

			// Step 6.5: Re-process product images with proper post association
			if (!empty($selected_products) && is_array($selected_products)) {
				error_log('[AEBG] Re-processing product images with post ID: ' . $new_post_id);
				// Use ImageProcessor module
				$selected_products = ImageProcessor::processProductImages($selected_products, $new_post_id);
				error_log('[AEBG] Product image re-processing completed');
			}

			// Step 6.6: Re-associate AI-generated images with the post
			$this->associateAIGeneratedImagesWithPost($new_post_id, $final_settings);

			// Step 6.7: Update comparison records with post_id
			// CRITICAL: Update comparison records that were created with null post_id before post creation
			// This links the comparison data to the actual post
			error_log('[AEBG] 📍 CHECKPOINT: Starting Step 6.7 - updateComparisonRecordsWithPostId (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			if (!empty($selected_products) && is_array($selected_products)) {
				$this->updateComparisonRecordsWithPostId($new_post_id, $selected_products, $this->author_id);
			}
			error_log('[AEBG] 📍 CHECKPOINT: Completed Step 6.7 - updateComparisonRecordsWithPostId (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');

			// Final verification that post exists in database
			$post_exists = get_post($new_post_id);
			if (!$post_exists) {
				error_log('[AEBG] CRITICAL ERROR: Post ID ' . $new_post_id . ' does not exist in database after creation!');
				
				// Track generation failure
				if ($activity_id) {
					\AEBG\Core\UsageTracker::complete_generation($activity_id, [
						'status' => 'failed',
						'duration_seconds' => microtime(true) - $generation_start_time,
						'steps_completed' => $this->observer ? count($this->observer->getProgress()['steps'] ?? []) : 0,
						'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
						'error_message' => 'Post was created but not found in database',
						'total_cost' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_cost($item_id) : null,
						'total_tokens' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_tokens($item_id) : null,
					]);
				}
				
				// Clear global
				if (isset($GLOBALS['aebg_current_item_id'])) {
					unset($GLOBALS['aebg_current_item_id']);
				}
				
				return new \WP_Error('aebg_post_not_found', 'Post was created but not found in database');
			}
			
			error_log('[AEBG] ===== FINAL VERIFICATION =====');
			error_log('[AEBG] Post ID: ' . $new_post_id);
			error_log('[AEBG] Post Title: ' . $post_exists->post_title);
			error_log('[AEBG] Post Status: ' . $post_exists->post_status);
			error_log('[AEBG] Post Type: ' . $post_exists->post_type);
			error_log('[AEBG] Elementor data exists: ' . (get_post_meta($new_post_id, '_elementor_data', true) ? 'yes' : 'no'));
			
			// CRITICAL: Ensure Elementor CSS/JS generation is fully complete before reporting success
			// This prevents shortcodes from executing during Elementor rendering
			error_log('[AEBG] 📍 CHECKPOINT: Verifying Elementor processing is complete (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			$this->verifyElementorProcessingComplete($new_post_id);
			error_log('[AEBG] 📍 CHECKPOINT: Elementor processing verification complete (elapsed: ' . round(microtime(true) - $run_start_time, 2) . 's)');
			
			// CRITICAL: Final cleanup after successful post creation
			// This ensures resources are freed before the next job starts
			$this->finalCleanupAfterPostCreation($new_post_id);
			
			// Mark generation as complete
			$this->observer->markComplete();
			
			// Log generation summary
			$summary = $this->observer->getSummary();
			Logger::info('Generation completed successfully', $summary);
			
			error_log('[AEBG] Generator::run completed successfully for post ID: ' . $new_post_id );
			
			// Track generation completion
			if ($activity_id) {
				$post_content = get_post_field('post_content', $new_post_id);
				$word_count = $post_content ? str_word_count(strip_tags($post_content)) : 0;
				
				\AEBG\Core\UsageTracker::complete_generation($activity_id, [
					'post_id' => $new_post_id,
					'status' => 'completed',
					'duration_seconds' => microtime(true) - $generation_start_time,
					'steps_completed' => $this->observer ? count($this->observer->getProgress()['steps'] ?? []) : 0,
					'checkpoint_count' => $item_id ? (CheckpointManager::getCheckpointCount($item_id) ?? 0) : 0,
					'resume_count' => $item_id ? (CheckpointManager::getResumeCount($item_id) ?? 0) : 0,
					'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
					'content_length_words' => $word_count,
					'total_cost' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_cost($item_id) : null,
					'total_tokens' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_tokens($item_id) : null,
				]);
			}
			
			// Clear global
			if (isset($GLOBALS['aebg_current_item_id'])) {
				unset($GLOBALS['aebg_current_item_id']);
			}
			
			// Clear checkpoint on successful completion
			if ( $item_id ) {
				CheckpointManager::clearCheckpoint( $item_id );
			}
			
			return $new_post_id;
		} catch ( \Exception $e ) {
			$msg = '[AEBG] Exception: ' . $e->getMessage();
			error_log($msg);
			error_log( '[AEBG] Exception trace: ' . $e->getTraceAsString() );
			
			// Track generation failure
			if ($activity_id) {
				\AEBG\Core\UsageTracker::complete_generation($activity_id, [
					'status' => 'failed',
					'duration_seconds' => microtime(true) - $generation_start_time,
					'steps_completed' => $this->observer ? count($this->observer->getProgress()['steps'] ?? []) : 0,
					'memory_peak_mb' => memory_get_peak_usage(true) / 1024 / 1024,
					'error_message' => $msg,
					'total_cost' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_cost($item_id) : null,
					'total_tokens' => $item_id ? \AEBG\Core\UsageTracker::get_generation_total_tokens($item_id) : null,
				]);
			}
			
			// Clear global
			if (isset($GLOBALS['aebg_current_item_id'])) {
				unset($GLOBALS['aebg_current_item_id']);
			}
			
			return new \WP_Error('aebg_generation_exception', $msg);
		}
	}

	/**
	 * Generate post using competitor products
	 * 
	 * @param string $title Post title
	 * @param string $competitor_url Competitor URL
	 * @param int $template_id Elementor template ID
	 * @param array $settings Generation settings
	 * @param string $api_key OpenAI API key
	 * @param string $ai_model AI model
	 * @return int|\WP_Error Post ID or error
	 */
	public function generateFromCompetitor(
		string $title,
		string $competitor_url,
		int $template_id,
		array $settings = [],
		string $api_key = '',
		string $ai_model = ''
	): int|\WP_Error {
		Logger::info( 'Generating post from competitor products', [
			'title' => $title,
			'competitor_url' => $competitor_url,
			'template_id' => $template_id,
		] );
		
		// Validate template to determine required product count
		$template_validation = $this->validateTemplateProductCount( $template_id, 0 );
		if ( is_wp_error( $template_validation ) ) {
			return $template_validation;
		}
		
		$required_count = $template_validation['required_count'] ?? 0;
		if ( $required_count <= 0 ) {
			return new \WP_Error( 'aebg_no_product_variables', __( 'Template does not contain any product variables. Please use a template with {product-X} variables.', 'aebg' ) );
		}
		
		// Fetch products from competitor URL
		$fetcher = new CompetitorProductFetcher();
		$products = $fetcher->fetchProducts( $competitor_url, $required_count );
		
		if ( is_wp_error( $products ) ) {
			return $products;
		}
		
		if ( empty( $products ) || count( $products ) < $required_count ) {
			return new \WP_Error( 
				'aebg_insufficient_products', 
				sprintf( 
					__( 'Found %d products, but template requires %d. Please use a different template or competitor URL.', 'aebg' ),
					count( $products ),
					$required_count
				)
			);
		}
		
		// Limit to required count
		$products = array_slice( $products, 0, $required_count );
		
		// Merge API key and model into settings if provided
		if ( ! empty( $api_key ) ) {
			$settings['api_key'] = $api_key;
		}
		if ( ! empty( $ai_model ) ) {
			$settings['ai_model'] = $ai_model;
		}
		
		// Run generator with competitor products
		return $this->run( $title, $template_id, $settings, null, $products );
	}

	/**
	 * Associate AI-generated images with the post.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	private function associateAIGeneratedImagesWithPost($post_id, array $settings = []) {
		if (!$post_id) {
			return;
		}
		
		// Check setting
		if (empty($settings['include_ai_images']) || !$settings['include_ai_images']) {
			error_log('[AEBG] AI image association skipped - include_ai_images setting disabled');
			return;
		}

		global $wpdb;
		
		// Find all AI-generated images that are not associated with any post
		$ai_images = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%'
			AND pm.meta_key = '_aebg_ai_generated'
			AND pm.meta_value = 'yes'
			AND p.post_parent = 0
			ORDER BY p.post_date DESC
			LIMIT 10",
		));

		if (empty($ai_images)) {
			error_log('[AEBG] No AI-generated images found to associate with post ' . $post_id);
			return;
		}

		$associated_count = 0;
		foreach ($ai_images as $attachment_id) {
			$result = wp_update_post([
				'ID' => $attachment_id,
				'post_parent' => $post_id
			]);

			if (!is_wp_error($result)) {
				$associated_count++;
				error_log('[AEBG] Associated AI-generated image ' . $attachment_id . ' with post ' . $post_id);
			} else {
				error_log('[AEBG] Failed to associate AI-generated image ' . $attachment_id . ' with post ' . $post_id . ': ' . $result->get_error_message());
			}
		}

		error_log('[AEBG] Associated ' . $associated_count . ' AI-generated images with post ' . $post_id);
	}

	/**
	 * Consume all MySQL results to prevent "Commands out of sync" errors.
	 *
	 * @return void
	 */
	private function consumeAllMySQLResults() {
		global $wpdb;
		
		// Clear WordPress query cache first
		$wpdb->flush();
		
		// If using mysqli extension, consume all unprocessed results
		if (isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
			$mysqli = $wpdb->dbh;
			
			// First, ensure current result is consumed
			if ($result = $mysqli->store_result()) {
				$result->free();
			}
			
			// Consume all remaining results from multi-query or stored procedures
			$max_iterations = 100; // Safety limit to prevent infinite loops
			$iteration = 0;
			while ($mysqli->more_results() && $iteration < $max_iterations) {
				$iteration++;
				$mysqli->next_result();
				if ($result = $mysqli->store_result()) {
					$result->free();
				}
				// Break if there's an error to prevent infinite loop
				if ($mysqli->errno) {
					error_log('[AEBG] MySQL error while consuming results: ' . $mysqli->error);
				break;
		}
			}
			
			if ($iteration >= $max_iterations) {
				error_log('[AEBG] WARNING: Reached max iterations while consuming MySQL results');
			}
			
			// Clear any remaining error state
			if ($mysqli->errno) {
				error_log('[AEBG] MySQL connection error detected, resetting: ' . $mysqli->error);
				// Reset connection state
				$mysqli->ping();
			}
		}
		
		// Clear WordPress error state
		$wpdb->last_error = '';
		$wpdb->last_query = '';
	}

	/**
	 * Reset database connection to ensure clean state.
	 *
	 * @return void
	 */
	private function resetDatabaseConnection() {
		global $wpdb;
		
		error_log('[AEBG] Resetting database connection...');
		
		// Consume all results first
		$this->consumeAllMySQLResults();
		
		// Force reconnection if using mysqli
		if (isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
			$mysqli = $wpdb->dbh;
			
			// Ping to check connection health first
			if (!$mysqli->ping()) {
				error_log('[AEBG] MySQL connection is dead, forcing reconnection...');
				// Close and reconnect
				if (method_exists($wpdb, 'db_connect')) {
					$wpdb->db_connect();
				}
			} else {
				// Connection is alive, but reset state anyway
				// This prevents "Commands out of sync" errors
				$mysqli->ping();
			}
			
			// CRITICAL: Verify connection is actually healthy after ping
			// Some connection issues may not be caught by ping alone
			if (isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
				$test_query = $wpdb->query("SELECT 1");
				if ($test_query === false && !empty($wpdb->last_error)) {
					error_log('[AEBG] ⚠️ WARNING: Database connection test failed: ' . $wpdb->last_error);
					// Attempt reconnection
					if (method_exists($wpdb, 'db_connect')) {
						$wpdb->db_connect();
					}
				}
			}
		}
		
		// Final flush - more aggressive
		$wpdb->flush();
		$wpdb->last_error = '';
		$wpdb->last_query = '';
		$wpdb->last_result = [];
		
		// Clear query cache more thoroughly
		if (isset($wpdb->queries)) {
			$wpdb->queries = [];
		}
		
		error_log('[AEBG] Database connection reset complete');
	}

	/**
	 * Safely get Elementor Plugin instance, handling Cloud Library 403 errors
	 * 
	 * @return \Elementor\Plugin|null Elementor instance or null if initialization fails
	 */
	private function getElementorInstance() {
		if (!class_exists('\Elementor\Plugin')) {
			return null;
		}
		
		try {
			return \Elementor\Plugin::instance();
		} catch (\WpOrg\Requests\Exception\Http\Status403 $e) {
			// Elementor Cloud Library 403 error - log and return null
			error_log('[AEBG] ⚠️ Elementor Cloud Library 403 error (non-critical): ' . $e->getMessage());
			return null;
		} catch (\Exception $e) {
			// Other Elementor initialization errors
			error_log('[AEBG] ⚠️ Elementor initialization error (non-critical): ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Clear Elementor cache for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function clearElementorCache($post_id) {
		error_log('[AEBG] Clearing Elementor cache for post ' . $post_id);
		
		// Clear Elementor-specific cache meta (but keep _elementor_edit_mode)
		delete_post_meta($post_id, '_elementor_data_cache');
		// Don't delete _elementor_edit_mode - we need this for Elementor to recognize the post
		delete_post_meta($post_id, '_elementor_css');
		delete_post_meta($post_id, '_elementor_js');
		delete_post_meta($post_id, '_elementor_custom_css');
		delete_post_meta($post_id, '_elementor_custom_js');
		
		// Clear Elementor page cache
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('elementor_css_' . $post_id, 'elementor');
			wp_cache_delete('elementor_js_' . $post_id, 'elementor');
			wp_cache_delete('elementor_data_' . $post_id, 'elementor');
		}
		
		// Clear Elementor global cache if available
		$elementor = $this->getElementorInstance();
		if ($elementor) {
			try {
				// Clear Elementor's internal cache
				if (method_exists($elementor, 'files_manager')) {
					$elementor->files_manager->clear_cache();
				}
				
				// Clear Elementor's CSS cache
				if (method_exists($elementor, 'kits_manager')) {
					$elementor->kits_manager->clear_cache();
				}
				
				// Clear Elementor's documents cache
				// FIX: documents is a property (object), not a method
				if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
					$document = $elementor->documents->get($post_id);
					if ($document && method_exists($document, 'delete_autosave')) {
						$document->delete_autosave();
					}
				}
				} catch (\Exception $e) {
				error_log('[AEBG] Error clearing Elementor cache: ' . $e->getMessage());
			}
		}
		
		// Clear WordPress object cache for this post
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete($post_id, 'posts');
			wp_cache_delete($post_id, 'post_meta');
		}
		
		// Clear any other Elementor-related caches
			do_action('elementor/core/files/clear_cache');
			do_action('elementor/css-file/clear_cache');
			do_action('elementor/js-file/clear_cache');
		
		// Clear Elementor's internal caches
		if (class_exists('\Elementor\Core\Files\CSS\Post')) {
			try {
				$css_file = new \Elementor\Core\Files\CSS\Post($post_id);
				$css_file->delete();
		} catch (\Exception $e) {
				error_log('[AEBG] Error clearing Elementor CSS file: ' . $e->getMessage());
			}
		}
		
		// Clear Elementor's JS cache
		if (class_exists('\Elementor\Core\Files\JS\Post')) {
			try {
				$js_file = new \Elementor\Core\Files\JS\Post($post_id);
				$js_file->delete();
			} catch (\Exception $e) {
				error_log('[AEBG] Error clearing Elementor JS file: ' . $e->getMessage());
			}
		}
		
		error_log('[AEBG] Elementor cache cleared for post ' . $post_id);
	}

	/**
	 * CRITICAL: Cleanup at START of job to ensure fresh state
	 * This prevents issues from previous jobs affecting this one
	 * 
	 * @return void
	 */
	private function cleanupAtJobStart() {
		error_log('[AEBG] cleanupAtJobStart: Starting fresh state cleanup...');
		
		// Reset database connection proactively
		// This ensures we start with a fresh, healthy connection
		$this->resetDatabaseConnection();
		
		// Consume any leftover MySQL results from previous operations
		$this->consumeAllMySQLResults();
		
		// Clear WordPress query cache
		global $wpdb;
		if ($wpdb) {
			$wpdb->flush();
			$wpdb->last_error = '';
			$wpdb->last_query = '';
		}
		
		// Clear context registry to prevent state accumulation from previous jobs
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
			error_log('[AEBG] cleanupAtJobStart: Context registry cleared');
		} elseif ($this->context_registry && method_exists($this->context_registry, 'reset')) {
			$this->context_registry->reset();
			error_log('[AEBG] cleanupAtJobStart: Context registry reset');
		}
		
		// Force garbage collection to free any memory from previous operations
		if (function_exists('gc_collect_cycles')) {
			$collected = gc_collect_cycles();
			if ($collected > 0) {
				error_log('[AEBG] cleanupAtJobStart: Garbage collected ' . $collected . ' cycles');
			}
		}
		
		// CRITICAL: Reset WordPress HTTP API transport state
		// This ensures every job starts with a completely fresh HTTP connection pool
		// The second job runs in the same PHP process, so it inherits the first job's connection pool
		// We need to reset this to ensure the second job has the same clean state as the first
		$this->resetWordPressHttpTransport();
		
		// Log memory usage for monitoring
		if (function_exists('memory_get_usage')) {
			$memory_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
			error_log('[AEBG] cleanupAtJobStart: Starting memory usage: ' . $memory_mb . ' MB');
		}
		
		error_log('[AEBG] cleanupAtJobStart: Fresh state cleanup completed');
	}
	
	/**
	 * Reset WordPress HTTP API transport state
	 * 
	 * CRITICAL: WordPress HTTP API (Requests library) maintains a connection pool
	 * that persists across requests in the same PHP process. The second job inherits
	 * this pool, which may contain stale connections. This method forces WordPress
	 * to reset its HTTP transport state, ensuring every job starts with a clean
	 * connection pool - the same "premises" as the first job.
	 * 
	 * @return void
	 */
	private function resetWordPressHttpTransport() {
		error_log('[AEBG] Generator::resetWordPressHttpTransport: Resetting WordPress HTTP API transport state...');
		
		// Clear WordPress HTTP API cache
		// WordPress caches the transport class and other HTTP-related data
		if (function_exists('wp_cache_delete')) {
			// Clear any HTTP-related cache
			wp_cache_delete('http_transport', 'options');
			wp_cache_delete('_transient_doing_cron', 'options');
			wp_cache_delete('http_response', 'transient');
		}
		
		// Force garbage collection to clean up any unreferenced connections
		// This will free any cURL handles or other resources that are no longer referenced
		if (function_exists('gc_collect_cycles')) {
			$collected = gc_collect_cycles();
			if ($collected > 0) {
				error_log('[AEBG] Generator::resetWordPressHttpTransport: Garbage collected ' . $collected . ' cycles (may include HTTP connections)');
			}
		}
		
		error_log('[AEBG] Generator::resetWordPressHttpTransport: WordPress HTTP API transport state reset completed');
	}

	/**
	 * CRITICAL: Cleanup after template processing to prevent memory accumulation
	 * This is called after processElementorTemplate to free large data structures
	 * 
	 * @return void
	 */
	private function cleanupAfterTemplateProcessing() {
		error_log('[AEBG] cleanupAfterTemplateProcessing: Starting cleanup...');
		
		// Clear WordPress object cache to prevent accumulation
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('posts');
			wp_cache_flush_group('post_meta');
		}
		
		// Reset database connection proactively (not just on errors)
		// This prevents MySQL connection from getting stale between jobs
		$this->resetDatabaseConnection();
		
		// Force garbage collection if available
		if (function_exists('gc_collect_cycles')) {
			$collected = gc_collect_cycles();
			if ($collected > 0) {
				error_log('[AEBG] cleanupAfterTemplateProcessing: Garbage collected ' . $collected . ' cycles');
			}
		}
		
		// Log memory usage for monitoring
		if (function_exists('memory_get_usage')) {
			$memory_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
			error_log('[AEBG] cleanupAfterTemplateProcessing: Memory usage: ' . $memory_mb . ' MB');
		}
		
		error_log('[AEBG] cleanupAfterTemplateProcessing: Cleanup completed');
	}

	/**
	 * CRITICAL: Cleanup after Elementor data processing to prevent memory accumulation
	 * This is called after processElementorData to free large arrays
	 * 
	 * @return void
	 */
	private function cleanupAfterElementorProcessing() {
		error_log('[AEBG] cleanupAfterElementorProcessing: Starting cleanup...');
		
		// Clear context registry to prevent state accumulation across jobs
		// The context registry might accumulate prompts/responses if not cleared
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
			error_log('[AEBG] cleanupAfterElementorProcessing: Context registry cleared');
		} elseif ($this->context_registry && method_exists($this->context_registry, 'reset')) {
			$this->context_registry->reset();
			error_log('[AEBG] cleanupAfterElementorProcessing: Context registry reset');
		}
		
		// Consume all MySQL results to ensure clean database state
		$this->consumeAllMySQLResults();
		
		// Clear WordPress query cache
		global $wpdb;
		if ($wpdb) {
			$wpdb->flush();
		}
		
		// Force garbage collection
		if (function_exists('gc_collect_cycles')) {
			$collected = gc_collect_cycles();
			if ($collected > 0) {
				error_log('[AEBG] cleanupAfterElementorProcessing: Garbage collected ' . $collected . ' cycles');
			}
		}
		
		error_log('[AEBG] cleanupAfterElementorProcessing: Cleanup completed');
	}

	/**
	 * Process merchant comparisons synchronously during post generation
	 * This ensures each post is 100% complete when generation finishes
	 * 
	 * @param int $post_id The post ID
	 * @param array $products Array of selected products
	 * @param int $user_id The user ID (post author)
	 * @return void
	 */
	private function processMerchantComparisons($post_id, $products, $user_id) {
		error_log('[AEBG] processMerchantComparisons: Starting for post ' . $post_id . ' with ' . count($products) . ' products');
		
		if (empty($products) || !is_array($products)) {
			error_log('[AEBG] processMerchantComparisons: No products to process');
			return;
		}

		if (!$user_id || $user_id <= 0) {
			error_log('[AEBG] processMerchantComparisons: Invalid user_id: ' . $user_id);
			return;
		}

		// CRITICAL: Check timeout before starting merchant comparisons
		// Merchant comparisons can take time, so we need to ensure we have enough time left
		$elapsed_time = microtime(true) - $this->job_start_time;
		$max_time = TimeoutManager::DEFAULT_TIMEOUT - TimeoutManager::SAFETY_BUFFER;
		$time_remaining = $max_time - $elapsed_time;
		
		// Estimate time needed: ~2-5 seconds per product (API call + processing)
		$estimated_time_needed = count($products) * 3; // Conservative estimate: 3s per product
		
		if ($time_remaining < $estimated_time_needed) {
			error_log('[AEBG] ⚠️ WARNING: Insufficient time remaining (' . round($time_remaining, 2) . 's) for merchant comparisons (estimated: ' . $estimated_time_needed . 's). Skipping to prevent timeout.');
			return;
		}
		
		error_log('[AEBG] processMerchantComparisons: Time remaining: ' . round($time_remaining, 2) . 's, estimated needed: ' . $estimated_time_needed . 's');
		
		// Initialize Datafeedr
		$datafeedr = new Datafeedr();
		
		// Process each product
		$processed_count = 0;
		$failed_count = 0;
		$merchant_limit = 10; // Default merchant limit
		
		foreach ($products as $index => $product) {
			// CRITICAL: Check timeout before each product
			$elapsed_time = microtime(true) - $this->job_start_time;
			$time_remaining = $max_time - $elapsed_time;
			
			if ($time_remaining < 5) {
				error_log('[AEBG] ⚠️ WARNING: Time running out (' . round($time_remaining, 2) . 's remaining). Stopping merchant comparison processing to prevent timeout.');
				break;
			}
			
			if (empty($product['id'])) {
				error_log('[AEBG] processMerchantComparisons: Skipping product ' . ($index + 1) . ' - no product ID');
				continue;
			}
			
			try {
				$product_start_time = microtime(true);
				error_log('[AEBG] processMerchantComparisons: Processing product ' . ($index + 1) . '/' . count($products) . ': ' . ($product['name'] ?? $product['id']));
				
				// CRITICAL: Add small delay before each API call to allow connection pool to recover
				// This prevents connection pool exhaustion that causes hangs, especially on the last call
				// Similar to the delay we add for second+ jobs in Datafeedr::search_advanced()
				// Skip delay for first product (index 0) to avoid unnecessary delay
				if ($index > 0) {
					error_log('[AEBG] processMerchantComparisons: About to add 0.1s delay before API call (index: ' . $index . ')');
					usleep(100000); // 0.1 second delay before each subsequent API call
					error_log('[AEBG] processMerchantComparisons: Added 0.1s delay before API call to allow connection pool recovery');
				}
				
				// CRITICAL: Log before API call to help debug hangs
				error_log('[AEBG] processMerchantComparisons: About to call get_merchant_comparison for product ' . $product['id']);
				
				// Get merchant comparison data with timeout protection
				$merchant_data = $datafeedr->get_merchant_comparison($product, $merchant_limit);
				
				// CRITICAL: Log after API call to verify it completed
				error_log('[AEBG] processMerchantComparisons: get_merchant_comparison returned for product ' . $product['id'] . ' (is_wp_error: ' . (is_wp_error($merchant_data) ? 'yes' : 'no') . ')');
				
				if (is_wp_error($merchant_data)) {
					error_log('[AEBG] processMerchantComparisons: Error fetching merchant data for product ' . $product['id'] . ': ' . $merchant_data->get_error_message());
					$failed_count++;
				continue;
			}

				if (empty($merchant_data['merchants']) || !is_array($merchant_data['merchants'])) {
					error_log('[AEBG] processMerchantComparisons: No merchants found for product: ' . $product['id']);
					$failed_count++;
				continue;
			}
			
				// Save comparison data to database
				$save_result = ComparisonManager::save_comparison(
					$user_id,
					$post_id,
					$product['id'],
					'Synchronous Merchant Comparison',
					$merchant_data
				);
				
				if ($save_result !== false) {
					$processed_count++;
					$product_elapsed = microtime(true) - $product_start_time;
					error_log('[AEBG] processMerchantComparisons: Successfully saved comparison data for product ' . $product['id'] . ' (' . count($merchant_data['merchants']) . ' merchants) in ' . round($product_elapsed, 2) . 's');
				} else {
					error_log('[AEBG] processMerchantComparisons: Failed to save comparison data for product: ' . $product['id']);
					$failed_count++;
				}
				
				// NOTE: Rate limiting is now handled by APIClient via OpenAI's 429 responses
				// No need for manual delays - APIClient will handle wait/retry automatically
				// Database connections are handled automatically by WordPress
				
			} catch (\Exception $e) {
				error_log('[AEBG] processMerchantComparisons: Exception processing product ' . $product['id'] . ': ' . $e->getMessage());
				error_log('[AEBG] processMerchantComparisons: Exception trace: ' . $e->getTraceAsString());
				$failed_count++;
				continue;
			} catch (\Throwable $e) {
				error_log('[AEBG] processMerchantComparisons: Throwable processing product ' . $product['id'] . ': ' . $e->getMessage());
				error_log('[AEBG] processMerchantComparisons: Throwable trace: ' . $e->getTraceAsString());
				$failed_count++;
				continue;
			}
		}
		
		error_log('[AEBG] processMerchantComparisons: Completed - Processed: ' . $processed_count . ', Failed: ' . $failed_count . ', Total: ' . count($products));
		
		// CRITICAL: Clean up database connections to prevent "too many connections" errors
		$this->cleanupDatabaseConnections();
	}
	
	/**
	 * Update comparison records with post_id after post creation
	 * 
	 * This method updates comparison records that were created with null post_id
	 * before post creation, linking them to the actual post.
	 * 
	 * @param int $post_id Post ID
	 * @param array $products Array of products
	 * @param int $user_id User ID
	 * @return void
	 */
	private function updateComparisonRecordsWithPostId($post_id, $products, $user_id) {
		if (empty($post_id) || !is_numeric($post_id) || $post_id <= 0) {
			error_log('[AEBG] updateComparisonRecordsWithPostId: Invalid post_id: ' . var_export($post_id, true));
			return;
		}
		
		if (empty($products) || !is_array($products)) {
			error_log('[AEBG] updateComparisonRecordsWithPostId: No products provided');
			return;
		}
		
		global $wpdb;
		$table_name = $wpdb->prefix . 'aebg_comparisons';
		$updated_count = 0;
		$saved_count = 0;
		
		foreach ($products as $product) {
			if (empty($product['id'])) {
				continue;
			}
			
			$product_id = $product['id'];
			
			// Step 1: Try to update existing comparison records that have null post_id
			$result = $wpdb->update(
				$table_name,
				['post_id' => $post_id, 'updated_at' => current_time('mysql')],
				[
					'user_id' => $user_id,
					'post_id' => null,
					'product_id' => $product_id,
					'status' => 'active'
				],
				['%d', '%s'],
				['%d', '%s', '%s', '%s']
			);
			
			if ($result !== false && $result > 0) {
				$updated_count++;
				error_log('[AEBG] updateComparisonRecordsWithPostId: Updated comparison record for product ' . $product_id . ' with post_id ' . $post_id);
				continue; // Successfully updated, move to next product
			}
			
			// Step 2: If no records were updated, check if comparison data exists in product array
			// This handles cases where comparison data wasn't saved to database during Step 3.6
			if (!empty($product['price_comparison']) && is_array($product['price_comparison'])) {
				$comparison_data = $product['price_comparison'];
				
				// Ensure we have merchants data in the expected format
				if (isset($comparison_data['merchants']) && is_array($comparison_data['merchants']) && !empty($comparison_data['merchants'])) {
					// Prepare comparison data in the format expected by ComparisonManager
					$formatted_data = [
						'merchants' => $comparison_data['merchants'] ?? [],
						'price_range' => $comparison_data['price_range'] ?? [],
						'price_statistics' => $comparison_data['price_statistics'] ?? [],
					];
					
					// Save comparison data with post_id
					try {
						$comparison_id = \AEBG\Core\ComparisonManager::save_comparison(
							$user_id,
							$post_id, // Now we have the actual post_id
							$product_id,
							'Price Comparison (Post Creation)',
							$formatted_data
						);
						
						if ($comparison_id !== false) {
							$saved_count++;
							error_log('[AEBG] updateComparisonRecordsWithPostId: Saved comparison data from product array for product ' . $product_id . ' with post_id ' . $post_id . ' (comparison_id: ' . $comparison_id . ')');
						} else {
							error_log('[AEBG] updateComparisonRecordsWithPostId: Failed to save comparison data from product array for product ' . $product_id);
						}
					} catch (\Exception $e) {
						error_log('[AEBG] updateComparisonRecordsWithPostId: Exception saving comparison data from product array: ' . $e->getMessage());
					}
				}
			} else {
				// Check if there's a comparison record with this post_id already (might have been saved earlier)
				$existing = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d AND post_id = %d AND product_id = %s AND status = 'active'",
					$user_id,
					$post_id,
					$product_id
				));
				
				if ($existing > 0) {
					error_log('[AEBG] updateComparisonRecordsWithPostId: Comparison record already exists for product ' . $product_id . ' with post_id ' . $post_id);
				} else {
					error_log('[AEBG] updateComparisonRecordsWithPostId: No comparison data found for product ' . $product_id . ' (neither in database with null post_id nor in product array)');
				}
			}
		}
		
		error_log('[AEBG] updateComparisonRecordsWithPostId: Updated ' . $updated_count . ' existing comparison records and saved ' . $saved_count . ' new comparison records for post_id ' . $post_id);
	}
	
	/**
	 * Clean up database connections to prevent connection leaks
	 *
	 * @return void
	 */
	private function cleanupDatabaseConnections() {
		global $wpdb;
		
		if (!$wpdb) {
			return;
		}
		
		// Consume all unprocessed MySQL results
		if (isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
			$mysqli = $wpdb->dbh;
			
			// Consume current result if any
			if ($result = $mysqli->store_result()) {
				$result->free();
			}
			
			// Consume all remaining results from multi-query
			$max_iterations = 50; // Safety limit
			$iteration = 0;
			while ($mysqli->more_results() && $iteration < $max_iterations) {
				$iteration++;
				$mysqli->next_result();
				if ($result = $mysqli->store_result()) {
					$result->free();
				}
				if ($mysqli->errno) {
					break; // Stop on error
				}
			}
		}
		
		// Flush WordPress query cache - more aggressive
		$wpdb->flush();
		$wpdb->last_error = '';
		$wpdb->last_query = '';
		$wpdb->last_result = [];
		
		// Clear query cache more thoroughly
		if (isset($wpdb->queries)) {
			$wpdb->queries = [];
		}
		
		// Clear object cache to free memory - more comprehensive
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('posts');
			wp_cache_flush_group('post_meta');
			wp_cache_flush_group('aebg_batches');
			wp_cache_flush_group('aebg_comparisons');
		}
		
		// Clear transients that might accumulate
		if (function_exists('wp_cache_delete')) {
			// Clear any AEBG-related transients
			// Delete transients - use prepared statements for security
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_aebg_%',
				'_transient_timeout_aebg_%'
			) );
		}
	}

	/**
	 * CRITICAL: Final cleanup after post creation to prevent resource accumulation
	 * This ensures each job starts with a clean state
	 * 
	 * @param int $post_id The created post ID
	 * @return void
	 */
	private function finalCleanupAfterPostCreation($post_id) {
		error_log('[AEBG] finalCleanupAfterPostCreation: Starting final cleanup for post ' . $post_id);
		
		// Consume all MySQL results one final time
		$this->consumeAllMySQLResults();
		
		// Reset database connection proactively
		// This prevents the next job from inheriting a stale connection
		$this->resetDatabaseConnection();
		
		// Clear WordPress object cache for this post
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete($post_id, 'posts');
			wp_cache_delete($post_id, 'post_meta');
		}
		
		// Clear Elementor cache for this post
		$this->clearElementorCache($post_id);
		
		// Clear context registry to prevent state accumulation
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
		} elseif ($this->context_registry && method_exists($this->context_registry, 'reset')) {
			$this->context_registry->reset();
		}
		
		// Force garbage collection to free memory from large Elementor arrays
		// Run multiple times to catch nested references
		if (function_exists('gc_collect_cycles')) {
			$total_collected = 0;
			for ($i = 0; $i < 3; $i++) {
				$collected = gc_collect_cycles();
				$total_collected += $collected;
				if ($collected === 0) {
					break; // No more cycles to collect
				}
			}
			if ($total_collected > 0) {
				error_log('[AEBG] finalCleanupAfterPostCreation: Garbage collected ' . $total_collected . ' cycles');
			}
		}
		
		// Explicitly clear large arrays that might hold references
		if (isset($this->context_registry)) {
			if (method_exists($this->context_registry, 'clear')) {
				$this->context_registry->clear();
			} elseif (method_exists($this->context_registry, 'reset')) {
				$this->context_registry->reset();
			}
		}
		
		// Clear any cached data in instance variables
		unset($this->product_finder);
		unset($this->ai_processor);
		
		// Log memory usage for monitoring
		if (function_exists('memory_get_usage')) {
			$memory_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
			$peak_memory_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
			error_log('[AEBG] finalCleanupAfterPostCreation: Memory usage: ' . $memory_mb . ' MB (peak: ' . $peak_memory_mb . ' MB)');
			
			// Warn if memory usage is high
			if ($memory_mb > 256) {
				error_log('[AEBG] ⚠️ WARNING: High memory usage detected: ' . $memory_mb . ' MB');
			}
		}
		
		error_log('[AEBG] finalCleanupAfterPostCreation: Final cleanup completed');
	}

	/**
	 * Logging helper with debug levels
	 * Only logs debug messages if AEBG_DEBUG constant is defined and true
	 *
	 * @param string $message The log message
	 * @param string $level Log level: 'info', 'warning', 'error', 'debug'
	 * @return void
	 */
	private function log($message, $level = 'info') {
		// Always log errors and warnings
		if ($level === 'error' || $level === 'warning') {
			error_log('[AEBG] ' . $message);
			return;
		}
		
		// Log info messages normally
		if ($level === 'info') {
			error_log('[AEBG] ' . $message);
			return;
		}
		
		// Only log debug messages if debug mode is enabled
		if ($level === 'debug' && (defined('AEBG_DEBUG') && AEBG_DEBUG)) {
			error_log('[AEBG] [DEBUG] ' . $message);
		}
	}

	/**
	 * Check if there's enough time remaining for an operation
	 * Consolidates all time checking logic into one reusable method
	 *
	 * @param float $required_time Required time in seconds (default: 30)
	 * @param float $max_allowed_time Maximum allowed time (default: uses TimeoutManager)
	 * @return array|false Returns array with 'remaining' and 'elapsed' if OK, false if not enough time
	 */
	private function checkTimeRemaining($required_time = 30, $max_allowed_time = null) {
		// Use centralized timeout if not specified
		if ($max_allowed_time === null) {
			$max_allowed_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
		}
		if (!$this->job_start_time) {
			// No time limit, return OK
			return ['remaining' => PHP_INT_MAX, 'elapsed' => 0];
		}
		
		$elapsed = microtime(true) - $this->job_start_time;
		$remaining = $max_allowed_time - $elapsed;
		
		// Past the limit
		if ($elapsed > $max_allowed_time) {
			$this->log('Past safe time limit (elapsed: ' . round($elapsed, 2) . 's > ' . $max_allowed_time . 's), aborting', 'warning');
			return false;
		}
		
		// Not enough time remaining
		if ($remaining < $required_time) {
			$this->log('Not enough time remaining (' . round($remaining, 2) . 's < ' . $required_time . 's), aborting', 'warning');
			return false;
		}
		
		return ['remaining' => $remaining, 'elapsed' => $elapsed];
	}

	/**
	 * Truncate prompt if it's too long for the AI model (like old version).
	 *
	 * @param string $prompt The prompt to truncate.
	 * @param string $ai_model The AI model to check against.
	 * @return string Truncated prompt.
	 */
	private function truncatePromptIfNeeded($prompt, $ai_model) {
		// Get the maximum tokens for the model
		$max_tokens = $this->getMaxTokensForModel($ai_model);
		if ($max_tokens === 0) {
			error_log('[AEBG] Unknown AI model: ' . $ai_model . ', using default token limit');
			$max_tokens = 4000; // Default fallback
		}

		// Estimate tokens (rough approximation: 1 token ≈ 4 characters for English text)
		$estimated_tokens = strlen($prompt) / 4;
		
		if ($estimated_tokens > $max_tokens * 0.8) { // Use 80% of max tokens to be safe
			error_log('[AEBG] Prompt too long (' . round($estimated_tokens) . ' estimated tokens), truncating to ' . round($max_tokens * 0.8) . ' tokens');
			
			// Truncate the prompt to 80% of max tokens
			$max_chars = $max_tokens * 0.8 * 4;
			$truncated_prompt = substr($prompt, 0, $max_chars);
			
			// Try to truncate at a word boundary
			$last_space = strrpos($truncated_prompt, ' ');
			if ($last_space !== false && $last_space > $max_chars * 0.9) {
				$truncated_prompt = substr($truncated_prompt, 0, $last_space);
			}
			
			$truncated_prompt .= '... [Content truncated due to length limits]';
			return $truncated_prompt;
		}

		return $prompt;
	}

	/**
	 * Get the maximum number of tokens allowed for a given AI model (like old version).
	 *
	 * @param string $ai_model The AI model to check against.
	 * @return int The maximum number of tokens allowed.
	 */
	private function getMaxTokensForModel($ai_model) {
		// Token limits for different models (these are approximate and may vary)
		$token_limits = [
			'gpt-5.2' => 128000,
			'gpt-5-mini' => 128000,
			'gpt-4' => 8192,
			'gpt-4-turbo' => 128000,
			'gpt-4-turbo-preview' => 128000,
			'gpt-4-0125-preview' => 128000,
			'gpt-4-1106-preview' => 128000,
			'gpt-4-32k' => 32768,
			'gpt-3.5-turbo' => 16385,
			'gpt-3.5-turbo-16k' => 16385,
			'gpt-3.5-turbo-1106' => 16385,
			'gpt-3.5-turbo-0125' => 16385,
			'o1-preview' => 200000,
			'o1-mini' => 128000,
			'o1' => 200000,
		];
		
		// Check for exact match first
		if (isset($token_limits[$ai_model])) {
			return $token_limits[$ai_model];
		}
		
		// Check for partial matches (e.g., "gpt-4" matches "gpt-4-turbo")
		foreach ($token_limits as $model => $limit) {
			if (strpos($ai_model, $model) === 0 || strpos($model, $ai_model) === 0) {
				return $limit;
			}
		}
		
		// Default fallback
		error_log('[AEBG] Unknown AI model: ' . $ai_model . ', using default token limit of 4000');
		return 4000;
	}

	// ============================================================================
	// PUBLIC METHODS - Called externally from other classes
	// ============================================================================

	/**
	 * Fix empty Elementor post by forcing recognition and CSS generation
	 *
	 * @param int $post_id The post ID
	 * @return bool|\WP_Error
	 */
	public function fixEmptyElementorPost($post_id) {
		error_log('[AEBG] ===== START: fixEmptyElementorPost =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		
		try {
			// Get the current Elementor data
			$template_manager = new TemplateManager();
			$elementor_data = $template_manager->getElementorData($post_id);
			
			if (is_wp_error($elementor_data)) {
				error_log('[AEBG] ERROR: Failed to get Elementor data: ' . $elementor_data->get_error_message());
				return $elementor_data;
			}
			
			error_log('[AEBG] Retrieved Elementor data successfully');
			
			// Force Elementor recognition
			$recognition_result = $template_manager->forceElementorRecognition($post_id);
			if (is_wp_error($recognition_result)) {
				error_log('[AEBG] ERROR: Failed to force Elementor recognition: ' . $recognition_result->get_error_message());
				return $recognition_result;
			}
			
			error_log('[AEBG] Elementor recognition forced successfully');
			
			// Sync the data again to ensure it's properly saved
			$sync_result = $template_manager->syncElementorData($post_id, $elementor_data);
			if (is_wp_error($sync_result)) {
				error_log('[AEBG] ERROR: Failed to sync Elementor data: ' . $sync_result->get_error_message());
				return $sync_result;
			}
			
			// Force Elementor CSS/JS generation for proper frontend rendering
			$this->forceElementorCSSGeneration($post_id);
			
			error_log('[AEBG] ===== SUCCESS: fixEmptyElementorPost completed =====');
			return true;
			
		} catch (\Exception $e) {
			error_log('[AEBG] ERROR in fixEmptyElementorPost: ' . $e->getMessage());
			return new \WP_Error('aebg_fix_failed', 'Failed to fix empty Elementor post: ' . $e->getMessage());
		}
	}

	/**
	 * Force Elementor to generate CSS and JS files for proper frontend rendering
	 *
	 * @param int $post_id The post ID
	 * @return bool Success or failure
	 */
	public function forceElementorCSSGeneration($post_id) {
		error_log('[AEBG] ===== START: forceElementorCSSGeneration =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		
		$process_start = microtime(true);
		$max_process_time = 30;
		
		try {
			if (!class_exists('\Elementor\Plugin')) {
				error_log('[AEBG] Elementor not active, skipping CSS generation');
				return false;
			}
			
			$elementor = $this->getElementorInstance();
			if (!$elementor) {
				error_log('[AEBG] ⚠️ Cannot force Elementor CSS generation - Elementor instance unavailable (Cloud Library 403 error)');
				return false;
			}
		
			update_post_meta($post_id, '_elementor_edit_mode', 'builder');
		update_post_meta($post_id, '_elementor_template_type', 'wp-post');
		update_post_meta($post_id, '_elementor_version', '3.31.0');
		
		if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
			$document = $elementor->documents->get($post_id);
				if ($document) {
					error_log('[AEBG] Found Elementor document for post ' . $post_id);
					
					$process_elapsed = microtime(true) - $process_start;
					if ($process_elapsed > $max_process_time) {
						error_log('[AEBG] ⚠️ forceElementorCSSGeneration exceeded timeout, aborting');
						return false;
					}
					
					$elementor_data = get_post_meta($post_id, '_elementor_data', true);
					if (!empty($elementor_data)) {
						$elementor_data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
						
						// CRITICAL: Sanitize data structure before passing to Elementor to prevent errors
						if (is_array($elementor_data)) {
							error_log('[AEBG] Sanitizing Elementor data structure before passing to Elementor...');
							$sanitized_data = $this->sanitizeElementorDataStructure($elementor_data);
							
							// CRITICAL: Save sanitized data to database BEFORE document->save() so Elementor reads clean data
							$cleaned_data = $this->cleanElementorDataForEncoding($sanitized_data);
							$encoded_data = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
							if ($encoded_data !== false) {
								update_post_meta($post_id, '_elementor_data', $encoded_data);
								error_log('[AEBG] Saved sanitized Elementor data to database');
								
								// Clear Elementor cache to force it to use the new clean data
								wp_cache_delete($post_id, 'post_meta');
								if (method_exists($elementor, 'files_manager')) {
									$elementor->files_manager->clear_cache();
								}
								error_log('[AEBG] Cleared Elementor cache after saving sanitized data');
							} else {
								error_log('[AEBG] ⚠️ WARNING: Failed to encode sanitized data, using in-memory version');
							}
							
							$elementor_data = $sanitized_data;
							error_log('[AEBG] Elementor data structure sanitized');
						}
						
						$process_elapsed = microtime(true) - $process_start;
						if ($process_elapsed > $max_process_time) {
							error_log('[AEBG] ⚠️ forceElementorCSSGeneration exceeded timeout before document save, aborting');
							return false;
						}
						
						if (method_exists($document, 'save')) {
							try {
								$document->save([
									'elements' => $elementor_data,
									'settings' => $document->get_settings()
								]);
								error_log('[AEBG] Forced document save with sanitized elements data for post ' . $post_id);
							} catch (\Throwable $e) {
								error_log('[AEBG] ERROR in document->save(): ' . $e->getMessage());
							}
						}
					}
					
					$process_elapsed = microtime(true) - $process_start;
					if ($process_elapsed > $max_process_time) {
						error_log('[AEBG] ⚠️ forceElementorCSSGeneration exceeded timeout before CSS generation, aborting');
						return false;
					}
					
					if (class_exists('\Elementor\Core\Files\CSS\Post')) {
						try {
							$css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
							if ($css_file) {
								$css_file->delete();
								error_log('[AEBG] Deleted existing CSS file for post ' . $post_id);
								
								$css_update_start = microtime(true);
								$css_file->update();
								$css_update_elapsed = microtime(true) - $css_update_start;
								if ($css_update_elapsed > 10) {
									error_log('[AEBG] ⚠️ CSS file->update() took ' . round($css_update_elapsed, 2) . 's (slow)');
								}
								error_log('[AEBG] Generated and saved CSS file for post ' . $post_id);
							}
						} catch (\Throwable $e) {
							error_log('[AEBG] ERROR handling CSS file: ' . $e->getMessage());
						}
					}
					
					$process_elapsed = microtime(true) - $process_start;
					if ($process_elapsed > $max_process_time) {
						error_log('[AEBG] ⚠️ forceElementorCSSGeneration exceeded timeout before JS generation, aborting');
						return false;
					}
					
					if (class_exists('\Elementor\Core\Files\JS\Post')) {
						try {
							$js_file = \Elementor\Core\Files\JS\Post::create($post_id);
							if ($js_file) {
								$js_file->delete();
								error_log('[AEBG] Deleted existing JS file for post ' . $post_id);
								
								$js_update_start = microtime(true);
								$js_file->update();
								$js_update_elapsed = microtime(true) - $js_update_start;
								if ($js_update_elapsed > 10) {
									error_log('[AEBG] ⚠️ JS file->update() took ' . round($js_update_elapsed, 2) . 's (slow)');
								}
								error_log('[AEBG] Generated and saved JS file for post ' . $post_id);
							}
						} catch (\Throwable $e) {
							error_log('[AEBG] ERROR handling JS file: ' . $e->getMessage());
						}
					}
					
					$this->clearElementorCache($post_id);
					
					if (function_exists('wp_cache_delete')) {
						wp_cache_delete($post_id, 'posts');
						wp_cache_delete($post_id, 'post_meta');
					}
					
					do_action('elementor/core/files/clear_cache');
					do_action('elementor/css-file/clear_cache');
					do_action('elementor/js-file/clear_cache');
					
					if (method_exists($elementor, 'files_manager')) {
						$elementor->files_manager->clear_cache();
						error_log('[AEBG] Cleared Elementor files manager cache');
					}
					
					if (method_exists($elementor, 'kits_manager')) {
						$elementor->kits_manager->clear_cache();
						error_log('[AEBG] Cleared Elementor kits manager cache');
					}
					
					wp_update_post([
						'ID' => $post_id,
						'post_modified' => current_time('mysql'),
						'post_modified_gmt' => current_time('mysql', 1)
					]);
					
					usleep(100000);
					
					error_log('[AEBG] CSS/JS generation completed successfully for post ' . $post_id);
					return true;
					
				} else {
					error_log('[AEBG] Elementor document not found for post ' . $post_id);
					if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'create')) {
						try {
							$document = $elementor->documents->create($post_id, 'post');
							if ($document) {
								error_log('[AEBG] Created Elementor document for post ' . $post_id);
								return $this->forceElementorCSSGeneration($post_id);
							}
						} catch (\Exception $e) {
							error_log('[AEBG] Failed to create Elementor document: ' . $e->getMessage());
						}
					}
					return false;
				}
			} else {
				error_log('[AEBG] Elementor documents method not available');
				return false;
			}
			
		} catch (\Exception $e) {
			error_log('[AEBG] Error in forceElementorCSSGeneration: ' . $e->getMessage());
			error_log('[AEBG] Error trace: ' . $e->getTraceAsString());
			return false;
		}
	}

	/**
	 * Analyze Elementor template for product variables and validate product count
	 *
	 * @param int $template_id The template ID
	 * @param int $selected_product_count The number of products selected by user
	 * @return array|\WP_Error Array with validation info or WP_Error on failure
	 */
	public function validateTemplateProductCount($template_id, $selected_product_count) {
		error_log('[AEBG] validateTemplateProductCount called with template_id: ' . $template_id . ', selected_count: ' . $selected_product_count);
		
		if (empty($template_id) || !is_numeric($template_id)) {
			return new \WP_Error('aebg_invalid_template_id', 'Invalid template ID provided');
		}
		
		$template_post = get_post($template_id);
		if (!$template_post) {
			return new \WP_Error('aebg_template_not_found', 'Template post not found');
		}
		
		$elementor_data = get_post_meta($template_id, '_elementor_data', true);
		if (empty($elementor_data)) {
			return new \WP_Error('aebg_no_elementor_data', 'No Elementor data found in template');
		}

		error_log('[AEBG] Raw Elementor data type: ' . gettype($elementor_data));
		error_log('[AEBG] Raw Elementor data: ' . substr($elementor_data, 0, 200) . '...');

		$elementor_data = is_string($elementor_data) ? $this->decodeJsonWithUnicode($elementor_data) : $elementor_data;
		if ($elementor_data === false) {
			return new \WP_Error('aebg_invalid_template', 'Failed to decode Elementor template data');
		}
		
		if (!is_array($elementor_data)) {
			return new \WP_Error('aebg_invalid_template', 'Invalid Elementor template data');
		}
		
		$template_analysis = $this->findProductVariablesInTemplate($elementor_data);
		$product_variables = $template_analysis['variables'];
		$css_ids = $template_analysis['css_ids'];
		
		$max_from_variables = $this->getMaxProductNumber($product_variables);
		$max_from_css_ids = $this->getMaxProductNumber($css_ids);
		$max_product_number = max($max_from_variables, $max_from_css_ids);
		
		error_log('[AEBG] Template analysis - Found ' . count($product_variables) . ' product variables: ' . implode(', ', $product_variables));
		error_log('[AEBG] Template analysis - Found ' . count($css_ids) . ' CSS IDs: ' . implode(', ', $css_ids));
		error_log('[AEBG] Max product number from variables: ' . $max_from_variables . ', from CSS IDs: ' . $max_from_css_ids . ', final: ' . $max_product_number);
		
		$is_valid = $selected_product_count >= $max_product_number;
		
		error_log('[AEBG] Validation result - Selected: ' . $selected_product_count . ', Required: ' . $max_product_number . ', Valid: ' . ($is_valid ? 'yes' : 'no'));
		
		return [
			'is_valid' => $is_valid,
			'selected_count' => $selected_product_count,
			'required_count' => $max_product_number,
			'product_variables' => $product_variables,
			'css_ids' => $css_ids,
			'template_title' => $template_post->post_title,
			'error_message' => $is_valid ? '' : $this->generateValidationErrorMessage($selected_product_count, $max_product_number, $template_post->post_title, $product_variables, $css_ids)
		];
	}
	
	/**
	 * Get Elementor data for a post
	 *
	 * @param int $post_id The post ID
	 * @return array|\WP_Error Elementor data or error
	 */
	public function getElementorData($post_id) {
		if (class_exists('\Elementor\Plugin')) {
			try {
				$elementor = $this->getElementorInstance();
				if ($elementor && isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
					$document = $elementor->documents->get($post_id);
					if ($document && method_exists($document, 'get_elements_data')) {
						$data = $document->get_elements_data();
						if (is_array($data)) {
							error_log('[AEBG] Successfully retrieved Elementor data using native API');
							return $data;
						}
					}
				}
			} catch (\Exception $e) {
				error_log('[AEBG] Elementor native API failed: ' . $e->getMessage());
			}
		}

		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		
		if (empty($elementor_data)) {
			error_log('[AEBG] No Elementor data found in post meta');
			return new \WP_Error('aebg_no_elementor_data', 'No Elementor data found in post');
		}

		if (is_array($elementor_data)) {
			error_log('[AEBG] Elementor data is already an array, returning directly');
			return $elementor_data;
		}

		if (is_string($elementor_data)) {
			error_log('[AEBG] Elementor data is a string, attempting to decode');
			
			// First try direct decode
			$decoded_data = json_decode($elementor_data, true);
			if ($decoded_data !== null) {
				error_log('[AEBG] Direct JSON decode succeeded');
				return $decoded_data;
			}
			
			// If direct decode failed, try cleaning the JSON first (like old version)
			error_log('[AEBG] Direct JSON decode failed: ' . json_last_error_msg() . ', trying to clean JSON');
			$cleaned_json = $this->cleanJsonString($elementor_data);
			if ($cleaned_json !== false) {
				$decoded_data = json_decode($cleaned_json, true);
				if ($decoded_data !== null) {
					error_log('[AEBG] JSON decode succeeded after cleaning');
					return $decoded_data;
				} else {
					error_log('[AEBG] JSON decode failed even after cleaning: ' . json_last_error_msg());
				}
			}
			
			// If all else fails, log the problematic data and return error
			error_log('[AEBG] JSON decode failed completely. First 200 chars: ' . substr($elementor_data, 0, 200));
			return new \WP_Error('aebg_json_decode_failed', 'Failed to decode Elementor data: ' . json_last_error_msg());
		}

		return new \WP_Error('aebg_invalid_elementor_data', 'Invalid Elementor data type: ' . gettype($elementor_data));
	}

	/**
	 * Regenerate content for a specific product container
	 *
	 * @param int $post_id The post ID
	 * @param int $product_number The product number (1-based)
	 * @param array $product The product data
	 * @return bool|\WP_Error
	 */
	public function regenerateProductContent($post_id, $product_number, $product) {
		// CRITICAL: Check if Action Scheduler replacement is scheduled or in progress - if so, skip old workflow
		$use_action_scheduler = get_option('aebg_use_action_scheduler_for_replacements', true);
		if ($use_action_scheduler && class_exists('\AEBG\Core\ProductReplacementScheduler')) {
			// Check if replacement is in progress (actions scheduled or running)
			$is_in_progress = \AEBG\Core\ProductReplacementScheduler::isReplacementInProgress($post_id, $product_number);
			
			// Also check if a checkpoint exists (indicates Action Scheduler workflow has started)
			$checkpoint = \AEBG\Core\CheckpointManager::loadReplacementCheckpoint($post_id, $product_number);
			$has_checkpoint = !empty($checkpoint);
			
			// CRITICAL: Also check if Action Scheduler was just scheduled (within last 5 seconds)
			// This catches the case where the shutdown hook runs before Step 1 creates the checkpoint
			$recently_scheduled = false;
			if (class_exists('\AEBG\Core\ActionSchedulerHelper')) {
				$group = "aebg_replace_{$post_id}_{$product_number}";
				$hook = 'aebg_replace_product_step_1';
				$recently_scheduled = \AEBG\Core\ActionSchedulerHelper::action_exists($hook, [$post_id, $product_number], $group);
			}
			
			if ($is_in_progress || $has_checkpoint || $recently_scheduled) {
				$reason = $is_in_progress ? 'in progress' : ($has_checkpoint ? 'checkpoint exists' : 'action scheduled');
				error_log('[AEBG] ⚠️ Action Scheduler replacement is ' . $reason . ' for post ' . $post_id . ', product ' . $product_number . ' - skipping old synchronous workflow');
				return true; // Return success to prevent errors, but don't actually regenerate
			}
		}
		
		try {
			error_log('[AEBG] Regenerating content for post ' . $post_id . ', product ' . $product_number . ' (LEGACY METHOD)');
			
			$post = get_post($post_id);
			if (!$post) {
				return new \WP_Error('post_not_found', 'Post not found');
			}

			$api_key = $this->settings['openai_api_key'] ?? '';
			$ai_model = $this->settings['ai_model'] ?? 'gpt-3.5-turbo';

			if (empty($api_key)) {
				return new \WP_Error('api_key_missing', 'OpenAI API key not configured');
			}

			$products = get_post_meta($post_id, '_aebg_products', true);
			if (!is_array($products)) {
				$products = [];
			}

			// CRITICAL: Enrich the NEW product data BEFORE adding it to the array
			// This ensures we're enriching the product that was just added/replaced, not the old one
			error_log('[AEBG] Enriching NEW product data before regeneration...');
			$enriched_new_product = $this->enrichSingleProduct($product, $api_key, $ai_model);
			
			$product_index = $product_number - 1;
			if (isset($products[$product_index])) {
				$products[$product_index] = $enriched_new_product;
			} else {
				while (count($products) < $product_index) {
					$products[] = null;
				}
				$products[$product_index] = $enriched_new_product;
			}

			error_log('[AEBG] Products array structure preserved: ' . json_encode(array_keys($products)));
			error_log('[AEBG] ✅ NEW product enriched and added to products array at index ' . $product_index);

			$context = [
				'title' => $post->post_title,
				'product_count' => count($products),
				'products' => $products,
				'regenerating_product' => $product_number
			];

			$elementor_data = get_post_meta($post_id, '_elementor_data', true);
			if (empty($elementor_data)) {
				return new \WP_Error('no_elementor_data', 'No Elementor data found for this post');
			}

			$elementor_data = is_string($elementor_data) ? $this->decodeJsonWithUnicode($elementor_data) : $elementor_data;
			if ($elementor_data === false) {
				return new \WP_Error('invalid_elementor_data', 'Failed to decode Elementor data with Unicode support');
			}
			
			if (!is_array($elementor_data)) {
				return new \WP_Error('invalid_elementor_data', 'Invalid Elementor data');
			}

			$processed_elementor_data = $this->deepCopyArray($elementor_data);
			error_log('[AEBG] Created deep copy of existing Elementor data for regeneration');

			$css_id = 'product-' . $product_number;
			error_log('[AEBG] Looking for product container with CSS ID: ' . $css_id);

			$processed_elementor_data = $this->regenerateProductContainerContent(
				$processed_elementor_data, 
				$css_id, 
				$post->post_title, 
				$products, 
				$context, 
				$api_key, 
				$ai_model
			);

			if (is_wp_error($processed_elementor_data)) {
				return $processed_elementor_data;
			}

			// NOTE: Variables are already updated during processContainerForRegeneration via updateProductVariablesInContainerAndChildren
			// No need to call updateProductVariablesInElementorData again - it would process ALL products redundantly
			error_log('[AEBG] Skipping redundant variable update - already updated during container regeneration');
			
			// Save the processed Elementor data (variables already updated during regeneration)
			$cleaned_data = $this->cleanElementorDataForEncoding($processed_elementor_data);
			$encoded_data = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($encoded_data === false) {
				error_log('[AEBG] Failed to encode processed Elementor data for content regeneration');
				return new \WP_Error('json_encoding_failed', 'Failed to encode processed Elementor data');
			}
			
			$update_result = update_post_meta($post_id, '_elementor_data', $encoded_data);
			if ($update_result === false) {
				error_log('[AEBG] Failed to update Elementor data in post meta for content regeneration');
				return new \WP_Error('update_failed', 'Failed to update Elementor data in post meta');
			}
			
			error_log('[AEBG] Elementor data saved successfully with regenerated content and updated variables');

			// CRITICAL: Clear Elementor cache and regenerate CSS/JS after content update
			error_log('[AEBG] Clearing Elementor cache and regenerating CSS/JS for post ' . $post_id);
			$this->clearElementorCache($post_id);
			
			// Force CSS/JS regeneration
			$elementor = $this->getElementorInstance();
			if ($elementor) {
				try {
					
					// Regenerate CSS file
					if (class_exists('\Elementor\Core\Files\CSS\Post')) {
						$css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
						if ($css_file) {
							$css_file->delete();
							$css_file->update(); // Generate and write the CSS file
							error_log('[AEBG] Regenerated Elementor CSS file for post ' . $post_id);
						}
					}
					
					// Regenerate JS file
					if (class_exists('\Elementor\Core\Files\JS\Post')) {
						$js_file = \Elementor\Core\Files\JS\Post::create($post_id);
						if ($js_file) {
							$js_file->delete();
							$js_file->update(); // Generate and write the JS file
							error_log('[AEBG] Regenerated Elementor JS file for post ' . $post_id);
						}
					}
					
					// Clear Elementor's internal caches
					if (method_exists($elementor, 'files_manager')) {
						$elementor->files_manager->clear_cache();
					}
					
					// Trigger Elementor hooks for complete refresh
					do_action('elementor/core/files/clear_cache');
					do_action('elementor/css-file/clear_cache');
					do_action('elementor/js-file/clear_cache');
					
					error_log('[AEBG] Elementor cache cleared and CSS/JS regenerated successfully');
				} catch (\Exception $e) {
					error_log('[AEBG] Error regenerating Elementor CSS/JS: ' . $e->getMessage());
					// Don't fail the entire operation if cache clearing fails
				}
			}
			
			return true;

			error_log('[AEBG] Content regeneration completed successfully for product ' . $product_number);
			return true;
			
		} catch (\Exception $e) {
			error_log('[AEBG] Error in regenerateProductContent: ' . $e->getMessage());
			return new \WP_Error('regeneration_error', $e->getMessage());
		}
	}

	/**
	 * Collect AI prompts for testvinder-only regeneration
	 * Similar to collectPromptsForProduct but only collects from testvinder container
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number (1-based)
	 * @return array|\WP_Error Result with elementor_data, testvinder_container, context_registry_state, and field_count
	 */
	public function collectPromptsForTestvinder($post_id, $product_number) {
		error_log('[AEBG] ===== COLLECTING PROMPTS FOR TESTVINDER REGENERATION =====');
		error_log('[AEBG] Post ID: ' . $post_id . ', Product: ' . $product_number);
		
		// Ensure context_registry is initialized
		if (!$this->context_registry) {
			$this->context_registry = new \AEBG\Core\ContextRegistry();
		}
		
		// Get Elementor data
		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		if (empty($elementor_data)) {
			return new \WP_Error('no_elementor_data', 'No Elementor data found for this post');
		}
		
		$elementor_data = is_string($elementor_data) ? $this->decodeJsonWithUnicode($elementor_data) : $elementor_data;
		if ($elementor_data === false || !is_array($elementor_data)) {
			return new \WP_Error('invalid_elementor_data', 'Invalid Elementor data');
		}
		
		// Find testvinder container (required for testvinder-only regeneration)
		$testvinder_container = $this->findTestvinderContainer($elementor_data, $product_number);
		if (!$testvinder_container) {
			error_log('[AEBG] ⚠️ Testvinder container not found: testvinder-' . $product_number);
			return new \WP_Error('testvinder_container_not_found', 'Testvinder container not found: testvinder-' . $product_number);
		}
		
		error_log('[AEBG] ✅ Found testvinder-' . $product_number . ' container for testvinder-only regeneration');
		
		// Clear context registry
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
		}
		
		// Collect prompts from testvinder container only
		$processed_testvinder = $this->deepCopyArray($testvinder_container);
		$field_prefix = 'testvinder-' . $product_number . '-';
		$this->collectAIPromptsInContainer($processed_testvinder, $product_number, $field_prefix);
		error_log('[AEBG] ✅ Collected prompts from testvinder-' . $product_number . ' container');
		
		// Export context registry state
		$context_registry_state = $this->context_registry ? $this->context_registry->exportState() : [];
		$field_count = count($context_registry_state['contexts'] ?? []);
		
		error_log('[AEBG] ✅ Collected ' . $field_count . ' AI prompts for testvinder-' . $product_number);
		
		return [
			'elementor_data' => $elementor_data,
			'testvinder_container' => $processed_testvinder,
			'context_registry_state' => $context_registry_state,
			'field_count' => $field_count
		];
	}
	
	/**
	 * Apply generated content to testvinder container only
	 * Similar to applyContentForProduct but only applies to testvinder container
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number (1-based)
	 * @param array $elementor_data Full Elementor data
	 * @param array $testvinder_container Processed testvinder container with field_ids
	 * @param array $context_registry_state Context registry state from Step 2
	 * @return array|\WP_Error Updated Elementor data or WP_Error
	 */
	public function applyContentForTestvinder($post_id, $product_number, $elementor_data, $testvinder_container, $context_registry_state = []) {
		error_log('[AEBG] ===== APPLYING CONTENT FOR TESTVINDER REGENERATION =====');
		error_log('[AEBG] Post ID: ' . $post_id . ', Product: ' . $product_number);
		
		// Ensure context_registry is initialized
		if (!$this->context_registry) {
			$this->context_registry = new \AEBG\Core\ContextRegistry();
		}
		
		// Restore context registry state if provided
		if (!empty($context_registry_state) && method_exists($this->context_registry, 'importState')) {
			$this->context_registry->importState($context_registry_state);
			$field_count = count($context_registry_state['contexts'] ?? []);
			error_log('[AEBG] ✅ Restored context registry state with ' . $field_count . ' fields');
		} else {
			error_log('[AEBG] ⚠️ No context registry state provided or importState method not available');
		}
		
		// Get products and title
		$products = get_post_meta($post_id, '_aebg_products', true);
		if (empty($products) || !is_array($products)) {
			return new \WP_Error('no_products', 'No products found for this post');
		}
		
		// CRITICAL: Enrich products with Datafeedr context, optimized names, and normalized URLs/images
		// OPTIMIZATION: Only enrich the target product being regenerated
		$api_key = $this->settings['openai_api_key'] ?? '';
		$ai_model = $this->settings['ai_model'] ?? 'gpt-3.5-turbo';
		error_log('[AEBG] Enriching products before applying testvinder content...');
		$products = $this->enrichProductsForRegeneration($products, $api_key, $ai_model, $product_number);
		
		$post = get_post($post_id);
		if (!$post) {
			return new \WP_Error('post_not_found', 'Post not found');
		}
		
		$title = $post->post_title;
		
		// Process testvinder container only using unified method
		$testvinder_css_id = 'testvinder-' . $product_number;
		$updated_data = $this->processContainerForReplacement($elementor_data, $testvinder_css_id, $testvinder_container, $products, $title, $product_number);
		
		if (is_wp_error($updated_data)) {
			error_log('[AEBG] ⚠️ Error processing testvinder container: ' . $updated_data->get_error_message());
			return $updated_data;
		}
		
		error_log('[AEBG] ✅ Successfully processed testvinder-' . $product_number . ' container');
		
		return $updated_data;
	}
	
	/**
	 * Collect AI prompts from a specific product container (optimized for Action Scheduler workflow)
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number (1-based)
	 * @return array|\WP_Error Array with elementor_data and context_registry_state, or WP_Error
	 */
	public function collectPromptsForProduct($post_id, $product_number) {
		error_log('[AEBG] ===== COLLECTING PROMPTS FOR PRODUCT REPLACEMENT =====');
		error_log('[AEBG] Post ID: ' . $post_id . ', Product: ' . $product_number);
		
		// Ensure context_registry is initialized
		if (!$this->context_registry) {
			$this->context_registry = new \AEBG\Core\ContextRegistry();
		}
		
		// Get Elementor data
		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		if (empty($elementor_data)) {
			return new \WP_Error('no_elementor_data', 'No Elementor data found for this post');
		}
		
		$elementor_data = is_string($elementor_data) ? $this->decodeJsonWithUnicode($elementor_data) : $elementor_data;
		if ($elementor_data === false || !is_array($elementor_data)) {
			return new \WP_Error('invalid_elementor_data', 'Invalid Elementor data');
		}
		
		// Find product container
		$css_id = 'product-' . $product_number;
		$container = $this->findProductContainer($elementor_data, $css_id);
		
		if (!$container) {
			error_log('[AEBG] ⚠️ Product container not found: ' . $css_id);
			return new \WP_Error('container_not_found', 'Product container not found: ' . $css_id);
		}
		
		// Find testvinder container (if it exists)
		$testvinder_container = $this->findTestvinderContainer($elementor_data, $product_number);
		if ($testvinder_container) {
			error_log('[AEBG] ✅ Found testvinder-' . $product_number . ' container for product-' . $product_number . ' replacement');
		} else {
			error_log('[AEBG] ℹ️ No testvinder-' . $product_number . ' container found (this is OK if not used)');
		}
		
		// Clear context registry
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
		}
		
		// Collect prompts from product container (only those referencing target product)
		$processed_container = $this->deepCopyArray($container);
		$this->collectAIPromptsInContainer($processed_container, $product_number);
		
		// Collect prompts from testvinder container (if it exists)
		$processed_testvinder = null;
		if ($testvinder_container) {
			$processed_testvinder = $this->deepCopyArray($testvinder_container);
			$field_prefix = 'testvinder-' . $product_number . '-';
			$this->collectAIPromptsInContainer($processed_testvinder, $product_number, $field_prefix);
			error_log('[AEBG] ✅ Collected prompts from testvinder-' . $product_number . ' container');
		}
		
		// Export context registry state
		$context_registry_state = $this->context_registry ? $this->context_registry->exportState() : [];
		$field_count = count($context_registry_state['contexts'] ?? []);
		
		error_log('[AEBG] ✅ Collected ' . $field_count . ' AI prompts for product-' . $product_number . (($testvinder_container) ? ' (including testvinder)' : ''));
		
		return [
			'elementor_data' => $elementor_data,
			'container' => $processed_container,
			'testvinder_container' => $processed_testvinder,
			'context_registry_state' => $context_registry_state,
			'field_count' => $field_count
		];
	}

	/**
	 * Collect AI prompts from a container, filtering by product number
	 * 
	 * @param array $container Container data (passed by reference)
	 * @param int $target_product_number Target product number
	 * @param string $field_id_prefix Optional prefix for field IDs (e.g., 'testvinder-1-')
	 * @return void
	 */
	private function collectAIPromptsInContainer(&$container, $target_product_number, $field_id_prefix = '') {
		if (!is_array($container)) {
			return;
		}
		
		// Process widgets
		if (isset($container['elType']) && $container['elType'] === 'widget') {
			$settings = &$container['settings'];
			if (!is_array($settings)) {
				return;
			}
			
			// Check for widget-level AI settings
			if (isset($settings['aebg_ai_enable']) && $settings['aebg_ai_enable'] === 'yes') {
				$widget_type = $container['widgetType'] ?? 'unknown';
				$prompt = $settings['aebg_ai_prompt'] ?? '';
				$trimmed_prompt = trim($prompt);
				
				// Skip variable-only prompts (image/URL/name/price/brand/etc.)
				// These should be replaced directly, not sent to AI
				$is_image_variable = preg_match('/^\{product-\d+-image\}$/', $trimmed_prompt);
				$is_url_variable = preg_match('/^\{product-\d+-url\}$/', $trimmed_prompt);
				$is_affiliate_url_variable = preg_match('/^\{product-\d+-affiliate-url\}$/', $trimmed_prompt);
				$is_name_variable = preg_match('/^\{product-\d+-name\}$/', $trimmed_prompt);
				$is_price_variable = preg_match('/^\{product-\d+-price\}$/', $trimmed_prompt);
				$is_brand_variable = preg_match('/^\{product-\d+-brand\}$/', $trimmed_prompt);
				$is_rating_variable = preg_match('/^\{product-\d+-rating\}$/', $trimmed_prompt);
				$is_description_variable = preg_match('/^\{product-\d+-description\}$/', $trimmed_prompt);
				$is_merchant_variable = preg_match('/^\{product-\d+-merchant\}$/', $trimmed_prompt);
				$is_category_variable = preg_match('/^\{product-\d+-category\}$/', $trimmed_prompt);
				// Check for any single variable pattern (e.g., {product-2}, {product-2-name}, etc.)
				$is_single_variable = preg_match('/^\{product-\d+(-[a-z-]+)?\}$/', $trimmed_prompt);
				
				if ($is_image_variable || $is_url_variable || $is_affiliate_url_variable || 
					$is_name_variable || $is_price_variable || $is_brand_variable || 
					$is_rating_variable || $is_description_variable || $is_merchant_variable || 
					$is_category_variable || $is_single_variable) {
					// Skip - will be handled directly in apply phase via variable replacement
					error_log('[AEBG] Skipping AI for variable-only prompt: ' . $trimmed_prompt);
					return;
				}
				
				// Only register if prompt references target product
				if (!empty($trimmed_prompt) && $this->promptReferencesProduct($trimmed_prompt, $target_product_number)) {
					$field_id = $field_id_prefix . uniqid('ai_field_');
					
					if ($this->context_registry) {
						$this->context_registry->registerField($field_id, [
							'widget_type' => $widget_type,
							'prompt' => $prompt,
							'original_content' => $this->getOriginalContentForWidget($container, $widget_type),
							'dependencies' => $this->extractDependencies($prompt)
						]);
					}
					
					$settings['aebg_field_id'] = $field_id;
					error_log('[AEBG] Registered AI prompt for widget ' . $widget_type . ' (references product-' . $target_product_number . ', field_id: ' . $field_id . ')');
				}
			}
			
			// Check for icon list AI settings
			if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
				foreach ($settings['icon_list'] as $index => &$icon_item) {
					if (isset($icon_item['aebg_iconlist_ai_enable']) && $icon_item['aebg_iconlist_ai_enable'] === 'yes') {
						$icon_prompt = trim($icon_item['aebg_iconlist_ai_prompt'] ?? '');
						
						// CRITICAL: Skip variable-only prompts for icon-list items too
						$is_image_variable = preg_match('/^\{product-\d+-image\}$/', $icon_prompt);
						$is_url_variable = preg_match('/^\{product-\d+-url\}$/', $icon_prompt);
						$is_affiliate_url_variable = preg_match('/^\{product-\d+-affiliate-url\}$/', $icon_prompt);
						$is_name_variable = preg_match('/^\{product-\d+-name\}$/', $icon_prompt);
						$is_price_variable = preg_match('/^\{product-\d+-price\}$/', $icon_prompt);
						$is_brand_variable = preg_match('/^\{product-\d+-brand\}$/', $icon_prompt);
						$is_rating_variable = preg_match('/^\{product-\d+-rating\}$/', $icon_prompt);
						$is_description_variable = preg_match('/^\{product-\d+-description\}$/', $icon_prompt);
						$is_merchant_variable = preg_match('/^\{product-\d+-merchant\}$/', $icon_prompt);
						$is_category_variable = preg_match('/^\{product-\d+-category\}$/', $icon_prompt);
						$is_single_variable = preg_match('/^\{product-\d+(-[a-z-]+)?\}$/', $icon_prompt);
						
						if ($is_image_variable || $is_url_variable || $is_affiliate_url_variable || 
							$is_name_variable || $is_price_variable || $is_brand_variable || 
							$is_rating_variable || $is_description_variable || $is_merchant_variable || 
							$is_category_variable || $is_single_variable) {
							// Skip - will be handled directly via variable replacement
							error_log('[AEBG] Skipping AI for icon-list item ' . $index . ' - variable-only prompt: ' . $icon_prompt);
							continue;
						}
						
						// Only register if prompt references target product
						if (!empty($icon_prompt) && $this->promptReferencesProduct($icon_prompt, $target_product_number)) {
							$field_id = $field_id_prefix . uniqid('ai_iconlist_');
							
							if ($this->context_registry) {
								$this->context_registry->registerField($field_id, [
									'widget_type' => 'icon-list-item',
									'prompt' => $icon_prompt,
									'original_content' => $icon_item['text'] ?? '',
									'dependencies' => $this->extractDependencies($icon_prompt),
									'parent_widget' => $container['widgetType'] ?? 'unknown',
									'icon_index' => $index
								]);
							}
							
							$icon_item['aebg_field_id'] = $field_id;
							error_log('[AEBG] Registered AI prompt for icon-list item ' . $index . ' (references product-' . $target_product_number . ', field_id: ' . $field_id . ')');
						}
					}
				}
				unset($icon_item);
			}
		}
		
		// Recursively process children
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as &$element) {
				$this->collectAIPromptsInContainer($element, $target_product_number, $field_id_prefix);
			}
			unset($element);
		}
		
		if (isset($container['content']) && is_array($container['content'])) {
			foreach ($container['content'] as &$content_item) {
				$this->collectAIPromptsInContainer($content_item, $target_product_number, $field_id_prefix);
			}
			unset($content_item);
		}
	}

	/**
	 * Enrich products with Datafeedr context, optimized names, and normalized URLs/images
	 * This ensures products have the same enriched data as bulk generation
	 * 
	 * @param array $products Products array
	 * @param string $api_key OpenAI API key
	 * @param string $ai_model AI model
	 * @return array Enriched products array
	 */
	/**
	 * Enrich a single product with Datafeedr data, normalized URLs/images, and optimized name
	 * 
	 * @param array $product Product data to enrich
	 * @param string $api_key OpenAI API key (for name optimization)
	 * @param string $ai_model AI model name
	 * @return array Enriched product data
	 */
	public function enrichSingleProduct($product, $api_key, $ai_model) {
		if (empty($product) || !is_array($product)) {
			return $product;
		}
		
		$product_id = $product['id'] ?? '';
		$product_name = $product['name'] ?? '';
		
		error_log('[AEBG] Enriching NEW product: ' . $product_name . ' (ID: ' . $product_id . ')');
		
		$datafeedr = new \AEBG\Core\Datafeedr();
		
		// 1. Enhance with Datafeedr database data (if available)
		if (!empty($product_id)) {
			$db_product_data = $datafeedr->get_product_data_from_database($product_id);
			if ($db_product_data && is_array($db_product_data)) {
				// Merge database data, prioritizing database data for missing fields
				$product = array_merge($product, $db_product_data);
				error_log('[AEBG] ✅ Enhanced with database data for product ' . $product_id);
			}
		}
		
		// 2. Normalize image URL (ensure image_url is set)
		if (empty($product['image_url']) && !empty($product['image'])) {
			$product['image_url'] = $product['image'];
		}
		if (empty($product['image']) && !empty($product['image_url'])) {
			$product['image'] = $product['image_url'];
		}
		
		// 3. Normalize URL fields (ensure url and affiliate_url are set)
		if (empty($product['url']) && !empty($product['product_url'])) {
			$product['url'] = $product['product_url'];
		}
		if (empty($product['affiliate_url']) && !empty($product['url'])) {
			// Process affiliate URL with @@@ placeholder if needed
			$shortcodes = new \AEBG\Core\Shortcodes();
			$product['affiliate_url'] = $shortcodes->process_affiliate_link($product['url']);
		}
		
		// 4. Optimize product name (add short_name if missing)
		if (empty($product['short_name']) && !empty($product_name) && !empty($api_key) && $this->product_finder) {
			// Optimize the product name using ProductFinder
			$single_product = [$product];
			$optimized = $this->product_finder->optimizeProductNames($single_product, $api_key, $ai_model);
			if (!empty($optimized[0])) {
				$product = $optimized[0];
				error_log('[AEBG] ✅ Product name optimized');
			}
		}
		
		return $product;
	}

	public function enrichProductsForRegeneration($products, $api_key, $ai_model, $target_product_number = null) {
		if (empty($products) || !is_array($products)) {
			return $products;
		}
		
		error_log('[AEBG] ===== ENRICHING PRODUCTS FOR REGENERATION =====');
		error_log('[AEBG] Products count: ' . count($products));
		if ($target_product_number !== null) {
			error_log('[AEBG] ⚡ OPTIMIZED: Only enriching product-' . $target_product_number . ' (index: ' . ($target_product_number - 1) . ')');
		} else {
			error_log('[AEBG] Enriching all products (bulk generation mode)');
		}
		
		$datafeedr = new \AEBG\Core\Datafeedr();
		$enriched_products = [];
		
		// Determine which products to enrich
		$products_to_enrich = [];
		if ($target_product_number !== null && $target_product_number > 0) {
			// Only enrich the target product
			$target_index = $target_product_number - 1;
			if (isset($products[$target_index])) {
				$products_to_enrich[$target_index] = $products[$target_index];
			} else {
				error_log('[AEBG] ⚠️ WARNING: Target product index ' . $target_index . ' (product-' . $target_product_number . ') not found in products array');
			}
		} else {
			// Enrich all products (bulk generation mode)
			$products_to_enrich = $products;
		}
		
		foreach ($products as $index => $product) {
			if (empty($product) || !is_array($product)) {
				$enriched_products[] = $product;
				continue;
			}
			
			// Only enrich if this is the target product (or if no target specified, enrich all)
			$should_enrich = ($target_product_number === null) || (($index + 1) === $target_product_number);
			
			if ($should_enrich) {
				$product_id = $product['id'] ?? '';
				$product_name = $product['name'] ?? '';
				
				error_log('[AEBG] Enriching product ' . ($index + 1) . ': ' . $product_name . ' (ID: ' . $product_id . ')');
				
				// 1. Enhance with Datafeedr database data (if available)
				if (!empty($product_id)) {
					$db_product_data = $datafeedr->get_product_data_from_database($product_id);
					if ($db_product_data && is_array($db_product_data)) {
						// Merge database data, prioritizing database data for missing fields
						$product = array_merge($product, $db_product_data);
						error_log('[AEBG] ✅ Enhanced with database data for product ' . $product_id);
					}
				}
				
				// 2. Normalize image URL (ensure image_url is set)
				if (empty($product['image_url']) && !empty($product['image'])) {
					$product['image_url'] = $product['image'];
				}
				if (empty($product['image']) && !empty($product['image_url'])) {
					$product['image'] = $product['image_url'];
				}
				
				// 3. Normalize URL fields (ensure url and affiliate_url are set)
				if (empty($product['url']) && !empty($product['product_url'])) {
					$product['url'] = $product['product_url'];
				}
				if (empty($product['affiliate_url']) && !empty($product['url'])) {
					// Process affiliate URL with @@@ placeholder if needed
					$shortcodes = new \AEBG\Core\Shortcodes();
					$product['affiliate_url'] = $shortcodes->process_affiliate_link($product['url']);
				}
				
				// 4. Optimize product name (add short_name if missing)
				if (empty($product['short_name']) && !empty($product_name) && !empty($api_key)) {
					// Only optimize if we have API key and product name
					// Note: This is a lightweight check - full optimization happens in ProductFinder
					// For regeneration, we'll use existing short_name or fallback to name
					$product['short_name'] = $product_name; // Will be optimized by ProductFinder if needed
				}
			}
			
			$enriched_products[] = $product;
		}
		
		// 5. Optimize product names using ProductFinder (only for enriched products)
		if (!empty($api_key) && $this->product_finder) {
			if ($target_product_number !== null) {
				// Only optimize the target product's name
				$target_index = $target_product_number - 1;
				if (isset($enriched_products[$target_index])) {
					error_log('[AEBG] Optimizing product name for product-' . $target_product_number . '...');
					$single_product = [$enriched_products[$target_index]];
					$optimized = $this->product_finder->optimizeProductNames($single_product, $api_key, $ai_model);
					if (!empty($optimized[0])) {
						$enriched_products[$target_index] = $optimized[0];
						error_log('[AEBG] ✅ Product name optimized for product-' . $target_product_number);
					}
				}
			} else {
				// Optimize all product names (bulk generation mode)
				error_log('[AEBG] Optimizing product names...');
				$enriched_products = $this->product_finder->optimizeProductNames($enriched_products, $api_key, $ai_model);
				error_log('[AEBG] ✅ Product names optimized');
			}
		}
		
		error_log('[AEBG] ===== PRODUCT ENRICHMENT COMPLETE =====');
		
		return $enriched_products;
	}

	/**
	 * Apply generated content to a product container
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number (1-based)
	 * @param array $elementor_data Full Elementor data
	 * @param array $container Processed container with field_ids
	 * @param array $context_registry_state Context registry state from Step 2
	 * @param array|null $testvinder_container Testvinder container data from Step 1 (optional)
	 * @return array|\WP_Error Updated Elementor data or WP_Error
	 */
	public function applyContentForProduct($post_id, $product_number, $elementor_data, $container, $context_registry_state = [], $testvinder_container = null) {
		error_log('[AEBG] ===== APPLYING CONTENT FOR PRODUCT REPLACEMENT =====');
		error_log('[AEBG] Post ID: ' . $post_id . ', Product: ' . $product_number);
		
		// Ensure context_registry is initialized
		if (!$this->context_registry) {
			$this->context_registry = new \AEBG\Core\ContextRegistry();
		}
		
		// Restore context registry state if provided
		if (!empty($context_registry_state) && method_exists($this->context_registry, 'importState')) {
			$this->context_registry->importState($context_registry_state);
			$field_count = count($context_registry_state['contexts'] ?? []);
			error_log('[AEBG] ✅ Restored context registry state with ' . $field_count . ' fields');
			
			// Log icon-list field IDs for debugging
			$icon_list_fields = [];
			$icon_list_with_content = [];
			foreach ($context_registry_state['contexts'] ?? [] as $field_id => $field_data) {
				if (isset($field_data['widget_type']) && $field_data['widget_type'] === 'icon-list-item') {
					$icon_list_fields[] = $field_id;
					if (isset($field_data['generated_content']) && !empty($field_data['generated_content'])) {
						$icon_list_with_content[] = $field_id . ' (' . substr($field_data['generated_content'], 0, 30) . '...)';
					}
				}
			}
			if (!empty($icon_list_fields)) {
				error_log('[AEBG] 📋 Found ' . count($icon_list_fields) . ' icon-list fields in context registry');
				error_log('[AEBG] 📋 Icon-list field IDs: ' . implode(', ', array_slice($icon_list_fields, 0, 10)) . (count($icon_list_fields) > 10 ? '...' : ''));
				if (!empty($icon_list_with_content)) {
					error_log('[AEBG] ✅ Icon-list fields with generated content: ' . count($icon_list_with_content));
					error_log('[AEBG] 📝 Sample: ' . implode(' | ', array_slice($icon_list_with_content, 0, 3)));
				} else {
					error_log('[AEBG] ⚠️ WARNING: No icon-list fields have generated content!');
				}
			} else {
				error_log('[AEBG] ⚠️ WARNING: No icon-list fields found in context registry state!');
			}
		} else {
			error_log('[AEBG] ⚠️ No context registry state provided or importState method not available');
			if (empty($context_registry_state)) {
				error_log('[AEBG] ⚠️ context_registry_state is empty!');
			}
			if (!method_exists($this->context_registry, 'importState')) {
				error_log('[AEBG] ⚠️ context_registry->importState() method not available!');
			}
		}
		
		// Get products and title
		$products = get_post_meta($post_id, '_aebg_products', true);
		if (empty($products) || !is_array($products)) {
			return new \WP_Error('no_products', 'No products found for this post');
		}
		
		// CRITICAL: Enrich products with Datafeedr context, optimized names, and normalized URLs/images
		// OPTIMIZATION: Only enrich the target product being replaced, not all products
		$api_key = $this->settings['openai_api_key'] ?? '';
		$ai_model = $this->settings['ai_model'] ?? 'gpt-3.5-turbo';
		error_log('[AEBG] Enriching products before applying content...');
		$products = $this->enrichProductsForRegeneration($products, $api_key, $ai_model, $product_number);
		
		$post = get_post($post_id);
		if (!$post) {
			return new \WP_Error('post_not_found', 'Post not found');
		}
		
		$title = $post->post_title;
		
		// Process product container using unified method
		$css_id = 'product-' . $product_number;
		$updated_data = $this->processContainerForReplacement($elementor_data, $css_id, $container, $products, $title, $product_number);
		
		if (is_wp_error($updated_data)) {
			return $updated_data;
		}
		
		// Also handle testvinder container if it exists - use the SAME unified method
		if (!empty($testvinder_container)) {
			$testvinder_css_id = 'testvinder-' . $product_number;
			error_log('[AEBG] 🔄 Processing testvinder-' . $product_number . ' container');
			
			$updated_data = $this->processContainerForReplacement($updated_data, $testvinder_css_id, $testvinder_container, $products, $title, $product_number);
			
			if (is_wp_error($updated_data)) {
				error_log('[AEBG] ⚠️ Error processing testvinder container: ' . $updated_data->get_error_message());
				// Continue with product container update even if testvinder fails
				// Return the product container data (already processed successfully)
			} else {
				error_log('[AEBG] ✅ Successfully processed testvinder-' . $product_number . ' container');
			}
		}
		
		return $updated_data;
	}

	/**
	 * Process container for replacement - unified method for both product and testvinder containers
	 * This ensures consistent structure and prevents Elementor sanitizer errors
	 * 
	 * @param array $elementor_data Full Elementor data
	 * @param string $target_css_id Target container CSS ID (e.g., 'product-1' or 'testvinder-1')
	 * @param array $container Processed container with field_ids from Step 1
	 * @param array $products Products array
	 * @param string $title Post title
	 * @param int $target_product_number Target product number (for filtering icon-list items)
	 * @return array|\WP_Error Updated Elementor data or WP_Error
	 */
	private function processContainerForReplacement($elementor_data, $target_css_id, $container, $products, $title, $target_product_number) {
		// CRITICAL: Log container structure before replacement for debugging
		if (defined('WP_DEBUG') && WP_DEBUG && !empty($container)) {
			$container_icon_list_count = 0;
			$container_icon_list_with_field_id = 0;
			if (isset($container['elements']) && is_array($container['elements'])) {
				foreach ($container['elements'] as $element) {
					if (isset($element['widgetType']) && ($element['widgetType'] === 'icon-list' || $element['widgetType'] === 'aebg-icon-list')) {
						$container_icon_list_count++;
						if (isset($element['settings']['icon_list']) && is_array($element['settings']['icon_list'])) {
							foreach ($element['settings']['icon_list'] as $item) {
								if (isset($item['aebg_field_id'])) {
									$container_icon_list_with_field_id++;
								}
							}
						}
					}
				}
			}
			if ($container_icon_list_count > 0) {
				error_log('[AEBG] 📋 Container from Step 1 (' . $target_css_id . ') has ' . $container_icon_list_count . ' icon-list widgets with ' . $container_icon_list_with_field_id . ' items having field_id');
			}
		}
		
		// Step 1: Replace container in Elementor data (preserves structure)
		$updated_data = $this->replaceContainerInElementorData($elementor_data, $target_css_id, $container);
		
		if (is_wp_error($updated_data)) {
			return $updated_data;
		}
		
		// Step 2: Apply generated content from context registry (pass product_number for icon-list filtering)
		// CRITICAL: This must happen AFTER container replacement so we're working with the container that has field_ids
		$updated_data = $this->applyContentToContainer($updated_data, $target_css_id, $products, $title, $target_product_number);
		
		// Step 3: Update product variables in container
		$updated_data = $this->updateProductVariablesInContainer($updated_data, $target_css_id, $products, $title, $target_product_number);
		
		if (is_wp_error($updated_data)) {
			return $updated_data;
		}
		
		return $updated_data;
	}

	/**
	 * Apply generated content to a container from context registry
	 * 
	 * @param array $elementor_data Elementor data
	 * @param string $target_css_id Target container CSS ID
	 * @param array $products Products array
	 * @param string $title Post title
	 * @param int|null $target_product_number Target product number (for filtering icon-list items)
	 * @return array Updated Elementor data
	 */
	private function applyContentToContainer($elementor_data, $target_css_id, $products, $title, $target_product_number = null) {
		if (!is_array($elementor_data)) {
			return $elementor_data;
		}
		
		// Extract product number from CSS ID if not provided
		// Support both product-{number} and testvinder-{number} patterns
		if ($target_product_number === null) {
			if (preg_match('/product-(\d+)/', $target_css_id, $matches)) {
				$target_product_number = (int)$matches[1];
			} elseif (preg_match('/testvinder-(\d+)/', $target_css_id, $matches)) {
				$target_product_number = (int)$matches[1];
			}
		}
		
		// Find container and apply content
		return $this->applyContentToContainerRecursively($elementor_data, $target_css_id, $products, $title, false, $target_product_number);
	}

	/**
	 * Recursively apply content to container
	 * 
	 * @param array $data Elementor data
	 * @param string $target_css_id Target CSS ID
	 * @param array $products Products array
	 * @param string $title Post title
	 * @param bool $inside_target Whether we're inside the target container
	 * @return array Updated data
	 */
	private function applyContentToContainerRecursively($data, $target_css_id, $products, $title, $inside_target = false, $target_product_number = null) {
		if (!is_array($data)) {
			return $data;
		}

		// Extract target product number from CSS ID for filtering (if not provided)
		// Support both product-{number} and testvinder-{number} patterns
		if ($target_product_number === null) {
			if (preg_match('/product-(\d+)/', $target_css_id, $matches)) {
				$target_product_number = (int)$matches[1];
			} elseif (preg_match('/testvinder-(\d+)/', $target_css_id, $matches)) {
				$target_product_number = (int)$matches[1];
			}
		}

		// Handle numeric array
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$data[$index] = $this->applyContentToContainerRecursively($item, $target_css_id, $products, $title, $inside_target, $target_product_number);
			}
			return $data;
		}
		
		// Check if this is the target container
		$is_target = false;
		if (isset($data['settings']) && is_array($data['settings'])) {
			$element_css_id = $data['settings']['_element_id'] ?? $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '';
			if ($element_css_id === $target_css_id) {
				$is_target = true;
				$inside_target = true; // Mark that we're now inside the target
			}
		}
		
		// Process widgets (only in target container or its children)
		if (isset($data['elType']) && $data['elType'] === 'widget' && ($is_target || $inside_target)) {
			$settings = &$data['settings'];
			$widget_type = $data['widgetType'] ?? 'unknown';
			
			error_log('[AEBG] 🔧 Processing widget: ' . $widget_type . ' (inside target: ' . ($inside_target ? 'yes' : 'no') . ', target product: ' . ($target_product_number ?? 'null') . ')');
			
			// CRITICAL: Log icon-list widgets specifically for debugging
			if ($widget_type === 'aebg-icon-list' || $widget_type === 'icon-list') {
				$icon_list_count = isset($settings['icon_list']) && is_array($settings['icon_list']) ? count($settings['icon_list']) : 0;
				error_log('[AEBG] 📋 Found ' . $widget_type . ' widget with ' . $icon_list_count . ' items');
				if ($icon_list_count > 0 && defined('WP_DEBUG') && WP_DEBUG) {
					// Log first few icon-list items for debugging
					foreach (array_slice($settings['icon_list'], 0, 3) as $idx => $item) {
						$has_field_id = isset($item['aebg_field_id']) ? 'yes (id: ' . $item['aebg_field_id'] . ')' : 'no';
						$has_ai_enable = isset($item['aebg_iconlist_ai_enable']) && $item['aebg_iconlist_ai_enable'] === 'yes' ? 'yes' : 'no';
						error_log('[AEBG]   Item ' . $idx . ': field_id=' . $has_field_id . ', ai_enable=' . $has_ai_enable . ', text=' . substr($item['text'] ?? '', 0, 50));
					}
				}
			}
			
			// CRITICAL: Handle image variables FIRST, even if AI is not enabled
			// This ensures image widgets with {product-x-image} get updated regardless of AI settings
			if ($widget_type === 'image' && isset($settings['aebg_ai_prompt'])) {
				$prompt = trim($settings['aebg_ai_prompt'] ?? '');
				if (preg_match('/^\{product-(\d+)-image\}$/', $prompt, $matches)) {
					$product_num = (int)$matches[1];
					$product_index = $product_num - 1;
					error_log('[AEBG] Processing image variable for product-' . $product_num . ' in image widget (index: ' . $product_index . ')');
					
					if (isset($products[$product_index])) {
						$product = $products[$product_index];
						
						// Try multiple image field names
						$image_url = $product['image_url'] ?? $product['image'] ?? $product['featured_image_url'] ?? '';
						
						// If still empty, try to get from featured_image_id
						if (empty($image_url) && !empty($product['featured_image_id'])) {
							$image_url = wp_get_attachment_url($product['featured_image_id']);
						}
						
						if (!empty($image_url)) {
							if (!isset($settings['image']) || !is_array($settings['image'])) {
								$settings['image'] = [];
							}
							$settings['image']['url'] = $image_url;
							$settings['image']['id'] = ''; // Clear ID to force URL usage
							error_log('[AEBG] ✅ Applied image variable to image widget: ' . $image_url);
						} else {
							error_log('[AEBG] ⚠️ No image URL found for product-' . $product_num);
						}
					} else {
						error_log('[AEBG] ❌ Product not found at index ' . $product_index . ' (product-' . $product_num . ')');
					}
				}
			}
			
			// Apply generated content if field_id exists
			if (isset($settings['aebg_field_id'])) {
				$field_id = $settings['aebg_field_id'];
				if ($this->context_registry) {
					$field_data = $this->context_registry->getContext($field_id);
					if ($field_data && isset($field_data['generated_content'])) {
						$generated_content = $field_data['generated_content'];
						if (!empty($generated_content)) {
							$this->applyContentToWidget($data, $generated_content, $widget_type);
							error_log('[AEBG] Applied generated content to widget ' . $widget_type);
						}
					}
				}
			}
			
			// Handle icon list items - apply generated content from context registry
			if (($widget_type === 'icon-list' || $widget_type === 'aebg-icon-list') && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
				error_log('[AEBG] 🔧 Processing icon-list widget with ' . count($settings['icon_list']) . ' items (target product: ' . ($target_product_number ?? 'all') . ')');
				
				// CRITICAL: Log container structure for debugging
				if (defined('WP_DEBUG') && WP_DEBUG) {
					$icon_list_with_field_id = 0;
					$icon_list_with_ai_enable = 0;
					foreach ($settings['icon_list'] as $idx => $item) {
						if (isset($item['aebg_field_id'])) $icon_list_with_field_id++;
						if (isset($item['aebg_iconlist_ai_enable']) && $item['aebg_iconlist_ai_enable'] === 'yes') $icon_list_with_ai_enable++;
					}
					error_log('[AEBG] 📊 Icon-list structure: ' . $icon_list_with_field_id . ' items with field_id, ' . $icon_list_with_ai_enable . ' items with AI enabled');
				}
				
				foreach ($settings['icon_list'] as $index => &$icon_item) {
					$content_applied = false;
					
					// First, try to apply generated content from context registry
					if (isset($icon_item['aebg_field_id'])) {
						$field_id = $icon_item['aebg_field_id'];
						error_log('[AEBG] 📝 Icon-list item ' . $index . ' has field_id: ' . $field_id);
						if ($this->context_registry) {
							$field_data = $this->context_registry->getContext($field_id);
							if ($field_data && isset($field_data['generated_content'])) {
								$generated_content = $field_data['generated_content'];
								if (!empty($generated_content)) {
									// Clean content
									$cleaned_content = $generated_content;
									if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
										$cleaned_content = $matches[1];
									}
									$cleaned_content = trim($cleaned_content);
									if (!empty($cleaned_content)) {
										$old_text = $icon_item['text'] ?? '';
										// CRITICAL: Completely replace old text, don't append
										$icon_item['text'] = $cleaned_content;
										$content_applied = true;
										error_log('[AEBG] ✅ Applied generated content to icon-list item ' . $index . ' - Old: "' . substr($old_text, 0, 50) . '..." New: "' . substr($cleaned_content, 0, 50) . '..."');
									} else {
										error_log('[AEBG] ⚠️ Generated content for icon-list item ' . $index . ' is empty after cleaning');
									}
								} else {
									error_log('[AEBG] ⚠️ No generated content found for icon-list item ' . $index . ' (field_id: ' . $field_id . ')');
									
									// CRITICAL: If no generated content found and item has AI prompt for target product, clear old content
									// This handles the case when AI is skipped (no API key) - old product-specific content should be cleared
									if (!$content_applied && $target_product_number !== null) {
										$icon_prompt = trim($icon_item['aebg_iconlist_ai_prompt'] ?? '');
										if (!empty($icon_prompt) && $this->promptReferencesProduct($icon_prompt, $target_product_number)) {
											$old_text = $icon_item['text'] ?? '';
											// Clear the old product-specific content since AI was skipped and no new content was generated
											$icon_item['text'] = '';
											error_log('[AEBG] 🧹 Cleared old content from icon-list item ' . $index . ' (AI skipped, no generated content)');
											error_log('[AEBG]   Old text: "' . substr($old_text, 0, 100) . '"');
											error_log('[AEBG]   AI prompt: ' . $icon_prompt);
										}
									}
								}
							} else {
								error_log('[AEBG] ⚠️ Context registry data not found for icon-list item ' . $index . ' (field_id: ' . $field_id . ')');
								// Debug: Check if field exists in context registry
								if ($this->context_registry && method_exists($this->context_registry, 'getAllContexts')) {
									$all_contexts = $this->context_registry->getAllContexts();
									error_log('[AEBG] 🔍 Available field IDs in context registry: ' . implode(', ', array_slice(array_keys($all_contexts ?? []), 0, 10)) . (count($all_contexts ?? []) > 10 ? '...' : ''));
								}
								
								// CRITICAL: If no generated content found and item has AI prompt for target product, clear old content
								// This handles the case when AI is skipped (no API key) - old product-specific content should be cleared
								if (!$content_applied && $target_product_number !== null) {
									$icon_prompt = trim($icon_item['aebg_iconlist_ai_prompt'] ?? '');
									if (!empty($icon_prompt) && $this->promptReferencesProduct($icon_prompt, $target_product_number)) {
										$old_text = $icon_item['text'] ?? '';
										// Clear the old product-specific content since AI was skipped and no new content was generated
										$icon_item['text'] = '';
										error_log('[AEBG] 🧹 Cleared old content from icon-list item ' . $index . ' (AI skipped, no generated content)');
										error_log('[AEBG]   Old text: "' . substr($old_text, 0, 100) . '"');
										error_log('[AEBG]   AI prompt: ' . $icon_prompt);
									}
								}
							}
						} else {
							error_log('[AEBG] ⚠️ Context registry not initialized for icon-list item ' . $index);
						}
					} else {
						error_log('[AEBG] 📝 Icon-list item ' . $index . ' has NO field_id');
						
						// CRITICAL FIX: If icon-list item has AI enabled but no field_id, it means it wasn't registered in Step 1
						// This can happen if the container structure changed or field_id was lost during replacement
						// Try to find the field_id by matching the prompt in the context registry
						if (!$content_applied && $target_product_number !== null && isset($icon_item['aebg_iconlist_ai_enable']) && $icon_item['aebg_iconlist_ai_enable'] === 'yes') {
							$icon_prompt = trim($icon_item['aebg_iconlist_ai_prompt'] ?? '');
							if (!empty($icon_prompt) && $this->promptReferencesProduct($icon_prompt, $target_product_number)) {
								error_log('[AEBG] 🔍 Icon-list item ' . $index . ' has AI prompt but no field_id - searching context registry by prompt...');
								
								// Try to find field by matching prompt and widget type
								if ($this->context_registry && method_exists($this->context_registry, 'getAllContexts')) {
									$all_contexts = $this->context_registry->getAllContexts();
									foreach ($all_contexts as $field_id => $field_data) {
										if (isset($field_data['widget_type']) && $field_data['widget_type'] === 'icon-list-item' &&
											isset($field_data['prompt']) && trim($field_data['prompt']) === $icon_prompt &&
											isset($field_data['generated_content']) && !empty($field_data['generated_content'])) {
											// Found matching field - apply the content
											$generated_content = $field_data['generated_content'];
											$cleaned_content = $generated_content;
											if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
												$cleaned_content = $matches[1];
											}
											$cleaned_content = trim($cleaned_content);
											if (!empty($cleaned_content)) {
												$old_text = $icon_item['text'] ?? '';
												$icon_item['text'] = $cleaned_content;
												$icon_item['aebg_field_id'] = $field_id; // Set field_id for future reference
												$content_applied = true;
												error_log('[AEBG] ✅ Found and applied generated content for icon-list item ' . $index . ' via prompt matching (field_id: ' . $field_id . ')');
												error_log('[AEBG]   Old: "' . substr($old_text, 0, 50) . '..." New: "' . substr($cleaned_content, 0, 50) . '..."');
												break;
											}
										}
									}
								}
								
								if (!$content_applied) {
									error_log('[AEBG] ⚠️ Icon-list item ' . $index . ' has AI prompt but no matching field found in context registry');
									error_log('[AEBG]   Prompt: ' . substr($icon_prompt, 0, 100));
									
									// CRITICAL: If no generated content found and item has AI prompt for target product, clear old content
									// This handles the case when AI is skipped (no API key) - old product-specific content should be cleared
									$old_text = $icon_item['text'] ?? '';
									if (!empty($old_text)) {
										// Clear the old product-specific content since AI was skipped and no new content was generated
										$icon_item['text'] = '';
										error_log('[AEBG] 🧹 Cleared old content from icon-list item ' . $index . ' (AI skipped, no generated content, no field_id)');
										error_log('[AEBG]   Old text: "' . substr($old_text, 0, 100) . '"');
									}
								}
							}
						}
					}
					
					// Also handle variable replacement for icon-list item text (even if no AI)
					// This must happen AFTER applying generated content to replace any variables in the generated content
					if (isset($icon_item['text']) && is_string($icon_item['text']) && preg_match('/\{product-\d+/', $icon_item['text'])) {
						$old_text = $icon_item['text'];
						$icon_item['text'] = $this->variables->replace($icon_item['text'], $title, $products);
						if ($old_text !== $icon_item['text']) {
							error_log('[AEBG] ✅ Replaced variables in icon-list item ' . $index . ' text');
						}
					}
				}
				unset($icon_item);
			}
			
			// CRITICAL: Always replace variables in widget settings (like bulk generator does)
			// This ensures variables are replaced even when AI is disabled or skipped
			// This must happen AFTER applying generated content, so variables in generated content are also replaced
			// OPTIMIZATION: Only process widgets that reference the target product (if target_product_number is specified)
			$should_update = true;
			if ($target_product_number !== null) {
				$should_update = $this->widgetReferencesProduct($data['settings'], $target_product_number);
				if (!$should_update) {
					error_log('[AEBG] ⏭️ Skipping variable update for widget ' . $widget_type . ' - does not reference product-' . $target_product_number);
				} else {
					error_log('[AEBG] Updating variables in widget ' . $widget_type . ' (references product-' . $target_product_number . ')');
				}
			}
			
			if ($should_update) {
				// Pass widget_type and target_product_number so logging can show the correct widget type and target product
				$data['settings'] = $this->updateProductVariablesInSettings($data['settings'], $products, $title, $widget_type, $target_product_number);
			}
		}
		
		// Recursively process children (pass $inside_target flag and $target_product_number)
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				$data['elements'][$index] = $this->applyContentToContainerRecursively($element, $target_css_id, $products, $title, $inside_target, $target_product_number);
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				$data['content'][$index] = $this->applyContentToContainerRecursively($content_item, $target_css_id, $products, $title, $inside_target, $target_product_number);
			}
		}
		
		return $data;
	}

	/**
	 * Replace container in Elementor data
	 * 
	 * @param array $elementor_data Full Elementor data
	 * @param string $target_css_id Target container CSS ID
	 * @param array $new_container New container data
	 * @return array|\WP_Error Updated Elementor data
	 */
	private function replaceContainerInElementorData($elementor_data, $target_css_id, $new_container) {
		return $this->replaceContainerRecursively($elementor_data, $target_css_id, $new_container);
	}

	/**
	 * Recursively replace container
	 * 
	 * @param array $data Elementor data
	 * @param string $target_css_id Target CSS ID
	 * @param array $new_container New container
	 * @return array Updated data
	 */
	private function replaceContainerRecursively($data, $target_css_id, $new_container) {
		if (!is_array($data)) {
			return $data;
		}
		
		// Handle numeric array
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$data[$index] = $this->replaceContainerRecursively($item, $target_css_id, $new_container);
			}
			return $data;
		}
		
		// Check if this is the target container
		if (isset($data['settings']) && is_array($data['settings'])) {
			$element_css_id = $data['settings']['_element_id'] ?? $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '';
			if ($element_css_id === $target_css_id) {
				// Replace with new container
				// CRITICAL: Log container replacement for debugging
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[AEBG] 🔄 Replacing container ' . $target_css_id . ' with new container');
					// Check if new container has icon-list widgets with field_ids
					$icon_list_count = 0;
					$icon_list_with_field_id = 0;
					if (isset($new_container['elements']) && is_array($new_container['elements'])) {
						foreach ($new_container['elements'] as $element) {
							if (isset($element['widgetType']) && ($element['widgetType'] === 'icon-list' || $element['widgetType'] === 'aebg-icon-list')) {
								$icon_list_count++;
								if (isset($element['settings']['icon_list']) && is_array($element['settings']['icon_list'])) {
									foreach ($element['settings']['icon_list'] as $item) {
										if (isset($item['aebg_field_id'])) {
											$icon_list_with_field_id++;
										}
									}
								}
							}
						}
					}
					if ($icon_list_count > 0) {
						error_log('[AEBG] 📋 New container has ' . $icon_list_count . ' icon-list widgets with ' . $icon_list_with_field_id . ' items having field_id');
					}
				}
				return $new_container;
			}
		}
		
		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				$data['elements'][$index] = $this->replaceContainerRecursively($element, $target_css_id, $new_container);
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				$data['content'][$index] = $this->replaceContainerRecursively($content_item, $target_css_id, $new_container);
			}
		}
		
		return $data;
	}

	/**
	 * Get original content for widget (helper method)
	 * 
	 * @param array $data Widget data
	 * @param string $widget_type Widget type
	 * @return string Original content
	 */
	private function getOriginalContentForWidget($data, $widget_type) {
		$settings = $data['settings'] ?? [];
		
		switch ($widget_type) {
			case 'text-editor':
			case 'html':
				return $settings['editor'] ?? $settings['html'] ?? '';
			case 'heading':
				return $settings['title'] ?? '';
			case 'button':
				return $settings['text'] ?? '';
			default:
				return '';
		}
	}

	/**
	 * Extract dependencies from prompt (helper method)
	 * 
	 * @param string $prompt Prompt text
	 * @return array Array of field IDs this prompt depends on
	 */
	private function extractDependencies($prompt) {
		// For now, no dependencies - can be enhanced later
		return [];
	}

	/**
	 * Find testvinder container by product number
	 * 
	 * @param array $elementor_data Elementor data
	 * @param int $product_number Product number
	 * @return array|null Container data or null if not found
	 */
	private function findTestvinderContainer($elementor_data, $product_number) {
		$testvinder_css_id = 'testvinder-' . $product_number;
		return $this->findProductContainer($elementor_data, $testvinder_css_id);
	}

	/**
	 * Find product container by CSS ID
	 * 
	 * @param array $elementor_data Elementor data
	 * @param string $css_id CSS ID to find (e.g., 'product-2')
	 * @return array|null Container data or null if not found
	 */
	private function findProductContainer($elementor_data, $css_id) {
		if (!is_array($elementor_data)) {
			return null;
		}
		
		// Handle numeric array
		if (array_keys($elementor_data) === range(0, count($elementor_data) - 1)) {
			foreach ($elementor_data as $item) {
				$result = $this->findProductContainer($item, $css_id);
				if ($result !== null) {
					return $result;
				}
			}
			return null;
		}
		
		// Check if this element matches the target CSS ID
		if (isset($elementor_data['settings']) && is_array($elementor_data['settings'])) {
			$element_css_id = $elementor_data['settings']['_element_id'] ?? $elementor_data['settings']['_css_id'] ?? $elementor_data['settings']['css_id'] ?? '';
			
			if ($element_css_id === $css_id) {
				return $elementor_data;
			}
		}
		
		// Recursively search children
		if (isset($elementor_data['elements']) && is_array($elementor_data['elements'])) {
			foreach ($elementor_data['elements'] as $element) {
				$result = $this->findProductContainer($element, $css_id);
				if ($result !== null) {
					return $result;
				}
			}
		}
		
		if (isset($elementor_data['content']) && is_array($elementor_data['content'])) {
			foreach ($elementor_data['content'] as $content_item) {
				$result = $this->findProductContainer($content_item, $css_id);
				if ($result !== null) {
					return $result;
				}
			}
		}
		
		return null;
	}

	/**
	 * Update template structure for a new product
	 *
	 * @param int $post_id The post ID
	 * @param int $new_product_number The new product number
	 * @param array|null $products Products array (optional)
	 * @return bool|\WP_Error
	 */
	public function updateTemplateForNewProduct($post_id, $new_product_number, $products = null) {
		error_log('[AEBG] ===== START: updateTemplateForNewProduct =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		error_log('[AEBG] New product number: ' . $new_product_number);
		
		try {
			$post = get_post($post_id);
			if (!$post) {
				return new \WP_Error('post_not_found', 'Post not found');
			}

			$elementor_data = get_post_meta($post_id, '_elementor_data', true);
			if (empty($elementor_data)) {
				return new \WP_Error('no_elementor_data', 'No Elementor data found');
			}

			$elementor_data = is_string($elementor_data) ? $this->decodeJsonWithUnicode($elementor_data) : $elementor_data;
			if ($elementor_data === false) {
				return new \WP_Error('json_decode_failed', 'Failed to decode Elementor data');
			}
			
			if (!is_array($elementor_data)) {
				return new \WP_Error('invalid_elementor_data', 'Invalid Elementor data');
			}

			$aebg_settings = get_option('aebg_settings', []);
			$api_key = $aebg_settings['api_key'] ?? '';
			$ai_model = $aebg_settings['model'] ?? 'gpt-3.5-turbo';

		if ($products === null) {
			$products = get_post_meta($post_id, '_aebg_products', true);
			if (!is_array($products)) {
				$products = [];
			}
			}

			$working_data = $this->deepCopyElementorData($elementor_data);
			if (!is_array($working_data)) {
				return new \WP_Error('copy_failed', 'Failed to create deep copy');
			}

			$existing_containers = $this->findExistingProductContainers($working_data);
			$actual_product_count = count($products);
			
		if (count($existing_containers) < $new_product_number || $new_product_number > $actual_product_count) {
			$updated_data = $this->createNewProductContainer($working_data, $new_product_number, $post->post_title, $products, $api_key, $ai_model);
		} else {
			$updated_data = $this->adjustTemplateForNewProduct($working_data, $new_product_number);
		}
			
			if (!is_array($updated_data)) {
				return new \WP_Error('invalid_updated_data', 'Updated data is not an array');
			}
			
			$validation_result = $this->validateElementorData($updated_data);
			if (is_wp_error($validation_result)) {
				return $validation_result;
			}

			$cleaned_data = $this->cleanElementorDataForEncoding($updated_data);
			$encoded_data = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($encoded_data === false) {
				return new \WP_Error('encoding_failed', 'Failed to encode updated data: ' . json_last_error_msg());
			}
			
			update_post_meta($post_id, '_elementor_edit_mode', 'builder');
			update_post_meta($post_id, '_elementor_template_type', 'wp-post');
			update_post_meta($post_id, '_elementor_version', '3.31.0');
			
			$update_result = update_post_meta($post_id, '_elementor_data', $encoded_data);
			if ($update_result === false) {
				delete_post_meta($post_id, '_elementor_data');
				$update_result = update_post_meta($post_id, '_elementor_data', $encoded_data);
				if ($update_result === false) {
					return new \WP_Error('update_failed', 'Failed to update Elementor data');
				}
			}
			
			wp_update_post([
				'ID' => $post_id,
				'post_modified' => current_time('mysql'),
				'post_modified_gmt' => current_time('mysql', 1)
			]);
			
			$this->clearElementorCache($post_id);
			$this->forceElementorCSSGeneration($post_id);

			error_log('[AEBG] ===== SUCCESS: updateTemplateForNewProduct completed =====');
			return true;

		} catch (\Exception $e) {
			error_log('[AEBG] ERROR: Exception in updateTemplateForNewProduct: ' . $e->getMessage());
			return new \WP_Error('exception', $e->getMessage());
		}
	}

	/**
	 * Update template structure after a product is removed
	 *
	 * @param int $post_id The post ID
	 * @param int $removed_product_number The removed product number
	 * @return bool|\WP_Error
	 */
	public function updateTemplateAfterRemoval($post_id, $removed_product_number) {
		try {
			error_log('[AEBG] Updating template after removing product ' . $removed_product_number . ' from post ' . $post_id);
			
			$post = get_post($post_id);
			if (!$post) {
				return new \WP_Error('post_not_found', 'Post not found');
			}

			$elementor_data = get_post_meta($post_id, '_elementor_data', true);
			if (empty($elementor_data)) {
				return new \WP_Error('no_elementor_data', 'No Elementor data found');
			}

			$elementor_data = is_string($elementor_data) ? $this->decodeJsonWithUnicode($elementor_data) : $elementor_data;
			if ($elementor_data === false) {
				return new \WP_Error('invalid_elementor_data', 'Failed to decode Elementor data');
			}
			
			if (!is_array($elementor_data)) {
				return new \WP_Error('invalid_elementor_data', 'Invalid Elementor data');
			}

			$updated_data = $this->adjustTemplateAfterRemoval($elementor_data, $removed_product_number);
			
			$cleaned_data = $this->cleanElementorDataForEncoding($updated_data);
			$encoded_data = json_encode($cleaned_data);
			if ($encoded_data === false) {
				return new \WP_Error('json_encoding_failed', 'Failed to encode updated Elementor data');
			}
			
			$update_result = update_post_meta($post_id, '_elementor_data', $encoded_data);
			if ($update_result === false) {
				return new \WP_Error('update_failed', 'Failed to update Elementor data');
			}

			error_log('[AEBG] Template updated successfully after removing product ' . $removed_product_number);
			return true;

							} catch (\Exception $e) {
			error_log('[AEBG] Error in updateTemplateAfterRemoval: ' . $e->getMessage());
			return new \WP_Error('update_error', $e->getMessage());
		}
	}

	// ============================================================================
	// PRIVATE HELPER METHODS - Used by public methods above
	// ============================================================================

	/**
	 * Clean JSON string to fix common encoding issues (like old version).
	 *
	 * @param string $json_string The JSON string to clean.
	 * @return string|false Cleaned JSON string or false on failure.
	 */
	private function cleanJsonString($json_string) {
		if (!is_string($json_string)) {
			return false;
		}
		
		// Remove BOM if present
		$json_string = preg_replace('/^\xEF\xBB\xBF/', '', $json_string);
		
		// Fix common encoding issues
		$json_string = str_replace(["\r\n", "\r"], "\n", $json_string);
		
		// Try to fix unescaped quotes in strings (basic fix)
		// This is a simple approach - more complex fixes may be needed
		$json_string = preg_replace('/(?<!\\\\)"(?![,}\]:])/', '\\"', $json_string);
		
		// Remove any null bytes
		$json_string = str_replace("\0", '', $json_string);
		
		return $json_string;
	}

	/**
	 * Simplify Elementor data to reduce size (like old version).
	 *
	 * @param array $data The Elementor data to simplify.
	 * @return array Simplified data.
	 */
	private function simplifyElementorData($data) {
		if (!is_array($data)) {
			return $data;
		}
		
		$simplified = [];
		
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				// Recursively simplify nested arrays
				$simplified[$key] = $this->simplifyElementorData($value);
			} elseif (is_string($value) && strlen($value) > 1000) {
				// Truncate very long strings
				$simplified[$key] = substr($value, 0, 1000) . '...';
			} else {
				$simplified[$key] = $value;
			}
		}
		
		return $simplified;
	}

	/**
	 * Debug data structure recursively (like old version).
	 *
	 * @param mixed $data The data to debug.
	 * @param int $depth Current depth (for indentation).
	 * @return void
	 */
	private function debugDataStructure($data, $depth = 0) {
		$indent = str_repeat('  ', $depth);
		
		if (is_array($data)) {
			error_log($indent . 'Array (' . count($data) . ' items)');
			foreach ($data as $key => $value) {
				error_log($indent . '  [' . $key . '] => ');
				if (is_array($value) && $depth < 5) { // Limit depth to prevent infinite recursion
					$this->debugDataStructure($value, $depth + 2);
				} else {
					$preview = is_string($value) ? substr($value, 0, 100) : (is_scalar($value) ? $value : gettype($value));
					error_log($indent . '    ' . $preview);
				}
			}
		} else {
			$preview = is_string($data) ? substr($data, 0, 100) : (is_scalar($data) ? $data : gettype($data));
			error_log($indent . $preview);
		}
	}

	/**
	 * Update element with generated content by CSS ID (like old version).
	 * 
	 * @param array $data Elementor data
	 * @param string $css_id CSS ID of the element
	 * @param string $widget_type Widget type
	 * @param string $generated_content Generated content
	 * @param string $prompt The prompt used
	 * @return array Updated data
	 */
	private function updateElementWithGeneratedContentByCssId($data, $css_id, $widget_type, $generated_content, $prompt) {
		if (!is_array($data)) {
			return $data;
		}

		// Check if this element matches the CSS ID
		$element_css_id = $data['settings']['_element_id'] ?? '';
		if ($element_css_id === $css_id) {
			// This is the target element, update it
			$data = $this->applyContentToWidget($data, $generated_content, $widget_type);
			return $data;
		}

		// Recursively search in children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				if (is_array($element)) {
					$data['elements'][$index] = $this->updateElementWithGeneratedContentByCssId($element, $css_id, $widget_type, $generated_content, $prompt);
				}
			}
		}

		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$data['content'][$index] = $this->updateElementWithGeneratedContentByCssId($content_item, $css_id, $widget_type, $generated_content, $prompt);
				}
			}
		}

		return $data;
	}

	/**
	 * Decode JSON with Unicode support
	 */
	private function decodeJsonWithUnicode($json_string) {
		if (!is_string($json_string)) {
			return false;
		}
		
		$decoded = json_decode($json_string, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}
		
	error_log('[AEBG] JSON decode failed: ' . json_last_error_msg());
	return false;
}

	/**
	 * Clean Elementor data before JSON encoding
	 */
	public function cleanElementorDataForEncoding($elementor_data, $fix_urls = true, $depth = 0, $start_time = null) {
		return \AEBG\Core\DataUtilities::cleanElementorDataForEncoding($elementor_data, $fix_urls, $depth, $start_time);
	}

	/**
	 * Sanitize Elementor data structure to fix common issues before passing to Elementor
	 * This fixes:
	 * - Missing widgetType in widgets
	 * - Non-array values in elements/content arrays
	 * 
	 * @param array $data Elementor data
	 * @return array Sanitized data
	 */
	public function sanitizeElementorDataStructure($data) {
		if (!is_array($data)) {
			return [];
		}

		// Handle numeric array structure (top-level array of elements)
		if (array_keys($data) === range(0, count($data) - 1)) {
			$sanitized = [];
			foreach ($data as $index => $item) {
				if (is_array($item)) {
					$sanitized[] = $this->sanitizeElementorDataStructure($item);
				}
				// Skip non-array items
			}
			return $sanitized;
		}

		$sanitized = $data;

		// CRITICAL: Ultra-aggressive widget detection - Elementor's sanitizer is very strict
		// ANY element that Elementor's sanitizer might identify as a widget MUST have widgetType
		// Elementor's content-sanitizer checks for widgetType on ANY element that has certain properties
		// Based on the errors, Elementor's sanitizer is checking widgetType on elements that don't have it
		$is_widget = false;
		$widget_indicators = [];
		
		// Case 1: Has elType === 'widget' (definite widget)
		if (isset($sanitized['elType']) && $sanitized['elType'] === 'widget') {
			$is_widget = true;
			$widget_indicators[] = 'elType=widget';
		}
		
		// Case 2: Has widgetType key (definite widget, even if elType is missing/wrong)
		if (isset($sanitized['widgetType'])) {
			$is_widget = true;
			$widget_indicators[] = 'has widgetType';
			// If widgetType exists but elType is not 'widget', set it
			if (!isset($sanitized['elType']) || $sanitized['elType'] !== 'widget') {
				$sanitized['elType'] = 'widget';
			}
		}
		
		// Case 3: Has settings array but no elType - Elementor treats this as a widget
		// This is the most common case causing errors
		if (isset($sanitized['settings']) && is_array($sanitized['settings']) && !isset($sanitized['elType'])) {
			$is_widget = true;
			$widget_indicators[] = 'has settings without elType';
			$sanitized['elType'] = 'widget';
		}
		
		// Case 4: Has settings array and elType is 'widget' but missing widgetType
		if (isset($sanitized['settings']) && is_array($sanitized['settings']) && 
		    isset($sanitized['elType']) && $sanitized['elType'] === 'widget' && 
		    (!isset($sanitized['widgetType']) || empty($sanitized['widgetType']))) {
			$is_widget = true;
			$widget_indicators[] = 'elType=widget with settings but no widgetType';
		}
		
		// Case 5: Elementor's sanitizer checks for widgetType on elements that have 'id' but no 'elType'
		// If an element has an 'id' field and looks like it could be a widget, ensure it has widgetType
		if (isset($sanitized['id']) && !isset($sanitized['elType']) && isset($sanitized['settings'])) {
			$is_widget = true;
			$widget_indicators[] = 'has id and settings but no elType';
			$sanitized['elType'] = 'widget';
		}
		
		// Case 6: Elementor's sanitizer may check ANY element with settings for widgetType
		// Be extra defensive: if it has settings and no elType is set, treat as widget
		if (isset($sanitized['settings']) && is_array($sanitized['settings']) && 
		    (!isset($sanitized['elType']) || $sanitized['elType'] === '')) {
			$is_widget = true;
			$widget_indicators[] = 'has settings with empty/missing elType';
			$sanitized['elType'] = 'widget';
		}
		
		// CRITICAL: Ensure ALL detected widgets have widgetType (Elementor requires this)
		// Also, if elType is 'widget', ALWAYS ensure widgetType exists
		// This is the key fix - Elementor's sanitizer expects widgetType on ALL widgets
		if ($is_widget || (isset($sanitized['elType']) && $sanitized['elType'] === 'widget')) {
			if (!isset($sanitized['widgetType']) || empty($sanitized['widgetType'])) {
				// Try to extract widgetType from settings if available
				if (isset($sanitized['settings']['widget_type'])) {
					$sanitized['widgetType'] = $sanitized['settings']['widget_type'];
				} elseif (isset($sanitized['settings']['__dynamic__']['widget_type'])) {
					$sanitized['widgetType'] = 'dynamic';
				} else {
					$sanitized['widgetType'] = 'unknown';
				}
			}
		}

		// CRITICAL: Sanitize elements array - remove non-array values
		// Elementor's db.php iterates over elements, so ALL values must be arrays
		if (isset($sanitized['elements'])) {
			if (is_array($sanitized['elements'])) {
				$sanitized_elements = [];
				foreach ($sanitized['elements'] as $index => $element) {
					if (is_array($element)) {
						$sanitized_elements[] = $this->sanitizeElementorDataStructure($element);
					} else {
						error_log('[AEBG] ⚠️ REMOVED: Non-array element at index ' . $index . ' (type: ' . gettype($element) . ')');
					}
				}
				$sanitized['elements'] = $sanitized_elements;
			} else {
				// If elements is not an array, remove it to prevent foreach errors
				error_log('[AEBG] ⚠️ REMOVED: Invalid elements (not an array, type: ' . gettype($sanitized['elements']) . ')');
				unset($sanitized['elements']);
			}
		}

		// CRITICAL: Sanitize content array - remove non-array values
		// Elementor's db.php iterates over content, so ALL values must be arrays
		if (isset($sanitized['content'])) {
			if (is_array($sanitized['content'])) {
				$sanitized_content = [];
				foreach ($sanitized['content'] as $index => $content_item) {
					if (is_array($content_item)) {
						$sanitized_content[] = $this->sanitizeElementorDataStructure($content_item);
					} else {
						error_log('[AEBG] ⚠️ REMOVED: Non-array content at index ' . $index . ' (type: ' . gettype($content_item) . ')');
					}
				}
				$sanitized['content'] = $sanitized_content;
			} else {
				// If content is not an array, remove it to prevent foreach errors
				error_log('[AEBG] ⚠️ REMOVED: Invalid content (not an array, type: ' . gettype($sanitized['content']) . ')');
				unset($sanitized['content']);
			}
		}

		// CRITICAL: Deep sanitize settings array - Elementor's db.php foreach errors come from settings arrays
		// containing non-array values where arrays are expected
		if (isset($sanitized['settings'])) {
			if (is_array($sanitized['settings'])) {
				$sanitized['settings'] = $this->sanitizeSettingsArray($sanitized['settings']);
			} else {
				// If settings is not an array, remove it to prevent errors
				error_log('[AEBG] ⚠️ REMOVED: Invalid settings (not an array, type: ' . gettype($sanitized['settings']) . ')');
				unset($sanitized['settings']);
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize settings array to ensure all nested arrays are valid
	 * CRITICAL: This fixes foreach() errors in Elementor's db.php by ensuring all array values are actually arrays
	 * 
	 * @param array $settings Settings array
	 * @param int $depth Current depth (prevents infinite recursion)
	 * @return array Sanitized settings
	 */
	private function sanitizeSettingsArray($settings, $depth = 0) {
		// Prevent infinite recursion
		if ($depth > 50) {
			error_log('[AEBG] ⚠️ WARNING: sanitizeSettingsArray exceeded max depth');
			return [];
		}
		
		if (!is_array($settings)) {
			return [];
		}

		$sanitized = [];
		foreach ($settings as $key => $value) {
			if (is_array($value)) {
				// Recursively sanitize nested arrays
				$sanitized[$key] = $this->sanitizeSettingsArray($value, $depth + 1);
			} elseif (is_string($value) || is_numeric($value) || is_bool($value) || is_null($value)) {
				// Valid primitive types - keep as-is
				$sanitized[$key] = $value;
			} elseif (is_object($value)) {
				// CRITICAL: Convert objects to arrays - Elementor expects arrays, not objects
				error_log('[AEBG] ⚠️ FIXED: Converting object to array for settings key "' . $key . '"');
				$sanitized[$key] = $this->sanitizeSettingsArray((array)$value, $depth + 1);
			} else {
				// Invalid type - convert to string or remove
				error_log('[AEBG] ⚠️ FIXED: Invalid setting value type for key "' . $key . '" (type: ' . gettype($value) . '), converting to string');
				$sanitized[$key] = '';
			}
		}
		return $sanitized;
	}

	/**
	 * Create a deep copy of an array
	 */
	private function deepCopyArray($array) {
		return \AEBG\Core\DataUtilities::deepCopyArray($array);
	}

	/**
	 * Create a deep copy of Elementor data
	 */
	private function deepCopyElementorData($data) {
		if (!is_array($data)) {
			return $data;
		}
		
		$json_encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json_encoded === false) {
			error_log('[AEBG] Failed to encode data for deep copy: ' . json_last_error_msg());
							return $data;
		}
		
		$deep_copy = json_decode($json_encoded, true);
		if ($deep_copy === null) {
			error_log('[AEBG] Failed to decode data for deep copy: ' . json_last_error_msg());
			return $data;
		}
		
		return $deep_copy;
	}

	/**
	 * Find all product variables and CSS IDs in Elementor template data
	 */
	private function findProductVariablesInTemplate($data) {
		$product_variables = [];
		$css_ids = [];
		
		if (!is_array($data)) {
			return ['variables' => $product_variables, 'css_ids' => $css_ids];
		}

		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $item) {
				if (is_array($item)) {
					$result = $this->findProductVariablesInTemplate($item);
					$product_variables = array_merge($product_variables, $result['variables']);
					$css_ids = array_merge($css_ids, $result['css_ids']);
				}
			}
			return ['variables' => $product_variables, 'css_ids' => $css_ids];
		}
		
		if (isset($data['settings'])) {
			$settings = $data['settings'];
			$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
			
			if (preg_match('/product-(\d+)/', $css_id, $matches)) {
				$product_number = (int) $matches[1];
				$css_ids[] = 'product-' . $product_number;
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $content_item) {
				if (is_array($content_item)) {
					$result = $this->findProductVariablesInTemplate($content_item);
					$product_variables = array_merge($product_variables, $result['variables']);
					$css_ids = array_merge($css_ids, $result['css_ids']);
				}
			}
		}
		
		$widget_types = ['text-editor', 'heading', 'html', 'shortcode', 'text-path', 'button', 'aebg-icon-list'];
		if (isset($data['widgetType']) && in_array($data['widgetType'], $widget_types)) {
			$settings = $data['settings'] ?? [];
			
		$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode'];
		foreach ($text_fields as $field) {
			if (isset($settings[$field]) && is_string($settings[$field])) {
					$found_vars = $this->extractProductVariablesFromText($settings[$field]);
					$product_variables = array_merge($product_variables, $found_vars);
				}
			}
			
			if (isset($settings['aebg_ai_prompt']) && is_string($settings['aebg_ai_prompt'])) {
				$found_vars = $this->extractProductVariablesFromText($settings['aebg_ai_prompt']);
				$product_variables = array_merge($product_variables, $found_vars);
			}
			
			if ($data['widgetType'] === 'aebg-icon-list' && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
				foreach ($settings['icon_list'] as $icon_item) {
					if (is_array($icon_item) && isset($icon_item['aebg_iconlist_ai_prompt']) && is_string($icon_item['aebg_iconlist_ai_prompt'])) {
						$found_vars = $this->extractProductVariablesFromText($icon_item['aebg_iconlist_ai_prompt']);
						$product_variables = array_merge($product_variables, $found_vars);
					}
				}
			}
		}
		
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $element) {
				if (is_array($element)) {
					$result = $this->findProductVariablesInTemplate($element);
					$product_variables = array_merge($product_variables, $result['variables']);
					$css_ids = array_merge($css_ids, $result['css_ids']);
				}
			}
		}
		
		return [
			'variables' => array_unique($product_variables),
			'css_ids' => array_unique($css_ids)
		];
	}

	/**
	 * Extract product variables from text content
	 */
	private function extractProductVariablesFromText($text) {
		$product_variables = [];
		
		$patterns = [
			'/\{product-(\d+)(?:-[a-zA-Z-]+)?\}/',
			'/\{product(\d+)(?:-[a-zA-Z-]+)?\}/',
			'/\{prod-(\d+)(?:-[a-zA-Z-]+)?\}/',
		];
		
		foreach ($patterns as $pattern) {
			preg_match_all($pattern, $text, $matches);
			if (!empty($matches[1])) {
				foreach ($matches[1] as $product_number) {
					$product_variables[] = 'product-' . $product_number;
				}
			}
		}
		
		return array_unique($product_variables);
	}

	/**
	 * Get the maximum product number from product variables
	 */
	private function getMaxProductNumber($product_variables) {
		$max_number = 0;
		
		foreach ($product_variables as $variable) {
			if (preg_match('/product-(\d+)/', $variable, $matches)) {
				$number = intval($matches[1]);
				if ($number > $max_number) {
					$max_number = $number;
				}
			}
		}
		
		return $max_number;
	}

	/**
	 * Generate a professional error message for template validation
	 */
	private function generateValidationErrorMessage($selected_count, $required_count, $template_title, $product_variables = [], $css_ids = []) {
		$message = '<div class="aebg-validation-error">';
		$message .= '<h3>🔒 Template Security Validation Failed</h3>';
		$message .= '<p><strong>Template:</strong> ' . esc_html($template_title) . '</p>';
		$message .= '<p><strong>Issue:</strong> The number of products you selected (' . $selected_count . ') does not match the template requirements.</p>';
		
		$detection_methods = [];
		if (!empty($product_variables)) {
			$detection_methods[] = count($product_variables) . ' product variables (e.g., {product-1}, {product-2})';
		}
		if (!empty($css_ids)) {
			$detection_methods[] = count($css_ids) . ' CSS ID containers (e.g., #product-1, #product-2)';
		}
		
		$detection_text = implode(' and ', $detection_methods);
		$message .= '<p><strong>Required:</strong> At least ' . $required_count . ' products (detected via ' . $detection_text . ')</p>';
		
		if (!empty($product_variables) || !empty($css_ids)) {
			$message .= '<div class="aebg-detection-details">';
			$message .= '<h4>🔍 Detection Details:</h4>';
			if (!empty($product_variables)) {
				$message .= '<p><strong>Product Variables Found:</strong> ' . implode(', ', $product_variables) . '</p>';
			}
			if (!empty($css_ids)) {
				$message .= '<p><strong>CSS ID Containers Found:</strong> ' . implode(', ', $css_ids) . '</p>';
			}
			$message .= '</div>';
		}
		
		$message .= '<div class="aebg-solution">';
		$message .= '<h4>💡 How to Fix:</h4>';
		$message .= '<ol>';
		$message .= '<li><strong>Increase Product Count:</strong> Adjust the "Number of Products" slider to ' . $required_count . ' or higher</li>';
		$message .= '<li><strong>Or Modify Template:</strong> Edit the Elementor template to use fewer product containers</li>';
		$message .= '<li><strong>Or Choose Different Template:</strong> Select a template that requires fewer products</li>';
		$message .= '</ol>';
		$message .= '</div>';
		$message .= '<p class="aebg-note"><strong>Note:</strong> This validation ensures your generated content will have all the products needed for the template. The system detects both {product-X} variables and CSS ID containers (#product-X).</p>';
		$message .= '</div>';
		
		return $message;
	}

	// ============================================================================
	// PRODUCT CONTAINER MANAGEMENT METHODS - Fully implemented using modular architecture
	// ============================================================================

	/**
	 * Regenerate product container content using modular architecture
	 * 
	 * @param array $elementor_data Elementor data
	 * @param string $css_id CSS ID of the container to regenerate
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Updated Elementor data or error
	 */
	private function regenerateProductContainerContent($elementor_data, $css_id, $title, $products, $context, $api_key, $ai_model) {
		error_log('[AEBG] regenerateProductContainerContent called for CSS ID: ' . $css_id);
		
		if (!is_array($elementor_data)) {
			return new \WP_Error('invalid_data', 'Invalid Elementor data');
		}

		// Extract product number from CSS ID (e.g., 'product-3' -> 3)
		$target_product_number = null;
		error_log('[AEBG] Extracting product number from CSS ID: ' . $css_id);
		if (preg_match('/product-(\d+)/', $css_id, $matches)) {
			$target_product_number = (int)$matches[1];
			error_log('[AEBG] ✅ Target product number for regeneration: ' . $target_product_number);
		} else {
			error_log('[AEBG] ⚠️ WARNING: Could not extract product number from CSS ID: ' . $css_id);
		}

		// Create deep copy to avoid modifying original
		$updated_data = DataUtilities::deepCopyElementorData($elementor_data);
		if (!is_array($updated_data)) {
			return new \WP_Error('copy_failed', 'Failed to create deep copy');
		}

		// Find and update the target container recursively
		$updated_data = $this->updateProductContainerRecursively($updated_data, $css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number);
		
		if (is_wp_error($updated_data)) {
			return $updated_data;
		}

		return $updated_data;
	}

	/**
	 * Recursively find and update a product container
	 * 
	 * @param array $data Elementor data
	 * @param string $target_css_id CSS ID to find
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @param int|null $target_product_number Target product number for filtering (null = regenerate all)
	 * @return array|\WP_Error Updated data or error
	 */
	private function updateProductContainerRecursively($data, $target_css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number = null) {
		if (!is_array($data)) {
			return $data;
		}

		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				if (is_array($item)) {
					$data[$index] = $this->updateProductContainerRecursively($item, $target_css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number);
				}
			}
			return $data;
		}

		// Check if this element matches the target CSS ID
		if (isset($data['settings']) && is_array($data['settings'])) {
			$element_css_id = $data['settings']['_element_id'] ?? $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '';
			
			if ($element_css_id === $target_css_id) {
				error_log('[AEBG] Found target product container: ' . $target_css_id);
				
				// Process this container using ElementorTemplateProcessor
				$updated_container = $this->processContainerForRegeneration($data, $target_css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number);
				
				if (is_wp_error($updated_container)) {
					return $updated_container;
				}
				
				return $updated_container;
			}
		}

		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				if (is_array($element)) {
					$data['elements'][$index] = $this->updateProductContainerRecursively($element, $target_css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number);
				}
			}
		}

		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$data['content'][$index] = $this->updateProductContainerRecursively($content_item, $target_css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number);
				}
			}
		}

		return $data;
	}

	/**
	 * Process container for regeneration using modular architecture
	 * 
	 * @param array $container Container to process
	 * @param string $css_id CSS ID
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @param int|null $target_product_number Target product number for filtering (null = regenerate all)
	 * @return array Updated container
	 */
	private function processContainerForRegeneration($container, $css_id, $title, $products, $context, $api_key, $ai_model, $target_product_number = null) {
		error_log('[AEBG] Processing container for regeneration: ' . $css_id . (($target_product_number !== null) ? ' (only product-' . $target_product_number . ')' : ' (all products)'));
		
		if (!is_array($container)) {
			return $container;
		}

		// Create deep copy
		$updated_container = DataUtilities::deepCopyElementorData($container);

		// Update product variables in the container using VariableReplacer
		// OPTIMIZATION: Use filtered method that only processes widgets referencing the target product
		$updated_container = $this->updateProductVariablesInContainerAndChildren($updated_container, $products, $title, $target_product_number);

		// Process AI-enabled widgets using ElementorTemplateProcessor approach
		// Only regenerate widgets that reference the target product number
		if (!empty($api_key) && $this->content_generator) {
			$updated_container = $this->processAIContentInContainer($updated_container, $title, $products, $context, $api_key, $ai_model, $target_product_number);
		}

		return $updated_container;
	}

	/**
	 * Update product variables in Elementor data for a specific product container
	 * 
	 * @param array $elementor_data Elementor data
	 * @param string $target_css_id Target CSS ID (e.g., 'product-2')
	 * @param array $products Products array
	 * @param string $title Post title
	 * @param int|null $product_number Product number (optional, for logging)
	 * @return array|\WP_Error Updated data or error
	 */
	public function updateProductVariablesInContainer($elementor_data, $target_css_id, $products, $title, $product_number = null) {
		error_log('[AEBG] ===== START: updateProductVariablesInContainer =====');
		error_log('[AEBG] Target CSS ID: ' . $target_css_id);
		error_log('[AEBG] Products count: ' . count($products));
		error_log('[AEBG] Title: ' . $title);
		
		if (!is_array($elementor_data)) {
			error_log('[AEBG] ERROR: Invalid Elementor data - not an array');
			return new \WP_Error('invalid_data', 'Invalid Elementor data');
		}

		// Create deep copy
		error_log('[AEBG] Creating deep copy of Elementor data...');
		$updated_data = DataUtilities::deepCopyElementorData($elementor_data);
		if (!is_array($updated_data)) {
			error_log('[AEBG] ERROR: Failed to create deep copy');
			return new \WP_Error('copy_failed', 'Failed to create deep copy');
		}
		error_log('[AEBG] Deep copy created successfully');
		
		// Recursively find and update only the target container
		error_log('[AEBG] Starting recursive search for container: ' . $target_css_id);
		$updated_data = $this->updateProductVariablesInContainerRecursively($updated_data, $target_css_id, $products, $title);
		
		if (is_wp_error($updated_data)) {
			error_log('[AEBG] ERROR: updateProductVariablesInContainerRecursively returned error: ' . $updated_data->get_error_message());
		} else {
			error_log('[AEBG] ===== SUCCESS: updateProductVariablesInContainer completed =====');
		}
		
		return $updated_data;
	}
	
	/**
	 * Recursively find and update product variables in a specific container
	 * 
	 * @param array $data Elementor data
	 * @param string $target_css_id Target CSS ID
	 * @param array $products Products array
	 * @param string $title Post title
	 * @return array Updated data
	 */
	private function updateProductVariablesInContainerRecursively($data, $target_css_id, $products, $title) {
		static $depth = 0;
		static $containers_checked = 0;
		$depth++;
		
		if (!is_array($data)) {
			$depth--;
			return $data;
		}

		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			error_log('[AEBG] Processing numeric array at depth ' . $depth . ' with ' . count($data) . ' items');
			foreach ($data as $index => $item) {
				if (is_array($item)) {
					$data[$index] = $this->updateProductVariablesInContainerRecursively($item, $target_css_id, $products, $title);
				}
			}
			$depth--;
			return $data;
		}

		// Check if this element matches the target CSS ID
		if (isset($data['settings']) && is_array($data['settings'])) {
			$element_css_id = $data['settings']['_element_id'] ?? $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '';
			$element_type = $data['elType'] ?? $data['widgetType'] ?? 'unknown';
			
			$containers_checked++;
			if ($containers_checked % 50 === 0) {
				error_log('[AEBG] Searched ' . $containers_checked . ' containers, still looking for: ' . $target_css_id);
			}
			
			if (!empty($element_css_id)) {
				error_log('[AEBG] Found element with CSS ID: ' . $element_css_id . ' (type: ' . $element_type . ') at depth ' . $depth);
			}
			
			if ($element_css_id === $target_css_id) {
				// Found the target container - extract product number from CSS ID
				$target_product_number = null;
				if (preg_match('/product-(\d+)/', $target_css_id, $matches)) {
					$target_product_number = (int)$matches[1];
				}
				
				// Found the target container - update variables in this container and all children
				error_log('[AEBG] ✅ MATCH FOUND! Target product container found: ' . $target_css_id . ' (product-' . $target_product_number . ')');
				error_log('[AEBG] Element type: ' . $element_type);
				error_log('[AEBG] Starting variable update in container and children (only widgets referencing product-' . $target_product_number . ')...');
				$result = $this->updateProductVariablesInContainerAndChildren($data, $products, $title, $target_product_number);
				error_log('[AEBG] Variable update completed for container: ' . $target_css_id);
				$depth--;
				return $result;
			}
		}

		// Recursively process children to find the target container with validation
		if (isset($data['elements']) && is_array($data['elements'])) {
			error_log('[AEBG] Processing ' . count($data['elements']) . ' child elements at depth ' . $depth);
			foreach ($data['elements'] as $index => $element) {
				if (is_array($element)) {
					$processed_element = $this->updateProductVariablesInContainerRecursively($element, $target_css_id, $products, $title);
					// CRITICAL: Validate that processed element is still an array before assigning
					if (is_array($processed_element)) {
						$data['elements'][$index] = $processed_element;
					} else {
						error_log('[AEBG] ⚠️ WARNING: Processed element at index ' . $index . ' is not an array (type: ' . gettype($processed_element) . '), preserving original');
						// Keep original element to prevent corruption
					}
				} else {
					// CRITICAL: Remove non-array values from elements array to prevent Elementor errors
					error_log('[AEBG] ⚠️ WARNING: Element at index ' . $index . ' is not an array (type: ' . gettype($element) . '), removing to prevent corruption');
					unset($data['elements'][$index]);
				}
			}
			// Re-index array after potential removals
			$data['elements'] = array_values($data['elements']);
		}

		if (isset($data['content']) && is_array($data['content'])) {
			error_log('[AEBG] Processing ' . count($data['content']) . ' content items at depth ' . $depth);
			foreach ($data['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$processed_content = $this->updateProductVariablesInContainerRecursively($content_item, $target_css_id, $products, $title);
					// CRITICAL: Validate that processed content is still an array before assigning
					if (is_array($processed_content)) {
						$data['content'][$index] = $processed_content;
					} else {
						error_log('[AEBG] ⚠️ WARNING: Processed content at index ' . $index . ' is not an array (type: ' . gettype($processed_content) . '), preserving original');
						// Keep original content to prevent corruption
					}
				} else {
					// CRITICAL: Remove non-array values from content array to prevent Elementor errors
					error_log('[AEBG] ⚠️ WARNING: Content at index ' . $index . ' is not an array (type: ' . gettype($content_item) . '), removing to prevent corruption');
					unset($data['content'][$index]);
				}
			}
			// Re-index array after potential removals
			$data['content'] = array_values($data['content']);
		}

		$depth--;
		if ($depth === 0) {
			error_log('[AEBG] ⚠️ WARNING: Finished recursive search without finding container: ' . $target_css_id);
			error_log('[AEBG] Total containers checked: ' . $containers_checked);
		}
		return $data;
	}
	
	/**
	 * Update product variables in container and all its children
	 * 
	 * @param array $container Container data
	 * @param array $products Products array
	 * @param string $title Post title
	 * @return array Updated container
	 */
	private function updateProductVariablesInContainerAndChildren($container, $products, $title, $target_product_number = null) {
		static $variables_replaced = 0;
		
		// CRITICAL: Always return a valid array structure - if input is not an array, return empty array to prevent corruption
		if (!is_array($container)) {
			error_log('[AEBG] ⚠️ WARNING: updateProductVariablesInContainerAndChildren received non-array input, type: ' . gettype($container));
			return [];
		}

		error_log('[AEBG] updateProductVariablesInContainerAndChildren - Processing container...');
		
		// Update settings in this container (only if it's a widget)
		if (isset($container['elType']) && $container['elType'] === 'widget' && isset($container['settings']) && is_array($container['settings'])) {
			// CRITICAL: Ensure widgetType exists - Elementor requires it for all widgets
			if (!isset($container['widgetType']) || empty($container['widgetType'])) {
				error_log('[AEBG] ⚠️ WARNING: Widget missing widgetType, attempting to infer from settings...');
				// Try to infer widgetType from settings or use 'unknown' as fallback
				$container['widgetType'] = 'unknown';
			}
			$widget_type = $container['widgetType'];
			
			// OPTIMIZATION: Only process widgets that reference the target product (if target_product_number is specified)
			if ($target_product_number !== null) {
				$has_target_variables = $this->widgetReferencesProduct($container['settings'], $target_product_number);
				if (!$has_target_variables) {
					error_log('[AEBG] ⏭️ Skipping widget ' . $widget_type . ' - does not reference product-' . $target_product_number);
					// Still process children in case they reference the target product
				} else {
					error_log('[AEBG] Updating variables in widget settings (widget: ' . $widget_type . ', references product-' . $target_product_number . ')...');
					$settings_before = json_encode($container['settings']);
					$updated_settings = $this->updateProductVariablesInSettings($container['settings'], $products, $title, $widget_type, $target_product_number);
					// CRITICAL: Validate that settings update returned a valid array
					if (is_array($updated_settings)) {
						$container['settings'] = $updated_settings;
					} else {
						error_log('[AEBG] ⚠️ WARNING: updateProductVariablesInSettings returned non-array, preserving original settings');
					}
					$settings_after = json_encode($container['settings']);
					if ($settings_before !== $settings_after) {
						$variables_replaced++;
						error_log('[AEBG] ✅ Variables replaced in widget settings (total: ' . $variables_replaced . ')');
					} else {
						error_log('[AEBG] No variables found in widget settings');
					}
				}
			} else {
				// No target product specified - process all widgets (bulk generation mode)
				error_log('[AEBG] Updating variables in widget settings (widget: ' . $widget_type . ')...');
				$settings_before = json_encode($container['settings']);
				$updated_settings = $this->updateProductVariablesInSettings($container['settings'], $products, $title, $widget_type, null);
				// CRITICAL: Validate that settings update returned a valid array
				if (is_array($updated_settings)) {
					$container['settings'] = $updated_settings;
				} else {
					error_log('[AEBG] ⚠️ WARNING: updateProductVariablesInSettings returned non-array, preserving original settings');
				}
				$settings_after = json_encode($container['settings']);
				if ($settings_before !== $settings_after) {
					$variables_replaced++;
					error_log('[AEBG] ✅ Variables replaced in widget settings (total: ' . $variables_replaced . ')');
				} else {
					error_log('[AEBG] No variables found in widget settings');
				}
			}
		}

		// Recursively process children with validation
		if (isset($container['elements']) && is_array($container['elements'])) {
			error_log('[AEBG] Processing ' . count($container['elements']) . ' child elements in container...');
			foreach ($container['elements'] as $index => $element) {
				if (is_array($element)) {
					$processed_element = $this->updateProductVariablesInContainerAndChildren($element, $products, $title, $target_product_number);
					// CRITICAL: Validate that processed element is still an array before assigning
					if (is_array($processed_element)) {
						$container['elements'][$index] = $processed_element;
					} else {
						error_log('[AEBG] ⚠️ WARNING: Processed element at index ' . $index . ' is not an array (type: ' . gettype($processed_element) . '), preserving original');
						// Keep original element to prevent corruption
					}
				} else {
					// CRITICAL: Remove non-array values from elements array to prevent Elementor errors
					error_log('[AEBG] ⚠️ WARNING: Element at index ' . $index . ' is not an array (type: ' . gettype($element) . '), removing to prevent corruption');
					unset($container['elements'][$index]);
				}
			}
			// Re-index array after potential removals
			$container['elements'] = array_values($container['elements']);
		}

		if (isset($container['content']) && is_array($container['content'])) {
			error_log('[AEBG] Processing ' . count($container['content']) . ' content items in container...');
			foreach ($container['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$processed_content = $this->updateProductVariablesInContainerAndChildren($content_item, $products, $title, $target_product_number);
					// CRITICAL: Validate that processed content is still an array before assigning
					if (is_array($processed_content)) {
						$container['content'][$index] = $processed_content;
					} else {
						error_log('[AEBG] ⚠️ WARNING: Processed content at index ' . $index . ' is not an array (type: ' . gettype($processed_content) . '), preserving original');
						// Keep original content to prevent corruption
					}
				} else {
					// CRITICAL: Remove non-array values from content array to prevent Elementor errors
					error_log('[AEBG] ⚠️ WARNING: Content at index ' . $index . ' is not an array (type: ' . gettype($content_item) . '), removing to prevent corruption');
					unset($container['content'][$index]);
				}
			}
			// Re-index array after potential removals
			$container['content'] = array_values($container['content']);
		}

		error_log('[AEBG] Container processing complete. Total variables replaced: ' . $variables_replaced);
		return $container;
	}
	
	/**
	 * Update product variables in container using VariableReplacer (legacy method - processes entire container)
	 * 
	 * @param array $container Container data
	 * @param array $products Products array
	 * @param string $title Post title
	 * @return array Updated container
	 */
	private function updateProductVariablesInContainerLegacy($container, $products, $title) {
		if (!is_array($container)) {
			return $container;
		}

		// Update settings (only if it's a widget)
		if (isset($container['elType']) && $container['elType'] === 'widget' && isset($container['settings']) && is_array($container['settings'])) {
			$widget_type = $container['widgetType'] ?? 'unknown';
			$container['settings'] = $this->updateProductVariablesInSettings($container['settings'], $products, $title, $widget_type, null);
		}

		// Recursively process children
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as $index => $element) {
				if (is_array($element)) {
					$container['elements'][$index] = $this->updateProductVariablesInContainerLegacy($element, $products, $title);
				}
			}
		}

		if (isset($container['content']) && is_array($container['content'])) {
			foreach ($container['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$container['content'][$index] = $this->updateProductVariablesInContainerLegacy($content_item, $products, $title);
				}
			}
		}

		return $container;
	}

	/**
	 * Update product variables in settings using Variables class
	 * 
	 * @param array $settings Settings to update
	 * @param array $products Products array
	 * @param string $title Post title
	 * @return array Updated settings
	 */
	private function updateProductVariablesInSettings($settings, $products, $title, $widget_type = 'unknown', $target_product_number = null) {
		// CRITICAL: Always return an array - if input is not an array, return empty array to prevent corruption
		if (!is_array($settings)) {
			error_log('[AEBG] ⚠️ WARNING: updateProductVariablesInSettings received non-array input, type: ' . gettype($settings));
			return [];
		}

		error_log('[AEBG] ===== UPDATING VARIABLES IN WIDGET SETTINGS =====');
		error_log('[AEBG] Widget type: ' . $widget_type);
		
		// Only log target product (if specified) to reduce log spam and confusion
		if ($target_product_number !== null) {
			$product_index = $target_product_number - 1;
			if (isset($products[$product_index]) && is_array($products[$product_index])) {
				$product = $products[$product_index];
				$product_name = !empty($product['short_name']) ? $product['short_name'] : ($product['name'] ?? 'N/A');
				$product_image = $product['image_url'] ?? $product['image'] ?? 'N/A';
				$product_url = $product['url'] ?? $product['affiliate_url'] ?? 'N/A';
				error_log('[AEBG] Target product-' . $target_product_number . ' data: name="' . substr($product_name, 0, 50) . '", image="' . substr($product_image, 0, 80) . '", url="' . substr($product_url, 0, 80) . '"');
			}
		} else {
			// Bulk mode - only log in verbose debug mode
			if (defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
				error_log('[AEBG] Products count: ' . count($products));
				foreach ($products as $index => $product) {
					if (is_array($product) && !empty($product['id'])) {
						$product_num = $index + 1;
						$product_name = !empty($product['short_name']) ? $product['short_name'] : ($product['name'] ?? 'N/A');
						$product_image = $product['image_url'] ?? $product['image'] ?? 'N/A';
						$product_url = $product['url'] ?? $product['affiliate_url'] ?? 'N/A';
						error_log('[AEBG] Product-' . $product_num . ' data: name="' . substr($product_name, 0, 50) . '", image="' . substr($product_image, 0, 80) . '", url="' . substr($product_url, 0, 80) . '"');
					}
				}
			}
		}

		// CRITICAL: If widget has a variable-only AI prompt referencing target product, force replace content
		// This handles cases where widgets have static content from old product but AI prompt references new product
		if ($target_product_number !== null && isset($settings['aebg_ai_prompt'])) {
			$ai_prompt = trim($settings['aebg_ai_prompt'] ?? '');
			
			// Check if prompt is a variable-only prompt for the target product
			if (preg_match('/^\{product-' . $target_product_number . '(-([a-z-]+))?\}$/', $ai_prompt, $prompt_matches)) {
				$variable_type = $prompt_matches[2] ?? 'name'; // Default to 'name' if no type specified
				$product_index = $target_product_number - 1;
				
				if (isset($products[$product_index])) {
					$product = $products[$product_index];
					
					// CRITICAL FIX: For button widgets with URL variables, skip text field replacement
					// The URL should only go in link.url, not in the text field
					if ($widget_type === 'button' && ($variable_type === 'url' || $variable_type === 'affiliate-url')) {
						// Skip text field replacement - link.url will be handled later
						error_log('[AEBG] ⏭️ Skipping text field replacement for button with URL variable - will update link.url only');
					} else {
						// Determine which field to update based on widget type
						$target_field = null;
						if ($widget_type === 'heading') {
							$target_field = 'title';
						} elseif ($widget_type === 'button') {
							$target_field = 'text';
						} elseif ($widget_type === 'text-editor') {
							$target_field = 'editor';
						} else {
							$target_field = 'text';
						}
						
						// Get the replacement value based on variable type
						$replacement_value = '';
						if ($variable_type === 'name') {
							$replacement_value = $product['short_name'] ?? $product['name'] ?? '';
						} elseif ($variable_type === 'price') {
							$replacement_value = $product['price'] ?? $product['finalprice'] ?? '';
						} elseif ($variable_type === 'brand') {
							$replacement_value = $product['brand'] ?? '';
						} else {
							// Use Variables class for other types
							$replacement_value = $this->variables->replace($ai_prompt, $title, [$product]);
						}
						
						// Apply replacement to appropriate field (always set, even if field doesn't exist)
						if (!empty($replacement_value) && $target_field !== null) {
							$settings[$target_field] = $replacement_value;
							error_log('[AEBG] ✅ FORCE REPLACED content in ' . $target_field . ' field (widget: ' . $widget_type . ', prompt: ' . $ai_prompt . ')');
							error_log('[AEBG]   NEW VALUE: ' . substr($replacement_value, 0, 100));
						}
					}
				}
			}
		}
		
		// Text fields that might contain product variables (exclude aebg_ai_prompt)
		$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode', 'caption', 'alt_text', 'editor'];
		$variables_found = 0;
		foreach ($text_fields as $field) {
			if (isset($settings[$field]) && is_string($settings[$field]) && $field !== 'aebg_ai_prompt') {
				$before = $settings[$field];
				
				// Log the field value for debugging (only if it's not empty and not too long)
				if (!empty($before) && strlen($before) < 200) {
					error_log('[AEBG] 📝 Checking field "' . $field . '" (widget: ' . $widget_type . '): ' . $before);
				} else if (!empty($before)) {
					error_log('[AEBG] 📝 Checking field "' . $field . '" (widget: ' . $widget_type . '): ' . substr($before, 0, 100) . '... (length: ' . strlen($before) . ')');
				}
				
				// Check if field contains product variables
				if (preg_match('/\{product-\d+/', $before)) {
					$variables_found++;
					error_log('[AEBG] 🔍 Found product variables in field "' . $field . '": ' . substr($before, 0, 100));
					
					// Extract which product variables are present
					preg_match_all('/\{product-(\d+)(-[a-z-]+)?\}/', $before, $matches);
					if (!empty($matches[1])) {
						$product_nums = array_unique($matches[1]);
						error_log('[AEBG]   Variables found: ' . implode(', ', array_map(function($n) use ($matches) {
							$idx = array_search($n, $matches[1]);
							return '{product-' . $n . ($matches[2][$idx] ?? '') . '}';
						}, $product_nums)));
					}
				}
				
				// CRITICAL FIX: For shortcode fields, replace {product-N} with product index number (N) instead of product name
				// This ensures price comparison shortcode can find the product by index
				if ($field === 'shortcode' && preg_match('/aebg_price_comparison.*product\s*=\s*["\']\{product-(\d+)\}["\']/', $before, $shortcode_matches)) {
					$product_num = (int)$shortcode_matches[1];
					// FIXED: Use a more precise regex that only matches the product attribute value, not the entire shortcode
					// Match: product="{product-6}" and replace with product="6"
					// CRITICAL FIX: Use callback function to properly insert the product number between backreferences
					$pattern = '/(product\s*=\s*["\'])\{product-' . preg_quote($product_num, '/') . '\}(["\'])/';
					$replaced_shortcode = preg_replace_callback($pattern, function($matches) use ($product_num) {
						return $matches[1] . $product_num . $matches[2];
					}, $before);
					if ($replaced_shortcode !== $before && $replaced_shortcode !== null) {
						$settings[$field] = $replaced_shortcode;
						error_log('[AEBG] ✅ REPLACED shortcode product attribute: {product-' . $product_num . '} → ' . $product_num);
						error_log('[AEBG]   BEFORE: ' . substr($before, 0, 150));
						error_log('[AEBG]   AFTER:  ' . substr($replaced_shortcode, 0, 150));
						continue; // Skip normal variable replacement for this field
					} else {
						error_log('[AEBG] ⚠️ Shortcode regex replacement failed for product-' . $product_num);
						error_log('[AEBG]   Shortcode: ' . substr($before, 0, 150));
						error_log('[AEBG]   Regex pattern: /(product\s*=\s*["\'])\{product-' . $product_num . '\}(["\'])/');
						// Debug: Test the regex manually
						if (preg_match('/(product\s*=\s*["\'])\{product-' . preg_quote($product_num, '/') . '\}(["\'])/', $before, $debug_matches)) {
							error_log('[AEBG]   Debug match found: $1="' . ($debug_matches[1] ?? '') . '", $2="' . ($debug_matches[2] ?? '') . '"');
						} else {
							error_log('[AEBG]   Debug: Regex pattern does not match!');
						}
					}
				}
				
				$settings[$field] = $this->variables->replace($settings[$field], $title, $products);
				$after = $settings[$field];
				if ($before !== $after) {
					error_log('[AEBG] ✅ REPLACED in "' . $field . '" (widget: ' . $widget_type . ')');
					error_log('[AEBG]   BEFORE: ' . substr($before, 0, 150));
					error_log('[AEBG]   AFTER:  ' . substr($after, 0, 150));
				} else if (preg_match('/\{product-\d+/', $before)) {
					error_log('[AEBG] ⚠️ Variables found but NO REPLACEMENT occurred in "' . $field . '" (widget: ' . $widget_type . ')');
					error_log('[AEBG]   BEFORE: ' . substr($before, 0, 150));
					error_log('[AEBG]   AFTER:  ' . substr($after, 0, 150));
				}
			}
		}
		if ($variables_found > 0) {
			error_log('[AEBG] Total fields with product variables found: ' . $variables_found);
		}

		// Update URL fields
		if (isset($settings['url']) && is_string($settings['url'])) {
			$before_url = $settings['url'];
			if (preg_match('/\{product-\d+/', $before_url)) {
				error_log('[AEBG] 🔍 Found product variables in url field: ' . $before_url);
			}
			$after_url = $this->variables->replace($settings['url'], $title, $products);
			$settings['url'] = $after_url;
			if ($before_url !== $after_url) {
				error_log('[AEBG] ✅ REPLACED in url field (widget: ' . $widget_type . ')');
				error_log('[AEBG]   BEFORE: ' . $before_url);
				error_log('[AEBG]   AFTER:  ' . $after_url);
			}
		}

		// CRITICAL: Force replace link.url if widget has {product-N-url} in AI prompt (even if link.url doesn't have variable)
		if ($target_product_number !== null && isset($settings['aebg_ai_prompt'])) {
			$ai_prompt = trim($settings['aebg_ai_prompt'] ?? '');
			if (preg_match('/^\{product-' . $target_product_number . '(-url|-affiliate-url)?\}$/', $ai_prompt)) {
				$product_index = $target_product_number - 1;
				if (isset($products[$product_index])) {
					$product = $products[$product_index];
					$product_url = $product['url'] ?? $product['affiliate_url'] ?? '';
					
					if (!empty($product_url)) {
						// Process affiliate link to replace @@@ with actual affiliate ID
						$shortcodes = new \AEBG\Core\Shortcodes();
						$product_url = $shortcodes->process_affiliate_link($product_url);
						
						if (!isset($settings['link']) || !is_array($settings['link'])) {
							$settings['link'] = [];
						}
						$old_url = $settings['link']['url'] ?? '';
						$settings['link']['url'] = $product_url;
						error_log('[AEBG] ✅ FORCE REPLACED link.url from AI prompt (widget: ' . $widget_type . ', prompt: ' . $ai_prompt . ')');
						error_log('[AEBG]   BEFORE: ' . $old_url);
						error_log('[AEBG]   AFTER:  ' . $product_url);
					}
				}
			}
		}
		
		// Update link.url for button widgets - CRITICAL for button functionality
		if (isset($settings['link']) && is_array($settings['link']) && isset($settings['link']['url']) && is_string($settings['link']['url'])) {
			$before_url = $settings['link']['url'];
			$has_variables = preg_match('/\{product-\d+/', $before_url);
			if ($has_variables) {
				error_log('[AEBG] 🔍 Found product variables in link.url (widget: ' . $widget_type . '): ' . $before_url);
				
				// Extract which product variables are present
				preg_match_all('/\{product-(\d+)(-[a-z-]+)?\}/', $before_url, $matches);
				if (!empty($matches[1])) {
					$product_nums = array_unique($matches[1]);
					foreach ($product_nums as $product_num) {
						$product_index = (int)$product_num - 1;
						if (isset($products[$product_index])) {
							$product = $products[$product_index];
							$product_url = $product['url'] ?? $product['affiliate_url'] ?? 'NOT FOUND';
							error_log('[AEBG]   Product-' . $product_num . ' URL source: ' . substr($product_url, 0, 100));
						}
					}
				}
			}
			$updated_link_url = $this->variables->replace($settings['link']['url'], $title, $products);
			
			// Process affiliate link to replace @@@ with actual affiliate ID
			$shortcodes = new \AEBG\Core\Shortcodes();
			$before_affiliate = $updated_link_url;
			$updated_link_url = $shortcodes->process_affiliate_link($updated_link_url);
			if ($before_affiliate !== $updated_link_url) {
				error_log('[AEBG]   Affiliate link processed: ' . substr($before_affiliate, 0, 100) . ' → ' . substr($updated_link_url, 0, 100));
			}
			
			$settings['link']['url'] = $updated_link_url;
			if ($has_variables && $before_url !== $updated_link_url) {
				error_log('[AEBG] ✅ REPLACED in link.url (widget: ' . $widget_type . ')');
				error_log('[AEBG]   BEFORE: ' . $before_url);
				error_log('[AEBG]   AFTER:  ' . $updated_link_url);
			} else if ($has_variables) {
				error_log('[AEBG] ⚠️ Variables found in link.url but no replacement occurred!');
				error_log('[AEBG]   Original: ' . $before_url);
				error_log('[AEBG]   Result:   ' . $updated_link_url);
			}
		}
		
		// CRITICAL: Force replace image.url if widget has {product-N-image} in AI prompt (even if image.url doesn't have variable)
		if ($target_product_number !== null && isset($settings['aebg_ai_prompt'])) {
			$ai_prompt = trim($settings['aebg_ai_prompt'] ?? '');
			if (preg_match('/^\{product-' . $target_product_number . '-image\}$/', $ai_prompt)) {
				$product_index = $target_product_number - 1;
				if (isset($products[$product_index])) {
					$product = $products[$product_index];
					$image_url = $product['image_url'] ?? $product['image'] ?? '';
					
					if (!empty($image_url)) {
						if (!isset($settings['image']) || !is_array($settings['image'])) {
							$settings['image'] = [];
						}
						$old_url = $settings['image']['url'] ?? '';
						$settings['image']['url'] = $image_url;
						$settings['image']['id'] = ''; // Clear ID to force URL usage
						error_log('[AEBG] ✅ FORCE REPLACED image.url from AI prompt (widget: ' . $widget_type . ', prompt: ' . $ai_prompt . ')');
						error_log('[AEBG]   BEFORE: ' . $old_url);
						error_log('[AEBG]   AFTER:  ' . $image_url);
					}
				}
			}
		}
		
		// Update image settings if they contain product variables
		// This handles both direct image.url and image variables in other fields
		if (isset($settings['image'])) {
			if (is_array($settings['image'])) {
				// Handle image.url field
				if (isset($settings['image']['url']) && is_string($settings['image']['url'])) {
					$before_image_url = $settings['image']['url'];
					if (preg_match('/\{product-\d+/', $before_image_url)) {
						error_log('[AEBG] 🔍 Found product variables in image.url (widget: ' . $widget_type . '): ' . $before_image_url);
						
						// Extract which product variables are present
						preg_match_all('/\{product-(\d+)(-[a-z-]+)?\}/', $before_image_url, $matches);
						if (!empty($matches[1])) {
							$product_nums = array_unique($matches[1]);
							foreach ($product_nums as $product_num) {
								$product_index = (int)$product_num - 1;
								if (isset($products[$product_index])) {
									$product = $products[$product_index];
									$product_image = $product['image_url'] ?? $product['image'] ?? 'NOT FOUND';
									error_log('[AEBG]   Product-' . $product_num . ' image source: ' . substr($product_image, 0, 100));
								}
							}
						}
					}
					$replaced_url = $this->variables->replace($settings['image']['url'], $title, $products);
					// Only update if replacement actually changed something (found variables)
					if ($replaced_url !== $settings['image']['url']) {
						$settings['image']['url'] = $replaced_url;
						$settings['image']['id'] = ''; // Clear ID when URL is set
						error_log('[AEBG] ✅ REPLACED in image.url (widget: ' . $widget_type . ')');
						error_log('[AEBG]   BEFORE: ' . $before_image_url);
						error_log('[AEBG]   AFTER:  ' . $replaced_url);
					} else if (preg_match('/\{product-\d+/', $before_image_url)) {
						error_log('[AEBG] ⚠️ Variables found in image.url but no replacement occurred!');
						error_log('[AEBG]   Original: ' . $before_image_url);
						error_log('[AEBG]   Result:   ' . $replaced_url);
					}
				}
			} elseif (is_string($settings['image'])) {
				// Handle case where image is a string (variable)
				$before_image = $settings['image'];
				$replaced_image = $this->variables->replace($settings['image'], $title, $products);
				if ($replaced_image !== $settings['image'] && filter_var($replaced_image, FILTER_VALIDATE_URL)) {
					// Convert to proper image structure
					$settings['image'] = [
						'url' => $replaced_image,
						'id' => ''
					];
					error_log('[AEBG] ✅ Converted image variable to image structure (widget: ' . $widget_type . ')');
					error_log('[AEBG]   BEFORE: ' . $before_image);
					error_log('[AEBG]   AFTER:  ' . $replaced_image);
				}
			}
		}
		
		// Also check if any text field contains an image variable that should be applied to image widget
		// This handles cases where {product-1-image} is in a text field but should be in image.url
		$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode'];
		foreach ($text_fields as $field) {
			if (isset($settings[$field]) && is_string($settings[$field])) {
				// Check if this field contains only an image URL variable
				$field_content = trim($settings[$field]);
				if (preg_match('/^\{product-(\d+)-image\}$/', $field_content, $matches)) {
					$product_num = (int)$matches[1];
					$product_index = $product_num - 1;
					
					if (isset($products[$product_index]) && $products[$product_index] !== null) {
						$product = $products[$product_index];
						$image_url = $product['image_url'] ?? $product['image'] ?? '';
						
						if (!empty($image_url)) {
							// Set image URL and clear the text field
							if (!isset($settings['image']) || !is_array($settings['image'])) {
								$settings['image'] = [];
							}
							$settings['image']['url'] = $image_url;
							$settings['image']['id'] = '';
							$settings[$field] = ''; // Clear the variable from text field
							error_log('[AEBG] ✅ Moved image variable from ' . $field . ' to image.url (widget: ' . $widget_type . ')');
							error_log('[AEBG]   Image URL: ' . $image_url);
						} else {
							error_log('[AEBG] ⚠️ Image variable found in ' . $field . ' but product-' . $product_num . ' has no image_url!');
						}
					} else {
						error_log('[AEBG] ⚠️ Image variable found in ' . $field . ' but product-' . $product_num . ' not found in products array!');
					}
				}
			}
		}
		
		// CRITICAL: Handle icon-list items - replace variables but preserve generated content
		if (($widget_type === 'icon-list' || $widget_type === 'aebg-icon-list') && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
			foreach ($settings['icon_list'] as $index => &$icon_item) {
				// Only replace variables in icon-list item text if it contains variables
				// Don't overwrite generated content that was already applied
				if (isset($icon_item['text']) && is_string($icon_item['text']) && preg_match('/\{product-\d+/', $icon_item['text'])) {
					$old_text = $icon_item['text'];
					$icon_item['text'] = $this->variables->replace($icon_item['text'], $title, $products);
					if ($old_text !== $icon_item['text']) {
						error_log('[AEBG] ✅ Replaced variables in icon-list item ' . $index . ' text');
						error_log('[AEBG]   BEFORE: ' . substr($old_text, 0, 100));
						error_log('[AEBG]   AFTER:  ' . substr($icon_item['text'], 0, 100));
					}
				}
			}
			unset($icon_item);
		}

	error_log('[AEBG] ===== FINISHED UPDATING VARIABLES IN WIDGET SETTINGS =====');
	return $settings;
}

/**
 * Truncate product data for prompt to prevent exceeding token limits (like old version).
	 *
	 * @param array $products Array of products.
	 * @return array Truncated products array.
	 */
	private function truncateProductDataForPrompt($products) {
		if (empty($products) || !is_array($products)) {
			return $products;
		}
		
		$truncated = [];
		foreach ($products as $product) {
			if (!is_array($product)) {
				$truncated[] = $product;
				continue;
			}
			
			$truncated_product = [];
			$truncated_product['name'] = $this->truncateString($product['name'] ?? '', 100);
			$truncated_product['brand'] = $this->truncateString($product['brand'] ?? '', 50);
			$truncated_product['price'] = $product['price'] ?? '';
			$truncated_product['rating'] = $product['rating'] ?? '';
			$truncated_product['description'] = $this->truncateString($product['description'] ?? '', 200);
			$truncated_product['category'] = $this->truncateString($product['category'] ?? '', 50);
			$truncated_product['merchant'] = $this->truncateString($product['merchant'] ?? '', 50);
			
			// Keep essential fields but truncate long ones
			if (isset($product['id'])) {
				$truncated_product['id'] = $product['id'];
			}
			if (isset($product['url'])) {
				$truncated_product['url'] = $product['url'];
			}
			if (isset($product['image_url'])) {
				$truncated_product['image_url'] = $product['image_url'];
			}
			
			$truncated[] = $truncated_product;
		}
		
		return $truncated;
	}

	/**
	 * Truncate a string to a maximum length (like old version).
	 *
	 * @param string $string The string to truncate.
	 * @param int $max_length Maximum length.
	 * @return string Truncated string.
	 */
	private function truncateString($string, $max_length) {
		if (strlen($string) <= $max_length) {
			return $string;
		}
		return substr($string, 0, $max_length) . '...';
	}

	/**
	 * Process element for AI content regeneration (like old version).
	 * 
	 * @param array $element Element data
	 * @param string $css_id CSS ID of the container
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array Updated element
	 */
	private function processElementForAIContentRegeneration($element, $css_id, $title, $products, $context, $api_key, $ai_model) {
		if (!is_array($element)) {
			return $element;
		}

		// Check if this element belongs to the target container
		$element_css_id = $element['settings']['_element_id'] ?? '';
		if ($element_css_id === $css_id || (isset($element['settings']['_css_classes']) && strpos($element['settings']['_css_classes'], $css_id) !== false)) {
			// This element belongs to the target container, process it
			$element = $this->generateAIContentForElement($element, $css_id, $title, $products, $context, $api_key, $ai_model);
		}

		// Recursively process children
		if (isset($element['elements']) && is_array($element['elements'])) {
			foreach ($element['elements'] as $index => $child) {
				if (is_array($child)) {
					$element['elements'][$index] = $this->processElementForAIContentRegeneration($child, $css_id, $title, $products, $context, $api_key, $ai_model);
				}
			}
		}

		if (isset($element['content']) && is_array($element['content'])) {
			foreach ($element['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$element['content'][$index] = $this->processElementForAIContentRegeneration($content_item, $css_id, $title, $products, $context, $api_key, $ai_model);
				}
			}
		}

		return $element;
	}

	/**
	 * Generate AI content for element (like old version).
	 * 
	 * @param array $element Element data
	 * @param string $css_id CSS ID of the container
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array Updated element
	 */
	private function generateAIContentForElement($element, $css_id, $title, $products, $context, $api_key, $ai_model) {
		if (!is_array($element) || !isset($element['elType']) || $element['elType'] !== 'widget') {
			return $element;
		}

		$widget_type = $element['widgetType'] ?? 'unknown';
		$settings = $element['settings'] ?? [];

		// Check for AI enable flag
		if (isset($settings['aebg_ai_enable']) && $settings['aebg_ai_enable'] === 'yes') {
			$prompt = $settings['aebg_ai_prompt'] ?? '';

			if (!empty($prompt) && $this->content_generator) {
				error_log('[AEBG] Generating AI content for widget: ' . $widget_type . ' in container: ' . $css_id);

				// Update context with products
				$updated_context = $this->updateContextWithProducts($context, $products);

				// Generate content using ContentGenerator
				$generated_content = $this->content_generator->generateContentWithPrompt($title, $products, $updated_context, $api_key, $ai_model, $prompt);

				if (!is_wp_error($generated_content) && !empty($generated_content) && $generated_content !== false) {
					// Apply content to widget
					$element = $this->applyContentToWidget($element, $generated_content, $widget_type);

					// Store metadata
					$element['settings']['aebg_generated_content'] = $generated_content;
					$element['settings']['aebg_generated_at'] = current_time('mysql');
				}
			}
		}

		// Process settings for AI content (like old version)
		$element['settings'] = $this->processSettingsForAIContent($element['settings'], $css_id, $title, $products, $context, $api_key, $ai_model);

		return $element;
	}

	/**
	 * Process settings for AI content (like old version).
	 * 
	 * @param array $settings Settings array
	 * @param string $css_id CSS ID (product number)
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array Updated settings
	 */
	private function processSettingsForAIContent($settings, $css_id, $title, $products, $context, $api_key, $ai_model) {
		if (!is_array($settings)) {
			return $settings;
		}

		// Extract product number from CSS ID (e.g., "product-1" -> 1)
		if (preg_match('/product-(\d+)/', $css_id, $matches)) {
			$product_number = (int)$matches[1];
			$product_index = $product_number - 1;

			if (isset($products[$product_index]) && $products[$product_index] !== null) {
				$product = $products[$product_index];

				// Process icon list items if present
				if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
					foreach ($settings['icon_list'] as $index => &$icon_item) {
						if (isset($icon_item['aebg_iconlist_ai_enable']) && $icon_item['aebg_iconlist_ai_enable'] === 'yes') {
							$icon_prompt = $icon_item['aebg_iconlist_ai_prompt'] ?? '';

							if (!empty($icon_prompt) && $this->content_generator) {
								// NOTE: Rate limiting is now handled by APIClient via OpenAI's 429 responses
								// No need for manual delays - APIClient will handle wait/retry automatically
								
								// Update context with products
								$updated_context = $this->updateContextWithProducts($context, $products);

								// Generate content (use icon-list-item format to skip text formatting)
								$generated_content = $this->content_generator->generateContentWithPrompt($title, $products, $updated_context, $api_key, $ai_model, $icon_prompt, 'icon-list-item');

								if (!is_wp_error($generated_content) && !empty($generated_content)) {
									// Clean the content
									$cleaned_content = $generated_content;
									if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
										$cleaned_content = $matches[1];
									}
									$cleaned_content = trim($cleaned_content);

									if (!empty($cleaned_content)) {
										$icon_item['text'] = $cleaned_content;
									}
								} elseif (is_wp_error($generated_content)) {
									$error_code = $generated_content->get_error_code();
									$error_message = $generated_content->get_error_message();
									
									// NOTE: Rate limit errors are now handled by APIClient with proper wait/retry logic
									// No need for manual delays - APIClient will handle retries automatically
									if (strpos($error_message, 'rate limit') !== false || $error_code === 'rate_limit_exceeded') {
										error_log('[AEBG] Rate limit detected for icon item ' . $index . ' - APIClient will handle retry automatically');
									}
								}
							}
						}
					}
					unset($icon_item);
				}

				// CRITICAL: Process generic repeater fields (like old version)
				// This handles any repeater field, not just icon_list
				foreach ($settings as $key => &$setting) {
					if (is_array($setting) && $this->is_repeater_items($setting)) {
						error_log('[AEBG] Processing repeater field: ' . $key);
						foreach ($setting as &$item) {
							// Check if this repeater item has AI enabled
							if (isset($item['aebg_ai_enable']) && $item['aebg_ai_enable'] === 'yes') {
								$item_prompt = $item['aebg_ai_prompt'] ?? '';
								if (!empty($item_prompt) && $this->content_generator) {
									error_log('[AEBG] Found AI-enabled repeater item in field: ' . $key);
									
									// Update context with products
									$updated_context = $this->updateContextWithProducts($context, $products);
									
									// Generate content for this repeater item
									$generated_content = $this->content_generator->generateContentWithPrompt($title, $products, $updated_context, $api_key, $ai_model, $item_prompt);
									
									if (!is_wp_error($generated_content) && !empty($generated_content)) {
										// Clean the content
										$cleaned_content = $generated_content;
										if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
											$cleaned_content = $matches[1];
										}
										$cleaned_content = trim($cleaned_content);
										
										// Update the repeater item with generated content
										if (isset($item['text'])) {
											$item['text'] = $cleaned_content;
										} elseif (isset($item['title'])) {
											$item['title'] = $cleaned_content;
										} elseif (isset($item['content'])) {
											$item['content'] = $cleaned_content;
										}
										
										// Store the generated content for reference
										$item['aebg_generated_content'] = $generated_content;
										$item['aebg_generated_at'] = current_time('mysql');
										$item['aebg_prompt_used'] = $item_prompt;
										
										error_log('[AEBG] Updated repeater item with generated content');
									} else {
										error_log('[AEBG] Failed to generate content for repeater item: ' . (is_wp_error($generated_content) ? $generated_content->get_error_message() : 'empty result'));
									}
								}
							}
						}
						unset($item);
					}
				}
			}
		}

		return $settings;
	}

	/**
	 * Check if a prompt references a specific product number
	 * 
	 * @param string $prompt The AI prompt text
	 * @param int $product_number The product number to check for (1-based)
	 * @return bool True if prompt references this product
	 */
	/**
	 * Check if a widget's settings contain variables that reference a specific product
	 * 
	 * @param array $settings Widget settings
	 * @param int $product_number Product number to check for
	 * @return bool True if widget references the product
	 */
	private function widgetReferencesProduct($settings, $product_number) {
		if (!is_array($settings)) {
			return false;
		}
		
		// Check all text fields for product variables
		$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode', 'caption', 'alt_text'];
		foreach ($text_fields as $field) {
			if (isset($settings[$field]) && is_string($settings[$field])) {
				if (preg_match('/\{product-' . $product_number . '(-[a-z-]+)?\}/', $settings[$field])) {
					return true;
				}
			}
		}
		
		// CRITICAL: Also check AI prompt field - if a widget has an AI prompt referencing the product,
		// it should be processed even if current content doesn't have variables yet
		// This ensures widgets with AI prompts like "{product-3}" are included in processing
		if (isset($settings['aebg_ai_prompt']) && is_string($settings['aebg_ai_prompt'])) {
			if ($this->promptReferencesProduct($settings['aebg_ai_prompt'], $product_number)) {
				return true;
			}
		}
		
		// Check URL fields
		if (isset($settings['url']) && is_string($settings['url'])) {
			if (preg_match('/\{product-' . $product_number . '(-[a-z-]+)?\}/', $settings['url'])) {
				return true;
			}
		}
		
		// Check link.url
		if (isset($settings['link']['url']) && is_string($settings['link']['url'])) {
			if (preg_match('/\{product-' . $product_number . '(-[a-z-]+)?\}/', $settings['link']['url'])) {
				return true;
			}
		}
		
		// Check image.url
		if (isset($settings['image']['url']) && is_string($settings['image']['url'])) {
			if (preg_match('/\{product-' . $product_number . '(-[a-z-]+)?\}/', $settings['image']['url'])) {
				return true;
			}
		}
		
		// Check icon-list items
		if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
			foreach ($settings['icon_list'] as $item) {
				if (isset($item['text']) && is_string($item['text'])) {
					if (preg_match('/\{product-' . $product_number . '(-[a-z-]+)?\}/', $item['text'])) {
						return true;
					}
				}
				if (isset($item['aebg_iconlist_ai_prompt']) && is_string($item['aebg_iconlist_ai_prompt'])) {
					if (preg_match('/\{product-' . $product_number . '(-[a-z-]+)?\}/', $item['aebg_iconlist_ai_prompt'])) {
						return true;
					}
				}
			}
		}
		
		return false;
	}

	private function promptReferencesProduct($prompt, $product_number) {
		if (empty($prompt) || !is_string($prompt)) {
			return false;
		}
		
		// Check for {product-N} pattern where N matches product_number
		// Also matches {product-N-name}, {product-N-price}, etc.
		$pattern = '/\{product-' . preg_quote($product_number, '/') . '(-[^}]+)?\}/';
		return preg_match($pattern, $prompt) === 1;
	}

	/**
	 * Process AI content in container using ContentGenerator
	 * 
	 * @param array $container Container data
	 * @param string $title Post title
	 * @param array $products Products array
	 * @param array $context Context data
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @param int|null $target_product_number Target product number for filtering (null = regenerate all)
	 * @return array Updated container
	 */
	private function processAIContentInContainer($container, $title, $products, $context, $api_key, $ai_model, $target_product_number = null) {
		if (!is_array($container)) {
			return $container;
		}

		// Process widgets
		if (isset($container['elType']) && $container['elType'] === 'widget') {
			$widget_type = $container['widgetType'] ?? 'unknown';
			$settings = $container['settings'] ?? [];
			
			// CRITICAL: Process icon-list items with individual AI prompts (like processSettingsForAIContent)
			if (($widget_type === 'icon-list' || $widget_type === 'aebg-icon-list') && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
				$updated_context = $this->updateContextWithProducts($context, $products);
				
				$regenerated_count = 0;
				$skipped_count = 0;
				
				foreach ($settings['icon_list'] as $index => &$icon_item) {
					if (isset($icon_item['aebg_iconlist_ai_enable']) && $icon_item['aebg_iconlist_ai_enable'] === 'yes') {
						$icon_prompt = trim($icon_item['aebg_iconlist_ai_prompt'] ?? '');
						
						// CRITICAL: Skip variable-only prompts for icon-list items
						$is_image_variable = preg_match('/^\{product-\d+-image\}$/', $icon_prompt);
						$is_url_variable = preg_match('/^\{product-\d+-url\}$/', $icon_prompt);
						$is_affiliate_url_variable = preg_match('/^\{product-\d+-affiliate-url\}$/', $icon_prompt);
						$is_name_variable = preg_match('/^\{product-\d+-name\}$/', $icon_prompt);
						$is_price_variable = preg_match('/^\{product-\d+-price\}$/', $icon_prompt);
						$is_brand_variable = preg_match('/^\{product-\d+-brand\}$/', $icon_prompt);
						$is_rating_variable = preg_match('/^\{product-\d+-rating\}$/', $icon_prompt);
						$is_description_variable = preg_match('/^\{product-\d+-description\}$/', $icon_prompt);
						$is_merchant_variable = preg_match('/^\{product-\d+-merchant\}$/', $icon_prompt);
						$is_category_variable = preg_match('/^\{product-\d+-category\}$/', $icon_prompt);
						$is_single_variable = preg_match('/^\{product-\d+(-[a-z-]+)?\}$/', $icon_prompt);
						
						if ($is_image_variable || $is_url_variable || $is_affiliate_url_variable || 
							$is_name_variable || $is_price_variable || $is_brand_variable || 
							$is_rating_variable || $is_description_variable || $is_merchant_variable || 
							$is_category_variable || $is_single_variable) {
							// Skip AI generation - these will be handled directly via variable replacement
							error_log('[AEBG] Skipping AI for icon-list item ' . $index . ' - variable-only prompt: ' . $icon_prompt);
							$skipped_count++;
							continue;
						}
						
						// OPTIMIZATION: Only regenerate if prompt references target product
						if ($target_product_number !== null && !$this->promptReferencesProduct($icon_prompt, $target_product_number)) {
							error_log('[AEBG] Skipping icon-list item ' . $index . ' - prompt does not reference product-' . $target_product_number);
							$skipped_count++;
							continue; // Skip this icon item
						}
						
						if (!empty($icon_prompt) && $this->content_generator) {
							error_log('[AEBG] Regenerating icon-list item ' . $index . ' with AI prompt (references product-' . $target_product_number . ')');
							error_log('[AEBG] Using user-defined prompt: ' . substr($icon_prompt, 0, 100) . (strlen($icon_prompt) > 100 ? '...' : ''));
							$regenerated_count++;
							
							// Generate content (use icon-list-item format to skip text formatting)
							$generated_content = $this->content_generator->generateContentWithPrompt($title, $products, $updated_context, $api_key, $ai_model, $icon_prompt, 'icon-list-item');
							
							if (!is_wp_error($generated_content) && !empty($generated_content)) {
								// Clean the content
								$cleaned_content = $generated_content;
								if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
									$cleaned_content = $matches[1];
								}
								$cleaned_content = trim($cleaned_content);
								
								if (!empty($cleaned_content)) {
									$old_text = $icon_item['text'] ?? '';
									$icon_item['text'] = $cleaned_content;
									error_log('[AEBG] ✅ Updated icon-list item ' . $index . ' - Old: "' . substr($old_text, 0, 50) . '..." New: "' . substr($cleaned_content, 0, 50) . '..."');
								} else {
									error_log('[AEBG] ⚠️ Generated content for icon-list item ' . $index . ' is empty after cleaning');
								}
							} else {
								$error_msg = is_wp_error($generated_content) ? $generated_content->get_error_message() : 'empty result';
								error_log('[AEBG] ❌ Failed to generate content for icon-list item ' . $index . ': ' . $error_msg);
							}
						}
					}
				}
				unset($icon_item);
				
				if ($target_product_number !== null) {
					error_log('[AEBG] Icon-list processing: ' . $regenerated_count . ' regenerated, ' . $skipped_count . ' skipped (not referencing product-' . $target_product_number . ')');
				}
			}
			
			// Check for AI enable flag on widget itself
			if (isset($settings['aebg_ai_enable']) && $settings['aebg_ai_enable'] === 'yes') {
				$prompt = $settings['aebg_ai_prompt'] ?? '';
				$trimmed_prompt = trim($prompt);
				
				// CRITICAL: Skip variable-only prompts (image/URL/name/price/brand/etc.) - these should NOT be sent to AI
				// These should be replaced directly, not sent to AI
				$is_image_variable = preg_match('/^\{product-\d+-image\}$/', $trimmed_prompt);
				$is_url_variable = preg_match('/^\{product-\d+-url\}$/', $trimmed_prompt);
				$is_affiliate_url_variable = preg_match('/^\{product-\d+-affiliate-url\}$/', $trimmed_prompt);
				$is_name_variable = preg_match('/^\{product-\d+-name\}$/', $trimmed_prompt);
				$is_price_variable = preg_match('/^\{product-\d+-price\}$/', $trimmed_prompt);
				$is_brand_variable = preg_match('/^\{product-\d+-brand\}$/', $trimmed_prompt);
				$is_rating_variable = preg_match('/^\{product-\d+-rating\}$/', $trimmed_prompt);
				$is_description_variable = preg_match('/^\{product-\d+-description\}$/', $trimmed_prompt);
				$is_merchant_variable = preg_match('/^\{product-\d+-merchant\}$/', $trimmed_prompt);
				$is_category_variable = preg_match('/^\{product-\d+-category\}$/', $trimmed_prompt);
				// Check for any single variable pattern (e.g., {product-2}, {product-2-name}, etc.)
				$is_single_variable = preg_match('/^\{product-\d+(-[a-z-]+)?\}$/', $trimmed_prompt);
				
				if ($is_image_variable || $is_url_variable || $is_affiliate_url_variable || 
					$is_name_variable || $is_price_variable || $is_brand_variable || 
					$is_rating_variable || $is_description_variable || $is_merchant_variable || 
					$is_category_variable || $is_single_variable) {
					// Skip AI generation - these will be handled directly via variable replacement
					error_log('[AEBG] Skipping AI for widget ' . $widget_type . ' - variable-only prompt: ' . $trimmed_prompt);
					// Continue to process children, but skip this widget's AI generation
				} elseif ($target_product_number !== null && !$this->promptReferencesProduct($prompt, $target_product_number)) {
					// OPTIMIZATION: Only regenerate if prompt references target product
					error_log('[AEBG] Skipping widget ' . $widget_type . ' - prompt does not reference product-' . $target_product_number);
					// Continue to process children, but skip this widget's AI generation
				} elseif (!empty($trimmed_prompt) && $this->content_generator) {
					error_log('[AEBG] Found AI-enabled widget: ' . $widget_type . (($target_product_number !== null) ? ' (references product-' . $target_product_number . ')' : ''));
					error_log('[AEBG] Using user-defined prompt: ' . substr($trimmed_prompt, 0, 100) . (strlen($trimmed_prompt) > 100 ? '...' : ''));
					
					// Generate content using ContentGenerator with the user-defined prompt
					$generated_content = $this->content_generator->generateContentWithPrompt($title, $products, $context, $api_key, $ai_model, $trimmed_prompt);
					
					if (!is_wp_error($generated_content) && !empty($generated_content) && $generated_content !== false) {
						// Apply content to widget using ElementorTemplateProcessor approach
						$container = $this->applyContentToWidget($container, $generated_content, $widget_type);
						
						// Store metadata
						$container['settings']['aebg_generated_content'] = $generated_content;
						$container['settings']['aebg_generated_at'] = current_time('mysql');
					}
				}
			}
		}

		// Recursively process children
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as $index => $element) {
				if (is_array($element)) {
					$container['elements'][$index] = $this->processAIContentInContainer($element, $title, $products, $context, $api_key, $ai_model, $target_product_number);
				}
			}
		}

		if (isset($container['content']) && is_array($container['content'])) {
			foreach ($container['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$container['content'][$index] = $this->processAIContentInContainer($content_item, $title, $products, $context, $api_key, $ai_model, $target_product_number);
				}
			}
		}

		return $container;
	}

	/**
	 * Apply generated content to widget
	 * 
	 * @param array $element Widget element
	 * @param string $content Generated content
	 * @param string $widget_type Widget type
	 * @return array Updated element
	 */
	private function applyContentToWidget($element, $content, $widget_type) {
		if (!isset($element['settings']) || !is_array($element['settings'])) {
			return $element;
		}

		$settings = &$element['settings'];
		$cleaned_content = trim($content);
		
		// Remove quotes if content is wrapped in them
		$cleaned_content = trim($cleaned_content, '"\'');
		
		// Use ContentFormatter for proper formatting
		$formatted_content = $cleaned_content;

		switch ($widget_type) {
			case 'text-editor':
				$formatted_content = ContentFormatter::formatTextEditorContent($cleaned_content);
				$settings['editor'] = $formatted_content;
				error_log('[AEBG] Applied text editor content: ' . substr($formatted_content, 0, 100) . '...');
				break;
				
			case 'heading':
				// For headings, use first line as title
				$lines = explode("\n", $cleaned_content);
				$title = trim($lines[0]);
				$title = strip_tags($title);
				$title = trim($title, '"\''); // Remove quotes
				$formatted_content = ContentFormatter::formatHeadingContent($title);
				$settings['title'] = $formatted_content;
				error_log('[AEBG] Applied heading title: ' . $formatted_content);
				break;
				
			case 'button':
				// For buttons, check if content is a URL
				if (preg_match('/^https?:\/\//i', $cleaned_content)) {
					// This is a URL, process affiliate link and apply to link.url
					$shortcodes = new \AEBG\Core\Shortcodes();
					$fixed_url = $shortcodes->process_affiliate_link($cleaned_content);
					
					// Ensure link structure exists
					if (!isset($settings['link']) || !is_array($settings['link'])) {
						$settings['link'] = [];
					}
					
					$settings['link']['url'] = $fixed_url;
					error_log('[AEBG] Applied button link URL: ' . $fixed_url);
				} else {
					// This is text, apply to button text
					$formatted_content = ContentFormatter::formatButtonContent($cleaned_content);
					$settings['text'] = strip_tags($formatted_content);
					error_log('[AEBG] Applied button text: ' . $settings['text']);
				}
				break;
				
			case 'image':
				// For images, handle image URLs properly
				$trimmed_content = trim($cleaned_content);
				error_log('[AEBG] Processing image content: ' . $trimmed_content);
				
				// Check if it's a valid URL
				$is_valid_url = false;
				if (!empty($trimmed_content)) {
					// Check if it looks like a URL (starts with http/https or has malformed patterns)
					if (preg_match('/^https?:\/\//i', $trimmed_content) || 
						preg_match('/^(s:\/\/|s\/\/|http:\/\/s:\/\/|https:\/\/s:\/\/|http:\/\/s\/\/|https:\/\/s\/\/)/', $trimmed_content)) {
						$is_valid_url = true;
						error_log('[AEBG] Detected URL pattern in image content');
					} else {
						// Try filter_var as fallback
						$is_valid_url = filter_var($trimmed_content, FILTER_VALIDATE_URL) !== false;
						error_log('[AEBG] filter_var check result: ' . ($is_valid_url ? 'valid' : 'invalid'));
					}
				}
				
				if ($is_valid_url) {
					// Fix malformed URLs
					if (preg_match('/^(http:\/\/|https:\/\/)?s:\/\//', $trimmed_content)) {
						$trimmed_content = preg_replace('/^(http:\/\/|https:\/\/)?s:\/\//', 'https://', $trimmed_content);
					} elseif (preg_match('/^(http:\/\/|https:\/\/)?s\/\//', $trimmed_content)) {
						$trimmed_content = preg_replace('/^(http:\/\/|https:\/\/)?s\/\//', 'https://', $trimmed_content);
					}
					
					// Ensure the image settings array exists
					if (!isset($settings['image']) || !is_array($settings['image'])) {
						$settings['image'] = [];
					}
					$settings['image']['url'] = $trimmed_content;
					$settings['image']['id'] = ''; // Clear any existing ID
					error_log('[AEBG] Successfully set image URL: ' . $trimmed_content);
				} else {
					// If not a URL, treat as caption
					$settings['caption'] = $trimmed_content;
					error_log('[AEBG] Applied image caption: ' . $trimmed_content);
				}
				break;
				
			case 'icon-list':
			case 'aebg-icon-list':
				// Parse content into list items
				$list_items = ContentFormatter::parseContentIntoListItems($cleaned_content);
				if (!empty($list_items)) {
					$settings['icon_list'] = $list_items;
					error_log('[AEBG] Applied icon list with ' . count($list_items) . ' items');
				}
				break;
				
			case 'icon':
				$settings['text'] = $cleaned_content;
				break;
				
			case 'icon-box':
				$settings['title_text'] = $cleaned_content;
				break;
				
			case 'flip-box':
				$settings['title_text_a'] = $cleaned_content;
				break;
				
			case 'call-to-action':
				$settings['title'] = $cleaned_content;
				break;
				
			default:
				// For any other widget, try common fields
				if (isset($settings['content'])) {
					$settings['content'] = $cleaned_content;
				} elseif (isset($settings['text'])) {
					$settings['text'] = $cleaned_content;
				} elseif (isset($settings['title'])) {
					$settings['title'] = $cleaned_content;
				} else {
					error_log('[AEBG] No suitable field found for widget type: ' . $widget_type);
				}
				break;
		}

		return $element;
	}

	/**
	 * Update product variables in Elementor data using VariableReplacer
	 * 
	 * @param array $elementor_data Elementor data
	 * @param array $products Products array
	 * @param string $title Post title
	 * @return array|\WP_Error Updated data or error
	 */
	public function updateProductVariablesInElementorData($elementor_data, $products, $title) {
		// Only log in debug mode to reduce log spam
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[AEBG] ===== START: updateProductVariablesInElementorData =====');
			error_log('[AEBG] Products count: ' . count($products));
		}
		
		try {
			if (!is_array($elementor_data)) {
				return new \WP_Error('invalid_data', 'Invalid Elementor data');
			}

			// Create deep copy
			$updated_data = DataUtilities::deepCopyElementorData($elementor_data);
			
			// Recursively update all product variables using Variables class
			$updated_data = $this->updateProductVariablesRecursively($updated_data, $products, $title);
			
			// Only log in debug mode
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[AEBG] ===== SUCCESS: updateProductVariablesInElementorData completed =====');
			}
			return $updated_data;
			
		} catch (\Exception $e) {
			error_log('[AEBG] Error in updateProductVariablesInElementorData: ' . $e->getMessage());
			return new \WP_Error('update_error', $e->getMessage());
		}
	}

	/**
	 * Recursively update product variables in Elementor data
	 * 
	 * @param array $data Data to update
	 * @param array $products Products array
	 * @param string $title Post title
	 * @return array Updated data
	 */
	private function updateProductVariablesRecursively($data, $products, $title) {
		if (!is_array($data)) {
			return $data;
		}

		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				if (is_array($item)) {
					$data[$index] = $this->updateProductVariablesRecursively($item, $products, $title);
				}
			}
			return $data;
		}

		// Update settings if they exist (only if it's a widget)
		if (isset($data['elType']) && $data['elType'] === 'widget' && isset($data['settings']) && is_array($data['settings'])) {
			$widget_type = $data['widgetType'] ?? 'unknown';
			$data['settings'] = $this->updateProductVariablesInSettings($data['settings'], $products, $title, $widget_type, null);
		}

		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				if (is_array($element)) {
					$data['elements'][$index] = $this->updateProductVariablesRecursively($element, $products, $title);
				}
			}
		}

		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$data['content'][$index] = $this->updateProductVariablesRecursively($content_item, $products, $title);
				}
			}
		}

		return $data;
	}

	/**
	 * Adjust template structure for new product using TemplateManipulator and VariableReplacer
	 * 
	 * @param array $data Elementor data
	 * @param int $new_product_number New product number
	 * @return array Updated data
	 */
	private function adjustTemplateForNewProduct($data, $new_product_number) {
		if (!is_array($data)) {
			error_log('[AEBG] adjustTemplateForNewProduct: data is not an array, type: ' . gettype($data));
			return $data;
		}

		// Create deep copy
		$data = DataUtilities::deepCopyElementorData($data);
		if (!is_array($data)) {
			error_log('[AEBG] adjustTemplateForNewProduct: failed to create deep copy');
			return $data;
		}

		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$data[$index] = $this->adjustTemplateForNewProduct($item, $new_product_number);
			}
			return $data;
		}

		// Check if this element has a CSS ID that needs updating
		if (isset($data['settings']) && is_array($data['settings'])) {
			$css_id = $data['settings']['_element_id'] ?? $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '';
			
			// Check for product-X CSS ID pattern
			if (preg_match('/product-(\d+)/', $css_id, $matches)) {
				$current_product_number = (int) $matches[1];
				
				// Only adjust if this product number is greater than or equal to the new product number
				// AND if this is not the new product itself
				if ($current_product_number >= $new_product_number && $current_product_number !== $new_product_number) {
					$new_css_id = 'product-' . ($current_product_number + 1);
					
					// Update CSS ID
					if (isset($data['settings']['_element_id'])) {
						$data['settings']['_element_id'] = $new_css_id;
					} elseif (isset($data['settings']['_css_id'])) {
						$data['settings']['_css_id'] = $new_css_id;
					} elseif (isset($data['settings']['css_id'])) {
						$data['settings']['css_id'] = $new_css_id;
					}
					
					error_log('[AEBG] Updated CSS ID from ' . $css_id . ' to ' . $new_css_id . ' for new product ' . $new_product_number);
				}
			}

			// Update variables in text content using VariableReplacer
			// EXCLUDE aebg_ai_prompt from variable replacement
			$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode'];
			foreach ($text_fields as $field) {
				if (isset($data['settings'][$field]) && is_string($data['settings'][$field])) {
					if (strpos($data['settings'][$field], '{product-') !== false) {
						$data['settings'][$field] = $this->variable_replacer->updateVariablesForNewProduct($data['settings'][$field], $new_product_number);
					}
				}
			}
			
			// Fix AI prompts that reference non-existent product numbers
			if (isset($data['settings']['aebg_ai_prompt']) && is_string($data['settings']['aebg_ai_prompt'])) {
				$data['settings']['aebg_ai_prompt'] = $this->fixAIPromptProductReferences($data['settings']['aebg_ai_prompt'], $new_product_number);
			}
		}

		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				if (is_array($element)) {
					$data['elements'][$index] = $this->adjustTemplateForNewProduct($element, $new_product_number);
				}
			}
		}

		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$data['content'][$index] = $this->adjustTemplateForNewProduct($content_item, $new_product_number);
				}
			}
		}

		return $data;
	}

	/**
	 * Fix AI prompt product references
	 * 
	 * @param string $prompt Prompt text
	 * @param int $new_product_number New product number
	 * @return string Fixed prompt
	 */
	private function fixAIPromptProductReferences($prompt, $new_product_number) {
		if (!is_string($prompt)) {
			return $prompt;
		}

		$pattern = '/\{product-(\d+)(-[^}]+)?\}/';
		
		return preg_replace_callback($pattern, function($matches) use ($new_product_number) {
			$product_num = (int) $matches[1];
			$suffix = $matches[2] ?? '';
			
			if ($product_num > $new_product_number) {
				$adjusted_product_num = min($product_num, $new_product_number);
				return '{product-' . $adjusted_product_num . $suffix . '}';
			}
			
			return $matches[0];
		}, $prompt);
	}

	/**
	 * Adjust template structure after product removal using TemplateManipulator and VariableReplacer
	 * 
	 * @param array $data Elementor data
	 * @param int $removed_product_number Removed product number
	 * @param array|null $stats Stats array (by reference)
	 * @return array Updated data
	 */
	private function adjustTemplateAfterRemoval($data, $removed_product_number, &$stats = null) {
		if (!is_array($data)) {
			return $data;
		}

		// Initialize stats if not provided
		if ($stats === null) {
			$stats = [
				'total_elements_processed' => 0,
				'total_children_processed' => 0,
				'containers_removed' => 0,
				'containers_failed' => 0
			];
		}

		// Handle numeric array structure (top-level containers)
		if (array_keys($data) === range(0, count($data) - 1)) {
			$filtered_data = [];
			
			foreach ($data as $index => $item) {
				$processed_item = $this->adjustTemplateAfterRemoval($item, $removed_product_number, $stats);
				if ($processed_item !== null) {
					$filtered_data[] = $processed_item;
				}
			}
			
			return $filtered_data;
		}

		// Increment element counter
		$stats['total_elements_processed']++;

		// Handle associative array structure (nested elements)
		$updated_data = $data;
		
		// Check if this element has a CSS ID that needs updating
		if (isset($data['settings']) && is_array($data['settings'])) {
			$settings = &$updated_data['settings'];
			$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
			
			// Check for product-X CSS ID pattern with exact matching
			if (preg_match('/^product-(\d+)$/', $css_id, $matches)) {
				$current_product_number = (int) $matches[1];
				
				// If this is the container for the removed product, return null to remove it
				if ($current_product_number === $removed_product_number) {
					$stats['containers_removed']++;
					return null; // This will remove the container
				}
				
				// If this product number is greater than the removed product number,
				// we need to decrement it to fill the gap
				if ($current_product_number > $removed_product_number) {
					$new_css_id = 'product-' . ($current_product_number - 1);
					
					// Update CSS ID
					if (isset($settings['_element_id'])) {
						$settings['_element_id'] = $new_css_id;
					} elseif (isset($settings['_css_id'])) {
						$settings['_css_id'] = $new_css_id;
					} elseif (isset($settings['css_id'])) {
						$settings['css_id'] = $new_css_id;
					}
				}
			}

			// Update variables in text content using VariableReplacer
			// EXCLUDE aebg_ai_prompt from variable replacement
			$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode'];
			foreach ($text_fields as $field) {
				if (isset($settings[$field]) && is_string($settings[$field])) {
					$settings[$field] = $this->variable_replacer->updateVariablesAfterRemoval($settings[$field], $removed_product_number);
				}
			}
		}

		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			$child_count = count($data['elements']);
			$stats['total_children_processed'] += $child_count;
			
			$updated_elements = [];
			foreach ($data['elements'] as $index => $element) {
				$processed_element = $this->adjustTemplateAfterRemoval($element, $removed_product_number, $stats);
				if ($processed_element !== null) {
					$updated_elements[] = $processed_element;
				}
			}
			$updated_data['elements'] = $updated_elements;
		}

		if (isset($data['content']) && is_array($data['content'])) {
			$stats['total_children_processed'] += count($data['content']);
			
			$updated_content = [];
			foreach ($data['content'] as $index => $content_item) {
				$processed_content = $this->adjustTemplateAfterRemoval($content_item, $removed_product_number, $stats);
				if ($processed_content !== null) {
					$updated_content[] = $processed_content;
				}
			}
			$updated_data['content'] = $updated_content;
		}
		
		// Process any other array structures that might contain nested elements
		foreach ($data as $key => $value) {
			if (is_array($value) && $key !== 'settings' && $key !== 'elements' && $key !== 'content') {
				if (array_keys($value) === range(0, count($value) - 1)) {
					$stats['total_children_processed'] += count($value);
					
					$updated_array = [];
					foreach ($value as $index => $item) {
						$processed_item = $this->adjustTemplateAfterRemoval($item, $removed_product_number, $stats);
						if ($processed_item !== null) {
							$updated_array[] = $processed_item;
						}
					}
					$updated_data[$key] = $updated_array;
				}
			}
		}

		return $updated_data;
	}

	private function findExistingProductContainers($data) {
		$containers = [];
		if (!is_array($data)) {
			return $containers;
		}
		
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $item) {
				$containers = array_merge($containers, $this->findExistingProductContainers($item));
			}
			return $containers;
		}
		
		if (isset($data['settings']) && is_array($data['settings'])) {
			$css_id = $data['settings']['_element_id'] ?? $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '';
			
			if (preg_match('/product-(\d+)/', $css_id, $matches)) {
				$product_number = (int) $matches[1];
				$containers[] = [
					'css_id' => $css_id,
					'product_number' => $product_number,
					'element' => $data
				];
			}
		}
		
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $element) {
				$containers = array_merge($containers, $this->findExistingProductContainers($element));
			}
		}
		
		return $containers;
	}

	/**
	 * Create new product container using modular architecture
	 * 
	 * @param array $elementor_data Elementor data
	 * @param int $new_product_number New product number
	 * @param string $post_title Post title
	 * @param array $products Products array
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array Updated Elementor data
	 */
	private function createNewProductContainer($elementor_data, $new_product_number, $post_title, $products, $api_key, $ai_model) {
		error_log('[AEBG] ===== START: createNewProductContainer =====');
		error_log('[AEBG] New product number: ' . $new_product_number);
		error_log('[AEBG] Products count: ' . count($products));
		
		// Create deep copy
		$updated_data = DataUtilities::deepCopyElementorData($elementor_data);
		if (!is_array($updated_data)) {
			error_log('[AEBG] Failed to create deep copy of elementor_data');
			return $elementor_data;
		}
		
		// Find existing product containers to use as a template
		$existing_containers = $this->findExistingProductContainers($updated_data);
		error_log('[AEBG] Found ' . count($existing_containers) . ' existing product containers to use as template');
		
		// Create a new container based on existing structure
		$new_container = null;
		
		if (!empty($existing_containers)) {
			// Use the first existing container as a template
			$template_container = $existing_containers[0]['element'];
			error_log('[AEBG] Using existing container as template: ' . ($template_container['settings']['_element_id'] ?? 'unknown'));
			
			// Create a deep copy of the template container
			$new_container = DataUtilities::deepCopyElementorData($template_container);
			
			// Update the container for the new product number
			$new_container = $this->updateContainerForNewProduct($new_container, $new_product_number);
			
			error_log('[AEBG] Created new container based on existing template structure');
		} else {
			// Fallback to basic container if no existing containers found
			error_log('[AEBG] No existing containers found, creating basic container');
			$new_container = $this->createBasicProductContainer($new_product_number);
		}
		
		// If we have AI capabilities, enhance the container with AI content
		if (!empty($api_key) && $this->content_generator) {
			error_log('[AEBG] Enhancing container with AI content for product ' . $new_product_number);
			
			// Get the specific product data for this new product
			$current_product = null;
			$product_index = $new_product_number - 1;
			
			if (isset($products[$product_index])) {
				$current_product = $products[$product_index];
				error_log('[AEBG] Found product data for new product ' . $new_product_number . ': ' . ($current_product['name'] ?? 'unknown'));
			} else {
				// Try to find any product with a name
				foreach ($products as $index => $product) {
					if (isset($product['name']) && !empty($product['name'])) {
						$current_product = $product;
						error_log('[AEBG] Found product by fallback search at index ' . $index . ': ' . $product['name']);
						break;
					}
				}
				
				// If still no product found, create a fallback product structure
				if (!$current_product) {
					error_log('[AEBG] Creating fallback product structure for product ' . $new_product_number);
					$current_product = [
						'name' => 'Product ' . $new_product_number,
						'id' => 'product-' . $new_product_number,
						'description' => 'Product ' . $new_product_number . ' description',
						'price' => 'N/A'
					];
				}
			}
			
			// Create context with the current product
			$context = [
				'product_number' => $new_product_number,
				'product_count' => count($products),
				'current_product' => $current_product
			];
			
			// Enhance the container with AI content
			$new_container = $this->processAIContentInContainer($new_container, $post_title, $products, $context, $api_key, $ai_model);
			error_log('[AEBG] Container enhanced with AI content');
		} else {
			error_log('[AEBG] Skipping AI content generation - no API key or ContentGenerator available');
		}
		
		// Add the new container to the existing data structure
		// Find the best position to insert the new container (after the last product container)
		$insert_position = $this->findInsertPositionForNewContainer($updated_data, $new_product_number);
		
		error_log('[AEBG] Data structure before insertion:');
		error_log('[AEBG] - Total elements: ' . count($updated_data));
		error_log('[AEBG] - Insert position: ' . $insert_position);
		
		// Insert the new container at the calculated position
		array_splice($updated_data, $insert_position, 0, [$new_container]);
		error_log('[AEBG] Inserted new container at position ' . $insert_position);
		
		error_log('[AEBG] Successfully created new product container for product ' . $new_product_number);
		return $updated_data;
	}

	/**
	 * Update container for new product number
	 * 
	 * @param array $container Container data
	 * @param int $new_product_number New product number
	 * @return array Updated container
	 */
	private function updateContainerForNewProduct($container, $new_product_number) {
		if (!is_array($container)) {
			return $container;
		}

		// Update CSS ID
		if (isset($container['settings']) && is_array($container['settings'])) {
			if (isset($container['settings']['_element_id'])) {
				$container['settings']['_element_id'] = 'product-' . $new_product_number;
			} elseif (isset($container['settings']['_css_id'])) {
				$container['settings']['_css_id'] = 'product-' . $new_product_number;
			} elseif (isset($container['settings']['css_id'])) {
				$container['settings']['css_id'] = 'product-' . $new_product_number;
			}
		}

		// Recursively update product variables in the container
		$container = $this->updateProductVariablesInContainerForNewProduct($container, $new_product_number);

		return $container;
	}

	/**
	 * Update product variables in container for new product
	 * 
	 * @param array $container Container data
	 * @param int $new_product_number New product number
	 * @return array Updated container
	 */
	private function updateProductVariablesInContainerForNewProduct($container, $new_product_number) {
		if (!is_array($container)) {
			return $container;
		}

		// Update settings
		if (isset($container['settings']) && is_array($container['settings'])) {
			$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode'];
			foreach ($text_fields as $field) {
				if (isset($container['settings'][$field]) && is_string($container['settings'][$field])) {
					// Replace all product variables with the new product number
					$container['settings'][$field] = preg_replace('/\{product-(\d+)(-[^}]+)?\}/', '{product-' . $new_product_number . '$2}', $container['settings'][$field]);
				}
			}
		}

		// Recursively process children
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as $index => $element) {
				if (is_array($element)) {
					$container['elements'][$index] = $this->updateProductVariablesInContainerForNewProduct($element, $new_product_number);
				}
			}
		}

		if (isset($container['content']) && is_array($container['content'])) {
			foreach ($container['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$container['content'][$index] = $this->updateProductVariablesInContainerForNewProduct($content_item, $new_product_number);
				}
			}
		}

		return $container;
	}

	/**
	 * Create basic product container structure
	 * 
	 * @param int $product_number Product number
	 * @return array Container data
	 */
	private function createBasicProductContainer($product_number) {
		return [
			'id' => uniqid('element_'),
			'elType' => 'container',
			'settings' => [
				'_element_id' => 'product-' . $product_number,
				'content_width' => 'full',
			],
			'elements' => [
				[
					'id' => uniqid('element_'),
					'elType' => 'widget',
					'widgetType' => 'text-editor',
					'settings' => [
						'_element_id' => 'product-' . $product_number . '-content',
						'editor' => '<p>Product ' . $product_number . ' content will be generated here.</p>',
					],
					'elements' => []
				]
			],
			'isInner' => false
		];
	}

	/**
	 * Find insert position for new container
	 * 
	 * @param array $data Elementor data
	 * @param int $new_product_number New product number
	 * @return int Insert position
	 */
	private function findInsertPositionForNewContainer($data, $new_product_number) {
		error_log('[AEBG] Finding insert position for new product container ' . $new_product_number);
		
		$last_product_container_position = -1;
		$last_product_number = 0;
		
		// Search for product containers at the top level
		foreach ($data as $index => $element) {
			if (isset($element['settings']['_element_id']) && 
				preg_match('/^product-(\d+)$/', $element['settings']['_element_id'], $matches)) {
				$product_number = (int) $matches[1];
				
				if ($product_number < $new_product_number && $product_number > $last_product_number) {
					$last_product_number = $product_number;
					$last_product_container_position = $index;
				}
			}
		}
		
		// If we found a product container, insert after it
		if ($last_product_container_position >= 0) {
			$insert_position = $last_product_container_position + 1;
			error_log('[AEBG] Inserting new container after product-' . $last_product_number . ' at position ' . $insert_position);
			return $insert_position;
		}
		
		// If no product containers found, insert at the end
		error_log('[AEBG] No product containers found, inserting at end at position ' . count($data));
		return count($data);
	}

	private function validateElementorData($data) {
		if (!is_array($data)) {
			return new \WP_Error('invalid_data', 'Data is not an array');
		}
		return true;
	}

	/**
	 * Update context with actual selected product information
	 * This enriches the context with product data extracted from products array
	 * 
	 * @param array $context Original context
	 * @param array $products Products array
	 * @return array Updated context
	 */
	private function updateContextWithProducts($context, $products) {
		if (empty($products) || !is_array($products)) {
			return $context;
		}
		
		// Extract actual product information to update context
		$actual_categories = [];
		$actual_brands = [];
		$actual_features = [];
		$actual_price_range = ['min' => PHP_INT_MAX, 'max' => 0];
		$actual_merchants = [];
		$actual_ratings = [];
		$actual_reviews = [];
		
		foreach ($products as $product) {
			if (empty($product) || !is_array($product)) {
				continue;
			}
			
			// Extract category
			if (!empty($product['category'])) {
				$actual_categories[] = $product['category'];
			}
			
			// Extract brand
			if (!empty($product['brand'])) {
				$actual_brands[] = $product['brand'];
			}
			
			// Extract merchant
			if (!empty($product['merchant'])) {
				$actual_merchants[] = $product['merchant'];
			}
			
			// Extract rating
			if (!empty($product['rating']) && is_numeric($product['rating'])) {
				$actual_ratings[] = (float)$product['rating'];
			}
			
			// Extract reviews count
			if (!empty($product['reviews_count']) && is_numeric($product['reviews_count'])) {
				$actual_reviews[] = (int)$product['reviews_count'];
			}
			
			// Extract features from description or other fields
			if (!empty($product['description'])) {
				// Simple feature extraction - could be enhanced
				$description = strtolower($product['description']);
				$features = [];
				if (strpos($description, 'wireless') !== false) $features[] = 'wireless';
				if (strpos($description, 'bluetooth') !== false) $features[] = 'bluetooth';
				if (strpos($description, 'waterproof') !== false) $features[] = 'waterproof';
				if (strpos($description, 'rechargeable') !== false) $features[] = 'rechargeable';
				if (strpos($description, 'portable') !== false) $features[] = 'portable';
				if (strpos($description, 'ceramic') !== false) $features[] = 'ceramic';
				if (strpos($description, 'christmas') !== false) $features[] = 'christmas';
				$actual_features = array_merge($actual_features, $features);
			}
			
			// Update price range
			if (!empty($product['price']) && is_numeric($product['price'])) {
				$price = (float)$product['price'];
				$actual_price_range['min'] = min($actual_price_range['min'], $price);
				$actual_price_range['max'] = max($actual_price_range['max'], $price);
			}
		}
		
		// Update context with actual product information
		$updated_context = $context;
		
		// Update category if we found actual categories
		if (!empty($actual_categories)) {
			$updated_context['actual_category'] = implode(', ', array_unique($actual_categories));
			$updated_context['category'] = $actual_categories[0]; // Use first category as primary
		}
		
		// Update brands if we found actual brands
		if (!empty($actual_brands)) {
			$updated_context['actual_brands'] = array_unique($actual_brands);
			$updated_context['preferred_brands'] = array_unique($actual_brands);
		}
		
		// Update features if we found actual features
		if (!empty($actual_features)) {
			$updated_context['actual_features'] = array_unique($actual_features);
			$updated_context['product_features'] = array_unique($actual_features);
		}
		
		// Update price range if we found actual prices
		if ($actual_price_range['min'] !== PHP_INT_MAX && $actual_price_range['max'] > 0) {
			$updated_context['actual_price_range'] = $actual_price_range;
			$updated_context['price_range'] = $actual_price_range;
		}
		
		// Add product count
		$updated_context['actual_product_count'] = count($products);
		
		// Add additional product information
		if (!empty($actual_merchants)) {
			$updated_context['actual_merchants'] = array_unique($actual_merchants);
		}
		
		if (!empty($actual_ratings)) {
			$updated_context['actual_avg_rating'] = round(array_sum($actual_ratings) / count($actual_ratings), 2);
			$updated_context['actual_ratings'] = $actual_ratings;
		}
		
		if (!empty($actual_reviews)) {
			$updated_context['actual_total_reviews'] = array_sum($actual_reviews);
			$updated_context['actual_reviews'] = $actual_reviews;
		}
		
		error_log('[AEBG] Updated context with actual product information:');
		error_log('[AEBG]   Actual category: ' . ($updated_context['actual_category'] ?? 'not set'));
		error_log('[AEBG]   Actual brands: ' . json_encode($updated_context['actual_brands'] ?? []));
		error_log('[AEBG]   Actual features: ' . json_encode($updated_context['actual_features'] ?? []));
		error_log('[AEBG]   Actual price range: ' . json_encode($updated_context['actual_price_range'] ?? []));
		error_log('[AEBG]   Actual product count: ' . ($updated_context['actual_product_count'] ?? 0));
		error_log('[AEBG]   Actual merchants: ' . json_encode($updated_context['actual_merchants'] ?? []));
		error_log('[AEBG]   Actual avg rating: ' . ($updated_context['actual_avg_rating'] ?? 'not set'));
		error_log('[AEBG]   Actual total reviews: ' . ($updated_context['actual_total_reviews'] ?? 'not set'));
		
		return $updated_context;
	}

	/**
	 * Detect if an array is a repeater items array (heuristic: array of arrays with string keys)
	 * Used to identify repeater fields in Elementor widgets
	 * 
	 * @param array $arr Array to check
	 * @return bool True if array appears to be repeater items
	 */
	private function is_repeater_items($arr) {
		if (!is_array($arr) || empty($arr)) {
			return false;
		}
		foreach ($arr as $item) {
			if (!is_array($item) || array_values($item) === $item) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Verify that Elementor processing (CSS/JS generation) is fully complete
	 * This ensures shortcodes won't execute during Elementor rendering
	 * 
	 * @param int $post_id Post ID
	 * @return void
	 */
	private function verifyElementorProcessingComplete($post_id) {
		if (!class_exists('\Elementor\Plugin')) {
			return;
		}
		
		// Small delay to ensure any async Elementor operations complete
		// This prevents shortcodes from executing during Elementor rendering
		usleep(100000); // 0.1 second delay
		
		// Verify Elementor CSS file exists (indicates rendering is complete)
		if (class_exists('\Elementor\Core\Files\CSS\Post')) {
			$css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
			if ($css_file && method_exists($css_file, 'get_meta')) {
				$meta = $css_file->get_meta();
				if (empty($meta) || !isset($meta['time'])) {
					// CSS file not generated yet, wait a bit more
					error_log('[AEBG] Elementor CSS file not ready, waiting...');
					usleep(1000000); // 1 second additional delay
				}
			}
		}
		
		// Verify Elementor JS file exists
		if (class_exists('\Elementor\Core\Files\JS\Post')) {
			$js_file = \Elementor\Core\Files\JS\Post::create($post_id);
			if ($js_file && method_exists($js_file, 'get_meta')) {
				$meta = $js_file->get_meta();
				if (empty($meta) || !isset($meta['time'])) {
					// JS file not generated yet, wait a bit more
					error_log('[AEBG] Elementor JS file not ready, waiting...');
					usleep(1000000); // 1 second additional delay
				}
			}
		}
		
		error_log('[AEBG] Elementor processing verification complete for post ' . $post_id);
	}

	/**
	 * Resume generation from checkpoint
	 *
	 * @param array $checkpoint Checkpoint state.
	 * @param int   $item_id Item ID.
	 * @return int|\WP_Error Post ID or error.
	 */
	private function resumeFromCheckpoint( array $checkpoint, int $item_id ) {
		$step = $checkpoint['step'];
		$state = $checkpoint['data'];
		
		error_log( '[AEBG] ▶️ RESUMING GENERATION | item_id=' . $item_id . ' | from_step=' . $step );
		
		// Validate checkpoint data
		if ( ! $this->validateResumeData( $checkpoint ) ) {
			error_log( '[AEBG] ⚠️ Checkpoint data incomplete - starting fresh' );
			CheckpointManager::clearCheckpoint( $item_id );
			// Start fresh with available data
			return $this->run( $state['title'] ?? '', $state['template_id'] ?? 0, $state['settings'] ?? [], $item_id );
		}
		
		// Restore state
		$title = $state['title'];
		$template_id = $state['template_id'];
		$final_settings = $state['settings'];
		$api_key = defined( 'AEBG_AI_API_KEY' ) ? AEBG_AI_API_KEY : ( $final_settings['api_key'] ?? get_option( 'aebg_settings' )['api_key'] ?? '' );
		$ai_model = $state['ai_model'] ?? $final_settings['ai_model'] ?? 'gpt-3.5-turbo';
		
		// Initialize AI processor if needed
		if ( ! empty( $api_key ) && ! isset( $this->ai_processor ) ) {
			$this->ai_processor = new \AEBG\Core\AIPromptProcessor( $this->variables, $this->context_registry, $api_key, $ai_model );
		}
		
		// Resume from appropriate step
		switch ( $step ) {
			case CheckpointManager::STEP_1_ANALYZE_TITLE:
				return $this->continueFromStep1( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_2_FIND_PRODUCTS:
				return $this->continueFromStep2( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_3_SELECT_PRODUCTS:
				return $this->continueFromStep3( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_3_6_PRICE_COMPARISON:
				return $this->continueFromStep3_6( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_3_7_PROCESS_IMAGES:
				return $this->continueFromStep3_7( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_4_PROCESS_TEMPLATE:
				return $this->continueFromStep4( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_5_GENERATE_CONTENT:
				return $this->continueFromStep5( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_5_5_MERCHANT_COMPARISONS:
				return $this->continueFromStep5_5( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_6_CREATE_POST:
				// Step 6 checkpoint - resume to Step 7 (image enhancements)
				// Note: This is for legacy resume flow, step-by-step flow handles this differently
				error_log( '[AEBG] ⚠️ Resuming from Step 6 - should use step-by-step flow instead' );
				return $this->continueFromStep5_5( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
				
			case CheckpointManager::STEP_7_IMAGE_ENHANCEMENTS:
				// Step 7 checkpoint - resume to Step 8 (SEO enhancements)
				// Note: This is for legacy resume flow, step-by-step flow handles this differently
				error_log( '[AEBG] ⚠️ Resuming from Step 7 - should use step-by-step flow instead' );
				// For legacy flow, just mark as complete
				return new \WP_Error( 'legacy_resume_step_7', 'Step 7 resume not supported in legacy flow - use step-by-step mode' );
				
			case CheckpointManager::STEP_8_SEO_ENHANCEMENTS:
				// Step 8 checkpoint - final step
				// Note: This is for legacy resume flow, step-by-step flow handles this differently
				error_log( '[AEBG] ⚠️ Resuming from Step 8 - should use step-by-step flow instead' );
				// For legacy flow, just mark as complete
				return new \WP_Error( 'legacy_resume_step_8', 'Step 8 resume not supported in legacy flow - use step-by-step mode' );
				
			default:
				error_log( '[AEBG] ⚠️ Unknown checkpoint step: ' . $step . ' - starting fresh' );
				CheckpointManager::clearCheckpoint( $item_id );
				return $this->run( $title, $template_id, $final_settings, $item_id );
		}
	}

	/**
	 * Validate resume data
	 *
	 * @param array $checkpoint Checkpoint state.
	 * @return bool True if valid.
	 */
	private function validateResumeData( array $checkpoint ): bool {
		if ( ! isset( $checkpoint['data'] ) || ! is_array( $checkpoint['data'] ) ) {
			return false;
		}
		
		$state = $checkpoint['data'];
		
		// Check required fields
		if ( ! isset( $state['title'] ) || ! isset( $state['template_id'] ) || ! isset( $state['settings'] ) ) {
			return false;
		}
		
		return true;
	}

	/**
	 * Continue from Step 1 (after title analysis)
	 */
	private function continueFromStep1( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$run_start_time = $this->job_start_time ?? microtime( true );
		
		// Continue from Step 2
		error_log( '[AEBG] ▶️ Continuing from Step 2 - findProducts' );
		$this->observer->recordStep( 'find_products' );
		
		$products = $this->product_finder->findProducts( $context, $title, $api_key, $ai_model );
		if ( is_wp_error( $products ) || empty( $products ) ) {
			return is_wp_error( $products ) ? $products : new \WP_Error( 'aebg_no_products_found', 'No products found' );
		}
		
		// Save checkpoint after Step 2
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_2_FIND_PRODUCTS, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'products' => $products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Continue with Step 3
		return $this->continueFromStep2( $title, $template_id, array_merge( $state, [ 'products' => $products ] ), $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 2 (after finding products)
	 */
	private function continueFromStep2( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$products = $state['products'];
		$run_start_time = $this->job_start_time ?? microtime( true );
		
		// Continue from Step 3
		error_log( '[AEBG] ▶️ Continuing from Step 3 - selectProducts' );
		$this->observer->recordStep( 'select_products' );
		
		$selected_products = $this->product_finder->selectProducts( $products, $context, $api_key, $ai_model );
		if ( is_wp_error( $selected_products ) ) {
			$selected_products = $this->product_finder->fallbackProductSelection( $products, $context );
		}
		
		// Optimize product names once to save tokens during content generation
		$selected_products = $this->product_finder->optimizeProductNames( $selected_products, $api_key, $ai_model );
		
		if ( empty( $selected_products ) ) {
			return new \WP_Error( 'aebg_no_products_selected', 'No products could be selected' );
		}
		
		// Adjust context quantity
		$actual_product_count = count( $selected_products );
		$requested_quantity = $context['quantity'] ?? $actual_product_count;
		if ( $actual_product_count < $requested_quantity ) {
			$context['quantity'] = $actual_product_count;
		}
		$context['actual_product_count'] = $actual_product_count;
		
		// Save checkpoint after Step 3
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_SELECT_PRODUCTS, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'products' => $products,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Continue with merchant discovery, price comparison, images, then template
		$selected_products = $this->product_finder->discoverMerchantsForProducts( $selected_products, $context );
		$selected_products = $this->product_finder->processPriceComparisonForProducts( $selected_products, $context );
		
		// Save checkpoint after Step 3.6
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_6_PRICE_COMPARISON, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Process images
		try {
			$selected_products = ImageProcessor::processProductImages( $selected_products, 0 );
		} catch ( \Throwable $e ) {
			error_log( '[AEBG] ERROR in image processing: ' . $e->getMessage() );
		}
		
		// Save checkpoint after Step 3.7
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_7_PROCESS_IMAGES, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Continue with Step 4
		return $this->continueFromStep3_7( $title, $template_id, array_merge( $state, [ 'context' => $context, 'selected_products' => $selected_products ] ), $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 3 (after selecting products)
	 */
	private function continueFromStep3( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$selected_products = $state['selected_products'];
		
		// Continue with merchant discovery, price comparison, images
		$selected_products = $this->product_finder->discoverMerchantsForProducts( $selected_products, $context );
		$selected_products = $this->product_finder->processPriceComparisonForProducts( $selected_products, $context );
		
		// Save checkpoint after Step 3.6
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_6_PRICE_COMPARISON, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Process images
		try {
			$selected_products = ImageProcessor::processProductImages( $selected_products, 0 );
		} catch ( \Throwable $e ) {
			error_log( '[AEBG] ERROR in image processing: ' . $e->getMessage() );
		}
		
		// Continue with Step 3.7
		return $this->continueFromStep3_7( $title, $template_id, array_merge( $state, [ 'context' => $context, 'selected_products' => $selected_products ] ), $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 3.6 (after price comparison)
	 */
	private function continueFromStep3_6( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$selected_products = $state['selected_products'];
		
		// Process images
		try {
			$selected_products = ImageProcessor::processProductImages( $selected_products, 0 );
		} catch ( \Throwable $e ) {
			error_log( '[AEBG] ERROR in image processing: ' . $e->getMessage() );
		}
		
		// Continue with Step 3.7
		return $this->continueFromStep3_7( $title, $template_id, array_merge( $state, [ 'selected_products' => $selected_products ] ), $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 3.7 (after processing images)
	 */
	private function continueFromStep3_7( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$selected_products = $state['selected_products'];
		$run_start_time = $this->job_start_time ?? microtime( true );
		
		// Continue from Step 4
		error_log( '[AEBG] ▶️ Continuing from Step 4 - processElementorTemplate' );
		$this->observer->recordStep( 'process_template' );
		
		// Clear context registry
		if ( $this->context_registry && method_exists( $this->context_registry, 'clear' ) ) {
			$this->context_registry->clear();
		}
		
		// Create template processor
		$this->template_processor = new ElementorTemplateProcessor( $this->context_registry, $this->ai_processor, $this->variable_replacer, $this->content_generator );
		
		$processed_template = $this->template_processor->processTemplate( $template_id, $title, $selected_products, $context, $api_key, $ai_model );
		
		if ( is_wp_error( $processed_template ) ) {
			$processed_template = null;
		}
		
		// Save checkpoint after Step 4
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_PROCESS_TEMPLATE, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'processed_template' => $processed_template,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Continue with Step 5
		return $this->continueFromStep4( $title, $template_id, array_merge( $state, [ 'processed_template' => $processed_template ] ), $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 4 (after template processing)
	 */
	private function continueFromStep4( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$selected_products = $state['selected_products'];
		$processed_template = $state['processed_template'] ?? null;
		$run_start_time = $this->job_start_time ?? microtime( true );
		
		// Continue from Step 5
		$content = '';
		if ( ! $processed_template || is_wp_error( $processed_template ) ) {
			$elapsed = microtime( true ) - $this->job_start_time;
			$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
			if ( $elapsed <= $max_time ) {
				$content = $this->content_generator->generateContent( $title, $selected_products, $context, $api_key, $ai_model );
				if ( ! is_wp_error( $content ) ) {
					$content = $this->variable_replacer->replaceVariablesInPrompt( $content, $title, $selected_products, $context );
				} else {
					$content = '';
				}
			}
		}
		
		// Save checkpoint after Step 5 (if content was generated)
		if ( $item_id && ! empty( $content ) ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_5_GENERATE_CONTENT, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'processed_template' => $processed_template,
				'content' => $content,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Continue with Step 5.5
		return $this->continueFromStep5( $title, $template_id, array_merge( $state, [ 'content' => $content ] ), $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 5 (after content generation)
	 */
	private function continueFromStep5( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$selected_products = $state['selected_products'];
		$processed_template = $state['processed_template'] ?? null;
		$content = $state['content'] ?? '';
		
		// Continue from Step 5.5
		if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
			$this->processMerchantComparisons( null, $selected_products, $this->author_id );
		}
		
		// Save checkpoint after Step 5.5
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_5_5_MERCHANT_COMPARISONS, [
				'title' => $title,
				'template_id' => $template_id,
				'context' => $context,
				'selected_products' => $selected_products,
				'processed_template' => $processed_template,
				'content' => $content,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			] );
		}
		
		// Continue with Step 6
		return $this->continueFromStep5_5( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id );
	}

	/**
	 * Continue from Step 5.5 (after merchant comparisons)
	 */
	private function continueFromStep5_5( $title, $template_id, $state, $final_settings, $api_key, $ai_model, $item_id ) {
		$context = $state['context'];
		$selected_products = $state['selected_products'];
		$processed_template = $state['processed_template'] ?? null;
		$content = $state['content'] ?? '';
		$run_start_time = $this->job_start_time ?? microtime( true );
		
		// Continue from Step 6 - create post
		error_log( '[AEBG] ▶️ Continuing from Step 6 - createPost' );
		$this->observer->recordStep( 'create_post' );
		
		// Check timeout
		$elapsed = microtime( true ) - $this->job_start_time;
		$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
		if ( $elapsed > $max_time ) {
			return new \WP_Error( 'aebg_timeout', 'Generation timeout: approaching action scheduler limit before post creation' );
		}
		
		$new_post_id = $this->post_creator->createPost( $title, $content, $processed_template, $final_settings, $selected_products );
		
		if ( is_wp_error( $new_post_id ) || empty( $new_post_id ) || ! is_numeric( $new_post_id ) || $new_post_id <= 0 ) {
			return is_wp_error( $new_post_id ) ? $new_post_id : new \WP_Error( 'aebg_invalid_post_id', 'Post creation returned invalid post ID' );
		}
		
		// Re-process images with post association
		if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
			$selected_products = ImageProcessor::processProductImages( $selected_products, $new_post_id );
		}
		
		// Re-associate AI-generated images
		$this->associateAIGeneratedImagesWithPost( $new_post_id, $final_settings );
		
		// Update comparison records
		if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
			$this->updateComparisonRecordsWithPostId( $new_post_id, $selected_products, $this->author_id );
		}
		
		// Verify post exists
		$post_exists = get_post( $new_post_id );
		if ( ! $post_exists ) {
			return new \WP_Error( 'aebg_post_not_found', 'Post was created but not found in database' );
		}
		
		// Verify Elementor processing
		$this->verifyElementorProcessingComplete( $new_post_id );
		
		// Final cleanup
		$this->finalCleanupAfterPostCreation( $new_post_id );
		
		// Mark complete
		$this->observer->markComplete();
		
		// Clear checkpoint
		if ( $item_id ) {
			CheckpointManager::clearCheckpoint( $item_id );
		}
		
		return $new_post_id;
	}

	/**
	 * Execute Step 1: Analyze Title
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state (null for Step 1)
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with context and elapsed time, or WP_Error
	 */
	public function execute_step_1_analyze_title( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		try {
			// Step 1 doesn't use checkpoint_state (it's the first step)
			// Extract title and template_id from item record or settings
			global $wpdb;
			
			$title = '';
			$template_id = 0;
			
			if ( $item_id ) {
				// Get title from item record
				$item = $wpdb->get_row( $wpdb->prepare( "SELECT source_title, batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", $item_id ) );
				if ( $item ) {
					$title = $item->source_title;
					
					// Get template_id from batch (stored as column, not in settings)
					$batch = $wpdb->get_row( $wpdb->prepare( "SELECT template_id, settings FROM {$wpdb->prefix}aebg_batches WHERE id = %d", $item->batch_id ) );
					if ( $batch ) {
						// template_id is stored as a column in the batch table
						$template_id = (int) ( $batch->template_id ?? 0 );
						
						// Also merge batch settings if needed
						if ( ! empty( $batch->settings ) ) {
							$batch_settings = json_decode( $batch->settings, true );
							if ( is_array( $batch_settings ) ) {
								$settings = array_merge( $settings, $batch_settings );
							}
						}
					}
				}
			}
			
			// Fallback to settings if not found in database
			if ( empty( $title ) ) {
				$title = $settings['title'] ?? '';
			}
			if ( empty( $template_id ) ) {
				$template_id = (int) ( $settings['template_id'] ?? 0 );
			}
			
			if ( empty( $title ) ) {
				return new \WP_Error( 'aebg_missing_title', 'Title is required for Step 1' );
			}
			
			if ( empty( $template_id ) ) {
				return new \WP_Error( 'aebg_missing_template_id', 'Template ID is required for Step 1' );
			}
			
			error_log( '[AEBG] 📍 STEP 1: Starting analyzeTitle | item_id=' . $item_id );
			$this->observer->recordStep( 'analyze_title' );
			
			// CRITICAL: Set step start time for timeout tracking (same as Step 4)
			$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
			
			// Build checkpoint state for rescheduling
			$current_checkpoint_state = [
				'title' => $title,
				'template_id' => (int) $template_id,
				'settings' => $settings,
				'ai_model' => $ai_model,
				'author_id' => $this->author_id,
			];
			
			// CRITICAL: Check timeout before API call
			$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_1', $current_checkpoint_state, CheckpointManager::STEP_1_ANALYZE_TITLE, $step_start_time );
			if ( $reschedule_error ) {
				return $reschedule_error;
			}
			
			// Use TitleAnalyzer module
			$title_analyzer = new TitleAnalyzer( $this->variables, $this->settings );
			$context = $title_analyzer->analyzeTitle( $title, $api_key, $ai_model );
			
			if ( is_wp_error( $context ) ) {
				$this->observer->recordError( 'Title analysis failed', [ 'error' => $context->get_error_message() ] );
				return $context;
			}
			
			$this->observer->recordMetric( 'context_extracted', true );
			
			// Save checkpoint after Step 1
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_1_ANALYZE_TITLE, [
					'title' => $title,
					'template_id' => (int) $template_id,
					'context' => $context,
					'settings' => $settings,
					'ai_model' => $ai_model,
					'author_id' => $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 1 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'context' => $context,
				'title' => $title,
				'template_id' => (int) $template_id,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_1_exception', 'Exception in Step 1: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 2: Find Products
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with products and elapsed time, or WP_Error
	 */
	public function execute_step_2_find_products( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['context'] ) || ! isset( $checkpoint_state['title'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 2' );
			}
			
		$title = $checkpoint_state['title'];
		$context = $checkpoint_state['context'];
		
		// Check if competitor products are provided (from clone content)
		$competitor_products = $checkpoint_state['competitor_products'] ?? null;
		
		if ( ! empty( $competitor_products ) && is_array( $competitor_products ) ) {
			// Clone content: Use provided competitor products - skip ProductFinder
			error_log( '[AEBG] 📍 STEP 2: Using competitor products (skipping ProductFinder) | item_id=' . $item_id . ' | products=' . count( $competitor_products ) );
			$this->observer->recordStep( 'use_competitor_products' );
			$this->observer->recordMetric( 'products_found', count( $competitor_products ) );
			$this->observer->recordMetric( 'products_selected', count( $competitor_products ) );
			
			$products = $competitor_products; // Use competitor products directly
			
			// Update context quantity to match provided products
			$context['quantity'] = count( $products );
			$context['actual_product_count'] = count( $products );
			
			// Save checkpoint after Step 2 (with competitor products)
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_2_FIND_PRODUCTS, [
					'title' => $title,
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'products' => $products,
					'selected_products' => $products, // Already selected (no need for Step 3 selection)
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 2 COMPLETED (competitor products) | item_id=' . $item_id . ' | products=' . count( $products ) . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'products' => $products,
				'selected_products' => $products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		}
		
		// Regular bulk generation: Use ProductFinder
		error_log( '[AEBG] 📍 STEP 2: Starting findProducts (bulk generation) | item_id=' . $item_id );
		$this->observer->recordStep( 'find_products' );
		
		// Build checkpoint state for rescheduling
		$current_checkpoint_state = [
			'title' => $title,
			'template_id' => $checkpoint_state['template_id'] ?? 0,
			'context' => $context,
			'settings' => $checkpoint_state['settings'] ?? $settings,
			'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
			'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
		];
		
		// CRITICAL: Check timeout before API call
		$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_2', $current_checkpoint_state, CheckpointManager::STEP_2_FIND_PRODUCTS, $step_start_time );
		if ( $reschedule_error ) {
			return $reschedule_error;
		}
		
		// Call heartbeat before long operation
		if ( isset( $GLOBALS['aebg_heartbeat_callback'] ) && is_callable( $GLOBALS['aebg_heartbeat_callback'] ) ) {
			call_user_func( $GLOBALS['aebg_heartbeat_callback'] );
		}
		
		$products = $this->product_finder->findProducts( $context, $title, $api_key, $ai_model );
		
		// Call heartbeat after long operation
		if ( isset( $GLOBALS['aebg_heartbeat_callback'] ) && is_callable( $GLOBALS['aebg_heartbeat_callback'] ) ) {
			call_user_func( $GLOBALS['aebg_heartbeat_callback'] );
		}
		
		if ( is_wp_error( $products ) ) {
			$this->observer->recordError( 'Product search failed', [ 'error' => $products->get_error_message() ] );
			return new \WP_Error( 'aebg_product_search_failed', 'Failed to search for products: ' . $products->get_error_message() );
		}
		
		$this->observer->recordMetric( 'products_found', count( $products ) );
		
		// Check if any products were found
		if ( empty( $products ) ) {
			$error_msg = 'No products found for the search term "' . $title . '". Please try a different search term or check your Datafeedr configuration.';
			return new \WP_Error( 'aebg_no_products_found', $error_msg );
		}
		
		// Save checkpoint after Step 2
		if ( $item_id ) {
			CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_2_FIND_PRODUCTS, [
				'title' => $title,
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $context,
				'products' => $products,
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
			] );
		}
		
		$elapsed = microtime( true ) - $start_time;
		error_log( '[AEBG] ✅ STEP 2 COMPLETED | item_id=' . $item_id . ' | products=' . count( $products ) . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
		
		return [
			'products' => $products,
			'context' => $context,
			'elapsed' => $elapsed,
		];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_2_exception', 'Exception in Step 2: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 3: Select Products
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with selected_products and elapsed time, or WP_Error
	 */
	public function execute_step_3_select_products( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['products'] ) || ! isset( $checkpoint_state['context'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 3' );
			}
			
			$products = $checkpoint_state['products'];
			$context = $checkpoint_state['context'];
			
			// Check if products are already selected (from competitor products in Step 2)
			if ( isset( $checkpoint_state['selected_products'] ) && ! empty( $checkpoint_state['selected_products'] ) ) {
				// Clone content: Products already selected in Step 2, skip selection
				error_log( '[AEBG] 📍 STEP 3: Products already selected (competitor products) | item_id=' . $item_id . ' | products=' . count( $checkpoint_state['selected_products'] ) );
				$this->observer->recordStep( 'skip_select_products' );
				
				$selected_products = $checkpoint_state['selected_products'];
				
				// Save checkpoint after Step 3 (already has selected_products)
				if ( $item_id ) {
					CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_SELECT_PRODUCTS, [
						'title' => $checkpoint_state['title'] ?? '',
						'template_id' => $checkpoint_state['template_id'] ?? 0,
						'context' => $context,
						'products' => $products,
						'selected_products' => $selected_products,
						'settings' => $checkpoint_state['settings'] ?? $settings,
						'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
						'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
					] );
				}
				
				$elapsed = microtime( true ) - $start_time;
				error_log( '[AEBG] ✅ STEP 3 COMPLETED (skipped - already selected) | item_id=' . $item_id . ' | products=' . count( $selected_products ) . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
				
				return [
					'selected_products' => $selected_products,
					'context' => $context,
					'elapsed' => $elapsed,
				];
			}
			
			// Regular bulk generation: Select products using ProductFinder
			error_log( '[AEBG] 📍 STEP 3: Starting selectProducts (bulk generation) | item_id=' . $item_id );
			$this->observer->recordStep( 'select_products' );
			
			$selected_products = $this->product_finder->selectProducts( $products, $context, $api_key, $ai_model );
			
			if ( is_wp_error( $selected_products ) ) {
				error_log( '[AEBG] Product selection failed, using fallback selection: ' . $selected_products->get_error_message() );
				$this->observer->recordError( 'Product selection failed, using fallback', [ 'error' => $selected_products->get_error_message() ] );
				$selected_products = $this->product_finder->fallbackProductSelection( $products, $context );
			}
			
			// Optimize product names once to save tokens during content generation
			error_log( '[AEBG] Optimizing product names to save tokens...' );
			$selected_products = $this->product_finder->optimizeProductNames( $selected_products, $api_key, $ai_model );
			
			$this->observer->recordMetric( 'products_selected', count( $selected_products ) );
			
			// Check if any products were selected
			if ( empty( $selected_products ) ) {
				$error_msg = 'No products could be selected. This may be due to insufficient product data or AI selection criteria.';
				return new \WP_Error( 'aebg_no_products_selected', $error_msg );
			}
			
			// Adjust context quantity to match actual product count
			$actual_product_count = count( $selected_products );
			$requested_quantity = $context['quantity'] ?? $actual_product_count;
			
			if ( $actual_product_count < $requested_quantity ) {
				error_log( '[AEBG] ⚠️ WARNING: Only found ' . $actual_product_count . ' products, but requested ' . $requested_quantity . '. Adjusting context quantity.' );
				$context['quantity'] = $actual_product_count;
			}
			$context['actual_product_count'] = $actual_product_count;
			
			// Save checkpoint after Step 3
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_SELECT_PRODUCTS, [
					'title' => $checkpoint_state['title'] ?? '',
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'products' => $products,
					'selected_products' => $selected_products,
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 3 COMPLETED | item_id=' . $item_id . ' | selected=' . count( $selected_products ) . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_3_exception', 'Exception in Step 3: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 3.5: Discover Merchants
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with selected_products and elapsed time, or WP_Error
	 */
	public function execute_step_3_5_discover_merchants( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) || ! isset( $checkpoint_state['context'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 3.5' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$context = $checkpoint_state['context'];
			
			error_log( '[AEBG] 📍 STEP 3.5: Starting discoverMerchantsForProducts | item_id=' . $item_id );
			
			// Build checkpoint state for rescheduling
			$current_checkpoint_state = [
				'title' => $checkpoint_state['title'] ?? '',
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
			];
			
			// CRITICAL: Check timeout before API calls (discoverMerchantsForProducts makes API calls)
			$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_3_5', $current_checkpoint_state, CheckpointManager::STEP_3_5_DISCOVER_MERCHANTS, $step_start_time );
			if ( $reschedule_error ) {
				return $reschedule_error;
			}
			
			$selected_products = $this->product_finder->discoverMerchantsForProducts( $selected_products, $context );
			
			// Save checkpoint after Step 3.5 (using STEP_3_SELECT_PRODUCTS as base, since 3.5 doesn't have its own checkpoint constant)
			// Actually, we should save it with the same step as Step 3 since 3.5 is a continuation
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_5_DISCOVER_MERCHANTS, [
					'title' => $checkpoint_state['title'] ?? '',
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'products' => $checkpoint_state['products'] ?? [],
					'selected_products' => $selected_products,
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 3.5 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_3_5_exception', 'Exception in Step 3.5: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 3.6: Price Comparison
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with selected_products and elapsed time, or WP_Error
	 */
	public function execute_step_3_6_price_comparison( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) || ! isset( $checkpoint_state['context'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 3.6' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$context = $checkpoint_state['context'];
			
			error_log( '[AEBG] 📍 STEP 3.6: Starting processPriceComparisonForProducts | item_id=' . $item_id );
			
			// Build checkpoint state for rescheduling
			$current_checkpoint_state = [
				'title' => $checkpoint_state['title'] ?? '',
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $context,
				'selected_products' => $selected_products,
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
			];
			
			// CRITICAL: Check timeout before API calls (processPriceComparisonForProducts makes API calls)
			$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_3_6', $current_checkpoint_state, CheckpointManager::STEP_3_6_PRICE_COMPARISON, $step_start_time );
			if ( $reschedule_error ) {
				return $reschedule_error;
			}
			
			$selected_products = $this->product_finder->processPriceComparisonForProducts( $selected_products, $context );
			
			// Save checkpoint after Step 3.6
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_6_PRICE_COMPARISON, [
					'title' => $checkpoint_state['title'] ?? '',
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'selected_products' => $selected_products,
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 3.6 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_3_6_exception', 'Exception in Step 3.6: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 3.7: Process Images
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with selected_products and elapsed time, or WP_Error
	 */
	public function execute_step_3_7_process_images( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 3.7' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			
			error_log( '[AEBG] 📍 STEP 3.7: Starting processProductImages | item_id=' . $item_id );
			
			// Build checkpoint state for rescheduling
			$current_checkpoint_state = [
				'title' => $checkpoint_state['title'] ?? '',
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $checkpoint_state['context'] ?? [],
				'selected_products' => $selected_products,
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
			];
			
			// CRITICAL: Check timeout before operation (processProductImages may make API calls)
			$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_3_7', $current_checkpoint_state, CheckpointManager::STEP_3_7_PROCESS_IMAGES, $step_start_time );
			if ( $reschedule_error ) {
				return $reschedule_error;
			}
			
			// Process images (will be re-processed after post creation for proper association)
			if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
				try {
					$selected_products = ImageProcessor::processProductImages( $selected_products, 0 );
				} catch ( \Throwable $e ) {
					error_log( '[AEBG] ERROR in image processing: ' . $e->getMessage() );
					// Continue with unprocessed products - don't halt entire generation
				}
			}
			
			// Save checkpoint after Step 3.7
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_3_7_PROCESS_IMAGES, [
					'title' => $checkpoint_state['title'] ?? '',
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $checkpoint_state['context'] ?? [],
					'selected_products' => $selected_products,
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 3.7 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'selected_products' => $selected_products,
				'context' => $checkpoint_state['context'] ?? [],
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_3_7_exception', 'Exception in Step 3.7: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 4: Collect AI Prompts
	 * 
	 * Phase 1: Scans Elementor template and collects all AI prompts with dependencies.
	 * This is a fast operation that doesn't make API calls.
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with elementor_data and elapsed time, or WP_Error
	 */
	public function execute_step_4_collect_prompts( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking
		$step_start_time = microtime( true );
		$GLOBALS['aebg_step_start_time'] = $step_start_time;
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) || ! isset( $checkpoint_state['context'] ) || ! isset( $checkpoint_state['template_id'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 4' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$context = $checkpoint_state['context'];
			$template_id = (int) $checkpoint_state['template_id'];
			$title = $checkpoint_state['title'] ?? '';
			
			error_log( '[AEBG] 📍 STEP 4: Starting collectAIPrompts | item_id=' . $item_id . ' | template_id=' . $template_id );
			
			// Clear context registry BEFORE creating template processor
			if ( $this->context_registry && method_exists( $this->context_registry, 'clear' ) ) {
				$this->context_registry->clear();
			}
			
			// Initialize AI processor if needed
			if ( ! isset( $this->ai_processor ) ) {
				$this->ai_processor = new \AEBG\Core\AIPromptProcessor( $this->variables, $this->context_registry, $api_key, $ai_model );
			}
			
			// Create template processor
			$this->template_processor = new ElementorTemplateProcessor( $this->context_registry, $this->ai_processor, $this->variable_replacer, $this->content_generator );
			
			// Get Elementor data
			$elementor_data = $this->template_processor->getElementorData( $template_id );
			
			if ( is_wp_error( $elementor_data ) ) {
				return $elementor_data;
			}
			
			// Remove unused product containers
			$actual_product_count = count( $selected_products );
			$elementor_data = $this->template_processor->removeUnusedProductContainers( $elementor_data, $actual_product_count );
			
			// Create deep copy for processing
			$processed_data = \AEBG\Core\DataUtilities::deepCopyArray( $elementor_data );
			
			// Phase 1: Collect AI prompts (no API calls)
			$this->template_processor->collectAIPrompts( $processed_data );
			
			// Export context registry state (prompts and dependencies)
			$context_registry_state = $this->context_registry->exportState();
			
			$field_count = count( $context_registry_state['contexts'] ?? [] );
			error_log( '[AEBG] ✅ STEP 4 COMPLETED | Collected ' . $field_count . ' AI fields | item_id=' . $item_id );
			
			// Save checkpoint after Step 4
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_COLLECT_PROMPTS, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'selected_products' => $selected_products,
					'elementor_data' => $processed_data, // Template with field_ids assigned
					'context_registry_state' => $context_registry_state, // Collected prompts and dependencies
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			
			return [
				'elementor_data' => $processed_data,
				'context_registry_state' => $context_registry_state,
				'field_count' => $field_count,
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_4_exception', 'Exception in Step 4: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 4.1: Process AI Fields
	 * 
	 * Phase 2: Processes all AI fields via API calls in dependency order.
	 * This can take a long time for templates with many fields.
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with context_registry_state and elapsed time, or WP_Error
	 */
	public function execute_step_4_1_process_fields( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking
		$step_start_time = microtime( true );
		$GLOBALS['aebg_step_start_time'] = $step_start_time;
		$GLOBALS['aebg_job_start_time'] = $step_start_time;
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['elementor_data'] ) || ! isset( $checkpoint_state['context_registry_state'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 4.1' );
			}
			
			$elementor_data = $checkpoint_state['elementor_data'];
			$context_registry_state = $checkpoint_state['context_registry_state'];
			$selected_products = $checkpoint_state['selected_products'] ?? [];
			$context = $checkpoint_state['context'] ?? [];
			$title = $checkpoint_state['title'] ?? '';
			
			error_log( '[AEBG] 📍 STEP 4.1: Starting processAIFields | item_id=' . $item_id );
			
			// Clear and import context registry state
			if ( $this->context_registry && method_exists( $this->context_registry, 'clear' ) ) {
				$this->context_registry->clear();
			}
			$this->context_registry->importState( $context_registry_state );
			
			// Initialize AI processor if needed
			if ( ! isset( $this->ai_processor ) ) {
				$this->ai_processor = new \AEBG\Core\AIPromptProcessor( $this->variables, $this->context_registry, $api_key, $ai_model );
			}
			
			// Create template processor
			$this->template_processor = new ElementorTemplateProcessor( $this->context_registry, $this->ai_processor, $this->variable_replacer, $this->content_generator );
			
			// Set global AI model for timeout threshold calculations in ElementorTemplateProcessor
			$GLOBALS['aebg_ai_model'] = $ai_model;
			
			// Check for resume state (incremental checkpointing)
			$field_progress = $checkpoint_state['field_processing_progress'] ?? [];
			$processed_fields = $field_progress['processed_fields'] ?? [];
			
			if ( ! empty( $processed_fields ) ) {
				error_log( sprintf(
					'[AEBG] 🔄 RESUMING Step 4.1 from checkpoint | %d fields already processed: %s',
					count( $processed_fields ),
					implode( ', ', array_slice( $processed_fields, 0, 5 ) ) . ( count( $processed_fields ) > 5 ? '...' : '' )
				) );
			} else {
				error_log( '[AEBG] 🔍 Step 4.1: No resume state found - starting from beginning' );
			}
			
			// Get processing order (dependency order)
			$processing_order = $this->context_registry->getProcessingOrder();
			$total_fields = count( $processing_order );
			
			$remaining_fields = array_diff( $processing_order, $processed_fields );
			$remaining_count = count( $remaining_fields );
			
			error_log( sprintf(
				'[AEBG] Processing %d AI fields in dependency order | %d already processed | %d remaining',
				$total_fields,
				count( $processed_fields ),
				$remaining_count
			) );
			
			// Build checkpoint state for rescheduling (updated after each field)
			$current_checkpoint_state = [
				'title' => $title,
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $context,
				'selected_products' => $selected_products,
				'elementor_data' => $elementor_data,
				'context_registry_state' => $context_registry_state, // Start with imported state
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				'field_processing_progress' => $field_progress,
			];
			
			$field_index = 0;
			$skipped_count = 0;
			$processed_count = count( $processed_fields );
			foreach ( $processing_order as $field_id ) {
				// Skip if already processed
				if ( in_array( $field_id, $processed_fields, true ) ) {
					$skipped_count++;
					if ( $skipped_count <= 3 || $skipped_count === $processed_count ) {
						error_log( sprintf(
							'[AEBG] ⏭️ Skipping already processed field: %s (%d/%d skipped)',
							$field_id,
							$skipped_count,
							$processed_count
						) );
					}
					continue;
				}
				
				$field_index++;
				$actual_field_number = $processed_count + $field_index; // Actual position including processed fields
				
				// CRITICAL: Update checkpoint state with latest registry state before timeout check
				// This ensures if we reschedule, we save the current state with all processed results
				$current_checkpoint_state['context_registry_state'] = $this->context_registry->exportState();
				$current_checkpoint_state['field_processing_progress'] = $field_progress;
				
				// CRITICAL: Check timeout before each API call
				$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_4_1', $current_checkpoint_state, CheckpointManager::STEP_4_1_PROCESS_FIELDS, $step_start_time, $ai_model );
				if ( $reschedule_error ) {
					// Log checkpoint state before rescheduling
					$checkpoint_field_count = count( $field_progress['processed_fields'] ?? [] );
					error_log( sprintf(
						'[AEBG] 🔄 Rescheduling Step 4.1 | Checkpoint will contain %d processed fields | Current field: %d/%d',
						$checkpoint_field_count,
						$actual_field_number,
						$total_fields
					) );
					return $reschedule_error;
				}
				
				// Log milestones (use actual field number including processed fields)
				$is_milestone = ( $actual_field_number === 1 || $actual_field_number === $total_fields || $actual_field_number % 10 === 0 );
				if ( $is_milestone ) {
					error_log( sprintf(
						'[AEBG] Processing AI field %d/%d: %s (resuming from field %d)',
						$actual_field_number,
						$total_fields,
						$field_id,
						$processed_count + 1
					) );
				}
				
				// Add small delay before each API call (except first) to prevent connection pool exhaustion
				if ( $field_index > 1 ) {
					usleep( 100000 ); // 0.1 second delay
					// Force garbage collection every 10 calls
					if ( $field_index % 10 === 0 ) {
						gc_collect_cycles();
					}
				}
				
				// Process the field
				$this->template_processor->processAIField( $field_id, $title, $selected_products, $context, $api_key, $ai_model );
				
				// Mark field as processed
				$processed_fields[] = $field_id;
				$field_progress['processed_fields'] = $processed_fields;
				
				// Update checkpoint state with latest registry state (after processing)
				$current_checkpoint_state['context_registry_state'] = $this->context_registry->exportState();
				$current_checkpoint_state['field_processing_progress'] = $field_progress;
				
				// Save incremental checkpoint after every 10 fields
				if ( $field_index % 10 === 0 && $item_id ) {
					$saved_field_count = count( $field_progress['processed_fields'] ?? [] );
					CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_1_PROCESS_FIELDS, $current_checkpoint_state );
					error_log( sprintf(
						'[AEBG] 💾 Step 4.1 checkpoint saved after field %d/%d | %d fields in checkpoint',
						$actual_field_number,
						$total_fields,
						$saved_field_count
					) );
				}
			}
			
			error_log( '[AEBG] ✅ Completed processing all AI fields' );
			
			// Export final context registry state (with all results)
			$final_context_registry_state = $this->context_registry->exportState();
			
			// Save checkpoint after Step 4.1
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_1_PROCESS_FIELDS, [
					'title' => $title,
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'selected_products' => $selected_products,
					'elementor_data' => $elementor_data,
					'context_registry_state' => $final_context_registry_state, // All fields processed
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 4.1 COMPLETED | item_id=' . $item_id . ' | processed ' . $total_fields . ' fields | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'context_registry_state' => $final_context_registry_state,
				'processed_fields_count' => $total_fields,
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_4_1_exception', 'Exception in Step 4.1: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 4.2: Apply Content to Widgets
	 * 
	 * Phase 3: Applies generated content to Elementor widgets.
	 * This can take a long time for templates with many widgets.
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with processed_template and elapsed time, or WP_Error
	 */
	public function execute_step_4_2_apply_content( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking
		$step_start_time = microtime( true );
		$GLOBALS['aebg_step_start_time'] = $step_start_time;
		$GLOBALS['aebg_job_start_time'] = $step_start_time;
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['elementor_data'] ) || ! isset( $checkpoint_state['context_registry_state'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 4.2' );
			}
			
			$elementor_data = $checkpoint_state['elementor_data'];
			$context_registry_state = $checkpoint_state['context_registry_state'];
			$selected_products = $checkpoint_state['selected_products'] ?? [];
			$context = $checkpoint_state['context'] ?? [];
			$title = $checkpoint_state['title'] ?? '';
			
			error_log( '[AEBG] 📍 STEP 4.2: Starting applyProcessedContent | item_id=' . $item_id );
			
			// Clear and import context registry state (with all processed results)
			if ( $this->context_registry && method_exists( $this->context_registry, 'clear' ) ) {
				$this->context_registry->clear();
			}
			$this->context_registry->importState( $context_registry_state );
			
			// Initialize AI processor if needed
			if ( ! isset( $this->ai_processor ) ) {
				$this->ai_processor = new \AEBG\Core\AIPromptProcessor( $this->variables, $this->context_registry, $api_key, $ai_model );
			}
			
			// Create template processor
			$this->template_processor = new ElementorTemplateProcessor( $this->context_registry, $this->ai_processor, $this->variable_replacer, $this->content_generator );
			
			// Create deep copy for processing
			$processed_data = \AEBG\Core\DataUtilities::deepCopyArray( $elementor_data );
			
			// Phase 3: Apply processed content to widgets
			try {
				$this->template_processor->applyProcessedContent( $processed_data, $title, $selected_products, $context, $api_key, $ai_model );
				error_log( '[AEBG] ✅ Completed applyProcessedContent phase' );
			} catch ( \Exception $e ) {
				// If it's a reschedule exception, let it propagate
				if ( strpos( $e->getMessage(), 'Proactive reschedule' ) !== false ) {
					// Save partial state before rethrowing
					if ( $item_id && class_exists( '\AEBG\Core\CheckpointManager' ) ) {
						$existing_checkpoint = \AEBG\Core\CheckpointManager::loadCheckpoint( $item_id );
						$existing_state = $existing_checkpoint['data'] ?? [];
						
						$processed_data_copy = \AEBG\Core\DataUtilities::deepCopyArray( $processed_data );
						
						$merged_state = array_merge( $existing_state, [
							'elementor_data' => $processed_data_copy,
							'context_registry_state' => $context_registry_state,
						] );
						
						\AEBG\Core\CheckpointManager::saveCheckpoint( $item_id, \AEBG\Core\CheckpointManager::STEP_4_2_APPLY_CONTENT, $merged_state );
						error_log( '[AEBG] ✅ Saved partial template state before reschedule for item_id=' . $item_id );
					}
					throw $e;
				}
				error_log( '[AEBG] ⚠️ ERROR in applyProcessedContent: ' . $e->getMessage() );
			}
			
			// Validate processed data
			$validation = $this->template_processor->validateElementorData( $processed_data );
			if ( is_wp_error( $validation ) ) {
				Logger::warning( 'Elementor data validation failed', [ 'error' => $validation->get_error_message() ] );
			}
			
			// Save checkpoint after Step 4.2
			if ( $item_id ) {
				// Export current context registry state (in case Step 4.2 needs to be resumed)
				$final_context_registry_state = null;
				if ( $this->context_registry && method_exists( $this->context_registry, 'exportState' ) ) {
					$final_context_registry_state = $this->context_registry->exportState();
				}
				
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_2_APPLY_CONTENT, [
					'title' => $title,
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'selected_products' => $selected_products,
					'elementor_data' => $processed_data, // Required for validation
					'processed_template' => $processed_data, // Final processed template (for Step 5)
					'context_registry_state' => $final_context_registry_state ?? $context_registry_state, // Required for validation (in case Step 4.2 resumes)
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 4.2 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'processed_template' => $processed_data,
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_4_2_exception', 'Exception in Step 4.2: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 4: Process Elementor Template (LEGACY - for backward compatibility)
	 * 
	 * @deprecated This method is kept for backward compatibility. New code should use:
	 * - execute_step_4_collect_prompts()
	 * - execute_step_4_1_process_fields()
	 * - execute_step_4_2_apply_content()
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with processed_template and elapsed time, or WP_Error
	 */
	public function execute_step_4_process_template( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking
		// In step-by-step mode, each step gets its own 180s timeout
		// We need to set job_start_time to step start time so ElementorTemplateProcessor's timeout checks work correctly
		$step_start_time = microtime( true );
		$GLOBALS['aebg_job_start_time'] = $step_start_time; // Override for step-by-step mode
		$GLOBALS['aebg_step_start_time'] = $step_start_time;
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) || ! isset( $checkpoint_state['context'] ) || ! isset( $checkpoint_state['template_id'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 4' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$context = $checkpoint_state['context'];
			$template_id = (int) $checkpoint_state['template_id'];
			$title = $checkpoint_state['title'] ?? '';
			
			error_log( '[AEBG] 📍 STEP 4: Starting processElementorTemplate | item_id=' . $item_id . ' | template_id=' . $template_id );
			
			// Clear context registry BEFORE creating template processor
			if ( $this->context_registry && method_exists( $this->context_registry, 'clear' ) ) {
				$this->context_registry->clear();
			}
			
			// Initialize AI processor if needed
			if ( ! isset( $this->ai_processor ) ) {
				$this->ai_processor = new \AEBG\Core\AIPromptProcessor( $this->variables, $this->context_registry, $api_key, $ai_model );
			}
			
			// Create template processor
			$this->template_processor = new ElementorTemplateProcessor( $this->context_registry, $this->ai_processor, $this->variable_replacer, $this->content_generator );
			
			// CRITICAL: Check if we have a partially processed template from a previous run
			$partially_processed_template = null;
			$resume_reason = null;
			
			// Debug: Log what keys are in checkpoint_state
			$checkpoint_keys = array_keys( $checkpoint_state );
			error_log( '[AEBG] 🔍 Checkpoint state keys for item_id=' . $item_id . ': ' . implode( ', ', $checkpoint_keys ) );
			error_log( '[AEBG] 🔍 Has processed_template_partial: ' . ( isset( $checkpoint_state['processed_template_partial'] ) ? 'yes' : 'no' ) );
			error_log( '[AEBG] 🔍 Has processed_template_after_ai_fields: ' . ( isset( $checkpoint_state['processed_template_after_ai_fields'] ) ? 'yes' : 'no' ) );
			
			if ( isset( $checkpoint_state['processed_template_partial'] ) && ! empty( $checkpoint_state['processed_template_partial'] ) ) {
				// Priority 1: Resume from apply phase (most recent interruption)
				$partially_processed_template = $checkpoint_state['processed_template_partial'];
				$resume_reason = 'apply phase (during widget processing)';
				error_log( '[AEBG] 🔄 RESUMING Step 4 from partially processed template (during apply phase) | item_id=' . $item_id );
				error_log( '[AEBG] 📊 Resume data: apply_phase_started=' . ( $checkpoint_state['apply_phase_started'] ?? 'not set' ) );
			} elseif ( isset( $checkpoint_state['processed_template_after_ai_fields'] ) && ! empty( $checkpoint_state['processed_template_after_ai_fields'] ) ) {
				// Priority 2: Resume from after AI fields (before apply phase started)
				$partially_processed_template = $checkpoint_state['processed_template_after_ai_fields'];
				$resume_reason = 'after AI fields (before apply phase)';
				error_log( '[AEBG] 🔄 RESUMING Step 4 from partially processed template (after AI fields) | item_id=' . $item_id );
			} else {
				error_log( '[AEBG] 🔍 No partial template found in checkpoint - will start from scratch' );
			}
			
			if ( $partially_processed_template ) {
				// Count widgets that are already processed (have _aebg_widget_processed flag)
				$processed_count = 0;
				$total_widgets = 0;
				$this->countProcessedWidgetsRecursive( $partially_processed_template, $processed_count, $total_widgets );
				if ( $total_widgets > 0 ) {
					error_log( sprintf(
						'[AEBG] 📊 Resume stats: %d/%d widgets already processed (will skip these)',
						$processed_count,
						$total_widgets
					) );
				}
			}
			
			// CRITICAL: Check timeout before starting template processing (Step 4 can take >180s)
			$SERVER_TIMEOUT = 180; // Server-level timeout (cannot be overridden)
			
			// Determine model-specific threshold
			// GPT-4 requests take 7-8s, so we need 20s buffer (reschedule at 160s)
			// GPT-3.5 requests take 1-2s, so we can use 40s buffer (reschedule at 140s)
			$is_gpt4 = strpos( strtolower( $ai_model ), 'gpt-4' ) !== false || strpos( strtolower( $ai_model ), 'gpt4' ) !== false;
			$RESCHEDULE_THRESHOLD = $is_gpt4 ? 160 : 140; // GPT-4: 20s buffer, GPT-3.5: 40s buffer
			
			// Check elapsed time for this step
			$elapsed = microtime( true ) - $step_start_time;
			
			// If we're already at the threshold, reschedule immediately
			if ( $elapsed >= $RESCHEDULE_THRESHOLD && $item_id ) {
				error_log( '[AEBG] ⚠️ CRITICAL: Step 4 already at reschedule threshold before starting - elapsed: ' . round( $elapsed, 1 ) . 's' );
				error_log( '[AEBG] 🔄 Rescheduling Step 4 to continue from checkpoint' );
				
				// Save current checkpoint state
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_PROCESS_TEMPLATE, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'selected_products' => $selected_products,
					'processed_template' => $partially_processed_template, // Save any partial progress
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
				
				// Reschedule Step 4
				if ( function_exists( 'as_schedule_single_action' ) && class_exists( '\AEBG\Core\StepHandler' ) ) {
					$step_4_hook = \AEBG\Core\StepHandler::get_step_hook( 'step_4' );
					if ( $step_4_hook ) {
						// Fetch title for rescheduling
						$reschedule_title = $checkpoint_state['title'] ?? '';
						if ( empty( $reschedule_title ) && $item_id ) {
							global $wpdb;
							$item = $wpdb->get_row( $wpdb->prepare( 
								"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
								$item_id 
							) );
							$reschedule_title = $item->source_title ?? '';
						}
						as_schedule_single_action( time() + 2, $step_4_hook, [ $item_id, $reschedule_title ], 'aebg_generation_' . $item_id, true );
						error_log( '[AEBG] ✅ Step 4 rescheduled for item_id=' . $item_id );
					}
				}
				
				// Return error to trigger reschedule handling
				return new \WP_Error( 'step_4_reschedule', 'Step 4 rescheduled to avoid timeout - will continue in next request' );
			}
			
			// If we have a partially processed template, resume from apply phase
			if ( $partially_processed_template ) {
				// Count widgets before resuming to show progress
				$processed_count = 0;
				$total_widgets = 0;
				$this->countProcessedWidgetsRecursive( $partially_processed_template, $processed_count, $total_widgets );
				
				error_log( sprintf(
					'[AEBG] 🔄 RESUMING Step 4: Skipping processElementorData, going straight to applyProcessedContent | reason=%s | Progress: %d/%d widgets already processed',
					$resume_reason ?? 'unknown',
					$processed_count,
					$total_widgets
				) );
				// Use the partially processed template and just run applyProcessedContent
				$processed_template = $partially_processed_template;
				
				// Run applyProcessedContent on the partially processed template
				// Widgets with _aebg_widget_processed flag will be skipped automatically
				try {
					$resume_start = microtime( true );
					$this->template_processor->applyProcessedContent( $processed_template, $title, $selected_products, $context, $api_key, $ai_model );
					$resume_elapsed = microtime( true ) - $resume_start;
					error_log( sprintf(
						'[AEBG] ✅ Completed applyProcessedContent phase on resumed template in %.2fs | Final progress: %d/%d widgets processed',
						$resume_elapsed,
						$processed_count,
						$total_widgets
					) );
				} catch ( \Exception $e ) {
					// If it's a reschedule exception, let it propagate
					if ( strpos( $e->getMessage(), 'Proactive reschedule' ) !== false ) {
						throw $e;
					}
					error_log( '[AEBG] ⚠️ ERROR in resumed applyProcessedContent: ' . $e->getMessage() );
				}
			} else {
				// Normal flow: process template from scratch
				error_log( '[AEBG] 🆕 Starting Step 4 from scratch (no partial template found in checkpoint)' );
				$processed_template = $this->template_processor->processTemplate( $template_id, $title, $selected_products, $context, $api_key, $ai_model );
			}
			
			if ( is_wp_error( $processed_template ) ) {
				$this->observer->recordError( 'Template processing failed', [ 'error' => $processed_template->get_error_message() ] );
				$processed_template = null;
			} else {
				$this->observer->recordStep( 'process_template', [ 'template_id' => $template_id ] );
			}
			
			// Save checkpoint after Step 4
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_4_PROCESS_TEMPLATE, [
					'title' => $title,
					'template_id' => $template_id,
					'context' => $context,
					'selected_products' => $selected_products,
					'processed_template' => $processed_template,
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 4 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'processed_template' => $processed_template,
				'selected_products' => $selected_products,
				'context' => $context,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_4_exception', 'Exception in Step 4: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 5: Generate Content
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with content and elapsed time, or WP_Error
	 */
	public function execute_step_5_generate_content( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) || ! isset( $checkpoint_state['context'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 5' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$context = $checkpoint_state['context'];
			$title = $checkpoint_state['title'] ?? '';
			$processed_template = $checkpoint_state['processed_template'] ?? null;
			
			error_log( '[AEBG] 📍 STEP 5: Starting generateContent | item_id=' . $item_id );
			
			// Build checkpoint state for rescheduling
			$current_checkpoint_state = [
				'title' => $title,
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $context,
				'selected_products' => $selected_products,
				'processed_template' => $processed_template,
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
			];
			
			// Skip generateContent if we have a processed template
			$content = '';
			if ( ! $processed_template || is_wp_error( $processed_template ) ) {
				// CRITICAL: Check timeout before API call
				$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_5', $current_checkpoint_state, CheckpointManager::STEP_5_GENERATE_CONTENT, $step_start_time );
				if ( $reschedule_error ) {
					return $reschedule_error;
				}
				
				$content = $this->content_generator->generateContent( $title, $selected_products, $context, $api_key, $ai_model );
				
				if ( is_wp_error( $content ) ) {
					error_log( '[AEBG] generateContent failed: ' . $content->get_error_message() . ' - using empty content' );
					$this->observer->recordError( 'Content generation failed', [ 'error' => $content->get_error_message() ] );
					$content = '';
				} else {
					// Replace product variables in the generated content
					$content = $this->variable_replacer->replaceVariablesInPrompt( $content, $title, $selected_products, $context );
					$this->observer->recordStep( 'generate_content', [ 'content_length' => strlen( $content ) ] );
				}
			} else {
				error_log( '[AEBG] Processed template exists - skipping generateContent' );
			}
			
			// Save checkpoint after Step 5 (if content was generated)
			if ( $item_id && ! empty( $content ) ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_5_GENERATE_CONTENT, [
					'title' => $title,
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $context,
					'selected_products' => $selected_products,
					'processed_template' => $processed_template,
					'content' => $content,
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 5 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'content' => $content,
				'selected_products' => $selected_products,
				'context' => $context,
				'processed_template' => $processed_template,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_5_exception', 'Exception in Step 5: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 5.5: Merchant Comparisons
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with selected_products and elapsed time, or WP_Error
	 */
	public function execute_step_5_5_merchant_comparisons( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 5.5' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$author_id = $checkpoint_state['author_id'] ?? $this->author_id;
			
			error_log( '[AEBG] 📍 STEP 5.5: Starting processMerchantComparisons | item_id=' . $item_id );
			
			// Build checkpoint state for rescheduling
			$current_checkpoint_state = [
				'title' => $checkpoint_state['title'] ?? '',
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'context' => $checkpoint_state['context'] ?? [],
				'selected_products' => $selected_products,
				'processed_template' => $checkpoint_state['processed_template'] ?? null,
				'content' => $checkpoint_state['content'] ?? '',
				'settings' => $checkpoint_state['settings'] ?? $settings,
				'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
				'author_id' => $author_id,
			];
			
			// CRITICAL: Check timeout before API calls (processMerchantComparisons makes multiple API calls)
			$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_5_5', $current_checkpoint_state, CheckpointManager::STEP_5_5_MERCHANT_COMPARISONS, $step_start_time );
			if ( $reschedule_error ) {
				return $reschedule_error;
			}
			
			// Generate comparison data with null post_id (will be updated after post creation)
			if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
				$this->processMerchantComparisons( null, $selected_products, $author_id );
			}
			
			// Save checkpoint after Step 5.5
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_5_5_MERCHANT_COMPARISONS, [
					'title' => $checkpoint_state['title'] ?? '',
					'template_id' => $checkpoint_state['template_id'] ?? 0,
					'context' => $checkpoint_state['context'] ?? [],
					'selected_products' => $selected_products,
					'processed_template' => $checkpoint_state['processed_template'] ?? null,
					'content' => $checkpoint_state['content'] ?? '',
					'settings' => $checkpoint_state['settings'] ?? $settings,
					'ai_model' => $checkpoint_state['ai_model'] ?? $ai_model,
					'author_id' => $author_id,
				] );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 5.5 COMPLETED | item_id=' . $item_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'selected_products' => $selected_products,
				'context' => $checkpoint_state['context'] ?? [],
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_5_5_exception', 'Exception in Step 5.5: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 6: Create Post
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with post_id and elapsed time, or WP_Error
	 */
	public function execute_step_6_create_post( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		try {
			// Extract data from checkpoint_state
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['selected_products'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing required data for Step 6' );
			}
			
			$selected_products = $checkpoint_state['selected_products'];
			$title = $checkpoint_state['title'] ?? '';
			$content = $checkpoint_state['content'] ?? '';
			$processed_template = $checkpoint_state['processed_template'] ?? null;
			$final_settings = $checkpoint_state['settings'] ?? $settings;
			
			error_log( '[AEBG] 📍 STEP 6: Starting createPost | item_id=' . $item_id );
			$this->observer->recordStep( 'create_post' );
			
			// Create post
			$new_post_id = $this->post_creator->createPost( $title, $content, $processed_template, $final_settings, $selected_products );
			
			if ( is_wp_error( $new_post_id ) || empty( $new_post_id ) || ! is_numeric( $new_post_id ) || $new_post_id <= 0 ) {
				return is_wp_error( $new_post_id ) ? $new_post_id : new \WP_Error( 'aebg_invalid_post_id', 'Post creation returned invalid post ID' );
			}
			
			// Re-process images with post association
			if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
				$selected_products = ImageProcessor::processProductImages( $selected_products, $new_post_id );
			}
			
			// Update comparison records with post_id
			if ( ! empty( $selected_products ) && is_array( $selected_products ) ) {
				$author_id = $checkpoint_state['author_id'] ?? $this->author_id;
				$this->updateComparisonRecordsWithPostId( $new_post_id, $selected_products, $author_id );
			}
			
			// Verify post exists
			$post_exists = get_post( $new_post_id );
			if ( ! $post_exists ) {
				return new \WP_Error( 'aebg_post_not_found', 'Post was created but not found in database' );
			}
			
			// Verify Elementor processing
			$this->verifyElementorProcessingComplete( $new_post_id );
			
			// Save checkpoint with post_id for Step 7
			// CRITICAL: Include template_id as it's required by CheckpointManager validation
			if ( $item_id ) {
				CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_6_CREATE_POST, [
					'post_id' => $new_post_id,
					'title' => $title,
					'template_id' => $checkpoint_state['template_id'] ?? 0, // CRITICAL: Required for Step 7 validation
					'content' => $content,
					'selected_products' => $selected_products,
					'context' => $checkpoint_state['context'] ?? [],
					'settings' => $final_settings,
					'api_key' => $api_key,
					'ai_model' => $ai_model,
					'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				] );
			}
			
			// NOTE: Do NOT schedule next step here - ActionHandler::execute_step() handles step scheduling
			// Scheduling here would create duplicate actions since ActionHandler also schedules the next step
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 6 COMPLETED | item_id=' . $item_id . ' | post_id=' . $new_post_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'post_id' => (int) $new_post_id,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_6_exception', 'Exception in Step 6: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 7: Image Enhancements
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with results and elapsed time, or WP_Error
	 */
	public function execute_step_7_image_enhancements( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Load checkpoint from Step 6
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['post_id'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing post_id for Step 7' );
			}
			
			$post_id = $checkpoint_state['post_id'];
			$title = $checkpoint_state['title'] ?? '';
			$final_settings = $checkpoint_state['settings'] ?? $settings;
			
			error_log( '[AEBG] 📍 STEP 7: Starting image enhancements | item_id=' . $item_id . ' | post_id=' . $post_id );
			$this->observer->recordStep( 'image_enhancements' );
			
			// CRITICAL: Check for resume state (incremental checkpointing)
			$image_progress = $checkpoint_state['image_progress'] ?? [];
			$featured_image_completed = ! empty( $image_progress['featured_image_completed'] );
			$ai_images_completed = ! empty( $image_progress['ai_images_completed'] );
			
			if ( $featured_image_completed || $ai_images_completed ) {
				error_log( '[AEBG] 🔄 RESUMING Step 7 from checkpoint | featured_image=' . ( $featured_image_completed ? 'done' : 'pending' ) . ' | ai_images=' . ( $ai_images_completed ? 'done' : 'pending' ) );
			}
			
			$results = [];
			
			// Build checkpoint state for rescheduling (updated after each operation)
			$current_checkpoint_state = [
				'post_id' => $post_id,
				'title' => $title,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'image_progress' => $image_progress,
				'image_enhancement_results' => $checkpoint_state['image_enhancement_results'] ?? [],
			];
			
			// 1. Generate Featured Image
			if ( ! empty( $final_settings['generate_featured_images'] ) && $final_settings['generate_featured_images'] ) {
				if ( ! $featured_image_completed ) {
					// CRITICAL: Check timeout before API call
					$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_7', $current_checkpoint_state, CheckpointManager::STEP_7_IMAGE_ENHANCEMENTS, $step_start_time );
					if ( $reschedule_error ) {
						return $reschedule_error;
					}
					
					error_log( '[AEBG] STEP 7: Featured image generation enabled - calling FeaturedImageGenerator::generate()' );
					$featured_image_id = FeaturedImageGenerator::generate(
						$post_id,
						$title,
						$final_settings,
						$api_key,
						$ai_model
					);
					$results['featured_image'] = $featured_image_id ?: false;
					
					// CRITICAL: Save checkpoint after featured image is completed
					$current_checkpoint_state['image_progress']['featured_image_completed'] = true;
					$current_checkpoint_state['image_enhancement_results']['featured_image'] = $results['featured_image'];
					if ( $item_id ) {
						CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_7_IMAGE_ENHANCEMENTS, $current_checkpoint_state );
						error_log( '[AEBG] 💾 Step 7 checkpoint saved after featured image completion' );
					}
				} else {
					error_log( '[AEBG] STEP 7: Featured image already completed - skipping' );
					$results['featured_image'] = $checkpoint_state['image_enhancement_results']['featured_image'] ?? false;
				}
			} else {
				error_log( '[AEBG] Featured image generation skipped - setting disabled' );
			}
			
			// 2. Associate AI-generated Images
			if ( ! empty( $final_settings['include_ai_images'] ) && $final_settings['include_ai_images'] ) {
				if ( ! $ai_images_completed ) {
					// CRITICAL: Check timeout before operation (may involve API calls)
					$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_7', $current_checkpoint_state, CheckpointManager::STEP_7_IMAGE_ENHANCEMENTS, $step_start_time );
					if ( $reschedule_error ) {
						return $reschedule_error;
					}
					
					error_log( '[AEBG] STEP 7: AI image association enabled - calling associateAIGeneratedImagesWithPost()' );
					$this->associateAIGeneratedImagesWithPost( $post_id, $final_settings );
					$results['ai_images'] = true;
					
					// CRITICAL: Save checkpoint after AI images are completed
					$current_checkpoint_state['image_progress']['ai_images_completed'] = true;
					$current_checkpoint_state['image_enhancement_results']['ai_images'] = true;
					if ( $item_id ) {
						CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_7_IMAGE_ENHANCEMENTS, $current_checkpoint_state );
						error_log( '[AEBG] 💾 Step 7 checkpoint saved after AI images completion' );
					}
				} else {
					error_log( '[AEBG] STEP 7: AI images already completed - skipping' );
					$results['ai_images'] = $checkpoint_state['image_enhancement_results']['ai_images'] ?? false;
				}
			} else {
				error_log( '[AEBG] AI image association skipped - setting disabled' );
			}
			
			// Checkpoint is already saved after each operation above
			
			// NOTE: Do NOT schedule next step here - ActionHandler::execute_step() handles step scheduling
			// Scheduling here would create duplicate actions since ActionHandler also schedules the next step
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 7 COMPLETED | item_id=' . $item_id . ' | post_id=' . $post_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'post_id' => (int) $post_id,
				'results' => $results,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_7_exception', 'Exception in Step 7: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Execute Step 8: SEO Enhancements
	 * 
	 * @param array|null $checkpoint_state Previous checkpoint state
	 * @param int|null $item_id Item ID
	 * @param array $settings Settings
	 * @param string $api_key API key
	 * @param string $ai_model AI model
	 * @return array|\WP_Error Result array with results and elapsed time, or WP_Error
	 */
	public function execute_step_8_seo_enhancements( ?array $checkpoint_state, ?int $item_id, array $settings, string $api_key, string $ai_model ) {
		$start_time = microtime( true );
		
		// CRITICAL: Set step start time for timeout tracking (same as Step 4)
		$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		
		try {
			// Load checkpoint from Step 7
			if ( ! $checkpoint_state || ! isset( $checkpoint_state['post_id'] ) ) {
				return new \WP_Error( 'aebg_missing_checkpoint', 'Checkpoint state missing post_id for Step 8' );
			}
			
			$post_id = $checkpoint_state['post_id'];
			$title = $checkpoint_state['title'] ?? '';
			$content = $checkpoint_state['content'] ?? '';
			$selected_products = $checkpoint_state['selected_products'] ?? [];
			$context = $checkpoint_state['context'] ?? [];
			$final_settings = $checkpoint_state['settings'] ?? $settings;
			
			error_log( '[AEBG] 📍 STEP 8: Starting SEO enhancements | item_id=' . $item_id . ' | post_id=' . $post_id );
			error_log( '[AEBG] STEP 8 Settings check: auto_tags=' . ( ! empty( $final_settings['auto_tags'] ) ? 'enabled' : 'disabled' ) . ' | auto_categories=' . ( ! empty( $final_settings['auto_categories'] ) ? 'enabled' : 'disabled' ) . ' | include_meta=' . ( ! empty( $final_settings['include_meta'] ) ? 'enabled' : 'disabled' ) . ' | include_schema=' . ( ! empty( $final_settings['include_schema'] ) ? 'enabled' : 'disabled' ) );
			$this->observer->recordStep( 'seo_enhancements' );
			
			// CRITICAL: Check for resume state (incremental checkpointing)
			$seo_progress = $checkpoint_state['seo_progress'] ?? [];
			$tags_completed = ! empty( $seo_progress['tags_completed'] );
			$categories_completed = ! empty( $seo_progress['categories_completed'] );
			$meta_completed = ! empty( $seo_progress['meta_completed'] );
			$schema_completed = ! empty( $seo_progress['schema_completed'] );
			
			if ( $tags_completed || $categories_completed || $meta_completed || $schema_completed ) {
				error_log( '[AEBG] 🔄 RESUMING Step 8 from checkpoint | tags=' . ( $tags_completed ? 'done' : 'pending' ) . ' | categories=' . ( $categories_completed ? 'done' : 'pending' ) . ' | meta=' . ( $meta_completed ? 'done' : 'pending' ) . ' | schema=' . ( $schema_completed ? 'done' : 'pending' ) );
			}
			
			$results = [];
			
			// Build checkpoint state for rescheduling (updated after each API call)
			$current_checkpoint_state = [
				'post_id' => $post_id,
				'title' => $title,
				'content' => $content,
				'selected_products' => $selected_products,
				'context' => $context,
				'settings' => $final_settings,
				'ai_model' => $ai_model,
				'author_id' => $checkpoint_state['author_id'] ?? $this->author_id,
				'template_id' => $checkpoint_state['template_id'] ?? 0,
				'seo_progress' => $seo_progress,
			];
			
			// 1. Generate and Assign Tags
			if ( ! empty( $final_settings['auto_tags'] ) && $final_settings['auto_tags'] ) {
				if ( ! $tags_completed ) {
					// CRITICAL: Check timeout before API call
					$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_8', $current_checkpoint_state, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $step_start_time );
					if ( $reschedule_error ) {
						return $reschedule_error;
					}
					
					error_log( '[AEBG] STEP 8: Tag generation enabled - calling TagGenerator::generateAndAssign()' );
					$tags_result = TagGenerator::generateAndAssign(
						$post_id,
						$title,
						$content,
						$selected_products,
						$context,
						$final_settings,
						$api_key,
						$ai_model
					);
					if ( $tags_result === false ) {
						error_log( '[AEBG] STEP 8: Tag generation returned false - check logs for details' );
					} else {
						error_log( '[AEBG] STEP 8: Tag generation succeeded - assigned ' . count( $tags_result ) . ' tags: ' . implode( ', ', $tags_result ) );
					}
					$results['tags'] = $tags_result;
					
					// CRITICAL: Save checkpoint after tags are completed
					$current_checkpoint_state['seo_progress']['tags_completed'] = true;
					$current_checkpoint_state['results'] = $results;
					if ( $item_id ) {
						CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $current_checkpoint_state );
						error_log( '[AEBG] 💾 Step 8 checkpoint saved after tags completion' );
					}
				} else {
					error_log( '[AEBG] STEP 8: Tags already completed - skipping' );
					$results['tags'] = $checkpoint_state['results']['tags'] ?? [];
				}
			} else {
				error_log( '[AEBG] STEP 8: Tag generation skipped - setting disabled (auto_tags=' . var_export( $final_settings['auto_tags'] ?? 'not set', true ) . ')' );
			}
			
			// 2. Generate and Assign Categories
			if ( ! empty( $final_settings['auto_categories'] ) && $final_settings['auto_categories'] ) {
				if ( ! $categories_completed ) {
					// CRITICAL: Check timeout before API call
					$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_8', $current_checkpoint_state, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $step_start_time );
					if ( $reschedule_error ) {
						return $reschedule_error;
					}
					
					error_log( '[AEBG] STEP 8: Category generation enabled - calling CategoryGenerator::generateAndAssign()' );
					$categories_result = CategoryGenerator::generateAndAssign(
						$post_id,
						$title,
						$content,
						$selected_products,
						$context,
						$final_settings,
						$api_key,
						$ai_model
					);
					$results['categories'] = $categories_result;
					
					// CRITICAL: Save checkpoint after categories are completed
					$current_checkpoint_state['seo_progress']['categories_completed'] = true;
					$current_checkpoint_state['results'] = $results;
					if ( $item_id ) {
						CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $current_checkpoint_state );
						error_log( '[AEBG] 💾 Step 8 checkpoint saved after categories completion' );
					}
				} else {
					error_log( '[AEBG] STEP 8: Categories already completed - skipping' );
					$results['categories'] = $checkpoint_state['results']['categories'] ?? [];
				}
			} else {
				error_log( '[AEBG] Category generation skipped - setting disabled' );
			}
			
			// 3. Generate Meta Description
			if ( ! empty( $final_settings['include_meta'] ) && $final_settings['include_meta'] ) {
				if ( ! $meta_completed ) {
					// CRITICAL: Check timeout before API call
					$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_8', $current_checkpoint_state, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $step_start_time );
					if ( $reschedule_error ) {
						return $reschedule_error;
					}
					
					error_log( '[AEBG] STEP 8: Meta description generation enabled - calling MetaDescriptionGenerator::generateAndSave()' );
					$meta_result = MetaDescriptionGenerator::generateAndSave(
						$post_id,
						$title,
						$content,
						$selected_products,
						$final_settings,
						$api_key,
						$ai_model
					);
					$results['meta_description'] = $meta_result;
					
					// CRITICAL: Save checkpoint after meta is completed
					$current_checkpoint_state['seo_progress']['meta_completed'] = true;
					$current_checkpoint_state['results'] = $results;
					if ( $item_id ) {
						CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $current_checkpoint_state );
						error_log( '[AEBG] 💾 Step 8 checkpoint saved after meta completion' );
					}
				} else {
					error_log( '[AEBG] STEP 8: Meta description already completed - skipping' );
					$results['meta_description'] = $checkpoint_state['results']['meta_description'] ?? false;
				}
			} else {
				error_log( '[AEBG] Meta description generation skipped - setting disabled' );
			}
			
			// 4. Generate Structured Data (Schema)
			if ( ! empty( $final_settings['include_schema'] ) && $final_settings['include_schema'] ) {
				if ( ! $schema_completed ) {
					// CRITICAL: Check timeout before API call
					$reschedule_error = $this->checkAndRescheduleIfNeeded( $item_id, 'step_8', $current_checkpoint_state, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $step_start_time );
					if ( $reschedule_error ) {
						return $reschedule_error;
					}
					
					error_log( '[AEBG] STEP 8: Schema generation enabled - calling SchemaGenerator::generateAndSave()' );
					$schema_result = SchemaGenerator::generateAndSave(
						$post_id,
						$title,
						$content,
						$selected_products,
						$final_settings,
						$api_key,
						$ai_model
					);
					$results['schema'] = $schema_result;
					
					// CRITICAL: Save checkpoint after schema is completed
					$current_checkpoint_state['seo_progress']['schema_completed'] = true;
					$current_checkpoint_state['results'] = $results;
					if ( $item_id ) {
						CheckpointManager::saveCheckpoint( $item_id, CheckpointManager::STEP_8_SEO_ENHANCEMENTS, $current_checkpoint_state );
						error_log( '[AEBG] 💾 Step 8 checkpoint saved after schema completion' );
					}
				} else {
					error_log( '[AEBG] STEP 8: Schema already completed - skipping' );
					$results['schema'] = $checkpoint_state['results']['schema'] ?? false;
				}
			} else {
				error_log( '[AEBG] Schema generation skipped - setting disabled' );
			}
			
			// Final cleanup
			$this->finalCleanupAfterPostCreation( $post_id );
			
			// Mark complete
			$this->observer->markComplete();
			
			// Clear checkpoint on successful completion
			if ( $item_id ) {
				CheckpointManager::clearCheckpoint( $item_id );
			}
			
			$elapsed = microtime( true ) - $start_time;
			error_log( '[AEBG] ✅ STEP 8 COMPLETED | item_id=' . $item_id . ' | post_id=' . $post_id . ' | elapsed=' . round( $elapsed, 2 ) . 's' );
			
			return [
				'post_id' => (int) $post_id,
				'results' => $results,
				'elapsed' => $elapsed,
			];
		} catch ( \Exception $e ) {
			$elapsed = microtime( true ) - $start_time;
			return new \WP_Error( 'step_8_exception', 'Exception in Step 8: ' . $e->getMessage(), [ 'elapsed' => $elapsed ] );
		}
	}

	/**
	 * Check if approaching server timeout and reschedule if needed
	 * 
	 * This helper method provides proactive rescheduling for all steps to prevent 180s server timeouts.
	 * It checks elapsed time and reschedules the current step if approaching the threshold.
	 * The threshold is model-specific: GPT-4 needs more buffer (160s) due to slower requests,
	 * while GPT-3.5 can use less buffer (140s) due to faster requests.
	 * 
	 * @param int|null $item_id Item ID
	 * @param string $step_key Step key (e.g., 'step_8')
	 * @param array $checkpoint_state Current checkpoint state to save
	 * @param string $checkpoint_step Checkpoint step constant (e.g., CheckpointManager::STEP_8_SEO_ENHANCEMENTS)
	 * @param float|null $step_start_time Step start time (microtime). If null, uses $GLOBALS['aebg_step_start_time']
	 * @param string|null $ai_model AI model name (optional, defaults to GPT-3.5 threshold)
	 * @return \WP_Error|null Returns WP_Error if rescheduled, null if OK to continue
	 */
	private function checkAndRescheduleIfNeeded( ?int $item_id, string $step_key, array $checkpoint_state, string $checkpoint_step, ?float $step_start_time = null, ?string $ai_model = null ): ?\WP_Error {
		if ( ! $item_id ) {
			return null; // Can't reschedule without item_id
		}
		
		// Get step start time (from parameter or global)
		if ( $step_start_time === null ) {
			$step_start_time = $GLOBALS['aebg_step_start_time'] ?? microtime( true );
		}
		
		// Get AI model from checkpoint state if not provided
		// Handle both 'model' and 'ai_model' keys for compatibility
		if ( $ai_model === null ) {
			$ai_model = $checkpoint_state['ai_model'] ?? $checkpoint_state['model'] ?? 'gpt-3.5-turbo';
		}
		
		// Server timeout constants
		$SERVER_TIMEOUT = 180; // Server-level timeout (cannot be overridden)
		
		// Model-specific reschedule thresholds
		// GPT-4 requests take 7-8s, so we need 20s buffer (reschedule at 160s)
		// GPT-3.5 requests take 1-2s, so we can use 40s buffer (reschedule at 140s)
		$is_gpt4 = strpos( strtolower( $ai_model ), 'gpt-4' ) !== false || strpos( strtolower( $ai_model ), 'gpt4' ) !== false;
		$RESCHEDULE_THRESHOLD = $is_gpt4 ? 160 : 140; // GPT-4: 20s buffer, GPT-3.5: 40s buffer
		
		// Calculate elapsed time for this step
		$elapsed = microtime( true ) - $step_start_time;
		
		// Check if we're approaching the threshold
		if ( $elapsed >= $RESCHEDULE_THRESHOLD && $elapsed < $SERVER_TIMEOUT ) {
			error_log( sprintf(
				'[AEBG] ⚠️ CRITICAL: Approaching server timeout (180s) in %s - elapsed: %.1fs - rescheduling to continue from checkpoint (model: %s, threshold: %ds)',
				$step_key,
				$elapsed,
				$ai_model,
				$RESCHEDULE_THRESHOLD
			) );
			
			// Save current checkpoint state
			CheckpointManager::saveCheckpoint( $item_id, $checkpoint_step, $checkpoint_state );
			
			// Reschedule the step
			if ( function_exists( 'as_schedule_single_action' ) && class_exists( '\AEBG\Core\StepHandler' ) ) {
				$hook = StepHandler::get_step_hook( $step_key );
				if ( $hook ) {
					$group = 'aebg_generation_' . $item_id;
					
					// Fetch title first - Action Scheduler requires args to match exactly when unscheduling.
					// We schedule with [ $item_id, $title ], so we must unschedule with the same.
					$reschedule_title = $checkpoint_state['title'] ?? '';
					if ( empty( $reschedule_title ) ) {
						global $wpdb;
						$item = $wpdb->get_row( $wpdb->prepare(
							"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
							$item_id
						) );
						$reschedule_title = $item->source_title ?? '';
					}
					$schedule_args = [ $item_id, $reschedule_title ];
					
					// CRITICAL: Unschedule any existing *pending* action for this step (same hook/args/group).
					// The currently *running* action cannot be unscheduled (we're inside it). So we schedule
					// with unique=false so the continuation is added even though an in-progress action exists.
					if ( function_exists( 'as_unschedule_action' ) ) {
						$unscheduled = as_unschedule_action( $hook, $schedule_args, $group );
						if ( $unscheduled ) {
							error_log( sprintf(
								'[AEBG] 🔄 Unscheduled existing pending action for %s before rescheduling | item_id=%d',
								$step_key,
								$item_id
							) );
						}
					}
					
					// Schedule the continuation. Use unique=false: the running action (same hook/args/group)
					// is still "in progress", so unique=true would make AS return 0 and no continuation would run.
					$action_id = as_schedule_single_action( time() + 2, $hook, $schedule_args, $group, false );
					
					if ( $action_id > 0 ) {
						error_log( sprintf(
							'[AEBG] ✅ %s rescheduled for item_id=%d | action_id=%d | elapsed=%.1fs',
							$step_key,
							$item_id,
							$action_id,
							$elapsed
						) );
					} elseif ( $action_id === 0 ) {
						// Should not happen with unique=false; log if it does
						error_log( sprintf(
							'[AEBG] ⚠️ WARNING: %s reschedule returned action_id=0 | item_id=%d | elapsed=%.1fs',
							$step_key,
							$item_id,
							$elapsed
						) );
					} else {
						error_log( sprintf(
							'[AEBG] ❌ ERROR: Failed to reschedule %s for item_id=%d | action_id=%s | elapsed=%.1fs',
							$step_key,
							$item_id,
							var_export( $action_id, true ),
							$elapsed
						) );
					}
				}
			}
			
			// Return error to trigger reschedule handling
			return new \WP_Error( $step_key . '_reschedule', sprintf( '%s rescheduled to avoid timeout - elapsed: %.1fs', $step_key, $elapsed ) );
		}
		
		return null; // OK to continue
	}

	/**
	 * Recursively count processed widgets in template data
	 * 
	 * @param array $data Template data
	 * @param int &$processed_count Reference to processed count (incremented)
	 * @param int &$total_count Reference to total count (incremented)
	 * @return void
	 */
	private function countProcessedWidgetsRecursive( $data, &$processed_count, &$total_count ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		
		// Check if this is a widget
		if ( isset( $data['elType'] ) && $data['elType'] === 'widget' ) {
			$total_count++;
			if ( isset( $data['settings']['_aebg_widget_processed'] ) && $data['settings']['_aebg_widget_processed'] === true ) {
				$processed_count++;
			}
		}
		
		// Recursively check content and elements
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $item ) {
				$this->countProcessedWidgetsRecursive( $item, $processed_count, $total_count );
			}
		}
		
		if ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
			foreach ( $data['elements'] as $element ) {
				$this->countProcessedWidgetsRecursive( $element, $processed_count, $total_count );
			}
		}
	}

}
			