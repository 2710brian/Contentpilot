<?php

namespace AEBG\Core;

/**
 * Logger Class
 * 
 * Centralized, configurable logging system.
 * Provides consistent log format and log level filtering.
 * 
 * @package AEBG\Core
 */
class Logger {
	
	/**
	 * Log levels
	 */
	const LEVEL_DEBUG = 'debug';
	const LEVEL_INFO = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR = 'error';
	
	/**
	 * Current log level (only log messages at or above this level)
	 * 
	 * @var string
	 */
	private static $log_level = self::LEVEL_INFO;
	
	/**
	 * Log level hierarchy (for comparison)
	 * 
	 * @var array
	 */
	private static $level_hierarchy = [
		self::LEVEL_DEBUG => 0,
		self::LEVEL_INFO => 1,
		self::LEVEL_WARNING => 2,
		self::LEVEL_ERROR => 3,
	];
	
	/**
	 * Set the log level
	 * 
	 * @param string $level Log level (debug, info, warning, error)
	 * @return void
	 */
	public static function set_level( string $level ): void {
		if ( isset( self::$level_hierarchy[ $level ] ) ) {
			self::$log_level = $level;
		}
	}
	
	/**
	 * Get the current log level
	 * 
	 * @return string Current log level
	 */
	public static function get_level(): string {
		return self::$log_level;
	}
	
	/**
	 * Check if a log level should be logged
	 * 
	 * @param string $level Log level to check
	 * @return bool True if should log, false otherwise
	 */
	private static function should_log( string $level ): bool {
		if ( ! isset( self::$level_hierarchy[ $level ] ) ) {
			return false;
		}
		
		// In production (WP_DEBUG false), only log warnings and errors
		// Debug and info messages should only be logged when WP_DEBUG is enabled
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			if ( in_array( $level, [ self::LEVEL_DEBUG, self::LEVEL_INFO ] ) ) {
				return false;
			}
		}
		
		return self::$level_hierarchy[ $level ] >= self::$level_hierarchy[ self::$log_level ];
	}
	
	/**
	 * Sanitize sensitive data from context array
	 * 
	 * @param array $context Context data that may contain sensitive information
	 * @return array Sanitized context data
	 */
	private static function sanitize_context( array $context ): array {
		$sensitive_keys = [
			'api_key',
			'access_key',
			'secret_key',
			'password',
			'token',
			'auth_token',
			'credentials',
			'datafeedr_access_id',
			'datafeedr_secret_key',
			'datafeedr_api_key',
		];
		
		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( $key );
			foreach ( $sensitive_keys as $sensitive_key ) {
				if ( strpos( $key_lower, $sensitive_key ) !== false ) {
					$context[ $key ] = '***REDACTED***';
					break;
				}
			}
			
			// Recursively sanitize nested arrays
			if ( is_array( $value ) ) {
				$context[ $key ] = self::sanitize_context( $value );
			}
		}
		
		return $context;
	}
	
	/**
	 * Format log message with context
	 * 
	 * @param string $level Log level
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return string Formatted log message
	 */
	private static function format_message( string $level, string $message, array $context = [] ): string {
		$prefix = '[AEBG]';
		
		// Add context if provided (sanitize sensitive data)
		if ( ! empty( $context ) ) {
			$sanitized_context = self::sanitize_context( $context );
			$context_str = json_encode( $sanitized_context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$message .= ' | Context: ' . $context_str;
		}
		
		return $prefix . ' [' . strtoupper( $level ) . '] ' . $message;
	}
	
	/**
	 * Log debug message
	 * 
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function debug( string $message, array $context = [] ): void {
		if ( ! self::should_log( self::LEVEL_DEBUG ) ) {
			return;
		}
		
		error_log( self::format_message( self::LEVEL_DEBUG, $message, $context ) );
	}
	
	/**
	 * Log info message
	 * 
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function info( string $message, array $context = [] ): void {
		if ( ! self::should_log( self::LEVEL_INFO ) ) {
			return;
		}
		
		error_log( self::format_message( self::LEVEL_INFO, $message, $context ) );
	}
	
	/**
	 * Log warning message
	 * 
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function warning( string $message, array $context = [] ): void {
		if ( ! self::should_log( self::LEVEL_WARNING ) ) {
			return;
		}
		
		error_log( self::format_message( self::LEVEL_WARNING, $message, $context ) );
	}
	
	/**
	 * Log error message
	 * 
	 * @param string $message Log message
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function error( string $message, array $context = [] ): void {
		if ( ! self::should_log( self::LEVEL_ERROR ) ) {
			return;
		}
		
		error_log( self::format_message( self::LEVEL_ERROR, $message, $context ) );
	}
}

