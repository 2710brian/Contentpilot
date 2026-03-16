<?php

namespace AEBG\Core;

use AEBG\Core\Logger;
use AEBG\Core\ActionSchedulerHelper;

/**
 * Competitor Tracking Manager
 * 
 * Main controller for competitor tracking functionality
 * 
 * @package AEBG\Core
 */
class CompetitorTrackingManager {
	
	/**
	 * Static flag to track if a scrape is currently executing.
	 * This prevents Action Scheduler from killing the action during execution.
	 * 
	 * @var bool
	 */
	private static $is_scraping = false;
	
	/**
	 * Check if a scrape is currently executing.
	 * Used by filters to prevent premature termination.
	 * 
	 * @return bool
	 */
	public static function is_scraping() {
		return self::$is_scraping;
	}
	
	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Register the scrape action handler
		// Set flag BEFORE action runs to prevent premature termination
		add_action( 'aebg_competitor_scrape', function( $competitor_id ) {
			// Set protection flags IMMEDIATELY when action is triggered
			// This prevents force_exit_after_one_action from killing it
			// Set it as early as possible, even before any other code runs
			// Note: process_scrape() will also set these, but setting them here provides
			// early protection before process_scrape() is called
			self::$is_scraping = true;
			$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = true;
			$GLOBALS['AEBG_COMPETITOR_SCRAPE_START_TIME'] = microtime( true );
			
			// Then call the actual handler
			// process_scrape() will set flags again and use try/finally to ensure cleanup
			try {
				self::process_scrape( $competitor_id );
			} catch ( \Exception $e ) {
				// If process_scrape() throws an exception before setting flags,
				// clear them here as a safety net
				self::$is_scraping = false;
				$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = false;
				unset( $GLOBALS['AEBG_COMPETITOR_SCRAPE_START_TIME'] );
				throw $e; // Re-throw to let Action Scheduler handle it
			}
		}, 10, 1 ); // Priority 10, accept 1 argument
		add_action( 'init', [ __CLASS__, 'schedule_active_competitors' ], 20 );
	}
	
	/**
	 * Add a new competitor to track
	 * 
	 * @param int    $user_id User ID
	 * @param string $name    Competitor name
	 * @param string $url     URL to monitor
	 * @param int    $interval Scraping interval in seconds (default: 3600 = 1 hour)
	 * @return int|\WP_Error Competitor ID or error
	 */
	public static function add_competitor( $user_id, $name, $url, $interval = 3600 ) {
		global $wpdb;
		
		// Validate URL
		$url = esc_url_raw( $url );
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid URL provided.', 'aebg' ) );
		}
		
		// Check if URL already exists
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_competitors WHERE url = %s",
			$url
		) );
		
		if ( $existing ) {
			return new \WP_Error( 'duplicate_url', __( 'This URL is already being tracked.', 'aebg' ) );
		}
		
		// Insert competitor
		$result = $wpdb->insert(
			$wpdb->prefix . 'aebg_competitors',
			[
				'user_id'          => $user_id,
				'name'            => sanitize_text_field( $name ),
				'url'             => $url,
				'scraping_interval' => absint( $interval ),
				'is_active'       => 1,
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);
		
		if ( ! $result ) {
			Logger::error( 'Failed to add competitor', [
				'user_id' => $user_id,
				'url'     => $url,
				'db_error' => $wpdb->last_error,
			] );
			return new \WP_Error( 'database_error', __( 'Failed to add competitor to database.', 'aebg' ) );
		}
		
		$competitor_id = $wpdb->insert_id;
		
		// Schedule first scrape
		self::schedule_scrape( $competitor_id, 0 );
		
		Logger::info( 'Competitor added', [
			'competitor_id' => $competitor_id,
			'name'          => $name,
			'url'           => $url,
			'interval'      => $interval,
		] );
		
		return $competitor_id;
	}
	
	/**
	 * Update competitor
	 * 
	 * @param int   $competitor_id Competitor ID
	 * @param array $data          Data to update
	 * @return bool|\WP_Error Success or error
	 */
	public static function update_competitor( $competitor_id, $data ) {
		global $wpdb;
		
		$update_data = [];
		$update_format = [];
		
		if ( isset( $data['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$update_format[] = '%s';
		}
		
		if ( isset( $data['url'] ) ) {
			$url = esc_url_raw( $data['url'] );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new \WP_Error( 'invalid_url', __( 'Invalid URL provided.', 'aebg' ) );
			}
			$update_data['url'] = $url;
			$update_format[] = '%s';
		}
		
		if ( isset( $data['scraping_interval'] ) ) {
			$update_data['scraping_interval'] = absint( $data['scraping_interval'] );
			$update_format[] = '%d';
		}
		
		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = (int) $data['is_active'];
			$update_format[] = '%d';
		}
		
		if ( isset( $data['ai_prompt_template'] ) ) {
			$update_data['ai_prompt_template'] = sanitize_textarea_field( $data['ai_prompt_template'] );
			$update_format[] = '%s';
		}
		
		if ( isset( $data['extraction_config'] ) ) {
			$update_data['extraction_config'] = wp_json_encode( $data['extraction_config'] );
			$update_format[] = '%s';
		}
		
		if ( empty( $update_data ) ) {
			return true; // Nothing to update
		}
		
		$result = $wpdb->update(
			$wpdb->prefix . 'aebg_competitors',
			$update_data,
			[ 'id' => $competitor_id ],
			$update_format,
			[ '%d' ]
		);
		
		if ( false === $result ) {
			Logger::error( 'Failed to update competitor', [
				'competitor_id' => $competitor_id,
				'db_error'      => $wpdb->last_error,
			] );
			return new \WP_Error( 'database_error', __( 'Failed to update competitor.', 'aebg' ) );
		}
		
		// If interval was changed, reschedule scrape
		if ( isset( $data['scraping_interval'] ) ) {
			// Cancel existing scheduled action
			$group = 'aebg_competitor_tracking_' . $competitor_id;
			if ( ActionSchedulerHelper::ensure_initialized() && function_exists( 'as_unschedule_action' ) ) {
				// Cancel all pending actions for this competitor
				as_unschedule_action( 'aebg_competitor_scrape', [ 'competitor_id' => $competitor_id ], $group );
			}
			
			// Reschedule with new interval
			self::schedule_scrape( $competitor_id, 0 );
		}
		
		Logger::info( 'Competitor updated', [
			'competitor_id' => $competitor_id,
			'updated_fields' => array_keys( $update_data ),
		] );
		
		return true;
	}
	
	/**
	 * Delete competitor
	 * 
	 * @param int $competitor_id Competitor ID
	 * @return bool|\WP_Error Success or error
	 */
	public static function delete_competitor( $competitor_id ) {
		global $wpdb;
		
		// Cancel scheduled actions
		$group = 'aebg_competitor_tracking_' . $competitor_id;
		if ( ActionSchedulerHelper::ensure_initialized() && function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( 'aebg_competitor_scrape', [ 'competitor_id' => $competitor_id ], $group );
		}
		
		// Delete competitor
		$result = $wpdb->delete(
			$wpdb->prefix . 'aebg_competitors',
			[ 'id' => $competitor_id ],
			[ '%d' ]
		);
		
		if ( false === $result ) {
			Logger::error( 'Failed to delete competitor', [
				'competitor_id' => $competitor_id,
				'db_error'      => $wpdb->last_error,
			] );
			return new \WP_Error( 'database_error', __( 'Failed to delete competitor.', 'aebg' ) );
		}
		
		Logger::info( 'Competitor deleted', [
			'competitor_id' => $competitor_id,
		] );
		
		return true;
	}
	
	/**
	 * Get competitor
	 * 
	 * @param int $competitor_id Competitor ID
	 * @return array|null Competitor data or null
	 */
	public static function get_competitor( $competitor_id ) {
		global $wpdb;
		
		$competitor = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitors WHERE id = %d",
			$competitor_id
		), ARRAY_A );
		
		if ( ! $competitor ) {
			return null;
		}
		
		// Decode JSON fields
		if ( ! empty( $competitor['extraction_config'] ) ) {
			$competitor['extraction_config'] = json_decode( $competitor['extraction_config'], true );
		}
		
		return $competitor;
	}
	
	/**
	 * Get all active competitors
	 * 
	 * @return array Active competitors
	 */
	public static function get_active_competitors() {
		global $wpdb;
		
		$competitors = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}aebg_competitors WHERE is_active = 1 ORDER BY name ASC",
			ARRAY_A
		);
		
		foreach ( $competitors as &$competitor ) {
			if ( ! empty( $competitor['extraction_config'] ) ) {
				$competitor['extraction_config'] = json_decode( $competitor['extraction_config'], true );
			}
		}
		
		return $competitors;
	}
	
	/**
	 * Trigger manual scrape (immediate, bypasses active check)
	 * 
	 * @param int $competitor_id Competitor ID
	 * @return int|false Action ID or false on failure
	 */
	public static function trigger_manual_scrape( $competitor_id ) {
		// Get competitor record
		$competitor = self::get_competitor( $competitor_id );
		if ( ! $competitor ) {
			Logger::error( 'Cannot trigger scrape: competitor not found', [ 'competitor_id' => $competitor_id ] );
			return false;
		}
		
		// Ensure Action Scheduler is initialized
		if ( ! ActionSchedulerHelper::ensure_initialized() ) {
			Logger::error( 'Action Scheduler not initialized for manual scrape', [
				'competitor_id' => $competitor_id,
			] );
			return false;
		}
		
		$hook = 'aebg_competitor_scrape';
		$args = [ 'competitor_id' => $competitor_id ];
		$group = 'aebg_competitor_tracking_' . $competitor_id;
		
		// Check if Action Scheduler functions are available
		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::error( 'Action Scheduler functions not available', [
				'competitor_id' => $competitor_id,
				'as_enqueue_async_action_exists' => function_exists( 'as_enqueue_async_action' ),
				'as_schedule_single_action_exists' => function_exists( 'as_schedule_single_action' ),
			] );
			return false;
		}
		
		// Cancel any existing action for this competitor first (when unique=true, existing actions prevent new ones)
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( $hook, $args, $group );
		}
		
		// For manual triggers, use immediate action (delay = 0)
		// Try as_enqueue_async_action first for immediate execution
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( $hook, $args, $group, true );
		} else {
			// Fallback to scheduled action with minimal delay
			$action_id = as_schedule_single_action( time() + 1, $hook, $args, $group, true );
		}
		
		if ( $action_id > 0 ) {
			Logger::info( 'Manual competitor scrape triggered', [
				'competitor_id' => $competitor_id,
				'action_id'     => $action_id,
				'method'        => function_exists( 'as_enqueue_async_action' ) ? 'async' : 'scheduled',
			] );
		} elseif ( $action_id === 0 ) {
			// Action Scheduler returns 0 when unique=true and action already exists
			// This shouldn't happen after unscheduling, but handle it gracefully
			Logger::warning( 'Manual scrape action already exists (returned 0)', [
				'competitor_id' => $competitor_id,
				'hook'          => $hook,
				'group'         => $group,
			] );
			// Return false to indicate failure
			return false;
		} else {
			Logger::error( 'Failed to trigger manual competitor scrape', [
				'competitor_id' => $competitor_id,
				'action_id'     => $action_id,
				'hook'          => $hook,
				'group'         => $group,
				'as_enqueue_async_action_exists' => function_exists( 'as_enqueue_async_action' ),
				'as_schedule_single_action_exists' => function_exists( 'as_schedule_single_action' ),
				'action_scheduler_initialized' => ActionSchedulerHelper::ensure_initialized(),
			] );
		}
		
		return $action_id;
	}
	
	/**
	 * Schedule scrape for competitor
	 * 
	 * @param int $competitor_id Competitor ID
	 * @param int $delay         Delay in seconds (0 = use competitor's interval)
	 * @return int|false Action ID or false on failure
	 */
	public static function schedule_scrape( $competitor_id, $delay = 0 ) {
		// Get competitor record to retrieve their custom scraping interval
		$competitor = self::get_competitor( $competitor_id );
		if ( ! $competitor || ! $competitor['is_active'] ) {
			return false;
		}
		
		// Use the competitor's configured scraping interval (in seconds)
		$interval = (int) $competitor['scraping_interval'];
		
		// If delay is provided (e.g., for immediate scrape), use that instead
		$delay_seconds = $delay > 0 ? $delay : $interval;
		
		$hook = 'aebg_competitor_scrape';
		$args = [ 'competitor_id' => $competitor_id ];
		$group = 'aebg_competitor_tracking_' . $competitor_id;
		
		// Calculate next scrape time
		$next_scrape_at = date( 'Y-m-d H:i:s', time() + $delay_seconds );
		
		// Update competitor record with next scrape time
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'aebg_competitors',
			[ 'next_scrape_at' => $next_scrape_at ],
			[ 'id' => $competitor_id ],
			[ '%s' ],
			[ '%d' ]
		);
		
		// Schedule the action with the competitor's custom interval
		$action_id = ActionSchedulerHelper::schedule_action(
			$hook,
			$args,
			$group,
			$delay_seconds, // Uses competitor's custom interval
			true // Unique - cancels existing action for this competitor
		);
		
		if ( $action_id > 0 ) {
			Logger::debug( 'Competitor scrape scheduled', [
				'competitor_id' => $competitor_id,
				'action_id'     => $action_id,
				'interval'      => $delay_seconds,
				'next_scrape_at' => $next_scrape_at,
			] );
		}
		
		return $action_id;
	}
	
	/**
	 * Process competitor scrape (Action Scheduler hook)
	 * 
	 * @param int $competitor_id Competitor ID
	 */
	public static function process_scrape( $competitor_id ) {
		Logger::info( 'Starting competitor scrape', [ 'competitor_id' => $competitor_id ] );
		
		// CRITICAL: Set execution flags FIRST (mirrors ActionHandler pattern)
		// This prevents Action Scheduler from killing the action during execution
		self::$is_scraping = true;
		$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = true;
		
		// CRITICAL: Set PHP timeout to prevent server from killing the process
		// Action Scheduler's failure_period defaults to 300s, but we need 1800s (30 minutes)
		// This matches the bulk generation timeout management
		$current_php_timeout = ini_get( 'max_execution_time' );
		$target_timeout = 1800; // 30 minutes (same as bulk generation)
		
		if ( $current_php_timeout != $target_timeout ) {
			@set_time_limit( $target_timeout );
			Logger::debug( 'Set PHP timeout for competitor scraping', [
				'old_timeout' => $current_php_timeout,
				'new_timeout' => $target_timeout,
			] );
		}
		
		$start_time = microtime( true );
		
		try {
			// 1. Get competitor config
			$competitor = self::get_competitor( $competitor_id );
			if ( ! $competitor || ! $competitor['is_active'] ) {
				Logger::warning( 'Competitor not found or inactive', [ 'competitor_id' => $competitor_id ] );
				self::$is_scraping = false;
				$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = false;
				return;
			}
			
			// 2. Create scrape record
			$scrape_id = self::create_scrape_record( $competitor_id, [ 'status' => 'processing' ] );
			
			// 3. Scrape URL
			$scraper = new CompetitorScraper();
			$scraped_data = $scraper->scrape( $competitor['url'] );
			
			if ( is_wp_error( $scraped_data ) ) {
				self::$is_scraping = false;
				$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = false;
				self::handle_scrape_error( $competitor_id, $scrape_id, $scraped_data->get_error_message() );
				return;
			}
			
			// 4. Update scrape record with HTML
			self::update_scrape_record( $scrape_id, [
				'scraped_html'    => $scraped_data['html'],
				'scraped_content' => $scraped_data['content'],
			] );
			
			// 5. Analyze with AI
			// CRITICAL: Ensure flag stays set during AI analysis (can take 30-60 seconds)
			// The flag is already set in the action hook wrapper, but we re-assert it here
			// to ensure it's definitely set during the long-running AI analysis
			$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = true;
			
			Logger::info( 'Starting AI analysis - flag is set', [
				'competitor_id' => $competitor_id,
				'flag_set' => isset( $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) && $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'],
			] );
			
			$analyzer = new CompetitorAnalyzer();
			$analysis_config = $competitor['extraction_config'] ?? [];
			// Add custom prompt template if available
			if ( ! empty( $competitor['ai_prompt_template'] ) ) {
				$analysis_config['ai_prompt_template'] = $competitor['ai_prompt_template'];
			}
			
			// CRITICAL: This is a BLOCKING call - it will wait for the API response
			// APIClient::makeRequest() is synchronous and will not return until the API call completes
			// The action callback will NOT return until this completes
			$products = $analyzer->analyze( $scraped_data['content'], $analysis_config );
			
			if ( is_wp_error( $products ) ) {
				$error_code = $products->get_error_code();
				$error_message = $products->get_error_message();
				
				Logger::error( 'AI analysis returned error', [
					'competitor_id' => $competitor_id,
					'scrape_id' => $scrape_id,
					'error_code' => $error_code,
					'error_message' => $error_message,
					'flag_still_set' => isset( $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) && $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'],
				] );
				
				// Also log to error_log for immediate visibility
				error_log( sprintf(
					'[AEBG] CompetitorTrackingManager::process_scrape() - AI analysis failed | competitor_id=%d | scrape_id=%d | error_code=%s | error_message=%s',
					$competitor_id,
					$scrape_id,
					$error_code,
					$error_message
				) );
				
				self::$is_scraping = false;
				$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = false;
				self::handle_analysis_error( $scrape_id, $error_message );
				return;
			}
			
			Logger::info( 'AI analysis completed successfully', [
				'competitor_id' => $competitor_id,
				'scrape_id' => $scrape_id,
				'products_count' => count( $products ),
				'flag_still_set' => isset( $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) && $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'],
			] );
			
			// Store AI analysis result
			self::update_scrape_record( $scrape_id, [
				'ai_analysis' => wp_json_encode( $products ),
			] );
			
			// 6. Save products and positions
			self::save_products( $scrape_id, $competitor_id, $products );
			
			// 7. Detect changes
			$previous_scrape_id = self::get_previous_scrape_id( $competitor_id, $scrape_id );
			if ( $previous_scrape_id ) {
				$tracker = new PositionTracker();
				$changes = $tracker->detect_changes( $competitor_id, $scrape_id, $previous_scrape_id );
				$tracker->save_changes( $competitor_id, $scrape_id, $changes );
			}
			
			// 8. Update competitor record
			$processing_time = microtime( true ) - $start_time;
			self::update_competitor_after_scrape( $competitor_id, $scrape_id, $processing_time, count( $products ) );
			
			// 9. Mark scrape as completed
			self::update_scrape_record( $scrape_id, [
				'status'          => 'completed',
				'product_count'   => count( $products ),
				'processing_time' => round( $processing_time, 3 ),
				'completed_at'    => current_time( 'mysql' ),
			] );
			
			// 10. Schedule next scrape
			self::schedule_next_scrape( $competitor_id );
			
			Logger::info( 'Competitor scrape completed', [
				'competitor_id' => $competitor_id,
				'scrape_id'     => $scrape_id,
				'products_found' => count( $products ),
				'processing_time' => round( $processing_time, 2 ),
			] );
			
		} catch ( \Exception $e ) {
			
			Logger::error( 'Competitor scrape failed', [
				'competitor_id' => $competitor_id,
				'error'        => $e->getMessage(),
				'trace'        => $e->getTraceAsString(),
			] );
			self::handle_scrape_error( $competitor_id, $scrape_id ?? 0, $e->getMessage() );
		} finally {
			// CRITICAL: Always clear execution flags (mirrors ActionHandler pattern)
			// This ensures flags are cleared even if an exception occurs
			self::$is_scraping = false;
			$GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] = false;
			
			Logger::debug( 'Competitor scrape flags cleared', [
				'competitor_id' => $competitor_id,
			] );
		}
	}
	
	/**
	 * Schedule next scrape after completion
	 * 
	 * @param int $competitor_id Competitor ID
	 * @return int|false Action ID or false
	 */
	public static function schedule_next_scrape( $competitor_id ) {
		return self::schedule_scrape( $competitor_id, 0 ); // 0 = use competitor's interval
	}
	
	/**
	 * Schedule active competitors on init
	 */
	public static function schedule_active_competitors() {
		$competitors = self::get_active_competitors();
		
		foreach ( $competitors as $competitor ) {
			// Check if already scheduled
			$group = 'aebg_competitor_tracking_' . $competitor['id'];
			if ( ActionSchedulerHelper::ensure_initialized() ) {
				$actions = ActionSchedulerHelper::get_scheduled_actions( [
					'hook'   => 'aebg_competitor_scrape',
					'group'  => $group,
					'status' => 'pending',
				] );
				
				if ( ! empty( $actions ) ) {
					continue; // Already scheduled
				}
			}
			
			// Schedule if next_scrape_at is in the past or null
			if ( empty( $competitor['next_scrape_at'] ) || strtotime( $competitor['next_scrape_at'] ) <= time() ) {
				self::schedule_scrape( $competitor['id'], 0 );
			}
		}
	}
	
	/**
	 * Create scrape record
	 * 
	 * @param int   $competitor_id Competitor ID
	 * @param array $data          Initial data
	 * @return int Scrape ID
	 */
	private static function create_scrape_record( $competitor_id, $data = [] ) {
		global $wpdb;
		
		$insert_data = array_merge( [
			'competitor_id' => $competitor_id,
			'status'       => 'pending',
			'created_at'   => current_time( 'mysql' ),
		], $data );
		
		$wpdb->insert(
			$wpdb->prefix . 'aebg_competitor_scrapes',
			$insert_data,
			[ '%d', '%s', '%s' ]
		);
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update scrape record
	 * 
	 * @param int   $scrape_id Scrape ID
	 * @param array $data      Data to update
	 */
	private static function update_scrape_record( $scrape_id, $data ) {
		global $wpdb;
		
		// Prepare format array for wpdb->update
		$format = [];
		foreach ( $data as $key => $value ) {
			if ( $key === 'ai_analysis' ) {
				$format[] = '%s'; // JSON string
			} elseif ( $key === 'scraped_html' || $key === 'scraped_content' ) {
				$format[] = '%s'; // Text
			} elseif ( $key === 'status' || $key === 'error_message' ) {
				$format[] = '%s'; // String
			} elseif ( $key === 'product_count' ) {
				$format[] = '%d'; // Integer
			} elseif ( $key === 'processing_time' ) {
				$format[] = '%f'; // Decimal
			} elseif ( $key === 'completed_at' ) {
				$format[] = '%s'; // DateTime
			} else {
				$format[] = '%s'; // Default to string
			}
		}
		
		$wpdb->update(
			$wpdb->prefix . 'aebg_competitor_scrapes',
			$data,
			[ 'id' => $scrape_id ],
			$format,
			[ '%d' ]
		);
	}
	
	/**
	 * Save products from scrape
	 * 
	 * @param int   $scrape_id    Scrape ID
	 * @param int   $competitor_id Competitor ID
	 * @param array $products     Products array
	 */
	private static function save_products( $scrape_id, $competitor_id, $products ) {
		global $wpdb;
		
		// Get previous scrape products for comparison
		$previous_scrape_id = self::get_previous_scrape_id( $competitor_id, $scrape_id );
		$previous_products = [];
		
		if ( $previous_scrape_id ) {
			$previous_products_raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT product_name, position FROM {$wpdb->prefix}aebg_competitor_products 
				WHERE competitor_id = %d AND scrape_id = %d ORDER BY position ASC",
				$competitor_id,
				$previous_scrape_id
			), ARRAY_A );
			
			foreach ( $previous_products_raw as $prev ) {
				$previous_products[ $prev['product_name'] ] = (int) $prev['position'];
			}
		}
		
		// Insert products
		foreach ( $products as $index => $product ) {
			$position = $index + 1;
			$product_name = sanitize_text_field( $product['name'] ?? '' );
			$product_url = ! empty( $product['url'] ) ? esc_url_raw( $product['url'] ) : null;
			
			$previous_position = $previous_products[ $product_name ] ?? null;
			$position_change = $previous_position ? ( $previous_position - $position ) : null;
			$is_new = ! isset( $previous_products[ $product_name ] );
			
			$product_data = [];
			if ( isset( $product['metadata'] ) ) {
				$product_data = $product['metadata'];
			}
			
			$wpdb->insert(
				$wpdb->prefix . 'aebg_competitor_products',
				[
					'competitor_id'     => $competitor_id,
					'scrape_id'         => $scrape_id,
					'product_name'      => $product_name,
					'product_url'       => $product_url,
					'position'          => $position,
					'previous_position' => $previous_position,
					'position_change'   => $position_change,
					'is_new'            => $is_new ? 1 : 0,
					'product_data'      => ! empty( $product_data ) ? wp_json_encode( $product_data ) : null,
					'extracted_at'      => current_time( 'mysql' ),
				],
				[ '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
			);
		}
		
		// Mark removed products
		$current_product_names = array_column( $products, 'name' );
		foreach ( $previous_products as $product_name => $position ) {
			if ( ! in_array( $product_name, $current_product_names, true ) ) {
				// Product was removed - mark it in the previous scrape
				$wpdb->update(
					$wpdb->prefix . 'aebg_competitor_products',
					[ 'is_removed' => 1 ],
					[
						'competitor_id' => $competitor_id,
						'scrape_id'      => $previous_scrape_id,
						'product_name'   => $product_name,
					],
					[ '%d' ],
					[ '%d', '%d', '%s' ]
				);
			}
		}
	}
	
	/**
	 * Get previous scrape ID
	 * 
	 * @param int $competitor_id Competitor ID
	 * @param int $current_scrape_id Current scrape ID
	 * @return int|null Previous scrape ID or null
	 */
	private static function get_previous_scrape_id( $competitor_id, $current_scrape_id ) {
		global $wpdb;
		
		$previous = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d AND id < %d AND status = 'completed' 
			ORDER BY id DESC LIMIT 1",
			$competitor_id,
			$current_scrape_id
		) );
		
		return $previous ? (int) $previous : null;
	}
	
	/**
	 * Update competitor after successful scrape
	 * 
	 * @param int    $competitor_id   Competitor ID
	 * @param int    $scrape_id        Scrape ID
	 * @param float  $processing_time Processing time in seconds
	 * @param int    $product_count   Number of products found
	 */
	private static function update_competitor_after_scrape( $competitor_id, $scrape_id, $processing_time, $product_count ) {
		global $wpdb;
		
		$wpdb->update(
			$wpdb->prefix . 'aebg_competitors',
			[
				'last_scraped_at' => current_time( 'mysql' ),
				'scrape_count'    => $wpdb->get_var( $wpdb->prepare(
					"SELECT scrape_count FROM {$wpdb->prefix}aebg_competitors WHERE id = %d",
					$competitor_id
				) ) + 1,
				'error_count'     => 0, // Reset error count on success
				'last_error'      => null,
			],
			[ 'id' => $competitor_id ],
			[ '%s', '%d', '%d', '%s' ],
			[ '%d' ]
		);
	}
	
	/**
	 * Handle scrape error
	 * 
	 * @param int    $competitor_id Competitor ID
	 * @param int    $scrape_id     Scrape ID
	 * @param string $error_message Error message
	 */
	private static function handle_scrape_error( $competitor_id, $scrape_id, $error_message ) {
		global $wpdb;
		
		// Update scrape record
		if ( $scrape_id > 0 ) {
			$wpdb->update(
				$wpdb->prefix . 'aebg_competitor_scrapes',
				[
					'status'        => 'failed',
					'error_message' => $error_message,
					'completed_at' => current_time( 'mysql' ),
				],
				[ 'id' => $scrape_id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}
		
		// Update competitor error count
		$error_count = $wpdb->get_var( $wpdb->prepare(
			"SELECT error_count FROM {$wpdb->prefix}aebg_competitors WHERE id = %d",
			$competitor_id
		) ) + 1;
		
		$wpdb->update(
			$wpdb->prefix . 'aebg_competitors',
			[
				'error_count' => $error_count,
				'last_error'  => $error_message,
			],
			[ 'id' => $competitor_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		
		// Reschedule with exponential backoff (max 24 hours)
		$backoff_delay = min( 3600 * pow( 2, $error_count - 1 ), 86400 );
		self::schedule_scrape( $competitor_id, $backoff_delay );
		
		Logger::error( 'Competitor scrape failed', [
			'competitor_id' => $competitor_id,
			'scrape_id'     => $scrape_id,
			'error'         => $error_message,
			'error_count'   => $error_count,
			'retry_in'      => $backoff_delay,
		] );
	}
	
	/**
	 * Handle analysis error
	 * 
	 * @param int    $scrape_id     Scrape ID
	 * @param string $error_message Error message
	 */
	private static function handle_analysis_error( $scrape_id, $error_message ) {
		global $wpdb;
		
		$wpdb->update(
			$wpdb->prefix . 'aebg_competitor_scrapes',
			[
				'status'        => 'failed',
				'error_message' => $error_message,
				'completed_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $scrape_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}
	
	/**
	 * Get competitor statistics
	 * 
	 * @param int $competitor_id Competitor ID
	 * @return array Statistics
	 */
	public static function get_competitor_stats( $competitor_id ) {
		global $wpdb;
		
		$stats = [
			'total_scrapes'    => 0,
			'successful_scrapes' => 0,
			'failed_scrapes'    => 0,
			'total_products'    => 0,
			'last_scrape'       => null,
			'next_scrape'       => null,
		];
		
		$competitor = self::get_competitor( $competitor_id );
		if ( ! $competitor ) {
			return $stats;
		}
		
		$stats['total_scrapes'] = (int) $competitor['scrape_count'];
		$stats['last_scrape'] = $competitor['last_scraped_at'];
		$stats['next_scrape'] = $competitor['next_scrape_at'];
		
		// Get scrape statistics
		$scrape_stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT 
				COUNT(*) as total,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
			FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d",
			$competitor_id
		), ARRAY_A );
		
		if ( $scrape_stats ) {
			$stats['successful_scrapes'] = (int) $scrape_stats['successful'];
			$stats['failed_scrapes'] = (int) $scrape_stats['failed'];
		}
		
		// Get total products from latest scrape
		$latest_scrape = $wpdb->get_var( $wpdb->prepare(
			"SELECT product_count FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d AND status = 'completed' 
			ORDER BY id DESC LIMIT 1",
			$competitor_id
		) );
		
		$stats['total_products'] = $latest_scrape ? (int) $latest_scrape : 0;
		
		return $stats;
	}
}

