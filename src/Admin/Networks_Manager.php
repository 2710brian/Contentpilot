<?php

namespace AEBG\Admin;

/**
 * Networks Manager Class
 * 
 * Handles storing and retrieving networks from the database using unified table
 * 
 * @package AEBG\Admin
 */
class Networks_Manager {

    /**
     * Flag to prevent infinite recursion when creating table
     * @var bool
     */
    private static $creating_table = false;

    /**
     * Initialize the networks manager
     */
    public function __construct() {
        add_action('init', [$this, 'maybe_create_unified_table']);
        add_action('wp_ajax_aebg_sync_networks_from_api', [$this, 'ajax_sync_networks_from_api']);
    }

    /**
     * Create unified database table if it doesn't exist
     */
    public function maybe_create_unified_table() {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$unified_table'") === $unified_table;
        
        if (!$table_exists) {
            $this->create_unified_table();
        }
    }

    /**
     * Create unified networks table
     */
    public function create_unified_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE `{$wpdb->prefix}aebg_networks_unified` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_key` VARCHAR(100) NOT NULL,
            `network_name` VARCHAR(255) NOT NULL,
            `network_type` VARCHAR(50) NOT NULL DEFAULT 'affiliate',
            `region` VARCHAR(50) NULL,
            `country` VARCHAR(10) NULL,
            `country_name` VARCHAR(100) NULL,
            `flag` VARCHAR(10) NULL,
            `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
            `category` VARCHAR(50) NULL,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `affiliate_id` VARCHAR(255) NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            `last_used` DATETIME NULL,
            `usage_count` INT(11) NOT NULL DEFAULT 0,
            `api_data` JSON NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_network_user` (`network_key`, `user_id`),
            KEY `idx_network_key` (`network_key`),
            KEY `idx_network_type` (`network_type`),
            KEY `idx_region` (`region`),
            KEY `idx_country` (`country`),
            KEY `idx_category` (`category`),
            KEY `idx_is_popular` (`is_popular`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_last_used` (`last_used`)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        // Populate with default networks if table is empty
        $this->populate_default_networks();
    }

    /**
     * Populate default networks from Networks_Data or Datafeedr API
     */
    private function populate_default_networks() {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        // Check if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $unified_table");
        if ($count > 0) {
            return; // Already populated
        }
        
        // Try to fetch networks from Datafeedr API first (if credentials are available)
        // Only if we're not currently creating the table (to avoid recursion)
        // Note: During initial table creation, we skip API sync and just use Networks_Data
        // The user can manually sync from API later using the sync button
        if (!self::$creating_table) {
            $api_result = $this->sync_networks_from_api(false); // false = don't clear existing, just populate if empty
            
            // sync_networks_from_api now returns an array with stats, not the networks themselves
            if (is_array($api_result) && isset($api_result['synced']) && $api_result['synced'] > 0) {
                error_log('[AEBG Networks_Manager] Populated ' . $api_result['synced'] . ' networks from Datafeedr API');
                return;
            }
        }
        
        // Fallback to hardcoded networks from Networks_Data
        $networks_data = new Networks_Data();
        $all_networks = $networks_data->get_all_networks();
        
        error_log('[AEBG Networks_Manager] Populating default networks. Received ' . count($all_networks) . ' networks from Networks_Data');
        
        foreach ($all_networks as $network) {
            // Map the keys from Networks_Data to our unified table structure
            // Networks_Data returns: code, name, country, countryName, popular, category
            // We need: network_key, network_name, network_type, region, country, country_name, flag, is_popular, category, sort_order, is_active
            
            // Log the first network structure for debugging
            if (array_search($network, $all_networks) === 0) {
                error_log('[AEBG Networks_Manager] First network structure: ' . json_encode($network));
            }
            
            $wpdb->insert(
                $unified_table,
                [
                    'network_key' => $network['code'] ?? $network['network_key'] ?? 'unknown',
                    'network_name' => $network['name'] ?? $network['network_name'] ?? 'Unknown Network',
                    'network_type' => 'affiliate', // Default type
                    'region' => $network['region'] ?? null,
                    'country' => $network['country'] ?? $network['countryCode'] ?? null,
                    'country_name' => $network['countryName'] ?? $network['country_name'] ?? null,
                    'flag' => $network['flag'] ?? null,
                    'is_popular' => isset($network['popular']) ? (int)$network['popular'] : 0,
                    'category' => $network['category'] ?? 'affiliate',
                    'sort_order' => $network['sort_order'] ?? 0,
                    'is_active' => isset($network['is_active']) ? (int)$network['is_active'] : 1,
                    'affiliate_id' => null,
                    'user_id' => 1,
                    'last_used' => null,
                    'usage_count' => 0,
                    'api_data' => json_encode($network['api_data'] ?? []),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                [
                    '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s'
                ]
            );
            
            // Log any database errors
            if ($wpdb->last_error) {
                error_log('[AEBG Networks_Manager] Database error inserting network ' . ($network['code'] ?? 'unknown') . ': ' . $wpdb->last_error);
            }
        }
        
        // Log summary
        $final_count = $wpdb->get_var("SELECT COUNT(*) FROM $unified_table");
        error_log('[AEBG Networks_Manager] Default networks population complete. Table now contains ' . $final_count . ' networks');
    }
    
    /**
     * Sync networks from Datafeedr API
     * 
     * @param bool $clear_existing Whether to clear existing networks before syncing
     * @return array|WP_Error Array of synced networks or WP_Error on failure
     */
    public function sync_networks_from_api($clear_existing = false) {
        global $wpdb;
        
        // Invalidate network cache when syncing
        \AEBG\Core\Network_API_Manager::invalidate_network_cache();
        
        // Check if Datafeedr is configured
        $options = get_option('aebg_settings', []);
        $access_id = $options['datafeedr_access_id'] ?? '';
        $access_key = $options['datafeedr_secret_key'] ?? '';
        $enabled = isset($options['enable_datafeedr']) ? (bool)$options['enable_datafeedr'] : false;
        
        if (!$enabled || empty($access_id) || empty($access_key)) {
            error_log('[AEBG Networks_Manager] Datafeedr not configured, skipping API sync');
            return [];
        }
        
        // Ensure the unified table exists before proceeding
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$unified_table'") === $unified_table;
        
        if (!$table_exists && !self::$creating_table) {
            error_log('[AEBG Networks_Manager] Unified table does not exist, creating it...');
            self::$creating_table = true;
            $this->maybe_create_unified_table();
            self::$creating_table = false;
            
            // Verify table was created
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$unified_table'") === $unified_table;
            if (!$table_exists) {
                error_log('[AEBG Networks_Manager] Failed to create unified table. Error: ' . $wpdb->last_error);
                return new \WP_Error('table_creation_failed', 'Failed to create networks table. Please check database permissions.');
            }
            error_log('[AEBG Networks_Manager] Unified table created successfully');
        } elseif (!$table_exists && self::$creating_table) {
            // Table is being created, wait a moment and check again
            error_log('[AEBG Networks_Manager] Table is being created, waiting...');
            sleep(1);
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$unified_table'") === $unified_table;
            if (!$table_exists) {
                error_log('[AEBG Networks_Manager] Table still does not exist after wait');
                return new \WP_Error('table_creation_failed', 'Failed to create networks table. Please try again.');
            }
        }
        
        // Get networks from Datafeedr API
        $datafeedr = new \AEBG\Core\Datafeedr();
        $api_networks = $datafeedr->get_networks();
        
        if (is_wp_error($api_networks)) {
            $error_msg = $api_networks->get_error_message();
            error_log('[AEBG Networks_Manager] Error fetching networks from API: ' . $error_msg);
            return $api_networks;
        }
        
        if (empty($api_networks) || !is_array($api_networks)) {
            error_log('[AEBG Networks_Manager] No networks returned from API. Response type: ' . gettype($api_networks) . ', Count: ' . (is_array($api_networks) ? count($api_networks) : 'N/A'));
            if (is_array($api_networks) && count($api_networks) === 0) {
                error_log('[AEBG Networks_Manager] API returned empty array');
            }
            return [];
        }
        
        error_log('[AEBG Networks_Manager] Received ' . count($api_networks) . ' networks from API');
        if (count($api_networks) > 0) {
            error_log('[AEBG Networks_Manager] First network structure: ' . json_encode($api_networks[0]));
        }
        
        // Clear existing networks if requested
        if ($clear_existing) {
            $wpdb->query("DELETE FROM $unified_table WHERE network_type = 'api'");
            error_log('[AEBG Networks_Manager] Cleared existing API networks');
        }
        
        $synced_count = 0;
        $skipped_count = 0;
        
        foreach ($api_networks as $api_network) {
            // Extract network information from API response
            // API format might be: ['id' => int, 'name' => string, 'code' => string, ...]
            // Or: ['network_id' => int, 'network_name' => string, 'network_code' => string, ...]
            // Or just: ['name' => string, ...]
            $network_id = $api_network['id'] ?? $api_network['network_id'] ?? null;
            $network_name = $api_network['name'] ?? $api_network['network_name'] ?? $api_network['source'] ?? 'Unknown Network';
            $network_code = $api_network['code'] ?? $api_network['network_code'] ?? null;
            
            // Skip if no name
            if (empty($network_name) || $network_name === 'Unknown Network') {
                continue;
            }
            
            // Use network code as key, or generate from name/ID
            if (!empty($network_code)) {
                $network_key = $network_code;
            } elseif (!empty($network_id)) {
                $network_key = 'api_' . $network_id;
            } else {
                // Generate key from name (sanitize)
                $network_key = 'api_' . sanitize_key(str_replace([' ', '-', '_'], '_', strtolower($network_name)));
            }
            
            // Check if network already exists (by key or by name)
            $exists_by_key = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $unified_table WHERE network_key = %s AND user_id = 1",
                $network_key
            ));
            
            $exists_by_name = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $unified_table WHERE network_name = %s AND user_id = 1",
                $network_name
            ));
            
            $exists = $exists_by_key || $exists_by_name;
            $existing_id = $exists_by_key ?: $exists_by_name;
            
            if ($exists && !$clear_existing) {
                $skipped_count++;
                if ($skipped_count <= 10) {
                    $match_type = $exists_by_key ? 'by key' : 'by name';
                    error_log('[AEBG Networks_Manager] Skipping existing network: ' . $network_key . ' (' . $network_name . ') - matched ' . $match_type);
                }
                continue;
            }
            
            // Extract country information from network name (e.g., "Partner-Ads Denmark")
            $country = null;
            $country_name = null;
            $region = null;
            
            // Try to extract country from network name
            $country_patterns = [
                '/Denmark|DK/i' => ['DK', 'Denmark', 'Europe'],
                '/Sweden|SE/i' => ['SE', 'Sweden', 'Europe'],
                '/Norway|NO/i' => ['NO', 'Norway', 'Europe'],
                '/Finland|FI/i' => ['FI', 'Finland', 'Europe'],
                '/Germany|DE/i' => ['DE', 'Germany', 'Europe'],
                '/Netherlands|NL/i' => ['NL', 'Netherlands', 'Europe'],
                '/Belgium|BE/i' => ['BE', 'Belgium', 'Europe'],
                '/France|FR/i' => ['FR', 'France', 'Europe'],
                '/Italy|IT/i' => ['IT', 'Italy', 'Europe'],
                '/Spain|ES/i' => ['ES', 'Spain', 'Europe'],
                '/United Kingdom|UK|GB/i' => ['UK', 'United Kingdom', 'Europe'],
                '/United States|US|USA/i' => ['US', 'United States', 'North America'],
                '/Canada|CA/i' => ['CA', 'Canada', 'North America'],
                '/Australia|AU/i' => ['AU', 'Australia', 'Oceania'],
            ];
            
            foreach ($country_patterns as $pattern => $info) {
                if (preg_match($pattern, $network_name)) {
                    $country = $info[0];
                    $country_name = $info[1];
                    $region = $info[2];
                    break;
                }
            }
            
            if ($synced_count < 5) {
                error_log('[AEBG Networks_Manager] Processing network: key=' . $network_key . ', name=' . $network_name . ', country=' . ($country ?? 'N/A'));
            }
            
            // Determine if popular (common networks)
            $popular_networks = ['Partner-Ads', 'Awin', 'Amazon', 'eBay', 'CJ', 'ShareASale', 'Impact'];
            $is_popular = false;
            foreach ($popular_networks as $popular) {
                if (stripos($network_name, $popular) !== false) {
                    $is_popular = true;
                    break;
                }
            }
            
            // Determine category
            $category = 'affiliate';
            if (stripos($network_name, 'Amazon') !== false) {
                $category = 'amazon';
            } elseif (stripos($network_name, 'eBay') !== false) {
                $category = 'marketplace';
            }
            
            if ($exists && $clear_existing) {
                // Update existing network
                $result = $wpdb->update(
                    $unified_table,
                    [
                        'network_name' => $network_name,
                        'network_type' => 'api',
                        'region' => $region,
                        'country' => $country,
                        'country_name' => $country_name,
                        'is_popular' => $is_popular ? 1 : 0,
                        'category' => $category,
                        'api_data' => json_encode($api_network),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $existing_id],
                    ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'],
                    ['%d']
                );
                
                if ($result !== false) {
                    $synced_count++;
                    if ($synced_count <= 5) {
                        error_log('[AEBG Networks_Manager] Successfully updated network: ' . $network_key . ' (ID: ' . $existing_id . ')');
                    }
                } else {
                    error_log('[AEBG Networks_Manager] Failed to update network: ' . $network_key . ' (' . $network_name . ') - ' . $wpdb->last_error);
                }
            } else {
                // Insert new network - use INSERT IGNORE to handle potential duplicates gracefully
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $unified_table 
                    (network_key, network_name, network_type, region, country, country_name, is_popular, category, sort_order, is_active, affiliate_id, user_id, api_data, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %d, %d, %s, %d, %s, %s, %s)",
                    $network_key,
                    $network_name,
                    'api',
                    $region,
                    $country,
                    $country_name,
                    $is_popular ? 1 : 0,
                    $category,
                    $synced_count + 1,
                    1,
                    null,
                    1,
                    json_encode($api_network),
                    current_time('mysql'),
                    current_time('mysql')
                ));
                
                if ($result !== false && $result > 0) {
                    $synced_count++;
                    if ($synced_count <= 5) {
                        error_log('[AEBG Networks_Manager] Successfully inserted network: ' . $network_key . ' (' . $network_name . ')');
                    }
                } else {
                    // Check if it was a duplicate key error
                    if ($wpdb->last_error) {
                        if (strpos($wpdb->last_error, 'Duplicate') !== false) {
                            $skipped_count++;
                            if ($skipped_count <= 10) {
                                error_log('[AEBG Networks_Manager] Duplicate key, skipping: ' . $network_key . ' (' . $network_name . ')');
                            }
                        } else {
                            error_log('[AEBG Networks_Manager] Failed to insert network: ' . $network_key . ' (' . $network_name . ') - ' . $wpdb->last_error);
                            error_log('[AEBG Networks_Manager] Last query: ' . $wpdb->last_query);
                        }
                    } else {
                        // INSERT IGNORE returns 0 if duplicate, which is not an error
                        $skipped_count++;
                        if ($skipped_count <= 10) {
                            error_log('[AEBG Networks_Manager] Network already exists (INSERT IGNORE): ' . $network_key . ' (' . $network_name . ')');
                        }
                    }
                }
            }
        }
        
        error_log('[AEBG Networks_Manager] Sync complete: ' . $synced_count . ' networks synced, ' . $skipped_count . ' skipped (already exist), ' . count($api_networks) . ' total from API');
        
        // If all were skipped, log a sample of network keys to help debug
        if ($synced_count === 0 && $skipped_count > 0) {
            $sample_keys = [];
            $count = 0;
            foreach ($api_networks as $api_network) {
                if ($count >= 5) break;
                $network_name = $api_network['name'] ?? $api_network['network_name'] ?? $api_network['source'] ?? 'Unknown';
                $network_code = $api_network['code'] ?? $api_network['network_code'] ?? null;
                $network_id = $api_network['id'] ?? $api_network['network_id'] ?? null;
                $network_key = !empty($network_code) ? $network_code : (!empty($network_id) ? 'api_' . $network_id : 'api_' . sanitize_key(str_replace([' ', '-', '_'], '_', strtolower($network_name))));
                $sample_keys[] = $network_key . ' => ' . $network_name;
                $count++;
            }
            error_log('[AEBG Networks_Manager] Sample network keys from API: ' . implode(', ', $sample_keys));
            
            // Check what's actually in the database
            $db_sample = $wpdb->get_results(
                "SELECT network_key, network_name FROM $unified_table WHERE user_id = 1 LIMIT 5",
                ARRAY_A
            );
            if ($db_sample) {
                $db_keys = [];
                foreach ($db_sample as $row) {
                    $db_keys[] = $row['network_key'] . ' => ' . $row['network_name'];
                }
                error_log('[AEBG Networks_Manager] Sample networks in database: ' . implode(', ', $db_keys));
            }
        }
        
        // Return the count of synced networks, not the API response
        // Invalidate cache after sync
        \AEBG\Core\Network_API_Manager::invalidate_network_cache();
        
        return ['synced' => $synced_count, 'skipped' => $skipped_count, 'total_api' => count($api_networks)];
    }

    /**
     * Store affiliate ID for a network
     */
    public function store_affiliate_id($network_key, $affiliate_id, $user_id = 1) {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$unified_table'") === $unified_table;
        
        if (!$table_exists) {
            error_log("[AEBG Networks_Manager] Unified table doesn't exist, creating it...");
            // Create the table
            $this->maybe_create_unified_table();
            
            // Check again
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$unified_table'") === $unified_table;
            if (!$table_exists) {
                error_log("[AEBG Networks_Manager] Failed to create unified table. Error: " . $wpdb->last_error);
                // Fallback to WordPress options
                $option_key = 'aebg_affiliate_id_' . $network_key;
                update_option($option_key, $affiliate_id);
                return true;
            } else {
                error_log("[AEBG Networks_Manager] Unified table created successfully");
            }
        }
        
        // Check if network exists (by key or by name)
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, network_name FROM $unified_table WHERE (network_key = %s OR network_name = %s) AND user_id = %d",
                $network_key,
                $network_key, // Also check by name in case key doesn't match
                $user_id
            )
        );
        
        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $unified_table,
                [
                    'affiliate_id' => $affiliate_id,
                    'last_used' => current_time('mysql'),
                    'usage_count' => ($wpdb->get_var($wpdb->prepare("SELECT usage_count FROM $unified_table WHERE id = %d", $existing->id)) ?: 0) + 1,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $existing->id],
                ['%s', '%s', '%d', '%s'],
                ['%d']
            );
            
            if ($result === false && $wpdb->last_error) {
                error_log("[AEBG Networks_Manager] Update failed: " . $wpdb->last_error);
                error_log("[AEBG Networks_Manager] Last query: " . $wpdb->last_query);
            }
        } else {
            // Network doesn't exist - try to get network name from Networks_Data or use key as name
            $network_name = $network_key;
            
            // Try to get proper name from Networks_Data
            try {
                $networks_data = new Networks_Data();
                $all_networks = $networks_data->get_all_networks();
                foreach ($all_networks as $network) {
                    if (($network['code'] ?? '') === $network_key) {
                        $network_name = $network['name'] ?? $network_key;
                        break;
                    }
                }
            } catch (Exception $e) {
                error_log("[AEBG Networks_Manager] Error getting network name: " . $e->getMessage());
            }
            
            // Extract country from network name if possible
            $country = null;
            $country_name = null;
            $region = null;
            
            if (stripos($network_name, 'Denmark') !== false || stripos($network_key, 'dk') !== false) {
                $country = 'DK';
                $country_name = 'Denmark';
                $region = 'Europe';
            } elseif (stripos($network_name, 'Sweden') !== false || stripos($network_key, 'se') !== false) {
                $country = 'SE';
                $country_name = 'Sweden';
                $region = 'Europe';
            } elseif (stripos($network_name, 'Norway') !== false || stripos($network_key, 'no') !== false) {
                $country = 'NO';
                $country_name = 'Norway';
                $region = 'Europe';
            }
            
            // Create new record
            $result = $wpdb->insert(
                $unified_table,
                [
                    'network_key' => $network_key,
                    'network_name' => $network_name,
                    'network_type' => 'affiliate',
                    'region' => $region,
                    'country' => $country,
                    'country_name' => $country_name,
                    'affiliate_id' => $affiliate_id,
                    'user_id' => $user_id,
                    'is_active' => 1,
                    'last_used' => current_time('mysql'),
                    'usage_count' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s']
            );
            
            if ($result === false && $wpdb->last_error) {
                error_log("[AEBG Networks_Manager] Insert failed: " . $wpdb->last_error);
                error_log("[AEBG Networks_Manager] Last query: " . $wpdb->last_query);
                error_log("[AEBG Networks_Manager] Network key: " . $network_key . ", Name: " . $network_name);
            }
        }
        
        // Also update WordPress options for backward compatibility
        $option_key = 'aebg_affiliate_id_' . $network_key;
        update_option($option_key, $affiliate_id);
        
        $success = $result !== false;
        if ($success) {
            error_log("[AEBG Networks_Manager] Successfully stored affiliate ID for network: " . $network_key);
        } else {
            error_log("[AEBG Networks_Manager] Failed to store affiliate ID for network: " . $network_key);
        }
        
        return $success;
    }

    /**
     * Get affiliate ID for a network
     * Can look up by network_key or network_name
     */
    public function get_affiliate_id($network_key, $user_id = 1) {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$unified_table'") != $unified_table) {
            // Fallback to WordPress options
            $option_key = 'aebg_affiliate_id_' . $network_key;
            return get_option($option_key, '');
        }
        
        // First try exact match on network_key
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT affiliate_id FROM $unified_table WHERE network_key = %s AND user_id = %d",
                $network_key,
                $user_id
            )
        );
        
        // If not found, try matching by network_name (case-insensitive)
        if (empty($result)) {
            $result = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT affiliate_id FROM $unified_table WHERE LOWER(network_name) = LOWER(%s) AND user_id = %d",
                    $network_key,
                    $user_id
                )
            );
        }
        
        return $result ?: '';
    }

    /**
     * Get all affiliate IDs for a user
     */
    public function get_all_affiliate_ids($user_id = 1) {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$unified_table'") != $unified_table) {
            // Fallback to WordPress options
            return $this->get_affiliate_ids_from_options();
        }
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT network_key, affiliate_id FROM $unified_table WHERE user_id = %d AND affiliate_id IS NOT NULL AND affiliate_id != ''",
                $user_id
            ),
            ARRAY_A
        );
        
        $affiliate_ids = [];
        foreach ($results as $row) {
            $affiliate_ids[$row['network_key']] = $row['affiliate_id'];
        }
        
        return $affiliate_ids;
    }

    /**
     * Get all networks with their configuration status
     */
    public function get_all_networks_with_status($user_id = 1) {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$unified_table'") != $unified_table) {
            // Fallback to Networks_Data
            $networks_data = new Networks_Data();
            return $networks_data->get_all_networks();
        }
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $unified_table WHERE user_id = %d ORDER BY sort_order ASC, network_name ASC",
                $user_id
            ),
            ARRAY_A
        );
        
        // Log any database errors
        if ($wpdb->last_error) {
            error_log('[AEBG Networks_Manager] Database error in get_all_networks_with_status: ' . $wpdb->last_error);
        }
        
        $networks = [];
        foreach ($results as $row) {
            $networks[] = [
                'code' => $row['network_key'] ?? 'unknown',
                'name' => $row['network_name'] ?? 'Unknown Network',
                'type' => $row['network_type'] ?? 'affiliate',
                'region' => $row['region'] ?? null,
                'country' => $row['country'] ?? null,
                'country_name' => $row['country_name'] ?? null,
                'flag' => $row['flag'] ?? null,
                'is_popular' => isset($row['is_popular']) ? (bool)$row['is_popular'] : false,
                'category' => $row['category'] ?? 'affiliate',
                'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : 0,
                'is_active' => isset($row['is_active']) ? (bool)$row['is_active'] : true,
                'configured' => !empty($row['affiliate_id']),
                'affiliate_id' => $row['affiliate_id'] ?? null,
                'last_used' => $row['last_used'] ?? null,
                'usage_count' => isset($row['usage_count']) ? (int)$row['usage_count'] : 0,
                'api_data' => json_decode($row['api_data'] ?? '{}', true)
            ];
        }
        
        return $networks;
    }

    /**
     * Fallback method to get affiliate IDs from WordPress options
     */
    private function get_affiliate_ids_from_options() {
        global $wpdb;
        
        $options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'aebg_affiliate_id_%'"
        );
        
        $affiliate_ids = [];
        foreach ($options as $option) {
            $network_key = str_replace('aebg_affiliate_id_', '', $option->option_name);
            $affiliate_ids[$network_key] = $option->option_value;
        }
        
        return $affiliate_ids;
    }

    /**
     * Get networks by category
     */
    public function get_networks_by_category($category, $user_id = 1) {
        $all_networks = $this->get_all_networks_with_status($user_id);
        
        return array_filter($all_networks, function($network) use ($category) {
            return $network['category'] === $category;
        });
    }

    /**
     * Get popular networks
     */
    public function get_popular_networks($user_id = 1) {
        $all_networks = $this->get_all_networks_with_status($user_id);
        
        return array_filter($all_networks, function($network) {
            return $network['is_popular'];
        });
    }

    /**
     * Search networks
     */
    public function search_networks($search_term, $user_id = 1) {
        $all_networks = $this->get_all_networks_with_status($user_id);
        
        return array_filter($all_networks, function($network) use ($search_term) {
            return stripos($network['name'], $search_term) !== false || 
                   stripos($network['code'], $search_term) !== false;
        });
    }

    /**
     * Get network statistics
     */
    public function get_network_stats($user_id = 1) {
        global $wpdb;
        
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$unified_table'") != $unified_table) {
            return [
                'total' => 0,
                'configured' => 0,
                'categories' => [],
                'countries' => []
            ];
        }
        
        $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $unified_table WHERE user_id = %d", $user_id));
        $configured = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $unified_table WHERE user_id = %d AND affiliate_id IS NOT NULL AND affiliate_id != ''", $user_id));
        
        $categories = $wpdb->get_results($wpdb->prepare("SELECT category, COUNT(*) as count FROM $unified_table WHERE user_id = %d GROUP BY category", $user_id), ARRAY_A);
        $countries = $wpdb->get_results($wpdb->prepare("SELECT country, COUNT(*) as count FROM $unified_table WHERE user_id = %d GROUP BY country", $user_id), ARRAY_A);
        
        return [
            'total' => (int)$total,
            'configured' => (int)$configured,
            'categories' => $categories,
            'countries' => $countries
        ];
    }
    
    /**
     * AJAX handler to sync networks from Datafeedr API
     */
    public function ajax_sync_networks_from_api() {
        // Log that the handler was called
        error_log('[AEBG Networks_Manager] AJAX handler called');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            error_log('[AEBG Networks_Manager] Permission check failed');
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce'])) {
            error_log('[AEBG Networks_Manager] Nonce not provided in POST data');
            wp_send_json_error(['message' => 'Nonce not provided']);
            return;
        }
        
        $nonce_verified = wp_verify_nonce($_POST['nonce'], 'aebg_sync_networks');
        if (!$nonce_verified) {
            error_log('[AEBG Networks_Manager] Nonce verification failed. Provided: ' . $_POST['nonce']);
            wp_send_json_error(['message' => 'Invalid nonce. Please refresh the page and try again.']);
            return;
        }
        
        error_log('[AEBG Networks_Manager] Nonce verified, proceeding with sync');
        
        $clear_existing = isset($_POST['clear_existing']) && $_POST['clear_existing'] === 'true';
        
        // Check Datafeedr configuration first
        $options = get_option('aebg_settings', []);
        $access_id = $options['datafeedr_access_id'] ?? '';
        $access_key = $options['datafeedr_secret_key'] ?? '';
        $enabled = isset($options['enable_datafeedr']) ? (bool)$options['enable_datafeedr'] : false;
        
        if (!$enabled || empty($access_id) || empty($access_key)) {
            wp_send_json_error([
                'message' => 'Datafeedr is not configured. Please configure your Datafeedr Access ID and Access Key in Settings first.'
            ]);
            return;
        }
        
        // Sync networks from API
        $result = $this->sync_networks_from_api($clear_existing);
        
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => 'Error syncing networks: ' . $result->get_error_message()
            ]);
            return;
        }
        
        // Result is now an array with sync stats
        if (!is_array($result) || !isset($result['synced'])) {
            wp_send_json_error([
                'message' => 'Unexpected response from sync. Please check error logs for details.'
            ]);
            return;
        }
        
        $synced_count = (int)($result['synced'] ?? 0);
        $skipped_count = (int)($result['skipped'] ?? 0);
        $total_api = (int)($result['total_api'] ?? 0);
        
        // Get updated network count
        global $wpdb;
        $unified_table = $wpdb->prefix . 'aebg_networks_unified';
        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $unified_table WHERE user_id = 1");
        
        if ($synced_count === 0 && $skipped_count > 0) {
            // All networks already exist - this is actually success, but inform the user
            wp_send_json_success([
                'message' => sprintf('All %d networks from API already exist in database. Total networks: %d. If you expected more networks, they may be using different keys/names. Check error logs for details.', $skipped_count, $total_count),
                'total_networks' => (int)$total_count,
                'synced_count' => $synced_count,
                'skipped_count' => $skipped_count,
                'note' => 'All networks from API were skipped because they already exist. Check error logs to see which network keys matched.'
            ]);
        } elseif ($synced_count === 0 && $skipped_count === 0) {
            // No networks were processed at all - this is an error
            wp_send_json_error([
                'message' => sprintf('No networks were processed. API returned %d networks, but none were added or skipped. This may indicate a problem with network key generation or database insertion. Check error logs for details.', $total_api)
            ]);
        } elseif ($synced_count === 0) {
            wp_send_json_error([
                'message' => sprintf('No networks were synced. API returned %d networks, but none were added. Check error logs for details.', $total_api)
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf('Successfully synced %d new networks from API (%d skipped, already exist). Total networks in database: %d', $synced_count, $skipped_count, $total_count),
                'total_networks' => (int)$total_count,
                'synced_count' => $synced_count,
                'skipped_count' => $skipped_count
            ]);
        }
    }
} 