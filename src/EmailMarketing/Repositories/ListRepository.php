<?php

namespace AEBG\EmailMarketing\Repositories;

/**
 * List Repository
 * 
 * Handles all database operations for email lists
 * 
 * @package AEBG\EmailMarketing\Repositories
 */
class ListRepository {
	/**
	 * Check if the email lists table exists.
	 *
	 * @return bool
	 */
	private function table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'aebg_email_lists';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table
			)
		);
	}

	/**
	 * Ensure the email lists table exists, attempting to recreate it if missing.
	 *
	 * @return bool True if the table exists (after any recovery), false otherwise.
	 */
	private function ensure_table() {
		if ( $this->table_exists() ) {
			return true;
		}

		// Attempt to recreate via Installer helper if available.
		if ( class_exists( '\AEBG\Installer' ) ) {
			\AEBG\Installer::ensureEmailMarketingTables();
		}

		if ( ! $this->table_exists() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Email lists table is missing and could not be created.' );
			}
			return false;
		}

		return true;
	}

	/**
	 * Get list by ID
	 * 
	 * @param int $list_id List ID
	 * @return object|null List object or null if not found
	 */
	public function get( $list_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return null;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			$list_id
		) );
	}
	
	/**
	 * Get list by list key
	 * 
	 * @param string $list_key List key
	 * @return object|null List object or null if not found
	 */
	public function get_by_key( $list_key ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return null;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE list_key = %s LIMIT 1",
			$list_key
		) );
	}
	
	/**
	 * Get list by post ID and list key
	 * 
	 * @param int $post_id Post ID
	 * @param string $list_key List key (default: 'default')
	 * @return object|null List object or null if not found
	 */
	public function get_by_post( $post_id, $list_key = 'default' ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return null;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE post_id = %d AND list_key = %s LIMIT 1",
			$post_id,
			$list_key
		) );
	}
	
	/**
	 * Get all lists by post ID
	 * 
	 * @param int $post_id Post ID
	 * @return array Array of list objects
	 */
	public function get_by_post_id( $post_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return [];
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE post_id = %d ORDER BY created_at ASC",
			$post_id
		) );
	}
	
	/**
	 * Get all lists with filters
	 * 
	 * @param array $filters Filters (list_type, is_active, user_id)
	 * @param int $limit Limit results
	 * @param int $offset Offset
	 * @return array Array of list objects
	 */
	public function get_all( $filters = [], $limit = 100, $offset = 0 ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return [];
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		$where = ['1=1'];
		$params = [];
		
		if ( ! empty( $filters['list_type'] ) ) {
			$where[] = 'list_type = %s';
			$params[] = $filters['list_type'];
		}
		
		if ( isset( $filters['is_active'] ) ) {
			$where[] = 'is_active = %d';
			$params[] = $filters['is_active'] ? 1 : 0;
		}
		
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$params[] = $filters['user_id'];
		}
		
		if ( ! empty( $filters['post_id'] ) ) {
			$where[] = 'post_id = %d';
			$params[] = $filters['post_id'];
		}
		
		$where_clause = implode( ' AND ', $where );
		$params[] = $limit;
		$params[] = $offset;
		
		$query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		
		if ( ! empty( $params ) ) {
			return $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		}
		
		return $wpdb->get_results( $query );
	}
	
	/**
	 * Create list
	 * 
	 * @param array $data List data
	 * @return int|false List ID on success, false on failure
	 */
	public function create( $data ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		$defaults = [
			'post_id' => null,
			'list_name' => '',
			'list_type' => 'post',
			'list_key' => '',
			'description' => '',
			'settings' => null,
			'subscriber_count' => 0,
			'is_active' => 1,
			'user_id' => get_current_user_id() ?: 1,
		];
		
		$data = wp_parse_args( $data, $defaults );
		
		// Generate list_key if not provided
		if ( empty( $data['list_key'] ) ) {
			if ( $data['post_id'] ) {
				$data['list_key'] = 'post_' . $data['post_id'] . '_' . sanitize_key( $data['list_name'] );
			} else {
				$data['list_key'] = 'list_' . time() . '_' . wp_generate_password( 8, false );
			}
		}
		
		// Ensure list_key is unique
		$existing = $this->get_by_key( $data['list_key'] );
		if ( $existing ) {
			$data['list_key'] .= '_' . wp_generate_password( 4, false );
		}
		
		// Encode settings if array
		if ( is_array( $data['settings'] ) ) {
			$data['settings'] = wp_json_encode( $data['settings'] );
		}
		
		$result = $wpdb->insert(
			$table,
			[
				'post_id' => $data['post_id'],
				'list_name' => sanitize_text_field( $data['list_name'] ),
				'list_type' => sanitize_text_field( $data['list_type'] ),
				'list_key' => sanitize_key( $data['list_key'] ),
				'description' => sanitize_textarea_field( $data['description'] ),
				'settings' => $data['settings'],
				'subscriber_count' => (int) $data['subscriber_count'],
				'is_active' => (int) $data['is_active'],
				'user_id' => (int) $data['user_id'],
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
		);
		
		if ( $result === false ) {
			return false;
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update list
	 * 
	 * @param int $list_id List ID
	 * @param array $data Data to update
	 * @return bool True on success, false on failure
	 */
	public function update( $list_id, $data ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		$update_data = [];
		$format = [];
		
		if ( isset( $data['list_name'] ) ) {
			$update_data['list_name'] = sanitize_text_field( $data['list_name'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['description'] ) ) {
			$update_data['description'] = sanitize_textarea_field( $data['description'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['settings'] ) ) {
			if ( is_array( $data['settings'] ) ) {
				$update_data['settings'] = wp_json_encode( $data['settings'] );
			} else {
				$update_data['settings'] = $data['settings'];
			}
			$format[] = '%s';
		}
		
		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = (int) $data['is_active'];
			$format[] = '%d';
		}
		
		if ( isset( $data['subscriber_count'] ) ) {
			$update_data['subscriber_count'] = (int) $data['subscriber_count'];
			$format[] = '%d';
		}
		
		if ( empty( $update_data ) ) {
			return false;
		}
		
		return $wpdb->update(
			$table,
			$update_data,
			['id' => $list_id],
			$format,
			['%d']
		) !== false;
	}
	
	/**
	 * Delete list
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success, false on failure
	 */
	public function delete( $list_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->delete(
			$table,
			['id' => $list_id],
			['%d']
		) !== false;
	}
	
	/**
	 * Increment subscriber count
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success, false on failure
	 */
	public function increment_subscriber_count( $list_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET subscriber_count = subscriber_count + 1 WHERE id = %d",
			$list_id
		) ) !== false;
	}
	
	/**
	 * Decrement subscriber count
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success, false on failure
	 */
	public function decrement_subscriber_count( $list_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_lists';
		
		return $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET subscriber_count = GREATEST(subscriber_count - 1, 0) WHERE id = %d",
			$list_id
		) ) !== false;
	}
	
	/**
	 * Recalculate subscriber count
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success, false on failure
	 */
	public function recalculate_subscriber_count( $list_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$lists_table = $wpdb->prefix . 'aebg_email_lists';
		$subscribers_table = $wpdb->prefix . 'aebg_email_subscribers';
		
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$subscribers_table} WHERE list_id = %d AND status = 'confirmed'",
			$list_id
		) );
		
		return $wpdb->update(
			$lists_table,
			['subscriber_count' => (int) $count],
			['id' => $list_id],
			['%d'],
			['%d']
		) !== false;
	}
}

