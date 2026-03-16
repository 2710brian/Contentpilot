<?php
/**
 * Settings Page Template with Tabs
 * 
 * @package AEBG
 */

// Get current options for the form using the Settings class
$options = \AEBG\Admin\Settings::get_settings();
?>
<div class="aebg-settings-container">
    <div class="aebg-settings-header">
        <div class="aebg-settings-title">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <p class="aebg-settings-subtitle">Configure your AI Content Generator settings with modern, powerful options</p>
        </div>
        <div class="aebg-settings-actions">
            <button type="button" id="aebg-test-api" class="aebg-btn aebg-btn-primary">
                <span class="aebg-icon">🔍</span>
                Test API Connection
            </button>
            <button type="button" id="aebg-save-settings" class="aebg-btn aebg-btn-success">
                <span class="aebg-icon">💾</span>
                Save Settings
            </button>
            <button type="button" id="aebg-debug-settings" class="aebg-btn aebg-btn-secondary">
                <span class="aebg-icon">🐛</span>
                Debug Settings
            </button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div id="aebg-settings-tabs" class="aebg-tabs-wrapper">
        <div class="aebg-tabs-nav">
            <button type="button" class="aebg-tab-btn active" data-tab="general">
                <span class="aebg-icon">⚙️</span>
                <span>General</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="integrations">
                <span class="aebg-icon">🔗</span>
                <span>Integrations</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="merchants">
                <span class="aebg-icon">🏬</span>
                <span>Merchants</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="advanced">
                <span class="aebg-icon">🔧</span>
                <span>Advanced</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="networks">
                <span class="aebg-icon">🌐</span>
                <span>Networks</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="logs">
                <span class="aebg-icon">📋</span>
                <span>Logs</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="competitor-tracking">
                <span class="aebg-icon">🔍</span>
                <span>Competitor Tracking</span>
            </button>
            <button type="button" class="aebg-tab-btn" data-tab="email-marketing">
                <span class="aebg-icon">📧</span>
                <span>Email Marketing</span>
            </button>
        </div>

        <div class="aebg-tabs-content">
            <!-- General Tab -->
            <div id="aebg-tab-general" class="aebg-tab-panel active">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-general.php'; ?>
            </div>

            <!-- Integrations Tab -->
            <div id="aebg-tab-integrations" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-integrations.php'; ?>
            </div>

            <!-- Merchants Tab -->
            <div id="aebg-tab-merchants" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-merchants.php'; ?>
            </div>

            <!-- Advanced Tab -->
            <div id="aebg-tab-advanced" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-advanced.php'; ?>
            </div>

            <!-- Networks Tab -->
            <div id="aebg-tab-networks" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-networks.php'; ?>
            </div>

            <!-- Logs Tab -->
            <div id="aebg-tab-logs" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-logs.php'; ?>
            </div>

            <!-- Competitor Tracking Tab -->
            <div id="aebg-tab-competitor-tracking" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/Admin/views/settings-tab-competitor-tracking.php'; ?>
            </div>
            
            <!-- Email Marketing Tab -->
            <div id="aebg-tab-email-marketing" class="aebg-tab-panel">
                <?php include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/settings-tab-email-marketing.php'; ?>
            </div>
        </div>
    </div>

    <!-- API Test Results Modal -->
    <div id="aebg-api-test-modal" class="aebg-modal" style="display: none;">
        <div class="aebg-modal-content">
            <div class="aebg-modal-header">
                <h3>API Connection Test Results</h3>
                <button type="button" class="aebg-modal-close">&times;</button>
            </div>
            <div class="aebg-modal-body" id="aebg-api-test-results">
                <!-- Test results will be populated here -->
            </div>
        </div>
    </div>

    <!-- Datafeedr Test Results Modal -->
    <div id="aebg-datafeedr-test-modal" class="aebg-modal" style="display: none;">
        <div class="aebg-modal-content">
            <div class="aebg-modal-header">
                <h3>Datafeedr Connection Test Results</h3>
                <button type="button" class="aebg-modal-close">&times;</button>
            </div>
            <div class="aebg-modal-body" id="aebg-datafeedr-test-results">
                <!-- Test results will be populated here -->
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="aebg-loading-overlay" class="aebg-loading-overlay">
        <div class="aebg-loading-spinner"></div>
        <p>Testing API connection...</p>
    </div>

    <script>
        // Immediately hide all modals on page load (before jQuery loads)
        (function() {
            var modals = document.querySelectorAll('.aebg-modal');
            for (var i = 0; i < modals.length; i++) {
                modals[i].style.display = 'none';
                modals[i].classList.remove('show');
            }
        })();
    </script>
