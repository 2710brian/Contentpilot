<?php

namespace AEBG\Core\Network_API;

/**
 * Network Clicks Sync
 * 
 * Handles syncing and storing network click data with duplicate prevention.
 * Specifically designed for Partner-Ads klikoversigt (last 40 days).
 *
 * @package AEBG\Core\Network_API
 */
class Network_Clicks_Sync {

	/**
	 * Sync clicks from API response
	 * 
	 * @param string $network_key Network identifier
	 * @param array $clicks_data Click data from API (parsed XML)
	 * @param int $user_id User ID
	 * @return array|WP_Error Stats array or WP_Error on failure
	 */
	public function sync_clicks($network_key, $clicks_data, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_clicks';

		// Ensure table exists
		$this->maybe_create_table();

		if (empty($clicks_data) || !is_array($clicks_data)) {
			return new \WP_Error('invalid_data', 'Invalid clicks data provided');
		}

		// Handle XML structure: clicks might be in 'klik' array or 'item' array
		$clicks = [];
		if (isset($clicks_data['klik']) && is_array($clicks_data['klik'])) {
			$clicks = $clicks_data['klik'];
		} elseif (isset($clicks_data['item']) && is_array($clicks_data['item'])) {
			$clicks = $clicks_data['item'];
		} elseif (is_array($clicks_data) && isset($clicks_data[0])) {
			// Already an array of clicks
			$clicks = $clicks_data;
		} else {
			// Single click object
			$clicks = [$clicks_data];
		}

		$inserted = 0;
		$skipped = 0;
		$errors = [];

		foreach ($clicks as $click) {
			// Normalize click data (handle XML->JSON conversion quirks)
			$click = $this->normalize_click_data($click);

			// Extract click fields
			$programid = $this->extract_value($click, ['programid', 'program_id']);
			$programnavn = $this->extract_value($click, ['programnavn', 'programnavn', 'program_name', 'program_navn']);
			$dato = $this->extract_value($click, ['dato', 'date']);
			$tid = $this->extract_value($click, ['tid', 'time', 'tidspunkt']);
			$url = $this->extract_value($click, ['url']);
			$uid = $this->extract_value($click, ['uid', 'user_id']);
			$uid2 = $this->extract_value($click, ['uid2', 'user_id_2']);
			$salg = $this->extract_value($click, ['salg', 'sale', 'sale_status']);

			// Validate required fields for uniqueness
			if (empty($programid) || empty($dato) || empty($tid)) {
				$errors[] = 'Missing required fields (programid, dato, or tid)';
				$skipped++;
				continue;
			}

			// Check if click already exists (duplicate check)
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $table_name 
				WHERE network_key = %s 
				AND user_id = %d 
				AND programid = %s 
				AND dato = %s 
				AND tid = %s",
				$network_key,
				$user_id,
				$programid,
				$dato,
				$tid
			));

			if ($exists) {
				$skipped++;
				continue; // Skip duplicate
			}

			// Convert date from DD-MM-YYYY to YYYY-MM-DD for database
			$db_date = $this->convert_date_format($dato);
			
			// Convert time to proper format (HH:MM:SS)
			$db_time = $this->convert_time_format($tid);
			
			// Create datetime for sorting
			$click_datetime = null;
			if ($db_date && $db_time) {
				$click_datetime = $db_date . ' ' . $db_time;
			}

			// Insert click
			$result = $wpdb->insert(
				$table_name,
				[
					'network_key' => $network_key,
					'user_id' => $user_id,
					'programid' => $programid,
					'programnavn' => $programnavn,
					'dato' => $db_date,
					'tid' => $db_time,
					'url' => $url,
					'uid' => $uid,
					'uid2' => $uid2,
					'salg' => $salg,
					'click_datetime' => $click_datetime,
					'raw_data' => json_encode($click),
					'synced_at' => current_time('mysql'),
				],
				['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
			);

			if ($result === false) {
				// Check if it's a duplicate key error (race condition)
				if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
					$skipped++;
				} else {
					$errors[] = 'Failed to insert click: ' . $wpdb->last_error;
				}
			} else {
				$inserted++;
			}
		}

		return [
			'inserted' => $inserted,
			'skipped' => $skipped,
			'total' => count($clicks),
			'errors' => $errors,
		];
	}

	/**
	 * Normalize click data from XML->JSON conversion
	 * 
	 * @param array|object $click Raw click data
	 * @return array Normalized click data
	 */
	protected function normalize_click_data($click) {
		if (!is_array($click) && !is_object($click)) {
			return [];
		}

		$normalized = [];

		foreach ($click as $key => $value) {
			// Handle XML->JSON conversion where values might be objects with #text
			if (is_array($value) && isset($value['#text'])) {
				$normalized[$key] = $value['#text'];
			} elseif (is_object($value) && isset($value->{'#text'})) {
				$normalized[$key] = $value->{'#text'};
			} else {
				$normalized[$key] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Extract value from click data
	 * 
	 * @param array $click Click data
	 * @param array $possible_keys Possible key names to try
	 * @return string Extracted value or empty string
	 */
	protected function extract_value($click, $possible_keys) {
		foreach ($possible_keys as $key) {
			if (isset($click[$key]) && $click[$key] !== null && $click[$key] !== '') {
				$value = $click[$key];
				// Handle XML->JSON conversion
				if (is_array($value) && isset($value['#text'])) {
					return $value['#text'];
				}
				return (string)$value;
			}
		}
		return '';
	}

	/**
	 * Convert date from DD-MM-YYYY to YYYY-MM-DD
	 * 
	 * @param string $date Date in DD-MM-YYYY format
	 * @return string Date in YYYY-MM-DD format or original if conversion fails
	 */
	protected function convert_date_format($date) {
		if (empty($date)) {
			return null;
		}

		// Try to parse DD-MM-YYYY format
		$parts = explode('-', $date);
		if (count($parts) === 3) {
			$day = $parts[0];
			$month = $parts[1];
			$year = $parts[2];

			// Handle 2-digit year (assume 20xx)
			if (strlen($year) === 2) {
				$year = '20' . $year;
			}

			// Validate and format
			if (checkdate((int)$month, (int)$day, (int)$year)) {
				return sprintf('%04d-%02d-%02d', (int)$year, (int)$month, (int)$day);
			}
		}

		// Try to parse as-is (might already be YYYY-MM-DD)
		try {
			$dt = new \DateTime($date);
			return $dt->format('Y-m-d');
		} catch (\Exception $e) {
			error_log('[AEBG Network_Clicks_Sync] Failed to parse date: ' . $date);
			return null;
		}
	}

	/**
	 * Convert time to HH:MM:SS format
	 * 
	 * @param string $time Time string (HH:MM or HH:MM:SS)
	 * @return string Time in HH:MM:SS format
	 */
	protected function convert_time_format($time) {
		if (empty($time)) {
			return null;
		}

		// If already in HH:MM:SS format, return as-is
		if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
			return $time;
		}

		// If in HH:MM format, add seconds
		if (preg_match('/^\d{2}:\d{2}$/', $time)) {
			return $time . ':00';
		}

		return $time;
	}

	/**
	 * Get synced clicks for a network
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @param array $args Query arguments (limit, offset, order_by, etc.)
	 * @return array Array of click records
	 */
	public function get_synced_clicks($network_key, $user_id = 1, $args = []) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_clicks';

		$defaults = [
			'limit' => 100,
			'offset' => 0,
			'order_by' => 'click_datetime',
			'order' => 'DESC',
		];

		$args = wp_parse_args($args, $defaults);

		$query = $wpdb->prepare(
			"SELECT * FROM $table_name 
			WHERE network_key = %s AND user_id = %d 
			ORDER BY {$args['order_by']} {$args['order']} 
			LIMIT %d OFFSET %d",
			$network_key,
			$user_id,
			$args['limit'],
			$args['offset']
		);

		return $wpdb->get_results($query, ARRAY_A);
	}

	/**
	 * Get click statistics
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @return array Statistics
	 */
	public function get_click_stats($network_key, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_clicks';

		$total = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE network_key = %s AND user_id = %d",
			$network_key,
			$user_id
		));

		$with_sales = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name 
			WHERE network_key = %s AND user_id = %d AND (salg = 'Ja' OR salg = 'Yes' OR salg = '1')",
			$network_key,
			$user_id
		));

		$last_sync = $wpdb->get_var($wpdb->prepare(
			"SELECT MAX(synced_at) FROM $table_name WHERE network_key = %s AND user_id = %d",
			$network_key,
			$user_id
		));

		return [
			'total_clicks' => (int)$total,
			'clicks_with_sales' => (int)$with_sales,
			'last_sync' => $last_sync,
		];
	}

	/**
	 * Clean up old clicks (older than 40 days)
	 * 
	 * @param string $network_key Network identifier (optional, null for all networks)
	 * @param int $user_id User ID
	 * @return int Number of clicks deleted
	 */
	public function cleanup_old_clicks($network_key = null, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_clicks';

		// Delete clicks older than 40 days
		$cutoff_date = date('Y-m-d', strtotime('-40 days'));

		if ($network_key) {
			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM $table_name 
				WHERE network_key = %s AND user_id = %d AND click_datetime < %s",
				$network_key,
				$user_id,
				$cutoff_date
			));
		} else {
			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM $table_name 
				WHERE user_id = %d AND click_datetime < %s",
				$user_id,
				$cutoff_date
			));
		}

		return $result;
	}

	/**
	 * Create clicks table if it doesn't exist
	 */
	protected function maybe_create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'aebg_network_clicks';

		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

		if (!$table_exists) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE `$table_name` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`network_key` VARCHAR(100) NOT NULL,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`programid` VARCHAR(50) NULL,
				`programnavn` VARCHAR(255) NULL,
				`dato` DATE NULL COMMENT 'Click date (DD-MM-YYYY format from API)',
				`tid` TIME NULL COMMENT 'Click time (HH:MM format from API)',
				`url` TEXT NULL,
				`uid` VARCHAR(255) NULL,
				`uid2` VARCHAR(255) NULL,
				`salg` VARCHAR(10) NULL COMMENT 'Yes/No',
				`click_datetime` DATETIME NULL COMMENT 'Combined date and time for sorting',
				`raw_data` JSON NULL COMMENT 'Full click data from API',
				`synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_network_click_unique` (`network_key`, `user_id`, `programid`, `dato`, `tid`),
				KEY `idx_network_key` (`network_key`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_programid` (`programid`),
				KEY `idx_dato` (`dato`),
				KEY `idx_click_datetime` (`click_datetime`),
				KEY `idx_synced_at` (`synced_at`)
			) $charset_collate;";

			dbDelta($sql);
		}
	}
}

