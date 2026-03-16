<?php
/**
 * Settings Tab: Networks
 * 
 * Modern network management interface - Wireframe Design
 * 
 * @package AEBG
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
    error_log('[AEBG Networks Tab] Error initializing Network API Manager: ' . $e->getMessage());
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
    error_log('[AEBG Networks Tab] Error loading networks: ' . $e->getMessage());
    $networks_with_status = [];
    $affiliate_ids = [];
}

// If no networks found, fallback to default networks
if (empty($networks_with_status)) {
    try {
        $networks_data = new \AEBG\Admin\Networks_Data();
        $networks_with_status = $networks_data->get_all_networks();
        
        // Add configuration status
        foreach ($networks_with_status as &$network) {
            $network['configured'] = isset($affiliate_ids[$network['code']]) && !empty($affiliate_ids[$network['code']]);
            $network['affiliate_id'] = $network['configured'] ? $affiliate_ids[$network['code']] : '';
        }
    } catch (Exception $e) {
        error_log('[AEBG Networks Tab] Error loading fallback networks: ' . $e->getMessage());
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
$not_configured_count = 0;
foreach ($networks as $network) {
    if ($network['configured']) {
        $configured_count++;
    } else {
        $not_configured_count++;
    }
}

// Calculate percentage configured
$configured_percentage = $total_networks > 0 ? round(($configured_count / $total_networks) * 100) : 0;

// Get current filter values
$selected_country = $_GET['country'] ?? '';
$selected_region = $_GET['region'] ?? '';
$selected_status = $_GET['status'] ?? '';
?>

<div class="aebg-networks-container">
    <!-- Stat Cards Carousel (4 cards) -->
    <div class="aebg-stats-carousel-wrapper">
        <button type="button" class="aebg-carousel-nav aebg-carousel-prev" aria-label="Previous stat cards">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </button>
        <div class="aebg-stats-carousel">
            <div class="aebg-networks-stats">
                <div class="aebg-stat-card">
                    <div class="aebg-stat-icon">🌐</div>
                    <div class="aebg-stat-content">
                        <div class="aebg-stat-value"><?php echo esc_html($total_networks); ?></div>
                        <div class="aebg-stat-label">Total Networks</div>
                    </div>
                </div>
                
                <div class="aebg-stat-card">
                    <div class="aebg-stat-icon">✓</div>
                    <div class="aebg-stat-content">
                        <div class="aebg-stat-value"><?php echo esc_html($configured_count); ?></div>
                        <div class="aebg-stat-label">Configured</div>
                    </div>
                </div>
                
                <div class="aebg-stat-card">
                    <div class="aebg-stat-icon">⚠</div>
                    <div class="aebg-stat-content">
                        <div class="aebg-stat-value"><?php echo esc_html($not_configured_count); ?></div>
                        <div class="aebg-stat-label">Not Configured</div>
                    </div>
                </div>
                
                <div class="aebg-stat-card">
                    <div class="aebg-stat-icon">📊</div>
                    <div class="aebg-stat-content">
                        <div class="aebg-stat-value"><?php echo esc_html($configured_percentage); ?>%</div>
                        <div class="aebg-stat-label">Completion</div>
                    </div>
                </div>
            </div>
        </div>
        <button type="button" class="aebg-carousel-nav aebg-carousel-next" aria-label="Next stat cards">
            <span class="dashicons dashicons-arrow-right-alt2"></span>
        </button>
        <div class="aebg-carousel-indicators"></div>
    </div>

    <!-- Filters Bar -->
    <div class="aebg-filters-bar">
        <div class="aebg-filters-left">
            <div class="aebg-filter-item">
                <label for="aebg-country-filter">Country</label>
                <select id="aebg-country-filter" class="aebg-filter-select">
                    <option value="">All Countries</option>
                    <?php foreach ($countries as $country_code => $country_display): ?>
                        <option value="<?php echo esc_attr($country_code); ?>" <?php selected($selected_country, $country_code); ?>>
                            <?php echo esc_html($country_display); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="aebg-filter-item">
                <label for="aebg-region-filter">Region</label>
                <select id="aebg-region-filter" class="aebg-filter-select">
                    <option value="">All Regions</option>
                    <?php foreach ($regions as $region_name): ?>
                        <option value="<?php echo esc_attr($region_name); ?>" <?php selected($selected_region, $region_name); ?>>
                            <?php echo esc_html($region_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="aebg-filter-item">
                <label for="aebg-status-filter">Status</label>
                <select id="aebg-status-filter" class="aebg-filter-select">
                    <option value="">All Networks</option>
                    <option value="configured" <?php selected($selected_status, 'configured'); ?>>Configured Only</option>
                    <option value="not_configured" <?php selected($selected_status, 'not_configured'); ?>>Not Configured Only</option>
                </select>
            </div>
            
            <div class="aebg-filter-item">
                <label for="aebg-per-page-filter">Per Page</label>
                <select id="aebg-per-page-filter" class="aebg-filter-select">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="all">All</option>
                </select>
            </div>
            
            <button type="button" id="aebg-clear-filters" class="aebg-clear-filters-btn">
                <span class="dashicons dashicons-filter"></span>
                Clear
            </button>
        </div>
        
        <div class="aebg-filters-right">
            <div class="aebg-search-wrapper">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="aebg-network-search" class="aebg-network-search" placeholder="Search networks..." />
            </div>
            <div class="aebg-network-count">
                <?php echo esc_html($configured_count); ?> of <?php echo esc_html($total_networks); ?> configured
            </div>
        </div>
    </div>
    
    <!-- Network Grid/List View -->
    <div class="aebg-networks-view">
        <?php if (empty($networks)): ?>
            <div class="aebg-networks-empty">
                <div class="aebg-networks-empty-icon">🌐</div>
                <h3>No Networks Found</h3>
                <p>Please check the Networks_Manager configuration or contact support.</p>
            </div>
        <?php else: ?>
            <?php wp_nonce_field('aebg_save_networks_ajax', 'aebg_networks_nonce'); ?>
            <?php wp_nonce_field('aebg_sync_networks', 'aebg_sync_networks_nonce'); ?>
            
            <div class="aebg-networks-grid">
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
                    <div class="aebg-network-card-header">
                        <h3><?php echo esc_html($network_name); ?></h3>
                        <div class="aebg-network-meta">
                            <?php if ($network_country): ?>
                                <?php 
                                $network_flag = is_array($network_data) ? ($network_data['flag'] ?? '') : '';
                                ?>
                                <span class="aebg-network-badge">
                                    <?php if ($network_flag): ?>
                                        <span class="aebg-network-flag"><?php echo $network_flag; ?></span>
                                    <?php endif; ?>
                                    <?php echo esc_html($network_country); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($network_region): ?>
                                <span class="aebg-network-badge"><?php echo esc_html($network_region); ?></span>
                            <?php endif; ?>
                            <?php if ($is_configured): ?>
                                <span class="aebg-status-badge aebg-status-configured">
                                    <span class="dashicons dashicons-yes"></span> Configured
                                </span>
                            <?php else: ?>
                                <span class="aebg-status-badge aebg-status-not-configured">
                                    <span class="dashicons dashicons-no"></span> Not Configured
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="aebg-network-card-body">
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
                            <button type="button" class="aebg-toggle-credentials" data-network="<?php echo esc_attr($network_key); ?>">
                                <span class="dashicons dashicons-admin-network"></span>
                                API Credentials
                                <span class="dashicons dashicons-arrow-down"></span>
                            </button>
                            
                            <div class="aebg-credentials-panel" data-network="<?php echo esc_attr($network_key); ?>" style="display: none;">
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
                                                    class="aebg-delete-credential" 
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
                                            class="aebg-save-credentials" 
                                            data-network="<?php echo esc_attr($network_key); ?>">
                                        Save Credentials
                                    </button>
                                    <button type="button" 
                                            class="aebg-test-credentials" 
                                            data-network="<?php echo esc_attr($network_key); ?>">
                                        Test Connection
                                    </button>
                                </div>
                                
                                <div class="aebg-credentials-messages" data-network="<?php echo esc_attr($network_key); ?>"></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination Controls -->
            <div class="aebg-pagination-wrapper" id="aebg-pagination-wrapper" style="display: none;">
                <div class="aebg-pagination-info">
                    <span id="aebg-pagination-info">Showing 0-0 of 0 networks</span>
                </div>
                <div class="aebg-pagination-controls">
                    <button type="button" id="aebg-pagination-prev" class="aebg-pagination-btn" disabled>
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                        Previous
                    </button>
                    <div class="aebg-pagination-pages" id="aebg-pagination-pages"></div>
                    <button type="button" id="aebg-pagination-next" class="aebg-pagination-btn">
                        Next
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Auto-save status indicator -->
    <?php if (!empty($networks)): ?>
    <div class="aebg-auto-save-status" id="aebg-auto-save-status"></div>
    <?php endif; ?>
</div>

<?php
// Localize script data
$network_analytics_nonce = wp_create_nonce('aebg_ajax_nonce');

// Check if this is first visit to networks tab
$networks_tab_visited = get_option('aebg_networks_tab_visited', false);
$should_auto_sync = false;

// Check if Datafeedr is configured
$options = get_option('aebg_settings', []);
$access_id = $options['datafeedr_access_id'] ?? '';
$access_key = $options['datafeedr_secret_key'] ?? '';
$enabled = isset($options['enable_datafeedr']) ? (bool)$options['enable_datafeedr'] : false;

// Auto-sync on first visit if Datafeedr is configured
if (!$networks_tab_visited && $enabled && !empty($access_id) && !empty($access_key)) {
    $should_auto_sync = true;
    update_option('aebg_networks_tab_visited', true);
}
?>
<script>
// Ensure ajaxurl is available
if (typeof ajaxurl === 'undefined') {
    var ajaxurl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
}

// Network Analytics nonce
var aebgNetworkAnalytics = {
    ajaxUrl: ajaxurl,
    nonce: '<?php echo esc_js($network_analytics_nonce); ?>'
};

// Auto-sync on first visit
<?php if ($should_auto_sync): ?>
var aebgShouldAutoSync = true;
<?php else: ?>
var aebgShouldAutoSync = false;
<?php endif; ?>
</script>
