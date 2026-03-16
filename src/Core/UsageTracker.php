<?php

namespace AEBG\Core;

/**
 * Usage Tracker Class
 * 
 * Centralized tracking for API usage, generation activity, and product replacements.
 * Handles cost calculations and efficiency metrics.
 *
 * @package AEBG\Core
 */
class UsageTracker {
	
	/**
	 * Model pricing (as of 2024)
	 * 
	 * @var array
	 */
	private static $model_pricing = [
		'gpt-3.5-turbo' => [
			'input' => 0.0015,  // per 1K tokens
			'output' => 0.002,   // per 1K tokens
		],
		'gpt-4' => [
			'input' => 0.03,    // per 1K tokens
			'output' => 0.06,    // per 1K tokens
		],
		'gpt-4-turbo' => [
			'input' => 0.01,    // per 1K tokens
			'output' => 0.03,    // per 1K tokens
		],
		'gpt-4o' => [
			'input' => 0.005,   // per 1K tokens
			'output' => 0.015,  // per 1K tokens
		],
		'gpt-4o-mini' => [
			'input' => 0.00015, // per 1K tokens
			'output' => 0.0006,  // per 1K tokens
		],
	];

	/**
	 * Get pricing for a model.
	 * 
	 * @param string $model Model name.
	 * @return array Pricing array with 'input' and 'output' keys.
	 */
	public static function get_model_pricing( $model ) {
		// Try exact match first
		if ( isset( self::$model_pricing[ $model ] ) ) {
			return self::$model_pricing[ $model ];
		}
		
		// Try partial match (e.g., gpt-4-turbo-preview matches gpt-4-turbo)
		foreach ( self::$model_pricing as $key => $pricing ) {
			if ( strpos( $model, $key ) !== false || strpos( $key, $model ) !== false ) {
				return $pricing;
			}
		}
		
		// Default to GPT-3.5 pricing if unknown
		return self::$model_pricing['gpt-3.5-turbo'];
	}

	/**
	 * Calculate cost for tokens.
	 * 
	 * @param string $model Model name.
	 * @param int    $prompt_tokens Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @return array Cost breakdown.
	 */
	public static function calculate_cost( $model, $prompt_tokens, $completion_tokens ) {
		$pricing = self::get_model_pricing( $model );
		
		$input_cost = ( $prompt_tokens / 1000 ) * $pricing['input'];
		$output_cost = ( $completion_tokens / 1000 ) * $pricing['output'];
		
		return [
			'input_cost' => round( $input_cost, 6 ),
			'output_cost' => round( $output_cost, 6 ),
			'total_cost' => round( $input_cost + $output_cost, 6 ),
		];
	}

	/**
	 * Record API usage (tokens, cost).
	 * 
	 * @param array $data Usage data.
	 * @return int|false Record ID or false on failure.
	 */
	public static function record_api_usage( $data ) {
		global $wpdb;
		
		// Ensure table exists
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureApiUsageTable();
		}
		
		$table_name = $wpdb->prefix . 'aebg_api_usage';
		
		// Calculate costs
		$costs = self::calculate_cost(
			$data['model'] ?? 'gpt-3.5-turbo',
			$data['prompt_tokens'] ?? 0,
			$data['completion_tokens'] ?? 0
		);
		
		$insert_data = [
			'batch_id' => $data['batch_id'] ?? null,
			'batch_item_id' => $data['batch_item_id'] ?? null,
			'post_id' => $data['post_id'] ?? null,
			'user_id' => $data['user_id'] ?? get_current_user_id(),
			'model' => $data['model'] ?? 'gpt-3.5-turbo',
			'prompt_tokens' => $data['prompt_tokens'] ?? 0,
			'completion_tokens' => $data['completion_tokens'] ?? 0,
			'total_tokens' => $data['total_tokens'] ?? ( ( $data['prompt_tokens'] ?? 0 ) + ( $data['completion_tokens'] ?? 0 ) ),
			'input_cost' => $costs['input_cost'],
			'output_cost' => $costs['output_cost'],
			'total_cost' => $costs['total_cost'],
			'request_type' => $data['request_type'] ?? 'generation',
			'field_id' => $data['field_id'] ?? null,
			'step_name' => $data['step_name'] ?? null,
			'created_at' => current_time( 'mysql' ),
		];
		
		$result = $wpdb->insert( $table_name, $insert_data, [
			'%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s',
		] );
		
		if ( $result === false ) {
			error_log( '[AEBG] UsageTracker::record_api_usage failed: ' . $wpdb->last_error );
			return false;
		}
		
		return $wpdb->insert_id;
	}

	/**
	 * Start tracking a generation.
	 * 
	 * @param array $data Generation data.
	 * @return int|false Activity ID or false on failure.
	 */
	public static function start_generation( $data ) {
		global $wpdb;
		
		// Ensure table exists
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureGenerationActivityTable();
		}
		
		$table_name = $wpdb->prefix . 'aebg_generation_activity';
		
		$insert_data = [
			'batch_id' => $data['batch_id'] ?? null,
			'batch_item_id' => $data['batch_item_id'] ?? null,
			'user_id' => $data['user_id'] ?? get_current_user_id(),
			'template_id' => $data['template_id'] ?? null,
			'status' => 'started',
			'started_at' => current_time( 'mysql' ),
			'created_at' => current_time( 'mysql' ),
		];
		
		$result = $wpdb->insert( $table_name, $insert_data, [
			'%d', '%d', '%d', '%d', '%s', '%s', '%s',
		] );
		
		if ( $result === false ) {
			error_log( '[AEBG] UsageTracker::start_generation failed: ' . $wpdb->last_error );
			return false;
		}
		
		return $wpdb->insert_id;
	}

	/**
	 * Complete tracking a generation.
	 * 
	 * @param int   $activity_id Activity ID.
	 * @param array $data Completion data.
	 * @return bool Success.
	 */
	public static function complete_generation( $activity_id, $data ) {
		global $wpdb;
		
		if ( ! $activity_id ) {
			return false;
		}
		
		$table_name = $wpdb->prefix . 'aebg_generation_activity';
		
		// Calculate duration if started_at exists
		$started_at = $wpdb->get_var( $wpdb->prepare(
			"SELECT started_at FROM {$table_name} WHERE id = %d",
			$activity_id
		) );
		
		$duration = null;
		if ( $started_at ) {
			$start_time = strtotime( $started_at );
			$end_time = time();
			$duration = $end_time - $start_time;
		}
		
		$update_data = [
			'post_id' => $data['post_id'] ?? null,
			'status' => $data['status'] ?? 'completed',
			'completed_at' => current_time( 'mysql' ),
			'duration_seconds' => $data['duration_seconds'] ?? $duration,
			'steps_completed' => $data['steps_completed'] ?? 0,
			'checkpoint_count' => $data['checkpoint_count'] ?? 0,
			'resume_count' => $data['resume_count'] ?? 0,
			'memory_peak_mb' => $data['memory_peak_mb'] ?? null,
			'content_length_words' => $data['content_length_words'] ?? null,
			'total_cost' => $data['total_cost'] ?? null,
			'total_tokens' => $data['total_tokens'] ?? null,
			'error_message' => $data['error_message'] ?? null,
			'metadata' => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
		];
		
		// Remove null values to avoid overwriting with NULL
		$update_data = array_filter( $update_data, function( $value ) {
			return $value !== null;
		} );
		
		$result = $wpdb->update(
			$table_name,
			$update_data,
			[ 'id' => $activity_id ],
			array_fill( 0, count( $update_data ), '%s' ),
			[ '%d' ]
		);
		
		if ( $result === false && $wpdb->last_error ) {
			error_log( '[AEBG] UsageTracker::complete_generation failed: ' . $wpdb->last_error );
			return false;
		}
		
		return true;
	}

	/**
	 * Record product replacement.
	 * 
	 * @param array $data Replacement data.
	 * @return int|false Record ID or false on failure.
	 */
	public static function record_product_replacement( $data ) {
		global $wpdb;
		
		// Ensure table exists
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureProductReplacementsTable();
		}
		
		$table_name = $wpdb->prefix . 'aebg_product_replacements';
		
		$insert_data = [
			'post_id' => $data['post_id'] ?? 0,
			'user_id' => $data['user_id'] ?? get_current_user_id(),
			'old_product_id' => $data['old_product_id'] ?? null,
			'old_product_name' => $data['old_product_name'] ?? null,
			'new_product_id' => $data['new_product_id'] ?? null,
			'new_product_name' => $data['new_product_name'] ?? null,
			'product_number' => $data['product_number'] ?? null,
			'replacement_type' => $data['replacement_type'] ?? 'manual',
			'reason' => $data['reason'] ?? null,
			'created_at' => current_time( 'mysql' ),
		];
		
		$result = $wpdb->insert( $table_name, $insert_data, [
			'%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
		] );
		
		if ( $result === false ) {
			error_log( '[AEBG] UsageTracker::record_product_replacement failed: ' . $wpdb->last_error );
			return false;
		}
		
		return $wpdb->insert_id;
	}

	/**
	 * Get total cost for a generation (sum of all API calls).
	 * 
	 * @param int $batch_item_id Batch item ID.
	 * @return float Total cost.
	 */
	public static function get_generation_total_cost( $batch_item_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'aebg_api_usage';
		
		$total = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(total_cost) FROM {$table_name} WHERE batch_item_id = %d",
			$batch_item_id
		) );
		
		return (float) $total;
	}

	/**
	 * Get total tokens for a generation.
	 * 
	 * @param int $batch_item_id Batch item ID.
	 * @return int Total tokens.
	 */
	public static function get_generation_total_tokens( $batch_item_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'aebg_api_usage';
		
		$total = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(total_tokens) FROM {$table_name} WHERE batch_item_id = %d",
			$batch_item_id
		) );
		
		return (int) $total;
	}

	/**
	 * Calculate efficiency metrics.
	 * 
	 * @param array $period_data Period data.
	 * @return array Efficiency metrics.
	 */
	public static function calculate_efficiency_metrics( $period_data ) {
		$total_cost = $period_data['total_cost'] ?? 0;
		$total_tokens = $period_data['total_tokens'] ?? 0;
		$total_words = $period_data['total_words'] ?? 0;
		$total_generations = $period_data['total_generations'] ?? 0;
		
		return [
			'avg_cost_per_generation' => $total_generations > 0 ? round( $total_cost / $total_generations, 4 ) : 0,
			'cost_per_token' => $total_tokens > 0 ? round( $total_cost / $total_tokens, 6 ) : 0,
			'cost_per_word' => $total_words > 0 ? round( $total_cost / $total_words, 6 ) : 0,
			'tokens_per_word' => $total_words > 0 ? round( $total_tokens / $total_words, 2 ) : 0,
		];
	}

	/**
	 * Record image generation (DALL-E API call).
	 * 
	 * @param array $data Image generation data.
	 * @return int|false Record ID or false on failure.
	 */
	public static function record_image_generation( $data ) {
		global $wpdb;
		
		// Ensure table exists
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureApiUsageTable();
		}
		
		$table_name = $wpdb->prefix . 'aebg_api_usage';
		
		// DALL-E and Nano Banana pricing (as of 2024)
		$image_pricing = [
			'dall-e-3' => [
				'standard' => [
					'1024x1024' => 0.040,   // $0.040 per image
					'1024x1792' => 0.080,   // $0.080 per image
					'1792x1024' => 0.080,   // $0.080 per image
				],
				'hd' => [
					'1024x1024' => 0.080,   // $0.080 per image
					'1024x1792' => 0.120,   // $0.120 per image
					'1792x1024' => 0.120,   // $0.120 per image
				],
			],
			'dall-e-2' => [
				'standard' => [
					'256x256' => 0.016,     // $0.016 per image
					'512x512' => 0.018,     // $0.018 per image
					'1024x1024' => 0.020,   // $0.020 per image
				],
			],
			'nano-banana' => [
				'standard' => [
					'1024x1024' => 0.020,
					'1024x1792' => 0.020,
					'1792x1024' => 0.020,
				],
			],
			'nano-banana-2' => [
				'standard' => [
					'1024x1024' => 0.020,
					'1024x1792' => 0.020,
					'1792x1024' => 0.020,
				],
			],
			'nano-banana-pro' => [
				'standard' => [
					'1024x1024' => 0.040,
					'1024x1792' => 0.040,
					'1792x1024' => 0.040,
				],
			],
		];
		
		// Calculate cost
		$model = $data['model'] ?? 'dall-e-3';
		$size = $data['size'] ?? '1024x1024';
		$quality = $data['quality'] ?? 'standard';
		
		$cost = 0;
		if ( isset( $image_pricing[ $model ][ $quality ][ $size ] ) ) {
			$cost = $image_pricing[ $model ][ $quality ][ $size ];
		} elseif ( isset( $image_pricing[ $model ]['standard'][ $size ] ) ) {
			$cost = $image_pricing[ $model ]['standard'][ $size ];
		} else {
			// Default pricing
			$cost = 0.040; // Default DALL-E 3 standard
		}
		
		$insert_data = [
			'batch_id' => $data['batch_id'] ?? null,
			'batch_item_id' => $data['batch_item_id'] ?? null,
			'post_id' => $data['post_id'] ?? null,
			'user_id' => $data['user_id'] ?? get_current_user_id(),
			'model' => $model,
			'prompt_tokens' => 0, // Images don't use tokens
			'completion_tokens' => 0,
			'total_tokens' => 0,
			'input_cost' => 0,
			'output_cost' => 0,
			'total_cost' => $cost,
			'request_type' => 'image_generation',
			'field_id' => $data['field_id'] ?? null,
			'step_name' => $data['step_name'] ?? null,
			'created_at' => current_time( 'mysql' ),
		];
		
		$result = $wpdb->insert( $table_name, $insert_data, [
			'%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s',
		] );
		
		if ( $result === false ) {
			error_log( '[AEBG] UsageTracker::record_image_generation failed: ' . $wpdb->last_error );
			return false;
		}
		
		return $wpdb->insert_id;
	}

	/**
	 * Compare efficiency between periods.
	 * 
	 * @param array $current_period Current period data.
	 * @param array $previous_period Previous period data.
	 * @return array Comparison data.
	 */
	public static function compare_periods( $current_period, $previous_period ) {
		$current_avg = $current_period['total_cost'] / max( $current_period['total_generations'], 1 );
		$previous_avg = $previous_period['total_cost'] / max( $previous_period['total_generations'], 1 );
		
		$change_percent = $previous_avg > 0 
			? ( ( $current_avg - $previous_avg ) / $previous_avg ) * 100 
			: 0;
		
		return [
			'current_avg_cost_per_gen' => round( $current_avg, 4 ),
			'previous_avg_cost_per_gen' => round( $previous_avg, 4 ),
			'change_percent' => round( $change_percent, 2 ),
			'trend' => $change_percent < 0 ? 'improving' : ( $change_percent > 0 ? 'declining' : 'stable' ),
		];
	}
}

