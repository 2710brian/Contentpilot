<?php

namespace AEBG\EmailMarketing\Repositories;

/**
 * Subscriber Repository
 * 
 * Handles all database operations for email subscribers
 * 
 * @package AEBG\EmailMarketing\Repositories
 */
class SubscriberRepository {
	/**
	 * Get subscriber by ID
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return object|null Subscriber object or null if not found
	 */
	public function get( $subscriber_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			$subscriber_id
		) );
	}
	
	/**
	 * Get subscriber by email and list ID
	 * 
	 * @param string $email Email address
	 * @param int $list_id List ID
	 * @return object|null Subscriber object or null if not found
	 */
	public function get_by_email_and_list( $email, $list_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE email = %s AND list_id = %d LIMIT 1",
			$email,
			$list_id
		) );
	}
	
	/**
	 * Get subscriber by email (any list)
	 * 
	 * @param string $email Email address
	 * @return object|null Subscriber object or null if not found
	 */
	public function get_by_email( $email ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE email = %s LIMIT 1",
			$email
		) );
	}
	
	/**
	 * Get subscribers by list ID
	 * 
	 * @param int $list_id List ID
	 * @param string $status Status filter (optional)
	 * @param int $limit Limit results
	 * @param int $offset Offset
	 * @return array Array of subscriber objects
	 */
	public function get_by_list( $list_id, $status = null, $limit = 100, $offset = 0 ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		$where = ['list_id = %d'];
		$params = [$list_id];
		
		if ( $status !== null ) {
			$where[] = 'status = %s';
			$params[] = $status;
		}
		
		$where_clause = implode( ' AND ', $where );
		$params[] = $limit;
		$params[] = $offset;
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$params
		) );
	}
	
	/**
	 * Get subscribers by list IDs (batch)
	 * 
	 * @param array $list_ids Array of list IDs
	 * @param string $status Status filter (optional)
	 * @return array Array of subscriber objects
	 */
	public function get_by_list_batch( $list_ids, $status = 'confirmed' ) {
		global $wpdb;
		
		if ( empty( $list_ids ) ) {
			return [];
		}
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		$placeholders = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
		
		$params = array_merge( $list_ids, [$status] );
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} 
			 WHERE list_id IN ($placeholders) 
			 AND status = %s 
			 ORDER BY created_at DESC",
			$params
		) );
	}
	
	/**
	 * Search subscribers
	 * 
	 * @param string $query Search query
	 * @param array $filters Additional filters
	 * @param int $limit Limit results
	 * @return array Array of subscriber objects
	 */
	public function search( $query, $filters = [], $limit = 50 ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		$search_term = '%' . $wpdb->esc_like( $query ) . '%';
		
		$where = [
			'(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)'
		];
		$params = [$search_term, $search_term, $search_term];
		
		if ( ! empty( $filters['list_id'] ) ) {
			$where[] = 'list_id = %d';
			$params[] = $filters['list_id'];
		}
		
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$params[] = $filters['status'];
		}
		
		$where_clause = implode( ' AND ', $where );
		$params[] = $limit;
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} 
			 WHERE {$where_clause} 
			 ORDER BY created_at DESC 
			 LIMIT %d",
			$params
		) );
	}
	
	/**
	 * Create subscriber
	 * 
	 * @param array $data Subscriber data
	 * @return int|false Subscriber ID on success, false on failure
	 */
	public function create( $data ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		$defaults = [
			'list_id' => 0,
			'email' => '',
			'first_name' => '',
			'last_name' => '',
			'status' => 'pending',
			'source' => 'widget',
			'ip_address' => $this->get_client_ip(),
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'opt_in_token' => null,
			'opt_in_confirmed_at' => null,
			'unsubscribed_at' => null,
			'unsubscribe_token' => null,
			'metadata' => null,
		];
		
		$data = wp_parse_args( $data, $defaults );
		
		// Encode metadata if array
		if ( is_array( $data['metadata'] ) ) {
			$data['metadata'] = wp_json_encode( $data['metadata'] );
		}
		
		$result = $wpdb->insert(
			$table,
			[
				'list_id' => (int) $data['list_id'],
				'email' => sanitize_email( $data['email'] ),
				'first_name' => sanitize_text_field( $data['first_name'] ),
				'last_name' => sanitize_text_field( $data['last_name'] ),
				'status' => sanitize_text_field( $data['status'] ),
				'source' => sanitize_text_field( $data['source'] ),
				'ip_address' => sanitize_text_field( $data['ip_address'] ),
				'user_agent' => sanitize_text_field( $data['user_agent'] ),
				'opt_in_token' => $data['opt_in_token'] ? sanitize_text_field( $data['opt_in_token'] ) : null,
				'opt_in_confirmed_at' => $data['opt_in_confirmed_at'],
				'unsubscribed_at' => $data['unsubscribed_at'],
				'unsubscribe_token' => $data['unsubscribe_token'] ? sanitize_text_field( $data['unsubscribe_token'] ) : null,
				'metadata' => $data['metadata'],
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
		);
		
		if ( $result === false ) {
			return false;
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @param array $data Data to update
	 * @return bool True on success, false on failure
	 */
	public function update( $subscriber_id, $data ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		$update_data = [];
		$format = [];
		
		if ( isset( $data['email'] ) ) {
			$update_data['email'] = sanitize_email( $data['email'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['first_name'] ) ) {
			$update_data['first_name'] = sanitize_text_field( $data['first_name'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['last_name'] ) ) {
			$update_data['last_name'] = sanitize_text_field( $data['last_name'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $data['status'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['opt_in_token'] ) ) {
			$update_data['opt_in_token'] = $data['opt_in_token'] ? sanitize_text_field( $data['opt_in_token'] ) : null;
			$format[] = '%s';
		}
		
		if ( isset( $data['opt_in_confirmed_at'] ) ) {
			$update_data['opt_in_confirmed_at'] = $data['opt_in_confirmed_at'];
			$format[] = '%s';
		}
		
		if ( isset( $data['unsubscribed_at'] ) ) {
			$update_data['unsubscribed_at'] = $data['unsubscribed_at'];
			$format[] = '%s';
		}
		
		if ( isset( $data['unsubscribe_token'] ) ) {
			$update_data['unsubscribe_token'] = $data['unsubscribe_token'] ? sanitize_text_field( $data['unsubscribe_token'] ) : null;
			$format[] = '%s';
		}
		
		if ( isset( $data['metadata'] ) ) {
			if ( is_array( $data['metadata'] ) ) {
				$update_data['metadata'] = wp_json_encode( $data['metadata'] );
			} else {
				$update_data['metadata'] = $data['metadata'];
			}
			$format[] = '%s';
		}
		
		if ( empty( $update_data ) ) {
			return false;
		}
		
		return $wpdb->update(
			$table,
			$update_data,
			['id' => $subscriber_id],
			$format,
			['%d']
		) !== false;
	}
	
	/**
	 * Delete subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return bool True on success, false on failure
	 */
	public function delete( $subscriber_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		return $wpdb->delete(
			$table,
			['id' => $subscriber_id],
			['%d']
		) !== false;
	}
	
	/**
	 * Get subscriber count by list
	 * 
	 * @param int $list_id List ID
	 * @param string $status Status filter (optional)
	 * @return int Count
	 */
	public function count_by_list( $list_id, $status = null ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_subscribers';
		
		if ( $status !== null ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE list_id = %d AND status = %s",
				$list_id,
				$status
			) );
		}
		
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE list_id = %d",
			$list_id
		) );
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

