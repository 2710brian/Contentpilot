<?php

namespace AEBG\Core\Network_API;

/**
 * Network Sales Sync
 * 
 * Handles syncing and storing network sales/leads data with duplicate prevention.
 * Specifically designed for Partner-Ads vissalg (sales/leads overview).
 *
 * @package AEBG\Core\Network_API
 */
class Network_Sales_Sync {

	/**
	 * Sync sales from API response
	 * 
	 * @param string $network_key Network identifier
	 * @param array $sales_data Sales data from API (parsed XML)
	 * @param int $user_id User ID
	 * @return array|WP_Error Stats array or WP_Error on failure
	 */
	public function sync_sales($network_key, $sales_data, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_sales';

		// Ensure table exists
		$this->maybe_create_table();

		if (empty($sales_data) || !is_array($sales_data)) {
			return new \WP_Error('invalid_data', 'Invalid sales data provided');
		}

		// Handle XML structure: sales might be in 'salg' array or 'item' array
		$sales = [];
		if (isset($sales_data['salg']) && is_array($sales_data['salg'])) {
			$sales = $sales_data['salg'];
		} elseif (isset($sales_data['salgspec']['salg']) && is_array($sales_data['salgspec']['salg'])) {
			$sales = $sales_data['salgspec']['salg'];
		} elseif (isset($sales_data['item']) && is_array($sales_data['item'])) {
			$sales = $sales_data['item'];
		} elseif (is_array($sales_data) && isset($sales_data[0])) {
			// Already an array of sales
			$sales = $sales_data;
		} else {
			// Single sale object
			$sales = [$sales_data];
		}

		$inserted = 0;
		$skipped = 0;
		$errors = [];

		foreach ($sales as $sale) {
			// Normalize sale data (handle XML->JSON conversion quirks)
			$sale = $this->normalize_sale_data($sale);

			// Extract sale fields
			$konvid = $this->extract_value($sale, ['konvid', 'conversion_id', 'conv_id']);
			$type = $this->extract_value($sale, ['type']);
			$programid = $this->extract_value($sale, ['programid', 'program_id']);
			$program = $this->extract_value($sale, ['program', 'programnavn', 'program_name']);
			$dato = $this->extract_value($sale, ['dato', 'date']);
			$tidspunkt = $this->extract_value($sale, ['tidspunkt', 'time', 'tid']);
			$ordrenr = $this->extract_value($sale, ['ordrenr', 'order_number', 'ordernr']);
			$varenr = $this->extract_value($sale, ['varenr', 'product_number', 'varenr']);
			$omsaetning = $this->extract_value($sale, ['omsaetning', 'revenue', 'turnover']);
			$provision = $this->extract_value($sale, ['provision', 'commission']);
			$url = $this->extract_value($sale, ['url']);
			$uid = $this->extract_value($sale, ['uid', 'user_id']);
			$uid2 = $this->extract_value($sale, ['uid2', 'user_id_2']);
			$valuta = $this->extract_value($sale, ['valuta', 'currency']);

			// Validate required fields for uniqueness (konvid is the unique identifier)
			if (empty($konvid)) {
				$errors[] = 'Missing required field: konvid (conversion ID)';
				$skipped++;
				continue;
			}

			// Check if sale already exists (duplicate check using konvid)
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $table_name 
				WHERE network_key = %s 
				AND user_id = %d 
				AND konvid = %s",
				$network_key,
				$user_id,
				$konvid
			));

			if ($exists) {
				$skipped++;
				continue; // Skip duplicate
			}

			// Convert date from DD-MM-YYYY to YYYY-MM-DD for database
			$db_date = $this->convert_date_format($dato);
			
			// Convert time to proper format (HH:MM:SS)
			$db_time = $this->convert_time_format($tidspunkt);
			
			// Create datetime for sorting
			$sale_datetime = null;
			if ($db_date && $db_time) {
				$sale_datetime = $db_date . ' ' . $db_time;
			}

			// Convert numeric values
			$omsaetning_float = $this->parse_decimal($omsaetning);
			$provision_float = $this->parse_decimal($provision);

			// Insert sale
			$result = $wpdb->insert(
				$table_name,
				[
					'network_key' => $network_key,
					'user_id' => $user_id,
					'konvid' => $konvid,
					'type' => $type,
					'programid' => $programid,
					'program' => $program,
					'dato' => $db_date,
					'tidspunkt' => $db_time,
					'ordrenr' => $ordrenr,
					'varenr' => $varenr,
					'omsaetning' => $omsaetning_float,
					'provision' => $provision_float,
					'url' => $url,
					'uid' => $uid,
					'uid2' => $uid2,
					'valuta' => $valuta,
					'is_cancelled' => 0,
					'cancelled_at' => null,
					'sale_datetime' => $sale_datetime,
					'raw_data' => json_encode($sale),
					'synced_at' => current_time('mysql'),
				],
				['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
			);

			if ($result === false) {
				// Check if it's a duplicate key error (race condition)
				if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
					$skipped++;
				} else {
					$errors[] = 'Failed to insert sale: ' . $wpdb->last_error;
				}
			} else {
				$inserted++;
			}
		}

		return [
			'inserted' => $inserted,
			'skipped' => $skipped,
			'total' => count($sales),
			'errors' => $errors,
		];
	}

	/**
	 * Normalize sale data from XML->JSON conversion
	 * 
	 * @param array|object $sale Raw sale data
	 * @return array Normalized sale data
	 */
	protected function normalize_sale_data($sale) {
		if (!is_array($sale) && !is_object($sale)) {
			return [];
		}

		$normalized = [];

		foreach ($sale as $key => $value) {
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
	 * Extract value from sale data
	 * 
	 * @param array $sale Sale data
	 * @param array $possible_keys Possible key names to try
	 * @return string Extracted value or empty string
	 */
	protected function extract_value($sale, $possible_keys) {
		foreach ($possible_keys as $key) {
			if (isset($sale[$key]) && $sale[$key] !== null && $sale[$key] !== '') {
				$value = $sale[$key];
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
	 * Parse decimal value
	 * 
	 * @param string $value Decimal string
	 * @return float|null Parsed float or null
	 */
	protected function parse_decimal($value) {
		if (empty($value)) {
			return null;
		}

		// Remove any non-numeric characters except decimal point and minus
		$cleaned = preg_replace('/[^0-9.-]/', '', (string)$value);
		
		if ($cleaned === '' || $cleaned === '-') {
			return null;
		}

		return (float)$cleaned;
	}

	/**
	 * Convert date from DD-MM-YYYY to YYYY-MM-DD
	 * 
	 * @param string $date Date in DD-MM-YYYY format
	 * @return string Date in YYYY-MM-DD format or null if conversion fails
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
			error_log('[AEBG Network_Sales_Sync] Failed to parse date: ' . $date);
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
	 * Get synced sales for a network
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @param array $args Query arguments (limit, offset, order_by, date_from, date_to, etc.)
	 * @return array Array of sale records
	 */
	public function get_synced_sales($network_key, $user_id = 1, $args = []) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_sales';

		$defaults = [
			'limit' => 100,
			'offset' => 0,
			'order_by' => 'sale_datetime',
			'order' => 'DESC',
			'date_from' => null,
			'date_to' => null,
			'type' => null, // 'salg', 'lead', etc.
		];

		$args = wp_parse_args($args, $defaults);

		$where = $wpdb->prepare("network_key = %s AND user_id = %d", $network_key, $user_id);

		if ($args['date_from']) {
			$where .= $wpdb->prepare(" AND dato >= %s", $args['date_from']);
		}

		if ($args['date_to']) {
			$where .= $wpdb->prepare(" AND dato <= %s", $args['date_to']);
		}

		if ($args['type']) {
			$where .= $wpdb->prepare(" AND type = %s", $args['type']);
		}

		$query = "SELECT * FROM $table_name 
			WHERE $where 
			ORDER BY {$args['order_by']} {$args['order']} 
			LIMIT %d OFFSET %d";

		$query = $wpdb->prepare($query, $args['limit'], $args['offset']);

		return $wpdb->get_results($query, ARRAY_A);
	}

	/**
	 * Get sales statistics
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @param array $args Query arguments (date_from, date_to, type)
	 * @return array Statistics
	 */
	public function get_sales_stats($network_key, $user_id = 1, $args = []) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_sales';

		$where = $wpdb->prepare("network_key = %s AND user_id = %d", $network_key, $user_id);

		if (!empty($args['date_from'])) {
			$where .= $wpdb->prepare(" AND dato >= %s", $args['date_from']);
		}

		if (!empty($args['date_to'])) {
			$where .= $wpdb->prepare(" AND dato <= %s", $args['date_to']);
		}

		if (!empty($args['type'])) {
			$where .= $wpdb->prepare(" AND type = %s", $args['type']);
		}

		if (isset($args['is_cancelled'])) {
			$where .= $wpdb->prepare(" AND is_cancelled = %d", $args['is_cancelled'] ? 1 : 0);
		}

		$total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where");

		$total_revenue = $wpdb->get_var("SELECT SUM(omsaetning) FROM $table_name WHERE $where");
		$total_commission = $wpdb->get_var("SELECT SUM(provision) FROM $table_name WHERE $where");

		$last_sync = $wpdb->get_var("SELECT MAX(synced_at) FROM $table_name WHERE $where");

		return [
			'total_sales' => (int)$total,
			'total_revenue' => (float)($total_revenue ?? 0),
			'total_commission' => (float)($total_commission ?? 0),
			'last_sync' => $last_sync,
		];
	}

	/**
	 * Clean up old sales (older than specified days)
	 * 
	 * @param string $network_key Network identifier (optional, null for all networks)
	 * @param int $user_id User ID
	 * @param int $days Number of days to keep (default: 365)
	 * @return int Number of sales deleted
	 */
	public function cleanup_old_sales($network_key = null, $user_id = 1, $days = 365) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_sales';

		// Delete sales older than specified days
		$cutoff_date = date('Y-m-d', strtotime("-{$days} days"));

		if ($network_key) {
			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM $table_name 
				WHERE network_key = %s AND user_id = %d AND sale_datetime < %s",
				$network_key,
				$user_id,
				$cutoff_date
			));
		} else {
			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM $table_name 
				WHERE user_id = %d AND sale_datetime < %s",
				$user_id,
				$cutoff_date
			));
		}

		return $result;
	}

	/**
	 * Create sales table if it doesn't exist
	 */
	protected function maybe_create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'aebg_network_sales';

		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

		if (!$table_exists) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE `$table_name` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`network_key` VARCHAR(100) NOT NULL,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`konvid` VARCHAR(100) NULL COMMENT 'Conversion ID (unique identifier)',
				`type` VARCHAR(50) NULL COMMENT 'salg, lead, etc.',
				`programid` VARCHAR(50) NULL,
				`program` VARCHAR(255) NULL COMMENT 'Program name',
				`dato` DATE NULL COMMENT 'Sale date (DD-MM-YYYY format from API)',
				`tidspunkt` TIME NULL COMMENT 'Sale time (HH:MM:SS format from API)',
				`ordrenr` VARCHAR(255) NULL COMMENT 'Order number',
				`varenr` VARCHAR(255) NULL COMMENT 'Product number',
				`omsaetning` DECIMAL(10,2) NULL COMMENT 'Revenue/turnover',
				`provision` DECIMAL(10,2) NULL COMMENT 'Commission',
				`url` TEXT NULL,
				`uid` VARCHAR(255) NULL,
				`uid2` VARCHAR(255) NULL,
				`valuta` VARCHAR(10) NULL COMMENT 'Currency code',
				`sale_datetime` DATETIME NULL COMMENT 'Combined date and time for sorting',
				`raw_data` JSON NULL COMMENT 'Full sale data from API',
				`synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_network_sale_unique` (`network_key`, `user_id`, `konvid`),
				KEY `idx_network_key` (`network_key`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_programid` (`programid`),
				KEY `idx_dato` (`dato`),
				KEY `idx_ordrenr` (`ordrenr`),
				KEY `idx_sale_datetime` (`sale_datetime`),
				KEY `idx_synced_at` (`synced_at`),
				KEY `idx_type` (`type`)
			) $charset_collate;";

			dbDelta($sql);
		}
	}
}

