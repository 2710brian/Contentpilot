<?php

namespace AEBG\EmailMarketing\Repositories;

/**
 * Campaign Repository
 * 
 * Handles all database operations for email campaigns
 * 
 * @package AEBG\EmailMarketing\Repositories
 */
class CampaignRepository {
	/**
	 * Get campaign by ID
	 * 
	 * @param int $campaign_id Campaign ID
	 * @return object|null Campaign object or null if not found
	 */
	public function get( $campaign_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_campaigns';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			$campaign_id
		) );
	}
	
	/**
	 * Get campaigns by post ID
	 * 
	 * @param int $post_id Post ID
	 * @return array Array of campaign objects
	 */
	public function get_by_post( $post_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_campaigns';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE post_id = %d ORDER BY created_at DESC",
			$post_id
		) );
	}
	
	/**
	 * Get all campaigns with filters
	 * 
	 * @param array $filters Filters (campaign_type, status, user_id)
	 * @param int $limit Limit results
	 * @param int $offset Offset
	 * @return array Array of campaign objects
	 */
	public function get_all( $filters = [], $limit = 100, $offset = 0 ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_campaigns';
		$where = ['1=1'];
		$params = [];
		
		if ( ! empty( $filters['campaign_type'] ) ) {
			$where[] = 'campaign_type = %s';
			$params[] = $filters['campaign_type'];
		}
		
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$params[] = $filters['status'];
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
		
		return $wpdb->get_results( $wpdb->prepare( $query, $params ) );
	}
	
	/**
	 * Create campaign
	 * 
	 * @param array $data Campaign data
	 * @return int|false Campaign ID on success, false on failure
	 */
	public function create( $data ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_campaigns';
		
		$defaults = [
			'post_id' => null,
			'campaign_name' => '',
			'campaign_type' => 'manual',
			'trigger_event' => null,
			'template_id' => null,
			'subject' => '',
			'content_html' => null,
			'content_text' => null,
			'status' => 'draft',
			'scheduled_at' => null,
			'sent_at' => null,
			'list_ids' => null,
			'settings' => null,
			'stats' => null,
			'user_id' => get_current_user_id() ?: 1,
		];
		
		$data = wp_parse_args( $data, $defaults );
		
		// Encode JSON fields
		if ( is_array( $data['list_ids'] ) ) {
			$data['list_ids'] = wp_json_encode( $data['list_ids'] );
		}
		
		if ( is_array( $data['settings'] ) ) {
			$data['settings'] = wp_json_encode( $data['settings'] );
		}
		
		if ( is_array( $data['stats'] ) ) {
			$data['stats'] = wp_json_encode( $data['stats'] );
		}
		
		$result = $wpdb->insert(
			$table,
			[
				'post_id' => $data['post_id'],
				'campaign_name' => sanitize_text_field( $data['campaign_name'] ),
				'campaign_type' => sanitize_text_field( $data['campaign_type'] ),
				'trigger_event' => $data['trigger_event'] ? sanitize_text_field( $data['trigger_event'] ) : null,
				'template_id' => $data['template_id'],
				'subject' => sanitize_text_field( $data['subject'] ),
				'content_html' => wp_kses_post( $data['content_html'] ),
				'content_text' => sanitize_textarea_field( $data['content_text'] ),
				'status' => sanitize_text_field( $data['status'] ),
				'scheduled_at' => $data['scheduled_at'],
				'sent_at' => $data['sent_at'],
				'list_ids' => $data['list_ids'],
				'settings' => $data['settings'],
				'stats' => $data['stats'],
				'user_id' => (int) $data['user_id'],
			],
			['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
		);
		
		if ( $result === false ) {
			return false;
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param array $data Data to update
	 * @return bool True on success, false on failure
	 */
	public function update( $campaign_id, $data ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_campaigns';
		
		$update_data = [];
		$format = [];
		
		if ( isset( $data['campaign_name'] ) ) {
			$update_data['campaign_name'] = sanitize_text_field( $data['campaign_name'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['subject'] ) ) {
			$update_data['subject'] = sanitize_text_field( $data['subject'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['content_html'] ) ) {
			$update_data['content_html'] = wp_kses_post( $data['content_html'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['content_text'] ) ) {
			$update_data['content_text'] = sanitize_textarea_field( $data['content_text'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['status'] ) ) {
			$update_data['status'] = sanitize_text_field( $data['status'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['scheduled_at'] ) ) {
			$update_data['scheduled_at'] = $data['scheduled_at'];
			$format[] = '%s';
		}
		
		if ( isset( $data['sent_at'] ) ) {
			$update_data['sent_at'] = $data['sent_at'];
			$format[] = '%s';
		}
		
		if ( isset( $data['list_ids'] ) ) {
			if ( is_array( $data['list_ids'] ) ) {
				$update_data['list_ids'] = wp_json_encode( $data['list_ids'] );
			} else {
				$update_data['list_ids'] = $data['list_ids'];
			}
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
		
		if ( isset( $data['stats'] ) ) {
			if ( is_array( $data['stats'] ) ) {
				$update_data['stats'] = wp_json_encode( $data['stats'] );
			} else {
				$update_data['stats'] = $data['stats'];
			}
			$format[] = '%s';
		}
		
		if ( empty( $update_data ) ) {
			return false;
		}
		
		return $wpdb->update(
			$table,
			$update_data,
			['id' => $campaign_id],
			$format,
			['%d']
		) !== false;
	}
	
	/**
	 * Delete campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @return bool True on success, false on failure
	 */
	public function delete( $campaign_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_campaigns';
		
		return $wpdb->delete(
			$table,
			['id' => $campaign_id],
			['%d']
		) !== false;
	}
	
	/**
	 * Get campaigns by subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return array Array of campaign objects
	 */
	public function get_by_subscriber( $subscriber_id ) {
		global $wpdb;
		
		$campaigns_table = $wpdb->prefix . 'aebg_email_campaigns';
		$queue_table = $wpdb->prefix . 'aebg_email_queue';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT c.* 
			 FROM {$campaigns_table} c
			 INNER JOIN {$queue_table} q ON c.id = q.campaign_id
			 WHERE q.subscriber_id = %d
			 ORDER BY c.created_at DESC",
			$subscriber_id
		) );
	}
}

