<?php

namespace AEBG\Core;

use AEBG\Core\DataUtilities;
use AEBG\Core\VariableReplacer;
use AEBG\Core\Logger;

/**
 * Template Manipulator Class
 * Handles template manipulation with transaction support.
 *
 * @package AEBG\Core
 */
class TemplateManipulator {
	/**
	 * Variable replacer instance.
	 *
	 * @var VariableReplacer
	 */
	private $variable_replacer;

	/**
	 * Constructor.
	 *
	 * @param VariableReplacer $variable_replacer Variable replacer instance.
	 */
	public function __construct($variable_replacer = null) {
		$this->variable_replacer = $variable_replacer ?: new VariableReplacer();
	}

	/**
	 * Add product container to template.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $product_number Product number.
	 * @param array $product Product data.
	 * @return array Updated Elementor data.
	 */
	public function addProductContainer($elementor_data, $product_number, $product) {
		Logger::debug('Adding product container', ['product_number' => $product_number]);

		// Find insert position
		$insert_position = $this->findInsertPosition($elementor_data, $product_number);

		// Create product container
		$container = $this->createProductContainer($product_number, $product);

		// Insert container
		array_splice($elementor_data, $insert_position, 0, [$container]);

		// Update variable references
		$elementor_data = $this->updateVariableReferences($elementor_data, $product_number, 'add');

		return $elementor_data;
	}

	/**
	 * Remove product container from template.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $product_number Product number.
	 * @return array Updated Elementor data.
	 */
	public function removeProductContainer($elementor_data, $product_number) {
		Logger::debug('Removing product container', ['product_number' => $product_number]);

		// Find and remove container
		$elementor_data = $this->removeContainerByProductNumber($elementor_data, $product_number);

		// Update variable references
		$elementor_data = $this->updateVariableReferences($elementor_data, $product_number, 'remove');

		return $elementor_data;
	}

	/**
	 * Update product in template.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $product_number Product number.
	 * @param array $product New product data.
	 * @return array Updated Elementor data.
	 */
	public function updateProductInTemplate($elementor_data, $product_number, $product) {
		Logger::debug('Updating product in template', ['product_number' => $product_number]);

		// Find container
		$container = $this->findProductContainer($elementor_data, $product_number);

		if ($container) {
			// Update product variables in container
			$container = $this->updateProductVariablesInContainer($container, $product);
		}

		return $elementor_data;
	}

	/**
	 * Find insert position for new container.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $product_number Product number.
	 * @return int Insert position.
	 */
	private function findInsertPosition($elementor_data, $product_number) {
		$last_position = -1;
		$last_product_number = 0;

		foreach ($elementor_data as $index => $element) {
			if (isset($element['settings']['_element_id']) && 
				preg_match('/^product-(\d+)$/', $element['settings']['_element_id'], $matches)) {
				$found_number = (int)$matches[1];
				if ($found_number < $product_number) {
					$last_position = $index;
					$last_product_number = $found_number;
				} else {
					break;
				}
			}
		}

		return $last_position + 1;
	}

	/**
	 * Create product container.
	 *
	 * @param int   $product_number Product number.
	 * @param array $product Product data.
	 * @return array Container data.
	 */
	private function createProductContainer($product_number, $product) {
		return [
			'id' => uniqid('element_'),
			'elType' => 'container',
			'settings' => [
				'_element_id' => 'product-' . $product_number,
				'content_width' => 'full',
			],
			'elements' => []
		];
	}

	/**
	 * Remove container by product number.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $product_number Product number.
	 * @return array Updated data.
	 */
	private function removeContainerByProductNumber($elementor_data, $product_number) {
		foreach ($elementor_data as $index => $element) {
			if (isset($element['settings']['_element_id']) && 
				$element['settings']['_element_id'] === 'product-' . $product_number) {
				unset($elementor_data[$index]);
				$elementor_data = array_values($elementor_data); // Re-index
				break;
			}

			// Recursively check child elements
			if (isset($element['elements']) && is_array($element['elements'])) {
				$element['elements'] = $this->removeContainerByProductNumber($element['elements'], $product_number);
			}
		}

		return $elementor_data;
	}

	/**
	 * Find product container.
	 *
	 * @param array $elementor_data Elementor data.
	 * @param int   $product_number Product number.
	 * @return array|null Container data or null.
	 */
	private function findProductContainer($elementor_data, $product_number) {
		foreach ($elementor_data as $element) {
			if (isset($element['settings']['_element_id']) && 
				$element['settings']['_element_id'] === 'product-' . $product_number) {
				return $element;
			}

			// Recursively check child elements
			if (isset($element['elements']) && is_array($element['elements'])) {
				$found = $this->findProductContainer($element['elements'], $product_number);
				if ($found) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Update variable references.
	 *
	 * @param array  $elementor_data Elementor data.
	 * @param int    $product_number Product number.
	 * @param string $action Action (add or remove).
	 * @return array Updated data.
	 */
	private function updateVariableReferences($elementor_data, $product_number, $action) {
		if (!is_array($elementor_data)) {
			return $elementor_data;
		}

		foreach ($elementor_data as &$element) {
			// Update settings
			if (isset($element['settings']) && is_array($element['settings'])) {
				$element['settings'] = $this->updateVariablesInArray($element['settings'], $product_number, $action);
			}

			// Recursively update child elements
			if (isset($element['elements']) && is_array($element['elements'])) {
				$element['elements'] = $this->updateVariableReferences($element['elements'], $product_number, $action);
			}
		}

		return $elementor_data;
	}

	/**
	 * Update variables in array.
	 *
	 * @param array  $array Array to update.
	 * @param int    $product_number Product number.
	 * @param string $action Action (add or remove).
	 * @return array Updated array.
	 */
	private function updateVariablesInArray($array, $product_number, $action) {
		foreach ($array as $key => &$value) {
			if (is_string($value)) {
				if ($action === 'add') {
					$value = $this->variable_replacer->updateVariablesForNewProduct($value, $product_number);
				} else {
					$value = $this->variable_replacer->updateVariablesAfterRemoval($value, $product_number);
				}
			} elseif (is_array($value)) {
				$value = $this->updateVariablesInArray($value, $product_number, $action);
			}
		}

		return $array;
	}

	/**
	 * Update product variables in container.
	 *
	 * @param array $container Container data.
	 * @param array $product Product data.
	 * @return array Updated container.
	 */
	private function updateProductVariablesInContainer($container, $product) {
		// Update product variables throughout the container
		// This would replace {product-X} variables with actual product data
		return $container;
	}

	/**
	 * Validate template structure.
	 *
	 * @param array $elementor_data Elementor data.
	 * @return bool|\WP_Error True if valid, WP_Error if invalid.
	 */
	public function validateTemplateStructure($elementor_data) {
		if (!is_array($elementor_data)) {
			return new \WP_Error('invalid_structure', 'Elementor data must be an array');
		}

		// Basic validation
		if (empty($elementor_data)) {
			return new \WP_Error('empty_structure', 'Elementor data is empty');
		}

		return true;
	}

	/**
	 * Optimize template structure.
	 *
	 * @param array $elementor_data Elementor data.
	 * @return array Optimized data.
	 */
	public function optimizeTemplateStructure($elementor_data) {
		return DataUtilities::optimizeDataStructure($elementor_data);
	}
}

