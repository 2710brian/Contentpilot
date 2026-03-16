<?php
/**
 * Testvinder-Only Regeneration Action Handlers
 * 
 * These methods need to be added to ActionHandler.php class.
 * They follow the same pattern as execute_replace_step_1, step_2, step_3
 * but work only with testvinder containers.
 * 
 * @package AEBG\Core
 */

// These methods should be added to the ActionHandler class:

/**
 * Execute testvinder-only regeneration Step 1: Collect prompts
 * 
 * @param int|array $post_id Post ID (can be array for Action Scheduler)
 * @param int|null $product_number Product number
 */
public function execute_testvinder_step_1( $post_id, $product_number = null ) {
	// CRITICAL: Use func_get_args() to capture ALL arguments passed by Action Scheduler
	$all_args = func_get_args();
	
	Logger::debug( 'execute_testvinder_step_1 received arguments', [
		'arg_count' => count( $all_args ),
		'arg_0' => $all_args[0] ?? 'missing',
		'arg_1' => $all_args[1] ?? 'missing',
	] );
	
	// Extract arguments from various possible formats (same as execute_replace_step_1)
	if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
		$post_id = $all_args[0][0];
		$product_number = $all_args[0][1];
	} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
		$post_id = $all_args[0];
		$product_number = $all_args[1];
	} elseif ( is_array( $all_args[0] ) && count( $all_args[0] ) === 1 && is_array( $all_args[0][0] ) ) {
		$nested = $all_args[0][0];
		if ( count( $nested ) >= 2 ) {
			$post_id = $nested[0];
			$product_number = $nested[1];
		}
	}
	
	// Validate and cast arguments
	$post_id = (int) $post_id;
	$product_number = (int) $product_number;
	
	// CRITICAL FALLBACK: If arguments are invalid, try to get from Action Scheduler database
	if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
		// Try to recover from Action Scheduler database (same logic as execute_replace_step_1)
		global $wpdb;
		$action_table = $wpdb->prefix . 'actionscheduler_actions';
		$action = $wpdb->get_row( $wpdb->prepare(
			"SELECT action_id, args FROM {$action_table} 
			WHERE hook = %s 
			AND (status = 'in-progress' OR status = 'pending')
			ORDER BY scheduled_date_gmt DESC 
			LIMIT 1",
			'aebg_regenerate_testvinder_only'
		), ARRAY_A );
		
		if ( $action && ! empty( $action['args'] ) ) {
			$raw_args = $action['args'];
			$args_from_db = maybe_unserialize( $raw_args );
			
			if ( ! is_array( $args_from_db ) && is_string( $raw_args ) ) {
				$args_from_db = json_decode( $raw_args, true );
			}
			
			if ( is_array( $args_from_db ) && count( $args_from_db ) >= 2 ) {
				$post_id = (int) $args_from_db[0];
				$product_number = (int) $args_from_db[1];
			}
		}
		
		// If still invalid, try checkpoint
		if ( ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) && $post_id > 0 ) {
			$checkpoint_table = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
			$checkpoint_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT product_number, checkpoint_state FROM {$checkpoint_table} 
				WHERE post_id = %d 
				AND checkpoint_type = 'testvinder'
				ORDER BY updated_at DESC 
				LIMIT 1",
				$post_id
			), ARRAY_A );
			
			if ( $checkpoint_row ) {
				$product_number = (int) $checkpoint_row['product_number'];
			}
		}
		
		if ( empty( $post_id ) || empty( $product_number ) || $product_number < 1 ) {
			Logger::error( 'Invalid arguments for execute_testvinder_step_1 - cannot recover', [
				'all_args' => $all_args,
				'parsed_post_id' => $post_id,
				'parsed_product_number' => $product_number,
			] );
			return;
		}
	}
	
	$start_time = microtime( true );
	$start_memory = memory_get_usage( true );
	
	// Fire monitoring hook
	do_action( 'aebg_testvinder_regeneration_started', $post_id, $product_number, 'step_1' );
	
	Logger::info( 'Starting testvinder regeneration Step 1', [
		'post_id' => $post_id,
		'product_number' => $product_number,
	] );
	
	try {
		// Get settings
		$aebg_settings = get_option( 'aebg_settings', [] );
		$settings = [
			'openai_api_key' => $aebg_settings['api_key'] ?? '',
			'ai_model' => $aebg_settings['model'] ?? 'gpt-3.5-turbo',
		];
		
		// Fallback API key
		if ( empty( $settings['openai_api_key'] ) && defined( 'AEBG_AI_API_KEY' ) ) {
			$settings['openai_api_key'] = AEBG_AI_API_KEY;
		}
		
		// Initialize Generator
		$generator = new \AEBG\Core\Generator( $settings );
		
		// Collect prompts from testvinder container only
		$result = $generator->collectPromptsForTestvinder( $post_id, $product_number );
		
		if ( is_wp_error( $result ) ) {
			Logger::error( 'Failed to collect testvinder prompts', [
				'post_id' => $post_id,
				'product_number' => $product_number,
				'error' => $result->get_error_message(),
			] );
			return;
		}
		
		// Save checkpoint
		$checkpoint_state = [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'elementor_data' => $result['elementor_data'] ?? [],
			'testvinder_container' => $result['testvinder_container'] ?? [],
			'context_registry_state' => $result['context_registry_state'] ?? [],
			'settings' => $settings,
		];
		
		$saved = \AEBG\Core\CheckpointManager::saveReplacementCheckpoint(
			$post_id,
			$product_number,
			\AEBG\Core\CheckpointManager::STEP_TESTVINDER_COLLECT_PROMPTS,
			$checkpoint_state,
			'testvinder' // checkpoint type
		);
		
		if ( ! $saved ) {
			Logger::error( 'Failed to save testvinder checkpoint', [
				'post_id' => $post_id,
				'product_number' => $product_number,
			] );
			return;
		}
		
		// Schedule Step 2
		\AEBG\Core\TestvinderReplacementScheduler::scheduleNextStep( 'step_1', $post_id, $product_number, 1 );
		
		$elapsed_time = microtime( true ) - $start_time;
		$peak_memory = memory_get_peak_usage( true );
		
		Logger::info( 'Testvinder Step 1 completed: Collected prompts', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'field_count' => $result['field_count'] ?? 0,
			'execution_time' => round( $elapsed_time, 2 ),
			'memory_usage_mb' => round( $start_memory / 1024 / 1024, 2 ),
			'peak_memory_mb' => round( $peak_memory / 1024 / 1024, 2 ),
		] );
		
	} catch ( \Exception $e ) {
		Logger::error( 'Exception in execute_testvinder_step_1', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'error' => $e->getMessage(),
			'trace' => $e->getTraceAsString(),
		] );
	}
}

/**
 * Execute testvinder-only regeneration Step 2: Process AI fields
 * 
 * @param int|array $post_id Post ID
 * @param int|null $product_number Product number
 */
public function execute_testvinder_step_2( $post_id, $product_number = null ) {
	// Same argument extraction logic as execute_replace_step_2
	$all_args = func_get_args();
	
	if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
		$post_id = $all_args[0][0];
		$product_number = $all_args[0][1];
	} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
		$post_id = $all_args[0];
		$product_number = $all_args[1];
	}
	
	$post_id = (int) $post_id;
	$product_number = (int) $product_number;
	
	// Load checkpoint
	$checkpoint_data = \AEBG\Core\CheckpointManager::loadReplacementCheckpoint(
		$post_id,
		$product_number,
		'testvinder'
	);
	
	if ( empty( $checkpoint_data ) ) {
		Logger::error( 'No checkpoint found for testvinder Step 2', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		] );
		return;
	}
	
	$start_time = microtime( true );
	$start_memory = memory_get_usage( true );
	
	// Get settings and context registry state
	$context_registry_state = $checkpoint_data['context_registry_state'] ?? [];
	$settings = $checkpoint_data['settings'] ?? [];
	$api_key = $settings['openai_api_key'] ?? '';
	$ai_model = $settings['ai_model'] ?? 'gpt-3.5-turbo';
	
	// Initialize Generator and restore context registry
	$generator = new \AEBG\Core\Generator( $settings );
	$context_registry = new \AEBG\Core\ContextRegistry();
	if ( method_exists( $context_registry, 'importState' ) ) {
		$context_registry->importState( $context_registry_state );
	}
	
	// Initialize template processor
	global $aebg_variables;
	if ( ! $aebg_variables ) {
		$aebg_variables = new \AEBG\Core\Variables();
	}
	
	$ai_processor = null;
	if ( ! empty( $api_key ) ) {
		$ai_processor = new \AEBG\Core\AIPromptProcessor( $aebg_variables, $context_registry, $api_key, $ai_model );
	}
	
	$template_processor = new \AEBG\Core\ElementorTemplateProcessor(
		$context_registry,
		$ai_processor,
		new \AEBG\Core\VariableReplacer(),
		new \AEBG\Core\ContentGenerator( new \AEBG\Core\VariableReplacer() )
	);
	
	// Get products and title
	$products = get_post_meta( $post_id, '_aebg_products', true );
	$post = get_post( $post_id );
	$title = $post ? $post->post_title : '';
	
	// Get processing order
	$processing_order = $context_registry->getProcessingOrder();
	
	// Process each field
	$context = [
		'title' => $title,
		'product_count' => count( $products ),
		'products' => $products,
	];
	
	foreach ( $processing_order as $field_id ) {
		$template_processor->processAIField( $field_id, $title, $products, $context, $api_key, $ai_model );
	}
	
	// Export updated context registry state
	$updated_context_registry_state = $context_registry->exportState();
	
	// Save checkpoint
	$checkpoint_state = [
		'post_id' => $post_id,
		'product_number' => $product_number,
		'elementor_data' => $checkpoint_data['elementor_data'] ?? [],
		'testvinder_container' => $checkpoint_data['testvinder_container'] ?? [],
		'context_registry_state' => $updated_context_registry_state,
		'settings' => $settings,
	];
	
	\AEBG\Core\CheckpointManager::saveReplacementCheckpoint(
		$post_id,
		$product_number,
		\AEBG\Core\CheckpointManager::STEP_TESTVINDER_PROCESS_FIELDS,
		$checkpoint_state,
		'testvinder'
	);
	
	// Schedule Step 3
	\AEBG\Core\TestvinderReplacementScheduler::scheduleNextStep( 'step_2', $post_id, $product_number, 1 );
	
	$elapsed_time = microtime( true ) - $start_time;
	Logger::info( 'Testvinder Step 2 completed: Processed AI fields', [
		'post_id' => $post_id,
		'product_number' => $product_number,
		'field_count' => count( $processing_order ),
		'execution_time' => round( $elapsed_time, 2 ),
	] );
}

/**
 * Execute testvinder-only regeneration Step 3: Apply content and save
 * 
 * @param int|array $post_id Post ID
 * @param int|null $product_number Product number
 */
public function execute_testvinder_step_3( $post_id, $product_number = null ) {
	// Same argument extraction logic as execute_replace_step_3
	$all_args = func_get_args();
	
	if ( is_array( $all_args[0] ) && count( $all_args[0] ) >= 2 ) {
		$post_id = $all_args[0][0];
		$product_number = $all_args[0][1];
	} elseif ( count( $all_args ) >= 2 && ! is_array( $all_args[0] ) ) {
		$post_id = $all_args[0];
		$product_number = $all_args[1];
	}
	
	$post_id = (int) $post_id;
	$product_number = (int) $product_number;
	
	// Load checkpoint
	$checkpoint_data = \AEBG\Core\CheckpointManager::loadReplacementCheckpoint(
		$post_id,
		$product_number,
		'testvinder'
	);
	
	if ( empty( $checkpoint_data ) ) {
		Logger::error( 'No checkpoint found for testvinder Step 3', [
			'post_id' => $post_id,
			'product_number' => $product_number,
		] );
		return;
	}
	
	$start_time = microtime( true );
	
	// Get checkpoint data
	$elementor_data = $checkpoint_data['elementor_data'] ?? [];
	$testvinder_container = $checkpoint_data['testvinder_container'] ?? [];
	$context_registry_state = $checkpoint_data['context_registry_state'] ?? [];
	$settings = $checkpoint_data['settings'] ?? [];
	
	// Initialize Generator
	$generator = new \AEBG\Core\Generator( $settings );
	
	// Apply content to testvinder container only
	$result = $generator->applyContentForTestvinder(
		$post_id,
		$product_number,
		$elementor_data,
		$testvinder_container,
		$context_registry_state
	);
	
	if ( is_wp_error( $result ) ) {
		Logger::error( 'Failed to apply testvinder content', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'error' => $result->get_error_message(),
		] );
		return;
	}
	
	// Save Elementor data
	$template_manager = new \AEBG\Core\TemplateManager();
	$save_result = $template_manager->saveElementorData( $post_id, $result );
	
	if ( is_wp_error( $save_result ) ) {
		Logger::error( 'Failed to save Elementor data after testvinder regeneration', [
			'post_id' => $post_id,
			'product_number' => $product_number,
			'error' => $save_result->get_error_message(),
		] );
		return;
	}
	
	// Force CSS generation
	$generator->forceElementorCSSGeneration( $post_id );
	
	// Clear checkpoint
	\AEBG\Core\CheckpointManager::clearReplacementCheckpoint( $post_id, $product_number, 'testvinder' );
	
	$elapsed_time = microtime( true ) - $start_time;
	Logger::info( 'Testvinder Step 3 completed: Applied content and saved', [
		'post_id' => $post_id,
		'product_number' => $product_number,
		'execution_time' => round( $elapsed_time, 2 ),
	] );
	
	// Fire completion hook
	do_action( 'aebg_testvinder_regeneration_completed', $post_id, $product_number );
}

