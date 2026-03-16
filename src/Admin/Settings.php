<?php

namespace AEBG\Admin;

/**
 * Admin Settings Class
 *
 * @package AEBG\Admin
 */
class Settings {
	/**
	 * Settings constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_aebg_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_aebg_save_networks_ajax', [ $this, 'ajax_save_networks' ] );
		add_action( 'wp_ajax_aebg_test_api', [ $this, 'ajax_test_api' ] );
		add_action( 'wp_ajax_aebg_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_aebg_test_datafeedr', [ $this, 'ajax_test_datafeedr' ] );
		add_action( 'wp_ajax_aebg_verify_settings', [ $this, 'ajax_verify_settings' ] );
		add_action( 'wp_ajax_aebg_debug_settings', [ $this, 'ajax_debug_settings' ] );
		add_action( 'wp_ajax_aebg_reset_action_scheduler', [ $this, 'ajax_reset_action_scheduler' ] );
		add_action( 'wp_ajax_aebg_cleanup_all_action_scheduler', [ $this, 'ajax_cleanup_all_action_scheduler' ] );
		add_action( 'wp_ajax_aebg_trigger_action_scheduler', [ $this, 'ajax_trigger_action_scheduler' ] );
		add_action( 'wp_ajax_aebg_debug_action_scheduler', [ $this, 'ajax_debug_action_scheduler' ] );
		add_action( 'wp_ajax_aebg_get_configured_networks', [ $this, 'ajax_get_configured_networks' ] );
		
		// Competitor Tracking AJAX handlers
		add_action( 'wp_ajax_aebg_add_competitor', [ $this, 'ajax_add_competitor' ] );
		add_action( 'wp_ajax_aebg_update_competitor', [ $this, 'ajax_update_competitor' ] );
		add_action( 'wp_ajax_aebg_delete_competitor', [ $this, 'ajax_delete_competitor' ] );
		add_action( 'wp_ajax_aebg_trigger_scrape', [ $this, 'ajax_trigger_scrape' ] );
		add_action( 'wp_ajax_aebg_get_competitor_history', [ $this, 'ajax_get_competitor_history' ] );
		
		// Network Analytics AJAX handlers
		add_action( 'wp_ajax_aebg_save_network_credentials', [ $this, 'ajax_save_network_credentials' ] );
		add_action( 'wp_ajax_aebg_test_network_credentials', [ $this, 'ajax_test_network_credentials' ] );
		add_action( 'wp_ajax_aebg_delete_network_credential', [ $this, 'ajax_delete_network_credential' ] );
		add_action( 'wp_ajax_aebg_get_network_credentials', [ $this, 'ajax_get_network_credentials' ] );
		
		// Auto-sync networks hook
		add_action( 'aebg_auto_sync_networks', [ $this, 'auto_sync_networks' ] );
	}
	
	/**
	 * Auto-sync networks from Datafeedr API
	 */
	public function auto_sync_networks() {
		$networks_manager = new \AEBG\Admin\Networks_Manager();
		$result = $networks_manager->sync_networks_from_api(false);
		
		if (is_wp_error($result)) {
			error_log('[AEBG] Auto-sync networks failed: ' . $result->get_error_message());
		} else {
			error_log('[AEBG] Auto-sync networks completed successfully');
		}
	}

	/**
	 * Check if Action Scheduler functions are available
	 * 
	 * @return bool
	 */
	private function is_action_scheduler_available() {
		// Check if the main functions exist
		$required_functions = [
			'as_get_scheduled_actions',
			'as_delete_action'
		];
		
		foreach ( $required_functions as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}
		
		return true;
	}

	/**
	 * Try to force-load Action Scheduler if not available
	 * 
	 * @return bool
	 */
	private function try_load_action_scheduler() {
		// If functions are already available, return true
		if ( $this->is_action_scheduler_available() ) {
			return true;
		}

		// Try to load from our vendor directory
		$action_scheduler_path = plugin_dir_path( dirname( __DIR__ ) ) . 'vendor/woocommerce/action-scheduler';
		
		if ( is_dir( $action_scheduler_path ) && file_exists( $action_scheduler_path . '/action-scheduler.php' ) ) {
			// Include the main Action Scheduler file
			require_once $action_scheduler_path . '/action-scheduler.php';
			
			// Check if it's now available
			if ( $this->is_action_scheduler_available() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get Action Scheduler status for debugging
	 * 
	 * @return array
	 */
	private function get_action_scheduler_status() {
		$status = [
			'as_get_scheduled_actions' => function_exists( 'as_get_scheduled_actions' ),
			'as_delete_action' => function_exists( 'as_delete_action' ),
			'ActionScheduler_class' => class_exists( '\ActionScheduler' ),
			'ActionScheduler_QueueRunner_class' => class_exists( '\ActionScheduler_QueueRunner' ),
		];
		
		return $status;
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// We're not using WordPress's built-in settings API since we handle saving via AJAX
		// This method is kept for compatibility but doesn't register any settings
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input The input.
	 * @return array
	 * 
	 * Handles the following fields:
	 * - api_key, model, temperature, max_tokens, top_p
	 * - default_prompt, system_message, system_message_enabled
	 * - content_style, language
	 * - datafeedr_access_id, datafeedr_secret_key, enable_datafeedr
	 * - batch_size, delay_between_requests
	 * - default_currency
	 * - All duplicate detection settings
	 */
	public function sanitize( $input ) {
		// Validate input parameter
		if (!is_array($input)) {
			error_log('[AEBG] Settings::sanitize - Invalid input parameter type: ' . gettype($input));
			return [];
		}
		
		$new_input = [];
		
		// API Key
		if ( isset( $input['api_key'] ) ) {
			$new_input['api_key'] = sanitize_text_field( $input['api_key'] );
		}

		// Google (Gemini) API Key - for Nano Banana image generation
		if ( isset( $input['google_api_key'] ) ) {
			$new_input['google_api_key'] = sanitize_text_field( $input['google_api_key'] );
		}
		
		// Model validation and sanitization
		if ( isset( $input['model'] ) ) {
			$model = sanitize_text_field( $input['model'] );
			$valid_models = ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'gpt-5.2', 'gpt-5-mini'];
			if (in_array($model, $valid_models)) {
				$new_input['model'] = $model;
			} else {
				$new_input['model'] = 'gpt-3.5-turbo'; // Default fallback
			}
		}
		
		// Temperature validation and sanitization
		if ( isset( $input['temperature'] ) ) {
			$temperature = floatval( $input['temperature'] );
			if ($temperature >= 0.0 && $temperature <= 2.0) {
				$new_input['temperature'] = $temperature;
			} else {
				$new_input['temperature'] = 0.7; // Default fallback
			}
		}
		
		// Max Tokens validation and sanitization
		if ( isset( $input['max_tokens'] ) ) {
			$max_tokens = intval( $input['max_tokens'] );
			if ($max_tokens >= 1 && $max_tokens <= 4000) {
				$new_input['max_tokens'] = $max_tokens;
			} else {
				$new_input['max_tokens'] = 1000; // Default fallback
			}
		}
		
		// Top P validation and sanitization
		if ( isset( $input['top_p'] ) ) {
			$top_p = floatval( $input['top_p'] );
			if ($top_p >= 0.0 && $top_p <= 1.0) {
				$new_input['top_p'] = $top_p;
			} else {
				$new_input['top_p'] = 1.0; // Default fallback
			}
		}
		
		// Default Prompt
		if ( isset( $input['default_prompt'] ) ) {
			$new_input['default_prompt'] = sanitize_textarea_field( $input['default_prompt'] );
		}
		
		// System Message
		if ( isset( $input['system_message'] ) ) {
			$new_input['system_message'] = sanitize_textarea_field( $input['system_message'] );
		}
		
		// System Message Enabled
		if ( isset( $input['system_message_enabled'] ) ) {
			$new_input['system_message_enabled'] = ( $input['system_message_enabled'] === '1' ) ? true : false;
		}
		
		// Content Style
		if ( isset( $input['content_style'] ) ) {
			$new_input['content_style'] = sanitize_text_field( $input['content_style'] );
		}
		
		// Language
		if ( isset( $input['language'] ) ) {
			$new_input['language'] = sanitize_text_field( $input['language'] );
		}
		
		// Datafeedr Access ID
		if ( isset( $input['datafeedr_access_id'] ) ) {
			$new_input['datafeedr_access_id'] = sanitize_text_field( $input['datafeedr_access_id'] );
		}
		
		// Datafeedr Secret Key
		if ( isset( $input['datafeedr_secret_key'] ) ) {
			$new_input['datafeedr_secret_key'] = sanitize_text_field( $input['datafeedr_secret_key'] );
		}
		
		// Enable Datafeedr
		if ( isset( $input['enable_datafeedr'] ) ) {
			$new_input['enable_datafeedr'] = ( $input['enable_datafeedr'] === '1' ) ? true : false;
		}
		
		// Datafeedr API Key (for backward compatibility)
		if ( isset( $input['datafeedr_api_key'] ) ) {
			$new_input['datafeedr_api_key'] = sanitize_text_field( $input['datafeedr_api_key'] );
		}
		
		// Batch Size validation and sanitization
		if ( isset( $input['batch_size'] ) ) {
			$batch_size = intval( $input['batch_size'] );
			if ($batch_size >= 1 && $batch_size <= 100) {
				$new_input['batch_size'] = $batch_size;
			} else {
				$new_input['batch_size'] = 10; // Default fallback
			}
		}
		
		// Duplicate Detection Settings
		if ( isset( $input['enable_duplicate_detection'] ) ) {
			$new_input['enable_duplicate_detection'] = ( $input['enable_duplicate_detection'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['prevent_same_product_different_suppliers'] ) ) {
			$new_input['prevent_same_product_different_suppliers'] = ( $input['prevent_same_product_different_suppliers'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['prevent_same_product_different_colors'] ) ) {
			$new_input['prevent_same_product_different_colors'] = ( $input['prevent_same_product_different_colors'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['duplicate_similarity_threshold'] ) ) {
			$threshold = floatval( $input['duplicate_similarity_threshold'] );
			if ($threshold >= 0.0 && $threshold <= 1.0) {
				$new_input['duplicate_similarity_threshold'] = $threshold;
			} else {
				$new_input['duplicate_similarity_threshold'] = 0.8; // Default fallback
			}
		}
		
		if ( isset( $input['prefer_higher_rating_for_duplicates'] ) ) {
			$new_input['prefer_higher_rating_for_duplicates'] = ( $input['prefer_higher_rating_for_duplicates'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['prefer_more_reviews_for_duplicates'] ) ) {
			$new_input['prefer_more_reviews_for_duplicates'] = ( $input['prefer_more_reviews_for_duplicates'] === '1' ) ? true : false;
		}
		
		// Delay Between Requests validation and sanitization
		if ( isset( $input['delay_between_requests'] ) ) {
			$delay = floatval( $input['delay_between_requests'] );
			if ($delay >= 0 && $delay <= 10) {
				$new_input['delay_between_requests'] = $delay;
			} else {
				$new_input['delay_between_requests'] = 1; // Default fallback
				add_settings_error( 'aebg_settings', 'invalid_delay', 
					'Delay between requests must be between 0 and 10 seconds. Using default value of 1 second.' );
			}
		}
		
		// Default Currency for Product Search validation and sanitization
		if ( isset( $input['default_currency'] ) ) {
			$currency = sanitize_text_field( $input['default_currency'] );
			$valid_currencies = [
				'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'DKK', 'SEK', 'NOK', 'CHF',
				'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'HRK', 'RUB', 'TRY', 'BRL', 'MXN',
				'INR', 'KRW', 'SGD', 'HKD', 'NZD', 'ZAR'
			];
			if (in_array($currency, $valid_currencies)) {
				$new_input['default_currency'] = $currency;
			} else {
				$new_input['default_currency'] = 'USD'; // Default fallback
			}
		}
		
		// Default Networks for Product Search validation and sanitization
		if ( isset( $input['default_networks'] ) ) {
			$networks = $input['default_networks'];
			error_log('[AEBG] Settings::sanitize - default_networks input: ' . json_encode($networks));
			error_log('[AEBG] Settings::sanitize - Input type: ' . gettype($networks));
			
			// Store the raw value exactly as received
			$new_input['default_networks'] = $networks;
		} else {
			error_log('[AEBG] Settings::sanitize - default_networks not set in input');
		}
		
		// Search only in configured networks option
		if ( isset( $input['search_configured_only'] ) ) {
			$new_input['search_configured_only'] = ( $input['search_configured_only'] === '1' ) ? true : false;
		}
		
		// Merchant Discovery Settings
		if ( isset( $input['enable_merchant_discovery'] ) ) {
			$new_input['enable_merchant_discovery'] = ( $input['enable_merchant_discovery'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['max_merchants_per_product'] ) ) {
			$max_merchants = intval( $input['max_merchants_per_product'] );
			if ($max_merchants >= 1 && $max_merchants <= 20) {
				$new_input['max_merchants_per_product'] = $max_merchants;
			} else {
				$new_input['max_merchants_per_product'] = 5; // Default fallback
			}
		}
		
		// Price Comparison Settings
		if ( isset( $input['enable_price_comparison'] ) ) {
			$new_input['enable_price_comparison'] = ( $input['enable_price_comparison'] === '1' ) ? true : false;
		}
		
		// Email Marketing Settings
		if ( isset( $input['aebg_email_marketing_enabled'] ) ) {
			update_option( 'aebg_email_marketing_enabled', ( $input['aebg_email_marketing_enabled'] === '1' ) ? true : false );
		}
		
		if ( isset( $input['aebg_email_campaign_post_update_enabled'] ) ) {
			update_option( 'aebg_email_campaign_post_update_enabled', ( $input['aebg_email_campaign_post_update_enabled'] === '1' ) ? true : false );
		}
		
		if ( isset( $input['aebg_email_campaign_product_reorder_enabled'] ) ) {
			update_option( 'aebg_email_campaign_product_reorder_enabled', ( $input['aebg_email_campaign_product_reorder_enabled'] === '1' ) ? true : false );
		}
		
		if ( isset( $input['aebg_email_campaign_product_replace_enabled'] ) ) {
			update_option( 'aebg_email_campaign_product_replace_enabled', ( $input['aebg_email_campaign_product_replace_enabled'] === '1' ) ? true : false );
		}
		
		if ( isset( $input['aebg_email_campaign_new_post_enabled'] ) ) {
			update_option( 'aebg_email_campaign_new_post_enabled', ( $input['aebg_email_campaign_new_post_enabled'] === '1' ) ? true : false );
		}
		
		if ( isset( $input['aebg_email_from_name'] ) ) {
			update_option( 'aebg_email_from_name', sanitize_text_field( $input['aebg_email_from_name'] ) );
		}
		
		if ( isset( $input['aebg_email_from_email'] ) ) {
			update_option( 'aebg_email_from_email', sanitize_email( $input['aebg_email_from_email'] ) );
		}
		
		if ( isset( $input['aebg_email_double_opt_in'] ) ) {
			update_option( 'aebg_email_double_opt_in', ( $input['aebg_email_double_opt_in'] === '1' ) ? true : false );
		}
		
		// Auto-process Elementor forms
		if ( isset( $input['aebg_email_auto_process_elementor_forms'] ) ) {
			update_option( 'aebg_email_auto_process_elementor_forms', ( $input['aebg_email_auto_process_elementor_forms'] === '1' ) ? true : false );
		}
		
		if ( isset( $input['min_merchant_count'] ) ) {
			$min_merchants = intval( $input['min_merchant_count'] );
			if ($min_merchants >= 1 && $min_merchants <= 10) {
				$new_input['min_merchant_count'] = $min_merchants;
			} else {
				$new_input['min_merchant_count'] = 2; // Default fallback
			}
		}
		
		if ( isset( $input['max_merchant_count'] ) ) {
			$max_merchants = intval( $input['max_merchant_count'] );
			if ($max_merchants >= 1 && $max_merchants <= 20) {
				$new_input['max_merchant_count'] = $max_merchants;
			} else {
				$new_input['max_merchant_count'] = 10; // Default fallback
			}
		}
		
		if ( isset( $input['prefer_lower_prices'] ) ) {
			$new_input['prefer_lower_prices'] = ( $input['prefer_lower_prices'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['prefer_higher_ratings'] ) ) {
			$new_input['prefer_higher_ratings'] = ( $input['prefer_higher_ratings'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['price_variance_threshold'] ) ) {
			$threshold = floatval( $input['price_variance_threshold'] );
			if ($threshold >= 0.0 && $threshold <= 1.0) {
				$new_input['price_variance_threshold'] = $threshold;
			} else {
				$new_input['price_variance_threshold'] = 0.2; // Default fallback
			}
		}
		
		if ( isset( $input['filter_by_price_comparison'] ) ) {
			$new_input['filter_by_price_comparison'] = ( $input['filter_by_price_comparison'] === '1' ) ? true : false;
		}
		
		if ( isset( $input['sort_by_price_comparison'] ) ) {
			$new_input['sort_by_price_comparison'] = ( $input['sort_by_price_comparison'] === '1' ) ? true : false;
		}
		
		// Negative Phrases
		if ( isset( $input['negative_phrases'] ) ) {
			$phrases = $input['negative_phrases'];
			$sanitized_phrases = [];
			
			// Handle array input (from JSON)
			if ( is_array( $phrases ) ) {
				foreach ( $phrases as $phrase ) {
					$sanitized = sanitize_text_field( trim( $phrase ) );
					if ( ! empty( $sanitized ) ) {
						$sanitized_phrases[] = $sanitized;
					}
				}
			} 
			// Handle JSON string input
			elseif ( is_string( $phrases ) ) {
				$decoded = json_decode( $phrases, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $phrase ) {
						$sanitized = sanitize_text_field( trim( $phrase ) );
						if ( ! empty( $sanitized ) ) {
							$sanitized_phrases[] = $sanitized;
						}
					}
				} else {
					// Handle comma-separated string
					$phrases_array = explode( ',', $phrases );
					foreach ( $phrases_array as $phrase ) {
						$sanitized = sanitize_text_field( trim( $phrase ) );
						if ( ! empty( $sanitized ) ) {
							$sanitized_phrases[] = $sanitized;
						}
					}
				}
			}
			
			// Remove duplicates and empty values
			$new_input['negative_phrases'] = array_values( array_unique( array_filter( $sanitized_phrases ) ) );
		} else {
			$new_input['negative_phrases'] = [];
		}
		
		// Excluded Merchants (by name)
		if ( isset( $input['excluded_merchants'] ) ) {
			$raw_merchants = $input['excluded_merchants'];
			$merchants = [];
			
			// Allow both array input and string (textarea) input
			if ( is_array( $raw_merchants ) ) {
				$merchants = $raw_merchants;
			} elseif ( is_string( $raw_merchants ) ) {
				// Split on newlines or commas
				$merchants = preg_split( '/[\r\n,]+/', $raw_merchants );
			}
			
			$sanitized_merchants = [];
			foreach ( $merchants as $merchant ) {
				$sanitized = sanitize_text_field( trim( (string) $merchant ) );
				if ( ! empty( $sanitized ) ) {
					$sanitized_merchants[] = $sanitized;
				}
			}
			
			// Remove duplicates and empty values
			$new_input['excluded_merchants'] = array_values( array_unique( $sanitized_merchants ) );
		}
		
		error_log('[AEBG] Settings::sanitize - Sanitized settings: ' . json_encode($new_input));
		
		return $new_input;
	}

	/**
	 * AJAX handler for saving settings.
	 */
	public function ajax_save_settings() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Get the settings from POST data
		$settings = isset( $_POST['settings'] ) ? $_POST['settings'] : [];
		
		// Validate input type
		if ( ! is_array( $settings ) ) {
			wp_send_json_error( 'Invalid settings format. Expected array.' );
			return;
		}
		
		if ( empty( $settings ) ) {
			wp_send_json_error( 'No settings provided' );
			return;
		}
		
		// Validate settings array size to prevent DoS
		if ( count( $settings ) > 100 ) {
			wp_send_json_error( 'Too many settings provided (maximum 100).' );
			return;
		}

		// Log the incoming settings for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AEBG] Incoming settings: ' . json_encode($settings) );
			
			// Special debug for networks
			if (isset($settings['default_networks'])) {
				error_log( '[AEBG] Incoming default_networks: ' . json_encode($settings['default_networks']) );
			}
		}

		// Sanitize the settings
		$sanitized_settings = $this->sanitize( $settings );
		
		// Add a timestamp to track when settings were last saved
		$sanitized_settings['last_saved'] = current_time( 'timestamp' );

		// Log the sanitized settings for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AEBG] Sanitized settings: ' . json_encode($sanitized_settings) );
			
			// Special debug for networks
			if (isset($sanitized_settings['default_networks'])) {
				error_log( '[AEBG] Sanitized default_networks: ' . json_encode($sanitized_settings['default_networks']) );
			}
		}

		// Get current settings for comparison
		$current_settings = get_option( 'aebg_settings', [] );
		
		// Log current settings for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AEBG] Current settings: ' . json_encode($current_settings) );
		}
		
		// Check if Datafeedr credentials are being set for the first time
		$should_sync_networks = false;
		$new_access_id = $sanitized_settings['datafeedr_access_id'] ?? '';
		$new_access_key = $sanitized_settings['datafeedr_secret_key'] ?? '';
		$new_enabled = isset($sanitized_settings['enable_datafeedr']) ? (bool)$sanitized_settings['enable_datafeedr'] : false;
		
		$old_access_id = $current_settings['datafeedr_access_id'] ?? '';
		$old_access_key = $current_settings['datafeedr_secret_key'] ?? '';
		$old_enabled = isset($current_settings['enable_datafeedr']) ? (bool)$current_settings['enable_datafeedr'] : false;
		
		// Check if Datafeedr credentials are being set for the first time
		if ($new_enabled && !empty($new_access_id) && !empty($new_access_key)) {
			// First time setting credentials (old values are empty)
			if (empty($old_access_id) && empty($old_access_key)) {
				$should_sync_networks = true;
				error_log('[AEBG] First time Datafeedr credentials detected, will auto-sync networks');
			}
			// Or credentials changed
			elseif ($old_access_id !== $new_access_id || $old_access_key !== $new_access_key) {
				$should_sync_networks = true;
				error_log('[AEBG] Datafeedr credentials changed, will auto-sync networks');
			}
		}
		
		// Save the settings
		$result = update_option( 'aebg_settings', $sanitized_settings );
		
		// Clear SettingsHelper cache after saving
		if (class_exists('\AEBG\Core\SettingsHelper')) {
			\AEBG\Core\SettingsHelper::clearCache();
		}

		// Auto-sync networks if Datafeedr credentials were just set
		if ($should_sync_networks && $result) {
			// Trigger network sync immediately (non-blocking via action)
			do_action('aebg_auto_sync_networks');
			error_log('[AEBG] Triggered auto-sync of networks from Datafeedr API');
		}

		// Log the result for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AEBG] Update option result: ' . ( $result ? 'true' : 'false' ) );
		}

		if ( $result ) {
			// Verify the settings were actually saved
			$saved_settings = get_option( 'aebg_settings', [] );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Verified saved settings: ' . json_encode($saved_settings) );
				if (isset($saved_settings['default_networks'])) {
					error_log( '[AEBG] Verified saved default_networks: ' . json_encode($saved_settings['default_networks']) );
					error_log( '[AEBG] Verified saved default_networks type: ' . gettype($saved_settings['default_networks']) );
					error_log( '[AEBG] Verified saved default_networks var_export: ' . var_export($saved_settings['default_networks'], true) );
				}
			}
			wp_send_json_success( 'Settings saved successfully' );
		} else {
			// Check if the settings are actually the same using proper array comparison
			$settings_changed = false;
			
			// Compare arrays properly
			if ( count( $sanitized_settings ) !== count( $current_settings ) ) {
				$settings_changed = true;
			} else {
				foreach ( $sanitized_settings as $key => $value ) {
					if ( ! isset( $current_settings[ $key ] ) || $current_settings[ $key ] !== $value ) {
						$settings_changed = true;
						break;
					}
				}
			}
			
			if ( ! $settings_changed ) {
				wp_send_json_success( 'Settings are already up to date' );
			} else {
				// Get more detailed error information
				$error_details = 'Update option returned false. Current settings: ' . json_encode($current_settings);
				$error_details .= ' | New settings: ' . json_encode($sanitized_settings);
				wp_send_json_error( 'Failed to save settings. ' . $error_details );
			}
		}
	}

	/**
	 * AJAX handler for saving network affiliate IDs.
	 */
	public function ajax_save_networks() {
		// Log the request for debugging
		error_log('[AEBG] ajax_save_networks called with POST data: ' . json_encode($_POST));
		
		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_save_networks_ajax' ) ) {
			error_log('[AEBG] Nonce verification failed');
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			error_log('[AEBG] User does not have manage_options permission');
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Get affiliate IDs from POST data
		$affiliate_ids = $_POST['affiliate_ids'] ?? [];
		error_log('[AEBG] Affiliate IDs received: ' . json_encode($affiliate_ids));
		
		// Initialize Networks Manager
		$networks_manager = new \AEBG\Admin\Networks_Manager();
		$user_id = get_current_user_id();
		$saved_count = 0;
		$errors = [];
		
		// Check if affiliate IDs table exists
		global $wpdb;
		$affiliate_table = $wpdb->prefix . 'aebg_affiliate_ids';
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$affiliate_table}'") === $affiliate_table;
		error_log('[AEBG] Affiliate table exists: ' . ($table_exists ? 'YES' : 'NO'));
		
		if (!$table_exists) {
			error_log('[AEBG] Creating affiliate IDs table...');
			// Try to create the table
			$sql = "CREATE TABLE IF NOT EXISTS `{$affiliate_table}` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`network_key` VARCHAR(100) NOT NULL,
				`affiliate_id` VARCHAR(255) NOT NULL,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`is_active` TINYINT(1) NOT NULL DEFAULT 1,
				`last_used` DATETIME NULL,
				`usage_count` INT(11) NOT NULL DEFAULT 0,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_network_user` (`network_key`, `user_id`),
				KEY `idx_network_key` (`network_key`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_is_active` (`is_active`),
				KEY `idx_last_used` (`last_used`)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;";
			
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			$result = dbDelta($sql);
			error_log('[AEBG] dbDelta result: ' . json_encode($result));
			
			if ($wpdb->last_error) {
				error_log('[AEBG] Table creation error: ' . $wpdb->last_error);
				wp_send_json_error( 'Failed to create affiliate IDs table: ' . $wpdb->last_error );
			}
			
			// Verify table was created
			$table_exists_after = $wpdb->get_var("SHOW TABLES LIKE '{$affiliate_table}'") === $affiliate_table;
			error_log('[AEBG] Table exists after creation: ' . ($table_exists_after ? 'YES' : 'NO'));
		}
		
		// Store each affiliate ID in the database
		foreach ( $affiliate_ids as $network => $id ) {
			$network = sanitize_text_field($network);
			$id = sanitize_text_field($id);
			
			if (!empty($id)) {
				error_log('[AEBG] Storing affiliate ID for network: ' . $network . ' = ' . $id);
				$result = $networks_manager->store_affiliate_id($network, $id, $user_id);
				if ($result !== false) {
					$saved_count++;
					error_log('[AEBG] Successfully stored affiliate ID for network: ' . $network);
				} else {
					$errors[] = "Failed to save affiliate ID for network: $network";
					error_log('[AEBG] Failed to store affiliate ID for network: ' . $network);
				}
			}
		}
		
		// Also update WordPress options for backward compatibility
		$settings = get_option( 'aebg_settings', [] );
		$settings['affiliate_ids'] = $affiliate_ids;
		update_option( 'aebg_settings', $settings );
		
		error_log('[AEBG] Save completed. Saved count: ' . $saved_count . ', Errors: ' . json_encode($errors));
		
		if ( $saved_count > 0 ) {
			wp_send_json_success( "Successfully saved $saved_count affiliate IDs" );
		} else {
			wp_send_json_error( 'No affiliate IDs were saved. ' . implode(', ', $errors) );
		}
	}

	/**
	 * AJAX handler for testing API connection.
	 */
	public function ajax_test_api() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = self::get_settings();
		
		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( 'API key is required' );
		}

		$start_time = microtime( true );

		// ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
		$model = $settings['model'] ?? 'gpt-4o';
		$request_body = array_merge(
			[
				'model' => $model,
				'messages' => [
					[
						'role' => 'user',
						'content' => 'Hello, this is a test message.'
					]
				],
			],
			\AEBG\Core\APIClient::getCompletionLimitParam( $model, 10 )
		);
		
		$data = \AEBG\Core\APIClient::makeRequest(
			'https://api.openai.com/v1/chat/completions',
			$settings['api_key'],
			$request_body,
			30,
			1 // Only 1 retry for test connection
		);

		$end_time = microtime( true );
		$response_time = round( ( $end_time - $start_time ) * 1000, 2 ); // Convert to milliseconds

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( 'Connection failed: ' . $data->get_error_message() );
		}

		if ( empty( $data ) || !is_array( $data ) ) {
			wp_send_json_error( 'API returned empty or invalid response' );
		}

		if ( isset( $data['error'] ) ) {
			wp_send_json_error( 'API Error: ' . ( $data['error']['message'] ?? 'Unknown API error' ) );
		}
		
		// Get status code from response (APIClient returns data directly on success)
		$status_code = 200; // If we got here, it's a success

		// Return detailed success information
		$result = [
			'model' => $settings['model'] ?? 'gpt-4o',
			'response_time' => $response_time . 'ms',
			'status' => 'Connected successfully',
			'status_code' => $status_code,
			'usage' => isset( $data['usage'] ) ? $data['usage'] : 'No usage data available',
			'message' => 'API connection test completed successfully'
		];

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for testing connection.
	 */
	public function ajax_test_connection() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = self::get_settings();
		
		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( 'API key is required' );
		}

		// ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
		$model = $settings['model'] ?? 'gpt-4o';
		$request_body = array_merge(
			[
				'model' => $model,
				'messages' => [
					[
						'role' => 'user',
						'content' => 'Hello, this is a test message.'
					]
				],
			],
			\AEBG\Core\APIClient::getCompletionLimitParam( $model, 10 )
		);
		
		$data = \AEBG\Core\APIClient::makeRequest(
			'https://api.openai.com/v1/chat/completions',
			$settings['api_key'],
			$request_body,
			30,
			1 // Only 1 retry for test connection
		);

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( 'Connection failed: ' . $data->get_error_message() );
		}

		if ( empty( $data ) || !is_array( $data ) ) {
			wp_send_json_error( 'API returned empty or invalid response' );
		}

		if ( isset( $data['error'] ) ) {
			wp_send_json_error( 'API Error: ' . ( $data['error']['message'] ?? 'Unknown API error' ) );
		}

		wp_send_json_success( 'Connection successful' );
	}

	/**
	 * AJAX handler for testing Datafeedr connection.
	 */
	public function ajax_test_datafeedr() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = self::get_settings();
		
		// Check if Datafeedr is enabled
		if ( ! isset( $settings['enable_datafeedr'] ) || ! $settings['enable_datafeedr'] ) {
			wp_send_json_error( 'Datafeedr integration is disabled. Please enable it in the settings.' );
		}

		// Check if credentials are provided
		if ( empty( $settings['datafeedr_access_id'] ) ) {
			wp_send_json_error( 'Datafeedr Access ID is required.' );
		}

		if ( empty( $settings['datafeedr_secret_key'] ) ) {
			wp_send_json_error( 'Datafeedr Access Key is required.' );
		}

		// Test the Datafeedr connection
		$datafeedr = new \AEBG\Core\Datafeedr();
		$result = $datafeedr->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( 'Datafeedr connection successful' );
		}
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( 'aebg_settings', [] );
		
		// Set default values only for fields that don't exist in saved settings
		$defaults = [
			'language' => 'da', // Default to Danish
			'model' => 'gpt-4o',
			'temperature' => 0.7,
			'max_tokens' => 1000,
			'top_p' => 1.0,
			'content_style' => 'professional',
			'batch_size' => 5,
			'delay_between_requests' => 1,
			'enable_duplicate_detection' => true,
			'prevent_same_product_different_suppliers' => true,
			'prevent_same_product_different_colors' => true,
			'duplicate_similarity_threshold' => 0.85,
			'prefer_higher_rating_for_duplicates' => true,
			'prefer_more_reviews_for_duplicates' => true,
			// Merchant Discovery Settings
			'enable_merchant_discovery' => true,
			'max_merchants_per_product' => 5,
			// Price Comparison Settings
			'enable_price_comparison' => true,
			'min_merchant_count' => 2,
			'max_merchant_count' => 10,
			'prefer_lower_prices' => true,
			'prefer_higher_ratings' => true,
			'price_variance_threshold' => 0.3,
			'filter_by_price_comparison' => false,
			'sort_by_price_comparison' => false,
			// Default Networks for Product Search - only set default if not already saved
			'search_configured_only' => false,
			// Competitor Tracking Settings
			'competitor_tracking_enabled' => true,
			'competitor_default_scraping_interval' => 3600, // 1 hour
			'competitor_max_scrape_time' => 30, // seconds
			'competitor_ai_retry_count' => 3,
			'competitor_enable_notifications' => false,
			'system_message' => 'You are an expert content creator specializing in e-commerce and product reviews. You have deep knowledge of consumer products, SEO best practices, and content marketing. Always respond in a professional, engaging manner that provides value to readers. Follow these guidelines:

1. Write in the specified language (Danish, Swedish, Norwegian, or English)
2. Use SEO-optimized content structure with proper headings and formatting
3. Include relevant product information when available
4. Maintain consistent tone and style throughout the content
5. Focus on providing valuable insights and helpful information
6. Use natural, conversational language that connects with readers
7. Include specific details and examples when possible
8. Structure content for easy reading with bullet points and paragraphs
9. Always prioritize user value and engagement',
			'system_message_enabled' => true,
			'negative_phrases' => [],
			// Default merchants to exclude from Datafeedr search results
			'excluded_merchants' => [
				'Ultrashop',
				'Homeshop',
				'Boligcenter.dk',
			],
		];
		
		// Remove default_networks from defaults since we handle it specially
		unset($defaults['default_networks']);
		
		// Merge defaults with existing settings
		$merged_settings = array_merge( $defaults, $settings );
		
		// Clean up negative phrases if they exist (remove corrupted data)
		// Only clean on read, don't auto-save to avoid interfering with user saves
		if ( isset( $merged_settings['negative_phrases'] ) && is_array( $merged_settings['negative_phrases'] ) ) {
			$cleaned_phrases = array_values( array_filter( array_map( function( $phrase ) {
				if ( is_string( $phrase ) ) {
					$trimmed = trim( $phrase );
					// Skip if it looks like a JSON array (corrupted data)
					if ( ! empty( $trimmed ) && ! ( strlen( $trimmed ) > 1 && $trimmed[0] === '[' && substr( $trimmed, -1 ) === ']' ) ) {
						return $trimmed;
					}
				}
				return null;
			}, $merged_settings['negative_phrases'] ) ) );
			
			$merged_settings['negative_phrases'] = $cleaned_phrases;
		}
		
		// Special handling for default_networks - preserve saved value or set empty array
		if ( array_key_exists( 'default_networks', $settings ) ) {
			$default_networks = $settings['default_networks'];
			
			// If it's already an array, use it directly
			if (is_array($default_networks)) {
				$merged_settings['default_networks'] = $default_networks;
			} 
			// If it's a string, try to decode it as JSON
			elseif (is_string($default_networks)) {
				$clean_string = trim($default_networks);
				$decoded = json_decode($clean_string, true);
				
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
					$merged_settings['default_networks'] = $decoded;
					error_log('[AEBG] Settings::get_settings - Successfully decoded JSON to array');
				} else {
					error_log('[AEBG] Settings::get_settings - Failed to decode default_networks JSON: ' . $default_networks);
					error_log('[AEBG] Settings::get_settings - JSON error: ' . json_last_error_msg());
					$merged_settings['default_networks'] = [];
				}
			} 
			// Fallback to empty array for any other type
			else {
				error_log('[AEBG] Settings::get_settings - Unexpected type for default_networks: ' . gettype($default_networks));
				$merged_settings['default_networks'] = [];
			}
		} else {
			// Only if the key doesn't exist at all, set default empty array
			$merged_settings['default_networks'] = [];
		}
		
		return $merged_settings;
	}

	/**
	 * AJAX handler for verifying settings.
	 */
	public function ajax_verify_settings() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = self::get_settings();
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AEBG] Verifying settings: ' . json_encode($settings) );
		}

		wp_send_json_success( $settings );
	}

	/**
	 * AJAX handler for debugging settings.
	 * SECURITY: Only available when WP_DEBUG is enabled and user has manage_options capability.
	 */
	public function ajax_debug_settings() {
		// Only allow in development mode
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			wp_send_json_error( 'Debug mode is disabled.' );
			return;
		}

		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
			return;
		}

		// Get settings but sanitize sensitive data
		$settings = self::get_settings();
		$sanitized_settings = $settings;
		
		// Remove sensitive data from debug output
		if ( isset( $sanitized_settings['api_key'] ) ) {
			$sanitized_settings['api_key'] = ! empty( $sanitized_settings['api_key'] ) ? '***REDACTED***' : '';
		}
		if ( isset( $sanitized_settings['datafeedr_access_id'] ) ) {
			$sanitized_settings['datafeedr_access_id'] = ! empty( $sanitized_settings['datafeedr_access_id'] ) ? '***REDACTED***' : '';
		}
		if ( isset( $sanitized_settings['datafeedr_secret_key'] ) ) {
			$sanitized_settings['datafeedr_secret_key'] = ! empty( $sanitized_settings['datafeedr_secret_key'] ) ? '***REDACTED***' : '';
		}
		if ( isset( $sanitized_settings['google_api_key'] ) ) {
			$sanitized_settings['google_api_key'] = ! empty( $sanitized_settings['google_api_key'] ) ? '***REDACTED***' : '';
		}

		$debug_info = [
			'current_settings' => $sanitized_settings,
			'wp_options_table_exists' => get_option( 'aebg_settings' ) !== false,
			'user_can_manage_options' => current_user_can( 'manage_options' ),
			'wp_debug' => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
			// Do not expose $_POST data in production
		];

		wp_send_json_success( $debug_info );
	}

	/**
	 * AJAX handler for debugging Action Scheduler status.
	 */
	public function ajax_debug_action_scheduler() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$status = $this->get_action_scheduler_status();
		wp_send_json_success( $status );
	}

	/**
	 * Render the API key field.
	 */
	public function render_api_key_field() {
		$options = get_option( 'aebg_settings' );
		printf(
			'<input type="text" id="aebg_api_key" name="aebg_settings[api_key]" value="%s" />',
			isset( $options['api_key'] ) ? esc_attr( $options['api_key'] ) : ''
		);
		echo '<p class="description">' . __( 'Enter your AI API key. It is recommended to define this in your wp-config.php file as `define( \'AEBG_AI_API_KEY\', \'your-key\' );`', 'aebg' ) . '</p>';
	}

	/**
	 * Render the model field.
	 */
	public function render_model_field() {
		$options = get_option( 'aebg_settings' );
		printf(
			'<input type="text" id="aebg_model" name="aebg_settings[model]" value="%s" />',
			isset( $options['model'] ) ? esc_attr( $options['model'] ) : 'gpt-4o'
		);
	}

	/**
	 * Render the temperature field.
	 */
	public function render_temperature_field() {
		$options = get_option( 'aebg_settings' );
		printf(
			'<input type="text" id="aebg_temperature" name="aebg_settings[temperature]" value="%s" />',
			isset( $options['temperature'] ) ? esc_attr( $options['temperature'] ) : '0.7'
		);
	}

	/**
	 * Render the Datafeedr API key field.
	 */
	public function render_datafeedr_api_key_field() {
		$options = get_option( 'aebg_settings' );
		printf(
			'<input type="text" id="aebg_datafeedr_api_key" name="aebg_settings[datafeedr_api_key]" value="%s" />',
			isset( $options['datafeedr_api_key'] ) ? esc_attr( $options['datafeedr_api_key'] ) : ''
		);
	}

	/**
	 * AJAX handler for resetting action scheduler.
	 */
	public function ajax_reset_action_scheduler() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Check if Action Scheduler functions are available
		if ( ! $this->is_action_scheduler_available() ) {
			// Try to load Action Scheduler
			if ( ! $this->try_load_action_scheduler() ) {
				$status = $this->get_action_scheduler_status();
				$error_message = 'Action Scheduler functions are not available. ';
				$error_message .= 'Status: ' . json_encode( $status );
				wp_send_json_error( $error_message );
			}
		}

		try {
			// Use ActionSchedulerHelper to ensure proper initialization check
			if ( ! class_exists( '\AEBG\Core\ActionSchedulerHelper' ) || ! \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
				wp_send_json_error( 'Action Scheduler is not initialized. Please wait a moment and try again.' );
				return;
			}
			
			// Get all scheduled actions for our plugin
			$scheduled_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => 'aebg_execute_generation',
				'status' => 'pending',
				'per_page' => -1,
			] );

			$deleted_count = 0;

			// Delete each scheduled action
			foreach ( $scheduled_actions as $action ) {
				if ( method_exists( $action, 'get_id' ) ) {
					as_delete_action( $action->get_id() );
					$deleted_count++;
				}
			}

			// Also clear any completed/failed actions for our plugin
			$completed_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => 'aebg_execute_generation',
				'status' => 'complete',
				'per_page' => -1,
			] );

			foreach ( $completed_actions as $action ) {
				if ( method_exists( $action, 'get_id' ) ) {
					as_delete_action( $action->get_id() );
					$deleted_count++;
				}
			}

			$failed_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => 'aebg_execute_generation',
				'status' => 'failed',
				'per_page' => -1,
			] );

			foreach ( $failed_actions as $action ) {
				if ( method_exists( $action, 'get_id' ) ) {
					as_delete_action( $action->get_id() );
					$deleted_count++;
				}
			}

			// Clear any running actions
			$running_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => 'aebg_execute_generation',
				'status' => 'in-progress',
				'per_page' => -1,
			] );

			foreach ( $running_actions as $action ) {
				if ( method_exists( $action, 'get_id' ) ) {
					as_delete_action( $action->get_id() );
					$deleted_count++;
				}
			}

			// Reset batch statuses to pending
			global $wpdb;
			// Use direct queries for status updates (status values are hardcoded, safe)
			// But use prepare for consistency and future-proofing
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_batches SET status = 'pending' WHERE status IN (%s, %s)",
				'scheduled',
				'in_progress'
			) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_batch_items SET status = 'pending' WHERE status = %s",
				'processing'
			) );

			wp_send_json_success( [
				'message' => sprintf( 'Action Scheduler reset successfully. Deleted %d scheduled actions.', $deleted_count ),
				'deleted_count' => $deleted_count
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( 'Failed to reset Action Scheduler: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for complete Action Scheduler cleanup (deletes ALL entries).
	 * WARNING: This will delete ALL Action Scheduler entries, not just plugin-specific ones.
	 */
	public function ajax_cleanup_all_action_scheduler() {
		// Verify nonce
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have permission to perform this action.' );
		}

		global $wpdb;

		// Set execution time limit
		@set_time_limit( 300 );
		@ini_set( 'max_execution_time', 300 );

		$total_deleted = 0;
		$results = [];

		// Action Scheduler table names
		$tables = [
			'actions' => $wpdb->prefix . 'actionscheduler_actions',
			'logs' => $wpdb->prefix . 'actionscheduler_logs',
			'claims' => $wpdb->prefix . 'actionscheduler_claims',
		];

		// Check which tables exist and delete all entries
		foreach ( $tables as $name => $table_name ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables 
				WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table_name
			) );

			if ( $exists ) {
				// Get count before deletion
				$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

				// Delete all entries
				$deleted = $wpdb->query( "DELETE FROM {$table_name}" );

				if ( $deleted !== false ) {
					$total_deleted += $deleted;
					$results[ $name ] = [
						'count_before' => $count_before,
						'deleted' => $deleted,
						'success' => true,
					];
				} else {
					$results[ $name ] = [
						'count_before' => $count_before,
						'deleted' => 0,
						'success' => false,
						'error' => $wpdb->last_error,
					];
				}
			} else {
				$results[ $name ] = [
					'exists' => false,
				];
			}
		}

		// Try Action Scheduler's built-in cleanup
		if ( class_exists( 'ActionScheduler_DataController' ) ) {
			try {
				$controller = \ActionScheduler_DataController::instance();
				if ( method_exists( $controller, 'cleanup' ) ) {
					$controller->cleanup();
					$results['builtin_cleanup'] = true;
				}
			} catch ( \Exception $e ) {
				$results['builtin_cleanup'] = [
					'success' => false,
					'error' => $e->getMessage(),
				];
			}
		}

		// Clear WordPress cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		wp_send_json_success( [
			'message' => sprintf( 'Action Scheduler cleanup completed. Deleted %d total entries.', $total_deleted ),
			'total_deleted' => $total_deleted,
			'details' => $results,
		] );
	}

	/**
	 * AJAX handler for triggering action scheduler.
	 */
	public function ajax_trigger_action_scheduler() {
		// Verify nonce - check both possible field names
		$nonce = $_POST['nonce'] ?? $_POST['_ajax_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Check if Action Scheduler functions are available
		if ( ! $this->is_action_scheduler_available() ) {
			// Try to load Action Scheduler
			if ( ! $this->try_load_action_scheduler() ) {
				$status = $this->get_action_scheduler_status();
				$error_message = 'Action Scheduler functions are not available. ';
				$error_message .= 'Status: ' . json_encode( $status );
				wp_send_json_error( $error_message );
			}
		}

		try {
			// Use ActionSchedulerHelper to ensure proper initialization check
			if ( ! class_exists( '\AEBG\Core\ActionSchedulerHelper' ) || ! \AEBG\Core\ActionSchedulerHelper::ensure_initialized() ) {
				wp_send_json_error( 'Action Scheduler is not initialized. Please wait a moment and try again.' );
				return;
			}
			
			// Get pending actions
			$pending_actions = \AEBG\Core\ActionSchedulerHelper::get_scheduled_actions( [
				'hook' => 'aebg_execute_generation',
				'status' => 'pending',
				'per_page' => 5, // Process only 5 at a time
			] );

			$processed_count = 0;

			foreach ( $pending_actions as $action ) {
				// Check if action has required methods before using them
				if ( method_exists( $action, 'get_args' ) ) {
					$args = $action->get_args();
					if ( ! empty( $args ) && isset( $args[0]['item_id'] ) ) {
						// Trigger the action manually
						do_action( 'aebg_execute_generation', $args[0]['item_id'] );
						$processed_count++;
					}
				}
			}

			wp_send_json_success( [
				'message' => sprintf( 'Manually triggered %d pending actions.', $processed_count ),
				'processed_count' => $processed_count
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( 'Failed to trigger Action Scheduler: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX handler for getting configured networks.
	 */
	public function ajax_get_configured_networks() {
		// Verify nonce
		if ( ! wp_verify_nonce( $_POST['nonce'], 'aebg_ajax_nonce' ) ) {
			wp_send_json_error( 'Security check failed' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		try {
			// Initialize Networks Manager
			$networks_manager = new \AEBG\Admin\Networks_Manager();
			
			// Get all networks with their configuration status
			$networks = $networks_manager->get_all_networks_with_status();
			
			if ( is_wp_error( $networks ) ) {
				wp_send_json_error( 'Failed to get networks: ' . $networks->get_error_message() );
			}

			wp_send_json_success( $networks );
			
		} catch ( Exception $e ) {
			wp_send_json_error( 'Error getting networks: ' . $e->getMessage() );
		}
	}
	
	/**
	 * AJAX handler: Add competitor
	 */
	public function ajax_add_competitor() {
		check_ajax_referer( 'aebg_competitor_tracking', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ] );
		}
		
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		$url = esc_url_raw( $_POST['url'] ?? '' );
		$interval = absint( $_POST['scraping_interval'] ?? 3600 );
		
		if ( empty( $name ) || empty( $url ) ) {
			wp_send_json_error( [ 'message' => __( 'Name and URL are required.', 'aebg' ) ] );
		}
		
		$result = \AEBG\Core\CompetitorTrackingManager::add_competitor( get_current_user_id(), $name, $url, $interval );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		
		wp_send_json_success( [
			'message'       => __( 'Competitor added successfully.', 'aebg' ),
			'competitor_id' => $result,
		] );
	}
	
	/**
	 * AJAX handler: Update competitor
	 */
	public function ajax_update_competitor() {
		check_ajax_referer( 'aebg_competitor_tracking', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ] );
		}
		
		$competitor_id = absint( $_POST['competitor_id'] ?? 0 );
		if ( ! $competitor_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid competitor ID.', 'aebg' ) ] );
		}
		
		$data = [];
		if ( isset( $_POST['name'] ) ) {
			$data['name'] = sanitize_text_field( $_POST['name'] );
		}
		if ( isset( $_POST['url'] ) ) {
			$data['url'] = esc_url_raw( $_POST['url'] );
		}
		if ( isset( $_POST['scraping_interval'] ) ) {
			$data['scraping_interval'] = absint( $_POST['scraping_interval'] );
		}
		if ( isset( $_POST['is_active'] ) ) {
			$data['is_active'] = (int) $_POST['is_active'];
		}
		
		$result = \AEBG\Core\CompetitorTrackingManager::update_competitor( $competitor_id, $data );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		
		wp_send_json_success( [ 'message' => __( 'Competitor updated successfully.', 'aebg' ) ] );
	}
	
	/**
	 * AJAX handler: Delete competitor
	 */
	public function ajax_delete_competitor() {
		check_ajax_referer( 'aebg_competitor_tracking', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ] );
		}
		
		$competitor_id = absint( $_POST['competitor_id'] ?? 0 );
		if ( ! $competitor_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid competitor ID.', 'aebg' ) ] );
		}
		
		$result = \AEBG\Core\CompetitorTrackingManager::delete_competitor( $competitor_id );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		
		wp_send_json_success( [ 'message' => __( 'Competitor deleted successfully.', 'aebg' ) ] );
	}
	
	/**
	 * AJAX handler: Trigger manual scrape
	 */
	public function ajax_trigger_scrape() {
		check_ajax_referer( 'aebg_competitor_tracking', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ] );
		}
		
		$competitor_id = absint( $_POST['competitor_id'] ?? 0 );
		if ( ! $competitor_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid competitor ID.', 'aebg' ) ] );
		}
		
		// Schedule immediate scrape (delay = 1 second)
		$action_id = \AEBG\Core\CompetitorTrackingManager::schedule_scrape( $competitor_id, 1 );
		
		if ( ! $action_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to schedule scrape.', 'aebg' ) ] );
		}
		
		wp_send_json_success( [ 'message' => __( 'Scrape scheduled successfully.', 'aebg' ) ] );
	}
	
	/**
	 * AJAX handler: Get competitor history
	 */
	public function ajax_get_competitor_history() {
		check_ajax_referer( 'aebg_competitor_tracking', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aebg' ) ] );
		}
		
		// Disable caching for this response
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		
		$competitor_id = absint( $_GET['competitor_id'] ?? 0 );
		if ( ! $competitor_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid competitor ID.', 'aebg' ) ] );
		}
		
		global $wpdb;
		
		// Get competitor info
		$competitor = \AEBG\Core\CompetitorTrackingManager::get_competitor( $competitor_id );
		
		// Get scrapes
		$scrapes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitor_scrapes 
			WHERE competitor_id = %d 
			ORDER BY created_at DESC 
			LIMIT 50",
			$competitor_id
		), ARRAY_A );
		
		// Get products from latest scrape
		$latest_scrape_id = $scrapes[0]['id'] ?? 0;
		$products = [];
		if ( $latest_scrape_id ) {
			$products_raw = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aebg_competitor_products 
				WHERE competitor_id = %d AND scrape_id = %d 
				ORDER BY position ASC",
				$competitor_id,
				$latest_scrape_id
			), ARRAY_A );
			
			// Decode product_data JSON for each product
			foreach ( $products_raw as $product ) {
				if ( ! empty( $product['product_data'] ) ) {
					$product['product_data'] = json_decode( $product['product_data'], true );
				}
				$products[] = $product;
			}
		}
		
		// Get changes
		$changes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}aebg_competitor_changes 
			WHERE competitor_id = %d 
			ORDER BY created_at DESC 
			LIMIT 50",
			$competitor_id
		), ARRAY_A );
		
		wp_send_json_success( [
			'competitor' => $competitor,
			'scrapes'    => $scrapes,
			'products'   => $products,
			'changes'    => $changes,
		] );
	}

	/**
	 * AJAX handler for saving network credentials
	 */
	public function ajax_save_network_credentials() {
		check_ajax_referer('aebg_ajax_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$network_key = sanitize_key($_POST['network_key'] ?? '');
		$credentials = $_POST['credentials'] ?? [];

		if (empty($network_key)) {
			wp_send_json_error(['message' => 'Network key is required']);
		}

		if (empty($credentials) || !is_array($credentials)) {
			wp_send_json_error(['message' => 'Credentials are required']);
		}

		$manager = new \AEBG\Core\Network_API_Manager();
		$user_id = get_current_user_id();
		$saved_count = 0;
		$errors = [];

		foreach ($credentials as $credential_type => $credential_value) {
			$credential_type = sanitize_key($credential_type);
			$credential_value = sanitize_text_field($credential_value);

			if (empty($credential_value) || $credential_value === '••••••••') {
				continue; // Skip empty or masked values
			}

			$result = $manager->store_credential($network_key, $credential_type, $credential_value, $user_id);

			if (is_wp_error($result)) {
				$errors[] = $result->get_error_message();
			} else {
				$saved_count++;
			}
		}

		if (!empty($errors)) {
			wp_send_json_error([
				'message' => 'Some credentials failed to save',
				'errors' => $errors,
				'saved_count' => $saved_count,
			]);
		}

		wp_send_json_success([
			'message' => sprintf('%d credential(s) saved successfully', $saved_count),
			'saved_count' => $saved_count,
		]);
	}

	/**
	 * AJAX handler for testing network credentials
	 */
	public function ajax_test_network_credentials() {
		check_ajax_referer('aebg_ajax_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$network_key = sanitize_key($_POST['network_key'] ?? '');

		if (empty($network_key)) {
			wp_send_json_error(['message' => 'Network key is required']);
		}

		$manager = new \AEBG\Core\Network_API_Manager();
		$user_id = get_current_user_id();

		$result = $manager->test_connection($network_key, $user_id);

		if (is_wp_error($result)) {
			wp_send_json_error([
				'message' => 'Connection test failed',
				'error' => $result->get_error_message(),
			]);
		}

		wp_send_json_success([
			'message' => 'Connection test successful',
		]);
	}

	/**
	 * AJAX handler for deleting network credential
	 */
	public function ajax_delete_network_credential() {
		check_ajax_referer('aebg_ajax_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$network_key = sanitize_key($_POST['network_key'] ?? '');
		$credential_type = sanitize_key($_POST['credential_type'] ?? '');

		if (empty($network_key) || empty($credential_type)) {
			wp_send_json_error(['message' => 'Network key and credential type are required']);
		}

		$manager = new \AEBG\Core\Network_API_Manager();
		$user_id = get_current_user_id();

		$result = $manager->delete_credential($network_key, $credential_type, $user_id);

		if (!$result) {
			wp_send_json_error(['message' => 'Failed to delete credential']);
		}

		wp_send_json_success(['message' => 'Credential deleted successfully']);
	}

	/**
	 * AJAX handler for getting network credentials status
	 */
	public function ajax_get_network_credentials() {
		check_ajax_referer('aebg_ajax_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
		}

		$network_key = sanitize_key($_GET['network_key'] ?? '');

		if (empty($network_key)) {
			wp_send_json_error(['message' => 'Network key is required']);
		}

		$manager = new \AEBG\Core\Network_API_Manager();
		$registry = new \AEBG\Core\Network_API\Network_Registry();
		$user_id = get_current_user_id();

		$all_credentials = $manager->get_all_credentials($network_key, $user_id);
		$required_creds = $registry->get_required_credentials($network_key);
		$optional_creds = $registry->get_optional_credentials($network_key);
		$credential_labels = $registry->get_credential_labels($network_key);
		$is_configured = $manager->is_configured($network_key, $user_id);

		// Build response with credential status (without actual values)
		$credentials_status = [];
		foreach (array_merge($required_creds, $optional_creds) as $cred_type) {
			$credentials_status[$cred_type] = [
				'configured' => isset($all_credentials[$cred_type]),
				'label' => $credential_labels[$cred_type] ?? ucwords(str_replace('_', ' ', $cred_type)),
				'required' => in_array($cred_type, $required_creds),
			];
		}

		wp_send_json_success([
			'network_key' => $network_key,
			'is_configured' => $is_configured,
			'credentials' => $credentials_status,
			'required_credentials' => $required_creds,
			'optional_credentials' => $optional_creds,
		]);
	}
}
