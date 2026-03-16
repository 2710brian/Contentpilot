<?php

namespace AEBG\Core;

/**
 * TimeoutManager Class
 * 
 * Centralizes all timeout management logic.
 * Provides consistent timeout setting, checking, and monitoring.
 * 
 * @package AEBG\Core
 */
class TimeoutManager {
	
	/**
	 * Default timeout in seconds (30 minutes)
	 * Increased from 600s (10 min) to 1800s (30 min) to allow long-running AI generation
	 */
	const DEFAULT_TIMEOUT = 1800;
	
	/**
	 * Safety buffer before timeout (50 seconds)
	 */
	const SAFETY_BUFFER = 50;
	
	/**
	 * Check and log PHP execution timeout (does NOT modify server configuration)
	 * 
	 * We respect the server's max_execution_time setting and do not attempt to override it.
	 * Individual API request timeouts are handled by cURL timeouts, not PHP's execution timeout.
	 * 
	 * @param int $expected_timeout Expected timeout in seconds (for logging purposes only)
	 * @return array Current timeout information
	 */
	public static function check_timeout( int $expected_timeout = self::DEFAULT_TIMEOUT ): array {
		$current_timeout = ini_get( 'max_execution_time' );
		
		// Log timeout information (only on first call to avoid log spam)
		static $logged = false;
		if ( ! $logged ) {
			Logger::debug( 'PHP execution timeout check', [
				'current_timeout' => $current_timeout == 0 ? 'unlimited' : $current_timeout . 's',
				'expected_timeout' => $expected_timeout . 's',
				'note' => 'Server configuration is respected - timeout is not modified by code',
			] );
			$logged = true;
		}
		
		return [
			'current' => $current_timeout == 0 ? PHP_INT_MAX : (int) $current_timeout,
			'is_unlimited' => $current_timeout == 0,
			'expected' => $expected_timeout,
		];
	}
	
	/**
	 * @deprecated Use check_timeout() instead. This method no longer modifies max_execution_time.
	 * @param int $seconds Ignored - kept for backward compatibility
	 * @return bool Always returns true (timeout is never modified)
	 */
	public static function set_timeout( int $seconds = self::DEFAULT_TIMEOUT ): bool {
		// Call check_timeout for logging, but don't modify anything
		self::check_timeout( $seconds );
		return true;
	}
	
	/**
	 * Get remaining execution time
	 * 
	 * @param float $start_time Job start time (microtime(true))
	 * @param int $max_time Maximum allowed time in seconds
	 * @return float Remaining time in seconds
	 */
	public static function get_remaining_time( float $start_time, int $max_time = self::DEFAULT_TIMEOUT ): float {
		$elapsed = microtime( true ) - $start_time;
		return max( 0, $max_time - $elapsed );
	}
	
	/**
	 * Check if approaching timeout
	 * 
	 * @param float $start_time Job start time (microtime(true))
	 * @param int $max_time Maximum allowed time in seconds
	 * @param int $buffer Safety buffer in seconds before timeout
	 * @return bool True if approaching timeout, false otherwise
	 */
	public static function is_approaching_timeout( float $start_time, int $max_time = self::DEFAULT_TIMEOUT, int $buffer = self::SAFETY_BUFFER ): bool {
		$elapsed = microtime( true ) - $start_time;
		return $elapsed >= ( $max_time - $buffer );
	}
	
	/**
	 * Check if timeout has been exceeded
	 * 
	 * @param float $start_time Job start time (microtime(true))
	 * @param int $max_time Maximum allowed time in seconds
	 * @return bool True if timeout exceeded, false otherwise
	 */
	public static function is_timeout_exceeded( float $start_time, int $max_time = self::DEFAULT_TIMEOUT ): bool {
		$elapsed = microtime( true ) - $start_time;
		return $elapsed >= $max_time;
	}
	
	/**
	 * Get elapsed time since start
	 * 
	 * @param float $start_time Job start time (microtime(true))
	 * @return float Elapsed time in seconds
	 */
	public static function get_elapsed_time( float $start_time ): float {
		return microtime( true ) - $start_time;
	}
}

