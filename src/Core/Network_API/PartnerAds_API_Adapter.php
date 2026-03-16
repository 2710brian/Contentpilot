<?php

namespace AEBG\Core\Network_API;

/**
 * Partner-Ads API Adapter
 * 
 * Handles all Partner-Ads API endpoints.
 * Custom adapter due to specific date format requirements (YY-MM-DD).
 *
 * @package AEBG\Core\Network_API
 */
class PartnerAds_API_Adapter extends Network_API_Adapter {

	protected $base_url = 'https://www.partner-ads.com/dk/';

	/**
	 * Get base URL
	 *
	 * @return string Base URL
	 */
	protected function get_base_url() {
		return $this->base_url;
	}

	/**
	 * Build endpoint URL with API key and parameters
	 *
	 * @param string $endpoint Endpoint name (without .php extension, or short name)
	 * @param array $params Additional parameters (fra, til for date ranges, godkendte)
	 * @return string|WP_Error Complete URL or WP_Error if API key missing
	 */
	protected function build_endpoint_url($endpoint, $params = []) {
		// Map short names to full endpoint names
		$endpoint_map = [
			'saldo' => 'saldo_xml',
			'indtjening' => 'partnerindtjening_xml',
			'indtjening_dato' => 'partnerindtjening_dato_xml',
			'partnerindtjening_dato_xml' => 'partnerindtjening_dato_xml',
			'programstat' => 'programstat_xml',
			'vissalg' => 'vissalg_xml',
			'annulleringer' => 'annulleringer_xml',
			'klikoversigt' => 'klikoversigt_xml',
			'programoversigt' => 'programoversigt_xml',
			'senestenyt' => 'senestenyt_xml',
		];
		
		// Use mapped name if available, otherwise use original
		$endpoint = $endpoint_map[$endpoint] ?? $endpoint;
		$api_key = $this->credential_manager->get_credential($this->network_key, 'analytics_key', $this->user_id);

		if (empty($api_key)) {
			return new \WP_Error('missing_api_key', 'Partner-Ads API key not configured');
		}

		$url = $this->base_url . $endpoint . '.php?key=' . urlencode($api_key);

		// Add date parameters if provided (for date range endpoints)
		if (isset($params['fra']) && isset($params['til'])) {
			$url .= '&fra=' . $this->format_date($params['fra']);
			$url .= '&til=' . $this->format_date($params['til']);
		}

		// Add godkendte parameter for programoversigt
		if (isset($params['godkendte']) && $params['godkendte']) {
			$url .= '&godkendte=1';
		}

		return $url;
	}

	/**
	 * Format date to Partner-Ads format (YY-MM-DD)
	 * 
	 * Partner-Ads uses: last 2 digits of year, month without leading zero, day without leading zero
	 * Example: 2024-03-15 -> 24-3-15
	 *
	 * @param string|DateTime $date Date string (YYYY-MM-DD) or DateTime object
	 * @return string Formatted date (YY-MM-DD)
	 */
	protected function format_date($date) {
		if (is_string($date)) {
			$date = new \DateTime($date);
		}

		$year = $date->format('y'); // Last 2 digits
		$month = (int)$date->format('n'); // Month without leading zero
		$day = (int)$date->format('j'); // Day without leading zero

		return $year . '-' . $month . '-' . $day;
	}

	/**
	 * Parse XML response
	 *
	 * @param string $response Raw XML response
	 * @param string $data_type Data type identifier
	 * @return array|WP_Error Parsed data or WP_Error on failure
	 */
	protected function parse_response($response, $data_type) {
		// Log raw response for debugging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[AEBG PartnerAds] Raw XML response (first 500 chars): ' . substr($response, 0, 500));
		}

		// Suppress XML errors for cleaner error handling
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($response);

		if ($xml === false) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$error_msg = !empty($errors) ? $errors[0]->message : 'Unknown XML parsing error';
			error_log('[AEBG PartnerAds] XML parse error: ' . $error_msg);
			error_log('[AEBG PartnerAds] Response: ' . substr($response, 0, 1000));
			return new \WP_Error('xml_parse_error', 'Failed to parse XML response: ' . $error_msg);
		}

		// Convert XML to array
		// Use JSON encoding/decoding to preserve structure
		$json = json_encode($xml);
		$data = json_decode($json, true);

		// Log parsed data structure for debugging
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[AEBG PartnerAds] Parsed data structure: ' . print_r($data, true));
		}

		return $data;
	}

	/**
	 * Fetch saldo (balance and expected payout)
	 *
	 * @return array|WP_Error Balance data or WP_Error on failure
	 */
	public function fetch_saldo() {
		return $this->fetch('saldo_xml');
	}
	

	/**
	 * Fetch indtjening (earnings summary - today, yesterday, week, month, year)
	 *
	 * @return array|WP_Error Earnings data or WP_Error on failure
	 */
	public function fetch_indtjening() {
		return $this->fetch('partnerindtjening_xml');
	}

	/**
	 * Fetch indtjening for date range
	 *
	 * @param string $date_from Start date (YYYY-MM-DD)
	 * @param string $date_to End date (YYYY-MM-DD)
	 * @return array|WP_Error Earnings data or WP_Error on failure
	 */
	public function fetch_indtjening_dato($date_from, $date_to) {
		return $this->fetch('partnerindtjening_dato_xml', [
			'fra' => $date_from,
			'til' => $date_to,
		]);
	}

	/**
	 * Fetch programstatistik (program statistics)
	 *
	 * @param string $date_from Start date (YYYY-MM-DD)
	 * @param string $date_to End date (YYYY-MM-DD)
	 * @return array|WP_Error Program statistics or WP_Error on failure
	 */
	public function fetch_programstat($date_from, $date_to) {
		return $this->fetch('programstat_xml', [
			'fra' => $date_from,
			'til' => $date_to,
		]);
	}

	/**
	 * Fetch vissalg (sales/leads overview)
	 *
	 * @param string $date_from Start date (YYYY-MM-DD)
	 * @param string $date_to End date (YYYY-MM-DD)
	 * @return array|WP_Error Sales data or WP_Error on failure
	 */
	public function fetch_vissalg($date_from, $date_to) {
		return $this->fetch('vissalg_xml', [
			'fra' => $date_from,
			'til' => $date_to,
		]);
	}

	/**
	 * Fetch annulleringer (cancellations)
	 *
	 * @param string $date_from Start date (YYYY-MM-DD)
	 * @param string $date_to End date (YYYY-MM-DD)
	 * @return array|WP_Error Cancellation data or WP_Error on failure
	 */
	public function fetch_annulleringer($date_from, $date_to) {
		return $this->fetch('annulleringer_xml', [
			'fra' => $date_from,
			'til' => $date_to,
		]);
	}

	/**
	 * Fetch klikoversigt (click overview - last 40 days)
	 *
	 * @return array|WP_Error Click data or WP_Error on failure
	 */
	public function fetch_klikoversigt() {
		return $this->fetch('klikoversigt_xml');
	}

	/**
	 * Fetch programoversigt (program overview)
	 *
	 * @param bool $godkendte_only Only show approved programs
	 * @return array|WP_Error Program overview or WP_Error on failure
	 */
	public function fetch_programoversigt($godkendte_only = false) {
		return $this->fetch('programoversigt_xml', [
			'godkendte' => $godkendte_only,
		]);
	}

	/**
	 * Fetch seneste nyt (latest news)
	 *
	 * @return array|WP_Error Latest news or WP_Error on failure
	 */
	public function fetch_senestenyt() {
		return $this->fetch('senestenyt_xml');
	}
}

