<?php

namespace AEBG\Core\Network_API;

/**
 * Network Credential Manager
 * 
 * Handles storage, retrieval, and encryption of network API credentials.
 * Supports multiple credentials per network (analytics_key, feed_key, etc.)
 *
 * @package AEBG\Core\Network_API
 */
class Network_Credential_Manager {

	/**
	 * Store a credential for a network
	 *
	 * @param string $network_key Network identifier
	 * @param string $credential_type Type of credential (analytics_key, feed_key, etc.)
	 * @param string $credential_value The credential value to store
	 * @param int $user_id User ID (default: 1)
	 * @param array $metadata Additional metadata (expires_at, etc.)
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function store_credential($network_key, $credential_type, $credential_value, $user_id = 1, $metadata = []) {
		global $wpdb;

		if (empty($network_key) || empty($credential_type) || empty($credential_value)) {
			return new \WP_Error('invalid_params', 'Network key, credential type, and value are required');
		}

		$table_name = $wpdb->prefix . 'aebg_network_api_credentials';

		// Ensure table exists
		$this->maybe_create_table();

		// Encrypt the credential
		$encrypted_value = $this->encrypt($credential_value);

		// Check if credential already exists
		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM $table_name 
			WHERE network_key = %s AND user_id = %d AND credential_type = %s",
			$network_key,
			$user_id,
			$credential_type
		));

		$data = [
			'network_key' => $network_key,
			'user_id' => $user_id,
			'credential_type' => $credential_type,
			'credential_value' => $encrypted_value,
			'is_encrypted' => 1,
			'is_active' => 1,
			'last_used' => null,
			'last_validated' => current_time('mysql'),
			'validation_status' => 'unknown',
			'updated_at' => current_time('mysql'),
		];

		// Add metadata
		if (!empty($metadata)) {
			$data['metadata'] = json_encode($metadata);
			if (isset($metadata['expires_at'])) {
				$data['expires_at'] = $metadata['expires_at'];
			}
		}

		if ($existing) {
			// Update existing
			$result = $wpdb->update(
				$table_name,
				$data,
				['id' => $existing],
				['%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'],
				['%d']
			);
		} else {
			// Insert new
			$data['created_at'] = current_time('mysql');
			$result = $wpdb->insert(
				$table_name,
				$data,
				['%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
			);
		}

		if ($result === false) {
			error_log('[AEBG Network_Credential_Manager] Failed to store credential: ' . $wpdb->last_error);
			return new \WP_Error('db_error', 'Failed to store credential: ' . $wpdb->last_error);
		}

		return true;
	}

	/**
	 * Get a specific credential for a network
	 *
	 * @param string $network_key Network identifier
	 * @param string $credential_type Type of credential
	 * @param int $user_id User ID (default: 1)
	 * @return string|null Decrypted credential value or null if not found
	 */
	public function get_credential($network_key, $credential_type, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_api_credentials';

		// Ensure table exists
		$this->maybe_create_table();

		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT credential_value, is_encrypted, is_active 
			FROM $table_name 
			WHERE network_key = %s AND user_id = %d AND credential_type = %s AND is_active = 1",
			$network_key,
			$user_id,
			$credential_type
		));

		if (!$row) {
			return null;
		}

		// Decrypt if encrypted
		if ($row->is_encrypted) {
			return $this->decrypt($row->credential_value);
		}

		return $row->credential_value;
	}

	/**
	 * Get all credentials for a network
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID (default: 1)
	 * @return array Array of credentials with their types
	 */
	public function get_all_credentials($network_key, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_api_credentials';

		// Ensure table exists
		$this->maybe_create_table();

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT credential_type, credential_value, is_encrypted, metadata, expires_at, validation_status
			FROM $table_name 
			WHERE network_key = %s AND user_id = %d AND is_active = 1",
			$network_key,
			$user_id
		), ARRAY_A);

		$credentials = [];
		foreach ($results as $row) {
			$value = $row['is_encrypted'] ? $this->decrypt($row['credential_value']) : $row['credential_value'];
			$credentials[$row['credential_type']] = [
				'credential_value' => $value,
				'metadata' => !empty($row['metadata']) ? json_decode($row['metadata'], true) : [],
				'expires_at' => $row['expires_at'],
				'validation_status' => $row['validation_status'],
			];
		}

		return $credentials;
	}

	/**
	 * Delete a specific credential
	 *
	 * @param string $network_key Network identifier
	 * @param string $credential_type Type of credential
	 * @param int $user_id User ID (default: 1)
	 * @return bool True on success
	 */
	public function delete_credential($network_key, $credential_type, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_api_credentials';

		$result = $wpdb->update(
			$table_name,
			['is_active' => 0, 'updated_at' => current_time('mysql')],
			[
				'network_key' => $network_key,
				'user_id' => $user_id,
				'credential_type' => $credential_type,
			],
			['%d', '%s'],
			['%s', '%d', '%s']
		);

		return $result !== false;
	}

	/**
	 * Delete all credentials for a network
	 *
	 * @param string $network_key Network identifier
	 * @param int $user_id User ID (default: 1)
	 * @return bool True on success
	 */
	public function delete_all_credentials($network_key, $user_id = 1) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'aebg_network_api_credentials';

		$result = $wpdb->update(
			$table_name,
			['is_active' => 0, 'updated_at' => current_time('mysql')],
			[
				'network_key' => $network_key,
				'user_id' => $user_id,
			],
			['%d', '%s'],
			['%s', '%d']
		);

		return $result !== false;
	}

	/**
	 * Check if network has all required credentials
	 *
	 * @param string $network_key Network identifier
	 * @param array $required_credentials Array of required credential types
	 * @param int $user_id User ID (default: 1)
	 * @return bool True if all required credentials are present
	 */
	public function has_required_credentials($network_key, $required_credentials, $user_id = 1) {
		if (empty($required_credentials)) {
			return true; // No requirements
		}

		foreach ($required_credentials as $cred_type) {
			$credential = $this->get_credential($network_key, $cred_type, $user_id);
			if (empty($credential)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Encrypt a value
	 *
	 * @param string $value Value to encrypt
	 * @return string Encrypted value
	 */
	protected function encrypt($value) {
		if (empty($value)) {
			return $value;
		}

		// Use WordPress salts for encryption key
		$key = $this->get_encryption_key();
		$method = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length($method);
		$iv = openssl_random_pseudo_bytes($iv_length);

		$encrypted = openssl_encrypt($value, $method, $key, 0, $iv);

		// Prepend IV to encrypted string
		return base64_encode($iv . $encrypted);
	}

	/**
	 * Decrypt a value
	 *
	 * @param string $encrypted_value Encrypted value
	 * @return string Decrypted value
	 */
	protected function decrypt($encrypted_value) {
		if (empty($encrypted_value)) {
			return $encrypted_value;
		}

		$key = $this->get_encryption_key();
		$method = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length($method);

		$data = base64_decode($encrypted_value);
		$iv = substr($data, 0, $iv_length);
		$encrypted = substr($data, $iv_length);

		$decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);

		return $decrypted !== false ? $decrypted : '';
	}

	/**
	 * Get encryption key from WordPress salts
	 *
	 * @return string Encryption key
	 */
	protected function get_encryption_key() {
		// Use WordPress salts to create a consistent encryption key
		// CRITICAL: AUTH_SALT must be defined for secure encryption
		if ( ! defined( 'AUTH_SALT' ) || empty( AUTH_SALT ) ) {
			// Generate a secure random salt and store it in options as fallback
			$fallback_salt = get_option( 'aebg_encryption_salt', '' );
			if ( empty( $fallback_salt ) ) {
				// Generate a secure random 64-character salt
				$fallback_salt = bin2hex( random_bytes( 32 ) );
				update_option( 'aebg_encryption_salt', $fallback_salt, false );
			}
			$salt = $fallback_salt;
		} else {
			$salt = AUTH_SALT;
		}
		$key = hash( 'sha256', $salt . 'aebg-network-api-credentials' );
		return $key;
	}

	/**
	 * Create credentials table if it doesn't exist
	 */
	protected function maybe_create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'aebg_network_api_credentials';

		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

		if (!$table_exists) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE `$table_name` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`network_key` VARCHAR(100) NOT NULL,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`credential_type` VARCHAR(50) NOT NULL COMMENT 'analytics_key, feed_key, reporting_key, access_token, refresh_token, secret_key, etc.',
				`credential_value` TEXT NOT NULL COMMENT 'Encrypted credential value',
				`credential_label` VARCHAR(255) NULL COMMENT 'User-friendly label',
				`is_encrypted` TINYINT(1) NOT NULL DEFAULT 1,
				`is_active` TINYINT(1) NOT NULL DEFAULT 1,
				`last_used` DATETIME NULL,
				`last_validated` DATETIME NULL,
				`validation_status` VARCHAR(50) NULL COMMENT 'valid, invalid, expired, unknown',
				`expires_at` DATETIME NULL COMMENT 'For OAuth tokens that expire',
				`metadata` JSON NULL COMMENT 'Additional network-specific metadata',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_network_user_type` (`network_key`, `user_id`, `credential_type`),
				KEY `idx_network_key` (`network_key`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_credential_type` (`credential_type`),
				KEY `idx_is_active` (`is_active`),
				KEY `idx_validation_status` (`validation_status`),
				KEY `idx_expires_at` (`expires_at`)
			) $charset_collate;";

			dbDelta($sql);
		}
	}
}

