<?php
/**
 * Settings Tab: Integrations
 * 
 * Contains: Datafeedr Integration, Network Settings
 * 
 * @package AEBG
 */

// Load networks data for JavaScript - using updated Networks Manager
try {
    $networks_manager = new \AEBG\Admin\Networks_Manager();
    $networks_with_status = $networks_manager->get_all_networks_with_status();
    
    if (is_wp_error($networks_with_status)) {
        throw new Exception($networks_with_status->get_error_message());
    }
    
    // Ensure we have an array
    if (!is_array($networks_with_status)) {
        $networks_with_status = [];
    }
} catch (Exception $e) {
    error_log('[AEBG Settings Tab Integrations] Error loading networks: ' . $e->getMessage());
    $networks_with_status = [];
}
?>
<div class="aebg-settings-grid">
    <!-- Datafeedr Integration -->
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>🛒 Datafeedr Integration</h2>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_datafeedr_access_id">Datafeedr Access ID</label>
                <div class="aebg-input-group">
                    <input type="password" id="aebg_datafeedr_access_id" name="aebg_settings[datafeedr_access_id]" 
                           value="<?php echo esc_attr( isset( $options['datafeedr_access_id'] ) ? $options['datafeedr_access_id'] : '' ); ?>" 
                           class="aebg-input" placeholder="Enter your Datafeedr Access ID">
                    <button type="button" class="aebg-toggle-password" data-target="aebg_datafeedr_access_id">
                        <span class="aebg-icon">👁️</span>
                    </button>
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">🔑</span>
                    Your Datafeedr Access ID (required for API authentication)
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_datafeedr_secret_key">Datafeedr Access Key</label>
                <div class="aebg-input-group">
                    <input type="password" id="aebg_datafeedr_secret_key" name="aebg_settings[datafeedr_secret_key]" 
                           value="<?php echo esc_attr( isset( $options['datafeedr_secret_key'] ) ? $options['datafeedr_secret_key'] : '' ); ?>" 
                           class="aebg-input" placeholder="Enter your Datafeedr Access Key">
                    <button type="button" class="aebg-toggle-password" data-target="aebg_datafeedr_secret_key">
                        <span class="aebg-icon">👁️</span>
                    </button>
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">🔐</span>
                    Your Datafeedr Access Key (required for API authentication)
                </p>
            </div>

            <div class="aebg-form-group">
                <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                    <input type="checkbox" id="aebg_enable_datafeedr" name="aebg_settings[enable_datafeedr]" 
                           value="1" <?php checked( isset( $options['enable_datafeedr'] ) ? $options['enable_datafeedr'] : false ); ?>>
                    <span class="aebg-checkbox-custom"></span>
                    <span class="aebg-checkbox-text">
                        <span class="aebg-icon">🔗</span>
                        Enable Datafeedr Integration
                    </span>
                </label>
                <p class="aebg-help-text">
                    <span class="aebg-icon">🔗</span>
                    Enable Datafeedr for product data enrichment (requires both Access ID and Access Key)
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_default_currency">Default Currency for Product Search</label>
                <select id="aebg_default_currency" name="aebg_settings[default_currency]" class="aebg-select">
                    <option value="All Currencies" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'All Currencies' ); ?>>All Currencies</option>
                    <option value="USD" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'USD' ); ?>>USD - US Dollar</option>
                    <option value="EUR" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'EUR' ); ?>>EUR - Euro</option>
                    <option value="GBP" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'GBP' ); ?>>GBP - British Pound</option>
                    <option value="CAD" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'CAD' ); ?>>CAD - Canadian Dollar</option>
                    <option value="AUD" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'AUD' ); ?>>AUD - Australian Dollar</option>
                    <option value="DKK" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'DKK' ); ?>>DKK - Danish Krone</option>
                    <option value="SEK" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'SEK' ); ?>>SEK - Swedish Krona</option>
                    <option value="NOK" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'NOK' ); ?>>NOK - Norwegian Krone</option>
                    <option value="CHF" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'CHF' ); ?>>CHF - Swiss Franc</option>
                    <option value="JPY" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'JPY' ); ?>>JPY - Japanese Yen</option>
                    <option value="PLN" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'PLN' ); ?>>PLN - Polish Złoty</option>
                    <option value="CZK" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'CZK' ); ?>>CZK - Czech Koruna</option>
                    <option value="HUF" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'HUF' ); ?>>HUF - Hungarian Forint</option>
                    <option value="RON" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'RON' ); ?>>RON - Romanian Leu</option>
                    <option value="BGN" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'BGN' ); ?>>BGN - Bulgarian Lev</option>
                    <option value="HRK" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'HRK' ); ?>>HRK - Croatian Kuna</option>
                    <option value="RUB" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'RUB' ); ?>>RUB - Russian Ruble</option>
                    <option value="TRY" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'TRY' ); ?>>TRY - Turkish Lira</option>
                    <option value="BRL" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'BRL' ); ?>>BRL - Brazilian Real</option>
                    <option value="MXN" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'MXN' ); ?>>MXN - Mexican Peso</option>
                    <option value="INR" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'INR' ); ?>>INR - Indian Rupee</option>
                    <option value="KRW" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'KRW' ); ?>>KRW - South Korean Won</option>
                    <option value="SGD" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'SGD' ); ?>>SGD - Singapore Dollar</option>
                    <option value="HKD" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'HKD' ); ?>>HKD - Hong Kong Dollar</option>
                    <option value="NZD" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'NZD' ); ?>>NZD - New Zealand Dollar</option>
                    <option value="ZAR" <?php selected( isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD', 'ZAR' ); ?>>ZAR - South African Rand</option>
                </select>
                <p class="aebg-help-text">
                    <span class="aebg-icon">💱</span>
                    Default currency to filter products by after search results are returned (post-filtering approach)
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_default_networks">Default Networks for Product Search</label>
                <div class="aebg-networks-selector">
                    <!-- Configuration Option -->
                    <div class="aebg-config-option">
                        <label class="aebg-checkbox-label">
                            <input type="checkbox" id="aebg_search_configured_only" name="aebg_settings[search_configured_only]" value="1" <?php checked(isset($options['search_configured_only']) ? $options['search_configured_only'] : false); ?>>
                            <span class="aebg-checkbox-custom"></span>
                            <span class="aebg-checkbox-text">
                                <strong>🔒 Search only in configured networks</strong>
                                <small>Only search networks where you have entered affiliate IDs</small>
                            </span>
                        </label>
                    </div>

                    <!-- Network Selection Interface -->
                    <div class="aebg-network-selection">
                        <div class="aebg-selection-header">
                            <h4>🌐 Select Networks for Bulk Generation</h4>
                            <p class="aebg-selection-description">
                                Choose which networks to search when generating content. Networks with affiliate IDs configured are highlighted.
                            </p>
                        </div>

                        <!-- Search and Filter Bar -->
                        <div class="aebg-filter-bar">
                            <div class="aebg-search-container">
                                <input type="text" id="aebg_networks_search" placeholder="Search networks by name..." class="aebg-search-input">
                                <span class="aebg-search-icon">🔍</span>
                            </div>
                            <div class="aebg-filter-tabs">
                                <button type="button" class="aebg-filter-tab active" data-filter="all">All Networks</button>
                                <button type="button" class="aebg-filter-tab" data-filter="configured">Configured</button>
                                <button type="button" class="aebg-filter-tab" data-filter="popular">Popular</button>
                                <button type="button" class="aebg-filter-tab" data-filter="amazon">Amazon</button>
                            </div>
                        </div>

                        <!-- Networks Grid -->
                        <div class="aebg-networks-grid-container">
                            <div class="aebg-networks-grid" id="aebg_networks_grid">
                                <!-- Networks will be populated by JavaScript -->
                            </div>
                        </div>

                        <!-- Selection Summary -->
                        <div class="aebg-selection-summary">
                            <div class="aebg-summary-stats">
                                <span class="aebg-stat">
                                    <span class="aebg-stat-number" id="aebg_selected_count">0</span>
                                    <span class="aebg-stat-label">networks selected</span>
                                </span>
                                <span class="aebg-stat">
                                    <span class="aebg-stat-number" id="aebg_configured_count">0</span>
                                    <span class="aebg-stat-label">configured</span>
                                </span>
                            </div>
                            <div class="aebg-summary-actions">
                                <button type="button" id="aebg_select_configured" class="aebg-btn aebg-btn-secondary">
                                    <span>🔒</span> Select Configured
                                </button>
                                <button type="button" id="aebg_select_popular" class="aebg-btn aebg-btn-secondary">
                                    <span>⭐</span> Select Popular
                                </button>
                                <button type="button" id="aebg_clear_all" class="aebg-btn aebg-btn-danger">
                                    <span>🗑️</span> Clear All
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden input to store selected networks -->
                    <?php 
                    // Prepare the networks value for the hidden input
                    $networks_value = '[]';
                    if (isset($options['default_networks']) && !empty($options['default_networks'])) {
                        if (is_array($options['default_networks'])) {
                            $networks_value = json_encode($options['default_networks']);
                        } else {
                            $networks_value = esc_attr($options['default_networks']);
                        }
                    }
                    ?>
                    <input type="hidden" id="aebg_default_networks" name="aebg_settings[default_networks]" value="<?php echo htmlspecialchars($networks_value, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">💡</span>
                    Select which networks to search when bulk generating content. Networks with configured affiliate IDs will provide better results.
                </p>
            </div>

            <script>
                // Networks data will be loaded later in the page
                var aebg_ajax_nonce = '<?php echo wp_create_nonce('aebg_ajax_nonce'); ?>';
            </script>

            <div class="aebg-form-group">
                <button type="button" id="aebg-test-datafeedr" class="aebg-btn aebg-btn-secondary">
                    <span class="aebg-icon">🔍</span>
                    Test Datafeedr Connection
                </button>
                <p class="aebg-help-text">
                    <span class="aebg-icon">ℹ️</span>
                    Test your Datafeedr credentials to ensure they're working correctly
                </p>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
// Make networks data available to JavaScript
var aebgNetworksData = <?php echo json_encode($networks_with_status); ?>;

// Initialize the networks selector after the page loads
document.addEventListener('DOMContentLoaded', function() {
    if (typeof ModernNetworksSelector !== 'undefined') {
        console.log('Initializing Modern Networks Selector...');
        new ModernNetworksSelector();
    } else {
        console.error('Modern Networks Selector class not found!');
    }
});
</script>

