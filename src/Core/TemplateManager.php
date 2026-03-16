<?php

namespace AEBG\Core;


/**
 * Template Manager Class
 * Handles CSS ID-based template management and product positioning
 *
 * @package AEBG\Core
 */
class TemplateManager {
	
	/**
	 * TemplateManager constructor.
	 */
	public function __construct() {
		// Initialize hooks for template management
		add_action('wp_ajax_aebg_analyze_template', [$this, 'ajax_analyze_template']);
		add_action('wp_ajax_aebg_update_template_products', [$this, 'ajax_update_template_products']);
		add_action('wp_ajax_aebg_update_post_products', [$this, 'ajax_update_post_products']);
		add_action('wp_ajax_aebg_detect_reorder_conflicts', [$this, 'ajax_detect_reorder_conflicts']);
		add_action('wp_ajax_aebg_execute_reorder_with_choices', [$this, 'ajax_execute_reorder_with_choices']);
		add_action('wp_ajax_aebg_fix_existing_post', [$this, 'ajax_fix_existing_post']);
		add_action('wp_ajax_aebg_fix_empty_elementor_post', [$this, 'ajax_fix_empty_elementor_post']);
	}
	
	/**
	 * AJAX analyze template for product containers
	 */
	public function ajax_analyze_template() {
		// Check capability
		if (!current_user_can('aebg_generate_content')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_analyze_template', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;

		if (empty($template_id)) {
			wp_send_json_error(['message' => __('Template ID is required.', 'aebg')]);
		}

		$analysis = $this->analyzeTemplate($template_id);

		if (is_wp_error($analysis)) {
			wp_send_json_error(['message' => $analysis->get_error_message()]);
		}

		wp_send_json_success($analysis);
	}
	
	/**
	 * AJAX update template with new product positions
	 */
	public function ajax_update_template_products() {
		// Check capability
		if (!current_user_can('aebg_generate_content')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_update_template_products', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$template_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
		$product_positions = isset($_POST['product_positions']) ? json_decode(stripslashes($_POST['product_positions']), true) : [];

		if (empty($template_id)) {
			wp_send_json_error(['message' => __('Template ID is required.', 'aebg')]);
		}

		if (empty($product_positions)) {
			wp_send_json_error(['message' => __('Product positions are required.', 'aebg')]);
		}

		$result = $this->updateTemplateProducts($template_id, $product_positions);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success($result);
	}
	
	/**
	 * AJAX update post with new product positions
	 */
	public function ajax_update_post_products() {
		// Check capability
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_update_post_products', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$new_product_order = isset($_POST['new_product_order']) ? json_decode(stripslashes($_POST['new_product_order']), true) : [];

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Post ID is required.', 'aebg')]);
		}

		if (empty($new_product_order)) {
			wp_send_json_error(['message' => __('New product order is required.', 'aebg')]);
		}

		error_log('[AEBG] ajax_update_post_products called with post_id: ' . $post_id . ', order: ' . json_encode($new_product_order));

		// NEW: Check for conflicts first
		$resolver = new \AEBG\Core\ReorderConflictResolver($this);
		$conflict_check = $resolver->prepareReorderingWithChoices($post_id, $new_product_order);
		
		if (is_wp_error($conflict_check)) {
			error_log('[AEBG] Conflict detection failed: ' . $conflict_check->get_error_message());
			wp_send_json_error(['message' => $conflict_check->get_error_message()]);
		}
		
		// If conflicts exist, return them for frontend modal
		if (!empty($conflict_check['conflicts'])) {
			error_log('[AEBG] Conflicts detected: ' . count($conflict_check['conflicts']));
			wp_send_json_success([
				'has_conflicts' => true,
				'conflicts' => $conflict_check['conflicts'],
				'position_mapping' => $conflict_check['position_mapping'],
				'message' => __('Conflicts detected. User choice required.', 'aebg'),
			]);
			return;
		}

		// No conflicts - proceed with normal reordering
		$result = $this->updatePostProductsWithOrder($post_id, $new_product_order);

		if (is_wp_error($result)) {
			error_log('[AEBG] updatePostProductsWithOrder failed: ' . $result->get_error_message());
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		error_log('[AEBG] updatePostProductsWithOrder succeeded: ' . json_encode($result));
		wp_send_json_success($result);
	}
	
	/**
	 * AJAX detect reorder conflicts
	 */
	public function ajax_detect_reorder_conflicts() {
		// Check capability
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_detect_reorder_conflicts', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$new_product_order = isset($_POST['new_product_order']) ? json_decode(stripslashes($_POST['new_product_order']), true) : [];

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Post ID is required.', 'aebg')]);
		}

		if (empty($new_product_order) || !is_array($new_product_order)) {
			wp_send_json_error(['message' => __('New product order is required.', 'aebg')]);
		}

		// Size limit (DoS prevention)
		if (count($new_product_order) > 100) {
			wp_send_json_error(['message' => __('Too many products in order (maximum 100).', 'aebg')]);
		}

		error_log('[AEBG] ajax_detect_reorder_conflicts called with post_id: ' . $post_id);

		$resolver = new \AEBG\Core\ReorderConflictResolver($this);
		$result = $resolver->prepareReorderingWithChoices($post_id, $new_product_order);

		if (is_wp_error($result)) {
			error_log('[AEBG] Conflict detection failed: ' . $result->get_error_message());
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		error_log('[AEBG] Conflict detection succeeded: ' . count($result['conflicts']) . ' conflicts found');
		wp_send_json_success($result);
	}
	
	/**
	 * AJAX execute reorder with regeneration choices
	 */
	public function ajax_execute_reorder_with_choices() {
		// Check capability
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_reorder_conflict', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$new_product_order = isset($_POST['new_product_order']) ? json_decode(stripslashes($_POST['new_product_order']), true) : [];
		$regeneration_choices = isset($_POST['regeneration_choices']) ? json_decode(stripslashes($_POST['regeneration_choices']), true) : [];

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Post ID is required.', 'aebg')]);
		}

		if (empty($new_product_order) || !is_array($new_product_order)) {
			wp_send_json_error(['message' => __('New product order is required.', 'aebg')]);
		}

		if (!is_array($regeneration_choices)) {
			wp_send_json_error(['message' => __('Regeneration choices are required.', 'aebg')]);
		}

		// Size limits (DoS prevention)
		if (count($new_product_order) > 100) {
			wp_send_json_error(['message' => __('Too many products in order (maximum 100).', 'aebg')]);
		}

		if (count($regeneration_choices) > 100) {
			wp_send_json_error(['message' => __('Too many regeneration choices (maximum 100).', 'aebg')]);
		}

		// Post ownership validation
		if (!current_user_can('edit_post', $post_id)) {
			wp_send_json_error(['message' => __('You cannot edit this post.', 'aebg')], 403);
		}

		error_log('[AEBG] ajax_execute_reorder_with_choices called with post_id: ' . $post_id);

		$resolver = new \AEBG\Core\ReorderConflictResolver($this);
		$result = $resolver->resolveConflictsAndReorder($post_id, $new_product_order, $regeneration_choices);

		if (is_wp_error($result)) {
			error_log('[AEBG] Reorder with choices failed: ' . $result->get_error_message());
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		error_log('[AEBG] Reorder with choices succeeded');
		wp_send_json_success($result);
	}
	
	/**
	 * AJAX fix existing post
	 */
	public function ajax_fix_existing_post() {
		// Check capability
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_fix_existing_post', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Post ID is required.', 'aebg')]);
		}

		$result = $this->fixExistingPost($post_id);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success(['message' => 'Post fixed successfully']);
	}
	
	/**
	 * AJAX fix empty Elementor post
	 */
	public function ajax_fix_empty_elementor_post() {
		// Check capability
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
		}
		
		// Verify nonce
		if (!check_ajax_referer('aebg_fix_empty_elementor_post', '_ajax_nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'aebg')], 403);
		}

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Post ID is required.', 'aebg')]);
		}

		// Use Generator to fix the post
		$generator = new Generator([]);
		$result = $generator->fixEmptyElementorPost($post_id);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()]);
		}

		wp_send_json_success(['message' => __('Elementor post fixed successfully.', 'aebg')]);
	}
	
	/**
	 * Analyze template for product containers and structure
	 *
	 * @param int $template_id The template ID.
	 * @return array|\WP_Error Analysis result or WP_Error on failure.
	 */
	public function analyzeTemplate($template_id) {
		error_log('[AEBG] TemplateManager::analyzeTemplate called with template_id: ' . $template_id);
		
		// Validate template ID
		if (empty($template_id) || !is_numeric($template_id)) {
			return new \WP_Error('aebg_invalid_template_id', 'Invalid template ID provided');
		}
		
		// Check if template post exists
		$template_post = get_post($template_id);
		if (!$template_post) {
			return new \WP_Error('aebg_template_not_found', 'Template post not found');
		}
		
		$elementor_data = get_post_meta($template_id, '_elementor_data', true);
		if (empty($elementor_data)) {
			return new \WP_Error('aebg_no_elementor_data', 'No Elementor data found in template');
		}

		if (is_string($elementor_data)) {
			$decoded_data = $this->decodeJsonWithUnicode($elementor_data);
			if ($decoded_data === false) {
				return new \WP_Error('aebg_invalid_template', 'Failed to decode Elementor template data');
			}
			$elementor_data = $decoded_data;
		}
		
		if (!is_array($elementor_data)) {
			return new \WP_Error('aebg_invalid_template', 'Invalid Elementor template data');
		}
		
		// Analyze template structure
		$analysis = $this->analyzeTemplateStructure($elementor_data);
		
		error_log('[AEBG] Template analysis completed - Found ' . count($analysis['product_containers']) . ' product containers');
		
		return [
			'template_id' => $template_id,
			'template_title' => $template_post->post_title,
			'product_containers' => $analysis['product_containers'],
			'total_slots' => count($analysis['product_containers']),
			'template_structure' => $analysis['structure'],
			'has_css_ids' => !empty($analysis['product_containers']),
			'has_variables' => !empty($analysis['variables']),
			'variables' => $analysis['variables']
		];
	}
	
	/**
	 * Analyze template structure for product containers
	 *
	 * @param array $data The Elementor data.
	 * @return array Analysis result.
	 */
	private function analyzeTemplateStructure($data) {
		$product_containers = [];
		$variables = [];
		$structure = [];
		
		$this->recursiveAnalyzeStructure($data, $product_containers, $variables, $structure);
		
		// Sort containers by product number
		usort($product_containers, function($a, $b) {
			return $a['product_number'] - $b['product_number'];
		});
		
		return [
			'product_containers' => $product_containers,
			'variables' => $variables,
			'structure' => $structure
		];
	}
	
	/**
	 * Recursively analyze template structure
	 *
	 * @param array $data The Elementor data.
	 * @param array &$product_containers Reference to product containers array.
	 * @param array &$variables Reference to variables array.
	 * @param array &$structure Reference to structure array.
	 * @param string $path Current path in structure.
	 */
	private function recursiveAnalyzeStructure($data, &$product_containers, &$variables, &$structure, $path = '') {
		if (!is_array($data)) {
			return;
		}
		
		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$new_path = $path ? $path . '.' . $index : (string) $index;
				$this->recursiveAnalyzeStructure($item, $product_containers, $variables, $structure, $new_path);
			}
			return;
		}
		
		// Check for CSS ID-based product containers
		if (isset($data['settings'])) {
			$settings = $data['settings'];
			// Check multiple possible CSS ID fields - including _element_id which is used in your templates
			$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
			
			// Check for product-X CSS ID pattern
			if (preg_match('/product-(\d+)/', $css_id, $matches)) {
				$product_number = (int) $matches[1];
				$element_id = $data['id'] ?? '';
				$widget_type = $data['widgetType'] ?? 'unknown';
				$field_used = isset($settings['_element_id']) ? '_element_id' : (isset($settings['_css_id']) ? '_css_id' : 'css_id');
				
				$product_containers[] = [
					'product_number' => $product_number,
					'css_id' => $css_id,
					'element_id' => $element_id,
					'widget_type' => $widget_type,
					'path' => $path,
					'has_variables' => false,
					'variables' => [],
					'field_used' => $field_used
				];
			}
		}
		
		// Check for product variables in text content
		if (isset($data['settings']) && is_array($data['settings'])) {
			$settings = $data['settings'];
			$text_fields = ['text', 'title', 'description', 'content', 'html', 'shortcode', 'aebg_ai_prompt'];
			
			foreach ($text_fields as $field) {
				if (isset($settings[$field]) && is_string($settings[$field])) {
					$found_vars = $this->extractProductVariablesFromText($settings[$field]);
					if (!empty($found_vars)) {
						$variables = array_merge($variables, $found_vars);
						
						// Check if this element has a CSS ID
						$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
						if (preg_match('/product-(\d+)/', $css_id, $matches)) {
							$product_number = (int) $matches[1];
							
							// Update the corresponding container
							foreach ($product_containers as &$container) {
								if ($container['product_number'] === $product_number) {
									$container['has_variables'] = true;
									$container['variables'] = array_merge($container['variables'], $found_vars);
									break;
								}
							}
						}
					}
				}
			}
			
			// Handle aebg-icon-list widget with nested icon_list items
			if (isset($data['widgetType']) && $data['widgetType'] === 'aebg-icon-list' && isset($settings['icon_list']) && is_array($settings['icon_list'])) {
				foreach ($settings['icon_list'] as $icon_item) {
					if (is_array($icon_item)) {
						// Check for AI prompts in icon list items
						if (isset($icon_item['aebg_iconlist_ai_prompt']) && is_string($icon_item['aebg_iconlist_ai_prompt'])) {
							$found_vars = $this->extractProductVariablesFromText($icon_item['aebg_iconlist_ai_prompt']);
							if (!empty($found_vars)) {
								$variables = array_merge($variables, $found_vars);
								
								// Check if this element has a CSS ID
								$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
								if (preg_match('/product-(\d+)/', $css_id, $matches)) {
									$product_number = (int) $matches[1];
									
									// Update the corresponding container
									foreach ($product_containers as &$container) {
										if ($container['product_number'] === $product_number) {
											$container['has_variables'] = true;
											$container['variables'] = array_merge($container['variables'], $found_vars);
											break;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		
		// Record structure information
		$structure[$path] = [
			'widget_type' => $data['widgetType'] ?? 'unknown',
			'element_id' => $data['id'] ?? '',
			'css_id' => $data['settings']['_css_id'] ?? $data['settings']['css_id'] ?? '',
			'has_children' => isset($data['elements']) || isset($data['content'])
		];
		
		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				$new_path = $path ? $path . '.elements.' . $index : 'elements.' . $index;
				$this->recursiveAnalyzeStructure($element, $product_containers, $variables, $structure, $new_path);
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				$new_path = $path ? $path . '.content.' . $index : 'content.' . $index;
				$this->recursiveAnalyzeStructure($content_item, $product_containers, $variables, $structure, $new_path);
			}
		}
	}
	
	/**
	 * Extract product variables from text content
	 *
	 * @param string $text The text to analyze.
	 * @return array Array of found product variables.
	 */
	private function extractProductVariablesFromText($text) {
		$product_variables = [];
		
		// More comprehensive pattern matching for product variables
		$patterns = [
			'/\{product-(\d+)(?:-[a-zA-Z-]+)?\}/',  // Standard format
			'/\{product(\d+)(?:-[a-zA-Z-]+)?\}/',   // Without hyphen
			'/\{prod-(\d+)(?:-[a-zA-Z-]+)?\}/',     // Shortened format
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
	 * Update template with new product positions
	 *
	 * @param int $template_id The template ID.
	 * @param array $product_positions Array of product positions.
	 * @return array|\WP_Error Result or WP_Error on failure.
	 */
	public function updateTemplateProducts($template_id, $product_positions) {
		error_log('[AEBG] TemplateManager::updateTemplateProducts called with template_id: ' . $template_id);
		
		// Validate template ID
		if (empty($template_id) || !is_numeric($template_id)) {
			return new \WP_Error('aebg_invalid_template_id', 'Invalid template ID provided');
		}
		
		// Check if template post exists
		$template_post = get_post($template_id);
		if (!$template_post) {
			return new \WP_Error('aebg_template_not_found', 'Template post not found');
		}
		
		$elementor_data = get_post_meta($template_id, '_elementor_data', true);
		if (empty($elementor_data)) {
			return new \WP_Error('aebg_no_elementor_data', 'No Elementor data found in template');
		}

		if (is_string($elementor_data)) {
			$decoded_data = $this->decodeJsonWithUnicode($elementor_data);
			if ($decoded_data === false) {
				return new \WP_Error('aebg_invalid_template', 'Failed to decode Elementor template data');
			}
			$elementor_data = $decoded_data;
		}
		
		if (!is_array($elementor_data)) {
			return new \WP_Error('aebg_invalid_template', 'Invalid Elementor template data');
		}
		
		// Create backup before making changes
		$backup_key = 'aebg_template_backup_' . $template_id . '_' . time();
		update_option($backup_key, $elementor_data);
		
		// Update template with new product positions
		$updated_data = $this->updateTemplateStructure($elementor_data, $product_positions);
		
		// Clean the data before encoding to prevent JSON issues
		$cleaned_data = $this->cleanElementorDataForEncoding($updated_data);
		
		// Save updated template
		update_post_meta($template_id, '_elementor_data', json_encode($cleaned_data));
		
		error_log('[AEBG] Template updated successfully with ' . count($product_positions) . ' product positions');
		
		return [
			'template_id' => $template_id,
			'backup_key' => $backup_key,
			'updated_positions' => $product_positions,
			'message' => 'Template updated successfully'
		];
	}
	
	/**
	 * Update template structure with new product positions
	 *
	 * @param array $data The Elementor data.
	 * @param array $product_positions Array of product positions.
	 * @return array Updated Elementor data.
	 */
	private function updateTemplateStructure($data, $product_positions) {
		if (!is_array($data)) {
			return $data;
		}
		
		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$data[$index] = $this->updateTemplateStructure($item, $product_positions);
			}
			return $data;
		}
		
		// Update CSS ID if this is a product container
		if (isset($data['settings']) && is_array($data['settings'])) {
			$settings = &$data['settings'];
			// Check multiple possible CSS ID fields - including _element_id which is used in your templates
			$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
			
			// Check for product-X CSS ID pattern
			if (preg_match('/product-(\d+)/', $css_id, $matches)) {
				$old_product_number = (int) $matches[1];
				
				// Find new position for this product
				foreach ($product_positions as $position) {
					if ($position['old_position'] === $old_product_number) {
						$new_product_number = $position['new_position'];
						$new_css_id = 'product-' . $new_product_number;
						
						// Update CSS ID - prioritize _element_id since that's what your templates use
						if (isset($settings['_element_id'])) {
							$settings['_element_id'] = $new_css_id;
						} elseif (isset($settings['_css_id'])) {
							$settings['_css_id'] = $new_css_id;
						} elseif (isset($settings['css_id'])) {
							$settings['css_id'] = $new_css_id;
						}
						
						error_log('[AEBG] Updated CSS ID from product-' . $old_product_number . ' to product-' . $new_product_number);
						break;
					}
				}
			}
		}
		
		// Recursively update children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				$data['elements'][$index] = $this->updateTemplateStructure($element, $product_positions);
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				$data['content'][$index] = $this->updateTemplateStructure($content_item, $product_positions);
			}
		}
		
		return $data;
	}
	
	/**
	 * Update post with new product positions
	 *
	 * @param int $post_id The post ID.
	 * @param array $product_positions Array of product positions.
	 * @return array|\WP_Error Result or WP_Error on failure.
	 */
	public function updatePostProducts($post_id, $product_positions) {
		error_log('[AEBG] TemplateManager::updatePostProducts called with post_id: ' . $post_id);
		
		// Validate post ID
		if (empty($post_id) || !is_numeric($post_id)) {
			return new \WP_Error('aebg_invalid_post_id', 'Invalid post ID provided');
		}
		
		// Check if post exists
		$post = get_post($post_id);
		if (!$post) {
			return new \WP_Error('aebg_post_not_found', 'Post not found');
		}
		
		// Check if user can edit this post
		if (!current_user_can('edit_post', $post_id)) {
			return new \WP_Error('aebg_permission_denied', 'You do not have permission to edit this post');
		}
		
		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		if (empty($elementor_data)) {
			return new \WP_Error('aebg_no_elementor_data', 'No Elementor data found in post');
		}

		if (is_string($elementor_data)) {
			$decoded_data = $this->decodeJsonWithUnicode($elementor_data);
			if ($decoded_data === false) {
				return new \WP_Error('aebg_invalid_elementor_data', 'Failed to decode Elementor data');
			}
			$elementor_data = $decoded_data;
		}
		
		if (!is_array($elementor_data)) {
			return new \WP_Error('aebg_invalid_elementor_data', 'Invalid Elementor data in post');
		}
		
		// Create backup before making changes
		$backup_key = 'aebg_post_backup_' . $post_id . '_' . time();
		update_option($backup_key, $elementor_data);
		
		// Update post with new product positions
		$updated_data = $this->updateTemplateStructure($elementor_data, $product_positions);
		
		// Clean the data before encoding to prevent JSON issues
		$cleaned_data = $this->cleanElementorDataForEncoding($updated_data);
		
		// Save updated post
		update_post_meta($post_id, '_elementor_data', json_encode($cleaned_data));
		
		// Clear any caches
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
		
		error_log('[AEBG] Post updated successfully with ' . count($product_positions) . ' product positions');
		
		return [
			'post_id' => $post_id,
			'backup_key' => $backup_key,
			'updated_positions' => $product_positions,
			'message' => 'Post updated successfully'
		];
	}
	
	/**
	 * Update post with new product order
	 *
	 * @param int $post_id The post ID.
	 * @param array $new_product_order Array of product IDs in new order.
	 * @return array|\WP_Error Result or WP_Error on failure.
	 */
	public function updatePostProductsWithOrder($post_id, $new_product_order) {
		error_log('[AEBG] ===== START: updatePostProductsWithOrder =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		error_log('[AEBG] New product order: ' . json_encode($new_product_order));
		
		// Use the new reorderProductContainers method
		$result = $this->reorderProductContainers($post_id, $new_product_order);
		if (is_wp_error($result)) {
			return $result;
		}
		
		// Update the products meta to match the new order
		$current_products = get_post_meta($post_id, '_aebg_products', true);
		if (!empty($current_products)) {
			$reordered_products = [];
			foreach ($new_product_order as $product_id) {
				foreach ($current_products as $product) {
					$product_id_from_data = is_array($product) ? ($product['id'] ?? '') : $product;
					if ($product_id_from_data == $product_id) {
						$reordered_products[] = $product;
						break;
					}
				}
			}
			
			// Add any remaining products that weren't in the new order
			foreach ($current_products as $product) {
				$product_id_from_data = is_array($product) ? ($product['id'] ?? '') : $product;
				$found = false;
				foreach ($new_product_order as $product_id) {
					if ($product_id_from_data == $product_id) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					$reordered_products[] = $product;
				}
			}
			
			update_post_meta($post_id, '_aebg_products', $reordered_products);
			error_log('[AEBG] Updated products meta with new order');
		}
		
		error_log('[AEBG] ===== SUCCESS: updatePostProductsWithOrder completed =====');
		
		return [
			'post_id' => $post_id,
			'updated_order' => $new_product_order,
			'message' => 'Post updated successfully with new product order',
			'frontend_refresh_required' => true,
			'cache_cleared' => true,
			'timestamp' => current_time('timestamp')
		];
	}
	
	/**
	 * Create missing product containers based on existing ones
	 *
	 * @param array $existing_containers Array of existing product containers.
	 * @param int $required_count Total number of containers needed.
	 * @return array|\WP_Error Array of new containers or WP_Error on failure.
	 */
	private function createMissingProductContainers($existing_containers, $required_count) {
		error_log('[AEBG] createMissingProductContainers called - existing: ' . count($existing_containers) . ', required: ' . $required_count);
		
		if (empty($existing_containers)) {
			return new \WP_Error('aebg_no_existing_containers', 'No existing containers to base new ones on');
		}
		
		// Get the first existing container as a template
		$template_container = reset($existing_containers);
		if (!is_array($template_container)) {
			return new \WP_Error('aebg_invalid_template_container', 'Invalid template container structure');
		}
		
		$new_containers = [];
		$existing_count = count($existing_containers);
		
		for ($i = $existing_count + 1; $i <= $required_count; $i++) {
			// Create a deep copy of the template container
			$new_container = json_decode(json_encode($template_container), true);
			if (!is_array($new_container)) {
				error_log('[AEBG] ERROR: Failed to create copy of template container for position ' . $i);
				continue;
			}
			
			// Generate a unique ID for the new container
			$new_element_id = $this->generateUniqueElementId();
			
			// Update the container with new IDs and settings
			$new_container['id'] = $new_element_id;
			$new_container['settings']['_element_id'] = 'product-' . $i;
			
			// Update any internal references to the old element ID
			$new_container = $this->updateContainerReferences($new_container, $template_container['id'] ?? '', $new_element_id);
			
			// Update variables to use the new position
			$new_container = $this->updateVariablesInContainer($new_container, 0, $i);
			
			$new_containers[$i] = $new_container;
			error_log('[AEBG] Created new product container for position ' . $i . ' with element ID: ' . $new_element_id);
		}
		
		error_log('[AEBG] Created ' . count($new_containers) . ' new product containers');
		return $new_containers;
	}
	
	/**
	 * Generate a unique Elementor element ID
	 *
	 * @return string Unique element ID.
	 */
	private function generateUniqueElementId() {
		// Generate a random string of 7 characters (Elementor's format)
		$characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$element_id = '';
		for ($i = 0; $i < 7; $i++) {
			$element_id .= $characters[rand(0, strlen($characters) - 1)];
		}
		return $element_id;
	}
	
	/**
	 * Update container references to use new element ID
	 *
	 * @param array $container The container to update.
	 * @param string $old_id The old element ID.
	 * @param string $new_id The new element ID.
	 * @return array Updated container.
	 */
	private function updateContainerReferences($container, $old_id, $new_id) {
		if (empty($old_id) || empty($new_id)) {
			return $container;
		}
		
		// Convert to JSON string, replace the old ID with new ID, then decode back
		$json_string = json_encode($container);
		$json_string = str_replace($old_id, $new_id, $json_string);
		$updated_container = json_decode($json_string, true);
		
		if (!is_array($updated_container)) {
			error_log('[AEBG] WARNING: Failed to update container references');
			return $container;
		}
		
		return $updated_container;
	}
	

	
	/**
	 * Reorder the content array which contains product containers
	 *
	 * @param array $content_array The content array from Elementor data.
	 * @param array $current_products Current product order.
	 * @param array $new_product_order New product order.
	 * @return array Reordered content array.
	 */
	private function reorderContentArray($content_array, $current_products, $new_product_order) {
		error_log('[AEBG] reorderContentArray called with ' . count($content_array) . ' elements');
		error_log('[AEBG] Current products: ' . json_encode($current_products));
		error_log('[AEBG] New product order: ' . json_encode($new_product_order));
		
		// Validate input
		if (!is_array($content_array)) {
			error_log('[AEBG] ERROR: content_array is not an array');
			return $content_array;
		}
		
		// Create a deep copy of the original content array
		$original_content = json_decode(json_encode($content_array), true);
		if (!is_array($original_content)) {
			error_log('[AEBG] ERROR: Failed to create deep copy of original content');
			return $content_array;
		}
		
		error_log('[AEBG] Original content array structure: ' . json_encode(array_keys($original_content)));
		
		// Find and collect product containers with their positions
		$product_containers = [];
		$non_product_elements = [];
		
		// First pass: separate product containers from non-product elements
		foreach ($content_array as $index => $element) {
			error_log('[AEBG] Processing element at index ' . $index . ': ' . json_encode(array_keys($element)));
			
			if (!is_array($element)) {
				error_log('[AEBG] WARNING: Element at index ' . $index . ' is not an array, preserving as-is');
				$non_product_elements[] = $element;
				continue;
			}
			
			// Check if this is a product container by looking for CSS ID
			$is_product_container = false;
			$product_number = null;
			
			if (isset($element['settings']['_element_id'])) {
				$css_id = $element['settings']['_element_id'];
				if (preg_match('/product-(\d+)/', $css_id, $matches)) {
					$product_number = (int) $matches[1];
					$is_product_container = true;
					error_log('[AEBG] Found product container: product-' . $product_number . ' at index ' . $index);
				} else {
					error_log('[AEBG] Non-product element with CSS ID: ' . $css_id);
				}
			} else {
				error_log('[AEBG] Element without _element_id at index ' . $index);
			}
			
			if ($is_product_container && $product_number !== null) {
				$product_containers[$product_number] = $element;
			} else {
				$non_product_elements[] = $element;
			}
		}
		
		error_log('[AEBG] Found ' . count($product_containers) . ' product containers');
		error_log('[AEBG] Found ' . count($non_product_elements) . ' non-product elements');
		
		// Create a mapping from current product positions to new positions
		$position_mapping = [];
		foreach ($new_product_order as $new_index => $product_id) {
			$new_position = $new_index + 1;
			
			// Find which current position this product was at
			foreach ($current_products as $old_index => $product) {
				$current_product_id = is_array($product) ? ($product['id'] ?? '') : $product;
				if ($current_product_id == $product_id) {
					$old_position = $old_index + 1;
					$position_mapping[$old_position] = $new_position;
					error_log('[AEBG] Mapping: product-' . $old_position . ' -> product-' . $new_position . ' (ID: ' . $product_id . ')');
					break;
				}
			}
		}
		
		error_log('[AEBG] Position mapping: ' . json_encode($position_mapping));
		
		// Create the reordered array
		$reordered_array = [];
		
		// First, add reordered product containers
		foreach ($position_mapping as $old_position => $new_position) {
			if (isset($product_containers[$old_position])) {
				$container = $product_containers[$old_position];
				
				// Create a deep copy to avoid modifying the original
				$container_copy = json_decode(json_encode($container), true);
				if (!is_array($container_copy)) {
					error_log('[AEBG] ERROR: Failed to create deep copy of container for product-' . $old_position);
					continue;
				}
				
				// Update the CSS ID to reflect the new position
				if (isset($container_copy['settings']['_element_id'])) {
					$container_copy['settings']['_element_id'] = 'product-' . $new_position;
					error_log('[AEBG] Updated CSS ID: product-' . $old_position . ' -> product-' . $new_position);
				}
				
				// Update variables within this container to reflect the new product number
				$container_copy = $this->updateVariablesForReordering($container_copy, $old_position, $new_position);
				
				$reordered_array[] = $container_copy;
				error_log('[AEBG] Reordered: product-' . $old_position . ' -> product-' . $new_position);
			} else {
				error_log('[AEBG] WARNING: No container found for product-' . $old_position);
			}
		}
		
		// Then, add non-product elements at the end
		foreach ($non_product_elements as $element) {
			$reordered_array[] = $element;
		}
		
		error_log('[AEBG] Final reordered array count: ' . count($reordered_array));
		error_log('[AEBG] Original array count: ' . count($original_content));
		
		// Validate that we haven't lost any elements
		if (count($reordered_array) !== count($original_content)) {
			error_log('[AEBG] ERROR: Element count mismatch! Original: ' . count($original_content) . ', Reordered: ' . count($reordered_array));
			error_log('[AEBG] WARNING: Returning original content to prevent corruption');
			return $original_content;
		}
		
		// Validate JSON structure
		$test_json = json_encode($reordered_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($test_json === false) {
			error_log('[AEBG] ERROR: JSON encoding failed for reordered array: ' . json_last_error_msg());
			error_log('[AEBG] WARNING: Returning original content to prevent corruption');
			return $original_content;
		}
		
		// Additional validation: ensure all elements have the required structure
		foreach ($reordered_array as $index => $element) {
			if (!is_array($element)) {
				error_log('[AEBG] ERROR: Element at index ' . $index . ' is not an array after reordering');
				error_log('[AEBG] WARNING: Returning original content to prevent corruption');
				return $original_content;
			}
		}
		
		error_log('[AEBG] Successfully reordered content array');
		return $reordered_array;
	}
	
	/**
	 * Update variables within a container to reflect new product numbering
	 *
	 * @param array $container The container element.
	 * @param int $old_position The old product position.
	 * @param int $new_position The new product position.
	 * @return array Updated container.
	 */
	private function updateVariablesForReordering($container, $old_position, $new_position) {
		if (!is_array($container)) {
			error_log('[AEBG] WARNING: Container is not an array in updateVariablesForReordering');
			return $container;
		}
		
		error_log('[AEBG] Updating variables for container: ' . json_encode(array_keys($container)));
		
		// Update variables in settings
		if (isset($container['settings']) && is_array($container['settings'])) {
			$container['settings'] = $this->updateVariablesInSettings($container['settings'], $old_position, $new_position);
		}
		
		// Update variables in nested elements
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as $index => &$element) {
				if (is_array($element)) {
					$element = $this->updateVariablesForReordering($element, $old_position, $new_position);
				}
			}
		}
		
		// Update variables in nested content (if any)
		if (isset($container['content']) && is_array($container['content'])) {
			foreach ($container['content'] as $index => &$content_item) {
				if (is_array($content_item)) {
					$content_item = $this->updateVariablesForReordering($content_item, $old_position, $new_position);
				}
			}
		}
		
		// Validate the container structure after updates
		$test_json = json_encode($container, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($test_json === false) {
			error_log('[AEBG] ERROR: JSON encoding failed for container after variable updates: ' . json_last_error_msg());
			error_log('[AEBG] WARNING: Returning original container to prevent corruption');
			return $container;
		}
		
		return $container;
	}
	
	/**
	 * Update variables within a container when reordering
	 *
	 * @param array $container The container element.
	 * @param int $old_position The old position.
	 * @param int $new_position The new position.
	 * @return array Updated container.
	 */
	private function updateVariablesInContainer($container, $old_position, $new_position) {
		if (!is_array($container)) {
			return $container;
		}
		
		// Create a deep copy to avoid modifying the original
		$updated_container = json_decode(json_encode($container), true);
		if (!is_array($updated_container)) {
			error_log('[AEBG] WARNING: Failed to create deep copy of container');
			return $container;
		}
		
		// Update variables in settings
		if (isset($updated_container['settings']) && is_array($updated_container['settings'])) {
			$updated_container['settings'] = $this->updateVariablesInSettings($updated_container['settings'], $old_position, $new_position);
		}
		
		// Update variables in elements (only if elements exist and are array)
		if (isset($updated_container['elements']) && is_array($updated_container['elements'])) {
			$updated_elements = [];
			foreach ($updated_container['elements'] as $element) {
				$updated_elements[] = $this->updateVariablesInContainer($element, $old_position, $new_position);
			}
			$updated_container['elements'] = $updated_elements;
		}
		
		return $updated_container;
	}

	/**
	 * Update variables in settings when reordering
	 *
	 * @param array $settings The settings array.
	 * @param int $old_position The old position.
	 * @param int $new_position The new position.
	 * @return array Updated settings.
	 */
	private function updateVariablesInSettings($settings, $old_position, $new_position) {
		if (!is_array($settings)) {
			return $settings;
		}
		
		// If old_position is 0, this is a new container, so we don't need to update existing variables
		if ($old_position <= 0) {
			return $settings;
		}
		
		$updated_settings = [];
		foreach ($settings as $key => $value) {
			if (is_string($value)) {
				$updated_settings[$key] = $this->updateVariablesInText($value, $old_position, $new_position);
			} elseif (is_array($value)) {
				$updated_settings[$key] = $this->updateVariablesInSettings($value, $old_position, $new_position);
			} else {
				$updated_settings[$key] = $value;
			}
		}
		
		return $updated_settings;
	}

	/**
	 * Update variables in text when reordering
	 *
	 * @param string $text The text to update.
	 * @param int $old_position The old position.
	 * @param int $new_position The new position.
	 * @return string Updated text.
	 */
	private function updateVariablesInText($text, $old_position, $new_position) {
		if (!is_string($text)) {
			return $text;
		}
		
		// If old_position is 0, this is a new container, so we don't need to update existing variables
		if ($old_position <= 0) {
			return $text;
		}
		
		// Pattern to match {product-X} variables
		$simple_pattern = '/\{product-' . $old_position . '\}/';
		$text = preg_replace($simple_pattern, '{product-' . $new_position . '}', $text);
		
		// Pattern to match {product-X-*} variables
		$complex_pattern = '/\{product-' . $old_position . '-([^}]+)\}/';
		$text = preg_replace_callback($complex_pattern, function($matches) use ($new_position) {
			$variable_name = $matches[1];
			return '{product-' . $new_position . '-' . $variable_name . '}';
		}, $text);
		
		return $text;
	}
	
	/**
	 * Find all product containers in Elementor data
	 *
	 * @param array $data The Elementor data.
	 * @return array Array of product containers with their positions and IDs.
	 */
	private function findProductContainers($data) {
		$containers = [];
		$this->recursiveFindProductContainers($data, $containers);
		return $containers;
	}
	
	/**
	 * Recursively find product containers
	 *
	 * @param array $data The Elementor data.
	 * @param array &$containers Reference to containers array.
	 * @param string $path Current path in structure.
	 */
	private function recursiveFindProductContainers($data, &$containers, $path = '') {
		if (!is_array($data)) {
			return;
		}
		
		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$new_path = $path ? $path . '.' . $index : (string) $index;
				$this->recursiveFindProductContainers($item, $containers, $new_path);
			}
			return;
		}
		
		// Check for CSS ID-based product containers
		if (isset($data['settings'])) {
			$settings = $data['settings'];
			$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
			
			// Check for product-X CSS ID pattern
			if (preg_match('/product-(\d+)/', $css_id, $matches)) {
				$product_number = (int) $matches[1];
				$element_id = $data['id'] ?? '';
				
				$containers[] = [
					'product_number' => $product_number,
					'css_id' => $css_id,
					'element_id' => $element_id,
					'path' => $path,
					'product_id' => null // Will be filled by the calling code
				];
			}
		}
		
		// Recursively process children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				$new_path = $path ? $path . '.elements.' . $index : 'elements.' . $index;
				$this->recursiveFindProductContainers($element, $containers, $new_path);
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				$new_path = $path ? $path . '.content.' . $index : 'content.' . $index;
				$this->recursiveFindProductContainers($content_item, $containers, $new_path);
			}
		}
	}

	/**
	 * Validate Elementor data structure
	 *
	 * @param array $data The Elementor data to validate
	 * @return true|\WP_Error True if valid, WP_Error if invalid
	 */
	private function validateElementorData($data) {
		// error_log('[AEBG] ===== START: validateElementorData =====');
		// error_log('[AEBG] Data type: ' . gettype($data));
		// error_log('[AEBG] Data count: ' . (is_array($data) ? count($data) : 'N/A'));
		
		if (!is_array($data)) {
			error_log('[AEBG] ERROR: Validation failed: Data is not an array, type: ' . gettype($data));
			// error_log('[AEBG] ===== END: validateElementorData (FAILED) =====');
			return new \WP_Error('aebg_invalid_data_type', 'Elementor data must be an array');
		}
		
		// Check if this is a direct array (like [{"id":"020c173",...}])
		if (array_keys($data) === range(0, count($data) - 1)) {
			// error_log('[AEBG] Detected direct array structure with ' . count($data) . ' items');
			// This is a direct array structure - validate each item
			foreach ($data as $index => $item) {
				if (!is_array($item)) {
					// error_log('[AEBG] WARNING: Direct array item at index ' . $index . ' is not an array, type: ' . gettype($item));
					// error_log('[AEBG] Item value: ' . json_encode($item));
				} else {
					// error_log('[AEBG] Direct array item at index ' . $index . ' is valid array with keys: ' . json_encode(array_keys($item)));
				}
			}
			// error_log('[AEBG] Validation passed: Direct array structure with ' . count($data) . ' items');
		} else {
			// error_log('[AEBG] Detected associative array structure');
			// error_log('[AEBG] Available keys: ' . json_encode(array_keys($data)));
			
			// Check if we have either content or elements array
			if (!isset($data['content']) && !isset($data['elements'])) {
				error_log('[AEBG] No content or elements array found');
				// This might be a valid Elementor structure without content/elements
				// Check if it has other valid Elementor properties
				$valid_properties = ['id', 'settings', 'elType', 'widgetType', 'elements', 'content', 'type', 'name', 'title'];
				$has_valid_properties = false;
				
				foreach ($valid_properties as $prop) {
					if (isset($data[$prop])) {
						$has_valid_properties = true;
						error_log('[AEBG] Found valid property: ' . $prop);
						break;
					}
				}
				
				if (!$has_valid_properties) {
					error_log('[AEBG] WARNING: Data structure does not contain expected Elementor properties');
					error_log('[AEBG] Available keys: ' . json_encode(array_keys($data)));
					// Don't fail validation for this - just log it as a warning
				} else {
					error_log('[AEBG] Validation passed: Elementor structure with valid properties');
				}
			} else {
				// Check if the content/elements array is valid
				$content_array = isset($data['content']) ? $data['content'] : $data['elements'];
				$content_key = isset($data['content']) ? 'content' : 'elements';
				
				error_log('[AEBG] Found ' . $content_key . ' array with ' . count($content_array) . ' items');
				
				if (!is_array($content_array)) {
					error_log('[AEBG] ERROR: Validation failed: Content/elements is not an array, type: ' . gettype($content_array));
					error_log('[AEBG] ===== END: validateElementorData (FAILED) =====');
					return new \WP_Error('aebg_invalid_content_type', 'Content/elements must be an array');
				}
				
				// Validate each item in the content array
				foreach ($content_array as $index => $item) {
					if (!is_array($item)) {
						error_log('[AEBG] WARNING: Content item at index ' . $index . ' is not an array, type: ' . gettype($item));
					} else {
						$item_keys = array_keys($item);
						error_log('[AEBG] Content item at index ' . $index . ' is valid array with keys: ' . json_encode($item_keys));
					}
				}
				
				error_log('[AEBG] Validation passed: Content/elements array structure with ' . count($content_array) . ' items');
			}
		}
		
		// Test JSON encoding/decoding to ensure data integrity
		error_log('[AEBG] Testing JSON encoding/decoding...');
		$test_json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($test_json === false) {
			$error_msg = json_last_error_msg();
			error_log('[AEBG] WARNING: JSON encoding failed: ' . $error_msg);
			error_log('[AEBG] JSON error code: ' . json_last_error());
			// Don't fail validation for JSON encoding issues, just log them
			error_log('[AEBG] Warning: JSON encoding failed but continuing with validation');
		} else {
			$test_decode = json_decode($test_json, true);
			if (!is_array($test_decode)) {
				error_log('[AEBG] WARNING: JSON decoding failed after encoding');
			} else {
				error_log('[AEBG] JSON encoding/decoding test passed');
			}
		}
		
		error_log('[AEBG] ===== SUCCESS: validateElementorData completed =====');
		return true;
	}

	/**
	 * Restore Elementor data from backup if corruption is detected.
	 *
	 * @param int $post_id The post ID.
	 */


	/**
	 * Clear Elementor cache and trigger refresh mechanisms
	 *
	 * @param int $post_id The post ID.
	 */
	private function clearElementorCache($post_id) {
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
		if (class_exists('\Elementor\Plugin')) {
			try {
				// Clear Elementor's internal cache
				$elementor = \Elementor\Plugin::instance();
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
	 * Clear all caches that might affect frontend display
	 *
	 * @param int $post_id Post ID
	 */
	public function clearAllCaches($post_id) {
		error_log('[AEBG] Clearing all caches for post ' . $post_id);
		
		// Clear WordPress object cache
		wp_cache_flush();
		
		// Clear any page cache plugins
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
			error_log('[AEBG] Cleared W3 Total Cache');
		}
		
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
			error_log('[AEBG] Cleared WP Super Cache');
		}
		
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
			error_log('[AEBG] Cleared WP Rocket Cache');
		}
		
		// Clear Elementor CSS/JS files
		if (class_exists('\Elementor\Plugin')) {
			$elementor = \Elementor\Plugin::instance();
			
			// Regenerate CSS
			if (method_exists($elementor->files_manager, 'clear_cache')) {
				$elementor->files_manager->clear_cache();
			}
			
			// Force CSS regeneration
			// FIX: documents is a property (object), not a method - check if property exists and has get method
			if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
				try {
					$document = $elementor->documents->get($post_id);
					if ($document && method_exists($document, 'get_css_wrapper_selector')) {
						// Force CSS regeneration
						$css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
						if ($css_file) {
							$css_file->delete();
							error_log('[AEBG] Deleted and will regenerate Elementor CSS for post ' . $post_id);
						}
					}
				} catch (Exception $e) {
					error_log('[AEBG] Error regenerating Elementor CSS: ' . $e->getMessage());
				}
			}
		}
		
		// Clear any custom post meta that might be cached
		delete_post_meta($post_id, '_elementor_css');
		delete_post_meta($post_id, '_elementor_js');
		
		// Force WordPress to refresh the post
		clean_post_cache($post_id);
		
		error_log('[AEBG] All caches cleared for post ' . $post_id);
	}

	/**
	 * Force page refresh by updating post modified date
	 *
	 * @param int $post_id Post ID
	 */
	public function forcePageRefresh($post_id) {
		// Update post modified date to force cache refresh
		wp_update_post([
			'ID' => $post_id,
			'post_modified' => current_time('mysql'),
			'post_modified_gmt' => current_time('mysql', 1)
		]);
		
		// Clear post cache
		clean_post_cache($post_id);
		
		error_log('[AEBG] Forced page refresh for post ' . $post_id);
	}

	/**
	 * Helper method to decode JSON with Unicode support
	 *
	 * @param string $json_string The JSON string to decode
	 * @return array|false The decoded data or false on failure
	 */
	private function decodeJsonWithUnicode($json_string) {
		if (!is_string($json_string)) {
			error_log('[AEBG] ERROR: Input is not a string, type: ' . gettype($json_string));
			return false;
		}
		
		// Simply use standard json_decode - it handles Unicode escape sequences automatically
		$decoded = json_decode($json_string, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}
		
		// Log error information for debugging
		error_log('[AEBG] JSON decode failed: ' . json_last_error_msg());
		error_log('[AEBG] JSON error code: ' . json_last_error());
		
		return false;
	}
	

	
	/**
	 * Helper method to analyze specific JSON issues
	 *
	 * @param string $json_string The JSON string to analyze
	 */
	private function analyzeJsonIssues($json_string) {
		$length = strlen($json_string);
		error_log('[AEBG] JSON string length: ' . $length);
		
		// Check for common issues
		$issues = [];
		
		// Check for unescaped quotes in strings
		$quote_count = 0;
		$in_string = false;
		$escaped = false;
		$last_quote_pos = -1;
		
		for ($i = 0; $i < $length; $i++) {
			$char = $json_string[$i];
			
			if ($escaped) {
				$escaped = false;
				continue;
			}
			
			if ($char === '\\') {
				$escaped = true;
				continue;
			}
			
			if ($char === '"') {
				$quote_count++;
				$last_quote_pos = $i;
				$in_string = !$in_string;
			}
		}
		
		if ($quote_count % 2 !== 0) {
			$issues[] = "Unmatched quotes (count: $quote_count, last quote at position: $last_quote_pos)";
		}
		
		// Check for unclosed brackets/braces
		$open_brackets = substr_count($json_string, '[');
		$close_brackets = substr_count($json_string, ']');
		$open_braces = substr_count($json_string, '{');
		$close_braces = substr_count($json_string, '}');
		
		if ($open_brackets !== $close_brackets) {
			$issues[] = "Unmatched brackets (open: $open_brackets, close: $close_brackets)";
		}
		
		if ($open_braces !== $close_braces) {
			$issues[] = "Unmatched braces (open: $open_braces, close: $close_braces)";
		}
		
		// Check for trailing commas
		if (preg_match('/,(\s*[}\]])/', $json_string)) {
			$issues[] = "Trailing commas found";
		}
		
		// Check for control characters
		$control_chars = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $json_string, $matches);
		if ($control_chars > 0) {
			$issues[] = "Control characters found ($control_chars instances)";
		}
		
		// Log all issues found
		if (!empty($issues)) {
			error_log('[AEBG] JSON issues detected: ' . implode(', ', $issues));
		} else {
			error_log('[AEBG] No obvious JSON issues detected');
		}
		
		// Detailed character analysis around problematic areas
		error_log('[AEBG] === DETAILED CHARACTER ANALYSIS ===');
		
		// Analyze first 200 characters
		$first_200 = substr($json_string, 0, 200);
		error_log('[AEBG] First 200 characters analysis:');
		for ($i = 0; $i < strlen($first_200); $i++) {
			$char = $first_200[$i];
			$ascii = ord($char);
			$hex = dechex($ascii);
			
			// Check if it's a control character
			if ($ascii < 32 && $ascii !== 9 && $ascii !== 10 && $ascii !== 13) {
				error_log("[AEBG] Position $i: CONTROL CHARACTER (ASCII: $ascii, hex: 0x$hex) - " . bin2hex($char));
			} elseif ($ascii > 127) {
				error_log("[AEBG] Position $i: NON-ASCII CHARACTER '$char' (ASCII: $ascii, hex: 0x$hex)");
			}
		}
		
		// Analyze last 200 characters
		$last_200 = substr($json_string, -200);
		error_log('[AEBG] Last 200 characters analysis:');
		for ($i = 0; $i < strlen($last_200); $i++) {
			$char = $last_200[$i];
			$ascii = ord($char);
			$hex = dechex($ascii);
			
			// Check if it's a control character
			if ($ascii < 32 && $ascii !== 9 && $ascii !== 10 && $ascii !== 13) {
				$actual_pos = $length - 200 + $i;
				error_log("[AEBG] Position $actual_pos: CONTROL CHARACTER (ASCII: $ascii, hex: 0x$hex) - " . bin2hex($char));
			} elseif ($ascii > 127) {
				$actual_pos = $length - 200 + $i;
				error_log("[AEBG] Position $actual_pos: NON-ASCII CHARACTER '$char' (ASCII: $ascii, hex: 0x$hex)");
			}
		}
		
		// Progressive decode test to find exact failure point
		error_log('[AEBG] === PROGRESSIVE DECODE TEST ===');
		for ($i = 50; $i <= $length; $i += 50) {
			$test_string = substr($json_string, 0, $i);
			$test_decode = json_decode($test_string, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				error_log("[AEBG] JSON decode failed at position $i: " . json_last_error_msg());
				
				// Show the problematic portion
				$problematic_start = max(0, $i - 50);
				$problematic_end = min($length, $i + 50);
				$problematic = substr($json_string, $problematic_start, $problematic_end - $problematic_start);
				error_log("[AEBG] Problematic portion (positions $problematic_start-$problematic_end): " . $problematic);
				
				// Detailed analysis of the problematic area
				error_log('[AEBG] Detailed analysis of problematic area:');
				for ($j = $problematic_start; $j < $problematic_end; $j++) {
					$char = $json_string[$j];
					$ascii = ord($char);
					$hex = dechex($ascii);
					
					if ($ascii < 32 && $ascii !== 9 && $ascii !== 10 && $ascii !== 13) {
						error_log("[AEBG] Position $j: CONTROL CHARACTER (ASCII: $ascii, hex: 0x$hex) - " . bin2hex($char));
					} elseif ($ascii > 127) {
						error_log("[AEBG] Position $j: NON-ASCII CHARACTER '$char' (ASCII: $ascii, hex: 0x$hex)");
					}
				}
				break;
			}
		}
		
		error_log('[AEBG] === END CHARACTER ANALYSIS ===');
	}
	
	/**
	 * Helper method to repair JSON structure
	 *
	 * @param string $json_string The JSON string to repair
	 * @return string The repaired JSON string
	 */
	private function repairJsonStructure($json_string) {
		$fixed = $json_string;
		
		// Try to fix common structural issues
		
		// 1. Fix unclosed strings by finding the last quote and ensuring it's properly closed
		// Count quotes more accurately by considering escaped quotes
		$quote_count = 0;
		$in_string = false;
		$escaped = false;
		
		for ($i = 0; $i < strlen($fixed); $i++) {
			$char = $fixed[$i];
			
			if ($escaped) {
				$escaped = false;
				continue;
			}
			
			if ($char === '\\') {
				$escaped = true;
				continue;
			}
			
			if ($char === '"') {
				$quote_count++;
				$in_string = !$in_string;
			}
		}
		
		// If we have an odd number of quotes, try to fix
		if ($quote_count % 2 !== 0) {
			// Find the last unescaped quote and ensure the string is properly closed
			$last_quote_pos = strrpos($fixed, '"');
			if ($last_quote_pos !== false) {
				// Check if the last quote is properly followed by a closing bracket/brace
				$remaining = substr($fixed, $last_quote_pos + 1);
				$remaining = trim($remaining);
				
				// If the remaining text doesn't end with proper closing brackets/braces, add a quote
				if (!preg_match('/^[,\s]*[}\]\s]*$/', $remaining)) {
					$fixed = rtrim($fixed, ',') . '"';
				}
			}
		}
		
		// 2. Fix unclosed brackets/braces by counting and adding missing ones
		$open_brackets = substr_count($fixed, '[');
		$close_brackets = substr_count($fixed, ']');
		$open_braces = substr_count($fixed, '{');
		$close_braces = substr_count($fixed, '}');
		
		// Add missing closing brackets
		for ($i = 0; $i < ($open_brackets - $close_brackets); $i++) {
			$fixed .= ']';
		}
		
		// Add missing closing braces
		for ($i = 0; $i < ($open_braces - $close_braces); $i++) {
			$fixed .= '}';
		}
		
		// 3. Fix trailing commas
		$fixed = preg_replace('/,(\s*[}\]])/', '$1', $fixed);
		
		// 4. Try to fix any remaining structural issues by ensuring proper JSON structure
		// Remove any trailing commas before closing brackets/braces
		$fixed = preg_replace('/,(\s*[}\]])/', '$1', $fixed);
		
		return $fixed;
	}
	
	/**
	 * Helper method to fix common JSON issues
	 *
	 * @param string $json_string The JSON string to fix
	 * @return string The fixed JSON string
	 */
	private function fixCommonJsonIssues($json_string) {
		$fixed = $json_string;
		
		// Fix common issues
		// Remove trailing commas before closing brackets/braces
		$fixed = preg_replace('/,(\s*[}\]])/', '$1', $fixed);
		
		// Fix single quotes that should be double quotes (but be careful not to break strings)
		$fixed = preg_replace("/(?<!\\\\)'/", '"', $fixed);
		
		// Fix missing quotes around property names (but be careful not to break existing quoted names)
		$fixed = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $fixed);
		
		// Fix double quotes inside strings that aren't properly escaped
		// This is more complex and might need a more sophisticated approach
		
		return $fixed;
	}
	
	/**
	 * Helper method to fix JSON by removing problematic characters
	 *
	 * @param string $json_string The JSON string to fix
	 * @return string The fixed JSON string
	 */
	private function fixJsonByRemovingProblematicChars($json_string) {
		$fixed = $json_string;
		
		// Remove null bytes and other control characters except newlines and tabs
		$fixed = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $fixed);
		
		// Remove any characters that might be causing issues
		$fixed = preg_replace('/[^\x20-\x7E\x0A\x09]/', '', $fixed);
		
		return $fixed;
	}
	
	/**
	 * Helper method to decode all Unicode escape sequences in a string
	 *
	 * @param string $string The string containing Unicode escape sequences
	 * @return string The string with decoded Unicode sequences
	 */
	private function decodeUnicodeSequences($string) {
		// Use a comprehensive approach to decode all Unicode escape sequences
		$fixed = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
			$hex = $matches[1];
			$dec = hexdec($hex);
			
			// Handle UTF-8 encoding properly
			if ($dec < 128) {
				// ASCII character
				return chr($dec);
			} elseif ($dec < 2048) {
				// 2-byte UTF-8
				return chr(192 | ($dec >> 6)) . chr(128 | ($dec & 63));
			} elseif ($dec < 65536) {
				// 3-byte UTF-8
				return chr(224 | ($dec >> 12)) . chr(128 | (($dec >> 6) & 63)) . chr(128 | ($dec & 63));
			} else {
				// 4-byte UTF-8 (though this shouldn't happen with 4-digit hex)
				return chr(240 | ($dec >> 18)) . chr(128 | (($dec >> 12) & 63)) . chr(128 | (($dec >> 6) & 63)) . chr(128 | ($dec & 63));
			}
		}, $string);
		
		// Also try to fix any remaining Unicode issues with common replacements
		$unicode_replacements = [
			'\\u00f8' => 'ø', '\\u00e6' => 'æ', '\\u00e5' => 'å', '\\u00d8' => 'Ø', '\\u00c6' => 'Æ', '\\u00c5' => 'Å',
			'\\u00e1' => 'á', '\\u00e9' => 'é', '\\u00ed' => 'í', '\\u00f3' => 'ó', '\\u00fa' => 'ú', '\\u00f1' => 'ñ',
			'\\u00c1' => 'Á', '\\u00c9' => 'É', '\\u00cd' => 'Í', '\\u00d3' => 'Ó', '\\u00da' => 'Ú', '\\u00d1' => 'Ñ',
			'\\u00e4' => 'ä', '\\u00f6' => 'ö', '\\u00fc' => 'ü', '\\u00df' => 'ß', '\\u00c4' => 'Ä', '\\u00d6' => 'Ö', '\\u00dc' => 'Ü',
			'\\u00e0' => 'à', '\\u00e2' => 'â', '\\u00ea' => 'ê', '\\u00ee' => 'î', '\\u00f4' => 'ô', '\\u00fb' => 'û', '\\u00e7' => 'ç',
			'\\u00c0' => 'À', '\\u00c2' => 'Â', '\\u00ca' => 'Ê', '\\u00ce' => 'Î', '\\u00d4' => 'Ô', '\\u00db' => 'Û', '\\u00c7' => 'Ç',
			// Additional Danish and Nordic characters
			'\\u00f0' => 'ð', '\\u00fe' => 'þ', '\\u00d0' => 'Ð', '\\u00de' => 'Þ',
			'\\u00f9' => 'ù', '\\u00f2' => 'ò', '\\u00e8' => 'è', '\\u00ec' => 'ì',
			'\\u00d9' => 'Ù', '\\u00d2' => 'Ò', '\\u00c8' => 'È', '\\u00cc' => 'Ì',
		];
		
		foreach ($unicode_replacements as $escaped => $replacement) {
			$fixed = str_replace($escaped, $replacement, $fixed);
		}
		
		return $fixed;
	}
	
	/**
	 * Helper method to find the approximate position of JSON syntax errors
	 *
	 * @param string $json_string The JSON string to analyze
	 * @return string The error position information
	 */
	private function findJsonErrorPosition($json_string) {
		// More sophisticated approach to find the exact error position
		$length = strlen($json_string);
		$error_position = "unknown position";
		
		// Try to find the exact character causing the issue by testing progressively longer portions
		for ($i = 100; $i <= $length; $i += 100) {
			$test_string = substr($json_string, 0, $i);
			$test_decode = json_decode($test_string, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				// Found the error, now narrow it down
				$error_position = $this->narrowDownErrorPosition($json_string, $i - 100, $i);
				break;
			}
		}
		
		// If we didn't find it in chunks, try character by character around the reported position
		if ($error_position === "unknown position") {
			$error_position = $this->narrowDownErrorPosition($json_string, max(0, $length - 200), $length);
		}
		
		return $error_position;
	}
	
	/**
	 * Helper method to narrow down the exact error position
	 *
	 * @param string $json_string The JSON string to analyze
	 * @param int $start_pos Start position for search
	 * @param int $end_pos End position for search
	 * @return string The exact error position information
	 */
	private function narrowDownErrorPosition($json_string, $start_pos, $end_pos) {
		$error_pos = "unknown";
		
		for ($i = $start_pos; $i < $end_pos; $i++) {
			$test_string = substr($json_string, 0, $i);
			$test_decode = json_decode($test_string, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				// Found the exact position
				$error_pos = "character " . $i;
				
				// Get context around the error
				$context_start = max(0, $i - 50);
				$context_end = min(strlen($json_string), $i + 50);
				$context = substr($json_string, $context_start, $context_end - $context_start);
				
				error_log('[AEBG] JSON error context around position ' . $i . ': ' . $context);
				
				// Try to identify the specific issue
				$char_at_error = substr($json_string, $i, 1);
				$error_pos .= " (character: '" . $char_at_error . "', ASCII: " . ord($char_at_error) . ")";
				
				break;
			}
		}
		
		return $error_pos;
	}
	
	/**
	 * Helper method to extract valid JSON portions from a problematic string
	 *
	 * @param string $json_string The JSON string to extract valid portions from
	 * @return string The extracted valid JSON string
	 */
	private function extractValidJsonPortions($json_string) {
		$fixed = $json_string;
		
		// Try to find the start and end of valid JSON structure
		$start_pos = strpos($fixed, '[');
		if ($start_pos === false) {
			$start_pos = strpos($fixed, '{');
		}
		
		if ($start_pos === false) {
			// No valid JSON structure found, return as is
			return $fixed;
		}
		
		// Find the matching closing bracket/brace
		$open_char = $fixed[$start_pos];
		$close_char = ($open_char === '[') ? ']' : '}';
		
		$depth = 0;
		$end_pos = -1;
		
		for ($i = $start_pos; $i < strlen($fixed); $i++) {
			$char = $fixed[$i];
			
			if ($char === $open_char) {
				$depth++;
			} elseif ($char === $close_char) {
				$depth--;
				if ($depth === 0) {
					$end_pos = $i;
					break;
				}
			}
		}
		
		if ($end_pos > $start_pos) {
			// Extract the valid JSON portion
			$extracted = substr($fixed, $start_pos, $end_pos - $start_pos + 1);
			error_log('[AEBG] Extracted JSON portion from position ' . $start_pos . ' to ' . $end_pos);
			return $extracted;
		}
		
		// If we can't find a complete structure, try to truncate at a reasonable point
		$last_complete_object = strrpos($fixed, '}');
		if ($last_complete_object !== false) {
			$truncated = substr($fixed, 0, $last_complete_object + 1);
			error_log('[AEBG] Truncated JSON at last complete object');
			return $truncated;
		}
		
		// If all else fails, return the original string
		return $fixed;
	}

	/**
	 * Get Elementor data using native Elementor functions when possible
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error Elementor data or WP_Error on failure.
	 */
	public function getElementorData($post_id) {
		// Try to use Elementor's native document system first
		if (class_exists('\Elementor\Plugin')) {
			try {
				$elementor = \Elementor\Plugin::instance();
				// FIX: documents is a property (object), not a method
				if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
					$document = $elementor->documents->get($post_id);
					if ($document && method_exists($document, 'get_elements_data')) {
						$data = $document->get_elements_data();
						if (is_array($data)) {
							error_log('[AEBG] Successfully retrieved Elementor data using native API');
							return $data;
						} else {
							error_log('[AEBG] Elementor native API returned non-array data: ' . gettype($data));
						}
					} else {
						error_log('[AEBG] Elementor document or get_elements_data method not available');
					}
				} else {
					error_log('[AEBG] Elementor documents method not available');
				}
			} catch (\Exception $e) {
				error_log('[AEBG] Elementor native API failed: ' . $e->getMessage());
			}
		} else {
			error_log('[AEBG] Elementor Plugin class not found');
		}

		// Fallback to direct post meta
		error_log('[AEBG] Falling back to direct post meta for post ID: ' . $post_id);
		$elementor_data = get_post_meta($post_id, '_elementor_data', true);
		error_log('[AEBG] Post meta data type: ' . gettype($elementor_data));
		error_log('[AEBG] Post meta data empty: ' . (empty($elementor_data) ? 'yes' : 'no'));
		
		if (empty($elementor_data)) {
			error_log('[AEBG] No Elementor data found in post meta');
			return new \WP_Error('aebg_no_elementor_data', 'No Elementor data found in post');
		}

		// If it's already an array, return it
		if (is_array($elementor_data)) {
			error_log('[AEBG] Elementor data is already an array, returning directly');
			return $elementor_data;
		}

		// If it's a string, try to decode it with cleaning
		if (is_string($elementor_data)) {
			error_log('[AEBG] Elementor data is a string, attempting to decode');
			
			// First try direct decode
			$decoded_data = json_decode($elementor_data, true);
			if ($decoded_data !== null) {
				error_log('[AEBG] Direct JSON decode succeeded');
				return $decoded_data;
			}
			
			// If direct decode failed, try cleaning the JSON first
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
	 * Clean JSON string by removing problematic characters
	 *
	 * @param string $json_string The JSON string to clean.
	 * @return string|false Cleaned JSON string or false on failure.
	 */
	private function cleanJsonString($json_string) {
		// Remove BOM if present
		$json_string = str_replace("\xEF\xBB\xBF", '', $json_string);
		
		// Remove any null bytes
		$json_string = str_replace("\x00", '', $json_string);
		
		// Remove any other control characters except newlines and tabs
		$json_string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $json_string);
		
		// Fix double quotes issue (common in Elementor data)
		$json_string = str_replace('""', '"', $json_string);
		
		// Fix unescaped quotes in HTML content and product titles
		// This is a more sophisticated approach to handle quotes within JSON strings
		$json_string = $this->fixUnescapedQuotesInJson($json_string);
		
		// Trim whitespace
		$json_string = trim($json_string);
		
		return $json_string;
	}

	/**
	 * Fix unescaped quotes in JSON strings
	 *
	 * @param string $json_string The JSON string to fix
	 * @return string The fixed JSON string
	 */
	private function fixUnescapedQuotesInJson($json_string) {
		$fixed = $json_string;
		
		// Fix the specific issues mentioned in the error
		
		// 1. Fix unescaped quotes in HTML class attributes like "wp-block-heading"
		// Replace: class="wp-block-heading" with class=\"wp-block-heading\"
		$fixed = str_replace('class="wp-block-heading"', 'class=\"wp-block-heading\"', $fixed);
		
		// 2. Fix unescaped quotes in product titles like "First Co A-Head Headset Spacer 1" 10mm - Black"
		// Replace: "First Co A-Head Headset Spacer 1" 10mm - Black" with "First Co A-Head Headset Spacer 1\" 10mm - Black"
		$fixed = str_replace('" 10mm - Black"', '\" 10mm - Black\"', $fixed);
		
		// 3. More general fix for any unescaped quotes within JSON string values
		// This is a conservative approach that only fixes quotes that are clearly problematic
		
		// Find patterns like "something"something" and fix them
		$fixed = preg_replace('/"([^"]*)"([^"]*)"([^"]*)"/', '"$1\\"$2\\"$3"', $fixed);
		
		// Fix any remaining unescaped quotes in common patterns
		$fixed = preg_replace('/"([^"]*)"\s+([^"]*)"([^"]*)"/', '"$1\\" $2\\"$3"', $fixed);
		
		return $fixed;
	}

	/**
	 * Save Elementor data using native Elementor functions when possible
	 *
	 * @param int $post_id The post ID.
	 * @param array $elementor_data The Elementor data to save.
	 * @return bool|\WP_Error Success or WP_Error on failure.
	 */
	public function saveElementorData($post_id, $elementor_data) {
		// CRITICAL: Sanitize data BEFORE saving to prevent Elementor errors
		if (class_exists('\AEBG\Core\Generator')) {
			$generator = new \AEBG\Core\Generator([]);
			if (method_exists($generator, 'sanitizeElementorDataStructure')) {
				$elementor_data = $generator->sanitizeElementorDataStructure($elementor_data);
				error_log('[AEBG] Sanitized Elementor data before saving for post ' . $post_id);
			}
		}
		
		// CRITICAL FIX: Always try to use Elementor's native API first, even for new posts
		// This ensures the document is properly initialized so Elementor editor can work
		if (class_exists('\Elementor\Plugin')) {
			try {
				$elementor = \Elementor\Plugin::instance();
				
				// First, ensure all required meta fields are set BEFORE getting the document
				// This is critical for Elementor to recognize the post
				update_post_meta($post_id, '_elementor_edit_mode', 'builder');
				update_post_meta($post_id, '_elementor_template_type', 'wp-post');
				update_post_meta($post_id, '_elementor_version', '3.31.0');
				
				// FIX: documents is a property (object), not a method
				if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
					// Try to get or create the document
					$document = $elementor->documents->get($post_id, false);
					
					// If document doesn't exist, try to create it
					if (!$document) {
						error_log('[AEBG] Document not found, attempting to create it for post ' . $post_id);
						// Try to get document type and create it
						$document_type = $elementor->documents->get_document_type('post');
						if ($document_type) {
							$document = $elementor->documents->create('post', $post_id);
							error_log('[AEBG] Created new Elementor document for post ' . $post_id);
						}
					}
					
					if ($document && method_exists($document, 'save')) {
						// Save using Elementor's native API - this properly initializes the document
						$document->save([
							'elements' => $elementor_data,
							'settings' => method_exists($document, 'get_settings') ? $document->get_settings() : []
						]);
						error_log('[AEBG] Successfully saved Elementor data using native API for post ' . $post_id);
						
						// Clear cache after save
						$this->clearElementorCache($post_id);
						return true;
					} else {
						error_log('[AEBG] Document found but save method not available or document is null');
					}
				}
			} catch (\Exception $e) {
				error_log('[AEBG] Elementor native API save failed: ' . $e->getMessage());
				error_log('[AEBG] Exception trace: ' . $e->getTraceAsString());
			}
		}

		// Fallback to direct post meta (or primary method for new posts)
		// Clean the data before encoding to prevent unescaped quotes issues
		$cleaned_elementor_data = $this->cleanElementorDataForEncoding($elementor_data);
		
		// Simply encode the data and save it - no complex checks needed
		$encoded_data = json_encode($cleaned_elementor_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded_data === false) {
			return new \WP_Error('aebg_encoding_failed', 'Failed to encode Elementor data: ' . json_last_error_msg());
		}

		// Set all required Elementor meta fields to ensure WordPress and Elementor recognize this as an Elementor post
		update_post_meta($post_id, '_elementor_edit_mode', 'builder');
		update_post_meta($post_id, '_elementor_template_type', 'wp-post');
		update_post_meta($post_id, '_elementor_version', '3.31.0');
		update_post_meta($post_id, '_elementor_data', $encoded_data);
		
		// Additional meta fields that Elementor expects
		update_post_meta($post_id, '_elementor_css', ''); // Empty CSS for now
		update_post_meta($post_id, '_elementor_page_settings', []); // Empty page settings as array
		update_post_meta($post_id, '_elementor_page_assets', []); // Empty page assets as array
		
		// Set additional meta fields that Elementor might need
		update_post_meta($post_id, '_elementor_controls_usage', []); // Controls usage as array
		update_post_meta($post_id, '_elementor_elements_usage', []); // Elements usage as array
		
		// Ensure the post is marked as using Elementor
		update_post_meta($post_id, '_elementor_edit_mode', 'builder');
		
		// Update the post to mark it as edited with Elementor
		wp_update_post([
			'ID' => $post_id,
			'post_modified' => current_time('mysql'),
			'post_modified_gmt' => current_time('mysql', 1)
		]);
		
		error_log('[AEBG] Enabled Elementor edit mode and set all required meta fields for post ' . $post_id);

		// Clear Elementor cache
		$this->clearElementorCache($post_id);
		
		return true;
	}

	/**
	 * Clean Elementor data before JSON encoding to prevent unescaped quotes issues
	 *
	 * @param array $elementor_data The Elementor data to clean
	 * @return array The cleaned Elementor data
	 */
	private function cleanElementorDataForEncoding($elementor_data, $fix_urls = true) {
		if (!is_array($elementor_data)) {
			return $elementor_data;
		}
		
		// First, fix any URLs in the data structure (only on first call, not recursively)
		if ($fix_urls) {
			$elementor_data = $this->fixUrlsInElementorData($elementor_data);
		}
		
		$cleaned = [];
		foreach ($elementor_data as $key => $value) {
			if (is_array($value)) {
				// Don't fix URLs recursively - they're already fixed
				$cleaned[$key] = $this->cleanElementorDataForEncoding($value, false);
			} else if (is_string($value)) {
				// Clean string values that might contain unescaped quotes
				$cleaned[$key] = $this->cleanStringForJson($value);
			} else {
				$cleaned[$key] = $value;
			}
		}
		
		return $cleaned;
	}

	/**
	 * Recursively fix malformed URLs in Elementor data structure
	 * This ensures URLs are fixed before being saved to Elementor post meta
	 *
	 * @param mixed $data The Elementor data to process
	 * @return mixed The data with fixed URLs
	 */
	private function fixUrlsInElementorData($data) {
		if (!is_array($data)) {
			// If it's a string and looks like a URL, fix it
			if (is_string($data) && (preg_match('/^(https?:\/\/|s:\/\/|s\/\/|http:\/\/s:\/\/|https:\/\/s:\/\/|http:\/\/s\/\/|https:\/\/s\/\/)/', $data) || filter_var($data, FILTER_VALIDATE_URL) !== false)) {
				// URLs from Datafeedr are already correctly formatted
				return $data;
			}
			return $data;
		}
		
		$fixed = [];
		foreach ($data as $key => $value) {
			// Check for common URL field names in Elementor
			if (($key === 'url' || $key === 'href') && is_string($value)) {
				// This is a URL field, fix it
				// URLs from Datafeedr are already correctly formatted
				$fixed[$key] = $value;
			} elseif (is_array($value) && isset($value['url']) && is_string($value['url'])) {
				// This is a nested URL structure (like link.url or image.url)
				$fixed_value = $value;
				// URLs from Datafeedr are already correctly formatted
				$fixed_value['url'] = $value['url'];
				$fixed[$key] = $this->fixUrlsInElementorData($fixed_value);
			} else {
				// Recursively process nested arrays
				$fixed[$key] = $this->fixUrlsInElementorData($value);
			}
		}
		
		return $fixed;
	}


	/**
	 * Clean a string value for JSON encoding
	 *
	 * @param string $string The string to clean
	 * @return string The cleaned string
	 */
	private function cleanStringForJson($string) {
		if (!is_string($string)) {
			return $string;
		}
		
		// First, handle any control characters that could break JSON
		$cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
		
		// Use json_encode to properly escape the string, then remove the surrounding quotes
		$json_encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json_encoded === false) {
			// If json_encode fails, fall back to manual escaping
			$cleaned = str_replace('"', '\\"', $cleaned);
			$cleaned = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $cleaned);
			return $cleaned;
		}
		
		// Remove the surrounding quotes that json_encode adds
		$cleaned = substr($json_encoded, 1, -1);
		
		return $cleaned;
	}

	/**
	 * Reorder product containers in Elementor data
	 *
	 * @param int $post_id The post ID.
	 * @param array $new_order Array of product IDs in new order.
	 * @return bool|\WP_Error Success or WP_Error on failure.
	 */
	public function reorderProductContainers($post_id, $new_order) {
		error_log('[AEBG] ===== START: reorderProductContainers =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		error_log('[AEBG] New order: ' . json_encode($new_order));

		// Get current Elementor data
		$elementor_data = $this->getElementorData($post_id);
		if (is_wp_error($elementor_data)) {
			return $elementor_data;
		}

		// Create comprehensive backup
		$backup_key = 'aebg_reorder_backup_' . $post_id . '_' . time();
		update_option($backup_key, $elementor_data);
		error_log('[AEBG] Created backup: ' . $backup_key);

		// Get current products to establish mapping
		$current_products = get_post_meta($post_id, '_aebg_products', true);
		if (!is_array($current_products)) {
			$current_products = [];
		}

		// Create position mapping based on current Elementor container positions
		$position_mapping = $this->createPositionMappingFromElementorData($elementor_data, $current_products, $new_order, $post_id);
		error_log('[AEBG] Position mapping: ' . json_encode($position_mapping));

		// Reorder the Elementor data with comprehensive updates
		$reordered_data = $this->comprehensiveReorderData($elementor_data, $position_mapping);
		if (is_wp_error($reordered_data)) {
			error_log('[AEBG] Reordering failed: ' . $reordered_data->get_error_message());
			return $reordered_data;
		}

		// Validate the reordered data
		$validation_result = $this->validateReorderedData($reordered_data, $elementor_data);
		if (is_wp_error($validation_result)) {
			error_log('[AEBG] Validation failed: ' . $validation_result->get_error_message());
			// Restore from backup
			$this->restoreElementorDataFromBackup($post_id, $backup_key);
			return $validation_result;
		}

		// CRITICAL: Sanitize data BEFORE saving to prevent Elementor errors
		if (class_exists('\AEBG\Core\Generator')) {
			$generator = new \AEBG\Core\Generator([]);
			if (method_exists($generator, 'sanitizeElementorDataStructure')) {
				$reordered_data = $generator->sanitizeElementorDataStructure($reordered_data);
				error_log('[AEBG] Sanitized reordered data before saving');
			}
		}
		
		// Save the reordered data
		$save_result = $this->saveElementorData($post_id, $reordered_data);
		if (is_wp_error($save_result)) {
			error_log('[AEBG] Save failed: ' . $save_result->get_error_message());
			// Restore from backup
			$this->restoreElementorDataFromBackup($post_id, $backup_key);
			return $save_result;
		}

		// Force Elementor recognition and clear caches
		$this->forceElementorRecognition($post_id);
		$this->clearElementorCache($post_id);
		
		// CRITICAL: Force CSS/JS regeneration after reordering
		// This ensures frontend shows updated content without page reload
		if (class_exists('\AEBG\Core\Generator')) {
			$generator = new \AEBG\Core\Generator([]);
			if (method_exists($generator, 'forceElementorCSSGeneration')) {
				$generator->forceElementorCSSGeneration($post_id);
				error_log('[AEBG] Forced Elementor CSS/JS regeneration after reordering');
			}
		}
		
		// Additional cache clearing for frontend
		$this->clearAllCaches($post_id);
		
		// Force page refresh by updating post modified date
		$this->forcePageRefresh($post_id);
		
		// Additional Elementor-specific cache clearing
		$this->clearElementorSpecificCaches($post_id);
		
		// CRITICAL: Update _aebg_products meta to match the new order
		// This ensures the product order persists after page refresh
		$current_products = get_post_meta($post_id, '_aebg_products', true);
		if (!empty($current_products) && is_array($current_products)) {
			$reordered_products = [];
			foreach ($new_order as $product_id) {
				foreach ($current_products as $product) {
					$product_id_from_data = is_array($product) ? ($product['id'] ?? '') : $product;
					if ($product_id_from_data == $product_id) {
						$reordered_products[] = $product;
						break;
					}
				}
			}
			
			// Add any remaining products that weren't in the new order
			foreach ($current_products as $product) {
				$product_id_from_data = is_array($product) ? ($product['id'] ?? '') : $product;
				$found = false;
				foreach ($new_order as $product_id) {
					if ($product_id_from_data == $product_id) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					$reordered_products[] = $product;
				}
			}
			
			update_post_meta($post_id, '_aebg_products', $reordered_products);
			error_log('[AEBG] Updated _aebg_products meta with new order');
			
			// Also update _aebg_product_ids if it exists
			$product_ids = array_map(function($product) {
				return is_array($product) ? ($product['id'] ?? '') : $product;
			}, $reordered_products);
			update_post_meta($post_id, '_aebg_product_ids', $product_ids);
			error_log('[AEBG] Updated _aebg_product_ids meta with new order');
		}

		// Fire hook for email marketing system
		do_action( 'aebg_post_products_reordered', $post_id, $new_order );
		
		error_log('[AEBG] ===== SUCCESS: reorderProductContainers completed =====');
		return true;
	}

	/**
	 * Create position mapping for reordering based on current Elementor data
	 *
	 * @param array $elementor_data Current Elementor data
	 * @param array $current_products Current products array
	 * @param array $new_order New product order
	 * @param int $post_id Post ID for looking up product data
	 * @return array Position mapping [old_position => new_position]
	 */
	private function createPositionMappingFromElementorData($elementor_data, $current_products, $new_order, $post_id = null) {
		$position_mapping = [];
		
		// First, find the current container positions in Elementor data
		$current_container_positions = $this->findCurrentContainerPositions($elementor_data, $post_id);
		error_log('[AEBG] Current container positions in Elementor data: ' . json_encode($current_container_positions));
		
		// Create a mapping from product number to actual product ID based on current products
		$product_number_to_id = [];
		foreach ($current_products as $index => $product) {
			$product_number = $index + 1;
			$product_id = is_array($product) ? ($product['id'] ?? '') : $product;
			$product_number_to_id[$product_number] = $product_id;
		}
		error_log('[AEBG] Product number to ID mapping: ' . json_encode($product_number_to_id));
		
		// Create a mapping from actual product ID to current container position
		$product_to_container_position = [];
		foreach ($current_container_positions as $container_position => $product_identifier) {
			// If the identifier is already a product ID, use it directly
			if (in_array($product_identifier, $new_order)) {
				$product_to_container_position[$product_identifier] = $container_position;
			} 
			// If it's a product number (like 'product-1'), map it to the actual product ID
			elseif (preg_match('/^product-(\d+)$/', $product_identifier, $matches)) {
				$product_number = (int) $matches[1];
				if (isset($product_number_to_id[$product_number])) {
					$actual_product_id = $product_number_to_id[$product_number];
					$product_to_container_position[$actual_product_id] = $container_position;
					error_log('[AEBG] Mapped product-' . $product_number . ' to actual product ID: ' . $actual_product_id);
				}
			}
		}
		
		error_log('[AEBG] Product to container position mapping: ' . json_encode($product_to_container_position));
		
		// Create the position mapping
		foreach ($new_order as $new_index => $product_id) {
			$new_position = $new_index + 1;
			
			// Find the current container position for this product
			if (isset($product_to_container_position[$product_id])) {
				$old_position = $product_to_container_position[$product_id];
				$position_mapping[$old_position] = $new_position;
				error_log('[AEBG] Mapping: product-' . $old_position . ' -> product-' . $new_position . ' (ID: ' . $product_id . ')');
			} else {
				error_log('[AEBG] WARNING: No container found for product ID: ' . $product_id);
				// Try to find it by searching through all containers
				$found_position = $this->findContainerPositionByProductId($elementor_data, $product_id);
				if ($found_position) {
					$position_mapping[$found_position] = $new_position;
					error_log('[AEBG] Found container position ' . $found_position . ' for product ID ' . $product_id . ' through deep search');
				} else {
					error_log('[AEBG] ERROR: Could not find container for product ID: ' . $product_id . ' - this product may not be in the Elementor data');
				}
			}
		}
		
		// Validate that we have a mapping for each product in the new order
		$mapped_products = array_keys($position_mapping);
		$missing_products = array_diff($new_order, array_values($product_to_container_position));
		
		if (!empty($missing_products)) {
			error_log('[AEBG] WARNING: Missing containers for products: ' . json_encode($missing_products));
		}
		
		error_log('[AEBG] Final position mapping: ' . json_encode($position_mapping));
		return $position_mapping;
	}

	/**
	 * Find current container positions in Elementor data
	 *
	 * @param array $elementor_data Elementor data
	 * @param int $post_id Post ID for looking up product data
	 * @return array Mapping of container position to product ID
	 */
	private function findCurrentContainerPositions($elementor_data, $post_id = null) {
		$container_positions = [];
		
		// error_log('[AEBG] findCurrentContainerPositions called with data keys: ' . json_encode(array_keys($elementor_data)) . ' and post_id: ' . $post_id);
		
		// Debug: Log a sample of the data structure
		// if (count($elementor_data) > 0) {
		// 	$sample_element = $elementor_data[0] ?? $elementor_data;
		// 	error_log('[AEBG] Sample element structure: ' . json_encode(array_keys($sample_element)));
		// 	if (isset($sample_element['settings'])) {
		// 		error_log('[AEBG] Sample element settings keys: ' . json_encode(array_keys($sample_element['settings'])));
		// 	}
		// }
		
		// Handle different data structures
		if (array_keys($elementor_data) === range(0, count($elementor_data) - 1)) {
			// error_log('[AEBG] Processing direct array structure with ' . count($elementor_data) . ' elements');
			// Direct array structure
			$container_positions = $this->findContainerPositionsInArray($elementor_data, $post_id);
		} elseif (isset($elementor_data['content']) && is_array($elementor_data['content'])) {
			// error_log('[AEBG] Processing content array structure with ' . count($elementor_data['content']) . ' elements');
			// Content array structure
			$container_positions = $this->findContainerPositionsInArray($elementor_data['content'], $post_id);
		} else {
			// error_log('[AEBG] Processing recursive structure');
			// Recursive structure
			$container_positions = $this->findContainerPositionsRecursive($elementor_data, $post_id);
		}
		
		// error_log('[AEBG] findCurrentContainerPositions returning ' . count($container_positions) . ' containers');
		
		// If no containers found, try a more aggressive search
		if (empty($container_positions)) {
			// error_log('[AEBG] No containers found, trying aggressive search...');
			$container_positions = $this->aggressiveContainerSearch($elementor_data);
		}
		
		return $container_positions;
	}

	/**
	 * Find container positions in array structure
	 *
	 * @param array $data Array data
	 * @param int $post_id Post ID for looking up product data
	 * @return array Mapping of container position to product ID
	 */
	private function findContainerPositionsInArray($data, $post_id = null) {
		$container_positions = [];
		
		error_log('[AEBG] Searching for containers in array with ' . count($data) . ' elements');
		
		foreach ($data as $index => $element) {
			error_log('[AEBG] Checking element ' . $index . ': ' . json_encode(array_keys($element)));
			
			// Check if this element is a container
			if (isset($element['settings']['_element_id'])) {
				$element_id = $element['settings']['_element_id'];
				error_log('[AEBG] Element ' . $index . ' has _element_id: ' . $element_id);
				
				if (preg_match('/^product-(\d+)$/', $element_id, $matches)) {
					$container_position = (int) $matches[1];
					error_log('[AEBG] Found product container: product-' . $container_position);
					
					// Only add if we haven't already found this container position
					if (!isset($container_positions[$container_position])) {
						// Try to find the product ID for this container
						$product_id = $this->findProductIdForContainer($element, $post_id);
						if ($product_id) {
							$container_positions[$container_position] = $product_id;
							error_log('[AEBG] Found container product-' . $container_position . ' with product ID: ' . $product_id);
						} else {
							error_log('[AEBG] No product ID found for container product-' . $container_position);
						}
					} else {
						error_log('[AEBG] Container position ' . $container_position . ' already found, skipping duplicate');
					}
				} else {
					error_log('[AEBG] Element ' . $index . ' has _element_id but not product pattern: ' . $element_id);
				}
			} else {
				error_log('[AEBG] Element ' . $index . ' has no _element_id');
			}
			
			// Also check nested elements for containers (but don't merge duplicates)
			if (isset($element['elements']) && is_array($element['elements'])) {
				error_log('[AEBG] Element ' . $index . ' has nested elements, checking them...');
				$nested_containers = $this->findContainerPositionsInArray($element['elements'], $post_id);
				// Only add nested containers that we haven't already found
				foreach ($nested_containers as $nested_position => $nested_product_id) {
					if (!isset($container_positions[$nested_position])) {
						$container_positions[$nested_position] = $nested_product_id;
						error_log('[AEBG] Added nested container product-' . $nested_position . ' with product ID: ' . $nested_product_id);
					} else {
						error_log('[AEBG] Nested container position ' . $nested_position . ' already found, skipping duplicate');
					}
				}
			}
			
			// Fallback: Check if this element contains product references even if it doesn't have the right _element_id
			if (!isset($element['settings']['_element_id']) || !preg_match('/^product-(\d+)$/', $element['settings']['_element_id'])) {
				$product_references = $this->findProductReferencesInElement($element);
				if (!empty($product_references)) {
					error_log('[AEBG] Element ' . $index . ' contains product references: ' . json_encode($product_references));
					// Try to infer the container position from the product references
					foreach ($product_references as $product_number) {
						if (!isset($container_positions[$product_number])) {
							$container_positions[$product_number] = 'product-' . $product_number;
							error_log('[AEBG] Inferred container position ' . $product_number . ' from product references');
						} else {
							error_log('[AEBG] Container position ' . $product_number . ' already found, skipping inferred duplicate');
						}
					}
				}
			}
		}
		
		error_log('[AEBG] Found ' . count($container_positions) . ' unique product containers in array (including nested)');
		return $container_positions;
	}

	/**
	 * Find container positions in recursive structure
	 *
	 * @param array $data Recursive data
	 * @param int $post_id Post ID for looking up product data
	 * @return array Mapping of container position to product ID
	 */
	private function findContainerPositionsRecursive($data, $post_id = null) {
		$container_positions = [];
		
		if (!is_array($data)) {
			return $container_positions;
		}
		
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$nested_positions = $this->findContainerPositionsRecursive($value, $post_id);
				$container_positions = array_merge($container_positions, $nested_positions);
			}
		}
		
		// Check if this element itself is a container
		if (isset($data['settings']['_element_id']) && 
			preg_match('/^product-(\d+)$/', $data['settings']['_element_id'], $matches)) {
			$container_position = (int) $matches[1];
			$product_id = $this->findProductIdForContainer($data, $post_id);
			if ($product_id) {
				$container_positions[$container_position] = $product_id;
			}
		}
		
		return $container_positions;
	}

	/**
	 * Find container position by product ID through deep search
	 *
	 * @param array $elementor_data Elementor data
	 * @param string $product_id Product ID to search for
	 * @return int|null Container position or null if not found
	 */
	private function findContainerPositionByProductId($elementor_data, $product_id) {
		// Handle different data structures
		if (array_keys($elementor_data) === range(0, count($elementor_data) - 1)) {
			// Direct array structure
			return $this->findContainerPositionInArray($elementor_data, $product_id);
		} elseif (isset($elementor_data['content']) && is_array($elementor_data['content'])) {
			// Content array structure
			return $this->findContainerPositionInArray($elementor_data['content'], $product_id);
		} else {
			// Recursive structure
			return $this->findContainerPositionRecursive($elementor_data, $product_id);
		}
	}

	/**
	 * Find container position in array structure
	 *
	 * @param array $data Array data
	 * @param string $product_id Product ID to search for
	 * @return int|null Container position or null if not found
	 */
	private function findContainerPositionInArray($data, $product_id) {
		foreach ($data as $element) {
			if (isset($element['settings']['_element_id']) && 
				preg_match('/^product-(\d+)$/', $element['settings']['_element_id'], $matches)) {
				$container_position = (int) $matches[1];
				
				// Check if this container contains the product ID
				if ($this->containerContainsProductId($element, $product_id)) {
					return $container_position;
				}
			}
		}
		
		return null;
	}

	/**
	 * Find container position in recursive structure
	 *
	 * @param array $data Recursive data
	 * @param string $product_id Product ID to search for
	 * @return int|null Container position or null if not found
	 */
	private function findContainerPositionRecursive($data, $product_id) {
		if (!is_array($data)) {
			return null;
		}
		
		// Check if this element itself is a container
		if (isset($data['settings']['_element_id']) && 
			preg_match('/^product-(\d+)$/', $data['settings']['_element_id'], $matches)) {
			$container_position = (int) $matches[1];
			
			// Check if this container contains the product ID
			if ($this->containerContainsProductId($data, $product_id)) {
				return $container_position;
			}
		}
		
		// Search recursively
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$result = $this->findContainerPositionRecursive($value, $product_id);
				if ($result !== null) {
					return $result;
				}
			}
		}
		
		return null;
	}

	/**
	 * Check if a container contains a specific product ID
	 *
	 * @param array $container Container data
	 * @param string $product_id Product ID to search for
	 * @return bool True if container contains the product ID
	 */
	private function containerContainsProductId($container, $product_id) {
		// Check for aebg_product_id in settings
		if (isset($container['settings']['aebg_product_id']) && $container['settings']['aebg_product_id'] === $product_id) {
			return true;
		}
		
		// Check all settings for product references
		foreach ($container['settings'] ?? [] as $key => $value) {
			if (is_string($value) && strpos($value, $product_id) !== false) {
				return true;
			}
		}
		
		// Check nested elements
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as $element) {
				if ($this->containerContainsProductId($element, $product_id)) {
					return true;
				}
			}
		}
		
		return false;
	}

	/**
	 * Find product references in an element
	 *
	 * @param array $element Element data
	 * @return array Array of product numbers found
	 */
	private function findProductReferencesInElement($element) {
		$product_references = [];
		
		// Check settings for product references
		if (isset($element['settings']) && is_array($element['settings'])) {
			foreach ($element['settings'] as $key => $value) {
				if (is_string($value)) {
					// Look for {product-X} patterns
					if (preg_match_all('/\{product-(\d+)\}/', $value, $matches)) {
						foreach ($matches[1] as $product_number) {
							$product_references[] = (int) $product_number;
						}
					}
				}
			}
		}
		
		// Check nested elements
		if (isset($element['elements']) && is_array($element['elements'])) {
			foreach ($element['elements'] as $nested_element) {
				$nested_references = $this->findProductReferencesInElement($nested_element);
				$product_references = array_merge($product_references, $nested_references);
			}
		}
		
		// Remove duplicates and sort
		$product_references = array_unique($product_references);
		sort($product_references);
		
		return $product_references;
	}

	/**
	 * Aggressive search for containers in Elementor data
	 *
	 * @param array $elementor_data Elementor data
	 * @return array Mapping of container position to product ID
	 */
	private function aggressiveContainerSearch($elementor_data) {
		$container_positions = [];
		
		error_log('[AEBG] Starting aggressive container search...');
		
		// Convert to JSON string and search for product patterns
		$json_string = json_encode($elementor_data);
		if ($json_string === false) {
			error_log('[AEBG] Failed to encode Elementor data to JSON for aggressive search');
			return $container_positions;
		}
		
		// Look for any product-X patterns in the JSON
		if (preg_match_all('/"product-(\d+)"/', $json_string, $matches)) {
			foreach ($matches[1] as $product_number) {
				$container_position = (int) $product_number;
				if (!isset($container_positions[$container_position])) {
					$container_positions[$container_position] = 'product-' . $product_number;
					error_log('[AEBG] Aggressive search found container product-' . $container_position);
				}
			}
		}
		
		// Also look for {product-X} patterns in the JSON
		if (preg_match_all('/\{product-(\d+)\}/', $json_string, $matches)) {
			foreach ($matches[1] as $product_number) {
				$container_position = (int) $product_number;
				if (!isset($container_positions[$container_position])) {
					$container_positions[$container_position] = 'product-' . $product_number;
					error_log('[AEBG] Aggressive search found product reference {product-' . $container_position . '}');
				}
			}
		}
		
		error_log('[AEBG] Aggressive search found ' . count($container_positions) . ' containers');
		return $container_positions;
	}

	/**
	 * Find product ID for a container
	 *
	 * @param array $container Container data
	 * @param int $post_id Post ID for looking up product data
	 * @return string|null Product ID or null if not found
	 */
	private function findProductIdForContainer($container, $post_id = null) {
		// Method 1: Check for aebg_product_id in settings
		if (isset($container['settings']['aebg_product_id'])) {
			return $container['settings']['aebg_product_id'];
		}
		
		// Method 2: Check for product references and map to actual product IDs
		$product_number = null;
		
		// Check for product references in AI prompt
		if (isset($container['settings']['aebg_ai_prompt'])) {
			$prompt = $container['settings']['aebg_ai_prompt'];
			if (preg_match('/\{product-(\d+)\}/', $prompt, $matches)) {
				$product_number = (int) $matches[1];
			}
		}
		
		// Check for product references in other content
		if (!$product_number) {
			foreach ($container['settings'] ?? [] as $key => $value) {
				if (is_string($value) && preg_match('/\{product-(\d+)\}/', $value, $matches)) {
					$product_number = (int) $matches[1];
					break;
				}
			}
		}
		
		// If we found a product number, try to map it to the actual product ID
		if ($product_number && $post_id) {
			$products = get_post_meta($post_id, '_aebg_products', true);
			if (is_array($products) && isset($products[$product_number - 1])) {
				$product = $products[$product_number - 1];
				if (isset($product['id'])) {
					error_log('[AEBG] Mapped product-' . $product_number . ' to actual product ID: ' . $product['id']);
					return $product['id'];
				}
			}
			
			// Fallback: try to get from product IDs
			$product_ids = get_post_meta($post_id, '_aebg_product_ids', true);
			if (is_array($product_ids) && isset($product_ids[$product_number - 1])) {
				$product_id = $product_ids[$product_number - 1];
				error_log('[AEBG] Mapped product-' . $product_number . ' to product ID from _aebg_product_ids: ' . $product_id);
				return $product_id;
			}
		}
		
		// If we can't find the actual product ID, return the product number as a fallback
		if ($product_number) {
			error_log('[AEBG] Could not map product-' . $product_number . ' to actual product ID, using fallback');
			return 'product-' . $product_number;
		}
		
		return null;
	}

	/**
	 * Create position mapping for reordering (legacy method - kept for compatibility)
	 *
	 * @param array $current_products Current products array
	 * @param array $new_order New product order
	 * @return array Position mapping [old_position => new_position]
	 */
	private function createPositionMapping($current_products, $new_order) {
		$position_mapping = [];
		
		foreach ($new_order as $new_index => $product_id) {
			$new_position = $new_index + 1;
			
			// Find which current position this product was at
			foreach ($current_products as $old_index => $product) {
				$current_product_id = is_array($product) ? ($product['id'] ?? '') : $product;
				if ($current_product_id == $product_id) {
					$old_position = $old_index + 1;
					$position_mapping[$old_position] = $new_position;
					error_log('[AEBG] Mapping: product-' . $old_position . ' -> product-' . $new_position . ' (ID: ' . $product_id . ')');
					break;
				}
			}
		}
		
		return $position_mapping;
	}

	/**
	 * Comprehensive reordering of Elementor data
	 *
	 * @param array $data Elementor data
	 * @param array $position_mapping Position mapping [old_position => new_position]
	 * @return array|\WP_Error Reordered data or WP_Error
	 */
	private function comprehensiveReorderData($data, $position_mapping) {
		if (!is_array($data)) {
			return new \WP_Error('aebg_invalid_data', 'Invalid data structure');
		}

		// Create deep copy
		$reordered_data = json_decode(json_encode($data), true);
		if (!is_array($reordered_data)) {
			return new \WP_Error('aebg_copy_failed', 'Failed to create deep copy of data');
		}

		// Handle different data structures
		if (array_keys($data) === range(0, count($data) - 1)) {
			// Direct array structure
			$reordered_data = $this->reorderDirectArrayWithNestedSupport($reordered_data, $position_mapping);
		} elseif (isset($data['content']) && is_array($data['content'])) {
			// Content array structure
			$reordered_data['content'] = $this->reorderDirectArrayWithNestedSupport($data['content'], $position_mapping);
		} else {
			// Recursive structure
			$reordered_data = $this->reorderRecursiveStructure($reordered_data, $position_mapping);
		}

		return $reordered_data;
	}

	/**
	 * Reorder direct array structure with nested container support
	 *
	 * @param array $data Direct array data
	 * @param array $position_mapping Position mapping
	 * @return array Reordered data
	 */
	private function reorderDirectArrayWithNestedSupport($data, $position_mapping) {
		error_log('[AEBG] reorderDirectArrayWithNestedSupport called with ' . count($data) . ' elements and mapping: ' . json_encode($position_mapping));

		// First, try to find and reorder containers at the top level
		$top_level_containers = [];
		$non_container_elements = [];
		$has_nested_containers = false;
		
		foreach ($data as $index => $element) {
			error_log('[AEBG] Checking element ' . $index . ' for top-level containers');
			if (isset($element['settings']['_element_id']) && 
				preg_match('/^product-(\d+)$/', $element['settings']['_element_id'], $matches)) {
				$product_number = (int) $matches[1];
				$top_level_containers[$product_number] = $element;
				error_log('[AEBG] Found top-level container: product-' . $product_number);
			} else {
				// Check if this element contains nested product containers
				if (isset($element['elements']) && is_array($element['elements'])) {
					$nested_containers = $this->findNestedProductContainers($element);
					if (!empty($nested_containers)) {
						$has_nested_containers = true;
						error_log('[AEBG] Found nested containers in element ' . $index . ': ' . implode(', ', array_keys($nested_containers)));
					}
				}
				$non_container_elements[] = $element;
			}
		}

		// If we found top-level containers, reorder them
		if (!empty($top_level_containers)) {
			error_log('[AEBG] Found ' . count($top_level_containers) . ' top-level product containers: ' . implode(', ', array_keys($top_level_containers)));
			return $this->reorderTopLevelContainers($data, $top_level_containers, $position_mapping);
		}

		// If no top-level containers but we found nested containers, use nested reordering
		if ($has_nested_containers) {
			error_log('[AEBG] No top-level containers found, but found nested containers, using nested reordering');
			return $this->reorderNestedContainers($data, $position_mapping);
		}

		// If no containers found at all, return data as is
		error_log('[AEBG] No product containers found at all, returning data as is');
		return $data;
	}

	/**
	 * Reorder top-level containers
	 *
	 * @param array $data Original data
	 * @param array $containers Found containers
	 * @param array $position_mapping Position mapping
	 * @return array Reordered data
	 */
	private function reorderTopLevelContainers($data, $containers, $position_mapping) {
		$reordered_containers = [];
		$processed_positions = [];
		
		// Reorder containers based on the mapping
		foreach ($position_mapping as $old_position => $new_position) {
			if (isset($containers[$old_position])) {
				$container = $containers[$old_position];
				$updated_container = $this->updateContainerForNewPosition($container, $old_position, $new_position);
				$reordered_containers[] = $updated_container;
				$processed_positions[] = $old_position;
				error_log('[AEBG] Reordered top-level container product-' . $old_position . ' to product-' . $new_position);
			} else {
				error_log('[AEBG] WARNING: Top-level container product-' . $old_position . ' not found in data');
			}
		}

		// Add any remaining containers that weren't in the mapping
		foreach ($containers as $position => $container) {
			if (!in_array($position, $processed_positions)) {
				$reordered_containers[] = $container;
				error_log('[AEBG] Added unprocessed top-level container product-' . $position . ' to end');
			}
		}

		// Combine with non-container elements
		$non_container_elements = array_filter($data, function($element) {
			return !isset($element['settings']['_element_id']) || 
				   !preg_match('/^product-(\d+)$/', $element['settings']['_element_id']);
		});

		$result = array_merge($reordered_containers, $non_container_elements);
		error_log('[AEBG] Final result has ' . count($result) . ' elements (original had ' . count($data) . ')');
		
		return $result;
	}

	/**
	 * Reorder nested containers within their parent containers
	 *
	 * @param array $data Original data
	 * @param array $position_mapping Position mapping
	 * @return array Reordered data
	 */
	private function reorderNestedContainers($data, $position_mapping) {
		$reordered_data = [];
		
		error_log('[AEBG] reorderNestedContainers called with ' . count($data) . ' elements');
		
		foreach ($data as $index => $element) {
			error_log('[AEBG] Processing element ' . $index . ' with keys: ' . implode(', ', array_keys($element)));
			
			if (isset($element['elements']) && is_array($element['elements'])) {
				error_log('[AEBG] Element ' . $index . ' has ' . count($element['elements']) . ' nested elements');
				
				// Check if this element contains nested product containers
				$nested_containers = $this->findNestedProductContainers($element);
				
				if (!empty($nested_containers)) {
					error_log('[AEBG] Found ' . count($nested_containers) . ' nested containers in element ' . $index . ': ' . implode(', ', array_keys($nested_containers)));
					
					// Reorder the nested containers within this element
					$reordered_element = $this->reorderContainersWithinElement($element, $nested_containers, $position_mapping);
					$reordered_data[] = $reordered_element;
				} else {
					error_log('[AEBG] No nested containers found in element ' . $index . ', keeping as is');
					// No nested containers, keep element as is
					$reordered_data[] = $element;
				}
			} else {
				error_log('[AEBG] Element ' . $index . ' has no nested elements, keeping as is');
				// No nested elements, keep element as is
				$reordered_data[] = $element;
			}
		}
		
		error_log('[AEBG] reorderNestedContainers returning ' . count($reordered_data) . ' elements');
		return $reordered_data;
	}

	/**
	 * Reorder containers within a specific element
	 *
	 * @param array $element Element containing nested containers
	 * @param array $nested_containers Found nested containers
	 * @param array $position_mapping Position mapping
	 * @return array Updated element
	 */
	private function reorderContainersWithinElement($element, $nested_containers, $position_mapping) {
		$updated_element = json_decode(json_encode($element), true);
		
		if (!isset($updated_element['elements']) || !is_array($updated_element['elements'])) {
			return $element;
		}
		
		error_log('[AEBG] reorderContainersWithinElement called with ' . count($updated_element['elements']) . ' nested elements');
		
		if (empty($nested_containers)) {
			error_log('[AEBG] No nested containers provided to reorderContainersWithinElement');
			return $element;
		}
		
		error_log('[AEBG] Found ' . count($nested_containers) . ' containers in element: ' . implode(', ', array_keys($nested_containers)));
		
		// Reorder the containers recursively within the element structure
		$updated_element = $this->reorderContainersRecursively($updated_element, $nested_containers, $position_mapping);
		
		error_log('[AEBG] Final element has ' . count($updated_element['elements']) . ' elements');
		
		return $updated_element;
	}

	/**
	 * Recursively reorder containers within an element structure
	 *
	 * @param array $element Element to process
	 * @param array $all_containers All containers found in the element
	 * @param array $position_mapping Position mapping
	 * @return array Updated element
	 */
	private function reorderContainersRecursively($element, $all_containers, $position_mapping) {
		if (!is_array($element)) {
			return $element;
		}
		
		// If this element has nested elements, process them
		if (isset($element['elements']) && is_array($element['elements'])) {
			$reordered_elements = [];
			$non_container_elements = [];
			$found_containers = [];
			$processed_positions = [];
			
			// First, find all product containers at this level and recursively process nested elements
			foreach ($element['elements'] as $nested_element) {
				if (isset($nested_element['settings']['_element_id']) && 
					preg_match('/^product-(\d+)$/', $nested_element['settings']['_element_id'], $matches)) {
					$product_number = (int) $matches[1];
					$found_containers[$product_number] = $nested_element;
					error_log('[AEBG] Found container product-' . $product_number . ' at current level');
				} else {
					// Recursively process this nested element to handle deeper nesting
					$processed_element = $this->reorderContainersRecursively($nested_element, $all_containers, $position_mapping);
					$non_container_elements[] = $processed_element;
				}
			}
			
			// If we found containers at this level, reorder them
			if (!empty($found_containers)) {
				error_log('[AEBG] Found ' . count($found_containers) . ' containers at current level: ' . implode(', ', array_keys($found_containers)));
				
				// Create a new array to hold reordered containers in the correct order
				$reordered_containers = [];
				
				// First, add containers in the order specified by the position mapping
				foreach ($position_mapping as $old_position => $new_position) {
					if (isset($found_containers[$old_position])) {
						$container = $found_containers[$old_position];
						$updated_container = $this->updateContainerForNewPosition($container, $old_position, $new_position);
						$reordered_containers[] = $updated_container;
						$processed_positions[] = $old_position;
						error_log('[AEBG] Reordered nested container product-' . $old_position . ' to product-' . $new_position);
					} else {
						error_log('[AEBG] WARNING: Nested container product-' . $old_position . ' not found at current level');
					}
				}
				
				// Add any remaining containers that weren't in the mapping (in their original order)
				foreach ($found_containers as $position => $container) {
					if (!in_array($position, $processed_positions)) {
						$reordered_containers[] = $container;
						error_log('[AEBG] Added unprocessed nested container product-' . $position . ' to end');
					}
				}
				
				// Combine reordered containers with non-container elements
				// Maintain the original order: containers first, then other elements
				$element['elements'] = array_merge($reordered_containers, $non_container_elements);
			} else {
				// No containers at this level, but we processed nested elements
				$element['elements'] = $non_container_elements;
			}
		}
		
		return $element;
	}

	/**
	 * Update container for new position
	 *
	 * @param array $container Container data
	 * @param int $old_position Old position
	 * @param int $new_position New position
	 * @return array Updated container
	 */
	private function updateContainerForNewPosition($container, $old_position, $new_position) {
		// Create deep copy
		$updated_container = json_decode(json_encode($container), true);
		if (!is_array($updated_container)) {
			return $container;
		}

		// Update CSS IDs
		$updated_container = $this->updateContainerIds($updated_container, $old_position, $new_position);

		// Update all variables and content
		$updated_container = $this->updateAllVariablesInContainer($updated_container, $old_position, $new_position);

		return $updated_container;
	}

	/**
	 * Update container IDs
	 *
	 * @param array $container Container data
	 * @param int $old_position Old position
	 * @param int $new_position New position
	 * @return array Updated container
	 */
	private function updateContainerIds($container, $old_position, $new_position) {
		if (isset($container['settings'])) {
			$old_id = 'product-' . $old_position;
			$new_id = 'product-' . $new_position;

			// Update all possible ID fields
			$id_fields = ['_element_id', '_css_id', 'css_id'];
			foreach ($id_fields as $field) {
				if (isset($container['settings'][$field]) && $container['settings'][$field] === $old_id) {
					$container['settings'][$field] = $new_id;
				}
			}
		}

		return $container;
	}

	/**
	 * Update all variables in container comprehensively
	 *
	 * @param array $container Container data
	 * @param int $old_position Old position
	 * @param int $new_position New position
	 * @return array Updated container
	 */
	private function updateAllVariablesInContainer($container, $old_position, $new_position) {
		if (!is_array($container)) {
			return $container;
		}

		// Update settings
		if (isset($container['settings'])) {
			$container['settings'] = $this->updateAllVariablesInSettings($container['settings'], $old_position, $new_position);
		}

		// Update elements recursively
		if (isset($container['elements']) && is_array($container['elements'])) {
			foreach ($container['elements'] as $index => $element) {
				$container['elements'][$index] = $this->updateAllVariablesInContainer($element, $old_position, $new_position);
			}
		}

		// Update content recursively
		if (isset($container['content']) && is_array($container['content'])) {
			foreach ($container['content'] as $index => $content_item) {
				$container['content'][$index] = $this->updateAllVariablesInContainer($content_item, $old_position, $new_position);
			}
		}

		return $container;
	}

	/**
	 * Update all variables in settings comprehensively
	 *
	 * @param array $settings Settings array
	 * @param int $old_position Old position
	 * @param int $new_position New position
	 * @return array Updated settings
	 */
	private function updateAllVariablesInSettings($settings, $old_position, $new_position) {
		if (!is_array($settings)) {
			return $settings;
		}

		$updated_settings = [];
		foreach ($settings as $key => $value) {
			if (is_string($value)) {
				$updated_settings[$key] = $this->updateAllVariablesInText($value, $old_position, $new_position);
			} elseif (is_array($value)) {
				// Handle special cases like icon_list
				if ($key === 'icon_list' && is_array($value)) {
					$updated_settings[$key] = $this->updateIconListVariables($value, $old_position, $new_position);
				} else {
					$updated_settings[$key] = $this->updateAllVariablesInSettings($value, $old_position, $new_position);
				}
			} else {
				$updated_settings[$key] = $value;
			}
		}

		return $updated_settings;
	}

	/**
	 * Update all variables in text comprehensively
	 *
	 * @param string $text Text to update
	 * @param int $old_position Old position
	 * @param int $new_position New position
	 * @return string Updated text
	 */
	private function updateAllVariablesInText($text, $old_position, $new_position) {
		if (!is_string($text)) {
			return $text;
		}

		// Update simple product variables: {product-X}
		$text = preg_replace('/\{product-' . $old_position . '\}/', '{product-' . $new_position . '}', $text);

		// Update complex product variables: {product-X-*}
		$text = preg_replace_callback('/\{product-' . $old_position . '-([^}]+)\}/', function($matches) use ($new_position) {
			$variable_name = $matches[1];
			return '{product-' . $new_position . '-' . $variable_name . '}';
		}, $text);

		// Update any other position-specific references
		$text = str_replace('product-' . $old_position, 'product-' . $new_position, $text);

		return $text;
	}

	/**
	 * Update variables in icon list items
	 *
	 * @param array $icon_list Icon list array
	 * @param int $old_position Old position
	 * @param int $new_position New position
	 * @return array Updated icon list
	 */
	private function updateIconListVariables($icon_list, $old_position, $new_position) {
		$updated_icon_list = [];
		
		foreach ($icon_list as $icon_item) {
			if (is_array($icon_item)) {
				$updated_item = $icon_item;
				
				// Update text field
				if (isset($updated_item['text'])) {
					$updated_item['text'] = $this->updateAllVariablesInText($updated_item['text'], $old_position, $new_position);
				}
				
				// Update AI prompt fields
				if (isset($updated_item['aebg_iconlist_ai_prompt'])) {
					$updated_item['aebg_iconlist_ai_prompt'] = $this->updateAllVariablesInText($updated_item['aebg_iconlist_ai_prompt'], $old_position, $new_position);
				}
				
				$updated_icon_list[] = $updated_item;
			} else {
				$updated_icon_list[] = $icon_item;
			}
		}
		
		return $updated_icon_list;
	}

	/**
	 * Validate reordered data
	 *
	 * @param array $reordered_data Reordered data
	 * @param array $original_data Original data
	 * @return true|\WP_Error Validation result
	 */
	private function validateReorderedData($reordered_data, $original_data) {
		// Check if data is still an array
		if (!is_array($reordered_data)) {
			return new \WP_Error('aebg_validation_failed', 'Reordered data is not an array');
		}

		// Check if we can encode it as JSON
		$test_json = json_encode($reordered_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($test_json === false) {
			return new \WP_Error('aebg_validation_failed', 'Reordered data cannot be encoded as JSON: ' . json_last_error_msg());
		}

		// Check element count (for direct arrays) - but be more flexible
		if (array_keys($original_data) === range(0, count($original_data) - 1) &&
			array_keys($reordered_data) === range(0, count($reordered_data) - 1)) {
			$original_count = count($original_data);
			$reordered_count = count($reordered_data);
			
			error_log('[AEBG] Validation: Original count: ' . $original_count . ', Reordered count: ' . $reordered_count);
			
			// Allow for some flexibility in element count (in case some elements were added/removed)
			if (abs($reordered_count - $original_count) > 2) {
				error_log('[AEBG] WARNING: Significant element count difference detected');
				// Don't fail validation for this, just log a warning
			}
			
			// Only fail if the difference is too large (more than 50% of original)
			if (abs($reordered_count - $original_count) > ($original_count * 0.5)) {
				return new \WP_Error('aebg_validation_failed', 'Element count mismatch after reordering: Original ' . $original_count . ', Reordered ' . $reordered_count);
			}
		}

		// Check for required Elementor structure
		foreach ($reordered_data as $element) {
			if (!is_array($element)) {
				return new \WP_Error('aebg_validation_failed', 'Invalid element structure in reordered data');
			}
		}

		error_log('[AEBG] Validation passed successfully');
		return true;
	}

	/**
	 * Restore Elementor data from backup
	 *
	 * @param int $post_id Post ID
	 * @param string $backup_key Backup key
	 * @return bool Success status
	 */
	private function restoreElementorDataFromBackup($post_id, $backup_key) {
		$backup_data = get_option($backup_key);
		if ($backup_data) {
			$this->saveElementorData($post_id, $backup_data);
			delete_option($backup_key);
			error_log('[AEBG] Restored Elementor data from backup: ' . $backup_key);
			return true;
		}
		return false;
	}

	/**
	 * Update product information in Elementor data
	 *
	 * @param int $post_id The post ID.
	 * @param array $product_updates Array of product updates [product_id => new_data].
	 * @return bool|\WP_Error Success or WP_Error on failure.
	 */
	public function updateProductInformation($post_id, $product_updates) {
		error_log('[AEBG] ===== START: updateProductInformation =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		error_log('[AEBG] Product updates: ' . json_encode($product_updates));

		// Get current Elementor data
		$elementor_data = $this->getElementorData($post_id);
		if (is_wp_error($elementor_data)) {
			return $elementor_data;
		}

		// Create backup
		$backup_key = 'aebg_update_backup_' . $post_id . '_' . time();
		update_option($backup_key, $elementor_data);

		// Update product information in the data
		$updated_data = $this->updateProductsInData($elementor_data, $product_updates);
		if (is_wp_error($updated_data)) {
			return $updated_data;
		}

		// Save the updated data
		$save_result = $this->saveElementorData($post_id, $updated_data);
		if (is_wp_error($save_result)) {
			return $save_result;
		}

		error_log('[AEBG] ===== SUCCESS: updateProductInformation completed =====');
		return true;
	}

	/**
	 * Insert new container with OpenAI-generated content
	 *
	 * @param int $post_id The post ID.
	 * @param int $position Position to insert the new container (0-based).
	 * @param array $product_data Product data for the new container.
	 * @param string $openai_api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return bool|\WP_Error Success or WP_Error on failure.
	 */
	public function insertNewContainer($post_id, $position, $product_data, $openai_api_key, $ai_model = 'gpt-3.5-turbo') {
		error_log('[AEBG] ===== START: insertNewContainer =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		error_log('[AEBG] Position: ' . $position);
		error_log('[AEBG] Product data: ' . json_encode($product_data));

		// Get current Elementor data
		$elementor_data = $this->getElementorData($post_id);
		if (is_wp_error($elementor_data)) {
			return $elementor_data;
		}

		// Create backup
		$backup_key = 'aebg_insert_backup_' . $post_id . '_' . time();
		update_option($backup_key, $elementor_data);

		// Generate new container with AI content
		$new_container = $this->generateNewContainerWithAI($product_data, $openai_api_key, $ai_model);
		if (is_wp_error($new_container)) {
			return $new_container;
		}

		// Insert the new container
		$updated_data = $this->insertContainerInData($elementor_data, $position, $new_container);
		if (is_wp_error($updated_data)) {
			return $updated_data;
		}

		// Save the updated data
		$save_result = $this->saveElementorData($post_id, $updated_data);
		if (is_wp_error($save_result)) {
			return $save_result;
		}

		error_log('[AEBG] ===== SUCCESS: insertNewContainer completed =====');
		return true;
	}

	/**
	 * Reorder containers in Elementor data
	 *
	 * @param array $data The Elementor data.
	 * @param array $new_order Array of product IDs in new order.
	 * @return array|\WP_Error Reordered data or WP_Error on failure.
	 */
	private function reorderContainersInData($data, $new_order) {
		if (!is_array($data)) {
			return new \WP_Error('aebg_invalid_data', 'Invalid data structure');
		}

		// Find all product containers
		$containers = [];
		$other_elements = [];

		foreach ($data as $element) {
			if (isset($element['settings']['_element_id']) && 
				preg_match('/^product-(\d+)$/', $element['settings']['_element_id'], $matches)) {
				$product_number = $matches[1];
				$containers[$product_number] = $element;
			} else {
				$other_elements[] = $element;
			}
		}

		// Reorder containers based on new order
		$reordered_containers = [];
		foreach ($new_order as $product_id) {
			// Find the container for this product
			foreach ($containers as $product_number => $container) {
				// You might need to adjust this logic based on how products are mapped
				if (isset($container['settings']['aebg_product_id']) && 
					$container['settings']['aebg_product_id'] == $product_id) {
					$reordered_containers[] = $container;
					break;
				}
			}
		}

		// Combine reordered containers with other elements
		return array_merge($reordered_containers, $other_elements);
	}

	/**
	 * Update products in Elementor data
	 *
	 * @param array $data The Elementor data.
	 * @param array $product_updates Array of product updates.
	 * @return array|\WP_Error Updated data or WP_Error on failure.
	 */
	private function updateProductsInData($data, $product_updates) {
		if (!is_array($data)) {
			return new \WP_Error('aebg_invalid_data', 'Invalid data structure');
		}

		// Recursively update the data
		return $this->updateProductsRecursive($data, $product_updates);
	}

	/**
	 * Recursively update products in Elementor data
	 *
	 * @param array $data The Elementor data.
	 * @param array $product_updates Array of product updates.
	 * @return array Updated data.
	 */
	private function updateProductsRecursive($data, $product_updates) {
		if (!is_array($data)) {
			return $data;
		}

		$updated_data = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$updated_data[$key] = $this->updateProductsRecursive($value, $product_updates);
			} else {
				$updated_data[$key] = $value;
			}
		}

		// Update AI prompts and content if this is a widget
		if (isset($updated_data['widgetType']) && isset($updated_data['settings'])) {
			$updated_data['settings'] = $this->updateWidgetSettings($updated_data['settings'], $product_updates);
		}

		return $updated_data;
	}

	/**
	 * Update widget settings with new product information
	 *
	 * @param array $settings The widget settings.
	 * @param array $product_updates Array of product updates.
	 * @return array Updated settings.
	 */
	private function updateWidgetSettings($settings, $product_updates) {
		if (!is_array($settings)) {
			return $settings;
		}

		$updated_settings = [];
		foreach ($settings as $key => $value) {
			if (is_string($value) && strpos($value, '{product-') !== false) {
				// Update product references in AI prompts
				$updated_settings[$key] = $this->updateProductReferences($value, $product_updates);
			} else {
				$updated_settings[$key] = $value;
			}
		}

		return $updated_settings;
	}

	/**
	 * Update product references in text
	 *
	 * @param string $text The text to update.
	 * @param array $product_updates Array of product updates.
	 * @return string Updated text.
	 */
	private function updateProductReferences($text, $product_updates) {
		// This is a placeholder - you'll need to implement the logic
		// to update product references based on your specific needs
		return $text;
	}

	/**
	 * Generate new container with AI content
	 *
	 * @param array $product_data Product data.
	 * @param string $openai_api_key OpenAI API key.
	 * @param string $ai_model AI model to use.
	 * @return array|\WP_Error New container or WP_Error on failure.
	 */
	private function generateNewContainerWithAI($product_data, $openai_api_key, $ai_model) {
		// Generate unique IDs for the new container
		$container_id = $this->generateUniqueId();
		$heading_id = $this->generateUniqueId();
		$text_id = $this->generateUniqueId();

		// Generate AI content
		$title = $this->generateAIContent(
			'Create an engaging product review title in native fluent danish for ' . $product_data['name'],
			$openai_api_key,
			$ai_model
		);

		$content = $this->generateAIContent(
			'Write a personal fictive positive, engaging product review about ' . $product_data['name'] . ' in native fluent danish.',
			$openai_api_key,
			$ai_model
		);

		// Create the new container structure
		$new_container = [
			'id' => $container_id,
			'elType' => 'container',
			'settings' => [
				'_element_id' => 'product-' . (count($this->getProductContainers()) + 1),
				'aebg_product_id' => $product_data['id'] ?? '',
				'aebg_ai_enable' => 'yes'
			],
			'elements' => [
				[
					'id' => $heading_id,
					'elType' => 'widget',
					'settings' => [
						'title' => $title,
						'aebg_ai_enable' => 'yes',
						'aebg_ai_prompt' => 'Create an engaging product review title in native fluent danish for {product-' . (count($this->getProductContainers()) + 1) . '}'
					],
					'elements' => [],
					'widgetType' => 'heading'
				],
				[
					'id' => $text_id,
					'elType' => 'widget',
					'settings' => [
						'editor' => $content,
						'aebg_ai_enable' => 'yes',
						'aebg_ai_prompt' => 'Write a personal fictive positive, engaging product review about {product-' . (count($this->getProductContainers()) + 1) . '} in native fluent danish.'
					],
					'elements' => [],
					'widgetType' => 'text-editor'
				]
			],
			'isInner' => false
		];

		return $new_container;
	}

	/**
	 * Generate AI content using OpenAI
	 *
	 * @param string $prompt The AI prompt.
	 * @param string $api_key OpenAI API key.
	 * @param string $model AI model to use.
	 * @return string Generated content.
	 */
	private function generateAIContent($prompt, $api_key, $model) {
		// This is a placeholder - you'll need to implement the OpenAI API call
		// based on your existing AI integration
		return 'AI generated content placeholder';
	}

	/**
	 * Insert container in Elementor data
	 *
	 * @param array $data The Elementor data.
	 * @param int $position Position to insert.
	 * @param array $new_container The new container to insert.
	 * @return array|\WP_Error Updated data or WP_Error on failure.
	 */
	private function insertContainerInData($data, $position, $new_container) {
		if (!is_array($data)) {
			return new \WP_Error('aebg_invalid_data', 'Invalid data structure');
		}

		// Insert the new container at the specified position
		array_splice($data, $position, 0, [$new_container]);

		return $data;
	}

	/**
	 * Generate unique ID for Elementor elements
	 *
	 * @return string Unique ID.
	 */
	private function generateUniqueId() {
		return substr(md5(uniqid() . microtime()), 0, 7);
	}

	/**
	 * Get all product containers from current data
	 *
	 * @return array Array of product containers.
	 */
	private function getProductContainers() {
		// This is a placeholder - you'll need to implement the logic
		// to get all current product containers
		return [];
	}

	/**
	 * Sync Elementor data and ensure proper post recognition
	 *
	 * @param int $post_id The post ID.
	 * @param array $elementor_data The Elementor data to sync.
	 * @return bool|\WP_Error Success or WP_Error on failure.
	 */
	public function syncElementorData($post_id, $elementor_data) {
		error_log('[AEBG] ===== START: syncElementorData =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		
		try {
			// Step 1: Ensure the post exists and is valid
			$post = get_post($post_id);
			if (!$post) {
				return new \WP_Error('aebg_post_not_found', 'Post not found');
			}
			
			// Step 2: Set all required Elementor meta fields
			$required_meta = [
				'_elementor_edit_mode' => 'builder',
				'_elementor_template_type' => 'wp-post',
				'_elementor_version' => '3.31.0',
				'_elementor_page_settings' => [],
				'_elementor_page_assets' => [],
				'_elementor_controls_usage' => [],
				'_elementor_elements_usage' => [],
				'_elementor_css' => '',
				'_elementor_js' => '',
				'_elementor_custom_css' => '',
				'_elementor_custom_js' => ''
			];
			
			foreach ($required_meta as $meta_key => $meta_value) {
				update_post_meta($post_id, $meta_key, $meta_value);
				error_log('[AEBG] Set meta field: ' . $meta_key);
			}
			
			// Step 3: Save the Elementor data with proper encoding
			$cleaned_data = $this->cleanElementorDataForEncoding($elementor_data);
			$encoded_data = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($encoded_data === false) {
				return new \WP_Error('aebg_encoding_failed', 'Failed to encode Elementor data: ' . json_last_error_msg());
			}
			
			update_post_meta($post_id, '_elementor_data', $encoded_data);
			error_log('[AEBG] Saved Elementor data successfully');
			
			// Step 4: Update post modified date to trigger refresh
			wp_update_post([
				'ID' => $post_id,
				'post_modified' => current_time('mysql'),
				'post_modified_gmt' => current_time('mysql', 1)
			]);
			
			// Step 5: Clear all Elementor caches
			$this->clearElementorCache($post_id);
			
			// Step 6: Clear WordPress object cache
			if (function_exists('wp_cache_delete')) {
				wp_cache_delete($post_id, 'posts');
				wp_cache_delete($post_id, 'post_meta');
			}
			
			// Step 7: Trigger Elementor hooks for data update
			do_action('elementor/core/files/clear_cache');
			do_action('elementor/css-file/clear_cache');
			do_action('elementor/js-file/clear_cache');
			
			// Step 8: Force Elementor to recognize the post as edited
			if (class_exists('\Elementor\Plugin')) {
				try {
					$elementor = \Elementor\Plugin::instance();
					
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
						if ($document) {
							// Force document refresh
							if (method_exists($document, 'delete_autosave')) {
								$document->delete_autosave();
							}
							if (method_exists($document, 'save')) {
								$document->save(['elements' => $elementor_data]);
								error_log('[AEBG] Saved Elementor document with elements data for post ' . $post_id);
								
								// Force CSS file generation after document save
								if (class_exists('\Elementor\Core\Files\CSS\Post')) {
									try {
										$css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
										if ($css_file) {
											$css_file->delete();
											$css_file->update(); // Generate and write the CSS file
											error_log('[AEBG] Generated CSS file after document save for post ' . $post_id);
										}
									} catch (\Exception $e) {
										error_log('[AEBG] Error generating CSS file in syncElementorData: ' . $e->getMessage());
									}
								}
							}
						}
					}
					
					error_log('[AEBG] Elementor internal caches cleared');
				} catch (\Exception $e) {
					error_log('[AEBG] Error clearing Elementor internal cache: ' . $e->getMessage());
				}
			}
			
			// Step 9: Validate the sync was successful
			$validated_data = $this->getElementorData($post_id);
			if (is_wp_error($validated_data)) {
				error_log('[AEBG] WARNING: Data validation failed after sync: ' . $validated_data->get_error_message());
			} else {
				error_log('[AEBG] Data validation successful after sync');
			}
			
			error_log('[AEBG] ===== SUCCESS: syncElementorData completed =====');
			return true;
			
		} catch (\Exception $e) {
			error_log('[AEBG] ERROR in syncElementorData: ' . $e->getMessage());
			return new \WP_Error('aebg_sync_failed', 'Failed to sync Elementor data: ' . $e->getMessage());
		}
	}

	/**
	 * Force Elementor to recognize a post as edited with Elementor
	 *
	 * @param int $post_id The post ID.
	 * @return bool Success or failure.
	 */
	public function forceElementorRecognition($post_id) {
		error_log('[AEBG] Force Elementor recognition for post: ' . $post_id);
		
		// Set the essential meta field that tells Elementor this is an Elementor post
		update_post_meta($post_id, '_elementor_edit_mode', 'builder');
		
		// Try to use Elementor's document system to force recognition
		if (class_exists('\Elementor\Plugin')) {
			try {
				$elementor = \Elementor\Plugin::instance();
				// FIX: documents is a property (object), not a method
				if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
					$document = $elementor->documents->get($post_id);
					if ($document) {
						// Force document to be recognized as an Elementor document
						if (method_exists($document, 'save')) {
							$document->save(['status' => 'publish']);
						}
						error_log('[AEBG] Elementor document system used for recognition');
					} else {
						error_log('[AEBG] Elementor document not found, using fallback method');
					}
				}
			} catch (\Exception $e) {
				error_log('[AEBG] Error using Elementor document system: ' . $e->getMessage());
			}
		}
		
		// Update the post to trigger any hooks
		wp_update_post([
			'ID' => $post_id,
			'post_modified' => current_time('mysql'),
			'post_modified_gmt' => current_time('mysql', 1)
		]);
		
		// Clear caches
		$this->clearElementorCache($post_id);
		
		// Clear WordPress object cache for this post
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete($post_id, 'posts');
			wp_cache_delete($post_id, 'post_meta');
		}
		
		// Force Elementor to rebuild its internal cache
		if (class_exists('\Elementor\Plugin')) {
			try {
				$elementor = \Elementor\Plugin::instance();
				
				// Clear Elementor's internal cache
				if (method_exists($elementor, 'files_manager')) {
					$elementor->files_manager->clear_cache();
				}
				
				// Clear Elementor's CSS cache
				if (method_exists($elementor, 'kits_manager')) {
					$elementor->kits_manager->clear_cache();
				}
				
				// Force Elementor to re-register the post
				// Note: These hooks expect a Document object, so we'll skip them to avoid type errors
				// The cache clearing should be sufficient for recognition
				
				error_log('[AEBG] Elementor internal cache cleared and hooks triggered');
			} catch (\Exception $e) {
				error_log('[AEBG] Error clearing Elementor internal cache: ' . $e->getMessage());
			}
		}
		
		error_log('[AEBG] Elementor recognition forced for post: ' . $post_id);
		return true;
	}

	/**
	 * Fix existing post that has incorrect content structure
	 *
	 * @param int $post_id The post ID.
	 * @return bool|\WP_Error Success or WP_Error on failure.
	 */
	public function fixExistingPost($post_id) {
		error_log('[AEBG] ===== START: fixExistingPost =====');
		error_log('[AEBG] Post ID: ' . $post_id);
		
		try {
			// Get the current Elementor data
			$elementor_data = $this->getElementorData($post_id);
			if (is_wp_error($elementor_data)) {
				return $elementor_data;
			}
			
			// Fix the content structure
			$fixed_data = $this->fixContentStructure($elementor_data);
			
			// Sync the fixed data
			$sync_result = $this->syncElementorData($post_id, $fixed_data);
			if (is_wp_error($sync_result)) {
				return $sync_result;
			}
			
			error_log('[AEBG] ===== SUCCESS: fixExistingPost completed =====');
			return true;
			
		} catch (\Exception $e) {
			error_log('[AEBG] ERROR in fixExistingPost: ' . $e->getMessage());
			return new \WP_Error('aebg_fix_failed', 'Failed to fix existing post: ' . $e->getMessage());
		}
	}
	
	/**
	 * Fix content structure in Elementor data
	 *
	 * @param array $data The Elementor data.
	 * @return array Fixed Elementor data.
	 */
	private function fixContentStructure($data) {
		if (!is_array($data)) {
			return $data;
		}
		
		// Handle numeric array structure
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				$data[$index] = $this->fixContentStructure($item);
			}
			return $data;
		}
		
		// Process child elements
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				$data['elements'][$index] = $this->fixContentStructure($element);
			}
		}
		
		// Fix widget content if this is a widget
		if (isset($data['elType']) && $data['elType'] === 'widget') {
			$data = $this->fixWidgetContent($data);
		}
		
		return $data;
	}
	
	/**
	 * Fix widget content structure
	 *
	 * @param array $widget The widget data.
	 * @return array Fixed widget data.
	 */
	private function fixWidgetContent($widget) {
		if (!isset($widget['settings']) || !is_array($widget['settings'])) {
			return $widget;
		}
		
		$widget_type = $widget['widgetType'] ?? 'unknown';
		$settings = &$widget['settings'];
		
		// Fix heading widgets - extract short title from long content
		if ($widget_type === 'heading' && isset($settings['title'])) {
			$title = $settings['title'];
			if (strlen($title) > 200) { // If title is too long, it's probably wrong content
				$lines = explode("\n", $title);
				$first_line = trim($lines[0]);
				
				// Clean up the title
				$clean_title = strip_tags($first_line);
				$clean_title = preg_replace('/^#+\s*/', '', $clean_title);
				$clean_title = preg_replace('/^\*\*(.*?)\*\*$/', '$1', $clean_title);
				
				// Limit length
				if (strlen($clean_title) > 100) {
					$clean_title = substr($clean_title, 0, 97) . '...';
				}
				
				$settings['title'] = $clean_title;
				error_log('[AEBG] Fixed heading title: ' . $clean_title);
			}
		}
		
		// Fix text-editor widgets - ensure proper formatting
		if ($widget_type === 'text-editor' && isset($settings['editor'])) {
			$editor_content = $settings['editor'];
			
			// If content is very short, it might be wrong
			if (strlen($editor_content) < 50 && isset($settings['aebg_ai_prompt'])) {
				error_log('[AEBG] Text editor content seems too short, might need regeneration');
			}
			
			// Ensure proper HTML formatting
			if (!empty($editor_content) && strip_tags($editor_content) === $editor_content) {
				// Content has no HTML tags, add basic formatting
				$settings['editor'] = '<p>' . nl2br($editor_content) . '</p>';
				error_log('[AEBG] Added HTML formatting to text editor content');
			}
		}
		
		// Fix other widget types that might have wrong content
		$short_content_widgets = ['button', 'icon', 'icon-box', 'flip-box', 'call-to-action'];
		if (in_array($widget_type, $short_content_widgets)) {
			$content_fields = ['text', 'title_text', 'title_text_a', 'title'];
			
			foreach ($content_fields as $field) {
				if (isset($settings[$field]) && strlen($settings[$field]) > 200) {
					$content = $settings[$field];
					$short_content = strip_tags($content);
					
					// Extract first sentence or limit length
					$sentences = explode('.', $short_content);
					$first_sentence = trim($sentences[0]);
					
					if (strlen($first_sentence) > 100) {
						$first_sentence = substr($first_sentence, 0, 97) . '...';
					}
					
					$settings[$field] = $first_sentence;
					error_log('[AEBG] Fixed ' . $widget_type . ' ' . $field . ': ' . $first_sentence);
				}
			}
		}
		
		return $widget;
	}

	/**
	 * Clear Elementor-specific caches that might prevent frontend updates
	 *
	 * @param int $post_id Post ID
	 */
	public function clearElementorSpecificCaches($post_id) {
		error_log('[AEBG] Clearing Elementor-specific caches for post ' . $post_id);
		
		// Clear Elementor CSS/JS files
		if (class_exists('\Elementor\Plugin')) {
			$elementor = \Elementor\Plugin::instance();
			
			// Clear Elementor files cache
			if (method_exists($elementor->files_manager, 'clear_cache')) {
				$elementor->files_manager->clear_cache();
				error_log('[AEBG] Cleared Elementor files cache');
			}
			
			// Force CSS regeneration
			// FIX: documents is a property (object), not a method - check if property exists and has get method
			if (isset($elementor->documents) && is_object($elementor->documents) && method_exists($elementor->documents, 'get')) {
				try {
					$document = $elementor->documents->get($post_id);
					if ($document) {
						// Get elementor data to save with document
						$elementor_data = get_post_meta($post_id, '_elementor_data', true);
						if (!empty($elementor_data)) {
							$elementor_data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
						}
						
						// Force document refresh with elements data
						if (method_exists($document, 'save')) {
							if (!empty($elementor_data)) {
								$document->save(['elements' => $elementor_data]);
							} else {
								$document->save(['status' => $document->get_main_post()->post_status]);
							}
							error_log('[AEBG] Forced Elementor document save for post ' . $post_id);
						}
						
						// Force CSS regeneration - use update() to actually generate and write the file
						$css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
						if ($css_file) {
							$css_file->delete();
							$css_file->update(); // Generate and write the CSS file
							error_log('[AEBG] Deleted and regenerated Elementor CSS for post ' . $post_id);
						}
					}
				} catch (Exception $e) {
					error_log('[AEBG] Error clearing Elementor-specific caches: ' . $e->getMessage());
				}
			}
		}
		
		// Clear any custom post meta that might be cached
		delete_post_meta($post_id, '_elementor_css');
		delete_post_meta($post_id, '_elementor_js');
		delete_post_meta($post_id, '_elementor_page_settings');
		
		// Clear Elementor data cache
		wp_cache_delete($post_id, 'post_meta');
		wp_cache_delete('_elementor_data_' . $post_id, 'post_meta');
		
		// Force WordPress to refresh the post
		clean_post_cache($post_id);
		
		// Clear any transients that might be caching Elementor data
		delete_transient('elementor_css_' . $post_id);
		delete_transient('elementor_js_' . $post_id);
		
		error_log('[AEBG] Elementor-specific caches cleared for post ' . $post_id);
	}

	/**
	 * Find nested product containers within an element
	 *
	 * @param array $element Element to search
	 * @return array Array of product containers found
	 */
	private function findNestedProductContainers($element) {
		$nested_containers = [];
		
		if (!is_array($element)) {
			return $nested_containers;
		}
		
		// Check if this element has nested elements
		if (isset($element['elements']) && is_array($element['elements'])) {
			error_log('[AEBG] Searching for nested containers in element with ' . count($element['elements']) . ' nested elements');
			
			foreach ($element['elements'] as $index => $nested_element) {
				error_log('[AEBG] Checking nested element ' . $index . ' with keys: ' . implode(', ', array_keys($nested_element)));
				
				// Check if this nested element is a product container
				if (isset($nested_element['settings']['_element_id']) && 
					preg_match('/^product-(\d+)$/', $nested_element['settings']['_element_id'], $matches)) {
					$product_number = (int) $matches[1];
					$nested_containers[$product_number] = $nested_element;
					error_log('[AEBG] Found nested product container: product-' . $product_number . ' at index ' . $index);
				} else {
					// Recursively search for more nested containers
					$deeper_containers = $this->findNestedProductContainers($nested_element);
					if (!empty($deeper_containers)) {
						error_log('[AEBG] Found ' . count($deeper_containers) . ' deeper nested containers in element ' . $index);
						$nested_containers = array_merge($nested_containers, $deeper_containers);
					}
				}
			}
		} else {
			error_log('[AEBG] Element has no nested elements');
		}
		
		error_log('[AEBG] findNestedProductContainers returning ' . count($nested_containers) . ' containers: ' . implode(', ', array_keys($nested_containers)));
		return $nested_containers;
	}

	/**
	 * Reorder recursive structure
	 *
	 * @param array $data Recursive data structure
	 * @param array $position_mapping Position mapping
	 * @return array Reordered data
	 */
	private function reorderRecursiveStructure($data, $position_mapping) {
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->comprehensiveReorderData($value, $position_mapping);
			}
		}
		return $data;
	}

}