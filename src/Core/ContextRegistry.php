<?php

namespace AEBG\Core;

/**
 * Context Registry Class
 * Manages AI Content Prompt field dependencies and processing order.
 *
 * @package AEBG\Core
 */
class ContextRegistry {
	/**
	 * Registered contexts.
	 *
	 * @var array
	 */
	private $contexts = [];

	/**
	 * Field dependencies.
	 *
	 * @var array
	 */
	private $dependencies = [];

	/**
	 * Processed results.
	 *
	 * @var array
	 */
	private $results = [];

	/**
	 * ContextRegistry constructor.
	 */
	public function __construct() {
		// Initialize empty arrays
	}

	/**
	 * Register a field with its context data and dependencies.
	 *
	 * @param string $field_id The field ID.
	 * @param array  $context_data The context data for this field.
	 * @param array  $dependencies Array of field IDs this field depends on.
	 */
	public function register($field_id, $context_data, $dependencies = []) {
		$this->contexts[$field_id] = $context_data;
		$this->dependencies[$field_id] = $dependencies;
		$this->results[$field_id] = null;
	}

	/**
	 * Register a field (alias for register method for backward compatibility).
	 *
	 * @param string $field_id The field ID.
	 * @param array  $context_data The context data for this field.
	 */
	public function registerField($field_id, $context_data) {
		$dependencies = $context_data['dependencies'] ?? [];
		$this->register($field_id, $context_data, $dependencies);
	}

	/**
	 * Get context data for a field.
	 *
	 * @param string $field_id The field ID.
	 * @return array|null
	 */
	public function getContext($field_id) {
		return $this->contexts[$field_id] ?? null;
	}

	/**
	 * Set/update context data for a field.
	 *
	 * @param string $field_id The field ID.
	 * @param array  $context_data The context data to set.
	 * @return void
	 */
	public function setContext($field_id, $context_data) {
		if (isset($this->contexts[$field_id])) {
			// Merge with existing context data
			$this->contexts[$field_id] = array_merge($this->contexts[$field_id], $context_data);
		} else {
			// Create new context entry
			$this->contexts[$field_id] = $context_data;
		}
	}

	/**
	 * Get dependencies for a field.
	 *
	 * @param string $field_id The field ID.
	 * @return array
	 */
	public function getDependencies($field_id) {
		return $this->dependencies[$field_id] ?? [];
	}

	/**
	 * Set result for a field.
	 *
	 * @param string $field_id The field ID.
	 * @param mixed  $result The processing result.
	 */
	public function setResult($field_id, $result) {
		$this->results[$field_id] = $result;
	}

	/**
	 * Get result for a field.
	 *
	 * @param string $field_id The field ID.
	 * @return mixed|null
	 */
	public function getResult($field_id) {
		return $this->results[$field_id] ?? null;
	}

	/**
	 * Get generated content by prompt text.
	 * Searches through all registered contexts to find a field with matching prompt.
	 *
	 * @param string $prompt The prompt text to search for.
	 * @return string|null The generated content, or null if not found.
	 */
	public function getGeneratedContent($prompt) {
		if (empty($prompt)) {
			return null;
		}

		$prompt = trim($prompt);
		
		// First try exact match
		foreach ($this->contexts as $field_id => $context_data) {
			if (isset($context_data['prompt'])) {
				$stored_prompt = trim($context_data['prompt']);
				if ($stored_prompt === $prompt) {
					// Found exact matching prompt, return the result
					$result = $this->getResult($field_id);
					if ($result !== null) {
						error_log('[AEBG ContextRegistry] Found generated content for prompt (exact match) via field_id: ' . $field_id);
						return $result;
					}
				}
			}
		}

		// If no exact match, try partial match (in case variables were replaced)
		// This handles cases where the prompt might have had variables replaced
		$prompt_normalized = preg_replace('/\{[^}]+\}/', '', $prompt); // Remove variable placeholders
		$prompt_normalized = trim($prompt_normalized);
		
		if (!empty($prompt_normalized)) {
			foreach ($this->contexts as $field_id => $context_data) {
				if (isset($context_data['prompt'])) {
					$stored_prompt = trim($context_data['prompt']);
					$stored_prompt_normalized = preg_replace('/\{[^}]+\}/', '', $stored_prompt);
					$stored_prompt_normalized = trim($stored_prompt_normalized);
					
					// Check if normalized prompts match (at least 80% similarity)
					if (!empty($stored_prompt_normalized) && 
						similar_text($prompt_normalized, $stored_prompt_normalized, $similarity) && 
						$similarity >= 80) {
						$result = $this->getResult($field_id);
						if ($result !== null) {
							error_log('[AEBG ContextRegistry] Found generated content for prompt (partial match, ' . round($similarity) . '% similarity) via field_id: ' . $field_id);
							return $result;
						}
					}
				}
			}
		}

		error_log('[AEBG ContextRegistry] No generated content found for prompt: ' . substr($prompt, 0, 50) . '...');
		error_log('[AEBG ContextRegistry] Registered contexts count: ' . count($this->contexts));
		error_log('[AEBG ContextRegistry] Results count: ' . count(array_filter($this->results)));
		return null;
	}

	/**
	 * Get all results.
	 *
	 * @return array
	 */
	public function getAllResults() {
		return $this->results;
	}

	/**
	 * Get processing order based on dependencies.
	 *
	 * @return array
	 */
	public function getProcessingOrder() {
		return $this->topologicalSort($this->dependencies);
	}

	/**
	 * Check if a field has unresolved dependencies.
	 *
	 * @param string $field_id The field ID.
	 * @return bool
	 */
	public function hasUnresolvedDependencies($field_id) {
		$dependencies = $this->getDependencies($field_id);
		
		foreach ($dependencies as $dep_id) {
			if (!isset($this->results[$dep_id]) || $this->results[$dep_id] === null) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Get all registered field IDs.
	 *
	 * @return array
	 */
	public function getRegisteredFields() {
		return array_keys($this->contexts);
	}

	/**
	 * Export registry state for checkpointing.
	 *
	 * @return array State data with contexts, dependencies, and results
	 */
	public function exportState() {
		return [
			'contexts' => $this->contexts,
			'dependencies' => $this->dependencies,
			'results' => $this->results,
		];
	}

	/**
	 * Import registry state from checkpoint.
	 *
	 * @param array $state State data with contexts, dependencies, and results
	 * @return void
	 */
	public function importState(array $state) {
		$this->contexts = $state['contexts'] ?? [];
		$this->dependencies = $state['dependencies'] ?? [];
		$this->results = $state['results'] ?? [];
	}

	/**
	 * Clear all registered data.
	 */
	public function clear() {
		$this->contexts = [];
		$this->dependencies = [];
		$this->results = [];
	}

	/**
	 * Perform topological sort to determine processing order.
	 *
	 * @param array $dependencies The dependency graph.
	 * @return array
	 */
	private function topologicalSort($dependencies) {
		$visited = [];
		$temp_visited = [];
		$order = [];

		foreach (array_keys($dependencies) as $node) {
			if (!isset($visited[$node])) {
				$this->topologicalSortVisit($node, $dependencies, $visited, $temp_visited, $order);
			}
		}

		return array_reverse($order);
	}

	/**
	 * Helper method for topological sort.
	 *
	 * @param string $node The current node.
	 * @param array  $dependencies The dependency graph.
	 * @param array  &$visited Visited nodes.
	 * @param array  &$temp_visited Temporarily visited nodes.
	 * @param array  &$order Processing order.
	 */
	private function topologicalSortVisit($node, $dependencies, &$visited, &$temp_visited, &$order) {
		if (isset($temp_visited[$node])) {
			// Circular dependency detected
			error_log("[AEBG] Circular dependency detected for field: $node");
			return;
		}

		if (isset($visited[$node])) {
			return;
		}

		$temp_visited[$node] = true;

		if (isset($dependencies[$node])) {
			foreach ($dependencies[$node] as $dependency) {
				if (isset($dependencies[$dependency])) {
					$this->topologicalSortVisit($dependency, $dependencies, $visited, $temp_visited, $order);
				}
			}
		}

		unset($temp_visited[$node]);
		$visited[$node] = true;
		$order[] = $node;
	}

	/**
	 * Get context string for variable replacement.
	 *
	 * @param string $field_id The field ID.
	 * @return string
	 */
	public function getContextString($field_id) {
		$context_parts = [];
		
		// Add basic context
		$context = $this->getContext($field_id);
		if ($context) {
			foreach ($context as $key => $value) {
				if (is_string($value) || is_numeric($value)) {
					$context_parts[] = "$key: $value";
				}
			}
		}
		
		// Add dependency results
		$dependencies = $this->getDependencies($field_id);
		foreach ($dependencies as $dep_id) {
			$result = $this->getResult($dep_id);
			if ($result !== null) {
				$context_parts[] = "$dep_id: $result";
			}
		}
		
		return implode("\n", $context_parts);
	}

	/**
	 * Validate dependencies to ensure no circular references.
	 *
	 * @return bool
	 */
	public function validateDependencies() {
		$visited = [];
		$temp_visited = [];

		foreach (array_keys($this->dependencies) as $node) {
			if (!isset($visited[$node])) {
				if ($this->hasCircularDependency($node, $visited, $temp_visited)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check for circular dependencies.
	 *
	 * @param string $node The current node.
	 * @param array  &$visited Visited nodes.
	 * @param array  &$temp_visited Temporarily visited nodes.
	 * @return bool
	 */
	private function hasCircularDependency($node, &$visited, &$temp_visited) {
		if (isset($temp_visited[$node])) {
			return true;
		}

		if (isset($visited[$node])) {
			return false;
		}

		$temp_visited[$node] = true;

		if (isset($this->dependencies[$node])) {
			foreach ($this->dependencies[$node] as $dependency) {
				if ($this->hasCircularDependency($dependency, $visited, $temp_visited)) {
					return true;
				}
			}
		}

		unset($temp_visited[$node]);
		$visited[$node] = true;

		return false;
	}
} 