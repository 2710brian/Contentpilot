<?php

namespace AEBG\Core\Network_API;

/**
 * Abstract Network API Adapter
 * 
 * Base class for all network API adapters.
 * Defines the interface and common functionality.
 *
 * @package AEBG\Core\Network_API
 */
abstract class Network_API_Adapter {

	protected $network_key;
	protected $user_id;
	protected $credential_manager;

	/**
	 * Constructor
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 */
	public function __construct($network_key, $user_id = 1) {
		$this->network_key = $network_key;
		$this->user_id = $user_id;
		$this->credential_manager = new Network_Credential_Manager();
	}

	/**
	 * Get base URL for the network API
	 *
	 * @return string Base URL
	 */
	abstract protected function get_base_url();

	/**
	 * Build endpoint URL with credentials and parameters
	 *
	 * @param string $endpoint Endpoint name
	 * @param array $params Additional parameters
	 * @return string Complete URL
	 */
	abstract protected function build_endpoint_url($endpoint, $params = []);

	/**
	 * Parse API response
	 *
	 * @param string $response Raw API response
	 * @param string $data_type Data type identifier
	 * @return array|WP_Error Parsed data or WP_Error on failure
	 */
	abstract protected function parse_response($response, $data_type);

	/**
	 * Make HTTP request
	 *
	 * @param string $url Request URL
	 * @param string $method HTTP method (GET, POST, etc.)
	 * @param array $headers Request headers
	 * @return string|WP_Error Response body or WP_Error on failure
	 */
	protected function make_request($url, $method = 'GET', $headers = []) {
		$ch = curl_init($url);

		$curl_headers = [];
		foreach ($headers as $key => $value) {
			$curl_headers[] = $key . ': ' . $value;
		}

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $curl_headers,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error) {
			return new \WP_Error('curl_error', 'cURL error: ' . $curl_error);
		}

		if ($http_code >= 400) {
			return new \WP_Error('http_error', "HTTP error {$http_code}: " . substr($response, 0, 200));
		}

		return $response;
	}

	/**
	 * Fetch data from an endpoint
	 *
	 * @param string $endpoint_name Endpoint name
	 * @param array $params Additional parameters
	 * @return array|WP_Error Parsed data or WP_Error on failure
	 */
	public function fetch($endpoint_name, $params = []) {
		$url = $this->build_endpoint_url($endpoint_name, $params);
		
		if (is_wp_error($url)) {
			return $url;
		}

		$response = $this->make_request($url);

		if (is_wp_error($response)) {
			return $response;
		}

		return $this->parse_response($response, $endpoint_name);
	}
}

