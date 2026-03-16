<?php

namespace AEBG\Core;

use AEBG\Core\AIPromptProcessor;
use AEBG\Core\ContextRegistry;
use AEBG\Core\ContentFormatter;
use AEBG\Core\DataUtilities;
use AEBG\Core\VariableReplacer;
use AEBG\Core\ContentGenerator;
use AEBG\Core\Variables;
use AEBG\Core\Logger;
use AEBG\Core\TitleAnalyzer;

/**
 * Elementor Template Processor Class
 * Handles Elementor template processing with AI content generation.
 *
 * @package AEBG\Core
 */
class ElementorTemplateProcessor {
	/**
	 * Context registry instance.
	 *
	 * @var ContextRegistry
	 */
	private $context_registry;

	/**
	 * AI prompt processor instance.
	 *
	 * @var AIPromptProcessor
	 */
	private $ai_processor;

	/**
	 * Variable replacer instance.
	 *
	 * @var VariableReplacer
	 */
	private $variable_replacer;

	/**
	 * Content generator instance.
	 *
	 * @var ContentGenerator
	 */
	private $content_generator;

	/**
	 * Apply start time for timeout tracking.
	 *
	 * @var float|null
	 */
	private $apply_start_time;

	/**
	 * Apply depth counter for recursion tracking.
	 *
	 * @var int
	 */
	private $apply_depth;

	/**
	 * Counter for button widget generations (to add delays between them).
	 *
	 * @var int
	 */
	private $button_generation_count = 0;
	
	/**
	 * Counter for inline API calls (to add delays between them).
	 *
	 * @var int
	 */
	private $inline_api_call_count = 0;

	/**
	 * Queue of widgets skipped due to timeout (for retry).
	 *
	 * @var array Array of [widget_data, widget_type, prompt, title, products, context, api_key, ai_model]
	 */
	private $skipped_widgets_queue = [];

	/**
	 * Constructor.
	 *
	 * @param ContextRegistry  $context_registry Context registry instance.
	 * @param AIPromptProcessor $ai_processor AI prompt processor instance.
	 * @param VariableReplacer $variable_replacer Variable replacer instance.
	 * @param ContentGenerator $content_generator Content generator instance.
	 */
	public function __construct($context_registry = null, $ai_processor = null, $variable_replacer = null, $content_generator = null) {
		$this->context_registry = $context_registry ?: new ContextRegistry();
		$this->variable_replacer = $variable_replacer ?: new VariableReplacer();
		$this->content_generator = $content_generator ?: new ContentGenerator($this->variable_replacer);
		// AI processor will be initialized when API key is available
		$this->ai_processor = $ai_processor;
	}

	/**
	 * Process Elementor template.
	 *
	 * @param int    $template_id Template ID.
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return array|\WP_Error Processed template data or WP_Error.
	 */
	public function processTemplate($template_id, $title, $products, $context, $api_key, $ai_model) {
		error_log('[AEBG] processTemplate: Starting for template ID: ' . $template_id);
		Logger::debug('Processing Elementor template', ['template_id' => $template_id]);

		// Get Elementor data
		error_log('[AEBG] processTemplate: About to call getElementorData...');
		$get_data_start = microtime(true);
		$elementor_data = $this->getElementorData($template_id);
		$get_data_elapsed = microtime(true) - $get_data_start;
		error_log('[AEBG] processTemplate: getElementorData returned after ' . round($get_data_elapsed, 2) . ' seconds');
		
		if (is_wp_error($elementor_data)) {
			error_log('[AEBG] processTemplate: getElementorData returned WP_Error: ' . $elementor_data->get_error_message());
			return $elementor_data;
		}

		error_log('[AEBG] processTemplate: Elementor data retrieved successfully, type: ' . gettype($elementor_data));

		// Remove unused product containers
		error_log('[AEBG] processTemplate: Removing unused product containers...');
		$actual_product_count = count($products);
		$elementor_data = $this->removeUnusedProductContainers($elementor_data, $actual_product_count);
		error_log('[AEBG] processTemplate: Product containers removed');

		// Process Elementor data
		error_log('[AEBG] processTemplate: About to call processElementorData...');
		$process_start = microtime(true);
		$processed_data = $this->processElementorData($elementor_data, $title, $products, $context, $api_key, $ai_model);
		$process_elapsed = microtime(true) - $process_start;
		error_log('[AEBG] processTemplate: processElementorData returned after ' . round($process_elapsed, 2) . ' seconds');

		if (is_wp_error($processed_data)) {
			return $processed_data;
		}

		// Validate processed data
		$validation = $this->validateElementorData($processed_data);
		if (is_wp_error($validation)) {
			Logger::warning('Elementor data validation failed', ['error' => $validation->get_error_message()]);
		}

		return $processed_data;
	}

	/**
	 * Process Elementor data.
	 *
	 * @param array  $elementor_data Elementor data.
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return array|\WP_Error Processed data or WP_Error.
	 */
	public function processElementorData($elementor_data, $title, $products, $context, $api_key, $ai_model) {
		if (empty($elementor_data) || !is_array($elementor_data)) {
			return new \WP_Error('aebg_invalid_elementor_data', 'Invalid Elementor data provided');
		}

		// CRITICAL: Clear context registry BEFORE processing to ensure clean state
		// This prevents state accumulation from previous jobs
		if ($this->context_registry && method_exists($this->context_registry, 'clear')) {
			$this->context_registry->clear();
			error_log('[AEBG] processElementorData: Cleared context registry for fresh state');
		}

		// Initialize AI processor if needed
		if (!$this->ai_processor && !empty($api_key)) {
			global $aebg_variables;
			if (!$aebg_variables) {
				$aebg_variables = new Variables();
			}
			$this->ai_processor = new AIPromptProcessor($aebg_variables, $this->context_registry, $api_key, $ai_model);
		}

		// Create deep copy
		error_log('[AEBG] processElementorData: Creating deep copy of elementor_data...');
		$processed_data = DataUtilities::deepCopyArray($elementor_data);
		error_log('[AEBG] processElementorData: Deep copy completed');

		// First pass: collect AI prompts
		error_log('[AEBG] processElementorData: Starting collectAIPrompts phase...');
		$collect_start = microtime(true);
		$this->collectAIPrompts($processed_data);
		$collect_elapsed = microtime(true) - $collect_start;
		error_log('[AEBG] processElementorData: Completed collectAIPrompts phase in ' . round($collect_elapsed, 2) . ' seconds');

		// Second pass: process in dependency order
		error_log('[AEBG] processElementorData: Getting processing order from context registry...');
		$order_start = microtime(true);
		$processing_order = $this->context_registry->getProcessingOrder();
		$order_elapsed = microtime(true) - $order_start;
		error_log('[AEBG] processElementorData: getProcessingOrder completed in ' . round($order_elapsed, 2) . ' seconds');
		error_log('[AEBG] Processing ' . count($processing_order) . ' AI fields in dependency order');
		
		$field_index = 0;
		foreach ($processing_order as $field_id) {
			$field_index++;
			
			// Only log milestones (every 10th field, first, last) to reduce verbosity
			$total_fields = count($processing_order);
			$is_milestone = ($field_index === 1 || $field_index === $total_fields || $field_index % 10 === 0);
			if ($is_milestone) {
				error_log('[AEBG] Processing AI field ' . $field_index . '/' . $total_fields . ': ' . $field_id);
			}
			
			// CRITICAL: Add small delay before each API call (except first) to prevent connection pool exhaustion
			// When processing many fields (e.g., 76 fields), rapid API calls can exhaust the connection pool
			// This delay allows the connection pool to recover between requests
			if ($field_index > 1) {
				$delay_ms = 100000; // 0.1 second delay (100ms) - prevents connection pool exhaustion
				usleep($delay_ms);
				// Force garbage collection every 10 calls to help free up connections
				if ($field_index % 10 === 0) {
					gc_collect_cycles();
					error_log('[AEBG] Garbage collection performed after field ' . $field_index);
				}
			}
			
			$field_start = microtime(true);
			$this->processAIField($field_id, $title, $products, $context, $api_key, $ai_model);
			$field_elapsed = microtime(true) - $field_start;
			
			// Only log slow fields (>3s) or milestones
			if ($field_elapsed > 3 || $is_milestone) {
				error_log('[AEBG] Completed AI field ' . $field_index . ' in ' . round($field_elapsed, 2) . ' seconds');
			}
		}
		error_log('[AEBG] Completed processing all AI fields');

		// CRITICAL: Save checkpoint after processElementorData completes (before applyProcessedContent)
		// This allows us to resume from applyProcessedContent if interrupted
		$item_id = $GLOBALS['aebg_current_item_id'] ?? null;
		if ( $item_id && class_exists( '\AEBG\Core\CheckpointManager' ) ) {
			// CRITICAL: Load existing checkpoint and merge with processed template data
			// This preserves all required fields (title, template_id, context, selected_products, etc.)
			$existing_checkpoint = \AEBG\Core\CheckpointManager::loadCheckpoint( $item_id );
			$existing_state = $existing_checkpoint['data'] ?? [];
			
			// Merge with processed template data
			$merged_state = array_merge( $existing_state, [
				'processed_template_after_ai_fields' => $processed_data, // Save after AI fields processed
				'apply_phase_started' => false, // Not yet started apply phase
			] );
			
			\AEBG\Core\CheckpointManager::saveCheckpoint( $item_id, \AEBG\Core\CheckpointManager::STEP_4_PROCESS_TEMPLATE, $merged_state );
			error_log( '[AEBG] ✅ Saved checkpoint after AI field processing (before apply phase) for item_id=' . $item_id . ' (merged with existing checkpoint)' );
		}
		
		// Third pass: apply processed content
		error_log('[AEBG] 📍 Starting applyProcessedContent phase');
		$apply_start = microtime(true);
		try {
			$this->applyProcessedContent($processed_data, $title, $products, $context, $api_key, $ai_model);
			$apply_elapsed = microtime(true) - $apply_start;
			error_log('[AEBG] 📍 Completed applyProcessedContent phase in ' . round($apply_elapsed, 2) . ' seconds');
		} catch (\Exception $e) {
			// Check if this is a proactive reschedule exception
			if ( strpos( $e->getMessage(), 'Proactive reschedule' ) !== false ) {
				// Save current state before rethrowing
				// CRITICAL: Load existing checkpoint and merge with partial template data
				// This preserves all required fields (title, template_id, context, selected_products, etc.)
				if ( $item_id && class_exists( '\AEBG\Core\CheckpointManager' ) ) {
					// Load existing checkpoint to preserve all fields
					$existing_checkpoint = \AEBG\Core\CheckpointManager::loadCheckpoint( $item_id );
					$existing_state = $existing_checkpoint['data'] ?? [];
					
					// CRITICAL: Create deep copy of processed_data to preserve modifications
					// Since applyProcessedContent modifies by reference, we need to ensure
					// we're saving the actual modified data, not a reference
					$processed_data_copy = $this->deepCopyArray( $processed_data );
					
					// CRITICAL: Count processed widgets before saving
					$processed_count = 0;
					$total_widgets = 0;
					$this->countProcessedWidgetsInData( $processed_data_copy, $processed_count, $total_widgets );
					
					// Merge with partial template data (using deep copy)
					$merged_state = array_merge( $existing_state, [
						'processed_template_after_ai_fields' => $processed_data_copy,
						'processed_template_partial' => $processed_data_copy, // Save partial state with processed flags
						'apply_phase_started' => true,
					] );
					
					\AEBG\Core\CheckpointManager::saveCheckpoint( $item_id, \AEBG\Core\CheckpointManager::STEP_4_PROCESS_TEMPLATE, $merged_state );
					error_log( sprintf(
						'[AEBG] ✅ Saved partial template state before reschedule for item_id=%d (merged with existing checkpoint) | %d/%d widgets processed',
						$item_id,
						$processed_count,
						$total_widgets
					) );
				}
				// Rethrow to stop execution
				throw $e;
			}
			error_log('[AEBG] ⚠️ ERROR in applyProcessedContent: ' . $e->getMessage());
			error_log('[AEBG] ⚠️ Stack trace: ' . $e->getTraceAsString());
			// Continue anyway for non-reschedule exceptions
		} catch (\Error $e) {
			error_log('[AEBG] ⚠️ FATAL ERROR in applyProcessedContent: ' . $e->getMessage());
			error_log('[AEBG] ⚠️ Stack trace: ' . $e->getTraceAsString());
			// Continue anyway - don't fail the entire process
		}

		// CRITICAL: Cleanup after processing to free memory and reset state (like old version)
		$this->cleanupAfterElementorProcessing();

		// CRITICAL VERIFICATION: Check if AI content is actually in the data structure before returning (like old version)
		$ai_content_verified = $this->checkForAIContent($processed_data);
		error_log('[AEBG] AI content verification after third pass: ' . ($ai_content_verified ? 'YES - Content found in data structure' : 'NO - Content NOT found in data structure'));

		// Additional verification: Check specific icon list items (like old version)
		$this->verifyIconListContent($processed_data);

		error_log('[AEBG] 📍 Returning processed_data from processElementorData');
		return $processed_data;
	}

	/**
	 * Collect AI prompts from Elementor data.
	 *
	 * @param array $data Elementor data.
	 * @return void
	 */
	public function collectAIPrompts($data) {
		if (!is_array($data)) {
			return;
		}

		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $item) {
				$this->collectAIPrompts($item);
			}
			return;
		}

		// Handle content array
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $content_item) {
				$this->collectAIPrompts($content_item);
			}
		}

		// Handle elements array
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $element) {
				$this->collectAIPrompts($element);
			}
		}

		// Check for widgets with AI settings
		if (isset($data['elType']) && $data['elType'] === 'widget') {
			if (isset($data['settings']) && is_array($data['settings'])) {
				$settings = &$data['settings'];

				// Check for widget-level AI settings
				if (isset($settings['aebg_ai_enable']) && $settings['aebg_ai_enable'] === 'yes') {
					$widget_type = $data['widgetType'] ?? 'unknown';
					$prompt = $settings['aebg_ai_prompt'] ?? '';
					
					// CRITICAL: Skip AI processing for image/URL-only variables
					// These should be handled directly, not sent to OpenAI
					$trimmed_prompt = trim($prompt);
					$is_image_variable = preg_match('/^\{product-\d+-image\}$/', $trimmed_prompt);
					$is_url_variable = preg_match('/^\{product-\d+-url\}$/', $trimmed_prompt);
					$is_affiliate_url_variable = preg_match('/^\{product-\d+-affiliate-url\}$/', $trimmed_prompt);
					
					if ($is_image_variable || $is_url_variable || $is_affiliate_url_variable) {
						// Don't register as AI field - these will be handled directly in applyProcessedContent
						// error_log('[AEBG] Skipping AI processing for variable-only prompt: ' . $trimmed_prompt . ' (widget: ' . $widget_type . ')');
					} else {
						$field_id = uniqid('ai_field_');

						$this->context_registry->registerField($field_id, [
							'widget_type' => $widget_type,
							'prompt' => $prompt,
							'original_content' => $this->getOriginalContent($data, $widget_type),
							'dependencies' => $this->extractDependencies($prompt)
						]);

						$settings['aebg_field_id'] = $field_id;
					}
				}

				// Check for icon list AI settings
				if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
					foreach ($settings['icon_list'] as $index => &$icon_item) {
						if (isset($icon_item['aebg_iconlist_ai_enable']) && $icon_item['aebg_iconlist_ai_enable'] === 'yes') {
							$prompt = $icon_item['aebg_iconlist_ai_prompt'] ?? '';
							$field_id = uniqid('ai_iconlist_');

							$this->context_registry->registerField($field_id, [
								'widget_type' => 'icon-list-item',
								'prompt' => $prompt,
								'original_content' => $icon_item['text'] ?? '',
								'dependencies' => $this->extractDependencies($prompt),
								'parent_widget' => $data['widgetType'] ?? 'unknown',
								'icon_index' => $index
							]);

							$icon_item['aebg_field_id'] = $field_id;
						}
					}
				}
			}
		}
	}

	/**
	 * Process an AI field.
	 *
	 * @param string $field_id Field ID.
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return void
	 */
	public function processAIField($field_id, $title, $products, $context, $api_key, $ai_model) {
		if (empty($field_id) || !is_string($field_id)) {
			return;
		}

		// Get prompt data from context registry
		$prompt_data = $this->context_registry->getContext($field_id);
		if (!$prompt_data || !is_array($prompt_data)) {
			return;
		}

		$prompt = trim($prompt_data['prompt'] ?? '');
		if (empty($prompt)) {
			return;
		}

		$widget_type = $prompt_data['widget_type'] ?? 'unknown';
		
		// Log icon-list-item processing for debugging
		if ($widget_type === 'icon-list-item') {
			error_log('[AEBG] 🔧 Processing icon-list-item field: ' . $field_id . ' | Prompt: ' . substr($prompt, 0, 100));
		}

		// Initialize AI processor if needed
		if (!$this->ai_processor && !empty($api_key)) {
			global $aebg_variables;
			if (!$aebg_variables) {
				$aebg_variables = new Variables();
			}
			$this->ai_processor = new AIPromptProcessor($aebg_variables, $this->context_registry, $api_key, $ai_model);
		}

		// CRITICAL: Update context with products like old version
		$updated_context = $this->updateContextWithProducts($context, $products);
		
		// CRITICAL: Special handling for image and button widgets (like old version)
		if ($widget_type === 'image' || $widget_type === 'button') {
			// Replace variables FIRST before checking if it's a URL
			$processed_prompt = $this->variable_replacer->replaceVariablesInPrompt($prompt, $title, $products, $updated_context);
			error_log('[AEBG] ' . $widget_type . ' widget: Processed prompt after variable replacement: ' . $processed_prompt);
			
			// FALLBACK: If variable wasn't replaced (still contains {product-X-image} pattern), try to get URL directly
			if (preg_match('/\{product-(\d+)-image\}/', $processed_prompt, $matches)) {
				$product_number = (int)$matches[1];
				$product_index = $product_number - 1;
				error_log('[AEBG] Variable {product-' . $product_number . '-image} was not replaced, attempting direct lookup');
				
				if (isset($products[$product_index]) && !empty($products[$product_index])) {
					$product = $products[$product_index];
					$product_image_url = $this->variable_replacer->getProductImageUrl($product);
					if ($product_image_url) {
						error_log('[AEBG] Found image URL via direct lookup: ' . $product_image_url);
						$processed_prompt = $product_image_url;
					} else {
						error_log('[AEBG] No image URL found for product ' . $product_number . ' via direct lookup');
					}
				} else {
					error_log('[AEBG] Product index ' . $product_index . ' (product-' . $product_number . ') not found in products array');
				}
			}
			
			// Check if the processed prompt is a valid URL
			$trimmed_prompt = trim($processed_prompt);
			$is_valid_url = false;
			if (!empty($trimmed_prompt)) {
				// Check if it looks like a URL (starts with http/https)
				if (preg_match('/^https?:\/\//i', $trimmed_prompt)) {
					$is_valid_url = true;
					error_log('[AEBG] ' . $widget_type . ' widget: Detected URL pattern in processed prompt');
				} else {
					// Try filter_var as fallback
					$is_valid_url = filter_var($trimmed_prompt, FILTER_VALIDATE_URL) !== false;
					if ($is_valid_url) {
						error_log('[AEBG] ' . $widget_type . ' widget: filter_var confirmed URL');
					}
				}
			}
			
			if ($widget_type === 'image') {
				if ($is_valid_url) {
					// If it's a valid URL, use it directly as the generated content
					$generated_content = $trimmed_prompt;
					error_log('[AEBG] Image widget: Using direct URL as generated content: ' . $generated_content);
				} else {
					// If it's not a URL, it might be a descriptive prompt for AI image generation
					error_log('[AEBG] Image widget: Processed prompt is not a URL, using AI image generation');
					$image_url = $this->generateAIImage($trimmed_prompt, $api_key, $ai_model);
					if ($image_url) {
						$generated_content = $image_url;
						error_log('[AEBG] Image generated successfully: ' . $image_url);
					} else {
						$generated_content = '';
						error_log('[AEBG] Image generation failed');
					}
				}
			} else { // button
				if ($is_valid_url) {
					// If it's a valid URL, use it directly as the generated content (will be applied to link.url in applyContentToWidget)
					$generated_content = $trimmed_prompt;
					error_log('[AEBG] Button widget: Using direct URL as generated content: ' . $generated_content);
				} else {
					// If it's not a URL, generate text content for the button text
					error_log('[AEBG] Button widget: Processing text prompt for button text generation');
					$generated_content = $this->ai_processor->processPrompt($trimmed_prompt, $title, $products, $updated_context, $field_id);
				}
			}
		} else {
			// For other widget types, let ai_processor handle variable replacement
			$generated_content = $this->ai_processor->processPrompt($prompt, $title, $products, $updated_context, $field_id);
		}
		
		if (!empty($generated_content) && is_string($generated_content)) {
			$generated_content = trim($generated_content);
			// error_log('[AEBG] Generated content for field ' . $field_id . ': ' . substr($generated_content, 0, 100));
			
			// Store generated content in context registry
			$this->context_registry->setContext($field_id, array_merge($prompt_data, [
				'generated_content' => $generated_content
			]));
			
			// error_log('[AEBG] Successfully processed and stored AI content for field: ' . $field_id);
		} else {
			error_log('[AEBG] No valid content generated for field: ' . $field_id);
			error_log('[AEBG] Generated content type: ' . gettype($generated_content));
			error_log('[AEBG] Generated content value: ' . var_export($generated_content, true));
		}
	}

	/**
	 * Apply processed content to template.
	 *
	 * @param array  &$data Elementor data (by reference).
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return void
	 */
	public function applyProcessedContent(&$data, $title = '', $products = [], $context = [], $api_key = '', $ai_model = '') {
		// Use instance variable instead of static to avoid issues
		if (!isset($this->apply_start_time)) {
			$this->apply_start_time = microtime(true);
			$this->apply_depth = 0;
			$this->button_generation_count = 0; // Reset button counter for new apply phase
			$this->inline_api_call_count = 0; // Reset inline API call counter for new apply phase
			$this->skipped_widgets_queue = []; // Reset skipped widgets queue for new apply phase
			error_log('[AEBG] applyProcessedContent: Starting content application');
		}
		
		$this->apply_depth++;
		
		// CRITICAL: Use job start time for timeout checks (not apply start time)
		// This ensures we don't reset timeout protection when applyProcessedContent restarts
		$elapsed = 0;
		$max_time = 0;
		$remaining_time = 0;
		
		if (isset($GLOBALS['aebg_job_start_time'])) {
			$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
			$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
			$remaining_time = $max_time - $elapsed;
		} elseif (isset($this->apply_start_time)) {
			$elapsed = microtime(true) - $this->apply_start_time;
			$max_time = 55; // Max 55 seconds for apply phase
			$remaining_time = $max_time - $elapsed;
		}
		
		// Safety check: if we've been applying content for more than 30 seconds, log warning
		if ($elapsed > 30 && $this->apply_depth % 100 === 0) {
			error_log('[AEBG] ⚠️ applyProcessedContent taking long time: ' . round($elapsed, 2) . 's, depth: ' . $this->apply_depth . ', remaining: ' . round($remaining_time, 2) . 's');
		}
		
		// CRITICAL: Hard limit - abort if we're running out of time
		// Use job start time if available, otherwise use apply start time
		if ($remaining_time < 5) {
			error_log('[AEBG] ⚠️ CRITICAL: applyProcessedContent - insufficient time remaining (' . round($remaining_time, 2) . 's), aborting to prevent timeout');
			// DON'T reset apply_start_time - we might still be processing other branches
			// Just skip this branch
			$this->apply_depth--;
			return;
		}
		
		if (!is_array($data)) {
			$this->apply_depth--;
			if ($this->apply_depth === 0) {
				$final_elapsed = isset($GLOBALS['aebg_job_start_time']) ? (microtime(true) - $GLOBALS['aebg_job_start_time']) : (isset($this->apply_start_time) ? (microtime(true) - $this->apply_start_time) : 0);
				error_log('[AEBG] applyProcessedContent completed in ' . round($final_elapsed, 2) . ' seconds (total depth: ' . $this->apply_depth . ')');
				// Only reset if we're truly done (not just one branch)
				// Keep apply_start_time for potential retries
			}
			return;
		}

		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as &$item) {
				// CRITICAL: Check timeout before processing each item
				if (isset($GLOBALS['aebg_job_start_time'])) {
					$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
					$remaining_time = (\AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER) - $elapsed; // Use centralized timeout
				} elseif (isset($this->apply_start_time)) {
					$elapsed = microtime(true) - $this->apply_start_time;
					$remaining_time = 55 - $elapsed;
				}
				
				if ($remaining_time < 3) {
					error_log('[AEBG] ⚠️ Skipping remaining items in numeric array - insufficient time (' . round($remaining_time, 2) . 's)');
					break;
				}
				
				$this->applyProcessedContent($item, $title, $products, $context, $api_key, $ai_model);
			}
			$this->apply_depth--;
			if ($this->apply_depth === 0) {
				$final_elapsed = isset($GLOBALS['aebg_job_start_time']) ? (microtime(true) - $GLOBALS['aebg_job_start_time']) : (isset($this->apply_start_time) ? (microtime(true) - $this->apply_start_time) : 0);
				error_log('[AEBG] applyProcessedContent completed in ' . round($final_elapsed, 2) . ' seconds');
				// Only reset if we're truly done
			}
			return;
		}

		// Handle content array
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as &$content_item) {
				// CRITICAL: Check timeout before processing each content item
				if (isset($GLOBALS['aebg_job_start_time'])) {
					$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
					$remaining_time = (\AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER) - $elapsed; // Use centralized timeout
				} elseif (isset($this->apply_start_time)) {
					$elapsed = microtime(true) - $this->apply_start_time;
					$remaining_time = 55 - $elapsed;
				}
				
				if ($remaining_time < 3) {
					error_log('[AEBG] ⚠️ Skipping remaining content items - insufficient time (' . round($remaining_time, 2) . 's)');
					break;
				}
				
				$this->applyProcessedContent($content_item, $title, $products, $context, $api_key, $ai_model);
			}
		}

		// Handle elements array
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as &$element) {
				// CRITICAL: Check timeout before processing each element
				if (isset($GLOBALS['aebg_job_start_time'])) {
					$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
					$remaining_time = (\AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER) - $elapsed; // Use centralized timeout
				} elseif (isset($this->apply_start_time)) {
					$elapsed = microtime(true) - $this->apply_start_time;
					$remaining_time = 55 - $elapsed;
				}
				
				if ($remaining_time < 3) {
					error_log('[AEBG] ⚠️ Skipping remaining elements - insufficient time (' . round($remaining_time, 2) . 's)');
					break;
				}
				
				$this->applyProcessedContent($element, $title, $products, $context, $api_key, $ai_model);
			}
		}

		// Process widgets
		if (isset($data['elType']) && $data['elType'] === 'widget') {
			try {
				$this->applyContentToWidget($data, $title, $products, $context, $api_key, $ai_model);
			} catch (\Exception $e) {
				// Check if this is a proactive reschedule exception
				if ( strpos( $e->getMessage(), 'Proactive reschedule' ) !== false ) {
					// Rethrow to stop execution - don't continue processing
					throw $e;
				}
				error_log('[AEBG] ⚠️ ERROR in applyContentToWidget: ' . $e->getMessage());
				// Continue processing other widgets for non-reschedule exceptions
			} catch (\Error $e) {
				error_log('[AEBG] ⚠️ FATAL ERROR in applyContentToWidget: ' . $e->getMessage());
				// Continue processing other widgets
			}
		}
		
		$this->apply_depth--;
		if ($this->apply_depth === 0) {
			error_log('[AEBG] applyProcessedContent completed in ' . round($elapsed, 2) . ' seconds');
			
			// Reset counters for next apply phase
			$this->button_generation_count = 0; // Reset for next apply phase
			$this->inline_api_call_count = 0; // Reset for next apply phase
			
			// CRITICAL: Retry skipped widgets if we have time remaining
			if (!empty($this->skipped_widgets_queue)) {
				$this->retrySkippedWidgets();
			}
			
			$this->apply_start_time = null;
			$this->skipped_widgets_queue = []; // Clear queue
		}
	}

	/**
	 * Retry widgets that were skipped due to timeout.
	 * Only retries if there's sufficient time remaining.
	 *
	 * @return void
	 */
	private function retrySkippedWidgets() {
		if (empty($this->skipped_widgets_queue)) {
			return;
		}

		error_log('[AEBG] 🔄 Attempting to retry ' . count($this->skipped_widgets_queue) . ' skipped widget(s)');

		// Check remaining time
		$elapsed = 0;
		$max_time = 0;
		$remaining_time = 0;
		
		if (isset($GLOBALS['aebg_job_start_time'])) {
			$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
			$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
			$remaining_time = $max_time - $elapsed;
		} elseif (isset($this->apply_start_time)) {
			$elapsed = microtime(true) - $this->apply_start_time;
			$max_time = 55; // Max 55 seconds for apply phase
			$remaining_time = $max_time - $elapsed;
		}

		// Need at least 10 seconds to retry (API call + processing)
		if ($remaining_time < 10) {
			error_log('[AEBG] ⚠️ Cannot retry skipped widgets - insufficient time remaining (' . round($remaining_time, 2) . 's, need: 10s)');
			return;
		}

		$retried_count = 0;
		$failed_count = 0;

		foreach ($this->skipped_widgets_queue as $index => $queued_widget) {
			// Check time before each retry
			if (isset($GLOBALS['aebg_job_start_time'])) {
				$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
				$remaining_time = 550 - $elapsed;
			} elseif (isset($this->apply_start_time)) {
				$elapsed = microtime(true) - $this->apply_start_time;
				$remaining_time = 55 - $elapsed;
			}

			if ($remaining_time < 10) {
				error_log('[AEBG] ⚠️ Stopping retry - insufficient time remaining (' . round($remaining_time, 2) . 's)');
				break;
			}

			error_log('[AEBG] 🔄 Retrying skipped widget: ' . $queued_widget['widget_type'] . ' (remaining time: ' . round($remaining_time, 2) . 's)');

			// Generate content
			$generation_start = microtime(true);
			$generated = $this->content_generator->generateContentWithPrompt(
				$queued_widget['title'],
				$queued_widget['products'],
				$queued_widget['context'],
				$queued_widget['api_key'],
				$queued_widget['ai_model'],
				$queued_widget['prompt']
			);
			$generation_elapsed = microtime(true) - $generation_start;

			if ($generated && !is_wp_error($generated)) {
				error_log('[AEBG] ✅ Successfully retried ' . $queued_widget['widget_type'] . ' widget (time: ' . round($generation_elapsed, 2) . 's)');
				$this->applyContentToWidgetByType($queued_widget['widget_data'], $generated, $queued_widget['widget_type']);
				$retried_count++;
			} else {
				$error_msg = is_wp_error($generated) ? $generated->get_error_message() : 'Unknown error';
				error_log('[AEBG] ❌ Retry failed for ' . $queued_widget['widget_type'] . ': ' . $error_msg);
				$failed_count++;
			}

			// NOTE: Rate limiting is now handled by APIClient via OpenAI's 429 responses
			// No need for manual delays - APIClient will handle wait/retry automatically
		}

		error_log('[AEBG] 🔄 Retry complete: ' . $retried_count . ' succeeded, ' . $failed_count . ' failed out of ' . count($this->skipped_widgets_queue) . ' queued');
	}

	/**
	 * Apply content to widget.
	 *
	 * @param array  &$data Widget data (by reference).
	 * @param string $title The title.
	 * @param array  $products Array of products.
	 * @param array  $context Context data.
	 * @param string $api_key API key.
	 * @param string $ai_model AI model.
	 * @return void
	 */
	public function applyContentToWidget(&$data, $title, $products, $context, $api_key, $ai_model) {
		if (!isset($data['settings']) || !is_array($data['settings'])) {
			return;
		}

		$widget_type = $data['widgetType'] ?? 'unknown';
		$settings = &$data['settings'];
		
		// CRITICAL: Check if this widget has already been processed (on resume)
		// We mark widgets as processed by setting a flag in settings
		// This prevents reprocessing widgets that were already processed before reschedule
		if ( isset( $settings['_aebg_widget_processed'] ) && $settings['_aebg_widget_processed'] === true ) {
			// Widget already processed - skip it to avoid duplicate processing
			// Log only occasionally to avoid spam (every 10th widget or first/last)
			static $skipped_count = 0;
			$skipped_count++;
			if ( $skipped_count === 1 || $skipped_count % 10 === 0 ) {
				error_log( sprintf(
					'[AEBG] ⏭️ Skipping already-processed widget: %s (skipped so far: %d)',
					$widget_type,
					$skipped_count
				) );
			}
			return;
		}

		// Check for field_id and get generated content
		if (isset($settings['aebg_field_id'])) {
			$field_id = $settings['aebg_field_id'];
			$field_data = $this->context_registry->getContext($field_id);
			
				if ($field_data && isset($field_data['generated_content'])) {
					$generated_content = $field_data['generated_content'];
					if (!empty($generated_content)) {
						$this->applyContentToWidgetByType($data, $generated_content, $widget_type);
						// Mark widget as processed to avoid reprocessing on resume
						$settings['_aebg_widget_processed'] = true;
					} else {
						error_log('[AEBG] ⚠️ Empty generated_content for field_id: ' . $field_id . ' (widget: ' . $widget_type . ')');
					}
				} else {
					error_log('[AEBG] ⚠️ No field_data or generated_content for field_id: ' . $field_id . ' (widget: ' . $widget_type . ')');
				}
			}

		// Handle icon list items - INLINE GENERATION LIKE OLD VERSION
		if ($widget_type === 'aebg-icon-list' && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
			error_log('[AEBG] Processing icon_list with ' . count($settings['icon_list']) . ' items');
			
			// CRITICAL: Check timeout before processing icon list
			$elapsed = 0;
			$max_time = 0;
			$remaining_time = 0;
			
			if (isset($GLOBALS['aebg_job_start_time'])) {
				$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
				$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
				$remaining_time = $max_time - $elapsed;
			} elseif (isset($this->apply_start_time)) {
				$elapsed = microtime(true) - $this->apply_start_time;
				$max_time = 55; // Max 55 seconds for apply phase
				$remaining_time = $max_time - $elapsed;
			}
			
			// If we have less than 3 seconds per item remaining, skip the rest
			$items_remaining = count($settings['icon_list']);
			$time_per_item = $items_remaining > 0 ? ($remaining_time / $items_remaining) : 0;
			
			if ($remaining_time < 3 || $time_per_item < 1) {
				error_log('[AEBG] ⚠️ Skipping icon_list processing - insufficient time remaining (' . round($remaining_time, 2) . 's, need: 3s, time per item: ' . round($time_per_item, 2) . 's)');
				unset($icon_item);
				return; // Skip entire icon list to prevent timeout
			}
			
			foreach ($settings['icon_list'] as $index => &$icon_item) {
				// CRITICAL: Check timeout before each icon item
				if (isset($GLOBALS['aebg_job_start_time'])) {
					$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
					$remaining_time = (\AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER) - $elapsed; // Use centralized timeout
				} elseif (isset($this->apply_start_time)) {
					$elapsed = microtime(true) - $this->apply_start_time;
					$remaining_time = 55 - $elapsed;
				}
				
				// Need at least 3 seconds per icon item (API call + processing)
				if ($remaining_time < 3) {
					error_log('[AEBG] ⚠️ Skipping remaining icon_list items (' . (count($settings['icon_list']) - $index) . ' items) - insufficient time remaining (' . round($remaining_time, 2) . 's)');
					break; // Skip remaining items
				}
				// First check if we have generated content from context registry
				if (isset($icon_item['aebg_field_id'])) {
					$field_id = $icon_item['aebg_field_id'];
					$field_data = $this->context_registry->getContext($field_id);
					
					if ($field_data && isset($field_data['generated_content'])) {
						$generated_content = $field_data['generated_content'];
						if (!empty($generated_content)) {
							// Clean the content by removing surrounding quotes (like old version)
							$cleaned_content = $generated_content;
							if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
								$cleaned_content = $matches[1];
							}
							$cleaned_content = trim($cleaned_content);
							
							if (!empty($cleaned_content)) {
								$icon_item['text'] = $cleaned_content;
								// Mark icon item as processed to avoid reprocessing on resume
								$icon_item['_aebg_processed'] = true;
								error_log('[AEBG] Updated icon item ' . $index . ' from context registry: ' . substr($cleaned_content, 0, 100));
							}
						}
					}
				}
				
				// ALSO handle inline generation like old version (backward compatibility)
				// CRITICAL: Check if icon item already processed (on resume)
				if ( isset( $icon_item['_aebg_processed'] ) && $icon_item['_aebg_processed'] === true ) {
					// Icon item already processed - skip it
					error_log( sprintf( '[AEBG] ⏭️ Skipping already-processed icon item %d in icon_list', $index ) );
					continue;
				}
				
				if (isset($icon_item['aebg_iconlist_ai_enable']) && $icon_item['aebg_iconlist_ai_enable'] === 'yes') {
					$icon_prompt = $icon_item['aebg_iconlist_ai_prompt'] ?? '';
					
					if (!empty($icon_prompt)) {
						error_log('[AEBG] Processing AI-enabled icon item ' . $index . ' inline with prompt: ' . substr($icon_prompt, 0, 50) . '...');
						
						// CRITICAL: Add small delay before icon_list API calls (except first) to prevent connection pool exhaustion
						// Icon list items are processed in rapid succession and can exhaust the connection pool
						// This delay allows the connection pool to recover between requests
						$this->inline_api_call_count++;
						if ($this->inline_api_call_count > 1) {
							$delay_ms = 100000; // 0.1 second delay (100ms) - prevents connection pool exhaustion
							usleep($delay_ms);
							// Force garbage collection every 10 calls to help free up connections
							if ($this->inline_api_call_count % 10 === 0) {
								gc_collect_cycles();
								error_log('[AEBG] Garbage collection performed after icon_list API call #' . $this->inline_api_call_count);
							}
						}
						
						// CRITICAL: Update context with products like old version
						$updated_context = $this->updateContextWithProducts($context, $products);
						
						// Generate content inline like old version (use icon-list-item format to skip text formatting)
						$generated_content = $this->content_generator->generateContentWithPrompt($title, $products, $updated_context, $api_key, $ai_model, $icon_prompt, 'icon-list-item');
						
						if ($generated_content && !is_wp_error($generated_content)) {
							// Clean the content by removing surrounding quotes (like old version)
							$cleaned_content = $generated_content;
							if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
								$cleaned_content = $matches[1];
							}
							$cleaned_content = trim($cleaned_content);
							
							if (!empty($cleaned_content)) {
							$icon_item['text'] = $cleaned_content;
							// Mark icon item as processed to avoid reprocessing on resume
							$icon_item['_aebg_processed'] = true;
							error_log('[AEBG] Updated icon item ' . $index . ' from inline generation: ' . substr($cleaned_content, 0, 100));
							}
						} elseif (is_wp_error($generated_content)) {
							$error_code = $generated_content->get_error_code();
							$error_message = $generated_content->get_error_message();
							error_log('[AEBG] ⚠️ Error generating content for icon item ' . $index . ': [' . $error_code . '] ' . $error_message);
							
							// NOTE: Rate limit errors are now handled by APIClient with proper wait/retry logic
							// No need for manual delays - APIClient will handle retries automatically
						} else {
							error_log('[AEBG] ⚠️ Icon item ' . $index . ' generation returned empty/false result');
						}
					} else {
						error_log('[AEBG] Icon item ' . $index . ' has empty prompt, skipping');
					}
				}
			}
			error_log('[AEBG] Completed processing icon_list with ' . count($settings['icon_list']) . ' items');
			unset($icon_item); // Break the reference after the loop
		}

		// Also handle inline generation for widgets without field_id (backward compatibility)
		// AND handle image/URL variables that were skipped in collectAIPrompts
		// THIS MATCHES OLD VERSION BEHAVIOR
		if (isset($settings['aebg_ai_enable']) && $settings['aebg_ai_enable'] === 'yes' && !empty($settings['aebg_ai_prompt'])) {
			$prompt = $settings['aebg_ai_prompt'];
			$trimmed_prompt = trim($prompt);
			
			// Only log widget processing if debugging - too verbose otherwise
			// error_log('[AEBG] Found AI-enabled widget in applyContentToWidget (inline): ' . $widget_type . ' with prompt: ' . substr($trimmed_prompt, 0, 50) . '...');
			
			// CRITICAL: Handle image/URL variables directly without AI processing
			if (preg_match('/^\{product-(\d+)-image\}$/', $trimmed_prompt, $matches)) {
				$product_num = (int)$matches[1];
				$product_index = $product_num - 1;
				
				if (isset($products[$product_index]) && $products[$product_index] !== null) {
					$product = $products[$product_index];
					$image_url = $product['image_url'] ?? $product['image'] ?? '';
					
					if (!empty($image_url) && $widget_type === 'image') {
						// Apply image URL directly to image widget
						if (!isset($settings['image']) || !is_array($settings['image'])) {
							$settings['image'] = [];
						}
						$settings['image']['url'] = $image_url;
						$settings['image']['id'] = '';
						error_log('[AEBG] Applied image variable directly to image widget: ' . $image_url);
						return; // Skip further processing
					}
				}
			}
			
			// Check if prompt is just a variable (like {product-1-image})
			if (preg_match('/^\{[^}]+\}$/', $trimmed_prompt)) {
				error_log('[AEBG] Detected variable-only prompt: ' . $trimmed_prompt . ' - processing as variable replacement');
				// CRITICAL: Update context with products like old version
				$updated_context = $this->updateContextWithProducts($context, $products);
				$processed = $this->variable_replacer->replaceVariablesInPrompt($prompt, $title, $products, $updated_context);
				
				if (!empty($processed) && $processed !== $prompt) {
					error_log('[AEBG] Variable replacement successful: ' . $trimmed_prompt . ' -> ' . substr($processed, 0, 100));
					$this->applyContentToWidgetByType($data, $processed, $widget_type);
				}
			} else {
				// Generate content inline like old version
				// CRITICAL: Update context with products like old version
				$updated_context = $this->updateContextWithProducts($context, $products);
				
				// NOTE: Rate limiting and throttling is now handled by APIClient::rateLimitCheck()
				// No need for widget-specific delays - APIClient will throttle all requests automatically
				
				// CRITICAL: Check timeout before making API call
				// Use job start time if available (from Generator), otherwise use apply start time
				$elapsed = 0;
				$max_time = 0;
				$remaining_time = 0;
				$SERVER_TIMEOUT = 180; // Server-level timeout (cannot be overridden)
				
				// Get AI model from globals or use default
				$ai_model = $GLOBALS['aebg_ai_model'] ?? 'gpt-3.5-turbo';
				
				// Model-specific reschedule thresholds
				// GPT-4/GPT-5 requests take longer, so we need 20s buffer (reschedule at 160s)
				// GPT-3.5 requests take 1-2s, so we can use 40s buffer (reschedule at 140s)
				$is_slower_model = strpos( strtolower( $ai_model ), 'gpt-4' ) !== false || strpos( strtolower( $ai_model ), 'gpt4' ) !== false
					|| strpos( strtolower( $ai_model ), 'gpt-5' ) !== false || strpos( strtolower( $ai_model ), 'gpt5' ) !== false;
				$RESCHEDULE_THRESHOLD = $is_slower_model ? 160 : 140; // GPT-4/5: 20s buffer, GPT-3.5: 40s buffer
				
				if (isset($GLOBALS['aebg_job_start_time'])) {
					$elapsed = microtime(true) - $GLOBALS['aebg_job_start_time'];
					
					// CRITICAL: Check server timeout FIRST (180s) - this is the real limit
					if ($elapsed >= $RESCHEDULE_THRESHOLD && $elapsed < $SERVER_TIMEOUT) {
						// Approaching server timeout - need to reschedule
						$item_id = $GLOBALS['aebg_current_item_id'] ?? null;
						
						// CRITICAL: Verify item_id is set and log it
						if ( ! $item_id ) {
							error_log( '[AEBG] ⚠️ WARNING: aebg_current_item_id not set in globals when rescheduling! Available keys: ' . implode( ', ', array_keys( $GLOBALS ) ) );
						} else {
							error_log( '[AEBG] 🔍 Rescheduling Step 4: item_id from globals=' . $item_id );
						}
						
						if ($item_id && class_exists('\AEBG\Core\CheckpointManager')) {
							$current_step = \AEBG\Core\CheckpointManager::getCurrentStep($item_id);
							if ($current_step) {
								error_log('[AEBG] ⚠️ CRITICAL: Approaching server timeout (180s) during template processing - elapsed: ' . round($elapsed, 1) . 's - checkpoint exists at step: ' . $current_step);
								error_log('[AEBG] 🔄 Throwing exception to trigger proactive reschedule');
								
								// Reschedule action to continue from checkpoint
								// Check if we're in step-by-step mode
								$use_step_by_step = get_option( 'aebg_use_step_by_step_actions', true );
								
								if (function_exists('as_schedule_single_action')) {
									if ( $use_step_by_step && class_exists( '\AEBG\Core\StepHandler' ) ) {
										// Step-by-step mode: Reschedule current step (Step 4)
										$step_4_hook = \AEBG\Core\StepHandler::get_step_hook( 'step_4' );
										if ( $step_4_hook ) {
											// CRITICAL: Verify item_id is an integer before scheduling
											$item_id_int = (int) $item_id;
											if ( $item_id_int !== (int) $item_id ) {
												error_log( '[AEBG] ⚠️ WARNING: item_id is not an integer! item_id=' . var_export( $item_id, true ) );
											}
											
											// CRITICAL: Schedule the rescheduled action
											// Fetch title for rescheduling (use $title parameter if available, otherwise fetch from DB)
											$reschedule_title = $title ?? '';
											if ( empty( $reschedule_title ) && $item_id_int ) {
												global $wpdb;
												$item = $wpdb->get_row( $wpdb->prepare( 
													"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
													$item_id_int 
												) );
												$reschedule_title = $item->source_title ?? '';
											}
											$scheduled_time = time() + 2;
											$action_id = as_schedule_single_action( $scheduled_time, $step_4_hook, [ $item_id_int, $reschedule_title ], 'aebg_generation_' . $item_id_int, true );
											error_log( sprintf(
												'[AEBG] ✅ Step 4 rescheduled via ElementorTemplateProcessor for item_id=%d | scheduled_time=%d (in %d seconds) | action_id=%s | hook=%s | args=%s',
												$item_id_int,
												$scheduled_time,
												2,
												$action_id ? (string) $action_id : 'null',
												$step_4_hook,
												json_encode( [ $item_id_int, $reschedule_title ] )
											) );
										} else {
											// Fallback to legacy
											// Fetch title for rescheduling
											$reschedule_title = $title ?? '';
											if ( empty( $reschedule_title ) && $item_id ) {
												global $wpdb;
												$item = $wpdb->get_row( $wpdb->prepare( 
													"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
													$item_id 
												) );
												$reschedule_title = $item->source_title ?? '';
											}
											as_schedule_single_action( time() + 2, 'aebg_execute_generation', [ 'item_id' => $item_id, 'title' => $reschedule_title ] );
										}
									} else {
										// Legacy mode: Reschedule full generation
										// Fetch title for rescheduling
										$reschedule_title = $title ?? '';
										if ( empty( $reschedule_title ) && $item_id ) {
											global $wpdb;
											$item = $wpdb->get_row( $wpdb->prepare( 
												"SELECT source_title FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d", 
												$item_id 
											) );
											$reschedule_title = $item->source_title ?? '';
										}
										as_schedule_single_action( time() + 2, 'aebg_execute_generation', [ 'item_id' => $item_id, 'title' => $reschedule_title ] );
									}
								}
								
								// Throw exception to stop current execution (action will continue in next request)
								throw new \Exception('Proactive reschedule: Approaching server timeout (180s) - elapsed: ' . round($elapsed, 1) . 's');
							}
						}
					}
					
					$max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER; // Use centralized timeout (1750s = 30min - 50s buffer)
					$remaining_time = $max_time - $elapsed;
					
					// Also check against server timeout for remaining time calculation
					$server_remaining = $SERVER_TIMEOUT - $elapsed;
					if ($server_remaining < $remaining_time) {
						$remaining_time = $server_remaining;
					}
				} elseif (isset($this->apply_start_time)) {
					$elapsed = microtime(true) - $this->apply_start_time;
					$max_time = 55; // Max 55 seconds for apply phase (more lenient)
					$remaining_time = $max_time - $elapsed;
				}
				
				// CRITICAL: For long-running operations (like FAQ generation), need more time
				// Estimate based on prompt length - longer prompts take longer to generate
				$prompt_length = strlen($prompt);
				$estimated_time = 5; // Base time
				if ($prompt_length > 500) {
					$estimated_time = 15; // Long prompts (like FAQ) need more time
				} elseif ($prompt_length > 200) {
					$estimated_time = 10; // Medium prompts
				}
				
				// Check if we have enough time (need estimated time + 5 seconds buffer)
				$required_time = $estimated_time + 5;
				if ($remaining_time < $required_time) {
					error_log('[AEBG] ⚠️ Skipping inline generation for ' . $widget_type . ' - insufficient time remaining (elapsed: ' . round($elapsed, 2) . 's, remaining: ' . round($remaining_time, 2) . 's, need: ' . $required_time . 's, prompt length: ' . $prompt_length . ')');
					// Queue for retry if we have at least 5 seconds left (might be able to retry later)
					if ($remaining_time >= 5) {
						$this->skipped_widgets_queue[] = [
							'widget_data' => &$data,
							'widget_type' => $widget_type,
							'prompt' => $prompt,
							'title' => $title,
							'products' => $products,
							'context' => $updated_context,
							'api_key' => $api_key,
							'ai_model' => $ai_model,
						];
						error_log('[AEBG] Queued ' . $widget_type . ' widget for retry (queue size: ' . count($this->skipped_widgets_queue) . ')');
					}
					return; // Skip this widget to prevent timeout
				}
				
				error_log('[AEBG] Generating content inline for widget: ' . $widget_type . ' (button count: ' . ($this->button_generation_count + 1) . ', remaining time: ' . round($remaining_time, 2) . 's)');
				error_log('[AEBG] Prompt preview: ' . substr($prompt, 0, 100) . '...');
				
				if ($widget_type === 'button') {
					$this->button_generation_count++;
				}
				
				// CRITICAL: Set a maximum timeout for this individual API call
				// Use remaining time minus 5 seconds buffer, but max 20 seconds per call
				$call_timeout = min(20, max(5, $remaining_time - 5));
				// Only log timeout if it's low (<10s) - indicates time pressure
				if ($call_timeout < 10) {
					error_log('[AEBG] Call timeout set to: ' . $call_timeout . ' seconds (low - time pressure)');
				}
				
				// CRITICAL: Update Action Scheduler action timestamp BEFORE long API call
				// This prevents Action Scheduler from marking the action as failed if the API call takes a while
				if (isset($GLOBALS['aebg_current_action_id']) && $GLOBALS['aebg_current_action_id'] && class_exists('\ActionScheduler_Store')) {
					try {
						$store = \ActionScheduler_Store::instance();
						$action = $store->fetch_action($GLOBALS['aebg_current_action_id']);
						if ($action) {
							$store->save_action($action);
							error_log('[AEBG] Updated Action Scheduler action timestamp before API call for widget: ' . $widget_type);
						}
					} catch (\Exception $e) {
						// Silently fail - best effort
					}
				}
				
				// CRITICAL: Add small delay before inline API calls (except first) to prevent connection pool exhaustion
				// When processing many widgets with inline generation, rapid API calls can exhaust the connection pool
				// This delay allows the connection pool to recover between requests
				$this->inline_api_call_count++;
				if ($this->inline_api_call_count > 1) {
					$delay_microseconds = \AEBG\Core\SettingsHelper::getDelayBetweenRequestsMicroseconds();
					usleep($delay_microseconds);
					// Force garbage collection every 10 calls to help free up connections
					if ($this->inline_api_call_count % 10 === 0) {
						gc_collect_cycles();
						error_log('[AEBG] Garbage collection performed after inline API call #' . $this->inline_api_call_count);
					}
				}
				
				$generation_start = microtime(true);
				
				// CRITICAL: Wrap API call in try-catch to prevent hanging
				try {
					$generated = $this->content_generator->generateContentWithPrompt($title, $products, $updated_context, $api_key, $ai_model, $prompt);
					$generation_elapsed = microtime(true) - $generation_start;
					
					// Only log slow generations (>3s) - too verbose otherwise
					if ($generation_elapsed > 3) {
						error_log('[AEBG] generateContentWithPrompt for widget ' . $widget_type . ' completed in ' . round($generation_elapsed, 2) . ' seconds');
					}
				} catch (\Throwable $e) {
					$generation_elapsed = microtime(true) - $generation_start;
					error_log('[AEBG] ⚠️ CRITICAL ERROR: generateContentWithPrompt threw exception after ' . round($generation_elapsed, 2) . ' seconds: ' . $e->getMessage());
					error_log('[AEBG] ⚠️ Exception trace: ' . $e->getTraceAsString());
					$generated = new \WP_Error('generation_exception', 'Content generation failed: ' . $e->getMessage());
				}
				
				// CRITICAL: Check if generation took too long (potential hang)
				if ($generation_elapsed > 30) {
					error_log('[AEBG] ⚠️ WARNING: Inline generation took ' . round($generation_elapsed, 2) . 's for ' . $widget_type . ' - possible hang detected');
				} elseif ($generation_elapsed > 10) {
					error_log('[AEBG] ⚠️ Slow inline generation for ' . $widget_type . ': ' . round($generation_elapsed, 2) . 's');
				}
				
				if ($generated && !is_wp_error($generated)) {
					error_log('[AEBG] Successfully generated content for widget: ' . $widget_type . ' (length: ' . strlen($generated) . ', time: ' . round($generation_elapsed, 2) . 's)');
					$this->applyContentToWidgetByType($data, $generated, $widget_type);
					// Mark widget as processed to avoid reprocessing on resume
					$settings['_aebg_widget_processed'] = true;
					error_log('[AEBG] ✅ Content applied successfully to widget: ' . $widget_type . ' (marked as processed)');
				} else {
					$error_msg = is_wp_error($generated) ? $generated->get_error_message() : 'Unknown error';
					error_log('[AEBG] Failed to generate content for widget ' . $widget_type . ': ' . $error_msg);
					
					// NOTE: Rate limit errors are now handled by APIClient with proper wait/retry logic
					// No need for manual delays - APIClient will handle retries automatically
					if (is_wp_error($generated)) {
						$error_code = $generated->get_error_code();
						$error_message = $generated->get_error_message();
						// Log error but let APIClient handle retries
						if (strpos($error_message, 'rate limit') !== false || $error_code === 'rate_limit_exceeded') {
							error_log('[AEBG] Rate limit detected for ' . $widget_type . ' - APIClient will handle retry automatically');
						}
					}
				}
			}
		}
	}

	/**
	 * Apply content to widget by type.
	 *
	 * @param array  &$data Widget data (by reference).
	 * @param string $content Generated content.
	 * @param string $widget_type Widget type.
	 * @return void
	 */
	private function applyContentToWidgetByType(&$data, $content, $widget_type) {
		if (!isset($data['settings']) || !is_array($data['settings'])) {
			error_log('[AEBG] Invalid data structure for applyContentToWidgetByType');
			return;
		}

		$generated_content = trim($content);
		if (empty($generated_content)) {
			error_log('[AEBG] Empty generated content, not applying');
			return;
		}

		error_log('[AEBG] Applying content to widget type: ' . $widget_type);
		error_log('[AEBG] Generated content: ' . substr($generated_content, 0, 100) . '...');

		// Clean the content by removing surrounding quotes (like old version)
		$cleaned_content = $generated_content;
		if (preg_match('/^["\'](.+)["\']$/s', $cleaned_content, $matches)) {
			$cleaned_content = $matches[1];
		}
		$cleaned_content = trim($cleaned_content);

		$settings = &$data['settings'];

		switch ($widget_type) {
			case 'text-editor':
				$settings['editor'] = ContentFormatter::formatTextEditorContent($cleaned_content);
				error_log('[AEBG] Applied text editor content: ' . substr($cleaned_content, 0, 100) . '...');
				break;
			case 'heading':
				// For headings, use first line as title (like old version)
				$lines = explode("\n", $cleaned_content);
				$title = trim($lines[0]);
				$title = strip_tags($title);
				$title = trim($title, '"\''); // Remove quotes
				$settings['title'] = ContentFormatter::formatHeadingContent($title);
				error_log('[AEBG] Applied heading title: ' . $title);
				break;
			case 'button':
				// For buttons, check if content is a URL (like old version)
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
				} elseif (isset($settings['url'])) {
					// Also check for url field (like old version)
					$shortcodes = new \AEBG\Core\Shortcodes();
					$fixed_url = $shortcodes->process_affiliate_link($cleaned_content);
					$settings['url'] = $fixed_url;
					error_log('[AEBG] Applied button URL: ' . $fixed_url);
				} else {
					// This is text, apply to button text
					$settings['text'] = ContentFormatter::formatButtonContent($cleaned_content);
					error_log('[AEBG] Applied button text: ' . $settings['text']);
				}
				break;
			case 'image':
				// For images, check if content is a URL (like old version)
				if (preg_match('/^https?:\/\//i', $cleaned_content) || filter_var($cleaned_content, FILTER_VALIDATE_URL)) {
					// This is a URL, apply to image.url
					if (!isset($settings['image']) || !is_array($settings['image'])) {
						$settings['image'] = [];
					}
					$settings['image']['url'] = $cleaned_content;
					$settings['image']['id'] = '';
					error_log('[AEBG] Applied image URL: ' . $cleaned_content);
				} else {
					// This is text, apply as caption
					$settings['caption'] = $cleaned_content;
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
			case 'aebg-icon-list':
				// Parse content into list items
				$list_items = ContentFormatter::parseContentIntoListItems($cleaned_content);
				if (!empty($list_items)) {
					$settings['icon_list'] = $list_items;
					error_log('[AEBG] Applied icon list with ' . count($list_items) . ' items');
				}
				break;
			case 'link':
			case 'url':
				// For link/URL fields, process affiliate link (like old version)
				$shortcodes = new \AEBG\Core\Shortcodes();
				$fixed_link_url = $shortcodes->process_affiliate_link($cleaned_content);
				$settings['url'] = $fixed_link_url;
				error_log('[AEBG] Applied link URL: ' . $fixed_link_url);
				break;
			default:
				// Try common fields (like old version)
				if (isset($settings['content'])) {
					$settings['content'] = $cleaned_content;
					error_log('[AEBG] Applied content to generic content field');
				} elseif (isset($settings['text'])) {
					$settings['text'] = $cleaned_content;
					error_log('[AEBG] Applied content to generic text field');
				} elseif (isset($settings['title'])) {
					$settings['title'] = $cleaned_content;
					error_log('[AEBG] Applied content to generic title field');
				} elseif (isset($settings['url'])) {
					// Check if this is a URL field (like old version)
					$shortcodes = new \AEBG\Core\Shortcodes();
					$fixed_url = $shortcodes->process_affiliate_link($cleaned_content);
					if (is_string($settings['url'])) {
						$settings['url'] = $fixed_url;
						error_log('[AEBG] Applied content to URL field (string): ' . $fixed_url);
					} elseif (is_array($settings['url']) && isset($settings['url']['url'])) {
						$settings['url']['url'] = $fixed_url;
						error_log('[AEBG] Applied content to URL field (array): ' . $fixed_url);
					}
				} else {
					error_log('[AEBG] No suitable field found for widget type: ' . $widget_type);
				}
				break;
		}
	}

	/**
	 * Get original content from widget.
	 *
	 * @param array  $data Widget data.
	 * @param string $widget_type Widget type.
	 * @return string Original content.
	 */
	private function getOriginalContent($data, $widget_type) {
		if (!isset($data['settings']) || !is_array($data['settings'])) {
			return '';
		}

		$settings = $data['settings'];

		switch ($widget_type) {
			case 'heading':
				return $settings['title'] ?? '';
			case 'text-editor':
				return $settings['editor'] ?? '';
			case 'button':
				return $settings['text'] ?? '';
			case 'image':
				return $settings['caption'] ?? '';
			case 'icon':
				return $settings['text'] ?? '';
			case 'icon-box':
				return $settings['title_text'] ?? '';
			case 'flip-box':
				return $settings['title_text_a'] ?? '';
			case 'call-to-action':
				return $settings['title'] ?? '';
			default:
				return $settings['content'] ?? $settings['text'] ?? $settings['title'] ?? '';
		}
	}

	/**
	 * Extract dependencies from prompt.
	 *
	 * @param string $prompt The prompt.
	 * @return array Dependencies.
	 */
	private function extractDependencies($prompt) {
		$dependencies = [];

		// Look for field references
		if (preg_match_all('/\{([^}]+)\}/', $prompt, $matches)) {
			foreach ($matches[1] as $match) {
				if (strpos($match, 'field_') === 0) {
					$dependencies[] = $match;
				}
			}
		}

		return $dependencies;
	}

	/**
	 * Remove unused product containers.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $actual_product_count Actual product count.
	 * @return array Updated data.
	 */
	public function removeUnusedProductContainers($elementor_data, $actual_product_count) {
		if (!is_array($elementor_data)) {
			return $elementor_data;
		}

		// Find all product containers
		$containers = $this->findProductContainers($elementor_data);

		// Remove containers for products that don't exist
		foreach ($containers as $container_info) {
			$product_number = $container_info['product_number'];
			if ($product_number > $actual_product_count) {
				$this->removeContainer($elementor_data, $container_info);
			}
		}

		return $elementor_data;
	}

	/**
	 * Find product containers in Elementor data.
	 *
	 * @param array $data Elementor data.
	 * @return array Array of container info.
	 */
	private function findProductContainers($data) {
		$containers = [];

		if (!is_array($data)) {
			return $containers;
		}

		// Check if this is a product container
		if (isset($data['settings']['_element_id']) && preg_match('/^product-(\d+)$/', $data['settings']['_element_id'], $matches)) {
			$containers[] = [
				'product_number' => (int)$matches[1],
				'element_id' => $data['settings']['_element_id'],
				'data' => $data
			];
		}

		// Recursively check child elements
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $element) {
				$containers = array_merge($containers, $this->findProductContainers($element));
			}
		}

		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $content_item) {
				$containers = array_merge($containers, $this->findProductContainers($content_item));
			}
		}

		return $containers;
	}

	/**
	 * Remove container from Elementor data.
	 *
	 * @param array &$data Elementor data (by reference).
	 * @param array $container_info Container info.
	 * @return void
	 */
	private function removeContainer(&$data, $container_info) {
		// This would need to traverse and remove the specific container
		// Implementation depends on data structure
	}

	/**
	 * Validate Elementor data structure.
	 *
	 * @param array $data Elementor data.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validateElementorData($data) {
		if (!is_array($data)) {
			return new \WP_Error('invalid_data', 'Elementor data must be an array');
		}

		// Basic validation - can be extended
		if (empty($data)) {
			return new \WP_Error('empty_data', 'Elementor data is empty');
		}

		return true;
	}

	/**
	 * Optimize Elementor data structure.
	 *
	 * @param array $data Elementor data.
	 * @return array Optimized data.
	 */
	public function optimizeElementorData($data) {
		return DataUtilities::optimizeDataStructure($data);
	}

	/**
	 * Get Elementor data from post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error Elementor data or WP_Error.
	 */
	public function getElementorData($post_id) {
		error_log('[AEBG] getElementorData: Starting for post ID: ' . $post_id);
		$get_meta_start = microtime(true);
		
		// CRITICAL: Clear cache before getting post meta to prevent stale data
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete($post_id, 'post_meta');
			error_log('[AEBG] getElementorData: Cleared post_meta cache for post ' . $post_id);
		}
		
		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		$get_meta_elapsed = microtime(true) - $get_meta_start;
		error_log('[AEBG] getElementorData: get_post_meta completed in ' . round($get_meta_elapsed, 2) . ' seconds');
		
		if (empty($elementor_data)) {
			error_log('[AEBG] getElementorData: No Elementor data found for post ID: ' . $post_id);
			return new \WP_Error('no_elementor_data', 'No Elementor data found for post ID: ' . $post_id);
		}

		error_log('[AEBG] getElementorData: Data type: ' . gettype($elementor_data) . ', empty: ' . (empty($elementor_data) ? 'yes' : 'no'));

		// Decode if it's a JSON string
		if (is_string($elementor_data)) {
			error_log('[AEBG] getElementorData: Decoding JSON string...');
			$decode_start = microtime(true);
			$decoded = json_decode($elementor_data, true);
			$decode_elapsed = microtime(true) - $decode_start;
			error_log('[AEBG] getElementorData: JSON decode completed in ' . round($decode_elapsed, 2) . ' seconds');
			
			if (json_last_error() === JSON_ERROR_NONE) {
				error_log('[AEBG] getElementorData: Successfully decoded JSON, returning array');
				return $decoded;
			} else {
				error_log('[AEBG] getElementorData: JSON decode error: ' . json_last_error_msg());
			}
		}

		if (is_array($elementor_data)) {
			error_log('[AEBG] getElementorData: Data is already array, returning directly');
			return $elementor_data;
		}

		error_log('[AEBG] getElementorData: Invalid Elementor data format');
		return new \WP_Error('invalid_elementor_data', 'Invalid Elementor data format');
	}

	/**
	 * Check for AI content in data.
	 *
	 * @param array $data Elementor data.
	 * @return bool True if AI content found.
	 */
	public function checkForAIContent($data) {
		if (!is_array($data)) {
			return false;
		}

		// Check widgets
		if (isset($data['elType']) && $data['elType'] === 'widget') {
			if (isset($data['settings']['aebg_ai_enable']) && $data['settings']['aebg_ai_enable'] === 'yes') {
				return true;
			}
		}

		// Recursively check child elements
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $element) {
				if ($this->checkForAIContent($element)) {
					return true;
				}
			}
		}

		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $content_item) {
				if ($this->checkForAIContent($content_item)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Verify icon list content.
	 *
	 * @param array $data Elementor data.
	 * @return void
	 */
	public function verifyIconListContent($data) {
		// Verify icon list widgets have proper content
		// Implementation can be extended
	}

	/**
	 * Update context with product information (like old version).
	 * This enriches the context with actual product data extracted from products array.
	 *
	 * @param array $context Original context.
	 * @param array $products Products array.
	 * @return array Updated context.
	 */
	private function updateContextWithProducts($context, $products) {
		if (empty($products) || !is_array($products)) {
			return $context;
		}
		
		// Use TitleAnalyzer's method if available, otherwise implement inline
		if (method_exists('AEBG\Core\TitleAnalyzer', 'updateContextWithProducts')) {
			$title_analyzer = new TitleAnalyzer(new Variables(), []);
			return $title_analyzer->updateContextWithProducts($context, $products);
		}
		
		// Fallback implementation (matches old version)
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
			
			// Extract features from description
			if (!empty($product['description'])) {
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
			$updated_context['actual_max_rating'] = max($actual_ratings);
		}
		
		if (!empty($actual_reviews)) {
			$updated_context['actual_total_reviews'] = array_sum($actual_reviews);
		}
		
		return $updated_context;
	}

	/**
	 * Generate an AI image using OpenAI DALL·E API (like old version).
	 *
	 * @param string $prompt The image prompt.
	 * @param string $api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return string|false Image URL or false on failure.
	 */
	private function generateAIImage($prompt, $api_key, $ai_model) {
		// Determine image model and settings based on the AI model
		$image_model = $this->getImageModelFromTextModel($ai_model);
		$image_size = $this->getImageSizeFromModel($image_model);
		
		$request_body = [
			'model' => $image_model,
			'prompt' => $prompt,
			'n' => 1,
			'size' => $image_size,
		];
		
		// Add quality parameter for DALL-E 3
		$quality = 'standard';
		if (strpos($image_model, 'dall-e-3') === 0) {
			$request_body['quality'] = $quality;
		}
		
		$json_body = json_encode($request_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json_body === false) {
			error_log('[AEBG] Failed to encode request body for AI image generation: ' . json_last_error_msg());
			return false;
		}
		
		// ULTRA-ROBUST: Use APIClient::makeRequest() for ultra-robust timeout handling
		$data = \AEBG\Core\APIClient::makeRequest(
			'https://api.openai.com/v1/images/generations',
			$api_key,
			$request_body,
			60,
			3
		);
		
		if (is_wp_error($data)) {
			error_log('[AEBG] AI image generation failed: ' . $data->get_error_message());
			return false;
		}
		
		if (empty($data) || !is_array($data)) {
			error_log('[AEBG] AI image generation returned empty or invalid response');
			return false;
		}
		
		// Check for API-level errors in response
		if (isset($data['error'])) {
			$error_message = $data['error']['message'] ?? 'Unknown API error';
			error_log('[AEBG] AI image generation failed: ' . $error_message);
			return false;
		}
		
		if (isset($data['data'][0]['url'])) {
			$image_url = $data['data'][0]['url'];
			error_log('[AEBG] AI image generated successfully: ' . $image_url);
			
			// Track image generation
			$item_id = $GLOBALS['aebg_current_item_id'] ?? null;
			$batch_id = null;
			$batch_item_id = null;
			
			if ($item_id) {
				global $wpdb;
				$item = $wpdb->get_row($wpdb->prepare(
					"SELECT batch_id FROM {$wpdb->prefix}aebg_batch_items WHERE id = %d",
					$item_id
				));
				if ($item) {
					$batch_id = $item->batch_id;
					$batch_item_id = $item_id;
				}
			}
			
			\AEBG\Core\UsageTracker::record_image_generation([
				'batch_id' => $batch_id,
				'batch_item_id' => $batch_item_id,
				'post_id' => $GLOBALS['aebg_current_post_id'] ?? null,
				'user_id' => get_current_user_id(),
				'model' => $image_model,
				'size' => $image_size,
				'quality' => $quality,
				'request_type' => 'image_generation',
				'field_id' => $this->current_field_id ?? null,
				'step_name' => $GLOBALS['aebg_current_step'] ?? 'image_generation',
			]);
			
			// Return external URL (downloading/saving can be handled elsewhere if needed)
			return $image_url;
		} else {
			error_log('[AEBG] AI image generation response missing URL: ' . print_r($data, true));
			return false;
		}
	}

	/**
	 * Get the appropriate image model based on the text model (like old version).
	 *
	 * @param string $text_model The text model being used.
	 * @return string The image model to use.
	 */
	private function getImageModelFromTextModel($text_model) {
		// Map text models to image models
		if (strpos($text_model, 'gpt-4') === 0) {
			return 'dall-e-3';
		} elseif (strpos($text_model, 'gpt-3.5') === 0) {
			return 'dall-e-2';
		} else {
			// Default to DALL-E 2 for other models
			return 'dall-e-2';
		}
	}

	/**
	 * Get the appropriate image size based on the model (like old version).
	 *
	 * @param string $model The image model being used.
	 * @return string The image size.
	 */
	private function getImageSizeFromModel($model) {
		if (strpos($model, 'dall-e-3') === 0) {
			return '1024x1024'; // DALL-E 3 only supports 1024x1024
		} else {
			return '1024x1024'; // DALL-E 2 default
		}
	}

	/**
	 * CRITICAL: Cleanup after Elementor data processing to prevent memory accumulation (like old version)
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
	 * Create a deep copy of an array
	 * 
	 * @param array $array Array to copy
	 * @return array Deep copy of the array
	 */
	private function deepCopyArray( $array ) {
		if ( ! is_array( $array ) ) {
			return $array;
		}
		
		$copy = [];
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$copy[ $key ] = $this->deepCopyArray( $value );
			} else {
				$copy[ $key ] = $value;
			}
		}
		
		return $copy;
	}

	/**
	 * Recursively count processed widgets in template data
	 * Helper method for checkpoint verification
	 * 
	 * @param array $data Template data
	 * @param int &$processed_count Reference to processed count (incremented)
	 * @param int &$total_count Reference to total count (incremented)
	 * @return void
	 */
	private function countProcessedWidgetsInData( $data, &$processed_count, &$total_count ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		
		// Handle numeric array (pages array)
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			// This is a numeric array - recurse into each item
			foreach ( $data as $item ) {
				$this->countProcessedWidgetsInData( $item, $processed_count, $total_count );
			}
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
				$this->countProcessedWidgetsInData( $item, $processed_count, $total_count );
			}
		}
		
		if ( isset( $data['elements'] ) && is_array( $data['elements'] ) ) {
			foreach ( $data['elements'] as $element ) {
				$this->countProcessedWidgetsInData( $element, $processed_count, $total_count );
			}
		}
	}
}


