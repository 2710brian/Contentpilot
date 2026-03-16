/**
 * AI Content Generator - Generator Page JavaScript
 * Handles all generator page functionality including form validation, 
 * content generation, progress tracking, and error handling.
 */

jQuery(document).ready(function($) {
    'use strict';

    // Initialize generator page
    initGeneratorPage();

    function initGeneratorPage() {
        // Ensure all modals are hidden on page load (prevents stale state from showing modals)
        $('.aebg-modal').removeClass('show').css('display', 'none');
        // Clear any stale error content
        $('#aebg-error-content').empty();
        
        // Debug: Log nonce values
        if (typeof aebg !== 'undefined' && typeof aebg_ajax !== 'undefined') {
            console.log('AEBG Debug - aebg.ajax_nonce:', aebg.ajax_nonce);
            console.log('AEBG Debug - aebg.validate_nonce:', aebg.validate_nonce);
            console.log('AEBG Debug - aebg_ajax.nonce:', aebg_ajax.nonce);
            console.log('AEBG Debug - aebg_ajax.ajaxurl:', aebg_ajax.ajaxurl);
        } else {
            console.error('AEBG Debug - Nonce variables not found');
        }
        
        // Initialize all components
        initSliders();
        initFormValidation();
        initConnectionTesting();
        initGenerationProcess();
        initModalHandling();
        initKeyboardShortcuts();
        initEnhancedCheckboxes();
        initTemplateValidation();
        initDuplicateDetection();
        
        // Check connection status on page load
        checkConnectionStatus();
        
        // CRITICAL: Check for active batches on page load
        // Use a small delay to ensure DOM is fully ready and REST API is available
        setTimeout(function() {
            console.log('AEBG: Initial check for active batch (100ms delay)...');
            checkActiveBatch();
        }, 100);
        
        // CRITICAL: Also check after a longer delay as a fallback
        // This ensures we catch active batches even if the first check fails due to timing
        setTimeout(function() {
            // Only check again if progress section is still hidden (first check might have failed)
            if ($('#aebg-progress-section').is(':hidden') && !isGenerating) {
                console.log('AEBG: Fallback check for active batch after 2 seconds...');
                checkActiveBatch(0);
            }
        }, 2000);
        
        // CRITICAL: Third check after 5 seconds as final fallback
        // This catches cases where REST API or database queries are slow
        setTimeout(function() {
            if ($('#aebg-progress-section').is(':hidden') && !isGenerating) {
                console.log('AEBG: Final fallback check for active batch after 5 seconds...');
                checkActiveBatch(0);
            }
        }, 5000);
    }

    /**
     * Initialize slider functionality
     */
    function initSliders() {
        // Number of products slider
        const numProductsSlider = $('#aebg-num-products');
        const numProductsValue = $('#aebg-num-products-value');
        
        if (numProductsSlider.length) {
            numProductsSlider.on('input', function() {
                const value = $(this).val();
                numProductsValue.text(value);
                updateSliderBackground($(this));
            });
            
            // Set initial value and background
            numProductsValue.text(numProductsSlider.val());
            updateSliderBackground(numProductsSlider);
        }

        // Creativity slider
        const creativitySlider = $('#aebg-creativity');
        const creativityValue = $('#aebg-creativity-value');
        
        if (creativitySlider.length) {
            creativitySlider.on('input', function() {
                const value = $(this).val();
                creativityValue.text(value);
                updateSliderBackground($(this));
            });
            
            // Set initial value and background
            creativityValue.text(creativitySlider.val());
            updateSliderBackground(creativitySlider);
        }

        // Content length slider
        const contentLengthSlider = $('#aebg-content-length');
        const contentLengthValue = $('#aebg-content-length-value');
        
        if (contentLengthSlider.length) {
            contentLengthSlider.on('input', function() {
                const value = $(this).val();
                contentLengthValue.text(value + ' words');
                updateSliderBackground($(this));
            });
            
            // Set initial value and background
            contentLengthValue.text(contentLengthSlider.val() + ' words');
            updateSliderBackground(contentLengthSlider);
        }
    }

    /**
     * Update slider background based on value
     */
    function updateSliderBackground(slider) {
        const value = slider.val();
        const min = slider.attr('min');
        const max = slider.attr('max');
        const percentage = ((value - min) / (max - min)) * 100;
        
        slider.css('background', 'linear-gradient(to right, #4f46e5 0%, #4f46e5 ' + percentage + '%, #e5e7eb ' + percentage + '%, #e5e7eb 100%)');
    }

    /**
     * Initialize form validation
     */
    function initFormValidation() {
        // Real-time validation for all form fields
        $('#aebg-titles, #aebg-template, #aebg-post-type, #aebg-num-products, #aebg-content-length, #aebg-creativity').on('blur', function() {
            validateField($(this));
        });
        
        // Real-time validation for numeric fields
        $('#aebg-num-products, #aebg-content-length, #aebg-creativity').on('input', function() {
            validateNumericField($(this));
        });
    }

    /**
     * Validate individual field
     */
    function validateField(field) {
        const fieldName = field.attr('name');
        let isValid = true;
        let errorMessage = '';

        switch (fieldName) {
            case 'aebg_titles':
                const titles = field.val().trim();
                if (!titles) {
                    isValid = false;
                    errorMessage = 'Please enter at least one title.';
                } else {
                    const titleLines = titles.split('\n').filter(line => line.trim() !== '');
                    if (titleLines.length === 0) {
                        isValid = false;
                        errorMessage = 'Please enter at least one valid title.';
                    }
                }
                break;

            case 'aebg_template':
                if (!field.val()) {
                    isValid = false;
                    errorMessage = 'Please select an Elementor template.';
                }
                break;

            case 'aebg_post_type':
                if (!field.val()) {
                    isValid = false;
                    errorMessage = 'Please select a post type.';
                }
                break;
        }

        // Show/hide error message
        const errorElement = field.siblings('.aebg-field-error');
        if (!isValid) {
            if (errorElement.length === 0) {
                field.after('<div class="aebg-field-error">' + errorMessage + '</div>');
            } else {
                errorElement.text(errorMessage);
            }
            field.addClass('aebg-error');
        } else {
            errorElement.remove();
            field.removeClass('aebg-error');
        }

        return isValid;
    }

    /**
     * Validate numeric field with range checking
     */
    function validateNumericField(field) {
        const fieldName = field.attr('name');
        const value = parseFloat(field.val());
        const min = parseFloat(field.attr('min'));
        const max = parseFloat(field.attr('max'));
        let isValid = true;
        let errorMessage = '';

        // Check if value is a valid number
        if (isNaN(value)) {
            isValid = false;
            errorMessage = 'Please enter a valid number.';
        }
        // Check minimum value
        else if (min !== undefined && value < min) {
            isValid = false;
            errorMessage = `Value must be at least ${min}.`;
        }
        // Check maximum value
        else if (max !== undefined && value > max) {
            isValid = false;
            errorMessage = `Value must be no more than ${max}.`;
        }

        // Show/hide error message
        const errorElement = field.siblings('.aebg-field-error');
        if (!isValid) {
            if (errorElement.length === 0) {
                field.after('<div class="aebg-field-error">' + errorMessage + '</div>');
            } else {
                errorElement.text(errorMessage);
            }
            field.addClass('aebg-error');
        } else {
            errorElement.remove();
            field.removeClass('aebg-error');
        }

        return isValid;
    }

    /**
     * Validate entire form
     */
    function validateForm() {
        let isValid = true;
        
        // Validate required fields
        $('#aebg-titles, #aebg-template, #aebg-post-type').each(function() {
            if (!validateField($(this))) {
                isValid = false;
            }
        });
        
        // Validate numeric fields
        $('#aebg-num-products, #aebg-content-length, #aebg-creativity').each(function() {
            if (!validateNumericField($(this))) {
                isValid = false;
            }
        });

        if (!isValid) {
            showMessage('Please fix the errors above before generating content.', 'error');
            return false;
        }

        return true;
    }

    /**
     * Initialize connection testing
     */
    function initConnectionTesting() {
        $('#aebg-test-connection').on('click', function() {
            testConnection();
        });

        $('#aebg-view-results').on('click', function() {
            window.location.href = window.location.href + '&view=results';
        });
    }

    /**
     * Check connection status on page load
     */
    function checkConnectionStatus() {
        updateConnectionStatus('checking', 'Checking connection...');
        
        // Debug: Log the initial connection check data
        const initialConnectionData = {
            action: 'aebg_test_connection',
            _ajax_nonce: aebg_ajax.nonce
        };
        console.log('AEBG Debug - Initial connection check data:', initialConnectionData);
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: initialConnectionData,
            success: function(response) {
                if (response.success) {
                    updateConnectionStatus('connected', 'Connected successfully');
                } else {
                    updateConnectionStatus('error', 'Connection failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                // Safe error logging with proper error handling
                try {
                    const errorInfo = {
                        status: xhr ? xhr.status : 'unknown',
                        statusText: xhr ? xhr.statusText : 'unknown',
                        responseText: xhr ? xhr.responseText : 'unknown',
                        error: error || 'unknown error'
                    };
                    console.error('AEBG Debug - Connection check error:', errorInfo);
                } catch (logError) {
                    console.error('AEBG Debug - Connection check error logging failed:', logError);
                    console.error('AEBG Debug - Original connection check error:', error);
                }
                updateConnectionStatus('error', 'Connection failed. Please check your settings.');
            }
        });
    }

    /**
     * Test API connection
     */
    function testConnection() {
        showLoadingOverlay();
        updateConnectionStatus('checking', 'Testing connection...');

        // Debug: Log the connection test data
        const connectionData = {
            action: 'aebg_test_connection',
            _ajax_nonce: aebg_ajax.nonce
        };
        console.log('AEBG Debug - Connection test data:', connectionData);
        console.log('AEBG Debug - aebg_ajax.nonce value:', aebg_ajax.nonce);

        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: connectionData,
            success: function(response) {
                hideLoadingOverlay();
                
                if (response.success) {
                    updateConnectionStatus('connected', 'Connection successful');
                    showMessage('Connection test successful!', 'success');
                } else {
                    updateConnectionStatus('error', 'Connection failed');
                    showMessage('Connection test failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                // Safe error logging with proper error handling
                try {
                    const errorInfo = {
                        status: xhr ? xhr.status : 'unknown',
                        statusText: xhr ? xhr.statusText : 'unknown',
                        responseText: xhr ? xhr.responseText : 'unknown',
                        error: error || 'unknown error'
                    };
                    console.error('AEBG Debug - Connection test error:', errorInfo);
                } catch (logError) {
                    console.error('AEBG Debug - Connection test error logging failed:', logError);
                    console.error('AEBG Debug - Original connection error:', error);
                }
                
                hideLoadingOverlay();
                updateConnectionStatus('error', 'Connection failed');
                showMessage('Connection test failed. Please check your internet connection.', 'error');
            }
        });
    }

    /**
     * Update connection status display
     */
    function updateConnectionStatus(status, text) {
        const indicator = $('#aebg-status-indicator');
        const statusText = $('#aebg-status-text');
        const statusContainer = $('.aebg-connection-status');
        
        indicator.removeClass('checking connected error').addClass(status);
        statusText.text(text);
        statusContainer.removeClass('checking connected error').addClass(status);
    }

    // Global variables for generation process
    let currentBatchId = null;
    let progressInterval = null;
    let isGenerating = false;
    let generationStartTime = null;
    let elapsedTimeInterval = null;
    let lastBackendElapsedTime = 0; // Store the last elapsed time from backend
    let lastBackendElapsedTimeUpdate = null; // Timestamp when we last received backend elapsed time
    let progressHistory = []; // Track completion times for ETA calculation
    let numProductsPerArticle = 7; // Default: 7 products (2m 14s base estimate)

    /**
     * Start the generation process
     */
    function startGeneration() {
        // Prevent duplicate generation requests
        if (isGenerating) {
            console.log('AEBG Debug - Generation already in progress, ignoring duplicate request');
            showMessage('Generation is already in progress. Please wait.', 'warning');
            return;
        }
        
        console.log('AEBG Debug - Starting generation, setting isGenerating = true');
        isGenerating = true;
        
        try {
            const formData = collectFormData();
                
            if (!formData.titles || formData.titles.length === 0) {
                showMessage('Please enter at least one title.', 'error');
                console.log('AEBG Debug - No titles found, setting isGenerating = false');
                isGenerating = false;
                return;
            }

            // Check for template validation errors
            const templateGroup = $('#aebg-template').closest('.aebg-form-group');
            if (templateGroup.hasClass('aebg-has-error')) {
                showMessage('Please fix template validation errors before proceeding.', 'error');
                console.log('AEBG Debug - Template validation errors found, setting isGenerating = false');
                isGenerating = false;
                return;
            }

            // Show progress section
            $('#aebg-generate-section').hide();
            $('#aebg-progress-section').show();
            
            // Scroll to top of page to show progress section
            $('html, body').animate({
                scrollTop: 0
            }, 300);
            
            // Reset progress (completed, processed, failed, total, status, currentItem, eta)
            updateProgress(0, 0, 0, formData.titles.length, 'Initializing...', null, null, [], []);

            // Debug: Log the AJAX request data
            const ajaxData = {
                action: 'aebg_schedule_batch',
                titles: JSON.stringify(formData.titles),
                settings: JSON.stringify(formData.settings),
                _ajax_nonce: aebg.ajax_nonce
            };
            console.log('AEBG Debug - AJAX request data:', ajaxData);
            console.log('AEBG Debug - Featured image settings:', {
                generate_featured_images: formData.settings.generate_featured_images,
                featured_image_style: formData.settings.featured_image_style,
                type: typeof formData.settings.generate_featured_images
            });
            
            $.ajax({
                url: aebg_ajax.ajaxurl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    if (response.success) {
                        currentBatchId = response.data.batch_id;
                        const productsPerArticle = parseInt(formData.settings.num_products || 7);
                        startProgressTracking(currentBatchId, formData.titles.length, productsPerArticle, 0); // New batch, elapsed time is 0
                    } else {
                        handleGenerationError(response.data || 'Unknown error occurred');
                    }
                },
                error: function(xhr, status, error) {
                    // Safe error logging with proper error handling
                    try {
                        const errorInfo = {
                            status: xhr ? xhr.status : 'unknown',
                            statusText: xhr ? xhr.statusText : 'unknown',
                            responseText: xhr ? xhr.responseText : 'unknown',
                            error: error || 'unknown error'
                        };
                        console.error('AEBG Debug - AJAX error:', errorInfo);
                    } catch (logError) {
                        console.error('AEBG Debug - Error logging failed:', logError);
                        console.error('AEBG Debug - Original error:', error);
                    }
                    
                    // Enhanced error handling for different status codes
                    let errorMessage = 'Network error occurred. Please try again.';
                    
                    if (xhr && xhr.status) {
                        if (xhr.status === 429) {
                            errorMessage = 'Rate limit exceeded (429). The AI service is temporarily limiting requests.';
                        } else if (xhr.status === 401) {
                            errorMessage = 'Authentication failed. Please check your API key.';
                        } else if (xhr.status === 403) {
                            errorMessage = 'Access denied. Please check your API permissions.';
                        } else if (xhr.status === 500) {
                            errorMessage = 'Server error. Please try again later.';
                        } else if (xhr.status === 503) {
                            errorMessage = 'Service temporarily unavailable. Please try again later.';
                        } else if (xhr.status === 0) {
                            errorMessage = 'Network connection failed. Please check your internet connection.';
                        } else {
                            errorMessage = 'Network error: ' + (error || 'Unknown error') + ' (Status: ' + xhr.status + ')';
                        }
                    }
                    
                    // Try to parse response for more specific error messages
                    if (xhr && xhr.responseText) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response && response.data && response.data.message) {
                                errorMessage = response.data.message;
                            }
                        } catch (parseError) {
                            // Use the default error message if parsing fails
                            console.log('AEBG Debug - Could not parse response:', parseError);
                        }
                    }
                    
                    handleGenerationError(errorMessage);
                }
            });
        } catch (error) {
            // Handle any unexpected errors during generation setup
            console.error('AEBG Debug - Unexpected error in startGeneration:', error);
            console.log('AEBG Debug - Error caught, setting isGenerating = false');
            isGenerating = false;
            handleGenerationError('Unexpected error occurred while starting generation: ' + error.message);
        }
    }

    /**
     * Start tracking generation progress
     */
    function startProgressTracking(batchId, totalItems, productsPerArticle, initialElapsedTime) {
        // CRITICAL: Use backend elapsed_time if provided, otherwise start from 0
        // This ensures elapsed time persists across page refreshes and is based on batch creation time
        const elapsedSeconds = initialElapsedTime || 0;
        generationStartTime = Date.now() - (elapsedSeconds * 1000); // Adjust start time to account for already elapsed time
        progressHistory = []; // Reset progress history
        numProductsPerArticle = productsPerArticle || 7; // Store products per article (default 7)
        
        // Initialize backend elapsed time tracking
        if (elapsedSeconds > 0) {
            lastBackendElapsedTime = elapsedSeconds;
            lastBackendElapsedTimeUpdate = Date.now();
        } else {
            lastBackendElapsedTime = 0;
            lastBackendElapsedTimeUpdate = null;
        }
        
        // Start elapsed time counter
        updateElapsedTime();
        elapsedTimeInterval = setInterval(updateElapsedTime, 1000); // Update every second
        
        // Add visual indicator that it's running
        $('#aebg-progress-section').addClass('aebg-progress-running');
        $('#aebg-progress-header h3').html('<span class="aebg-spinner"></span> Generating Content');
        
        let consecutiveErrors = 0; // Track consecutive errors to prevent spam
        const maxConsecutiveErrors = 5; // Stop tracking after 5 consecutive errors
        let seenFailedItemIds = new Set(); // Track which failed items we've already notified about
        
        progressInterval = setInterval(function() {
                $.ajax({
                    url: aebg.rest_url + 'batch/' + batchId,
                    method: 'GET',
                    timeout: 5000, // 5 second timeout for AJAX request
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
                    },
                    success: function(response) {
                        // Reset error counter on success
                        consecutiveErrors = 0;
                        const processed = parseInt(response.processed_items || 0);
                        const failed = parseInt(response.failed_items || 0);
                        const total = parseInt(response.total_items || totalItems);
                        const completed = processed + failed;
                        const currentItem = response.current_item || null;
                        
                        // CRITICAL: Always use backend elapsed_time as the source of truth
                        // This ensures elapsed time is based on batch creation time, not per-post time
                        if (response.elapsed_time !== undefined && response.elapsed_time !== null) {
                            const backendElapsedSeconds = parseInt(response.elapsed_time || 0);
                            
                            // CRITICAL: Only update the timestamp if the backend time has actually increased
                            // This prevents resetting the time calculation when backend returns the same value
                            if (backendElapsedSeconds > lastBackendElapsedTime) {
                                // Backend time increased - update both the time and the timestamp
                                lastBackendElapsedTime = backendElapsedSeconds;
                                lastBackendElapsedTimeUpdate = Date.now();
                            } else if (lastBackendElapsedTime === 0 && backendElapsedSeconds > 0) {
                                // First time we get a valid elapsed time
                                lastBackendElapsedTime = backendElapsedSeconds;
                                lastBackendElapsedTimeUpdate = Date.now();
                            } else if (backendElapsedSeconds === lastBackendElapsedTime) {
                                // Backend time hasn't changed - don't reset the timestamp
                                // This allows the frontend to continue incrementing from the last known time
                                // The timestamp remains unchanged so timeSinceLastUpdate continues to grow
                            }
                            
                            // Always update the display with current calculated time (not just backend time)
                            // This ensures smooth incrementing even when backend value hasn't changed
                            updateElapsedTime();
                        }
                        
                        // Track progress for ETA calculation
                        const currentTime = Date.now();
                        if (progressHistory.length === 0 || progressHistory[progressHistory.length - 1].completed !== completed) {
                            // Only record when completion count changes
                            progressHistory.push({
                                completed: completed,
                                timestamp: currentTime
                            });
                            // Keep only last 10 entries for calculation
                            if (progressHistory.length > 10) {
                                progressHistory.shift();
                            }
                        }
                        
                        // CRITICAL: Ensure failed count is valid (not showing false positives)
                        // Only count as failed if we have completed items AND some failed
                        // Also check that failed_items from API is actually valid
                        let validFailed = 0;
                        if (failed > 0 && completed > 0) {
                            // Only show failed if completed count matches (processed + failed)
                            if (completed === (processed + failed)) {
                                validFailed = failed;
                            }
                        }
                        // Additional safety: if completed is 0, force failed to 0
                        if (completed === 0) {
                            validFailed = 0;
                        }
                        
                        // Calculate ETA (always calculate, even if 0 completed)
                        const aiModel = response.ai_model || 'gpt-3.5-turbo';
                        const eta = calculateETA(completed, total, progressHistory, generationStartTime, aiModel);
                        
                        // Always update progress to show current state (including failures and current item)
                        // Pass processing_items array if available (shows all posts being processed)
                        const processingItems = response.processing_items || [];
                        const failedItemsDetail = response.failed_items_detail || [];
                        updateProgress(completed, processed, validFailed, total, response.status, currentItem, eta, processingItems, failedItemsDetail);
                        
                        // Check for new failed items and show notifications
                        if (failedItemsDetail && failedItemsDetail.length > 0) {
                            failedItemsDetail.forEach(function(failedItem) {
                                if (!seenFailedItemIds.has(failedItem.id)) {
                                    // New failure detected - show notification
                                    seenFailedItemIds.add(failedItem.id);
                                    const title = failedItem.title || 'Untitled Post';
                                    const errorMsg = failedItem.error_message || 'Unknown error';
                                    showFailureNotification(title, errorMsg);
                                }
                            });
                        }

                        // CRITICAL: Check for completion in multiple ways:
                        // 1. Explicit status === 'completed' or 'failed'
                        // 2. All items are done (completed === total) - fallback for when status is wrong
                        const isCompleted = response.status === 'completed' || response.status === 'failed';
                        const allItemsDone = total > 0 && completed === total;
                        
                        if (isCompleted || allItemsDone) {
                            // If status isn't 'completed' but all items are done, update status for display
                            if (allItemsDone && !isCompleted) {
                                response.status = 'completed';
                                console.log('AEBG Debug - All items completed, updating status to completed');
                            }
                            
                            clearInterval(progressInterval);
                            if (elapsedTimeInterval) {
                                clearInterval(elapsedTimeInterval);
                            }
                            // Remove running indicator
                            $('#aebg-progress-section').removeClass('aebg-progress-running');
                            $('#aebg-progress-header h3').html('Generating Content');
                            handleGenerationComplete(response, batchId);
                        }
                    },
                    error: function(xhr, status, error) {
                        consecutiveErrors++;
                        
                        // If too many consecutive errors, stop tracking to prevent spam
                        if (consecutiveErrors >= maxConsecutiveErrors) {
                            console.error('AEBG Debug - Too many consecutive errors (' + consecutiveErrors + '), stopping progress tracking');
                            clearInterval(progressInterval);
                            if (elapsedTimeInterval) {
                                clearInterval(elapsedTimeInterval);
                            }
                            $('#aebg-progress-section').removeClass('aebg-progress-running');
                            $('#aebg-progress-header h3').html('Generating Content - Connection Error');
                            return;
                        }
                        
                        // For 503 errors, log but don't spam - these are usually temporary
                        if (xhr && xhr.status === 503) {
                            if (consecutiveErrors === 1) {
                                console.warn('AEBG Debug - Server temporarily unavailable (503). Retrying...');
                            }
                            // Continue polling - 503s are usually temporary
                            return;
                        }
                        
                        // Try to parse response even if there's an error (might be valid JSON with HTTP error)
                        let responseData = null;
                        try {
                            // Clean response text - remove any leading/trailing whitespace or non-JSON content
                            let responseText = (xhr && xhr.responseText) ? xhr.responseText.trim() : '';
                            
                            // Try to extract JSON if there's extra content (e.g., PHP warnings before JSON)
                            if (responseText) {
                                const jsonMatch = responseText.match(/\{[\s\S]*\}/);
                                if (jsonMatch) {
                                    responseText = jsonMatch[0];
                                }
                                
                                if (responseText) {
                                    responseData = JSON.parse(responseText);
                                }
                            }
                            
                            // If we successfully parsed, use the data even if HTTP status was error
                            if (responseData && responseData.status) {
                                const processed = parseInt(responseData.processed_items || 0);
                                const failed = parseInt(responseData.failed_items || 0);
                                const total = parseInt(responseData.total_items || totalItems);
                                const completed = processed + failed;
                                const currentItem = responseData.current_item || null;
                                
                                // Track progress for ETA calculation
                                const currentTime = Date.now();
                                if (progressHistory.length === 0 || progressHistory[progressHistory.length - 1].completed !== completed) {
                                    progressHistory.push({
                                        completed: completed,
                                        timestamp: currentTime
                                    });
                                    if (progressHistory.length > 10) {
                                        progressHistory.shift();
                                    }
                                }
                                
                                const validFailed = (failed > 0 && completed > 0) ? failed : 0;
                                const aiModel = responseData.ai_model || 'gpt-3.5-turbo';
                                const eta = calculateETA(completed, total, progressHistory, generationStartTime, aiModel);
                                
                                const processingItems = responseData.processing_items || [];
                                const failedItemsDetail = responseData.failed_items_detail || [];
                                updateProgress(completed, processed, validFailed, total, responseData.status || 'in_progress', currentItem, eta, processingItems, failedItemsDetail);
                                
                                // Successfully recovered from parse error - reset error counter and continue tracking
                                consecutiveErrors = 0;
                                return;
                            }
                        } catch (parseError) {
                            // JSON parsing failed - log detailed error for debugging
                            console.error('AEBG Debug - Progress tracking error:', {
                                status: xhr ? xhr.status : 'unknown',
                                statusText: status || 'unknown',
                                error: error || 'unknown error',
                                responseText: xhr && xhr.responseText ? xhr.responseText.substring(0, 200) : 'No response',
                                parseError: parseError ? parseError.message : 'Unknown parse error'
                            });
                        }
                        
                        // If we couldn't recover, don't stop tracking immediately
                        // The next poll might succeed - only stop after multiple consecutive failures
                        // For now, just log and continue - the backend fixes should prevent this
                    }
                });
            }, 2000); // Check every 2 seconds for faster updates
        }
        
    /**
     * Format elapsed time in seconds to a human-readable string
     */
    function formatElapsedTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        let timeString = '';
        if (hours > 0) {
            timeString = hours + 'h ' + minutes + 'm ' + secs + 's';
        } else if (minutes > 0) {
            timeString = minutes + 'm ' + secs + 's';
        } else {
            timeString = secs + 's';
        }
        return timeString;
    }
    
    /**
     * Update elapsed time display with a specific elapsed time in seconds
     */
    function updateElapsedTimeDisplay(elapsedSeconds) {
        const timeString = formatElapsedTime(elapsedSeconds);
        
        // Update elapsed time display
        let elapsedTimeElement = $('#aebg-elapsed-time');
        if (elapsedTimeElement.length === 0) {
            // Create element if it doesn't exist
            $('#aebg-progress-stats').after('<span id="aebg-elapsed-time" class="aebg-elapsed-time"></span>');
            elapsedTimeElement = $('#aebg-elapsed-time');
        }
        elapsedTimeElement.text('⏱️ ' + timeString);
    }
    
    /**
     * Update elapsed time display (called every second)
     * Uses backend elapsed time if available, otherwise calculates from generationStartTime
     */
    function updateElapsedTime() {
        // CRITICAL: Prefer backend elapsed time (based on batch creation time)
        // This ensures we show total batch elapsed time, not per-post time
        if (lastBackendElapsedTime > 0 && lastBackendElapsedTimeUpdate !== null) {
            // Calculate elapsed time since last backend update and add to backend time
            // This allows smooth incrementing even when backend value hasn't changed yet
            const timeSinceLastUpdate = Math.floor((Date.now() - lastBackendElapsedTimeUpdate) / 1000);
            const totalElapsed = lastBackendElapsedTime + timeSinceLastUpdate;
            
            // CRITICAL: Ensure we never show a time less than the backend time
            // This prevents the display from going backwards if there's a timing issue
            const displayTime = Math.max(totalElapsed, lastBackendElapsedTime);
            
            updateElapsedTimeDisplay(displayTime);
            return;
        }
        
        // Fallback: Use generationStartTime if backend time not available yet
        if (!generationStartTime) {
            return;
        }
        
        const elapsed = Math.floor((Date.now() - generationStartTime) / 1000); // seconds
        updateElapsedTimeDisplay(elapsed);
    }
    
    /**
     * Update ETA display
     */
    function updateETADisplay(eta, remaining, status) {
        let etaElement = $('#aebg-eta-time');
        
        // Show ETA for both 'in_progress' and 'scheduled' statuses
        const shouldShowEta = (status === 'in_progress' || status === 'scheduled') && remaining > 0;
        
        if (!shouldShowEta) {
            // Remove ETA when not in progress/scheduled or completed
            if (etaElement.length > 0) {
                etaElement.remove();
            }
            return;
        }
        
        if (etaElement.length === 0) {
            // Create element if it doesn't exist
            $('#aebg-elapsed-time').after('<span id="aebg-eta-time" class="aebg-eta-time"></span>');
            etaElement = $('#aebg-eta-time');
        }
        
        // Always show ETA if we're in progress and have remaining items
        // Calculate fallback ETA if not provided
        if (eta === null || eta <= 0) {
            // Calculate fallback ETA based on base estimate
            if (generationStartTime && remaining > 0) {
                const elapsed = (Date.now() - generationStartTime) / 1000;
                const baseEstimatePerArticle = (134 / 7) * numProductsPerArticle;
                const totalEstimate = baseEstimatePerArticle * remaining;
                const fallbackEta = Math.max(0, Math.ceil(totalEstimate - elapsed));
                
                if (fallbackEta > 0) {
                    const etaFormatted = formatTimeEstimate(fallbackEta);
                    etaElement.text('⏳ ~' + etaFormatted + ' remaining');
                } else {
                    etaElement.text('⏳ Almost done...');
                }
            } else {
                etaElement.text('⏳ Calculating...');
            }
        } else {
            const etaFormatted = formatTimeEstimate(eta);
            etaElement.text('⏳ ~' + etaFormatted + ' remaining');
        }
    }

    /**
     * Calculate estimated time to completion (ETA)
     * Uses model-specific base estimates:
     * - GPT-3.5: 2m 14s (134 seconds) for 7 products = ~19.14 seconds per product
     * - GPT-4: ~4x slower = ~76.56 seconds per product (GPT-4 requests take 7-8s vs 1-2s for GPT-3.5)
     */
    function calculateETA(completed, total, history, startTime, aiModel) {
        const remaining = total - completed;
        
        // Determine if GPT-4 model (check for 'gpt-4' or 'gpt4' in model name)
        const isGPT4 = aiModel && (aiModel.toLowerCase().indexOf('gpt-4') !== -1 || aiModel.toLowerCase().indexOf('gpt4') !== -1);
        
        // Base estimates per product (model-specific)
        // GPT-3.5: 2m 14s (134 seconds) for 7 products = ~19.14 seconds per product
        // GPT-4: ~4x slower = ~76.56 seconds per product
        const BASE_TIME_7_PRODUCTS_GPT35 = 134; // seconds for GPT-3.5
        const BASE_TIME_7_PRODUCTS_GPT4 = 536; // seconds for GPT-4 (4x slower)
        const BASE_PRODUCT_COUNT = 7;
        
        const baseTime7Products = isGPT4 ? BASE_TIME_7_PRODUCTS_GPT4 : BASE_TIME_7_PRODUCTS_GPT35;
        const SECONDS_PER_PRODUCT = baseTime7Products / BASE_PRODUCT_COUNT; // ~19.14 for GPT-3.5, ~76.56 for GPT-4
        
        // Calculate base estimate based on current product count
        const baseEstimatePerArticle = SECONDS_PER_PRODUCT * numProductsPerArticle;
        
        // Calculate elapsed time
        const elapsed = startTime ? (Date.now() - startTime) / 1000 : 0; // seconds
        
        // If nothing completed yet, use base estimate but subtract elapsed time
        if (completed === 0) {
            if (remaining > 0 && startTime) {
                const totalEstimate = baseEstimatePerArticle * remaining;
                const adjustedEstimate = Math.max(0, totalEstimate - elapsed);
                // Always return a value (even if 0) so ETA shows immediately
                return Math.ceil(adjustedEstimate);
            }
            // Fallback: return base estimate if no start time yet
            if (remaining > 0) {
                return Math.ceil(baseEstimatePerArticle * remaining);
            }
            return null;
        }
        
        // Calculate average time per item from history
        let totalTimeForItems = 0;
        let itemsProcessed = 0;
        
        for (let i = 1; i < history.length; i++) {
            const timeDiff = history[i].timestamp - history[i - 1].timestamp;
            const itemsDiff = history[i].completed - history[i - 1].completed;
            
            if (itemsDiff > 0 && timeDiff > 0) {
                // Calculate time per item
                const timePerItem = timeDiff / itemsDiff;
                totalTimeForItems += timePerItem;
                itemsProcessed++;
            }
        }
        
        // If we have enough historical data (at least 2 data points), use it
        if (itemsProcessed > 0 && remaining > 0) {
            const avgTimePerItem = totalTimeForItems / itemsProcessed;
            const estimatedMs = avgTimePerItem * remaining;
            const estimatedSeconds = Math.ceil(estimatedMs / 1000);
            
            // Blend with base estimate (70% historical, 30% base) for more stable estimates
            const blendedEstimate = Math.ceil(estimatedSeconds * 0.7 + (baseEstimatePerArticle * remaining) * 0.3);
            
            // Account for elapsed time - if we've been running longer than expected, adjust
            // Calculate expected time for completed items
            const expectedTimeForCompleted = baseEstimatePerArticle * completed;
            const timeDifference = elapsed - expectedTimeForCompleted;
            
            // If we're running slower than expected, add the difference to the estimate
            // If we're running faster, subtract it (but don't go negative)
            const adjustedEstimate = timeDifference > 0 
                ? blendedEstimate + Math.ceil(timeDifference * 0.5) // Add 50% of the delay
                : Math.max(0, blendedEstimate + Math.ceil(timeDifference * 0.3)); // Subtract 30% of the speedup
            
            return Math.max(0, adjustedEstimate);
        }
        
        // Fallback: use overall rate if available
        if (startTime && completed > 0 && history.length >= 2) {
            const rate = completed / elapsed; // items per second
            if (rate > 0 && remaining > 0) {
                const estimatedSeconds = remaining / rate;
                // Blend with base estimate
                const blendedEstimate = Math.ceil(estimatedSeconds * 0.7 + (baseEstimatePerArticle * remaining) * 0.3);
                return Math.max(0, blendedEstimate);
            }
        }
        
        // Final fallback: use base estimate adjusted for elapsed time
        if (remaining > 0) {
            const totalEstimate = baseEstimatePerArticle * remaining;
            const adjustedEstimate = Math.max(0, totalEstimate - elapsed);
            return Math.ceil(adjustedEstimate);
        }
        
        return null;
    }
    
    /**
     * Format time estimate in human-readable format
     */
    function formatTimeEstimate(seconds) {
        if (seconds < 60) {
            return seconds + 's';
        } else if (seconds < 3600) {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return minutes + 'm' + (secs > 0 ? ' ' + secs + 's' : '');
        } else {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            return hours + 'h' + (minutes > 0 ? ' ' + minutes + 'm' : '');
        }
    }

    /**
     * Update progress display
     * @param {number} completed - Total completed items
     * @param {number} processed - Successfully processed items
     * @param {number} failed - Failed items
     * @param {number} total - Total items
     * @param {string} status - Batch status
     * @param {object} currentItem - Current item being processed (for backward compatibility)
     * @param {number} eta - Estimated time remaining
     * @param {array} processingItems - Array of all items currently being processed
     */
    function updateProgress(completed, processed, failed, total, status, currentItem, eta, processingItems, failedItemsDetail) {
        // Default to empty array if not provided
        processingItems = processingItems || [];
        failedItemsDetail = failedItemsDetail || [];
        const remaining = total - completed;
        
        // CRITICAL: Determine if batch is actually active based on status AND completion count
        // A batch is active if: status is in_progress/processing/pending/scheduled OR if items are being processed (completed > 0 or items exist)
        // This must be defined FIRST before it's used below
        const isActiveStatus = ['in_progress', 'processing', 'pending', 'scheduled'].includes(status);
        const hasActiveItems = completed > 0 || (currentItem && currentItem.title) || (processingItems && processingItems.length > 0);
        const isActuallyActive = isActiveStatus || hasActiveItems;
        
        // Calculate progress based on actual completion
        const actualPercentage = total > 0 ? (completed / total) * 100 : 0;
        
        // Calculate progress based on elapsed time vs estimated time
        let timeBasedPercentage = 0;
        // Use isActuallyActive for progress calculation
        if (isActuallyActive && generationStartTime) {
            const elapsed = (Date.now() - generationStartTime) / 1000; // seconds
            const baseEstimatePerArticle = (134 / 7) * numProductsPerArticle; // Base time per article
            const totalEstimatedTime = baseEstimatePerArticle * total; // Total estimated time for all items
            
            if (totalEstimatedTime > 0) {
                // Calculate progress based on elapsed time
                // Cap at 85% to prevent showing near-completion before items actually complete
                timeBasedPercentage = Math.min(85, (elapsed / totalEstimatedTime) * 100);
            }
        }
        
        // Blend actual completion with time-based progress
        // Prioritize actual completion - never show 100% until items actually complete
        let percentage = actualPercentage;
        if (completed === 0 && timeBasedPercentage > 0) {
            // Before any items complete, use time-based progress (capped at 85%)
            percentage = timeBasedPercentage;
        } else if (completed > 0) {
            // Once items start completing, blend: 80% actual, 20% time-based
            percentage = (actualPercentage * 0.8) + (timeBasedPercentage * 0.2);
            // Never exceed actual completion percentage by more than 10%
            percentage = Math.min(percentage, actualPercentage + 10);
        }
        
        // Ensure minimum width for animation visibility when running
        // Cap at 99% max until actually completed
        const maxWidth = (completed === total && total > 0) ? 100 : 99;
        // Use isActuallyActive for minimum width check
        const minWidth = (isActuallyActive && percentage < 2) ? 2 : percentage;
        $('#aebg-progress-bar-inner').css('width', Math.min(maxWidth, minWidth) + '%');
        
        // CRITICAL: Always show progress stats (completed/total) regardless of status
        // Show detailed stats including failed items (only show failed if there are actual failures)
        let statsText = completed + '/' + total + ' completed';
        if (completed > 0) {
            if (processed > 0) {
                statsText += ' (' + processed + ' successful';
                if (failed > 0) {
                    statsText += ', ' + failed + ' failed';
                }
                statsText += ')';
            } else if (failed > 0) {
                // Only show failed if we have completed items
                statsText += ' (' + failed + ' failed)';
            }
        }
        // Show remaining count if batch is active and there are remaining items
        if (remaining > 0 && isActuallyActive) {
            statsText += ' - ' + remaining + ' remaining';
        }
        $('#aebg-progress-stats').text(statsText);
        
        // Update ETA display
        updateETADisplay(eta, remaining, status);
        
        // Update status text to show current item being processed with running indicator
        if (isActuallyActive) {
            // Add pulsing animation class to indicate it's running
            $('#aebg-progress-section').addClass('aebg-progress-running');
            
            if (currentItem && currentItem.title) {
                // Truncate long titles
                const title = currentItem.title.length > 60 
                    ? currentItem.title.substring(0, 57) + '...' 
                    : currentItem.title;
                // Only show failed count if we have completed items
                const failedText = (failed > 0 && completed > 0) ? ' (' + failed + ' failed so far)' : '';
                
                // Add step progress if available
                let stepInfo = '';
                if (currentItem && currentItem.step_progress > 0 && currentItem.total_steps > 0) {
                    const stepDesc = currentItem.step_description || 'Step ' + currentItem.step_progress;
                    stepInfo = ' <span class="aebg-step-progress">(' + stepDesc + ' - ' + currentItem.step_progress + '/' + currentItem.total_steps + ')</span>';
                }
                
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Processing: ' + title + stepInfo + failedText);
            } else if (completed > 0) {
                // Show progress when items are completing - always show completion count
                const failedText = (failed > 0) ? ' (' + failed + ' failed)' : '';
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Generating content... (' + completed + '/' + total + ' completed' + failedText + ')');
            } else if (status === 'in_progress' || status === 'processing') {
                // CRITICAL: If status is in_progress/processing, show that generation is active
                // This fixes the issue where it shows "preparing" even when actively generating
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Generating content...');
            } else if (processingItems && processingItems.length > 0) {
                // CRITICAL: If there are processing items, generation has started even if status is still scheduled/pending
                // This fixes the issue where it shows "preparing" even when actions are running
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Generating content...');
            } else if (failed > 0) {
                // Only show failed if we have completed items
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Generating... (' + failed + ' failed so far)');
            } else if (status === 'scheduled' || status === 'pending') {
                // Show scheduled/pending status only if nothing has started yet (no processing items)
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Scheduled - preparing to generate...');
            } else {
                // Initial state - show that we're starting
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Starting generation...');
            }
        } else {
            // Remove running indicator when not in progress
            $('#aebg-progress-section').removeClass('aebg-progress-running');
            // Show final status
            if (status === 'completed') {
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Generation completed!');
            } else if (status === 'failed') {
                $('#aebg-progress-text').html('<span class="aebg-status-indicator"></span> Generation failed');
            } else {
                $('#aebg-progress-text').text(status || 'Unknown status');
            }
        }
        
        if (completed > 0) {
            $('#aebg-view-live-results').show();
        }
        
        // CRITICAL: Update the "current activity" text below progress bar
        // This shows what's currently happening (different from main status text)
        let currentActivityText = '';
        if (isActuallyActive) {
            if (currentItem && currentItem.title) {
                const title = currentItem.title.length > 50 
                    ? currentItem.title.substring(0, 47) + '...' 
                    : currentItem.title;
                
                // Add step progress if available
                let stepInfo = '';
                if (currentItem && currentItem.step_progress > 0 && currentItem.total_steps > 0) {
                    const stepDesc = currentItem.step_description || 'Step ' + currentItem.step_progress;
                    stepInfo = ' (' + stepDesc + ' - ' + currentItem.step_progress + '/' + currentItem.total_steps + ')';
                }
                
                currentActivityText = 'Generating: ' + title + stepInfo;
            } else if (completed > 0) {
                currentActivityText = 'Generating content... (' + completed + '/' + total + ' completed' + (failed > 0 ? ', ' + failed + ' failed' : '') + ')';
            } else if (status === 'in_progress' || status === 'processing') {
                // CRITICAL: If status is in_progress/processing, show that generation is active
                currentActivityText = 'Generating content...';
            } else if (processingItems && processingItems.length > 0) {
                // CRITICAL: If there are processing items, generation has started even if status is still scheduled/pending
                // This fixes the issue where it shows "preparing" even when actions are running
                currentActivityText = 'Generating content...';
            } else {
                currentActivityText = 'Preparing to generate...';
            }
        } else if (status === 'completed') {
            currentActivityText = 'All articles generated successfully!';
        } else if (status === 'failed') {
            currentActivityText = 'Generation failed';
        } else {
            currentActivityText = 'Waiting...';
        }
        
        // Update the current activity element if it exists
        const currentActivityElement = $('#aebg-current-activity');
        if (currentActivityElement.length > 0) {
            currentActivityElement.text(currentActivityText);
        }
        
        // CRITICAL: Display all processing items with their current steps
        const processingItemsList = $('#aebg-processing-items-list');
        const processingItemsContent = $('#aebg-processing-items-content');
        
        if (processingItemsList.length > 0 && processingItemsContent.length > 0) {
            if (processingItems && processingItems.length > 0 && isActuallyActive) {
                // Build HTML for all processing items
                let itemsHtml = '';
                processingItems.forEach(function(item) {
                    const title = item.title || 'Untitled Post';
                    const truncatedTitle = title.length > 50 ? title.substring(0, 47) + '...' : title;
                    
                    let stepInfo = '';
                    // CRITICAL: Show step info if we have current_step, even if step_progress is 0
                    // This ensures we show the step as soon as it's scheduled
                    if (item.current_step) {
                        const stepDesc = item.step_description || 'Step ' + (item.step_progress || 0);
                        const stepNum = item.step_progress || 0;
                        const totalSteps = item.total_steps || 12;
                        if (stepNum > 0) {
                            stepInfo = ' - <span class="aebg-step-badge">' + stepDesc + ' (' + stepNum + '/' + totalSteps + ')</span>';
                        } else {
                            stepInfo = ' - <span class="aebg-step-badge">' + stepDesc + ' (Starting...)</span>';
                        }
                    } else if (item.current_step) {
                        // Step is set but no description yet - show step key
                        stepInfo = ' - <span class="aebg-step-badge">' + item.current_step + ' (Starting...)</span>';
                    } else {
                        stepInfo = ' - <span class="aebg-step-badge">Preparing...</span>';
                    }
                    
                    itemsHtml += '<div class="aebg-processing-item-row">';
                    itemsHtml += '<span class="aebg-item-title">' + truncatedTitle + '</span>';
                    itemsHtml += stepInfo;
                    itemsHtml += '</div>';
                });
                
                processingItemsContent.html(itemsHtml);
                processingItemsList.show();
            } else {
                // Hide the list if no items are processing
                processingItemsList.hide();
                processingItemsContent.html('');
            }
        }
        
        // CRITICAL: Display all failed items with their error messages
        const failedItemsList = $('#aebg-failed-items-list');
        const failedItemsContent = $('#aebg-failed-items-content');
        const failedCountElement = $('#aebg-failed-count');
        
        if (failedItemsList.length > 0 && failedItemsContent.length > 0) {
            if (failedItemsDetail && failedItemsDetail.length > 0) {
                // Update failed count
                if (failedCountElement.length > 0) {
                    failedCountElement.text('(' + failedItemsDetail.length + ')');
                }
                
                // Build HTML for all failed items
                let failedItemsHtml = '';
                failedItemsDetail.forEach(function(item) {
                    const title = item.title || 'Untitled Post';
                    const truncatedTitle = title.length > 60 ? title.substring(0, 57) + '...' : title;
                    const errorMsg = item.error_message || 'Unknown error';
                    const truncatedError = errorMsg.length > 150 ? errorMsg.substring(0, 147) + '...' : errorMsg;
                    
                    failedItemsHtml += '<div class="aebg-failed-item-row">';
                    failedItemsHtml += '<div class="aebg-failed-item-title">';
                    failedItemsHtml += '<span class="aebg-icon">❌</span>';
                    failedItemsHtml += '<strong>' + truncatedTitle + '</strong>';
                    failedItemsHtml += '</div>';
                    failedItemsHtml += '<div class="aebg-failed-item-error">' + truncatedError + '</div>';
                    failedItemsHtml += '</div>';
                });
                
                failedItemsContent.html(failedItemsHtml);
                failedItemsList.show();
            } else {
                // Hide the list if no items failed
                failedItemsList.hide();
                failedItemsContent.html('');
                if (failedCountElement.length > 0) {
                    failedCountElement.text('');
                }
            }
        }
    }

    /**
     * Handle generation completion
     */
    function handleGenerationComplete(response, batchId) {
        // Reset generation state
        isGenerating = false;
        
        // Stop elapsed time tracking
        if (elapsedTimeInterval) {
            clearInterval(elapsedTimeInterval);
        }
        generationStartTime = null;
        
        // Re-enable the button immediately on successful completion
        $('#aebg-generate-posts').removeClass('processing').prop('disabled', false);
        
        const processed = parseInt(response.processed_items || 0);
            const failed = parseInt(response.failed_items || 0);
            const total = parseInt(response.total_items || 0);

            if (failed === 0) {
                showMessage('Successfully generated ' + processed + ' posts!', 'success');
                $('#aebg-progress-text').text('Generation completed successfully!');
            } else if (processed === 0) {
                showMessage('Generation failed for all ' + total + ' posts.', 'error');
                $('#aebg-progress-text').text('Generation failed');
            } else {
                showMessage('Generated ' + processed + ' posts successfully, ' + failed + ' failed.', 'warning');
                $('#aebg-progress-text').text('Completed with ' + failed + ' failures');
            }

            // Show view results button
            $('#aebg-view-live-results').show();
            
            // Auto-redirect after 5 seconds
            setTimeout(function() {
                window.location.href = window.location.href + '&batch_id=' + batchId;
            }, 5000);
        }

    /**
     * Show failure notification for a failed item
     * @param {string} title - Title of the failed item
     * @param {string} errorMessage - Error message
     */
    function showFailureNotification(title, errorMessage) {
        // Truncate long titles and messages for notification
        const truncatedTitle = title.length > 50 ? title.substring(0, 47) + '...' : title;
        const truncatedError = errorMessage.length > 100 ? errorMessage.substring(0, 97) + '...' : errorMessage;
        
        // Show a non-intrusive notification
        const notification = $('<div class="aebg-failure-notification">')
            .html('<span class="aebg-icon">⚠️</span> <strong>' + truncatedTitle + '</strong> failed: ' + truncatedError)
            .css({
                'position': 'fixed',
                'top': '20px',
                'right': '20px',
                'background': '#fff3cd',
                'border': '1px solid #ffc107',
                'border-radius': '4px',
                'padding': '12px 16px',
                'box-shadow': '0 2px 8px rgba(0,0,0,0.15)',
                'z-index': '10000',
                'max-width': '400px',
                'font-size': '14px'
            });
        
        $('body').append(notification);
        
        // Auto-remove after 8 seconds
        setTimeout(function() {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 8000);
        
        // Also log to console for debugging
        console.warn('AEBG - Item failed:', truncatedTitle, 'Error:', truncatedError);
    }

    /**
     * Handle generation errors
     */
    function handleGenerationError(error) {
        // Reset generation state
        console.log('AEBG Debug - handleGenerationError called, setting isGenerating = false');
        isGenerating = false;
        
        // Add a small delay before re-enabling the button to prevent rapid clicking
        setTimeout(function() {
            $('#aebg-generate-posts').removeClass('processing').prop('disabled', false);
        }, 1000);
        
        clearInterval(progressInterval);
        if (elapsedTimeInterval) {
            clearInterval(elapsedTimeInterval);
        }
        generationStartTime = null;
        
        // Remove running indicator
        $('#aebg-progress-section').removeClass('aebg-progress-running');
        $('#aebg-progress-header h3').html('Generating Content');
        
        // Convert error to string for display
        let errorMessage = '';
        if (typeof error === 'string') {
            errorMessage = error;
        } else if (error && typeof error === 'object') {
            if (error.message) {
                errorMessage = error.message;
            } else if (error.error) {
                errorMessage = error.error;
            } else {
                errorMessage = JSON.stringify(error);
            }
        } else {
            errorMessage = String(error || 'Unknown error occurred');
        }
        
        showMessage('Generation failed: ' + errorMessage, 'error');
        $('#aebg-progress-text').text('Generation failed');
        
        // Show error modal
        showErrorModal(error);
    }

    /**
     * Cancel generation
     */
    function cancelGeneration() {
        // Reset generation state
        isGenerating = false;
        
        // Re-enable the button immediately on cancellation
        $('#aebg-generate-posts').removeClass('processing').prop('disabled', false);

        if (progressInterval) {
            clearInterval(progressInterval);
        }
        if (elapsedTimeInterval) {
            clearInterval(elapsedTimeInterval);
        }
        generationStartTime = null;
        
        // Remove running indicator
        $('#aebg-progress-section').removeClass('aebg-progress-running');
        $('#aebg-progress-header h3').html('Generating Content');
            
            if (currentBatchId) {
                // Cancel the batch on the server and wait for response
                $.ajax({
                    url: aebg.rest_url + 'batch/' + currentBatchId + '/cancel',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
                    },
                    success: function(response) {
                        console.log('AEBG: Batch cancelled successfully', response);
                        // Clear the current batch ID so it won't be checked again
                        currentBatchId = null;
                    },
                    error: function(xhr, status, error) {
                        console.error('AEBG: Error cancelling batch:', error);
                        // Still clear the batch ID and hide progress even if cancel fails
                        currentBatchId = null;
                    },
                    complete: function() {
                        // Always hide progress and show form after cancel attempt
                        showMessage('Generation cancelled.', 'info');
                        $('#aebg-progress-section').hide();
                        $('#aebg-generate-section').show();
                    }
                });
            } else {
                // No batch ID, just hide the progress section
                showMessage('Generation cancelled.', 'info');
                $('#aebg-progress-section').hide();
                $('#aebg-generate-section').show();
            }
        }

    /**
     * Check for active batch on page load and resume tracking if found
     * @param {number} retryCount - Number of retries attempted (for internal use)
     */
    function checkActiveBatch(retryCount) {
        retryCount = retryCount || 0;
        const maxRetries = 3; // Increased from 2 to 3 for better reliability
        
        // Check if DOM is ready
        if (!$('#aebg-progress-section').length) {
            console.warn('AEBG: DOM not ready yet, retrying...');
            if (retryCount < maxRetries) {
                setTimeout(function() {
                    checkActiveBatch(retryCount + 1);
                }, 200);
                return;
            }
        }
        
        // Check if REST API URL is available
        if (typeof aebg === 'undefined' || !aebg.rest_url) {
            console.error('AEBG: REST API URL not available. Cannot check for active batch.');
            console.error('AEBG: aebg object:', typeof aebg !== 'undefined' ? aebg : 'undefined');
            if (retryCount < maxRetries) {
                // Retry with exponential backoff
                const delay = Math.min(500 * Math.pow(2, retryCount), 2000);
                console.log('AEBG: Retrying active batch check in ' + delay + 'ms...');
                setTimeout(function() {
                    checkActiveBatch(retryCount + 1);
                }, delay);
            } else {
                console.error('AEBG: Max retries reached. REST API variables not available.');
            }
            return;
        }
        
        // Add cache-busting timestamp to prevent browser/server caching
        const cacheBuster = '?_=' + Date.now();
        const apiUrl = aebg.rest_url + 'batch/active' + cacheBuster;
        console.log('AEBG: Checking for active batch at:', apiUrl, '(attempt ' + (retryCount + 1) + ')');
        console.log('AEBG: REST nonce available:', typeof aebg.rest_nonce !== 'undefined' ? 'Yes' : 'No');
        
        // Check if there's an active batch for the current user
        $.ajax({
            url: apiUrl,
            method: 'GET',
            cache: false, // Prevent jQuery from caching the response
            timeout: 10000, // 10 second timeout
            beforeSend: function(xhr) {
                if (aebg.rest_nonce) {
                    xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
                }
            },
            success: function(response) {
                console.log('AEBG: Active batch check response (raw):', response);
                console.log('AEBG: Response type:', typeof response);
                console.log('AEBG: Response keys:', response && typeof response === 'object' ? Object.keys(response) : 'N/A');
                
                // CRITICAL: WordPress REST API can return data in different formats:
                // 1. Direct object: { active: true, batch_id: 123, ... }
                // 2. Wrapped in 'data': { data: { active: true, batch_id: 123, ... } }
                // 3. jQuery may parse JSON automatically
                let responseData = response;
                
                // Unwrap WordPress REST API response format
                if (response && typeof response === 'object') {
                    // Check if response has 'data' property (WordPress REST API format)
                    if ('data' in response && response.data !== null && typeof response.data === 'object') {
                        responseData = response.data;
                        console.log('AEBG: Unwrapped response.data');
                    }
                    // Check if response itself has the expected properties
                    else if ('active' in response || 'batch_id' in response) {
                        // Response is already in the correct format
                        responseData = response;
                        console.log('AEBG: Response already in correct format');
                    } else {
                        // Try to find nested data
                        console.log('AEBG: ⚠️ Response format unexpected, trying to find nested data...');
                        // Sometimes WordPress wraps it differently
                        for (let key in response) {
                            if (response[key] && typeof response[key] === 'object' && ('active' in response[key] || 'batch_id' in response[key])) {
                                responseData = response[key];
                                console.log('AEBG: Found nested data in key:', key);
                                break;
                            }
                        }
                    }
                }
                
                console.log('AEBG: Processed responseData:', responseData);
                console.log('AEBG: responseData.active:', responseData ? responseData.active : 'N/A', '(type:', typeof (responseData ? responseData.active : null), ')');
                console.log('AEBG: responseData.batch_id:', responseData ? responseData.batch_id : 'N/A');
                console.log('AEBG: responseData.status:', responseData ? responseData.status : 'N/A');
                
                // Check if we have an active batch
                // Handle boolean true, string 'true', number 1, and string '1'
                const activeValue = responseData ? responseData.active : false;
                const isActive = activeValue === true || activeValue === 'true' || activeValue === 1 || activeValue === '1';
                const batchId = responseData ? (responseData.batch_id || responseData.batchId) : null;
                
                if (isActive && batchId) {
                    console.log('AEBG: ✅ Found active batch on page load - ID: ' + batchId);
                    
                    // Get settings from the batch to determine products per article
                    // For now, use default of 7 (can be improved later to store in batch settings)
                    const productsPerArticle = 7;
                    const totalItems = responseData.total_items || responseData.totalItems || 16; // Default to 16 if not provided
                    const elapsedTime = parseInt(responseData.elapsed_time || responseData.elapsedTime || 0); // Get elapsed time from backend
                    
                    console.log('AEBG: Starting progress tracking with batch_id:', batchId, 'totalItems:', totalItems, 'elapsedTime:', elapsedTime);
                    
                    // CRITICAL: Show progress section immediately and ensure it's visible
                    const progressSection = $('#aebg-progress-section');
                    const generateSection = $('#aebg-generate-section');
                    
                    if (progressSection.length === 0) {
                        console.error('AEBG: ❌ Progress section element not found in DOM!');
                        console.error('AEBG: Available elements:', {
                            progressSection: progressSection.length,
                            generateSection: generateSection.length
                        });
                    } else {
                        console.log('AEBG: Showing progress section...');
                        // Use multiple methods to ensure visibility
                        progressSection.show();
                        progressSection.css('display', 'block'); // Force block display
                        progressSection.removeAttr('style'); // Remove inline styles that might hide it
                        progressSection.show(); // Show again to be sure
                        
                        if (generateSection.length > 0) {
                            generateSection.hide();
                        }
                        
                        // Verify visibility
                        const isVisible = progressSection.is(':visible');
                        const displayValue = progressSection.css('display');
                        console.log('AEBG: Progress section visibility:', isVisible, 'display:', displayValue);
                        
                        if (!isVisible) {
                            console.error('AEBG: ⚠️ Progress section still not visible after show()!');
                            console.error('AEBG: Computed styles:', {
                                display: displayValue,
                                visibility: progressSection.css('visibility'),
                                opacity: progressSection.css('opacity'),
                                height: progressSection.css('height')
                            });
                        }
                    }
                    
                    // Start tracking the existing batch with elapsed time from backend
                    startProgressTracking(batchId, totalItems, productsPerArticle, elapsedTime);
                    
                    // Set current batch ID for cancel/view results functionality
                    currentBatchId = batchId;
                    
                    // Set isGenerating flag to prevent duplicate checks
                    isGenerating = true;
                } else {
                    console.log('AEBG: ❌ No active batch found on page load.');
                    console.log('AEBG: isActive:', isActive, 'activeValue:', activeValue, 'batchId:', batchId);
                    console.log('AEBG: Full responseData:', JSON.stringify(responseData, null, 2));
                    // Ensure progress section is hidden if no active batch
                    $('#aebg-progress-section').hide();
                    $('#aebg-generate-section').show();
                    currentBatchId = null;
                    isGenerating = false;
                }
            },
            error: function(xhr, status, error) {
                const errorDetails = {
                    status: status,
                    error: error,
                    statusCode: xhr ? xhr.status : 'unknown',
                    responseText: xhr && xhr.responseText ? xhr.responseText.substring(0, 500) : 'No response',
                    readyState: xhr ? xhr.readyState : 'unknown'
                };
                
                console.error('AEBG: ❌ Error checking for active batch:', errorDetails);
                
                // Handle specific error codes
                if (xhr && xhr.status === 403) {
                    console.error('AEBG: ⚠️ Permission denied (403). User may not have aebg_generate_content capability.');
                    console.error('AEBG: This might prevent active batch detection. Check user capabilities.');
                } else if (xhr && xhr.status === 404) {
                    console.error('AEBG: ⚠️ REST API endpoint not found (404). Check route registration.');
                } else if (xhr && xhr.status === 0) {
                    console.error('AEBG: ⚠️ Network error (0). Check internet connection or CORS settings.');
                }
                
                // Retry if we haven't exceeded max retries and it's a network/server error
                if (retryCount < maxRetries && xhr && (xhr.status === 0 || xhr.status >= 500)) {
                    const delay = Math.min(1000 * Math.pow(2, retryCount), 5000);
                    console.log('AEBG: Retrying active batch check in ' + delay + 'ms...');
                    setTimeout(function() {
                        checkActiveBatch(retryCount + 1);
                    }, delay);
                    return;
                }
                
                // For permission errors (403), don't retry but log the issue
                if (xhr && xhr.status === 403) {
                    console.error('AEBG: ⚠️ Permission error - not retrying. User needs aebg_generate_content capability.');
                }
                
                // Don't show error to user - just silently fail and ensure form is visible
                // This prevents disrupting the user experience if the check fails
                $('#aebg-progress-section').hide();
                $('#aebg-generate-section').show();
                currentBatchId = null;
                isGenerating = false;
            }
        });
    }

    /**
     * Initialize generation process event handlers
     */
    function initGenerationProcess() {
        // Start generation
        $('#aebg-generate-posts').on('click', function(e) {
            // Prevent duplicate submissions
            if ($(this).hasClass('processing')) {
                e.preventDefault();
                return false;
            }
            
            if (validateForm()) {
                $(this).addClass('processing').prop('disabled', true);
                startGeneration();
            }
        });

        // Cancel generation
        $('#aebg-cancel-generation').on('click', function() {
            if (confirm('Are you sure you want to cancel the generation? This cannot be undone.')) {
                cancelGeneration();
            }
        });

        // View live results
        $('#aebg-view-live-results').on('click', function() {
            if (currentBatchId) {
                window.location.href = window.location.href + '&batch_id=' + currentBatchId;
            }
        });
    }

    /**
     * Collect form data with bulletproof error handling
     */
    function collectFormData() {
        try {
            // Safely get titles with comprehensive validation
            const titlesElement = $('#aebg-titles');
            let titles = [];
            
            if (titlesElement.length > 0) {
                const titlesValue = titlesElement.val();
                if (titlesValue && typeof titlesValue === 'string' && titlesValue.trim() !== '') {
                    titles = titlesValue.split('\n').filter(function(title) {
                        return title && typeof title === 'string' && title.trim() !== '';
                    });
                }
            }
            
            // Safely get all form settings with comprehensive validation
            const settings = {
                template_id: getSafeFormValue('#aebg-template', ''),
                post_type: getSafeFormValue('#aebg-post-type', 'post'),
                post_status: getSafeFormValue('#aebg-post-status', 'draft'),
                num_products: validateNumericParameter(getSafeFormValue('#aebg-num-products', '1'), 1, 50, 5),
                ai_model: validateAIModel(getSafeFormValue('#aebg-ai-model', 'gpt-3.5-turbo')),
                creativity: validateNumericParameter(getSafeFormValue('#aebg-creativity', '0.7'), 0.0, 1.0, 0.5),
                content_length: validateNumericParameter(getSafeFormValue('#aebg-content-length', '1500'), 100, 5000, 1000),
                include_ai_images: getSafeCheckboxValue('#aebg-include-ai-images', false),
                generate_featured_images: getSafeCheckboxValue('#aebg-generate-featured-images', false),
                featured_image_style: getSafeFormValue('#aebg-featured-image-style', 'realistic photo'),
                image_model: getSafeFormValue('#aebg-image-model', 'dall-e-3'),
                image_size: getSafeFormValue('#aebg-image-size', '1024x1024'),
                image_quality: (function() {
                    const checked = $('input[name="aebg_image_quality"]:checked');
                    return checked.length > 0 ? checked.val() : 'standard';
                })(),
                auto_categories: getSafeCheckboxValue('#aebg-auto-categories', false),
                auto_tags: getSafeCheckboxValue('#aebg-auto-tags', false),
                include_meta: getSafeCheckboxValue('#aebg-include-meta', false),
                include_schema: getSafeCheckboxValue('#aebg-include-schema', false)
            };

            return {
                titles: titles,
                settings: settings
            };
        } catch (error) {
            console.error('AEBG Error in collectFormData:', error);
            // Return safe defaults
            return {
                titles: [],
                settings: {
                    template_id: '',
                    post_type: 'post',
                    post_status: 'draft',
                    num_products: '1',
                    ai_model: 'gpt-3.5-turbo',
                    creativity: '0.7',
                    content_length: '1500',
                    include_ai_images: false,
                    auto_categories: false,
                    auto_tags: false,
                    include_meta: false,
                    include_schema: false
                }
            };
        }
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
                console.warn(`AEBG: Invalid numeric parameter ${value}, using default ${defaultValue}`);
                return defaultValue;
            }
            return numValue;
        } catch (error) {
            console.error('AEBG Error validating numeric parameter:', error);
            return defaultValue;
        }
    }

    /**
     * Validate AI model parameter
     */
    function validateAIModel(value) {
        const validModels = ['gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'gpt-5.2', 'gpt-5-mini'];
        if (validModels.includes(value)) {
            return value;
        }
        console.warn(`AEBG: Invalid AI model ${value}, using default gpt-3.5-turbo`);
        return 'gpt-3.5-turbo';
    }

    /**
     * Initialize modal handling
     */
    function initModalHandling() {
        // Close modal on close button click
        $('.aebg-modal-close').on('click', function() {
            $(this).closest('.aebg-modal').removeClass('show');
        });

        // Close modal on outside click
        $('.aebg-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).removeClass('show');
            }
        });

        // Close modal on escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.aebg-modal.show').removeClass('show');
            }
        });

        // Variables Modal Handling
        initVariablesModal();
    }

    /**
     * Initialize Variables Modal
     */
    function initVariablesModal() {
        // Wait a bit to ensure DOM is ready
        setTimeout(function() {
            const $modal = $('#aebg-variables-modal');
            const $trigger = $('.aebg-variables-modal-trigger, #aebg-view-variables');
            const $closeBtn = $('#aebg-close-variables-modal');
            const $tabs = $('.aebg-variables-tab');
            const $tabContents = $('.aebg-variables-tab-content');
            const $variableItems = $('.aebg-variable-item');

            // Debug: Check if elements exist
            if ($modal.length === 0) {
                console.warn('AEBG: Variables modal not found in DOM');
                return;
            }
            
            if ($trigger.length === 0) {
                console.warn('AEBG: Variables modal trigger button not found. Looking for: .aebg-variables-modal-trigger, #aebg-view-variables');
                return;
            }

            console.log('AEBG: Variables modal initialized. Found', $trigger.length, 'trigger button(s)');

            // Open modal function
            function openModal() {
                if ($modal.length) {
                    // Remove inline display:none style and add show class
                    $modal.removeAttr('style').addClass('show');
                    $('body').css('overflow', 'hidden');
                    console.log('AEBG: Variables modal opened');
                } else {
                    console.error('AEBG: Variables modal not found when trying to open');
                }
            }

            // Close modal function
            function closeModal() {
                $modal.removeClass('show').css('display', 'none');
                $('body').css('overflow', '');
            }

            // Open modal - use event delegation for reliability
            $(document).off('click', '.aebg-variables-modal-trigger, #aebg-view-variables').on('click', '.aebg-variables-modal-trigger, #aebg-view-variables', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openModal();
            });

            // Also bind directly to found elements
            $trigger.off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openModal();
            });

            // Close modal handlers
            $closeBtn.off('click').on('click', closeModal);
            $modal.find('.aebg-modal-close').off('click').on('click', closeModal);

            // Tab switching
            $tabs.off('click').on('click', function() {
                const tabName = $(this).data('tab');
                
                // Update active tab
                $tabs.removeClass('active');
                $(this).addClass('active');
                
                // Update active content
                $tabContents.removeClass('active');
                $tabContents.filter('[data-content="' + tabName + '"]').addClass('active');
            });

            // Variable copying
            $variableItems.off('click').on('click', function(e) {
                // Don't trigger if clicking the copy button directly
                if ($(e.target).closest('.aebg-variable-copy').length) {
                    return;
                }
                
                const variable = $(this).data('variable');
                copyToClipboard(variable, $(this).find('.aebg-variable-code'));
            });

            // Copy button click
            $variableItems.find('.aebg-variable-copy').off('click').on('click', function(e) {
                e.stopPropagation();
                const $item = $(this).closest('.aebg-variable-item');
                const variable = $item.data('variable');
                copyToClipboard(variable, $item.find('.aebg-variable-code'));
            });

            // Copy to clipboard function
            function copyToClipboard(text, $element) {
            // Create temporary textarea
            const $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    // Visual feedback
                    const originalBg = $element.css('background-color');
                    $element.css({
                        'background-color': '#10b981',
                        'color': 'white',
                        'transition': 'all 0.3s ease'
                    });
                    
                    // Show temporary success message
                    const $success = $('<span class="aebg-copy-success">✓ Copied!</span>');
                    $success.css({
                        position: 'absolute',
                        top: '-30px',
                        left: '50%',
                        transform: 'translateX(-50%)',
                        background: '#10b981',
                        color: 'white',
                        padding: '4px 8px',
                        borderRadius: '4px',
                        fontSize: '11px',
                        whiteSpace: 'nowrap',
                        zIndex: '1000',
                        pointerEvents: 'none'
                    });
                    $element.parent().css('position', 'relative').append($success);
                    
                    setTimeout(function() {
                        $element.css({
                            'background-color': originalBg,
                            'color': ''
                        });
                        $success.fadeOut(200, function() {
                            $(this).remove();
                        });
                    }, 1500);
                } else {
                    showCopyFallback(text);
                }
            } catch (err) {
                showCopyFallback(text);
            }
            
            $temp.remove();
        }

            // Fallback for clipboard API
            function showCopyFallback(text) {
                // Try modern clipboard API
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function() {
                        showMessage('Variable copied to clipboard!', 'success');
                    }).catch(function() {
                        showMessage('Failed to copy. Variable: ' + text, 'error');
                    });
                } else {
                    showMessage('Variable: ' + text, 'info');
                }
            }
        }, 100); // Small delay to ensure DOM is ready
    }

    /**
     * Show error modal
     */
    function showErrorModal(error) {
        const modal = $('#aebg-error-modal');
        const content = $('#aebg-error-content');
        
        // Ensure error is a string
        let errorString = '';
        if (typeof error === 'string') {
            errorString = error;
        } else if (error && typeof error === 'object') {
            // Try to extract meaningful error message from object
            if (error.message) {
                errorString = error.message;
            } else if (error.error) {
                errorString = error.error;
            } else {
                errorString = JSON.stringify(error);
            }
        } else {
            errorString = String(error || 'Unknown error occurred');
        }
        
        // Enhanced error handling for rate limiting
        let errorMessage = errorString;
        let helpText = 'Please check your settings and try again. If the problem persists, contact support.';
        
        // Check for rate limiting errors
        if (errorString.includes('429') || errorString.includes('rate limit') || errorString.includes('Too Many Requests')) {
            errorMessage = 'Rate limit exceeded. The AI service is temporarily limiting requests.';
            helpText = 'Please wait a few minutes before trying again. The system will automatically retry with delays, but you may need to wait longer if you\'ve made many requests recently.';
        } else if (errorString.includes('quota') || errorString.includes('billing')) {
            errorMessage = 'API quota exceeded. Please check your OpenAI account billing.';
            helpText = 'Your OpenAI account may have reached its usage limit. Please check your billing status and add credits if needed.';
        } else if (errorString.includes('Network error')) {
            errorMessage = 'Network connection error. Please check your internet connection.';
            helpText = 'There was a problem connecting to the AI service. Please check your internet connection and try again.';
        }
        
        content.html('<div class="aebg-message error"><span class="aebg-icon">❌</span><strong>Generation Error:</strong> ' + errorMessage + '</div><p>' + helpText + '</p>');
        
        modal.addClass('show');
    }

    /**
     * Initialize keyboard shortcuts
     */
    function initKeyboardShortcuts() {
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + Enter to generate
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                $('#aebg-generate-posts').click();
            }
            
            // Ctrl/Cmd + T to test connection
            if ((e.ctrlKey || e.metaKey) && e.key === 't') {
                e.preventDefault();
                $('#aebg-test-connection').click();
            }
            
            // Ctrl/Cmd + R to view results
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                $('#aebg-view-results').click();
            }
        });
    }

    /**
     * Initialize enhanced checkbox functionality
     */
    function initEnhancedCheckboxes() {
        // Enhanced checkbox interactions
        $('.aebg-checkbox-label input[type="checkbox"]').on('change', function() {
            const label = $(this).closest('.aebg-checkbox-label');
            if ($(this).is(':checked')) {
                label.addClass('checked');
            } else {
                label.removeClass('checked');
            }
        });

        // Initialize checked state for existing checkboxes
        $('.aebg-checkbox-label input[type="checkbox"]:checked').each(function() {
            $(this).closest('.aebg-checkbox-label').addClass('checked');
        });

        // Featured image checkbox functionality
        $('#aebg-generate-featured-images').on('change', function() {
            const isChecked = $(this).is(':checked');
            const $styleGroup = $('#aebg-featured-image-style-group');
            const $modelGroup = $('#aebg-image-model-group');
            const $sizeGroup = $('#aebg-image-size-group');
            const $qualityGroup = $('#aebg-image-quality-group');
            const $estimatesGroup = $('#aebg-image-estimates-group');
            
            if (isChecked) {
                $styleGroup.slideDown(200);
                $modelGroup.slideDown(200);
                $sizeGroup.slideDown(200);
                $qualityGroup.slideDown(200);
                $estimatesGroup.slideDown(200);
                updateImageEstimates();
            } else {
                $styleGroup.slideUp(200);
                $modelGroup.slideUp(200);
                $sizeGroup.slideUp(200);
                $qualityGroup.slideUp(200);
                $estimatesGroup.slideUp(200);
            }
        });

        // Initialize featured image groups visibility
        if ($('#aebg-generate-featured-images').is(':checked')) {
            $('#aebg-featured-image-style-group').show();
            $('#aebg-image-model-group').show();
            $('#aebg-image-size-group').show();
            $('#aebg-image-quality-group').show();
            $('#aebg-image-estimates-group').show();
            // Initialize HD option state based on selected model
            const initialModel = $('#aebg-image-model').val();
            const isNanoBanana = /^nano-banana(-2|-pro)?$/.test(initialModel);
            if (initialModel === 'dall-e-2' || isNanoBanana) {
                const $hdWrapper = $('#aebg-image-quality-hd-wrapper');
                const $hdRadio = $('#aebg-image-quality-hd');
                if ($hdRadio.is(':checked')) {
                    $('#aebg-image-quality-standard').prop('checked', true);
                }
                $hdWrapper.css('opacity', '0.5').css('pointer-events', 'none');
            }
            updateImageEstimates();
        }

        // Image model change - enable/disable HD option for DALL-E 2 and Nano Banana family
        $('#aebg-image-model').on('change', function() {
            const model = $(this).val();
            const $hdWrapper = $('#aebg-image-quality-hd-wrapper');
            const $hdRadio = $('#aebg-image-quality-hd');
            const isNanoBanana = /^nano-banana(-2|-pro)?$/.test(model);
            
            if (model === 'dall-e-2' || isNanoBanana) {
                // DALL-E 2 and Nano Banana models don't use HD tier, switch to standard if HD is selected
                if ($hdRadio.is(':checked')) {
                    $('#aebg-image-quality-standard').prop('checked', true);
                }
                $hdWrapper.css('opacity', '0.5').css('pointer-events', 'none');
            } else {
                $hdWrapper.css('opacity', '1').css('pointer-events', 'auto');
            }
            updateImageEstimates();
        });

        // Image quality change
        $('input[name="aebg_image_quality"]').on('change', function() {
            updateImageEstimates();
        });

        // Image size change
        $('#aebg-image-size').on('change', function() {
            updateImageEstimates();
        });

        // Titles change - update estimates
        $('#aebg-titles').on('input', function() {
            updateImageEstimates();
        });

        /**
         * Update image generation cost and time estimates
         */
        function updateImageEstimates() {
            if (!$('#aebg-generate-featured-images').is(':checked')) {
                return;
            }

            const model = $('#aebg-image-model').val() || 'dall-e-3';
            const quality = $('input[name="aebg_image_quality"]:checked').val() || 'standard';
            const size = $('#aebg-image-size').val() || '1024x1024';
            
            // Get post count
            const titlesValue = $('#aebg-titles').val() || '';
            const titles = titlesValue.split('\n').filter(function(title) {
                return title && typeof title === 'string' && title.trim() !== '';
            });
            const postCount = titles.length;

            // Calculate cost per image
            let costPerImage = 0;
            if (model === 'dall-e-3') {
                costPerImage = quality === 'hd' ? 0.08 : 0.04;
            } else if (model === 'dall-e-2') {
                costPerImage = 0.02;
            } else if (model === 'nano-banana' || model === 'nano-banana-2') {
                costPerImage = 0.02;
            } else if (model === 'nano-banana-pro') {
                costPerImage = 0.04;
            }

            // Calculate time per image (seconds)
            let timePerImage = 15; // Base time
            if (model === 'dall-e-3') {
                timePerImage = quality === 'hd' ? 25 : 15;
            } else if (model === 'dall-e-2') {
                timePerImage = 10;
            } else if (model === 'nano-banana' || model === 'nano-banana-2') {
                timePerImage = 8;
            } else if (model === 'nano-banana-pro') {
                timePerImage = 15;
            }
            // Larger images take slightly longer
            if (size === '1792x1024' || size === '1024x1792') {
                timePerImage += 3;
            }

            // Calculate totals
            const totalCost = costPerImage * postCount;
            const totalTime = timePerImage * postCount;

            // Update UI
            $('#aebg-cost-per-image').text('$' + costPerImage.toFixed(2));
            $('#aebg-total-cost').text('$' + totalCost.toFixed(2));
            $('#aebg-post-count-text').text('(' + postCount + ' post' + (postCount !== 1 ? 's' : '') + ')');
            $('#aebg-time-per-image').text('~' + timePerImage + ' seconds');
            
            // Format total time nicely
            let totalTimeText = '';
            if (totalTime < 60) {
                totalTimeText = '~' + Math.round(totalTime) + ' seconds';
            } else if (totalTime < 3600) {
                const minutes = Math.round(totalTime / 60);
                totalTimeText = '~' + minutes + ' minute' + (minutes !== 1 ? 's' : '');
            } else {
                const hours = Math.floor(totalTime / 3600);
                const minutes = Math.round((totalTime % 3600) / 60);
                totalTimeText = '~' + hours + ' hour' + (hours !== 1 ? 's' : '');
                if (minutes > 0) {
                    totalTimeText += ' ' + minutes + ' minute' + (minutes !== 1 ? 's' : '');
                }
            }
            $('#aebg-total-time').text(totalTimeText);
        }
    }

    /**
     * Initialize template validation
     */
    function initTemplateValidation() {
        const templateSelect = $('#aebg-template');
        const numProductsSlider = $('#aebg-num-products');
        
        // Validate when template changes
        templateSelect.on('change', function() {
            validateTemplateAndProducts();
        });
        
        // Validate when product count changes
        numProductsSlider.on('input', function() {
            // Debounce the validation to avoid too many requests
            clearTimeout(window.templateValidationTimeout);
            window.templateValidationTimeout = setTimeout(function() {
                validateTemplateAndProducts();
            }, 500);
        });
    }

    /**
     * Validate template and product count
     */
    function validateTemplateAndProducts() {
        const templateId = $('#aebg-template').val();
        const productCount = parseInt($('#aebg-num-products').val()) || 0;
        
        // Don't validate if no template is selected
        if (!templateId) {
            hideTemplateValidationError();
            hideTemplateValidationSuccess();
            resetSliderLimits();
            return;
        }
        
        // Don't validate if product count is 0
        if (productCount < 1) {
            hideTemplateValidationError();
            hideTemplateValidationSuccess();
            return;
        }
        
        // Show loading state
        showTemplateValidationLoading();
        
        $.ajax({
            url: aebg_ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_validate_template',
                template_id: templateId,
                product_count: productCount,
                _ajax_nonce: aebg.validate_nonce
            },
            success: function(response) {
                if (response.success) {
                    const validationData = response.data;
                    
                    // Update slider limits based on template requirements
                    updateSliderLimits(validationData.required_count);
                    
                    if (validationData.is_valid) {
                        hideTemplateValidationError();
                        showTemplateValidationSuccess(validationData);
                    } else {
                        showTemplateValidationError(validationData.error_message);
                    }
                } else {
                    showTemplateValidationError('Validation failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Template validation error:', error);
                showTemplateValidationError('Validation failed. Please try again.');
            }
        });
    }

    /**
     * Show template validation loading state
     */
    function showTemplateValidationLoading() {
        hideTemplateValidationError();
        hideTemplateValidationSuccess();
        
        const templateGroup = $('#aebg-template').closest('.aebg-form-group');
        let loadingElement = templateGroup.find('.aebg-validation-loading');
        
        if (loadingElement.length === 0) {
            loadingElement = $('<div class="aebg-validation-loading">🔍 Validating template...</div>');
            templateGroup.append(loadingElement);
        }
        
        loadingElement.show();
    }

    /**
     * Show template validation error
     */
    function showTemplateValidationError(errorMessage) {
        hideTemplateValidationLoading();
        hideTemplateValidationSuccess();
        
        const templateGroup = $('#aebg-template').closest('.aebg-form-group');
        let errorElement = templateGroup.find('.aebg-validation-error');
        
        if (errorElement.length === 0) {
            errorElement = $('<div class="aebg-validation-error"></div>');
            templateGroup.append(errorElement);
        }
        
        errorElement.html(errorMessage).show();
        
        // Add error class to form group
        templateGroup.addClass('aebg-has-error');
        
        // Disable generate button
        $('#aebg-generate-posts').prop('disabled', true).addClass('aebg-btn-disabled');
    }

    /**
     * Show template validation success
     */
    function showTemplateValidationSuccess(validationData) {
        hideTemplateValidationLoading();
        hideTemplateValidationError();
        
        const templateGroup = $('#aebg-template').closest('.aebg-form-group');
        let successElement = templateGroup.find('.aebg-validation-success');
        
        if (successElement.length === 0) {
            successElement = $('<div class="aebg-validation-success"></div>');
            templateGroup.append(successElement);
        }
        
        // Only show success if the selected count matches the required count
        if (validationData.selected_count === validationData.required_count) {
            const successMessage = `✅ Template validated! Template requires ${validationData.required_count} products, you selected ${validationData.selected_count}.`;
            successElement.html(successMessage).removeClass('warning').show();
        } else {
            // Show warning if more products selected than needed
            const warningMessage = `⚠️ Template validated! Template requires ${validationData.required_count} products, you selected ${validationData.selected_count}. Consider reducing to ${validationData.required_count} for optimal results.`;
            successElement.html(warningMessage).addClass('warning').show();
        }
        
        // Remove error class from form group
        templateGroup.removeClass('aebg-has-error');
        
        // Enable generate button
        $('#aebg-generate-posts').prop('disabled', false).removeClass('aebg-btn-disabled');
    }

    /**
     * Hide template validation loading state
     */
    function hideTemplateValidationLoading() {
        $('.aebg-validation-loading').hide();
    }

    /**
     * Hide template validation error
     */
    function hideTemplateValidationError() {
        $('.aebg-validation-error').hide();
        $('.aebg-form-group').removeClass('aebg-has-error');
        $('#aebg-generate-posts').prop('disabled', false).removeClass('aebg-btn-disabled');
    }

    /**
     * Hide template validation success
     */
    function hideTemplateValidationSuccess() {
        $('.aebg-validation-success').hide();
    }

    /**
     * Update slider limits based on template requirements
     */
    function updateSliderLimits(requiredCount) {
        const numProductsSlider = $('#aebg-num-products');
        const currentValue = parseInt(numProductsSlider.val()) || 1;
        
        // Set the max value to the required count
        numProductsSlider.attr('max', requiredCount);
        
        // If current value exceeds the new max, adjust it
        if (currentValue > requiredCount) {
            numProductsSlider.val(requiredCount);
            $('#aebg-num-products-value').text(requiredCount);
            updateSliderBackground(numProductsSlider);
        }
        
        // Update the max label
        const sliderLabels = numProductsSlider.siblings('.aebg-slider-labels');
        const maxLabel = sliderLabels.find('span:last');
        maxLabel.text(requiredCount);
    }

    /**
     * Reset slider limits to default
     */
    function resetSliderLimits() {
        const numProductsSlider = $('#aebg-num-products');
        
        // Reset to default max of 20
        numProductsSlider.attr('max', 20);
        
        // Update the max label
        const sliderLabels = numProductsSlider.siblings('.aebg-slider-labels');
        const maxLabel = sliderLabels.find('span:last');
        maxLabel.text('20');
    }

    /**
     * Show loading overlay
     */
    function showLoadingOverlay() {
        $('#aebg-loading-overlay').addClass('show');
    }

    /**
     * Hide loading overlay
     */
    function hideLoadingOverlay() {
        $('#aebg-loading-overlay').removeClass('show');
    }

    /**
     * Show message
     */
    function showMessage(message, type = 'info') {
        // Remove existing messages
        $('.aebg-message').remove();
        
        const messageHtml = '<div class="aebg-message ' + type + '"><span class="aebg-icon">' + getMessageIcon(type) + '</span>' + message + '</div>';
        
        $('.aebg-generator-header').after(messageHtml);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            $('.aebg-message').fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Get message icon
     */
    function getMessageIcon(type) {
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        return icons[type] || icons.info;
    }

    /**
     * Enhanced form validation with real-time feedback
     */
    function validateFormEnhanced() {
        const errors = [];
        
        // Validate titles
        const titles = $('#aebg-titles').val().trim();
        if (!titles) {
            errors.push('Please enter at least one title');
        } else {
            const titleLines = titles.split('\n').filter(line => line.trim() !== '');
            if (titleLines.length === 0) {
                errors.push('Please enter at least one valid title');
            }
        }
        
        // Validate template
        if (!$('#aebg-template').val()) {
            errors.push('Please select an Elementor template');
        }
        
        // Validate post type
        if (!$('#aebg-post-type').val()) {
            errors.push('Please select a post type');
        }
        
        // Check for template validation errors
        const templateGroup = $('#aebg-template').closest('.aebg-form-group');
        if (templateGroup.hasClass('aebg-has-error')) {
            errors.push('Please fix template validation errors before proceeding');
        }
        
        return errors;
    }

    /**
     * Auto-save form data to localStorage
     */
    function initAutoSave() {
        const formData = collectFormData();
        localStorage.setItem('aebg_generator_form', JSON.stringify(formData));
        
        // Auto-save every 30 seconds
        setInterval(function() {
            const currentData = collectFormData();
            localStorage.setItem('aebg_generator_form', JSON.stringify(currentData));
        }, 30000);
    }

    /**
     * Restore form data from localStorage
     */
    function restoreFormData() {
        const savedData = localStorage.getItem('aebg_generator_form');
        if (savedData) {
            try {
                const data = JSON.parse(savedData);
                
                if (data.titles && data.titles.length > 0) {
                    $('#aebg-titles').val(data.titles.join('\n'));
                }
                
                if (data.settings) {
                    Object.keys(data.settings).forEach(key => {
                        const element = $('[name="aebg_' + key + '"]');
                        if (element.length) {
                            if (element.attr('type') === 'checkbox') {
                                element.prop('checked', data.settings[key]);
                            } else {
                                element.val(data.settings[key]);
                            }
                        }
                    });
                }

                // Clear any duplicate data when restoring - user should check manually if needed
                // This prevents the modal from showing on page load
                window.aebgDuplicateTitles = {};
                $('#aebg-duplicate-warning').hide();
                $('#aebg-duplicate-modal').removeClass('show');
            } catch (e) {
                console.log('Failed to restore form data:', e);
            }
        } else {
            // Even if no saved data, clear any duplicate state
            window.aebgDuplicateTitles = {};
            $('#aebg-duplicate-warning').hide();
            $('#aebg-duplicate-modal').removeClass('show');
        }
    }

    // Initialize auto-save and restore
    initAutoSave();
    restoreFormData();

    /**
     * Initialize duplicate title detection
     */
    function initDuplicateDetection() {
        // Always clear duplicate data on page load to prevent stale state from previous sessions
        // User must manually check for duplicates after entering titles
        window.aebgDuplicateTitles = {};
        $('#aebg-duplicate-warning').hide();
        $('#aebg-duplicate-modal').removeClass('show').css('display', 'none');
        
        // Clear any duplicate data if there are no titles (additional safety check)
        const titlesText = $('#aebg-titles').val();
        if (!titlesText || titlesText.trim() === '') {
            // Ensure duplicate state is cleared when textarea is empty
            window.aebgDuplicateTitles = {};
            $('#aebg-duplicate-warning').hide();
            $('#aebg-duplicate-modal').removeClass('show').css('display', 'none');
        }

        // Check for duplicates button
        $('#aebg-check-duplicates').on('click', function() {
            checkDuplicateTitles();
        });

        // View duplicates link
        $('#aebg-view-duplicates-link').on('click', function(e) {
            e.preventDefault();
            // Only show modal if there are actually duplicates
            if (window.aebgDuplicateTitles && Object.keys(window.aebgDuplicateTitles).length > 0) {
                $('#aebg-duplicate-modal').addClass('show');
            }
        });

        // Remove all duplicates - use event delegation to ensure it works
        $(document).on('click', '#aebg-remove-all-duplicates', function(e) {
            e.preventDefault();
            e.stopPropagation();
            removeAllDuplicates();
        });

        // Keep all duplicates - use event delegation to ensure it works
        $(document).on('click', '#aebg-keep-all-duplicates', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#aebg-duplicate-modal').removeClass('show');
        });

        // Close duplicate modal - use event delegation to ensure it works
        $(document).on('click', '#aebg-close-duplicate-modal', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#aebg-duplicate-modal').removeClass('show');
        });

        // Also handle the modal close button in header (if it exists)
        $(document).on('click', '#aebg-duplicate-modal .aebg-modal-close', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#aebg-duplicate-modal').removeClass('show');
        });
    }

    /**
     * Check for duplicate titles
     */
    function checkDuplicateTitles() {
        const titlesText = $('#aebg-titles').val();
        if (!titlesText || titlesText.trim() === '') {
            // Clear any existing duplicate data and hide warnings
            window.aebgDuplicateTitles = {};
            $('#aebg-duplicate-warning').hide();
            $('#aebg-duplicate-modal').removeClass('show');
            alert('Please enter at least one title before checking for duplicates.');
            return;
        }

        const titles = titlesText.split('\n').filter(function(title) {
            return title && title.trim() !== '';
        }).map(function(title) {
            return title.trim();
        });

        if (titles.length === 0) {
            // Clear any existing duplicate data and hide warnings
            window.aebgDuplicateTitles = {};
            $('#aebg-duplicate-warning').hide();
            $('#aebg-duplicate-modal').removeClass('show');
            alert('Please enter at least one valid title.');
            return;
        }

        const postType = $('#aebg-post-type').val() || 'post';
        const button = $('#aebg-check-duplicates');
        const originalText = button.html();

        // Show loading state
        button.prop('disabled', true).html('<span class="aebg-icon">⏳</span> Checking...');

        // Get nonce - try multiple sources
        const nonce = (typeof aebg !== 'undefined' && aebg.duplicate_check_nonce) 
            ? aebg.duplicate_check_nonce 
            : (typeof aebg_ajax !== 'undefined' && aebg_ajax.nonce) 
                ? aebg_ajax.nonce 
                : (typeof aebg !== 'undefined' && aebg.ajax_nonce) 
                    ? aebg.ajax_nonce 
                    : '';

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_check_duplicate_titles',
                titles: JSON.stringify(titles),
                post_type: postType,
                _ajax_nonce: nonce
            },
            success: function(response) {
                button.prop('disabled', false).html(originalText);

                if (response.success) {
                    const duplicates = response.data.duplicates || {};
                    const duplicateCount = Object.keys(duplicates).length;

                    if (duplicateCount > 0) {
                        displayDuplicateResults(duplicates);
                        $('#aebg-duplicate-count').text(duplicateCount);
                        $('#aebg-duplicate-warning').show();
                    } else {
                        // Clear duplicate state when no duplicates found
                        window.aebgDuplicateTitles = {};
                        $('#aebg-duplicate-warning').hide();
                        $('#aebg-duplicate-modal').removeClass('show');
                        alert('✅ No duplicate titles found! All titles are unique.');
                    }
                } else {
                    // Clear duplicate state on error
                    window.aebgDuplicateTitles = {};
                    $('#aebg-duplicate-warning').hide();
                    $('#aebg-duplicate-modal').removeClass('show');
                    alert('Error checking for duplicates: ' + (response.data.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false).html(originalText);
                // Clear duplicate state on error
                window.aebgDuplicateTitles = {};
                $('#aebg-duplicate-warning').hide();
                $('#aebg-duplicate-modal').removeClass('show');
                alert('Error checking for duplicates: ' + error);
                console.error('Duplicate check error:', xhr, status, error);
            }
        });
    }

    /**
     * Display duplicate results in modal
     */
    function displayDuplicateResults(duplicates) {
        const listContainer = $('#aebg-duplicate-list');
        listContainer.empty();

        // Store duplicates globally for actions
        window.aebgDuplicateTitles = duplicates;

        Object.keys(duplicates).forEach(function(title) {
            const posts = duplicates[title];
            const duplicateItem = $('<div>', {
                class: 'aebg-duplicate-item',
                style: 'padding: 12px; margin-bottom: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;'
            });

            const titleHeader = $('<div>', {
                style: 'font-weight: bold; margin-bottom: 8px; color: #dc3545;'
            }).html('<span class="aebg-icon">⚠️</span> ' + title);

            const postsList = $('<div>', {
                style: 'margin-left: 20px; margin-top: 8px;'
            });

            posts.forEach(function(post) {
                const postInfo = $('<div>', {
                    style: 'margin-bottom: 6px; font-size: 13px; color: #666;'
                });

                const statusBadge = $('<span>', {
                    style: 'display: inline-block; padding: 2px 6px; margin-right: 8px; background: #' + 
                        (post.post_status === 'publish' ? '28a745' : post.post_status === 'draft' ? 'ffc107' : '6c757d') + 
                        '; color: white; border-radius: 3px; font-size: 11px; text-transform: uppercase;'
                }).text(post.post_status);

                const editLink = post.edit_link 
                    ? $('<a>', {
                        href: post.edit_link,
                        target: '_blank',
                        style: 'color: #0073aa; text-decoration: none; margin-right: 8px;'
                    }).text('Edit Post #' + post.post_id)
                    : $('<span>').text('Post #' + post.post_id);

                const date = new Date(post.post_date);
                const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();

                postInfo.append(statusBadge);
                postInfo.append(editLink);
                postInfo.append($('<span>', { style: 'color: #999;' }).text(' • ' + dateStr));

                postsList.append(postInfo);
            });

            const actions = $('<div>', {
                style: 'margin-top: 10px; display: flex; gap: 8px;'
            });

            const removeBtn = $('<button>', {
                type: 'button',
                class: 'aebg-btn aebg-btn-secondary',
                style: 'padding: 4px 8px; font-size: 12px;',
                'data-title': title
            }).html('<span class="aebg-icon">🗑️</span> Remove').on('click', function() {
                removeDuplicateTitle(title);
            });

            const editBtn = $('<button>', {
                type: 'button',
                class: 'aebg-btn aebg-btn-secondary',
                style: 'padding: 4px 8px; font-size: 12px;',
                'data-title': title
            }).html('<span class="aebg-icon">✏️</span> Edit').on('click', function() {
                editDuplicateTitle(title);
            });

            actions.append(removeBtn);
            actions.append(editBtn);

            duplicateItem.append(titleHeader);
            duplicateItem.append(postsList);
            duplicateItem.append(actions);

            listContainer.append(duplicateItem);
        });

        $('#aebg-duplicate-modal').addClass('show');
    }

    /**
     * Remove a duplicate title from textarea
     */
    function removeDuplicateTitle(title) {
        const titlesText = $('#aebg-titles').val();
        const titles = titlesText.split('\n').filter(function(t) {
            return t.trim() !== title.trim();
        });
        $('#aebg-titles').val(titles.join('\n'));

        // Remove from duplicates object
        if (window.aebgDuplicateTitles && window.aebgDuplicateTitles[title]) {
            delete window.aebgDuplicateTitles[title];
        }

        // Update UI
        const remainingCount = Object.keys(window.aebgDuplicateTitles || {}).length;
        if (remainingCount === 0) {
            $('#aebg-duplicate-warning').hide();
            $('#aebg-duplicate-modal').removeClass('show');
        } else {
            $('#aebg-duplicate-count').text(remainingCount);
            displayDuplicateResults(window.aebgDuplicateTitles);
        }
    }

    /**
     * Edit a duplicate title
     */
    function editDuplicateTitle(title) {
        const newTitle = prompt('Edit title:', title);
        if (newTitle && newTitle.trim() !== '' && newTitle.trim() !== title.trim()) {
            const titlesText = $('#aebg-titles').val();
            const titles = titlesText.split('\n').map(function(t) {
                return t.trim() === title.trim() ? newTitle.trim() : t;
            });
            $('#aebg-titles').val(titles.join('\n'));

            // Remove from duplicates object
            if (window.aebgDuplicateTitles && window.aebgDuplicateTitles[title]) {
                delete window.aebgDuplicateTitles[title];
            }

            // Update UI
            const remainingCount = Object.keys(window.aebgDuplicateTitles || {}).length;
            if (remainingCount === 0) {
                $('#aebg-duplicate-warning').hide();
                $('#aebg-duplicate-modal').removeClass('show');
            } else {
                $('#aebg-duplicate-count').text(remainingCount);
                displayDuplicateResults(window.aebgDuplicateTitles);
            }
        }
    }

    /**
     * Remove all duplicate titles
     */
    function removeAllDuplicates() {
        if (!window.aebgDuplicateTitles || Object.keys(window.aebgDuplicateTitles).length === 0) {
            return;
        }

        if (!confirm('Are you sure you want to remove all duplicate titles from the list?')) {
            return;
        }

        const titlesText = $('#aebg-titles').val();
        const duplicateTitles = Object.keys(window.aebgDuplicateTitles);
        const titles = titlesText.split('\n').filter(function(t) {
            return !duplicateTitles.includes(t.trim());
        });
        $('#aebg-titles').val(titles.join('\n'));

        // Clear duplicates
        window.aebgDuplicateTitles = {};

        // Update UI
        $('#aebg-duplicate-warning').hide();
        $('#aebg-duplicate-modal').removeClass('show');
    }
}); 