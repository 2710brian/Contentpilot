<?php
/**
 * Simple activation script for AEBG Migration Tool
 * 
 * This script ensures the migration tool is properly loaded and accessible.
 * Run this once to activate the migration tool in WordPress admin.
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

// Add migration tool to admin menu
function aebg_add_migration_tool() {
    add_submenu_page(
        'tools.php',
        'AEBG Database Migration',
        'AEBG Migration',
        'manage_options',
        'aebg-migration',
        'aebg_migration_page'
    );
}

// Migration page callback
function aebg_migration_page() {
    // Handle migration actions
    if (isset($_POST['run_migration'])) {
        $dry_run = isset($_POST['dry_run']);
        require_once __DIR__ . '/database-migration.php';
        $migration = new AEBG_Database_Migration($dry_run);
        $success = $migration->run_migration();
        
        if ($success) {
            echo '<div class="notice notice-success"><p>✅ Migration completed successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Migration failed! Check the logs below.</p></div>';
        }
    }
    
    // Handle simplification actions
    if (isset($_POST['run_simplification'])) {
        $dry_run = isset($_POST['dry_run_simplify']);
        require_once __DIR__ . '/simplify-database-structure.php';
        $simplification = new AEBG_Database_Simplification($dry_run);
        $success = $simplification->run_simplification();
        
        if ($success) {
            echo '<div class="notice notice-success"><p>✅ Database simplification completed successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Database simplification failed! Check the logs below.</p></div>';
        }
    }
    
    echo '<div class="wrap">';
    echo '<h1>🚀 AEBG Database Tools</h1>';
    
    // Migration Section
    echo '<div style="margin-bottom: 30px; padding: 20px; background: #f0f0f1; border-radius: 5px;">';
    echo '<h2>📊 Database Migration (Legacy)</h2>';
    echo '<p>This tool will migrate your existing network data to the enhanced structure.</p>';
    echo '<div class="notice notice-warning"><p><strong>⚠️ IMPORTANT: Always backup your database before running migration!</strong></p></div>';
    
    echo '<form method="post">';
    echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> 🔍 Dry Run (no changes will be made)</label></p>';
    echo '<p><input type="submit" name="run_migration" class="button button-primary" value="🚀 Run Migration"></p>';
    echo '</form>';
    
    echo '<div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">';
    echo '<h4>💡 What This Migration Does</h4>';
    echo '<ul>';
    echo '<li>Creates enhanced database tables for better performance</li>';
    echo '<li>Migrates all existing network data</li>';
    echo '<li>Preserves all affiliate ID configurations</li>';
    echo '<li>Improves database structure and indexing</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    
    // Simplification Section
    echo '<div style="margin-bottom: 30px; padding: 20px; background: #e8f5e8; border-radius: 5px;">';
    echo '<h2>🔄 Database Simplification (Recommended)</h2>';
    echo '<p><strong>NEW!</strong> This tool will consolidate all network data into one simple, unified table.</p>';
    echo '<div class="notice notice-info"><p><strong>💡 RECOMMENDED:</strong> This approach is much simpler and easier to maintain!</strong></p></div>';
    
    echo '<form method="post">';
    echo '<p><label><input type="checkbox" name="dry_run_simplify" value="1" checked> 🔍 Dry Run (no changes will be made)</label></p>';
    echo '<p><input type="submit" name="run_simplification" class="button button-primary" style="background: #28a745; border-color: #28a745;" value="🔄 Run Simplification"></p>';
    echo '</form>';
    
    echo '<div style="margin-top: 15px; padding: 10px; background: #d1ecf1; border-left: 4px solid #17a2b8;">';
    echo '<h4>💡 What This Simplification Does</h4>';
    echo '<ul>';
    echo '<li><strong>🎯 ONE TABLE:</strong> Consolidates networks + affiliate IDs into single table</li>';
    echo '<li><strong>🚀 SIMPLER:</strong> No more complex dual-table system</li>';
    echo '<li><strong>🔧 EASIER:</strong> Simpler queries and maintenance</li>';
    echo '<li><strong>📈 BETTER:</strong> Improved performance and reliability</li>';
    echo '<li><strong>🔄 MIGRATES:</strong> Automatically moves all existing data</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
}

// Hook into WordPress admin
add_action('admin_menu', 'aebg_add_migration_tool');

// CLI execution
if (php_sapi_name() === 'cli') {
    echo "AEBG Migration Tool activated!\n";
    echo "You can now access it in WordPress admin under Tools → AEBG Migration\n";
}

// WordPress admin execution - only output when actually needed
if (defined('ABSPATH') && is_admin() && isset($_GET['page']) && $_GET['page'] === 'aebg-migration') {
    // Only output when on the actual migration page, not during AJAX requests
    if (!wp_doing_ajax()) {
        echo "<!-- AEBG Migration Tool loaded successfully -->\n";
    }
} 