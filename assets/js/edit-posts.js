/**
 * AI Content Generator - Edit Posts JavaScript
 * Handles associated products table, drag-and-drop reordering, and product search
 */

jQuery(document).ready(function($) {
    'use strict';
    
    console.log('🔧 AEBG Edit Posts JavaScript loaded successfully!');
    console.log('🔧 jQuery version:', $.fn.jquery);
    console.log('🔧 Document ready triggered');
    
    // Make jQuery available globally for debugging
    window.$ = $;
    console.log('🔧 jQuery made available globally as window.$');
    
    // AEBG Edit Posts initialized - version 1.0.2 with cache clearing and merchant comparison fixes

    // Global variables
    let isSearching = false;

    /**
     * Escape HTML to prevent XSS attacks
     * 
     * @param {string} text Text to escape
     * @returns {string} Escaped text
     */
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

        // Initialize edit posts functionality
        console.log('🔧 About to initialize edit posts...');
        initEditPosts();
        console.log('🔧 Edit posts initialized');
        
        // Reinitialize drag and drop on window resize (for mobile/desktop switching)
        let resizeTimer;
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                // Only reinitialize if we have the associated products table
                if ($('.aebg-associated-products-container .aebg-associated-products-table tbody').length > 0) {
                    initDragAndDrop();
                }
            }, 250); // Debounce resize events
        });
    
    // Initialize modern network selector if present
    console.log('🔧 About to initialize modern network selector...');
    initModernNetworkSelector();
    console.log('🔧 Modern network selector initialization complete');

    /**
     * Get configured networks for filtering merchants
     * Returns array of network names/keys that have affiliate IDs configured
     */
    function getConfiguredNetworks() {
        const configuredNetworks = [];
        
        // Get networks from aebgNetworksData if available
        if (typeof aebgNetworksData !== 'undefined' && Array.isArray(aebgNetworksData)) {
            aebgNetworksData.forEach(network => {
                if (network.configured && network.affiliate_id) {
                    // Add network name, code, and display name variations
                    if (network.name) {
                        configuredNetworks.push(network.name);
                    }
                    if (network.code) {
                        configuredNetworks.push(network.code);
                    }
                    
                    // Add common variations for Partner-ads (check both name and code)
                    const networkNameLower = (network.name || '').toLowerCase();
                    const networkCodeLower = (network.code || '').toLowerCase();
                    
                    if (networkNameLower.includes('partner') || networkCodeLower === 'partner_ads' || 
                        networkCodeLower === 'partner_ads_dk' || networkCodeLower === 'api_15') {
                        configuredNetworks.push('Partner-ads Denmark');
                        configuredNetworks.push('Partner-Ads Denmark');
                        configuredNetworks.push('partner-ads denmark');
                        configuredNetworks.push('partner-ads');
                        configuredNetworks.push('partner_ads');
                        configuredNetworks.push('partner_ads_dk');
                        configuredNetworks.push('api_15');
                        configuredNetworks.push('partnerads');
                    }
                    
                    // Add variations for other common networks
                    if (networkCodeLower === 'awin' || networkNameLower.includes('awin')) {
                        configuredNetworks.push('Awin Denmark');
                        configuredNetworks.push('awin');
                    }
                    if (networkCodeLower === 'timeone' || networkNameLower.includes('timeone')) {
                        configuredNetworks.push('TimeOne');
                        configuredNetworks.push('timeone');
                    }
                    if (networkCodeLower === 'adrecord' || networkNameLower.includes('adrecord')) {
                        configuredNetworks.push('Adrecord');
                        configuredNetworks.push('adrecord');
                    }
                }
            });
        }
        
        // Remove duplicates
        return [...new Set(configuredNetworks)];
    }

    /**
     * Generate a more reliable product ID
     */
    function generateProductId(product) {
        // First, try to use the actual product ID if available
        let productId = product.id || product.product_id;
        
        if (productId) {
            // If we have a real ID, use it directly
            const finalId = productId.toString().replace(/[^a-zA-Z0-9_-]/g, '_');
                    // Using real product ID: originalId, finalId, productName
            return finalId;
        }
        
        // Fallback: create a unique ID based on product properties
        const name = (product.name || '').trim();
        const price = product.price || 0;
        const merchant = (product.merchant || '').trim();
        
        // Create a more unique identifier
        const fallbackId = `${name}_${price}_${merchant}_${Date.now()}`;
        const finalId = fallbackId.replace(/[^a-zA-Z0-9_-]/g, '_');
        
        // Generated fallback product ID
        
        return finalId;
    }

    /**
     * Initialize modern network selector functionality
     */
    function initModernNetworkSelector() {
        // Prevent multiple initializations
        if (window.aebgNetworkSelectorInitialized) {
            // Modern Network Selector already initialized, skipping...
            return;
        }
        
        // Check if script is loaded and ready
        if (typeof window.aebgNetworksSelectorLoaded === 'undefined') {
            // Modern Networks Selector script not yet loaded, waiting...
            // Wait a bit for the script to load
            setTimeout(initModernNetworkSelector, 100);
            return;
        }
        
        if ($('.aebg-modern-network-selector').length) {
            // Modern Network Selector found, checking if initialization is needed...
            
            // Check if this is actually a functional network selector (has data) or just a display element
            if (typeof aebgNetworksData !== 'undefined' && aebgNetworksData) {
                // Network data available, initializing full functionality...
                
                // Check if ModernNetworksSelector class is available (plural version)
                if (typeof ModernNetworksSelector !== 'undefined') {
                    try {
                        new ModernNetworksSelector();
                        // Modern Networks Selector initialized successfully
                        window.aebgNetworkSelectorInitialized = true;
                    } catch (error) {
                        console.error('Error initializing Modern Networks Selector:', error);
                    }
                } else if (typeof ModernNetworkSelector !== 'undefined') {
                    // Fallback to singular version if plural not available
                    try {
                        new ModernNetworkSelector();
                        // Modern Network Selector (singular) initialized successfully
                        window.aebgNetworkSelectorInitialized = true;
                    } catch (error) {
                        console.error('Error initializing Modern Network Selector (singular):', error);
                    }
                } else {
                    // No Modern Networks Selector class available
                    console.warn('Modern Networks Selector class not found');
                }
            } else {
                // No network data available - this appears to be a display-only network selector. Skipping full initialization.
                // Initialize basic functionality for display purposes
                initBasicNetworkSelector();
            }
        } else {
            // No modern network selector found, initializing basic functionality
            initBasicNetworkSelector();
        }
    }
    
    /**
     * Initialize basic network selector functionality for display purposes
     */
    function initBasicNetworkSelector() {
        // Initializing basic network selector functionality...
        
        // Add basic click handlers for network display elements
        $('.aebg-network-display').on('click', function() {
            const networkName = $(this).text().trim();
            // Handle network display click
        });
        
        // Basic network selector functionality initialized
    }

    /**
     * Initialize edit posts functionality
     */
    function initEditPosts() {
        // Initializing Edit Posts functionality...
        
        // Initialize drag and drop for product tables
        initDragAndDrop();
        
        // Initialize merchant comparison modal
            initMerchantComparisonModal();
        
        // Initialize comparison management
        initComparisonManagement();
        
        // Initialize product name editing
            initProductNameEditing();
        
        // Initialize comparison table drag and drop
        initComparisonTableDragAndDrop();
        
        // Initialize product search functionality
        initProductSearch();
        
        // Initialize search result event handlers
        initSearchResultHandlers();
        
        // Edit Posts functionality initialized
    }

    /**
     * Initialize drag and drop functionality for product tables
     */
    function initDragAndDrop() {
        const tbody = $('.aebg-products-table tbody');
        
        if (tbody.length && tbody.find('tr').length > 1) {
            tbody.sortable({
                handle: '.drag-handle',
                axis: 'y',
                containment: 'parent',
                tolerance: 'pointer',
                update: function(event, ui) {
                    // Handle reordering
                    updateProductOrder();
                }
            });
            
            // Drag and drop initialized for rows
        }
        
        // Found comparison tables, initializing auto-refresh
        initAutoRefresh();
    }

    /**
     * Initialize auto-refresh functionality for comparison data
     */
    function initAutoRefresh() {
        // Check if we're on a page with products that need comparison data
        const $comparisonTables = $('.aebg-price-comparison, .aebg-comparison-table');
        
        if ($comparisonTables.length > 0) {
            console.log('Found comparison tables, initializing auto-refresh');
            
            // Auto-refresh comparison data every 5 minutes to ensure fresh prices
            setInterval(function() {
                refreshAllComparisonData();
            }, 5 * 60 * 1000); // 5 minutes
            
            // Also refresh on page visibility change (when user returns to tab)
            $(document).on('visibilitychange', function() {
                if (!document.hidden) {
                    console.log('Page became visible, refreshing comparison data');
                    refreshAllComparisonData();
                }
            });
        }
    }

    /**
     * Refresh all comparison data on the page
     */
    function refreshAllComparisonData() {
        const $comparisonTables = $('.aebg-price-comparison, .aebg-comparison-table');
        
        $comparisonTables.each(function() {
            const $table = $(this);
            const $productRow = $table.closest('.aebg-product-row, .aebg-associated-product');
            
            if ($productRow.length > 0) {
                const productId = $productRow.data('product-id') || $productRow.find('[data-product-id]').data('product-id');
                
                if (productId) {
                    console.log('Auto-refreshing comparison data for product:', productId);
                    
                    // Show loading state
                    $table.html('<div class="aebg-loading-comparison">Opdaterer pris sammenligning...</div>');
                    
                    // Fetch fresh data
                    refreshComparisonDataForProduct(productId, $table);
                }
            }
        });
    }

    /**
     * Refresh comparison data for a specific product
     */
    function refreshComparisonDataForProduct(productId, $table) {
        // Get product data from the page
        const $productRow = $table.closest('.aebg-product-row, .aebg-associated-product');
        const productData = {
            id: productId,
            name: $productRow.find('.aebg-product-name').text().trim(),
            brand: $productRow.find('.aebg-brand').text().trim(),
            merchant: $productRow.find('.aebg-merchant').text().trim(),
            price: parseFloat($productRow.find('.aebg-price').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0,
            network: $productRow.find('.aebg-network').text().trim() || 'Unknown'
        };
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_merchant_comparison',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                product_data: productData,
                limit: 10
            },
            success: function(response) {
                if (response.success && response.data && response.data.merchants) {
                    console.log('Successfully refreshed comparison data for product:', productId);
                    
                    // Update the table with fresh data
                    updateComparisonTableWithData(response.data, $table);
                } else {
                    console.error('Failed to refresh comparison data for product:', productId);
                    $table.html('<div class="aebg-no-comparison">Kunne ikke opdatere pris sammenligning</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error refreshing comparison data for product:', productId, error);
                $table.html('<div class="aebg-no-comparison">Fejl ved opdatering af pris sammenligning</div>');
            }
        });
    }

    /**
     * Update comparison table with fresh data
     */
    function updateComparisonTableWithData(data, $table) {
        if (!data.merchants || Object.keys(data.merchants).length === 0) {
            $table.html('<div class="aebg-no-comparison">Ingen pris sammenligning tilgængelig</div>');
            return;
        }
        
        // Generate fresh comparison HTML
        const merchants = Object.values(data.merchants);
        let html = '<table class="aebg-comparison-table-frontend">';
        html += '<thead><tr><th>Forhandler</th><th>Pris</th><th>Lagerstatus</th><th></th></tr></thead><tbody>';
        
        merchants.forEach(function(merchant) {
            const price = merchant.price || merchant.lowest_price || 0;
            const currency = merchant.currency || productData.currency || 'DKK';
            const availability = merchant.availability || 'in_stock';
            const availabilityText = availability === 'in_stock' ? 'På lager' : 'Ikke på lager';
            const availabilityClass = availability === 'in_stock' ? 'in-stock' : 'out-of-stock';
            
            html += '<tr>';
            html += '<td class="aebg-merchant-name">' + esc_html(merchant.name || 'Unknown') + '</td>';
            html += '<td class="aebg-price">' + formatPrice(price, currency) + '</td>';
            html += '<td class="aebg-availability ' + availabilityClass + '">' + availabilityText + '</td>';
            html += '<td class="aebg-action">';
            if (merchant.url) {
                html += '<a href="' + esc_url(merchant.url) + '" target="_blank" class="aebg-btn-view">Se tilbud</a>';
            }
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        
        $table.html(html);
    }

    /**
     * Simple HTML escaping function
     */
    function esc_html(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Simple URL escaping function
     */
    function esc_url(url) {
        try {
            return encodeURI(url);
        } catch (e) {
            return '#';
        }
    }

    // formatPrice function is defined later in the file (line ~3045) with currency support

    /**
     * Initialize the associated products table
     */
    function initAssociatedProductsTable() {
        const table = $('.aebg-associated-products-table');
        if (table.length === 0) {
            console.log('No associated products table found');
            return;
        }

        // Update products count
        updateProductsCount();
        
        // Show empty state if no products
        if (table.find('tbody tr').length === 0) {
            showEmptyState();
        } else {
            // Merchant counts are now loaded directly from database on page load
            // No need to call loadMerchantCounts() here anymore
            console.log('Merchant counts loaded directly from database on page load');
        }
        
        // Debug: Check stored products data
        console.log('🔍 INIT DEBUG: window.aebgProducts:', window.aebgProducts);
        if (window.aebgProducts && window.aebgProducts.length > 0) {
            console.log('🔍 INIT DEBUG: First product:', window.aebgProducts[0]);
            console.log('🔍 INIT DEBUG: First product ID:', window.aebgProducts[0].id);
            console.log('🔍 INIT DEBUG: First product name:', window.aebgProducts[0].name);
        }
        
        // Debug: Check table structure
        const firstRow = table.find('tbody tr:first');
        if (firstRow.length > 0) {
            console.log('🔍 INIT DEBUG: First row data-product-id:', firstRow.data('product-id'));
            console.log('🔍 INIT DEBUG: First row merchants info data-product-id:', firstRow.find('.aebg-merchants-info').data('product-id'));
        }
        
        // Debug: Check if first product has comparison data
        if (window.aebgProducts && window.aebgProducts.length > 0) {
            const firstProduct = window.aebgProducts[0];
            if (firstProduct && firstProduct.id) {
                // Checking if first product has comparison data...
                checkProductComparisonData(firstProduct.id);
                
                // If the first product has no comparison data, we might need to generate it
                setTimeout(() => {
                    // First product check completed, ready for modal testing
                }, 1000);
            }
        }
    }



    /**
     * Initialize drag and drop functionality
     */
    function initDragAndDrop() {
        const tbody = $('.aebg-associated-products-container .aebg-associated-products-table tbody');
        
        if (tbody.length === 0) {
            return;
        }

        // Destroy existing sortable if it exists
        if (tbody.hasClass('ui-sortable')) {
            tbody.sortable('destroy');
        }
        
        // Clean up any existing touch event handlers
        $(document).off('touchmove.aebg-sortable touchend.aebg-sortable touchcancel.aebg-sortable');

        // Detect if we're on a touch device and mobile screen
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        const isMobile = window.innerWidth <= 768;
        
        // Don't prevent default on drag handle - let sortable handle it
        // Only prevent on mousedown for desktop to avoid accidental clicks
        if (!isMobile && !isTouchDevice) {
            $(document).off('mousedown', '.aebg-drag-handle').on('mousedown', '.aebg-drag-handle', function(e) {
                e.preventDefault();
            });
        }
        
        // For mobile: Convert touch events to mouse events for jQuery UI Sortable
        // Focus on drag handle for precise control
        if (isMobile || isTouchDevice) {
            let touchStartY = 0;
            let touchStartX = 0;
            let touchStartTime = 0;
            let isDragging = false;
            let $dragHandle = null;
            let touchIdentifier = null;
            let hasMoved = false;
            
            // Convert touch events on drag handle to mouse events
            tbody.on('touchstart', '.aebg-drag-handle', function(e) {
                const $handle = $(this);
                const touch = e.originalEvent.touches[0];
                
                touchStartY = touch.clientY;
                touchStartX = touch.clientX;
                touchStartTime = Date.now();
                $dragHandle = $handle;
                touchIdentifier = touch.identifier;
                isDragging = false;
                hasMoved = false;
                
                // Add visual feedback
                $handle.addClass('aebg-handle-active');
                
                // Create and dispatch mousedown event at the touch point
                const mouseDownEvent = new MouseEvent('mousedown', {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                    clientX: touch.clientX,
                    clientY: touch.clientY,
                    screenX: touch.screenX || touch.clientX,
                    screenY: touch.screenY || touch.clientY,
                    button: 0,
                    buttons: 1
                });
                this.dispatchEvent(mouseDownEvent);
            });
            
            // Use document-level touchmove to track dragging
            $(document).on('touchmove.aebg-sortable', function(e) {
                if (!$dragHandle || !touchIdentifier) {
                    return;
                }
                
                // Find the touch with our identifier
                const touch = Array.from(e.originalEvent.touches).find(t => t.identifier === touchIdentifier);
                if (!touch) {
                    return;
                }
                
                const deltaY = Math.abs(touch.clientY - touchStartY);
                const deltaX = Math.abs(touch.clientX - touchStartX);
                const timeDelta = Date.now() - touchStartTime;
                const totalDelta = Math.sqrt(deltaX * deltaX + deltaY * deltaY);
                
                // Start dragging if moved more than 10px (matching sortable distance)
                if (!isDragging && totalDelta > 10 && timeDelta > 50) {
                    isDragging = true;
                    hasMoved = true;
                    e.preventDefault(); // Prevent scrolling when dragging
                    
                    // Create mousemove event
                    const mouseMoveEvent = new MouseEvent('mousemove', {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: touch.clientX,
                        clientY: touch.clientY,
                        screenX: touch.screenX || touch.clientX,
                        screenY: touch.screenY || touch.clientY,
                        button: 0,
                        buttons: 1
                    });
                    $dragHandle[0].dispatchEvent(mouseMoveEvent);
                } else if (isDragging) {
                    e.preventDefault(); // Continue preventing scroll during drag
                    
                    // Create mousemove event - update continuously for smooth dragging
                    const mouseMoveEvent = new MouseEvent('mousemove', {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: touch.clientX,
                        clientY: touch.clientY,
                        screenX: touch.screenX || touch.clientX,
                        screenY: touch.screenY || touch.clientY,
                        button: 0,
                        buttons: 1
                    });
                    document.dispatchEvent(mouseMoveEvent);
                } else if (totalDelta > 3) {
                    // Small movement but not enough to drag - prevent default to avoid scrolling
                    hasMoved = true;
                }
            });
            
            // Use document-level touchend to handle end of drag
            $(document).on('touchend.aebg-sortable touchcancel.aebg-sortable', function(e) {
                if (!$dragHandle || !touchIdentifier) {
                    return;
                }
                
                // Find the touch with our identifier
                const touch = e.originalEvent.changedTouches ? 
                    Array.from(e.originalEvent.changedTouches).find(t => t.identifier === touchIdentifier) : null;
                
                // Remove visual feedback
                $dragHandle.removeClass('aebg-handle-active');
                
                if (isDragging && touch) {
                    e.preventDefault();
                    
                    // Create mouseup event
                    const mouseUpEvent = new MouseEvent('mouseup', {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                        clientX: touch.clientX,
                        clientY: touch.clientY,
                        screenX: touch.screenX || touch.clientX,
                        screenY: touch.screenY || touch.clientY,
                        button: 0,
                        buttons: 0
                    });
                    document.dispatchEvent(mouseUpEvent);
                } else if (hasMoved) {
                    // Prevent click if we moved
                    e.preventDefault();
                }
                
                // Reset
                isDragging = false;
                $dragHandle = null;
                touchStartY = 0;
                touchStartX = 0;
                touchStartTime = 0;
                touchIdentifier = null;
                hasMoved = false;
            });
        }

        // Configure sortable options based on device type
        const sortableOptions = {
            axis: 'y',
            opacity: 0.8,
            scroll: true,
            scrollSensitivity: 100,
            scrollSpeed: 20,
            start: function(event, ui) {
                ui.item.addClass('dragging');
                ui.placeholder.height(ui.item.height());
                ui.placeholder.addClass('drag-placeholder');
                $('body').addClass('aebg-dragging');
                
                if (isTouchDevice || isMobile) {
                    ui.item.css({
                        'z-index': 10000,
                        'transform': 'rotate(2deg) scale(1.02)'
                    });
                }
                
                showMessage('Drag to reorder products...', 'info');
            },
            stop: function(event, ui) {
                ui.item.removeClass('dragging');
                ui.item.css({
                    'z-index': '',
                    'transform': ''
                });
                $('body').removeClass('aebg-dragging');
                
                showMessage('Saving new product order...', 'info');
                updateProductOrder();
            },
            over: function(event, ui) {
                $(this).addClass('drag-over');
            },
            out: function(event, ui) {
                $(this).removeClass('drag-over');
            },
            change: function(event, ui) {
                const $placeholder = ui.placeholder;
                $placeholder.addClass('drag-placeholder');
                $placeholder.css({
                    'background': '#f0f4ff',
                    'border': '2px dashed #4f46e5',
                    'border-radius': '12px',
                    'min-height': ui.item.height() + 'px'
                });
            }
        };
        
        // Mobile-specific configuration
        if (isMobile) {
            // On mobile, use drag handle for more precise control
            // This prevents accidental drags and makes it easier to target
            sortableOptions.handle = '.aebg-drag-handle';
            sortableOptions.tolerance = 'pointer';
            sortableOptions.cursor = 'move';
            sortableOptions.distance = 10; // Require 10px movement to start drag (prevents accidental drags)
            sortableOptions.delay = 100; // Small delay to distinguish from tap
            // Cancel drag on interactive elements
            sortableOptions.cancel = 'input, textarea, button:not(.aebg-drag-handle), a, select, .aebg-product-name-text[contenteditable="true"]';
            sortableOptions.helper = function(e, item) {
                const $helper = item.clone();
                $helper.css({
                    'width': item.width(),
                    'opacity': 0.95,
                    'box-shadow': '0 8px 24px rgba(0, 0, 0, 0.3)',
                    'transform': 'rotate(2deg) scale(1.02)',
                    'background': '#fff',
                    'border': '2px solid #4f46e5',
                    'border-radius': '8px',
                    'z-index': 10000
                });
                return $helper;
            };
            // Add touch event handling for better mobile support
            sortableOptions.appendTo = 'body';
            // Force touch support
            sortableOptions.forceHelperSize = true;
            sortableOptions.forcePlaceholderSize = true;
            // Better scrolling during drag
            sortableOptions.scroll = true;
            sortableOptions.scrollSensitivity = 50;
            sortableOptions.scrollSpeed = 15;
        } else {
            // Desktop configuration
            sortableOptions.handle = '.aebg-drag-handle';
            sortableOptions.tolerance = 'pointer';
            sortableOptions.cursor = 'move';
            sortableOptions.cursorAt = { top: 20, left: 20 };
            sortableOptions.distance = 5;
            sortableOptions.delay = 0;
            sortableOptions.helper = function(e, item) {
                return item;
            };
        }

        // Make table rows sortable
        tbody.sortable(sortableOptions);
        
        // For mobile, add visual feedback
        if (isTouchDevice || isMobile) {
            // Add visual feedback to drag handle
            tbody.find('.aebg-drag-handle').on('touchstart', function(e) {
                $(this).addClass('aebg-handle-active');
            }).on('touchend touchcancel', function(e) {
                $(this).removeClass('aebg-handle-active');
            });
        }

        // Drag and drop initialized for rows
        
        // Check if jQuery UI sortable is available
        if (typeof $.fn.sortable === 'undefined') {
            console.error('jQuery UI sortable is not loaded!');
        }
    }

    /**
     * Check if a product has comparison data in the database
     */
    function checkProductComparisonData(productId) {
        // Checking comparison data for product
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_load_comparison',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                post_id: getCurrentPostId()
            },
            success: function(response) {
                if (response.success && response.data && response.data.comparison_data) {
                    const merchants = response.data.comparison_data.merchants || {};
                    // Product has merchants
                } else {
                    // Product has NO comparison data
                }
            },
            error: function(xhr, status, error) {
                console.error('Error checking product comparison data:', error);
            }
        });
    }

    /**
     * Debounced version of loadMerchantCounts to prevent multiple rapid calls
     */
    let loadMerchantCountsTimeout = null;
    function debouncedLoadMerchantCounts() {
        if (loadMerchantCountsTimeout) {
            clearTimeout(loadMerchantCountsTimeout);
        }
        loadMerchantCountsTimeout = setTimeout(function() {
            loadMerchantCounts();
        }, 500); // 500ms debounce
    }
    
    /**
     * Load merchant count for a single product
     */
    function loadMerchantCountForProduct(productId) {
        const $row = $('tr[data-product-id="' + productId + '"]');
        if ($row.length === 0) {
            return;
        }
        
        const $merchantInfo = $row.find('.aebg-merchants-info');
        if ($merchantInfo.length === 0) {
            return;
        }
        
        // Get product data from the row
        const productName = $row.find('.aebg-product-name').text().trim();
        const productPrice = $row.find('.aebg-price').text().trim();
        const productBrand = $row.find('.aebg-brand').text().trim();
        const productMerchant = $row.find('.aebg-merchant').text().trim();
        
        // Extract price value
        let price = 0;
        if (productPrice && productPrice !== 'N/A') {
            const cleanPrice = productPrice.replace(/[^\d,]/g, '');
            if (cleanPrice) {
                price = parseInt(cleanPrice.replace(/,/g, ''));
            }
        }
        
        const productData = {
            id: productId,
            name: productName.replace(/^Product ID:\s*\d+\s*/, '').trim(),
            brand: productBrand === 'N/A' ? '' : productBrand,
            merchant: productMerchant === 'N/A' ? '' : productMerchant,
            price: price
        };
        
        // Show loading state
        $merchantInfo.find('.aebg-merchants-number').text('Loading...');
        
        // Make AJAX request for single product
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_merchant_counts',
                nonce: aebg_ajax.nonce,
                products: [productData]
            },
            success: function(response) {
                if (response.success && response.data) {
                    updateMerchantInfo(response.data);
                }
            },
            error: function() {
                $merchantInfo.find('.aebg-merchants-number').text('Error');
            }
        });
    }
    
    /**
     * Load merchant and price information for all products in the table
     * Note: This is now only used for dynamic updates (adding products, refreshing, etc.)
     * Initial merchant counts are loaded directly from database on page load
     */
    function loadMerchantCounts() {
        // Loading merchant counts for all products in table
        const merchantInfos = $('.aebg-merchants-info');
        if (merchantInfos.length === 0) {
            // No merchant info containers found
            return;
        }

        // Collect all products that need merchant info
        const products = [];
        merchantInfos.each(function() {
            const $this = $(this);
            const productId = $this.data('product-id');
            const $row = $this.closest('tr');
            
            // Get product data from the row
            const productName = $row.find('.aebg-product-name').text().trim();
            const productDescription = $row.find('.aebg-product-description').text().trim();
            const productPrice = $row.find('.aebg-price').text().trim();
            const productBrand = $row.find('.aebg-brand').text().trim();
            const productMerchant = $row.find('.aebg-merchant').text().trim();
            
            // Extract price value (remove currency symbols and convert to number)
            let price = 0;
            if (productPrice && productPrice !== 'N/A') {
                // Remove currency symbols and formatting
                const cleanPrice = productPrice.replace(/[^\d,]/g, '');
                if (cleanPrice) {
                    price = parseInt(cleanPrice.replace(/,/g, ''));
                }
            }
            
            // Determine the actual product name
            let finalProductName = productName;
            if (productName.startsWith('Product ID:')) {
                // This is a product with only ID, use the description if available
                finalProductName = productDescription || productName;
            }
            
            // Clean up the product name
            finalProductName = finalProductName.replace(/^Product ID:\s*\d+\s*/, '').trim();
            
            const productData = {
                id: productId,
                name: finalProductName,
                brand: productBrand === 'N/A' ? '' : productBrand,
                merchant: productMerchant === 'N/A' ? '' : productMerchant,
                price: price,
                sku: '',
                mpn: '',
                upc: '',
                ean: '',
                isbn: ''
            };
            
            // Product data for merchant counting
            
            // Include all products, even if name is not perfect
            if (productData.id) {
                products.push(productData);
            }
        });

        if (products.length === 0) {
            // No valid products found for merchant counting
            return;
        }

        // Loading merchant and price info for products

        // Show loading state
        $('.aebg-merchants-number').text('Loading...');
        $('.aebg-price-lowest .aebg-price-value').text('Loading...');
        $('.aebg-price-highest .aebg-price-value').text('Loading...');

        // Make AJAX request to get merchant info from our database
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_merchant_counts',
                nonce: aebg_ajax.nonce,
                products: products
            },
            success: function(response) {
                // Merchant counting AJAX response received
                console.log('📋 FRONTEND: Response data:', response.data);
                if (response.success && response.data) {
                    console.log('🎯 FRONTEND: Updating merchant info in table with backend data');
                    updateMerchantInfo(response.data);
                } else {
                    console.error('❌ FRONTEND: Failed to load merchant info:', response);
                    // Show error state
                    $('.aebg-merchants-number').text('Error');
                    $('.aebg-price-lowest .aebg-price-value').text('Error');
                    $('.aebg-price-highest .aebg-price-value').text('Error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ FRONTEND: Network error loading merchant info:', error);
                // Show error state
                $('.aebg-merchants-number').text('Error');
                $('.aebg-price-lowest .aebg-price-value').text('Error');
                $('.aebg-price-highest .aebg-price-value').text('Error');
            }
        });
    }

    /**
     * Update merchant and price information displays with actual data
     */
    function updateMerchantInfo(merchantInfo) {
        console.log('Updating merchant info:', merchantInfo);
        
        if (!merchantInfo || typeof merchantInfo !== 'object') {
            console.error('Invalid merchant info:', merchantInfo);
            return;
        }
        
        // Process each product's merchant info
        Object.keys(merchantInfo).forEach(function(productId) {
            const info = merchantInfo[productId];
            if (!info) return;
            
            console.log('Processing merchant info for product:', productId, info);
            
            // Find the merchant info element
            const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
            const $priceRangeDisplay = $('.aebg-price-range-display[data-product-id="' + productId + '"]');
            
            if ($merchantInfo.length === 0) {
                console.warn('Merchant info element not found for product:', productId);
                return;
            }
            
            // Update merchant count
            const $merchantCount = $merchantInfo.find('.aebg-merchants-number');
            const merchantCount = info.merchant_count || 0;
            $merchantCount.text(merchantCount);
            
            // Update price range in the Price column
            if ($priceRangeDisplay.length > 0 && info.price_range && info.merchants) {
                // Format prices using Danish currency format (no thousands separator)
                const formatPrice = (price) => {
                    if (!price || price === 0) return 'N/A';
                    
                    // Check if price seems to be incorrectly multiplied (like in PHP)
                    if (price > 100000) {
                        price = price / 100;
                    } else if (price > 10000) {
                        price = price / 10;
                    }
                    
                    const hasDecimals = price % 1 !== 0;
                    const decimals = hasDecimals ? 2 : 0;
                    
                    // Manual formatting to match PHP number_format behavior
                    let formatted = price.toFixed(decimals);
                    
                    // No thousands separator, just comma for decimal separator (Danish format)
                    const parts = formatted.split('.');
                    formatted = parts.join(',');
                    
                    return formatted + ' kr.';
                };
                
                // Update lowest price
                const lowestPrice = info.price_range.lowest || 0;
                const currency = productData.currency || 'DKK';
                $priceRangeDisplay.find('.aebg-price-lowest .aebg-price-value').text(formatPrice(lowestPrice, currency));
                
                // Update highest price
                const highestPrice = info.price_range.highest || 0;
                $priceRangeDisplay.find('.aebg-price-highest .aebg-price-value').text(formatPrice(highestPrice, currency));
            }
            
            // Update visual indicator based on count
            $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
            if (merchantCount > 5) {
                $merchantInfo.addClass('many-merchants');
            } else if (merchantCount > 2) {
                $merchantInfo.addClass('some-merchants');
            } else if (merchantCount > 0) {
                $merchantInfo.addClass('few-merchants');
            }
        });
        
        console.log('Merchant info update completed');
    }

    /**
     * Initialize product search functionality
     */
    function initProductSearch() {
        console.log('🔧 Initializing product search functionality...');
        
        const searchInput = $('.aebg-search-input');
        const searchResults = $('.aebg-search-results');
        
        console.log('🔧 Search input found:', searchInput.length, 'elements');
        console.log('🔧 Search results container found:', searchResults.length, 'elements');
        
        if (searchInput.length === 0) {
            console.log('🔧 No search input found, returning early');
            return;
        }

        let searchTimeout;

        // Handle search input
        searchInput.on('input', function() {
            const query = $(this).val().trim();
            console.log('🔧 Search input event triggered with query:', query);
            
            // Clear previous timeout
            clearTimeout(searchTimeout);
            
            // Hide results if query is too short
            if (query.length < 3) {
                console.log('🔧 Query too short, hiding results');
                searchResults.removeClass('show').html('');
                return;
            }

            console.log('🔧 Query length OK, setting timeout for search');
            // Set timeout to avoid too many requests
            searchTimeout = setTimeout(function() {
                console.log('🔧 Timeout triggered, performing search for:', query);
                performProductSearch(query);
            }, 300);
        });

        // Handle search input focus
        searchInput.on('focus', function() {
            const query = $(this).val().trim();
            if (query.length >= 3 && searchResults.find('.aebg-search-result-item').length > 0) {
                searchResults.addClass('show');
            }
        });

        // Handle clicking outside to close results
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.aebg-search-container').length) {
                searchResults.removeClass('show');
            }
        });

        // Initialize main search filters
        initMainSearchFilters();
    }

    /**
     * Get selected networks from the modern network selector
     */
    function getSelectedNetworks() {
        const selectedNetworks = [];
        
        // Get all checked network checkboxes
        $('.aebg-network-checkbox input[type="checkbox"]:checked').each(function() {
            const networkValue = $(this).val();
            if (networkValue && networkValue !== '') {
                selectedNetworks.push(networkValue);
            }
        });
        
        console.log('🔍 NETWORKS: Selected networks:', selectedNetworks);
        return selectedNetworks;
    }
    
    /**
     * Clear all network selections
     */
    function clearNetworkSelections() {
        $('.aebg-network-checkbox input[type="checkbox"]:checked').prop('checked', false);
        console.log('🔍 NETWORKS: Cleared all network selections');
    }

    /**
     * Initialize main search filters functionality
     */
    function initMainSearchFilters() {
        console.log('🔧 Initializing main search filters...');
        
        // Toggle search filters
        $(document).on('click', '#main-toggle-search', function() {
            console.log('🔧 Main toggle search clicked');
            const $filters = $('#main-search-filters');
            const $button = $(this);
            const $icon = $button.find('.dashicons');
            
            if ($filters.is(':visible')) {
                $filters.slideUp();
                $button.html('<span class="dashicons dashicons-arrow-down-alt2"></span>Show Search Filters');
            } else {
                $filters.slideDown();
                $button.html('<span class="dashicons dashicons-arrow-up-alt2"></span>Hide Search Filters');
            }
        });
        
        // Search button click
        $(document).on('click', '#main-search-products', function() {
            console.log('🔧 Main search products button clicked');
            performMainSearch();
        });
        
        // Clear button click
        $(document).on('click', '#main-clear-search', function() {
            clearMainSearch();
        });
        
        // Enter key for search
        $(document).on('keypress', '#main-search-name', function(e) {
            if (e.which === 13) {
                performMainSearch();
            }
        });
        
        // CRITICAL FIX: Add functionality to the simple search input in "Add More Products"
        // Handle typing in the simple search input
        console.log('🔧 Setting up search input event handler...');
        $(document).on('input', '.aebg-search-input', function() {
            const query = $(this).val().trim();
            console.log('🔧 Simple search input changed:', query);
            
            // Clear previous search results if query is empty
            if (!query) {
                $('.aebg-search-results').html('').removeClass('show');
                return;
            }
            
            // Perform simple search after a short delay (debounce)
            clearTimeout(window.simpleSearchTimeout);
            window.simpleSearchTimeout = setTimeout(() => {
                performSimpleSearch(query);
            }, 500);
        });
        
        // Handle Enter key in simple search input
        $(document).on('keypress', '.aebg-search-input', function(e) {
            if (e.which === 13) {
                const query = $(this).val().trim();
                if (query) {
                    console.log('🔧 Simple search Enter key pressed:', query);
                    performSimpleSearch(query);
                }
            }
        });
        
        // Handle "Add to Post" button clicks in search results
        $(document).on('click', '.aebg-btn-add-product', function() {
            const productId = $(this).data('product-id');
            const $resultItem = $(this).closest('.aebg-result-item');
            const productName = $resultItem.find('h5').text();
            
            console.log('🔧 Add to Post clicked for product:', productId, productName);
            
            if (productId) {
                // Add the product to the post
                addProductToPost(productId, productName);
                
                // Show success feedback
                $(this).text('Added!').prop('disabled', true).addClass('added');
                
                // Re-enable after 2 seconds
                setTimeout(() => {
                    $(this).text('Add to Post').prop('disabled', false).removeClass('added');
                }, 2000);
            } else {
                console.error('🔧 No product ID found for add to post');
            }
        });
    }

    /**
     * Perform simple search from the main search input (without advanced filters)
     */
    function performSimpleSearch(query) {
        console.log('🔧 Performing simple search for:', query);
        
        // Debug: Check if aebg_ajax object exists
        if (typeof aebg_ajax === 'undefined') {
            console.error('🔧 ERROR: aebg_ajax object is undefined!');
            $('.aebg-search-results').html('<div class="aebg-error">Error: AJAX configuration not loaded</div>').addClass('show');
            return;
        }
        
        // Debug: Check if search_products_nonce exists
        if (typeof aebg_ajax.search_products_nonce === 'undefined') {
            console.error('🔧 ERROR: search_products_nonce is undefined!');
            console.log('🔧 Available aebg_ajax properties:', Object.keys(aebg_ajax));
            $('.aebg-search-results').html('<div class="aebg-error">Error: Search nonce not available</div>').addClass('show');
            return;
        }
        
        console.log('🔧 AJAX configuration:', aebg_ajax);
        console.log('🔧 Search nonce:', aebg_ajax.search_products_nonce);
        
        // Show loading state
        const $searchResults = $('.aebg-search-results');
        $searchResults.html('<div class="aebg-loading">Searching products...</div>').addClass('show');
        
        // Build simple request data
        const requestData = {
            action: 'aebg_search_products_advanced',
            nonce: aebg_ajax.search_products_nonce,
            query: query,
            limit: 50
        };
        
        console.log('🔧 Request data being sent:', requestData);
        
        // Perform the search
        console.log('🔧 Sending AJAX request to:', aebg_ajax.ajaxurl);
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                console.log('🔧 Simple search response:', response);
                if (response.success) {
                    // Handle the response
                    const products = response.data.products || response.data || [];
                    displaySimpleSearchResults(products, query);
                } else {
                    // Handle error
                    console.error('🔧 Search failed with error:', response.data);
                    $searchResults.html('<div class="aebg-error">Search failed: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('🔧 Simple search AJAX error:', {xhr, status, error});
                console.error('🔧 Response text:', xhr.responseText);
                $searchResults.html('<div class="aebg-error">Search failed: ' + error + '</div>');
            }
        });
    }

    /**
     * Add a product to the post (wrapper for addProductToTable)
     */
    function addProductToPost(productId, productName) {
        console.log('🔧 Adding product to post:', { productId, productName });
        
        // Find the product data from the search results
        const $resultItem = $(`.aebg-result-item button[data-product-id="${productId}"]`).closest('.aebg-result-item');
        if ($resultItem.length === 0) {
            console.error('🔧 Product result item not found');
            return;
        }
        
        // Extract product data from the result item
        const productData = {
            id: productId,
            name: $resultItem.find('h5').text().trim(),
            price: parseFloat($resultItem.find('.aebg-result-price').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0,
            merchant: $resultItem.find('.aebg-result-merchant').text().trim(),
            image: $resultItem.find('img').attr('src') || '',
            brand: $resultItem.find('.aebg-result-merchant').text().trim(),
            network: 'Unknown',
            rating: 0
        };
        
        console.log('🔧 Extracted product data:', productData);
        
        // Add the product to the table
        const productNumber = addProductToTable(productData);
        
        if (productNumber) {
            console.log('🔧 Product added successfully with number:', productNumber);
            // Show success feedback
            $(`.aebg-result-item button[data-product-id="${productId}"]`).text('Added!').prop('disabled', true).addClass('added');
            
            // Re-enable after 2 seconds
            setTimeout(() => {
                $(`.aebg-result-item button[data-product-id="${productId}"]`).text('Add to Post').prop('disabled', false).removeClass('added');
            }, 2000);
        } else {
            console.error('🔧 Failed to add product to table');
        }
    }

    /**
     * Display simple search results
     * For consistency, reuse the table-based renderer used by the main search.
     */
    function displaySimpleSearchResults(products, query) {
        const $searchResults = $('.aebg-search-results');

        // Preserve existing "no results" behaviour
        if (!products || products.length === 0) {
            $searchResults.html(
                '<div class="aebg-no-results">No products found for "' + query + '"</div>'
            );
            return;
        }

        // Reuse the existing table-based search results renderer
        displayMainSearchResults(products);
    }

    /**
     * Perform main search with filters
     */
    function performMainSearch() {
        console.log('🔧 Performing main search...');
        const searchData = {
            name: $('#main-search-name').val().trim(),
            brand: $('#main-search-brand').val().trim(),
            currency: $('#main-search-currency').val(),
            networks: getSelectedNetworks(),
            category: $('#main-search-category').val().trim(),
            min_rating: $('#main-search-rating').val(),
            min_price: $('#main-search-min-price').val().trim(),
            max_price: $('#main-search-max-price').val().trim(),
            limit: $('#main-search-limit').val(),
            has_image: $('#main-search-has-image').is(':checked'),
            sort_by: $('#main-search-sort').val() || 'relevance',
            in_stock_only: $('#main-search-in-stock').is(':checked')
        };
        
        // Validate at least name or brand is provided
        if (!searchData.name && !searchData.brand) {
            showError('Product name or brand is required for search');
            return;
        }
        
        // Show loading state
        console.log('🔧 Showing loading state...');
        const $searchResults = $('.aebg-search-results');
        console.log('🔧 Search results container found:', $searchResults.length);
        $searchResults.html('<div class="aebg-loading">Searching products...</div>').addClass('show');
        
        // Build request data, only including non-empty values
        const requestData = {
            action: 'aebg_search_products_advanced',
            nonce: aebg_ajax.search_products_nonce,
            limit: parseInt(searchData.limit) || 50
        };
        
        // Add query (name or brand)
        if (searchData.name) {
            requestData.query = searchData.name;
        }
        if (searchData.brand) {
            requestData.brand = searchData.brand;
        }
        
        // Only add currency if it's not "All Currencies"
        if (searchData.currency && searchData.currency !== 'All Currencies') {
            requestData.currency = searchData.currency;
        }
        
        // Add networks if selected
        if (searchData.networks && searchData.networks.length > 0 && !searchData.networks.includes('all')) {
            requestData.network_ids = searchData.networks;
        }
        
        // Add category if provided
        if (searchData.category) {
            requestData.category = searchData.category;
        }
        
        // Add rating filter if provided
        if (searchData.min_rating) {
            requestData.min_rating = parseInt(searchData.min_rating);
        }
        
        // Only add price filters if they have actual values (not empty, not 0)
        if (searchData.min_price && searchData.min_price !== '' && parseFloat(searchData.min_price) > 0) {
            requestData.min_price = parseFloat(searchData.min_price);
        }
        
        if (searchData.max_price && searchData.max_price !== '' && parseFloat(searchData.max_price) > 0) {
            requestData.max_price = parseFloat(searchData.max_price);
        }
        
        // Add has_image filter
        if (searchData.has_image) {
            requestData.has_image = true;
        }
        
        // Add sort_by
        if (searchData.sort_by) {
            requestData.sort_by = searchData.sort_by;
        }
        
        // Add in_stock_only
        if (searchData.in_stock_only) {
            requestData.in_stock_only = true;
        }
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                if (response.success) {
                    // Handle the correct response structure
                    const products = response.data.products || response.data || [];
                    
                    if (products.length > 0) {
                        displayMainSearchResults(products);
                    } else {
                        $('.aebg-search-results').html('<div class="aebg-search-no-results">No products found for your search criteria</div>');
                    }
                } else {
                    $('.aebg-search-results').html('<div class="aebg-error">Search failed: ' + (response.data || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Search error:', xhr, status, error);
                $('.aebg-search-results').html('<div class="aebg-error">Search failed: ' + error + '</div>');
            }
        });
    }

    /**
     * Clear main search filters
     */
    function clearMainSearch() {
        $('#main-search-name').val('');
        $('#main-search-brand').val('');
        $('#main-search-currency').val('');
        clearNetworkSelections();
        $('#main-search-category').val('');
        $('#main-search-rating').val('');
        $('#main-search-min-price').val('');
        $('#main-search-max-price').val('');
        $('#main-search-limit').val('50');
        $('#main-search-has-image').prop('checked', false);
        $('#main-search-sort').val('relevance');
        $('#main-search-in-stock').prop('checked', false);
        $('.aebg-search-results').removeClass('show').html('');
    }

    /**
     * Initialize search result event handlers
     */
    function initSearchResultHandlers() {
        // Handle result item click
        $(document).on('click', '.aebg-search-result-item', function() {
            const productData = $(this).data('product');
            addProductToTable(productData);
            $('.aebg-search-results').removeClass('show').html('');
            $('.aebg-search-input').val('');
        });

        // Handle Add button click in search results
        $(document).on('click', '.aebg-btn-add', function() {
            try {
                const rawData = $(this).attr('data-product');
                const productData = JSON.parse(decodeURIComponent(rawData));
                addProductToTable(productData);
                $('.aebg-search-results').removeClass('show').html('');
                $('.aebg-search-input').val('');
            } catch (error) {
                console.error('Error parsing product data for Add:', error);
            }
        });

        // Handle Add to Comparison button click in main search results
        $(document).on('click', '.aebg-btn-add-to-comparison', function() {
            try {
                const $button = $(this);
                const productId = $button.data('product-id');
                
                if (!productId) {
                    console.error('No product ID found for Add to Comparison button');
                    showError('Product ID not found');
                    return;
                }
                
                // Find the product data from the search results
                const $row = $button.closest('tr');
                const productData = {
                    id: productId,
                    name: $row.find('.aebg-product-name').text().trim(),
                    brand: $row.find('.aebg-brand').text().trim(),
                    merchant: $row.find('.aebg-merchant').text().trim(),
                    price: parseFloat($row.find('.aebg-merchant-price').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0,
                    rating: parseFloat($row.find('.aebg-rating-text').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0,
                    network: $row.find('.aebg-network').text().trim() || 'Unknown',
                    availability: 'in_stock',
                    url: $row.find('.aebg-product-url').data('url') || ''
                };
                
                console.log('Adding product to comparison:', productData);
                addProductToComparison(productData);
                
            } catch (error) {
                console.error('Error adding product to comparison:', error);
                showError('Failed to add product to comparison: ' + error.message);
            }
        });

        // Handle Replace button click in search results
        console.log('🔧 Setting up Replace button event handler...');
        $(document).on('click', '.aebg-btn-replace', function() {
            try {
                console.log('🔧 Replace button clicked');
                console.log('🔧 Button element:', this);
                console.log('🔧 Button HTML:', $(this).prop('outerHTML'));
                
                const rawData = $(this).attr('data-product');
                console.log('🔧 Raw product data:', rawData);
                
                if (!rawData) {
                    console.error('🔧 No product data found in data-product attribute');
                    console.error('🔧 Button attributes:', $(this).prop('attributes'));
                    return;
                }
                
                const productData = JSON.parse(decodeURIComponent(rawData));
                console.log('🔧 Parsed product data:', productData);
                
                if (!productData || !productData.name) {
                    console.error('🔧 Invalid product data structure:', productData);
                    return;
                }
                
                console.log('🔧 Calling showReplaceProductDialog...');
                showReplaceProductDialog(productData);
                console.log('🔧 showReplaceProductDialog called successfully');
            } catch (error) {
                console.error('🔧 Error parsing product data for Replace:', error);
                console.error('🔧 Raw data was:', $(this).attr('data-product'));
                console.error('🔧 Error details:', error.message);
            }
        });

        // Handle Add to Comparison button click in search results
        $(document).on('click', '.aebg-btn-add-to-comparison', function() {
            try {
                const $button = $(this);
                const productId = $button.data('product-id');
                
                if (!productId) {
                    console.error('No product ID found for Add to Comparison button');
                    showError('Product ID not found');
                    return;
                }
                
                // Find the product data from the search results
                const $row = $button.closest('tr');
                const productData = {
                    id: productId,
                    name: $row.find('.aebg-product-name').text().trim(),
                    brand: $row.find('.aebg-brand').text().trim(),
                    merchant: $row.find('.aebg-merchant').text().trim(),
                    price: parseFloat($row.find('.aebg-merchant-price').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0,
                    rating: parseFloat($row.find('.aebg-rating-text').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0,
                    network: $row.find('.aebg-network').text().trim() || 'Unknown',
                    availability: 'in_stock',
                    url: $row.find('.aebg-product-url').data('url') || ''
                };
                
                console.log('Adding product to comparison:', productData);
                addProductToComparison(productData);
                
            } catch (error) {
                console.error('Error adding product to comparison:', error);
                showError('Failed to add product to comparison: ' + error.message);
            }
        });
    }

    /**
     * Perform product search via AJAX
     */
    function performProductSearch(query) {
        const searchResults = $('.aebg-search-results');
        
        if (isSearching) {
            return;
        }

        // Validate query
        if (!query || query.trim().length < 2) {
            searchResults.html('<div class="aebg-search-no-results">Please enter at least 2 characters to search</div>').addClass('show');
            return;
        }

        isSearching = true;
        searchResults.html('<div class="aebg-search-loading">Searching for products...</div>').addClass('show');

        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_search_products_advanced',
                nonce: aebg_ajax.nonce,
                query: query,
                limit: 10,
                currency: 'DKK',
                country: 'DK',
                has_image: true,
                in_stock_only: true
            },
            success: function(response) {
                isSearching = false;
                
                if (response.success && response.data && response.data.products && response.data.products.length > 0) {
                    displayMainSearchResults(response.data.products);
                } else if (response.success && response.data && Array.isArray(response.data) && response.data.length > 0) {
                    // Fallback for direct array response
                    displayMainSearchResults(response.data);
                } else {
                    searchResults.html('<div class="aebg-search-no-results">No products found for "' + query + '"<br><small>Try different keywords</small></div>');
                }
            },
            error: function(xhr, status, error) {
                isSearching = false;
                searchResults.html('<div class="aebg-search-no-results">Search failed. Please try again.</div>');
            }
        });
    }

    /**
     * Display search results in a table format identical to associated products
     */
    function displayMainSearchResults(products) {
        const searchResults = $('.aebg-search-results');
        
        let html = '';

        // Create header similar to associated products
        html += `
            <div class="aebg-associated-products-header">
                <h3>
                    <span class="aebg-icon">🔍</span>
                    Search Results (${products.length} products found)
                </h3>
            </div>
        `;

        // Create table structure similar to associated products (but without drag & drop)
        html += `
            <table class="aebg-associated-products-table">
                <thead>
                    <tr>
                        <th class="aebg-th-image">Image</th>
                        <th class="aebg-th-name">Product Name</th>
                        <th class="aebg-th-price">Price</th>
                        <th class="aebg-th-brand">Brand</th>
                        <th class="aebg-th-network">Network</th>
                        <th class="aebg-th-merchant">Merchant</th>
                        <th class="aebg-th-merchants">Merchants</th>
                        <th class="aebg-th-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;

        products.forEach(function(product) {
            const image = product.image_url || product.image || '';
            const currency = product.currency || 'DKK';
            const price = formatPrice(product.price, currency);
            const brand = product.brand || product.merchant || 'Unknown';
            const network = product.network || product.network_name || 'Unknown';
            
            // Create a more reliable product ID based on product properties
            const productId = generateProductId(product);
            
            const productJson = encodeURIComponent(JSON.stringify(product));

            // Fix image URL to prevent mixed content errors
            let safeImageUrl = '';
            if (image) {
                // Convert HTTP to HTTPS if needed
                safeImageUrl = image.replace(/^http:/, 'https:');
            }

            // Escape all user-generated content to prevent XSS
            const safeProductName = escapeHtml(product.name || '');
            const safeDisplayName = escapeHtml(product.display_name || product.name || '');
            const safeDescription = product.description ? escapeHtml(product.description.substring(0, 100)) + (product.description.length > 100 ? '...' : '') : '';
            const safeBrand = escapeHtml(brand);
            const safeNetwork = escapeHtml(network);
            const safeMerchant = escapeHtml(product.merchant || 'N/A');
            const safeTitle = product.display_name && product.display_name !== product.name 
                ? escapeHtml('Original name: ' + product.name + ' - Click to edit')
                : escapeHtml('Click to edit product name');
            const safeRenameTitle = escapeHtml('Renamed from: ' + product.name);

            html += `
                <tr class="aebg-product-row" data-product-id="${productId}">
                    <td class="aebg-td-image">
                        ${image ? `<img src="${safeImageUrl}" alt="${safeProductName}" onerror="this.style.display='none';">` : '<div class="aebg-no-image">No Image</div>'}
                    </td>
                    <td class="aebg-td-name">
                        <div class="aebg-product-name">
                            <strong class="aebg-product-name-text" data-product-id="${productId}" contenteditable="true" title="${safeTitle}">${safeDisplayName}</strong>
                            ${product.display_name && product.display_name !== product.name ? '<span class="aebg-original-name-indicator" title="' + safeRenameTitle + '">✏️</span>' : ''}
                            <button type="button" class="aebg-edit-name-btn" title="Edit product name" style="display: none;">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                            <button type="button" class="aebg-save-name-btn" title="Save changes" style="display: none;">
                                <span class="dashicons dashicons-yes"></span>
                            </button>
                            <button type="button" class="aebg-cancel-name-btn" title="Cancel editing" style="display: none;">
                                <span class="dashicons dashicons-no"></span>
                            </button>
                        </div>
                        ${product.description ? `<div class="aebg-product-description">${safeDescription}</div>` : ''}
                        <div class="aebg-product-meta">
                            <span>💰 ${price}</span>
                            <span>🏷️ ${safeBrand}</span>
                        </div>
                    </td>
                    <td class="aebg-td-price">
                        <span class="aebg-price">${price}</span>
                    </td>
                    <td class="aebg-td-brand">
                        <span class="aebg-brand">${safeBrand}</span>
                    </td>
                    <td class="aebg-td-network">
                        <span class="aebg-network">${safeNetwork}</span>
                    </td>
                    <td class="aebg-td-merchant">
                        <span class="aebg-merchant">${safeMerchant}</span>
                        <br>
                        <span class="aebg-merchant-price">${price}</span>
                    </td>
                    <td class="aebg-td-merchants">
                        <span class="aebg-merchants-placeholder">—</span>
                    </td>
                    <td class="aebg-td-actions">
                        <button type="button" class="aebg-btn-add" data-product="${productJson}">
                            <span>➕</span>
                            Add
                        </button>
                        <button type="button" class="aebg-btn-replace" data-product="${productJson}">
                            <span>🔄</span>
                            Replace
                        </button>
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;

        searchResults.html(html).addClass('show');
    }

    /**
     * Add a new product to the table
     * @param {Object} productData - The product data to add
     * @param {number} [productNumber] - Optional specific product number (1-based)
     */
    function addProductToTable(productData, productNumber = null) {
        // Specifically target the associated products table, not the search results table
        const associatedProductsTable = $('.aebg-associated-products-container .aebg-associated-products-table tbody');
        
        // Create a more reliable product ID based on product properties
        const productId = generateProductId(productData);
        
        console.log('Adding product to associated products table:', {
            productId: productId,
            productName: productData.name,
            productNumber: productNumber,
            existingProducts: associatedProductsTable.find('tr').map(function() {
                return {
                    id: $(this).data('product-id'),
                    name: $(this).find('.aebg-product-name').text().trim(),
                    number: $(this).data('product-number')
                };
            }).get()
        });
        
        // Check if product already exists in the associated products table only
        // Skip this check if we're doing a replacement (productNumber is specified)
        if (productNumber === null) {
            const existingProduct = associatedProductsTable.find(`tr[data-product-id="${productId}"]`);
            if (existingProduct.length > 0) {
                console.log('Product already exists in associated products table:', {
                    productId: productId,
                    productName: productData.name,
                    existingProductName: existingProduct.find('.aebg-product-name').text().trim(),
                    existingProductId: existingProduct.data('product-id'),
                    existingProductNumber: existingProduct.data('product-number'),
                    totalProductsInTable: associatedProductsTable.find('tr').length
                });
                showMessage('Product already added to the list', 'warning');
                return;
            }

            // Additional check: look for products with the same name and price (more specific check)
            const existingProducts = associatedProductsTable.find('tr');
            let duplicateFound = false;
            
            existingProducts.each(function() {
                const existingName = $(this).find('.aebg-product-name').text().trim();
                const existingPrice = $(this).find('.aebg-product-meta span:first').text().trim();
                const currentPrice = formatPrice(productData.price, productData.currency || 'DKK');
                
                // Check if name and price match (case-insensitive)
                if (existingName.toLowerCase() === productData.name.trim().toLowerCase() && 
                    existingPrice === currentPrice) {
                    console.log('Duplicate product found by name and price:', {
                        existingName: existingName,
                        existingPrice: existingPrice,
                        newName: productData.name,
                        newPrice: currentPrice
                    });
                    duplicateFound = true;
                    return false; // Break the loop
                }
            });
            
            if (duplicateFound) {
                showMessage('Product already added to the list', 'warning');
                return;
            }
        }

        // Determine the product number for the new product
        let newProductNumber;
        if (productNumber !== null) {
            // Use the specified product number (for replacements)
            newProductNumber = productNumber;
        } else {
            // Find the next available product number (for additions)
            const existingRows = associatedProductsTable.find('tr');
            if (existingRows.length === 0) {
                newProductNumber = 1;
            } else {
                // Find the highest product number and add 1
                let maxProductNumber = 0;
                existingRows.each(function() {
                    const rowProductNumber = $(this).data('product-number') || 0;
                    if (rowProductNumber > maxProductNumber) {
                        maxProductNumber = rowProductNumber;
                    }
                });
                newProductNumber = maxProductNumber + 1;
            }
        }

        const image = productData.image_url || productData.image || '';
        const price = formatPrice(productData.price, productData.currency || 'DKK');
        const brand = productData.brand || productData.merchant || 'Unknown';
        const rating = productData.rating || 0;
        const stars = generateStars(rating);

        const row = `
            <tr class="aebg-product-row" data-product-id="${productId}" data-product-number="${newProductNumber}">
                <td class="aebg-td-drag">
                    <span class="aebg-drag-handle">⋮⋮</span>
                </td>
                <td class="aebg-td-image">
                    ${image ? `<img src="${image}" alt="${escapeHtml(productData.name)}" onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"><div class="aebg-no-image" style="display: none;">No Image</div>` : '<div class="aebg-no-image">No Image</div>'}
                </td>
                <td class="aebg-td-name">
                    <div class="aebg-product-name">
                        <strong class="aebg-product-name-text" data-product-id="${productId}" contenteditable="true" title="${productData.display_name && productData.display_name !== productData.name ? 'Original name: ' + productData.name + ' - Click to edit' : 'Click to edit product name'}">${productData.display_name || productData.name}</strong>
                        ${productData.display_name && productData.display_name !== productData.name ? '<span class="aebg-original-name-indicator" title="Renamed from: ' + productData.name + '">✏️</span>' : ''}
                        <button type="button" class="aebg-edit-name-btn" title="Edit product name" style="display: none;">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button type="button" class="aebg-save-name-btn" title="Save changes" style="display: none;">
                            <span class="dashicons dashicons-yes"></span>
                        </button>
                        <button type="button" class="aebg-cancel-name-btn" title="Cancel editing" style="display: none;">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                    ${productData.description ? `<div class="aebg-product-description">${productData.description.substring(0, 100)}${productData.description.length > 100 ? '...' : ''}</div>` : ''}
                    <div class="aebg-product-meta">
                        <span>💰 ${price}</span>
                        <span>🏷️ ${brand}</span>
                        <span>⭐ ${rating}/5</span>
                    </div>
                </td>
                <td class="aebg-td-price">
                    <div class="aebg-price-range-display" data-product-id="${productId}">
                        <div class="aebg-price-lowest">
                            <span class="aebg-price-label">Lowest:</span>
                            <span class="aebg-price-value">Loading...</span>
                        </div>
                        <div class="aebg-price-highest">
                            <span class="aebg-price-label">Highest:</span>
                            <span class="aebg-price-value">Loading...</span>
                        </div>
                    </div>
                </td>
                <td class="aebg-td-brand">
                    <span class="aebg-brand">${brand}</span>
                </td>
                <td class="aebg-td-network">
                    <span class="aebg-network">${productData.network || productData.network_name || 'Unknown'}</span>
                </td>
                <td class="aebg-td-merchant">
                    <span class="aebg-merchant">${productData.merchant || 'N/A'}</span>
                    <br>
                    <span class="aebg-merchant-price">${price}</span>
                </td>
                <td class="aebg-td-rating">
                    <div class="aebg-rating" data-product-id="${productId}">
                        <div class="aebg-rating-editor">
                            <div class="aebg-stars-container">
                                <span class="aebg-star" data-rating="1">☆</span>
                                <span class="aebg-star" data-rating="2">☆</span>
                                <span class="aebg-star" data-rating="3">☆</span>
                                <span class="aebg-star" data-rating="4">☆</span>
                                <span class="aebg-star" data-rating="5">☆</span>
                            </div>
                            <input type="number" class="aebg-rating-input" value="${rating}" min="0" max="5" step="0.1" />
                            <span class="aebg-rating-slash">/5</span>
                        </div>
                        <div class="aebg-rating-display">
                            <div class="aebg-stars">${stars}</div>
                            <div class="aebg-rating-text">
                                ${rating}/5
                                <span class="aebg-edit-icon" title="Click to edit rating">✏️</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="aebg-td-merchants">
                    <div class="aebg-merchants-info" data-product-id="${productId}">
                        <div class="aebg-merchants-count">
                            <span class="aebg-merchants-icon">🏪</span>
                            <span class="aebg-merchants-number">Loading...</span>
                        </div>
                    </div>
                </td>
                <td class="aebg-td-actions">
                    <button type="button" class="aebg-btn-remove" data-product-id="${productId}">
                        <span>🗑️</span>
                        Remove
                    </button>
                </td>
            </tr>
        `;

        // Insert at the correct position if productNumber is specified (for replacements)
        if (productNumber !== null) {
            // For replacements, we want to insert at the specific position
            // Since we just removed the old product, we need to find where to insert
            const $rows = associatedProductsTable.find('tr');
            
            if ($rows.length === 0) {
                // Table is empty, just append
                associatedProductsTable.append(row);
            } else if (productNumber === 1) {
                // Inserting at position 1 means prepending to the beginning
                associatedProductsTable.prepend(row);
            } else {
                // Find the first row with product-number >= our target and insert before it
                let inserted = false;
                $rows.each(function() {
                    const rowProductNumber = $(this).data('product-number') || 0;
                    if (rowProductNumber >= productNumber) {
                        $(row).insertBefore($(this));
                        inserted = true;
                        return false; // Break the loop
                    }
                });
                
                // If no row found with higher number, append to end
                if (!inserted) {
                    associatedProductsTable.append(row);
                }
            }
        } else {
            // For new products, just append to the end
            associatedProductsTable.append(row);
        }
        
        // Hide empty state if it exists
        $('.aebg-empty-state').hide();
        
        // Update products count
        updateProductsCount();
        
        // Update hidden input
        updateProductIds();
        
        // Save full product data to database
        // Only save automatically if this is NOT a replacement (productNumber === null means new product)
        // For replacements, the caller (replaceProduct) will handle the save
        if (productNumber === null) {
            // Ensure the productData has the id field set
            const productDataToSave = {
                ...productData,
                id: productId
            };
            saveProductDataToDatabase(productDataToSave, newProductNumber);
            
            // Update template structure for new products (not for replacements)
            updateTemplateForNewProduct(newProductNumber);
            
            // Only show success message for new products, not replacements
            showMessage('Product added successfully', 'success');
        }
        // For replacements, don't show the generic success message - the replacement progress indicator handles that
        
        // Load merchant count only for the newly added/replaced product (not all products)
        // Use debounced version to prevent multiple rapid calls
        if (productNumber !== null) {
            // For replacements, only load merchant count for this specific product
            loadMerchantCountForProduct(productId);
        } else {
            // For new products, load all (but debounced)
            debouncedLoadMerchantCounts();
        }
        
        // Initialize rating editor for the new product
        const $newRating = associatedProductsTable.find(`tr[data-product-id="${productId}"] .aebg-rating`);
        const $input = $newRating.find('.aebg-rating-input');
        const $starsContainer = $newRating.find('.aebg-stars-container');
        const initialRating = parseFloat($input.val()) || 0;
        
        $starsContainer.find('.aebg-star').each(function(index) {
            const starIndex = index + 1;
            if (starIndex <= initialRating) {
                $(this).text('★').addClass('filled');
            } else {
                $(this).text('☆').removeClass('filled');
            }
        });
        
        // Re-initialize drag and drop for the updated table
        initDragAndDrop();
        
        // Return the product number for potential use
        return newProductNumber;
    }

    /**
     * Save product data to database via AJAX
     */
    function saveProductDataToDatabase(productData, productNumber = null) {
        const postId = getCurrentPostId();
        if (!postId) {
            console.error('Could not determine post ID for saving product data');
            return Promise.reject('Could not determine post ID');
        }

        // Debug logging
        console.log('[AEBG] saveProductDataToDatabase called with:', {
            postId: postId,
            productNumber: productNumber,
            productData: productData
        });

        const ajaxData = {
            action: 'aebg_save_product_data',
            nonce: aebg_ajax.nonce,
            post_id: postId,
            product_data: productData
        };

        // Add product number if specified
        if (productNumber !== null) {
            ajaxData.product_number = productNumber;
        }

        console.log('[AEBG] Sending AJAX data:', ajaxData);

        return new Promise((resolve, reject) => {
            $.ajax({
                url: aebg_ajax.ajaxurl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    console.log('[AEBG] AJAX response received:', response);
                    if (response.success) {
                        console.log('Product data saved successfully:', response.data);
                        // Pass full response data including Action Scheduler status
                        resolve(response.data || {});
                    } else {
                        console.error('Failed to save product data:', response);
                        reject(response.data || 'Unknown error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error saving product data:', error);
                    console.error('XHR status:', status);
                    console.error('XHR response:', xhr.responseText);
                    reject(error);
                }
            });
        });
    }

    /**
     * Initialize remove product functionality
     */
    function initRemoveProducts() {
        $(document).on('click', '.aebg-btn-remove', function() {
            const productId = $(this).data('product-id');
            const row = $(this).closest('tr');
            const removedProductNumber = row.data('product-number');
            const postId = getCurrentPostId();
            
            if (!productId || !removedProductNumber || !postId) {
                console.error('Missing product information:', {
                    productId: productId,
                    removedProductNumber: removedProductNumber,
                    postId: postId
                });
                showMessage('Error: Missing product information', 'error');
                return;
            }

            // Show loading state
            row.addClass('updating');
            const $removeBtn = $(this);
            const originalText = $removeBtn.text();
            $removeBtn.text('⏳').prop('disabled', true);
            showMessage('Removing product...', 'info');

            console.log('Removing product:', {
                productId: productId,
                removedProductNumber: removedProductNumber,
                postId: postId
            });

            // Call AJAX to remove product from database
            $.ajax({
                url: aebg_ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aebg_remove_product',
                    post_id: postId,
                    product_id: productId,
                    removed_product_number: removedProductNumber,
                    nonce: aebg_ajax.nonce
                },
                success: function(response) {
                    console.log('Remove product response:', response);
                    
                    if (response.success) {
                        // Immediately start the removal animation
                        removeProductDynamically(row, removedProductNumber, response);
                    } else {
                        // Reset button state
                        $removeBtn.text(originalText).prop('disabled', false);
                        row.removeClass('updating');
                        const errorMessage = response.data || 'Unknown error';
                        console.error('Failed to remove product:', errorMessage);
                        showMessage('Failed to remove product: ' + errorMessage, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    // Reset button state
                    $removeBtn.text(originalText).prop('disabled', false);
                    row.removeClass('updating');
                    console.error('Product removal error:', {
                        status: status,
                        error: error,
                        xhr: xhr,
                        responseText: xhr.responseText
                    });
                    
                    // Try to parse the response text for more detailed error information
                    let errorMessage = error;
                    try {
                        if (xhr.responseText) {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data) {
                                errorMessage = errorResponse.data;
                            }
                        }
                    } catch (e) {
                        console.log('Could not parse error response:', e);
                    }
                    
                    showMessage('Failed to remove product: ' + errorMessage, 'error');
                }
            });
        });
    }

    /**
     * Remove product dynamically with smooth animations
     */
    function removeProductDynamically(row, removedProductNumber, response) {
        console.log('Starting dynamic removal for product-' + removedProductNumber);
        
        // Step 1: Remove the product row with animation
        row.fadeOut(400, function() {
            $(this).remove();
            
            // Step 2: Remove Elementor containers with animation
            removeElementorContainersDynamically(removedProductNumber);
            
            // Step 3: Reposition remaining products
            repositionProductsAfterRemoval(removedProductNumber);
            
            // Step 4: Update UI elements
            updateProductsCount();
            updateProductIds();
            
            // Step 5: Show empty state if no products left
            if ($('.aebg-associated-products-container .aebg-associated-products-table tbody tr').length === 0) {
                showEmptyState();
            }
            
            // Step 6: Re-initialize drag and drop for the updated table
            initDragAndDrop();
            
            // Step 7: Show success message
            let message = response.data && response.data.message ? response.data.message : 'Product removed successfully';
            if (response.data && response.data.warning) {
                message += ' (' + response.data.warning + ')';
            }
            showMessage(message, 'success');
        });
    }

    /**
     * Remove Elementor containers dynamically with smooth animations
     */
    function removeElementorContainersDynamically(removedProductNumber) {
        console.log('Removing Elementor containers dynamically for product-' + removedProductNumber);
        
        let containersRemoved = 0;
        
        // Method 1: Remove by exact CSS ID match
        const exactIdSelector = `[id="product-${removedProductNumber}"], [id*="product-${removedProductNumber}-"]`;
        const exactIdContainers = $(exactIdSelector);
        
        exactIdContainers.each(function() {
            const $container = $(this);
            const containerId = $container.attr('id');
            console.log('Removing container by exact ID:', containerId);
            
            $container.fadeOut(400, function() {
                $(this).remove();
                containersRemoved++;
            });
        });
        
        // Method 2: Remove by data-element-id attribute
        const dataIdSelector = `[data-element-id="product-${removedProductNumber}"], [data-element-id*="product-${removedProductNumber}-"]`;
        const dataIdContainers = $(dataIdSelector);
        
        dataIdContainers.each(function() {
            const $container = $(this);
            const dataElementId = $container.attr('data-element-id');
            console.log('Removing container by data-element-id:', dataElementId);
            
            if (!$container.is(':visible')) return; // Skip if already being removed
            
            $container.fadeOut(400, function() {
                $(this).remove();
                containersRemoved++;
            });
        });
        
        // Method 3: Remove by Elementor-specific selectors
        const elementorSelectors = [
            `.elementor-element[data-element-id="product-${removedProductNumber}"]`,
            `.elementor-widget-container[data-element-id="product-${removedProductNumber}"]`,
            `.elementor-container[data-element-id="product-${removedProductNumber}"]`,
            `.elementor-element[data-element-id*="product-${removedProductNumber}-"]`,
            `.elementor-widget-container[data-element-id*="product-${removedProductNumber}-"]`,
            `.elementor-container[data-element-id*="product-${removedProductNumber}-"]`
        ];
        
        elementorSelectors.forEach(selector => {
            const containers = $(selector);
            containers.each(function() {
                const $container = $(this);
                const dataElementId = $container.attr('data-element-id');
                console.log('Removing Elementor container:', dataElementId);
                
                if (!$container.is(':visible')) return; // Skip if already being removed
                
                $container.fadeOut(400, function() {
                    $(this).remove();
                    containersRemoved++;
                });
            });
        });
        
        console.log(`Removed ${containersRemoved} containers for product-${removedProductNumber}`);
        return containersRemoved;
    }

    /**
     * Reposition products after a product is removed
     * @param {number} removedProductNumber - The product number that was removed
     */
    function repositionProductsAfterRemoval(removedProductNumber) {
        if (!removedProductNumber) {
            console.warn('No product number found for removed product');
            return;
        }

        console.log('Repositioning products after removing product-' + removedProductNumber);

        // Get all remaining product rows
        const remainingRows = $('.aebg-associated-products-container .aebg-associated-products-table tbody tr');
        
        remainingRows.each(function() {
            const $row = $(this);
            const currentProductNumber = $row.data('product-number');
            
            if (currentProductNumber && currentProductNumber > removedProductNumber) {
                // This product needs to be repositioned
                const newProductNumber = currentProductNumber - 1;
                $row.attr('data-product-number', newProductNumber);
                
                // Update the product number display in the row
                const $productNumberCell = $row.find('.aebg-product-number');
                if ($productNumberCell.length) {
                    $productNumberCell.text(newProductNumber);
                }
                
                // Also update the corresponding Elementor container
                updateElementorContainerNumber(currentProductNumber, newProductNumber);
                
                console.log('Repositioned product from ' + currentProductNumber + ' to ' + newProductNumber);
            }
        });

        // Note: Template update is now handled in the ajax_remove_product handler
    }

    /**
     * Remove Elementor container from frontend when a product is removed
     * @param {number} removedProductNumber - The product number that was removed
     */
    function removeElementorContainerFromFrontend(removedProductNumber) {
        console.log('Removing Elementor container for product-' + removedProductNumber + ' from frontend');
        
        let containersRemoved = false;
        
        try {
            // Method 1: Remove container by CSS ID (most common) - be more specific
            const containerSelector = `[id*="product-${removedProductNumber}"]`;
            const containers = $(containerSelector);
            
            if (containers.length > 0) {
                containers.each(function() {
                    const $container = $(this);
                    const containerId = $container.attr('id');
                    console.log('Removing container by ID:', containerId);
                    
                    // Only remove if it's actually a product container (not just any element with the number)
                    if (containerId && containerId.includes(`product-${removedProductNumber}`)) {
                        $container.fadeOut(300, function() {
                            $(this).remove();
                        });
                        containersRemoved = true;
                    }
                });
            } else {
                console.log('No containers found with selector:', containerSelector);
            }

            // Method 2: Remove container by data-element-id attribute - be more specific
            const dataContainerSelector = `[data-element-id*="product-${removedProductNumber}"]`;
            const dataContainers = $(dataContainerSelector);
            
            if (dataContainers.length > 0) {
                dataContainers.each(function() {
                    const $container = $(this);
                    const dataElementId = $container.attr('data-element-id');
                    console.log('Removing container by data-element-id:', dataElementId);
                    
                    // Only remove if it's actually a product container
                    if (dataElementId && dataElementId.includes(`product-${removedProductNumber}`)) {
                        $container.fadeOut(300, function() {
                            $(this).remove();
                        });
                        containersRemoved = true;
                    }
                });
            }

            // Method 3: Remove container by Elementor-specific classes and data attributes
            const elementorContainerSelector = `.elementor-element[data-element-id*="product-${removedProductNumber}"], .elementor-widget-container[data-element-id*="product-${removedProductNumber}"], .elementor-container[data-element-id*="product-${removedProductNumber}"]`;
            const elementorContainers = $(elementorContainerSelector);
            
            if (elementorContainers.length > 0) {
                elementorContainers.each(function() {
                    const $container = $(this);
                    const dataElementId = $container.attr('data-element-id');
                    console.log('Removing Elementor container by class:', $container.attr('class'), 'data-element-id:', dataElementId);
                    
                    // Only remove if it's actually a product container
                    if (dataElementId && dataElementId.includes(`product-${removedProductNumber}`)) {
                        $container.fadeOut(300, function() {
                            $(this).remove();
                        });
                        containersRemoved = true;
                    }
                });
            }

            // Method 4: Remove container by looking for nested elements with the product pattern - more targeted
            const nestedContainerSelector = `*[id*="product-${removedProductNumber}"], *[data-element-id*="product-${removedProductNumber}"]`;
            const nestedContainers = $(nestedContainerSelector);
            
            if (nestedContainers.length > 0) {
                nestedContainers.each(function() {
                    const $container = $(this);
                    const id = $container.attr('id') || '';
                    const dataElementId = $container.attr('data-element-id') || '';
                    
                    // Only remove if it's actually a product container (not just any element with the number)
                    if ((id && id.includes(`product-${removedProductNumber}`)) || 
                        (dataElementId && dataElementId.includes(`product-${removedProductNumber}`))) {
                        
                        console.log('Removing nested container:', {id, dataElementId});
                        $container.fadeOut(300, function() {
                            $(this).remove();
                        });
                        containersRemoved = true;
                    }
                });
            }

            // Method 5: Force Elementor to refresh if we're in Elementor editor
            if (window.elementor && window.elementor.documents) {
                try {
                    const currentDocument = window.elementor.documents.getCurrentDocument();
                    if (currentDocument && currentDocument.container) {
                        // Trigger a refresh to update the editor
                        if (currentDocument.container.model) {
                            currentDocument.container.model.trigger('change');
                            console.log('Elementor document model change triggered for container removal');
                            containersRemoved = true;
                        }
                    }
                } catch (e) {
                    console.log('Error refreshing Elementor document for container removal:', e);
                }
            }

            // Method 6: If no containers were found or removed, force a page refresh
            if (!containersRemoved) {
                console.log('No containers found in DOM, forcing page refresh to update frontend');
                
                // Check if we're in Elementor editor
                if (window.location.href.includes('action=elementor')) {
                    // In Elementor editor, try to reload the document
                    if (window.elementor && window.elementor.documents) {
                        try {
                            const currentDocument = window.elementor.documents.getCurrentDocument();
                            if (currentDocument && currentDocument.reload) {
                                currentDocument.reload();
                                console.log('Elementor document reloaded');
                                return;
                            }
                        } catch (e) {
                            console.log('Error reloading Elementor document:', e);
                        }
                    }
                }
                
                // If we're not in Elementor editor or reload failed, refresh the page
                setTimeout(() => {
                    console.log('Refreshing page to update frontend after container removal');
                    window.location.reload();
                }, 1000);
            } else {
                console.log('Successfully removed containers from frontend');
            }

        } catch (e) {
            console.error('Error removing Elementor container from frontend:', e);
            
            // If there was an error, force a page refresh as a fallback
            setTimeout(() => {
                console.log('Refreshing page due to error in container removal');
                window.location.reload();
            }, 1000);
        }
    }

    /**
     * Update Elementor container number when a product is repositioned
     * @param {number} oldProductNumber - The old product number
     * @param {number} newProductNumber - The new product number
     */
    function updateElementorContainerNumber(oldProductNumber, newProductNumber) {
        console.log('Updating Elementor container from product-' + oldProductNumber + ' to product-' + newProductNumber);
        
        try {
            // Update container by exact CSS ID match
            const exactIdSelector = `[id="product-${oldProductNumber}"], [id*="product-${oldProductNumber}-"]`;
            const exactIdContainers = $(exactIdSelector);
            
            exactIdContainers.each(function() {
                const $container = $(this);
                const oldId = $container.attr('id');
                const newId = oldId.replace(`product-${oldProductNumber}`, `product-${newProductNumber}`);
                
                console.log('Updating container ID from', oldId, 'to', newId);
                $container.attr('id', newId);
                
                // Also update any data attributes
                const oldDataId = $container.attr('data-element-id');
                if (oldDataId) {
                    const newDataId = oldDataId.replace(`product-${oldProductNumber}`, `product-${newProductNumber}`);
                    $container.attr('data-element-id', newDataId);
                }
            });

            // Update container by data-element-id attribute
            const dataIdSelector = `[data-element-id="product-${oldProductNumber}"], [data-element-id*="product-${oldProductNumber}-"]`;
            const dataIdContainers = $(dataIdSelector);
            
            dataIdContainers.each(function() {
                const $container = $(this);
                const oldDataId = $container.attr('data-element-id');
                const newDataId = oldDataId.replace(`product-${oldProductNumber}`, `product-${newProductNumber}`);
                
                console.log('Updating container data-element-id from', oldDataId, 'to', newDataId);
                $container.attr('data-element-id', newDataId);
                
                // Also update the ID if it exists
                const oldId = $container.attr('id');
                if (oldId) {
                    const newId = oldId.replace(`product-${oldProductNumber}`, `product-${newProductNumber}`);
                    $container.attr('id', newId);
                }
            });

            // Update Elementor-specific containers
            const elementorSelectors = [
                `.elementor-element[data-element-id="product-${oldProductNumber}"]`,
                `.elementor-widget-container[data-element-id="product-${oldProductNumber}"]`,
                `.elementor-container[data-element-id="product-${oldProductNumber}"]`,
                `.elementor-element[data-element-id*="product-${oldProductNumber}-"]`,
                `.elementor-widget-container[data-element-id*="product-${oldProductNumber}-"]`,
                `.elementor-container[data-element-id*="product-${oldProductNumber}-"]`
            ];
            
            elementorSelectors.forEach(selector => {
                const containers = $(selector);
                containers.each(function() {
                    const $container = $(this);
                    const oldDataId = $container.attr('data-element-id');
                    const newDataId = oldDataId.replace(`product-${oldProductNumber}`, `product-${newProductNumber}`);
                    
                    console.log('Updating Elementor container data-element-id from', oldDataId, 'to', newDataId);
                    $container.attr('data-element-id', newDataId);
                    
                    // Also update the ID if it exists
                    const oldId = $container.attr('id');
                    if (oldId) {
                        const newId = oldId.replace(`product-${oldProductNumber}`, `product-${newProductNumber}`);
                        $container.attr('id', newId);
                    }
                });
            });

        } catch (e) {
            console.error('Error updating Elementor container number:', e);
        }
    }

    /**
     * Initialize refresh merchants functionality
     */
    function initRefreshMerchants() {
        $(document).on('click', '#aebg-refresh-merchants', function() {
            const $btn = $(this);
            
            // Add loading state
            $btn.text('⏳').prop('disabled', true);
            
            // Simple page reload to get fresh data from database
            console.log('🔄 Manual refresh: Reloading page to get fresh merchant data');
            location.reload();
        });
    }

    /**
     * Initialize rating editor functionality
     */
    function initRatingEditor() {
        // Handle star click events (only for associated products)
        $(document).on('click', '.aebg-associated-products-table .aebg-star', function() {
            const $star = $(this);
            const $ratingContainer = $star.closest('.aebg-rating');
            const $starsContainer = $star.closest('.aebg-stars-container');
            const $ratingEditor = $ratingContainer.find('.aebg-rating-editor');
            const $input = $ratingContainer.find('.aebg-rating-input');
            const clickedRating = parseInt($star.data('rating'));
            
            // Add editing state
            $ratingEditor.addClass('editing');
            
            // Update stars display
            $starsContainer.find('.aebg-star').each(function(index) {
                const starIndex = index + 1;
                if (starIndex <= clickedRating) {
                    $(this).text('★').addClass('filled');
                } else {
                    $(this).text('☆').removeClass('filled');
                }
            });
            
            // Update input value
            $input.val(clickedRating);
            
            // Save rating
            saveProductRating($ratingContainer);
            
            // Remove editing state after a short delay
            setTimeout(() => {
                $ratingEditor.removeClass('editing');
            }, 2000);
        });

        // Handle star hover events (only for associated products)
        $(document).on('mouseenter', '.aebg-associated-products-table .aebg-star', function() {
            const $star = $(this);
            const $starsContainer = $star.closest('.aebg-stars-container');
            const hoverRating = parseInt($star.data('rating'));
            
            $starsContainer.find('.aebg-star').each(function(index) {
                const starIndex = index + 1;
                if (starIndex <= hoverRating) {
                    $(this).text('★').addClass('hover');
                } else {
                    $(this).text('☆').removeClass('hover');
                }
            });
        });

        $(document).on('mouseleave', '.aebg-associated-products-table .aebg-stars-container', function() {
            const $starsContainer = $(this);
            const $input = $starsContainer.closest('.aebg-rating').find('.aebg-rating-input');
            const currentRating = parseFloat($input.val()) || 0;
            
            $starsContainer.find('.aebg-star').each(function(index) {
                const starIndex = index + 1;
                if (starIndex <= currentRating) {
                    $(this).text('★').addClass('filled').removeClass('hover');
                } else {
                    $(this).text('☆').removeClass('filled hover');
                }
            });
        });

        // Handle input change events (only for associated products)
        $(document).on('input', '.aebg-associated-products-table .aebg-rating-input', function() {
            const $input = $(this);
            const $ratingContainer = $input.closest('.aebg-rating');
            const $starsContainer = $ratingContainer.find('.aebg-stars-container');
            const $ratingEditor = $ratingContainer.find('.aebg-rating-editor');
            const rating = parseFloat($input.val()) || 0;
            
            // Add editing state
            $ratingEditor.addClass('editing');
            
            // Update stars display
            $starsContainer.find('.aebg-star').each(function(index) {
                const starIndex = index + 1;
                if (starIndex <= rating) {
                    $(this).text('★').addClass('filled').removeClass('hover');
                } else {
                    $(this).text('☆').removeClass('filled hover');
                }
            });
        });

        // Handle input focus events (only for associated products)
        $(document).on('focus', '.aebg-associated-products-table .aebg-rating-input', function() {
            const $input = $(this);
            const $ratingContainer = $input.closest('.aebg-rating');
            const $ratingEditor = $ratingContainer.find('.aebg-rating-editor');
            $ratingEditor.addClass('editing');
        });

        // Handle input blur events (save on blur) - only for associated products
        $(document).on('blur', '.aebg-associated-products-table .aebg-rating-input', function() {
            const $input = $(this);
            const $ratingContainer = $input.closest('.aebg-rating');
            const $ratingEditor = $ratingContainer.find('.aebg-rating-editor');
            
            // Remove editing state
            $ratingEditor.removeClass('editing');
            
            // Save rating
            saveProductRating($ratingContainer);
        });

        // Initialize existing ratings
        $('.aebg-rating').each(function() {
            const $ratingContainer = $(this);
            const $input = $ratingContainer.find('.aebg-rating-input');
            const $starsContainer = $ratingContainer.find('.aebg-stars-container');
            const rating = parseFloat($input.val()) || 0;
            
            $starsContainer.find('.aebg-star').each(function(index) {
                const starIndex = index + 1;
                if (starIndex <= rating) {
                    $(this).text('★').addClass('filled');
                } else {
                    $(this).text('☆').removeClass('filled');
                }
            });
        });
    }

    /**
     * Save product rating via AJAX
     */
    function saveProductRating($ratingContainer) {
        const productId = $ratingContainer.data('product-id');
        const $input = $ratingContainer.find('.aebg-rating-input');
        const rating = parseFloat($input.val()) || 0;
        const postId = getCurrentPostId();

        if (!productId || !postId) {
            console.error('Missing product ID or post ID for rating save');
            return;
        }

        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_update_product_rating',
                nonce: aebg_ajax.nonce,
                post_id: postId,
                product_id: productId,
                rating: rating
            },
            success: function(response) {
                if (response.success) {
                    // Update the display version
                    const $display = $ratingContainer.find('.aebg-rating-display');
                    $display.find('.aebg-stars').html(response.data.stars);
                    $display.find('.aebg-rating-text').text(response.data.rating + '/5');
                    
                    // Update product meta in the name column
                    const $row = $ratingContainer.closest('tr');
                    const $meta = $row.find('.aebg-product-meta span:last');
                    $meta.text('⭐ ' + response.data.rating + '/5');
                    
                    showMessage('Rating updated successfully', 'success');
                } else {
                    showMessage('Failed to update rating: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                showMessage('Error updating rating: ' + error, 'error');
            }
        });
    }

    /**
     * Update product order via AJAX without page refresh
     */
    function updateProductOrder() {
        console.log('updateProductOrder called');
        updateProductIds();
        
        // Get current post ID from the page
        const postId = getCurrentPostId();
        if (!postId) {
            showMessage('Could not determine post ID', 'error');
            return;
        }
        
        // Get the new product order
        const newProductOrder = [];
        $('.aebg-associated-products-container .aebg-associated-products-table tbody tr').each(function(index) {
            const productId = $(this).data('product-id');
            console.log('Row ' + index + ' has product ID:', productId);
            newProductOrder.push(productId);
        });
        
        console.log('New product order:', newProductOrder);
        console.log('Post ID:', postId);
        console.log('AJAX URL:', aebg_ajax.ajaxurl);
        console.log('Nonce:', aebg_ajax.update_post_nonce);
        
        // Add loading state to all rows
        $('.aebg-product-row').addClass('updating');
        
        // Show loading state
        showMessage('Updating product order...', 'info');
        
        // Call AJAX to update the post's Elementor data
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_update_post_products',
                _ajax_nonce: aebg_ajax.update_post_nonce,
                post_id: postId,
                new_product_order: JSON.stringify(newProductOrder)
            },
            success: function(response) {
                console.log('AJAX success response:', response);
                // Remove loading state
                $('.aebg-product-row').removeClass('updating');
                
                if (response.success) {
                    // NEW: Check for conflicts first
                    if (response.data && response.data.has_conflicts && response.data.conflicts && response.data.conflicts.length > 0) {
                        console.log('Conflicts detected:', response.data.conflicts);
                        
                        // Use ReorderConflictHandler to show modal
                        if (window.ReorderConflictHandler) {
                            window.ReorderConflictHandler.showConflictModal(
                                response.data.conflicts,
                                postId,
                                newProductOrder
                            );
                        } else {
                            console.error('ReorderConflictHandler not available');
                            showMessage('Conflicts detected but handler not available. Please refresh the page.', 'error');
                        }
                        return;
                    }
                    
                    // Check if there were more products than containers
                    if (response.data && response.data.containers_created && response.data.containers_created > 0) {
                        showMessage('Product order updated successfully! Created ' + response.data.containers_created + ' new product containers to accommodate all ' + response.data.product_count + ' products.', 'success');
                    } else if (response.data && response.data.containers_used && response.data.total_containers) {
                        if (response.data.containers_used < response.data.total_containers) {
                            showMessage('Product order updated successfully! Note: Only ' + response.data.containers_used + ' out of ' + response.data.total_containers + ' products were reordered due to limited container availability.', 'warning');
                        } else if (response.data.containers_used < newProductOrder.length) {
                            showMessage('Product order updated successfully! Note: Only ' + response.data.containers_used + ' out of ' + newProductOrder.length + ' products were reordered due to limited container availability.', 'warning');
                        } else {
                            showMessage('Product order updated successfully!', 'success');
                        }
                    } else {
                        // Enhanced response handling
                        if (response.data && response.data.frontend_refresh_required) {
                            showMessage('Product order updated successfully! Refreshing content to show changes...', 'info');
                        } else {
                            showMessage('Product order updated successfully! Content refreshed dynamically.', 'success');
                        }
                    }
                    
                    // Update the hidden input with new order
                    updateProductIds();
                    
                    // Update products count
                    updateProductsCount();
                    
                    // Validate that Elementor data is still intact
                    if (window.location.href.includes('action=elementor')) {
                        try {
                            // Check if Elementor is still accessible
                            if (window.elementor && window.elementor.documents && window.elementor.documents.getCurrentDocument) {
                                const currentDocument = window.elementor.documents.getCurrentDocument();
                                if (currentDocument && currentDocument.container) {
                                    // Try to access the content to see if it's corrupted
                                    const content = currentDocument.container.content;
                                    if (content && typeof content === 'object') {
                                        console.log('Elementor content appears valid after reordering');
                                    } else {
                                        console.warn('Elementor content appears corrupted after reordering');
                                        showMessage('Warning: Elementor data may be corrupted. Please refresh the page.', 'warning');
                                    }
                                }
                            }
                        } catch (e) {
                            console.error('Error validating Elementor data after reordering:', e);
                            showMessage('Warning: Unable to validate Elementor data. Please refresh the page.', 'warning');
                        }
                    }
                    
                    // Update the frontend dynamically without page reload
                    updateFrontendDynamically(newProductOrder, response.data);
                } else {
                    console.error('AJAX response indicates failure:', response);
                    showMessage('Error updating product order: ' + (response.data?.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {xhr: xhr, status: status, error: error});
                console.error('Response text:', xhr.responseText);
                // Remove loading state
                $('.aebg-product-row').removeClass('updating');
                showMessage('Error updating product order: ' + error, 'error');
            }
        });
        }

    /**
     * Update frontend dynamically without page reload
     */
    function updateFrontendDynamically(newProductOrder, responseData) {
        console.log('Updating frontend dynamically with new order:', newProductOrder);
        
        // Update Elementor editor if we're in Elementor mode
        if (window.location.href.includes('action=elementor')) {
            updateElementorDynamically(newProductOrder, responseData);
        } else {
            // Update frontend content dynamically
            updateFrontendContentDynamically(newProductOrder, responseData);
        }
    }

    /**
     * Update Elementor editor dynamically
     */
    function updateElementorDynamically(newProductOrder, responseData) {
        console.log('Updating Elementor editor dynamically');
        
        try {
            let updateTriggered = false;
            
            // Method 1: Force Elementor to reload the document completely
            if (window.elementor && window.elementor.documents) {
                try {
                    const currentDocument = window.elementor.documents.getCurrentDocument();
                    if (currentDocument) {
                        // Force document reload by triggering multiple refresh events
                        if (currentDocument.container) {
                            // Trigger model change
                            if (currentDocument.container.model) {
                                currentDocument.container.model.trigger('change');
                                currentDocument.container.model.trigger('change:model');
                                console.log('Elementor document model change triggered');
                            }
                            
                            // Force view refresh
                            if (currentDocument.container.view) {
                                currentDocument.container.view.refresh();
                                console.log('Elementor document view refreshed');
                            }
                            
                            // Force editor refresh
                            if (window.elementor.channels && window.elementor.channels.editor) {
                                window.elementor.channels.editor.trigger('change');
                                window.elementor.channels.editor.trigger('change:model');
                                console.log('Elementor editor channels triggered');
                            }
                            
                            updateTriggered = true;
                        }
                    }
                } catch (e) {
                    console.log('Error refreshing Elementor document:', e);
                }
            }
            
            // Method 2: Use Elementor's data manager to update content
            if (!updateTriggered && window.elementor && window.elementor.dataManager) {
                try {
                    const currentDocument = window.elementor.documents.getCurrentDocument();
                    if (currentDocument && currentDocument.container) {
                        const currentData = currentDocument.container.settings.get('_elementor_data');
                        if (currentData) {
                            const updatedData = updateElementorDataWithNewOrder(currentData, newProductOrder);
                            if (updatedData) {
                                currentDocument.container.settings.set('_elementor_data', updatedData);
                                console.log('Elementor data updated dynamically');
                                updateTriggered = true;
                            }
                        }
                    }
                } catch (e) {
                    console.log('Error updating Elementor data manager:', e);
                }
            }
            
            // Method 3: Force Elementor to reload from server
            if (!updateTriggered && window.elementor && window.elementor.documents) {
                try {
                    const currentDocument = window.elementor.documents.getCurrentDocument();
                    if (currentDocument) {
                        // Force document to reload from server
                        if (currentDocument.reload) {
                            currentDocument.reload();
                            console.log('Elementor document reloaded from server');
                            updateTriggered = true;
                        }
                    }
                } catch (e) {
                    console.log('Error reloading Elementor document:', e);
                }
            }
            
            // Method 4: Use Elementor's frontend API
            if (!updateTriggered && window.elementorFrontend) {
                try {
                    if (window.elementorFrontend.documentsManager && window.elementorFrontend.documentsManager.documents.length > 0) {
                        const document = window.elementorFrontend.documentsManager.documents[0];
                        if (document && document.elements) {
                            document.elements.refresh();
                            console.log('Elementor frontend refreshed dynamically');
                            updateTriggered = true;
                        }
                    }
                } catch (e) {
                    console.log('Error refreshing Elementor frontend:', e);
                }
            }
            
            // Method 5: Dispatch custom event for Elementor to handle
            if (!updateTriggered) {
                try {
                    const updateEvent = new CustomEvent('elementor:product-reorder', {
                        detail: { 
                            newOrder: newProductOrder,
                            responseData: responseData,
                            source: 'aebg-product-reorder'
                        }
                    });
                    window.dispatchEvent(updateEvent);
                    console.log('Custom Elementor update event dispatched');
                    updateTriggered = true;
                } catch (e) {
                    console.log('Error dispatching custom Elementor event:', e);
                }
            }
            
            if (updateTriggered) {
                showMessage('Product order updated successfully! Content refreshed dynamically.', 'success');
                
                // Additional delay to ensure Elementor has time to process the changes
                setTimeout(() => {
                    // Force a final refresh attempt
                    if (window.elementor && window.elementor.documents) {
                        try {
                            const currentDocument = window.elementor.documents.getCurrentDocument();
                            if (currentDocument && currentDocument.container && currentDocument.container.view) {
                                currentDocument.container.view.refresh();
                                console.log('Final Elementor refresh triggered');
                            }
                        } catch (e) {
                            console.log('Error in final Elementor refresh:', e);
                        }
                    }
                }, 500);
                
                // Fallback: If content still doesn't update after 3 seconds, reload the page
                setTimeout(() => {
                    // Check if the content has actually updated by looking for changes in the DOM
                    const currentProductOrder = [];
                    $('.aebg-associated-products-container .aebg-associated-products-table tbody tr').each(function(index) {
                        const productId = $(this).data('product-id');
                        currentProductOrder.push(productId);
                    });
                    
                    // If the order hasn't changed in the frontend, reload the page
                    const orderChanged = JSON.stringify(currentProductOrder) !== JSON.stringify(newProductOrder);
                    if (!orderChanged) {
                        console.log('Content update not detected, forcing page reload');
                        showMessage('Content update not detected. Reloading page to show changes...', 'info');
                        window.location.reload();
                    }
                }, 3000);
            } else {
                console.log('No Elementor update method available, forcing page reload');
                showMessage('Product order updated successfully! Refreshing page to show changes...', 'info');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
            
        } catch (e) {
            console.error('Error updating Elementor dynamically:', e);
            // Fallback to page reload
            showMessage('Product order updated successfully! Refreshing page to show changes...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    }

    /**
     * Update frontend content dynamically
     */
    function updateFrontendContentDynamically(newProductOrder, responseData) {
        console.log('Updating frontend content dynamically');
        
        try {
            // Method 1: Update product containers in the DOM
            updateProductContainersInDOM(newProductOrder);
            
            // Method 2: Update any product-related content
            updateProductContentInDOM(newProductOrder);
            
            // Method 3: Trigger any custom events for other plugins
            const updateEvent = new CustomEvent('aebg:product-reorder', {
                detail: { 
                    newOrder: newProductOrder,
                    responseData: responseData
                }
            });
            window.dispatchEvent(updateEvent);
            
            showMessage('Product order updated successfully! Content refreshed dynamically.', 'success');
            
        } catch (e) {
            console.error('Error updating frontend content dynamically:', e);
            // Fallback to page reload
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        }
    }

    /**
     * Update product containers in the DOM
     */
    function updateProductContainersInDOM(newProductOrder) {
        console.log('Updating product containers in DOM');
        
        // Find all product containers on the page
        const productContainers = document.querySelectorAll('[data-element-id*="product-"], .elementor-widget[data-element-id*="product-"]');
        
        if (productContainers.length === 0) {
            console.log('No product containers found in DOM');
            return;
        }
        
        // Update each container based on the new order
        productContainers.forEach((container, index) => {
            if (index < newProductOrder.length) {
                const productId = newProductOrder[index];
                const newPosition = index + 1;
                
                // Update container attributes
                container.setAttribute('data-product-position', newPosition);
                container.setAttribute('data-product-id', productId);
                
                // Update any product-specific content within the container
                updateContainerContent(container, productId, newPosition);
            }
        });
        
        console.log('Updated', productContainers.length, 'product containers in DOM');
    }

    /**
     * Update container content with new product data
     */
    function updateContainerContent(container, productId, position) {
        // Update product references in text content
        const textElements = container.querySelectorAll('p, h1, h2, h3, h4, h5, h6, span, div');
        textElements.forEach(element => {
            if (element.textContent && element.textContent.includes('{product-')) {
                // Replace old product references with new ones
                const updatedText = element.textContent.replace(/\{product-\d+\}/g, `{product-${position}}`);
                if (updatedText !== element.textContent) {
                    element.textContent = updatedText;
                }
            }
        });
        
        // Update any data attributes
        container.querySelectorAll('[data-product-id]').forEach(element => {
            element.setAttribute('data-product-id', productId);
        });
        
        // Update any product-specific classes
        container.querySelectorAll('[class*="product-"]').forEach(element => {
            const newClass = element.className.replace(/product-\d+/g, `product-${position}`);
            if (newClass !== element.className) {
                element.className = newClass;
            }
        });
    }

    /**
     * Update product content in the DOM
     */
    function updateProductContentInDOM(newProductOrder) {
        console.log('Updating product content in DOM');
        
        // Update any product lists or grids
        const productLists = document.querySelectorAll('.product-list, .product-grid, [data-products]');
        productLists.forEach(list => {
            // Reorder products in the list based on new order
            const products = list.querySelectorAll('[data-product-id]');
            if (products.length > 0) {
                const sortedProducts = Array.from(products).sort((a, b) => {
                    const aId = a.getAttribute('data-product-id');
                    const bId = b.getAttribute('data-product-id');
                    const aIndex = newProductOrder.indexOf(aId);
                    const bIndex = newProductOrder.indexOf(bId);
                    return aIndex - bIndex;
                });
                
                // Reorder DOM elements
                sortedProducts.forEach(product => {
                    list.appendChild(product);
                });
            }
        });
    }

    /**
     * Update Elementor data with new product order
     */
    function updateElementorDataWithNewOrder(elementorData, newProductOrder) {
        try {
            // Deep clone the data to avoid modifying the original
            const updatedData = JSON.parse(JSON.stringify(elementorData));
            
            // Recursively update product containers in the data
            updateElementorDataRecursively(updatedData, newProductOrder);
            
            return updatedData;
        } catch (e) {
            console.error('Error updating Elementor data:', e);
            return null;
        }
    }

    /**
     * Recursively update Elementor data
     */
    function updateElementorDataRecursively(data, newProductOrder) {
        if (Array.isArray(data)) {
            data.forEach(item => updateElementorDataRecursively(item, newProductOrder));
        } else if (typeof data === 'object' && data !== null) {
            // Check if this is a product container
            if (data.settings && data.settings._element_id && data.settings._element_id.match(/^product-\d+$/)) {
                const currentPosition = parseInt(data.settings._element_id.replace('product-', ''));
                const newPosition = newProductOrder.findIndex(id => {
                    // Try to find the product ID in the container
                    return data.settings.aebg_product_id === id || 
                           (data.settings.aebg_ai_prompt && data.settings.aebg_ai_prompt.includes(`{product-${currentPosition}}`));
                }) + 1;
                
                if (newPosition > 0) {
                    // Update the container ID
                    data.settings._element_id = `product-${newPosition}`;
                    
                    // Update any product references in settings
                    Object.keys(data.settings).forEach(key => {
                        if (typeof data.settings[key] === 'string') {
                            data.settings[key] = data.settings[key].replace(
                                new RegExp(`\\{product-${currentPosition}\\}`, 'g'),
                                `{product-${newPosition}}`
                            );
                        }
                    });
                }
            }
            
            // Recursively update nested elements
            if (data.elements) {
                updateElementorDataRecursively(data.elements, newProductOrder);
            }
        }
    }

    /**
     * Get current post ID from the page
     */
    function getCurrentPostId() {
        // Try to get from post ID input
        const postIdInput = $('#post_ID');
        if (postIdInput.length > 0) {
            return postIdInput.val();
        }
        
        // Try to get from URL
        const urlParams = new URLSearchParams(window.location.search);
        const postParam = urlParams.get('post');
        if (postParam) {
            return postParam;
        }
        
        // Try to get from body class
        const bodyClasses = $('body').attr('class');
        if (bodyClasses) {
            const match = bodyClasses.match(/postid-(\d+)/);
            if (match) {
                return match[1];
            }
        }
        
        return null;
    }

    /**
     * Update the hidden input with current product IDs
     */
    function updateProductIds() {
        const productIds = [];
        const productNumbers = [];
        
        $('.aebg-associated-products-container .aebg-associated-products-table tbody tr').each(function(index) {
            const productId = $(this).data('product-id');
            const productNumber = $(this).data('product-number') || (index + 1);
            
            productIds.push(productId);
            productNumbers.push(productNumber);
            
            // Update the data-product-number attribute if it's missing or incorrect
            if (!$(this).data('product-number') || $(this).data('product-number') !== productNumber) {
                $(this).attr('data-product-number', productNumber);
            }
        });
        
        $('#aebg_product_ids').val(productIds.join(','));
        
        // Store product numbers in a data attribute for reference
        $('.aebg-associated-products-container .aebg-associated-products-table').attr('data-product-numbers', JSON.stringify(productNumbers));
    }

    /**
     * Update products count display
     */
    function updateProductsCount() {
        const count = $('.aebg-associated-products-container .aebg-associated-products-table tbody tr').length;
        $('.aebg-products-count').text(count + ' product' + (count !== 1 ? 's' : ''));
    }

    /**
     * Show empty state when no products
     */
    function showEmptyState() {
        const tbody = $('.aebg-associated-products-container .aebg-associated-products-table tbody');
        
        if (tbody.find('tr').length === 0) {
            tbody.html(`
                <tr>
                    <td colspan="7">
                        <div class="aebg-empty-state">
                            <div class="aebg-icon">📦</div>
                            <h4>No Products Associated</h4>
                            <p>Use the search below to add products to this post.</p>
                        </div>
                    </td>
                </tr>
            `);
        }
    }

    /**
     * Format price for display
     * API returns proper decimal values, so no conversion needed
     */
    function formatPrice(price, currency = 'DKK') {
        console.log('formatPrice called with:', price, 'type:', typeof price, 'currency:', currency);
        
        if (price === null || price === undefined || price === '') {
            return 'N/A';
        }
        
        // Convert to number if it's a string
        let numPrice = parseFloat(price);
        
        if (isNaN(numPrice) || numPrice <= 0) {
            return 'N/A';
        }
        
        // Currency symbol and formatting map
        const currencyMap = {
            'DKK': { symbol: 'kr.', decimals: 2, decimalSep: ',', thousandsSep: '.', position: 'after' },
            'SEK': { symbol: 'kr', decimals: 2, decimalSep: ',', thousandsSep: '.', position: 'after' },
            'NOK': { symbol: 'kr', decimals: 2, decimalSep: ',', thousandsSep: '.', position: 'after' },
            'USD': { symbol: '$', decimals: 2, decimalSep: '.', thousandsSep: ',', position: 'before' },
            'EUR': { symbol: '€', decimals: 2, decimalSep: ',', thousandsSep: '.', position: 'before' },
            'GBP': { symbol: '£', decimals: 2, decimalSep: '.', thousandsSep: ',', position: 'before' }
        };
        
        const format = currencyMap[currency] || currencyMap['DKK'];
        
        // For DKK, SEK, NOK - only show decimals if price has decimal places
        let decimals = format.decimals;
        if (['DKK', 'SEK', 'NOK'].includes(currency)) {
            decimals = (numPrice % 1 !== 0) ? 2 : 0;
        }
        
        // Format the number - split into integer and decimal parts to handle separators correctly
        const parts = numPrice.toFixed(decimals).split('.');
        const integerPart = parts[0];
        const decimalPart = parts[1];
        
        // Format integer part with thousands separators
        // Add thousands separators manually to avoid locale issues
        let formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousandsSep);
        
        // Combine integer and decimal parts
        let formatted = formattedInteger;
        if (decimals > 0 && decimalPart) {
            formatted = formattedInteger + format.decimalSep + decimalPart;
        }
        
        // Special handling for Danish Kroner (DKK) - use "249,-" format
        if (currency === 'DKK') {
            if (decimals === 0) {
                // Whole number: "249,-"
                formatted = formatted + ',-';
            }
            // Has decimals: "249,50" (no dash, no currency symbol)
            // Don't add currency symbol for DKK
        } else {
            // Add currency symbol for other currencies
            if (format.position === 'before') {
                formatted = format.symbol + (format.symbol === 'CHF' ? ' ' : '') + formatted;
            } else {
                formatted = formatted + ' ' + format.symbol;
            }
        }
        
        console.log('formatPrice result:', formatted);
        return formatted;
    }

    /**
     * Generate star rating display
     */
    function generateStars(rating) {
        // Ensure rating is a valid number
        const numericRating = parseFloat(rating) || 0;
        if (isNaN(numericRating) || numericRating < 0) {
            return '☆☆☆☆☆'; // Return empty stars for invalid ratings
        }
        
        const fullStars = Math.floor(numericRating);
        const hasHalfStar = numericRating % 1 >= 0.5;
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
        
        let stars = '';
        for (let i = 0; i < fullStars; i++) {
            stars += '★';
        }
        if (hasHalfStar) {
            stars += '☆';
        }
        for (let i = 0; i < emptyStars; i++) {
            stars += '☆';
        }
        
        return stars;
    }

    /**
     * Show message to user
     */
    function showMessage(message, type = 'info') {
        const $messageContainer = $('#aebg-message-container');
        
        if ($messageContainer.length === 0) {
            // Create message container if it doesn't exist
            $('body').append('<div id="aebg-message-container" style="position: fixed; top: 20px; right: 20px; z-index: 999999; max-width: 400px;"></div>');
        }
        
        const $message = $(`
            <div class="aebg-message aebg-message-${type}" style="
                background: ${type === 'success' ? '#d1e7dd' : type === 'error' ? '#f8d7da' : type === 'warning' ? '#fff3cd' : '#d1ecf1'};
                color: ${type === 'success' ? '#0f5132' : type === 'error' ? '#721c24' : type === 'warning' ? '#856404' : '#0c5460'};
                border: 1px solid ${type === 'success' ? '#badbcc' : type === 'error' ? '#f5c2c7' : type === 'warning' ? '#ffeaa7' : '#bee5eb'};
                padding: 12px 16px;
                margin-bottom: 8px;
                border-radius: 6px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                font-size: 14px;
                line-height: 1.4;
                opacity: 0;
                transform: translateX(100%);
                transition: all 0.3s ease;
            ">
                ${message}
            </div>
        `);
        
        $('#aebg-message-container').append($message);
        
        // Animate in
        setTimeout(() => {
            $message.css({
                opacity: 1,
                transform: 'translateX(0)'
            });
        }, 10);
        
        // Auto remove after 5 seconds (only if not manually removed)
        const autoRemoveTimeout = setTimeout(() => {
            if ($message.is(':visible')) {
                $message.css({
                    opacity: 0,
                    transform: 'translateX(100%)'
                });
                setTimeout(() => {
                    $message.remove();
                }, 300);
            }
        }, 5000);

        // Store the timeout ID on the message element for potential cancellation
        $message.data('autoRemoveTimeout', autoRemoveTimeout);
        
        return $message;
    }
    
    function showError(message) {
        showMessage(message, 'error');
    }
    
    function showWarning(message) {
        showMessage(message, 'warning');
    }
    
    function showSuccess(message) {
        showMessage(message, 'success');
    }

    /**
     * Show dialog to select which product to replace
     */
    function showReplaceProductDialog(newProductData) {
        console.log('🔧 showReplaceProductDialog called with:', newProductData);
        
        const existingProducts = $('.aebg-associated-products-container .aebg-associated-products-table tbody tr');
        console.log('🔧 Found existing products:', existingProducts.length);
        
        if (existingProducts.length === 0) {
            console.log('🔧 No existing products found, adding new product instead');
            // No products to replace, just add the new one
            addProductToTable(newProductData);
            return;
        }

        // Create replacement dialog
        let dialogHtml = `
            <div class="aebg-replace-dialog" style="
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                border-radius: 12px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
                padding: 24px;
                z-index: 10000;
                max-width: 500px;
                width: 90%;
            ">
                <h3 style="margin: 0 0 16px 0; color: #1f2937;">Replace Product</h3>
                <p style="margin: 0 0 20px 0; color: #6b7280;">Select which product to replace with "${newProductData.name}":</p>
                <div class="aebg-replace-options" style="max-height: 300px; overflow-y: auto;">
        `;

        existingProducts.each(function(index) {
            const $row = $(this);
            const productId = $row.data('product-id');
            const productName = $row.find('.aebg-product-name').text();
            const productPrice = $row.find('.aebg-price').text();
            const productBrand = $row.find('.aebg-brand').text();
            
            dialogHtml += `
                <div class="aebg-replace-option" data-product-id="${productId}" style="
                    padding: 12px;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    margin-bottom: 8px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                ">
                    <div style="font-weight: 500; color: #111827;">${productName}</div>
                    <div style="font-size: 12px; color: #6b7280;">${productBrand} • ${productPrice}</div>
                </div>
            `;
        });

        dialogHtml += `
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" class="aebg-btn-cancel" style="
                        background: #f3f4f6;
                        border: 1px solid #d1d5db;
                        padding: 8px 16px;
                        border-radius: 6px;
                        margin-right: 8px;
                        cursor: pointer;
                    ">Cancel</button>
                </div>
            </div>
            <div class="aebg-dialog-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 9999;
            "></div>
        `;

        // Add dialog to page
        $('body').append(dialogHtml);

        // Handle option selection
        $('.aebg-replace-option').on('click', function() {
            const productId = $(this).data('product-id');
            replaceProduct(productId, newProductData);
            $('.aebg-replace-dialog, .aebg-dialog-overlay').remove();
        });

        // Handle cancel
        $('.aebg-btn-cancel, .aebg-dialog-overlay').on('click', function() {
            $('.aebg-replace-dialog, .aebg-dialog-overlay').remove();
        });

        // Removed search results clearing - search results should remain visible
    }

    /**
     * Replace an existing product with a new one
     */
    function replaceProduct(oldProductId, newProductData) {
        const $oldRow = $(`.aebg-associated-products-container .aebg-associated-products-table tbody tr[data-product-id="${oldProductId}"]`);
        
        if ($oldRow.length === 0) {
            showMessage('Product not found for replacement', 'error');
            return;
        }

        // Get the product number of the old product
        const oldProductNumber = $oldRow.data('product-number');
        
        if (!oldProductNumber) {
            showMessage('Could not determine product number for replacement', 'error');
            return;
        }

        // Get old product name for display
        const oldProductName = $oldRow.find('.aebg-product-name-text').text().trim() || 'Product #' + oldProductNumber;
        const newProductName = newProductData.display_name || newProductData.name || 'New Product';

        console.log('Replacing product:', {
            oldProductId: oldProductId,
            oldProductNumber: oldProductNumber,
            oldProductName: oldProductName,
            newProductData: newProductData
        });

        // Highlight the old product row before removal
        $oldRow.css({
            'background-color': '#fff3cd',
            'border': '2px solid #ffc107',
            'transition': 'all 0.3s ease'
        });

        // Create enhanced replacement progress indicator
        const $replacementProgress = createReplacementProgressIndicator(oldProductName, newProductName, oldProductNumber);
        
        // Step 1: Preparing replacement
        updateReplacementProgress($replacementProgress, 1, 'Preparing replacement...', '🔄');
        
        // Small delay to show the highlight
        setTimeout(() => {
            // Step 2: Removing old product
            updateReplacementProgress($replacementProgress, 2, 'Removing old product from list...', '🗑️');
            
            // Animate removal
            $oldRow.fadeOut(300, function() {
                $(this).remove();
                
                // Clear any existing product with the same ID to prevent conflicts
                $(`.aebg-associated-products-container .aebg-associated-products-table tbody tr[data-product-id="${newProductData.id}"]`).remove();
                
                // Step 3: Adding new product
                updateReplacementProgress($replacementProgress, 3, 'Adding new product to list...', '➕');
                
                // Add the new product with the same product number
                const newProductNumber = addProductToTable(newProductData, oldProductNumber);
                
                if (newProductNumber) {
                    // Get the product ID that was actually used in the table (may differ from newProductData.id)
                    const $newRow = $(`.aebg-associated-products-container .aebg-associated-products-table tbody tr[data-product-number="${newProductNumber}"]`);
                    
                    if ($newRow.length === 0) {
                        console.error('New product row not found after adding to table');
                        updateReplacementProgress($replacementProgress, 0, '❌ Failed to locate new product in table', 'error');
                        setTimeout(() => {
                            $replacementProgress.remove();
                            showMessage('Failed to locate new product in table', 'error');
                        }, 5000);
                        return;
                    }
                    
                    const actualProductId = $newRow.data('product-id');
                    
                    // Highlight the new product row
                    $newRow.css({
                        'background-color': '#d1e7dd',
                        'border': '2px solid #198754',
                        'transition': 'all 0.3s ease'
                    });
                    
                    // Remove highlight after a moment
                    setTimeout(() => {
                        $newRow.css({
                            'background-color': '',
                            'border': ''
                        });
                    }, 2000);
                    
                    // Ensure the product data has the correct ID that was used in the table
                    const productDataToSave = {
                        ...newProductData,
                        id: actualProductId || newProductData.id
                    };
                    
                    // Step 4: Saving to database
                    updateReplacementProgress($replacementProgress, 4, 'Saving product data to database...', '💾');
                    
                    // Save the product data to database first
                    saveProductDataToDatabase(productDataToSave, newProductNumber).then((responseData) => {
                        // Check if Action Scheduler was used for background processing
                        if (responseData && responseData.action_scheduler_scheduled && responseData.status === 'scheduled') {
                            // Step 5: Content regeneration scheduled in background
                            updateReplacementProgress($replacementProgress, 5, 'Content regeneration scheduled in background...', '⏳');
                            
                            // Show that it's running in background and will complete automatically
                            setTimeout(() => {
                                updateReplacementProgress($replacementProgress, 5, '✅ Product replacement scheduled! Content will be updated in the background...', '✅');
                                $replacementProgress.css({
                                    'background': 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)'
                                });
                                
                                // Keep the progress indicator visible longer for background processing
                                setTimeout(() => {
                                    $replacementProgress.css({
                                        opacity: 0,
                                        transform: 'translateX(100%)'
                                    });
                                    setTimeout(() => {
                                        $replacementProgress.remove();
                                        showMessage(responseData.message || 'Product replacement scheduled. Content will be updated in the background.', 'info');
                                    }, 500);
                                }, 5000); // Show for 5 seconds
                            }, 1000);
                        } else {
                            // Legacy: Immediate regeneration (no Action Scheduler)
                            // Step 5: Regenerating content
                            updateReplacementProgress($replacementProgress, 5, 'Regenerating content with new product...', '✨');
                            
                            // Then trigger content regeneration, passing the progress indicator
                            regenerateContentForProduct(oldProductNumber, productDataToSave, $replacementProgress);
                        }
                    }).catch((error) => {
                        console.error('Failed to save product data:', error);
                        // Show error in progress indicator
                        updateReplacementProgress($replacementProgress, 0, '❌ Failed to save product data: ' + error, 'error');
                        setTimeout(() => {
                            $replacementProgress.remove();
                            showMessage('Failed to save product data: ' + error, 'error');
                        }, 5000);
                    });
                } else {
                    // Show error in progress indicator
                    updateReplacementProgress($replacementProgress, 0, '❌ Failed to add replacement product', 'error');
                    setTimeout(() => {
                        $replacementProgress.remove();
                        showMessage('Failed to add replacement product', 'error');
                    }, 5000);
                }
            });
        }, 500);
    }

    /**
     * Create an enhanced replacement progress indicator
     */
    function createReplacementProgressIndicator(oldProductName, newProductName, productNumber) {
        const $messageContainer = $('#aebg-message-container');
        
        if ($messageContainer.length === 0) {
            $('body').append('<div id="aebg-message-container" style="position: fixed; top: 20px; right: 20px; z-index: 999999; max-width: 450px;"></div>');
        }
        
        const $progress = $(`
            <div class="aebg-replacement-progress" style="
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 20px;
                margin-bottom: 8px;
                border-radius: 12px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                font-size: 14px;
                line-height: 1.6;
                opacity: 0;
                transform: translateX(100%);
                transition: all 0.3s ease;
                min-width: 400px;
            ">
                <div style="display: flex; align-items: center; margin-bottom: 16px;">
                    <div style="font-size: 24px; margin-right: 12px;">🔄</div>
                    <div>
                        <div style="font-weight: 600; font-size: 16px; margin-bottom: 4px;">Replacing Product #${productNumber}</div>
                        <div style="font-size: 12px; opacity: 0.9;">Updating product in your content</div>
                    </div>
                </div>
                
                <div style="background: rgba(255,255,255,0.2); border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <div style="font-size: 14px; opacity: 0.9; margin-right: 8px;">From:</div>
                        <div style="font-weight: 500; text-decoration: line-through; opacity: 0.8;">${escapeHtml(oldProductName || 'Unknown Product')}</div>
                    </div>
                    <div style="display: flex; align-items: center;">
                        <div style="font-size: 14px; opacity: 0.9; margin-right: 8px;">To:</div>
                        <div style="font-weight: 600;">${escapeHtml(newProductName || 'New Product')}</div>
                    </div>
                </div>
                
                <div class="aebg-progress-steps" style="margin-bottom: 12px;">
                    <div class="aebg-progress-step" data-step="1" style="display: flex; align-items: center; padding: 8px; margin-bottom: 6px; background: rgba(255,255,255,0.1); border-radius: 6px; font-size: 13px;">
                        <span class="aebg-step-icon" style="margin-right: 10px; font-size: 16px;">⏳</span>
                        <span class="aebg-step-text">Preparing replacement...</span>
                    </div>
                </div>
                
                <div class="aebg-progress-bar-container" style="background: rgba(255,255,255,0.2); border-radius: 10px; height: 8px; overflow: hidden; margin-bottom: 8px;">
                    <div class="aebg-progress-bar" style="background: #4ade80; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 10px;"></div>
                </div>
                
                <div class="aebg-progress-status" style="font-size: 12px; opacity: 0.9; text-align: center;">
                    Step 1 of 5
                </div>
            </div>
        `);
        
        $('#aebg-message-container').append($progress);
        
        // Animate in
        setTimeout(() => {
            $progress.css({
                opacity: 1,
                transform: 'translateX(0)'
            });
        }, 10);
        
        return $progress;
    }

    /**
     * Update replacement progress indicator
     */
    function updateReplacementProgress($progress, step, message, icon) {
        if (!$progress || !$progress.length) return;
        
        const totalSteps = 5;
        const percentage = (step / totalSteps) * 100;
        
        // Update progress bar
        $progress.find('.aebg-progress-bar').css('width', percentage + '%');
        
        // Update status text
        if (step > 0) {
            $progress.find('.aebg-progress-status').text(`Step ${step} of ${totalSteps}`);
        } else {
            $progress.find('.aebg-progress-status').text(message);
        }
        
        // Update or add step indicator
        const $stepsContainer = $progress.find('.aebg-progress-steps');
        if (step > 0 && step <= totalSteps) {
            // Clear existing steps
            $stepsContainer.empty();
            
            // Show current step
            const stepClass = step === totalSteps ? 'current final' : 'current';
            $stepsContainer.append(`
                <div class="aebg-progress-step ${stepClass}" data-step="${step}" style="
                    display: flex; 
                    align-items: center; 
                    padding: 8px; 
                    background: rgba(255,255,255,${step === totalSteps ? '0.3' : '0.15'}); 
                    border-radius: 6px; 
                    font-size: 13px;
                    animation: pulse 1.5s ease-in-out infinite;
                ">
                    <span class="aebg-step-icon" style="margin-right: 10px; font-size: 16px;">${icon}</span>
                    <span class="aebg-step-text">${escapeHtml(message)}</span>
                </div>
            `);
        }
        
        // If error, change styling
        if (icon === 'error' || step === 0) {
            $progress.css({
                'background': 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'
            });
        }
    }

    /**
     * Regenerate content for a specific product container
     * @param {number} productNumber The product number to regenerate
     * @param {object} productData The product data
     * @param {jQuery} $existingProgress Optional existing progress indicator to update instead of creating new one
     */
    function regenerateContentForProduct(productNumber, productData, $existingProgress) {
        const postId = getCurrentPostId();
        if (!postId) {
            console.error('Could not determine post ID for content regeneration');
            return;
        }

        // Use existing progress indicator if provided, otherwise create new one
        let $regenerationProgress;
        if ($existingProgress && $existingProgress.length && $existingProgress.hasClass('aebg-replacement-progress')) {
            $regenerationProgress = $existingProgress;
        } else {
            // Create a simple progress indicator for standalone regeneration
            const $messageContainer = $('#aebg-message-container');
            if ($messageContainer.length === 0) {
                $('body').append('<div id="aebg-message-container" style="position: fixed; top: 20px; right: 20px; z-index: 999999; max-width: 450px;"></div>');
            }
            
            $regenerationProgress = $(`
                <div class="aebg-regeneration-progress" style="
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 16px;
                    margin-bottom: 8px;
                    border-radius: 12px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    font-size: 14px;
                    opacity: 0;
                    transform: translateX(100%);
                    transition: all 0.3s ease;
                ">
                    <div style="display: flex; align-items: center;">
                        <span style="font-size: 20px; margin-right: 12px;">✨</span>
                        <span>Regenerating content for product ${productNumber}...</span>
                    </div>
                </div>
            `);
            
            $('#aebg-message-container').append($regenerationProgress);
            setTimeout(() => {
                $regenerationProgress.css({
                    opacity: 1,
                    transform: 'translateX(0)'
                });
            }, 10);
        }

        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_regenerate_content',
                post_id: postId,
                product_number: productNumber,
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Check if this is part of a replacement progress indicator
                    if ($regenerationProgress.hasClass('aebg-replacement-progress')) {
                        // Update to completion state
                        updateReplacementProgress($regenerationProgress, 5, '✅ Product replacement completed successfully!', '✅');
                        $regenerationProgress.css({
                            'background': 'linear-gradient(135deg, #10b981 0%, #059669 100%)'
                        });
                        
                        // Show success message
                        setTimeout(() => {
                            $regenerationProgress.css({
                                opacity: 0,
                                transform: 'translateX(100%)'
                            });
                            setTimeout(() => {
                                $regenerationProgress.remove();
                                showMessage('Product replaced successfully! Content has been updated.', 'success');
                            }, 500);
                        }, 3000);
                    } else {
                        // Simple regeneration progress - update to success
                        $regenerationProgress.html(`
                            <div style="display: flex; align-items: center;">
                                <span style="font-size: 20px; margin-right: 12px;">✅</span>
                                <span>Content regenerated successfully for product ${productNumber}</span>
                            </div>
                        `);
                        $regenerationProgress.css({
                            'background': 'linear-gradient(135deg, #10b981 0%, #059669 100%)'
                        });
                        
                        // Remove after 3 seconds
                        setTimeout(() => {
                            $regenerationProgress.css({
                                opacity: 0,
                                transform: 'translateX(100%)'
                            });
                            setTimeout(() => {
                                $regenerationProgress.remove();
                            }, 300);
                        }, 3000);
                    }
                    
                    // Optionally refresh the Elementor editor to show the new content
                    if (typeof elementor !== 'undefined' && elementor.channels && elementor.channels.editor) {
                        elementor.channels.editor.trigger('change');
                    }
                } else {
                    // Show error
                    const errorMsg = response.data || 'Unknown error';
                    if ($regenerationProgress.hasClass('aebg-replacement-progress')) {
                        updateReplacementProgress($regenerationProgress, 0, '❌ Content regeneration failed: ' + errorMsg, 'error');
                    } else {
                        $regenerationProgress.html(`
                            <div style="display: flex; align-items: center;">
                                <span style="font-size: 20px; margin-right: 12px;">❌</span>
                                <span>Content regeneration failed: ${escapeHtml(errorMsg)}</span>
                            </div>
                        `);
                        $regenerationProgress.css({
                            'background': 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'
                        });
                    }
                    
                    // Remove after 5 seconds
                    setTimeout(() => {
                        $regenerationProgress.css({
                            opacity: 0,
                            transform: 'translateX(100%)'
                        });
                        setTimeout(() => {
                            $regenerationProgress.remove();
                        }, 300);
                    }, 5000);
                }
            },
            error: function(xhr, status, error) {
                // Show error
                if ($regenerationProgress.hasClass('aebg-replacement-progress')) {
                    updateReplacementProgress($regenerationProgress, 0, '❌ Content regeneration failed: ' + error, 'error');
                } else {
                    $regenerationProgress.html(`
                        <div style="display: flex; align-items: center;">
                            <span style="font-size: 20px; margin-right: 12px;">❌</span>
                            <span>Content regeneration failed: ${escapeHtml(error)}</span>
                        </div>
                    `);
                    $regenerationProgress.css({
                        'background': 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'
                    });
                }
                
                console.error('Content regeneration error:', error);
                
                // Remove after 5 seconds
                setTimeout(() => {
                    $regenerationProgress.css({
                        opacity: 0,
                        transform: 'translateX(100%)'
                    });
                    setTimeout(() => {
                        $regenerationProgress.remove();
                    }, 300);
                }, 5000);
            }
        });
    }

    /**
     * Update template structure for a new product
     */
    function updateTemplateForNewProduct(productNumber) {
        const postId = getCurrentPostId();
        if (!postId) {
            console.error('Could not determine post ID for template update');
            return;
        }

        // Show loading message with more details
        const loadingMessage = `🔄 Updating template structure for new product ${productNumber}...`;
        showMessage(loadingMessage, 'info');
        
        // Add a visual indicator to the product row
        const productRow = $(`.aebg-product-row[data-product-number="${productNumber}"]`);
        if (productRow.length > 0) {
            productRow.addClass('processing');
            productRow.find('.aebg-product-name').append('<span class="aebg-processing-indicator"> 🔄 Processing...</span>');
        }

        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_update_template_for_new_product',
                post_id: postId,
                product_number: productNumber,
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                // Remove processing indicator
                if (productRow.length > 0) {
                    productRow.removeClass('processing');
                    productRow.find('.aebg-processing-indicator').remove();
                }

                if (response.success) {
                    let message = `✅ Template updated successfully for new product ${productNumber}`;
                    
                    // Add AI status to the message
                    if (response.data && response.data.ai_configured === false) {
                        message += ' (AI content generation not configured - using placeholder content)';
                        showMessage(message, 'warning');
                    } else if (response.data && response.data.ai_configured === true) {
                        message += ' (AI content generated successfully)';
                        showMessage(message, 'success');
                    } else {
                        showMessage(message, 'success');
                    }
                    
                    // Comprehensive refresh for new product addition
                    refreshElementorForNewProduct(productNumber);
                } else {
                    const errorMessage = response.data || 'Unknown error occurred';
                    showMessage(`❌ Template update failed: ${errorMessage}`, 'error');
                    console.error('Template update failed:', response);
                }
            },
            error: function(xhr, status, error) {
                // Remove processing indicator
                if (productRow.length > 0) {
                    productRow.removeClass('processing');
                    productRow.find('.aebg-processing-indicator').remove();
                }

                console.error('Template update error:', error);
                showMessage(`❌ Template update failed: ${error}`, 'error');
            }
        });
    }

    /**
     * Comprehensive refresh for new product addition with hard refresh
     */
    function refreshElementorForNewProduct(productNumber) {
        console.log('Refreshing Elementor for new product:', productNumber);
        
        try {
            // Method 1: Try to add the new container to the DOM directly
            if (addNewProductContainerToDOM(productNumber)) {
                console.log('Successfully added new product container to DOM');
                showMessage('New product container added successfully', 'success');
                return;
            }
            
            // Method 2: Force Elementor to reload the document with hard refresh
            if (window.elementor && window.elementor.documents) {
                try {
                    const currentDocument = window.elementor.documents.getCurrentDocument();
                    if (currentDocument) {
                        // Force document reload
                        if (currentDocument.container && currentDocument.container.model) {
                            currentDocument.container.model.trigger('change');
                            console.log('Elementor document model change triggered');
                        }
                        
                        // Force view refresh
                        if (currentDocument.container && currentDocument.container.view) {
                            currentDocument.container.view.refresh();
                            console.log('Elementor document view refreshed');
                        }
                        
                        // Method 3: Force a hard refresh by triggering multiple Elementor events
                        if (window.elementor.channels && window.elementor.channels.editor) {
                            window.elementor.channels.editor.trigger('change');
                            console.log('Elementor editor channel change triggered');
                        }
                        
                        // Method 4: Force document save and reload
                        if (currentDocument.container && currentDocument.container.model) {
                            // Trigger a save to force Elementor to reload the data
                            currentDocument.container.model.trigger('save');
                            console.log('Elementor document save triggered');
                        }
                        
                        // Method 5: Force frontend refresh if we're in preview mode
                        if (window.elementorFrontend && window.elementorFrontend.documentsManager) {
                            const frontendDocument = window.elementorFrontend.documentsManager.documents[currentDocument.id];
                            if (frontendDocument) {
                                frontendDocument.container.view.refresh();
                                console.log('Elementor frontend document refreshed');
                            }
                        }
                        
                        // Method 6: Force a complete page reload after a short delay
                        setTimeout(() => {
                            console.log('Forcing hard refresh for Elementor...');
                            showMessage('Refreshing Elementor to show new content...', 'info');
                            
                            // Try to trigger Elementor's internal refresh mechanism
                            if (window.elementor && window.elementor.reloadPreview) {
                                window.elementor.reloadPreview();
                                console.log('Elementor preview reload triggered');
                                
                                // Give it a moment, then force a hard refresh if needed
                                setTimeout(() => {
                                    const newContainer = $(`[id*="product-${productNumber}"]`);
                                    if (newContainer.length === 0) {
                                        console.log('Container still not found after preview reload, forcing hard page refresh...');
                                        window.location.reload(true);
                                    } else {
                                        console.log('Container found after preview reload!');
                                        showMessage('New product content loaded successfully!', 'success');
                                    }
                                }, 2000);
                            } else {
                                // Fallback: Force a hard page refresh immediately
                                console.log('Forcing hard page refresh...');
                                window.location.reload(true);
                            }
                        }, 1000);
                        
                        return;
                    }
                } catch (e) {
                    console.error('Error in Elementor refresh:', e);
                }
            }
            
            // Method 7: If Elementor is not available, force a hard refresh
            console.log('Elementor not available, forcing hard refresh...');
            setTimeout(() => {
                window.location.reload(true);
            }, 500);
            
        } catch (e) {
            console.error('Error in refreshElementorForNewProduct:', e);
            // Fallback: Force a hard refresh
            setTimeout(() => {
                window.location.reload(true);
            }, 500);
        }
    }

    /**
     * Add new product container to the DOM
     */
    function addNewProductContainerToDOM(productNumber) {
        console.log('Attempting to add new product container to DOM:', productNumber);
        
        try {
            // First, check if the container already exists in the DOM
            const existingContainer = $(`[id*="product-${productNumber}"]`);
            if (existingContainer.length > 0) {
                console.log('Container already exists in DOM:', existingContainer.attr('id'));
                return true;
            }
            
            // Try multiple selectors to find the Elementor editor container
            let elementorContainer = $('.elementor-edit-area');
            if (elementorContainer.length === 0) {
                elementorContainer = $('.elementor-preview-container');
            }
            if (elementorContainer.length === 0) {
                elementorContainer = $('.elementor-widget-container');
            }
            if (elementorContainer.length === 0) {
                elementorContainer = $('.elementor-section');
            }
            
            if (elementorContainer.length === 0) {
                console.log('Elementor editor container not found');
                return false;
            }
            
            // Find the last product container with multiple selectors
            let lastProductContainer = elementorContainer.find('[id*="product-"]').last();
            if (lastProductContainer.length === 0) {
                lastProductContainer = elementorContainer.find('[data-element-id*="product-"]').last();
            }
            if (lastProductContainer.length === 0) {
                lastProductContainer = elementorContainer.find('.elementor-section[id*="product-"]').last();
            }
            
            if (lastProductContainer.length === 0) {
                console.log('No existing product containers found');
                return false;
            }
            
            console.log('Found last product container:', lastProductContainer.attr('id') || lastProductContainer.attr('data-element-id'));
            
            // Create a new container element based on the last one
            const newContainer = lastProductContainer.clone();
            const newContainerId = `product-${productNumber}`;
            
            // Update the new container's ID and data attributes
            newContainer.attr('id', newContainerId);
            newContainer.attr('data-element-id', newContainerId);
            newContainer.attr('data-element-type', 'container');
            newContainer.attr('data-element-settings', '{}');
            
            // Clear the content and add placeholder
            newContainer.html('<div class="elementor-container-placeholder">🔄 Loading new product content...</div>');
            
            // Insert the new container after the last product container
            lastProductContainer.after(newContainer);
            
            console.log('New product container added to DOM:', newContainerId);
            
            // Add some visual feedback
            newContainer.addClass('aebg-new-container');
            setTimeout(() => {
                newContainer.removeClass('aebg-new-container');
            }, 3000);
            
            // Trigger Elementor to recognize the new element
            if (window.elementor && window.elementor.channels && window.elementor.channels.editor) {
                window.elementor.channels.editor.trigger('change:structure');
                console.log('Triggered Elementor structure change');
            }
            
            // Also try to trigger a document change
            if (window.elementor && window.elementor.documents) {
                try {
                    const currentDocument = window.elementor.documents.getCurrentDocument();
                    if (currentDocument && currentDocument.container && currentDocument.container.model) {
                        currentDocument.container.model.trigger('change');
                        console.log('Triggered Elementor document model change');
                    }
                } catch (e) {
                    console.log('Error triggering Elementor document change:', e);
                }
            }
            
            return true;
            
        } catch (e) {
            console.error('Error adding new product container to DOM:', e);
            return false;
        }
    }

    /**
     * Check if new product container exists in the DOM
     */
    function checkNewProductContainerExists(productNumber) {
        const container = $(`[id*="product-${productNumber}"]`);
        if (container.length > 0) {
            console.log('New product container found in DOM:', container.attr('id'));
            return true;
        }
        
        console.log('New product container not found in DOM');
        return false;
    }

    /**
     * Debug function to show current products in table
     */
    function debugShowCurrentProducts() {
        const tbody = $('.aebg-associated-products-container .aebg-associated-products-table tbody');
        const products = tbody.find('tr').map(function() {
            return {
                id: $(this).data('product-id'),
                name: $(this).find('.aebg-product-name').text().trim(),
                number: $(this).data('product-number')
            };
        }).get();
        
        console.log('Current products in table:', products);
        console.log('Total products:', products.length);
        
        // Show in a more readable format
        let productList = 'Current products in table:\n';
        products.forEach((product, index) => {
            // Escape product name to prevent XSS in console output
            const safeName = escapeHtml(product.name || 'Unknown');
            productList += `${index + 1}. ${safeName} (ID: ${product.id}, Number: ${product.number})\n`;
        });
        
        alert(productList);
    }

    /**
     * Clear all products from table (for debugging)
     */
    function debugClearAllProducts() {
        if (confirm('Are you sure you want to clear all products from the table? This is for debugging only.')) {
            $('.aebg-associated-products-container .aebg-associated-products-table tbody').empty();
            updateProductsCount();
            showEmptyState();
            console.log('All products cleared from table');
        }
    }

    /**
     * Debug function to check Elementor containers
     */
    function debugCheckElementorContainers() {
        console.log('=== Elementor Containers Debug ===');
        
        // Check for Elementor editor containers
        const editArea = $('.elementor-edit-area');
        const previewContainer = $('.elementor-preview-container');
        const widgetContainer = $('.elementor-widget-container');
        const sections = $('.elementor-section');
        
        console.log('Elementor containers found:');
        console.log('- .elementor-edit-area:', editArea.length);
        console.log('- .elementor-preview-container:', previewContainer.length);
        console.log('- .elementor-widget-container:', widgetContainer.length);
        console.log('- .elementor-section:', sections.length);
        
        // Check for product containers
        const productContainers = $('[id*="product-"]');
        console.log('Product containers found:', productContainers.length);
        
        productContainers.each(function(index) {
            const container = $(this);
            console.log(`Product container ${index + 1}:`, {
                id: container.attr('id'),
                elementId: container.attr('data-element-id'),
                elementType: container.attr('data-element-type'),
                classes: container.attr('class')
            });
        });
        
        // Check Elementor global objects
        console.log('Elementor objects:');
        console.log('- window.elementor:', !!window.elementor);
        console.log('- window.elementorFrontend:', !!window.elementorFrontend);
        
        if (window.elementor) {
            console.log('- window.elementor.channels:', !!window.elementor.channels);
            console.log('- window.elementor.documents:', !!window.elementor.documents);
            console.log('- window.elementor.reloadPreview:', !!window.elementor.reloadPreview);
        }
        
        // Show results in alert
        let debugInfo = 'Elementor Containers Debug:\n\n';
        debugInfo += `Edit Area: ${editArea.length}\n`;
        debugInfo += `Preview Container: ${previewContainer.length}\n`;
        debugInfo += `Widget Container: ${widgetContainer.length}\n`;
        debugInfo += `Sections: ${sections.length}\n`;
        debugInfo += `Product Containers: ${productContainers.length}\n\n`;
        debugInfo += `Elementor: ${!!window.elementor}\n`;
        debugInfo += `ElementorFrontend: ${!!window.elementorFrontend}`;
        
        alert(debugInfo);
    }

    // Make debug functions available globally
    window.debugShowCurrentProducts = debugShowCurrentProducts;
    window.debugClearAllProducts = debugClearAllProducts;
    window.debugCheckElementorContainers = debugCheckElementorContainers;
    
    console.log('Debug functions available: debugShowCurrentProducts(), debugClearAllProducts(), debugCheckElementorContainers()');

    // Merchant Comparison Modal functionality
    function initMerchantComparisonModal() {
        console.log('Initializing Merchant Comparison Modal...');
        
        // Bind click events to merchant info containers (only for associated products)
        $(document).on('click', '.aebg-associated-products-table .aebg-merchants-info', function(e) {
            e.preventDefault();
            const $this = $(this);
            const productId = $this.data('product-id');
            const $row = $this.closest('tr');
            
            console.log('🔍 CLICK: Merchant info clicked for product ID:', productId);
            console.log('🔍 CLICK: Product ID type:', typeof productId, 'Value:', productId);
            console.log('🔍 CLICK: Row data:', $row);
            console.log('🔍 CLICK: Row HTML:', $row.html());
            
            // Check if this is the first product (index 0)
            const rowIndex = $row.index();
            console.log('🔍 CLICK: Row index:', rowIndex);
            console.log('🔍 CLICK: Is first product:', rowIndex === 0);
            
            if (!productId) {
                console.error('❌ No product ID found for merchant info click');
                return;
            }
            
            // For the first product, check if we need to use a different ID
            if (rowIndex === 0 && (productId === '0' || productId === 0)) {
                console.log('🔍 CLICK: First product detected with index ID, checking for actual product ID');
                
                // Try to get the actual product ID from the row data
                const actualProductId = $row.data('product-id');
                if (actualProductId && actualProductId !== '0' && actualProductId !== 0) {
                    console.log('🔍 CLICK: Found actual product ID:', actualProductId);
                    showMerchantComparisonModal(actualProductId, $row);
                    return;
                }
                
                // If no actual product ID, try to get it from the stored products
                if (window.aebgProducts && window.aebgProducts.length > 0) {
                    const firstProduct = window.aebgProducts[0];
                    if (firstProduct && firstProduct.id) {
                        console.log('🔍 CLICK: Using first product ID from stored data:', firstProduct.id);
                        showMerchantComparisonModal(firstProduct.id, $row);
                        return;
                    }
                }
            }
            
            showMerchantComparisonModal(productId, $row);
        });
        
        // Modal close events
        $(document).on('click', '.aebg-modal-close, .aebg-modal-overlay', function(e) {
            e.preventDefault();
            hideMerchantComparisonModal();
        });
        
        // Search functionality
        initModalSearch();
        
        // Comparison management
        initComparisonManagement();
        
        console.log('Merchant Comparison Modal initialized');
    }
    
    function initModalSearch() {
        // Toggle search filters
        $(document).on('click', '#modal-toggle-search', function() {
            const $filters = $('#modal-search-filters');
            const $button = $(this);
            const $icon = $button.find('.dashicons');
            
            if ($filters.is(':visible')) {
                $filters.slideUp();
                $button.html('<span class="dashicons dashicons-arrow-down-alt2"></span>Show Search');
            } else {
                $filters.slideDown();
                $button.html('<span class="dashicons dashicons-arrow-up-alt2"></span>Hide Search');
            }
        });
        
        // Search button click
        $(document).on('click', '#modal-search-products', function() {
            performModalSearch();
        });
        
        // Clear button click
        $(document).on('click', '#modal-clear-search', function() {
            clearModalSearch();
        });
        
        // Enter key for search
        $(document).on('keypress', '#modal-search-name', function(e) {
            if (e.which === 13) {
                performModalSearch();
            }
        });
        
        // Enter key for brand field
        $(document).on('keypress', '#modal-search-brand', function(e) {
            if (e.which === 13) {
                performModalSearch();
            }
        });
    }
    
    function initComparisonManagement() {
        console.log('Initializing comparison management...');
        
        // Remove all products from comparison (merchant comparison table)
        $(document).on('click', '#remove-all-products', function() {
            console.log('Remove all products clicked');
            const $modal = $('#aebg-merchant-comparison-modal');
            const productId = $modal.data('product-id');
            
            if (productId) {
                // Clear the comparison data from database
                $.ajax({
                    url: aebg_ajax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aebg_delete_comparison',
                        nonce: aebg_ajax.nonce,
                        product_id: productId
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Comparison data deleted successfully');
                            
                            // Clear the comparison table
                            $('#comparison-table-body').html('<tr><td colspan="8" class="text-center">No merchant data available</td></tr>');
                            
                            // Update comparison count
                            $('#comparison-count').text('(0 products)');
                            
                            // Clear the comparison data from modal
                            $modal.removeData('comparison-data');
                            
                            // Update merchant count in main table
                            const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
                            if ($merchantInfo.length > 0) {
                                const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
                                $merchantCountElement.text('1');
                                
                                // Reset price range to original product data
                                const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
                                const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
                                const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
                                const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
                                
                                // Get original product data from the row
                                const $productRow = $('.aebg-product-row[data-product-id="' + productId + '"]');
                                if ($productRow.length > 0) {
                                    const originalPrice = parseFloat($productRow.find('.product-price').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
                                    const originalMerchant = $productRow.find('.product-merchant').text().trim() || 'Unknown';
                                    
                                    if ($lowestPriceElement.length > 0) {
                                        $lowestPriceElement.text(formatPrice(originalPrice));
                                        $lowestMerchantElement.text(originalMerchant);
                                    }
                                    
                                    if ($highestPriceElement.length > 0) {
                                        $highestPriceElement.text(formatPrice(originalPrice));
                                        $highestMerchantElement.text(originalMerchant);
                                    }
                                }
                                
                                // Reset visual indicator
                                $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                                $merchantInfo.addClass('few-merchants');
                            }
                            
                            showSuccess('All comparison data removed successfully');
                        } else {
                            console.error('Failed to delete comparison:', response.data);
                            showError('Failed to remove comparison data');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error deleting comparison:', error);
                        showError('Error removing comparison data');
                    }
                });
            }
        });
        
        // Remove individual merchant from comparison
        $(document).on('click', '.aebg-btn-remove-from-comparison', function() {
            console.log('Remove merchant clicked');
            const $row = $(this).closest('tr');
            const merchantName = $row.data('merchant');
            const productId = $('#aebg-merchant-comparison-modal').data('product-id');
            
            console.log('Removing merchant:', merchantName, 'from product:', productId);
            
            if (merchantName && productId) {
                // Remove the merchant from the comparison data
                const $modal = $('#aebg-merchant-comparison-modal');
                const currentData = $modal.data('comparison-data');
                
                if (currentData && currentData.merchants) {
                    delete currentData.merchants[merchantName];
                    
                    // Save updated comparison data
                    saveComparisonDataToDatabase(productId, currentData);
                    
                    // Refresh the display
                    displayMerchantComparisonTable(currentData);
                    
                    // Update merchant count in main table immediately
                    const remainingMerchants = Object.keys(currentData.merchants);
                    const merchantCount = remainingMerchants.length;
                    
                    console.log('Updated merchant count after removal:', merchantCount, 'merchants remaining:', remainingMerchants);
                    
                    const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
                    if ($merchantInfo.length > 0) {
                        const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
                        $merchantCountElement.text(merchantCount);
                        
                        // Update price range if there are remaining merchants
                        if (merchantCount > 0) {
                            const prices = [];
                            const merchantPrices = {};
                            
                            remainingMerchants.forEach(merchantName => {
                                const merchant = currentData.merchants[merchantName];
                                const price = merchant.lowest_price || merchant.price || 0;
                                if (price > 0) {
                                    prices.push(price);
                                    merchantPrices[merchantName] = price;
                                }
                            });
                            
                            const lowestPrice = prices.length > 0 ? Math.min(...prices) : 0;
                            const highestPrice = prices.length > 0 ? Math.max(...prices) : 0;
                            
                            // Find merchants with lowest and highest prices
                            let lowestMerchant = 'Unknown';
                            let highestMerchant = 'Unknown';
                            
                            Object.keys(merchantPrices).forEach(merchant => {
                                if (merchantPrices[merchant] === lowestPrice) {
                                    lowestMerchant = merchant;
                                }
                                if (merchantPrices[merchant] === highestPrice) {
                                    highestMerchant = merchant;
                                }
                            });
                            
                            // Update price range display
                            const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
                            const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
                            const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
                            const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
                            
                            if ($lowestPriceElement.length > 0) {
                                $lowestPriceElement.text(formatPrice(lowestPrice));
                                $lowestMerchantElement.text(lowestMerchant);
                            }
                            
                            if ($highestPriceElement.length > 0) {
                                $highestPriceElement.text(formatPrice(highestPrice));
                                $highestMerchantElement.text(highestMerchant);
                            }
                            
                            // Update visual indicator
                            $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                            if (merchantCount > 5) {
                                $merchantInfo.addClass('many-merchants');
                            } else if (merchantCount > 2) {
                                $merchantInfo.addClass('some-merchants');
                            } else if (merchantCount > 0) {
                                $merchantInfo.addClass('few-merchants');
                            }
                        } else {
                            // No merchants left, reset to original product data
                            const $productRow = $('.aebg-product-row[data-product-id="' + productId + '"]');
                            if ($productRow.length > 0) {
                                const originalPrice = parseFloat($productRow.find('.product-price').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
                                const originalMerchant = $productRow.find('.product-merchant').text().trim() || 'Unknown';
                                
                                const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
                                const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
                                const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
                                const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
                                
                                if ($lowestPriceElement.length > 0) {
                                    $lowestPriceElement.text(formatPrice(originalPrice));
                                    $lowestMerchantElement.text(originalMerchant);
                                }
                                
                                if ($highestPriceElement.length > 0) {
                                    $highestPriceElement.text(formatPrice(originalPrice));
                                    $highestMerchantElement.text(originalMerchant);
                                }
                            }
                            
                            // Reset visual indicator
                            $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                            $merchantInfo.addClass('few-merchants');
                        }
                    }
                    
                    showSuccess('Merchant removed from comparison');
                }
            }
        });
        
        // View product on merchant website
        $(document).on('click', '.aebg-btn-view-product', function() {
            console.log('View product clicked');
            const productUrl = $(this).data('product-url');
            
            if (productUrl) {
                console.log('Opening product URL:', productUrl);
                // Open the URL in a new tab
                window.open(productUrl, '_blank');
            } else {
                console.warn('No product URL available');
                showWarning('Product URL not available');
            }
        });
        
        // Save comparison
        $(document).on('click', '#save-comparison', function() {
            console.log('Save comparison clicked');
            const $modal = $('#aebg-merchant-comparison-modal');
            const productId = $modal.data('product-id');
            const comparisonData = $modal.data('comparison-data');
            
            if (productId && comparisonData) {
                saveComparisonDataToDatabase(productId, comparisonData);
                showSuccess('Comparison saved successfully');
            }
        });
        
        // Cancel comparison
        $(document).on('click', '#cancel-comparison', function() {
            console.log('Cancel comparison clicked');
            hideMerchantComparisonModal();
        });
        
        // Initialize drag and drop for comparison table
        initComparisonTableDragAndDrop();
        
        // Add sync merchant count button handler
        $(document).on('click', '.aebg-sync-merchant-count', function() {
            console.log('Manual sync merchant count clicked');
            updateMerchantCountFromComparison();
            
            // Also update from modal data
            const $modal = $('#aebg-merchant-comparison-modal');
            const productId = $modal.data('product-id');
            const comparisonData = $modal.data('comparison-data');
            
            if (productId && comparisonData && comparisonData.merchants) {
                const merchantCount = Object.keys(comparisonData.merchants).length;
                console.log('Manual sync: Found', merchantCount, 'merchants in modal data');
                
                // Update the merchant count in the UI directly
                const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
                if ($merchantInfo.length > 0) {
                    const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
                    $merchantCountElement.text(merchantCount);
                    
                    // Calculate price range from modal data
                    const prices = [];
                    const merchantPrices = {};
                    
                    Object.keys(comparisonData.merchants).forEach(merchantName => {
                        const merchant = comparisonData.merchants[merchantName];
                        const price = merchant.lowest_price || merchant.price || 0;
                        if (price > 0) {
                            prices.push(price);
                            merchantPrices[merchantName] = price;
                        }
                    });
                    
                    const lowestPrice = prices.length > 0 ? Math.min(...prices) : 0;
                    const highestPrice = prices.length > 0 ? Math.max(...prices) : 0;
                    
                    // Find merchants with lowest and highest prices
                    let lowestMerchant = 'Unknown';
                    let highestMerchant = 'Unknown';
                    
                    Object.keys(merchantPrices).forEach(merchant => {
                        if (merchantPrices[merchant] === lowestPrice) {
                            lowestMerchant = merchant;
                        }
                        if (merchantPrices[merchant] === highestPrice) {
                            highestMerchant = merchant;
                        }
                    });
                    
                    console.log('Manual sync: Price range from modal data:', lowestPrice, 'to', highestPrice, 'from merchants:', lowestMerchant, 'and', highestMerchant);
                    
                    // Update price range display
                    const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
                    const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
                    const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
                    const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
                    
                    if ($lowestPriceElement.length > 0) {
                        $lowestPriceElement.text(formatPrice(lowestPrice));
                        $lowestMerchantElement.text(lowestMerchant);
                    }
                    
                    if ($highestPriceElement.length > 0) {
                        $highestPriceElement.text(formatPrice(highestPrice));
                        $highestMerchantElement.text(highestMerchant);
                    }
                    
                    // Update visual indicator
                    $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                    if (merchantCount > 5) {
                        $merchantInfo.addClass('many-merchants');
                    } else if (merchantCount > 2) {
                        $merchantInfo.addClass('some-merchants');
                    } else if (merchantCount > 0) {
                        $merchantInfo.addClass('few-merchants');
                    }
                    
                    console.log('Manual sync: Successfully updated merchant count and price range to', merchantCount);
                    showSuccess('Merchant count and price range synced: ' + merchantCount + ' merchants, ' + formatPrice(lowestPrice) + ' - ' + formatPrice(highestPrice));
                }
            }
        });
        
        console.log('Comparison management initialized');
    }
    
    let currentSearchResults = [];
    let currentPage = 1;
    let totalPages = 1;
    let searchParams = {};
    
    function performModalSearch() {
        const searchData = {
            name: $('#modal-search-name').val().trim(),
            brand: $('#modal-search-brand').val().trim(),
            currency: $('#modal-search-currency').val(),
            networks: getSelectedNetworks(), // Use the same network selection as main search
            category: $('#modal-search-category').val().trim(),
            min_rating: $('#modal-search-rating').val(),
            min_price: $('#modal-search-min-price').val().trim(),
            max_price: $('#modal-search-max-price').val().trim(),
            limit: $('#modal-search-limit').val(),
            page: currentPage,
            has_image: $('#modal-search-has-image').is(':checked'),
            sort_by: $('#modal-search-sort').val() || 'relevance',
            in_stock_only: $('#modal-search-in-stock').is(':checked')
        };
        
        // Validate at least name or brand is provided
        if (!searchData.name && !searchData.brand) {
            showError('Product name or brand is required for search');
            return;
        }
        
        searchParams = searchData;
        
        // Clear sort state for new search
        currentSortColumn = null;
        currentSortDirection = 'asc';
        
        // Show loading state
        $('#modal-search-results').html('<div class="aebg-loading">Searching products...</div>');
        $('#modal-search-results-section').show();
        
        // Build request data, only including non-empty values
        const requestData = {
            action: 'aebg_search_products_advanced',
            nonce: aebg_ajax.search_products_nonce,
            limit: parseInt(searchData.limit) || 50,
            page: currentPage
        };
        
        // Add query (name or brand)
        if (searchData.name) {
            requestData.query = searchData.name;
        }
        if (searchData.brand) {
            requestData.brand = searchData.brand;
        }
        
        // Only add currency if it's not "All Currencies"
        if (searchData.currency && searchData.currency !== 'All Currencies') {
            requestData.currency = searchData.currency;
        }
        
        // Add networks if selected
        if (searchData.networks && searchData.networks.length > 0 && !searchData.networks.includes('all')) {
            requestData.network_ids = searchData.networks;
        }
        
        // Add category if provided
        if (searchData.category) {
            requestData.category = searchData.category;
        }
        
        // Add rating filter if provided
        if (searchData.min_rating) {
            requestData.min_rating = parseInt(searchData.min_rating);
        }
        
        // Only add price filters if they have actual values (not empty, not 0)
        if (searchData.min_price && searchData.min_price !== '' && parseFloat(searchData.min_price) > 0) {
            requestData.min_price = parseFloat(searchData.min_price);
        }
        
        if (searchData.max_price && searchData.max_price !== '' && parseFloat(searchData.max_price) > 0) {
            requestData.max_price = parseFloat(searchData.max_price);
        }
        
        // Add has_image filter
        if (searchData.has_image) {
            requestData.has_image = true;
        }
        
        // Add sort_by
        if (searchData.sort_by) {
            requestData.sort_by = searchData.sort_by;
        }
        
        // Add in_stock_only
        if (searchData.in_stock_only) {
            requestData.in_stock_only = true;
        }
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: requestData,
            success: function(response) {
                console.log('Search response:', response);
                
                if (response.success) {
                    // Handle the correct response structure
                    const products = response.data.products || response.data || [];
                    const pagination = response.data.pagination || null;
                    
                    console.log('Products found:', products.length);
                    console.log('Products data:', products);
                    
                    if (products.length > 0) {
                        displaySearchResults(products, pagination);
                    } else {
                        $('#modal-search-results').html('<div class="aebg-no-results">No products found for "' + searchData.name + '"</div>');
                        $('#modal-search-results-section').show();
                    }
                } else {
                    $('#modal-search-results').html('<div class="aebg-error">Search failed: ' + (response.data || 'Unknown error') + '</div>');
                    $('#modal-search-results-section').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Search error:', xhr, status, error);
                $('#modal-search-results').html('<div class="aebg-error">Search failed: ' + error + '</div>');
            }
        });
    }
    
    function displaySearchResults(products, pagination) {
        currentSearchResults = products;
        totalPages = pagination ? pagination.total_pages : 1;
        
        let html = '';
        
        if (products.length === 0) {
            html = '<div class="aebg-no-results">No products found</div>';
        } else {
            // Create table structure similar to associated products
            html += `
                <table class="aebg-modal-search-table">
                    <thead>
                        <tr>
                            <th class="aebg-th-image sortable" data-sort="image">Image</th>
                            <th class="aebg-th-name sortable" data-sort="name">Product Name <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-price sortable" data-sort="price">Price <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-brand sortable" data-sort="brand">Brand <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-network sortable" data-sort="network">Network <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-merchant sortable" data-sort="merchant">Merchant <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-rating sortable" data-sort="rating">Rating <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-ean sortable" data-sort="ean">EAN/GTIN <span class="sort-icon">↕</span></th>
                            <th class="aebg-th-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            products.forEach(function(product) {
                const image = product.image_url || product.image || '';
                const price = formatPrice(product.price);
                const brand = product.brand || 'Unknown';
                const network = product.network || product.network_name || 'Unknown';
                const rating = product.rating || 0;
                const stars = generateStars(rating);
                const ean = product.ean || product.gtin || product.upc || product.isbn || 'N/A';
                
                // Escape all user-generated content to prevent XSS
                const safeProductName = escapeHtml(product.name || '');
                const safeBrand = escapeHtml(brand);
                const safeNetwork = escapeHtml(network);
                const safeMerchant = escapeHtml(product.merchant || 'N/A');
                const safeEan = escapeHtml(ean);
                const safeDescription = product.description ? escapeHtml(product.description.substring(0, 100)) + (product.description.length > 100 ? '...' : '') : '';
                
                // Fix image URL to prevent mixed content errors
                let safeImageUrl = '';
                if (image) {
                    // Convert HTTP to HTTPS if needed
                    safeImageUrl = image.replace(/^http:/, 'https:');
                }
                
                html += `
                    <tr class="aebg-modal-search-result-item" data-product-id="${product.id}" data-price="${product.price || 0}" data-rating="${rating}" data-brand="${safeBrand.toLowerCase()}" data-network="${safeNetwork.toLowerCase()}" data-merchant="${safeMerchant.toLowerCase()}" data-ean="${safeEan.toLowerCase()}">
                        <td class="aebg-td-image">
                            ${image ? `<img src="${safeImageUrl}" alt="${safeProductName}" class="aebg-search-result-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"><div class="aebg-no-image" style="display: none;">No Image</div>` : '<div class="aebg-no-image">No Image</div>'}
                        </td>
                        <td class="aebg-td-name">
                            <div class="aebg-product-name">
                                <strong class="aebg-product-name-text">${safeProductName}</strong>
                            </div>
                            ${product.description ? `<div class="aebg-product-description">${safeDescription}</div>` : ''}
                            <div class="aebg-product-meta">
                                <span>💰 ${price}</span>
                                <span>🏷️ ${safeBrand}</span>
                                <span>⭐ ${rating}/5</span>
                            </div>
                        </td>
                        <td class="aebg-td-price">
                            <span class="aebg-price">${price}</span>
                        </td>
                        <td class="aebg-td-brand">
                            <span class="aebg-brand">${safeBrand}</span>
                        </td>
                        <td class="aebg-td-network">
                            <span class="aebg-network">${safeNetwork}</span>
                        </td>
                        <td class="aebg-td-merchant">
                            <span class="aebg-merchant">${safeMerchant}</span>
                            <br>
                            <span class="aebg-merchant-price">${price}</span>
                        </td>
                        <td class="aebg-td-rating">
                            <div class="aebg-rating">
                                <div class="aebg-stars">${stars}</div>
                                <div class="aebg-rating-text">${rating}/5</div>
                            </div>
                        </td>
                        <td class="aebg-td-ean">
                            <span class="aebg-ean">${ean}</span>
                        </td>
                        <td class="aebg-td-actions">
                            <button type="button" class="aebg-btn-add-to-comparison" data-product-id="${product.id}">
                                <span class="dashicons dashicons-plus"></span>
                                Add to Comparison
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
        }
        
        $('#modal-search-results').html(html);
        $('#modal-results-count').text(`(${products.length} results)`);
        
        // Show the search results section
        $('#modal-search-results-section').show();
        
        // Explicitly show the search results container
        $('#modal-search-results').show().css('display', 'block');
        
        // Initialize sorting
        initTableSorting();
        
        // Debug: Check if elements exist and are visible
        console.log('=== MODAL SEARCH DEBUG ===');
        console.log('Search results container exists:', $('#modal-search-results').length > 0);
        console.log('Search results section exists:', $('#modal-search-results-section').length > 0);
        console.log('Search results section is visible:', $('#modal-search-results-section').is(':visible'));
        console.log('Search results container height:', $('#modal-search-results').height());
        console.log('Search results container CSS display:', $('#modal-search-results').css('display'));
        console.log('Search results container CSS visibility:', $('#modal-search-results').css('visibility'));
        console.log('Search results container CSS overflow:', $('#modal-search-results').css('overflow'));
        console.log('Search results container CSS max-height:', $('#modal-search-results').css('max-height'));
        console.log('Search results container innerHTML length:', $('#modal-search-results').html().length);
        console.log('First search result item exists:', $('.aebg-modal-search-result-item').length > 0);
        console.log('=== END MODAL SEARCH DEBUG ===');
        
        // Force scroll to top of search results
        $('#modal-search-results').scrollTop(0);
        
        // Update pagination
        updatePagination(pagination);
        
        console.log('Search results displayed:', products.length, 'products');
        console.log('Search results HTML length:', html.length);
    }
    
    function updatePagination(pagination) {
        if (!pagination || pagination.total_pages <= 1) {
            $('#modal-search-pagination').hide();
            return;
        }
        
        let html = '';
        const currentPage = pagination.current_page || 1;
        const totalPages = pagination.total_pages;
        
        // Previous button
        html += `<button class="aebg-pagination-prev" ${currentPage === 1 ? 'disabled' : ''} data-page="${currentPage - 1}">Previous</button>`;
        
        // Page numbers
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button data-page="1">1</button>`;
            if (startPage > 2) {
                html += `<span class="aebg-pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="aebg-pagination-ellipsis">...</span>`;
            }
            html += `<button data-page="${totalPages}">${totalPages}</button>`;
        }
        
        // Next button
        html += `<button class="aebg-pagination-next" ${currentPage === totalPages ? 'disabled' : ''} data-page="${currentPage + 1}">Next</button>`;
        
        // Page info
        html += `<span class="aebg-pagination-info">Page ${currentPage} of ${totalPages}</span>`;
        
        $('#modal-search-pagination').html(html).show();
    }
    
    // Pagination click handler
    $(document).on('click', '.aebg-pagination button:not(:disabled)', function(e) {
        e.preventDefault();
        const page = $(this).data('page');
        if (page && page !== currentPage) {
            currentPage = page;
            performModalSearch();
        }
    });
    
    function clearModalSearch() {
        $('#modal-search-name').val('');
        $('#modal-search-brand').val('');
        $('#modal-search-currency').val('');
        clearNetworkSelections(); // Clear the modern network selector instead of dropdown
        $('#modal-search-category').val('');
        $('#modal-search-rating').val('');
        $('#modal-search-min-price').val('');
        $('#modal-search-max-price').val('');
        $('#modal-search-limit').val('50');
        $('#modal-search-has-image').prop('checked', false);
        $('#modal-search-sort').val('relevance');
        $('#modal-search-in-stock').prop('checked', false);
        $('#modal-search-results-section').hide();
        currentSearchResults = [];
        currentPage = 1;
        
        // Reset sort state
        currentSortColumn = null;
        currentSortDirection = 'asc';
        lastSortState = { column: null, direction: 'asc' };
    }
    
    let comparisonProducts = [];
    
    function addProductToComparison(productData) {
        console.log('Adding product to comparison:', productData);
        
        // Check if product is already in comparison
        const existingIndex = comparisonProducts.findIndex(p => p.id === productData.id);
        if (existingIndex !== -1) {
            showWarning('Product already in comparison');
            return;
        }
        
        comparisonProducts.push(productData);
        console.log('Product added to comparison array. Total products:', comparisonProducts.length);
        
        // Get the current modal product ID to integrate with main comparison system
            const $modal = $('#aebg-merchant-comparison-modal');
            const currentProductId = $modal.data('product-id');
        
            if (currentProductId) {
            // CRITICAL FIX: Get existing comparison data from modal first, then load from database if needed
            let existingData = $modal.data('comparison-data');
            
            if (existingData && Object.keys(existingData.merchants || {}).length > 0) {
                console.log('🔍 ADD: Using existing comparison data from modal');
                // Merge with existing data in modal
                mergeSearchResultsWithComparison(productData, existingData);
            } else {
                console.log('🔍 ADD: No existing data in modal, loading from database');
                // Load existing comparison data from database
                loadComparisonFromDatabase(currentProductId, function(databaseData) {
                    // Merge search results with existing comparison data
                    mergeSearchResultsWithComparison(productData, databaseData);
                });
            }
        } else {
            // If no current product, just update the search results table
            updateComparisonTable();
            updateComparisonCount();
        }
        
        // Update button state - use the new data-product-id attribute
        $(`.aebg-btn-add-to-comparison[data-product-id="${productData.id}"]`)
            .addClass('added')
            .html('<span class="dashicons dashicons-yes"></span>Added')
            .prop('disabled', true);
        
        showSuccess('Product added to comparison');
        
        // Debug logging
        console.log('Product added to comparison:', productData.name);
        console.log('Total products in comparison:', comparisonProducts.length);
    }
    
    function addAllSearchResultsToComparison() {
        if (currentSearchResults.length === 0) {
            showWarning('No search results to add');
            return;
        }
        
        let addedCount = 0;
        currentSearchResults.forEach(function(product) {
            const existingIndex = comparisonProducts.findIndex(p => p.id === product.id);
            if (existingIndex === -1) {
                comparisonProducts.push(product);
                addedCount++;
            }
        });
        
        if (addedCount > 0) {
            // Add a small delay to ensure the modal is fully loaded
            setTimeout(function() {
                updateComparisonTable();
                updateComparisonCount();
            }, 100);
            showSuccess(`Added ${addedCount} products to comparison`);
        } else {
            showWarning('All products are already in comparison');
        }
    }
    
    function removeProductFromComparison(productId) {
        const index = comparisonProducts.findIndex(p => p.id === productId);
        if (index !== -1) {
            comparisonProducts.splice(index, 1);
            updateComparisonTable();
            updateComparisonCount();
            
            // Force refresh merchant count from database to ensure accuracy
            const $modal = $('#aebg-merchant-comparison-modal');
            const currentProductId = $modal.data('product-id');
            if (currentProductId) {
                forceRefreshMerchantCount(currentProductId);
            }
            
            // Update button state in search results
            $(`.aebg-btn-add-to-comparison[data-product-id="${productId}"]`)
                .removeClass('added')
                .html('<span class="dashicons dashicons-plus"></span>Add to Comparison')
                .prop('disabled', false);
            
            // Note: autoSaveComparison is deprecated for merchant comparison
            // This is for search results comparison only
            
            showSuccess('Product removed from comparison');
        }
    }
    
    function removeAllFromComparison() {
        if (comparisonProducts.length === 0) {
            showWarning('No products in comparison');
            return;
        }
        
        comparisonProducts = [];
        updateComparisonTable();
        updateComparisonCount();
        
        // Reset all add buttons
        $('.aebg-btn-add-to-comparison')
            .removeClass('added')
            .html('<span class="dashicons dashicons-plus"></span>Add to Comparison')
            .prop('disabled', false);
        
        // Note: autoSaveComparison is deprecated for merchant comparison
        // This is for search results comparison only
        
        showSuccess('All products removed from comparison');
    }
    
    function updateComparisonTable() {
        let html = '';
        
        console.log('=== UPDATE COMPARISON TABLE DEBUG ===');
        console.log('Updating comparison table with', comparisonProducts.length, 'products:', comparisonProducts);
        
        if (comparisonProducts.length === 0) {
            html = '<tr><td colspan="8" class="text-center">No products in comparison</td></tr>';
        } else {
            comparisonProducts.forEach(function(product, index) {
                const price = formatPrice(product.price);
                const rating = product.rating || 0;
                const stars = generateStars(rating);
                const availability = product.availability || 'In Stock';
                const merchant = product.merchant || product.store || 'Unknown';
                const network = product.network || product.network_name || 'Unknown';
                
                // Escape all user-generated content to prevent XSS
                const safeProductName = escapeHtml(product.name || '');
                const safeMerchant = escapeHtml(merchant);
                const safeNetwork = escapeHtml(network);
                const safeAvailability = escapeHtml(availability);
                
                console.log(`Processing comparison product ${index + 1}:`, safeProductName, 'from', safeMerchant);
                
                html += `
                    <tr class="aebg-comparison-row" data-product-id="${product.id}" data-merchant="${safeMerchant}">
                        <td class="aebg-td-drag">
                            <span class="aebg-drag-handle" title="Drag to reorder">⋮⋮</span>
                        </td>
                        <td>${safeMerchant}</td>
                        <td>${safeProductName}</td>
                        <td>${price}</td>
                        <td>${safeNetwork}</td>
                        <td>${stars} ${rating}/5</td>
                        <td>${safeAvailability}</td>
                        <td>
                            ${product.url ? `
                                <button type="button" class="aebg-btn-view-product" data-product-url="${product.url}" title="View product on merchant website">
                                    <span class="dashicons dashicons-external"></span>
                                    View
                                </button>
                            ` : ''}
                            <button type="button" class="aebg-btn-remove-from-comparison" data-product-id="${product.id}">
                                <span class="dashicons dashicons-trash"></span>
                                Remove
                            </button>
                        </td>
                    </tr>
                `;
            });
        }
        
        const $comparisonTableBody = $('#comparison-table-body');
        console.log('Looking for comparison table body. Found:', $comparisonTableBody.length, 'elements');
        
        if ($comparisonTableBody.length > 0) {
            $comparisonTableBody.html(html);
            console.log('Comparison table body updated with HTML length:', html.length);
            console.log('Comparison table body HTML preview:', html.substring(0, 200) + '...');
        } else {
            console.error('Comparison table body not found! Looking for #comparison-table-body');
            // Try to find the table body in the modal
            const $modal = $('#aebg-merchant-comparison-modal');
            console.log('Modal found:', $modal.length, 'elements');
            
            if ($modal.length > 0) {
                const $tableBody = $modal.find('#comparison-table-body');
                console.log('Table body found in modal:', $tableBody.length, 'elements');
                
                if ($tableBody.length > 0) {
                    $tableBody.html(html);
                    console.log('Found comparison table body in modal and updated it');
                } else {
                    console.error('Comparison table body not found in modal either');
                    // Try alternative selectors
                    const $altTableBody = $modal.find('tbody');
                    console.log('Alternative tbody found:', $altTableBody.length, 'elements');
                    if ($altTableBody.length > 0) {
                        $altTableBody.html(html);
                        console.log('Updated alternative tbody');
                    }
                }
            }
        }
        

        
        // Debug logging
        console.log('=== END UPDATE COMPARISON TABLE DEBUG ===');
        console.log('Comparison table updated with', comparisonProducts.length, 'products');
        
        // Verify drag handles are present
        const dragHandles = $('#comparison-table-body .aebg-drag-handle');
        console.log('Drag handles found in comparison table:', dragHandles.length);
        if (dragHandles.length > 0) {
            console.log('Drag and drop should be functional');
            // Reinitialize drag and drop after updating the table
            initComparisonTableDragAndDrop();
        } else {
            console.warn('No drag handles found - drag and drop may not work');
        }
    }
    
    /**
     * Merge search results with existing comparison data
     */
    function mergeSearchResultsWithComparison(searchProduct, existingComparisonData) {
        console.log('🔍 MERGE: Merging search product with existing comparison data');
        console.log('🔍 MERGE: Search product:', searchProduct);
        console.log('🔍 MERGE: Existing comparison data:', existingComparisonData);
        
        // CRITICAL FIX: Get the current comparison data from the modal if it exists
        const $modal = $('#aebg-merchant-comparison-modal');
        let currentComparisonData = $modal.data('comparison-data');
        
        // If no current data in modal, use the existing data passed in
        if (!currentComparisonData) {
            currentComparisonData = existingComparisonData || {};
        }
        
        console.log('🔍 MERGE: Current comparison data from modal:', currentComparisonData);
        
        // Create a new comparison data structure that includes both existing and new data
        let mergedData = {
            original_product: currentComparisonData.original_product || {},
            merchants: { ...(currentComparisonData.merchants || {}) }, // Clone existing merchants
            search_results: [...(currentComparisonData.search_results || [])] // Clone existing search results
        };
        
        // Add the search product to search_results if not already there
        const existingSearchIndex = mergedData.search_results.findIndex(p => p.id === searchProduct.id);
        if (existingSearchIndex === -1) {
            mergedData.search_results.push(searchProduct);
            console.log('🔍 MERGE: Added search product to search_results');
        }
        
        // CRITICAL FIX: Also add the search product to the main merchants object for proper display
        if (searchProduct.merchant || searchProduct.store) {
            const merchantName = searchProduct.merchant || searchProduct.store;
            
            // Check if this merchant already exists
            if (mergedData.merchants[merchantName]) {
                console.log('🔍 MERGE: Merchant already exists, updating with new product data');
                // Update existing merchant with new product data
                mergedData.merchants[merchantName] = {
                    ...mergedData.merchants[merchantName],
                    id: searchProduct.id,
                    price: searchProduct.price,
                    lowest_price: searchProduct.price,
                    availability: searchProduct.availability || 'Unknown',
                    rating: searchProduct.rating || 0,
                    network: searchProduct.network || searchProduct.network_name || 'Unknown',
                    url: searchProduct.url || '',
                    image_url: searchProduct.image || searchProduct.image_url || '',
                    is_search_result: true,
                    original_product: {
                        id: searchProduct.id,
                        name: searchProduct.name,
                        price: searchProduct.price,
                        url: searchProduct.url || '',
                        image: searchProduct.image || searchProduct.image_url || ''
                    }
                };
            } else {
                // Create a new merchant entry
                mergedData.merchants[merchantName] = {
                    name: merchantName,
                    id: searchProduct.id,
                    price: searchProduct.price,
                    lowest_price: searchProduct.price,
                    availability: searchProduct.availability || 'Unknown',
                    rating: searchProduct.rating || 0,
                    network: searchProduct.network || searchProduct.network_name || 'Unknown',
                    url: searchProduct.url || '',
                    image_url: searchProduct.image || searchProduct.image_url || '',
                    is_search_result: true,
                    original_product: {
                        id: searchProduct.id,
                        name: searchProduct.name,
                        price: searchProduct.price,
                        url: searchProduct.url || '',
                        image: searchProduct.image || searchProduct.image_url || ''
                    }
                };
            }
            
            console.log('🔍 MERGE: Added/updated search product in main merchants object as:', merchantName);
            console.log('🔍 MERGE: Total merchants now:', Object.keys(mergedData.merchants).length);
        }
        
        // Update the comparison table to show the merged data
        displayMergedComparisonTable(mergedData);
        
        // Save the merged data to database
        const currentProductId = $modal.data('product-id');
        if (currentProductId) {
            saveComparisonDataToDatabase(currentProductId, mergedData);
        }
    }
    
        /**
     * Display merged comparison table with both merchant data and search results
     */
    function displayMergedComparisonTable(mergedData) {
        console.log('🔍 MERGE: Displaying merged comparison table');
        
        let html = '';
        
        // CRITICAL FIX: Create a unified list of all products to compare
        const allProducts = [];
        
        console.log('🔍 MERGE DISPLAY: Processing merchants:', mergedData.merchants);
        console.log('🔍 MERGE DISPLAY: Processing search results:', mergedData.search_results);
        
        // Add original merchant products
        if (mergedData.merchants && Object.keys(mergedData.merchants).length > 0) {
            Object.keys(mergedData.merchants).forEach(merchantName => {
                const merchant = mergedData.merchants[merchantName];
                console.log(`🔍 MERGE DISPLAY: Processing merchant ${merchantName}:`, merchant);
                
                // Skip if this merchant doesn't have basic required data
                if (!merchant || !merchant.name) {
                    console.log(`🔍 MERGE DISPLAY: Skipping merchant ${merchantName} - no basic data`);
                    return;
                }
                
                allProducts.push({
                    merchant: merchantName,
                    data: merchant,
                    type: merchant.is_search_result ? 'search_result' : 'original_merchant'
                });
                console.log(`🔍 MERGE DISPLAY: Added merchant ${merchantName} to allProducts`);
            });
        }
        
        // Add search results that aren't already in merchants
        if (mergedData.search_results && Array.isArray(mergedData.search_results)) {
            mergedData.search_results.forEach(searchProduct => {
                const merchantName = searchProduct.merchant || searchProduct.store || 'Unknown';
                console.log(`🔍 MERGE DISPLAY: Processing search result for merchant ${merchantName}:`, searchProduct);
                
                // Check if this search product is already in the merchants list
                const alreadyExists = allProducts.some(p => 
                    p.merchant === merchantName && 
                    p.data.is_search_result && 
                    p.data.original_product && 
                    p.data.original_product.id === searchProduct.id
                );
                
                if (!alreadyExists) {
                    // CRITICAL FIX: Ensure search results are properly marked and have their product names preserved
                    const searchResultData = {
                        ...searchProduct,
                        is_search_result: true,
                        original_product: searchProduct,
                        // Preserve the original product name
                        product_name: searchProduct.name,
                        // Ensure we have the network information
                        network: searchProduct.network || searchProduct.network_name || 'Unknown'
                    };
                    
                    allProducts.push({
                        merchant: merchantName,
                        data: searchResultData,
                        type: 'search_result'
                    });
                    console.log(`🔍 MERGE DISPLAY: Added search result for merchant ${merchantName} to allProducts with product name: ${searchProduct.name}`);
                } else {
                    console.log(`🔍 MERGE DISPLAY: Search result for merchant ${merchantName} already exists, skipping`);
                }
            });
        }
        
        console.log('🔍 MERGE: Unified products list:', allProducts);
        
        // Display all products in a single unified table
        if (allProducts.length > 0) {
            allProducts.forEach((productInfo, index) => {
                const { merchant, data, type } = productInfo;
                
                // Get product name - prioritize the actual product name over generic names
                let productName = 'Unknown Product';
                
                // CRITICAL FIX: Check if this is a search result first
                if (data.is_search_result && data.original_product && data.original_product.name) {
                    // This is a search result - ALWAYS use its own product name
                    productName = data.original_product.name;
                    console.log(`🔍 MERGE DISPLAY: Using search result product name for merchant ${merchant}: ${productName}`);
                } else if (data.name && data.name !== merchant) {
                    // Use the merchant's own name if it's different from the merchant name
                    productName = data.name;
                    console.log(`🔍 MERGE DISPLAY: Using merchant's own name for merchant ${merchant}: ${productName}`);
                } else if (data.original_product && data.original_product.name) {
                    // Check if the merchant object has its own original_product
                    productName = data.original_product.name;
                    console.log(`🔍 MERGE DISPLAY: Using merchant's original_product name for merchant ${merchant}: ${productName}`);
                } else if (mergedData.original_product && mergedData.original_product.name) {
                    // LAST RESORT: Fallback to the main product name only if no other option
                    productName = mergedData.original_product.name;
                    console.log(`🔍 MERGE DISPLAY: Using main product name as fallback for merchant ${merchant}: ${productName}`);
                }
                
                // Additional safety check: if we still have 'Unknown Product', try to find any available name
                if (productName === 'Unknown Product') {
                    // Look for any available name in the data
                    if (data.product_name) {
                        productName = data.product_name;
                        console.log(`🔍 MERGE DISPLAY: Found product_name for merchant ${merchant}: ${productName}`);
                    } else if (data.title) {
                        productName = data.title;
                        console.log(`🔍 MERGE DISPLAY: Found title for merchant ${merchant}: ${productName}`);
                    } else if (data.display_name) {
                        productName = data.display_name;
                        console.log(`🔍 MERGE DISPLAY: Found display_name for merchant ${merchant}: ${productName}`);
                    }
                }
                
                console.log(`🔍 MERGE DISPLAY: Final product name for merchant ${merchant}: ${productName}`);
                
                // Get price
                const price = data.price || data.lowest_price || 0;
                let displayPrice = 'N/A';
                if (typeof price === 'number' && !isNaN(price) && price > 0) {
                    displayPrice = formatPrice(price);
                } else if (typeof price === 'string' && price.trim() !== '' && price !== '0') {
                    const numericPrice = parseFloat(price);
                    if (!isNaN(numericPrice) && numericPrice > 0) {
                        displayPrice = formatPrice(numericPrice);
                    } else {
                        displayPrice = price;
                    }
                }
                
                // Get other data
                const rating = data.rating || 0;
                const stars = generateStars(rating);
                const availability = data.availability || 'Unknown';
                const network = data.network || data.network_name || 'Unknown';
                let productUrl = data.url || '';
                
                // Clean up the URL if it's malformed
                if (productUrl) {
                    // Fix malformed URLs with double slashes
                    productUrl = productUrl.replace(/^https:\/\/s\/\//, 'https://'); // Fix https://s// to https://
                    productUrl = productUrl.replace(/^http:\/\/s\/\//, 'https://'); // Fix http://s// to https://
                    productUrl = productUrl.replace(/^http:\/\//, 'https://'); // Ensure https://
                    
                    // Additional safety: remove any remaining double slashes in the protocol
                    productUrl = productUrl.replace(/^(https?):\/\/\/+/, '$1://');
                }
                
                // Make product name clickable if URL is available
                let productNameCell = productName;
                if (productUrl) {
                    productNameCell = `<a href="${productUrl}" target="_blank" class="aebg-product-link" title="View product on ${merchant} website" rel="noopener noreferrer">${productName} <span class="dashicons dashicons-external"></span></a>`;
                }
                
                html += `
                    <tr class="aebg-comparison-row ${type}-row" data-product-id="${data.id || data.original_product?.id || ''}" data-merchant="${merchant}">
                        <td class="aebg-td-drag">
                            <span class="aebg-drag-handle" title="Drag to reorder">⋮⋮</span>
                        </td>
                        <td>${merchant}</td>
                        <td>${productNameCell}</td>
                        <td>${displayPrice}</td>
                        <td>${network}</td>
                        <td>${stars} ${rating}/5</td>
                        <td>${availability}</td>
                        <td>
                            ${productUrl ? `
                                <button type="button" class="aebg-btn-view-product" data-product-url="${productUrl}" title="View product on merchant website">
                                    <span class="dashicons dashicons-external"></span>
                                    View
                                </button>
                            ` : ''}
                            <button type="button" class="aebg-btn-remove-merchant" data-merchant="${merchant}">
                                <span class="dashicons dashicons-trash"></span>
                                Remove
                            </button>
                        </td>
                    </tr>
                `;
            });
        } else {
            html = '<tr><td colspan="8" class="text-center">No products in comparison</td></tr>';
        }
        
        // Update the comparison table
        const $comparisonTableBody = $('#comparison-table-body');
        if ($comparisonTableBody.length > 0) {
            $comparisonTableBody.html(html);
            console.log('🔍 MERGE: Comparison table updated with unified data');
            
            // Update the comparison count based on actual products displayed
            const totalItems = allProducts.length;
            $('#comparison-count').text(`(${totalItems} items)`);
            
            // Store the merged data in the modal for future reference
            $('#aebg-merchant-comparison-modal').data('comparison-data', mergedData);
            
            // Reinitialize drag and drop
            initComparisonTableDragAndDrop();
        } else {
            console.error('🔍 MERGE: Comparison table body not found');
        }
    }
    
    function updateComparisonCount() {
        // Count actual unique merchants in the comparison table for accurate display
        const uniqueMerchants = new Set();
        
        $('#comparison-table-body tr').not('.no-data').each(function() {
            const merchantName = $(this).find('td:nth-child(2)').text().trim();
            if (merchantName && merchantName !== '') {
                uniqueMerchants.add(merchantName);
            }
        });
        
        const count = uniqueMerchants.size;
        $('#comparison-count').text(`(${count} merchants)`);
        
        console.log('Updated comparison count to:', count, 'unique merchants:', Array.from(uniqueMerchants));
    }
    

    
    function updateMerchantCountFromComparison() {
        console.log('=== updateMerchantCountFromComparison DEBUG ===');
        
        // Get the current product ID from the modal
        const $modal = $('#aebg-merchant-comparison-modal');
        const currentProductId = $modal.data('product-id');
        
        if (!currentProductId) {
            console.log('No current product ID found in modal');
            return;
        }
        
        // Count unique merchants directly from the comparison table
        const uniqueMerchants = new Set();
        const merchantPrices = {};
        
        $('#comparison-table-body tr').not('.no-data').each(function() {
            const merchantName = $(this).find('td:nth-child(2)').text().trim();
            const priceText = $(this).find('td:nth-child(4)').text().trim();
            const price = parseFloat(priceText.replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
            
            if (merchantName && merchantName !== '') {
                uniqueMerchants.add(merchantName);
                
                // Track the lowest price for each merchant
                if (!merchantPrices[merchantName] || price < merchantPrices[merchantName]) {
                    merchantPrices[merchantName] = price;
                }
            }
        });
        
        const merchantCount = uniqueMerchants.size;
        console.log('Found ' + merchantCount + ' unique merchants in comparison table:', Array.from(uniqueMerchants));
        
        // Calculate price range
        const prices = Object.values(merchantPrices).filter(p => p > 0);
        const lowestPrice = prices.length > 0 ? Math.min(...prices) : 0;
        const highestPrice = prices.length > 0 ? Math.max(...prices) : 0;
        
        // Find merchants with lowest and highest prices
        let lowestMerchant = 'Unknown';
        let highestMerchant = 'Unknown';
        
        Object.keys(merchantPrices).forEach(merchant => {
            if (merchantPrices[merchant] === lowestPrice) {
                lowestMerchant = merchant;
            }
            if (merchantPrices[merchant] === highestPrice) {
                highestMerchant = merchant;
            }
        });
        
        console.log('Price range:', lowestPrice, 'to', highestPrice, 'from merchants:', lowestMerchant, 'and', highestMerchant);
        
        // Update the merchant count and price range in the main table
        const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + currentProductId + '"]');
        
        if ($merchantInfo.length > 0) {
            // Update merchant count
            const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
            $merchantCountElement.text(merchantCount);
            
            // Update price range
            const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
            const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
            const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
            const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
            
            if ($lowestPriceElement.length > 0) {
                $lowestPriceElement.text(formatPrice(lowestPrice));
                $lowestMerchantElement.text(lowestMerchant);
            }
            
            if ($highestPriceElement.length > 0) {
                $highestPriceElement.text(formatPrice(highestPrice));
                $highestMerchantElement.text(highestMerchant);
            }
            
            // Update visual indicator based on count
            $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
            
            if (merchantCount > 5) {
                $merchantInfo.addClass('many-merchants');
            } else if (merchantCount > 2) {
                $merchantInfo.addClass('some-merchants');
            } else if (merchantCount > 0) {
                $merchantInfo.addClass('few-merchants');
            }
            
            console.log('Updated merchant info in main table for product:', currentProductId);
        } else {
            console.log('Merchant info element not found for product:', currentProductId);
        }
        
        console.log('=== END updateMerchantCountFromComparison DEBUG ===');
    }
    
    /**
     * Force refresh merchant count from database for a specific product
     */
    function forceRefreshMerchantCount(productId) {
        if (!productId) {
            console.log('No product ID provided for force refresh');
            return;
        }
        
        console.log('Force refreshing merchant count for product:', productId);
        
        // Refresh merchant count directly - no caching
        refreshMerchantCountFromDatabase(productId);
    }
    
    /**
     * Refresh merchant count from database for a specific product
     */
    function refreshMerchantCountFromDatabase(productId) {
        if (!productId) {
            console.log('No product ID provided for merchant count refresh');
            return;
        }
        
        console.log('Refreshing merchant count from database for product:', productId);
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_merchant_counts',
                nonce: aebg_ajax.nonce,
                products: [{
                    id: productId,
                    name: 'Unknown'
                }]
            },
            success: function(response) {
                console.log('Merchant count refresh response:', response);
                if (response.success && response.data && response.data[productId]) {
                    const merchantInfo = response.data[productId];
                    const merchantCount = parseInt(merchantInfo.merchant_count) || 0;
                    
                    // Update the merchant count in the UI
                    const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
                    if ($merchantInfo.length > 0) {
                        const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
                        $merchantCountElement.text(merchantCount);
                        
                        // Update price range if available
                        if (merchantInfo.price_range) {
                            const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
                            const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
                            const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
                            const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
                            
                            if ($lowestPriceElement.length > 0 && merchantInfo.merchants && merchantInfo.merchants.lowest_price) {
                                $lowestPriceElement.text(formatPrice(merchantInfo.merchants.lowest_price.price));
                                $lowestMerchantElement.text(merchantInfo.merchants.lowest_price.name);
                            }
                            
                            if ($highestPriceElement.length > 0 && merchantInfo.merchants && merchantInfo.merchants.highest_price) {
                                $highestPriceElement.text(formatPrice(merchantInfo.merchants.highest_price.price));
                                $highestMerchantElement.text(merchantInfo.merchants.highest_price.name);
                            }
                        }
                        
                        // Update visual indicator
                        $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                        if (merchantCount > 5) {
                            $merchantInfo.addClass('many-merchants');
                        } else if (merchantCount > 2) {
                            $merchantInfo.addClass('some-merchants');
                        } else if (merchantCount > 0) {
                            $merchantInfo.addClass('few-merchants');
                        }
                    }
                    
                    console.log('Successfully refreshed merchant count from database:', merchantCount);
                
                // Log merchant details from fresh API data
                if (response.data && response.data[productId] && response.data[productId].merchants) {
                    console.log('=== FRESH API MERCHANT DATA ===');
                    const apiMerchants = response.data[productId].merchants;
                    Object.keys(apiMerchants).forEach(merchantName => {
                        const merchant = apiMerchants[merchantName];
                        console.log(`Merchant: ${merchantName}, Price: ${merchant.lowest_price || merchant.price || 'N/A'}`);
                    });
                    console.log('=== END FRESH API MERCHANT DATA ===');
                }
                } else {
                    console.log('No merchant info found in database for product:', productId);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error refreshing merchant count from database:', error);
            }
        });
    }
    
    function saveComparison() {
        if (comparisonProducts.length === 0) {
            showWarning('No products in comparison to save');
            return;
        }
        
        const $modal = $('#aebg-merchant-comparison-modal');
        const productId = $modal.data('product-id');
        const postId = getCurrentPostId();
        
        if (!productId) {
            showError('No product ID found for comparison');
            return;
        }
        
        const comparisonData = {
            products: comparisonProducts,
            timestamp: new Date().toISOString(),
            search_params: searchParams
        };
        
        // Save to database
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_save_comparison',
                nonce: aebg_ajax.nonce,
                post_id: postId,
                product_id: productId,
                comparison_name: 'Product Comparison',
                comparison_data: comparisonData
            },
            success: function(response) {
                if (response.success) {
                    showSuccess('Comparison saved successfully to database');
                    // Removed hideMerchantComparisonModal() - modal stays open
                    
                    // Reload page to show updated merchant counts
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showError('Failed to save comparison: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showError('Network error while saving comparison: ' + error);
            }
        });
    }

    function showMerchantComparisonModal(productId, $row) {
        console.log('Showing merchant comparison modal for product:', productId);
        
        // Get product data from the row or stored data
        let productData = {
            id: productId,
            name: '',
            brand: '',
            merchant: '',
            price: 0,
            image_url: '',
            description: '',
            ean: '',
            gtin: ''
        };
        
        // Try to get data from the row first
        if ($row && $row.length > 0) {
            productData.name = $row.find('.aebg-product-name-text').text().trim() || '';
            productData.brand = $row.find('.aebg-product-meta span:nth-child(2)').text().replace('🏷️ ', '').trim() || '';
            productData.merchant = $row.find('.aebg-product-meta span:nth-child(1)').text().replace('💰 ', '').trim() || '';
            productData.price = parseFloat($row.find('.aebg-product-meta span:nth-child(1)').text().replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
            productData.image_url = $row.find('.aebg-td-image img').attr('src') || '';
            productData.description = $row.find('.aebg-product-description').text().trim() || '';
            productData.ean = $row.find('.aebg-ean').text().trim() || '';
            productData.gtin = $row.find('.aebg-gtin').text().trim() || '';
        }
        
        // If we couldn't get data from the row, try to get it from the stored products data
        if (!productData.name && window.aebgProducts && window.aebgProducts[productId]) {
            const storedProduct = window.aebgProducts[productId];
            productData = {
                id: productId,
                name: storedProduct.name || storedProduct.display_name || '',
                brand: storedProduct.brand || '',
                merchant: storedProduct.merchant || '',
                price: parseFloat(storedProduct.price) || 0,
                image_url: storedProduct.image_url || storedProduct.image || '',
                description: storedProduct.description || '',
                ean: storedProduct.ean || storedProduct.gtin || '',
                gtin: storedProduct.gtin || storedProduct.ean || '',
                network: storedProduct.network || '' // Include network information
            };
        }
        
        // If we still don't have product data, try to get it from the database
        if (!productData.name) {
            console.log('No product data found in DOM or stored data, will try to get from database');
            // The product data will be loaded when we call loadComparisonFromDatabase
        }
        
        console.log('Product data for modal:', productData);
        
        // Debug network information from product data
        console.log('🔍 PRODUCT DATA DEBUG: Network information from product data:');
        console.log('  - productData.network:', productData.network);
        console.log('  - productData.network_name:', productData.network_name);
        console.log('  - productData.network_info:', productData.network_info);
        console.log('  - productData.network_code:', productData.network_code);
        
        // Debug window.aebgProducts data structure
        if (window.aebgProducts && window.aebgProducts[productId]) {
            const storedProduct = window.aebgProducts[productId];
            console.log('🔍 STORED PRODUCT DEBUG: Found stored product data:', storedProduct);
            console.log('🔍 STORED PRODUCT DEBUG: Network field:', storedProduct.network);
            console.log('🔍 STORED PRODUCT DEBUG: All fields:', Object.keys(storedProduct));
        } else {
            console.log('🔍 STORED PRODUCT DEBUG: No stored product data found for ID:', productId);
            console.log('🔍 STORED PRODUCT DEBUG: Available product IDs:', Object.keys(window.aebgProducts || {}));
        }
        
        // Store product data in modal
        $('#aebg-merchant-comparison-modal').data('product-data', productData);
        $('#aebg-merchant-comparison-modal').data('product-id', productId);
        
        // Populate modal product information
        populateModalProductInfo(productData);
        
        // Don't clear comparison data immediately - let loadComparisonFromDatabase handle it
        // This prevents data loss when switching between products
        console.log('🔍 MODAL: Preserving comparison data for proper loading');
        
        console.log('🔍 MODAL: Opening modal for product:', productId);
        console.log('🔍 MODAL: Product data:', productData);
        
        // Clear search results
        clearModalSearch();
        
        // Show modal first
        $('#aebg-merchant-comparison-modal').addClass('show');
        
        // Load comparison data from our database first
        console.log('Loading comparison data for product:', productId);
        console.log('🔍 FIRST PRODUCT DEBUG: Product ID type:', typeof productId, 'Value:', productId);
        console.log('🔍 FIRST PRODUCT DEBUG: Product data:', productData);
        
        loadComparisonFromDatabase(productId, function(hasExistingData) {
            if (hasExistingData) {
                console.log('✅ Found existing comparison data, displaying it');
                // The comparison data will be displayed by loadComparisonFromDatabase
                // No need to make additional API calls
            } else {
                console.log('❌ No existing comparison data, loading fresh API data and saving to database');
                // Only load fresh data if we don't have any existing data
                loadMerchantComparisonDataAndSave(productId, productData);
            }
        });
        
        // Initialize drag and drop after data is loaded
        setTimeout(function() {
            initComparisonTableDragAndDrop();
        }, 200);
        
        // Update merchant count after modal is fully loaded
        setTimeout(function() {
            updateMerchantCountFromComparison();
        }, 500);
        
        // Also update the merchant count and price range from the modal data to ensure consistency
        setTimeout(function() {
            const $modal = $('#aebg-merchant-comparison-modal');
            const productId = $modal.data('product-id');
            const comparisonData = $modal.data('comparison-data');
            
            if (productId && comparisonData && comparisonData.merchants) {
                const merchantCount = Object.keys(comparisonData.merchants).length;
                console.log('Updating merchant count and price range from modal data:', merchantCount, 'merchants');
                
                // Update the merchant count in the UI directly
                const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
                if ($merchantInfo.length > 0) {
                    const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
                    $merchantCountElement.text(merchantCount);
                    
                    // Calculate price range from modal data
                    const prices = [];
                    const merchantPrices = {};
                    
                    Object.keys(comparisonData.merchants).forEach(merchantName => {
                        const merchant = comparisonData.merchants[merchantName];
                        const price = merchant.lowest_price || merchant.price || 0;
                        if (price > 0) {
                            prices.push(price);
                            merchantPrices[merchantName] = price;
                        }
                    });
                    
                    const lowestPrice = prices.length > 0 ? Math.min(...prices) : 0;
                    const highestPrice = prices.length > 0 ? Math.max(...prices) : 0;
                    
                    // Find merchants with lowest and highest prices
                    let lowestMerchant = 'Unknown';
                    let highestMerchant = 'Unknown';
                    
                    Object.keys(merchantPrices).forEach(merchant => {
                        if (merchantPrices[merchant] === lowestPrice) {
                            lowestMerchant = merchant;
                        }
                        if (merchantPrices[merchant] === highestPrice) {
                            highestMerchant = merchant;
                        }
                    });
                    
                    console.log('Price range from modal data:', lowestPrice, 'to', highestPrice, 'from merchants:', lowestMerchant, 'and', highestMerchant);
                    
                    // Update price range display
                    const $lowestPriceElement = $merchantInfo.find('.aebg-price-lowest .aebg-price-value');
                    const $lowestMerchantElement = $merchantInfo.find('.aebg-price-lowest .aebg-merchant-name');
                    const $highestPriceElement = $merchantInfo.find('.aebg-price-highest .aebg-price-value');
                    const $highestMerchantElement = $merchantInfo.find('.aebg-price-highest .aebg-merchant-name');
                    
                    if ($lowestPriceElement.length > 0) {
                        $lowestPriceElement.text(formatPrice(lowestPrice));
                        $lowestMerchantElement.text(lowestMerchant);
                    }
                    
                    if ($highestPriceElement.length > 0) {
                        $highestPriceElement.text(formatPrice(highestPrice));
                        $highestMerchantElement.text(highestMerchant);
                    }
                    
                    // Update visual indicator
                    $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                    if (merchantCount > 5) {
                        $merchantInfo.addClass('many-merchants');
                    } else if (merchantCount > 2) {
                        $merchantInfo.addClass('some-merchants');
                    } else if (merchantCount > 0) {
                        $merchantInfo.addClass('few-merchants');
                    }
                    
                    console.log('Successfully updated merchant count and price range from modal data:', merchantCount);
                }
            }
        }, 300);
        
        // Add a manual sync button for debugging
        setTimeout(function() {
            const $modal = $('#aebg-merchant-comparison-modal');
            if ($modal.find('.aebg-sync-merchant-count').length === 0) {
                $modal.find('.modal-header').append('<button type="button" class="aebg-sync-merchant-count" style="position: absolute; right: 60px; top: 10px; background: #0073aa; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px;">Sync Data</button>');
            }
        }, 600);
        
        // Focus on search field
        $('#merchant-search-input').focus();
        
        // Initialize search toggle functionality
        initializeModalSearchToggle();
        
        // Load networks dynamically
        loadModalNetworks();
    }

    /**
     * Initialize modal search toggle functionality
     */
    function initializeModalSearchToggle() {
        // Handle search toggle button
        $(document).off('click', '#modal-toggle-search').on('click', '#modal-toggle-search', function() {
            const $filters = $('#modal-search-filters');
            const $button = $(this);
            const $icon = $button.find('.dashicons');
            const $text = $button.contents().filter(function() {
                return this.nodeType === 3;
            });
            
            if ($filters.is(':visible')) {
                $filters.slideUp(300);
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $text.replaceWith(' Hide Search');
            } else {
                $filters.slideDown(300);
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $text.replaceWith(' Show Search');
            }
        });
        
        // Handle clear search button
        $(document).off('click', '#modal-clear-search').on('click', '#modal-clear-search', function() {
            $('#modal-search-name').val('');
            $('#modal-search-brand').val('');
            $('#modal-search-currency').val('');
            $('#modal-search-networks').val('');
            $('#modal-search-category').val('');
            $('#modal-search-rating').val('');
            $('#modal-search-min-price').val('');
            $('#modal-search-max-price').val('');
            $('#modal-search-limit').val('50');
            $('#modal-search-has-image').prop('checked', false);
            $('#modal-search-sort').val('relevance');
            $('#modal-search-in-stock').prop('checked', false);
        });
        
        // Handle search button
        $(document).off('click', '#modal-search-products').on('click', '#modal-search-products', function() {
            // Collect search parameters
            const rawSearchParams = {
                name: $('#modal-search-name').val().trim(),
                brand: $('#modal-search-brand').val().trim(),
                currency: $('#modal-search-currency').val(),
                networks: $('#modal-search-networks').val(),
                category: $('#modal-search-category').val().trim(),
                rating: $('#modal-search-rating').val(),
                min_price: $('#modal-search-min-price').val(),
                max_price: $('#modal-search-max-price').val(),
                limit: $('#modal-search-limit').val(),
                has_image: $('#modal-search-has-image').is(':checked'),
                sort: $('#modal-search-sort').val(),
                in_stock: $('#modal-search-in-stock').is(':checked')
            };
            
            // Filter out empty parameters
            const searchParams = {};
            Object.keys(rawSearchParams).forEach(key => {
                const value = rawSearchParams[key];
                if (value !== '' && value !== null && value !== undefined && value !== false) {
                    // Special handling for networks array
                    if (key === 'networks') {
                        if (Array.isArray(value) && value.length > 0) {
                            searchParams[key] = value;
                        } else if (typeof value === 'string' && value.trim() !== '') {
                            searchParams[key] = [value];
                        }
                    } else {
                        searchParams[key] = value;
                    }
                }
            });
            
            console.log('Raw search parameters:', rawSearchParams);
            console.log('Filtered search parameters:', searchParams);
            
            // Show search results section
            $('#modal-search-results-section').show();
            
            // Perform actual search
            performProductSearch(searchParams);
        });
    }

    /**
     * Perform product search using the provided parameters
     */
    function performProductSearch(searchParams) {
        console.log('🔍 SEARCH: Performing product search with params:', searchParams);
        
        // Show loading state
        $('#modal-search-results').html('<div class="aebg-search-loading">Searching for products...</div>');
        $('#modal-results-count').text('(Searching...)');
        
        // Make AJAX request to search for products using advanced search
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_search_products_advanced',
                nonce: aebg_ajax.search_products_nonce,
                query: searchParams.name || '',
                brand: searchParams.brand || '',
                limit: searchParams.limit || 50,
                sort_by: searchParams.sort || 'relevance',
                min_price: searchParams.min_price || '',
                max_price: searchParams.max_price || '',
                min_rating: searchParams.rating || '',
                in_stock_only: searchParams.in_stock || false,
                currency: searchParams.currency || '',
                category: searchParams.category || '',
                has_image: searchParams.has_image || false,
                network_ids: searchParams.networks && searchParams.networks.length > 0 ? searchParams.networks : '',
                page: 1
            },
            success: function(response) {
                console.log('🔍 SEARCH: Search response received:', response);
                
                if (response.success && response.data) {
                    // Handle advanced search response format
                    const products = response.data.products || [];
                    const totalResults = products.length; // Use actual products array length
                    const pagination = response.data.pagination || {};
                    
                    console.log('🔍 SEARCH: Found', totalResults, 'products');
                    console.log('🔍 SEARCH: Pagination info:', pagination);
                    
                    // Update results count
                    $('#modal-results-count').text(`(${totalResults} results)`);
                    
                    if (products && products.length > 0) {
                        // Display search results
                        displaySearchResults(products);
                    } else {
                        // Show no results message
                        $('#modal-search-results').html('<div class="aebg-search-no-results">No products found matching your search criteria.</div>');
                    }
                } else {
                    console.error('🔍 SEARCH: Search failed:', response.data);
                    $('#modal-search-results').html('<div class="aebg-search-error">Search failed: ' + (response.data || 'Unknown error') + '</div>');
                    $('#modal-results-count').text('(Error)');
                }
            },
            error: function(xhr, status, error) {
                console.error('🔍 SEARCH: AJAX error:', error);
                $('#modal-search-results').html('<div class="aebg-search-error">Search failed due to a network error. Please try again.</div>');
                $('#modal-results-count').text('(Error)');
            }
        });
    }

    /**
     * Display search results in the modal
     */
    function displaySearchResults(products) {
        let html = '';
        
        // Create table header
        html += `
            <table class="aebg-search-results-table">
                <thead>
                    <tr>
                        <th class="aebg-th-product">Product</th>
                        <th class="aebg-th-price">Price</th>
                        <th class="aebg-th-network">Network</th>
                        <th class="aebg-th-availability">Availability</th>
                        <th class="aebg-th-merchants">Merchant</th>
                        <th class="aebg-th-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        products.forEach((product, index) => {
            const productName = product.name || 'Unknown Product';
            const productPrice = product.price ? formatPrice(product.price) : 'N/A';
            const productImage = product.image_url || '';
            let productUrl = product.url || '';
            
            // Clean up the URL if it's malformed (same logic as in comparison table)
            if (productUrl) {
                // Fix malformed URLs with double slashes
                productUrl = productUrl.replace(/^https:\/\/s\/\//, 'https://'); // Fix https://s// to https://
                productUrl = productUrl.replace(/^http:\/\/s\/\//, 'https://'); // Fix http://s// to https://
                productUrl = productUrl.replace(/^http:\/\//, 'https://'); // Ensure https://
                
                // Additional safety: remove any remaining double slashes in the protocol
                productUrl = productUrl.replace(/^(https?):\/\/\/+/, '$1://');
            }
            const productNetwork = product.network || product.network_name || product.source_name || 'Unknown';
            const productAvailability = product.availability || 'Unknown';
            const merchantName = product.merchant || product.merchant_name || product.store || 'Unknown';
            
            html += `
                <tr class="aebg-search-result-row" data-product-id="${product.id || index}">
                    <td class="aebg-td-product">
                        <div class="aebg-product-info">
                            <div class="aebg-product-image">
                                ${productImage ? `<img src="${productImage}" alt="${productName}" onerror="this.parentNode.innerHTML='<div class=\'aebg-no-image\'>No Image</div>';">` : '<div class="aebg-no-image">No Image</div>'}
                            </div>
                            <div class="aebg-product-details">
                                <div class="aebg-product-name">
                                    ${productUrl ? `<a href="${productUrl}" target="_blank" class="aebg-product-link" title="View product on merchant website" rel="noopener noreferrer">${productName}</a>` : productName}
                                </div>
                                <div class="aebg-product-brand">${product.brand || ''}</div>
                            </div>
                        </div>
                    </td>
                    <td class="aebg-td-price">${productPrice}</td>
                    <td class="aebg-td-network">${productNetwork}</td>
                    <td class="aebg-td-availability">${productAvailability}</td>
                    <td class="aebg-td-merchants">${merchantName}</td>
                    <td class="aebg-td-actions">
                        <button type="button" class="aebg-btn-add-to-comparison" data-product-id="${product.id || index}" title="Add to comparison">
                            <span class="dashicons dashicons-plus"></span>
                            Add
                        </button>
                    </td>
                </tr>
            `;
        });
        
        // Close table
        html += `
                </tbody>
            </table>
        `;
        
        $('#modal-search-results').html(html);
        
        // Initialize add to comparison functionality
        initSearchResultActions();
        
        // Add CSS for the search results table that matches the modal design
        if (!$('#aebg-search-results-styles').length) {
            const styles = `
                <style id="aebg-search-results-styles">
                    .aebg-search-results-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 10px;
                        font-size: 13px;
                        background: white;
                        border-radius: 4px;
                        overflow: hidden;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    }
                    
                    .aebg-search-results-table th {
                        background-color: #f1f1f1;
                        border: none;
                        border-bottom: 2px solid #ddd;
                        padding: 10px 8px;
                        text-align: left;
                        font-weight: 600;
                        color: #333;
                        font-size: 12px;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    
                    .aebg-search-results-table td {
                        border: none;
                        border-bottom: 1px solid #eee;
                        padding: 12px 8px;
                        vertical-align: middle;
                    }
                    
                    .aebg-search-results-table tr:hover {
                        background-color: #f9f9f9;
                    }
                    
                    .aebg-product-info {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                    }
                    
                    .aebg-product-image {
                        flex-shrink: 0;
                        width: 50px;
                        height: 50px;
                        border-radius: 3px;
                        overflow: hidden;
                        border: 1px solid #eee;
                        background: #f9f9f9;
                    }
                    
                    .aebg-product-image img {
                        width: 100%;
                        height: 100%;
                        object-fit: cover;
                    }
                    
                    .aebg-product-details {
                        flex: 1;
                        min-width: 0;
                    }
                    
                    .aebg-product-name {
                        font-weight: 500;
                        margin-bottom: 3px;
                        line-height: 1.3;
                        font-size: 13px;
                    }
                    
                    .aebg-product-name a {
                        color: #0073aa;
                        text-decoration: none;
                    }
                    
                    .aebg-product-name a:hover {
                        text-decoration: underline;
                    }
                    
                    .aebg-product-brand {
                        font-size: 11px;
                        color: #666;
                        font-style: italic;
                    }
                    
                    .aebg-td-price {
                        font-weight: 600;
                        color: #28a745;
                        font-size: 13px;
                    }
                    
                    .aebg-td-network {
                        color: #333;
                        font-size: 12px;
                    }
                    
                    .aebg-td-availability {
                        text-transform: capitalize;
                        font-size: 12px;
                        color: #666;
                    }
                    
                    .aebg-td-merchants {
                        text-align: center;
                        font-weight: 500;
                        color: #333;
                        font-size: 12px;
                    }
                    
                    .aebg-td-actions {
                        text-align: center;
                    }
                    
                    .aebg-btn-add-to-comparison {
                        background-color: #0073aa;
                        color: white;
                        border: none;
                        padding: 6px 12px;
                        border-radius: 3px;
                        cursor: pointer;
                        font-size: 11px;
                        transition: all 0.2s;
                        font-weight: 500;
                    }
                    
                    .aebg-btn-add-to-comparison:hover {
                        background-color: #005a87;
                        transform: translateY(-1px);
                    }
                    
                    .aebg-btn-add-to-comparison:disabled {
                        background-color: #6c757d;
                        cursor: not-allowed;
                        transform: none;
                    }
                    
                    .aebg-no-image {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        height: 100%;
                        background-color: #f9f9f9;
                        color: #999;
                        font-size: 10px;
                        text-align: center;
                        padding: 4px;
                    }
                </style>
            `;
            $('head').append(styles);
        }
    }

    /**
     * Initialize actions for search result items
     */
    function initSearchResultActions() {
        // Handle add to comparison button clicks
        $(document).off('click', '.aebg-btn-add-to-comparison').on('click', '.aebg-btn-add-to-comparison', function() {
            const productId = $(this).data('product-id');
            const $row = $(this).closest('.aebg-search-result-row');
            
            // Get product data from the row
            const productData = {
                id: productId,
                name: $row.find('.aebg-product-name').text().trim(),
                price: $row.find('.aebg-td-price').text().trim(),
                network: $row.find('.aebg-td-network').text().trim(),
                availability: $row.find('.aebg-td-availability').text().trim(),
                merchant: $row.find('.aebg-td-merchants').text().trim(),
                image_url: $row.find('.aebg-product-image img').attr('src') || '',
                url: $row.find('.aebg-product-name a').attr('href') || ''
            };
            
            console.log('🔍 SEARCH: Adding product to comparison:', productData);
            
            // Add the product to the comparison table
            addProductToComparison(productData);
            
            // Show success message
            $(this).html('<span class="dashicons dashicons-yes"></span> Added');
            $(this).prop('disabled', true);
            
            setTimeout(() => {
                $(this).html('<span class="dashicons dashicons-plus"></span> Add');
                $(this).prop('disabled', false);
            }, 2000);
        });
    }

    /**
     * Load networks dynamically for the modal
     */
    function loadModalNetworks() {
        const $networksSelect = $('#modal-search-networks');
        const $loadingIndicator = $('#modal-networks-loading');
        
        // Show loading indicator
        $loadingIndicator.show();
        $networksSelect.hide();
        
        // Make AJAX request to get networks
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_networks_for_modal',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Clear existing options
                    $networksSelect.empty();
                    
                    // Add "All Networks" option
                    $networksSelect.append('<option value="">All Networks</option>');
                    
                    // Add network options
                    response.data.forEach(function(network) {
                        const optionText = network.configured 
                            ? `${network.name} (${network.country}) ✅` 
                            : `${network.name} (${network.country})`;
                        
                        $networksSelect.append(
                            `<option value="${network.code}" ${network.configured ? 'data-configured="true"' : ''}>${optionText}</option>`
                        );
                    });
                    
                    console.log('Loaded ' + response.data.length + ' networks for modal');
                } else {
                    console.error('Failed to load networks:', response.data);
                    $networksSelect.html('<option value="">Error loading networks</option>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Network loading error:', error);
                $networksSelect.html('<option value="">Error loading networks</option>');
            },
            complete: function() {
                // Hide loading indicator and show select
                $loadingIndicator.hide();
                $networksSelect.show();
            }
        });
    }

    /**
     * Populate modal product information header
     */
    function populateModalProductInfo(productData) {
        // Set product image
        const $productImage = $('#modal-product-image');
        if (productData.image_url && productData.image_url.trim() !== '') {
            $productImage.attr('src', productData.image_url);
            $productImage.attr('alt', productData.name || 'Product Image');
            $productImage.show();
        } else {
            $productImage.hide();
            $productImage.closest('.aebg-product-image').html('<div class="aebg-no-image">No Image Available</div>');
        }
        
        // Set product name
        const $productName = $('#modal-product-name');
        if (productData.name && productData.name.trim() !== '') {
            $productName.text(productData.name);
            $productName.show();
        } else {
            $productName.text('Product Name Not Available');
            $productName.addClass('text-muted');
        }
        
        // Set product description
        const $productDescription = $('#modal-product-description');
        if (productData.description && productData.description.trim() !== '') {
            $productDescription.text(productData.description);
            $productDescription.show();
        } else {
            $productDescription.hide();
        }
        
        // Set product price
        const $productPrice = $('#modal-product-price');
        if (productData.price && productData.price > 0) {
            $productPrice.html(`<span class="aebg-price-label">💰 Price:</span> ${formatPrice(productData.price)}`);
            $productPrice.show();
        } else {
            $productPrice.hide();
        }
        
        // Set product brand
        const $productBrand = $('#modal-product-brand');
        if (productData.brand && productData.brand.trim() !== '') {
            $productBrand.html(`<span class="aebg-brand-label">🏷️ Brand:</span> ${productData.brand}`);
            $productBrand.show();
        } else {
            $productBrand.hide();
        }
        
        // Set product rating (if available)
        const $productRating = $('#modal-product-rating');
        if (productData.rating && productData.rating > 0) {
            const stars = generateStars(productData.rating);
            $productRating.html(`<span class="aebg-rating-label">⭐ Rating:</span> ${stars} ${productData.rating.toFixed(1)}/5`);
            $productRating.show();
        } else {
            $productRating.hide();
        }
        
        // Add EAN/GTIN if available
        const $productMeta = $('.aebg-product-meta');
        if ((productData.ean && productData.ean.trim() !== '') || (productData.gtin && productData.gtin.trim() !== '')) {
            let eanGtinHtml = '';
            if (productData.ean && productData.ean.trim() !== '') {
                eanGtinHtml += `<span class="aebg-ean-label">📋 EAN:</span> ${productData.ean}`;
            }
            if (productData.gtin && productData.gtin.trim() !== '') {
                if (eanGtinHtml) eanGtinHtml += ' ';
                eanGtinHtml += `<span class="aebg-gtin-label">🏷️ GTIN:</span> ${productData.gtin}`;
            }
            
            // Remove existing EAN/GTIN elements
            $productMeta.find('.aebg-ean-gtin').remove();
            
            // Add new EAN/GTIN element
            $productMeta.append(`<span class="aebg-ean-gtin">${eanGtinHtml}</span>`);
        }
    }

    function displayMerchantComparisonTable(merchantData) {
        const $tbody = $('#comparison-table-body');
        let html = '';
        
        // Verify we're displaying data for the correct product
        const modalProductId = $('#aebg-merchant-comparison-modal').data('product-id');
        if (!modalProductId) {
            console.error('❌ No product ID found in modal - cannot display comparison data');
            return;
        }
        
        console.log('=== DISPLAY MERCHANT COMPARISON TABLE DEBUG ===');
        console.log('Displaying merchant comparison table for product:', modalProductId);
        console.log('Displaying merchant comparison table:', merchantData);
        
        // Enhanced debugging for merchant data structure
        if (merchantData) {
            console.log('merchantData type:', typeof merchantData);
            console.log('merchantData keys:', Object.keys(merchantData));
        console.log('merchantData.merchants:', merchantData.merchants);
        console.log('merchantData.original_product:', merchantData.original_product);
        console.log('merchantData.merchant_count:', merchantData.merchant_count);
        console.log('merchantData.price_range:', merchantData.price_range);
            console.log('merchantData.search_results:', merchantData.search_results);
            
            if (merchantData.merchants) {
                console.log('merchantData.merchants type:', typeof merchantData.merchants);
                console.log('merchantData.merchants is array:', Array.isArray(merchantData.merchants));
                console.log('merchantData.merchants is object:', typeof merchantData.merchants === 'object' && merchantData.merchants !== null);
                console.log('merchantData.merchants keys:', Object.keys(merchantData.merchants));
                console.log('merchantData.merchants length:', Object.keys(merchantData.merchants).length);
                
                // Debug each merchant to see if search result flags are preserved
                Object.keys(merchantData.merchants).forEach(merchantName => {
                    const merchant = merchantData.merchants[merchantName];
                    console.log(`🔍 Merchant ${merchantName}:`, {
                        is_search_result: merchant.is_search_result,
                        has_original_product: !!merchant.original_product,
                        original_product_name: merchant.original_product?.name
                    });
                });
            }
        }
        
        // Debug window.aebgProducts data for this product
        let originalProduct = null;
        if (window.aebgProducts && Array.isArray(window.aebgProducts)) {
            originalProduct = window.aebgProducts.find(product => product.id === modalProductId);
        }
        
        if (originalProduct) {
            console.log('🔍 ORIGINAL PRODUCT DEBUG: Found original product data:', originalProduct);
            console.log('🔍 ORIGINAL PRODUCT DEBUG: Network field:', originalProduct.network);
            console.log('🔍 ORIGINAL PRODUCT DEBUG: All fields:', Object.keys(originalProduct));
        } else {
            console.log('🔍 ORIGINAL PRODUCT DEBUG: No original product data found for ID:', modalProductId);
            console.log('🔍 ORIGINAL PRODUCT DEBUG: Available product IDs:', window.aebgProducts ? window.aebgProducts.map(p => p.id) : 'undefined');
            console.log('🔍 ORIGINAL PRODUCT DEBUG: window.aebgProducts structure:', window.aebgProducts);
        }
        
        console.log('🔍 FIRST PRODUCT DEBUG: Full merchantData structure:', JSON.stringify(merchantData, null, 2));
        
        // Initialize merchants array at the top level of the function
        let merchants = [];
        
        // Validate input data structure
        if (!merchantData) {
            console.error('❌ No merchant data provided to displayMerchantComparisonTable');
            html = '<tr><td colspan="8" class="text-center">No merchant data provided</td></tr>';
        } else if (!merchantData.merchants) {
            console.error('❌ No merchants property in merchant data:', merchantData);
            html = '<tr><td colspan="8" class="text-center">No merchants data available</td></tr>';
        } else if (typeof merchantData.merchants !== 'object') {
            console.error('❌ Merchants property is not an object:', typeof merchantData.merchants, merchantData.merchants);
            html = '<tr><td colspan="8" class="text-center">Invalid merchants data format</td></tr>';
        } else if (Object.keys(merchantData.merchants).length === 0) {
            console.log('No merchant data available - showing empty state');
            html = '<tr><td colspan="8" class="text-center">No merchant data available</td></tr>';
        } else {
            // Additional validation: check if merchants object has valid structure
            const merchantKeys = Object.keys(merchantData.merchants);
            const validMerchants = merchantKeys.filter(key => {
                const merchant = merchantData.merchants[key];
                return merchant && typeof merchant === 'object' && Object.keys(merchant).length > 0;
            });
            
            if (validMerchants.length === 0) {
                console.error('❌ No valid merchant objects found in merchants data');
                html = '<tr><td colspan="8" class="text-center">No valid merchant data found</td></tr>';
            } else {
                console.log(`Found ${validMerchants.length} valid merchants out of ${merchantKeys.length} total`);
            }
            // Validate merchants object structure
            if (typeof merchantData.merchants !== 'object' || merchantData.merchants === null) {
                console.error('❌ Invalid merchants object:', merchantData.merchants);
                return;
            }
            
            // Convert the merchants object to an array of merchant objects with names preserved
            if (typeof merchantData.merchants === 'object' && merchantData.merchants !== null) {
                const merchantKeys = Object.keys(merchantData.merchants);
                console.log('🔍 MERCHANT PROCESSING: Found', merchantKeys.length, 'merchants in merchantData.merchants');
                console.log('🔍 MERCHANT PROCESSING: Merchant keys:', merchantKeys);
                
                // Get configured networks for filtering
                const configuredNetworks = getConfiguredNetworks();
                console.log('🔍 MERCHANT PROCESSING: Configured networks for filtering:', configuredNetworks);
                
                merchantKeys.forEach((merchantName, keyIndex) => {
                    const merchant = merchantData.merchants[merchantName];
                    console.log(`🔍 MERCHANT PROCESSING [${keyIndex + 1}/${merchantKeys.length}]: Processing merchant "${merchantName}"`);
                    
                    if (merchant && typeof merchant === 'object') {
                        // Validate merchant object structure based on Datafeedr API
                        const validatedMerchant = {
                            // CRITICAL FIX: Preserve the original merchant object first, then override specific fields
                            ...merchant,
                            // Override specific fields for consistency
                            merchant_name: merchantName, // Store the merchant name separately
                            id: merchant.id || merchant.merchant_id || null,
                            url: merchant.url || merchant.website || null,
                            logo: merchant.logo || merchant.image || null,
                            country: merchant.country || merchant.country_code || null,
                            network: merchant.network || merchant.network_name || merchant.network_code || null
                        };
                        
                        // CRITICAL: Filter by configured networks
                        // Use exact or word-boundary matching to avoid false positives
                        if (configuredNetworks.length > 0) {
                            const merchantNetwork = (validatedMerchant.network || '').toLowerCase().trim();
                            const isConfigured = configuredNetworks.some(configuredNetwork => {
                                const configuredLower = configuredNetwork.toLowerCase().trim();
                                
                                if (!merchantNetwork || !configuredLower) {
                                    return false;
                                }
                                
                                // Exact match
                                if (merchantNetwork === configuredLower) {
                                    return true;
                                }
                                
                                // Word-boundary matching to prevent false positives
                                // e.g., "partner" won't match "partnerize"
                                const merchantRegex = new RegExp('\\b' + configuredLower.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i');
                                const configuredRegex = new RegExp('\\b' + merchantNetwork.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'i');
                                
                                if (merchantRegex.test(merchantNetwork) || configuredRegex.test(configuredLower)) {
                                    return true;
                                }
                                
                                return false;
                            });
                            
                            if (!isConfigured) {
                                console.log(`🔍 MERCHANT PROCESSING: Filtered out merchant "${merchantName}" (network: ${merchantNetwork}) - not in configured networks`);
                                return; // Skip this merchant
                            }
                        }
                        
                        merchants.push(validatedMerchant);
                        console.log(`🔍 MERCHANT PROCESSING: Added validated merchant [${merchants.length}]:`, merchantName, 'with data:', validatedMerchant);
                    } else {
                        console.warn('⚠️ MERCHANT PROCESSING: Skipping invalid merchant data for:', merchantName, merchant);
                    }
                });
                
                console.log('🔍 MERCHANT PROCESSING: Total merchants added to array after filtering:', merchants.length);
            } else {
                console.error('❌ MERCHANT PROCESSING: merchantData.merchants is not an object or is null:', typeof merchantData.merchants, merchantData.merchants);
            }
            
            // Ensure merchants array is always defined and is an array
            if (!Array.isArray(merchants)) {
                console.error('❌ Merchants array is not properly initialized:', merchants);
                merchants = [];
            }
            console.log('Found ' + merchants.length + ' merchants:', merchants);
            
            // Validate merchants array
            if (!Array.isArray(merchants)) {
                console.error('❌ Merchants is not an array:', merchants);
                return;
            }
            
            if (merchants.length === 0) {
                console.log('ℹ️ No merchants to display');
                // Don't return here - we might have search results to display
            }
            
            // Update comparison count based on actual merchants for THIS product
            // After processing search results, count all unique merchants
            const allMerchantNames = new Set();
            
            // Count merchants from the main merchants object
            if (merchantData.merchants) {
                Object.keys(merchantData.merchants).forEach(merchantName => {
                    allMerchantNames.add(merchantName);
                });
            }
            
            // Count merchants from search results that aren't already counted
            if (merchantData.search_results && Array.isArray(merchantData.search_results)) {
                merchantData.search_results.forEach(searchProduct => {
                    const merchantName = searchProduct.merchant || searchProduct.store || 'Unknown';
                    allMerchantNames.add(merchantName);
                });
            }
            
            const totalCount = allMerchantNames.size;
            $('#comparison-count').text(`(${totalCount} items)`);
            
            // Display merchants from database or API response
            console.log('🔍 DISPLAY: About to display', merchants.length, 'merchants');
            if (merchants.length > 0) {
            merchants.forEach((merchant, index) => {
                console.log(`🔍 DISPLAY [${index + 1}/${merchants.length}]: Processing merchant at index`, index, ':', merchant.name || merchant.merchant_name || 'Unknown');
                console.log('🔍 Merchant name:', merchant.name, 'Type:', typeof merchant.name);
                console.log('🔍 Merchant is_search_result:', merchant.is_search_result);
                console.log('🔍 Merchant original_product:', merchant.original_product);
                
                // ADDITIONAL DEBUG: Log the full merchant object structure
                console.log('🔍 FULL MERCHANT OBJECT for', merchant.name, ':', JSON.stringify(merchant, null, 2));
                
                // Special debugging for first product
                if (index === 0) {
                    console.log('🔍 FIRST PRODUCT DEBUG: First merchant object:', merchant);
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant keys:', Object.keys(merchant));
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant name:', merchant.name, 'Type:', typeof merchant.name);
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant price:', merchant.price, 'Type:', typeof merchant.price);
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant availability:', merchant.availability, 'Type:', typeof merchant.availability);
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant lowest_price:', merchant.lowest_price, 'Type:', typeof merchant.lowest_price);
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant is_search_result:', merchant.is_search_result);
                    console.log('🔍 FIRST PRODUCT DEBUG: Merchant original_product:', merchant.original_product);
                }
                
                // Validate merchant data
                if (!merchant || typeof merchant !== 'object') {
                    console.error('❌ Invalid merchant data at index', index, ':', merchant);
                    return; // Skip this merchant
                }
                
                if (!merchant.name) {
                    console.error('❌ Merchant at index', index, 'has no name:', merchant);
                    return; // Skip this merchant
                }
                
                const isSelected = merchant.name === (merchantData.original_product?.merchant || '');
                const selectedClass = isSelected ? 'selected' : '';
                const rating = parseFloat(merchant.rating || merchant.average_rating || 0) || 0;
                const stars = generateStars(rating);
                const availability = merchant.availability || 'in_stock';
                        // Based on Datafeedr API structure, network info is in different fields
        // Also check the original product data from window.aebgProducts for network info
        let network = merchant.network_name || merchant.network || merchant.source_name || merchant.source || merchant.network_code || 'Unknown';
        
        // CRITICAL FIX: Map internal network codes to proper display names
        if (network === 'partnerads' || network === 'partner_ads' || network === 'api_15') {
            network = 'Partner Ads Denmark';
        } else if (network === 'awin') {
            network = 'Awin Denmark';
        } else if (network === 'timeone') {
            network = 'TimeOne';
        } else if (network === 'adrecord') {
            network = 'Adrecord';
        } else if (network === 'addrevenue') {
            network = 'AddRevenue';
        } else if (network === 'zanox') {
            network = 'Zanox';
        } else if (network === 'partnerize') {
            network = 'Partnerize';
        } else if (network === 'performance_horizon') {
            network = 'Performance Horizon';
        }

        // If network is still 'Unknown', try to get it from the original product data
        const modalProductId = $('#aebg-merchant-comparison-modal').data('product-id');
        if (network === 'Unknown' && window.aebgProducts && modalProductId) {
            let originalProduct = null;
            if (Array.isArray(window.aebgProducts)) {
                originalProduct = window.aebgProducts.find(product => product.id === modalProductId);
            }
            if (originalProduct && originalProduct.network) {
                network = originalProduct.network;
                console.log('🔍 NETWORK DEBUG: Got network from original product data:', network);
            }
        }

        // Additional fallback: check if this is a merchant object with network info
        if (network === 'Unknown' && merchant.merchant && typeof merchant.merchant === 'object' && merchant.merchant.network) {
            network = merchant.merchant.network;
            console.log('🔍 NETWORK DEBUG: Got network from merchant.merchant.network:', network);
        }
                
                console.log('🔍 NETWORK DEBUG: Final network value for', merchant.name, ':', network);
                const price = merchant.price || merchant.lowest_price || 0;
                
                // Special debugging for first product
                if (index === 0) {
                    console.log('🔍 FIRST PRODUCT DEBUG: Price extraction:');
                    console.log('  - merchant.price:', merchant.price, 'Type:', typeof merchant.price);
                    console.log('  - merchant.lowest_price:', merchant.lowest_price, 'Type:', typeof merchant.lowest_price);
                    console.log('  - Final price value:', price, 'Type:', typeof price);
                    console.log('  - Price > 0 check:', price > 0);
                    console.log('🔍 FIRST PRODUCT DEBUG: Availability extraction:');
                    console.log('  - merchant.availability:', merchant.availability, 'Type:', typeof merchant.availability);
                    console.log('  - Final availability value:', availability);
                    console.log('🔍 FIRST PRODUCT DEBUG: Network extraction:');
                    console.log('  - merchant.network:', merchant.network, 'Type:', typeof merchant.network);
                    console.log('  - merchant.network_name:', merchant.network_name, 'Type:', typeof merchant.network_name);
                    console.log('  - merchant.network_info:', merchant.network_info, 'Type:', typeof merchant.network_info);
                    console.log('  - merchant.network_code:', merchant.network_code, 'Type:', typeof merchant.network_code);
                    console.log('  - Final network value:', network, 'Type:', typeof network);
                }
                
                // Find the merchant key by matching the merchant name
                let merchantKey = merchant.name; // Use the merchant name directly since we preserved it
                
                console.log('Processed merchant data:', {
                    name: merchant.name,
                    key: merchantKey,
                    price: price,
                    rating: rating,
                    network: network,
                    availability: availability,
                    isSelected: isSelected
                });
                console.log('Raw merchant object for debugging:', merchant);
                
                // Make product name clickable if merchant URL is available
                let productNameCell = '';
                let productUrl = '';
                
                // Try to get URL from multiple sources
                if (merchant.url && typeof merchant.url === 'string' && merchant.url.trim() !== '') {
                    productUrl = merchant.url;
                } else if (merchantData.original_product && merchantData.original_product.url && typeof merchantData.original_product.url === 'string' && merchantData.original_product.url.trim() !== '') {
                    productUrl = merchantData.original_product.url;
                }
                
                // Clean up the URL if it's malformed
                if (productUrl) {
                    // Fix malformed URLs with double slashes
                    productUrl = productUrl.replace(/^https:\/\/s\/\//, 'https://'); // Fix https://s// to https://
                    productUrl = productUrl.replace(/^http:\/\/s\/\//, 'https://'); // Fix http://s// to https://
                    productUrl = productUrl.replace(/^http:\/\//, 'https://'); // Ensure https://
                    
                    // Additional safety: remove any remaining double slashes in the protocol
                    productUrl = productUrl.replace(/^(https?):\/\/\/+/, '$1://');
                    
                    console.log('🔗 URL DEBUG: Final cleaned URL (preserving affiliate tracking):', productUrl);
                }
                
                // CRITICAL FIX: Use merchant-specific product name if available, otherwise fall back to original product name
                let displayProductName = '';
                
                // DEBUG: Log what we're working with
                console.log(`🔍 PRODUCT NAME DEBUG for ${merchant.merchant_name || merchant.name}:`);
                console.log(`  - merchant.original_product:`, merchant.original_product);
                console.log(`  - merchant.product_name:`, merchant.product_name);
                console.log(`  - merchant.name:`, merchant.name);
                console.log(`  - merchant.title:`, merchant.title);
                console.log(`  - merchantData.original_product?.name:`, merchantData.original_product?.name);
                
                if (merchant.original_product && merchant.original_product.name && merchant.original_product.name.trim() !== '') {
                    // Use merchant's specific product name (e.g., from search results)
                    displayProductName = merchant.original_product.name;
                    console.log(`  ✅ Using merchant.original_product.name: "${displayProductName}"`);
                } else if (merchant.product_name && merchant.product_name.trim() !== '') {
                    // Use merchant's product_name field if available
                    displayProductName = merchant.product_name;
                    console.log(`  ✅ Using merchant.product_name: "${displayProductName}"`);
                } else if (merchant.name && merchant.name !== merchantData.original_product?.name && merchant.name.trim() !== '') {
                    // Use merchant's name if it's different from the original product name (likely a product name)
                    displayProductName = merchant.name;
                    console.log(`  ✅ Using merchant.name: "${displayProductName}"`);
                } else if (merchant.title && merchant.title.trim() !== '') {
                    // Use merchant's title if available
                    displayProductName = merchant.title;
                    console.log(`  ✅ Using merchant.title: "${displayProductName}"`);
                } else {
                    // Fall back to original product name
                    displayProductName = merchantData.original_product?.name || 'Unknown Product';
                    console.log(`  ✅ Using fallback: "${displayProductName}"`);
                }
                
                console.log(`  🎯 Final displayProductName: "${displayProductName}"`);
                
                if (productUrl) {
                    productNameCell = `<a href="${productUrl}" target="_blank" class="aebg-product-link" title="View product on ${merchant.name} website" rel="noopener noreferrer">${displayProductName} <span class="dashicons dashicons-external"></span></a>`;
                } else {
                    productNameCell = displayProductName;
                }
                
                // Fix price display - check multiple price fields and handle string prices
                let displayPrice = 'N/A';
                
                // First, try to get the price from the original product data for consistency
                if (merchantData.original_product && merchantData.original_product.price) {
                    const originalPrice = merchantData.original_product.price;
                    if (typeof originalPrice === 'string' && originalPrice.includes('kr.')) {
                        // Use the already formatted price from original product
                        displayPrice = originalPrice;
                    } else if (typeof originalPrice === 'number' && !isNaN(originalPrice) && originalPrice > 0) {
                        displayPrice = formatPrice(originalPrice);
                    } else if (typeof originalPrice === 'string' && originalPrice.trim() !== '' && originalPrice !== '0') {
                        // If it's a raw price string like "159000", format it properly
                        const numericPrice = parseFloat(originalPrice);
                        if (!isNaN(numericPrice) && numericPrice > 0) {
                            displayPrice = formatPrice(numericPrice);
                        } else {
                            displayPrice = originalPrice;
                        }
                    }
                }
                
                // Fallback to merchant price if original product price not available
                if (displayPrice === 'N/A') {
                if (typeof price === 'number' && !isNaN(price) && price > 0) {
                    displayPrice = formatPrice(price);
                } else if (typeof price === 'string' && price.trim() !== '' && price !== '0') {
                        // Handle string prices - if it's a raw number, format it
                        const numericPrice = parseFloat(price);
                        if (!isNaN(numericPrice) && numericPrice > 0) {
                            displayPrice = formatPrice(numericPrice);
                        } else {
                    displayPrice = price;
                        }
                } else if (merchant.price && typeof merchant.price === 'number' && !isNaN(merchant.price) && merchant.price > 0) {
                    displayPrice = formatPrice(merchant.price);
                } else if (merchant.price && typeof merchant.price === 'string' && merchant.price.trim() !== '' && merchant.price !== '0') {
                        // Handle merchant price string - if it's a raw number, format it
                        const numericPrice = parseFloat(merchant.price);
                        if (!isNaN(numericPrice) && numericPrice > 0) {
                            displayPrice = formatPrice(numericPrice);
                        } else {
                    displayPrice = merchant.price;
                        }
                } else if (merchant.lowest_price && typeof merchant.lowest_price === 'number' && !isNaN(merchant.lowest_price) && merchant.lowest_price > 0) {
                    displayPrice = formatPrice(merchant.lowest_price);
                } else if (merchant.lowest_price && typeof merchant.lowest_price === 'string' && merchant.lowest_price.trim() !== '' && merchant.lowest_price !== '0') {
                        // Handle lowest price string - if it's a raw number, format it
                        const numericPrice = parseFloat(merchant.lowest_price);
                        if (!isNaN(numericPrice) && numericPrice > 0) {
                            displayPrice = formatPrice(numericPrice);
                        } else {
                    displayPrice = merchant.lowest_price;
                        }
                    }
                }
                
                // Fix availability display - handle various availability formats
                let displayAvailability = 'Unknown';
                if (availability === 'in_stock' || availability === 'available' || availability === '1' || availability === 'In Stock') {
                    displayAvailability = 'In Stock';
                } else if (availability === 'out_of_stock' || availability === 'unavailable' || availability === '0' || availability === 'Out of Stock') {
                    displayAvailability = 'Out of Stock';
                } else if (availability && availability.toString().toLowerCase().includes('stock')) {
                    displayAvailability = availability;
                } else if (availability && availability.toString().trim() !== '') {
                    // Use the actual availability value if it exists
                    displayAvailability = availability.toString();
                }
                
                const merchantRowHtml = `
                    <tr class="${selectedClass} aebg-comparison-row" data-product-id="${merchant.merchant_name || merchant.name}" data-merchant="${merchant.merchant_name || merchant.name}">
                        <td class="aebg-td-drag">
                            <span class="aebg-drag-handle" title="Drag to reorder">⋮⋮</span>
                        </td>
                        <td>${merchant.merchant_name || merchant.name}</td>
                        <td>${productNameCell}</td>
                        <td>${displayPrice}</td>
                        <td>${network && network !== 'Unknown' ? network : 'Unknown'}</td>
                        <td>${stars} ${(typeof rating === 'number' && !isNaN(rating) ? rating.toFixed(1) : '0.0')}/5</td>
                        <td>${displayAvailability}</td>
                        <td>
                            ${productUrl ? `
                                <button type="button" class="aebg-btn-view-product" data-product-url="${productUrl}" title="View product on merchant website">
                                    <span class="dashicons dashicons-external"></span>
                                    View
                                </button>
                            ` : ''}
                            <button type="button" class="aebg-btn-remove-from-comparison" data-product-id="${merchant.merchant_name || merchant.name}">
                                <span class="dashicons dashicons-trash"></span>
                                Remove
                            </button>
                        </td>
                    </tr>
                `;
                html += merchantRowHtml;
                console.log(`🔍 DISPLAY [${index + 1}/${merchants.length}]: Added HTML row for merchant "${merchant.merchant_name || merchant.name}". Total HTML length: ${html.length} characters`);
                });
            }
        }
        
                // CRITICAL FIX: Use the same unified approach for consistency
        // Instead of showing search results separately, merge them into the main table
        if (merchantData.search_results && Array.isArray(merchantData.search_results) && merchantData.search_results.length > 0) {
            console.log('🔍 DISPLAY: Processing search results for unified display:', merchantData.search_results.length, 'products');
            
            // Add search results to the main merchants object if they're not already there
            merchantData.search_results.forEach(searchProduct => {
                const merchantName = searchProduct.merchant || searchProduct.store || 'Unknown';
                
                // Check if this merchant already exists in the main merchants object
                if (!merchantData.merchants[merchantName]) {
                    // Add the search product as a merchant
                    merchantData.merchants[merchantName] = {
                        name: merchantName,
                        id: searchProduct.id,
                        price: searchProduct.price,
                        lowest_price: searchProduct.price,
                        availability: searchProduct.availability || 'Unknown',
                        rating: searchProduct.rating || 0,
                        network: searchProduct.network || searchProduct.network_name || 'Unknown',
                        url: searchProduct.url || '',
                        is_search_result: true,
                        original_product: searchProduct
                    };
                }
            });
        }
        
        console.log('🔍 DISPLAY: Final HTML length:', html.length, 'characters');
        console.log('🔍 DISPLAY: Number of <tr> tags in HTML:', (html.match(/<tr/g) || []).length);
        $tbody.html(html);
        
        // Verify the table was updated correctly
        const rowsInTable = $tbody.find('tr').length;
        console.log('🔍 DISPLAY: Rows actually in table after update:', rowsInTable);
        
        // Ensure merchants array is defined before accessing its length
        if (typeof merchants !== 'undefined' && Array.isArray(merchants)) {
            console.log('🔍 DISPLAY: Table body updated with ' + merchants.length + ' merchants. Rows in table: ' + rowsInTable);
            if (merchants.length !== rowsInTable) {
                console.error('❌ DISPLAY: MISMATCH! Expected', merchants.length, 'rows but found', rowsInTable, 'rows in table!');
            }
        } else {
            console.log('🔍 DISPLAY: Table body updated (merchants array not available). Rows in table: ' + rowsInTable);
        }
        
        // Store the comparison data in the modal for remove functionality
        $('#aebg-merchant-comparison-modal').data('comparison-data', merchantData);
        
        // Log merchant details for debugging
        if (merchants && Array.isArray(merchants) && merchants.length > 0) {
            console.log('=== FRESH MERCHANT DATA ===');
            merchants.forEach(merchant => {
                console.log(`Merchant: ${merchant.name}, Price: ${merchant.lowest_price || merchant.price || 'N/A'}`);
            });
            console.log('=== END FRESH MERCHANT DATA ===');
        }
        
        // Initialize sorting
        initTableSorting();
        
        // Initialize drag and drop for comparison table
        setTimeout(function() {
            initComparisonTableDragAndDrop();
        }, 100);
        
        // Update merchant count in the main table based on comparison data
        setTimeout(function() {
            updateMerchantCountFromComparison();
        }, 200);
        
        // Also update the merchant count from the modal data to ensure consistency
        setTimeout(function() {
            const $modal = $('#aebg-merchant-comparison-modal');
            const productId = $modal.data('product-id');
            const comparisonData = $modal.data('comparison-data');
            
            if (productId && comparisonData && comparisonData.merchants) {
                // Get merchant count from the merchants array if it exists, otherwise fall back to object keys
                let merchantCount = 0;
                if (Array.isArray(comparisonData.merchants)) {
                    merchantCount = comparisonData.merchants.length;
                } else {
                    merchantCount = Object.keys(comparisonData.merchants).length;
                }
                console.log('Updating merchant count from modal data:', merchantCount, 'merchants');
                
                // Update the merchant count in the UI directly
                const $merchantInfo = $('.aebg-merchants-info[data-product-id="' + productId + '"]');
                if ($merchantInfo.length > 0) {
                    const $merchantCountElement = $merchantInfo.find('.aebg-merchants-number');
                    $merchantCountElement.text(merchantCount);
                    
                    // Update visual indicator
                    $merchantInfo.removeClass('few-merchants some-merchants many-merchants');
                    if (merchantCount > 5) {
                        $merchantInfo.addClass('many-merchants');
                    } else if (merchantCount > 2) {
                        $merchantInfo.addClass('some-merchants');
                    } else if (merchantCount > 0) {
                        $merchantInfo.addClass('few-merchants');
                    }
                    
                    console.log('Successfully updated merchant count from modal data:', merchantCount);
                }
            }
        }, 300);
        
        // Final safety check - ensure function completes successfully
        console.log('=== DISPLAY MERCHANT COMPARISON TABLE COMPLETED ===');
    }

    /**
     * Refresh network data in existing comparison data
     */
    function refreshNetworkDataInComparison(productId, comparisonData) {
        console.log('🔧 REFRESH DEBUG: Function called with productId:', productId);
        console.log('🔧 REFRESH DEBUG: comparisonData:', comparisonData);
        
        if (!comparisonData || !comparisonData.merchants) {
            console.log('🔧 REFRESH DEBUG: No comparison data or merchants, returning original');
            return comparisonData;
        }

        let hasChanges = false;
        const updatedMerchants = {};

        // Get the original product data - search through the array since it's indexed by numbers
        let originalProduct = null;
        let originalNetwork = null;
        
        if (window.aebgProducts && Array.isArray(window.aebgProducts)) {
            originalProduct = window.aebgProducts.find(product => product.id === productId);
            originalNetwork = originalProduct ? originalProduct.network : null;
        }

        console.log('🔧 REFRESH DEBUG: Refreshing network data for product:', productId);
        console.log('🔧 REFRESH DEBUG: Original network from window.aebgProducts:', originalNetwork);
        console.log('🔧 REFRESH DEBUG: window.aebgProducts structure:', window.aebgProducts);
        console.log('🔧 REFRESH DEBUG: Looking for product ID:', productId);
        console.log('🔧 REFRESH DEBUG: Found original product:', originalProduct);
        console.log('🔧 REFRESH DEBUG: Available product IDs:', window.aebgProducts ? window.aebgProducts.map(p => p.id) : 'undefined');

        Object.keys(comparisonData.merchants).forEach(merchantName => {
            const merchant = comparisonData.merchants[merchantName];
            let updatedNetwork = merchant.network;

            // If network is 'Unknown' and we have original network data, update it
            if (merchant.network === 'Unknown' && originalNetwork) {
                updatedNetwork = originalNetwork;
                hasChanges = true;
                console.log('🔧 REFRESH DEBUG: Updated network for', merchantName, 'from "Unknown" to', originalNetwork);
            }

            updatedMerchants[merchantName] = {
                ...merchant,
                network: updatedNetwork
            };
        });

        if (hasChanges) {
            console.log('🔧 REFRESH DEBUG: Network data refreshed, saving updated comparison data');
            // Save the updated data back to database
            saveComparisonDataToDatabase(productId, {
                ...comparisonData,
                merchants: updatedMerchants
            });
        }

        return {
            ...comparisonData,
            merchants: updatedMerchants
        };
    }

    function loadComparisonFromDatabase(productId, callback) {
        console.log('🔄 FRONTEND: Loading comparison data from database for product:', productId);
        console.log('🔍 FRONTEND: Product ID type:', typeof productId, 'Value:', productId);
        
        const postId = getCurrentPostId();
        console.log('📄 FRONTEND: Current post ID:', postId);
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_load_comparison',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                post_id: postId
            },
            success: function(response) {
                console.log('=== LOAD COMPARISON FROM DATABASE DEBUG ===');
                console.log('Response:', response);
                console.log('🔍 FIRST PRODUCT DEBUG: Checking response for product:', productId);
                
                if (response.success && response.data) {
                    // Load comparison data from database
                    console.log('🔍 AJAX RESPONSE: Full response.data structure:', response.data);
                    console.log('🔍 AJAX RESPONSE: response.data keys:', Object.keys(response.data));
                    const comparisonData = response.data.comparison_data;
                    console.log('🔍 AJAX RESPONSE: Raw comparison data from database:', comparisonData);
                    console.log('🔍 AJAX RESPONSE: comparisonData type:', typeof comparisonData);
                    console.log('🔍 AJAX RESPONSE: comparisonData is array:', Array.isArray(comparisonData));
                    console.log('🔍 AJAX RESPONSE: Has comparison_data:', !!comparisonData);
                    console.log('🔍 AJAX RESPONSE: Has merchants:', !!(comparisonData && comparisonData.merchants));
                    console.log('🔍 AJAX RESPONSE: Merchants type:', comparisonData && comparisonData.merchants ? typeof comparisonData.merchants : 'N/A');
                    console.log('🔍 AJAX RESPONSE: Merchants is array:', comparisonData && comparisonData.merchants ? Array.isArray(comparisonData.merchants) : 'N/A');
                    console.log('🔍 AJAX RESPONSE: Merchants count:', comparisonData && comparisonData.merchants ? Object.keys(comparisonData.merchants).length : 'N/A');
                    if (comparisonData && comparisonData.merchants) {
                        console.log('🔍 AJAX RESPONSE: Merchant keys:', Object.keys(comparisonData.merchants));
                        console.log('🔍 AJAX RESPONSE: First merchant sample:', Object.keys(comparisonData.merchants).length > 0 ? comparisonData.merchants[Object.keys(comparisonData.merchants)[0]] : 'N/A');
                    }
                    
                    if (comparisonData && comparisonData.merchants && Object.keys(comparisonData.merchants).length > 0) {
                        console.log('✅ Loaded merchant comparison data from database:', Object.keys(comparisonData.merchants).length, 'merchants');
                        console.log('Merchant data:', comparisonData.merchants);
                        
                        // Debug network data from database
                        console.log('🔍 DATABASE DEBUG: Network data from database:');
                        Object.keys(comparisonData.merchants).forEach(merchantName => {
                            const merchant = comparisonData.merchants[merchantName];
                            console.log(`  - Merchant: ${merchantName}`);
                            console.log(`    - Keys:`, Object.keys(merchant));
                            console.log(`    - Network fields:`, {
                                network: merchant.network,
                                network_name: merchant.network_name,
                                source_name: merchant.source_name,
                                source: merchant.source,
                                network_code: merchant.network_code
                            });
                        });
                        
                        // Special debugging for first product
                        if (window.aebgProducts && window.aebgProducts.length > 0 && productId === window.aebgProducts[0].id) {
                            console.log('🔍 FIRST PRODUCT DEBUG: This is the first product - analyzing data structure');
                            console.log('🔍 FIRST PRODUCT DEBUG: First merchant object:', Object.values(comparisonData.merchants)[0]);
                            console.log('🔍 FIRST PRODUCT DEBUG: First merchant keys:', Object.keys(Object.values(comparisonData.merchants)[0]));
                            console.log('🔍 FIRST PRODUCT DEBUG: First merchant price field:', Object.values(comparisonData.merchants)[0]?.price);
                            console.log('🔍 FIRST PRODUCT DEBUG: First merchant availability field:', Object.values(comparisonData.merchants)[0]?.availability);
                            console.log('🔍 FIRST PRODUCT DEBUG: First merchant lowest_price field:', Object.values(comparisonData.merchants)[0]?.lowest_price);
                        }
                        
                        // Verify this data is for the specific product being viewed
                        const currentProductId = $('#aebg-merchant-comparison-modal').data('product-id');
                        console.log('🔍 FIRST PRODUCT DEBUG: Current modal product ID:', currentProductId);
                        console.log('🔍 FIRST PRODUCT DEBUG: Requested product ID:', productId);
                        console.log('🔍 FIRST PRODUCT DEBUG: IDs match:', currentProductId === productId);
                        
                        if (currentProductId === productId) {
                            console.log('🔧 MAIN DEBUG: About to refresh network data for product:', productId);
                            
                            // Clear the comparison table before loading new data
                            $('#comparison-table-body').empty();
                            $('#comparison-count').text('(0 merchants)');
                            
                            // Refresh network data if it's 'Unknown'
                            const refreshedData = refreshNetworkDataInComparison(productId, comparisonData);
                            
                            console.log('🔧 MAIN DEBUG: Network refresh completed, refreshed data:', refreshedData);
                            
                            // Always display the merchant data from database to ensure correct data
                            console.log('🔍 FIRST PRODUCT DEBUG: Displaying comparison data for product:', productId);
                            displayMerchantComparisonTable(refreshedData);
                            
                            // Call callback with true to indicate existing data was found
                            if (typeof callback === 'function') {
                                callback(true);
                            }
                        } else {
                            console.log('❌ Product ID mismatch - modal is for different product, not loading data');
                            if (typeof callback === 'function') {
                                callback(false);
                            }
                        }
                    } else {
                        console.log('❌ No existing merchant comparison data found for product:', productId);
                        console.log('comparisonData structure:', comparisonData);
                        
                        // Clear the comparison table when no data is found
                        $('#comparison-table-body').empty();
                        $('#comparison-count').text('(0 merchants)');
                        
                        // Call callback with false to indicate no existing data was found
                        if (typeof callback === 'function') {
                            callback(false);
                        }
                    }
                } else {
                    console.log('❌ No existing comparison data found for product:', productId);
                    console.log('Response data:', response.data);
                    
                    // Call callback with false to indicate no existing data was found
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                }
                console.log('=== END LOAD COMPARISON FROM DATABASE DEBUG ===');
            },
            error: function(xhr, status, error) {
                console.error('Failed to load comparison data:', error);
                
                // Clear the comparison table on error
                $('#comparison-table-body').empty();
                $('#comparison-count').text('(0 merchants)');
                
                // Force refresh merchant count from database to ensure accuracy
                forceRefreshMerchantCount(productId);
                
                // Call callback with false to indicate no existing data was found
                if (typeof callback === 'function') {
                    callback(false);
                }
            }
        });
    }

    function autoSaveComparison() {
        // This function is deprecated - we now use database-first approach
        // Merchant data is saved directly when loaded from API
        console.log('autoSaveComparison called but deprecated - using new database-first system');
        return;
    }

    function loadMerchantComparisonData(productId, productData) {
        const limit = $('#merchant-limit').val();
        
        console.log('🌐 FRONTEND: Calling Datafeedr API for merchant comparison - Product:', productId, 'Limit:', limit);
        console.log('📊 Product data being sent to API:', productData);
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_merchant_comparison',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                product_data: productData,
                limit: limit
            },
            success: function(response) {
                console.log('✅ FRONTEND: API response received for product:', productId);
                console.log('📋 API response data:', response.data);
                
                if (response.success) {
                    console.log('🎯 FRONTEND: Displaying merchant comparison table with', Object.keys(response.data.merchants || {}).length, 'merchants');
                    displayMerchantComparisonTable(response.data);
                } else {
                    console.error('❌ FRONTEND: API call failed for product:', productId, 'Error:', response.data);
                    showError('Failed to load merchant data: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ FRONTEND: Network error for product:', productId, 'Error:', error);
                showError('Network error: ' + error);
            }
        });
    }

    function loadMerchantComparisonDataAndSave(productId, productData) {
        const limit = $('#merchant-limit').val();
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_get_merchant_comparison',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                product_data: productData,
                limit: limit
            },
            success: function(response) {
                console.log('🔍 API RESPONSE DEBUG: Raw API response:', response);
                console.log('🔍 API RESPONSE DEBUG: Response data structure:', response.data);
                
                if (response.success) {
                    // Debug the merchant data structure before processing
                    if (response.data && response.data.merchants) {
                        console.log('🔍 API RESPONSE DEBUG: Merchants data structure:');
                        Object.keys(response.data.merchants).forEach(merchantName => {
                            const merchant = response.data.merchants[merchantName];
                            console.log(`  - Merchant: ${merchantName}`);
                            console.log(`    - Keys:`, Object.keys(merchant));
                            console.log(`    - Network fields:`, {
                                network: merchant.network,
                                network_name: merchant.network_name,
                                source_name: merchant.source_name,
                                source: merchant.source,
                                network_code: merchant.network_code
                            });
                        });
                    }
                    
                    // Display the merchant data
                    displayMerchantComparisonTable(response.data);
                    
                    // Save the comparison data to our database
                    saveComparisonDataToDatabase(productId, response.data);
                } else {
                    showError('Failed to load merchant data: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showError('Network error: ' + error);
            }
        });
    }

    function saveComparisonDataToDatabase(productId, merchantData) {
        const postId = getCurrentPostId();
        
        console.log('💾 FRONTEND: Saving comparison data to database for product:', productId, 'Post ID:', postId);
        console.log('📊 Raw merchant data from API:', merchantData);
        console.log('=== SAVE COMPARISON DATA DEBUG ===');
        console.log('Saving merchant data for product:', productId);
        console.log('Raw merchant data:', merchantData);
        
        // Convert merchant data to the correct format for our database
        // We need to extract just the merchant information, not the full API response
        const merchants = {};
        
        if (merchantData.merchants && typeof merchantData.merchants === 'object') {
            Object.keys(merchantData.merchants).forEach(merchantName => {
                const merchant = merchantData.merchants[merchantName];
                
                // Debug network data extraction
                console.log('🔍 SAVE DEBUG: Extracting network data for merchant:', merchantName);
                console.log('  - merchant.network:', merchant.network);
                console.log('  - merchant.network_name:', merchant.network_name);
                console.log('  - merchant.source_name:', merchant.source_name);
                console.log('  - merchant.source:', merchant.source);
                console.log('  - merchant.network_code:', merchant.network_code);
                console.log('  - Full merchant object keys:', Object.keys(merchant));
                console.log('  - Full merchant object:', merchant);
                
                // Extract the essential merchant data
                // Based on Datafeedr API structure, network info is in different fields
                let networkData = merchant.network_name || merchant.network || merchant.source_name || merchant.source || merchant.network_code || 'Unknown';
            
            // CRITICAL FIX: Map internal network codes to proper display names (same as display logic)
            if (networkData === 'partnerads' || networkData === 'partner_ads' || networkData === 'api_15') {
                networkData = 'Partner Ads Denmark';
            } else if (networkData === 'awin') {
                networkData = 'Awin Denmark';
            } else if (networkData === 'timeone') {
                networkData = 'TimeOne';
            } else if (networkData === 'adrecord') {
                networkData = 'Adrecord';
            } else if (networkData === 'addrevenue') {
                networkData = 'AddRevenue';
            } else if (networkData === 'zanox') {
                networkData = 'Zanox';
            } else if (networkData === 'partnerize') {
                networkData = 'Partnerize';
            } else if (networkData === 'performance_horizon') {
                networkData = 'Performance Horizon';
            }

                // If network is still 'Unknown', try to get it from the original product data
                if (networkData === 'Unknown' && window.aebgProducts && productId && window.aebgProducts[productId]) {
                    const originalProduct = window.aebgProducts[productId];
                    if (originalProduct && originalProduct.network) {
                        networkData = originalProduct.network;
                        console.log('🔍 SAVE DEBUG: Got network from original product data:', networkData);
                    }
                }

                // Additional fallback: check if this is a merchant object with network info
                if (networkData === 'Unknown' && merchant.merchant && typeof merchant.merchant === 'object' && merchant.merchant.network) {
                    networkData = merchant.merchant.network;
                    console.log('🔍 SAVE DEBUG: Got network from merchant.network:', networkData);
                }
                
                // CRITICAL FIX: Handle search result products properly
                if (merchant.is_search_result && merchant.original_product) {
                    // This is a search result - preserve all the original product data
                    merchants[merchantName] = {
                        name: merchant.name || merchantName,
                        id: merchant.id || merchant.original_product.id,
                        price: merchant.price || merchant.lowest_price || 0,
                        lowest_price: merchant.price || merchant.lowest_price || 0,
                        url: merchant.url || merchant.original_product.url || '',
                        rating: merchant.rating || merchant.average_rating || 0,
                        availability: merchant.availability || 'unknown',
                        network: networkData,
                        is_search_result: true,
                        original_product: merchant.original_product
                    };
                } else {
                    // This is a regular merchant
                merchants[merchantName] = {
                    name: merchant.name || merchantName,
                    price: merchant.lowest_price || merchant.price || 0,
                    url: merchant.url || merchant.direct_url || '',
                    rating: merchant.average_rating || merchant.rating || 0,
                    availability: merchant.availability || 'unknown',
                        network: networkData
                    };
                }
                
                console.log('🔍 SAVE DEBUG: Network extraction for', merchantName, ':');
                console.log('  - merchant.network_name:', merchant.network_name);
                console.log('  - merchant.network:', merchant.network);
                console.log('  - merchant.source_name:', merchant.source_name);
                console.log('  - merchant.source:', merchant.source);
                console.log('  - merchant.network_code:', merchant.network_code);
                console.log('  - Final network value:', networkData);
                
                console.log('🔍 SAVE DEBUG: Final network value for', merchantName, ':', merchants[merchantName].network);
                
                // Additional debugging: show all available fields that might contain network info
                const allFields = Object.keys(merchant);
                const networkRelatedFields = allFields.filter(field => 
                    field.toLowerCase().includes('network') || 
                    field.toLowerCase().includes('source') || 
                    field.toLowerCase().includes('affiliate')
                );
                console.log('🔍 SAVE DEBUG: All fields:', allFields);
                console.log('🔍 SAVE DEBUG: Network-related fields:', networkRelatedFields);
                if (networkRelatedFields.length > 0) {
                    networkRelatedFields.forEach(field => {
                        console.log(`  - ${field}:`, merchant[field]);
                    });
                }
            });
        }
        
        const comparisonData = {
            merchants: merchants,
            original_product: merchantData.original_product || {},
            merchant_count: Object.keys(merchants).length,
            price_range: merchantData.price_range || {},
            search_results: merchantData.search_results || [],
            timestamp: new Date().toISOString()
        };
        
        console.log('Processed comparison data:', comparisonData);
        console.log('=== END SAVE COMPARISON DATA DEBUG ===');
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_save_comparison',
                nonce: aebg_ajax.nonce,
                post_id: postId,
                product_id: productId,
                comparison_name: 'Product Comparison',
                comparison_data: comparisonData
            },
            success: function(response) {
                if (response.success) {
                    console.log('✅ FRONTEND: Comparison data saved to database successfully for product:', productId);
                    // Note: Main table is already updated by the calling function (individual remove or remove all)
                    // No need to force refresh as it can cause conflicts with the correct data
                } else {
                    console.error('❌ FRONTEND: Failed to save comparison data for product:', productId, 'Error:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ FRONTEND: Network error saving comparison data for product:', productId, 'Error:', error);
            }
        });
    }

    function selectMerchantFromModal() {
        const selectedMerchant = $('input[name="selected_merchant"]:checked').val();
        const productId = $('#aebg-merchant-comparison-modal').data('product-id');
        
        if (!selectedMerchant) {
            showError('Please select a merchant');
            return;
        }
        
        // Update the product with selected merchant
        updateProductWithSelectedMerchant(productId, selectedMerchant);
        
        // Close modal
        hideMerchantComparisonModal();
    }

    function updateProductWithSelectedMerchant(productId, merchantName) {
        // Find the product row
        const $row = $(`.aebg-product-row[data-product-id="${productId}"]`);
        
        if ($row.length === 0) {
            showError('Product not found');
            return;
        }
        
        // Update merchant display
        $row.find('.aebg-merchant').text(merchantName);
        
        // Update product data in database
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_update_product_merchant',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                merchant_name: merchantName
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Merchant updated successfully', 'success');
                    // Refresh merchant info
                    loadMerchantCounts();
                } else {
                    showError('Failed to update merchant: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showError('Network error: ' + error);
            }
        });
    }

    function hideMerchantComparisonModal() {
        // Update merchant count before closing
        updateMerchantCountFromComparison();
        
        $('#aebg-merchant-comparison-modal').removeClass('show');
        
        // Clear modal data
        $('#aebg-merchant-comparison-modal').removeData('product-id');
    }

    function getProductDataFromRow($row) {
        console.log('Extracting product data from row:', $row);
        
        const productData = {
            id: $row.data('product-id'),
            name: $row.find('.aebg-product-name strong').text().trim(),
            display_name: $row.find('.aebg-product-name strong').text().trim(), // Use the displayed name
            brand: $row.find('.aebg-brand').text().trim(),
            price: $row.find('.aebg-price').text().trim(),
            merchant: $row.find('.aebg-merchant').text().trim(),
            image_url: $row.find('.aebg-td-image img').attr('src') || ''
        };
        
        console.log('Extracted product data:', productData);
        return productData;
    }

    // Product Name Inline Editing functionality
    function initProductNameEditing() {
        console.log('Initializing Product Name Editing...');
        
        // Handle click on product name text (only for associated products)
        $(document).on('click', '.aebg-associated-products-table .aebg-product-name-text', function(e) {
            e.preventDefault();
            const $this = $(this);
            const $container = $this.closest('.aebg-product-name');
            
            if (!$container.hasClass('editing')) {
                startEditing($this);
            }
        });
        
        // Handle edit button click (only for associated products)
        $(document).on('click', '.aebg-associated-products-table .aebg-edit-name-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $this = $(this);
            const $nameText = $this.siblings('.aebg-product-name-text');
            startEditing($nameText);
        });
        
        // Handle save button click (only for associated products)
        $(document).on('click', '.aebg-associated-products-table .aebg-save-name-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $this = $(this);
            const $nameText = $this.siblings('.aebg-product-name-text');
            saveEditing($nameText);
        });
        
        // Handle cancel button click (only for associated products)
        $(document).on('click', '.aebg-associated-products-table .aebg-cancel-name-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $this = $(this);
            const $nameText = $this.siblings('.aebg-product-name-text');
            cancelEditing($nameText);
        });
        
        // Handle Enter key to save (only for associated products)
        $(document).on('keydown', '.aebg-associated-products-table .aebg-product-name-text', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveEditing($(this));
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancelEditing($(this));
            }
        });
        
        // Handle blur to save if content changed (only for associated products)
        $(document).on('blur', '.aebg-associated-products-table .aebg-product-name-text', function(e) {
            const $this = $(this);
            const $container = $this.closest('.aebg-product-name');
            
            if ($container.hasClass('editing')) {
                // Small delay to allow button clicks to register
                setTimeout(() => {
                    if ($container.hasClass('editing')) {
                        saveEditing($this);
                    }
                }, 100);
            }
        });
        
        console.log('Product Name Editing initialized');
    }
    
    function startEditing($nameText) {
        const $container = $nameText.closest('.aebg-product-name');
        const originalText = $nameText.text().trim();
        
        // Store original text
        $nameText.data('original-text', originalText);
        
        // Enable editing
        $nameText.attr('contenteditable', 'true').focus();
        $container.addClass('editing');
        
        // Select all text
        const range = document.createRange();
        range.selectNodeContents($nameText[0]);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }
    
    function saveEditing($nameText) {
        const $container = $nameText.closest('.aebg-product-name');
        const newText = $nameText.text().trim();
        const originalText = $nameText.data('original-text');
        const productId = $nameText.data('product-id');
        
        if (newText === originalText) {
            // No changes, just cancel
            cancelEditing($nameText);
            return;
        }
        
        if (newText === '') {
            // Empty text, revert to original
            $nameText.text(originalText);
            cancelEditing($nameText);
            return;
        }
        
        // Save the new name
        updateProductName(productId, newText, $nameText);
    }
    
    function cancelEditing($nameText) {
        const $container = $nameText.closest('.aebg-product-name');
        const originalText = $nameText.data('original-text');
        
        // Restore original text
        $nameText.text(originalText);
        
        // Disable editing
        $nameText.attr('contenteditable', 'false');
        $container.removeClass('editing');
    }
    
    function updateProductName(productId, newName, $nameText) {
        const $container = $nameText.closest('.aebg-product-name');
        const originalName = $nameText.data('original-text');
        
        // Get the current post ID
        const postId = $('#post_ID').val() || $('input[name="post_ID"]').val() || '';
        
        // Show loading state
        $nameText.attr('contenteditable', 'false');
        $container.removeClass('editing');
        $nameText.addClass('updating');
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_update_product_name',
                nonce: aebg_ajax.nonce,
                product_id: productId,
                new_name: newName,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    // Update the display
                    $nameText.text(newName);
                    $nameText.data('original-text', newName);
                    
                    // Update tooltip
                    if (newName !== originalName) {
                        $nameText.attr('title', 'Original name: ' + originalName + ' - Click to edit');
                        
                        // Add or update original name indicator
                        let $indicator = $container.find('.aebg-original-name-indicator');
                        if ($indicator.length === 0) {
                            $indicator = $('<span class="aebg-original-name-indicator" title="Renamed from: ' + originalName + '">✏️</span>');
                            $nameText.after($indicator);
                        } else {
                            $indicator.attr('title', 'Renamed from: ' + originalName);
                        }
                    } else {
                        // Remove indicator if name is back to original
                        $nameText.attr('title', 'Click to edit product name');
                        $container.find('.aebg-original-name-indicator').remove();
                    }
                    
                    // Update the product data in the row
                    const $row = $nameText.closest('tr');
                    updateProductDataInRow($row, { name: newName, display_name: newName });
                    
                    showSuccess('Product name updated successfully');
                    
                    // Refresh merchant counts with new name
                    loadMerchantCounts();
                } else {
                    // Revert on error
                    const originalText = $nameText.data('original-text');
                    $nameText.text(originalText);
                    showError('Failed to update product name: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                // Revert on error
                const originalText = $nameText.data('original-text');
                $nameText.text(originalText);
                showError('Network error: ' + error);
            },
            complete: function() {
                $nameText.removeClass('updating');
            }
        });
    }
    
    function updateProductDataInRow($row, updatedData) {
        // Update the product data stored in the row
        const currentData = $row.data('product-data') || {};
        const newData = { ...currentData, ...updatedData };
        $row.data('product-data', newData);
    }

    // Table sorting functionality
    let currentSortColumn = null;
    let currentSortDirection = 'asc';
    let lastSortState = { column: null, direction: 'asc' };
    
    function initTableSorting() {
        // Remove existing click handlers
        $(document).off('click', '.aebg-modal-search-table th.sortable');
        
        // Add click handlers for sortable columns
        $(document).on('click', '.aebg-modal-search-table th.sortable', function() {
            const sortColumn = $(this).data('sort');
            const $table = $(this).closest('.aebg-modal-search-table');
            const $tbody = $table.find('tbody');
            const $rows = $tbody.find('tr');
            
            // Determine sort direction
            let sortDirection = 'asc';
            if (currentSortColumn === sortColumn && currentSortDirection === 'asc') {
                sortDirection = 'desc';
            }
            
            // Update sort indicators and classes
            $table.find('th.sortable').removeClass('sorted-asc sorted-desc');
            $table.find('th.sortable .sort-icon').text('↕');
            $(this).addClass(sortDirection === 'asc' ? 'sorted-asc' : 'sorted-desc');
            $(this).find('.sort-icon').text(sortDirection === 'asc' ? '↑' : '↓');
            
            // Add sorting animation
            $rows.addClass('sorting');
            
            // Sort the rows
            const sortedRows = sortTableRows($rows, sortColumn, sortDirection);
            
            // Re-append sorted rows with animation
            setTimeout(() => {
                $tbody.empty().append(sortedRows);
                $rows.removeClass('sorting');
                
                // Update current sort state
                currentSortColumn = sortColumn;
                currentSortDirection = sortDirection;
                lastSortState = { column: sortColumn, direction: sortDirection };
                
                console.log(`Sorted by ${sortColumn} in ${sortDirection} order`);
            }, 150);
        });
        
        // Apply last sort state if available
        if (lastSortState.column) {
            applySortState(lastSortState.column, lastSortState.direction);
        }
    }
    
    function applySortState(sortColumn, sortDirection) {
        const $table = $('.aebg-modal-search-table');
        const $tbody = $table.find('tbody');
        const $rows = $tbody.find('tr');
        
        if ($rows.length === 0) return;
        
        // Update sort indicators and classes
        $table.find('th.sortable').removeClass('sorted-asc sorted-desc');
        $table.find('th.sortable .sort-icon').text('↕');
        $table.find(`th.sortable[data-sort="${sortColumn}"]`).addClass(sortDirection === 'asc' ? 'sorted-asc' : 'sorted-desc');
        $table.find(`th.sortable[data-sort="${sortColumn}"] .sort-icon`).text(sortDirection === 'asc' ? '↑' : '↓');
        
        // Sort the rows
        const sortedRows = sortTableRows($rows, sortColumn, sortDirection);
        
        // Re-append sorted rows
        $tbody.empty().append(sortedRows);
        
        // Update current sort state
        currentSortColumn = sortColumn;
        currentSortDirection = sortDirection;
        
        console.log(`Applied saved sort: ${sortColumn} in ${sortDirection} order`);
    }
    
    function sortTableRows($rows, sortColumn, sortDirection) {
        const rowsArray = $rows.toArray();
        
        rowsArray.sort(function(a, b) {
            const $a = $(a);
            const $b = $(b);
            
            let valueA, valueB;
            
            switch (sortColumn) {
                case 'name':
                    valueA = $a.find('.aebg-product-name-text').text().toLowerCase().trim();
                    valueB = $b.find('.aebg-product-name-text').text().toLowerCase().trim();
                    break;
                    
                case 'price':
                    valueA = parseFloat($a.data('price')) || 0;
                    valueB = parseFloat($b.data('price')) || 0;
                    break;
                    
                case 'brand':
                    valueA = ($a.data('brand') || '').toLowerCase().trim();
                    valueB = ($b.data('brand') || '').toLowerCase().trim();
                    break;
                    
                case 'merchant':
                    valueA = ($a.data('merchant') || '').toLowerCase().trim();
                    valueB = ($b.data('merchant') || '').toLowerCase().trim();
                    break;
                    
                case 'rating':
                    valueA = parseFloat($a.data('rating')) || 0;
                    valueB = parseFloat($b.data('rating')) || 0;
                    break;
                    
                case 'ean':
                    valueA = ($a.data('ean') || '').toLowerCase().trim();
                    valueB = ($b.data('ean') || '').toLowerCase().trim();
                    break;
                    
                case 'image':
                    // Sort by whether image exists or not
                    valueA = $a.find('img').length > 0 ? 1 : 0;
                    valueB = $b.find('img').length > 0 ? 1 : 0;
                    break;
                    
                default:
                    valueA = '';
                    valueB = '';
            }
            
            // Handle numeric values
            if (typeof valueA === 'number' && typeof valueB === 'number') {
                if (sortDirection === 'asc') {
                    return valueA - valueB;
                } else {
                    return valueB - valueA;
                }
            }
            
            // Handle string values
            if (typeof valueA === 'string' && typeof valueB === 'string') {
                // Handle empty strings
                if (valueA === '' && valueB !== '') return sortDirection === 'asc' ? -1 : 1;
                if (valueA !== '' && valueB === '') return sortDirection === 'asc' ? 1 : -1;
                if (valueA === '' && valueB === '') return 0;
                
                if (sortDirection === 'asc') {
                    return valueA.localeCompare(valueB);
                } else {
                    return valueB.localeCompare(valueA);
                }
            }
            
            return 0;
        });
        
        return rowsArray;
    }
    
    /**
     * Initialize drag and drop functionality for the comparison table
     */
    function initComparisonTableDragAndDrop() {
        console.log('Initializing comparison table drag and drop functionality...');
        
        const $tbody = $('#comparison-table-body');
        
        if ($tbody.length === 0) {
            console.log('Comparison table body not found');
            return;
        }
        
        // Check if jQuery UI sortable is available
        if (typeof $.fn.sortable === 'undefined') {
            console.error('jQuery UI sortable is not loaded!');
            return;
        }
        
        console.log('jQuery UI sortable is available for comparison table');
        
        // Destroy existing sortable if it exists
        if ($tbody.hasClass('ui-sortable')) {
            console.log('Destroying existing sortable');
            $tbody.sortable('destroy');
        }
        
        // Check for drag handles
        const dragHandles = $tbody.find('.aebg-drag-handle');
        console.log('Found drag handles:', dragHandles.length);
        
        if (dragHandles.length === 0) {
            console.warn('No drag handles found in comparison table');
            return;
        }
        
        // Detect if we're on a touch device and mobile screen
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        const isMobile = window.innerWidth <= 768;
        
        // Configure sortable options based on device type
        const sortableOptions = {
            axis: 'y',
            opacity: 0.8,
            placeholder: 'drag-placeholder',
            scroll: true,
            scrollSensitivity: 100,
            scrollSpeed: 20,
            start: function(event, ui) {
                ui.item.addClass('dragging');
                ui.placeholder.height(ui.item.height());
                $('body').addClass('aebg-dragging');
                
                if (isTouchDevice || isMobile) {
                    ui.item.css({
                        'z-index': 10000,
                        'transform': 'rotate(2deg) scale(1.02)'
                    });
                }
                
                showMessage('Drag to reorder products...', 'info');
                console.log('Started dragging comparison row:', ui.item.data('product-id'));
            },
            stop: function(event, ui) {
                ui.item.removeClass('dragging');
                ui.item.css({
                    'z-index': '',
                    'transform': ''
                });
                $('body').removeClass('aebg-dragging');
                
                // Get the new order
                const newOrder = [];
                $tbody.find('tr.aebg-comparison-row').each(function(index) {
                    const productId = $(this).data('product-id');
                    if (productId) {
                        newOrder.push(productId);
                    }
                });
                
                // Reorder the comparisonProducts array based on the new order
                const reorderedProducts = [];
                newOrder.forEach(function(productId) {
                    const product = comparisonProducts.find(p => p.id == productId);
                    if (product) {
                        reorderedProducts.push(product);
                    }
                });
                
                // Update the comparisonProducts array
                if (reorderedProducts.length > 0) {
                    comparisonProducts = reorderedProducts;
                    
                    console.log('Reordered comparison products:', {
                        newOrder: newOrder,
                        total: comparisonProducts.length
                    });
                    
                    showSuccess('Product order updated');
                }
                
                console.log('Finished dragging comparison row');
            },
            over: function(event, ui) {
                $(this).addClass('drag-over');
            },
            out: function(event, ui) {
                $(this).removeClass('drag-over');
            },
            change: function(event, ui) {
                const $placeholder = ui.placeholder;
                $placeholder.addClass('drag-placeholder');
                $placeholder.css({
                    'background': '#f0f4ff',
                    'border': '2px dashed #4f46e5',
                    'border-radius': '12px',
                    'min-height': ui.item.height() + 'px'
                });
            }
        };
        
        // Mobile-specific configuration
        if (isMobile) {
            sortableOptions.handle = 'tr'; // Entire row on mobile
            sortableOptions.tolerance = 'touch';
            sortableOptions.distance = 15;
            sortableOptions.delay = 150;
            sortableOptions.cancel = '.aebg-btn-view-product, .aebg-btn-remove-from-comparison, input, button, a';
            sortableOptions.helper = function(e, item) {
                const $helper = item.clone();
                $helper.css({
                    'width': item.width(),
                    'opacity': 0.95,
                    'box-shadow': '0 8px 24px rgba(0, 0, 0, 0.3)',
                    'transform': 'rotate(2deg) scale(1.02)',
                    'background': '#fff',
                    'border': '2px solid #4f46e5',
                    'border-radius': '12px'
                });
                return $helper;
            };
        } else {
            sortableOptions.handle = '.aebg-drag-handle';
            sortableOptions.tolerance = 'pointer';
            sortableOptions.distance = 5;
            sortableOptions.delay = 0;
            sortableOptions.helper = function(e, item) {
                return item;
            };
        }
        
        // Make table rows sortable using jQuery UI
        $tbody.sortable(sortableOptions);
        
        console.log('Drag and drop initialized for comparison table with', $tbody.find('tr.aebg-comparison-row').length, 'rows');
    }

    /**
     * Debug function to check merchant count calculation
     */
    function debugMerchantCount() {
        console.log('=== DEBUG MERCHANT COUNT ===');
        console.log('comparisonProducts array:', comparisonProducts);
        console.log('Number of products in comparison:', comparisonProducts.length);
        
        const uniqueMerchants = new Set();
        const merchantPrices = {};
        
        comparisonProducts.forEach(function(product, index) {
            // Handle different data structures - some products might have merchant, others might have store
            let merchant = product.merchant || product.store || product.name || 'Unknown';
            const price = parseFloat(product.price) || 0;
            
            console.log(`Product ${index + 1}:`, {
                name: product.name,
                merchant: merchant,
                price: price,
                productData: product
            });
            
            // Clean up merchant name - remove any extra whitespace and ensure it's a string
            merchant = String(merchant).trim();
            
            if (merchant && merchant !== 'Unknown' && merchant !== '') {
                uniqueMerchants.add(merchant);
                
                // Track the lowest price for each merchant
                if (!merchantPrices[merchant] || price < merchantPrices[merchant]) {
                    merchantPrices[merchant] = price;
                }
            }
        });
        
        const merchantCount = uniqueMerchants.size;
        
        // Debug merchant count calculation completed
        
        return merchantCount;
    }

    /**
     * Debug function to clear incorrect comparison data
     */
    function clearIncorrectComparisonData() {
        // Clearing incorrect comparison data
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_clear_incorrect_comparisons',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showSuccess('Incorrect comparison data cleared successfully');
                } else {
                    console.error('Failed to clear comparison data:', response.data);
                    showError('Failed to clear comparison data: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error clearing comparison data:', error);
                showError('Error clearing comparison data: ' + error);
            }
        });
    }

    /**
     * Debug function to manually add a merchant to comparison
     */
    function addMerchantToComparison(merchantName, price, network = 'Unknown') {
        // Adding merchant to comparison
        
        const $modal = $('#aebg-merchant-comparison-modal');
        
        // Add CSS styling for search results
        if (!$('#aebg-search-results-styles').length) {
            const styles = `
                <style id="aebg-search-results-styles">
                    .aebg-search-results {
                        margin-top: 20px;
                        padding: 20px;
                        background: #fff;
                        border: 1px solid #ddd;
                        border-radius: 8px;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    
                    .aebg-search-results.show {
                        display: block;
                    }
                    
                    .aebg-loading {
                        text-align: center;
                        padding: 40px;
                        color: #666;
                        font-style: italic;
                    }
                    
                    .aebg-error {
                        color: #dc3545;
                        text-align: center;
                        padding: 20px;
                    }
                    
                    .aebg-no-results {
                        text-align: center;
                        padding: 40px;
                        color: #666;
                        font-style: italic;
                    }
                    
                    .aebg-results-header h4 {
                        margin: 0 0 20px 0;
                        color: #333;
                        border-bottom: 2px solid #0073aa;
                        padding-bottom: 10px;
                    }
                    
                    .aebg-results-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                        gap: 20px;
                    }
                    
                    .aebg-result-item {
                        border: 1px solid #e1e5e9;
                        border-radius: 8px;
                        padding: 15px;
                        background: #f8f9fa;
                        transition: all 0.3s ease;
                    }
                    
                    .aebg-result-item:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    }
                    
                    .aebg-result-image {
                        text-align: center;
                        margin-bottom: 15px;
                    }
                    
                    .aebg-result-image img {
                        max-width: 100%;
                        height: auto;
                        border-radius: 4px;
                    }
                    
                    .aebg-no-image {
                        background: #e9ecef;
                        color: #6c757d;
                        padding: 40px 20px;
                        text-align: center;
                        border-radius: 4px;
                        font-style: italic;
                    }
                    
                    .aebg-result-info h5 {
                        margin: 0 0 10px 0;
                        color: #333;
                        font-size: 16px;
                        line-height: 1.4;
                    }
                    
                    .aebg-result-price {
                        font-weight: bold;
                        color: #28a745;
                        margin: 0 0 8px 0;
                        font-size: 18px;
                    }
                    
                    .aebg-result-merchant {
                        color: #666;
                        margin: 0 0 15px 0;
                        font-size: 14px;
                    }
                    
                    .aebg-btn-add-product {
                        width: 100%;
                        padding: 10px;
                        background: #0073aa;
                        color: white;
                        border: none;
                        border-radius: 4px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background 0.3s ease;
                    }
                    
                    .aebg-btn-add-product:hover {
                        background: #005a87;
                    }
                    
                    .aebg-btn-add-product.added {
                        background: #28a745;
                    }
                    
                    .aebg-btn-add-product:disabled {
                        background: #6c757d;
                        cursor: not-allowed;
                    }
                </style>
            `;
            $('head').append(styles);
        }
        const productId = $modal.data('product-id');
        const currentData = $modal.data('comparison-data') || { merchants: {}, original_product: {} };
        
        // Add the merchant to the comparison data
        currentData.merchants[merchantName] = {
            name: merchantName,
            price: parseFloat(price) || 0,
            network: network,
            rating: 0,
            availability: 'in_stock'
        };
        
        // Update the modal data
        $modal.data('comparison-data', currentData);
        
        // Refresh the display
        displayMerchantComparisonTable(currentData);
        
        // Save to database
        saveComparisonDataToDatabase(productId, currentData);
        
        showSuccess('Merchant added to comparison');
    }

    /**
     * Debug function to force refresh modal data
     */
    function forceRefreshModalData() {
        // Force refreshing modal data
        
        const $modal = $('#aebg-merchant-comparison-modal');
        const productId = $modal.data('product-id');
        
        if (productId) {
            // Force refreshing data for product
            
            // Clear any cached data
            $modal.removeData('comparison-data');
            
            // Reload the modal
            showMerchantComparisonModal(productId, null);
        } else {
            console.error('No product ID found in modal');
        }
    }
}); 