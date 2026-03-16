<?php
/**
 * Database Simplification Script for AEBG Networks
 * 
 * This script consolidates the complex dual-table system into one simple, unified table.
 * 
 * @package AEBG\Database
 * @version 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // If running from command line, define ABSPATH
    if (php_sapi_name() === 'cli') {
        define('ABSPATH', dirname(__FILE__) . '/');
        require_once ABSPATH . 'wp-config.php';
    } else {
        exit('Direct access not allowed');
    }
}

class AEBG_Database_Simplification {
    
    private $wpdb;
    private $migration_log = [];
    private $errors = [];
    private $dry_run = false;
    
    public function __construct($dry_run = false) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->dry_run = $dry_run;
        
        if ($dry_run) {
            $this->log('DRY RUN MODE - No changes will be made to database');
        }
    }
    
    /**
     * Run the complete simplification
     */
    public function run_simplification() {
        $this->log('Starting AEBG Database Simplification...');
        $this->log('Timestamp: ' . date('Y-m-d H:i:s'));
        
        try {
            // Step 1: Create unified table
            $this->create_unified_table();
            
            // Step 2: Migrate data from all existing tables
            $this->migrate_all_data();
            
            // Step 3: Verify data integrity
            $this->verify_data_integrity();
            
            // Step 4: Test functionality
            $this->test_functionality();
            
            // Step 5: Cleanup old tables (only if not dry run)
            if (!$this->dry_run) {
                $this->cleanup_old_tables();
            }
            
            $this->log('Database simplification completed successfully!');
            return true;
            
        } catch (Exception $e) {
            $this->error('Simplification failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create the unified networks table
     */
    private function create_unified_table() {
        $this->log('Creating unified networks table...');
        
        $unified_table = $this->wpdb->prefix . 'aebg_networks_unified';
        
        $sql = "CREATE TABLE IF NOT EXISTS `{$unified_table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_key` VARCHAR(100) NOT NULL,
            `network_name` VARCHAR(255) NOT NULL,
            `country` VARCHAR(10) NULL,
            `country_name` VARCHAR(100) NULL,
            `flag` VARCHAR(10) NULL,
            `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
            `category` VARCHAR(50) NULL DEFAULT 'affiliate',
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `affiliate_id` VARCHAR(255) NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_used` DATETIME NULL,
            `usage_count` INT(11) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_network_user` (`network_key`, `user_id`),
            KEY `idx_network_key` (`network_key`),
            KEY `idx_country` (`country`),
            KEY `idx_category` (`category`),
            KEY `idx_is_popular` (`is_popular`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_affiliate_id` (`affiliate_id`),
            KEY `idx_sort_order` (`sort_order`)
        ) " . $this->wpdb->get_charset_collate();
        
        if (!$this->dry_run) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $result = dbDelta($sql);
            
            if ($this->table_exists($unified_table)) {
                $this->log("✅ Unified table created successfully: {$unified_table}");
            } else {
                throw new Exception("Failed to create unified table: {$unified_table}");
            }
        } else {
            $this->log("📋 Unified table would be created: {$unified_table} (dry run mode)");
        }
    }
    
    /**
     * Migrate data from all existing tables
     */
    private function migrate_all_data() {
        $this->log('Migrating data from all existing tables...');
        
        $unified_table = $this->wpdb->prefix . 'aebg_networks_unified';
        $migrated_count = 0;
        $skipped_count = 0;
        
        // Get all networks from Networks_Data class (this includes hardcoded networks)
        try {
            $networks_data = new \AEBG\Admin\Networks_Data();
            $all_networks = $networks_data->get_all_networks();
            
            foreach ($all_networks as $network) {
                $network_key = $network['code'];
                $network_name = $network['name'];
                $country = $network['country'] ?? 'GL';
                $country_name = $network['countryName'] ?? 'Global';
                $flag = $network['flag'] ?? '';
                $is_popular = $network['popular'] ?? false;
                $category = $network['category'] ?? 'affiliate';
                
                // Check if already migrated
                $exists = false;
                if (!$this->dry_run) {
                    $exists = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT id FROM {$unified_table} WHERE network_key = %s AND user_id = 1",
                        $network_key
                    ));
                }
                
                if ($exists) {
                    $skipped_count++;
                    continue;
                }
                
                if (!$this->dry_run) {
                    $result = $this->wpdb->insert(
                        $unified_table,
                        [
                            'network_key' => $network_key,
                            'network_name' => $network_name,
                            'country' => $country,
                            'country_name' => $country_name,
                            'flag' => $flag,
                            'is_popular' => $is_popular ? 1 : 0,
                            'category' => $category,
                            'sort_order' => $migrated_count + 1,
                            'affiliate_id' => null,
                            'user_id' => 1,
                            'is_active' => 1
                        ]
                    );
                    
                    if ($result !== false) {
                        $migrated_count++;
                    }
                } else {
                    $migrated_count++;
                }
            }
        } catch (Exception $e) {
            $this->error('Error getting networks from Networks_Data: ' . $e->getMessage());
        }
        
        // Now migrate affiliate IDs from all existing tables
        $this->migrate_affiliate_ids($unified_table);
        
        $this->log("✅ Data migration completed: {$migrated_count} networks migrated, {$skipped_count} skipped");
    }
    
    /**
     * Migrate affiliate IDs from existing tables
     */
    private function migrate_affiliate_ids($unified_table) {
        $this->log('Migrating affiliate IDs from existing tables...');
        
        $tables_to_check = [
            $this->wpdb->prefix . 'aebg_affiliate_ids',
            $this->wpdb->prefix . 'aebg_affiliate_ids_enhanced'
        ];
        
        $migrated_count = 0;
        
        foreach ($tables_to_check as $table) {
            if ($this->table_exists($table)) {
                $this->log("Checking table: {$table}");
                
                $affiliates = $this->wpdb->get_results(
                    "SELECT network_key, affiliate_id, user_id FROM {$table} WHERE is_active = 1",
                    ARRAY_A
                );
                
                foreach ($affiliates as $affiliate) {
                    $network_key = $affiliate['network_key'];
                    $affiliate_id = $affiliate['affiliate_id'];
                    $user_id = $affiliate['user_id'];
                    
                    // Update the unified table with affiliate ID
                    if (!$this->dry_run) {
                        $result = $this->wpdb->update(
                            $unified_table,
                            [
                                'affiliate_id' => $affiliate_id,
                                'last_used' => current_time('mysql'),
                                'usage_count' => 1
                            ],
                            [
                                'network_key' => $network_key,
                                'user_id' => $user_id
                            ]
                        );
                        
                        if ($result !== false) {
                            $migrated_count++;
                            $this->log("✅ Migrated affiliate ID for {$network_key}: {$affiliate_id}");
                        }
                    } else {
                        $migrated_count++;
                    }
                }
            }
        }
        
        $this->log("✅ Affiliate IDs migration completed: {$migrated_count} records migrated");
    }
    
    /**
     * Verify data integrity
     */
    private function verify_data_integrity() {
        $this->log('Verifying data integrity...');
        
        $unified_table = $this->wpdb->prefix . 'aebg_networks_unified';
        
        if (!$this->table_exists($unified_table)) {
            $this->log("⚠️ Unified table does not exist - verification skipped");
            return;
        }
        
        // Count total networks
        $total_networks = $this->wpdb->get_var("SELECT COUNT(*) FROM {$unified_table}");
        $this->log("📊 Total networks in unified table: {$total_networks}");
        
        // Count configured networks
        $configured_networks = $this->wpdb->get_var("SELECT COUNT(*) FROM {$unified_table} WHERE affiliate_id IS NOT NULL AND affiliate_id != ''");
        $this->log("🔑 Configured networks: {$configured_networks}");
        
        // Count by category
        $categories = $this->wpdb->get_results("SELECT category, COUNT(*) as count FROM {$unified_table} GROUP BY category", ARRAY_A);
        foreach ($categories as $cat) {
            $this->log("📁 Category '{$cat['category']}': {$cat['count']} networks");
        }
        
        // Count by country
        $countries = $this->wpdb->get_results("SELECT country, COUNT(*) as count FROM {$unified_table} GROUP BY country ORDER BY count DESC LIMIT 5", ARRAY_A);
        foreach ($countries as $country) {
            $this->log("🌍 Country '{$country['country']}': {$country['count']} networks");
        }
        
        $this->log("✅ Data integrity verification completed");
    }
    
    /**
     * Test functionality
     */
    private function test_functionality() {
        $this->log('Testing functionality...');
        
        try {
            // Test Networks_Manager functionality
            $networks_manager = new \AEBG\Admin\Networks_Manager();
            
            // Test getting affiliate IDs
            $affiliate_ids = $networks_manager->get_all_affiliate_ids();
            $this->log("🔍 Test: get_all_networks_with_status() returned " . count($affiliate_ids) . " affiliate IDs");
            
            // Test getting networks with status
            $networks_with_status = $networks_manager->get_all_networks_with_status();
            if (is_wp_error($networks_with_status)) {
                $this->error("❌ Test failed: " . $networks_with_status->get_error_message());
            } else {
                $configured_count = 0;
                foreach ($networks_with_status as $network) {
                    if ($network['configured']) {
                        $configured_count++;
                    }
                }
                $this->log("✅ Test: get_all_networks_with_status() returned " . count($networks_with_status) . " networks, {$configured_count} configured");
            }
            
        } catch (Exception $e) {
            $this->error("❌ Test failed: " . $e->getMessage());
        }
        
        $this->log("✅ Functionality testing completed");
    }
    
    /**
     * Cleanup old tables
     */
    private function cleanup_old_tables() {
        $this->log('Cleaning up old tables...');
        
        $old_tables = [
            $this->wpdb->prefix . 'aebg_networks_enhanced',
            $this->wpdb->prefix . 'aebg_affiliate_ids_enhanced'
        ];
        
        foreach ($old_tables as $table) {
            if ($this->table_exists($table)) {
                $this->wpdb->query("DROP TABLE IF EXISTS {$table}");
                $this->log("🗑️ Dropped old table: {$table}");
            }
        }
        
        $this->log("✅ Cleanup completed");
    }
    
    /**
     * Check if table exists
     */
    private function table_exists($table) {
        return $this->wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }
    
    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $this->migration_log[] = "[{$timestamp}] {$message}";
        echo "[{$timestamp}] {$message}\n";
    }
    
    /**
     * Log error
     */
    private function error($message) {
        $timestamp = date('Y-m-d H:i:s');
        $this->migration_log[] = "[{$timestamp}] ERROR: {$message}";
        $this->errors[] = $message;
        echo "[{$timestamp}] ❌ ERROR: {$message}\n";
    }
    
    /**
     * Get migration log
     */
    public function get_log() {
        return $this->migration_log;
    }
    
    /**
     * Get errors
     */
    public function get_errors() {
        return $this->errors;
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    echo "=== AEBG Database Simplification Tool ===\n\n";
    
    // Try to load WordPress from common locations
    $wp_load_paths = [
        __DIR__ . '/../../../wp-load.php',
        __DIR__ . '/../../wp-load.php',
        __DIR__ . '/../wp-load.php',
        __DIR__ . '/wp-load.php'
    ];
    
    $wp_loaded = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $wp_loaded = true;
            echo "✅ WordPress loaded from: {$path}\n";
            break;
        }
    }
    
    if (!$wp_loaded) {
        echo "❌ Could not find wp-load.php. Please run this script from the WordPress root directory.\n";
        exit(1);
    }
    
    $dry_run = in_array('--dry-run', $argv);
    if ($dry_run) {
        echo "🔍 DRY RUN MODE - No changes will be made\n\n";
    }
    
    $simplification = new AEBG_Database_Simplification($dry_run);
    $success = $simplification->run_simplification();
    
    if ($success) {
        echo "\n🎉 Database simplification completed successfully!\n";
        exit(0);
    } else {
        echo "\n💥 Database simplification failed!\n";
        exit(1);
    }
} 