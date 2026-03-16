<?php

namespace AEBG\Core\Network_API;

/**
 * Network Registry
 * 
 * Centralized configuration for all network APIs.
 * Defines required credentials, endpoints, authentication methods, etc.
 *
 * @package AEBG\Core\Network_API
 */
class Network_Registry {

	/**
	 * Get network configuration
	 *
	 * @param string $network_key Network identifier
	 * @return array|null Network configuration or null if not found
	 */
	public static function get_config($network_key) {
		$registry = self::get_registry();
		return $registry[$network_key] ?? null;
	}

	/**
	 * Get adapter class name for a network
	 *
	 * @param string $network_key Network identifier
	 * @return string|null Adapter class name or null
	 */
	public static function get_adapter_class($network_key) {
		$config = self::get_config($network_key);
		return $config['adapter_class'] ?? null;
	}

	/**
	 * Get required credentials for a network
	 *
	 * @param string $network_key Network identifier
	 * @return array Array of required credential types
	 */
	public static function get_required_credentials($network_key) {
		$config = self::get_config($network_key);
		return $config['required_credentials'] ?? [];
	}

	/**
	 * Get optional credentials for a network
	 *
	 * @param string $network_key Network identifier
	 * @return array Array of optional credential types
	 */
	public static function get_optional_credentials($network_key) {
		$config = self::get_config($network_key);
		return $config['optional_credentials'] ?? [];
	}

	/**
	 * Get credential labels for a network
	 *
	 * @param string $network_key Network identifier
	 * @return array Array of credential_type => label mappings
	 */
	public static function get_credential_labels($network_key) {
		$config = self::get_config($network_key);
		return $config['credential_labels'] ?? [];
	}

	/**
	 * Get endpoint configuration
	 *
	 * @param string $network_key Network identifier
	 * @param string $endpoint_name Endpoint name
	 * @return array|null Endpoint configuration or null
	 */
	public static function get_endpoint_config($network_key, $endpoint_name) {
		$config = self::get_config($network_key);
		return $config['endpoints'][$endpoint_name] ?? null;
	}

	/**
	 * Check if network uses configurable adapter
	 *
	 * @param string $network_key Network identifier
	 * @return bool True if uses Configurable_API_Adapter
	 */
	public static function is_configurable($network_key) {
		$adapter_class = self::get_adapter_class($network_key);
		return $adapter_class === 'Configurable_API_Adapter';
	}

	/**
	 * Check if network has custom adapter
	 *
	 * @param string $network_key Network identifier
	 * @return bool True if has custom adapter class
	 */
	public static function has_custom_adapter($network_key) {
		$adapter_class = self::get_adapter_class($network_key);
		return !empty($adapter_class) && $adapter_class !== 'Configurable_API_Adapter';
	}

	/**
	 * Get all networks in registry
	 *
	 * @return array Array of all network configurations
	 */
	public static function get_all_networks() {
		return self::get_registry();
	}

	/**
	 * Get the registry configuration
	 *
	 * @return array Registry configuration
	 */
	protected static function get_registry() {
		// For now, return hardcoded registry
		// In future, this could load from a config file
		
		// Partner-Ads configuration (supports both 'partner_ads' and 'api_15' keys)
		$partner_ads_config = [
				'adapter_class' => 'PartnerAds_API_Adapter',
				'required_credentials' => ['analytics_key'],
				'optional_credentials' => [],
				'credential_labels' => [
					'analytics_key' => 'API Key',
				],
				'base_url' => 'https://www.partner-ads.com/dk/',
				'auth_method' => 'query_param',
				'auth_param' => 'key',
				'response_format' => 'xml',
				'date_format' => 'YY-MM-DD',
				'endpoints' => [
					'saldo' => [
						'url' => 'saldo_xml.php',
						'method' => 'GET',
						'cache_ttl' => 3600, // 1 hour
						'required_credentials' => ['analytics_key'],
					],
					'indtjening' => [
						'url' => 'partnerindtjening_xml.php',
						'method' => 'GET',
						'cache_ttl' => 21600, // 6 hours
						'required_credentials' => ['analytics_key'],
					],
					'indtjening_dato' => [
						'url' => 'partnerindtjening_dato_xml.php',
						'method' => 'GET',
						'cache_ttl' => 43200, // 12 hours
						'required_credentials' => ['analytics_key'],
						'requires_date_range' => true,
					],
					'programstat' => [
						'url' => 'programstat_xml.php',
						'method' => 'GET',
						'cache_ttl' => 86400, // 24 hours
						'required_credentials' => ['analytics_key'],
						'requires_date_range' => true,
					],
					'vissalg' => [
						'url' => 'vissalg_xml.php',
						'method' => 'GET',
						'cache_ttl' => 43200, // 12 hours
						'required_credentials' => ['analytics_key'],
						'requires_date_range' => true,
					],
					'annulleringer' => [
						'url' => 'annulleringer_xml.php',
						'method' => 'GET',
						'cache_ttl' => 43200, // 12 hours
						'required_credentials' => ['analytics_key'],
						'requires_date_range' => true,
					],
					'klikoversigt' => [
						'url' => 'klikoversigt_xml.php',
						'method' => 'GET',
						'cache_ttl' => 7200, // 2 hours
						'required_credentials' => ['analytics_key'],
					],
					'programoversigt' => [
						'url' => 'programoversigt_xml.php',
						'method' => 'GET',
						'cache_ttl' => 86400, // 24 hours
						'required_credentials' => ['analytics_key'],
					],
					'senestenyt' => [
						'url' => 'senestenyt_xml.php',
						'method' => 'GET',
						'cache_ttl' => 21600, // 6 hours
						'required_credentials' => ['analytics_key'],
					],
				],
			];
		
		// Return registry with both network key variations
		return [
			'partner_ads' => $partner_ads_config,
			'api_15' => $partner_ads_config, // Datafeedr's code for Partner-Ads
			'partner_ads_dk' => $partner_ads_config, // Alternative key
		];
	}
}

