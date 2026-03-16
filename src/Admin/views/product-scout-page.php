<div class="aebg-generator-container">
    <!-- Enhanced Header Section -->
    <div class="aebg-generator-header">
        <div class="aebg-generator-title">
            <h1>
                <span class="aebg-icon-large">🔍</span>
                Product Scout
            </h1>
            <p>Discover the perfect products with our advanced search engine. Leverage the Datafeedr API to find high-quality, relevant products across thousands of merchants and networks.</p>
        </div>
        <div class="aebg-generator-actions">
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-test-connection">
                <span class="aebg-icon">🔗</span>
                Test Connection
            </button>
            <button type="button" class="aebg-btn aebg-btn-outline" id="aebg-clear-results">
                <span class="aebg-icon">🗑️</span>
                Clear Results
            </button>
        </div>
    </div>

    <!-- Enhanced Connection Status -->
    <div class="aebg-connection-status" id="aebg-connection-status">
        <div class="aebg-status-indicator" id="aebg-status-indicator"></div>
        <div class="aebg-status-content">
            <span id="aebg-status-text">Checking Datafeedr connection...</span>
            <span id="aebg-status-details" class="aebg-status-details">Verifying API credentials and network connectivity</span>
        </div>
    </div>

    <!-- Enhanced Search Configuration Grid -->
    <div class="aebg-generator-grid">
        <!-- Enhanced Search Configuration Card -->
        <div class="aebg-generator-card aebg-card-primary">
            <div class="aebg-card-header">
                <h2>
                    <span class="aebg-icon">🔍</span>
                    Search Configuration
                </h2>
                <div class="aebg-card-badge">Required</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label for="aebg-search-query">
                        <span class="aebg-icon">📝</span>
                        Search Queries
                    </label>
                    <textarea 
                        name="aebg_search_query" 
                        id="aebg-search-query" 
                        class="aebg-textarea aebg-textarea-enhanced" 
                        rows="5" 
                        placeholder="Enter your search terms (one per line):&#10;&#10;🎮 gaming headset wireless bluetooth&#10;📱 smartphone android 5g&#10;💻 laptop gaming 16gb ram&#10;🏃‍♂️ running shoes nike&#10;📚 books self improvement"></textarea>
                    <div class="aebg-input-counter">
                        <span id="aebg-query-counter">0</span> queries entered
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💡</span>
                        Enter one search query per line. Each query will be processed separately to find the best matching products.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-results-limit">
                        <span class="aebg-icon">📊</span>
                        Results per Query
                    </label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_results_limit" 
                            id="aebg-results-limit" 
                            class="aebg-slider aebg-slider-enhanced" 
                            min="10" 
                            max="200" 
                            value="50"
                        >
                        <div class="aebg-slider-labels">
                            <span class="aebg-slider-min">10</span>
                            <span class="aebg-slider-value" id="aebg-results-limit-value">50</span>
                            <span class="aebg-slider-max">200</span>
                        </div>
                    </div>
                    <div class="aebg-quick-results">
                        <button type="button" class="aebg-quick-result-btn" data-value="25">25</button>
                        <button type="button" class="aebg-quick-result-btn active" data-value="50">50</button>
                        <button type="button" class="aebg-quick-result-btn" data-value="100">100</button>
                        <button type="button" class="aebg-quick-result-btn" data-value="200">200</button>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">⚡</span>
                        Higher limits provide more options but may take longer to process.
                    </p>
                    <div class="aebg-notice aebg-notice-info">
                        <span class="aebg-icon">ℹ️</span>
                        <strong>API Limit:</strong> Datafeedr API returns maximum 100 products per request. For more results, use pagination or export.
                    </div>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-sort-by">
                        <span class="aebg-icon">📈</span>
                        Sort Results By
                    </label>
                    <select name="aebg_sort_by" id="aebg-sort-by" class="aebg-select aebg-select-enhanced">
                        <option value="relevance">🎯 Relevance (Best Match)</option>
                        <option value="price_low">💰 Price: Low to High</option>
                        <option value="price_high">💰 Price: High to Low</option>
                        <option value="rating">⭐ Customer Rating</option>
                        <option value="reviews">📝 Number of Reviews</option>
                        <option value="newest">🆕 Newest First</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎯</span>
                        Choose the primary sorting method for your search results.
                    </p>
                </div>
            </div>
        </div>

        <!-- Enhanced Filter Options Card -->
        <div class="aebg-generator-card aebg-card-secondary">
            <div class="aebg-card-header">
                <h2>
                    <span class="aebg-icon">🔧</span>
                    Filter Options
                </h2>
                <div class="aebg-card-badge optional">Optional</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-row">
                    <div class="aebg-form-group aebg-form-group-half">
                        <label for="aebg-min-price">
                            <span class="aebg-icon">💵</span>
                            Min Price
                        </label>
                        <div class="aebg-input-group">
                            <span class="aebg-input-prefix" id="aebg-min-price-prefix">$</span>
                            <input 
                                type="number" 
                                name="aebg_min_price" 
                                id="aebg-min-price" 
                                class="aebg-input aebg-input-enhanced" 
                                placeholder="0.00"
                                min="0"
                                step="0.01"
                            >
                        </div>
                    </div>
                    <div class="aebg-form-group aebg-form-group-half">
                        <label for="aebg-max-price">
                            <span class="aebg-icon">💵</span>
                            Max Price
                        </label>
                        <div class="aebg-input-group">
                            <span class="aebg-input-prefix" id="aebg-max-price-prefix">$</span>
                            <input 
                                type="number" 
                                name="aebg_max_price" 
                                id="aebg-max-price" 
                                class="aebg-input aebg-input-enhanced" 
                                placeholder="No limit"
                                min="0"
                                step="0.01"
                            >
                        </div>
                    </div>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-min-rating">
                        <span class="aebg-icon">⭐</span>
                        Minimum Rating
                    </label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_min_rating" 
                            id="aebg-min-rating" 
                            class="aebg-slider aebg-slider-enhanced" 
                            min="0" 
                            max="5" 
                            step="0.1" 
                            value="0"
                        >
                        <div class="aebg-slider-labels">
                            <span class="aebg-slider-min">0</span>
                            <span class="aebg-slider-value" id="aebg-min-rating-value">0</span>
                            <span class="aebg-slider-max">5</span>
                        </div>
                    </div>
                    <div class="aebg-rating-display">
                        <span class="aebg-rating-stars" id="aebg-rating-display">☆☆☆☆☆</span>
                        <span class="aebg-rating-text">and above</span>
                    </div>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-currency">
                        <span class="aebg-icon">💰</span>
                        Currency
                    </label>
                    <select name="aebg_currency" id="aebg-currency" class="aebg-select aebg-select-enhanced">
                        <option value="DKK" selected>kr DKK (Danish Krone)</option>
                        <option value="SEK">kr SEK (Swedish Krona)</option>
                        <option value="NOK">kr NOK (Norwegian Krone)</option>
                        <option value="EUR">€ EUR (Euro)</option>
                        <option value="USD">💵 USD (US Dollar)</option>
                        <option value="GBP">£ GBP (British Pound)</option>
                        <option value="CAD">C$ CAD (Canadian Dollar)</option>
                        <option value="AUD">A$ AUD (Australian Dollar)</option>
                        <option value="JPY">¥ JPY (Japanese Yen)</option>
                        <option value="CHF">CHF (Swiss Franc)</option>
                        <option value="CNY">¥ CNY (Chinese Yuan)</option>
                        <option value="INR">₹ INR (Indian Rupee)</option>
                        <option value="BRL">R$ BRL (Brazilian Real)</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💱</span>
                        Select the currency for price filtering and display.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-country">
                        <span class="aebg-icon">🌍</span>
                        Country
                    </label>
                    <select name="aebg_country" id="aebg-country" class="aebg-select aebg-select-enhanced">
                        <option value="DK" selected>🇩🇰 Denmark</option>
                        <option value="SE">🇸🇪 Sweden</option>
                        <option value="NO">🇳🇴 Norway</option>
                        <option value="FI">🇫🇮 Finland</option>
                        <option value="DE">🇩🇪 Germany</option>
                        <option value="NL">🇳🇱 Netherlands</option>
                        <option value="GB">🇬🇧 United Kingdom</option>
                        <option value="US">🇺🇸 United States</option>
                        <option value="CA">🇨🇦 Canada</option>
                        <option value="AU">🇦🇺 Australia</option>
                        <option value="FR">🇫🇷 France</option>
                        <option value="IT">🇮🇹 Italy</option>
                        <option value="ES">🇪🇸 Spain</option>
                        <option value="PL">🇵🇱 Poland</option>
                        <option value="AT">🇦🇹 Austria</option>
                        <option value="BE">🇧🇪 Belgium</option>
                        <option value="CH">🇨🇭 Switzerland</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📍</span>
                        Select the target country for product availability and shipping.
                    </p>
                    <div class="aebg-notice aebg-notice-info">
                        <span class="aebg-icon">ℹ️</span>
                        <strong>Filtering:</strong> Results are filtered by currency (DKK) only. Network name filtering has been removed to include all Danish merchants.
                    </div>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-category">
                        <span class="aebg-icon">📂</span>
                        Product Category (Optional)
                    </label>
                    <input 
                        type="text" 
                        name="aebg_category" 
                        id="aebg-category" 
                        class="aebg-input aebg-input-enhanced" 
                        placeholder="e.g., Electronics, Home & Garden, Fashion"
                    >
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎯</span>
                        Narrow down results by specific product category or subcategory.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_has_image" id="aebg-has-image" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🖼️</span>
                            Has product image
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📸</span>
                        Only show products that have a product image available.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_in_stock_only" id="aebg-in-stock-only" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">📦</span>
                            In stock only
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">✅</span>
                        Only show products that are currently available for purchase.
                    </p>
                </div>
            </div>
        </div>

        <!-- Enhanced Export Options Card -->
        <div class="aebg-generator-card aebg-card-tertiary">
            <div class="aebg-card-header">
                <h2>
                    <span class="aebg-icon">📤</span>
                    Export Options
                </h2>
                <div class="aebg-card-badge optional">Optional</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label for="aebg-export-format">
                        <span class="aebg-icon">📄</span>
                        Export Format
                    </label>
                    <select name="aebg_export_format" id="aebg-export-format" class="aebg-select aebg-select-enhanced">
                        <option value="json">📄 JSON (Structured Data)</option>
                        <option value="csv">📊 CSV (Spreadsheet Ready)</option>
                        <option value="txt">📝 Plain Text (Simple List)</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💾</span>
                        Choose the format that best suits your workflow.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_include_images" id="aebg-include-images" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🖼️</span>
                            Include product images
                        </span>
                    </label>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_include_description" id="aebg-include-description" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">📝</span>
                            Include product descriptions
                        </span>
                    </label>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_include_affiliate_links" id="aebg-include-affiliate-links" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🔗</span>
                            Include affiliate links
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Search Button Section -->
    <div class="aebg-generate-section aebg-search-section">
        <div class="aebg-search-header">
            <h3>Ready to Discover Products?</h3>
            <p>Click the button below to start your product search journey</p>
        </div>
        <button type="button" id="aebg-search-products" class="aebg-btn aebg-btn-primary aebg-btn-large aebg-btn-glow">
            <span class="aebg-icon">🔍</span>
            <span class="aebg-btn-text">Search Products</span>
            <span class="aebg-btn-loading" style="display: none;">
                <span class="aebg-loading-spinner-small"></span>
                Searching...
            </span>
        </button>
        <button type="button" id="aebg-test-filtering" class="aebg-btn aebg-btn-secondary" style="margin-left: 10px;">
            <span class="aebg-icon">🧪</span>
            Test Filtering
        </button>
        <div class="aebg-search-stats">
            <div class="aebg-stat-item">
                <span class="aebg-stat-icon">⚡</span>
                <span class="aebg-stat-text">Fast Search</span>
            </div>
            <div class="aebg-stat-item">
                <span class="aebg-stat-icon">🎯</span>
                <span class="aebg-stat-text">Accurate Results</span>
            </div>
            <div class="aebg-stat-item">
                <span class="aebg-stat-icon">📊</span>
                <span class="aebg-stat-text">Rich Data</span>
            </div>
        </div>
    </div>

    <!-- Enhanced Results Section -->
    <div id="aebg-results-section" class="aebg-results-section" style="display: none;">
        <div class="aebg-results-header">
            <div class="aebg-results-title">
                <h3>
                    <span class="aebg-icon">📊</span>
                    Search Results
                </h3>
                <div class="aebg-results-stats" id="aebg-results-stats">
                    <span class="aebg-stat-badge">0 queries</span>
                    <span class="aebg-stat-badge">0 products</span>
                </div>
            </div>
            <div class="aebg-results-actions">
                <button type="button" id="aebg-export-results" class="aebg-btn aebg-btn-success">
                    <span class="aebg-icon">📤</span>
                    Export Results
                </button>
                <button type="button" id="aebg-new-search" class="aebg-btn aebg-btn-primary">
                    <span class="aebg-icon">🔄</span>
                    New Search
                </button>
            </div>
        </div>
        
        <div id="aebg-results-container" class="aebg-results-container">
            <!-- Results will be populated here -->
        </div>
    </div>
</div>

<!-- Enhanced Loading Overlay -->
<div id="aebg-loading-overlay" class="aebg-loading-overlay">
    <div class="aebg-loading-content">
        <div class="aebg-loading-spinner aebg-loading-spinner-large"></div>
        <div class="aebg-loading-text">Searching for products...</div>
        <div class="aebg-loading-progress">
            <div class="aebg-progress-bar">
                <div class="aebg-progress-bar-inner" id="aebg-loading-progress"></div>
            </div>
            <div class="aebg-progress-text" id="aebg-loading-progress-text">Initializing search...</div>
        </div>
    </div>
</div>

<!-- Enhanced Error Modal -->
<div id="aebg-error-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content">
        <div class="aebg-modal-header">
            <h3>
                <span class="aebg-icon">❌</span>
                Search Error
            </h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div id="aebg-error-content"></div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-primary" onclick="document.getElementById('aebg-error-modal').classList.remove('show')">
                <span class="aebg-icon">✅</span>
                Got it
            </button>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div id="aebg-export-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content aebg-export-modal-content">
        <div class="aebg-modal-header">
            <h3>
                <span class="aebg-icon">📤</span>
                Export Results
            </h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div class="aebg-export-preview" id="aebg-export-preview">
                <!-- Export preview will be shown here -->
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" id="aebg-download-export" class="aebg-btn aebg-btn-success">
                <span class="aebg-icon">💾</span>
                Download File
            </button>
            <button type="button" class="aebg-btn aebg-btn-secondary" onclick="document.getElementById('aebg-export-modal').classList.remove('show')">
                Cancel
            </button>
        </div>
    </div>
</div> 