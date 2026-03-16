<?php
/**
 * Enhanced Networks Page View with Country Selection and Sorting
 *
 * @package AEBG\Admin\Views
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize Networks Manager
$networks_manager = new \AEBG\Admin\Networks_Manager();

// Initialize Network API Manager for credential management
$network_api_manager = null;
$network_registry = null;
$network_credentials = [];
try {
    $network_api_manager = new \AEBG\Core\Network_API_Manager();
    $network_registry = new \AEBG\Core\Network_API\Network_Registry();
} catch (Exception $e) {
    error_log('[AEBG Networks Page] Error initializing Network API Manager: ' . $e->getMessage());
}

// Get all networks with their configuration status from unified table
try {
    $networks_with_status = $networks_manager->get_all_networks_with_status();
    $affiliate_ids = $networks_manager->get_all_affiliate_ids();
    
    // Get credentials for networks that support API
    if ($network_api_manager) {
        foreach ($networks_with_status as $network) {
            $network_key = $network['code'] ?? '';
            if ($network_registry && $network_registry->get_config($network_key)) {
                $network_credentials[$network_key] = $network_api_manager->get_all_credentials($network_key);
            }
        }
    }
} catch (Exception $e) {
    error_log('[AEBG Networks Page] Error loading networks: ' . $e->getMessage());
    $networks_with_status = [];
    $affiliate_ids = [];
}

// If no networks found, fallback to default networks
if (empty($networks_with_status)) {
    // Fallback to Networks_Data for default networks
    try {
        $networks_data = new \AEBG\Admin\Networks_Data();
        $networks_with_status = $networks_data->get_all_networks();
        
        // Add configuration status
        foreach ($networks_with_status as &$network) {
            $network['configured'] = isset($affiliate_ids[$network['code']]) && !empty($affiliate_ids[$network['code']]);
            $network['affiliate_id'] = $network['configured'] ? $affiliate_ids[$network['code']] : '';
        }
    } catch (Exception $e) {
        error_log('[AEBG Networks Page] Error loading fallback networks: ' . $e->getMessage());
        $networks_with_status = [];
    }
}

// Convert networks array to the format expected by the template
$networks = [];
if (!empty($networks_with_status)) {
    foreach ($networks_with_status as $network) {
        $networks[$network['code']] = [
            'name' => $network['name'],
            'country' => $network['country'] ?? '',
            'region' => $network['region'] ?? '',
            'flag' => $network['flag'] ?? '',
            'configured' => $network['configured'] ?? false,
            'affiliate_id' => $network['affiliate_id'] ?? ''
        ];
    }
}

// Get unique countries and regions for filtering
$countries = [];
$regions = [];
foreach ($networks as $network) {
    if (!empty($network['country'])) {
        $countries[$network['country']] = $network['flag'] . ' ' . $network['country'];
    }
    if (!empty($network['region'])) {
        $regions[$network['region']] = $network['region'];
    }
}
asort($countries);
asort($regions);

$total_networks = count($networks);
$configured_count = 0;
foreach ($networks as $network) {
    if ($network['configured']) {
        $configured_count++;
    }
}

// Get current filter values
$selected_country = $_GET['country'] ?? '';
$selected_region = $_GET['region'] ?? '';
$selected_status = $_GET['status'] ?? '';
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Network Management</h1>
    
    <!-- Network Summary -->
    <div class="notice notice-info">
        <p><strong>Network Summary:</strong> <?php echo $total_networks; ?> total networks, <?php echo $configured_count; ?> configured</p>
        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
            <p><small>Debug: Networks loaded from <?php echo !empty($networks_with_status) ? 'Networks_Manager' : 'Networks_Data fallback'; ?></small></p>
        <?php endif; ?>
        <p>
            <button type="button" id="aebg-sync-networks-btn" class="button button-secondary">
                <span class="dashicons dashicons-update"></span>
                Sync Networks from Datafeedr API
            </button>
            <span id="aebg-sync-status" style="margin-left: 10px;"></span>
        </p>
    </div>
    
    <div class="aebg-networks-container">
        <!-- Enhanced Filter Controls -->
        <div class="aebg-networks-header">
            <div class="aebg-filters">
                <div class="aebg-filter-group">
                    <label for="aebg-country-filter">Country:</label>
                    <select id="aebg-country-filter" class="aebg-filter-select">
                        <option value="">All Countries</option>
                        <?php foreach ($countries as $country_code): ?>
                            <?php 
                            // Find the flag for this country
                            $country_flag = '';
                            foreach ($networks as $network) {
                                if (is_array($network) && isset($network['country']) && $network['country'] === $country_code) {
                                    $country_flag = $network['flag'] ?? '';
                                    break;
                                }
                            }
                            ?>
                            <option value="<?php echo esc_attr($country_code); ?>" <?php selected($selected_country, $country_code); ?>>
                                <?php if ($country_flag): ?>
                                    <?php echo $country_flag; ?> 
                                <?php endif; ?>
                                <?php echo esc_html($country_code); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="aebg-filter-group">
                    <label for="aebg-region-filter">Region:</label>
                    <select id="aebg-region-filter" class="aebg-filter-select">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $region_name): ?>
                            <?php 
                            // Find the flag for the first country in this region
                            $region_flag = '';
                            foreach ($networks as $network) {
                                if (is_array($network) && isset($network['region']) && $network['region'] === $region_name) {
                                    $region_flag = $network['flag'] ?? '';
                                    break;
                                }
                            }
                            ?>
                            <option value="<?php echo esc_attr($region_name); ?>" <?php selected($selected_region, $region_name); ?>>
                                <?php if ($region_flag): ?>
                                    <?php echo $region_flag; ?> 
                                <?php endif; ?>
                                <?php echo esc_html($region_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="aebg-filter-group">
                    <label for="aebg-status-filter">Status:</label>
                    <select id="aebg-status-filter" class="aebg-filter-select">
                        <option value="">All Networks</option>
                        <option value="configured" <?php selected($selected_status, 'configured'); ?>>Configured Only</option>
                        <option value="not_configured" <?php selected($selected_status, 'not_configured'); ?>>Not Configured Only</option>
                    </select>
                </div>
                
                <div class="aebg-filter-group">
                    <button type="button" id="aebg-clear-filters" class="button button-secondary">
                        <span class="dashicons dashicons-filter"></span>
                        Clear Filters
                    </button>
                </div>
            </div>
            
            <div class="aebg-search-box">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="aebg-network-search" placeholder="Search networks..." />
                <span class="aebg-network-count">
                    <?php echo $configured_count; ?> of <?php echo $total_networks; ?> configured
                </span>
            </div>
        </div>
        
        <!-- Networks Grid -->
        <div class="aebg-networks-grid">
            <?php if (empty($networks)): ?>
                <div class="notice notice-warning">
                    <p><strong>No networks found.</strong> Please check the Networks_Manager configuration or contact support.</p>
                </div>
            <?php else: ?>
                <?php wp_nonce_field('aebg_save_networks_ajax', 'aebg_networks_nonce'); ?>
                <?php wp_nonce_field('aebg_sync_networks', 'aebg_sync_networks_nonce'); ?>
                <?php foreach ($networks as $network_key => $network_data): ?>
                <?php 
                $network_name = is_array($network_data) ? $network_data['name'] : $network_data;
                $network_country = is_array($network_data) ? ($network_data['country'] ?? '') : '';
                $network_region = is_array($network_data) ? ($network_data['region'] ?? '') : '';
                $current_id = $affiliate_ids[$network_key] ?? '';
                $is_configured = !empty($current_id);
                ?>
                <div class="aebg-network-card" 
                     data-network="<?php echo esc_attr($network_key); ?>"
                     data-country="<?php echo esc_attr($network_country); ?>"
                     data-region="<?php echo esc_attr($network_region); ?>"
                     data-configured="<?php echo $is_configured ? '1' : '0'; ?>">
                    <div class="aebg-network-header">
                        <h3><?php echo esc_html($network_name); ?></h3>
                        <div class="aebg-network-meta">
                            <?php if ($network_country): ?>
                                <?php 
                                $network_flag = is_array($network_data) ? ($network_data['flag'] ?? '') : '';
                                ?>
                                <span class="aebg-network-country">
                                    <?php if ($network_flag): ?>
                                        <span class="aebg-network-flag"><?php echo $network_flag; ?></span>
                                    <?php endif; ?>
                                    <?php echo esc_html($network_country); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($network_region): ?>
                                <span class="aebg-network-region"><?php echo esc_html($network_region); ?></span>
                            <?php endif; ?>
                            <?php if (strpos($network_key, 'api_') === 0): ?>
                                <span class="aebg-network-source">API</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="aebg-network-content">
                        <div class="aebg-form-group">
                            <label for="affiliate_id_<?php echo esc_attr($network_key); ?>">
                                Affiliate ID
                            </label>
                            <input 
                                type="text" 
                                id="affiliate_id_<?php echo esc_attr($network_key); ?>"
                                name="affiliate_id_<?php echo esc_attr($network_key); ?>" 
                                value="<?php echo esc_attr($current_id); ?>" 
                                placeholder="Enter your affiliate ID"
                                class="aebg-affiliate-input"
                                data-network="<?php echo esc_attr($network_key); ?>"
                            />
                        </div>
                        
                        <?php
                        // Show API credentials section if network supports it
                        $network_config = $network_registry ? $network_registry->get_config($network_key) : null;
                        if ($network_config):
                            $required_creds = $network_registry->get_required_credentials($network_key);
                            $optional_creds = $network_registry->get_optional_credentials($network_key);
                            $credential_labels = $network_registry->get_credential_labels($network_key);
                            $all_creds = $network_credentials[$network_key] ?? [];
                            $is_api_configured = $network_api_manager ? $network_api_manager->is_configured($network_key) : false;
                        ?>
                        <div class="aebg-network-api-credentials">
                            <h4>API Credentials</h4>
                            
                            <?php if (!empty($required_creds)): ?>
                            <div class="aebg-credentials-section">
                                <?php foreach ($required_creds as $cred_type): 
                                    $label = $credential_labels[$cred_type] ?? ucwords(str_replace('_', ' ', $cred_type));
                                    $value = $all_creds[$cred_type]['credential_value'] ?? '';
                                    $is_configured = !empty($value);
                                ?>
                                <div class="aebg-form-group">
                                    <label for="api_cred_<?php echo esc_attr($network_key); ?>_<?php echo esc_attr($cred_type); ?>">
                                        <?php echo esc_html($label); ?>
                                        <span class="required-badge">Required</span>
                                    </label>
                                    <div class="aebg-credential-input-wrapper">
                                        <input 
                                            type="password" 
                                            id="api_cred_<?php echo esc_attr($network_key); ?>_<?php echo esc_attr($cred_type); ?>"
                                            class="aebg-credential-input" 
                                            data-network="<?php echo esc_attr($network_key); ?>"
                                            data-credential-type="<?php echo esc_attr($cred_type); ?>"
                                            value="<?php echo $is_configured ? '••••••••' : ''; ?>"
                                            placeholder="Enter <?php echo esc_attr(strtolower($label)); ?>"
                                        />
                                        <?php if ($is_configured): ?>
                                        <button type="button" 
                                                class="aebg-delete-credential button button-small" 
                                                data-network="<?php echo esc_attr($network_key); ?>"
                                                data-credential-type="<?php echo esc_attr($cred_type); ?>">
                                            Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($is_configured): ?>
                                    <span class="credential-status success">✓ Configured</span>
                                    <?php else: ?>
                                    <span class="credential-status missing">Missing</span>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="aebg-credentials-actions">
                                <button type="button" 
                                        class="aebg-save-credentials button button-primary" 
                                        data-network="<?php echo esc_attr($network_key); ?>">
                                    Save Credentials
                                </button>
                                <button type="button" 
                                        class="aebg-test-credentials button" 
                                        data-network="<?php echo esc_attr($network_key); ?>">
                                    Test Connection
                                </button>
                            </div>
                            
                            <div class="aebg-credentials-messages" data-network="<?php echo esc_attr($network_key); ?>"></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="aebg-network-status">
                            <?php if ($is_configured): ?>
                                <span class="aebg-status aebg-status-active">
                                    <span class="dashicons dashicons-yes"></span> Configured
                                </span>
                            <?php else: ?>
                                <span class="aebg-status aebg-status-inactive">
                                    <span class="dashicons dashicons-no"></span> Not configured
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Save Button -->
        <?php if (!empty($networks)): ?>
        <div class="aebg-form-actions">
            <button type="button" id="aebg-save-all-networks-btn" class="button button-primary button-large">
                <span class="dashicons dashicons-saved"></span>
                <span class="btn-text">Save All Network IDs</span>
            </button>
            <div class="aebg-save-status" id="aebg-save-status"></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Ensure ajaxurl is available
if (typeof ajaxurl === 'undefined') {
    var ajaxurl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
}

// Network Analytics nonce
var aebgNetworkAnalytics = {
    ajaxUrl: ajaxurl,
    nonce: '<?php echo wp_create_nonce("aebg_ajax_nonce"); ?>'
};

jQuery(document).ready(function($) {
    // Enhanced filtering functionality
    function filterNetworks() {
        const searchTerm = $('#aebg-network-search').val().toLowerCase();
        const selectedCountry = $('#aebg-country-filter').val();
        const selectedRegion = $('#aebg-region-filter').val();
        const selectedStatus = $('#aebg-status-filter').val();
        
        let visibleCount = 0;
        let configuredCount = 0;
        
        $('.aebg-network-card').each(function() {
            const $card = $(this);
            const networkName = $card.find('h3').text().toLowerCase();
            const country = $card.data('country');
            const region = $card.data('region');
            const isConfigured = $card.data('configured') === 1;
            
            // Check search term
            const matchesSearch = !searchTerm || networkName.includes(searchTerm);
            
            // Check country filter
            const matchesCountry = !selectedCountry || country === selectedCountry;
            
            // Check region filter
            const matchesRegion = !selectedRegion || region === selectedRegion;
            
            // Check status filter
            let matchesStatus = true;
            if (selectedStatus === 'configured') {
                matchesStatus = isConfigured;
            } else if (selectedStatus === 'not_configured') {
                matchesStatus = !isConfigured;
            }
            
            // Show/hide card based on all filters
            if (matchesSearch && matchesCountry && matchesRegion && matchesStatus) {
                $card.show();
                visibleCount++;
                if (isConfigured) configuredCount++;
            } else {
                $card.hide();
            }
        });
        
        // Update count display
        $('.aebg-network-count').text(configuredCount + ' of ' + visibleCount + ' networks configured');
    }
    
    // Search functionality
    $('#aebg-network-search').on('input', filterNetworks);
    
    // Filter change handlers
    $('#aebg-country-filter, #aebg-region-filter, #aebg-status-filter').on('change', filterNetworks);
    
    // Clear filters
    $('#aebg-clear-filters').on('click', function() {
        $('#aebg-country-filter, #aebg-region-filter, #aebg-status-filter').val('');
        $('#aebg-network-search').val('');
        filterNetworks();
    });
    
    // Save all networks functionality
    $('#aebg-save-all-networks-btn').on('click', function() {
        const $btn = $(this);
        const $status = $('#aebg-save-status');
        
        $btn.prop('disabled', true);
        $btn.find('.btn-text').text('Saving...');
        $status.html('<div class="notice notice-info"><p>Saving network IDs...</p></div>');
        
        const formData = new FormData();
        formData.append('action', 'aebg_save_networks_ajax');
        formData.append('nonce', $('#aebg_networks_nonce').val());
        
        $('.aebg-affiliate-input').each(function() {
            const $input = $(this);
            const networkKey = $input.data('network');
            const value = $input.val();
            formData.append('affiliate_ids[' + networkKey + ']', value);
        });
        
        // Get ajaxurl - try multiple sources
        var saveAjaxUrl = '';
        if (typeof aebg_ajax !== 'undefined' && aebg_ajax.ajaxurl) {
            saveAjaxUrl = aebg_ajax.ajaxurl;
        } else if (typeof aebg !== 'undefined' && aebg.ajaxurl) {
            saveAjaxUrl = aebg.ajaxurl;
        } else if (typeof ajaxurl !== 'undefined') {
            saveAjaxUrl = ajaxurl;
        } else {
            saveAjaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
        }
        
        console.log('Save: Using AJAX URL:', saveAjaxUrl);
        console.log('Save: Nonce value:', $('#aebg_networks_nonce').val());
        console.log('Save: Affiliate IDs being sent:', formData);
        
        $.ajax({
            url: saveAjaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log('AJAX Response:', response);
                if (response.success) {
                    $status.html('<div class="notice notice-success"><p>All network IDs saved successfully!</p></div>');
                    
                    // Update status indicators and data attributes
                    $('.aebg-affiliate-input').each(function() {
                        const $input = $(this);
                        const $card = $input.closest('.aebg-network-card');
                        const $statusSpan = $card.find('.aebg-network-status');
                        
                        if ($input.val().trim()) {
                            $statusSpan.html('<span class="aebg-status aebg-status-active"><span class="dashicons dashicons-yes"></span> Configured</span>');
                            $card.data('configured', 1);
                        } else {
                            $statusSpan.html('<span class="aebg-status aebg-status-inactive"><span class="dashicons dashicons-no"></span> Not configured</span>');
                            $card.data('configured', 0);
                        }
                    });
                    
                    // Re-filter to update counts
                    filterNetworks();
                } else {
                    console.error('Save failed:', response.data);
                    $status.html('<div class="notice notice-error"><p>Error saving network IDs: ' + (response.data || 'Unknown error') + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                console.error('Response Text:', xhr.responseText);
                $status.html('<div class="notice notice-error"><p>Error saving network IDs. Please try again. Check console for details.</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btn.find('.btn-text').text('Save All Network IDs');
            }
        });
    });
    
    // Auto-save on input blur (optional)
    $('.aebg-affiliate-input').on('blur', function() {
        const $input = $(this);
        const $card = $input.closest('.aebg-network-card');
        const value = $input.val();
        
        // Update status immediately
        const $statusSpan = $card.find('.aebg-network-status');
        if (value.trim()) {
            $statusSpan.html('<span class="aebg-status aebg-status-active"><span class="dashicons dashicons-yes"></span> Configured</span>');
            $card.data('configured', 1);
        } else {
            $statusSpan.html('<span class="aebg-status aebg-status-inactive"><span class="dashicons dashicons-no"></span> Not configured</span>');
            $card.data('configured', 0);
        }
        
        // Re-filter to update counts
        filterNetworks();
    });
    
    // Sync networks from API
    console.log('Registering sync button handler...');
    var $syncBtn = $('#aebg-sync-networks-btn');
    console.log('Sync button found:', $syncBtn.length > 0);
    
    $syncBtn.on('click', function(e) {
        e.preventDefault();
        console.log('Sync button clicked!');
        const $btn = $(this);
        const $status = $('#aebg-sync-status');
        
        // Get ajaxurl - try multiple sources
        var ajaxUrl = '';
        if (typeof aebg_ajax !== 'undefined' && aebg_ajax.ajaxurl) {
            ajaxUrl = aebg_ajax.ajaxurl;
        } else if (typeof aebg !== 'undefined' && aebg.ajaxurl) {
            ajaxUrl = aebg.ajaxurl;
        } else if (typeof ajaxurl !== 'undefined') {
            ajaxUrl = ajaxurl;
        } else {
            // Fallback to WordPress default
            ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
        }
        
        console.log('Using AJAX URL:', ajaxUrl);
        console.log('Nonce value:', $('#aebg_sync_networks_nonce').val());
        
        if (!ajaxUrl) {
            $status.html('<span style="color: red;">✗ Error: AJAX URL not found. Please refresh the page.</span>');
            return;
        }
        
        $btn.prop('disabled', true);
        $btn.find('.dashicons').addClass('spin');
        $status.html('<span class="spinner is-active" style="float: none; margin: 0 5px;"></span> Syncing networks from API...');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'aebg_sync_networks_from_api',
                nonce: $('#aebg_sync_networks_nonce').val(),
                clear_existing: false
            },
            success: function(response) {
                console.log('Sync response:', response);
                if (response.success) {
                    $status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    // Reload page after 2 seconds to show updated networks
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    console.error('Sync failed:', errorMsg);
                    $status.html('<span style="color: red;">✗ Error: ' + errorMsg + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                console.error('Response Text:', xhr.responseText);
                var errorMsg = 'Error syncing networks. ';
                if (xhr.responseText) {
                    try {
                        var errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data && errorResponse.data.message) {
                            errorMsg += errorResponse.data.message;
                        } else {
                            errorMsg += 'Please check console for details.';
                        }
                    } catch(e) {
                        errorMsg += 'Please check console for details.';
                    }
                } else {
                    errorMsg += 'Please check console for details.';
                }
                $status.html('<span style="color: red;">✗ ' + errorMsg + '</span>');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btn.find('.dashicons').removeClass('spin');
            }
        });
    });
    
    // Network Credentials Management
    // Save credentials
    $(document).on('click', '.aebg-save-credentials', function() {
        const $btn = $(this);
        const networkKey = $btn.data('network');
        const $messages = $('.aebg-credentials-messages[data-network="' + networkKey + '"]');
        
        $btn.prop('disabled', true).text('Saving...');
        $messages.html('<div class="notice notice-info"><p>Saving credentials...</p></div>');
        
        const credentials = {};
        $('.aebg-credential-input[data-network="' + networkKey + '"]').each(function() {
            const $input = $(this);
            const credType = $input.data('credential-type');
            const value = $input.val().trim();
            
            if (value && value !== '••••••••') {
                credentials[credType] = value;
            }
        });
        
        $.ajax({
            url: aebgNetworkAnalytics.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aebg_save_network_credentials',
                nonce: aebgNetworkAnalytics.nonce,
                network_key: networkKey,
                credentials: credentials
            },
            success: function(response) {
                if (response.success) {
                    $messages.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    // Update UI - mask saved credentials
                    $('.aebg-credential-input[data-network="' + networkKey + '"]').each(function() {
                        const $input = $(this);
                        if ($input.val() && $input.val() !== '••••••••') {
                            $input.val('••••••••');
                            $input.closest('.aebg-form-group').find('.credential-status').removeClass('missing').addClass('success').html('✓ Configured');
                        }
                    });
                } else {
                    $messages.html('<div class="notice notice-error"><p>' + (response.data.message || 'Failed to save credentials') + '</p></div>');
                }
            },
            error: function() {
                $messages.html('<div class="notice notice-error"><p>Error saving credentials. Please try again.</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Credentials');
            }
        });
    });
    
    // Test credentials
    $(document).on('click', '.aebg-test-credentials', function() {
        const $btn = $(this);
        const networkKey = $btn.data('network');
        const $messages = $('.aebg-credentials-messages[data-network="' + networkKey + '"]');
        
        $btn.prop('disabled', true).text('Testing...');
        $messages.html('<div class="notice notice-info"><p>Testing connection...</p></div>');
        
        $.ajax({
            url: aebgNetworkAnalytics.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aebg_test_network_credentials',
                nonce: aebgNetworkAnalytics.nonce,
                network_key: networkKey
            },
            success: function(response) {
                if (response.success) {
                    $messages.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    $messages.html('<div class="notice notice-error"><p>' + (response.data.message || 'Connection test failed') + '</p></div>');
                }
            },
            error: function() {
                $messages.html('<div class="notice notice-error"><p>Error testing connection. Please try again.</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Test Connection');
            }
        });
    });
    
    // Delete credential
    $(document).on('click', '.aebg-delete-credential', function() {
        if (!confirm('Are you sure you want to delete this credential?')) {
            return;
        }
        
        const $btn = $(this);
        const networkKey = $btn.data('network');
        const credType = $btn.data('credential-type');
        const $input = $('.aebg-credential-input[data-network="' + networkKey + '"][data-credential-type="' + credType + '"]');
        const $messages = $('.aebg-credentials-messages[data-network="' + networkKey + '"]');
        
        $btn.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: aebgNetworkAnalytics.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aebg_delete_network_credential',
                nonce: aebgNetworkAnalytics.nonce,
                network_key: networkKey,
                credential_type: credType
            },
            success: function(response) {
                if (response.success) {
                    $input.val('').closest('.aebg-form-group').find('.credential-status').removeClass('success').addClass('missing').html('Missing');
                    $btn.remove();
                    $messages.html('<div class="notice notice-success"><p>Credential deleted successfully</p></div>');
                } else {
                    $messages.html('<div class="notice notice-error"><p>' + (response.data.message || 'Failed to delete credential') + '</p></div>');
                }
            },
            error: function() {
                $messages.html('<div class="notice notice-error"><p>Error deleting credential. Please try again.</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Delete');
            }
        });
    });
});
</script>
<style>
.dashicons.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style> 