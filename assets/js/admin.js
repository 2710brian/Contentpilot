jQuery(document).ready(function($) {
    'use strict';

    // Initialize settings page functionality
    if ($('.aebg-settings-container').length) {
        initSettingsPage();
    }

    // Initialize generator page functionality
    if ($('#aebg-generator-form').length) {
        initGeneratorPage();
    }

    // Flag to prevent auto-save during initial page load
    let pageInitialized = false;

    function initSettingsPage() {
        // Ensure all modals are hidden on page load (do this first)
        $('.aebg-modal').removeClass('show');
        
        // Initialize sliders
        initSliders();
        
        // Initialize password toggles
        initPasswordToggles();
        
        // Initialize form handling
        initFormHandling();
        
        // Initialize API testing
        initApiTesting();
        
        // Initialize Datafeedr testing
        initDatafeedrTesting();
        
        // Initialize modal functionality
        initModal();
        
        // Initialize enhanced checkboxes
        initEnhancedCheckboxes();
        
        // Initialize negative phrases management
        initNegativePhrases();
        
        // Mark page as initialized after a short delay to allow all initialization to complete
        setTimeout(function() {
            pageInitialized = true;
        }, 2000);
    }

    // Slider functionality
    function initSliders() {
        // Temperature slider
        const tempSlider = $('#aebg_temperature');
        const tempValue = $('#aebg-temperature-value');
        
        if (tempSlider.length) {
            tempSlider.on('input', function() {
                tempValue.text($(this).val());
            });
            
            // Set initial value
            tempValue.text(tempSlider.val());
        }

        // Top P slider
        const topPSlider = $('#aebg_top_p');
        const topPValue = $('#aebg-top-p-value');
        
        if (topPSlider.length) {
            topPSlider.on('input', function() {
                topPValue.text($(this).val());
            });
            
            // Set initial value
            topPValue.text(topPSlider.val());
        }

        // Similarity threshold slider
        const similaritySlider = $('#aebg_duplicate_similarity_threshold');
        const similarityValue = $('#aebg-similarity-value');
        
        if (similaritySlider.length) {
            similaritySlider.on('input', function() {
                similarityValue.text($(this).val());
            });
            
            // Set initial value
            similarityValue.text(similaritySlider.val());
        }
    }

    // Password toggle functionality
    function initPasswordToggles() {
        $('.aebg-toggle-password').on('click', function() {
            const targetId = $(this).data('target');
            const input = $('#' + targetId);
            const icon = $(this).find('.aebg-icon');
            
            if (input.attr('type') === 'password') {
                input.attr('type', 'text');
                icon.text('🙈');
            } else {
                input.attr('type', 'password');
                icon.text('👁️');
            }
        });
    }

    // Form handling
    function initFormHandling() {
        $('#aebg-save-settings').on('click', function() {
            saveSettings();
        });

        $('#aebg-debug-settings').on('click', function() {
            debugSettings();
        });

        $('#aebg-reset-action-scheduler').on('click', function() {
            resetActionScheduler();
        });

        $('#aebg-trigger-action-scheduler').on('click', function() {
            triggerActionScheduler();
        });

        // Auto-save on input change (with debounce)
        let saveTimeout;
        $('.aebg-input, .aebg-select, .aebg-textarea, .aebg-slider').on('input change', function() {
            // Only auto-save if page has finished initializing
            if (!pageInitialized) {
                return;
            }
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                saveSettings();
            }, 1000);
        });

        // Auto-save on hidden input changes (for network selections)
        $('input[type="hidden"][name^="aebg_settings["]').on('change', function() {
            // Only auto-save if page has finished initializing
            if (!pageInitialized) {
                return;
            }
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                saveSettings();
            }, 1000);
        });
    }

    function saveSettings() {
        // Ensure negative phrases hidden input is up to date before collecting form data
        if ($('#aebg-negative-phrases-list').length) {
            updateNegativePhrasesInput();
        }
        
        const formData = collectFormData();
        
        // Validate required fields
        const errors = [];
        if (!formData.api_key) {
            errors.push('OpenAI API Key is required');
        }
        
        if (formData.enable_datafeedr === '1') {
            if (!formData.datafeedr_access_id) {
                errors.push('Datafeedr Access ID is required when Datafeedr is enabled');
            }
            if (!formData.datafeedr_secret_key) {
                errors.push('Datafeedr Access Key is required when Datafeedr is enabled');
            }
        }
        
        if (errors.length > 0) {
            showMessage('Validation errors: ' + errors.join(', '), 'error');
            return;
        }
        
        // Debug: Log the data being sent
        console.log('Sending settings data:', formData);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_save_settings',
                settings: formData,
                _ajax_nonce: aebg_ajax.nonce
            },
            beforeSend: function() {
                showMessage('Saving settings...', 'info');
            },
            success: function(response) {
                console.log('Settings save response:', response);
                
                if (response.success) {
                    showMessage('Settings saved successfully!', 'success');
                    
                    // Verify the save by checking if we can retrieve the settings
                    setTimeout(function() {
                        console.log('Verifying settings save...');
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'aebg_verify_settings',
                                _ajax_nonce: aebg_ajax.nonce
                            },
                            success: function(verifyResponse) {
                                console.log('Settings verification response:', verifyResponse);
                                if (verifyResponse.success) {
                                    console.log('Settings verified successfully:', verifyResponse.data);
                                } else {
                                    console.error('Settings verification failed:', verifyResponse.data);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Settings verification AJAX error:', {xhr, status, error});
                            }
                        });
                    }, 1000);
                } else {
                    const errorMessage = response.data || 'Unknown error occurred while saving settings';
                    showMessage('Failed to save settings: ' + errorMessage, 'error');
                    console.error('Settings save error:', response);
                }
            },
            error: function(xhr, status, error) {
                console.error('Settings save AJAX error:', {xhr, status, error});
                
                let errorMessage = 'Network error occurred while saving settings';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                } else if (error) {
                    errorMessage = error;
                }
                
                // Log the raw response for debugging
                if (xhr.responseText) {
                    console.error('Raw response:', xhr.responseText);
                }
                
                showMessage('Failed to save settings: ' + errorMessage, 'error');
            }
        });
    }

    function collectFormData() {
        const data = {};
        
        // Collect all form inputs
        $('.aebg-input, .aebg-select, .aebg-textarea, .aebg-slider').each(function() {
            const name = $(this).attr('name');
            if (name) {
                const key = name.replace('aebg_settings[', '').replace(']', '');
                data[key] = $(this).val();
            }
        });

        // Collect hidden inputs (like network selections)
        $('input[type="hidden"][name^="aebg_settings["]').each(function() {
            const name = $(this).attr('name');
            if (name) {
                const key = name.replace('aebg_settings[', '').replace(']', '');
                let value = $(this).val();
                
                // Special handling for default_networks - parse JSON string to array
                if (key === 'default_networks' && value) {
                    try {
                        // Parse the JSON string to get the actual array
                        value = JSON.parse(value);
                        console.log('Parsed default_networks from JSON string to array:', value);
                    } catch (e) {
                        console.error('Failed to parse default_networks JSON:', e);
                        value = []; // Fallback to empty array
                    }
                }
                
                // Special handling for negative_phrases - parse JSON string to array
                if (key === 'negative_phrases' && value) {
                    try {
                        // Parse the JSON string to get the actual array
                        value = JSON.parse(value);
                        console.log('Parsed negative_phrases from JSON string to array:', value);
                    } catch (e) {
                        console.error('Failed to parse negative_phrases JSON:', e);
                        value = []; // Fallback to empty array
                    }
                }
                
                data[key] = value;
                console.log('Hidden input found:', key, 'value:', value);
            }
        });

        // Collect checkboxes - ensure they always have a value
        $('.aebg-checkbox-label input[type="checkbox"]').each(function() {
            const name = $(this).attr('name');
            const isChecked = $(this).is(':checked');
            if (name) {
                const key = name.replace('aebg_settings[', '').replace(']', '');
                data[key] = isChecked ? '1' : '0';
                console.log('Checkbox found:', key, 'checked:', isChecked, 'value:', data[key]);
            }
        });
        
        // Also try a more specific selector for checkboxes
        $('input[type="checkbox"][name^="aebg_settings["]').each(function() {
            const name = $(this).attr('name');
            const isChecked = $(this).is(':checked');
            if (name) {
                const key = name.replace('aebg_settings[', '').replace(']', '');
                data[key] = isChecked ? '1' : '0';
                console.log('Checkbox (alternative selector):', key, 'checked:', isChecked, 'value:', data[key]);
            }
        });

        // Ensure checkboxes have default values if not found
        const expectedCheckboxes = ['enable_datafeedr'];
        expectedCheckboxes.forEach(function(checkboxKey) {
            if (!(checkboxKey in data)) {
                data[checkboxKey] = '0';
                console.log('Setting default value for missing checkbox:', checkboxKey, '=', '0');
            }
        });

        // Debug log for troubleshooting
        if (typeof console !== 'undefined' && console.log) {
            console.log('Collected form data:', data);
        }

        return data;
    }

    function debugSettings() {
        console.log('Debugging settings...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_debug_settings',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                console.log('Debug response:', response);
                if (response.success) {
                    console.log('Debug info:', response.data);
                    alert('Debug info logged to console. Check browser developer tools.');
                } else {
                    console.error('Debug failed:', response.data);
                    alert('Debug failed: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Debug AJAX error:', {xhr, status, error});
                alert('Debug AJAX error: ' + error);
            }
        });
    }

    function resetActionScheduler() {
        if (!confirm('Are you sure you want to reset the Action Scheduler? This will clear all pending and completed tasks. This action cannot be undone.')) {
            return;
        }

        showLoadingOverlay();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_reset_action_scheduler',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                hideLoadingOverlay();
                
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    console.log('Action Scheduler reset:', response.data);
                } else {
                    showMessage('Reset failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoadingOverlay();
                showMessage('Reset request failed. Please check your connection.', 'error');
            }
        });
    }

    function triggerActionScheduler() {
        if (!confirm('This will manually trigger up to 5 pending action scheduler tasks. Continue?')) {
            return;
        }

        showLoadingOverlay();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_trigger_action_scheduler',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                hideLoadingOverlay();
                
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    console.log('Action Scheduler triggered:', response.data);
                } else {
                    showMessage('Trigger failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                hideLoadingOverlay();
                showMessage('Trigger request failed. Please check your connection.', 'error');
            }
        });
    }

    // API Testing functionality
    function initApiTesting() {
        $('#aebg-test-api').on('click', function() {
            testApiConnection();
        });
    }

    function testApiConnection() {
        const apiKey = $('#aebg_api_key').val();
        const model = $('#aebg_model').val();
        
        if (!apiKey) {
            showMessage('Please enter an API key first.', 'warning');
            return;
        }

        // Show loading state
        showLoadingOverlay();
        updateApiStatus('loading', 'Testing connection...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_test_api',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                hideLoadingOverlay();
                
                if (response.success) {
                    updateApiStatus('success', 'Connection successful');
                    // Use the detailed response from the server
                    showApiTestModal(response.data, true);
                } else {
                    updateApiStatus('error', 'Connection failed');
                    showApiTestModal({
                        error: response.data || 'Unknown error',
                        details: 'Please check your API key and try again.'
                    }, false);
                }
            },
            error: function(xhr, status, error) {
                hideLoadingOverlay();
                updateApiStatus('error', 'Connection failed');
                showApiTestModal({
                    error: 'Network error: ' + error,
                    details: 'Please check your internet connection and try again.'
                }, false);
            }
        });
    }

    function updateApiStatus(status, text) {
        const indicator = $('#aebg-status-indicator');
        const statusText = $('#aebg-status-text');
        
        indicator.removeClass('success error loading').addClass(status);
        statusText.text(text);
    }

    // Datafeedr testing functionality
    function initDatafeedrTesting() {
        $('#aebg-test-datafeedr').on('click', function() {
            testDatafeedrConnection();
        });

        // Add dynamic feedback for Datafeedr enable checkbox
        $('#aebg_enable_datafeedr').on('change', function() {
            const isEnabled = $(this).is(':checked');
            const accessId = $('#aebg_datafeedr_access_id').val();
            const accessKey = $('#aebg_datafeedr_secret_key').val();
            
            if (isEnabled) {
                if (!accessId || !accessKey) {
                    showMessage('Please enter both Datafeedr Access ID and Access Key before enabling.', 'warning');
                    $(this).prop('checked', false);
                    return;
                }
                showMessage('Datafeedr integration enabled. You can now test the connection.', 'success');
            } else {
                showMessage('Datafeedr integration disabled.', 'info');
            }
        });
    }

    function testDatafeedrConnection() {
        showLoadingOverlay();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'aebg_test_datafeedr',
                nonce: aebg_ajax.nonce
            },
            success: function(response) {
                hideLoadingOverlay();
                
                if (response.success) {
                    showDatafeedrTestModal({
                        status: 'Connected successfully',
                        message: 'Datafeedr API connection is working properly.',
                        details: 'You can now use Datafeedr for product data enrichment.'
                    }, true);
                } else {
                    showDatafeedrTestModal({
                        error: response.data || 'Unknown error',
                        details: 'Please check your Datafeedr credentials and try again.'
                    }, false);
                }
            },
            error: function(xhr, status, error) {
                hideLoadingOverlay();
                showDatafeedrTestModal({
                    error: 'Network error: ' + error,
                    details: 'Please check your internet connection and try again.'
                }, false);
            }
        });
    }

    function showApiTestModal(results, isSuccess = true) {
        const modal = $('#aebg-api-test-modal');
        const resultsContainer = $('#aebg-api-test-results');
        
        let html = '';
        
        if (isSuccess) {
            html = `
                <div class="aebg-api-test-result success">
                    <h4>✅ API Connection Successful</h4>
                    <p><strong>Model:</strong> ${results.model || 'N/A'}</p>
                    <p><strong>Response Time:</strong> ${results.response_time || 'N/A'}</p>
                    <p><strong>Status:</strong> ${results.status || 'N/A'}</p>
                    ${results.usage ? `<p><strong>Usage:</strong> ${results.usage}</p>` : ''}
                </div>
            `;
        } else {
            html = `
                <div class="aebg-api-test-result error">
                    <h4>❌ API Connection Failed</h4>
                    <p><strong>Error:</strong> ${results.error || 'Unknown error'}</p>
                    ${results.details ? `<p><strong>Details:</strong> ${results.details}</p>` : ''}
                    ${results.suggestion ? `<p><strong>Suggestion:</strong> ${results.suggestion}</p>` : ''}
                </div>
            `;
        }
        
        resultsContainer.html(html);
        modal.addClass('show');
    }

    function showDatafeedrTestModal(results, isSuccess = true) {
        const modal = $('#aebg-datafeedr-test-modal');
        const resultsContainer = $('#aebg-datafeedr-test-results');
        
        // Only show modal if we have actual results to display
        if (!results || (typeof results === 'object' && Object.keys(results).length === 0)) {
            console.warn('AEBG: Attempted to show Datafeedr test modal with no results');
            return;
        }
        
        let html = '';
        
        if (isSuccess) {
            html = `
                <div class="aebg-api-test-result success">
                    <h4>✅ Datafeedr Connection Successful</h4>
                    <p><strong>Status:</strong> ${results.status || 'N/A'}</p>
                    <p><strong>Message:</strong> ${results.message || 'N/A'}</p>
                    ${results.details ? `<p><strong>Details:</strong> ${results.details}</p>` : ''}
                </div>
            `;
        } else {
            html = `
                <div class="aebg-api-test-result error">
                    <h4>❌ Datafeedr Connection Failed</h4>
                    <p><strong>Error:</strong> ${results.error || 'Unknown error'}</p>
                    ${results.details ? `<p><strong>Details:</strong> ${results.details}</p>` : ''}
                    ${results.suggestion ? `<p><strong>Suggestion:</strong> ${results.suggestion}</p>` : ''}
                </div>
            `;
        }
        
        resultsContainer.html(html);
        modal.addClass('show');
    }

    // Modal functionality
    function initModal() {
        // Ensure all modals are hidden on page load
        $('.aebg-modal').removeClass('show');
        
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
    }

    // Enhanced checkbox functionality
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
    }

    // Loading overlay
    function showLoadingOverlay() {
        $('#aebg-loading-overlay').addClass('show');
    }

    function hideLoadingOverlay() {
        $('#aebg-loading-overlay').removeClass('show');
    }

    // Message display
    function showMessage(message, type = 'info') {
        // Remove existing messages
        $('.aebg-message').remove();
        
        const messageHtml = `
            <div class="aebg-message ${type}">
                <span class="aebg-icon">${getMessageIcon(type)}</span>
                ${message}
            </div>
        `;
        
        $('.aebg-settings-header').after(messageHtml);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            $('.aebg-message').fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    function getMessageIcon(type) {
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        return icons[type] || icons.info;
    }

    // Enhanced form validation
    function validateForm() {
        const errors = [];
        
        // Check API key
        const apiKey = $('#aebg_api_key').val();
        if (!apiKey) {
            errors.push('API key is required');
        } else if (!apiKey.startsWith('sk-')) {
            errors.push('API key should start with "sk-"');
        }
        
        // Check temperature
        const temperature = parseFloat($('#aebg_temperature').val());
        if (isNaN(temperature) || temperature < 0 || temperature > 2) {
            errors.push('Temperature must be between 0 and 2');
        }
        
        // Check max tokens
        const maxTokens = parseInt($('#aebg_max_tokens').val());
        if (isNaN(maxTokens) || maxTokens < 1 || maxTokens > 4000) {
            errors.push('Maximum tokens must be between 1 and 4000');
        }
        
        return errors;
    }

    // Real-time validation
    $('.aebg-input, .aebg-select, .aebg-textarea, .aebg-slider').on('blur', function() {
        const errors = validateForm();
        if (errors.length > 0) {
            showMessage(errors[0], 'warning');
        }
    });

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveSettings();
        }
        
        // Ctrl/Cmd + T to test API
        if ((e.ctrlKey || e.metaKey) && e.key === 't') {
            e.preventDefault();
            testApiConnection();
        }
    });

    // Tooltip functionality
    $('.aebg-help-text').each(function() {
        const helpText = $(this).text();
        $(this).attr('title', helpText);
    });

    // Auto-save indicator
    let autoSaveIndicator;
    function showAutoSaveIndicator() {
        if (autoSaveIndicator) {
            clearTimeout(autoSaveIndicator);
        }
        
        const indicator = $('<div class="aebg-auto-save-indicator">Auto-saving...</div>');
        $('.aebg-settings-header').after(indicator);
        
        autoSaveIndicator = setTimeout(function() {
            indicator.fadeOut(300, function() {
                $(this).remove();
            });
        }, 2000);
    }

    // Enhanced slider experience
    $('.aebg-slider').on('input', function() {
        const value = $(this).val();
        const min = $(this).attr('min');
        const max = $(this).attr('max');
        const percentage = ((value - min) / (max - min)) * 100;
        
        $(this).css('background', `linear-gradient(to right, #4f46e5 0%, #4f46e5 ${percentage}%, #e5e7eb ${percentage}%, #e5e7eb 100%)`);
    });

    // Initialize slider backgrounds
    $('.aebg-slider').each(function() {
        const value = $(this).val();
        const min = $(this).attr('min');
        const max = $(this).attr('max');
        const percentage = ((value - min) / (max - min)) * 100;
        
        $(this).css('background', `linear-gradient(to right, #4f46e5 0%, #4f46e5 ${percentage}%, #e5e7eb ${percentage}%, #e5e7eb 100%)`);
    });

    // Generator page functionality
    function initGeneratorPage() {
        $('#aebg-generator-form').on('submit', function(e) {
            e.preventDefault();

            var titles = $('#aebg-titles').val().split('\n').filter(function(title) {
                return title.trim() !== '';
            });

            if (titles.length === 0) {
                alert('Please enter at least one title.');
                return;
            }

            var settings = {
                template_id: $('#aebg-template').val(),
                num_products: $('#aebg-num-products').val(),
            };

            $('#aebg-generator-form').hide();
            $('#aebg-progress-bar-container').show();

            $.post(ajaxurl, {
                action: 'aebg_schedule_batch',
                _ajax_nonce: aebg.ajax_nonce,
                titles: JSON.stringify(titles),
                settings: JSON.stringify(settings),
            }, function(response) {
                if (response.success) {
                    var batchId = response.data.batch_id;
                    var interval = setInterval(function() {
                        $.ajax({
                            url: aebg.rest_url + 'batch/' + batchId,
                            method: 'GET',
                            beforeSend: function(xhr) {
                                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
                            }
                        }).done(function(response) {
                            var processed = parseInt(response.processed_items) + parseInt(response.failed_items);
                            var total = parseInt(response.total_items);
                            var percentage = (processed / total) * 100;

                            $('#aebg-progress-bar-inner').css('width', percentage + '%').text(Math.round(percentage) + '%');
                            $('#aebg-progress-text').text(processed + ' / ' + total + ' posts generated.');

                            if (response.status === 'completed') {
                                clearInterval(interval);
                                $('#aebg-progress-text').text('Completed! Redirecting to results page...');
                                window.location.href = window.location.href + '&batch_id=' + batchId;
                            }
                        });
                    }, 5000);
                } else {
                    var msg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    if (response.status === 403 || msg === 'Permission denied.') {
                        msg = 'You do not have permission to use the bulk generator. Admin access required.';
                    }
                    alert(msg);
                }
            });
        });
    }

    // Initialize networks page functionality
    if ($('.aebg-networks-container').length) {
        initNetworksPage();
    }

    // Initialize modern network selector only when needed
    if ($('.aebg-modern-network-selector').length && typeof ModernNetworksSelector === 'undefined') {
        // Only try to load if we're on a page that needs it and the script isn't already loaded
        // Skip if we're on edit-posts page since it loads the script directly
        if (window.location.href.includes('post.php') && window.location.href.includes('action=edit')) {
            console.log('Modern Network Selector: On edit-posts page, script loaded directly. Skipping dynamic load.');
        } else if (typeof aebg_ajax !== 'undefined' && aebg_ajax.plugin_url) {
            $.getScript(aebg_ajax.plugin_url + '/assets/js/modern-networks-selector.js')
                .done(function() {
                    console.log('Modern Network Selector loaded successfully');
                })
                .fail(function(jqxhr, settings, exception) {
                    console.error('Failed to load Modern Network Selector:', exception);
                });
        } else {
            console.log('Modern Network Selector: Not needed on this page or plugin_url not available');
        }
    }

    function initNetworksPage() {
        // Tab functionality
        $('.aebg-tab-button').on('click', function() {
            const targetTab = $(this).data('tab');
            
            // Update active tab button
            $('.aebg-tab-button').removeClass('active');
            $(this).addClass('active');
            
            // Update active tab panel
            $('.aebg-tab-panel').removeClass('active');
            $('#tab-' + targetTab).addClass('active');
        });
        
        // Save all networks functionality
        $('#aebg-save-all-networks-btn').on('click', function() {
            const $btn = $(this);
            const $status = $('#aebg-save-status');
            
            // Show loading state
            $btn.prop('disabled', true);
            $btn.find('.btn-text').text('Saving...');
            $status.html('<div class="notice notice-info"><p>Saving network IDs...</p></div>');
            
            // Collect all form data
            const formData = new FormData();
            formData.append('action', 'aebg_save_networks_ajax');
            formData.append('nonce', $('#aebg_networks_nonce').val());
            
            // Add all affiliate ID fields
            $('.aebg-affiliate-input').each(function() {
                const $input = $(this);
                const networkKey = $input.data('network');
                const value = $input.val();
                formData.append('affiliate_ids[' + networkKey + ']', value);
            });
            
            // Send AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>All network IDs saved successfully!</p></div>');
                        
                        // Update status indicators
                        $('.aebg-affiliate-input').each(function() {
                            const $input = $(this);
                            const $statusSpan = $input.closest('.aebg-network-card').find('.aebg-network-status .aebg-status');
                            const $statusCell = $input.closest('.aebg-network-card').find('.aebg-network-status');
                            
                            if ($input.val().trim()) {
                                $statusCell.html('<span class="aebg-status aebg-status-active"><span class="dashicons dashicons-yes"></span> Configured</span>');
                            } else {
                                $statusCell.html('<span class="aebg-status aebg-status-inactive"><span class="dashicons dashicons-no"></span> Not configured</span>');
                            }
                        });
                    } else {
                        $status.html('<div class="notice notice-error"><p>Error saving network IDs: ' + (response.data || 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>Error saving network IDs. Please try again.</p></div>');
                },
                complete: function() {
                    // Reset button state
                    $btn.prop('disabled', false);
                    $btn.find('.btn-text').text('Save All Network IDs');
                }
            });
        });
        
        // Auto-save on input change (optional)
        $('.aebg-affiliate-input').on('blur', function() {
            const $input = $(this);
            const networkKey = $input.data('network');
            const value = $input.val();
            
            // Update status immediately
            const $statusCell = $input.closest('.aebg-network-card').find('.aebg-network-status');
            if (value.trim()) {
                $statusCell.html('<span class="aebg-status aebg-status-active"><span class="dashicons dashicons-yes"></span> Configured</span>');
            } else {
                $statusCell.html('<span class="aebg-status aebg-status-inactive"><span class="dashicons dashicons-no"></span> Not configured</span>');
            }
        });

        // Add smooth scrolling for tab navigation
        $('.aebg-tab-button').on('click', function() {
            const $container = $('.aebg-networks-container');
            $container.scrollTop(0);
        });

        // Add keyboard navigation for tabs
        $('.aebg-tab-button').on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });

        // Add search functionality for networks (optional enhancement)
        if ($('#aebg-network-search').length) {
            $('#aebg-network-search').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                
                $('.aebg-network-card').each(function() {
                    const $card = $(this);
                    const networkName = $card.find('h3').text().toLowerCase();
                    
                    if (networkName.includes(searchTerm)) {
                        $card.show();
                    } else {
                        $card.hide();
                    }
                });
            });
        }
    }

    /**
     * Initialize negative phrases management
     */
    function initNegativePhrases() {
        const container = $('#aebg-negative-phrases-container');
        if (!container.length) {
            return;
        }
        
        const input = $('#aebg-negative-phrase-input');
        const addBtn = $('#aebg-add-negative-phrase');
        const list = $('#aebg-negative-phrases-list');
        
        // Load existing phrases on page load (with small delay to ensure DOM is ready)
        setTimeout(function() {
            loadNegativePhrases();
        }, 100);
        
        // Add phrase button click
        addBtn.on('click', function() {
            const phrase = input.val().trim();
            if (phrase) {
                addNegativePhraseToList(phrase);
                input.val('');
                input.focus();
            }
        });
        
        // Add phrase on Enter key
        input.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                addBtn.click();
            }
        });
        
        // Remove phrase button click
        list.on('click', '.aebg-remove-phrase', function() {
            $(this).closest('.aebg-phrase-item').fadeOut(300, function() {
                $(this).remove();
                updateNegativePhrasesInput();
            });
        });
    }
    
    /**
     * Load existing negative phrases from hidden input
     */
    function loadNegativePhrases() {
        const hiddenInput = $('#aebg_negative_phrases');
        const list = $('#aebg-negative-phrases-list');
        
        if (!hiddenInput.length || !list.length) {
            return;
        }
        
        try {
            let value = hiddenInput.val() || '[]';
            
            // Debug log
            if (value && value !== '[]') {
                console.log('Loading negative phrases, raw value:', value);
            }
            
            // Handle double-encoded JSON (WordPress sometimes does this)
            if (value.startsWith('"') && value.endsWith('"')) {
                value = JSON.parse(value);
            }
            
            // Parse the JSON
            const phrases = JSON.parse(value);
            
            if (Array.isArray(phrases) && phrases.length > 0) {
                // Clear any existing items first
                list.empty();
                
                phrases.forEach(function(phrase) {
                    if (phrase && typeof phrase === 'string' && phrase.trim()) {
                        // Skip if it looks like a JSON array string (corrupted data)
                        const trimmed = phrase.trim();
                        if (trimmed.startsWith('[') && trimmed.endsWith(']')) {
                            console.warn('Skipping invalid phrase that looks like JSON:', phrase);
                            return;
                        }
                        addNegativePhraseToList(trimmed);
                    }
                });
            } else {
                // Clear list if no valid phrases
                list.empty();
            }
        } catch (e) {
            console.error('Error parsing negative phrases:', e);
            console.error('Raw value:', hiddenInput.val());
            // Clear the list if parsing fails
            list.empty();
        }
    }
    
    /**
     * Add a phrase to the list
     */
    function addNegativePhraseToList(phrase) {
        const list = $('#aebg-negative-phrases-list');
        
        // Check for duplicates
        let isDuplicate = false;
        list.find('.aebg-phrase-text').each(function() {
            if ($(this).text().trim().toLowerCase() === phrase.trim().toLowerCase()) {
                isDuplicate = true;
                return false;
            }
        });
        
        if (isDuplicate) {
            alert('This phrase is already in the list.');
            return;
        }
        
        const item = $('<div class="aebg-phrase-item"></div>');
        item.html(
            '<span class="aebg-phrase-text">' + escapeHtml(phrase) + '</span>' +
            '<button type="button" class="aebg-remove-phrase aebg-btn aebg-btn-danger aebg-btn-sm">' +
            '<span class="aebg-icon">🗑️</span> Remove' +
            '</button>'
        );
        list.append(item);
        updateNegativePhrasesInput();
    }
    
    /**
     * Update the hidden input with current phrases
     */
    function updateNegativePhrasesInput() {
        const phrases = [];
        $('#aebg-negative-phrases-list .aebg-phrase-text').each(function() {
            const phrase = $(this).text().trim();
            if (phrase) {
                phrases.push(phrase);
            }
        });
        const jsonValue = JSON.stringify(phrases);
        const hiddenInput = $('#aebg_negative_phrases');
        const oldValue = hiddenInput.val();
        
        // Only update and trigger if value actually changed
        if (oldValue !== jsonValue) {
            hiddenInput.val(jsonValue);
            // Trigger change event to activate existing auto-save mechanism
            hiddenInput.trigger('change');
        }
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
