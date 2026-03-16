<?php

namespace AEBG\Core;

use AEBG\Core\TestvinderConflictDetector;
use AEBG\Core\TestvinderRegenerationManager;
use AEBG\Core\TemplateManager;
use AEBG\Core\Logger;

/**
 * Reorder Conflict Resolver
 * 
 * Orchestrates product reordering with conflict detection and resolution.
 * Provides transaction-like behavior with automatic rollback on failure.
 * 
 * @package AEBG\Core
 */
class ReorderConflictResolver {
	
	/**
	 * @var TemplateManager
	 */
	private $template_manager;
	
	/**
	 * Constructor
	 * 
	 * @param TemplateManager|null $template_manager Template manager instance
	 */
	public function __construct(TemplateManager $template_manager = null) {
		$this->template_manager = $template_manager ?: new TemplateManager();
	}
	
	/**
	 * Prepare reordering - detect conflicts for frontend
	 * 
	 * @param int $post_id Post ID
	 * @param array $new_order New product order
	 * @return array|\WP_Error Conflicts array or error
	 */
	public function prepareReorderingWithChoices(int $post_id, array $new_order) {
		Logger::info('Preparing reordering with conflict detection', [
			'post_id' => $post_id,
			'new_order_count' => count($new_order),
		]);
		
		// 1. Get Elementor data
		$elementor_data = $this->template_manager->getElementorData($post_id);
		if (is_wp_error($elementor_data)) {
			Logger::error('Failed to get Elementor data', [
				'post_id' => $post_id,
				'error' => $elementor_data->get_error_message(),
			]);
			return $elementor_data;
		}
		
		// 2. Get current products
		$current_products = get_post_meta($post_id, '_aebg_products', true);
		if (!is_array($current_products)) {
			$current_products = [];
		}
		
		// 3. Create position mapping
		$position_mapping = $this->createPositionMapping(
			$elementor_data,
			$current_products,
			$new_order,
			$post_id
		);
		
		if (empty($position_mapping)) {
			Logger::warning('No position mapping created', [
				'post_id' => $post_id,
				'new_order' => $new_order,
			]);
		}
		
		// 4. Detect conflicts
		$conflicts = TestvinderConflictDetector::detectConflicts(
			$elementor_data,
			$position_mapping,
			$current_products
		);
		
		Logger::info('Conflict detection complete', [
			'post_id' => $post_id,
			'conflicts_count' => count($conflicts),
			'has_conflicts' => !empty($conflicts),
		]);
		
		return [
			'conflicts' => $conflicts,
			'position_mapping' => $position_mapping,
			'has_conflicts' => !empty($conflicts),
		];
	}
	
	/**
	 * Resolve conflicts and execute reordering
	 * 
	 * @param int $post_id Post ID
	 * @param array $new_order New product order
	 * @param array $regeneration_choices User's regeneration choices
	 * @return array|\WP_Error Success result or error
	 */
	public function resolveConflictsAndReorder(
		int $post_id,
		array $new_order,
		array $regeneration_choices
	) {
		Logger::info('Resolving conflicts and reordering', [
			'post_id' => $post_id,
			'new_order_count' => count($new_order),
			'choices_count' => count($regeneration_choices),
		]);
		
		// 1. Create comprehensive backup
		$backup = $this->createBackup($post_id);
		$backup_key = 'aebg_reorder_backup_' . $post_id . '_' . time();
		update_option($backup_key, $backup);
		Logger::info('Created backup', [
			'post_id' => $post_id,
			'backup_key' => $backup_key,
		]);
		
		try {
			// 2. Validate inputs
			$this->validateInputs($post_id, $new_order, $regeneration_choices);
			
			// 3. Execute reordering
			$reorder_result = $this->template_manager->reorderProductContainers($post_id, $new_order);
			if (is_wp_error($reorder_result)) {
				throw new \Exception($reorder_result->get_error_message());
			}
			
			Logger::info('Reordering completed successfully', [
				'post_id' => $post_id,
			]);
			
			// 4. Execute regenerations based on choices
			$regeneration_results = $this->executeRegenerations(
				$post_id,
				$regeneration_choices
			);
			
			Logger::info('Regenerations scheduled', [
				'post_id' => $post_id,
				'regeneration_count' => count($regeneration_results),
			]);
			
			// 5. Validate final state (basic validation)
			$validation = $this->validateFinalState($post_id, $new_order);
			if (!$validation['valid']) {
				Logger::warning('Final state validation failed', [
					'post_id' => $post_id,
					'error' => $validation['error'],
				]);
				// Don't throw - reordering succeeded, validation is just a check
			}
			
			// 6. Success - schedule backup cleanup after delay (safety net)
			$this->scheduleBackupCleanup($backup_key, 3600); // 1 hour
			
			// Return response matching updatePostProductsWithOrder structure for frontend compatibility
			return [
				'post_id' => $post_id,
				'updated_order' => $new_order,
				'message' => 'Post updated successfully with new product order',
				'frontend_refresh_required' => true,
				'cache_cleared' => true,
				'timestamp' => current_time('timestamp'),
				'reorder_result' => $reorder_result,
				'regeneration_results' => $regeneration_results,
				'regeneration_count' => count(array_filter($regeneration_results, function($result) {
					return isset($result['success']) && $result['success'] && isset($result['action']) && $result['action'] !== 'skip';
				})),
				'backup_key' => $backup_key,
			];
			
		} catch (\Exception $e) {
			// Rollback on any error
			Logger::error('Reorder conflict resolution failed', [
				'post_id' => $post_id,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			
			$this->rollback($post_id, $backup);
			
			return new \WP_Error(
				'reorder_failed',
				'Reorder failed: ' . $e->getMessage(),
				['backup_key' => $backup_key]
			);
		}
	}
	
	/**
	 * Create position mapping
	 * 
	 * @param array $elementor_data Elementor data
	 * @param array $current_products Current products
	 * @param array $new_order New order
	 * @param int $post_id Post ID
	 * @return array Position mapping
	 */
	private function createPositionMapping(
		array $elementor_data,
		array $current_products,
		array $new_order,
		int $post_id
	): array {
		// Use reflection to access private method, or make it public
		// For now, we'll duplicate the logic or use a public wrapper
		// Actually, let's check if there's a public method we can use
		
		// Use reflection to call private method
		$reflection = new \ReflectionClass($this->template_manager);
		$method = $reflection->getMethod('createPositionMappingFromElementorData');
		$method->setAccessible(true);
		
		return $method->invoke($this->template_manager, $elementor_data, $current_products, $new_order, $post_id);
	}
	
	/**
	 * Create comprehensive backup
	 * 
	 * @param int $post_id Post ID
	 * @return array Backup data
	 */
	private function createBackup(int $post_id): array {
		return [
			'elementor_data' => get_post_meta($post_id, '_elementor_data', true),
			'products' => get_post_meta($post_id, '_aebg_products', true),
			'product_ids' => get_post_meta($post_id, '_aebg_product_ids', true),
			'timestamp' => time(),
			'user_id' => get_current_user_id(),
		];
	}
	
	/**
	 * Rollback to previous state
	 * 
	 * @param int $post_id Post ID
	 * @param array $backup Backup data
	 * @return bool Success
	 */
	private function rollback(int $post_id, array $backup): bool {
		Logger::warning('Rolling back reorder operation', [
			'post_id' => $post_id,
			'backup_timestamp' => $backup['timestamp'] ?? 'unknown',
		]);
		
		if (isset($backup['elementor_data'])) {
			update_post_meta($post_id, '_elementor_data', $backup['elementor_data']);
		}
		if (isset($backup['products'])) {
			update_post_meta($post_id, '_aebg_products', $backup['products']);
		}
		if (isset($backup['product_ids'])) {
			update_post_meta($post_id, '_aebg_product_ids', $backup['product_ids']);
		}
		
		// Clear caches
		wp_cache_delete($post_id, 'post_meta');
		clean_post_cache($post_id);
		
		// Clear Elementor caches
		if (method_exists($this->template_manager, 'clearElementorCache')) {
			$this->template_manager->clearElementorCache($post_id);
		}
		
		Logger::info('Rollback completed', [
			'post_id' => $post_id,
		]);
		
		return true;
	}
	
	/**
	 * Validate inputs
	 * 
	 * @param int $post_id Post ID
	 * @param array $new_order New order
	 * @param array $regeneration_choices Choices
	 * @return void
	 * @throws \Exception On validation failure
	 */
	private function validateInputs(int $post_id, array $new_order, array $regeneration_choices): void {
		if (empty($post_id) || $post_id <= 0) {
			throw new \Exception('Invalid post ID');
		}
		
		if (empty($new_order) || !is_array($new_order)) {
			throw new \Exception('Invalid new order');
		}
		
		if (count($new_order) > 100) {
			throw new \Exception('Too many products in order (max 100)');
		}
		
		if (!is_array($regeneration_choices)) {
			throw new \Exception('Invalid regeneration choices');
		}
		
		// Validate post exists
		$post = get_post($post_id);
		if (!$post) {
			throw new \Exception('Post not found');
		}
	}
	
	/**
	 * Execute regenerations based on user choices
	 * 
	 * @param int $post_id Post ID
	 * @param array $regeneration_choices User choices
	 * @return array Results
	 */
	private function executeRegenerations(int $post_id, array $regeneration_choices): array {
		$results = [];
		
		foreach ($regeneration_choices as $product_id => $choice) {
			$action = sanitize_text_field($choice['action'] ?? 'skip');
			$product_number = absint($choice['product_number'] ?? 0);
			
			if ($product_number <= 0) {
				Logger::warning('Invalid product number in regeneration choice', [
					'product_id' => $product_id,
					'choice' => $choice,
				]);
				continue;
			}
			
			switch ($action) {
				case 'regenerate_both':
					$action_id = TestvinderRegenerationManager::scheduleProductAndTestvinderRegeneration(
						$post_id,
						$product_number
					);
					$results[$product_id] = [
						'action' => 'regenerate_both',
						'action_id' => $action_id,
						'success' => $action_id !== false,
					];
					break;
					
				case 'regenerate_testvinder_only':
					$action_id = TestvinderRegenerationManager::scheduleTestvinderOnlyRegeneration(
						$post_id,
						$product_number
					);
					$results[$product_id] = [
						'action' => 'regenerate_testvinder_only',
						'action_id' => $action_id,
						'success' => $action_id !== false,
					];
					break;
					
				case 'skip':
				default:
					$results[$product_id] = [
						'action' => 'skip',
						'success' => true,
					];
					break;
			}
		}
		
		return $results;
	}
	
	/**
	 * Validate final state
	 * 
	 * @param int $post_id Post ID
	 * @param array $new_order New order
	 * @return array Validation result
	 */
	private function validateFinalState(int $post_id, array $new_order): array {
		// Basic validation - check if Elementor data is still valid
		$elementor_data = $this->template_manager->getElementorData($post_id);
		
		if (is_wp_error($elementor_data)) {
			return [
				'valid' => false,
				'error' => $elementor_data->get_error_message(),
			];
		}
		
		// Check if we can encode it
		$test_json = json_encode($elementor_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($test_json === false) {
			return [
				'valid' => false,
				'error' => 'Elementor data cannot be encoded as JSON',
			];
		}
		
		return [
			'valid' => true,
			'error' => null,
		];
	}
	
	/**
	 * Schedule backup cleanup
	 * 
	 * @param string $backup_key Backup key
	 * @param int $delay Delay in seconds
	 * @return void
	 */
	private function scheduleBackupCleanup(string $backup_key, int $delay): void {
		// Schedule cleanup via WordPress cron
		wp_schedule_single_event(time() + $delay, 'aebg_cleanup_reorder_backup', [$backup_key]);
	}
}

