<?php

namespace AEBG\Core;

use AEBG\Core\Network_API\Network_Credential_Manager;
use AEBG\Core\Network_API\Network_Registry;
use AEBG\Core\Network_API\PartnerAds_API_Adapter;
use AEBG\Core\Network_API\Network_API_Adapter;

/**
 * Network API Manager
 * 
 * Orchestrator for network API operations.
 * Manages credentials, adapters, and provides unified interface.
 *
 * @package AEBG\Core
 */
class Network_API_Manager {

	protected $credential_manager;
	protected $registry;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->credential_manager = new Network_API\Network_Credential_Manager();
		$this->registry = new Network_API\Network_Registry();
	}

	/**
	 * Get adapter for a network
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @return Network_API_Adapter|WP_Error Adapter instance or WP_Error
	 */
	public function get_adapter($network_key, $user_id = 1) {
		$config = $this->registry->get_config($network_key);

		if (!$config) {
			return new \WP_Error('network_not_found', "Network '{$network_key}' not found in registry");
		}

		$adapter_class = $config['adapter_class'] ?? null;

		if (!$adapter_class) {
			return new \WP_Error('no_adapter', "No adapter class defined for network '{$network_key}'");
		}

		// Map adapter class name to full class name
		$class_name = "AEBG\\Core\\Network_API\\{$adapter_class}";

		if (!class_exists($class_name)) {
			return new \WP_Error('adapter_not_found', "Adapter class '{$class_name}' not found");
		}

		// Instantiate adapter
		return new $class_name($network_key, $user_id);
	}

	/**
	 * Store a credential for a network
	 *
	 * @param string $network_key Network identifier
	 * @param string $credential_type Credential type
	 * @param string $credential_value Credential value
	 * @param int $user_id User ID
	 * @param array $metadata Additional metadata
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function store_credential($network_key, $credential_type, $credential_value, $user_id = 1, $metadata = []) {
		return $this->credential_manager->store_credential($network_key, $credential_type, $credential_value, $user_id, $metadata);
	}

	/**
	 * Get a credential for a network
	 *
	 * @param string $network_key Network identifier
	 * @param string $credential_type Credential type
	 * @param int $user_id User ID
	 * @return string|null Credential value or null if not found
	 */
	public function get_credential($network_key, $credential_type, $user_id = 1) {
		return $this->credential_manager->get_credential($network_key, $credential_type, $user_id);
	}

	/**
	 * Get all credentials for a network
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @return array Array of credentials
	 */
	public function get_all_credentials($network_key, $user_id = 1) {
		return $this->credential_manager->get_all_credentials($network_key, $user_id);
	}

	/**
	 * Delete a credential
	 *
	 * @param string $network_key Network identifier
	 * @param string $credential_type Credential type
	 * @param int $user_id User ID
	 * @return bool True on success
	 */
	public function delete_credential($network_key, $credential_type, $user_id = 1) {
		return $this->credential_manager->delete_credential($network_key, $credential_type, $user_id);
	}

	/**
	 * Check if network is fully configured
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @return bool True if all required credentials are present
	 */
	public function is_configured($network_key, $user_id = 1) {
		$required_creds = $this->registry->get_required_credentials($network_key);
		return $this->credential_manager->has_required_credentials($network_key, $required_creds, $user_id);
	}

	/**
	 * Fetch analytics data from a network
	 *
	 * @param string $network_key Network identifier
	 * @param string $endpoint_name Endpoint name
	 * @param array $params Additional parameters
	 * @param int $user_id User ID
	 * @return array|WP_Error Analytics data or WP_Error on failure
	 */
	public function fetch_analytics($network_key, $endpoint_name, $params = [], $user_id = 1) {
		// Check if network is configured
		if (!$this->is_configured($network_key, $user_id)) {
			return new \WP_Error('not_configured', "Network '{$network_key}' is not fully configured. Missing required credentials.");
		}

		// Get adapter
		$adapter = $this->get_adapter($network_key, $user_id);

		if (is_wp_error($adapter)) {
			return $adapter;
		}

		// Fetch data
		return $adapter->fetch($endpoint_name, $params);
	}

	/**
	 * Test network API connection
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function test_connection($network_key, $user_id = 1) {
		// For Partner-Ads, test with saldo endpoint (simple, no date range needed)
		if ($network_key === 'partner_ads' || $network_key === 'api_15') {
			$result = $this->fetch_analytics($network_key, 'saldo_xml', [], $user_id);
			if (is_wp_error($result)) {
				return $result;
			}
			return true;
		}

		// For other networks, use a simple endpoint if available
		$config = $this->registry->get_config($network_key);
		if ($config && isset($config['endpoints'])) {
			// Try first endpoint that doesn't require date range
			foreach ($config['endpoints'] as $endpoint_name => $endpoint_config) {
				if (empty($endpoint_config['requires_date_range'])) {
					$result = $this->fetch_analytics($network_key, $endpoint_name, [], $user_id);
					if (is_wp_error($result)) {
						return $result;
					}
					return true;
				}
			}
		}

		return new \WP_Error('no_test_endpoint', "No suitable test endpoint found for network '{$network_key}'");
	}

	/**
	 * Sync clicks from klikoversigt endpoint
	 * Fetches clicks and stores them with duplicate prevention
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @return array|WP_Error Sync statistics or WP_Error on failure
	 */
	public function sync_clicks($network_key, $user_id = 1) {
		// Check if network is configured
		if (!$this->is_configured($network_key, $user_id)) {
			return new \WP_Error('not_configured', "Network '{$network_key}' is not fully configured. Missing required credentials.");
		}

		// Fetch klikoversigt data
		$clicks_data = $this->fetch_analytics($network_key, 'klikoversigt_xml', [], $user_id);

		if (is_wp_error($clicks_data)) {
			return $clicks_data;
		}

		// Sync clicks to database
		$clicks_sync = new Network_API\Network_Clicks_Sync();
		$result = $clicks_sync->sync_clicks($network_key, $clicks_data, $user_id);

		return $result;
	}

	/**
	 * Sync sales from vissalg endpoint
	 * Fetches sales/leads and stores them with duplicate prevention
	 *
	 * @param string $network_key Network identifier
	 * @param array $date_range Date range ['date_from' => 'YYYY-MM-DD', 'date_to' => 'YYYY-MM-DD']
	 * @param int $user_id User ID
	 * @return array|WP_Error Sync statistics or WP_Error on failure
	 */
	public function sync_sales($network_key, $date_range = [], $user_id = 1) {
		// Check if network is configured
		if (!$this->is_configured($network_key, $user_id)) {
			return new \WP_Error('not_configured', "Network '{$network_key}' is not fully configured. Missing required credentials.");
		}

		// Validate date range
		if (empty($date_range['date_from']) || empty($date_range['date_to'])) {
			return new \WP_Error('missing_date_range', 'Date range (date_from and date_to) is required for syncing sales.');
		}

		// Convert date format for Partner-Ads API (YY-MM-DD)
		$api_params = [];
		if (!empty($date_range['date_from'])) {
			$api_params['fra'] = $this->convert_date_to_partnerads_format($date_range['date_from']);
		}
		if (!empty($date_range['date_to'])) {
			$api_params['til'] = $this->convert_date_to_partnerads_format($date_range['date_to']);
		}

		// Fetch vissalg data
		$sales_data = $this->fetch_analytics($network_key, 'vissalg_xml', $api_params, $user_id);

		if (is_wp_error($sales_data)) {
			return $sales_data;
		}

		// Sync sales to database
		$sales_sync = new Network_API\Network_Sales_Sync();
		$result = $sales_sync->sync_sales($network_key, $sales_data, $user_id);

		return $result;
	}

	/**
	 * Convert date from YYYY-MM-DD to YY-MM-DD format for Partner-Ads API
	 * 
	 * @param string $date Date in YYYY-MM-DD format
	 * @return string Date in YY-MM-DD format (without leading zeros)
	 */
	protected function convert_date_to_partnerads_format($date) {
		if (empty($date)) {
			return '';
		}

		try {
			$dt = new \DateTime($date);
			$year = $dt->format('y'); // Last 2 digits
			$month = (int)$dt->format('m'); // Remove leading zero
			$day = (int)$dt->format('d'); // Remove leading zero
			
			return sprintf('%s-%d-%d', $year, $month, $day);
		} catch (\Exception $e) {
			error_log('[AEBG Network_API_Manager] Failed to convert date: ' . $date);
			return '';
		}
	}

	/**
	 * Sync cancellations from annulleringer endpoint
	 * Fetches cancellations and stores them with duplicate prevention
	 * Also marks corresponding sales as cancelled
	 *
	 * @param string $network_key Network identifier
	 * @param array $date_range Date range ['date_from' => 'YYYY-MM-DD', 'date_to' => 'YYYY-MM-DD']
	 * @param int $user_id User ID
	 * @return array|WP_Error Sync statistics or WP_Error on failure
	 */
	public function sync_cancellations($network_key, $date_range = [], $user_id = 1) {
		// Check if network is configured
		if (!$this->is_configured($network_key, $user_id)) {
			return new \WP_Error('not_configured', "Network '{$network_key}' is not fully configured. Missing required credentials.");
		}

		// Validate date range
		if (empty($date_range['date_from']) || empty($date_range['date_to'])) {
			return new \WP_Error('missing_date_range', 'Date range (date_from and date_to) is required for syncing cancellations.');
		}

		// Convert date format for Partner-Ads API (YY-MM-DD)
		$api_params = [];
		if (!empty($date_range['date_from'])) {
			$api_params['fra'] = $this->convert_date_to_partnerads_format($date_range['date_from']);
		}
		if (!empty($date_range['date_to'])) {
			$api_params['til'] = $this->convert_date_to_partnerads_format($date_range['date_to']);
		}

		// Fetch annulleringer data
		$cancellations_data = $this->fetch_analytics($network_key, 'annulleringer_xml', $api_params, $user_id);

		if (is_wp_error($cancellations_data)) {
			return $cancellations_data;
		}

		// Sync cancellations to database
		$cancellations_sync = new Network_API\Network_Cancellations_Sync();
		$result = $cancellations_sync->sync_cancellations($network_key, $cancellations_data, $user_id);

		return $result;
	}
	
	/**
	 * Get active networks with caching
	 * 
	 * @param bool $force_refresh Force cache refresh
	 * @return array Active networks
	 */
	public static function get_active_networks($force_refresh = false) {
		$cache_key = 'aebg_active_networks';
		$cache_group = 'aebg_networks';
		$cache_ttl = 3600; // 1 hour
		
		// Check cache first
		if (!$force_refresh) {
			$cached = wp_cache_get($cache_key, $cache_group);
			if ($cached !== false) {
				return $cached;
			}
		}
		
		// Fetch from database
		$networks_manager = new \AEBG\Admin\Networks_Manager();
		$all_networks = $networks_manager->get_all_networks_with_status();
		
		// Filter active networks
		$active_networks = array_filter($all_networks, function($network) {
			return ($network['status'] ?? 'active') === 'active';
		});
		
		// Normalize structure
		$normalized = array_map(function($network) {
			return [
				'network_key' => $network['code'] ?? '',
				'network_name' => $network['name'] ?? '',
				'status' => $network['status'] ?? 'active'
			];
		}, $active_networks);
		
		// Cache result
		wp_cache_set($cache_key, $normalized, $cache_group, $cache_ttl);
		
		return $normalized;
	}
	
	/**
	 * Invalidate network cache
	 */
	public static function invalidate_network_cache() {
		wp_cache_delete('aebg_active_networks', 'aebg_networks');
	}
}

