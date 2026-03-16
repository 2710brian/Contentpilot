<?php
/**
 * PHPUnit Bootstrap File
 * 
 * Sets up WordPress environment for testing
 */

// Load WordPress
if (!defined('ABSPATH')) {
    // Try to find WordPress
    $wp_load = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        // For CI/CD environments
        require_once getenv('WP_TESTS_DIR') . '/includes/bootstrap.php';
    }
}

// Load plugin
require_once dirname(__FILE__) . '/../ai-bulk-generator-for-elementor.php';

