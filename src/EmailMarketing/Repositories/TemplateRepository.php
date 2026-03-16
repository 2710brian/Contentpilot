<?php

namespace AEBG\EmailMarketing\Repositories;

/**
 * Template Repository
 * 
 * Handles all database operations for email templates
 * 
 * @package AEBG\EmailMarketing\Repositories
 */
class TemplateRepository {
	/**
	 * Get template by ID
	 * 
	 * @param int $template_id Template ID
	 * @return object|null Template object or null if not found
	 */
	public function get( $template_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			$template_id
		) );
	}
	
	/**
	 * Get template by type
	 * 
	 * @param string $template_type Template type
	 * @param bool $default_only Get only default template
	 * @return object|null Template object or null if not found
	 */
	public function get_by_type( $template_type, $default_only = true ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		
		if ( $default_only ) {
			return $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} 
				 WHERE template_type = %s 
				 AND is_default = 1 
				 AND is_active = 1 
				 LIMIT 1",
				$template_type
			) );
		}
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} 
			 WHERE template_type = %s 
			 AND is_active = 1 
			 ORDER BY is_default DESC, created_at DESC 
			 LIMIT 1",
			$template_type
		) );
	}
	
	/**
	 * Get all templates with filters
	 * 
	 * @param array $filters Filters (template_type, is_active, user_id)
	 * @return array Array of template objects
	 */
	public function get_all( $filters = [] ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		$where = ['1=1'];
		$params = [];
		
		if ( ! empty( $filters['template_type'] ) ) {
			$where[] = 'template_type = %s';
			$params[] = $filters['template_type'];
		}
		
		if ( isset( $filters['is_active'] ) ) {
			$where[] = 'is_active = %d';
			$params[] = $filters['is_active'] ? 1 : 0;
		}
		
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$params[] = $filters['user_id'];
		}
		
		$where_clause = implode( ' AND ', $where );
		
		$query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY is_default DESC, created_at DESC";
		
		if ( ! empty( $params ) ) {
			return $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		}
		
		return $wpdb->get_results( $query );
	}
	
	/**
	 * Create template
	 * 
	 * @param array $data Template data
	 * @return int|false Template ID on success, false on failure
	 */
	public function create( $data ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		
		$defaults = [
			'template_name' => '',
			'template_type' => 'custom',
			'subject_template' => '',
			'content_html' => '',
			'content_text' => '',
			'variables' => null,
			'is_default' => 0,
			'is_active' => 1,
			'user_id' => get_current_user_id() ?: 1,
		];
		
		$data = wp_parse_args( $data, $defaults );
		
		// Encode variables if array
		if ( is_array( $data['variables'] ) ) {
			$data['variables'] = wp_json_encode( $data['variables'] );
		}
		
		// If setting as default, unset other defaults of same type
		if ( $data['is_default'] ) {
			$wpdb->update(
				$table,
				['is_default' => 0],
				['template_type' => $data['template_type']],
				['%d'],
				['%s']
			);
		}
		
		$result = $wpdb->insert(
			$table,
			[
				'template_name' => sanitize_text_field( $data['template_name'] ),
				'template_type' => sanitize_text_field( $data['template_type'] ),
				'subject_template' => sanitize_text_field( $data['subject_template'] ),
				'content_html' => wp_kses_post( $data['content_html'] ),
				'content_text' => sanitize_textarea_field( $data['content_text'] ),
				'variables' => $data['variables'],
				'is_default' => (int) $data['is_default'],
				'is_active' => (int) $data['is_active'],
				'user_id' => (int) $data['user_id'],
			],
			['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
		);
		
		if ( $result === false ) {
			return false;
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update template
	 * 
	 * @param int $template_id Template ID
	 * @param array $data Data to update
	 * @return bool True on success, false on failure
	 */
	public function update( $template_id, $data ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		
		$update_data = [];
		$format = [];
		
		if ( isset( $data['template_name'] ) ) {
			$update_data['template_name'] = sanitize_text_field( $data['template_name'] );
			$format[] = '%s';
		}
		
		if ( isset( $data['subject_template'] ) ) {
			$update_data['subject_template'] = sanitize_text_field( $data['subject_template'] );
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
		
		if ( isset( $data['variables'] ) ) {
			if ( is_array( $data['variables'] ) ) {
				$update_data['variables'] = wp_json_encode( $data['variables'] );
			} else {
				$update_data['variables'] = $data['variables'];
			}
			$format[] = '%s';
		}
		
		if ( isset( $data['is_default'] ) ) {
			$update_data['is_default'] = (int) $data['is_default'];
			$format[] = '%d';
			
			// If setting as default, unset other defaults of same type
			if ( $data['is_default'] ) {
				$template = $this->get( $template_id );
				if ( $template ) {
					$wpdb->update(
						$table,
						['is_default' => 0],
						[
							'template_type' => $template->template_type,
							'id' => ['<>', $template_id],
						],
						['%d'],
						['%s', '%d']
					);
				}
			}
		}
		
		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = (int) $data['is_active'];
			$format[] = '%d';
		}
		
		if ( empty( $update_data ) ) {
			return false;
		}
		
		return $wpdb->update(
			$table,
			$update_data,
			['id' => $template_id],
			$format,
			['%d']
		) !== false;
	}
	
	/**
	 * Delete template
	 * 
	 * @param int $template_id Template ID
	 * @return bool True on success, false on failure
	 */
	public function delete( $template_id ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		
		return $wpdb->delete(
			$table,
			['id' => $template_id],
			['%d']
		) !== false;
	}
}

