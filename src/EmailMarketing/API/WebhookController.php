<?php

namespace AEBG\EmailMarketing\API;

use AEBG\EmailMarketing\Core\SubscriberManager;
use AEBG\EmailMarketing\Core\ListManager;
use AEBG\EmailMarketing\Services\ValidationService;
use AEBG\Core\Logger;

/**
 * Webhook Controller
 * 
 * Handles REST API webhook endpoint for Elementor Forms integration
 * 
 * @package AEBG\EmailMarketing\API
 */
class WebhookController extends \WP_REST_Controller {

	/**
	 * Namespace
	 * 
	 * @var string
	 */
	protected $namespace = 'aebg/v1';

	/**
	 * REST base
	 * 
	 * @var string
	 */
	protected $rest_base = 'email-marketing';

	/**
	 * Subscriber Manager
	 * 
	 * @var SubscriberManager
	 */
	private $subscriber_manager;

	/**
	 * List Manager
	 * 
	 * @var ListManager
	 */
	private $list_manager;

	/**
	 * Validation Service
	 * 
	 * @var ValidationService
	 */
	private $validation_service;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->subscriber_manager = new SubscriberManager();
		$this->list_manager = new ListManager();
		$this->validation_service = new ValidationService();
		
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes
	 * 
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'list_id' => [
						'required' => false,
						'type'     => 'integer',
						'description' => __( 'Email list ID to add subscriber to. If not provided, will use post_id or current post.', 'aebg' ),
					],
					'post_id' => [
						'required' => false,
						'type'     => 'integer',
						'description' => __( 'Post ID to get list from. If not provided, will try to detect from referrer URL.', 'aebg' ),
					],
					'list_key' => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'default',
						'description' => __( 'List key for post-based lists (default: "default")', 'aebg' ),
					],
				],
			]
		);
	}

	/**
	 * Permission check
	 * 
	 * Webhook endpoint should be publicly accessible (no authentication required)
	 * Security is handled via rate limiting and validation
	 * 
	 * @param \WP_REST_Request $request Request object
	 * @return bool Always return true (public endpoint)
	 */
	public function permission_check( $request ) {
		// Public endpoint - no authentication required
		// Security handled via rate limiting and validation
		return true;
	}

	/**
	 * Handle webhook request
	 * 
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error Response object
	 */
	public function handle_webhook( $request ) {
		// Rate limiting
		$rate_limit_result = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit_result ) ) {
			Logger::warning( 'Webhook rate limit exceeded', [
				'ip' => $this->get_client_ip(),
			] );
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Too many requests. Please try again later.', 'aebg' ),
			], 429 );
		}

		// Get request body
		$body = $request->get_json_params();
		
		// Fallback to POST data if JSON not available
		if ( empty( $body ) ) {
			$body = $request->get_body_params();
		}

		// Parse Elementor webhook format
		$form_data = $this->parse_elementor_webhook( $body );
		
		if ( is_wp_error( $form_data ) ) {
			Logger::error( 'Failed to parse webhook data', [
				'error' => $form_data->get_error_message(),
				'body' => $body,
			] );
			return new \WP_REST_Response( [
				'success' => false,
				'message' => $form_data->get_error_message(),
			], 400 );
		}

		// Validate email
		if ( empty( $form_data['email'] ) || ! is_email( $form_data['email'] ) ) {
			Logger::warning( 'Webhook: Invalid or missing email', [
				'form_data' => $form_data,
			] );
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Valid email address is required', 'aebg' ),
			], 400 );
		}

		// Get list ID
		$list_id = $this->get_list_id( $request, $form_data );
		
		if ( ! $list_id ) {
			Logger::warning( 'Webhook: No list ID found', [
				'request_params' => [
					'list_id' => $request->get_param( 'list_id' ),
					'post_id' => $request->get_param( 'post_id' ),
				],
				'form_data' => $form_data,
			] );
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Email list not found. Please configure list_id or post_id parameter.', 'aebg' ),
			], 400 );
		}

		// Add subscriber
		$result = $this->subscriber_manager->add_subscriber(
			$list_id,
			$form_data['email'],
			[
				'first_name' => $form_data['first_name'] ?? '',
				'last_name' => $form_data['last_name'] ?? '',
				'source' => 'elementor_webhook',
				'metadata' => [
					'form_id' => $form_data['form_id'] ?? '',
					'form_name' => $form_data['form_name'] ?? '',
					'page_url' => $form_data['page_url'] ?? '',
					'ip_address' => $this->get_client_ip(),
					'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
					'webhook_data' => $body, // Store full webhook data for debugging
				],
			]
		);

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$error_message = $result->get_error_message();
			
			Logger::error( 'Failed to add subscriber from webhook', [
				'error' => $error_message,
				'error_code' => $error_code,
				'email' => $form_data['email'],
				'list_id' => $list_id,
			] );

			// Return appropriate status code based on error
			$status_code = 400;
			if ( $error_code === 'subscriber_exists' ) {
				$status_code = 409; // Conflict
			} elseif ( $error_code === 'list_not_found' ) {
				$status_code = 404; // Not Found
			}

			return new \WP_REST_Response( [
				'success' => false,
				'message' => $error_message,
				'error_code' => $error_code,
			], $status_code );
		}

		// Success response
		Logger::info( 'Subscriber added from webhook', [
			'subscriber_id' => $result->id ?? 'unknown',
			'email' => $form_data['email'],
			'list_id' => $list_id,
			'status' => $result->status ?? 'unknown',
		] );

		$response_data = [
			'success' => true,
			'message' => $result->status === 'pending' 
				? __( 'Please check your email to confirm your subscription.', 'aebg' )
				: __( 'Thank you for subscribing!', 'aebg' ),
			'status' => $result->status ?? 'unknown',
		];

		return new \WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Parse Elementor webhook data
	 * 
	 * Elementor sends webhook data in this format:
	 * {
	 *   "form": {
	 *     "id": "form_id",
	 *     "name": "Form Name"
	 *   },
	 *   "fields": [
	 *     {
	 *       "id": "email",
	 *       "title": "Email",
	 *       "value": "user@example.com"
	 *     },
	 *     {
	 *       "id": "first_name",
	 *       "title": "First Name",
	 *       "value": "John"
	 *     }
	 *   ],
	 *   "meta": {
	 *     "page_url": "https://example.com/page",
	 *     "user_agent": "...",
	 *     "remote_ip": "..."
	 *   }
	 * }
	 * 
	 * @param array $body Request body
	 * @return array|\WP_Error Parsed form data or error
	 */
	private function parse_elementor_webhook( $body ) {
		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_data', __( 'Invalid webhook data format', 'aebg' ) );
		}

		$form_data = [
			'email' => '',
			'first_name' => '',
			'last_name' => '',
			'form_id' => '',
			'form_name' => '',
			'page_url' => '',
		];

		// Extract form info
		if ( isset( $body['form'] ) ) {
			$form_data['form_id'] = sanitize_text_field( $body['form']['id'] ?? '' );
			$form_data['form_name'] = sanitize_text_field( $body['form']['name'] ?? '' );
		}

		// Extract meta info
		if ( isset( $body['meta'] ) ) {
			$form_data['page_url'] = esc_url_raw( $body['meta']['page_url'] ?? '' );
		}

		// Extract fields
		if ( isset( $body['fields'] ) && is_array( $body['fields'] ) ) {
			foreach ( $body['fields'] as $field ) {
				$field_id = strtolower( sanitize_text_field( $field['id'] ?? '' ) );
				$field_value = $field['value'] ?? '';

				// Map common field IDs to our data structure
				if ( strpos( $field_id, 'email' ) !== false || $field_id === 'email' ) {
					$form_data['email'] = sanitize_email( $field_value );
				} elseif ( strpos( $field_id, 'first' ) !== false || strpos( $field_id, 'fname' ) !== false || $field_id === 'first_name' ) {
					$form_data['first_name'] = sanitize_text_field( $field_value );
				} elseif ( strpos( $field_id, 'last' ) !== false || strpos( $field_id, 'lname' ) !== false || strpos( $field_id, 'surname' ) !== false || $field_id === 'last_name' ) {
					$form_data['last_name'] = sanitize_text_field( $field_value );
				}
			}
		}

		// Also check for direct field access (some webhook formats)
		if ( empty( $form_data['email'] ) && isset( $body['email'] ) ) {
			$form_data['email'] = sanitize_email( $body['email'] );
		}
		if ( empty( $form_data['first_name'] ) && isset( $body['first_name'] ) ) {
			$form_data['first_name'] = sanitize_text_field( $body['first_name'] );
		}
		if ( empty( $form_data['last_name'] ) && isset( $body['last_name'] ) ) {
			$form_data['last_name'] = sanitize_text_field( $body['last_name'] );
		}

		return $form_data;
	}

	/**
	 * Get list ID from request
	 * 
	 * Priority:
	 * 1. Query parameter: list_id
	 * 2. Query parameter: post_id (get or create list for post)
	 * 3. Extract post_id from page_url in webhook data
	 * 4. Current post ID (if available)
	 * 
	 * @param \WP_REST_Request $request Request object
	 * @param array $form_data Parsed form data
	 * @return int|null List ID or null
	 */
	private function get_list_id( $request, $form_data ) {
		// Method 1: Direct list_id parameter
		$list_id = $request->get_param( 'list_id' );
		if ( ! empty( $list_id ) ) {
			$list = $this->list_manager->get_list( (int) $list_id );
			if ( $list ) {
				return (int) $list_id;
			}
		}

		// Method 2: post_id parameter
		$post_id = $request->get_param( 'post_id' );
		if ( empty( $post_id ) && ! empty( $form_data['page_url'] ) ) {
			// Method 3: Extract post_id from page URL
			$post_id = url_to_postid( $form_data['page_url'] );
		}
		
		if ( empty( $post_id ) ) {
			// Method 4: Current post ID
			$post_id = get_the_ID();
		}

		if ( $post_id ) {
			$list_key = $request->get_param( 'list_key' ) ?: 'default';
			$list = $this->list_manager->get_or_create_list( (int) $post_id, $list_key );
			if ( $list ) {
				return $list->id;
			}
		}

		return null;
	}

	/**
	 * Check rate limit
	 * 
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited
	 */
	private function check_rate_limit() {
		$ip_address = $this->get_client_ip();
		$rate_limit_key = 'aebg_webhook_rate_' . md5( $ip_address );
		$rate_limit_count = get_transient( $rate_limit_key );

		if ( $rate_limit_count === false ) {
			set_transient( $rate_limit_key, 1, 3600 ); // 1 hour
			return true;
		}

		// Max 10 webhook requests per hour per IP
		if ( $rate_limit_count >= 10 ) {
			return new \WP_Error( 'rate_limit_exceeded', __( 'Rate limit exceeded', 'aebg' ) );
		}

		set_transient( $rate_limit_key, $rate_limit_count + 1, 3600 );
		return true;
	}

	/**
	 * Get client IP address
	 * 
	 * @return string IP address
	 */
	private function get_client_ip() {
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_REAL_IP', // Nginx proxy
			'HTTP_X_FORWARDED_FOR', // Proxy
			'REMOTE_ADDR', // Standard
		];

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( $_SERVER[ $key ] );
				// Handle comma-separated IPs (from proxies)
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

