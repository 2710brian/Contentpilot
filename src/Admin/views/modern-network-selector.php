<?php
/**
 * Modern Network Selector Interface
 * 
 * This file provides a modern, user-friendly interface for selecting networks
 * instead of the old multi-select dropdown.
 */
?>

<div class="aebg-modern-network-selector">
    <!-- Search Bar -->
    <div class="aebg-network-search">
        <div class="aebg-search-input-wrapper">
            <input type="text" 
                   id="network-search-input" 
                   class="aebg-search-input" 
                   placeholder="Search networks by name, country, or code..."
                   autocomplete="off">
            <div class="aebg-search-icon">🔍</div>
        </div>
    </div>

    <!-- Network Selection Tabs -->
    <div class="aebg-network-tabs">
        <button class="aebg-tab-button active" data-tab="popular">
            <span class="tab-icon">🌟</span>
            <span class="tab-text">Popular</span>
        </button>
        <button class="aebg-tab-button" data-tab="us-uk">
            <span class="tab-icon">🇺🇸🇬🇧</span>
            <span class="tab-text">US/UK</span>
        </button>
        <button class="aebg-tab-button" data-tab="europe">
            <span class="tab-icon">🇪🇺</span>
            <span class="tab-text">Europe</span>
        </button>
        <button class="aebg-tab-button" data-tab="scandinavia">
            <span class="tab-icon">🇩🇰🇸🇪🇳🇴</span>
            <span class="tab-text">Scandinavia</span>
        </button>
        <button class="aebg-tab-button" data-tab="amazon">
            <span class="tab-icon">📦</span>
            <span class="tab-text">Amazon</span>
        </button>
        <button class="aebg-tab-button" data-tab="all">
            <span class="tab-icon">🌍</span>
            <span class="tab-text">All</span>
        </button>
    </div>

    <!-- Network Content Areas -->
    <div class="aebg-network-content">
        <!-- Popular Networks Tab -->
        <div class="aebg-tab-content active" id="tab-popular">
            <div class="aebg-networks-grid">
                <div class="aebg-network-item" data-network="amazon_us">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_us" value="amazon_us">
                        <label for="network_amazon_us"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon US</div>
                        <div class="aebg-network-country">🇺🇸 United States</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="amazon_uk">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_uk" value="amazon_uk">
                        <label for="network_amazon_uk"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon UK</div>
                        <div class="aebg-network-country">🇬🇧 United Kingdom</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="partner_ads">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_partner_ads" value="partner_ads">
                        <label for="network_partner_ads"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Partner-ads</div>
                        <div class="aebg-network-country">🇩🇰 Denmark</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="timeone">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_timeone" value="timeone">
                        <label for="network_timeone"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">TimeOne</div>
                        <div class="aebg-network-country">🇩🇰 Denmark</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="adrecord">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_adrecord" value="adrecord">
                        <label for="network_adrecord"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Adrecord</div>
                        <div class="aebg-network-country">🇩🇰 Denmark</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="belboon">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_belboon" value="belboon">
                        <label for="network_belboon"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Belboon</div>
                        <div class="aebg-network-country">🇩🇰 Denmark</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- US/UK Networks Tab -->
        <div class="aebg-tab-content" id="tab-us-uk">
            <div class="aebg-networks-grid">
                <div class="aebg-network-item" data-network="avantlink_us">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_avantlink_us" value="avantlink_us">
                        <label for="network_avantlink_us"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">AvantLink US</div>
                        <div class="aebg-network-country">🇺🇸 United States</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="avantlink_uk">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_avantlink_uk" value="avantlink_uk">
                        <label for="network_avantlink_uk"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">AvantLink UK</div>
                        <div class="aebg-network-country">🇬🇧 United Kingdom</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="linkshare_us">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_linkshare_us" value="linkshare_us">
                        <label for="network_linkshare_us"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">LinkShare US</div>
                        <div class="aebg-network-country">🇺🇸 United States</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="cj_us">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_cj_us" value="cj_us">
                        <label for="network_cj_us"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Commission Junction US</div>
                        <div class="aebg-network-country">🇺🇸 United States</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="shareasale">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_shareasale" value="shareasale">
                        <label for="network_shareasale"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">ShareASale</div>
                        <div class="aebg-network-country">🇺🇸 United States</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="flexoffers">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_flexoffers" value="flexoffers">
                        <label for="network_flexoffers"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">FlexOffers</div>
                        <div class="aebg-network-country">🇺🇸 United States</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Europe Networks Tab -->
        <div class="aebg-tab-content" id="tab-europe">
            <div class="aebg-networks-grid">
                <div class="aebg-network-item" data-network="awin">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_awin" value="awin">
                        <label for="network_awin"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">AWIN</div>
                        <div class="aebg-network-country">🇪🇺 Europe</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="tradetracker">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_tradetracker" value="tradetracker">
                        <label for="network_tradetracker"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">TradeTracker</div>
                        <div class="aebg-network-country">🇪🇺 Europe</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="affiliate_window">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_affiliate_window" value="affiliate_window">
                        <label for="network_affiliate_window"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Affiliate Window</div>
                        <div class="aebg-network-country">🇪🇺 Europe</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scandinavia Networks Tab -->
        <div class="aebg-tab-content" id="tab-scandinavia">
            <div class="aebg-networks-grid">
                <div class="aebg-network-item" data-network="dk_elgiganten">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_dk_elgiganten" value="dk_elgiganten">
                        <label for="network_dk_elgiganten"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Elgiganten</div>
                        <div class="aebg-network-country">🇩🇰 Denmark</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="dk_power">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_dk_power" value="dk_power">
                        <label for="network_dk_power"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Power</div>
                        <div class="aebg-network-country">🇩🇰 Denmark</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="se_amazon">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_se_amazon" value="se_amazon">
                        <label for="network_se_amazon"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon Sweden</div>
                        <div class="aebg-network-country">🇸🇪 Sweden</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="no_amazon">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_no_amazon" value="no_amazon">
                        <label for="network_no_amazon"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon Norway</div>
                        <div class="aebg-network-country">🇳🇴 Norway</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Amazon Networks Tab -->
        <div class="aebg-tab-content" id="tab-amazon">
            <div class="aebg-networks-grid">
                <div class="aebg-network-item" data-network="amazon_de">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_de" value="amazon_de">
                        <label for="network_amazon_de"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon Germany</div>
                        <div class="aebg-network-country">🇩🇪 Germany</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="amazon_fr">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_fr" value="amazon_fr">
                        <label for="network_amazon_fr"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon France</div>
                        <div class="aebg-network-country">🇫🇷 France</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="amazon_it">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_it" value="amazon_it">
                        <label for="network_amazon_it"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon Italy</div>
                        <div class="aebg-network-country">🇮🇹 Italy</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="amazon_es">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_es" value="amazon_es">
                        <label for="network_amazon_es"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon Spain</div>
                        <div class="aebg-network-country">🇪🇸 Spain</div>
                    </div>
                </div>
                
                <div class="aebg-network-item" data-network="amazon_ca">
                    <div class="aebg-network-checkbox">
                        <input type="checkbox" id="network_amazon_ca" value="amazon_ca">
                        <label for="network_amazon_ca"></label>
                    </div>
                    <div class="aebg-network-info">
                        <div class="aebg-network-name">Amazon Canada</div>
                        <div class="aebg-network-country">🇨🇦 Canada</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All Networks Tab -->
        <div class="aebg-tab-content" id="tab-all">
            <div class="aebg-networks-grid" id="all-networks-grid">
                <!-- This will be populated dynamically with all networks -->
            </div>
        </div>
    </div>

    <!-- Selected Networks Display -->
    <div class="aebg-selected-networks">
        <div class="aebg-selected-header">
            <h4>✅ Selected Networks (<span id="selected-count">0</span>)</h4>
            <button type="button" id="clear-all-networks" class="aebg-clear-all-btn">Clear All</button>
        </div>
        <div class="aebg-selected-list" id="selected-networks-list">
            <div class="aebg-no-selections">No networks selected</div>
        </div>
    </div>

    <!-- Hidden input to store selected networks -->
    <input type="hidden" id="selected-networks-input" name="networks" value="">
</div>

<!-- Modern Network Selector Styles -->
<style>
.aebg-modern-network-selector {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin: 15px 0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Search Bar */
.aebg-network-search {
    margin-bottom: 20px;
}

.aebg-search-input-wrapper {
    position: relative;
    max-width: 400px;
}

.aebg-search-input {
    width: 100%;
    padding: 12px 40px 12px 16px;
    border: 2px solid #e1e5e9;
    border-radius: 25px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.aebg-search-input:focus {
    outline: none;
    border-color: #0073aa;
    box-shadow: 0 0 0 3px rgba(0,115,170,0.1);
}

.aebg-search-icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #666;
    font-size: 16px;
}

/* Network Tabs */
.aebg-network-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.aebg-tab-button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border: 1px solid #ddd;
    background: #f8f9fa;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    font-weight: 500;
}

.aebg-tab-button:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.aebg-tab-button.active {
    background: #0073aa;
    color: white;
    border-color: #0073aa;
}

.tab-icon {
    font-size: 16px;
}

/* Network Content */
.aebg-network-content {
    margin-bottom: 20px;
}

.aebg-tab-content {
    display: none;
}

.aebg-tab-content.active {
    display: block;
}

.aebg-networks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

/* Network Items */
.aebg-network-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border: 1px solid #e1e5e9;
    border-radius: 8px;
    background: #f8f9fa;
    transition: all 0.3s ease;
    cursor: pointer;
}

.aebg-network-item:hover {
    background: #e9ecef;
    border-color: #adb5bd;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.aebg-network-item.selected {
    background: #d4edda;
    border-color: #28a745;
}

/* Checkbox Styling */
.aebg-network-checkbox {
    position: relative;
}

.aebg-network-checkbox input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    cursor: pointer;
    height: 0;
    width: 0;
}

.aebg-network-checkbox label {
    position: relative;
    display: inline-block;
    width: 20px;
    height: 20px;
    background: #fff;
    border: 2px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.aebg-network-checkbox input[type="checkbox"]:checked + label {
    background: #0073aa;
    border-color: #0073aa;
}

.aebg-network-checkbox input[type="checkbox"]:checked + label:after {
    content: '✓';
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 14px;
    font-weight: bold;
}

/* Network Info */
.aebg-network-info {
    flex: 1;
}

.aebg-network-name {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
}

.aebg-network-country {
    font-size: 12px;
    color: #666;
}

/* Selected Networks */
.aebg-selected-networks {
    border-top: 1px solid #e1e5e9;
    padding-top: 20px;
}

.aebg-selected-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.aebg-selected-header h4 {
    margin: 0;
    color: #333;
}

.aebg-clear-all-btn {
    padding: 6px 12px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    transition: background 0.3s ease;
}

.aebg-clear-all-btn:hover {
    background: #c82333;
}

.aebg-selected-list {
    min-height: 40px;
}

.aebg-no-selections {
    color: #666;
    font-style: italic;
    text-align: center;
    padding: 20px;
}

.aebg-selected-network {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #d4edda;
    color: #155724;
    padding: 6px 12px;
    border-radius: 20px;
    margin: 4px;
    font-size: 12px;
    font-weight: 500;
}

.aebg-selected-network .remove-network {
    background: none;
    border: none;
    color: #155724;
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    margin-left: 4px;
}

.aebg-selected-network .remove-network:hover {
    color: #721c24;
}

/* Responsive Design */
@media (max-width: 768px) {
    .aebg-network-tabs {
        flex-direction: column;
    }
    
    .aebg-tab-button {
        justify-content: center;
    }
    
    .aebg-networks-grid {
        grid-template-columns: 1fr;
    }
    
    .aebg-selected-header {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
    }
}
</style> 