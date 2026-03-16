jQuery(document).ready(function($) {
    'use strict';

    // Global variables
    let searchResults = [];
    let isSearching = false;
    let currentPage = 1;
    let productsPerPage = 10;
    let allProducts = [];

    // Initialize the page
    function init() {
        // Ensure all modals are hidden on page load and clear any stale content
        $('.aebg-modal').removeClass('show').css('display', 'none');
        $('#aebg-error-content').empty();
        
        checkConnection();
        setupEventListeners();
        setupSliders();
    }

    // Check Datafeedr connection
    function checkConnection() {
        const statusIndicator = $('#aebg-status-indicator');
        const statusText = $('#aebg-status-text');
        const statusDetails = $('#aebg-status-details');
        const connectionStatus = $('#aebg-connection-status');

        statusIndicator.removeClass('connected error').addClass('checking');
        statusText.text('Checking Datafeedr connection...');
        statusDetails.text('Verifying API credentials and network connectivity');
        connectionStatus.removeClass('connected error').addClass('checking');

        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_test_datafeedr_connection',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusIndicator.removeClass('checking').addClass('connected');
                    statusText.text('✅ Connected to Datafeedr API');
                    statusDetails.text('Ready to search thousands of products across multiple networks');
                    connectionStatus.removeClass('checking error').addClass('connected');
                } else {
                    statusIndicator.removeClass('checking').addClass('error');
                    statusText.text('❌ Connection failed');
                    statusDetails.text(response.data || 'Unknown error occurred');
                    connectionStatus.removeClass('checking connected').addClass('error');
                }
            },
            error: function() {
                statusIndicator.removeClass('checking').addClass('error');
                statusText.text('❌ Connection failed');
                statusDetails.text('Network error - please check your internet connection');
                connectionStatus.removeClass('checking connected').addClass('error');
            }
        });
    }

    // Setup event listeners
    function setupEventListeners() {
        // Test connection button
        $('#aebg-test-connection').on('click', function() {
            checkConnection();
        });

        // Clear results button
        $('#aebg-clear-results').on('click', function() {
            clearResults();
        });

        // Search products button
        $('#aebg-search-products').on('click', function() {
            if (!isSearching) {
                searchProducts();
            }
        });
        
        // Test filtering button
        $('#aebg-test-filtering').on('click', function() {
            testFiltering();
        });

        // Export results button
        $('#aebg-export-results').on('click', function() {
            exportResults();
        });

        // New search button
        $('#aebg-new-search').on('click', function() {
            clearResults();
            $('#aebg-search-query').focus();
        });

        // Error modal close
        $('.aebg-modal-close').on('click', function() {
            $(this).closest('.aebg-modal').removeClass('show');
        });

        // Close modal on backdrop click
        $('.aebg-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).removeClass('show');
            }
        });

        // Pagination event listeners (delegated to handle dynamically added elements)
        $(document).on('click', '.aebg-prev-page', function() {
            const currentPage = parseInt($('.aebg-page-info').text().match(/Page (\d+)/)[1]);
            if (currentPage > 1) {
                goToPage(currentPage - 1);
            }
        });

        $(document).on('click', '.aebg-next-page', function() {
            const currentPage = parseInt($('.aebg-page-info').text().match(/Page (\d+)/)[1]);
            const totalPages = parseInt($('.aebg-page-info').text().match(/of (\d+)/)[1]);
            if (currentPage < totalPages) {
                goToPage(currentPage + 1);
            }
        });

        $(document).on('click', '.aebg-go-page', function() {
            const pageInput = $('.aebg-page-input');
            const page = parseInt(pageInput.val());
            const totalPages = parseInt($('.aebg-page-info').text().match(/of (\d+)/)[1]);
            
            if (page >= 1 && page <= totalPages) {
                goToPage(page);
            } else {
                showError('Please enter a valid page number between 1 and ' + totalPages);
            }
        });

        // Handle Enter key in page input
        $(document).on('keypress', '.aebg-page-input', function(e) {
            if (e.which === 13) { // Enter key
                $('.aebg-go-page').click();
            }
        });
    }

    // Setup sliders and enhanced interactions
    function setupSliders() {
        // Results limit slider
        $('#aebg-results-limit').on('input', function() {
            const value = $(this).val();
            $('#aebg-results-limit-value').text(value);
            updateSliderBackground($(this), value);
        });

        // Min rating slider
        $('#aebg-min-rating').on('input', function() {
            const value = $(this).val();
            $('#aebg-min-rating-value').text(value);
            updateRatingDisplay(value);
            updateSliderBackground($(this), value);
        });

        // Initialize sliders
        $('#aebg-results-limit').trigger('input');
        $('#aebg-min-rating').trigger('input');

        // Query counter
        $('#aebg-search-query').on('input', function() {
            const queries = $(this).val().split('\n').filter(q => q.trim()).length;
            $('#aebg-query-counter').text(queries);
        });

        // Enhanced input interactions
        $('.aebg-input-enhanced, .aebg-textarea-enhanced, .aebg-select-enhanced').on('focus', function() {
            $(this).closest('.aebg-form-group').addClass('focused');
        }).on('blur', function() {
            $(this).closest('.aebg-form-group').removeClass('focused');
        });

        // Checkbox enhancements
        $('.aebg-checkbox-enhanced input[type="checkbox"]').on('change', function() {
            const label = $(this).closest('.aebg-checkbox-enhanced');
            if ($(this).is(':checked')) {
                label.addClass('checked');
            } else {
                label.removeClass('checked');
            }
        });

        // Currency selection
        $('#aebg-currency').on('change', function() {
            updateCurrencySymbols($(this).val());
        });

        // Initialize currency symbols
        updateCurrencySymbols($('#aebg-currency').val());

        // Setup table sorting
        setupTableSorting();

        // Setup quick results buttons
        setupQuickResults();
    }

    // Setup quick results buttons
    function setupQuickResults() {
        $('.aebg-quick-result-btn').on('click', function() {
            const value = $(this).data('value');
            $('#aebg-results-limit').val(value).trigger('input');
            $('.aebg-quick-result-btn').removeClass('active');
            $(this).addClass('active');
        });
    }

    // Setup table sorting functionality
    function setupTableSorting() {
        $(document).on('click', '.aebg-th-name, .aebg-th-price', function() {
            const column = $(this).data('sort');
            const currentDirection = $(this).find('.aebg-sort-icon').text();
            
            // Reset all sort icons
            $('.aebg-sort-icon').text('↕');
            
            // Set new sort direction
            const newDirection = currentDirection === '↑' ? '↓' : '↑';
            $(this).find('.aebg-sort-icon').text(newDirection);
            
            // Sort the table
            sortTable(column, newDirection === '↑');
        });
    }

    // Sort table function
    function sortTable(column, ascending) {
        const tbody = $('.aebg-products-table tbody');
        const rows = tbody.find('tr').get();
        
        rows.sort(function(a, b) {
            let aVal, bVal;
            
            switch(column) {
                case 'name':
                    aVal = $(a).find('.aebg-product-name').text().toLowerCase();
                    bVal = $(b).find('.aebg-product-name').text().toLowerCase();
                    break;
                case 'price':
                    aVal = parseFloat($(a).find('.aebg-td-price').data('price')) || 0;
                    bVal = parseFloat($(b).find('.aebg-td-price').data('price')) || 0;
                    break;

                default:
                    return 0;
            }
            
            if (ascending) {
                return aVal > bVal ? 1 : -1;
            } else {
                return aVal < bVal ? 1 : -1;
            }
        });
        
        tbody.empty().append(rows);
    }

    // Add pagination controls
    function addPaginationControls(totalProducts, limit) {
        const totalPages = Math.ceil(totalProducts / limit);
        if (totalPages <= 1) return;

        const paginationHtml = `
            <div class="aebg-pagination">
                <div class="aebg-pagination-info">
                    Showing ${limit} of ${totalProducts} products
                </div>
                <div class="aebg-pagination-controls">
                    <button type="button" class="aebg-btn aebg-btn-outline aebg-prev-page" disabled>
                        <span class="aebg-icon">←</span> Previous
                    </button>
                    <span class="aebg-page-info">Page 1 of ${totalPages}</span>
                    <button type="button" class="aebg-btn aebg-btn-outline aebg-next-page" ${totalPages <= 1 ? 'disabled' : ''}>
                        Next <span class="aebg-icon">→</span>
                    </button>
                </div>
                <div class="aebg-pagination-jump">
                    <label>Go to page:</label>
                    <input type="number" class="aebg-page-input" min="1" max="${totalPages}" value="1">
                    <button type="button" class="aebg-btn aebg-btn-small aebg-go-page">Go</button>
                </div>
            </div>
        `;

        $('.aebg-table-container').after(paginationHtml);
    }

    // Go to specific page
    function goToPage(page) {
        const totalPages = Math.ceil(allProducts.length / productsPerPage);
        
        if (page < 1 || page > totalPages) {
            return;
        }

        currentPage = page;
        
        // Calculate start and end indices for current page
        const startIndex = (page - 1) * productsPerPage;
        const endIndex = startIndex + productsPerPage;
        const pageProducts = allProducts.slice(startIndex, endIndex);
        
        // Render products for current page
        renderProducts(pageProducts);
        
        // Update pagination controls
        updatePaginationControls(page, totalPages);
        
        // Scroll to top of results
        $('html, body').animate({
            scrollTop: $('#aebg-results-section').offset().top - 20
        }, 300);
    }

    // Update pagination controls
    function updatePaginationControls(currentPage, totalPages) {
        const $pagination = $('.aebg-pagination');
        const $prevBtn = $pagination.find('.aebg-prev-page');
        const $nextBtn = $pagination.find('.aebg-next-page');
        const $pageInfo = $pagination.find('.aebg-page-info');
        const $pageInput = $pagination.find('.aebg-page-input');
        
        // Update page info
        $pageInfo.text(`Page ${currentPage} of ${totalPages}`);
        
        // Update input value
        $pageInput.val(currentPage);
        
        // Update button states
        $prevBtn.prop('disabled', currentPage <= 1);
        $nextBtn.prop('disabled', currentPage >= totalPages);
        
        // Update showing info
        const startIndex = (currentPage - 1) * productsPerPage + 1;
        const endIndex = Math.min(currentPage * productsPerPage, allProducts.length);
        $pagination.find('.aebg-pagination-info').text(`Showing ${startIndex}-${endIndex} of ${allProducts.length} products`);
    }

    // Update slider background
    function updateSliderBackground(slider, value) {
        const min = slider.attr('min');
        const max = slider.attr('max');
        const percentage = ((value - min) / (max - min)) * 100;
        slider.css('background', `linear-gradient(to right, #4f46e5 0%, #4f46e5 ${percentage}%, #e5e7eb ${percentage}%, #e5e7eb 100%)`);
    }

    // Update rating display
    function updateRatingDisplay(rating) {
        const stars = '★'.repeat(Math.floor(rating)) + '☆'.repeat(5 - Math.floor(rating));
        $('#aebg-rating-display').html(`<span class="aebg-rating-stars">${stars}</span><span class="aebg-rating-text">and above</span>`);
    }

    // Update currency symbols
    function updateCurrencySymbols(currency) {
        const currencySymbols = {
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'DKK': 'kr',
            'SEK': 'kr',
            'NOK': 'kr',
            'CAD': 'C$',
            'AUD': 'A$',
            'JPY': '¥',
            'CHF': 'CHF',
            'CNY': '¥',
            'INR': '₹',
            'BRL': 'R$'
        };
        const symbol = currencySymbols[currency] || '$';
        $('#aebg-min-price-prefix').text(symbol);
        $('#aebg-max-price-prefix').text(symbol);
    }

    // Update loading progress
    function updateLoadingProgress(percentage, text) {
        $('#aebg-loading-progress').css('width', percentage + '%');
        $('#aebg-loading-progress-text').text(text);
    }

    // Search products
    function searchProducts() {
        const searchQuery = $('#aebg-search-query').val().trim();
        
        if (!searchQuery) {
            showError('Please enter at least one search query.');
            return;
        }

        const queries = searchQuery.split('\n').filter(q => q.trim());
        if (queries.length === 0) {
            showError('Please enter at least one search query.');
            return;
        }

        // Get form data
        const formData = {
            queries: queries,
            limit: $('#aebg-results-limit').val(),
            sort_by: $('#aebg-sort-by').val(),
            min_price: $('#aebg-min-price').val() || null,
            max_price: $('#aebg-max-price').val() || null,
            min_rating: $('#aebg-min-rating').val() || 0,
            in_stock_only: $('#aebg-in-stock-only').is(':checked'),
            currency: $('#aebg-currency').val(),
            country: $('#aebg-country').val(),
            category: $('#aebg-category').val().trim() || null,
            has_image: $('#aebg-has-image').is(':checked'),
            offset: 0 // Start from first page
        };

        // Start search
        isSearching = true;
        showLoading(true);
        updateSearchButton('Searching...', true);

        // Clear previous results
        clearResults();

        // Perform search for each query
        let completedQueries = 0;
        let totalProducts = 0;
        searchResults = [];

        // Update loading progress
        updateLoadingProgress(0, `Starting search for ${queries.length} queries...`);

        queries.forEach(function(query, index) {
            performSearch(query, formData, function(results) {
                searchResults.push({
                    query: query,
                    results: results
                });
                totalProducts += results.length;
                completedQueries++;
                
                const progress = (completedQueries / queries.length) * 100;
                updateLoadingProgress(progress, `Completed ${completedQueries} of ${queries.length} queries (${totalProducts} products found)...`);
                
                if (completedQueries === queries.length) {
                    // All searches completed
                    isSearching = false;
                    showLoading(false);
                    updateSearchButton('Search Products', false);
                    displayResults(formData);
                }
            });
        });
    }

    // Perform individual search
    function performSearch(query, formData, callback) {
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_search_products_advanced',
                nonce: aebg_ajax.search_products_nonce || aebg_ajax.nonce,
                query: query,
                limit: formData.limit,
                sort_by: formData.sort_by,
                min_price: formData.min_price,
                max_price: formData.max_price,
                min_rating: formData.min_rating,
                in_stock_only: formData.in_stock_only,
                currency: formData.currency,
                country: formData.country,
                category: formData.category,
                has_image: formData.has_image
            },
            success: function(response) {
                if (response.success) {
                    // Handle both old format (array) and new format (object with products key)
                    const products = response.data.products || response.data || [];
                    callback(products);
                } else {
                    console.error('Search failed for query "' + query + '":', response.data);
                    callback([]);
                }
            },
            error: function(xhr, status, error) {
                console.error('Search error for query "' + query + '":', error);
                callback([]);
            }
        });
    }

    // Global variable to store current products for copy functionality
    let currentProducts = [];

    // Get stock status with intelligent field detection
    function getStockStatus(product) {
        // Check various possible stock-related fields
        const stockFields = [
            'availability',
            'stock_status',
            'in_stock',
            'stock',
            'available',
            'status'
        ];
        
        let stockValue = null;
        for (const field of stockFields) {
            if (product[field] !== undefined && product[field] !== null) {
                stockValue = product[field];
                break;
            }
        }
        
        if (stockValue === null || stockValue === undefined) {
            return '<span class="aebg-stock-unknown">Unknown</span>';
        }
        
        // Convert to string and normalize
        const stockStr = String(stockValue).toLowerCase().trim();
        
        // Check for in-stock indicators
        if (stockStr === 'true' || stockStr === '1' || stockStr === 'yes' || 
            stockStr === 'in stock' || stockStr === 'available' || stockStr === 'instock') {
            return '<span class="aebg-stock-in">✅ In Stock</span>';
        }
        
        // Check for out-of-stock indicators
        if (stockStr === 'false' || stockStr === '0' || stockStr === 'no' || 
            stockStr === 'out of stock' || stockStr === 'unavailable' || stockStr === 'outofstock') {
            return '<span class="aebg-stock-out">❌ Out of Stock</span>';
        }
        
        // Check for limited stock indicators
        if (stockStr.includes('limited') || stockStr.includes('low') || stockStr.includes('few')) {
            return '<span class="aebg-stock-limited">⚠️ Limited Stock</span>';
        }
        
        // If we can't determine, show the raw value
        return `<span class="aebg-stock-raw">${stockValue}</span>`;
    }

    // Display search results
    function displayResults(formData) {
        const resultsContainer = $('#aebg-results-container');
        const resultsSection = $('#aebg-results-section');
        const resultsStats = $('#aebg-results-stats');

        if (searchResults.length === 0) {
            resultsContainer.html(`
                <div class="aebg-no-results">
                    <span class="aebg-icon">🔍</span>
                    <h4>No Results Found</h4>
                    <p>Try adjusting your search terms or filters.</p>
                </div>
            `);
        } else {
            // Combine all results from all queries into one table
            allProducts = [];
            searchResults.forEach(function(queryResult) {
                allProducts = allProducts.concat(queryResult.results);
            });
            
            // Store products globally for copy functionality
            currentProducts = allProducts;
            
            // Set products per page from form data
            productsPerPage = parseInt(formData.limit) || 10;
            
            // Reset to first page
            currentPage = 1;
            
            // Debug: Log first product to see available fields
            if (allProducts.length > 0) {
                console.log('First product data:', allProducts[0]);
                console.log('Available fields:', Object.keys(allProducts[0]));
            }

            // Get products for first page
            const firstPageProducts = allProducts.slice(0, productsPerPage);

            // Render single table with first page products
            const html = `
                <div class="aebg-search-summary">
                    <div class="aebg-query-info">
                        <span class="aebg-icon">🔍</span>
                        <span class="aebg-search-term">${searchResults.map(r => r.query).join(', ')}</span>
                    </div>
                    <div class="aebg-results-count">${allProducts.length} products found</div>
                </div>
                ${renderProducts(firstPageProducts)}
            `;

            resultsContainer.html(html);
        }

        // Calculate total products
        let totalProducts = 0;
        searchResults.forEach(function(queryResult) {
            totalProducts += queryResult.results.length;
        });

        // Add pagination controls
        addPaginationControls(totalProducts, formData.limit);

        // Update stats with enhanced display
        resultsStats.html(`
            <span class="aebg-stat-badge">${searchResults.length} queries</span>
            <span class="aebg-stat-badge">${totalProducts} products</span>
            <span class="aebg-stat-badge">Page 1 of ${Math.ceil(totalProducts / formData.limit)}</span>
        `);
        
        resultsSection.show();
        
        // Add smooth scroll to results
        $('html, body').animate({
            scrollTop: resultsSection.offset().top - 20
        }, 800);
    }

    // Fix malformed URLs (handles s// issue)
    function fixMalformedUrl(url) {
        if (!url || typeof url !== 'string') {
            return url || '#';
        }
        
        const originalUrl = url;
        
        // CRITICAL FIX: Handle URLs that start with http://s// or https://s// (protocol + s// issue)
        // This must be checked FIRST before handling plain s//
        if (url.indexOf('://s//') !== -1) {
            // Replace http://s// or https://s// with https://
            url = url.replace(/^https?:\/\/s\/\//, 'https://');
            // Also handle cases where s// appears after the protocol (in case of encoding issues)
            url = url.replace('://s//', '://');
        }
        
        // CRITICAL FIX: Handle URLs that start with s// (missing http: prefix)
        if (url.indexOf('s//') === 0) {
            url = 'https://' + url.substring(3); // Remove 's//' and add 'https://'
        }
        
        // Additional safety: ensure URL has proper protocol
        if (url && url.indexOf('://') === -1 && url.indexOf('//') === 0) {
            url = 'https:' + url;
        }
        
        // If URL still doesn't have a protocol and looks like a domain, add https://
        if (url && url.indexOf('://') === -1 && /^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/.test(url)) {
            url = 'https://' + url;
        }
        
        if (url !== originalUrl) {
            console.log('[AEBG] Fixed malformed URL - Original: ' + originalUrl + ' -> Fixed: ' + url);
        }
        
        return url;
    }

    // Format price according to currency
    function formatPriceForCurrency(priceValue, currency, symbol) {
        if (!priceValue || isNaN(priceValue)) {
            return 'N/A';
        }
        
        // Scandinavian currencies (DKK, SEK, NOK) use space as thousands separator and comma as decimal separator
        const scandinavianCurrencies = ['DKK', 'SEK', 'NOK'];
        
        // Check if price has decimals
        const hasDecimals = priceValue % 1 !== 0;
        const decimals = hasDecimals ? 2 : 0;
        
        if (scandinavianCurrencies.includes(currency)) {
            // Format for Scandinavian currencies: space as thousands separator, comma as decimal separator
            // Example: 262400 -> "262 400" or 2624.50 -> "2 624,50"
            const parts = priceValue.toFixed(decimals).split('.');
            const integerPart = parts[0];
            const decimalPart = parts[1];
            
            // Add thousands separators (space) to integer part
            const formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            
            // Combine with decimal part if exists
            let formattedPrice = formattedInteger;
            if (hasDecimals && decimalPart) {
                formattedPrice = formattedInteger + ',' + decimalPart;
            }
            
            // Add currency symbol with space: "kr 262 400" or "kr 2 624,50"
            return symbol + ' ' + formattedPrice;
        } else {
            // Format for other currencies (USD, EUR, GBP, etc.)
            // Use standard formatting with comma as thousands separator and period as decimal separator
            const formattedPrice = priceValue.toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
            
            // Add currency symbol: "$262,400" or "€2,624.50"
            if (symbol === '$' || symbol === '€' || symbol === '£') {
                return symbol + formattedPrice;
            } else {
                // For other symbols, add space: "¥ 262,400"
                return symbol + ' ' + formattedPrice;
            }
        }
    }

    // Render products as modern responsive table
    function renderProducts(products) {
        if (products.length === 0) {
            return `
                <div class="aebg-no-results">
                    <span class="aebg-icon">📦</span>
                    <h4>No Products Found</h4>
                    <p>Try adjusting your search terms or filters.</p>
                </div>
            `;
        }

        const currency = $('#aebg-currency').val();
        const currencySymbols = {
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'DKK': 'kr',
            'SEK': 'kr',
            'NOK': 'kr',
            'CAD': 'C$',
            'AUD': 'A$',
            'JPY': '¥',
            'CHF': 'CHF',
            'CNY': '¥',
            'INR': '₹',
            'BRL': 'R$'
        };
        const symbol = currencySymbols[currency] || '$';

        return `
            <div class="aebg-table-container">
                <table class="aebg-products-table">
                    <thead>
                        <tr>
                            <th class="aebg-th-image">Image</th>
                            <th class="aebg-th-name sortable" data-sort="name">
                                Product Name
                                <span class="aebg-sort-icon">↕</span>
                            </th>
                            <th class="aebg-th-price sortable" data-sort="price">
                                Price
                                <span class="aebg-sort-icon">↕</span>
                            </th>
                            <th class="aebg-th-network">Network</th>
                            <th class="aebg-th-stock">Stock</th>
                            <th class="aebg-th-merchant">Merchant</th>
                            <th class="aebg-th-category">Category</th>
                            <th class="aebg-th-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${products.map(function(product, index) {
                            const image = product.image_url || product.image || '';
                            
                            // Format price based on currency
                            // Use product's currency if available, otherwise use form selection
                            const productCurrency = product.currency || currency;
                            const productSymbol = currencySymbols[productCurrency] || symbol;
                            
                            let price = 'N/A';
                            if (product.price) {
                                const priceValue = parseFloat(product.price);
                                
                                // Format price according to currency
                                price = formatPriceForCurrency(priceValue, productCurrency, productSymbol);
                            }
                            

                            
                            return `
                                <tr class="aebg-product-row" data-product-id="${index}">
                                    <td class="aebg-td-image">
                                        ${image ? `<img src="${image}" alt="${product.name}" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjYwIiBoZWlnaHQ9IjYwIiBmaWxsPSIjRjNGNEY2Ii8+CjxwYXRoIGQ9Ik0yMCAyMEg0MFY0MEgyMFYyMFoiIGZpbGw9IiM5Q0EzQUYiLz4KPC9zdmc+'; this.style.opacity='0.5';">` : '<div class="aebg-no-image">No Image</div>'}
                                    </td>
                                    <td class="aebg-td-name">
                                        <div class="aebg-product-name">${product.name || 'Product Name Not Available'}</div>
                                        ${product.description ? `<div class="aebg-product-description">${product.description.substring(0, 100)}${product.description.length > 100 ? '...' : ''}</div>` : ''}
                                    </td>
                                    <td class="aebg-td-price" data-price="${product.price || 0}">
                                        <span class="aebg-price">${price}</span>
                                    </td>
                                    <td class="aebg-td-network">
                                        <span class="aebg-network">${product.network || product.network_name || 'Unknown'}</span>
                                    </td>
                                    <td class="aebg-td-stock">
                                        ${getStockStatus(product)}
                                    </td>
                                    <td class="aebg-td-merchant">
                                        <span class="aebg-merchant">${product.merchant || 'Unknown'}</span>
                                    </td>
                                    <td class="aebg-td-category">
                                        <span class="aebg-category">${product.category || 'Uncategorized'}</span>
                                    </td>
                                    <td class="aebg-td-actions">
                                        <div class="aebg-actions">
                                            <a href="${product.url || '#'}" target="_blank" class="aebg-btn aebg-btn-small aebg-btn-outline" title="View Product">
                                                <span class="aebg-icon">🔗</span>
                                            </a>
                                            <button type="button" class="aebg-btn aebg-btn-small aebg-btn-primary copy-product-data" title="Copy Data">
                                                <span class="aebg-icon">📋</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // Export results
    function exportResults() {
        if (searchResults.length === 0) {
            showError('No results to export.');
            return;
        }

        const format = $('#aebg-export-format').val();
        const includeImages = $('#aebg-include-images').is(':checked');
        const includeDescription = $('#aebg-include-description').is(':checked');

        // Prepare export data
        let exportData = [];
        searchResults.forEach(function(queryResult) {
            queryResult.results.forEach(function(product) {
                const exportProduct = {
                    query: queryResult.query,
                    name: product.name,
                    price: product.price,
                    url: product.url,
                    merchant: product.merchant,
                    category: product.category,
                    rating: product.rating,
                    reviews_count: product.reviews_count
                };

                if (includeImages) {
                    exportProduct.image_url = product.image_url || product.image;
                }

                if (includeDescription) {
                    exportProduct.description = product.description;
                }

                exportData.push(exportProduct);
            });
        });

        // Generate export content
        let exportContent = '';
        let filename = 'product-scout-results';

        switch (format) {
            case 'json':
                exportContent = JSON.stringify(exportData, null, 2);
                filename += '.json';
                break;
            case 'csv':
                exportContent = generateCSV(exportData);
                filename += '.csv';
                break;
            case 'txt':
                exportContent = generateTXT(exportData);
                filename += '.txt';
                break;
        }

        // Download file
        downloadFile(exportContent, filename, format === 'json' ? 'application/json' : 'text/plain');
    }

    // Generate CSV content
    function generateCSV(data) {
        if (data.length === 0) return '';

        const headers = Object.keys(data[0]);
        const csvContent = [
            headers.join(','),
            ...data.map(row => headers.map(header => {
                const value = row[header] || '';
                return `"${String(value).replace(/"/g, '""')}"`;
            }).join(','))
        ].join('\n');

        return csvContent;
    }

    // Generate TXT content
    function generateTXT(data) {
        return data.map((product, index) => {
            return `Product ${index + 1}:
Query: ${product.query}
Name: ${product.name}
Price: ${product.price}
URL: ${product.url}
Merchant: ${product.merchant}
Category: ${product.category}
Rating: ${product.rating}
Reviews: ${product.reviews_count}
${product.image_url ? `Image: ${product.image_url}` : ''}
${product.description ? `Description: ${product.description}` : ''}
${'-'.repeat(50)}`;
        }).join('\n\n');
    }

    // Download file
    function downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Clear results
    function clearResults() {
        searchResults = [];
        $('#aebg-results-section').hide();
        $('#aebg-results-container').empty();
    }

    // Show loading overlay
    function showLoading(show) {
        const overlay = $('#aebg-loading-overlay');
        if (show) {
            overlay.addClass('show');
        } else {
            overlay.removeClass('show');
        }
    }

    // Update search button
    function updateSearchButton(text, disabled) {
        const button = $('#aebg-search-products');
        const btnText = button.find('.aebg-btn-text');
        const btnLoading = button.find('.aebg-btn-loading');
        
        if (disabled) {
            btnText.hide();
            btnLoading.show();
            button.addClass('loading').prop('disabled', true);
        } else {
            btnText.show();
            btnLoading.hide();
            button.removeClass('loading').prop('disabled', false);
        }
    }

    // Show error
    function showError(message) {
        $('#aebg-error-content').html(`
            <div class="aebg-message error">
                <span class="aebg-icon">❌</span>
                ${message}
            </div>
        `);
        $('#aebg-error-modal').addClass('show');
    }

    // Copy product data to clipboard
    $(document).on('click', '.copy-product-data', function() {
        const productId = $(this).closest('tr').data('product-id');
        const productData = currentProducts[productId];
        
        if (!productData) {
            showError('Product data not found.');
            return;
        }
        
        const textToCopy = JSON.stringify(productData, null, 2);
        const button = $(this);
        
        navigator.clipboard.writeText(textToCopy).then(function() {
            // Show success feedback
            const originalText = button.html();
            button.html('<span class="aebg-icon">✓</span>').addClass('aebg-btn-success');
            
            setTimeout(function() {
                button.html(originalText).removeClass('aebg-btn-success');
            }, 2000);
        }).catch(function() {
            showError('Failed to copy product data to clipboard.');
        });
    });

    // Test filtering functionality
    function testFiltering() {
        console.log('Testing filtering...');
        
        // Get current form values
        const currency = $('#aebg-currency').val();
        const country = $('#aebg-country').val();
        
        console.log('Current settings - Currency:', currency, 'Country:', country);
        
        // Perform a test search with the actual query from the form
        const testQuery = $('#aebg-search-query').val().trim() || 'robotstøvsuger';
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_search_products_advanced',
                nonce: aebg_ajax.search_products_nonce || aebg_ajax.nonce,
                query: testQuery,
                limit: 5,
                sort_by: 'relevance',
                min_price: 0,
                max_price: $('#aebg-max-price').val() || 0,
                min_rating: 0,
                in_stock_only: false,
                currency: currency,
                country: country,
                category: '',
                has_image: false
            },
            success: function(response) {
                console.log('Test filtering response:', response);
                
                if (response.success && response.data) {
                    // Handle both old format (array) and new format (object with products key)
                    const products = response.data.products || response.data || [];
                    console.log('Found', products.length, 'products');
                    
                    // Check currency and country of results
                    let dkkCount = 0;
                    let dkCount = 0;
                    let otherCurrencies = [];
                    let otherCountries = [];
                    
                    products.forEach(function(product) {
                        if (product.currency === 'DKK') {
                            dkkCount++;
                        } else {
                            otherCurrencies.push(product.currency);
                        }
                        
                        // Check if it's a Danish product by country field or network name
                        let isDanish = false;
                        if (product.country === 'DK' || product.country === 'Denmark') {
                            isDanish = true;
                        } else if (product.network && (product.network.toLowerCase().includes('dk') || 
                                                      product.network.toLowerCase().includes('danmark') || 
                                                      product.network.toLowerCase().includes('denmark'))) {
                            isDanish = true;
                        } else if (product.network_id && (product.network_id.toLowerCase().includes('dk') || 
                                                         product.network_id.toLowerCase().includes('danmark') || 
                                                         product.network_id.toLowerCase().includes('denmark'))) {
                            isDanish = true;
                        }
                        
                        if (isDanish) {
                            dkCount++;
                        } else {
                            otherCountries.push(product.country || product.network || 'Unknown');
                        }
                    });
                    
                    console.log('Currency analysis:');
                    console.log('- DKK products:', dkkCount);
                    console.log('- Other currencies:', otherCurrencies);
                    
                    console.log('Country analysis:');
                    console.log('- DK products:', dkCount);
                    console.log('- Other countries:', otherCountries);
                    
                    // Show results in a modal
                    const results = `
                        <h3>Filtering Test Results</h3>
                        <p><strong>Query:</strong> ${testQuery}</p>
                        <p><strong>Settings:</strong> Currency: ${currency}, Country: ${country}</p>
                        <p><strong>Total Products:</strong> ${products.length}</p>
                        
                        <h4>Currency Analysis:</h4>
                        <p>✅ DKK products: ${dkkCount}</p>
                        <p>❌ Other currencies: ${otherCurrencies.length > 0 ? otherCurrencies.join(', ') : 'None'}</p>
                        
                        <h4>Country Analysis:</h4>
                        <p>✅ DK products: ${dkCount}</p>
                        <p>❌ Other countries: ${otherCountries.length > 0 ? otherCountries.join(', ') : 'None'}</p>
                        
                        <h4>Sample Products:</h4>
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr><th>Name</th><th>Price</th><th>Currency</th><th>Country</th><th>Network</th><th>Network ID</th></tr>
                            ${products.slice(0, 3).map(p => `
                                <tr>
                                    <td>${p.name.substring(0, 30)}...</td>
                                    <td>${p.price}</td>
                                    <td>${p.currency}</td>
                                    <td>${p.country || 'N/A'}</td>
                                    <td>${p.network || 'N/A'}</td>
                                    <td>${p.network_id || 'N/A'}</td>
                                </tr>
                            `).join('')}
                        </table>
                    `;
                    
                    const errorModal = $('#aebg-error-modal');
                    const errorContent = $('#aebg-error-content');
                    
                    errorContent.html(results);
                    errorModal.addClass('show');
                    
                } else {
                    console.error('Test filtering failed:', response);
                    showError('Test filtering failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Test filtering error:', error);
                showError('Test filtering error: ' + error);
            }
        });
    }

    // Initialize the page
    init();
}); 