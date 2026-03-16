<?php

namespace AEBG\Core;

use AEBG\Core\Logger;

/**
 * Generation Observer Class
 * Handles observability and progress tracking.
 *
 * @package AEBG\Core
 */
class GenerationObserver {
	/**
	 * Generation start time.
	 *
	 * @var float
	 */
	private $start_time;

	/**
	 * Current step.
	 *
	 * @var string
	 */
	private $current_step;

	/**
	 * Progress data.
	 *
	 * @var array
	 */
	private $progress_data;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->start_time = microtime(true);
		$this->current_step = 'initialized';
		$this->progress_data = [
			'steps' => [],
			'metrics' => [],
			'errors' => []
		];
	}

	/**
	 * Start generation observation.
	 *
	 * @param string $title Post title.
	 * @return void
	 */
	public function startGeneration($title) {
		$this->start_time = microtime(true);
		$this->current_step = 'started';
		$this->progress_data = [
			'title' => $title,
			'start_time' => $this->start_time,
			'steps' => [],
			'metrics' => [],
			'errors' => []
		];

		Logger::info('Generation started', ['title' => $title]);
	}

	/**
	 * Record step completion.
	 *
	 * @param string $step_name Step name.
	 * @param array  $data Step data.
	 * @return void
	 */
	public function recordStep($step_name, $data = []) {
		$elapsed = microtime(true) - $this->start_time;
		
		$this->progress_data['steps'][] = [
			'name' => $step_name,
			'timestamp' => microtime(true),
			'elapsed' => $elapsed,
			'data' => $data
		];

		$this->current_step = $step_name;

		Logger::debug('Step completed', ['step' => $step_name, 'elapsed' => round($elapsed, 2)]);
	}

	/**
	 * Record metric.
	 *
	 * @param string $metric_name Metric name.
	 * @param mixed  $value Metric value.
	 * @return void
	 */
	public function recordMetric($metric_name, $value) {
		$this->progress_data['metrics'][$metric_name] = $value;
		Logger::debug('Metric recorded', ['metric' => $metric_name, 'value' => $value]);
	}

	/**
	 * Record error.
	 *
	 * @param string $error_message Error message.
	 * @param array  $context Error context.
	 * @return void
	 */
	public function recordError($error_message, $context = []) {
		$this->progress_data['errors'][] = [
			'message' => $error_message,
			'timestamp' => microtime(true),
			'context' => $context
		];

		Logger::error('Error recorded', ['message' => $error_message, 'context' => $context]);
	}

	/**
	 * Get progress data.
	 *
	 * @return array Progress data.
	 */
	public function getProgress() {
		$elapsed = microtime(true) - $this->start_time;
		
		return [
			'current_step' => $this->current_step,
			'elapsed_time' => $elapsed,
			'steps' => $this->progress_data['steps'],
			'metrics' => $this->progress_data['metrics'],
			'errors' => $this->progress_data['errors']
		];
	}

	/**
	 * Get generation summary.
	 *
	 * @return array Summary data.
	 */
	public function getSummary() {
		$elapsed = microtime(true) - $this->start_time;
		
		return [
			'title' => $this->progress_data['title'] ?? '',
			'total_time' => $elapsed,
			'steps_count' => count($this->progress_data['steps']),
			'errors_count' => count($this->progress_data['errors']),
			'final_step' => $this->current_step,
			'success' => empty($this->progress_data['errors'])
		];
	}

	/**
	 * Check if generation is complete.
	 *
	 * @return bool True if complete.
	 */
	public function isComplete() {
		return $this->current_step === 'completed';
	}

	/**
	 * Mark generation as complete.
	 *
	 * @return void
	 */
	public function markComplete() {
		$this->current_step = 'completed';
		$this->recordStep('completed');
		
		$summary = $this->getSummary();
		Logger::info('Generation completed', $summary);
	}

	/**
	 * Get performance metrics.
	 *
	 * @return array Performance metrics.
	 */
	public function getPerformanceMetrics() {
		$metrics = [
			'total_time' => microtime(true) - $this->start_time,
			'step_times' => [],
			'average_step_time' => 0,
			'slowest_step' => null,
			'fastest_step' => null
		];

		if (!empty($this->progress_data['steps'])) {
			$step_times = [];
			foreach ($this->progress_data['steps'] as $step) {
				$step_times[$step['name']] = $step['elapsed'];
			}

			$metrics['step_times'] = $step_times;
			$metrics['average_step_time'] = array_sum($step_times) / count($step_times);
			$metrics['slowest_step'] = array_search(max($step_times), $step_times);
			$metrics['fastest_step'] = array_search(min($step_times), $step_times);
		}

		return $metrics;
	}
}

