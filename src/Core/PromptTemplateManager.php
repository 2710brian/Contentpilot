<?php

namespace AEBG\Core;

/**
 * Prompt Template Manager
 * 
 * Handles all prompt template CRUD operations and business logic
 * 
 * @package AEBG\Core
 */
class PromptTemplateManager {
	
	/**
	 * Ensure table exists before operations
	 */
	private static function ensure_table_exists() {
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensurePromptTemplatesTable();
		}
	}
	
	/**
	 * Create a new prompt template
	 * 
	 * @param array $data Template data
	 * @return int|\WP_Error Template ID or error
	 */
	public static function create( $data ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		// Validate and sanitize data
		$validated = self::validate( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		
		$sanitized = self::sanitize( $validated );
		
		// Set user_id if not provided
		if ( ! isset( $sanitized['user_id'] ) ) {
			$sanitized['user_id'] = get_current_user_id();
		}
		
		// Insert template
		$result = $wpdb->insert(
			$wpdb->prefix . 'aebg_prompt_templates',
			$sanitized,
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
		);
		
		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Failed to create template.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		$template_id = $wpdb->insert_id;
		
		// Clear cache
		self::clear_cache( $sanitized['user_id'] );
		
		return $template_id;
	}
	
	/**
	 * Get a template by ID
	 * 
	 * @param int $id Template ID
	 * @return array|\WP_Error Template data or error
	 */
	public static function get( $id ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'invalid_id', __( 'Invalid template ID.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Check if table exists before querying
		$table_name = $wpdb->prefix . 'aebg_prompt_templates';
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			return new \WP_Error( 'table_not_found', __( 'Templates table does not exist. Please deactivate and reactivate the plugin.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		$template = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aebg_prompt_templates WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		
		if ( false === $template ) {
			// Database error
			return new \WP_Error( 'db_error', __( 'Database error: ', 'aebg' ) . $wpdb->last_error, [ 'status' => 500 ] );
		}
		
		if ( ! $template ) {
			return new \WP_Error( 'not_found', __( 'Template not found.', 'aebg' ), [ 'status' => 404 ] );
		}
		
		return self::format_template( $template );
	}
	
	/**
	 * Update a template
	 * 
	 * @param int   $id   Template ID
	 * @param array $data Updated data
	 * @return bool|\WP_Error Success or error
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'invalid_id', __( 'Invalid template ID.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Check if template exists
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		
		// Check permissions
		$current_user_id = get_current_user_id();
		if ( $existing['user_id'] != $current_user_id && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'permission_denied', __( 'You do not have permission to edit this template.', 'aebg' ), [ 'status' => 403 ] );
		}
		
		// Validate and sanitize
		$validated = self::validate( $data, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		
		$sanitized = self::sanitize( $validated );
		
		// Don't allow changing user_id
		unset( $sanitized['user_id'] );
		
		// Update template
		$result = $wpdb->update(
			$wpdb->prefix . 'aebg_prompt_templates',
			$sanitized,
			[ 'id' => $id ],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ],
			[ '%d' ]
		);
		
		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Failed to update template.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		// Clear cache
		self::clear_cache( $existing['user_id'] );
		
		return true;
	}
	
	/**
	 * Delete a template
	 * 
	 * @param int $id Template ID
	 * @return bool|\WP_Error Success or error
	 */
	public static function delete( $id ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'invalid_id', __( 'Invalid template ID.', 'aebg' ), [ 'status' => 400 ] );
		}
		
		// Check if template exists and get user_id for cache clearing
		$existing = self::get( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		
		// Check permissions
		$current_user_id = get_current_user_id();
		if ( $existing['user_id'] != $current_user_id && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'permission_denied', __( 'You do not have permission to delete this template.', 'aebg' ), [ 'status' => 403 ] );
		}
		
		// Delete template
		$result = $wpdb->delete(
			$wpdb->prefix . 'aebg_prompt_templates',
			[ 'id' => $id ],
			[ '%d' ]
		);
		
		if ( false === $result ) {
			return new \WP_Error( 'db_error', __( 'Failed to delete template.', 'aebg' ), [ 'status' => 500 ] );
		}
		
		// Clear cache
		self::clear_cache( $existing['user_id'] );
		
		return true;
	}
	
	/**
	 * Get user's templates
	 * 
	 * @param int   $user_id User ID
	 * @param array $args    Query arguments
	 * @return array Templates array
	 */
	public static function get_user_templates( $user_id, $args = [] ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$user_id = absint( $user_id );
		$defaults = [
			'per_page' => 20,
			'page'      => 1,
			'category'  => '',
			'search'    => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		];
		
		$args = wp_parse_args( $args, $defaults );
		
		// Build query
		$where = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];
		
		if ( ! empty( $args['category'] ) ) {
			$where[] = $wpdb->prepare( 'category = %s', $args['category'] );
		}
		
		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR description LIKE %s OR prompt LIKE %s)', $search, $search, $search );
		}
		
		$where_clause = implode( ' AND ', $where );
		
		// Validate orderby
		$allowed_orderby = [ 'name', 'created_at', 'updated_at', 'usage_count', 'last_used_at' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		
		// Get total count
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_prompt_templates WHERE {$where_clause}"
		);
		
		// Get templates
		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$templates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aebg_prompt_templates 
				WHERE {$where_clause} 
				ORDER BY {$orderby} {$order} 
				LIMIT %d OFFSET %d",
				$args['per_page'],
				$offset
			),
			ARRAY_A
		);
		
		$formatted = [];
		foreach ( $templates as $template ) {
			$formatted[] = self::format_template( $template );
		}
		
		return [
			'templates' => $formatted,
			'total'     => (int) $total,
			'pages'     => (int) ceil( $total / $args['per_page'] ),
			'page'      => (int) $args['page'],
		];
	}
	
	/**
	 * Get public templates
	 * 
	 * @param array $args Query arguments
	 * @return array Templates array
	 */
	public static function get_public_templates( $args = [] ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$defaults = [
			'per_page' => 20,
			'page'      => 1,
			'category'  => '',
			'search'    => '',
			'orderby'   => 'usage_count',
			'order'     => 'DESC',
		];
		
		$args = wp_parse_args( $args, $defaults );
		
		// Build query
		$where = [ 'is_public = 1' ];
		
		if ( ! empty( $args['category'] ) ) {
			$where[] = $wpdb->prepare( 'category = %s', $args['category'] );
		}
		
		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(name LIKE %s OR description LIKE %s OR prompt LIKE %s)', $search, $search, $search );
		}
		
		$where_clause = implode( ' AND ', $where );
		
		// Validate orderby
		$allowed_orderby = [ 'name', 'created_at', 'updated_at', 'usage_count', 'last_used_at' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'usage_count';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		
		// Get total count
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_prompt_templates WHERE {$where_clause}"
		);
		
		// Get templates
		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$templates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aebg_prompt_templates 
				WHERE {$where_clause} 
				ORDER BY {$orderby} {$order} 
				LIMIT %d OFFSET %d",
				$args['per_page'],
				$offset
			),
			ARRAY_A
		);
		
		$formatted = [];
		foreach ( $templates as $template ) {
			$formatted[] = self::format_template( $template );
		}
		
		return [
			'templates' => $formatted,
			'total'     => (int) $total,
			'pages'     => (int) ceil( $total / $args['per_page'] ),
			'page'      => (int) $args['page'],
		];
	}
	
	/**
	 * Get all templates (user's + public)
	 * 
	 * @param int   $user_id User ID
	 * @param array $args    Query arguments
	 * @return array Templates array
	 */
	public static function get_all_templates( $user_id, $args = [] ) {
		$user_templates = self::get_user_templates( $user_id, $args );
		$public_templates = self::get_public_templates( $args );
		
		// Merge and deduplicate (in case user has public templates)
		$all_templates = [];
		$seen_ids = [];
		
		foreach ( $user_templates['templates'] as $template ) {
			$all_templates[] = $template;
			$seen_ids[] = $template['id'];
		}
		
		foreach ( $public_templates['templates'] as $template ) {
			if ( ! in_array( $template['id'], $seen_ids, true ) ) {
				$all_templates[] = $template;
				$seen_ids[] = $template['id'];
			}
		}
		
		return [
			'templates' => $all_templates,
			'total'     => $user_templates['total'] + $public_templates['total'],
			'pages'     => max( $user_templates['pages'], $public_templates['pages'] ),
			'page'      => $args['page'] ?? 1,
		];
	}
	
	/**
	 * Search templates
	 * 
	 * @param string $query Search query
	 * @param array  $args  Additional arguments
	 * @return array Templates array
	 */
	public static function search( $query, $args = [] ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$user_id = get_current_user_id();
		$defaults = [
			'per_page' => 20,
			'page'      => 1,
			'include_public' => true,
		];
		
		$args = wp_parse_args( $args, $defaults );
		
		// Build search query
		$search = '%' . $wpdb->esc_like( $query ) . '%';
		$where = [
			$wpdb->prepare( '(name LIKE %s OR description LIKE %s OR prompt LIKE %s)', $search, $search, $search )
		];
		
		if ( $args['include_public'] ) {
			$where[] = $wpdb->prepare( '(user_id = %d OR is_public = 1)', $user_id );
		} else {
			$where[] = $wpdb->prepare( 'user_id = %d', $user_id );
		}
		
		$where_clause = implode( ' AND ', $where );
		
		// Get total count
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}aebg_prompt_templates WHERE {$where_clause}"
		);
		
		// Get templates
		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$templates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aebg_prompt_templates 
				WHERE {$where_clause} 
				ORDER BY usage_count DESC, created_at DESC 
				LIMIT %d OFFSET %d",
				$args['per_page'],
				$offset
			),
			ARRAY_A
		);
		
		$formatted = [];
		foreach ( $templates as $template ) {
			$formatted[] = self::format_template( $template );
		}
		
		return [
			'templates' => $formatted,
			'total'     => (int) $total,
			'pages'     => (int) ceil( $total / $args['per_page'] ),
			'page'      => (int) $args['page'],
		];
	}
	
	/**
	 * Increment usage count
	 * 
	 * @param int $id Template ID
	 * @return bool Success
	 */
	public static function increment_usage( $id ) {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}
		
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}aebg_prompt_templates 
				SET usage_count = usage_count + 1, 
				    last_used_at = NOW() 
				WHERE id = %d",
				$id
			)
		);
		
		// Clear cache
		$template = self::get( $id );
		if ( ! is_wp_error( $template ) ) {
			self::clear_cache( $template['user_id'] );
		}
		
		return $result !== false;
	}
	
	/**
	 * Get all categories
	 * 
	 * @return array Categories array
	 */
	public static function get_categories() {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$categories = $wpdb->get_col(
			"SELECT DISTINCT category FROM {$wpdb->prefix}aebg_prompt_templates 
			WHERE category IS NOT NULL AND category != '' 
			ORDER BY category ASC"
		);
		
		return $categories ?: [];
	}
	
	/**
	 * Get all tags
	 * 
	 * @return array Tags array
	 */
	public static function get_tags() {
		global $wpdb;
		
		self::ensure_table_exists();
		
		$tags_json = $wpdb->get_col(
			"SELECT tags FROM {$wpdb->prefix}aebg_prompt_templates 
			WHERE tags IS NOT NULL AND tags != 'null' AND tags != '[]'"
		);
		
		$all_tags = [];
		foreach ( $tags_json as $tags_str ) {
			$tags = json_decode( $tags_str, true );
			if ( is_array( $tags ) ) {
				$all_tags = array_merge( $all_tags, $tags );
			}
		}
		
		return array_unique( $all_tags );
	}
	
	/**
	 * Validate template data
	 * 
	 * @param array $data  Template data
	 * @param bool  $is_update Whether this is an update
	 * @return array|\WP_Error Validated data or error
	 */
	private static function validate( $data, $is_update = false ) {
		$errors = [];
		
		if ( ! $is_update ) {
			if ( empty( $data['name'] ) ) {
				$errors[] = __( 'Template name is required.', 'aebg' );
			}
			
			if ( empty( $data['prompt'] ) ) {
				$errors[] = __( 'Prompt text is required.', 'aebg' );
			}
		} else {
			if ( isset( $data['name'] ) && empty( $data['name'] ) ) {
				$errors[] = __( 'Template name cannot be empty.', 'aebg' );
			}
			
			if ( isset( $data['prompt'] ) && empty( $data['prompt'] ) ) {
				$errors[] = __( 'Prompt text cannot be empty.', 'aebg' );
			}
		}
		
		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'validation_error', implode( ' ', $errors ), [ 'status' => 400 ] );
		}
		
		return $data;
	}
	
	/**
	 * Sanitize template data
	 * 
	 * @param array $data Template data
	 * @return array Sanitized data
	 */
	private static function sanitize( $data ) {
		$sanitized = [];
		
		if ( isset( $data['user_id'] ) ) {
			$sanitized['user_id'] = absint( $data['user_id'] );
		}
		
		if ( isset( $data['name'] ) ) {
			$sanitized['name'] = sanitize_text_field( $data['name'] );
		}
		
		if ( isset( $data['description'] ) ) {
			$sanitized['description'] = sanitize_textarea_field( $data['description'] );
		}
		
		if ( isset( $data['prompt'] ) ) {
			$sanitized['prompt'] = wp_kses_post( $data['prompt'] );
		}
		
		if ( isset( $data['category'] ) ) {
			$sanitized['category'] = sanitize_text_field( $data['category'] );
			if ( empty( $sanitized['category'] ) ) {
				$sanitized['category'] = 'general';
			}
		}
		
		if ( isset( $data['tags'] ) ) {
			if ( is_array( $data['tags'] ) ) {
				$sanitized['tags'] = wp_json_encode( array_map( 'sanitize_text_field', $data['tags'] ) );
			} elseif ( is_string( $data['tags'] ) ) {
				$decoded = json_decode( $data['tags'], true );
				if ( is_array( $decoded ) ) {
					$sanitized['tags'] = wp_json_encode( array_map( 'sanitize_text_field', $decoded ) );
				} else {
					$sanitized['tags'] = null;
				}
			} else {
				$sanitized['tags'] = null;
			}
		}
		
		if ( isset( $data['widget_types'] ) ) {
			if ( is_array( $data['widget_types'] ) ) {
				$sanitized['widget_types'] = wp_json_encode( array_map( 'sanitize_key', $data['widget_types'] ) );
			} elseif ( is_string( $data['widget_types'] ) ) {
				$decoded = json_decode( $data['widget_types'], true );
				if ( is_array( $decoded ) ) {
					$sanitized['widget_types'] = wp_json_encode( array_map( 'sanitize_key', $decoded ) );
				} else {
					$sanitized['widget_types'] = null;
				}
			} else {
				$sanitized['widget_types'] = null;
			}
		}
		
		if ( isset( $data['is_public'] ) ) {
			$sanitized['is_public'] = (int) (bool) $data['is_public'];
		}
		
		return $sanitized;
	}
	
	/**
	 * Format template data
	 * 
	 * @param array $template Raw template data
	 * @return array Formatted template data
	 */
	private static function format_template( $template ) {
		$formatted = [
			'id'           => (int) $template['id'],
			'user_id'      => (int) $template['user_id'],
			'name'          => $template['name'],
			'description'   => $template['description'] ?? '',
			'prompt'        => $template['prompt'],
			'category'      => $template['category'] ?? 'general',
			'tags'          => [],
			'widget_types'  => null,
			'is_public'     => (bool) $template['is_public'],
			'usage_count'   => (int) $template['usage_count'],
			'last_used_at'  => $template['last_used_at'] ?? null,
			'created_at'    => $template['created_at'],
			'updated_at'    => $template['updated_at'],
		];
		
		// Parse JSON fields
		if ( ! empty( $template['tags'] ) && $template['tags'] !== 'null' ) {
			$tags = json_decode( $template['tags'], true );
			if ( is_array( $tags ) ) {
				$formatted['tags'] = $tags;
			}
		}
		
		if ( ! empty( $template['widget_types'] ) && $template['widget_types'] !== 'null' ) {
			$widget_types = json_decode( $template['widget_types'], true );
			if ( is_array( $widget_types ) ) {
				$formatted['widget_types'] = $widget_types;
			}
		}
		
		return $formatted;
	}
	
	/**
	 * Clear cache for user
	 * 
	 * @param int $user_id User ID
	 */
	private static function clear_cache( $user_id ) {
		// Clear user templates cache
		delete_transient( 'aebg_templates_user_' . $user_id );
		
		// Clear public templates cache
		delete_transient( 'aebg_templates_public' );
		
		// Clear categories cache
		delete_transient( 'aebg_template_categories' );
		
		// Clear tags cache
		delete_transient( 'aebg_template_tags' );
	}
}

