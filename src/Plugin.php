<?php

namespace AEBG;

/**
 * Main Plugin Class
 *
 * @package AEBG
 */
class Plugin {
	/**
	 * The single instance of the class.
	 *
	 * @var Plugin
	 */
	private static $_instance = null;
	
	/**
	 * Track if classes have been initialized to prevent duplicate initialization
	 *
	 * @var bool
	 */
	private static $_classes_initialized = false;

	/**
	 * Cache previous featured image IDs for posts whose products were modified
	 * during the current request. This allows us to restore the featured image
	 * if an external process clears it when products are replaced/added/removed.
	 *
	 * @var array<int,int>
	 */
	private $previous_thumbnails = [];

	/**
	 * Main Plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Plugin constructor.
	 */
	private function __construct() {
		$this->define_constants();
		$this->init_hooks();
	}

	/**
	 * Define constants.
	 */
	private function define_constants() {
		// Constants are already defined in the main plugin file
		// This method is kept for compatibility but doesn't redefine constants
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		register_activation_hook( AEBG_PLUGIN_DIR . 'ai-bulk-generator-for-elementor.php', [ $this, 'activate' ] );
		register_deactivation_hook( AEBG_PLUGIN_DIR . 'ai-bulk-generator-for-elementor.php', [ $this, 'deactivate' ] );
		
		// CRITICAL: Register global exception handler to catch Action Scheduler errors when actions are deleted
		// This prevents fatal errors when Action Scheduler tries to mark a deleted action as failed
		set_exception_handler( [ $this, 'handle_uncaught_exception' ] );
		
		// Load Action Scheduler early
		add_action( 'plugins_loaded', [ $this, 'init_action_scheduler' ], 5 );
		add_action( 'init', [ $this, 'init_classes' ] );
		
		// CRITICAL: Suppress Action Scheduler initialization warnings for internal cleanup code
		// Action Scheduler's WPCommentCleaner may call as_next_scheduled_action() during init
		// before the data store is ready. We suppress these specific warnings since they're
		// from Action Scheduler's own internal code and are handled gracefully (returns false).
		// Note: This filter was added in WordPress 5.1.0
		// Register early (before plugins_loaded) to catch all warnings
		add_filter( 'doing_it_wrong_trigger_error', [ $this, 'suppress_action_scheduler_init_warnings' ], 10, 3 );
		
		// Increase action scheduler timeout for AI generation tasks (must be added early)
		add_filter( 'action_scheduler_queue_runner_time_limit', [ $this, 'increase_action_scheduler_timeout' ], 10, 1 );
		add_filter( 'action_scheduler_timeout_period', [ $this, 'increase_action_scheduler_timeout_period' ], 10, 1 );
		add_filter( 'action_scheduler_failure_period', [ $this, 'increase_action_scheduler_failure_period' ], 10, 1 );
		
		// CRITICAL: Limit Action Scheduler to 1 concurrent batch to prevent concurrent generations
		// This ensures only one generation action runs at a time, even if Action Scheduler tries to run multiple
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', [ $this, 'limit_concurrent_batches' ], 10, 1 );
		
		// CRITICAL: Limit batch size to 1 action per batch to ensure process isolation
		// This forces Action Scheduler to process only one action per batch, then make async loopback
		// request for next action, which runs in separate PHP process (separate HTTP request = separate process)
		add_filter( 'action_scheduler_queue_runner_batch_size', [ $this, 'limit_batch_size' ], 10, 1 );
		
		// CRITICAL: Force Action Scheduler to exit after processing 1 action to ensure process isolation
		// Action Scheduler's queue runner has a do...while loop that processes multiple batches in same process
		// We use the time limit filter to force exit after 1 action, triggering async loopback
		add_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', [ $this, 'force_exit_after_one_action' ], 10, 5 );
		
		// CRITICAL: Allow Action Scheduler async requests in non-admin contexts (WP-Cron, frontend)
		// By default, Action Scheduler only allows async requests in admin context (is_admin() check)
		// This filter allows async requests from any context, enabling true async execution
		// Each async request = separate HTTP request = separate PHP process = fresh 180s timer
		add_filter( 'action_scheduler_allow_async_request_runner', [ $this, 'allow_async_requests_in_all_contexts' ], 10, 1 );
		
		// Migration controller is now initialized in init_action_scheduler() method
		// No need for additional migration fixes
		
		// Add admin menu for debugging Action Scheduler
		add_action( 'admin_menu', [ $this, 'add_action_scheduler_debug_menu' ] );
		
		// Add AJAX handlers for debug page
		add_action( 'wp_ajax_trigger_action_scheduler_migration', [ $this, 'ajax_trigger_action_scheduler_migration' ] );
		add_action( 'wp_ajax_delete_action_scheduler_action', [ $this, 'ajax_delete_action_scheduler_action' ] );
		add_action( 'wp_ajax_retry_stuck_item', [ $this, 'ajax_retry_stuck_item' ] );
		
		// Add AJAX handler for async Action Scheduler trigger (authenticated users only for security)
		add_action( 'wp_ajax_aebg_trigger_action_scheduler', [ $this, 'ajax_trigger_action_scheduler' ] );
		
		// Add WP Cron hook for triggering Action Scheduler (most reliable method)
		add_action( 'aebg_trigger_action_scheduler_queue', [ $this, 'trigger_action_scheduler_queue' ] );
		
		// Add cleanup hooks (runs daily/weekly)
		add_action( 'aebg_cleanup_orphaned_steps', [ $this, 'cleanup_orphaned_steps' ] );
		add_action( 'aebg_cleanup_old_log_messages', [ $this, 'cleanup_old_log_messages' ] );
		add_action( 'aebg_cleanup_completed_checkpoints', [ $this, 'cleanup_completed_checkpoints' ] );
		add_action( 'aebg_archive_old_batch_items', [ $this, 'archive_old_batch_items' ] );
		add_action( 'aebg_cleanup_action_scheduler_logs', [ $this, 'cleanup_action_scheduler_logs' ] );
		add_action( 'aebg_cleanup_action_scheduler_actions', [ $this, 'cleanup_action_scheduler_actions' ] );
		add_action( 'aebg_cleanup_old_trustpilot_cache', [ $this, 'cleanup_old_trustpilot_cache' ] );
		add_action( 'aebg_cleanup_old_competitor_scrapes', [ $this, 'cleanup_old_competitor_scrapes' ] );
		add_action( 'aebg_log_database_size', [ $this, 'log_database_size' ] );
		add_action( 'aebg_cleanup_product_replacement_checkpoints', [ $this, 'cleanup_product_replacement_checkpoints' ] );

		// Preserve featured images when AEBG product metadata changes.
		// This prevents losing the post's featured image when products are
		// replaced/added/removed via the product management UI.
		add_action( 'updated_postmeta', [ $this, 'track_thumbnail_before_product_meta_change' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'track_thumbnail_before_product_meta_change' ], 10, 4 );
		
		// Schedule cleanup routines if not already scheduled
		if ( ! wp_next_scheduled( 'aebg_cleanup_orphaned_steps' ) ) {
			wp_schedule_event( time(), 'daily', 'aebg_cleanup_orphaned_steps' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_old_log_messages' ) ) {
			wp_schedule_event( time(), 'daily', 'aebg_cleanup_old_log_messages' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_completed_checkpoints' ) ) {
			wp_schedule_event( time(), 'daily', 'aebg_cleanup_completed_checkpoints' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_product_replacement_checkpoints' ) ) {
			wp_schedule_event( time(), 'daily', 'aebg_cleanup_product_replacement_checkpoints' );
		}
		if ( ! wp_next_scheduled( 'aebg_archive_old_batch_items' ) ) {
			wp_schedule_event( time(), 'weekly', 'aebg_archive_old_batch_items' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_action_scheduler_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'aebg_cleanup_action_scheduler_logs' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_action_scheduler_actions' ) ) {
			wp_schedule_event( time(), 'daily', 'aebg_cleanup_action_scheduler_actions' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_old_trustpilot_cache' ) ) {
			wp_schedule_event( time(), 'weekly', 'aebg_cleanup_old_trustpilot_cache' );
		}
		if ( ! wp_next_scheduled( 'aebg_cleanup_old_competitor_scrapes' ) ) {
			wp_schedule_event( time(), 'weekly', 'aebg_cleanup_old_competitor_scrapes' );
		}
		if ( ! wp_next_scheduled( 'aebg_log_database_size' ) ) {
			wp_schedule_event( time(), 'weekly', 'aebg_log_database_size' );
		}
	}

	/**
	 * Track the current featured image for posts whose AEBG product meta changes.
	 * This is called when product-related post meta is added or updated.
	 *
	 * We don't make any changes here; we only remember the featured image so it
	 * can be restored at the very end of the request if something clears it.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public function track_thumbnail_before_product_meta_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		// Only care about this plugin's product meta keys.
		$watched_keys = [
			'_aebg_products',
			'_aebg_product_ids',
			'_aebg_product_count',
		];

		if ( ! in_array( $meta_key, $watched_keys, true ) ) {
			return;
		}

		// Get the current featured image for this post.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			// Nothing to preserve.
			return;
		}

		// Cache the current thumbnail so we can restore it later if needed.
		$this->previous_thumbnails[ $post_id ] = (int) $thumbnail_id;

		// Ensure our shutdown handler is registered once per request.
		if ( ! has_action( 'shutdown', [ $this, 'maybe_restore_featured_image_after_products_change' ] ) ) {
			add_action( 'shutdown', [ $this, 'maybe_restore_featured_image_after_products_change' ] );
		}
	}

	/**
	 * At the end of the request, restore the featured image if it was cleared
	 * for any posts whose AEBG product meta changed.
	 *
	 * This ensures that product replacement/add/remove flows do not silently
	 * remove an existing featured image unless a new one has been set.
	 */
	public function maybe_restore_featured_image_after_products_change() {
		if ( empty( $this->previous_thumbnails ) || ! is_array( $this->previous_thumbnails ) ) {
			return;
		}

		foreach ( $this->previous_thumbnails as $post_id => $prev_thumbnail_id ) {
			$prev_thumbnail_id = (int) $prev_thumbnail_id;
			if ( $prev_thumbnail_id <= 0 ) {
				continue;
			}

			$current_thumbnail_id = get_post_thumbnail_id( $post_id );

			// If the featured image is now missing but we had one before,
			// restore the previous thumbnail.
			if ( ! $current_thumbnail_id ) {
				set_post_thumbnail( $post_id, $prev_thumbnail_id );
			}
		}
	}
	
	/**
	 * Limit Action Scheduler to 1 concurrent batch
	 * This ensures only one generation action runs at a time
	 * 
	 * @param int $concurrent_batches Current concurrent batch limit
	 * @return int Always return 1 to enforce sequential processing
	 */
	public function limit_concurrent_batches( $concurrent_batches ) {
		// Force sequential processing - only 1 batch at a time
		// This works in conjunction with our MySQL lock to prevent concurrent generations
		return 1;
	}

	/**
	 * Limit Action Scheduler batch size to 1 action per batch
	 * This ensures each action runs in a separate PHP process via async loopback requests
	 * 
	 * According to Action Scheduler docs:
	 * - Default batch size is 25 actions per batch
	 * - Actions within a batch are processed sequentially in the same PHP process
	 * - When a batch completes, Action Scheduler makes an async loopback request for next batch
	 * - By limiting batch size to 1, each action triggers an async loopback request
	 * - Each async loopback request = separate HTTP request = separate PHP process
	 * 
	 * This provides guaranteed process isolation:
	 * - Each action runs in separate PHP process
	 * - Fresh HTTP connections (no stale connection pool)
	 * - Fresh memory state (no shared globals)
	 * - No 300-second timeout issues
	 * 
	 * @param int $batch_size Current batch size (default 25)
	 * @return int Always return 1 to ensure process isolation
	 */
	public function limit_batch_size( $batch_size ) {
		// Force batch size to 1 action per batch
		// This ensures each action runs in separate PHP process via async loopback requests
		return 1;
	}

	/**
	 * Force Action Scheduler to exit after processing 1 action to ensure process isolation
	 * 
	 * Action Scheduler's queue runner has a do...while loop that processes multiple batches
	 * in the same PHP process. Even with batch_size = 1, it will process multiple actions
	 * sequentially because the loop continues until batch_limits_exceeded() returns true.
	 * 
	 * By making the time limit check return true after 1 action, we force the loop to exit,
	 * which causes Action Scheduler to make an async loopback request for the next action.
	 * 
	 * This ensures:
	 * - Only 1 action processes per PHP process
	 * - Action Scheduler makes async loopback request for next action
	 * - Next action runs in separate PHP process (separate HTTP request = separate process)
	 * 
	 * @param bool $likely_to_be_exceeded Whether time limit is likely to be exceeded (from Action Scheduler)
	 * @param ActionScheduler_Abstract_QueueRunner $runner The queue runner instance
	 * @param int $processed_actions Number of actions processed so far
	 * @param float $execution_time Execution time so far
	 * @param int $max_execution_time Maximum execution time allowed
	 * @return bool True to force exit after 1 action, false otherwise
	 */
	public function force_exit_after_one_action( $likely_to_be_exceeded, $runner, $processed_actions, $execution_time, $max_execution_time ) {
		// CRITICAL: If this is a competitor scraping action, DO NOT APPLY THIS FILTER AT ALL
		// Competitor tracking is completely separate and should not be affected by bulk generation logic
		
		global $wpdb;
		$action_table = $wpdb->prefix . 'actionscheduler_actions';
		
		// Check 1: Static property (most reliable, mirrors ActionHandler pattern)
		if ( class_exists( '\AEBG\Core\CompetitorTrackingManager' ) ) {
			$is_scraping = \AEBG\Core\CompetitorTrackingManager::is_scraping();
			if ( $is_scraping ) {
				error_log( sprintf(
					'[AEBG] 🔍 FILTER: force_exit_after_one_action | Competitor scraping detected (static property) - FILTER DISABLED | execution_time=%.2fs | processed_actions=%d',
					$execution_time,
					$processed_actions
				) );
				return false; // Don't apply filter to competitor scraping
			}
		}
		
		// Check 1b: Global flag (backup check)
		if ( isset( $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) && $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) {
			error_log( sprintf(
				'[AEBG] 🔍 FILTER: force_exit_after_one_action | Competitor scraping detected (global flag) - FILTER DISABLED | execution_time=%.2fs | processed_actions=%d',
				$execution_time,
				$processed_actions
			) );
			return false; // Don't apply filter to competitor scraping
		}
		
		// Check 2: Check if the most recent action (by action_id) is a competitor scraping action
		// IGNORE STATUS - Action Scheduler marks actions complete too early
		// If the most recent competitor action is within the last 30 actions, it's VERY recent
		// AI analysis can take 30-60 seconds, so we need a wide window
		$most_recent_competitor = $wpdb->get_row(
			"SELECT hook, status, action_id 
			FROM {$action_table} 
			WHERE hook = 'aebg_competitor_scrape'
			ORDER BY action_id DESC
			LIMIT 1",
			ARRAY_A
		);
		
		if ( $most_recent_competitor && isset( $most_recent_competitor['hook'] ) ) {
			$competitor_action_id = (int) ( $most_recent_competitor['action_id'] ?? 0 );
			
			// Get the highest action_id to see if this is very recent
			$max_action_id = (int) $wpdb->get_var( "SELECT MAX(action_id) FROM {$action_table}" );
			
			// If this competitor action is within the last 30 actions, it's VERY recent
			// This catches actions that just started, even if status is already "complete"
			// Action Scheduler marks complete too early, so we ignore status and check by ID
			$is_very_recent = ( $max_action_id && ( $max_action_id - $competitor_action_id ) < 30 );
			
			if ( $is_very_recent ) {
				error_log( sprintf(
					'[AEBG] 🔍 FILTER: force_exit_after_one_action | Very recent competitor scraping action (id: %d, max_id: %d, diff: %d, status: %s) - FILTER DISABLED | execution_time=%.2fs | processed_actions=%d',
					$competitor_action_id,
					$max_action_id,
					$max_action_id - $competitor_action_id,
					$most_recent_competitor['status'] ?? 'unknown',
					$execution_time,
					$processed_actions
				) );
				return false; // Don't apply filter to competitor scraping
			}
		}
		
		// Check 3: Database check for currently running competitor scraping actions
		$running_competitor_action = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$action_table} 
			WHERE hook = 'aebg_competitor_scrape' 
			AND status IN ('in-progress', 'running')
			LIMIT 1"
		);
		if ( $running_competitor_action > 0 ) {
			error_log( sprintf(
				'[AEBG] 🔍 FILTER: force_exit_after_one_action | Competitor scraping action running (DB check) - FILTER DISABLED | execution_time=%.2fs | processed_actions=%d',
				$execution_time,
				$processed_actions
			) );
			return false; // Don't apply filter to competitor scraping
		}
		
		// Check 4: Check batch for competitor scraping hook
		if ( method_exists( $runner, 'get_batch' ) ) {
			$batch = $runner->get_batch();
			if ( is_array( $batch ) && ! empty( $batch ) ) {
				foreach ( $batch as $action ) {
					if ( is_object( $action ) && method_exists( $action, 'get_hook' ) ) {
						$hook = $action->get_hook();
						if ( $hook === 'aebg_competitor_scrape' ) {
							error_log( sprintf(
								'[AEBG] 🔍 FILTER: force_exit_after_one_action | Competitor scraping action in batch - FILTER DISABLED | execution_time=%.2fs | processed_actions=%d',
								$execution_time,
								$processed_actions
							) );
							return false; // Don't apply filter to competitor scraping
						}
					}
				}
			}
		}
		
		// If we get here, this is NOT a competitor scraping action
		// Continue with normal bulk generation filter logic
		
		// CRITICAL: Check if action is currently executing
		// If an action is executing, NEVER return true (don't kill it)
		// IMPORTANT: This only applies to bulk generation actions now
		
		// Check for bulk generation actions
		$is_executing = false;
		if ( class_exists( '\AEBG\Core\ActionHandler' ) ) {
			$is_executing = \AEBG\Core\ActionHandler::is_executing();
		}
		// Protect bulk generation actions
		if ( isset( $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] ) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS'] ) {
			$is_executing = true;
		}
		
		if ( $is_executing ) {
			// Action is executing - NEVER kill it, regardless of execution_time
			// The execution_time is QueueRunner age, not action execution time
			error_log( sprintf(
				'[AEBG] 🔍 FILTER: force_exit_after_one_action | Action executing - PROTECTING | execution_time=%.2fs | max_execution_time=%ds | processed_actions=%d | likely_to_be_exceeded=%s',
				$execution_time,
				$max_execution_time,
				$processed_actions,
				$likely_to_be_exceeded ? 'true' : 'false'
			) );
			return false; // Don't kill executing action
		}
		
		// CRITICAL: Force exit after 1 action for process isolation
		// This ensures each action runs in a separate PHP process
		// BUT: Only apply this to bulk generation actions, NOT competitor tracking
		// Competitor tracking actions are handled separately and don't need this restriction
		if ( $processed_actions >= 1 ) {
			// Check what action is currently being processed/running
			// The filter is called DURING action execution, so we need to check the running action
			$current_action_hook = '';
			
			// Method 1: Check database for currently running actions
			global $wpdb;
			$action_table = $wpdb->prefix . 'actionscheduler_actions';
			$running_action = $wpdb->get_row(
				"SELECT hook FROM {$action_table} 
				WHERE status = 'in-progress' 
				ORDER BY scheduled_date_gmt DESC 
				LIMIT 1",
				ARRAY_A
			);
			if ( $running_action && isset( $running_action['hook'] ) ) {
				$current_action_hook = $running_action['hook'];
			}
			
			// Method 2: Check the batch being processed
			if ( empty( $current_action_hook ) && method_exists( $runner, 'get_batch' ) ) {
				$batch = $runner->get_batch();
				if ( is_array( $batch ) && ! empty( $batch ) ) {
					// Get the first action in the batch (currently being processed)
					$current_action = reset( $batch );
					if ( is_object( $current_action ) && method_exists( $current_action, 'get_hook' ) ) {
						$current_action_hook = $current_action->get_hook();
					}
				}
			}
			
			// Method 3: Check via Action Scheduler store (claim-based)
			if ( empty( $current_action_hook ) && class_exists( '\ActionScheduler_Store' ) ) {
				$store = \ActionScheduler_Store::instance();
				// Get the current claim
				$reflection = new \ReflectionClass( $store );
				if ( $reflection->hasProperty( 'claim_before_date' ) ) {
					$claim_property = $reflection->getProperty( 'claim_before_date' );
					$claim_property->setAccessible( true );
					// Try to find claimed actions
					$claimed_actions = $wpdb->get_results(
						"SELECT hook FROM {$action_table} 
						WHERE status = 'in-progress' 
						AND claim_id IS NOT NULL
						ORDER BY scheduled_date_gmt DESC 
						LIMIT 1",
						ARRAY_A
					);
					if ( ! empty( $claimed_actions ) && isset( $claimed_actions[0]['hook'] ) ) {
						$current_action_hook = $claimed_actions[0]['hook'];
					}
				}
			}
			
			// If this is a competitor scraping action, don't force exit
			if ( $current_action_hook === 'aebg_competitor_scrape' ) {
				error_log( sprintf(
					'[AEBG] 🔍 FILTER: force_exit_after_one_action | Competitor scraping action detected (hook: %s) - NOT forcing exit | execution_time=%.2fs | processed_actions=%d',
					$current_action_hook,
					$execution_time,
					$processed_actions
				) );
				return false; // Don't kill competitor scraping actions
			}
			
			// Also check the global flag as a fallback (in case hook detection failed)
			// This is the most reliable method since it's set immediately when action starts
			if ( isset( $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) && $GLOBALS['AEBG_COMPETITOR_SCRAPE_IN_PROGRESS'] ) {
				error_log( sprintf(
					'[AEBG] 🔍 FILTER: force_exit_after_one_action | Competitor scraping flag detected - NOT forcing exit | execution_time=%.2fs | processed_actions=%d | detected_hook=%s',
					$execution_time,
					$processed_actions,
					$current_action_hook ?: 'none'
				) );
				return false; // Don't kill competitor scraping actions
			}
			
			$GLOBALS['aebg_action_processed_in_process'] = true;
			error_log( sprintf(
				'[AEBG] 🔍 FILTER: force_exit_after_one_action | Forcing exit after %d actions | execution_time=%.2fs | max_execution_time=%ds | current_hook=%s',
				$processed_actions,
				$execution_time,
				$max_execution_time,
				$current_action_hook ?: 'unknown'
			) );
			return true; // Force exit - triggers async loopback for next action
		}
		
		// For the first action (processed_actions = 0), only return true if it's a REAL timeout
		// execution_time is QueueRunner age, not action execution time
		// Only kill if execution_time >= max_execution_time (real timeout)
		if ( $execution_time >= $max_execution_time && $max_execution_time >= 1800 ) {
			error_log( sprintf(
				'[AEBG] 🔍 FILTER: force_exit_after_one_action | Real timeout exceeded | execution_time=%.2fs >= max_execution_time=%ds',
				$execution_time,
				$max_execution_time
			) );
			return true;
		}
		
		// Otherwise, let Action Scheduler decide (but log it)
		error_log( sprintf(
			'[AEBG] 🔍 FILTER: force_exit_after_one_action | Returning false | execution_time=%.2fs | max_execution_time=%ds | processed_actions=%d | likely_to_be_exceeded=%s',
			$execution_time,
			$max_execution_time,
			$processed_actions,
			$likely_to_be_exceeded ? 'true' : 'false'
		) );
		return false;
	}

	/**
	 * Increase action scheduler queue runner time limit
	 * 
	 * @param int $time_limit Current time limit (default 30 seconds)
	 * @return int Increased time limit (1800 seconds = 30 minutes)
	 */
	public function increase_action_scheduler_timeout( $time_limit ) {
		// Increase from default 30 to 1800 seconds (30 minutes) for AI generation
		// Use transients to log only once per hour to prevent log spam
		$transient_key = 'aebg_timeout_logged_' . date( 'Y-m-d-H' );
		$new_limit = 1800; // 30 minutes
		if ( ! get_transient( $transient_key ) && $time_limit < $new_limit ) {
			\AEBG\Core\Logger::info( 'Increasing action scheduler queue runner time limit', [
				'old_limit' => $time_limit,
				'new_limit' => $new_limit,
			] );
			set_transient( $transient_key, true, 3600 ); // Log once per hour
		}
		return $new_limit;
	}
	
	/**
	 * Increase action scheduler timeout period (when actions are reset)
	 * 
	 * @param int $time_limit Current timeout period (default 300 seconds)
	 * @return int Increased timeout period (1800 seconds = 30 minutes)
	 */
	public function increase_action_scheduler_timeout_period( $time_limit ) {
		// Increase from default 300 to 1800 seconds (30 minutes) to allow long-running AI generation
		// Use transients to log only once per hour to prevent log spam
		$transient_key = 'aebg_timeout_period_logged_' . date( 'Y-m-d-H' );
		$new_limit = 1800; // 30 minutes
		if ( ! get_transient( $transient_key ) && $time_limit < $new_limit ) {
			\AEBG\Core\Logger::info( 'Increasing action scheduler timeout period', [
				'old_limit' => $time_limit,
				'new_limit' => $new_limit,
			] );
			set_transient( $transient_key, true, 3600 ); // Log once per hour
		}
		return $new_limit;
	}
	
	/**
	 * Increase action scheduler failure period (when actions are marked as failed)
	 * 
	 * @param int $time_limit Current failure period (default 300 seconds)
	 * @return int Increased failure period (1800 seconds = 30 minutes)
	 */
	public function increase_action_scheduler_failure_period( $time_limit ) {
		// Increase from default 300 to 1800 seconds (30 minutes) to allow long-running AI generation
		// CRITICAL: Always return 1800, regardless of input, to ensure filter is applied
		$new_limit = 1800; // 30 minutes
		
		// Use transients to log only once per hour to prevent log spam
		$transient_key = 'aebg_failure_period_logged_' . date( 'Y-m-d-H' );
		if ( ! get_transient( $transient_key ) && $time_limit < $new_limit ) {
			\AEBG\Core\Logger::info( 'Increasing action scheduler failure period', [
				'old_limit' => $time_limit,
				'new_limit' => $new_limit,
			] );
			error_log('[AEBG] Plugin::increase_action_scheduler_failure_period - Filter applied: ' . $time_limit . 's -> ' . $new_limit . 's');
			set_transient( $transient_key, true, 3600 ); // Log once per hour
		}
		
		// CRITICAL: Always return 1800, even if already set, to ensure consistency
		return $new_limit;
	}

	/**
	 * Activate the plugin.
	 */
	public function activate() {
		try {
			if ( class_exists( 'AEBG\\Installer' ) ) {
				$installer = new \AEBG\Installer();
				$installer->activate();
			} else {
				error_log( '[AEBG] Installer class not found during activation' );
			}
		} catch ( \Exception $e ) {
			error_log( '[AEBG] Error during plugin activation: ' . $e->getMessage() );
		}
	}

	/**
	 * Deactivate the plugin.
	 */
	public function deactivate() {
		try {
			if ( class_exists( 'AEBG\\Installer' ) ) {
				$installer = new \AEBG\Installer();
				$installer->deactivate();
			} else {
				error_log( '[AEBG] Installer class not found during deactivation' );
			}
		} catch ( \Exception $e ) {
			error_log( '[AEBG] Error during plugin deactivation: ' . $e->getMessage() );
		}
	}

	/**
	 * Initialize classes.
	 */
	public function init_classes() {
		// Prevent multiple initializations using class-level static property
		if ( self::$_classes_initialized ) {
			return;
		}
		self::$_classes_initialized = true;
		
		// Ensure checkpoint columns exist (for upgrades)
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureCheckpointColumns();
			\AEBG\Installer::ensurePromptTemplatesTable();
			// Ensure Email Marketing tables exist (handles cases where tables were dropped or not created properly).
			\AEBG\Installer::ensureEmailMarketingTables();
		}
		
		try {
			// Manually include classes if autoloader fails
			$plugin_dir = AEBG_PLUGIN_DIR . 'src/';
			
			// Include admin classes
			if ( ! class_exists( 'AEBG\\Admin\\Menu' ) && file_exists( $plugin_dir . 'Admin/Menu.php' ) ) {
				require_once $plugin_dir . 'Admin/Menu.php';
			}
			
			if ( ! class_exists( 'AEBG\\Admin\\Settings' ) && file_exists( $plugin_dir . 'Admin/Settings.php' ) ) {
				require_once $plugin_dir . 'Admin/Settings.php';
			}
			
			if ( ! class_exists( 'AEBG\\Admin\\Meta_Box' ) && file_exists( $plugin_dir . 'Admin/Meta_Box.php' ) ) {
				require_once $plugin_dir . 'Admin/Meta_Box.php';
			}
			
			if ( ! class_exists( 'AEBG\\Admin\\Networks_Manager' ) && file_exists( $plugin_dir . 'Admin/Networks_Manager.php' ) ) {
				require_once $plugin_dir . 'Admin/Networks_Manager.php';
			}
			
			// Include core classes
			$action_handler_path = $plugin_dir . 'Core/ActionHandler.php';
			if ( ! class_exists( 'AEBG\\Core\\ActionHandler' ) ) {
				if ( file_exists( $action_handler_path ) ) {
					require_once $action_handler_path;
					error_log( '[AEBG] Loaded ActionHandler from: ' . $action_handler_path );
				} else {
					error_log( '[AEBG] ActionHandler file not found at: ' . $action_handler_path );
				}
			}
			
			$batch_scheduler_path = $plugin_dir . 'Core/BatchScheduler.php';
			if ( ! class_exists( 'AEBG\\Core\\BatchScheduler' ) ) {
				if ( file_exists( $batch_scheduler_path ) ) {
					require_once $batch_scheduler_path;
					error_log( '[AEBG] Loaded BatchScheduler from: ' . $batch_scheduler_path );
				} else {
					error_log( '[AEBG] BatchScheduler file not found at: ' . $batch_scheduler_path );
				}
			}
			
			if ( ! class_exists( 'AEBG\\Core\\Shortcodes' ) && file_exists( $plugin_dir . 'Core/Shortcodes.php' ) ) {
				require_once $plugin_dir . 'Core/Shortcodes.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\Datafeedr' ) && file_exists( $plugin_dir . 'Core/Datafeedr.php' ) ) {
				require_once $plugin_dir . 'Core/Datafeedr.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\TemplateManager' ) && file_exists( $plugin_dir . 'Core/TemplateManager.php' ) ) {
				require_once $plugin_dir . 'Core/TemplateManager.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\Variables' ) && file_exists( $plugin_dir . 'Core/Variables.php' ) ) {
				require_once $plugin_dir . 'Core/Variables.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\ContextRegistry' ) && file_exists( $plugin_dir . 'Core/ContextRegistry.php' ) ) {
				require_once $plugin_dir . 'Core/ContextRegistry.php';
			}
			
			// Include Competitor Tracking classes
			if ( ! class_exists( 'AEBG\\Core\\CompetitorTrackingManager' ) && file_exists( $plugin_dir . 'Core/CompetitorTrackingManager.php' ) ) {
				require_once $plugin_dir . 'Core/CompetitorTrackingManager.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\CompetitorScraper' ) && file_exists( $plugin_dir . 'Core/CompetitorScraper.php' ) ) {
				require_once $plugin_dir . 'Core/CompetitorScraper.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\CompetitorAnalyzer' ) && file_exists( $plugin_dir . 'Core/CompetitorAnalyzer.php' ) ) {
				require_once $plugin_dir . 'Core/CompetitorAnalyzer.php';
			}
			
			if ( ! class_exists( 'AEBG\\Core\\PositionTracker' ) && file_exists( $plugin_dir . 'Core/PositionTracker.php' ) ) {
				require_once $plugin_dir . 'Core/PositionTracker.php';
			}
			
			// Initialize admin classes with error handling
			// Ensure Menu class is loaded
			if ( ! class_exists( 'AEBG\\Admin\\Menu' ) && file_exists( $plugin_dir . 'Admin/Menu.php' ) ) {
				require_once $plugin_dir . 'Admin/Menu.php';
			}
			
			if ( class_exists( 'AEBG\\Admin\\Menu' ) ) {
				new \AEBG\Admin\Menu();
			} else {
				error_log( '[AEBG] Admin\\Menu class not found. File exists: ' . ( file_exists( $plugin_dir . 'Admin/Menu.php' ) ? 'yes' : 'no' ) );
			}
			
			// Ensure Settings class is loaded
			if ( ! class_exists( 'AEBG\\Admin\\Settings' ) && file_exists( $plugin_dir . 'Admin/Settings.php' ) ) {
				require_once $plugin_dir . 'Admin/Settings.php';
			}
			
			if ( class_exists( 'AEBG\\Admin\\Settings' ) ) {
				new \AEBG\Admin\Settings();
			} else {
				error_log( '[AEBG] Admin\\Settings class not found. File exists: ' . ( file_exists( $plugin_dir . 'Admin/Settings.php' ) ? 'yes' : 'no' ) );
			}
			
			// Only initialize REST API controller if REST API is available
			if ( class_exists( 'WP_REST_Controller' ) ) {
				if ( class_exists( 'AEBG\\API\\BatchStatusController' ) ) {
					new \AEBG\API\BatchStatusController();
				}
				
				if ( class_exists( 'AEBG\\API\\LogsController' ) ) {
					new \AEBG\API\LogsController();
				}
				
				// Initialize Dashboard Controller
				$dashboard_controller_path = $plugin_dir . 'API/DashboardController.php';
				if ( ! class_exists( 'AEBG\\API\\DashboardController' ) && file_exists( $dashboard_controller_path ) ) {
					require_once $dashboard_controller_path;
				}
				if ( class_exists( 'AEBG\\API\\DashboardController' ) ) {
					new \AEBG\API\DashboardController();
				}
				
				// Include Competitor Tracking Controller
				$competitor_controller_path = $plugin_dir . 'API/CompetitorTrackingController.php';
				if ( ! class_exists( 'AEBG\\API\\CompetitorTrackingController' ) && file_exists( $competitor_controller_path ) ) {
					require_once $competitor_controller_path;
				}
				
				if ( class_exists( 'AEBG\\API\\CompetitorTrackingController' ) ) {
					new \AEBG\API\CompetitorTrackingController();
				}
				
				// Initialize Competitor Generator Controller
				$competitor_generator_controller_path = $plugin_dir . 'API/CompetitorGeneratorController.php';
				if ( ! class_exists( 'AEBG\\API\\CompetitorGeneratorController' ) && file_exists( $competitor_generator_controller_path ) ) {
					require_once $competitor_generator_controller_path;
				}
				if ( class_exists( 'AEBG\\API\\CompetitorGeneratorController' ) ) {
					new \AEBG\API\CompetitorGeneratorController();
				}
				
				// Initialize Prompt Template Controller
				$prompt_template_controller_path = $plugin_dir . 'API/PromptTemplateController.php';
				if ( ! class_exists( 'AEBG\\API\\PromptTemplateController' ) && file_exists( $prompt_template_controller_path ) ) {
					require_once $prompt_template_controller_path;
				}
				if ( class_exists( 'AEBG\\API\\PromptTemplateController' ) ) {
					new \AEBG\API\PromptTemplateController();
				}
				
				// Initialize Generator V2 Controller
				$generator_v2_controller_path = $plugin_dir . 'API/GeneratorV2Controller.php';
				if ( ! class_exists( 'AEBG\\API\\GeneratorV2Controller' ) && file_exists( $generator_v2_controller_path ) ) {
					require_once $generator_v2_controller_path;
				}
				if ( class_exists( 'AEBG\\API\\GeneratorV2Controller' ) ) {
					new \AEBG\API\GeneratorV2Controller();
				}

				// Network Analytics API Controller
				$network_analytics_controller_path = $plugin_dir . 'API/NetworkAnalyticsController.php';
				if ( ! class_exists( 'AEBG\\API\\NetworkAnalyticsController' ) && file_exists( $network_analytics_controller_path ) ) {
					require_once $network_analytics_controller_path;
				}
				if ( class_exists( 'AEBG\\API\\NetworkAnalyticsController' ) ) {
					new \AEBG\API\NetworkAnalyticsController();
				}

				// Featured Image API Controller
				$featured_image_controller_path = $plugin_dir . 'API/FeaturedImageController.php';
				if ( ! class_exists( 'AEBG\\API\\FeaturedImageController' ) && file_exists( $featured_image_controller_path ) ) {
					require_once $featured_image_controller_path;
				}
				if ( class_exists( 'AEBG\\API\\FeaturedImageController' ) ) {
					new \AEBG\API\FeaturedImageController();
				}

				// Email Marketing Webhook Controller
				$email_webhook_controller_path = $plugin_dir . 'EmailMarketing/API/WebhookController.php';
				if ( ! class_exists( 'AEBG\\EmailMarketing\\API\\WebhookController' ) && file_exists( $email_webhook_controller_path ) ) {
					require_once $email_webhook_controller_path;
				}
				if ( class_exists( 'AEBG\\EmailMarketing\\API\\WebhookController' ) ) {
					new \AEBG\EmailMarketing\API\WebhookController();
				}
			}
			
			// Initialize core classes with error handling
			// CRITICAL: Only initialize once per request to prevent duplicate hooks
			static $core_classes_initialized = false;
			if ( ! $core_classes_initialized ) {
				if ( class_exists( 'AEBG\\Core\\ActionHandler' ) ) {
					try {
						new \AEBG\Core\ActionHandler();
						// Only log once per session to reduce log spam
						if ( ! get_transient( 'aebg_actionhandler_logged' ) ) {
							error_log( '[AEBG] ActionHandler initialized successfully' );
							set_transient( 'aebg_actionhandler_logged', true, 3600 ); // Log once per hour
						}
					} catch ( \Exception $e ) {
						error_log( '[AEBG] Error initializing ActionHandler: ' . $e->getMessage() );
					}
				} else {
					error_log( '[AEBG] Core\\ActionHandler class not found after require. Plugin dir: ' . $plugin_dir );
				}
				
				if ( class_exists( 'AEBG\\Core\\BatchScheduler' ) ) {
					try {
						new \AEBG\Core\BatchScheduler();
						// Only log once per session to reduce log spam
						if ( ! get_transient( 'aebg_batchscheduler_logged' ) ) {
							error_log( '[AEBG] BatchScheduler initialized successfully' );
							set_transient( 'aebg_batchscheduler_logged', true, 3600 ); // Log once per hour
						}
					} catch ( \Exception $e ) {
						error_log( '[AEBG] Error initializing BatchScheduler: ' . $e->getMessage() );
					}
				} else {
					error_log( '[AEBG] Core\\BatchScheduler class not found after require. Plugin dir: ' . $plugin_dir );
				}
				
				if ( class_exists( 'AEBG\\Core\\MerchantComparisonHandler' ) ) {
					try {
						new \AEBG\Core\MerchantComparisonHandler();
					} catch ( \Exception $e ) {
						error_log( '[AEBG] Error initializing MerchantComparisonHandler: ' . $e->getMessage() );
					}
				}
				
				// Initialize Competitor Tracking Manager
				if ( class_exists( 'AEBG\\Core\\CompetitorTrackingManager' ) ) {
					try {
						\AEBG\Core\CompetitorTrackingManager::init();
					} catch ( \Exception $e ) {
						error_log( '[AEBG] Error initializing CompetitorTrackingManager: ' . $e->getMessage() );
					}
				}
				
				$core_classes_initialized = true;
			}
			
			if ( class_exists( 'AEBG\\Core\\Shortcodes' ) ) {
				new \AEBG\Core\Shortcodes();
			} else {
				error_log( '[AEBG] Core\\Shortcodes class not found' );
			}
			
			// Initialize Schema Output (for frontend JSON-LD output)
			if ( class_exists( 'AEBG\\Core\\SchemaOutput' ) ) {
				try {
					\AEBG\Core\SchemaOutput::init();
				} catch ( \Exception $e ) {
					error_log( '[AEBG] Error initializing SchemaOutput: ' . $e->getMessage() );
				}
			}
			
			// Initialize Meta Description Output (for frontend meta tag output)
			if ( class_exists( 'AEBG\\Core\\MetaDescriptionOutput' ) ) {
				try {
					\AEBG\Core\MetaDescriptionOutput::init();
				} catch ( \Exception $e ) {
					error_log( '[AEBG] Error initializing MetaDescriptionOutput: ' . $e->getMessage() );
				}
			}
			
			// Ensure Meta_Box class is loaded
			if ( ! class_exists( 'AEBG\\Admin\\Meta_Box' ) && file_exists( $plugin_dir . 'Admin/Meta_Box.php' ) ) {
				require_once $plugin_dir . 'Admin/Meta_Box.php';
			}
			
			if ( class_exists( 'AEBG\\Admin\\Meta_Box' ) ) {
				new \AEBG\Admin\Meta_Box();
			} else {
				error_log( '[AEBG] Admin\\Meta_Box class not found. File exists: ' . ( file_exists( $plugin_dir . 'Admin/Meta_Box.php' ) ? 'yes' : 'no' ) );
			}

			// Initialize Featured Image UI
			$featured_image_ui_path = $plugin_dir . 'Admin/FeaturedImageUI.php';
			if ( ! class_exists( 'AEBG\\Admin\\FeaturedImageUI' ) && file_exists( $featured_image_ui_path ) ) {
				require_once $featured_image_ui_path;
			}
			if ( class_exists( 'AEBG\\Admin\\FeaturedImageUI' ) ) {
				new \AEBG\Admin\FeaturedImageUI();
			}
			
			// Initialize Bulk Category Manager
			$bulk_category_manager_path = $plugin_dir . 'Admin/BulkCategoryManager.php';
			if ( ! class_exists( 'AEBG\\Admin\\BulkCategoryManager' ) && file_exists( $bulk_category_manager_path ) ) {
				require_once $bulk_category_manager_path;
			}
			if ( class_exists( 'AEBG\\Admin\\BulkCategoryManager' ) ) {
				new \AEBG\Admin\BulkCategoryManager();
			}
			
			// Initialize Bulk Featured Image Finder
			$bulk_featured_image_finder_path = $plugin_dir . 'Admin/BulkFeaturedImageFinder.php';
			if ( ! class_exists( 'AEBG\\Admin\\BulkFeaturedImageFinder' ) && file_exists( $bulk_featured_image_finder_path ) ) {
				require_once $bulk_featured_image_finder_path;
			}
			if ( class_exists( 'AEBG\\Admin\\BulkFeaturedImageFinder' ) ) {
				new \AEBG\Admin\BulkFeaturedImageFinder();
			}
			
			if ( class_exists( 'AEBG\\Core\\Datafeedr' ) ) {
				new \AEBG\Core\Datafeedr();
			} else {
				error_log( '[AEBG] Core\\Datafeedr class not found' );
			}
			
			if ( class_exists( 'AEBG\\Core\\TemplateManager' ) ) {
				new \AEBG\Core\TemplateManager();
			} else {
				error_log( '[AEBG] Core\\TemplateManager class not found' );
			}
			
			// Initialize Email Marketing System
			$this->init_email_marketing();
			
			// Initialize Email Marketing Admin Menu
			$email_marketing_menu_path = $plugin_dir . 'EmailMarketing/Admin/EmailMarketingMenu.php';
			if ( ! class_exists( 'AEBG\\EmailMarketing\\Admin\\EmailMarketingMenu' ) && file_exists( $email_marketing_menu_path ) ) {
				require_once $email_marketing_menu_path;
			}
			if ( class_exists( 'AEBG\\EmailMarketing\\Admin\\EmailMarketingMenu' ) ) {
				new \AEBG\EmailMarketing\Admin\EmailMarketingMenu();
			}
			
			// Initialize Email Signup Controller (AJAX handlers)
			$email_signup_controller_path = $plugin_dir . 'EmailMarketing/API/EmailSignupController.php';
			if ( ! class_exists( 'AEBG\\EmailMarketing\\API\\EmailSignupController' ) && file_exists( $email_signup_controller_path ) ) {
				require_once $email_signup_controller_path;
			}
			if ( class_exists( 'AEBG\\EmailMarketing\\API\\EmailSignupController' ) ) {
				new \AEBG\EmailMarketing\API\EmailSignupController();
			}
			
			// Elementor Forms integration now uses webhook approach
			// Users configure webhook URL in Elementor Form widget > Actions After Submit > Webhook
			// Webhook endpoint: /wp-json/aebg/v1/email-marketing/webhook
			
			// Enqueue email signup assets for shortcode support
			add_action( 'wp_enqueue_scripts', [$this, 'enqueue_email_signup_assets'] );
			
			// Initialize core components for AI content generation and store globally
			global $aebg_variables, $aebg_context_registry;
			
			if ( class_exists( 'AEBG\\Core\\Variables' ) ) {
				$aebg_variables = new \AEBG\Core\Variables();
			} else {
				error_log( '[AEBG] Core\\Variables class not found' );
			}
			
			if ( class_exists( 'AEBG\\Core\\ContextRegistry' ) ) {
				$aebg_context_registry = new \AEBG\Core\ContextRegistry();
			} else {
				error_log( '[AEBG] Core\\ContextRegistry class not found' );
			}
			
		} catch ( Exception $e ) {
			error_log( '[AEBG] Error initializing plugin classes: ' . $e->getMessage() );
		}
	}
	
	/**
	 * Initialize Email Marketing System
	 * 
	 * @return void
	 */
	private function init_email_marketing() {
		try {
			$plugin_dir = AEBG_PLUGIN_DIR . 'src/';
			
			// Initialize Event Listener (triggers campaigns on events)
			$event_listener_path = $plugin_dir . 'EmailMarketing/Core/EventListener.php';
			if ( ! class_exists( 'AEBG\\EmailMarketing\\Core\\EventListener' ) && file_exists( $event_listener_path ) ) {
				require_once $event_listener_path;
			}
			if ( class_exists( 'AEBG\\EmailMarketing\\Core\\EventListener' ) ) {
				new \AEBG\EmailMarketing\Core\EventListener();
			}
			
			// Initialize Tracking Endpoints (opens, clicks, opt-in, unsubscribe)
			$tracking_endpoints_path = $plugin_dir . 'EmailMarketing/Core/TrackingEndpoints.php';
			if ( ! class_exists( 'AEBG\\EmailMarketing\\Core\\TrackingEndpoints' ) && file_exists( $tracking_endpoints_path ) ) {
				require_once $tracking_endpoints_path;
			}
			if ( class_exists( 'AEBG\\EmailMarketing\\Core\\TrackingEndpoints' ) ) {
				new \AEBG\EmailMarketing\Core\TrackingEndpoints();
			}
			
			// Register Action Scheduler hook for email queue processing
			add_action( 'aebg_process_email_queue', [$this, 'process_email_queue'] );
			
			// Schedule queue processing if not already scheduled
			if ( ! as_next_scheduled_action( 'aebg_process_email_queue' ) ) {
				as_schedule_recurring_action(
					time(),
					60, // Every minute
					'aebg_process_email_queue'
				);
			}
			
		} catch ( \Exception $e ) {
			error_log( '[AEBG] Error initializing Email Marketing System: ' . $e->getMessage() );
		}
	}
	
	/**
	 * Process email queue (Action Scheduler hook)
	 * 
	 * @return void
	 */
	public function process_email_queue() {
		try {
			$queue_manager = new \AEBG\EmailMarketing\Core\QueueManager();
			$results = $queue_manager->process_queue( 50 ); // Process 50 emails per batch
			
			if ( $results['sent'] > 0 || $results['failed'] > 0 ) {
				\AEBG\Core\Logger::info( 'Email queue processed', $results );
			}
		} catch ( \Exception $e ) {
			\AEBG\Core\Logger::error( 'Error processing email queue', [
				'error' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Initialize Action Scheduler
	 */
	public function init_action_scheduler() {
		try {
			// First, check if Action Scheduler is already available (from standalone plugin)
			if ( class_exists( '\ActionScheduler' ) ) {
				$this->setup_action_scheduler_hooks();
				return;
			}

			// Check if Action Scheduler plugin is active
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'action-scheduler/action-scheduler.php' ) ) {
				error_log( '[AEBG] Action Scheduler plugin is active but class not found. Waiting for plugin to load...' );
				// The plugin might not be loaded yet, so we'll try again later
				add_action( 'plugins_loaded', [ $this, 'check_action_scheduler_again' ], 20 );
				return;
			}

			// Check if the action scheduler directory exists in our vendor folder (fallback)
			$action_scheduler_path = AEBG_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler';
			if ( ! is_dir( $action_scheduler_path ) ) {
				error_log( '[AEBG] Action Scheduler not available. Please install the Action Scheduler plugin or ensure it is active.' );
				return;
			}

			// Load Action Scheduler if not already loaded
			if ( ! class_exists( '\ActionScheduler' ) ) {
				error_log( '[AEBG] Loading Action Scheduler from: ' . $action_scheduler_path . '/action-scheduler.php' );
				require_once $action_scheduler_path . '/action-scheduler.php';
				
				// Trigger the plugins_loaded action to ensure Action Scheduler initializes
				if ( ! did_action( 'plugins_loaded' ) ) {
					do_action( 'plugins_loaded' );
				}
			}

			// Check if Action Scheduler is now available
			if ( ! class_exists( '\ActionScheduler' ) ) {
				error_log( '[AEBG] Action Scheduler not found. Please ensure the action-scheduler package is installed.' );
				return;
			}

			// Initialize Action Scheduler
			\ActionScheduler::init( $action_scheduler_path . '/action-scheduler.php' );

			// CRITICAL: Delay migration controller initialization until init hook
			// The migration controller may trigger cleanup code (ActionScheduler_WPCommentCleaner)
			// that calls as_next_scheduled_action() which requires the data store to be ready.
			// By delaying until init hook, we ensure the data store is fully initialized.
			if ( class_exists( '\Action_Scheduler\Migration\Controller' ) ) {
				add_action( 'init', function() {
					// Double-check initialization before proceeding
					if ( \ActionScheduler::is_initialized() ) {
						\Action_Scheduler\Migration\Controller::init();
						error_log( '[AEBG] Action Scheduler Migration Controller initialized on init hook' );
					} else {
						error_log( '[AEBG] Warning: Action Scheduler data store not initialized when trying to init migration controller' );
					}
				}, 1 );
			}

			$this->setup_action_scheduler_hooks();
			error_log( '[AEBG] Action Scheduler initialized successfully' );
		} catch ( Exception $e ) {
			error_log( '[AEBG] Error initializing Action Scheduler: ' . $e->getMessage() );
		}
	}

	/**
	 * Check Action Scheduler again after plugins are loaded
	 */
	public function check_action_scheduler_again() {
		if ( class_exists( '\ActionScheduler' ) ) {
			error_log( '[AEBG] Action Scheduler now available from standalone plugin' );
			
			// CRITICAL: Delay migration controller initialization until init hook
			// The migration controller may trigger cleanup code (ActionScheduler_WPCommentCleaner)
			// that calls as_next_scheduled_action() which requires the data store to be ready.
			// By delaying until init hook, we ensure the data store is fully initialized.
			if ( class_exists( '\Action_Scheduler\Migration\Controller' ) ) {
				add_action( 'init', function() {
					// Double-check initialization before proceeding
					if ( \ActionScheduler::is_initialized() ) {
						\Action_Scheduler\Migration\Controller::init();
						error_log( '[AEBG] Action Scheduler Migration Controller initialized on init hook (standalone plugin)' );
					} else {
						error_log( '[AEBG] Warning: Action Scheduler data store not initialized when trying to init migration controller' );
					}
				}, 1 );
			}
			
			$this->setup_action_scheduler_hooks();
		} else {
			error_log( '[AEBG] Action Scheduler still not available after plugins_loaded' );
		}
	}

	/**
	 * Set up Action Scheduler hooks and processing
	 */
	private function setup_action_scheduler_hooks() {
		// Set up the queue runner to process actions
		add_action( 'init', [ $this, 'setup_action_scheduler_runner' ] );
		
		// Add custom cron interval
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
		
		// Add cron hook for processing actions
		add_action( 'aebg_process_actions', [ $this, 'process_pending_actions' ] );
		
		// Schedule cron job if not already scheduled
		if ( ! wp_next_scheduled( 'aebg_process_actions' ) ) {
			wp_schedule_event( time(), 'aebg_every_30_seconds', 'aebg_process_actions' );
		}
	}

	/**
	 * Add custom cron interval
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['aebg_every_30_seconds'] = array(
			'interval' => 30,
			'display'  => 'Every 30 seconds'
		);
		return $schedules;
	}

	/**
	 * Set up Action Scheduler queue runner
	 * 
	 * Action Scheduler automatically handles queue processing via:
	 * 1. WP-Cron (action_scheduler_run_queue hook) - Initial trigger
	 * 2. Async requests triggered on shutdown - Automatic chaining (now enabled in all contexts)
	 * 
	 * We use a conditional shutdown hook that only triggers if:
	 * - No action is currently executing (to avoid interference)
	 * - We're in admin context (for immediate processing on admin pages)
	 * - This is a fallback - Action Scheduler's async mechanism is primary
	 */
	public function setup_action_scheduler_runner() {
		// Hook into shutdown as fallback trigger (only in admin context, only if no action executing)
		// Action Scheduler's async mechanism will handle chaining automatically
		// This is just for immediate processing when admin pages are visited
		add_action( 'shutdown', [ $this, 'trigger_action_scheduler_queue_conditional' ], 5 );
		
		// Hook into shutdown to bypass Action Scheduler's is_admin() check in non-admin contexts
		// This ensures async requests are dispatched even from WP-Cron context
		// Priority 10 ensures it runs after Action Scheduler's own shutdown hook (priority 10)
		add_action( 'shutdown', [ $this, 'trigger_action_scheduler_async_bypass' ], 10 );
	}


	/**
	 * Allow Action Scheduler async requests in all contexts (not just admin)
	 * 
	 * By default, Action Scheduler only allows async requests in admin context (is_admin() check).
	 * This filter removes that limitation, allowing async requests from:
	 * - WP-Cron context (where our jobs run)
	 * - Frontend context
	 * - Any context where actions need to be processed
	 * 
	 * Each async request is a separate HTTP request = separate PHP process = fresh 180s timer.
	 * This enables true async execution that avoids the 180-second server timeout.
	 * 
	 * NOTE: This filter is applied in the `allow()` method, but Action Scheduler's
	 * `maybe_dispatch_async_request()` checks `is_admin()` BEFORE calling `maybe_dispatch()`.
	 * So we also need to bypass that check by directly calling the async mechanism
	 * (see `trigger_action_scheduler_async_bypass()` method).
	 * 
	 * @param bool $allow Whether async requests are allowed (from Action Scheduler's internal checks)
	 * @return bool True to allow async requests, false to block them
	 */
	public function allow_async_requests_in_all_contexts( $allow ) {
		// Action Scheduler's internal checks already verified:
		// - has_action('action_scheduler_run_queue')
		// - !has_maximum_concurrent_batches() (we limit to 1)
		// - has_pending_actions_due()
		// 
		// We just need to remove the is_admin() limitation
		// If Action Scheduler says it's allowed (based on its checks), we allow it
		return $allow;
	}

	/**
	 * Bypass Action Scheduler's is_admin() check and trigger async requests directly
	 * 
	 * Action Scheduler's `maybe_dispatch_async_request()` checks `is_admin()` before
	 * calling `maybe_dispatch()`, which blocks async requests in WP-Cron context.
	 * 
	 * This method bypasses that check by directly calling the async request's
	 * `maybe_dispatch()` method, which will:
	 * 1. Check if async requests are allowed (via our filter)
	 * 2. Check if there are pending actions
	 * 3. Check if we haven't exceeded concurrent batches
	 * 4. Dispatch async request if all conditions are met
	 * 
	 * This is called on shutdown after Action Scheduler processes actions,
	 * ensuring async requests are dispatched even in non-admin contexts.
	 */
	public function trigger_action_scheduler_async_bypass() {
		// Only trigger if we're NOT in admin context (admin context uses Action Scheduler's built-in mechanism)
		// This ensures we don't interfere with Action Scheduler's normal operation in admin
		if ( is_admin() ) {
			return;
		}
		
		// Never trigger if an action is currently executing
		if ( class_exists( '\AEBG\Core\ActionHandler' ) && \AEBG\Core\ActionHandler::is_executing() ) {
			return;
		}
		
		// Only trigger if an action was processed in this process
		// This ensures we only dispatch async requests after Action Scheduler has processed actions
		// (Action Scheduler's async mechanism will handle chaining)
		if ( ! isset( $GLOBALS['aebg_action_processed_in_process'] ) || ! $GLOBALS['aebg_action_processed_in_process'] ) {
			return;
		}
		
		// Bypass Action Scheduler's is_admin() check and trigger async request directly
		// This ensures async requests are dispatched even from WP-Cron context
		try {
			if ( ! class_exists( '\ActionScheduler_QueueRunner' ) ) {
				return;
			}
			
			$runner = \ActionScheduler_QueueRunner::instance();
			
			// Use reflection to access protected async_request property
			$reflection = new \ReflectionClass( $runner );
			if ( ! $reflection->hasProperty( 'async_request' ) ) {
				return;
			}
			
			$async_request_property = $reflection->getProperty( 'async_request' );
			$async_request_property->setAccessible( true );
			$async_request = $async_request_property->getValue( $runner );
			
			if ( ! $async_request || ! method_exists( $async_request, 'maybe_dispatch' ) ) {
				return;
			}
			
			// Call maybe_dispatch() directly - this bypasses the admin check
			// The allow() method inside maybe_dispatch() will check all conditions:
			// - has_action('action_scheduler_run_queue')
			// - !has_maximum_concurrent_batches() (we limit to 1)
			// - has_pending_actions_due()
			// - Our filter that allows async in all contexts
			// If all conditions are met, it will dispatch an async request
			$result = $async_request->maybe_dispatch();
			
			error_log( sprintf(
				'[AEBG] Triggered Action Scheduler async request bypass (non-admin context) | result=%s',
				$result ? 'dispatched' : 'not_dispatched'
			) );
		} catch ( \Exception $e ) {
			error_log( '[AEBG] Failed to trigger Action Scheduler async bypass: ' . $e->getMessage() );
		}
	}

	/**
	 * Conditionally trigger Action Scheduler queue on shutdown
	 * 
	 * Only triggers if:
	 * 1. We're in admin context (for immediate processing on admin pages)
	 * 2. No action is currently executing (to avoid interference)
	 * 3. No action was already processed in this process (to avoid duplicate triggers)
	 * 
	 * This is a fallback mechanism. Action Scheduler's async mechanism is the primary method.
	 */
	public function trigger_action_scheduler_queue_conditional() {
		// Only trigger in admin context (for immediate processing when admin pages are visited)
		// In WP-Cron context, let Action Scheduler's async mechanism handle it
		if ( ! is_admin() ) {
			return;
		}
		
		// Never trigger if an action is currently executing
		if ( class_exists( '\AEBG\Core\ActionHandler' ) && \AEBG\Core\ActionHandler::is_executing() ) {
			return;
		}
		
		// Never trigger if we've already processed an action in this process
		if ( isset( $GLOBALS['aebg_action_processed_in_process'] ) && $GLOBALS['aebg_action_processed_in_process'] ) {
			return;
		}
		
		// Safe to trigger - this will start the async chain
		$this->trigger_action_scheduler_queue();
	}

	/**
	 * Process pending actions via cron
	 */
	public function process_pending_actions() {
		if ( class_exists( '\ActionScheduler_QueueRunner' ) ) {
			$runner = \ActionScheduler_QueueRunner::instance();
			$runner->run();
		}
	}

	/**
	 * Ensure proper migration controller initialization.
	 */
	public function fix_action_scheduler_migration() {
		// Prevent multiple calls
		static $called = false;
		if ( $called ) {
			return;
		}
		$called = true;
		
		try {
			// Wait a bit more to ensure Action Scheduler is fully initialized
			if ( ! class_exists( '\ActionScheduler' ) ) {
				error_log( '[AEBG] Action Scheduler class not ready, skipping migration fix' );
				return;
			}
			
			// Wait for Action Scheduler to be fully initialized
			// Use ActionSchedulerHelper to check initialization properly
			if ( class_exists( '\AEBG\Core\ActionSchedulerHelper' ) ) {
				if ( ! \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
					error_log( '[AEBG] Action Scheduler not initialized, skipping migration fix' );
					return;
				}
			} elseif ( ! function_exists( 'as_get_scheduled_actions' ) ) {
				error_log( '[AEBG] Action Scheduler functions not ready, skipping migration fix' );
				return;
			}
			
			// Additional check to ensure the data store is ready
			if ( ! class_exists( '\ActionScheduler_DataController' ) ) {
				error_log( '[AEBG] Action Scheduler Data Controller not ready, skipping migration fix' );
				return;
			}
			

			
			// Ensure the migration controller is initialized
			if ( class_exists( '\Action_Scheduler\Migration\Controller' ) ) {
				\Action_Scheduler\Migration\Controller::init();
				error_log( '[AEBG] Action Scheduler Migration Controller initialized during fix' );
			}
			

			
		} catch ( Exception $e ) {
			error_log( '[AEBG] Error fixing Action Scheduler migration: ' . $e->getMessage() );
		}
	}

	/**
	 * Manually trigger Action Scheduler migration for debugging purposes.
	 */
	public function trigger_action_scheduler_migration() {
		try {
			if ( class_exists( '\Action_Scheduler\Migration\Controller' ) ) {
				// Ensure the migration controller is initialized
				\Action_Scheduler\Migration\Controller::init();
				
				// Get the scheduler instance and trigger migration
				$controller = \Action_Scheduler\Migration\Controller::instance();
				if ( method_exists( $controller, 'schedule_migration' ) ) {
					$controller->schedule_migration();
					error_log( '[AEBG] Action Scheduler migration manually triggered' );
					return true;
				}
			}
			
			error_log( '[AEBG] Could not trigger Action Scheduler migration' );
			return false;
		} catch ( Exception $e ) {
			error_log( '[AEBG] Error triggering Action Scheduler migration: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Add admin menu for debugging Action Scheduler issues.
	 */
	public function add_action_scheduler_debug_menu() {
		add_menu_page(
			'Action Scheduler Debug',
			'Action Scheduler Debug',
			'manage_options',
			'action-scheduler-debug',
			[ $this, 'action_scheduler_debug_page' ],
			'dashicons-clock',
			60
		);
	}

	/**
	 * Display the Action Scheduler debug page.
	 */
	public function action_scheduler_debug_page() {
		?>
		<div class="wrap">
			<h1>Action Scheduler Debug</h1>
			<p>This page provides tools to help debug Action Scheduler issues.</p>

			<h2>Action Scheduler Status</h2>
			<p>
				<?php
				if ( class_exists( '\ActionScheduler' ) ) {
					echo 'Action Scheduler is active and running.';
				} else {
					echo 'Action Scheduler is not active or not found. Please ensure the Action Scheduler plugin is installed and activated.';
				}
				?>
			</p>

			<h2>Debug Actions</h2>
			<p>
				<button id="trigger-migration" class="button button-primary">Trigger Action Scheduler Migration</button>
				<span id="migration-status"></span>
			</p>

			<h2>Stuck Items Diagnostic</h2>
			<?php
			// Check for stuck items (items in processing status)
			global $wpdb;
			$stuck_items = $wpdb->get_results(
				"SELECT id, status, current_step, step_progress, updated_at 
				FROM {$wpdb->prefix}aebg_batch_items 
				WHERE status = 'processing' 
				ORDER BY updated_at DESC 
				LIMIT 10"
			);
			
			if ( ! empty( $stuck_items ) ) {
				echo '<p><strong>Found ' . count( $stuck_items ) . ' items stuck in processing:</strong></p>';
				echo '<table class="widefat">';
				echo '<thead><tr><th>Item ID</th><th>Status</th><th>Current Step</th><th>Step Progress</th><th>Last Updated</th><th>Action Scheduler Status</th><th>Actions</th></tr></thead>';
				echo '<tbody>';
				
				foreach ( $stuck_items as $item ) {
					$item_id = (int) $item->id;
					$group = 'aebg_generation_' . $item_id;
					
					// Check Action Scheduler for this item
					$as_status = 'No actions found';
					$pending_count = 0;
					$in_progress_count = 0;
					
					if ( class_exists( '\AEBG\Core\ActionSchedulerHelper' ) && \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
						// Check for step-by-step actions
						$all_steps = [ 'step_1', 'step_2', 'step_3', 'step_3_5', 'step_3_6', 'step_3_7', 'step_4', 'step_5', 'step_5_5', 'step_6' ];
						
						foreach ( $all_steps as $step_key ) {
							$hook = \AEBG\Core\StepHandler::get_step_hook( $step_key );
							if ( ! $hook ) {
								continue;
							}
							
							$pending = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
								'hook' => $hook,
								'args' => [ $item_id ],
								'group' => $group,
								'status' => 'pending',
								'per_page' => 10,
							] );
							
							$in_progress = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
								'hook' => $hook,
								'args' => [ $item_id ],
								'group' => $group,
								'status' => 'in-progress',
								'per_page' => 10,
							] );
							
							if ( ! empty( $pending ) ) {
								$pending_count += count( $pending );
								$as_status = sprintf( '%d pending %s action(s)', count( $pending ), $step_key );
							}
							
							if ( ! empty( $in_progress ) ) {
								$in_progress_count += count( $in_progress );
								$as_status = sprintf( '%d in-progress %s action(s)', count( $in_progress ), $step_key );
							}
						}
						
						if ( $pending_count === 0 && $in_progress_count === 0 ) {
							$as_status = '<span style="color: red;">⚠️ No pending or in-progress actions found - item may be truly stuck</span>';
						}
					}
					
					echo '<tr>';
					echo '<td>' . esc_html( $item_id ) . '</td>';
					echo '<td>' . esc_html( $item->status ) . '</td>';
					echo '<td>' . esc_html( $item->current_step ?? 'N/A' ) . '</td>';
					echo '<td>' . esc_html( $item->step_progress ?? 'N/A' ) . '</td>';
					echo '<td>' . esc_html( $item->updated_at ) . '</td>';
					echo '<td>' . $as_status . '</td>';
					echo '<td>';
					echo '<button class="button button-small retry-item" data-item-id="' . esc_attr( $item_id ) . '" data-step="' . esc_attr( $item->current_step ?? 'step_1' ) . '">Retry Step</button>';
					echo '</td>';
					echo '</tr>';
				}
				
				echo '</tbody>';
				echo '</table>';
			} else {
				echo '<p>No stuck items found.</p>';
			}
			?>
			
			<h2>Scheduled Actions</h2>
			<table class="widefat">
				<thead>
					<tr>
						<th>ID</th>
						<th>Hook</th>
						<th>Status</th>
						<th>Scheduled Date</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php
							// Use ActionSchedulerHelper to ensure proper initialization check
							if ( class_exists( '\AEBG\Core\ActionSchedulerHelper' ) && \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
			$scheduled_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( array(
				'hook' => 'action_scheduler/migration_hook',
				'status' => 'pending'
			) );
						foreach ( $scheduled_actions as $action_id_key => $action ) {
							// Handle different action object formats
							if ( is_object( $action ) && method_exists( $action, 'get_hook' ) ) {
								// ActionScheduler_Action object - use methods to access properties
								$action_id = is_numeric( $action_id_key ) ? $action_id_key : ( method_exists( $action, 'get_id' ) ? $action->get_id() : 'N/A' );
								$hook = method_exists( $action, 'get_hook' ) ? $action->get_hook() : 'N/A';
								
								// Get status from store (not directly from action object)
								$status = 'N/A';
								if ( class_exists( '\ActionScheduler_Store' ) ) {
									try {
										$store = \ActionScheduler_Store::instance();
										$status = $store->get_status( $action_id );
									} catch ( \Exception $e ) {
										$status = 'unknown';
									}
								}
								
								// Get scheduled date from schedule object
								$scheduled_date = 'N/A';
								if ( method_exists( $action, 'get_schedule' ) ) {
									try {
										$schedule = $action->get_schedule();
										if ( method_exists( $schedule, 'get_date' ) ) {
											$date = $schedule->get_date();
											if ( $date ) {
												$scheduled_date = $date->format( 'Y-m-d H:i:s' );
											}
										}
									} catch ( \Exception $e ) {
										$scheduled_date = 'unknown';
									}
								}
							} elseif ( is_array( $action ) ) {
								// Array format (ARRAY_A return format)
								$action_id = isset( $action['action_id'] ) ? $action['action_id'] : ( isset( $action['id'] ) ? $action['id'] : 'N/A' );
								$hook = isset( $action['hook'] ) ? $action['hook'] : 'N/A';
								$status = isset( $action['status'] ) ? $action['status'] : 'N/A';
								$scheduled_date = isset( $action['scheduled_date'] ) ? $action['scheduled_date'] : 'N/A';
							} else {
								// Fallback for unknown format
								$action_id = is_numeric( $action_id_key ) ? $action_id_key : 'N/A';
								$hook = 'N/A';
								$status = 'N/A';
								$scheduled_date = 'N/A';
							}
							
							echo '<tr>';
							echo '<td>' . esc_html( $action_id ) . '</td>';
							echo '<td>' . esc_html( $hook ) . '</td>';
							echo '<td>' . esc_html( $status ) . '</td>';
							echo '<td>' . esc_html( $scheduled_date ) . '</td>';
							echo '<td>';
							echo '<button class="button button-small delete-action" data-action-id="' . esc_attr( $action_id ) . '">Delete</button>';
							echo '</td>';
							echo '</tr>';
						}
					}
					?>
				</tbody>
			</table>
		</div>

		<script>
			jQuery(document).ready(function($) {
				$('#trigger-migration').on('click', function() {
					var button = $(this);
					var statusSpan = $('#migration-status');
					button.prop('disabled', true).text('Triggering...');
					statusSpan.text('Triggering...');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'trigger_action_scheduler_migration',
							nonce: '<?php echo wp_create_nonce( 'trigger_action_scheduler_migration' ); ?>'
						},
						success: function(response) {
							if (response.success) {
								statusSpan.text('Migration triggered successfully!');
								button.prop('disabled', false).text('Trigger Action Scheduler Migration');
							} else {
								statusSpan.text('Error: ' + response.data);
								button.prop('disabled', false).text('Trigger Action Scheduler Migration');
							}
						},
						error: function(xhr, status, error) {
							statusSpan.text('Error: ' + error);
							button.prop('disabled', false).text('Trigger Action Scheduler Migration');
						}
					});
				});

				$(document).on('click', '.delete-action', function() {
					var actionId = $(this).data('action-id');
					if (confirm('Are you sure you want to delete this scheduled action?')) {
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'delete_action_scheduler_action',
								action_id: actionId,
								nonce: '<?php echo wp_create_nonce( 'delete_action_scheduler_action' ); ?>'
							},
							success: function(response) {
								if (response.success) {
									alert('Action deleted successfully!');
									location.reload(); // Refresh the page to show updated list
								} else {
									alert('Error deleting action: ' + response.data);
								}
							},
							error: function(xhr, status, error) {
								alert('Error deleting action: ' + error);
							}
						});
					}
				});
				
				$(document).on('click', '.retry-item', function() {
					var button = $(this);
					var itemId = button.data('item-id');
					var step = button.data('step');
					
					if (confirm('Are you sure you want to retry step "' + step + '" for item ' + itemId + '?')) {
						button.prop('disabled', true).text('Retrying...');
						
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'retry_stuck_item',
								item_id: itemId,
								step: step,
								nonce: '<?php echo wp_create_nonce( 'retry_stuck_item' ); ?>'
							},
							success: function(response) {
								if (response.success) {
									alert('Item ' + itemId + ' retry scheduled successfully!');
									location.reload(); // Refresh the page to show updated list
								} else {
									alert('Error retrying item: ' + response.data);
									button.prop('disabled', false).text('Retry Step');
								}
							},
							error: function(xhr, status, error) {
								alert('Error retrying item: ' + error);
								button.prop('disabled', false).text('Retry Step');
							}
						});
					}
				});
			});
		</script>
		<?php
	}

	/**
	 * AJAX handler for triggering Action Scheduler migration.
	 */
	public function ajax_trigger_action_scheduler_migration() {
		check_ajax_referer( 'trigger_action_scheduler_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have sufficient permissions to access this page.' );
			exit;
		}

		$success = $this->trigger_action_scheduler_migration();

		if ( $success ) {
			wp_send_json_success( 'Action Scheduler migration triggered successfully!' );
		} else {
			wp_send_json_error( 'Could not trigger Action Scheduler migration.' );
		}
		exit;
	}

	/**
	 * AJAX handler for retrying a stuck item.
	 */
	public function ajax_retry_stuck_item() {
		check_ajax_referer( 'retry_stuck_item', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have sufficient permissions to access this page.' );
			exit;
		}

		if ( ! isset( $_POST['item_id'] ) || ! isset( $_POST['step'] ) ) {
			wp_send_json_error( 'Item ID or step not provided.' );
			exit;
		}

		$item_id = (int) $_POST['item_id'];
		$step = sanitize_text_field( $_POST['step'] );

		// Validate step
		if ( ! class_exists( '\AEBG\Core\StepHandler' ) ) {
			wp_send_json_error( 'StepHandler class not found.' );
			exit;
		}

		$hook = \AEBG\Core\StepHandler::get_step_hook( $step );
		if ( ! $hook ) {
			wp_send_json_error( 'Invalid step: ' . $step );
			exit;
		}

		// CRITICAL: Check if Action Scheduler is initialized before calling any functions
		if ( ! class_exists( '\AEBG\Core\ActionSchedulerHelper' ) || ! \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
			wp_send_json_error( 'Action Scheduler not initialized.' );
			exit;
		}
		
		// Schedule the step action
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			wp_send_json_error( 'Action Scheduler not available.' );
			exit;
		}

		$group = 'aebg_generation_' . $item_id;
		$action_id = as_schedule_single_action(
			time() + 2, // Schedule 2 seconds from now
			$hook,
			[ $item_id ],
			$group,
			true // unique
		);

		if ( $action_id > 0 ) {
			error_log( sprintf(
				'[AEBG] Manually retried stuck item: item_id=%d, step=%s, action_id=%d',
				$item_id,
				$step,
				$action_id
			) );
			wp_send_json_success( sprintf( 'Item %d retry scheduled successfully (action_id: %d)', $item_id, $action_id ) );
		} else {
			wp_send_json_error( 'Failed to schedule retry action. Action may already exist.' );
		}
		exit;
	}

	/**
	 * AJAX handler for deleting a scheduled Action Scheduler action.
	 */
	public function ajax_delete_action_scheduler_action() {
		check_ajax_referer( 'delete_action_scheduler_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have sufficient permissions to access this page.' );
			exit;
		}

		if ( ! isset( $_POST['action_id'] ) ) {
			wp_send_json_error( 'Action ID not provided.' );
			exit;
		}

		$action_id = intval( $_POST['action_id'] );

		if ( function_exists( 'as_unschedule_action' ) ) {
			$unscheduled = \as_unschedule_action( 'action_scheduler/migration_hook', null, 'action-scheduler-migration', $action_id );
			if ( $unscheduled ) {
				wp_send_json_success( 'Action deleted successfully!' );
			} else {
				wp_send_json_error( 'Error deleting action: Action not found or already unscheduled.' );
			}
		} else {
			wp_send_json_error( 'Action Scheduler is not available.' );
		}
		exit;
	}

	/**
	 * AJAX handler for triggering Action Scheduler to process pending actions.
	 * This is called via async HTTP request to ensure process isolation.
	 */
	public function ajax_trigger_action_scheduler() {
		// Verify nonce for security
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'aebg_trigger_action_scheduler' ) ) {
			wp_send_json_error( 'Invalid nonce.' );
			exit;
		}

		// Check permissions - require authenticated user with manage_options capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
			exit;
		}

		// Trigger Action Scheduler to process pending actions
		// This runs in a separate PHP process (separate HTTP request)
		$this->trigger_action_scheduler_queue();
		wp_send_json_success( 'Action Scheduler triggered successfully.' );
		exit;
	}

	/**
	 * Trigger Action Scheduler queue runner.
	 * 
	 * Uses do_action('action_scheduler_run_queue') per Action Scheduler FAQ
	 * The force_exit_after_one_action filter ensures process isolation (1 action per process)
	 */
	public function trigger_action_scheduler_queue() {
		if ( ! class_exists( '\ActionScheduler_QueueRunner' ) ) {
			return;
		}
		
		// CRITICAL: Never trigger if a bulk generation action is currently executing
		// Note: Competitor tracking actions are separate and don't interfere
		if ( class_exists( '\AEBG\Core\ActionHandler' ) && \AEBG\Core\ActionHandler::is_executing() ) {
			return;
		}
		
		// CRITICAL: Never trigger if we've already processed a bulk generation action in this process
		// Note: This only applies to bulk generation, not competitor tracking
		if ( isset( $GLOBALS['aebg_action_processed_in_process'] ) && $GLOBALS['aebg_action_processed_in_process'] ) {
			return;
		}
		
		// Check if there are pending bulk generation actions (competitor tracking is separate)
		if ( class_exists( '\AEBG\Core\ActionSchedulerHelper' ) ) {
			$pending = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => 'aebg_execute_generation',
				'status' => 'pending',
				'per_page' => 1,
			], 'ids' );
			
			if ( empty( $pending ) ) {
				return; // No pending bulk generation actions
			}
		}
		
		// Trigger Action Scheduler queue processing per FAQ
		// The force_exit_after_one_action filter ensures only 1 action runs per process
		do_action( 'action_scheduler_run_queue' );
	}
	
	/**
	 * Cleanup orphaned step actions
	 * 
	 * Removes step actions for items that are:
	 * - Already completed
	 * - Failed
	 * - Cancelled
	 * - Stuck in processing for more than 24 hours
	 * 
	 * Runs daily via WP-Cron
	 */
	public function cleanup_orphaned_steps() {
		global $wpdb;
		
		if ( ! class_exists( '\AEBG\Core\StepHandler' ) ) {
			return;
		}
		
		error_log( '[AEBG] Starting orphaned step cleanup...' );
		
		// Get all step hooks
		$all_steps = \AEBG\Core\StepHandler::get_all_step_keys();
		$cleaned_count = 0;
		
		// Find items that are completed, failed, or cancelled
		$completed_items = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}aebg_batch_items 
			WHERE status IN ('completed', 'failed', 'cancelled')"
		);
		
		// Find items stuck in processing for more than 24 hours
		$stuck_items = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_batch_items 
			WHERE status = 'processing' 
			AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		) );
		
		$items_to_clean = array_unique( array_merge( $completed_items, $stuck_items ) );
		
		foreach ( $items_to_clean as $item_id ) {
			$item_id = (int) $item_id;
			
			// Cancel all steps for this item
			$cancelled = \AEBG\Core\StepHandler::cancel_all_steps_for_item( $item_id );
			if ( $cancelled > 0 ) {
				$cleaned_count += $cancelled;
				error_log( sprintf(
					'[AEBG] Cleaned up %d orphaned steps for item_id=%d',
					$cancelled,
					$item_id
				) );
			}
		}
		
		// Also clean up actions for non-existent items
		// CRITICAL: Check if Action Scheduler is initialized before calling any functions
		if ( ! class_exists( '\AEBG\Core\ActionSchedulerHelper' ) || ! \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
			error_log( '[AEBG] Action Scheduler not initialized, skipping orphaned action cleanup' );
			return;
		}
		
		foreach ( $all_steps as $step_key ) {
			$hook = \AEBG\Core\StepHandler::get_step_hook( $step_key );
			if ( ! $hook ) {
				continue;
			}
			
			// Get all pending actions for this step using helper method (includes initialization check)
			$actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => $hook,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 100,
			] );
			
			foreach ( $actions as $action ) {
				$args = $action->get_args();
				$action_item_id = isset( $args[0] ) ? (int) $args[0] : 0;
				
				if ( $action_item_id > 0 ) {
					// Check if item exists
					$item_exists = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
						$action_item_id
					) );
					
					if ( ! $item_exists ) {
						// Item doesn't exist - cancel this action
						// CRITICAL: Check initialization before calling as_unschedule_action
						if ( \AEBG\Core\ActionSchedulerHelper::ensure_initialized() && function_exists( 'as_unschedule_action' ) ) {
							as_unschedule_action( $hook, $args );
							$cleaned_count++;
							error_log( sprintf(
								'[AEBG] Cleaned up orphaned action for non-existent item_id=%d (step=%s)',
								$action_item_id,
								$step_key
							) );
						}
					}
				}
			}
		}
		
		error_log( sprintf(
			'[AEBG] Orphaned step cleanup completed. Cleaned %d orphaned actions.',
			$cleaned_count
		) );
	}
	
	/**
	 * Clean up log messages for old completed/failed items
	 * 
	 * Removes log_message field for items older than 30 days
	 * This reduces database size while keeping recent error messages for debugging
	 * 
	 * Runs daily via WP-Cron
	 * 
	 * @return int Number of items cleaned
	 */
	public function cleanup_old_log_messages() {
		global $wpdb;
		
		error_log( '[AEBG] Starting log message cleanup...' );
		
		// Clean up log_message for completed/failed/cancelled items older than 30 days
		// CRITICAL: Only clean items that are truly completed/failed/cancelled
		$cleaned = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}aebg_batch_items 
			SET log_message = NULL 
			WHERE status IN ('completed', 'failed', 'cancelled')
			AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)
			AND log_message IS NOT NULL",
			30
		) );
		
		if ( $cleaned !== false && $cleaned > 0 ) {
			error_log( sprintf( '[AEBG] 🧹 CLEANUP: Cleaned up log messages for %d old items (older than 30 days)', $cleaned ) );
		}
		
		return $cleaned !== false ? $cleaned : 0;
	}
	
	/**
	 * Clean up checkpoint state for completed items
	 * 
	 * Removes checkpoint_state for items that are completed
	 * Checkpoints are only needed for resume, not for completed items
	 * 
	 * Runs daily via WP-Cron
	 * 
	 * @return int Number of checkpoints cleaned
	 */
	public function cleanup_completed_checkpoints() {
		global $wpdb;
		
		error_log( '[AEBG] Starting checkpoint cleanup for completed items...' );
		
		// CRITICAL: Only clean checkpoints for completed items
		// NEVER clean checkpoints for processing/pending items (they might need to resume)
		$cleaned = $wpdb->query(
			"UPDATE {$wpdb->prefix}aebg_batch_items 
			SET checkpoint_state = NULL, checkpoint_step = NULL, last_checkpoint_at = NULL 
			WHERE status = 'completed'
			AND checkpoint_state IS NOT NULL"
		);
		
		if ( $cleaned !== false && $cleaned > 0 ) {
			error_log( sprintf( '[AEBG] 🧹 CLEANUP: Cleaned up %d checkpoint(s) for completed items', $cleaned ) );
		}
		
		// Also clean up old checkpoints for failed items (older than 1 day)
		// Failed items don't need checkpoints after 1 day
		$cleaned_failed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}aebg_batch_items 
			SET checkpoint_state = NULL, checkpoint_step = NULL, last_checkpoint_at = NULL 
			WHERE status = 'failed'
			AND last_checkpoint_at < DATE_SUB(NOW(), INTERVAL %d DAY)
			AND checkpoint_state IS NOT NULL",
			1
		) );
		
		if ( $cleaned_failed !== false && $cleaned_failed > 0 ) {
			error_log( sprintf( '[AEBG] 🧹 CLEANUP: Cleaned up %d old checkpoint(s) for failed items (older than 1 day)', $cleaned_failed ) );
		}
		
		return ( $cleaned !== false ? $cleaned : 0 ) + ( $cleaned_failed !== false ? $cleaned_failed : 0 );
	}
	
	/**
	 * Archive old batch items
	 * 
	 * Archives old batch items by clearing large fields while preserving essential data
	 * Keeps: id, batch_id, status, generated_post_id, source_title (truncated)
	 * Clears: log_message, checkpoint_state, checkpoint_step
	 * 
	 * Runs weekly via WP-Cron
	 * 
	 * @return int Number of items archived
	 */
	public function archive_old_batch_items() {
		global $wpdb;
		
		error_log( '[AEBG] Starting batch item archiving...' );
		
		// Archive items older than 90 days
		// CRITICAL: Preserve essential fields (id, batch_id, status, generated_post_id)
		// Only clear non-essential large fields
		$archived = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}aebg_batch_items 
			SET 
				log_message = NULL,
				checkpoint_state = NULL,
				checkpoint_step = NULL,
				last_checkpoint_at = NULL,
				source_title = CASE 
					WHEN LENGTH(source_title) > 50 THEN CONCAT(LEFT(source_title, 50), '... [archived]')
					ELSE source_title
				END
			WHERE status IN ('completed', 'failed', 'cancelled')
			AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)
			AND (log_message IS NOT NULL OR checkpoint_state IS NOT NULL)",
			90
		) );
		
		if ( $archived !== false && $archived > 0 ) {
			error_log( sprintf( '[AEBG] 🗄️ ARCHIVE: Archived %d old batch items (older than 90 days)', $archived ) );
		}
		
		return $archived !== false ? $archived : 0;
	}
	
	/**
	 * Clean up Action Scheduler logs
	 * 
	 * Removes Action Scheduler log entries older than 30 days
	 * These logs are for debugging only and not used by plugin logic
	 * 
	 * Runs daily via WP-Cron
	 * 
	 * @return int Number of log entries cleaned
	 */
	public function cleanup_action_scheduler_logs() {
		global $wpdb;
		
		error_log( '[AEBG] Starting Action Scheduler log cleanup...' );
		
		$logs_table = $wpdb->prefix . 'actionscheduler_logs';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$logs_table
		) );
		
		if ( ! $table_exists ) {
			error_log( '[AEBG] Action Scheduler logs table does not exist, skipping cleanup' );
			return 0;
		}
		
		// Clean up logs older than 30 days
		$cleaned = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$logs_table} 
			WHERE log_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)",
			30
		) );
		
		if ( $cleaned !== false && $cleaned > 0 ) {
			error_log( sprintf( '[AEBG] 🧹 CLEANUP: Cleaned up %d Action Scheduler log entries (older than 30 days)', $cleaned ) );
		}
		
		return $cleaned !== false ? $cleaned : 0;
	}
	
	/**
	 * Clean up completed Action Scheduler actions
	 * 
	 * Removes completed Action Scheduler actions older than 7 days
	 * These are internal Action Scheduler records and not used by plugin logic
	 * 
	 * Runs daily via WP-Cron
	 * 
	 * @return int Number of actions cleaned
	 */
	public function cleanup_action_scheduler_actions() {
		global $wpdb;
		
		error_log( '[AEBG] Starting Action Scheduler action cleanup...' );
		
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$actions_table
		) );
		
		if ( ! $table_exists ) {
			error_log( '[AEBG] Action Scheduler actions table does not exist, skipping cleanup' );
			return 0;
		}
		
		// Clean up completed actions older than 7 days
		$cleaned = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$actions_table} 
			WHERE status = 'complete' 
			AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL %d DAY)",
			7
		) );
		
		if ( $cleaned !== false && $cleaned > 0 ) {
			error_log( sprintf( '[AEBG] 🧹 CLEANUP: Cleaned up %d completed Action Scheduler actions (older than 7 days)', $cleaned ) );
		}
		
		// Also use Action Scheduler's built-in cleanup if available
		if ( class_exists( 'ActionScheduler_DataController' ) ) {
			try {
				$controller = \ActionScheduler_DataController::instance();
				if ( method_exists( $controller, 'cleanup' ) ) {
					$controller->cleanup();
					error_log( '[AEBG] 🧹 CLEANUP: Ran Action Scheduler built-in cleanup' );
				}
			} catch ( \Exception $e ) {
				error_log( '[AEBG] ⚠️ Error running Action Scheduler cleanup: ' . $e->getMessage() );
			}
		}
		
		return $cleaned !== false ? $cleaned : 0;
	}
	
	/**
	 * Clean up old Trustpilot cache entries
	 * 
	 * Removes Trustpilot rating cache entries older than 30 days
	 * 
	 * Runs weekly via WP-Cron
	 * 
	 * @return int Number of cache entries cleaned
	 */
	public function cleanup_old_competitor_scrapes() {
		global $wpdb;
		
		// Delete scrapes older than 90 days (keep 3 months of history)
		$days_to_keep = 90;
		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$days_to_keep} days" ) );
		
		// Get old scrape IDs
		$old_scrapes = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE created_at < %s AND status IN ('completed', 'failed')",
			$cutoff_date
		) );
		
		if ( empty( $old_scrapes ) ) {
			return;
		}
		
		// Sanitize all IDs to integers
		$scrape_ids = array_map( 'absint', $old_scrapes );
		$scrape_ids = array_filter( $scrape_ids ); // Remove any invalid IDs
		
		if ( empty( $scrape_ids ) ) {
			return;
		}
		
		// Use prepared statements for security - build placeholders dynamically
		$placeholders = implode( ',', array_fill( 0, count( $scrape_ids ), '%d' ) );
		
		// Delete associated products
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}aebg_competitor_products WHERE scrape_id IN ({$placeholders})",
			...$scrape_ids
		) );
		
		// Delete associated changes
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}aebg_competitor_changes WHERE scrape_id IN ({$placeholders})",
			...$scrape_ids
		) );
		
		// Delete scrapes
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}aebg_competitor_scrapes WHERE id IN ({$placeholders})",
			...$scrape_ids
		) );
		
		if ( $deleted > 0 ) {
			error_log( sprintf( '[AEBG] Cleaned up %d old competitor scrapes (older than %d days)', $deleted, $days_to_keep ) );
		}
	}

	public function cleanup_old_trustpilot_cache() {
		global $wpdb;
		
		error_log( '[AEBG] Starting Trustpilot cache cleanup...' );
		
		$table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			error_log( '[AEBG] Trustpilot ratings table does not exist, skipping cleanup' );
			return 0;
		}
		
		// Clean up cache entries older than 30 days
		$cleaned = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} 
			WHERE last_updated < DATE_SUB(NOW(), INTERVAL %d DAY)",
			30
		) );
		
		if ( $cleaned !== false && $cleaned > 0 ) {
			error_log( sprintf( '[AEBG] 🧹 CLEANUP: Cleaned up %d old Trustpilot cache entries (older than 30 days)', $cleaned ) );
		}
		
		return $cleaned !== false ? $cleaned : 0;
	}
	
	/**
	 * Log database size for monitoring
	 * 
	 * Logs the size of log-related database tables for monitoring
	 * Alerts if total size exceeds 500MB
	 * 
	 * Runs weekly via WP-Cron
	 * 
	 * @return array Database size information
	 */
	public function log_database_size() {
		global $wpdb;
		
		error_log( '[AEBG] Starting database size monitoring...' );
		
		$tables = [
			$wpdb->prefix . 'aebg_batches',
			$wpdb->prefix . 'aebg_batch_items',
			$wpdb->prefix . 'actionscheduler_logs',
			$wpdb->prefix . 'actionscheduler_actions',
			$wpdb->prefix . 'aebg_comparisons',
			$wpdb->prefix . 'aebg_trustpilot_ratings',
		];
		
		$total_size = 0;
		$sizes = [];
		
		foreach ( $tables as $table ) {
			$size = $wpdb->get_var( $wpdb->prepare(
				"SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) 
				FROM information_schema.TABLES 
				WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table
			) );
			
			$size_float = (float) ( $size ?? 0 );
			$total_size += $size_float;
			$sizes[ $table ] = $size_float;
			
			error_log( sprintf( '[AEBG] 📊 Table %s size: %.2f MB', $table, $size_float ) );
		}
		
		error_log( sprintf( '[AEBG] 📊 Total log tables size: %.2f MB', $total_size ) );
		
		// Alert if size exceeds threshold
		if ( $total_size > 500 ) {
			error_log( '[AEBG] ⚠️ WARNING: Log tables exceed 500MB. Consider running cleanup or increasing retention periods.' );
		}
		
		return [
			'total_size' => $total_size,
			'tables' => $sizes,
		];
	}
	
	/**
	 * Enqueue email signup widget assets
	 * 
	 * @return void
	 */
	/**
	 * Enqueue email signup assets for shortcode support
	 * 
	 * Note: These assets are used by the [aebg_email_signup] shortcode.
	 * Elementor Forms integration uses webhook approach (no custom widget needed).
	 * 
	 * @return void
	 */
	public function enqueue_email_signup_assets() {
		wp_enqueue_style(
			'aebg-email-signup-widget',
			AEBG_PLUGIN_URL . 'assets/css/email-signup-widget.css',
			[],
			AEBG_VERSION
		);
		
		wp_enqueue_script(
			'aebg-email-signup-widget',
			AEBG_PLUGIN_URL . 'assets/js/email-signup-widget.js',
			['jquery'],
			AEBG_VERSION,
			true
		);
		
		wp_localize_script( 'aebg-email-signup-widget', 'aebgEmailSignup', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
	}
	
	/**
	 * Handle uncaught exceptions, specifically Action Scheduler errors when actions are deleted.
	 *
	 * @param \Throwable $exception The uncaught exception.
	 * @return void
	 */
	public function handle_uncaught_exception( $exception ) {
		// Check if this is an Action Scheduler error about a deleted action
		if ( $exception instanceof \InvalidArgumentException ) {
			$message = $exception->getMessage();
			$file = $exception->getFile();
			
			// Check if it's the specific Action Scheduler error about deleted actions
			if ( strpos( $file, 'ActionScheduler_DBStore.php' ) !== false 
				&& strpos( $message, 'Unidentified action' ) !== false 
				&& strpos( $message, 'unable to mark this action as having failed' ) !== false ) {
				// This is the expected error when an action is deleted while executing
				// Log it but don't treat it as a fatal error
				error_log( sprintf(
					'[AEBG] ⚠️ Action Scheduler tried to mark a deleted action as failed - this is expected when actions are manually deleted. Exception: %s',
					$message
				) );
				return; // Exit gracefully - don't let it become a fatal error
			}
		}
		
		// For all other exceptions, let WordPress handle them normally
		// Restore the previous exception handler if it exists
		restore_exception_handler();
		
		// Re-throw the exception so WordPress can handle it
		throw $exception;
	}
	
	/**
	 * Suppress Action Scheduler initialization warnings for internal cleanup code
	 * 
	 * Action Scheduler's WPCommentCleaner may call as_next_scheduled_action() during initialization
	 * before the data store is ready. These warnings are from Action Scheduler's own internal code
	 * and are handled gracefully (the function returns false safely). We suppress these specific
	 * warnings to prevent noise in the error logs.
	 * 
	 * @param bool   $trigger Whether to trigger the error
	 * @param string $function_name The function name that was called incorrectly
	 * @param string $message The error message
	 * @return bool Whether to trigger the error
	 */
	public function suppress_action_scheduler_init_warnings( $trigger, $function_name, $message ) {
		// Suppress warnings for Action Scheduler functions called before data store initialization
		// This happens when Action Scheduler's internal cleanup code (WPCommentCleaner, Migration Controller)
		// runs during initialization before the data store is ready.
		// These warnings are safe to suppress because:
		// 1. They're from Action Scheduler's own internal code, not our code
		// 2. The functions return false safely when called before initialization
		// 3. Action Scheduler handles this gracefully and continues initialization
		
		if ( is_string( $function_name ) && is_string( $message ) ) {
			// Check for Action Scheduler initialization warnings
			$as_functions = [ 'as_next_scheduled_action', 'as_has_scheduled_action', 'as_get_scheduled_actions' ];
			if ( in_array( $function_name, $as_functions, true ) 
				&& ( strpos( $message, 'was called before the Action Scheduler data store was initialized' ) !== false
					|| strpos( $message, 'Action Scheduler data store was initialized' ) !== false ) ) {
				// Suppress during initialization phase (before or during init hook)
				// This is safe because Action Scheduler's own code handles this gracefully
				return false; // Suppress the warning
			}
		}
		
		return $trigger; // Let other warnings through
	}
}
