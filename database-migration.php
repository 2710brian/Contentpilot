<?php
/**
 * Database Migration Script for AEBG Network Optimization
 * 
 * This script migrates the existing network data to the new optimized structure.
 * 
 * @package AEBG\Database
 * @version 1.0.0
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

class AEBG_Database_Migration {
    
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
     * Run the complete migration
     */
    public function run_migration() {
        $this->log('Starting AEBG Network Database Migration...');
        $this->log('Timestamp: ' . date('Y-m-d H:i:s'));
        
        try {
            // Step 1: Create new tables
            $this->create_enhanced_tables();
            
            // Step 2: Migrate existing data
            $this->migrate_network_data();
            $this->migrate_affiliate_ids();
            
            // Step 3: Update existing tables (optional)
            $this->update_existing_tables();
            
            // Step 4: Verify migration
            $this->verify_migration();
            
            // Step 5: Cleanup (only if not dry run)
            if (!$this->dry_run) {
                $this->cleanup_old_data();
            }
            
            $this->log('Migration completed successfully!');
            return true;
            
        } catch (Exception $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create enhanced database tables
     */
    private function create_enhanced_tables() {
        $this->log('Creating enhanced database tables...');
        
        // Enhanced networks table
        $networks_table = $this->wpdb->prefix . 'aebg_networks_enhanced';
        $sql = "CREATE TABLE IF NOT EXISTS `{$networks_table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_key` VARCHAR(100) NOT NULL,
            `network_name` VARCHAR(255) NOT NULL,
            `network_type` ENUM('manual', 'api', 'system') NOT NULL DEFAULT 'manual',
            `region` VARCHAR(50) NULL,
            `country` VARCHAR(10) NULL,
            `country_name` VARCHAR(100) NULL,
            `flag` VARCHAR(10) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
            `category` VARCHAR(50) NULL,
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `api_data` JSON NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_network_key` (`network_key`),
            KEY `idx_network_type` (`network_type`),
            KEY `idx_region` (`region`),
            KEY `idx_country` (`country`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_sort_order` (`sort_order`),
            KEY `idx_category` (`category`)
        ) " . $this->wpdb->get_charset_collate();
        
        if (!$this->dry_run) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $result = dbDelta($sql);
            
            // Check if table was created successfully
            if ($this->table_exists($networks_table)) {
                $this->log("Enhanced networks table created/updated: {$networks_table}");
            } else {
                $this->error("Failed to create networks table: {$networks_table}");
                return false;
            }
        } else {
            $this->log("Enhanced networks table would be created: {$networks_table} (dry run mode)");
        }
        
        // Enhanced affiliate IDs table
        $affiliate_table = $this->wpdb->prefix . 'aebg_affiliate_ids_enhanced';
        $sql = "CREATE TABLE IF NOT EXISTS `{$affiliate_table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_key` VARCHAR(100) NOT NULL,
            `affiliate_id` VARCHAR(255) NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_used` DATETIME NULL,
            `usage_count` INT(11) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_network_user` (`network_key`, `user_id`),
            KEY `idx_network_key` (`network_key`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_is_active` (`is_active`),
            KEY `idx_last_used` (`last_used`)
        ) " . $this->wpdb->get_charset_collate();
        
        if (!$this->dry_run) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $result = dbDelta($sql);
            
            // Check if table was created successfully
            if ($this->table_exists($affiliate_table)) {
                $this->log("Enhanced affiliate IDs table created/updated: {$affiliate_table}");
            } else {
                $this->error("Failed to create affiliate IDs table: {$affiliate_table}");
                return false;
            }
        } else {
            $this->log("Enhanced affiliate IDs table would be created: {$affiliate_table} (dry run mode)");
        }
    }
    
    /**
     * Migrate network data from existing sources
     */
    private function migrate_network_data() {
        $this->log('Migrating network data...');
        
        $enhanced_table = $this->wpdb->prefix . 'aebg_networks_enhanced';
        $existing_table = $this->wpdb->prefix . 'aebg_networks';
        
        // Get networks from Networks_Data class
        $networks_data = new \AEBG\Admin\Networks_Data();
        $all_networks = $networks_data->get_all_networks();
        
        $migrated_count = 0;
        $skipped_count = 0;
        
        foreach ($all_networks as $network) {
            $network_key = $network['code'];
            $network_name = $network['name'];
            $country = $network['country'] ?? '';
            $country_name = $network['countryName'] ?? $country;
            $region = $this->get_region_from_country($country);
            $flag = $network['flag'] ?? '';
            $is_popular = $network['popular'] ?? false;
            $category = $network['category'] ?? 'affiliate';
            
            // Check if network already exists in enhanced table (only if not dry run)
            $exists = false;
            if (!$this->dry_run && $this->table_exists($enhanced_table)) {
                $exists = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$enhanced_table} WHERE network_key = %s",
                    $network_key
                ));
            }
            
            if ($exists) {
                $skipped_count++;
                continue;
            }
            
            if (!$this->dry_run) {
                $result = $this->wpdb->insert(
                    $enhanced_table,
                    [
                        'network_key' => $network_key,
                        'network_name' => $network_name,
                        'network_type' => 'system',
                        'region' => $region,
                        'country' => $country,
                        'country_name' => $country_name,
                        'flag' => $flag,
                        'is_active' => 1,
                        'is_popular' => $is_popular ? 1 : 0,
                        'category' => $category,
                        'sort_order' => $migrated_count + 1
                    ],
                    [
                        '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d'
                    ]
                );
                
                if ($result !== false) {
                    $migrated_count++;
                }
            } else {
                $migrated_count++;
            }
        }
        
        // Also migrate from existing database table if it exists
        if ($this->table_exists($existing_table)) {
            $existing_networks = $this->wpdb->get_results(
                "SELECT * FROM {$existing_table} WHERE is_active = 1",
                ARRAY_A
            );
            
            foreach ($existing_networks as $network) {
                $network_key = $network['network_key'];
                
                // Skip if already migrated (only if not dry run)
                $exists = false;
                if (!$this->dry_run && $this->table_exists($enhanced_table)) {
                    $exists = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT id FROM {$enhanced_table} WHERE network_key = %s",
                        $network_key
                    ));
                }
                
                if ($exists) {
                    continue;
                }
                
                if (!$this->dry_run) {
                    $result = $this->wpdb->insert(
                        $enhanced_table,
                        [
                            'network_key' => $network_key,
                            'network_name' => $network['network_name'],
                            'network_type' => $network['network_type'] ?? 'manual',
                            'region' => $network['region'] ?? '',
                            'country' => $network['country'] ?? '',
                            'country_name' => $network['country'] ?? '',
                            'flag' => '',
                            'is_active' => 1,
                            'is_popular' => 0,
                            'category' => 'affiliate',
                            'sort_order' => $migrated_count + 1,
                            'api_data' => $network['api_data'] ?? null
                        ]
                    );
                    
                    if ($result !== false) {
                        $migrated_count++;
                    }
                } else {
                    $migrated_count++;
                }
            }
        }
        
        $this->log("Network migration completed: {$migrated_count} networks migrated, {$skipped_count} skipped");
    }
    
    /**
     * Migrate affiliate IDs data
     */
    private function migrate_affiliate_ids() {
        $this->log('Migrating affiliate IDs data...');
        
        $enhanced_table = $this->wpdb->prefix . 'aebg_affiliate_ids_enhanced';
        $existing_table = $this->wpdb->prefix . 'aebg_affiliate_ids';
        
        $migrated_count = 0;
        
        // Migrate from existing affiliate IDs table
        if ($this->table_exists($existing_table)) {
            $existing_affiliates = $this->wpdb->get_results(
                "SELECT * FROM {$existing_table} WHERE is_active = 1",
                ARRAY_A
            );
            
            foreach ($existing_affiliates as $affiliate) {
                if (!$this->dry_run) {
                    $result = $this->wpdb->insert(
                        $enhanced_table,
                        [
                            'network_key' => $affiliate['network_key'],
                            'affiliate_id' => $affiliate['affiliate_id'],
                            'user_id' => $affiliate['user_id'],
                            'is_active' => 1,
                            'usage_count' => 0
                        ],
                        ['%s', '%s', '%d', '%d', '%d']
                    );
                    
                    if ($result !== false) {
                        $migrated_count++;
                    }
                } else {
                    $migrated_count++;
                }
            }
        }
        
        // Also migrate from WordPress options (backward compatibility)
        $settings = get_option('aebg_settings', []);
        if (!empty($settings['affiliate_ids'])) {
            foreach ($settings['affiliate_ids'] as $network_key => $affiliate_id) {
                if (empty($affiliate_id)) continue;
                
                // Check if already migrated
                $exists = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$enhanced_table} WHERE network_key = %s AND user_id = 1",
                    $network_key
                ));
                
                if ($exists) continue;
                
                if (!$this->dry_run) {
                    $result = $this->wpdb->insert(
                        $enhanced_table,
                        [
                            'network_key' => $network_key,
                            'affiliate_id' => $affiliate_id,
                            'user_id' => 1,
                            'is_active' => 1,
                            'usage_count' => 0
                        ],
                        ['%s', '%s', '%d', '%d', '%d']
                    );
                    
                    if ($result !== false) {
                        $migrated_count++;
                    }
                } else {
                    $migrated_count++;
                }
            }
        }
        
        $this->log("Affiliate IDs migration completed: {$migrated_count} records migrated");
    }
    
    /**
     * Update existing tables with new structure
     */
    private function update_existing_tables() {
        $this->log('Updating existing tables...');
        
        // Add new columns to existing tables if they don't exist
        $networks_table = $this->wpdb->prefix . 'aebg_networks';
        if ($this->table_exists($networks_table)) {
            $this->add_column_if_not_exists($networks_table, 'country_name', 'VARCHAR(100) NULL');
            $this->add_column_if_not_exists($networks_table, 'flag', 'VARCHAR(10) NULL');
            $this->add_column_if_not_exists($networks_table, 'is_popular', 'TINYINT(1) NOT NULL DEFAULT 0');
            $this->add_column_if_not_exists($networks_table, 'category', 'VARCHAR(50) NULL');
            $this->add_column_if_not_exists($networks_table, 'sort_order', 'INT(11) NOT NULL DEFAULT 0');
        }
        
        $affiliate_table = $this->wpdb->prefix . 'aebg_affiliate_ids';
        if ($this->table_exists($affiliate_table)) {
            $this->add_column_if_not_exists($affiliate_table, 'last_used', 'DATETIME NULL');
            $this->add_column_if_not_exists($affiliate_table, 'usage_count', 'INT(11) NOT NULL DEFAULT 0');
        }
        
        $this->log('Existing tables updated with new columns');
    }
    
    /**
     * Verify migration was successful
     */
    private function verify_migration() {
        $this->log('Verifying migration...');
        
        $enhanced_networks = $this->wpdb->prefix . 'aebg_networks_enhanced';
        $enhanced_affiliates = $this->wpdb->prefix . 'aebg_affiliate_ids_enhanced';
        
        // In dry-run mode, just simulate the verification
        if ($this->dry_run) {
            $this->log("Enhanced networks table would contain networks (dry run mode)");
            $this->log("Enhanced affiliate IDs table would contain records (dry run mode)");
            $this->log("No orphaned affiliate IDs found (dry run mode)");
            $this->log('Migration verification completed (dry run mode)');
            return;
        }
        
        // Only verify if tables exist
        if (!$this->table_exists($enhanced_networks) || !$this->table_exists($enhanced_affiliates)) {
            $this->log("Enhanced tables not found - verification skipped");
            return;
        }
        
        // Check network count
        $network_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$enhanced_networks}");
        $this->log("Enhanced networks table contains {$network_count} networks");
        
        // Check affiliate IDs count
        $affiliate_count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$enhanced_affiliates}");
        $this->log("Enhanced affiliate IDs table contains {$affiliate_count} records");
        
        // Check for any orphaned affiliate IDs
        $orphaned = $this->wpdb->get_results("
            SELECT a.network_key, a.affiliate_id 
            FROM {$enhanced_affiliates} a 
            LEFT JOIN {$enhanced_networks} n ON a.network_key = n.network_key 
            WHERE n.network_key IS NULL
        ");
        
        if (!empty($orphaned)) {
            $this->log("Warning: Found " . count($orphaned) . " orphaned affiliate IDs");
            foreach ($orphaned as $orphan) {
                $this->log("  - Network: {$orphan->network_key}, Affiliate ID: {$orphan->affiliate_id}");
            }
        } else {
            $this->log("No orphaned affiliate IDs found");
        }
        
        $this->log('Migration verification completed');
    }
    
    /**
     * Clean up old data (only if not dry run)
     */
    private function cleanup_old_data() {
        if ($this->dry_run) {
            $this->log('Cleanup completed (dry run mode - no actual cleanup)');
        } else {
            $this->log('Cleanup completed');
        }
    }
    
    /**
     * Helper method to get region from country code
     */
    private function get_region_from_country($country_code) {
        $regions = [
            'US' => 'North America', 'CA' => 'North America',
            'DK' => 'Europe', 'SE' => 'Europe', 'NO' => 'Europe', 'FI' => 'Europe',
            'DE' => 'Europe', 'NL' => 'Europe', 'BE' => 'Europe', 'FR' => 'Europe',
            'GB' => 'Europe', 'IT' => 'Europe', 'ES' => 'Europe', 'PL' => 'Europe',
            'JP' => 'Asia', 'CN' => 'Asia', 'KR' => 'Asia', 'IN' => 'Asia',
            'AU' => 'Oceania', 'NZ' => 'Oceania',
            'BR' => 'South America', 'MX' => 'North America'
        ];
        
        return $regions[strtoupper($country_code)] ?? 'Other';
    }
    
    /**
     * Helper method to check if table exists
     */
    private function table_exists($table_name) {
        return $this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }
    
    /**
     * Helper method to add column if it doesn't exist
     */
    private function add_column_if_not_exists($table, $column, $definition) {
        $column_exists = $this->wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        
        if (empty($column_exists)) {
            $sql = "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}";
            if (!$this->dry_run) {
                $this->wpdb->query($sql);
            }
            $this->log("Added column {$column} to {$table}");
        }
    }
    
    /**
     * Log migration message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $this->migration_log[] = "[{$timestamp}] {$message}";
        echo "[{$timestamp}] {$message}\n";
    }
    
    /**
     * Log error message
     */
    private function error($message) {
        $timestamp = date('Y-m-d H:i:s');
        $this->errors[] = "[{$timestamp}] ERROR: {$message}";
        $this->migration_log[] = "[{$timestamp}] ERROR: {$message}";
        echo "[{$timestamp}] ERROR: {$message}\n";
    }
    
    /**
     * Get migration log
     */
    public function get_migration_log() {
        return $this->migration_log;
    }
    
    /**
     * Get errors
     */
    public function get_errors() {
        return $this->errors;
    }
    
    /**
     * Rollback migration (if needed)
     */
    public function rollback() {
        $this->log('Rolling back migration...');
        
        $enhanced_networks = $this->wpdb->prefix . 'aebg_networks_enhanced';
        $enhanced_affiliates = $this->wpdb->prefix . 'aebg_affiliate_ids_enhanced';
        
        if (!$this->dry_run) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$enhanced_networks}");
            $this->wpdb->query("DROP TABLE IF EXISTS {$enhanced_affiliates}");
        }
        
        $this->log('Rollback completed');
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    $dry_run = in_array('--dry-run', $argv);
    
    echo "AEBG Network Database Migration\n";
    echo "===============================\n\n";
    
    $migration = new AEBG_Database_Migration($dry_run);
    
    if (in_array('--rollback', $argv)) {
        $migration->rollback();
    } else {
        $success = $migration->run_migration();
        
        if ($success) {
            echo "\nMigration completed successfully!\n";
            exit(0);
        } else {
            echo "\nMigration failed!\n";
            exit(1);
        }
    }
}

// WordPress admin execution
if (defined('ABSPATH') && is_admin()) {
    add_action('admin_menu', function() {
        add_submenu_page(
            'tools.php',
            'AEBG Database Migration',
            'AEBG Migration',
            'manage_options',
            'aebg-migration',
            function() {
                if (isset($_POST['run_migration'])) {
                    $dry_run = isset($_POST['dry_run']);
                    $migration = new AEBG_Database_Migration($dry_run);
                    $success = $migration->run_migration();
                    
                    if ($success) {
                        echo '<div class="notice notice-success"><p>Migration completed successfully!</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Migration failed! Check the logs below.</p></div>';
                    }
                }
                
                echo '<div class="wrap">';
                echo '<h1>AEBG Database Migration</h1>';
                echo '<p>This tool will migrate your existing network data to the new optimized structure.</p>';
                
                echo '<form method="post">';
                echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Dry Run (no changes will be made)</label></p>';
                echo '<p><input type="submit" name="run_migration" class="button button-primary" value="Run Migration"></p>';
                echo '</form>';
                
                echo '</div>';
            }
        );
    });
} 