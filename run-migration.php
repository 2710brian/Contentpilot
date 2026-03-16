<?php
/**
 * Simple CLI script to run AEBG Network Database Migration
 * 
 * Usage:
 * php run-migration.php [--dry-run] [--rollback]
 * 
 * Options:
 *   --dry-run    Run migration without making changes (default)
 *   --rollback   Rollback the migration
 */

// Load WordPress
require_once 'wp-config.php';

// Include the migration class
require_once 'database-migration.php';

// Parse command line arguments
$dry_run = in_array('--dry-run', $argv) || !in_array('--live', $argv);
$rollback = in_array('--rollback', $argv);

echo "AEBG Network Database Migration CLI\n";
echo "===================================\n\n";

if ($dry_run) {
    echo "Running in DRY RUN mode - no changes will be made\n";
} else {
    echo "Running in LIVE mode - changes WILL be made to database\n";
}

if ($rollback) {
    echo "ROLLBACK mode - will remove enhanced tables\n";
}

echo "\n";

// Create migration instance
$migration = new AEBG_Database_Migration($dry_run);

if ($rollback) {
    $migration->rollback();
    echo "\nRollback completed!\n";
} else {
    // Run migration
    $success = $migration->run_migration();
    
    if ($success) {
        echo "\n✅ Migration completed successfully!\n";
        
        if ($dry_run) {
            echo "\nTo apply the changes, run:\n";
            echo "php run-migration.php --live\n";
        }
    } else {
        echo "\n❌ Migration failed!\n";
        echo "\nErrors:\n";
        foreach ($migration->get_errors() as $error) {
            echo "  - {$error}\n";
        }
        exit(1);
    }
}

echo "\nMigration log:\n";
echo "==============\n";
foreach ($migration->get_migration_log() as $log_entry) {
    echo $log_entry . "\n";
}

echo "\nDone!\n"; 