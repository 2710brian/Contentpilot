<?php
/**
 * Network Analytics Dashboard Page
 *
 * @package AEBG\Admin\Views
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Initialize managers
$network_api_manager = new \AEBG\Core\Network_API_Manager();
$network_registry = new \AEBG\Core\Network_API\Network_Registry();
$networks_manager = new \AEBG\Admin\Networks_Manager();

// Get all networks that support API
$all_networks = $networks_manager->get_all_networks_with_status();
$configured_networks = [];

foreach ($all_networks as $network) {
    $network_key = $network['code'] ?? '';
    $config = $network_registry->get_config($network_key);
    
    if ($config && $network_api_manager->is_configured($network_key)) {
        $configured_networks[] = [
            'key' => $network_key,
            'name' => $network['name'] ?? $network_key,
            'config' => $config,
        ];
    }
}

// Get Partner-Ads endpoints for reference
$partner_ads_config = $network_registry->get_config('partner_ads');
$partner_ads_endpoints = $partner_ads_config['endpoints'] ?? [];
?>

<div class="wrap aebg-network-analytics">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-chart-line" style="font-size: 32px; width: 32px; height: 32px; margin-right: 8px;"></span>
        Network Analytics Dashboard
    </h1>
    
    <?php if (empty($configured_networks)): ?>
        <div class="notice notice-warning">
            <p><strong>No networks configured.</strong> Please configure at least one network's API credentials on the <a href="<?php echo admin_url('admin.php?page=aebg_settings#networks'); ?>">Networks page</a>.</p>
        </div>
    <?php else: ?>
        
        <!-- Network Selector -->
        <div class="aebg-analytics-network-selector">
            <label for="aebg-select-network">
                <strong>Select Network:</strong>
            </label>
            <select id="aebg-select-network" class="aebg-select">
                <option value="">-- Select a Network --</option>
                <?php foreach ($configured_networks as $network): ?>
                    <option value="<?php echo esc_attr($network['key']); ?>" 
                            data-network-name="<?php echo esc_attr($network['name']); ?>">
                        <?php echo esc_html($network['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Dashboard Content (Hidden until network selected) -->
        <div id="aebg-analytics-dashboard" style="display: none;">
            
            <!-- Summary Cards -->
            <div class="aebg-analytics-summary-cards">
                <div class="aebg-summary-card" id="aebg-card-saldo">
                    <div class="card-icon">💰</div>
                    <div class="card-content">
                        <h3>Balance</h3>
                        <div class="card-value" id="card-saldo-value">--</div>
                        <div class="card-label">Current Balance & Expected Payout</div>
                    </div>
                    <button class="aebg-refresh-btn" data-endpoint="saldo_xml" title="Refresh">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
                
                <div class="aebg-summary-card" id="aebg-card-indtjening">
                    <div class="card-icon">📈</div>
                    <div class="card-content">
                        <h3>Today's Earnings</h3>
                        <div class="card-value" id="card-indtjening-value">--</div>
                        <div class="card-label">Earnings Summary</div>
                    </div>
                    <button class="aebg-refresh-btn" data-endpoint="partnerindtjening_xml" title="Refresh">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
                
                <div class="aebg-summary-card" id="aebg-card-klikoversigt">
                    <div class="card-icon">👆</div>
                    <div class="card-content">
                        <h3>Clicks (40 days)</h3>
                        <div class="card-value" id="card-klikoversigt-value">--</div>
                        <div class="card-label">Click Overview</div>
                    </div>
                    <button class="aebg-refresh-btn" data-endpoint="klikoversigt_xml" title="Refresh">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            
            <!-- Date Range Section -->
            <div class="aebg-analytics-date-range">
                <h2>Date Range Reports</h2>
                <div class="aebg-date-range-controls">
                    <label>
                        <strong>From:</strong>
                        <input type="date" id="aebg-date-from" class="aebg-date-input" />
                    </label>
                    <label>
                        <strong>To:</strong>
                        <input type="date" id="aebg-date-to" class="aebg-date-input" />
                    </label>
                    <button id="aebg-apply-date-range" class="button button-primary">Apply Date Range</button>
                    <button id="aebg-clear-date-range" class="button">Clear</button>
                </div>
            </div>
            
            <!-- Data Sections -->
            <div class="aebg-analytics-sections">
                
                <!-- Earnings by Date Range -->
                <div class="aebg-analytics-section" id="section-indtjening-dato">
                    <div class="section-header">
                        <h2>Earnings by Date Range</h2>
                        <button class="aebg-fetch-btn button" data-endpoint="partnerindtjening_dato_xml" data-requires-dates="true">
                            <span class="dashicons dashicons-download"></span> Fetch Data
                        </button>
                    </div>
                    <div class="section-content" id="content-indtjening-dato">
                        <p class="aebg-placeholder">Select a date range and click "Fetch Data" to load earnings information.</p>
                    </div>
                </div>
                
                <!-- Program Statistics -->
                <div class="aebg-analytics-section" id="section-programstat">
                    <div class="section-header">
                        <h2>Program Statistics</h2>
                        <button class="aebg-fetch-btn button" data-endpoint="programstat_xml" data-requires-dates="true">
                            <span class="dashicons dashicons-download"></span> Fetch Data
                        </button>
                    </div>
                    <div class="section-content" id="content-programstat">
                        <p class="aebg-placeholder">Select a date range and click "Fetch Data" to load program statistics.</p>
                    </div>
                </div>
                
                <!-- Sales/Leads Overview -->
                <div class="aebg-analytics-section" id="section-vissalg">
                    <div class="section-header">
                        <h2>Sales/Leads Overview</h2>
                        <div class="section-controls">
                            <button class="aebg-sync-sales-btn button button-primary" data-network="">
                                <span class="dashicons dashicons-update"></span> Sync Sales
                            </button>
                            <button class="aebg-fetch-btn button" data-endpoint="vissalg_xml" data-requires-dates="true">
                                <span class="dashicons dashicons-download"></span> Fetch from API
                            </button>
                            <button class="aebg-view-synced-sales-btn button" data-network="">
                                <span class="dashicons dashicons-list-view"></span> View Synced Sales
                            </button>
                        </div>
                    </div>
                    <div class="section-content" id="content-vissalg">
                        <div id="vissalg-stats" class="aebg-sales-stats" style="display: none;">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <span class="stat-label">Total Sales:</span>
                                    <span class="stat-value" id="stat-total-sales">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Total Revenue:</span>
                                    <span class="stat-value" id="stat-total-revenue">0.00</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Total Commission:</span>
                                    <span class="stat-value" id="stat-total-commission">0.00</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Last Sync:</span>
                                    <span class="stat-value" id="stat-sales-last-sync">Never</span>
                                </div>
                            </div>
                        </div>
                        <div id="vissalg-data">
                            <p class="aebg-placeholder">Select a date range and click "Sync Sales" to sync and store sales, or "Fetch from API" to view raw data.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Cancellations -->
                <div class="aebg-analytics-section" id="section-annulleringer">
                    <div class="section-header">
                        <h2>Cancellations</h2>
                        <div class="section-controls">
                            <button class="aebg-sync-cancellations-btn button button-primary" data-network="">
                                <span class="dashicons dashicons-update"></span> Sync Cancellations
                            </button>
                            <button class="aebg-fetch-btn button" data-endpoint="annulleringer_xml" data-requires-dates="true">
                                <span class="dashicons dashicons-download"></span> Fetch from API
                            </button>
                            <button class="aebg-view-synced-cancellations-btn button" data-network="">
                                <span class="dashicons dashicons-list-view"></span> View Synced Cancellations
                            </button>
                        </div>
                    </div>
                    <div class="section-content" id="content-annulleringer">
                        <div id="annulleringer-stats" class="aebg-cancellations-stats" style="display: none;">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <span class="stat-label">Total Cancellations:</span>
                                    <span class="stat-value" id="stat-total-cancellations">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Commission Cancelled:</span>
                                    <span class="stat-value" id="stat-commission-cancelled">0.00</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Last Sync:</span>
                                    <span class="stat-value" id="stat-cancellations-last-sync">Never</span>
                                </div>
                            </div>
                        </div>
                        <div id="annulleringer-data">
                            <p class="aebg-placeholder">Select a date range and click "Sync Cancellations" to sync and store cancellations (this will also mark corresponding sales as cancelled), or "Fetch from API" to view raw data.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Program Overview -->
                <div class="aebg-analytics-section" id="section-programoversigt">
                    <div class="section-header">
                        <h2>Program Overview</h2>
                        <div class="section-controls">
                            <label>
                                <input type="checkbox" id="aebg-godkendte-only" />
                                Only show approved programs
                            </label>
                            <button class="aebg-fetch-btn button" data-endpoint="programoversigt_xml">
                                <span class="dashicons dashicons-download"></span> Fetch Data
                            </button>
                        </div>
                    </div>
                    <div class="section-content" id="content-programoversigt">
                        <p class="aebg-placeholder">Click "Fetch Data" to load program overview.</p>
                    </div>
                </div>
                
                <!-- Latest News -->
                <div class="aebg-analytics-section" id="section-senestenyt">
                    <div class="section-header">
                        <h2>Latest News</h2>
                        <button class="aebg-fetch-btn button" data-endpoint="senestenyt_xml">
                            <span class="dashicons dashicons-download"></span> Fetch Data
                        </button>
                    </div>
                    <div class="section-content" id="content-senestenyt">
                        <p class="aebg-placeholder">Click "Fetch Data" to load latest news.</p>
                    </div>
                </div>
                
            </div>
        </div>
        
    <?php endif; ?>
</div>

<script>
// Network Analytics JavaScript
(function($) {
    'use strict';
    
    var currentNetwork = null;
    var ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
    var restUrl = '<?php echo esc_js(rest_url("aebg/v1")); ?>';
    var nonce = '<?php echo wp_create_nonce("wp_rest"); ?>';
    
    // Network selector change
    $('#aebg-select-network').on('change', function() {
        currentNetwork = $(this).val();
        if (currentNetwork) {
            $('#aebg-analytics-dashboard').show();
            // Update network data attributes on buttons
            $('.aebg-sync-clicks-btn, .aebg-view-synced-clicks-btn, .aebg-sync-sales-btn, .aebg-view-synced-sales-btn, .aebg-sync-cancellations-btn, .aebg-view-synced-cancellations-btn').attr('data-network', currentNetwork);
            loadSummaryData();
            loadClickStats();
            loadSalesStats();
            loadCancellationStats();
        } else {
            $('#aebg-analytics-dashboard').hide();
        }
    });
    
    // Load click statistics
    function loadClickStats() {
        if (!currentNetwork) return;
        
        $.ajax({
            url: restUrl + '/network-analytics/' + currentNetwork + '/clicks?limit=0',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                if (response.success && response.stats) {
                    $('#stat-total-clicks').text(response.stats.total_clicks.toLocaleString());
                    $('#stat-clicks-sales').text(response.stats.clicks_with_sales.toLocaleString());
                    $('#stat-last-sync').text(response.stats.last_sync ? new Date(response.stats.last_sync).toLocaleString() : 'Never');
                    $('#klikoversigt-stats').show();
                }
            }
        });
    }
    
    // Load sales statistics
    function loadSalesStats() {
        if (!currentNetwork) return;
        
        var dateFrom = $('#aebg-date-from').val();
        var dateTo = $('#aebg-date-to').val();
        
        var url = restUrl + '/network-analytics/' + currentNetwork + '/sales?limit=0';
        if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
        if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
        
        $.ajax({
            url: url,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                if (response.success && response.stats) {
                    $('#stat-total-sales').text(response.stats.total_sales.toLocaleString());
                    $('#stat-total-revenue').text(response.stats.total_revenue.toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    $('#stat-total-commission').text(response.stats.total_commission.toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    $('#stat-sales-last-sync').text(response.stats.last_sync ? new Date(response.stats.last_sync).toLocaleString() : 'Never');
                    $('#vissalg-stats').show();
                }
            }
        });
    }
    
    // Load summary data (saldo, indtjening, klikoversigt)
    function loadSummaryData() {
        if (!currentNetwork) return;
        
        // Load saldo
        fetchData('saldo_xml', {}, function(data) {
            displaySaldo(data);
        });
        
        // Load indtjening
        fetchData('partnerindtjening_xml', {}, function(data) {
            displayIndtjening(data);
        });
        
        // Load klikoversigt
        fetchData('klikoversigt_xml', {}, function(data) {
            displayKlikoversigt(data);
        });
    }
    
    // Fetch data from API
    function fetchData(endpoint, params, callback) {
        if (!currentNetwork) {
            showError('Please select a network first');
            return;
        }
        
        var url = restUrl + '/network-analytics/' + currentNetwork + '/' + endpoint;
        var queryParams = [];
        
        if (params.date_from && params.date_to) {
            queryParams.push('date_from=' + encodeURIComponent(params.date_from));
            queryParams.push('date_to=' + encodeURIComponent(params.date_to));
        }
        
        if (queryParams.length > 0) {
            url += '?' + queryParams.join('&');
        }
        
        // Show loading
        var sectionMap = {
            'saldo_xml': 'saldo',
            'partnerindtjening_xml': 'indtjening',
            'partnerindtjening_dato_xml': 'indtjening-dato',
            'programstat_xml': 'programstat',
            'vissalg_xml': 'vissalg',
            'annulleringer_xml': 'annulleringer',
            'klikoversigt_xml': 'klikoversigt',
            'programoversigt_xml': 'programoversigt',
            'senestenyt_xml': 'senestenyt'
        };
        var sectionId = sectionMap[endpoint] || endpoint.replace('_xml', '').replace(/_/g, '-');
        var $section = $('#section-' + sectionId);
        if ($section.length) {
            $section.find('.section-content').html('<p class="aebg-loading">Loading...</p>');
        }
        
        $.ajax({
            url: url,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                console.log('API Response for', endpoint, ':', response);
                
                if (response.success && response.data) {
                    // Log the actual data structure for debugging
                    console.log('Data structure:', JSON.stringify(response.data, null, 2));
                    
                    if (callback) {
                        callback(response.data);
                    } else {
                        displayData(endpoint, response.data);
                    }
                } else {
                    var errorMsg = response.error || response.data?.error || response.data?.message || 'Failed to fetch data';
                    console.error('API Error:', errorMsg, response);
                    showError(errorMsg);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Error fetching data';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                showError(errorMsg);
            }
        });
    }
    
    // Display saldo data
    function displaySaldo(data) {
        console.log('Displaying saldo data:', data);
        
        if (!data || typeof data !== 'object') {
            $('#card-saldo-value').html('<span class="error">Error: Invalid data</span>');
            return;
        }
        
        // Partner-Ads saldo XML structure can be:
        // Option 1: Direct fields: { saldo: "value", forventet_udbetaling: "value" }
        // Option 2: Nested: { saldo: { "@attributes": {...}, "#text": "value" } }
        // Option 3: Root element: { "@attributes": {...}, saldo: "value", forventet_udbetaling: "value" }
        
        var saldo = extractNestedValue(data, ['saldo', 'balance', 'current_balance']);
        var expected = extractNestedValue(data, ['forventet_udbetaling', 'expected_payout', 'expected', 'forventet']);
        
        console.log('Extracted saldo:', saldo, 'expected:', expected);
        
        $('#card-saldo-value').html(
            '<div class="value-main">' + formatCurrency(saldo) + '</div>' +
            '<div class="value-sub">Expected: ' + formatCurrency(expected) + '</div>'
        );
    }
    
    // Display indtjening data
    function displayIndtjening(data) {
        console.log('Displaying indtjening data:', data);
        
        if (!data || typeof data !== 'object') {
            $('#card-indtjening-value').html('<span class="error">Error: Invalid data</span>');
            return;
        }
        
        // Partner-Ads indtjening XML structure has: i_dag, i_gar, denne_uge, sidste_uge, denne_maned, sidste_maned, dette_ar, sidste_ar
        var today = extractNestedValue(data, ['i_dag', 'today', 'dag', 'i_dag_value']);
        
        console.log('Extracted today earnings:', today);
        
        $('#card-indtjening-value').html(
            '<div class="value-main">' + formatCurrency(today) + '</div>' +
            '<div class="value-sub">Today</div>'
        );
    }
    
    // Display klikoversigt data
    function displayKlikoversigt(data) {
        if (!data || typeof data !== 'object') {
            $('#card-klikoversigt-value').text('Error');
            return;
        }
        
        // Partner-Ads klikoversigt is an array of click entries
        // Count total clicks
        var total = 0;
        if (Array.isArray(data)) {
            total = data.length;
        } else if (data.klik || data.total) {
            total = parseInt(data.klik || data.total || 0);
        } else if (data.item && Array.isArray(data.item)) {
            // XML structure: <klikoversigt><item>...</item><item>...</item></klikoversigt>
            total = data.item.length;
        }
        
        $('#card-klikoversigt-value').text(total.toLocaleString());
    }
    
    // Helper function to extract value from nested object (handles XML->JSON conversion quirks)
    function extractNestedValue(obj, keys) {
        if (!obj || typeof obj !== 'object') {
            return '0';
        }
        
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var value = obj[key];
            
            if (value !== undefined && value !== null && value !== '') {
                // Handle XML->JSON conversion where value might be an object with @attributes
                if (typeof value === 'object') {
                    // Check for #text property (common in XML->JSON conversion)
                    if (value['#text'] !== undefined) {
                        return value['#text'];
                    }
                    // Check if it's an array with one element
                    if (Array.isArray(value) && value.length > 0) {
                        return value[0];
                    }
                    // If object has a toString or valueOf, try that
                    if (value.toString && value.toString() !== '[object Object]') {
                        return value.toString();
                    }
                }
                // Return the value directly
                return value;
            }
        }
        return '0';
    }
    
    // Legacy function for backward compatibility
    function extractValue(obj, keys) {
        return extractNestedValue(obj, keys);
    }
    
    // Display generic data
    function displayData(endpoint, data) {
        console.log('Displaying data for', endpoint, ':', data);
        
        // Map endpoint names to section IDs
        var sectionMap = {
            'saldo_xml': 'saldo',
            'partnerindtjening_xml': 'indtjening',
            'partnerindtjening_dato_xml': 'indtjening-dato',
            'programstat_xml': 'programstat',
            'vissalg_xml': 'vissalg',
            'annulleringer_xml': 'annulleringer',
            'klikoversigt_xml': 'klikoversigt',
            'programoversigt_xml': 'programoversigt',
            'senestenyt_xml': 'senestenyt'
        };
        
        var sectionId = sectionMap[endpoint] || endpoint.replace('_xml', '').replace(/_/g, '-');
        var $content = $('#content-' + sectionId);
        
        if (!data) {
            $content.html('<p class="aebg-no-data">No data available (null/undefined)</p>');
            return;
        }
        
        if (typeof data === 'object' && Object.keys(data).length === 0) {
            $content.html('<p class="aebg-no-data">No data available (empty object)</p>');
            return;
        }
        
        // Add debug button to show raw data
        var debugHtml = '<button class="aebg-debug-data button button-small" style="margin-bottom: 10px;">Show Raw Data</button>';
        debugHtml += '<div class="aebg-raw-data" style="display: none;"><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-height: 400px;">' + 
                     JSON.stringify(data, null, 2) + '</pre></div>';
        
        // Convert to table format
        var html = '<div class="aebg-data-table-wrapper"><table class="aebg-data-table">';
        
        // Handle XML structure where root might have a single child array
        // Example: { item: [{...}, {...}] } should be treated as array
        // Also handle cases where XML root has @attributes
        var actualData = data;
        
        // Remove @attributes if present (from XML->JSON conversion)
        if (actualData['@attributes']) {
            delete actualData['@attributes'];
        }
        
        // Check for common XML array patterns
        if (actualData.item && Array.isArray(actualData.item)) {
            actualData = actualData.item;
        } else if (actualData.klik && Array.isArray(actualData.klik)) {
            actualData = actualData.klik;
        } else if (actualData.program && Array.isArray(actualData.program)) {
            actualData = actualData.program;
        } else if (actualData.salg && Array.isArray(actualData.salg)) {
            actualData = actualData.salg;
        }
        
        data = actualData;
        
        if (Array.isArray(data)) {
            // Array of items
            if (data.length > 0) {
                // Get all unique keys from all items
                var allKeys = [];
                var keysSet = {};
                data.forEach(function(item) {
                    if (typeof item === 'object' && item !== null) {
                        Object.keys(item).forEach(function(key) {
                            if (!keysSet[key]) {
                                keysSet[key] = true;
                                allKeys.push(key);
                            }
                        });
                    }
                });
                
                if (allKeys.length > 0) {
                    // Header row
                    html += '<thead><tr>';
                    allKeys.forEach(function(key) {
                        html += '<th>' + formatHeader(key) + '</th>';
                    });
                    html += '</tr></thead><tbody>';
                    
                    // Data rows
                    data.forEach(function(item) {
                        if (typeof item === 'object' && item !== null) {
                            html += '<tr>';
                            allKeys.forEach(function(key) {
                                html += '<td>' + formatValue(item[key]) + '</td>';
                            });
                            html += '</tr>';
                        }
                    });
                    html += '</tbody>';
                } else {
                    html += '<tbody><tr><td colspan="100%">No data available</td></tr></tbody>';
                }
            } else {
                html += '<tbody><tr><td colspan="100%">No data available</td></tr></tbody>';
            }
        } else if (typeof data === 'object' && data !== null) {
            // Single object - display as key-value pairs
            html += '<tbody>';
            Object.keys(data).forEach(function(key) {
                var value = data[key];
                // Skip if value is an array (will be handled separately if needed)
                if (!Array.isArray(value)) {
                    html += '<tr>';
                    html += '<th>' + formatHeader(key) + '</th>';
                    html += '<td>' + formatValue(value) + '</td>';
                    html += '</tr>';
                }
            });
            html += '</tbody>';
        } else {
            html += '<tbody><tr><td colspan="100%">Invalid data format</td></tr></tbody>';
        }
        
        html += '</table></div>';
        $content.html(debugHtml + html);
        
        // Toggle debug data
        $content.find('.aebg-debug-data').on('click', function() {
            $(this).next('.aebg-raw-data').toggle();
        });
    }
    
    // Format currency
    function formatCurrency(value) {
        if (typeof value === 'string') {
            value = parseFloat(value.replace(/[^\d.-]/g, ''));
        }
        if (isNaN(value)) return '0.00';
        return new Intl.NumberFormat('da-DK', {
            style: 'currency',
            currency: 'DKK'
        }).format(value);
    }
    
    // Format header
    function formatHeader(key) {
        return key.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
            return l.toUpperCase();
        });
    }
    
    // Format value
    function formatValue(value) {
        if (value === null || value === undefined) return '--';
        
        // Handle XML->JSON conversion quirks
        if (typeof value === 'object') {
            // Check for #text property
            if (value['#text'] !== undefined) {
                value = value['#text'];
            } else if (Array.isArray(value) && value.length === 1) {
                value = value[0];
            } else {
                return '<pre style="font-size: 11px; max-width: 300px; overflow-x: auto;">' + JSON.stringify(value, null, 2) + '</pre>';
            }
        }
        
        // If still an object, stringify it
        if (typeof value === 'object') {
            return '<pre style="font-size: 11px; max-width: 300px; overflow-x: auto;">' + JSON.stringify(value, null, 2) + '</pre>';
        }
        
        // Check if it's a number that might be currency
        var numValue = parseFloat(String(value).replace(/[^\d.-]/g, ''));
        if (!isNaN(numValue) && numValue > 0) {
            // If it looks like currency (has decimal or is large number), format as currency
            if (String(value).includes('.') || numValue > 100) {
                return formatCurrency(value);
            }
        }
        
        return String(value);
    }
    
    // Show error
    function showError(message) {
        $('.aebg-analytics-sections .section-content').each(function() {
            if ($(this).find('.aebg-loading').length) {
                $(this).html('<p class="aebg-error">' + message + '</p>');
            }
        });
    }
    
    // Fetch button click
    $(document).on('click', '.aebg-fetch-btn', function() {
        var $btn = $(this);
        var endpoint = $btn.data('endpoint');
        var requiresDates = $btn.data('requires-dates') === true;
        
        var params = {};
        
        if (requiresDates) {
            var dateFrom = $('#aebg-date-from').val();
            var dateTo = $('#aebg-date-to').val();
            
            if (!dateFrom || !dateTo) {
                alert('Please select a date range first');
                return;
            }
            
            params.date_from = dateFrom;
            params.date_to = dateTo;
        }
        
        // Check for godkendte parameter
        if (endpoint === 'programoversigt_xml' && $('#aebg-godkendte-only').is(':checked')) {
            params.godkendte = 1;
        }
        
        $btn.prop('disabled', true).text('Loading...');
        
        fetchData(endpoint, params, function(data) {
            displayData(endpoint, data);
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Fetch Data');
        });
    });
    
    // Refresh button click
    $(document).on('click', '.aebg-refresh-btn', function() {
        var $btn = $(this);
        var endpoint = $btn.data('endpoint');
        
        $btn.addClass('spinning');
        
        fetchData(endpoint, {}, function(data) {
            if (endpoint === 'saldo_xml') {
                displaySaldo(data);
            } else if (endpoint === 'partnerindtjening_xml') {
                displayIndtjening(data);
            } else if (endpoint === 'klikoversigt_xml') {
                displayKlikoversigt(data);
            }
            $btn.removeClass('spinning');
        });
    });
    
    // Apply date range
    $('#aebg-apply-date-range').on('click', function() {
        var dateFrom = $('#aebg-date-from').val();
        var dateTo = $('#aebg-date-to').val();
        
        if (!dateFrom || !dateTo) {
            alert('Please select both start and end dates');
            return;
        }
        
        if (dateFrom > dateTo) {
            alert('Start date must be before end date');
            return;
        }
        
        // Reload all date-range sections
        $('.aebg-fetch-btn[data-requires-dates="true"]').each(function() {
            var $btn = $(this);
            var endpoint = $btn.data('endpoint');
            
            fetchData(endpoint, {
                date_from: dateFrom,
                date_to: dateTo
            }, function(data) {
                displayData(endpoint, data);
            });
        });
        
        // Show success message
        $('<div class="notice notice-success is-dismissible"><p>Date range applied. Data refreshed.</p></div>')
            .insertAfter('.aebg-analytics-date-range')
            .delay(3000)
            .fadeOut(function() { $(this).remove(); });
    });
    
    // Clear date range
    $('#aebg-clear-date-range').on('click', function() {
        $('#aebg-date-from, #aebg-date-to').val('');
        $('.aebg-fetch-btn[data-requires-dates="true"]').each(function() {
            var endpoint = $(this).data('endpoint');
            var sectionMap = {
                'partnerindtjening_dato_xml': 'indtjening-dato',
                'programstat_xml': 'programstat',
                'vissalg_xml': 'vissalg',
                'annulleringer_xml': 'annulleringer'
            };
            var sectionId = sectionMap[endpoint] || endpoint.replace('_xml', '').replace(/_/g, '-');
            $('#content-' + sectionId).html('<p class="aebg-placeholder">Select a date range and click "Fetch Data" to load data.</p>');
        });
    });
    
    // Sync clicks button
    $(document).on('click', '.aebg-sync-clicks-btn', function() {
        var $btn = $(this);
        var networkKey = $btn.data('network') || currentNetwork;
        
        if (!networkKey) {
            alert('Please select a network first');
            return;
        }
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Syncing...');
        var $messages = $('#content-klikoversigt');
        $messages.html('<p class="aebg-loading">Syncing clicks from API...</p>');
        
        $.ajax({
            url: restUrl + '/network-analytics/' + networkKey + '/sync-clicks',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                if (response.success) {
                    var stats = response.stats;
                    $messages.html(
                        '<div class="notice notice-success">' +
                        '<p><strong>Sync Complete!</strong></p>' +
                        '<ul>' +
                        '<li>Inserted: ' + stats.inserted + ' new clicks</li>' +
                        '<li>Skipped: ' + stats.skipped + ' duplicates</li>' +
                        '<li>Total processed: ' + stats.total + '</li>' +
                        '</ul>' +
                        '</div>'
                    );
                    loadClickStats();
                    // Auto-load synced clicks
                    loadSyncedClicks();
                } else {
                    $messages.html('<div class="notice notice-error"><p>' + (response.error || 'Sync failed') + '</p></div>');
                }
            },
            error: function(xhr) {
                var errorMsg = 'Error syncing clicks';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                $messages.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Clicks');
            }
        });
    });
    
    // View synced clicks button
    $(document).on('click', '.aebg-view-synced-clicks-btn', function() {
        loadSyncedClicks();
    });
    
    // Sync sales button
    $(document).on('click', '.aebg-sync-sales-btn', function() {
        var $btn = $(this);
        var networkKey = $btn.data('network') || currentNetwork;
        
        if (!networkKey) {
            alert('Please select a network first');
            return;
        }
        
        var dateFrom = $('#aebg-date-from').val();
        var dateTo = $('#aebg-date-to').val();
        
        if (!dateFrom || !dateTo) {
            alert('Please select a date range first');
            return;
        }
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Syncing...');
        var $messages = $('#vissalg-data');
        $messages.html('<p class="aebg-loading">Syncing sales from API...</p>');
        
        $.ajax({
            url: restUrl + '/network-analytics/' + networkKey + '/sync-sales',
            method: 'POST',
            data: {
                date_from: dateFrom,
                date_to: dateTo
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                if (response.success) {
                    var stats = response.stats;
                    $messages.html(
                        '<div class="notice notice-success">' +
                        '<p><strong>Sync Complete!</strong></p>' +
                        '<ul>' +
                        '<li>Inserted: ' + stats.inserted + ' new sales</li>' +
                        '<li>Skipped: ' + stats.skipped + ' duplicates</li>' +
                        '<li>Total processed: ' + stats.total + '</li>' +
                        '</ul>' +
                        '</div>'
                    );
                    loadSalesStats();
                    // Auto-load synced sales
                    loadSyncedSales();
                } else {
                    $messages.html('<div class="notice notice-error"><p>' + (response.error || 'Sync failed') + '</p></div>');
                }
            },
            error: function(xhr) {
                var errorMsg = 'Error syncing sales';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                $messages.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Sales');
            }
        });
    });
    
    // View synced sales button
    $(document).on('click', '.aebg-view-synced-sales-btn', function() {
        loadSyncedSales();
    });
    
    // Load synced sales from database
    function loadSyncedSales() {
        if (!currentNetwork) {
            alert('Please select a network first');
            return;
        }
        
        var dateFrom = $('#aebg-date-from').val();
        var dateTo = $('#aebg-date-to').val();
        
        var $content = $('#vissalg-data');
        $content.html('<p class="aebg-loading">Loading synced sales...</p>');
        
        var url = restUrl + '/network-analytics/' + currentNetwork + '/sales?limit=100';
        if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
        if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
        
        $.ajax({
            url: url,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                if (response.success && response.sales) {
                    displaySyncedSales(response.sales, response.stats);
                } else {
                    $content.html('<p class="aebg-no-data">No synced sales found. Select a date range and click "Sync Sales" to sync from API.</p>');
                }
            },
            error: function() {
                $content.html('<p class="aebg-error">Error loading synced sales</p>');
            }
        });
    }
    
    // Display synced sales in table
    function displaySyncedSales(sales, stats) {
        var $content = $('#vissalg-data');
        
        if (!sales || sales.length === 0) {
            $content.html('<p class="aebg-no-data">No synced sales found. Select a date range and click "Sync Sales" to sync from API.</p>');
            return;
        }
        
        var html = '<div class="aebg-synced-sales-info">';
        html += '<p><strong>Showing ' + sales.length + ' of ' + (stats.total_sales || sales.length) + ' synced sales</strong></p>';
        if (stats.total_revenue) {
            html += '<p>Total Revenue: ' + parseFloat(stats.total_revenue).toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + (sales[0].valuta || 'DKK') + '</p>';
        }
        if (stats.total_commission) {
            html += '<p>Total Commission: ' + parseFloat(stats.total_commission).toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + (sales[0].valuta || 'DKK') + '</p>';
        }
        html += '</div>';
        
        html += '<div class="aebg-data-table-wrapper"><table class="aebg-data-table">';
        html += '<thead><tr>';
        html += '<th>Date</th>';
        html += '<th>Time</th>';
        html += '<th>Program</th>';
        html += '<th>Order #</th>';
        html += '<th>Product #</th>';
        html += '<th>Revenue</th>';
        html += '<th>Commission</th>';
        html += '<th>Currency</th>';
        html += '</tr></thead><tbody>';
        
        sales.forEach(function(sale) {
            html += '<tr>';
            html += '<td>' + (sale.dato || '--') + '</td>';
            html += '<td>' + (sale.tidspunkt || '--') + '</td>';
            html += '<td>' + (sale.program || sale.programid || '--') + '</td>';
            html += '<td>' + (sale.ordrenr || '--') + '</td>';
            html += '<td>' + (sale.varenr || '--') + '</td>';
            html += '<td>' + (sale.omsaetning ? parseFloat(sale.omsaetning).toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '--') + '</td>';
            html += '<td>' + (sale.provision ? parseFloat(sale.provision).toLocaleString('da-DK', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '--') + '</td>';
            html += '<td>' + (sale.valuta || 'DKK') + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        $content.html(html);
    }
    
    // Load synced clicks from database
    function loadSyncedClicks() {
        if (!currentNetwork) {
            alert('Please select a network first');
            return;
        }
        
        var $content = $('#klikoversigt-data');
        $content.html('<p class="aebg-loading">Loading synced clicks...</p>');
        
        $.ajax({
            url: restUrl + '/network-analytics/' + currentNetwork + '/clicks?limit=100',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function(response) {
                if (response.success && response.clicks) {
                    displaySyncedClicks(response.clicks, response.stats);
                } else {
                    $content.html('<p class="aebg-no-data">No synced clicks found. Click "Sync Clicks" to sync from API.</p>');
                }
            },
            error: function() {
                $content.html('<p class="aebg-error">Error loading synced clicks</p>');
            }
        });
    }
    
    // Display synced clicks in table
    function displaySyncedClicks(clicks, stats) {
        var $content = $('#klikoversigt-data');
        
        if (!clicks || clicks.length === 0) {
            $content.html('<p class="aebg-no-data">No synced clicks found. Click "Sync Clicks" to sync from API.</p>');
            return;
        }
        
        var html = '<div class="aebg-synced-clicks-info">';
        html += '<p><strong>Showing ' + clicks.length + ' of ' + (stats.total_clicks || clicks.length) + ' synced clicks</strong></p>';
        html += '</div>';
        
        html += '<div class="aebg-data-table-wrapper"><table class="aebg-data-table">';
        html += '<thead><tr>';
        html += '<th>Date</th>';
        html += '<th>Time</th>';
        html += '<th>Program ID</th>';
        html += '<th>Program Name</th>';
        html += '<th>Sale</th>';
        html += '<th>URL</th>';
        html += '</tr></thead><tbody>';
        
        clicks.forEach(function(click) {
            html += '<tr>';
            html += '<td>' + (click.dato || '--') + '</td>';
            html += '<td>' + (click.tid || '--') + '</td>';
            html += '<td>' + (click.programid || '--') + '</td>';
            html += '<td>' + (click.programnavn || '--') + '</td>';
            html += '<td>' + (click.salg || '--') + '</td>';
            html += '<td>' + (click.url ? '<a href="' + click.url + '" target="_blank">View</a>' : '--') + '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table></div>';
        $content.html(html);
    }
    
    // Set default date range (last 30 days)
    $(document).ready(function() {
        var today = new Date();
        var lastMonth = new Date();
        lastMonth.setDate(today.getDate() - 30);
        
        $('#aebg-date-to').val(today.toISOString().split('T')[0]);
        $('#aebg-date-from').val(lastMonth.toISOString().split('T')[0]);
    });
    
})(jQuery);
</script>

<style>
.aebg-network-analytics {
    max-width: 1400px;
}

.aebg-analytics-network-selector {
    margin: 20px 0;
    padding: 15px;
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.aebg-analytics-network-selector label {
    margin-right: 10px;
}

.aebg-analytics-network-selector select {
    min-width: 250px;
    padding: 5px 10px;
}

.aebg-analytics-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.aebg-summary-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 15px;
    position: relative;
}

.aebg-summary-card .card-icon {
    font-size: 48px;
    line-height: 1;
}

.aebg-summary-card .card-content {
    flex: 1;
}

.aebg-summary-card .card-content h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
    color: #646970;
    text-transform: uppercase;
}

.aebg-summary-card .card-value {
    font-size: 28px;
    font-weight: 600;
    color: #1d2327;
    margin-bottom: 5px;
}

.aebg-summary-card .card-value .value-main {
    font-size: 32px;
}

.aebg-summary-card .card-value .value-sub {
    font-size: 14px;
    color: #646970;
    font-weight: normal;
}

.aebg-summary-card .card-label {
    font-size: 12px;
    color: #646970;
}

.aebg-refresh-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 5px;
    color: #646970;
}

.aebg-refresh-btn:hover {
    color: #2271b1;
}

.aebg-refresh-btn .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

.aebg-refresh-btn.spinning .dashicons {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.aebg-analytics-date-range {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    margin: 20px 0;
    border-radius: 4px;
}

.aebg-analytics-date-range h2 {
    margin-top: 0;
}

.aebg-date-range-controls {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.aebg-date-range-controls label {
    display: flex;
    align-items: center;
    gap: 8px;
}

.aebg-date-input {
    padding: 5px 10px;
    border: 1px solid #8c8f94;
    border-radius: 3px;
}

.aebg-analytics-sections {
    margin-top: 30px;
}

.aebg-analytics-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.aebg-analytics-section .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f1;
}

.aebg-analytics-section .section-header h2 {
    margin: 0;
    font-size: 18px;
}

.aebg-analytics-section .section-controls {
    display: flex;
    gap: 15px;
    align-items: center;
}

.aebg-fetch-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.aebg-fetch-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.aebg-section-content {
    min-height: 100px;
}

.aebg-placeholder,
.aebg-loading,
.aebg-no-data,
.aebg-error {
    padding: 20px;
    text-align: center;
    color: #646970;
    font-style: italic;
}

.aebg-error {
    color: #d63638;
    font-style: normal;
}

.aebg-data-table-wrapper {
    overflow-x: auto;
}

.aebg-data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.aebg-data-table th,
.aebg-data-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #f0f0f1;
}

.aebg-data-table th {
    background: #f6f7f7;
    font-weight: 600;
    color: #1d2327;
}

.aebg-data-table tbody tr:hover {
    background: #f6f7f7;
}

.aebg-data-table pre {
    margin: 0;
    font-size: 12px;
    max-width: 500px;
    overflow-x: auto;
}

.aebg-click-stats,
.aebg-sales-stats,
.aebg-cancellations-stats {
    background: #f6f7f7;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.aebg-click-stats .stats-grid,
.aebg-sales-stats .stats-grid,
.aebg-cancellations-stats .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.aebg-click-stats .stat-item,
.aebg-sales-stats .stat-item,
.aebg-cancellations-stats .stat-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.aebg-click-stats .stat-label,
.aebg-sales-stats .stat-label,
.aebg-cancellations-stats .stat-label {
    font-size: 12px;
    color: #646970;
    text-transform: uppercase;
}

.aebg-click-stats .stat-value,
.aebg-sales-stats .stat-value,
.aebg-cancellations-stats .stat-value {
    font-size: 20px;
    font-weight: 600;
    color: #1d2327;
}

.aebg-synced-clicks-info,
.aebg-synced-sales-info,
.aebg-synced-cancellations-info {
    margin-bottom: 15px;
    padding: 10px;
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
}

.aebg-section-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.dashicons.spinning {
    animation: spin 1s linear infinite;
}
</style>

