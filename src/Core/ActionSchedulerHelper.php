<?php

namespace AEBG\Core;

/**
 * ActionSchedulerHelper Class
 * 
 * Centralizes all Action Scheduler operations.
 * Provides proper initialization checks, efficient existence checks,
 * and handles immediate actions correctly.
 * 
 * @package AEBG\Core
 */
class ActionSchedulerHelper {
	
	/**
	 * Ensure Action Scheduler is initialized
	 * Uses Action Scheduler's built-in is_initialized() check
	 * This is the same check used internally by all Action Scheduler functions
	 * 
	 * @return bool True if initialized, false otherwise
	 */
	public static function ensure_initialized(): bool {
		// Check if Action Scheduler is available
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return false;
		}
		
		// CRITICAL: Use Action Scheduler's built-in initialization check
		// This is the same check used by as_next_scheduled_action() and other functions
		// It checks both the init hook AND the data store initialization
		if ( method_exists( '\ActionScheduler', 'is_initialized' ) ) {
			// Pass the calling function name for better error messages
			$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1]['function'] ?? 'unknown';
			if ( ! \ActionScheduler::is_initialized( $caller ) ) {
				return false;
			}
		} else {
			// Fallback: check if init hook has fired
			if ( ! did_action( 'action_scheduler_init' ) ) {
				return false;
			}
		}
		
		// Additional check: ensure Action Scheduler functions are available
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Check if action exists
	 * Uses as_has_scheduled_action() - more efficient than as_get_scheduled_actions()
	 * Available since Action Scheduler 3.3.0
	 * 
	 * @param string $hook Action hook name
	 * @param array $args Action arguments
	 * @param string $group Action group
	 * @return bool True if action exists (pending or in-progress), false otherwise
	 */
	public static function action_exists( string $hook, array $args = [], string $group = '' ): bool {
		// CRITICAL: Check if Action Scheduler is initialized before calling any functions
		if ( ! self::ensure_initialized() ) {
			Logger::debug( 'Action Scheduler not initialized, cannot check action existence', [
				'hook' => $hook,
				'group' => $group,
			] );
			return false;
		}
		
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			// Fallback for older Action Scheduler versions
			$actions = self::get_scheduled_actions( [
				'hook' => $hook,
				'args' => $args,
				'group' => $group,
				'status' => [ 'pending', 'in-progress' ],
				'per_page' => 1,
			], 'ids' );
			
			return ! empty( $actions );
		}
		
		// Use efficient existence check (3.3.0+)
		return as_has_scheduled_action( $hook, $args, $group );
	}
	
	/**
	 * Safe wrapper for as_get_scheduled_actions()
	 * Ensures Action Scheduler is initialized before calling
	 * 
	 * @param array $args Query arguments
	 * @param string $return_format Return format ('ids' or 'objects')
	 * @return array Array of actions or empty array if not initialized
	 */
	public static function get_scheduled_actions( array $args = [], string $return_format = 'objects' ): array {
		// CRITICAL: Check if Action Scheduler is initialized before calling any functions
		if ( ! self::ensure_initialized() ) {
			Logger::debug( 'Action Scheduler not initialized, cannot get scheduled actions', [
				'args' => $args,
			] );
			return [];
		}
		
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			Logger::error( 'as_get_scheduled_actions() function not available' );
			return [];
		}
		
		try {
			return as_get_scheduled_actions( $args, $return_format );
		} catch ( \Exception $e ) {
			Logger::error( 'Error calling as_get_scheduled_actions()', [
				'error' => $e->getMessage(),
				'args' => $args,
			] );
			return [];
		}
	}
	
	/**
	 * Enqueue immediate action
	 * Uses as_enqueue_async_action() - better than scheduling with time() + 1
	 * 
	 * @param string $hook Action hook name
	 * @param array $args Action arguments
	 * @param string $group Action group
	 * @param bool $unique Whether the action should be unique
	 * @return int|false Action ID on success, false on failure
	 */
	public static function enqueue_immediate_action( string $hook, array $args = [], string $group = '', bool $unique = true ): int|false {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			Logger::error( 'as_enqueue_async_action() not available' );
			return false;
		}
		
		// Check if action already exists (if unique) - but only if Action Scheduler is initialized
		if ( $unique && self::ensure_initialized() && self::action_exists( $hook, $args, $group ) ) {
			Logger::debug( 'Action already exists, skipping duplicate', [
				'hook' => $hook,
				'group' => $group,
			] );
			return 0; // Return 0 to indicate action exists (not an error)
		}
		
		$action_id = as_enqueue_async_action( $hook, $args, $group, $unique );
		
		if ( $action_id > 0 ) {
			Logger::debug( 'Action enqueued successfully', [
				'action_id' => $action_id,
				'hook' => $hook,
				'group' => $group,
			] );
		} elseif ( $action_id === 0 && $unique ) {
			// When unique=true, 0 means action already exists (not an error)
			Logger::debug( 'Action already exists (unique check)', [
				'hook' => $hook,
				'group' => $group,
			] );
		} else {
			Logger::error( 'Failed to enqueue action', [
				'hook' => $hook,
				'group' => $group,
				'action_id' => $action_id,
			] );
		}
		
		return $action_id;
	}
	
	/**
	 * Schedule an action (delayed or immediate)
	 * Uses as_enqueue_async_action() for immediate actions (delay = 0)
	 * Uses as_schedule_single_action() for delayed actions
	 * 
	 * @param string $hook Action hook name
	 * @param array $args Action arguments
	 * @param string $group Action group
	 * @param int $delay Delay in seconds (0 for immediate)
	 * @param bool $unique Whether the action should be unique
	 * @return int|false Action ID on success, false on failure
	 */
	public static function schedule_action( string $hook, array $args = [], string $group = '', int $delay = 0, bool $unique = true ): int|false {
		// CRITICAL: Check if Action Scheduler is initialized before calling any functions
		if ( ! self::ensure_initialized() ) {
			Logger::error( 'Action Scheduler not initialized, cannot schedule action', [
				'hook' => $hook,
				'group' => $group,
				'delay' => $delay,
			] );
			return false;
		}
		
		// Check if Action Scheduler functions are available
		if ( ! function_exists( 'as_schedule_single_action' ) && ! function_exists( 'as_enqueue_async_action' ) ) {
			Logger::error( 'Action Scheduler functions not available', [
				'hook' => $hook,
				'group' => $group,
			] );
			return false;
		}
		
		// For immediate actions, try as_enqueue_async_action() first
		if ( $delay === 0 ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				return self::enqueue_immediate_action( $hook, $args, $group, $unique );
			}
			// Fallback to as_schedule_single_action with time() + 1 if as_enqueue_async_action not available
			$delay = 1;
		}
		
		// For delayed actions, use as_schedule_single_action()
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::error( 'as_schedule_single_action() not available' );
			return false;
		}
		
		// Check if action already exists (if unique)
		if ( $unique && self::action_exists( $hook, $args, $group ) ) {
			Logger::debug( 'Action already exists, skipping duplicate', [
				'hook' => $hook,
				'group' => $group,
				'delay' => $delay,
			] );
			return 0; // Return 0 to indicate action exists (not an error)
		}
		
		$timestamp = time() + $delay;
		$action_id = as_schedule_single_action( $timestamp, $hook, $args, $group, $unique );
		
		if ( $action_id > 0 ) {
			Logger::debug( 'Action scheduled successfully', [
				'action_id' => $action_id,
				'hook' => $hook,
				'group' => $group,
				'delay' => $delay,
				'timestamp' => $timestamp,
			] );
		} elseif ( $action_id === 0 && $unique ) {
			// When unique=true, 0 means action already exists (not an error)
			Logger::debug( 'Action already exists (unique check)', [
				'hook' => $hook,
				'group' => $group,
				'delay' => $delay,
			] );
		} else {
			Logger::error( 'Failed to schedule action', [
				'hook' => $hook,
				'group' => $group,
				'delay' => $delay,
				'action_id' => $action_id,
			] );
		}
		
		return $action_id;
	}
	
	/**
	 * Cancel stuck actions (in-progress for too long)
	 * 
	 * @param string $hook Action hook name
	 * @param array $args Action arguments
	 * @param string $group Action group
	 * @param int $max_age Maximum age in seconds before considering stuck (default: 600 = 10 minutes)
	 * @return int Number of stuck actions cancelled
	 */
	public static function cancel_stuck_actions( string $hook, array $args = [], string $group = '', int $max_age = 600 ): int {
		// CRITICAL: Check if Action Scheduler is initialized before calling any functions
		if ( ! self::ensure_initialized() ) {
			Logger::debug( 'Action Scheduler not initialized, cannot cancel stuck actions', [
				'hook' => $hook,
				'group' => $group,
			] );
			return 0;
		}
		
		$stuck_actions = self::get_scheduled_actions( [
			'hook' => $hook,
			'args' => $args,
			'group' => $group,
			'status' => 'in-progress',
		] );
		
		$cancelled = 0;
		$current_time = time();
		
		foreach ( $stuck_actions as $action ) {
			$schedule = $action->get_schedule();
			if ( ! $schedule ) {
				continue;
			}
			
			$schedule_date = $schedule->get_date();
			if ( ! $schedule_date ) {
				continue;
			}
			
			$action_age = $current_time - $schedule_date->getTimestamp();
			
			if ( $action_age > $max_age ) {
				// Cancel stuck action
				as_unschedule_action( $hook, $args, $group );
				$cancelled++;
				
				Logger::warning( 'Cancelled stuck action', [
					'action_id' => $action->get_id(),
					'hook' => $hook,
					'age' => $action_age,
					'max_age' => $max_age,
				] );
			}
		}
		
		return $cancelled;
	}
}


