<?php

namespace AEBG\EmailMarketing\Repositories;

/**
 * Tracking Repository
 * 
 * Handles all database operations for email tracking
 * 
 * @package AEBG\EmailMarketing\Repositories
 */
class TrackingRepository {
	/**
	 * Log tracking event
	 * 
	 * @param int $queue_id Queue ID
	 * @param int $campaign_id Campaign ID
	 * @param int $subscriber_id Subscriber ID
	 * @param string $event_type Event type (opened, clicked, etc.)
	 * @param array $event_data Additional event data
	 * @return int|false Tracking ID on success, false on failure
	 */
	public function log_event( $queue_id, $campaign_id, $subscriber_id, $event_type, $event_data = [] ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_tracking';
		
		// Detect device and email client from user agent
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$device_type = $this->detect_device_type( $user_agent );
		$email_client = $this->detect_email_client( $user_agent );
		
		// Encode event data if array
		if ( is_array( $event_data ) ) {
			$event_data = wp_json_encode( $event_data );
		}
		
		$result = $wpdb->insert(
			$table,
			[
				'queue_id' => (int) $queue_id,
				'campaign_id' => (int) $campaign_id,
				'subscriber_id' => (int) $subscriber_id,
				'event_type' => sanitize_text_field( $event_type ),
				'event_data' => $event_data,
				'ip_address' => $this->get_client_ip(),
				'user_agent' => sanitize_text_field( $user_agent ),
				'device_type' => $device_type,
				'email_client' => $email_client,
				'location' => null, // Can be populated with IP geolocation if needed
			],
			['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
		);
		
		if ( $result === false ) {
			return false;
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Check if event already exists
	 * 
	 * @param int $queue_id Queue ID
	 * @param string $event_type Event type
	 * @return bool True if event exists
	 */
	public function has_event( $queue_id, $event_type ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_tracking';
		
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE queue_id = %d AND event_type = %s",
			$queue_id,
			$event_type
		) );
		
		return (int) $count > 0;
	}
	
	/**
	 * Get tracking events by campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param string $event_type Event type filter (optional)
	 * @return array Array of tracking objects
	 */
	public function get_by_campaign( $campaign_id, $event_type = null ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_tracking';
		
		if ( $event_type ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} 
				 WHERE campaign_id = %d 
				 AND event_type = %s 
				 ORDER BY created_at DESC",
				$campaign_id,
				$event_type
			) );
		}
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY created_at DESC",
			$campaign_id
		) );
	}
	
	/**
	 * Get tracking events by subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return array Array of tracking objects
	 */
	public function get_by_subscriber( $subscriber_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_tracking';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE subscriber_id = %d ORDER BY created_at DESC",
			$subscriber_id
		) );
	}
	
	/**
	 * Get event count by campaign and type
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param string $event_type Event type
	 * @return int Count
	 */
	public function count_by_campaign_and_type( $campaign_id, $event_type ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_tracking';
		
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT subscriber_id) FROM {$table} 
			 WHERE campaign_id = %d AND event_type = %s",
			$campaign_id,
			$event_type
		) );
	}
	
	/**
	 * Delete tracking by subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return bool True on success, false on failure
	 */
	public function delete_by_subscriber( $subscriber_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_tracking';
		
		return $wpdb->delete(
			$table,
			['subscriber_id' => $subscriber_id],
			['%d']
		) !== false;
	}
	
	/**
	 * Detect device type from user agent
	 * 
	 * @param string $user_agent User agent string
	 * @return string Device type (desktop, mobile, tablet)
	 */
	private function detect_device_type( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return 'desktop';
		}
		
		$user_agent_lower = strtolower( $user_agent );
		
		if ( preg_match( '/mobile|android|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $user_agent_lower ) ) {
			return 'mobile';
		}
		
		if ( preg_match( '/tablet|ipad|playbook|silk/i', $user_agent_lower ) ) {
			return 'tablet';
		}
		
		return 'desktop';
	}
	
	/**
	 * Detect email client from user agent
	 * 
	 * @param string $user_agent User agent string
	 * @return string Email client name
	 */
	private function detect_email_client( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return 'unknown';
		}
		
		$user_agent_lower = strtolower( $user_agent );
		
		if ( strpos( $user_agent_lower, 'gmail' ) !== false ) {
			return 'gmail';
		}
		
		if ( strpos( $user_agent_lower, 'outlook' ) !== false || strpos( $user_agent_lower, 'microsoft' ) !== false ) {
			return 'outlook';
		}
		
		if ( strpos( $user_agent_lower, 'apple' ) !== false || strpos( $user_agent_lower, 'mail' ) !== false ) {
			return 'apple-mail';
		}
		
		if ( strpos( $user_agent_lower, 'yahoo' ) !== false ) {
			return 'yahoo';
		}
		
		return 'unknown';
	}
	
	/**
	 * Get client IP address
	 * 
	 * @return string IP address
	 */
	private function get_client_ip() {
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		];
		
		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( $_SERVER[ $key ] );
				// Handle comma-separated IPs (X-Forwarded-For)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		
		return '0.0.0.0';
	}
}

