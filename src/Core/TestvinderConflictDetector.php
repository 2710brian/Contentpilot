<?php

namespace AEBG\Core;

use AEBG\Core\Logger;

/**
 * Testvinder Conflict Detector
 * 
 * Detects conflicts when products are reordered to positions
 * that already have testvinder containers.
 * 
 * Pure function - no side effects, no database writes.
 * 
 * @package AEBG\Core
 */
class TestvinderConflictDetector {
	
	/**
	 * Detect conflicts for product reordering
	 * 
	 * @param array $elementor_data Current Elementor data
	 * @param array $position_mapping Position mapping [old_position => new_position]
	 * @param array $current_products Current products array
	 * @return array Array of conflicts
	 */
	public static function detectConflicts(
		array $elementor_data,
		array $position_mapping,
		array $current_products
	): array {
		Logger::info('Detecting testvinder conflicts', [
			'position_mapping_count' => count($position_mapping),
			'products_count' => count($current_products),
		]);
		
		$conflicts = [];
		$testvinder_containers = self::findTestvinderContainers($elementor_data);
		
		if (empty($testvinder_containers)) {
			Logger::debug('No testvinder containers found', []);
			return $conflicts;
		}
		
		Logger::debug('Found testvinder containers', [
			'count' => count($testvinder_containers),
			'containers' => array_keys($testvinder_containers),
		]);
		
		foreach ($position_mapping as $old_position => $new_position) {
			// Check if target position has testvinder container
			$testvinder_key = 'testvinder-' . $new_position;
			
			if (isset($testvinder_containers[$testvinder_key])) {
				// Get product info
				$product_index = $old_position - 1;
				$product = $current_products[$product_index] ?? null;
				
				if ($product) {
					$product_id = is_array($product) ? ($product['id'] ?? '') : $product;
					$product_name = is_array($product) ? ($product['name'] ?? $product['display_name'] ?? 'Unknown') : 'Unknown';
					
					$conflicts[] = [
						'product_id' => $product_id,
						'product_name' => $product_name,
						'old_position' => (int)$old_position,
						'new_position' => (int)$new_position,
						'testvinder_exists' => true,
						'testvinder_css_id' => $testvinder_key,
					];
					
					Logger::info('Conflict detected', [
						'product_id' => $product_id,
						'product_name' => $product_name,
						'old_position' => $old_position,
						'new_position' => $new_position,
						'testvinder_css_id' => $testvinder_key,
					]);
				}
			}
		}
		
		Logger::info('Conflict detection complete', [
			'conflicts_count' => count($conflicts),
		]);
		
		return $conflicts;
	}
	
	/**
	 * Find all testvinder containers in Elementor data
	 * 
	 * @param array $elementor_data Elementor data
	 * @return array Map of CSS ID => container info
	 */
	private static function findTestvinderContainers(array $elementor_data): array {
		$containers = [];
		self::recursiveFindTestvinder($elementor_data, $containers);
		return $containers;
	}
	
	/**
	 * Recursively find testvinder containers
	 * 
	 * @param array $data Elementor data
	 * @param array &$containers Reference to containers array
	 * @param string $path Current path in structure
	 * @return void
	 */
	private static function recursiveFindTestvinder(
		array $data,
		array &$containers,
		string $path = ''
	): void {
		if (!is_array($data)) {
			return;
		}
		
		// Handle numeric array structure (top-level array of elements)
		if (array_keys($data) === range(0, count($data) - 1)) {
			foreach ($data as $index => $item) {
				if (is_array($item)) {
					$new_path = $path ? $path . '.' . $index : (string) $index;
					self::recursiveFindTestvinder($item, $containers, $new_path);
				}
			}
			return;
		}
		
		// Check for testvinder CSS ID
		if (isset($data['settings'])) {
			$settings = $data['settings'];
			$css_id = $settings['_element_id'] ?? $settings['_css_id'] ?? $settings['css_id'] ?? '';
			
			if (preg_match('/^testvinder-(\d+)$/i', $css_id, $matches)) {
				$product_number = (int)$matches[1];
				$containers[$css_id] = [
					'css_id' => $css_id,
					'product_number' => $product_number,
					'path' => $path,
					'container' => $data,
				];
				
				Logger::debug('Found testvinder container', [
					'css_id' => $css_id,
					'product_number' => $product_number,
					'path' => $path,
				]);
			}
		}
		
		// Recursively check children
		if (isset($data['elements']) && is_array($data['elements'])) {
			foreach ($data['elements'] as $index => $element) {
				if (is_array($element)) {
					$new_path = $path ? $path . '.elements.' . $index : 'elements.' . $index;
					self::recursiveFindTestvinder($element, $containers, $new_path);
				}
			}
		}
		
		if (isset($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $index => $content_item) {
				if (is_array($content_item)) {
					$new_path = $path ? $path . '.content.' . $index : 'content.' . $index;
					self::recursiveFindTestvinder($content_item, $containers, $new_path);
				}
			}
		}
	}
}

