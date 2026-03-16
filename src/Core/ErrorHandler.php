<?php

namespace AEBG\Core;

/**
 * ErrorHandler Class
 * 
 * Centralized error handling and recovery.
 * Provides consistent error logging and recovery strategies.
 * 
 * @package AEBG\Core
 */
class ErrorHandler {
	
	/**
	 * Handle exception
	 * 
	 * @param \Exception $e Exception instance
	 * @param string $context Context where error occurred
	 * @param array $additional_data Additional context data
	 * @return void
	 */
	public static function handle_exception( \Exception $e, string $context, array $additional_data = [] ): void {
		$message = $e->getMessage();
		$code = $e->getCode();
		$file = $e->getFile();
		$line = $e->getLine();
		$trace = $e->getTraceAsString();
		
		Logger::error( 'Exception in ' . $context, [
			'message' => $message,
			'code' => $code,
			'file' => $file,
			'line' => $line,
			'trace' => $trace,
			'additional' => $additional_data,
		] );
	}
	
	/**
	 * Handle PHP error
	 * 
	 * @param int $code Error code
	 * @param string $message Error message
	 * @param string $file File where error occurred
	 * @param int $line Line number
	 * @return void
	 */
	public static function handle_error( int $code, string $message, string $file, int $line ): void {
		$level = self::get_error_level( $code );
		
		// Call the appropriate Logger method based on error level
		switch ( $level ) {
			case Logger::LEVEL_ERROR:
				Logger::error( 'PHP error in ' . $file . ':' . $line, [
					'code' => $code,
					'message' => $message,
					'file' => $file,
					'line' => $line,
				] );
				break;
			case Logger::LEVEL_WARNING:
				Logger::warning( 'PHP error in ' . $file . ':' . $line, [
					'code' => $code,
					'message' => $message,
					'file' => $file,
					'line' => $line,
				] );
				break;
			case Logger::LEVEL_DEBUG:
				Logger::debug( 'PHP error in ' . $file . ':' . $line, [
					'code' => $code,
					'message' => $message,
					'file' => $file,
					'line' => $line,
				] );
				break;
			default:
				Logger::info( 'PHP error in ' . $file . ':' . $line, [
					'code' => $code,
					'message' => $message,
					'file' => $file,
					'line' => $line,
				] );
				break;
		}
	}
	
	/**
	 * Get log level for PHP error code
	 * 
	 * @param int $code PHP error code
	 * @return string Log level
	 */
	private static function get_error_level( int $code ): string {
		switch ( $code ) {
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_PARSE:
			case E_RECOVERABLE_ERROR:
				return Logger::LEVEL_ERROR;
			
			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
				return Logger::LEVEL_WARNING;
			
			case E_NOTICE:
			case E_USER_NOTICE:
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				return Logger::LEVEL_DEBUG;
			
			default:
				return Logger::LEVEL_WARNING;
		}
	}
	
	/**
	 * Mark batch item as failed
	 * 
	 * @param int $item_id Item ID
	 * @param string $reason Failure reason
	 * @param int|null $batch_id Optional batch ID
	 * @return bool True on success, false on failure
	 */
	public static function mark_item_failed( int $item_id, string $reason, ?int $batch_id = null ): bool {
		global $wpdb;
		
		$update_data = [
			'status' => 'failed',
			'log_message' => $reason,
		];
		
		$result = $wpdb->update(
			"{$wpdb->prefix}aebg_batch_items",
			$update_data,
			[ 'id' => $item_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		
		if ( $result === false ) {
			Logger::error( 'Failed to mark item as failed', [
				'item_id' => $item_id,
				'reason' => $reason,
				'db_error' => $wpdb->last_error,
			] );
			return false;
		}
		
		// Increment failed_items count if batch_id provided
		if ( $batch_id ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_batches SET failed_items = COALESCE(failed_items, 0) + 1 WHERE id = %d",
				$batch_id
			) );
			
			// CRITICAL: Clear cache immediately to ensure frontend sees the update
			$cache_key = 'aebg_batch_status_' . $batch_id;
			wp_cache_delete( $cache_key, 'aebg_batches' );
			
			// Also flush object cache group if available
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				wp_cache_flush_group( 'aebg_batches' );
			}
		}
		
		Logger::info( 'Item marked as failed', [
			'item_id' => $item_id,
			'reason' => $reason,
			'batch_id' => $batch_id,
		] );
		
		return true;
	}
	
	/**
	 * Check if exception should be retried
	 * 
	 * @param \Exception $e Exception instance
	 * @return bool True if should retry, false otherwise
	 */
	public static function should_retry( \Exception $e ): bool {
		$message = strtolower( $e->getMessage() );
		
		// Retry on transient errors
		$retryable_errors = [
			'timeout',
			'connection',
			'temporary',
			'rate limit',
			'server error',
			'503',
			'502',
			'500',
		];
		
		foreach ( $retryable_errors as $error ) {
			if ( strpos( $message, $error ) !== false ) {
				return true;
			}
		}
		
		return false;
	}
}

