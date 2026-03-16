/**
 * Clone Content Page JavaScript
 * Handles competitor scanning, product approval, and post generation
 */

jQuery(document).ready(function($) {
    'use strict';

    // Initialize page
    initCloneContentPage();

    function initCloneContentPage() {
        initSliders();
        initScanning();
        initApproval();
        initModalHandling();
        initImageSettings();
    }

    /**
     * Initialize sliders
     */
    function initSliders() {
        // Creativity slider
        const creativitySlider = $('#aebg-clone-creativity');
        const creativityValue = $('#aebg-clone-creativity-value');
        
        if (creativitySlider.length) {
            creativitySlider.on('input', function() {
                const value = $(this).val();
                creativityValue.text(value);
                updateSliderBackground($(this));
            });
            creativityValue.text(creativitySlider.val());
            updateSliderBackground(creativitySlider);
        }

        // Content length slider
        const contentLengthSlider = $('#aebg-clone-content-length');
        const contentLengthValue = $('#aebg-clone-content-length-value');
        
        if (contentLengthSlider.length) {
            contentLengthSlider.on('input', function() {
                const value = $(this).val();
                contentLengthValue.text(value + ' words');
                updateSliderBackground($(this));
            });
            contentLengthValue.text(contentLengthSlider.val() + ' words');
            updateSliderBackground(contentLengthSlider);
        }
    }

    /**
     * Update slider background
     */
    function updateSliderBackground(slider) {
        const value = slider.val();
        const min = slider.attr('min');
        const max = slider.attr('max');
        const percentage = ((value - min) / (max - min)) * 100;
        slider.css('background', 'linear-gradient(to right, #4f46e5 0%, #4f46e5 ' + percentage + '%, #e5e7eb ' + percentage + '%, #e5e7eb 100%)');
    }

    /**
     * Initialize scanning functionality
     */
    function initScanning() {
        $('#aebg-clone-scan-competitor').on('click', function() {
            scanCompetitorProducts();
        });
    }

    /**
     * Scan competitor products with progress tracking
     */
    function scanCompetitorProducts() {
        const competitorUrl = $('#aebg-clone-competitor-url').val().trim();
        const templateId = $('#aebg-clone-template').val();
        const scanMethod = $('input[name="aebg_clone_scan_method"]:checked').val() || 'scrape';

        if (!competitorUrl) {
            showError('Please enter a competitor URL');
            return;
        }

        if (!templateId) {
            showError('Please select a template first');
            return;
        }

        // Show progress section
        $('#aebg-scan-progress-section').show();
        updateScanProgress(0, 'Initializing scan...', 'Preparing to fetch competitor page');

        // Disable button
        const $btn = $('#aebg-clone-scan-competitor');
        const originalText = $btn.html();
        $btn.prop('disabled', true);

        // Start scanning with progress updates
        startScanWithProgress(competitorUrl, templateId, function() {
            $btn.prop('disabled', false).html(originalText);
        });
    }

    /**
     * Start scan with optimistic progress tracking
     */
    function startScanWithProgress(competitorUrl, templateId, onComplete) {
        // Show optimistic progress updates (different steps based on scan method)
        const scanMethod = $('input[name="aebg_clone_scan_method"]:checked').val() || 'scrape';
        let progressSteps = [];
        
        if (scanMethod === 'ai_analysis') {
            progressSteps = [
                { progress: 10, message: 'Fetching page...', step: 'Downloading page content' },
                { progress: 20, message: 'AI Analysis...', step: 'Analyzing website directly with AI' },
                { progress: 50, message: 'Extracting products...', step: 'AI extracting product information' },
                { progress: 70, message: 'Processing products...', step: 'Converting product data' },
                { progress: 85, message: 'Searching networks...', step: 'Finding affiliate links' },
                { progress: 95, message: 'Finalizing...', step: 'Preparing product list' },
            ];
        } else {
            progressSteps = [
                { progress: 10, message: 'Connecting...', step: 'Connecting to competitor website' },
                { progress: 20, message: 'Fetching page...', step: 'Downloading page content' },
                { progress: 40, message: 'Analyzing content...', step: 'Extracting products using AI' },
                { progress: 60, message: 'Processing products...', step: 'Converting product data' },
                { progress: 80, message: 'Finalizing...', step: 'Preparing product list' },
            ];
        }
        
        let currentStep = 0;
        const progressInterval = setInterval(function() {
            if (currentStep < progressSteps.length) {
                const step = progressSteps[currentStep];
                updateScanProgress(step.progress, step.message, step.step);
                currentStep++;
            }
        }, 2000); // Update every 2 seconds
        
        // Build REST API URL properly
        let restUrl = aebg.rest_url || '';
        if (restUrl) {
            // Remove trailing slash if present, then add endpoint
            restUrl = restUrl.replace(/\/$/, '') + '/scan-competitor-products';
        } else {
            // Fallback: construct from ajaxurl by removing /wp-admin/admin-ajax.php and adding /wp-json/
            const ajaxUrl = aebg_ajax.ajaxurl || '';
            restUrl = ajaxUrl.replace('/wp-admin/admin-ajax.php', '') + '/wp-json/aebg/v1/scan-competitor-products';
        }
        
        // Make the actual API request
        $.ajax({
            url: restUrl,
            method: 'POST',
            contentType: 'application/json',
            timeout: 120000, // 2 minute timeout
            data: JSON.stringify({
                competitor_url: competitorUrl,
                template_id: parseInt(templateId),
                scan_method: scanMethod
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(response) {
                clearInterval(progressInterval);
                if (response.success) {
                    // Scan completed successfully
                    updateScanProgress(100, 'Scan complete!', 'Products extracted successfully');
                    setTimeout(function() {
                        $('#aebg-scan-progress-section').hide();
                        
                        // Ensure we have valid products before showing modal
                        const products = response.products || [];
                        const requiredCount = response.required_count || 0;
                        const foundCount = response.found_count || products.length;
                        const hasShortage = response.has_shortage || false;
                        const shortageCount = response.shortage_count || 0;
                        
                        console.log('[AEBG] Scan completed, showing approval modal:', {
                            products_count: products.length,
                            required_count: requiredCount,
                            found_count: foundCount,
                            has_shortage: hasShortage,
                            shortage_count: shortageCount
                        });
                        
                        // Show the product approval modal - this should show the results first
                        // User can then choose to use AI Find Missing Products or Manual Search
                        showProductApprovalModal(
                            products, 
                            requiredCount, 
                            foundCount,
                            hasShortage,
                            shortageCount
                        );
                        
                        if (onComplete) onComplete();
                    }, 500);
                } else {
                    showError(response.message || 'Failed to scan competitor products');
                    $('#aebg-scan-progress-section').hide();
                    if (onComplete) onComplete();
                }
            },
            error: function(xhr) {
                clearInterval(progressInterval);
                let errorMsg = 'Failed to scan competitor products';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (xhr.status === 0) {
                    errorMsg = 'Network error. Please check your connection.';
                } else if (xhr.status >= 500) {
                    errorMsg = 'Server error. Please try again later.';
                } else if (xhr.statusText === 'timeout') {
                    errorMsg = 'Request timed out. The competitor page may be slow to respond.';
                }
                showError(errorMsg);
                $('#aebg-scan-progress-section').hide();
                if (onComplete) onComplete();
            }
        });
    }

    /**
     * Update scan progress UI
     */
    function updateScanProgress(percentage, message, step) {
        $('#aebg-scan-progress-bar-inner').css('width', percentage + '%');
        $('#aebg-scan-progress-text').text(message);
        $('#aebg-scan-current-activity').text(step);
    }

    /**
     * Show product approval modal
     */
    function showProductApprovalModal(products, requiredCount, foundCount, hasShortage, shortageCount) {
        console.log('[AEBG] showProductApprovalModal called:', {
            products_count: products.length,
            required_count: requiredCount,
            found_count: foundCount,
            has_shortage: hasShortage,
            shortage_count: shortageCount
        });

        // Validate inputs
        if (!Array.isArray(products)) {
            console.error('[AEBG] Invalid products array:', products);
            products = [];
        }

        $('#aebg-found-count').text(foundCount);
        $('#aebg-required-count').text(requiredCount);
        $('#aebg-required-count-2').text(requiredCount);

        // Show/hide shortage notification
        if (hasShortage && shortageCount > 0) {
            $('#aebg-shortage-notification').show();
            $('#aebg-shortage-missing-count').text(shortageCount);
            $('#aebg-shortage-found-count').text(foundCount);
            $('#aebg-shortage-required-count').text(requiredCount);
        } else {
            $('#aebg-shortage-notification').hide();
        }

        // Build products list HTML
        let html = '';
        products.forEach(function(product, index) {
            // Ensure product has required fields
            if (!product || typeof product !== 'object') {
                console.warn('[AEBG] Invalid product at index', index, ':', product);
                return;
            }

            const isChecked = index < requiredCount ? 'checked' : '';
            const hasAffiliateLink = product.datafeedr_match && product.datafeedr_match.affiliate_url;
            const affiliateStatus = hasAffiliateLink ? 'success' : 'warning';
            const affiliateIcon = hasAffiliateLink ? '✅' : '⚠️';
            const affiliateText = hasAffiliateLink ? 'Affiliate link found' : 'No affiliate link';
            
            // Get price information
            const originalPrice = product._full_data?.price || product.price || '';
            const salePrice = product._full_data?.finalprice || product.finalprice || '';
            const discount = product._full_data?.salediscount || product.salediscount || 0;
            const displayPrice = salePrice || originalPrice || '';
            const hasDiscount = discount > 0 && salePrice && originalPrice && parseFloat(salePrice) < parseFloat(originalPrice);
            
            // Get product image
            const productImage = product.image || product.image_url || product._full_data?.image || product._full_data?.image_url || product.datafeedr_match?.image || '';
            
            html += '<div class="aebg-product-item">';
            html += '<label class="aebg-product-checkbox-label">';
            html += '<input type="checkbox" class="aebg-product-checkbox" data-product-index="' + index + '" ' + isChecked + '>';
            
            // Product image thumbnail
            if (productImage) {
                html += '<div class="aebg-product-thumbnail">';
                html += '<img src="' + escapeHtml(productImage) + '" alt="' + escapeHtml(product.name || 'Product') + '" onerror="this.style.display=\'none\'" />';
                html += '</div>';
            } else {
                html += '<div class="aebg-product-thumbnail aebg-product-thumbnail-placeholder">';
                html += '<span class="aebg-product-thumbnail-icon">📦</span>';
                html += '</div>';
            }
            
            html += '<div class="aebg-product-details">';
            html += '<div class="aebg-product-name"><span class="aebg-product-number">' + (index + 1) + '.</span> ' + escapeHtml(product.name || 'Unknown Product') + '</div>';
            
            // Price information with savings
            if (displayPrice) {
                html += '<div class="aebg-product-price-info">';
                if (hasDiscount) {
                    html += '<div class="aebg-product-price-row">';
                    html += '<span class="aebg-product-price-original">' + escapeHtml(originalPrice) + '</span>';
                    html += '<span class="aebg-product-price-sale">' + escapeHtml(salePrice) + '</span>';
                    html += '</div>';
                    html += '<div class="aebg-product-savings">';
                    html += '<span class="aebg-product-savings-badge">Spar ' + Math.round(discount) + '%</span>';
                    const savingsAmount = parseFloat(originalPrice) - parseFloat(salePrice);
                    if (!isNaN(savingsAmount) && savingsAmount > 0) {
                        html += '<span class="aebg-product-savings-amount">Spar ' + savingsAmount.toFixed(2) + ' kr</span>';
                    }
                    html += '</div>';
                } else {
                    html += '<div class="aebg-product-price-single">' + escapeHtml(displayPrice) + '</div>';
                }
                html += '</div>';
            }
            
            if (product.merchant) {
                html += '<div class="aebg-product-meta"><strong>Merchant:</strong> ' + escapeHtml(product.merchant) + '</div>';
            }
            // Affiliate link status
            html += '<div class="aebg-product-meta aebg-affiliate-status aebg-affiliate-' + affiliateStatus + '">';
            html += '<strong>' + affiliateIcon + ' ' + affiliateText + '</strong>';
            if (hasAffiliateLink) {
                html += '<br><small>Network: ' + escapeHtml(product.datafeedr_match.network || 'Unknown') + '</small>';
                html += '<br><a href="' + escapeHtml(product.datafeedr_match.affiliate_url) + '" target="_blank" rel="noopener" class="aebg-affiliate-link">View Affiliate Link</a>';
            } else {
                html += '<br><small>Product not found in configured affiliate networks</small>';
            }
            html += '</div>';
            // Original competitor link if available
            if (product.affiliate_link && !hasAffiliateLink) {
                html += '<div class="aebg-product-meta"><a href="' + escapeHtml(product.affiliate_link) + '" target="_blank" rel="noopener">View Original Link</a></div>';
            }
            html += '</div>';
            html += '</label>';
            html += '</div>';
        });

        // Update the products list HTML
        $('#aebg-products-list').html(html);
        
        console.log('[AEBG] Products list HTML updated, product items:', $('#aebg-products-list .aebg-product-item').length);
        
        // Store products data for later use
        $('#aebg-products-list').data('products', products);
        $('#aebg-products-list').data('competitor-url', $('#aebg-clone-competitor-url').val());
        $('#aebg-products-list').data('template-id', $('#aebg-clone-template').val());
        $('#aebg-products-list').data('required-count', requiredCount);

        // Update selection count
        updateProductSelection();

        // Ensure modal is visible and centered
        const $modal = $('#aebg-product-approval-modal');
        $modal.css('display', 'flex').addClass('show');
        
        // IMPORTANT: Ensure the modal is actually visible and not hidden
        // This prevents any auto-triggering of find missing products
        console.log('[AEBG] Product approval modal displayed:', {
            is_visible: $modal.is(':visible'),
            display: $modal.css('display'),
            has_show_class: $modal.hasClass('show'),
            products_in_modal: $('#aebg-products-list .aebg-product-item').length,
            has_shortage: hasShortage,
            shortage_count: shortageCount
        });
        
        // CRITICAL: Do NOT auto-trigger find missing products
        // The user should see the results first (5/7 found) and choose to find missing products manually
        // The shortage notification will show the buttons, but nothing should auto-trigger
        
        // Scroll to top of products list to show new products
        const $productsList = $('#aebg-products-list');
        if ($productsList.length) {
            $productsList[0].scrollTop = 0;
        }
    }

    /**
     * Initialize approval functionality
     */
    function initApproval() {
        // Approve and generate button
        $('#aebg-approve-and-generate-btn').on('click', function() {
            approveAndGenerate();
        });

        // Product checkbox change handler
        $(document).on('change', '.aebg-product-checkbox', function() {
            updateProductSelection();
        });

        // AI Find Missing Products button
        $('#aebg-ai-find-missing-btn').on('click', function() {
            findMissingProductsWithAI();
        });

        // Manual Search button
        $('#aebg-manual-search-btn').on('click', function() {
            openManualSearchModal();
        });
    }

    /**
     * Find missing products using AI market analysis
     */
    function findMissingProductsWithAI() {
        const products = $('#aebg-products-list').data('products') || [];
        const competitorUrl = $('#aebg-products-list').data('competitor-url');
        const requiredCount = parseInt($('#aebg-products-list').data('required-count') || 0);
        const foundCount = products.length;
        const missingCount = requiredCount - foundCount;

        if (missingCount <= 0) {
            showError('No missing products to find.');
            return;
        }

        // Disable button and show loading
        const $btn = $('#aebg-ai-find-missing-btn');
        const originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="aebg-icon">⏳</span> Analyzing market...');

        // Show progress notification
        showMarketAnalysisProgress('Analyzing market for product category...');

        // Build REST API URL
        let restUrl = aebg.rest_url || '';
        if (restUrl) {
            restUrl = restUrl.replace(/\/$/, '') + '/find-missing-products';
        } else {
            const ajaxUrl = aebg_ajax.ajaxurl || '';
            restUrl = ajaxUrl.replace('/wp-admin/admin-ajax.php', '') + '/wp-json/aebg/v1/find-missing-products';
        }

        // Make API request
        $.ajax({
            url: restUrl,
            method: 'POST',
            contentType: 'application/json',
            timeout: 120000, // 2 minute timeout
            data: JSON.stringify({
                found_products: products,
                competitor_url: competitorUrl,
                missing_count: missingCount,
                country: 'Denmark', // Can be made configurable later
                language: 'Danish'
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(response) {
                console.log('[AEBG] findMissingProductsWithAI success response:', response);
                
                $btn.prop('disabled', false).html(originalText);
                hideMarketAnalysisProgress();

                // Handle WordPress REST API response structure
                // Response might be wrapped in 'data' property or be direct
                const responseData = response.data || response;
                
                if (responseData.success && responseData.recommendations && responseData.recommendations.length > 0) {
                    console.log('[AEBG] Showing recommendations modal:', {
                        recommendations_count: responseData.recommendations.length,
                        category: responseData.category,
                        recommendations: responseData.recommendations
                    });
                    // Show recommendations modal
                    showAIRecommendationsModal(responseData.recommendations, responseData.category, responseData.market_insights);
                } else {
                    console.warn('[AEBG] No recommendations found in response:', responseData);
                    console.warn('[AEBG] Response structure:', {
                        success: responseData.success,
                        has_recommendations: !!responseData.recommendations,
                        recommendations_length: responseData.recommendations ? responseData.recommendations.length : 0,
                        total_found: responseData.total_found
                    });
                    showError(responseData.message || 'No product recommendations found. Please try again or proceed with available products.');
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html(originalText);
                hideMarketAnalysisProgress();
                
                let errorMsg = 'Failed to find missing products';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (xhr.status === 0) {
                    errorMsg = 'Network error. Please check your connection.';
                } else if (xhr.status >= 500) {
                    errorMsg = 'Server error. Please try again later.';
                }
                showError(errorMsg);
            }
        });
    }

    /**
     * Show AI recommendations modal
     */
    function showAIRecommendationsModal(recommendations, category, marketInsights) {
        let html = '<div class="aebg-ai-recommendations-header">';
        html += '<h3>🤖 AI Market Analysis Results</h3>';
        if (category) {
            html += '<p class="aebg-category-badge">Category: <strong>' + escapeHtml(category) + '</strong></p>';
        }
        if (marketInsights) {
            html += '<p class="aebg-market-insights">' + escapeHtml(marketInsights) + '</p>';
        }
        html += '</div>';

        html += '<div class="aebg-recommendations-list">';
        recommendations.forEach(function(rec, index) {
            const hasAffiliateLink = rec.datafeedr_match && rec.datafeedr_match.affiliate_url;
            const affiliateStatus = hasAffiliateLink ? 'success' : 'warning';
            const affiliateIcon = hasAffiliateLink ? '✅' : '⚠️';
            
            html += '<div class="aebg-recommendation-item">';
            html += '<label class="aebg-recommendation-checkbox-label">';
            html += '<input type="checkbox" class="aebg-recommendation-checkbox" data-recommendation-index="' + index + '" checked>';
            html += '<div class="aebg-recommendation-details">';
            html += '<div class="aebg-recommendation-name">' + escapeHtml(rec.name || '') + '</div>';
            if (rec.recommendation_reason) {
                html += '<div class="aebg-recommendation-reason">💡 ' + escapeHtml(rec.recommendation_reason) + '</div>';
            }
            if (rec.price) {
                html += '<div class="aebg-recommendation-meta"><strong>Price:</strong> ' + escapeHtml(rec.price) + '</div>';
            }
            if (rec.merchant) {
                html += '<div class="aebg-recommendation-meta"><strong>Merchant:</strong> ' + escapeHtml(rec.merchant) + '</div>';
            }
            html += '<div class="aebg-recommendation-meta aebg-affiliate-status aebg-affiliate-' + affiliateStatus + '">';
            html += '<strong>' + affiliateIcon + ' ' + (hasAffiliateLink ? 'Affiliate link found' : 'No affiliate link') + '</strong>';
            if (hasAffiliateLink) {
                html += '<br><small>Network: ' + escapeHtml(rec.datafeedr_match.network || 'Unknown') + '</small>';
            }
            html += '</div>';
            html += '</div>';
            html += '</label>';
            html += '</div>';
        });
        html += '</div>';

        $('#aebg-ai-recommendations-content').html(html);
        $('#aebg-ai-recommendations-modal').data('recommendations', recommendations);
        $('#aebg-ai-recommendations-modal').css('display', 'flex').addClass('show');
        
        console.log('[AEBG] Recommendations modal shown with', recommendations.length, 'recommendations');
        console.log('[AEBG] Stored recommendations data:', recommendations);
    }

    /**
     * Add AI recommendations to product list
     */
    function addAIRecommendationsToProducts() {
        const recommendations = $('#aebg-ai-recommendations-modal').data('recommendations') || [];
        const selectedIndices = [];
        
        $('.aebg-recommendation-checkbox:checked').each(function() {
            selectedIndices.push(parseInt($(this).data('recommendation-index')));
        });

        if (selectedIndices.length === 0) {
            showError('Please select at least one recommendation to add.');
            return;
        }

        console.log('[AEBG] Adding recommendations:', {
            total_recommendations: recommendations.length,
            selected_count: selectedIndices.length,
            selected_indices: selectedIndices
        });

        // Get current products - CRITICAL: preserve existing products
        const currentProducts = $('#aebg-products-list').data('products') || [];
        console.log('[AEBG] Current products BEFORE adding recommendations:', {
            count: currentProducts.length,
            product_names: currentProducts.map(p => p.name || 'Unknown')
        });
        
        if (currentProducts.length === 0) {
            console.warn('[AEBG] WARNING: No existing products found! This might cause products to be replaced.');
        }
        
        // Create a new array with existing products (shallow copy is fine for objects)
        const newProducts = currentProducts.slice();

        // Add selected recommendations
        selectedIndices.forEach(function(index) {
            if (recommendations[index]) {
                const recommendation = recommendations[index];
                console.log('[AEBG] Adding recommendation:', {
                    index: index,
                    name: recommendation.name,
                    has_datafeedr_match: !!recommendation.datafeedr_match,
                    full_recommendation: recommendation
                });
                // Ensure recommendation has all required fields
                const formattedRecommendation = {
                    name: recommendation.name || 'Unknown Product',
                    price: recommendation.price || '',
                    merchant: recommendation.merchant || '',
                    affiliate_link: recommendation.affiliate_link || (recommendation.datafeedr_match && recommendation.datafeedr_match.affiliate_url) || '',
                    network: recommendation.network || '',
                    rating: recommendation.rating || '',
                    description: recommendation.description || '',
                    image: recommendation.image || '',
                    position: newProducts.length + 1,
                    datafeedr_match: recommendation.datafeedr_match || null,
                    recommendation_reason: recommendation.recommendation_reason || '',
                    _full_data: recommendation._full_data || {
                        name: recommendation.name || '',
                        short_name: recommendation.name || '',
                        price: recommendation.price || '',
                        url: recommendation.affiliate_link || (recommendation.datafeedr_match && recommendation.datafeedr_match.affiliate_url) || '',
                        affiliate_url: recommendation.affiliate_link || (recommendation.datafeedr_match && recommendation.datafeedr_match.affiliate_url) || '',
                        merchant: recommendation.merchant || '',
                        network: recommendation.network || '',
                        rating: recommendation.rating || '',
                        description: recommendation.description || '',
                        image: recommendation.image || '',
                        image_url: recommendation.image || '',
                    }
                };
                newProducts.push(formattedRecommendation);
                console.log('[AEBG] Added formatted recommendation:', formattedRecommendation.name);
            } else {
                console.warn('[AEBG] Recommendation at index', index, 'not found. Available recommendations:', recommendations);
            }
        });

        console.log('[AEBG] Products AFTER adding recommendations:', {
            original_count: currentProducts.length,
            new_count: newProducts.length,
            added_count: newProducts.length - currentProducts.length,
            all_product_names: newProducts.map(p => p.name || 'Unknown')
        });

        // CRITICAL: Update products list data BEFORE showing modal
        $('#aebg-products-list').data('products', newProducts);
        
        // Verify the update worked
        const verifyProducts = $('#aebg-products-list').data('products') || [];
        if (verifyProducts.length !== newProducts.length) {
            console.error('[AEBG] ERROR: Products data update failed! Expected:', newProducts.length, 'Got:', verifyProducts.length);
        } else {
            console.log('[AEBG] Products data updated successfully:', verifyProducts.length, 'products');
        }

        // Close recommendations modal
        $('#aebg-ai-recommendations-modal').css('display', 'none').removeClass('show');

        // Recalculate shortage status
        const requiredCount = parseInt($('#aebg-products-list').data('required-count') || 0);
        const foundCount = newProducts.length;
        const hasShortage = foundCount < requiredCount;
        const shortageCount = hasShortage ? (requiredCount - foundCount) : 0;

        console.log('[AEBG] Refreshing approval modal:', {
            required_count: requiredCount,
            found_count: foundCount,
            has_shortage: hasShortage,
            shortage_count: shortageCount
        });

        // Refresh product approval modal with updated data
        showProductApprovalModal(newProducts, requiredCount, foundCount, hasShortage, shortageCount);
        
        // Show success message
        if (selectedIndices.length > 0) {
            const message = selectedIndices.length === 1 
                ? '1 product added successfully!' 
                : selectedIndices.length + ' products added successfully!';
            // Use a simple notification (you can enhance this with a toast notification)
            setTimeout(function() {
                console.log('[AEBG]', message);
            }, 100);
        }
    }

    /**
     * Show market analysis progress notification
     */
    function showMarketAnalysisProgress(message) {
        let $notification = $('#aebg-market-analysis-progress');
        if ($notification.length === 0) {
            $notification = $('<div id="aebg-market-analysis-progress" class="aebg-market-analysis-progress" style="position: fixed; top: 20px; right: 20px; background: #4f46e5; color: white; padding: 15px 20px; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 10001; max-width: 400px; display: none;"><span class="aebg-icon">⏳</span> <span class="aebg-progress-message"></span></div>');
            $('body').append($notification);
        }
        $notification.find('.aebg-progress-message').text(message);
        $notification.fadeIn();
    }

    /**
     * Hide market analysis progress notification
     */
    function hideMarketAnalysisProgress() {
        $('#aebg-market-analysis-progress').fadeOut();
    }

    /**
     * Update product selection count
     */
    function updateProductSelection() {
        const requiredCount = parseInt($('#aebg-products-list').data('required-count') || 0);
        const selectedCount = $('.aebg-product-checkbox:checked').length;

        $('#aebg-selected-count').text(selectedCount);

        // Allow approval if at least one product is selected (even if less than required)
        if (selectedCount > 0) {
            $('#aebg-approve-and-generate-btn').prop('disabled', false);
            if (selectedCount < requiredCount) {
                $('#aebg-approve-and-generate-btn').html('Approve & Generate (' + selectedCount + ' of ' + requiredCount + ' required)');
            } else {
                $('#aebg-approve-and-generate-btn').html('Approve & Generate');
            }
        } else {
            $('#aebg-approve-and-generate-btn').prop('disabled', true);
            $('#aebg-approve-and-generate-btn').html('Approve & Generate');
        }
    }

    /**
     * Approve selected products and generate post
     */
    function approveAndGenerate() {
        const products = $('#aebg-products-list').data('products') || [];
        const competitorUrl = $('#aebg-products-list').data('competitor-url');
        const templateId = $('#aebg-products-list').data('template-id');
        const title = $('#aebg-clone-title').val().trim();

        if (!title) {
            showError('Please enter a title');
            return;
        }

        // Get selected products
        const selectedProducts = [];
        $('.aebg-product-checkbox:checked').each(function() {
            const index = $(this).data('product-index');
            if (products[index]) {
                selectedProducts.push(products[index]);
            }
        });

        if (selectedProducts.length === 0) {
            showError('Please select at least one product');
            return;
        }

        // Close modal
        $('#aebg-product-approval-modal').css('display', 'none').removeClass('show');

        // Collect all settings (matching bulk generator)
        const settings = {
            post_type: getSafeFormValue('#aebg-clone-post-type', 'post'),
            post_status: getSafeFormValue('#aebg-clone-post-status', 'draft'),
            ai_model: getSafeFormValue('#aebg-clone-ai-model', 'gpt-3.5-turbo'),
            creativity: validateNumericParameter(getSafeFormValue('#aebg-clone-creativity', '0.7'), 0.0, 2.0, 0.7),
            content_length: validateNumericParameter(getSafeFormValue('#aebg-clone-content-length', '1500'), 500, 3000, 1500),
            num_products: selectedProducts.length,
            include_ai_images: getSafeCheckboxValue('#aebg-clone-include-ai-images', false),
            generate_featured_images: getSafeCheckboxValue('#aebg-clone-generate-featured-images', false),
            featured_image_style: getSafeFormValue('#aebg-clone-featured-image-style', 'realistic photo'),
            image_model: getSafeFormValue('#aebg-clone-image-model', 'dall-e-3'),
            image_size: getSafeFormValue('#aebg-clone-image-size', '1024x1024'),
            image_quality: (function() {
                const checked = $('input[name="aebg_clone_image_quality"]:checked');
                return checked.length > 0 ? checked.val() : 'standard';
            })(),
            auto_categories: getSafeCheckboxValue('#aebg-clone-auto-categories', false),
            auto_tags: getSafeCheckboxValue('#aebg-clone-auto-tags', false),
            include_meta: getSafeCheckboxValue('#aebg-clone-include-meta', false),
            include_schema: getSafeCheckboxValue('#aebg-clone-include-schema', false)
        };

        // Show generation progress
        $('#aebg-generation-progress-section').show();
        updateGenerationProgress(0, 'Starting generation...', 'Initializing post generation');

        // Build REST API URL properly
        let generateUrl = aebg.rest_url || '';
        if (generateUrl) {
            // Remove trailing slash if present, then add endpoint
            generateUrl = generateUrl.replace(/\/$/, '') + '/generate-from-competitor';
        } else {
            // Fallback: construct from ajaxurl by removing /wp-admin/admin-ajax.php and adding /wp-json/
            const ajaxUrl = aebg_ajax.ajaxurl || '';
            generateUrl = ajaxUrl.replace('/wp-admin/admin-ajax.php', '') + '/wp-json/aebg/v1/generate-from-competitor';
        }
        
        // Make API request
        $.ajax({
            url: generateUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                title: title,
                competitor_url: competitorUrl,
                template_id: parseInt(templateId),
                approved_products: selectedProducts,
                settings: settings
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(response) {
                if (response.success) {
                    if (response.post_id) {
                        // Legacy: Instant generation completed
                        updateGenerationProgress(100, 'Generation complete!', 'Post created successfully');
                        setTimeout(function() {
                            window.location.href = 'post.php?post=' + response.post_id + '&action=edit';
                        }, 1500);
                    } else if (response.item_id) {
                        // Step-by-step: Generation scheduled
                        updateGenerationProgress(100, 'Generation scheduled!', 'Post generation has been scheduled and will be processed in the background');
                        setTimeout(function() {
                            $('#aebg-generation-progress-section').hide();
                            // Show success message
                            showSuccess(response.message || 'Post generation scheduled successfully. It will be processed step-by-step in the background.');
                            // Optionally redirect to results page or refresh
                            // window.location.href = 'admin.php?page=aebg_generator&batch_id=' + response.batch_id;
                        }, 1500);
                    } else {
                        // Unexpected response format
                        showError(response.message || 'Unexpected response format');
                        $('#aebg-generation-progress-section').hide();
                    }
                } else {
                    showError(response.message || 'Failed to generate post');
                    $('#aebg-generation-progress-section').hide();
                }
            },
            error: function(xhr) {
                let errorMsg = 'Failed to generate post';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                showError(errorMsg);
                $('#aebg-generation-progress-section').hide();
            }
        });
    }

    /**
     * Update generation progress UI
     */
    function updateGenerationProgress(percentage, message, step) {
        $('#aebg-generation-progress-bar-inner').css('width', percentage + '%');
        $('#aebg-generation-progress-text').text(message);
        $('#aebg-generation-current-activity').text(step);
    }

    /**
     * Initialize modal handling
     */
    function initModalHandling() {
        // Close modals
        $('.aebg-modal-close, #aebg-cancel-approval, #aebg-cancel-recommendations, #aebg-cancel-manual-search, #aebg-manual-search-close').on('click', function() {
            const $modal = $(this).closest('.aebg-modal');
            $modal.css('display', 'none').removeClass('show');
        });

        // Add recommendations button
        $('#aebg-add-recommendations-btn').on('click', function() {
            addAIRecommendationsToProducts();
        });

        // Manual search buttons
        $('#aebg-manual-search-execute').on('click', function() {
            performManualProductSearch();
        });

        $('#aebg-manual-search-clear').on('click', function() {
            $('#aebg-manual-search-name').val('');
            $('#aebg-manual-search-brand').val('');
            $('#aebg-manual-search-min-price').val('');
            $('#aebg-manual-search-max-price').val('');
            $('#aebg-manual-search-results-section').hide();
            $('#aebg-manual-search-results').html('');
        });

        // Allow Enter key to trigger search
        $('#aebg-manual-search-name, #aebg-manual-search-brand').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                performManualProductSearch();
            }
        });

        // Add selected products button
        $('#aebg-add-selected-products-btn').on('click', function() {
            addManualSearchProductsToList();
        });

        // Close on outside click
        $('.aebg-modal').on('click', function(e) {
            if ($(e.target).hasClass('aebg-modal')) {
                $(this).css('display', 'none').removeClass('show');
            }
        });
    }

    /**
     * Initialize image settings visibility
     */
    function initImageSettings() {
        // Show/hide image settings based on checkboxes
        $('#aebg-clone-include-ai-images, #aebg-clone-generate-featured-images').on('change', function() {
            const includeAiImages = $('#aebg-clone-include-ai-images').is(':checked');
            const generateFeatured = $('#aebg-clone-generate-featured-images').is(':checked');
            
            // Show image settings if either checkbox is checked
            if (includeAiImages || generateFeatured) {
                $('#aebg-clone-image-model-group, #aebg-clone-image-size-group, #aebg-clone-image-quality-group').show();
            } else {
                $('#aebg-clone-image-model-group, #aebg-clone-image-size-group, #aebg-clone-image-quality-group').hide();
            }
            
            // Show featured image style only if featured images is checked
            if (generateFeatured) {
                $('#aebg-clone-featured-image-style-group').show();
            } else {
                $('#aebg-clone-featured-image-style-group').hide();
            }
        });
        
        // Trigger on page load to set initial state
        $('#aebg-clone-include-ai-images, #aebg-clone-generate-featured-images').trigger('change');
    }

    /**
     * Show error message
     */
    function showError(message) {
        $('#aebg-error-content').text(message);
        $('#aebg-error-modal').css('display', 'flex').addClass('show');
    }

    /**
     * Open manual search modal
     */
    function openManualSearchModal() {
        // Clear previous search results
        $('#aebg-manual-search-results-section').hide();
        $('#aebg-manual-search-results').html('');
        $('#aebg-manual-search-results-count').text('(0 results)');
        $('#aebg-add-selected-products-btn').prop('disabled', true);
        
        // Clear form fields
        $('#aebg-manual-search-name').val('');
        $('#aebg-manual-search-brand').val('');
        $('#aebg-manual-search-min-price').val('');
        $('#aebg-manual-search-max-price').val('');
        
        // Show modal
        $('#aebg-manual-search-modal').css('display', 'flex').addClass('show');
    }

    /**
     * Perform manual product search
     */
    function performManualProductSearch() {
        const searchParams = {
            name: $('#aebg-manual-search-name').val().trim(),
            brand: $('#aebg-manual-search-brand').val().trim(),
            min_price: $('#aebg-manual-search-min-price').val() || '',
            max_price: $('#aebg-manual-search-max-price').val() || '',
            limit: 50,
            sort: 'relevance'
        };

        // Validate that at least product name is provided
        if (!searchParams.name) {
            showError('Please enter a product name to search for.');
            return;
        }

        // Validate nonce is available
        if (!aebg_ajax || !aebg_ajax.search_products_nonce) {
            console.error('[AEBG] search_products_nonce is not available');
            showError('Security token missing. Please refresh the page and try again.');
            return;
        }

        console.log('[AEBG] Performing manual product search:', searchParams);

        // Show loading state
        $('#aebg-manual-search-results-section').show();
        $('#aebg-manual-search-results').html('<div class="aebg-search-loading">Searching for products...</div>');
        $('#aebg-manual-search-results-count').text('(Searching...)');
        $('#aebg-add-selected-products-btn').prop('disabled', true);

        // Make AJAX request
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_search_products_advanced',
                nonce: aebg_ajax.search_products_nonce,
                query: searchParams.name,
                brand: searchParams.brand,
                limit: searchParams.limit,
                sort_by: searchParams.sort,
                min_price: searchParams.min_price,
                max_price: searchParams.max_price,
                min_rating: '',
                in_stock_only: false,
                currency: '',
                category: '',
                has_image: false,
                network_ids: '',
                page: 1
            },
            success: function(response) {
                console.log('[AEBG] Manual search response:', response);

                if (response.success && response.data) {
                    const products = response.data.products || [];
                    const totalResults = products.length;

                    $('#aebg-manual-search-results-count').text(`(${totalResults} results)`);

                    if (products && products.length > 0) {
                        displayManualSearchResults(products);
                    } else {
                        $('#aebg-manual-search-results').html('<div class="aebg-search-no-results">No products found matching your search criteria.</div>');
                    }
                } else {
                    console.error('[AEBG] Manual search failed:', response.data);
                    $('#aebg-manual-search-results').html('<div class="aebg-search-error">Search failed: ' + (response.data || 'Unknown error') + '</div>');
                    $('#aebg-manual-search-results-count').text('(Error)');
                }
            },
            error: function(xhr, status, error) {
                console.error('[AEBG] Manual search AJAX error:', error);
                $('#aebg-manual-search-results').html('<div class="aebg-search-error">Search failed due to a network error. Please try again.</div>');
                $('#aebg-manual-search-results-count').text('(Error)');
            }
        });
    }

    /**
     * Display manual search results
     */
    function displayManualSearchResults(products) {
        let html = '<div class="aebg-manual-search-results-list">';
        
        products.forEach(function(product, index) {
            const productName = product.name || 'Unknown Product';
            const originalPrice = product.price || '';
            const salePrice = product.finalprice || product.final_price || product.sale_price || '';
            const discount = product.salediscount || product.discount || 0;
            const displayPrice = salePrice || originalPrice || '';
            const hasDiscount = discount > 0 && salePrice && originalPrice && parseFloat(salePrice) < parseFloat(originalPrice);
            const productImage = product.image_url || product.image || '';
            const productMerchant = product.merchant || product.merchant_name || 'N/A';
            const productNetwork = product.network || product.network_name || 'N/A';
            const productUrl = product.affiliate_url || product.url || '';
            const hasAffiliateLink = !!productUrl;

            html += '<div class="aebg-manual-search-result-item">';
            html += '<label class="aebg-manual-search-checkbox-label">';
            html += '<input type="checkbox" class="aebg-manual-search-checkbox" data-product-index="' + index + '" />';
            html += '<div class="aebg-manual-search-result-details">';
            
            // Product image thumbnail
            if (productImage) {
                html += '<div class="aebg-manual-search-result-image">';
                html += '<img src="' + escapeHtml(productImage) + '" alt="' + escapeHtml(productName) + '" onerror="this.style.display=\'none\'" />';
                html += '</div>';
            } else {
                html += '<div class="aebg-manual-search-result-image aebg-product-thumbnail-placeholder">';
                html += '<span class="aebg-product-thumbnail-icon">📦</span>';
                html += '</div>';
            }
            
            html += '<div class="aebg-manual-search-result-info">';
            html += '<div class="aebg-manual-search-result-name">' + escapeHtml(productName) + '</div>';
            
            // Price information with savings
            if (displayPrice) {
                html += '<div class="aebg-product-price-info">';
                if (hasDiscount) {
                    html += '<div class="aebg-product-price-row">';
                    html += '<span class="aebg-product-price-original">' + escapeHtml(originalPrice) + '</span>';
                    html += '<span class="aebg-product-price-sale">' + escapeHtml(salePrice) + '</span>';
                    html += '</div>';
                    html += '<div class="aebg-product-savings">';
                    html += '<span class="aebg-product-savings-badge">Spar ' + Math.round(discount) + '%</span>';
                    const savingsAmount = parseFloat(originalPrice) - parseFloat(salePrice);
                    if (!isNaN(savingsAmount) && savingsAmount > 0) {
                        html += '<span class="aebg-product-savings-amount">Spar ' + savingsAmount.toFixed(2) + ' kr</span>';
                    }
                    html += '</div>';
                } else {
                    html += '<div class="aebg-product-price-single">' + escapeHtml(displayPrice) + '</div>';
                }
                html += '</div>';
            }
            
            html += '<div class="aebg-manual-search-result-meta">';
            html += '<span><strong>Merchant:</strong> ' + escapeHtml(productMerchant) + '</span>';
            html += '<span><strong>Network:</strong> ' + escapeHtml(productNetwork) + '</span>';
            html += '</div>';
            
            if (hasAffiliateLink) {
                html += '<div class="aebg-manual-search-result-link">';
                html += '<a href="' + escapeHtml(productUrl) + '" target="_blank" rel="noopener">View Product</a>';
                html += '</div>';
            }
            
            // Close aebg-manual-search-result-info
            html += '</div>';
            // Close aebg-manual-search-result-details
            html += '</div>';
            // Close aebg-manual-search-checkbox-label
            html += '</label>';
            // Close aebg-manual-search-result-item
            html += '</div>';
        });
        
        html += '</div>';
        
        $('#aebg-manual-search-results').html(html);
        
        // Store all products in modal data for reliable access
        $('#aebg-manual-search-modal').data('search-products', products);
        console.log('[AEBG] Stored', products.length, 'products in modal data');
        
        // Update button state when checkboxes change
        $(document).off('change', '.aebg-manual-search-checkbox').on('change', '.aebg-manual-search-checkbox', function() {
            updateManualSearchSelection();
        });
    }

    /**
     * Update manual search selection count
     */
    function updateManualSearchSelection() {
        const selectedCount = $('.aebg-manual-search-checkbox:checked').length;
        $('#aebg-add-selected-products-btn').prop('disabled', selectedCount === 0);
        if (selectedCount > 0) {
            $('#aebg-add-selected-products-btn').text('Add Selected Products (' + selectedCount + ')');
        } else {
            $('#aebg-add-selected-products-btn').text('Add Selected Products');
        }
    }

    /**
     * Format price for display
     */
    function formatPrice(price) {
        if (typeof price === 'string') {
            return price;
        }
        if (typeof price === 'number') {
            return price.toFixed(2) + ' kr';
        }
        return 'N/A';
    }

    /**
     * Add selected products from manual search to product list
     */
    function addManualSearchProductsToList() {
        const selectedIndices = [];
        
        $('.aebg-manual-search-checkbox:checked').each(function() {
            selectedIndices.push(parseInt($(this).data('product-index')));
        });

        if (selectedIndices.length === 0) {
            showError('Please select at least one product to add.');
            return;
        }

        console.log('[AEBG] Adding manual search products:', selectedIndices);

        // Get current products
        const currentProducts = $('#aebg-products-list').data('products') || [];
        const newProducts = [...currentProducts];

        // Get products from modal data (more reliable than document.data)
        const allSearchProducts = $('#aebg-manual-search-modal').data('search-products') || [];
        console.log('[AEBG] Retrieved products from modal:', allSearchProducts.length);
        
        // Add selected products
        selectedIndices.forEach(function(index) {
            const product = allSearchProducts[index];
            if (product) {
                console.log('[AEBG] Processing product from manual search:', {
                    index: index,
                    product_name: product.name,
                    has_affiliate_url: !!product.affiliate_url,
                    has_url: !!product.url,
                    product_data: product
                });
                
                // Format product similar to how competitor products are formatted
                const formattedProduct = {
                    name: product.name || 'Unknown Product',
                    price: product.price || '',
                    merchant: product.merchant || product.merchant_name || '',
                    affiliate_link: product.affiliate_url || product.url || '',
                    network: product.network || product.network_name || '',
                    rating: product.rating || 0,
                    description: product.description || '',
                    image: product.image_url || product.image || '',
                    position: newProducts.length + 1,
                    datafeedr_match: {
                        name: product.name || '',
                        price: product.price || '',
                        affiliate_url: product.affiliate_url || product.url || '',
                        network: product.network || product.network_name || '',
                        merchant: product.merchant || product.merchant_name || '',
                        image: product.image_url || product.image || '',
                    },
                    _full_data: {
                        name: product.name || '',
                        short_name: product.name || '',
                        price: product.price || '',
                        url: product.affiliate_url || product.url || '',
                        affiliate_url: product.affiliate_url || product.url || '',
                        merchant: product.merchant || product.merchant_name || '',
                        network: product.network || product.network_name || '',
                        rating: product.rating || 0,
                        description: product.description || '',
                        image: product.image_url || product.image || '',
                        image_url: product.image_url || product.image || '',
                    }
                };
                
                // Validate product has at least a name
                if (!formattedProduct.name || formattedProduct.name === 'Unknown Product') {
                    console.warn('[AEBG] Skipping product with no name at index', index);
                    return;
                }
                
                newProducts.push(formattedProduct);
                console.log('[AEBG] Successfully added product to list:', {
                    name: formattedProduct.name,
                    total_products: newProducts.length,
                    formatted_product: formattedProduct
                });
            } else {
                console.error('[AEBG] Product not found at index', index, 'in manual search data');
            }
        });

        console.log('[AEBG] New products count:', newProducts.length);
        console.log('[AEBG] Product names in new array:', newProducts.map(p => p.name));

        // Validate we actually added products
        if (newProducts.length === currentProducts.length) {
            console.error('[AEBG] ERROR: No products were added!', {
                before: currentProducts.length,
                after: newProducts.length,
                selected_indices: selectedIndices
            });
            showError('Failed to add products. Please try again.');
            return;
        }

        // Update products list data BEFORE closing modal
        $('#aebg-products-list').data('products', newProducts);

        // Close manual search modal
        $('#aebg-manual-search-modal').css('display', 'none').removeClass('show');

        // Recalculate shortage status
        const requiredCount = parseInt($('#aebg-products-list').data('required-count') || 0);
        const foundCount = newProducts.length;
        const hasShortage = foundCount < requiredCount;
        const shortageCount = hasShortage ? (requiredCount - foundCount) : 0;

        console.log('[AEBG] Refreshing approval modal with new products:', {
            products_count: newProducts.length,
            required_count: requiredCount,
            found_count: foundCount,
            has_shortage: hasShortage
        });

        // Refresh product approval modal - ensure we pass the actual newProducts array
        showProductApprovalModal(newProducts, requiredCount, foundCount, hasShortage, shortageCount);
        
        // Double-check the products were actually added to the DOM
        setTimeout(function() {
            const domProductCount = $('#aebg-products-list .aebg-product-item').length;
            console.log('[AEBG] Verification - Products in DOM:', domProductCount, 'Expected:', newProducts.length);
            if (domProductCount !== newProducts.length) {
                console.error('[AEBG] MISMATCH: DOM has', domProductCount, 'products but array has', newProducts.length);
            }
        }, 100);
    }

    /**
     * Show success message
     */
    function showSuccess(message) {
        // Create or update success notification
        let $notification = $('#aebg-success-notification');
        if ($notification.length === 0) {
            $notification = $('<div id="aebg-success-notification" class="aebg-success-notification" style="position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 15px 20px; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 10000; max-width: 400px; display: none;"><strong>✅ Success:</strong> <span class="aebg-success-message"></span></div>');
            $('body').append($notification);
        }
        $notification.find('.aebg-success-message').text(message);
        $notification.fadeIn();
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        // Handle null, undefined, or empty values
        if (text === null || text === undefined) return '';
        
        // Convert to string if it's not already (handles numbers, booleans, etc.)
        if (typeof text !== 'string') {
            text = String(text);
        }
        
        // Handle empty string
        if (!text) return '';
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /**
     * Safely get form input value with validation
     */
    function getSafeFormValue(selector, defaultValue) {
        try {
            const element = $(selector);
            if (element.length > 0) {
                const value = element.val();
                return (value !== null && value !== undefined && value !== '') ? value : defaultValue;
            }
            return defaultValue;
        } catch (error) {
            console.error('AEBG Error getting form value for', selector, ':', error);
            return defaultValue;
        }
    }

    /**
     * Safely get checkbox value with validation
     */
    function getSafeCheckboxValue(selector, defaultValue) {
        try {
            const element = $(selector);
            if (element.length > 0) {
                return element.is(':checked') || false;
            }
            return defaultValue;
        } catch (error) {
            console.error('AEBG Error getting checkbox value for', selector, ':', error);
            return defaultValue;
        }
    }

    /**
     * Validate numeric parameter with range checking and fallback
     */
    function validateNumericParameter(value, min, max, defaultValue) {
        try {
            const numValue = parseFloat(value);
            if (isNaN(numValue) || numValue < min || numValue > max) {
                console.warn('AEBG: Invalid numeric parameter', value, ', using default', defaultValue);
                return defaultValue;
            }
            return numValue;
        } catch (error) {
            console.error('AEBG Error validating numeric parameter:', error);
            return defaultValue;
        }
    }
});

