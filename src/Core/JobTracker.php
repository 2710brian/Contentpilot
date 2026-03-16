<?php

namespace AEBG\Core;

/**
 * JobTracker Class
 * 
 * Single source of truth for job timing.
 * Tracks job start time and provides timeout status.
 * 
 * @package AEBG\Core
 */
class JobTracker {
	
	/**
	 * Job start time (microtime(true))
	 * 
	 * @var float
	 */
	private $start_time;
	
	/**
	 * Constructor
	 * 
	 * @param float|null $start_time Optional start time. If not provided, uses current time.
	 */
	public function __construct( ?float $start_time = null ) {
		$this->start_time = $start_time ?? microtime( true );
	}
	
	/**
	 * Get elapsed time since job start
	 * 
	 * @return float Elapsed time in seconds
	 */
	public function get_elapsed_time(): float {
		return microtime( true ) - $this->start_time;
	}
	
	/**
	 * Get remaining time before timeout
	 * 
	 * @param float $max_time Maximum allowed time in seconds
	 * @return float Remaining time in seconds
	 */
	public function get_remaining_time( float $max_time = TimeoutManager::DEFAULT_TIMEOUT ): float {
		return TimeoutManager::get_remaining_time( $this->start_time, (int) $max_time );
	}
	
	/**
	 * Check if approaching timeout
	 * 
	 * @param float $max_time Maximum allowed time in seconds
	 * @param float $buffer Safety buffer in seconds before timeout
	 * @return bool True if approaching timeout, false otherwise
	 */
	public function is_approaching_timeout( float $max_time = TimeoutManager::DEFAULT_TIMEOUT, float $buffer = TimeoutManager::SAFETY_BUFFER ): bool {
		return TimeoutManager::is_approaching_timeout( $this->start_time, (int) $max_time, (int) $buffer );
	}
	
	/**
	 * Check if timeout has been exceeded
	 * 
	 * @param float $max_time Maximum allowed time in seconds
	 * @return bool True if timeout exceeded, false otherwise
	 */
	public function is_timeout_exceeded( float $max_time = TimeoutManager::DEFAULT_TIMEOUT ): bool {
		return TimeoutManager::is_timeout_exceeded( $this->start_time, (int) $max_time );
	}
	
	/**
	 * Get start time
	 * 
	 * @return float Start time (microtime(true))
	 */
	public function get_start_time(): float {
		return $this->start_time;
	}
}

