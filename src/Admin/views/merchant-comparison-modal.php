<?php
/**
 * Merchant Comparison Modal Template
 * 
 * This template is included in the Meta_Box class to provide
 * the merchant comparison modal functionality.
 * 
 * @package AEBG\\Admin\\Views
 */
?>
<div id="aebg-merchant-comparison-modal" class="aebg-modal">
    <div class="aebg-modal-overlay"></div>
    <div class="aebg-modal-container">
        <div class="aebg-modal-header">
            <h3>
                <span class="aebg-icon">🏪</span>
                Merchant Price Comparison
            </h3>
            <button type="button" class="aebg-modal-close">&times;</button>
        </div>
        
        <div class="aebg-modal-body">
            <!-- Product Info Section -->
            <div class="aebg-product-info">
                <div class="aebg-product-image">
                    <img src="" alt="Product Image" id="modal-product-image">
                </div>
                <div class="aebg-product-details">
                    <h4 id="modal-product-name"></h4>
                    <p id="modal-product-description"></p>
                    <div class="aebg-product-meta">
                        <span id="modal-product-price"></span>
                        <span id="modal-product-brand"></span>
                        <span id="modal-product-rating"></span>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter Section -->
            <div class="aebg-search-section">
                <div class="aebg-search-header">
                    <h4>
                        <span class="aebg-icon">🔍</span>
                        Search Products
                    </h4>
                    <button type="button" class="aebg-toggle-search" id="modal-toggle-search">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                        Show Search
                    </button>
                </div>
                
                <div class="aebg-search-filters" id="modal-search-filters" style="display: none;">
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="modal-search-name">Product Name</label>
                            <input type="text" id="modal-search-name" placeholder="Enter product name..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-brand">Brand</label>
                            <input type="text" id="modal-search-brand" placeholder="Enter brand name..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-currency">Currency</label>
                            <select id="modal-search-currency">
                                <option value="">All Currencies</option>
                                <option value="DKK">DKK (Danish Krone)</option>
                                <option value="USD">USD (US Dollar)</option>
                                <option value="EUR">EUR (Euro)</option>
                                <option value="GBP">GBP (British Pound)</option>
                                <option value="SEK">SEK (Swedish Krona)</option>
                                <option value="NOK">NOK (Norwegian Krone)</option>
                                <option value="CHF">CHF (Swiss Franc)</option>
                                <option value="PLN">PLN (Polish Złoty)</option>
                                <option value="CZK">CZK (Czech Koruna)</option>
                                <option value="HUF">HUF (Hungarian Forint)</option>
                                <option value="RON">RON (Romanian Leu)</option>
                                <option value="BGN">BGN (Bulgarian Lev)</option>
                                <option value="HRK">HRK (Croatian Kuna)</option>
                                <option value="RSD">RSD (Serbian Dinar)</option>
                                <option value="UAH">UAH (Ukrainian Hryvnia)</option>
                                <option value="RUB">RUB (Russian Ruble)</option>
                                <option value="TRY">TRY (Turkish Lira)</option>
                                <option value="ILS">ILS (Israeli Shekel)</option>
                                <option value="ZAR">ZAR (South African Rand)</option>
                                <option value="BRL">BRL (Brazilian Real)</option>
                                <option value="MXN">MXN (Mexican Peso)</option>
                                <option value="CAD">CAD (Canadian Dollar)</option>
                                <option value="AUD">AUD (Australian Dollar)</option>
                                <option value="NZD">NZD (New Zealand Dollar)</option>
                                <option value="JPY">JPY (Japanese Yen)</option>
                                <option value="CNY">CNY (Chinese Yuan)</option>
                                <option value="INR">INR (Indian Rupee)</option>
                                <option value="KRW">KRW (South Korean Won)</option>
                                <option value="SGD">SGD (Singapore Dollar)</option>
                                <option value="HKD">HKD (Hong Kong Dollar)</option>
                                <option value="THB">THB (Thai Baht)</option>
                                <option value="MYR">MYR (Malaysian Ringgit)</option>
                                <option value="IDR">IDR (Indonesian Rupiah)</option>
                                <option value="PHP">PHP (Philippine Peso)</option>
                                <option value="VND">VND (Vietnamese Dong)</option>
                            </select>
                        </div>
                    </div>
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="modal-search-networks">Networks</label>
                            <select id="modal-search-networks" multiple>
                                <option value="">Loading networks...</option>
                            </select>
                            <div class="aebg-networks-loading" id="modal-networks-loading" style="display: none;">
                                <span class="spinner is-active"></span> Loading networks...
                            </div>
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-category">Category</label>
                            <input type="text" id="modal-search-category" placeholder="Enter category..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-rating">Min Rating</label>
                            <select id="modal-search-rating">
                                <option value="">Any Rating</option>
                                <option value="1">1+ Stars</option>
                                <option value="2">2+ Stars</option>
                                <option value="3">3+ Stars</option>
                                <option value="4">4+ Stars</option>
                                <option value="5">5 Stars Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="modal-search-min-price">Min Price</label>
                            <input type="number" id="modal-search-min-price" placeholder="0" step="0.01" />
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-max-price">Max Price</label>
                            <input type="number" id="modal-search-max-price" placeholder="999999" step="0.01" />
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-limit">Results per page</label>
                            <select id="modal-search-limit">
                                <option value="20">20</option>
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <div class="aebg-search-field checkbox-field">
                            <input type="checkbox" id="modal-search-has-image" />
                            <label for="modal-search-has-image">Has Image Only</label>
                        </div>
                        <div class="aebg-search-field">
                            <label for="modal-search-sort">Sort By</label>
                            <select id="modal-search-sort">
                                <option value="relevance">Relevance</option>
                                <option value="price_asc">Price: Low to High</option>
                                <option value="price_desc">Price: High to Low</option>
                                <option value="rating_desc">Rating: High to Low</option>
                                <option value="name_asc">Name: A to Z</option>
                            </select>
                        </div>
                        <div class="aebg-search-field checkbox-field">
                            <input type="checkbox" id="modal-search-in-stock" />
                            <label for="modal-search-in-stock">In Stock Only</label>
                        </div>
                    </div>
                    <div class="aebg-search-actions">
                        <button type="button" class="aebg-btn-search" id="modal-search-products">
                            <span class="dashicons dashicons-search"></span>
                            Search Products
                        </button>
                        <button type="button" class="aebg-btn-clear" id="modal-clear-search">
                            <span class="dashicons dashicons-dismiss"></span>
                            Clear
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Search Results Section -->
            <div class="aebg-search-results-section" id="modal-search-results-section" style="display: none;">
                <div class="aebg-search-results-header">
                    <h4>
                        <span class="aebg-icon">📋</span>
                        Search Results
                        <span class="aebg-results-count" id="modal-results-count"></span>
                    </h4>
                    <div class="aebg-results-actions">
                        <button type="button" class="aebg-btn-add-all" id="modal-add-all-products">
                            <span class="dashicons dashicons-plus"></span>
                            Add All to Comparison
                        </button>
                    </div>
                </div>
                
                <div class="aebg-search-results" id="modal-search-results">
                    <!-- Search results will be loaded here -->
                </div>
                
                <div class="aebg-pagination" id="modal-search-pagination">
                    <!-- Pagination will be loaded here -->
                </div>
            </div>
            
            <!-- Comparison Table Section -->
            <div class="aebg-comparison-section">
                <div class="aebg-comparison-header">
                    <h4>
                        <span class="aebg-icon">📊</span>
                        Price Comparison
                        <span class="aebg-comparison-count" id="comparison-count"></span>
                    </h4>
                    <div class="aebg-comparison-actions">
                        <button type="button" class="aebg-btn-remove-all" id="remove-all-products">
                            <span class="dashicons dashicons-trash"></span>
                            Remove All
                        </button>
                    </div>
                </div>
                
                <div class="aebg-comparison-table-container">
                    <table class="aebg-comparison-table">
                        <thead>
                            <tr>
                                <th class="aebg-th-drag"></th>
                                <th>Merchant</th>
                                <th>Product Name</th>
                                <th>Price</th>
                                <th>Network</th>
                                <th>Rating</th>
                                <th>Availability</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="comparison-table-body">
                            <!-- Comparison data will be loaded here by JavaScript -->
                            <!-- PHP pre-population removed to prevent conflicts with JavaScript updates -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="aebg-modal-footer">
            <div class="aebg-modal-actions">
                <button type="button" class="aebg-btn-primary" id="save-comparison">
                    <span class="dashicons dashicons-yes"></span>
                    Save Comparison
                </button>
                <button type="button" class="aebg-btn-secondary" id="cancel-comparison">
                    <span class="dashicons dashicons-no"></span>
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div> 