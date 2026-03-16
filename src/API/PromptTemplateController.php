<?php

namespace AEBG\API;

use AEBG\Core\PromptTemplateManager;

/**
 * Prompt Template Controller
 * 
 * REST API endpoints for prompt template management
 * 
 * @package AEBG\API
 */
class PromptTemplateController extends \WP_REST_Controller {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->namespace = 'aebg/v1';
		$this->rest_base = 'prompt-templates';
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}
	
	/**
	 * Register routes
	 */
	public function register_routes() {
		// List templates
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
				],
			]
		);
		
		// Get single template
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'id' => [
							'description' => __( 'Unique identifier for the template.', 'aebg' ),
							'type'        => 'integer',
							'required'    => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
					'args'                => [
						'id' => [
							'description' => __( 'Unique identifier for the template.', 'aebg' ),
							'type'        => 'integer',
							'required'    => true,
						],
					],
				],
			]
		);
		
		// Search templates
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args'                => [
					'q' => [
						'description' => __( 'Search query.', 'aebg' ),
						'type'        => 'string',
						'required'    => true,
					],
					'per_page' => [
						'description' => __( 'Maximum number of items to be returned in result set.', 'aebg' ),
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
					],
					'page' => [
						'description' => __( 'Current page of the collection.', 'aebg' ),
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
					],
				],
			]
		);
		
		// Get categories
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/categories',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_categories' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);
		
		// Get tags
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_tags' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
			]
		);
		
		// Increment usage
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/usage',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'increment_usage' ],
				'permission_callback' => [ $this, 'get_item_permissions_check' ],
				'args'                => [
					'id' => [
						'description' => __( 'Unique identifier for the template.', 'aebg' ),
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);
	}
	
	/**
	 * Check if a given request has access to get items
	 * 
	 * @param \WP_REST_Request $request Full details about the request
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'edit_posts' );
	}
	
	/**
	 * Check if a given request has access to create items
	 * 
	 * @param \WP_REST_Request $request Full details about the request
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( 'edit_posts' );
	}
	
	/**
	 * Check if a given request has access to get a specific item
	 * 
	 * @param \WP_REST_Request $request Full details about the request
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return current_user_can( 'edit_posts' );
	}
	
	/**
	 * Check if a given request has access to update a specific item
	 * 
	 * @param \WP_REST_Request $request Full details about the request
	 * @return bool|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$template = PromptTemplateManager::get( $request['id'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		
		$current_user_id = get_current_user_id();
		if ( $template['user_id'] == $current_user_id || current_user_can( 'manage_options' ) ) {
			return true;
		}
		
		return new \WP_Error(
			'rest_cannot_edit',
			__( 'Sorry, you are not allowed to edit this template.', 'aebg' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
	
	/**
	 * Check if a given request has access to delete a specific item
	 * 
	 * @param \WP_REST_Request $request Full details about the request
	 * @return bool|\WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$template = PromptTemplateManager::get( $request['id'] );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		
		$current_user_id = get_current_user_id();
		if ( $template['user_id'] == $current_user_id || current_user_can( 'manage_options' ) ) {
			return true;
		}
		
		return new \WP_Error(
			'rest_cannot_delete',
			__( 'Sorry, you are not allowed to delete this template.', 'aebg' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
	
	/**
	 * Get a collection of items
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		// Ensure table exists
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensurePromptTemplatesTable();
		}
		
		$user_id = get_current_user_id();
		$args = [
			'per_page' => $request['per_page'],
			'page'      => $request['page'],
			'category'  => $request['category'] ?? '',
			'search'    => $request['search'] ?? '',
			'orderby'   => $request['orderby'] ?? 'created_at',
			'order'     => $request['order'] ?? 'DESC',
		];
		
		$result = PromptTemplateManager::get_all_templates( $user_id, $args );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		$response = rest_ensure_response( $result['templates'] );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', $result['pages'] );
		
		return $response;
	}
	
	/**
	 * Get a single item
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		// Ensure table exists
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensurePromptTemplatesTable();
		}
		
		$template = PromptTemplateManager::get( $request['id'] );
		
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		
		// Check if user can access this template
		$current_user_id = get_current_user_id();
		if ( $template['user_id'] != $current_user_id && ! $template['is_public'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_cannot_read',
				__( 'Sorry, you are not allowed to view this template.', 'aebg' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		
		return rest_ensure_response( $template );
	}
	
	/**
	 * Create a single item
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		$data = [
			'name'         => $request['name'],
			'description'  => $request['description'] ?? '',
			'prompt'       => $request['prompt'],
			'category'     => $request['category'] ?? 'general',
			'tags'         => $request['tags'] ?? [],
			'widget_types' => $request['widget_types'] ?? null,
			'is_public'    => $request['is_public'] ?? false,
		];
		
		$template_id = PromptTemplateManager::create( $data );
		
		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}
		
		$template = PromptTemplateManager::get( $template_id );
		$response = rest_ensure_response( $template );
		$response->set_status( 201 );
		
		return $response;
	}
	
	/**
	 * Update a single item
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		$data = [];
		
		if ( isset( $request['name'] ) ) {
			$data['name'] = $request['name'];
		}
		
		if ( isset( $request['description'] ) ) {
			$data['description'] = $request['description'];
		}
		
		if ( isset( $request['prompt'] ) ) {
			$data['prompt'] = $request['prompt'];
		}
		
		if ( isset( $request['category'] ) ) {
			$data['category'] = $request['category'];
		}
		
		if ( isset( $request['tags'] ) ) {
			$data['tags'] = $request['tags'];
		}
		
		if ( isset( $request['widget_types'] ) ) {
			$data['widget_types'] = $request['widget_types'];
		}
		
		if ( isset( $request['is_public'] ) ) {
			$data['is_public'] = $request['is_public'];
		}
		
		$result = PromptTemplateManager::update( $request['id'], $data );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		$template = PromptTemplateManager::get( $request['id'] );
		return rest_ensure_response( $template );
	}
	
	/**
	 * Delete a single item
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_item( $request ) {
		$result = PromptTemplateManager::delete( $request['id'] );
		
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		
		$response = new \WP_REST_Response();
		$response->set_data( [ 'deleted' => true, 'id' => $request['id'] ] );
		$response->set_status( 200 );
		
		return $response;
	}
	
	/**
	 * Search items
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function search_items( $request ) {
		$args = [
			'per_page'      => $request['per_page'] ?? 20,
			'page'           => $request['page'] ?? 1,
			'include_public' => true,
		];
		
		$result = PromptTemplateManager::search( $request['q'], $args );
		
		$response = rest_ensure_response( $result['templates'] );
		$response->header( 'X-WP-Total', $result['total'] );
		$response->header( 'X-WP-TotalPages', $result['pages'] );
		
		return $response;
	}
	
	/**
	 * Get categories
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_categories( $request ) {
		$categories = PromptTemplateManager::get_categories();
		return rest_ensure_response( $categories );
	}
	
	/**
	 * Get tags
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_tags( $request ) {
		$tags = PromptTemplateManager::get_tags();
		return rest_ensure_response( $tags );
	}
	
	/**
	 * Increment usage count
	 * 
	 * @param \WP_REST_Request $request Full data about the request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function increment_usage( $request ) {
		$result = PromptTemplateManager::increment_usage( $request['id'] );
		
		if ( ! $result ) {
			return new \WP_Error(
				'rest_cannot_update',
				__( 'Failed to update usage count.', 'aebg' ),
				[ 'status' => 500 ]
			);
		}
		
		$template = PromptTemplateManager::get( $request['id'] );
		return rest_ensure_response( $template );
	}
	
	/**
	 * Get collection parameters
	 * 
	 * @return array
	 */
	public function get_collection_params() {
		return [
			'context' => $this->get_context_param( [ 'default' => 'view' ] ),
			'page' => [
				'description' => __( 'Current page of the collection.', 'aebg' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'per_page' => [
				'description' => __( 'Maximum number of items to be returned in result set.', 'aebg' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
			],
			'category' => [
				'description' => __( 'Filter by category.', 'aebg' ),
				'type'        => 'string',
			],
			'search' => [
				'description' => __( 'Search query.', 'aebg' ),
				'type'        => 'string',
			],
			'orderby' => [
				'description' => __( 'Sort collection by object attribute.', 'aebg' ),
				'type'        => 'string',
				'default'     => 'created_at',
				'enum'        => [ 'name', 'created_at', 'updated_at', 'usage_count', 'last_used_at' ],
			],
			'order' => [
				'description' => __( 'Order sort attribute ascending or descending.', 'aebg' ),
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => [ 'ASC', 'DESC' ],
			],
		];
	}
	
	/**
	 * Get the item schema
	 * 
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		
		$schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'prompt_template',
			'type'       => 'object',
			'properties' => [
				'id' => [
					'description' => __( 'Unique identifier for the template.', 'aebg' ),
					'type'        => 'integer',
					'readonly'    => true,
				],
				'user_id' => [
					'description' => __( 'User ID of the template creator.', 'aebg' ),
					'type'        => 'integer',
					'readonly'    => true,
				],
				'name' => [
					'description' => __( 'Template name.', 'aebg' ),
					'type'        => 'string',
					'required'    => true,
					'context'     => [ 'view', 'edit' ],
				],
				'description' => [
					'description' => __( 'Template description.', 'aebg' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
				],
				'prompt' => [
					'description' => __( 'The prompt text.', 'aebg' ),
					'type'        => 'string',
					'required'    => true,
					'context'     => [ 'view', 'edit' ],
				],
				'category' => [
					'description' => __( 'Template category.', 'aebg' ),
					'type'        => 'string',
					'default'     => 'general',
					'context'     => [ 'view', 'edit' ],
				],
				'tags' => [
					'description' => __( 'Array of tags.', 'aebg' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
					],
					'context'     => [ 'view', 'edit' ],
				],
				'widget_types' => [
					'description' => __( 'Compatible widget types.', 'aebg' ),
					'type'        => [ 'array', 'null' ],
					'items'       => [
						'type' => 'string',
					],
					'context'     => [ 'view', 'edit' ],
				],
				'is_public' => [
					'description' => __( 'Whether template is public.', 'aebg' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => [ 'view', 'edit' ],
				],
				'usage_count' => [
					'description' => __( 'Number of times template has been used.', 'aebg' ),
					'type'        => 'integer',
					'readonly'    => true,
				],
				'last_used_at' => [
					'description' => __( 'Last usage timestamp.', 'aebg' ),
					'type'        => [ 'string', 'null' ],
					'readonly'    => true,
				],
				'created_at' => [
					'description' => __( 'Creation timestamp.', 'aebg' ),
					'type'        => 'string',
					'readonly'    => true,
				],
				'updated_at' => [
					'description' => __( 'Last update timestamp.', 'aebg' ),
					'type'        => 'string',
					'readonly'    => true,
				],
			],
		];
		
		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}
}

