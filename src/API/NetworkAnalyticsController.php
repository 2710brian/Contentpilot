<?php

namespace AEBG\API;

use AEBG\Core\Network_API_Manager;

/**
 * Network Analytics Controller
 *
 * Handles REST API endpoints for network analytics and credential management
 *
 * @package AEBG\API
 */
class NetworkAnalyticsController extends \WP_REST_Controller {

	/**
	 * NetworkAnalyticsController constructor.
	 */
	public function __construct() {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		// Store credential
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/credential', [
			'methods' => 'POST',
			'callback' => [$this, 'store_credential'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'network_key' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'credential_type' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_key',
				],
				'credential_value' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);

		// Get credential
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/credential/(?P<credential_type>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_credential'],
			'permission_callback' => [$this, 'permissions_check'],
		]);

		// Delete credential
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/credential/(?P<credential_type>[a-zA-Z0-9_-]+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_credential'],
			'permission_callback' => [$this, 'permissions_check'],
		]);

		// Test connection
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/test', [
			'methods' => 'POST',
			'callback' => [$this, 'test_connection'],
			'permission_callback' => [$this, 'permissions_check'],
		]);

		// Fetch analytics data
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/(?P<endpoint>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'fetch_analytics'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'date_from' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
				'godkendte' => [
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			],
		]);

		// Sync clicks
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/sync-clicks', [
			'methods' => 'POST',
			'callback' => [$this, 'sync_clicks'],
			'permission_callback' => [$this, 'permissions_check'],
		]);

		// Get synced clicks
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/clicks', [
			'methods' => 'GET',
			'callback' => [$this, 'get_synced_clicks'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'limit' => [
					'default' => 100,
					'sanitize_callback' => 'absint',
				],
				'offset' => [
					'default' => 0,
					'sanitize_callback' => 'absint',
				],
			],
		]);

		// Sync sales
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/sync-sales', [
			'methods' => 'POST',
			'callback' => [$this, 'sync_sales'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'date_from' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);

		// Get synced sales
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/sales', [
			'methods' => 'GET',
			'callback' => [$this, 'get_synced_sales'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'limit' => [
					'default' => 100,
					'sanitize_callback' => 'absint',
				],
				'offset' => [
					'default' => 0,
					'sanitize_callback' => 'absint',
				],
				'date_from' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
				'type' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);

		// Sync cancellations
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/sync-cancellations', [
			'methods' => 'POST',
			'callback' => [$this, 'sync_cancellations'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'date_from' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to' => [
					'required' => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);

		// Get synced cancellations
		register_rest_route('aebg/v1', '/network-analytics/(?P<network_key>[a-zA-Z0-9_-]+)/cancellations', [
			'methods' => 'GET',
			'callback' => [$this, 'get_synced_cancellations'],
			'permission_callback' => [$this, 'permissions_check'],
			'args' => [
				'limit' => [
					'default' => 100,
					'sanitize_callback' => 'absint',
				],
				'offset' => [
					'default' => 0,
					'sanitize_callback' => 'absint',
				],
				'date_from' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_to' => [
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);
	}

	/**
	 * Check if user has permission
	 *
	 * @param WP_REST_Request $request Request object
	 * @return bool True if user has permission
	 */
	public function permissions_check($request) {
		return current_user_can('manage_options');
	}

	/**
	 * Store credential
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function store_credential($request) {
		$network_key = $request->get_param('network_key');
		$credential_type = $request->get_param('credential_type');
		$credential_value = $request->get_param('credential_value');
		$user_id = get_current_user_id();

		if (empty($network_key) || empty($credential_type) || empty($credential_value)) {
			return new \WP_Error('missing_params', 'Network key, credential type, and value are required', ['status' => 400]);
		}

		$manager = new Network_API_Manager();
		$result = $manager->store_credential($network_key, $credential_type, $credential_value, $user_id);

		if (is_wp_error($result)) {
			return $result;
		}

		return new \WP_REST_Response([
			'success' => true,
			'message' => 'Credential stored successfully',
		], 200);
	}

	/**
	 * Get credential
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_credential($request) {
		$network_key = $request->get_param('network_key');
		$credential_type = $request->get_param('credential_type');
		$user_id = get_current_user_id();

		$manager = new Network_API_Manager();
		$credential = $manager->get_credential($network_key, $credential_type, $user_id);

		if ($credential === null) {
			return new \WP_Error('not_found', 'Credential not found', ['status' => 404]);
		}

		// Don't return the actual value for security - just indicate it exists
		return new \WP_REST_Response([
			'exists' => true,
			'configured' => !empty($credential),
		], 200);
	}

	/**
	 * Delete credential
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_credential($request) {
		$network_key = $request->get_param('network_key');
		$credential_type = $request->get_param('credential_type');
		$user_id = get_current_user_id();

		$manager = new Network_API_Manager();
		$result = $manager->delete_credential($network_key, $credential_type, $user_id);

		if (!$result) {
			return new \WP_Error('delete_failed', 'Failed to delete credential', ['status' => 500]);
		}

		return new \WP_REST_Response([
			'success' => true,
			'message' => 'Credential deleted successfully',
		], 200);
	}

	/**
	 * Test connection
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection($request) {
		$network_key = $request->get_param('network_key');
		$user_id = get_current_user_id();

		$manager = new Network_API_Manager();
		$result = $manager->test_connection($network_key, $user_id);

		if (is_wp_error($result)) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => $result->get_error_message(),
			], 400);
		}

		return new \WP_REST_Response([
			'success' => true,
			'message' => 'Connection successful',
		], 200);
	}

	/**
	 * Fetch analytics data
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function fetch_analytics($request) {
		$network_key = $request->get_param('network_key');
		$endpoint = $request->get_param('endpoint');
		$date_from = $request->get_param('date_from');
		$date_to = $request->get_param('date_to');
		$godkendte = $request->get_param('godkendte');
		$user_id = get_current_user_id();

		$params = [];
		if ($date_from && $date_to) {
			$params['fra'] = $date_from;
			$params['til'] = $date_to;
		}
		
		if ($godkendte !== null) {
			$params['godkendte'] = (bool)$godkendte;
		}

		$manager = new Network_API_Manager();
		$data = $manager->fetch_analytics($network_key, $endpoint, $params, $user_id);

		if (is_wp_error($data)) {
			return new \WP_REST_Response([
				'success' => false,
				'error' => $data->get_error_message(),
			], 400);
		}

		return new \WP_REST_Response([
			'success' => true,
			'data' => $data,
		], 200);
	}

	/**
	 * Sync clicks from klikoversigt
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_clicks($request) {
		$network_key = $request->get_param('network_key');
		$user_id = get_current_user_id();

		$manager = new Network_API_Manager();
		$result = $manager->sync_clicks($network_key, $user_id);

		if (is_wp_error($result)) {
			return new \WP_REST_Response([
				'success' => false,
				'error' => $result->get_error_message(),
			], 400);
		}

		return new \WP_REST_Response([
			'success' => true,
			'stats' => $result,
			'message' => sprintf(
				'Synced %d new clicks, skipped %d duplicates',
				$result['inserted'],
				$result['skipped']
			),
		], 200);
	}

	/**
	 * Get synced clicks
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_synced_clicks($request) {
		$network_key = $request->get_param('network_key');
		$limit = $request->get_param('limit');
		$offset = $request->get_param('offset');
		$user_id = get_current_user_id();

		$clicks_sync = new \AEBG\Core\Network_API\Network_Clicks_Sync();
		$clicks = $clicks_sync->get_synced_clicks($network_key, $user_id, [
			'limit' => $limit,
			'offset' => $offset,
		]);

		$stats = $clicks_sync->get_click_stats($network_key, $user_id);

		return new \WP_REST_Response([
			'success' => true,
			'clicks' => $clicks,
			'stats' => $stats,
		], 200);
	}

	/**
	 * Sync sales from vissalg
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_sales($request) {
		$network_key = $request->get_param('network_key');
		$date_from = $request->get_param('date_from');
		$date_to = $request->get_param('date_to');
		$user_id = get_current_user_id();

		$manager = new Network_API_Manager();
		$result = $manager->sync_sales($network_key, [
			'date_from' => $date_from,
			'date_to' => $date_to,
		], $user_id);

		if (is_wp_error($result)) {
			return new \WP_REST_Response([
				'success' => false,
				'error' => $result->get_error_message(),
			], 400);
		}

		return new \WP_REST_Response([
			'success' => true,
			'stats' => $result,
			'message' => sprintf(
				'Synced %d new sales, skipped %d duplicates',
				$result['inserted'],
				$result['skipped']
			),
		], 200);
	}

	/**
	 * Get synced sales
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_synced_sales($request) {
		$network_key = $request->get_param('network_key');
		$limit = $request->get_param('limit');
		$offset = $request->get_param('offset');
		$date_from = $request->get_param('date_from');
		$date_to = $request->get_param('date_to');
		$type = $request->get_param('type');
		$user_id = get_current_user_id();

		$sales_sync = new \AEBG\Core\Network_API\Network_Sales_Sync();
		$sales = $sales_sync->get_synced_sales($network_key, $user_id, [
			'limit' => $limit,
			'offset' => $offset,
			'date_from' => $date_from,
			'date_to' => $date_to,
			'type' => $type,
		]);

		$stats = $sales_sync->get_sales_stats($network_key, $user_id, [
			'date_from' => $date_from,
			'date_to' => $date_to,
			'type' => $type,
		]);

		return new \WP_REST_Response([
			'success' => true,
			'sales' => $sales,
			'stats' => $stats,
		], 200);
	}

	/**
	 * Sync cancellations from annulleringer
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_cancellations($request) {
		$network_key = $request->get_param('network_key');
		$date_from = $request->get_param('date_from');
		$date_to = $request->get_param('date_to');
		$user_id = get_current_user_id();

		$manager = new Network_API_Manager();
		$result = $manager->sync_cancellations($network_key, [
			'date_from' => $date_from,
			'date_to' => $date_to,
		], $user_id);

		if (is_wp_error($result)) {
			return new \WP_REST_Response([
				'success' => false,
				'error' => $result->get_error_message(),
			], 400);
		}

		return new \WP_REST_Response([
			'success' => true,
			'stats' => $result,
			'message' => sprintf(
				'Synced %d new cancellations, skipped %d duplicates, marked %d sales as cancelled',
				$result['inserted'],
				$result['skipped'],
				$result['sales_marked_cancelled']
			),
		], 200);
	}

	/**
	 * Get synced cancellations
	 *
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_synced_cancellations($request) {
		$network_key = $request->get_param('network_key');
		$limit = $request->get_param('limit');
		$offset = $request->get_param('offset');
		$date_from = $request->get_param('date_from');
		$date_to = $request->get_param('date_to');
		$user_id = get_current_user_id();

		$cancellations_sync = new \AEBG\Core\Network_API\Network_Cancellations_Sync();
		$cancellations = $cancellations_sync->get_synced_cancellations($network_key, $user_id, [
			'limit' => $limit,
			'offset' => $offset,
			'date_from' => $date_from,
			'date_to' => $date_to,
		]);

		$stats = $cancellations_sync->get_cancellation_stats($network_key, $user_id, [
			'date_from' => $date_from,
			'date_to' => $date_to,
		]);

		return new \WP_REST_Response([
			'success' => true,
			'cancellations' => $cancellations,
			'stats' => $stats,
		], 200);
	}
}

