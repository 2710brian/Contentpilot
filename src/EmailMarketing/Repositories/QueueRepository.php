<?php

namespace AEBG\EmailMarketing\Repositories;

/**
 * Queue Repository
 * 
 * Handles all database operations for email queue
 * 
 * @package AEBG\EmailMarketing\Repositories
 */
class QueueRepository {
	/**
	 * Check if the email queue table exists.
	 *
	 * @return bool
	 */
	private function table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'aebg_email_queue';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
				DB_NAME,
				$table
			)
		);
	}

	/**
	 * Ensure the email queue table exists, attempting to recreate it if missing.
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
				error_log( '[AEBG] Email queue table is missing and could not be created.' );
			}
			return false;
		}

		return true;
	}

	/**
	 * Get queue item by ID
	 * 
	 * @param int $queue_id Queue ID
	 * @return object|null Queue object or null if not found
	 */
	public function get( $queue_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return null;
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			$queue_id
		) );
	}
	
	/**
	 * Get pending emails
	 * 
	 * @param int $limit Limit results
	 * @return array Array of queue objects
	 */
	public function get_pending( $limit = 50 ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return [];
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} 
			 WHERE status = 'pending' 
			 AND scheduled_at <= NOW() 
			 ORDER BY scheduled_at ASC 
			 LIMIT %d",
			$limit
		) );
	}
	
	/**
	 * Get queue items by campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @return array Array of queue objects
	 */
	public function get_by_campaign( $campaign_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return [];
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY created_at ASC",
			$campaign_id
		) );
	}
	
	/**
	 * Get queue items by subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return array Array of queue objects
	 */
	public function get_by_subscriber( $subscriber_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return [];
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE subscriber_id = %d ORDER BY created_at DESC",
			$subscriber_id
		) );
	}
	
	/**
	 * Add to queue
	 * 
	 * @param array $data Queue data
	 * @return int|false Queue ID on success, false on failure
	 */
	public function create( $data ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		$defaults = [
			'campaign_id' => 0,
			'subscriber_id' => 0,
			'email' => '',
			'subject' => '',
			'content_html' => '',
			'content_text' => null,
			'status' => 'pending',
			'scheduled_at' => current_time( 'mysql' ),
			'sent_at' => null,
			'error_message' => null,
			'retry_count' => 0,
			'max_retries' => 3,
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
				'campaign_id' => (int) $data['campaign_id'],
				'subscriber_id' => (int) $data['subscriber_id'],
				'email' => sanitize_email( $data['email'] ),
				'subject' => sanitize_text_field( $data['subject'] ),
				'content_html' => wp_kses_post( $data['content_html'] ),
				'content_text' => $data['content_text'] ? sanitize_textarea_field( $data['content_text'] ) : null,
				'status' => sanitize_text_field( $data['status'] ),
				'scheduled_at' => $data['scheduled_at'],
				'sent_at' => $data['sent_at'],
				'error_message' => $data['error_message'] ? sanitize_text_field( $data['error_message'] ) : null,
				'retry_count' => (int) $data['retry_count'],
				'max_retries' => (int) $data['max_retries'],
				'metadata' => $data['metadata'],
			],
			['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']
		);
		
		if ( $result === false ) {
			return false;
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update queue item
	 * 
	 * @param int $queue_id Queue ID
	 * @param array $data Data to update
	 * @return bool True on success, false on failure
	 */
	public function update( $queue_id, $data ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		$update_data = [];
		$format = [];
		
		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $data['status'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['sent_at'] ) ) {
			$update_data['sent_at'] = $data['sent_at'];
			$format[] = '%s';
		}
		
		if ( isset( $data['error_message'] ) ) {
			$update_data['error_message'] = $data['error_message'] ? sanitize_text_field( $data['error_message'] ) : null;
			$format[] = '%s';
		}
		
		if ( isset( $data['retry_count'] ) ) {
			$update_data['retry_count'] = (int) $data['retry_count'];
			$format[] = '%d';
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
			['id' => $queue_id],
			$format,
			['%d']
		) !== false;
	}
	
	/**
	 * Mark as sent
	 * 
	 * @param int $queue_id Queue ID
	 * @return bool True on success, false on failure
	 */
	public function mark_as_sent( $queue_id ) {
		return $this->update( $queue_id, [
			'status' => 'sent',
			'sent_at' => current_time( 'mysql' ),
		] );
	}
	
	/**
	 * Mark as failed
	 * 
	 * @param int $queue_id Queue ID
	 * @param string $error_message Error message
	 * @return bool True on success, false on failure
	 */
	public function mark_as_failed( $queue_id, $error_message ) {
		$queue = $this->get( $queue_id );
		if ( ! $queue ) {
			return false;
		}
		
		$retry_count = (int) $queue->retry_count + 1;
		$status = ( $retry_count >= (int) $queue->max_retries ) ? 'failed' : 'pending';
		
		return $this->update( $queue_id, [
			'status' => $status,
			'error_message' => $error_message,
			'retry_count' => $retry_count,
		] );
	}
	
	/**
	 * Get retry count
	 * 
	 * @param int $queue_id Queue ID
	 * @return int Retry count
	 */
	public function get_retry_count( $queue_id ) {
		$queue = $this->get( $queue_id );
		return $queue ? (int) $queue->retry_count : 0;
	}
	
	/**
	 * Schedule retry
	 * 
	 * @param int $queue_id Queue ID
	 * @param int $delay_seconds Delay in seconds
	 * @return bool True on success, false on failure
	 */
	public function schedule_retry( $queue_id, $delay_seconds = 300 ) {
		$scheduled_at = date( 'Y-m-d H:i:s', time() + $delay_seconds );
		
		return $this->update( $queue_id, [
			'status' => 'pending',
			'scheduled_at' => $scheduled_at,
		] );
	}
	
	/**
	 * Delete queue items by campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @return bool True on success, false on failure
	 */
	public function delete_by_campaign( $campaign_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->delete(
			$table,
			['campaign_id' => $campaign_id],
			['%d']
		) !== false;
	}
	
	/**
	 * Delete queue items by subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return bool True on success, false on failure
	 */
	public function delete_by_subscriber( $subscriber_id ) {
		global $wpdb;
		
		if ( ! $this->ensure_table() ) {
			return false;
		}

		$table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->delete(
			$table,
			['subscriber_id' => $subscriber_id],
			['%d']
		) !== false;
	}
}

