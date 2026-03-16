<?php

namespace AEBG\Core\Network_API;

/**
 * Network Cancellations Sync
 * 
 * Handles syncing and storing network cancellations data with duplicate prevention.
 * Also marks corresponding sales as cancelled when cancellations are synced.
 * Specifically designed for Partner-Ads annulleringer (cancellations).
 *
 * @package AEBG\Core\Network_API
 */
class Network_Cancellations_Sync {

	/**
	 * Sync cancellations from API response
	 * Also marks corresponding sales as cancelled
	 * 
	 * @param string $network_key Network identifier
	 * @param array $cancellations_data Cancellations data from API (parsed XML)
	 * @param int $user_id User ID
	 * @return array|WP_Error Stats array or WP_Error on failure
	 */
	public function sync_cancellations($network_key, $cancellations_data, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_cancellations';
		$sales_table = $wpdb->prefix . 'aebg_network_sales';

		// Ensure table exists
		$this->maybe_create_table();

		if (empty($cancellations_data) || !is_array($cancellations_data)) {
			return new \WP_Error('invalid_data', 'Invalid cancellations data provided');
		}

		// Handle XML structure: cancellations might be in 'annullering' array or 'item' array
		$cancellations = [];
		if (isset($cancellations_data['annullering']) && is_array($cancellations_data['annullering'])) {
			$cancellations = $cancellations_data['annullering'];
		} elseif (isset($cancellations_data['annulleredeordrer']['annullering']) && is_array($cancellations_data['annulleredeordrer']['annullering'])) {
			$cancellations = $cancellations_data['annulleredeordrer']['annullering'];
		} elseif (isset($cancellations_data['item']) && is_array($cancellations_data['item'])) {
			$cancellations = $cancellations_data['item'];
		} elseif (is_array($cancellations_data) && isset($cancellations_data[0])) {
			// Already an array of cancellations
			$cancellations = $cancellations_data;
		} else {
			// Single cancellation object
			$cancellations = [$cancellations_data];
		}

		$inserted = 0;
		$skipped = 0;
		$sales_marked_cancelled = 0;
		$errors = [];

		foreach ($cancellations as $cancellation) {
			// Normalize cancellation data (handle XML->JSON conversion quirks)
			$cancellation = $this->normalize_cancellation_data($cancellation);

			// Extract cancellation fields
			$convid = $this->extract_value($cancellation, ['convid', 'konvid', 'conversion_id', 'conv_id']);
			$programid = $this->extract_value($cancellation, ['programid', 'program_id']);
			$program = $this->extract_value($cancellation, ['program', 'programnavn', 'program_name']);
			$dato = $this->extract_value($cancellation, ['dato', 'date']);
			$ordrenr = $this->extract_value($cancellation, ['ordrenr', 'order_number', 'ordernr']);
			$varenr = $this->extract_value($cancellation, ['varenr', 'product_number', 'varenr']);
			$ordretotal = $this->extract_value($cancellation, ['ordretotal', 'order_total', 'total']);
			$provision = $this->extract_value($cancellation, ['provision', 'commission']);
			$uid = $this->extract_value($cancellation, ['uid', 'user_id']);
			$uid2 = $this->extract_value($cancellation, ['uid2', 'user_id_2']);

			// Validate required fields for uniqueness (convid is the unique identifier)
			if (empty($convid)) {
				$errors[] = 'Missing required field: convid (conversion ID)';
				$skipped++;
				continue;
			}

			// Check if cancellation already exists (duplicate check using convid)
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $table_name 
				WHERE network_key = %s 
				AND user_id = %d 
				AND convid = %s",
				$network_key,
				$user_id,
				$convid
			));

			if ($exists) {
				$skipped++;
				// Still mark the sale as cancelled if it exists (in case sale was synced after cancellation)
				$this->mark_sale_as_cancelled($network_key, $user_id, $convid);
				continue; // Skip duplicate cancellation record
			}

			// Convert date from DD-MM-YYYY to YYYY-MM-DD for database
			$db_date = $this->convert_date_format($dato);
			
			// Create datetime for sorting (use date with 00:00:00 time)
			$cancellation_datetime = null;
			if ($db_date) {
				$cancellation_datetime = $db_date . ' 00:00:00';
			}

			// Convert numeric values
			$ordretotal_float = $this->parse_decimal($ordretotal);
			$provision_float = $this->parse_decimal($provision);

			// Insert cancellation
			$result = $wpdb->insert(
				$table_name,
				[
					'network_key' => $network_key,
					'user_id' => $user_id,
					'convid' => $convid,
					'programid' => $programid,
					'program' => $program,
					'dato' => $db_date,
					'ordrenr' => $ordrenr,
					'varenr' => $varenr,
					'ordretotal' => $ordretotal_float,
					'provision' => $provision_float,
					'uid' => $uid,
					'uid2' => $uid2,
					'cancellation_datetime' => $cancellation_datetime,
					'raw_data' => json_encode($cancellation),
					'synced_at' => current_time('mysql'),
				],
				['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s']
			);

			if ($result === false) {
				// Check if it's a duplicate key error (race condition)
				if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
					$skipped++;
				} else {
					$errors[] = 'Failed to insert cancellation: ' . $wpdb->last_error;
				}
			} else {
				$inserted++;
				
				// Mark corresponding sale as cancelled (if it exists)
				if ($this->mark_sale_as_cancelled($network_key, $user_id, $convid)) {
					$sales_marked_cancelled++;
				}
			}
		}

		return [
			'inserted' => $inserted,
			'skipped' => $skipped,
			'total' => count($cancellations),
			'sales_marked_cancelled' => $sales_marked_cancelled,
			'errors' => $errors,
		];
	}

	/**
	 * Mark a sale as cancelled by matching convid/konvid
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @param string $convid Conversion ID
	 * @return bool True if sale was found and marked, false otherwise
	 */
	protected function mark_sale_as_cancelled($network_key, $user_id, $convid) {
		global $wpdb;

		$sales_table = $wpdb->prefix . 'aebg_network_sales';

		// Check if sales table exists
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$sales_table'") === $sales_table;
		if (!$table_exists) {
			return false;
		}

		// Update sale to mark as cancelled (only if not already cancelled)
		$updated = $wpdb->query($wpdb->prepare(
			"UPDATE $sales_table 
			SET is_cancelled = 1, 
				cancelled_at = %s,
				updated_at = %s
			WHERE network_key = %s 
			AND user_id = %d 
			AND konvid = %s 
			AND is_cancelled = 0",
			current_time('mysql'),
			current_time('mysql'),
			$network_key,
			$user_id,
			$convid
		));

		return $updated > 0;
	}

	/**
	 * Normalize cancellation data from XML->JSON conversion
	 * 
	 * @param array|object $cancellation Raw cancellation data
	 * @return array Normalized cancellation data
	 */
	protected function normalize_cancellation_data($cancellation) {
		if (!is_array($cancellation) && !is_object($cancellation)) {
			return [];
		}

		$normalized = [];

		foreach ($cancellation as $key => $value) {
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
	 * Extract value from cancellation data
	 * 
	 * @param array $cancellation Cancellation data
	 * @param array $possible_keys Possible key names to try
	 * @return string Extracted value or empty string
	 */
	protected function extract_value($cancellation, $possible_keys) {
		foreach ($possible_keys as $key) {
			if (isset($cancellation[$key]) && $cancellation[$key] !== null && $cancellation[$key] !== '') {
				$value = $cancellation[$key];
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
			error_log('[AEBG Network_Cancellations_Sync] Failed to parse date: ' . $date);
			return null;
		}
	}

	/**
	 * Get synced cancellations for a network
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @param array $args Query arguments (limit, offset, order_by, date_from, date_to, etc.)
	 * @return array Array of cancellation records
	 */
	public function get_synced_cancellations($network_key, $user_id = 1, $args = []) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_cancellations';

		$defaults = [
			'limit' => 100,
			'offset' => 0,
			'order_by' => 'cancellation_datetime',
			'order' => 'DESC',
			'date_from' => null,
			'date_to' => null,
		];

		$args = wp_parse_args($args, $defaults);

		$where = $wpdb->prepare("network_key = %s AND user_id = %d", $network_key, $user_id);

		if ($args['date_from']) {
			$where .= $wpdb->prepare(" AND dato >= %s", $args['date_from']);
		}

		if ($args['date_to']) {
			$where .= $wpdb->prepare(" AND dato <= %s", $args['date_to']);
		}

		$query = "SELECT * FROM $table_name 
			WHERE $where 
			ORDER BY {$args['order_by']} {$args['order']} 
			LIMIT %d OFFSET %d";

		$query = $wpdb->prepare($query, $args['limit'], $args['offset']);

		return $wpdb->get_results($query, ARRAY_A);
	}

	/**
	 * Get cancellation statistics
	 * 
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID
	 * @param array $args Query arguments (date_from, date_to)
	 * @return array Statistics
	 */
	public function get_cancellation_stats($network_key, $user_id = 1, $args = []) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_cancellations';

		$where = $wpdb->prepare("network_key = %s AND user_id = %d", $network_key, $user_id);

		if (!empty($args['date_from'])) {
			$where .= $wpdb->prepare(" AND dato >= %s", $args['date_from']);
		}

		if (!empty($args['date_to'])) {
			$where .= $wpdb->prepare(" AND dato <= %s", $args['date_to']);
		}

		$total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where");

		$total_commission_cancelled = $wpdb->get_var("SELECT SUM(provision) FROM $table_name WHERE $where");

		$last_sync = $wpdb->get_var("SELECT MAX(synced_at) FROM $table_name WHERE $where");

		return [
			'total_cancellations' => (int)$total,
			'total_commission_cancelled' => (float)($total_commission_cancelled ?? 0),
			'last_sync' => $last_sync,
		];
	}

	/**
	 * Create cancellations table if it doesn't exist
	 */
	protected function maybe_create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'aebg_network_cancellations';

		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

		if (!$table_exists) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE `$table_name` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`network_key` VARCHAR(100) NOT NULL,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`convid` VARCHAR(100) NULL COMMENT 'Conversion ID (matches konvid in sales table)',
				`programid` VARCHAR(50) NULL,
				`program` VARCHAR(255) NULL COMMENT 'Program name',
				`dato` DATE NULL COMMENT 'Cancellation date (DD-MM-YYYY format from API)',
				`ordrenr` VARCHAR(255) NULL COMMENT 'Order number',
				`varenr` VARCHAR(255) NULL COMMENT 'Product number',
				`ordretotal` DECIMAL(10,2) NULL COMMENT 'Order total',
				`provision` DECIMAL(10,2) NULL COMMENT 'Commission that was cancelled',
				`uid` VARCHAR(255) NULL,
				`uid2` VARCHAR(255) NULL,
				`cancellation_datetime` DATETIME NULL COMMENT 'Combined date and time for sorting',
				`raw_data` JSON NULL COMMENT 'Full cancellation data from API',
				`synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_network_cancellation_unique` (`network_key`, `user_id`, `convid`),
				KEY `idx_network_key` (`network_key`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_programid` (`programid`),
				KEY `idx_dato` (`dato`),
				KEY `idx_convid` (`convid`),
				KEY `idx_cancellation_datetime` (`cancellation_datetime`),
				KEY `idx_synced_at` (`synced_at`)
			) $charset_collate;";

			dbDelta($sql);
		}
	}
}

