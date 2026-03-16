/**
 * AI Content Generator V2 - Step-by-Step Interface
 * 
 * @package AEBG
 * @since 2.0.0
 */

jQuery(document).ready(function($) {
    'use strict';

    // State management
    const state = {
        currentStep: 1,
        totalSteps: 5,
        formData: {
            titles: [],
            template: null,
            templateName: null,
            numProducts: null,
            postType: 'post',
            postStatus: 'draft',
            aiModel: 'gpt-3.5-turbo',
            creativity: 0.7,
            contentLength: 1500,
            generateFeaturedImages: false,
            featuredImageStyle: 'realistic photo',
            imageModel: 'dall-e-3',
            imageSize: '1024x1024',
            imageQuality: 'standard',
            autoCategories: true,
            autoTags: true,
            includeMeta: true,
            includeSchema: false
        },
        templates: [],
        costEstimate: {
            textGeneration: { totalCost: 0 },
            imageGeneration: { totalCost: 0 },
            totalCost: 0,
            estimatedTime: 0
        },
        currentBatchId: null,
        progressInterval: null,
        isGenerating: false
    };

    // Initialize
    initGeneratorV2();

    function initGeneratorV2() {
        // Step 1: Titles
        initStep1();
        
        // Step 2: Template
        initStep2();
        
        // Step 3: Post Status & Images
        initStep3();
        
        // Step 4: Generation Options
        initStep4();
        
        // Step 5: Review
        initStep5();
        
        // Cancel generation
        initCancelGeneration();
        
        // Check for active batch on page load
        setTimeout(function() {
            checkActiveBatch();
        }, 100);
    }

    // Step 1: Enter Titles
    function initStep1() {
        const $textarea = $('#aebg-v2-titles');
        const $continue = $('#aebg-v2-continue');

        $textarea.on('input', function() {
            const titles = $(this).val().split('\n')
                .map(t => t.trim())
                .filter(t => t.length > 0);
            
            state.formData.titles = titles;
            
            // Enable/disable continue button
            $continue.prop('disabled', titles.length === 0);
        });

        $continue.on('click', function() {
            if (state.formData.titles.length > 0) {
                goToStep(2);
            }
        });
    }

    // Step 2: Select Template
    function initStep2() {
        const $back = $('#aebg-v2-back-2');
        const $continue = $('#aebg-v2-continue-2');
        const $search = $('#aebg-v2-template-search');
        const $table = $('#aebg-v2-template-list');

        $back.on('click', function() {
            goToStep(1);
        });

        $continue.on('click', function() {
            if (state.formData.template) {
                goToStep(3);
            }
        });

        // Template search
        $search.on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            $table.find('tr').each(function() {
                const $row = $(this);
                const text = $row.text().toLowerCase();
                $row.toggle(text.includes(searchTerm));
            });
        });
    }

    // Load templates from API
    function loadTemplates() {
        const $table = $('#aebg-v2-template-list');
        $table.html('<tr><td colspan="5" style="text-align: center; padding: 40px;">Loading templates...</td></tr>');
        
        $.ajax({
            url: aebg.rest_url + 'generator-v2/templates',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(templates) {
                state.templates = templates;
                renderTemplates(templates);
            },
            error: function(xhr) {
                console.error('Failed to load templates', xhr);
                $table.html('<tr><td colspan="5" style="text-align: center; padding: 40px; color: #ef4444;">Failed to load templates. Please refresh the page.</td></tr>');
            }
        });
    }

    // Render templates in table
    function renderTemplates(templates) {
        const $table = $('#aebg-v2-template-list');
        $table.empty();

        if (!templates || templates.length === 0) {
            $table.html('<tr><td colspan="5" style="text-align: center; padding: 40px;">No templates found. Please create a template first.</td></tr>');
            return;
        }

        templates.forEach(function(template) {
            const $row = $('<tr>')
                .data('template-id', template.id)
                .html(`
                    <td><strong>${escapeHtml(template.name)}</strong></td>
                    <td>${template.typeIcon || '📄'} ${escapeHtml(template.type || 'Page')}</td>
                    <td><span class="aebg-product-count">${template.productCount || 'N/A'}</span></td>
                    <td>${escapeHtml(template.lastUsed || 'Never')}</td>
                    <td><input type="radio" name="template-select" value="${template.id}"></td>
                `)
                .on('click', function(e) {
                    if ($(e.target).is('input[type="radio"]')) {
                        return; // Let radio handle it
                    }
                    selectTemplate(template);
                });

            $table.append($row);
        });

        // Handle radio button clicks
        $table.find('input[type="radio"]').on('click', function(e) {
            e.stopPropagation();
            const templateId = parseInt($(this).val());
            const template = templates.find(t => t.id === templateId);
            if (template) {
                selectTemplate(template);
            }
        });
    }

    // Select template and auto-detect product count
    function selectTemplate(template) {
        state.formData.template = template.id;
        state.formData.templateName = template.name;
        
        // Remove previous selection
        $('#aebg-v2-template-table tbody tr').removeClass('selected');
        
        // Highlight selected row
        $(`#aebg-v2-template-table tbody tr[data-template-id="${template.id}"]`)
            .addClass('selected')
            .find('input[type="radio"]')
            .prop('checked', true);

        // Auto-detect product count
        detectProductCount(template.id);
        
        // Enable continue button
        $('#aebg-v2-continue-2').prop('disabled', false);
    }

    // Detect product count from template
    function detectProductCount(templateId) {
        $('#aebg-v2-template-selected').hide();
        
        $.ajax({
            url: aebg.rest_url + 'generator-v2/templates/' + templateId + '/analyze',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(data) {
                if (data.product_count) {
                    state.formData.numProducts = data.product_count;
                    
                    // Show confirmation
                    $('#aebg-v2-product-count').text(data.product_count);
                    $('#aebg-v2-template-selected').slideDown();
                } else {
                    // Fallback to template's detected count
                    const template = state.templates.find(t => t.id === templateId);
                    if (template && template.productCount) {
                        state.formData.numProducts = template.productCount;
                        $('#aebg-v2-product-count').text(template.productCount);
                        $('#aebg-v2-template-selected').slideDown();
                    }
                }
            },
            error: function(xhr) {
                console.error('Failed to analyze template', xhr);
                // Fallback to template's detected count
                const template = state.templates.find(t => t.id === templateId);
                if (template && template.productCount) {
                    state.formData.numProducts = template.productCount;
                    $('#aebg-v2-product-count').text(template.productCount);
                    $('#aebg-v2-template-selected').slideDown();
                }
            }
        });
    }

    // Step 3: Post Status & Images
    function initStep3() {
        const $back = $('#aebg-v2-back-3');
        const $continue = $('#aebg-v2-continue-3');
        const $postStatus = $('input[name="aebg-v2-post-status"]');
        const $imageConfig = $('#aebg-v2-image-config');
        const $generateImages = $('#aebg-v2-generate-featured-images');
        const $imageOptions = $('#aebg-v2-image-options');

        $back.on('click', function() {
            goToStep(2);
        });

        $continue.on('click', function() {
            goToStep(4);
        });

        // Show image config when post status is selected
        $postStatus.on('change', function() {
            state.formData.postStatus = $(this).val();
            $imageConfig.slideDown();
        });

        // Show image options when checkbox is checked
        $generateImages.on('change', function() {
            state.formData.generateFeaturedImages = $(this).is(':checked');
            if ($(this).is(':checked')) {
                $imageOptions.slideDown();
            } else {
                $imageOptions.slideUp();
            }
            // Update costs when image settings change
            if (state.currentStep === 5) {
                calculateCosts();
            }
        });

        // Update image settings when options change
        $('#aebg-v2-featured-image-style').on('change', function() {
            state.formData.featuredImageStyle = $(this).val();
        });

        $('#aebg-v2-image-model').on('change', function() {
            state.formData.imageModel = $(this).val();
            const model = $(this).val();
            const isNanoBanana = /^nano-banana(-2|-pro)?$/.test(model);
            // Hide HD option for DALL-E 2 and Nano Banana family
            if (model === 'dall-e-2' || isNanoBanana) {
                $('#aebg-v2-hd-option').hide();
                if ($('input[name="aebg-v2-image-quality"]:checked').val() === 'hd') {
                    $('input[name="aebg-v2-image-quality"][value="standard"]').prop('checked', true);
                    state.formData.imageQuality = 'standard';
                }
            } else {
                $('#aebg-v2-hd-option').show();
            }
            if (state.currentStep === 5) {
                calculateCosts();
            }
        });

        $('#aebg-v2-image-size').on('change', function() {
            state.formData.imageSize = $(this).val();
        });

        $('input[name="aebg-v2-image-quality"]').on('change', function() {
            state.formData.imageQuality = $(this).val();
            if (state.currentStep === 5) {
                calculateCosts();
            }
        });
    }

    // Step 4: Generation Options
    function initStep4() {
        const $back = $('#aebg-v2-back-4');
        const $continue = $('#aebg-v2-continue-4');
        const $autoCategories = $('#aebg-v2-auto-categories');
        const $autoTags = $('#aebg-v2-auto-tags');
        const $generateMeta = $('#aebg-v2-generate-meta');
        const $addSchema = $('#aebg-v2-add-schema');

        $back.on('click', function() {
            goToStep(3);
        });

        $continue.on('click', function() {
            goToStep(5);
        });

        // Update state when options change
        $autoCategories.on('change', function() {
            state.formData.autoCategories = $(this).is(':checked');
            updateOptionCard($(this).closest('.aebg-v2-option-card'), $(this).is(':checked'));
        });

        $autoTags.on('change', function() {
            state.formData.autoTags = $(this).is(':checked');
            updateOptionCard($(this).closest('.aebg-v2-option-card'), $(this).is(':checked'));
        });

        $generateMeta.on('change', function() {
            state.formData.includeMeta = $(this).is(':checked');
            updateOptionCard($(this).closest('.aebg-v2-option-card'), $(this).is(':checked'));
        });

        $addSchema.on('change', function() {
            state.formData.includeSchema = $(this).is(':checked');
            updateOptionCard($(this).closest('.aebg-v2-option-card'), $(this).is(':checked'));
        });

        // Initialize option cards on step load
        if (state.currentStep === 4) {
            updateOptionCard($autoCategories.closest('.aebg-v2-option-card'), state.formData.autoCategories);
            updateOptionCard($autoTags.closest('.aebg-v2-option-card'), state.formData.autoTags);
            updateOptionCard($generateMeta.closest('.aebg-v2-option-card'), state.formData.includeMeta);
            updateOptionCard($addSchema.closest('.aebg-v2-option-card'), state.formData.includeSchema);
        }
    }

    // Update option card visual state
    function updateOptionCard($card, isEnabled) {
        if (isEnabled) {
            $card.addClass('aebg-option-enabled');
        } else {
            $card.removeClass('aebg-option-enabled');
        }
    }

    // Step 5: Review & Generate
    function initStep5() {
        const $back = $('#aebg-v2-back-5');
        const $generate = $('#aebg-v2-generate');

        $back.on('click', function() {
            goToStep(4);
        });

        $generate.on('click', function() {
            generateContent();
        });
    }

    // Navigate to step
    function goToStep(step) {
        // Hide current step
        $(`#aebg-step-${state.currentStep}`).removeClass('active').hide();
        
        // Show new step
        state.currentStep = step;
        $(`#aebg-step-${step}`).addClass('active').show();
        
        // Update progress indicator
        updateProgress();
        
        // Update back button visibility
        $('.aebg-btn-secondary').toggle(step > 1);
        
        // Load step-specific data
        if (step === 2) {
            if (state.templates.length === 0) {
                loadTemplates();
            }
        } else if (step === 4) {
            // Initialize option cards visual state
            updateOptionCard($('#aebg-v2-auto-categories').closest('.aebg-v2-option-card'), state.formData.autoCategories);
            updateOptionCard($('#aebg-v2-auto-tags').closest('.aebg-v2-option-card'), state.formData.autoTags);
            updateOptionCard($('#aebg-v2-generate-meta').closest('.aebg-v2-option-card'), state.formData.includeMeta);
            updateOptionCard($('#aebg-v2-add-schema').closest('.aebg-v2-option-card'), state.formData.includeSchema);
        } else if (step === 5) {
            renderReview();
            calculateCosts();
        }
    }

    // Update progress indicator
    function updateProgress() {
        const percentage = (state.currentStep / state.totalSteps) * 100;
        
        $('#aebg-current-step').text(state.currentStep);
        $('#aebg-progress-percentage').text(Math.round(percentage) + '%');
        
        // Update dots
        $('.aebg-dot').each(function(index) {
            const stepNum = index + 1;
            const $dot = $(this);
            
            if (stepNum < state.currentStep) {
                $dot.addClass('completed').removeClass('active');
            } else if (stepNum === state.currentStep) {
                $dot.addClass('active').removeClass('completed');
            } else {
                $dot.removeClass('active completed');
            }
        });
    }

    // Render review summary
    function renderReview() {
        const $summary = $('#aebg-v2-review-summary');
        
        const html = `
            <div class="aebg-review-item">
                <strong>Titles:</strong> ${state.formData.titles.length} post(s)
                <div class="aebg-review-titles-list">
                    ${state.formData.titles.slice(0, 5).map(t => `<div class="aebg-review-title">• ${escapeHtml(t)}</div>`).join('')}
                    ${state.formData.titles.length > 5 ? `<div class="aebg-review-more">... and ${state.formData.titles.length - 5} more</div>` : ''}
                </div>
            </div>
            <div class="aebg-review-item">
                <strong>Template:</strong> ${escapeHtml(state.formData.templateName || 'Not selected')}
            </div>
            <div class="aebg-review-item">
                <strong>Products per post:</strong> ${state.formData.numProducts || 'N/A'}
            </div>
            <div class="aebg-review-item">
                <strong>Post status:</strong> ${escapeHtml(state.formData.postStatus)}
            </div>
            ${state.formData.generateFeaturedImages ? `
            <div class="aebg-review-item">
                <strong>Image generation:</strong> Enabled
                <div class="aebg-review-details">
                    Model: ${escapeHtml(state.formData.imageModel)}, 
                    Quality: ${escapeHtml(state.formData.imageQuality)}, 
                    Style: ${escapeHtml(state.formData.featuredImageStyle)}
                </div>
            </div>
            ` : ''}
            <div class="aebg-review-item">
                <strong>Generation options:</strong>
                <div class="aebg-review-details">
                    ${state.formData.autoCategories ? '✓ Auto-assign categories' : '✗ Auto-assign categories'}<br>
                    ${state.formData.autoTags ? '✓ Auto-generate tags' : '✗ Auto-generate tags'}<br>
                    ${state.formData.includeMeta ? '✓ Generate meta descriptions' : '✗ Generate meta descriptions'}<br>
                    ${state.formData.includeSchema ? '✓ Add structured data' : '✗ Add structured data'}
                </div>
            </div>
        `;
        
        $summary.html(html);
    }

    // Calculate costs
    function calculateCosts() {
        const params = {
            num_posts: state.formData.titles.length,
            ai_model: state.formData.aiModel,
            content_length: state.formData.contentLength,
            num_products: state.formData.numProducts || 7,
            generate_images: state.formData.generateFeaturedImages,
            image_model: state.formData.imageModel,
            image_quality: state.formData.imageQuality,
            generate_featured_images: state.formData.generateFeaturedImages,
        };

        $.ajax({
            url: aebg.rest_url + 'generator-v2/calculate-costs',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(params),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(data) {
                state.costEstimate = data;
                
                $('#aebg-v2-text-cost').text('$' + data.text_generation.total_cost.toFixed(2));
                $('#aebg-v2-image-cost').text('$' + data.image_generation.total_cost.toFixed(2));
                $('#aebg-v2-total-cost').text('$' + data.total_cost.toFixed(2));
                $('#aebg-v2-estimated-time').text('~' + data.estimated_time_minutes + ' minutes');
            },
            error: function(xhr) {
                console.error('Failed to calculate costs', xhr);
                // Set defaults
                $('#aebg-v2-text-cost').text('$0.00');
                $('#aebg-v2-image-cost').text('$0.00');
                $('#aebg-v2-total-cost').text('$0.00');
                $('#aebg-v2-estimated-time').text('~0 minutes');
            }
        });
    }

    // Generate content
    function generateContent() {
        const $generate = $('#aebg-v2-generate');
        const $overlay = $('#aebg-v2-loading-overlay');
        
        // Disable button
        $generate.prop('disabled', true).text('Generating...');
        
        // Show loading overlay
        $overlay.show();
        
        // Prepare data
        const data = {
            titles: state.formData.titles,
            template: state.formData.template,
            num_products: state.formData.numProducts,
            post_type: state.formData.postType,
            post_status: state.formData.postStatus,
            ai_model: state.formData.aiModel,
            creativity: state.formData.creativity,
            content_length: state.formData.contentLength,
            generate_featured_images: state.formData.generateFeaturedImages,
            featured_image_style: state.formData.featuredImageStyle,
            image_model: state.formData.imageModel,
            image_size: state.formData.imageSize,
            image_quality: state.formData.imageQuality,
            auto_categories: state.formData.autoCategories,
            auto_tags: state.formData.autoTags,
            include_meta: state.formData.includeMeta,
            include_schema: state.formData.includeSchema,
        };

        $.ajax({
            url: aebg.rest_url + 'generator-v2/generate',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(response) {
                $overlay.hide();
                
                if (response.batch_id) {
                    state.currentBatchId = response.batch_id;
                    state.isGenerating = true;
                    
                    // Hide form progress indicator
                    $('#aebg-v2-form-progress').hide();
                    
                    // Show progress section
                    $('#aebg-v2-progress-section').show();
                    
                    // Hide step container
                    $('.aebg-step-container').hide();
                    
                    // Scroll to top
                    $('html, body').animate({
                        scrollTop: 0
                    }, 300);
                    
                    // Start progress tracking
                    startProgressTracking(response.batch_id, response.num_posts || state.formData.titles.length);
                } else {
                    alert('Content generation started successfully!');
                    $generate.prop('disabled', false).text('🚀 Generate Content');
                }
            },
            error: function(xhr) {
                console.error('Failed to generate content', xhr);
                let errorMessage = 'Failed to start generation.';
                
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }
                
                alert(errorMessage);
                $overlay.hide();
                $generate.prop('disabled', false).text('🚀 Generate Content');
            }
        });
    }

    // Start progress tracking
    function startProgressTracking(batchId, totalItems) {
        const generationStartTime = Date.now();
        let progressHistory = [];
        let seenFailedItemIds = new Set();
        
        // Add running class
        $('#aebg-v2-progress-section').addClass('aebg-v2-progress-running');
        $('#aebg-v2-progress-header h3').html('<span class="aebg-v2-spinner"></span> Generating Content');
        
        // Update progress every 2 seconds
        state.progressInterval = setInterval(function() {
            $.ajax({
                url: aebg.rest_url + 'batch/' + batchId,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
                },
                success: function(response) {
                    const completed = parseInt(response.completed_items || 0);
                    const processed = parseInt(response.processed_items || 0);
                    const failed = parseInt(response.failed_items || 0);
                    const total = parseInt(response.total_items || totalItems);
                    const status = response.status || 'pending';
                    const currentItem = response.current_item || null;
                    const processingItems = response.processing_items || [];
                    const failedItemsDetail = response.failed_items_detail || [];
                    
                    // Calculate progress percentage
                    const percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
                    
                    // Update progress bar
                    $('#aebg-v2-progress-bar-inner').css('width', percentage + '%');
                    
                    // Update stats
                    $('#aebg-v2-progress-stats').text(`${processed} / ${total} posts`);
                    
                    // Update progress text
                    updateProgressText(completed, processed, failed, total, status, currentItem, processingItems);
                    
                    // Update processing items list
                    updateProcessingItems(processingItems);
                    
                    // Update failed items list
                    updateFailedItems(failedItemsDetail, seenFailedItemIds);
                    
                    // Show view live results button if we have completed items
                    if (completed > 0) {
                        $('#aebg-v2-view-live-results').show();
                    }
                    
                    // Check for completion
                    if (status === 'completed' || status === 'failed' || (total > 0 && completed === total)) {
                        clearInterval(state.progressInterval);
                        handleGenerationComplete(response, batchId);
                    }
                },
                error: function(xhr) {
                    console.error('Failed to get batch status', xhr);
                }
            });
        }, 2000);
    }

    // Update progress text
    function updateProgressText(completed, processed, failed, total, status, currentItem, processingItems) {
        let progressText = '';
        
        if (currentItem && currentItem.title) {
            const title = currentItem.title.length > 60 
                ? currentItem.title.substring(0, 57) + '...' 
                : currentItem.title;
            
            let stepInfo = '';
            if (currentItem.step_progress > 0 && currentItem.total_steps > 0) {
                const stepDesc = currentItem.step_description || 'Step ' + currentItem.step_progress;
                stepInfo = ' <span class="aebg-v2-step-badge">' + stepDesc + ' (' + currentItem.step_progress + '/' + currentItem.total_steps + ')</span>';
            }
            
            const failedText = (failed > 0 && completed > 0) ? ' (' + failed + ' failed so far)' : '';
            progressText = '<span class="aebg-v2-status-indicator"></span> Processing: ' + escapeHtml(title) + stepInfo + failedText;
        } else if (completed > 0) {
            const failedText = (failed > 0) ? ' (' + failed + ' failed)' : '';
            progressText = '<span class="aebg-v2-status-indicator"></span> Generating content... (' + completed + '/' + total + ' completed' + failedText + ')';
        } else if (status === 'in_progress' || status === 'processing' || processingItems.length > 0) {
            progressText = '<span class="aebg-v2-status-indicator"></span> Generating content...';
        } else {
            progressText = '<span class="aebg-v2-status-indicator"></span> Starting generation...';
        }
        
        $('#aebg-v2-progress-text').html(progressText);
        
        // Update current activity
        let currentActivity = '';
        if (currentItem && currentItem.title) {
            const title = currentItem.title.length > 50 
                ? currentItem.title.substring(0, 47) + '...' 
                : currentItem.title;
            
            let stepInfo = '';
            if (currentItem.step_progress > 0 && currentItem.total_steps > 0) {
                const stepDesc = currentItem.step_description || 'Step ' + currentItem.step_progress;
                stepInfo = ' (' + stepDesc + ' - ' + currentItem.step_progress + '/' + currentItem.total_steps + ')';
            }
            
            currentActivity = 'Generating: ' + escapeHtml(title) + stepInfo;
        } else if (completed > 0) {
            currentActivity = 'Generating content... (' + completed + '/' + total + ' completed' + (failed > 0 ? ', ' + failed + ' failed' : '') + ')';
        } else {
            currentActivity = 'Preparing to generate...';
        }
        
        $('#aebg-v2-current-activity').text(currentActivity);
    }

    // Update processing items list
    function updateProcessingItems(processingItems) {
        const $list = $('#aebg-v2-processing-items-list');
        const $content = $('#aebg-v2-processing-items-content');
        
        if ($list.length > 0 && $content.length > 0) {
            if (processingItems && processingItems.length > 0) {
                let itemsHtml = '';
                processingItems.forEach(function(item) {
                    const title = item.title || 'Untitled Post';
                    const truncatedTitle = title.length > 50 ? title.substring(0, 47) + '...' : title;
                    
                    let stepInfo = '';
                    if (item.current_step) {
                        const stepDesc = item.step_description || 'Step ' + (item.step_progress || 0);
                        const stepNum = item.step_progress || 0;
                        const totalSteps = item.total_steps || 12;
                        if (stepNum > 0) {
                            stepInfo = ' - <span class="aebg-v2-step-badge">' + stepDesc + ' (' + stepNum + '/' + totalSteps + ')</span>';
                        } else {
                            stepInfo = ' - <span class="aebg-v2-step-badge">' + stepDesc + ' (Starting...)</span>';
                        }
                    } else {
                        stepInfo = ' - <span class="aebg-v2-step-badge">Preparing...</span>';
                    }
                    
                    itemsHtml += '<div class="aebg-v2-processing-item-row">';
                    itemsHtml += '<span class="aebg-v2-item-title">' + escapeHtml(truncatedTitle) + '</span>';
                    itemsHtml += stepInfo;
                    itemsHtml += '</div>';
                });
                
                $content.html(itemsHtml);
                $list.show();
            } else {
                $list.hide();
                $content.html('');
            }
        }
    }

    // Update failed items list
    function updateFailedItems(failedItemsDetail, seenFailedItemIds) {
        const $list = $('#aebg-v2-failed-items-list');
        const $content = $('#aebg-v2-failed-items-content');
        const $count = $('#aebg-v2-failed-count');
        
        if ($list.length > 0 && $content.length > 0) {
            if (failedItemsDetail && failedItemsDetail.length > 0) {
                if ($count.length > 0) {
                    $count.text('(' + failedItemsDetail.length + ')');
                }
                
                let failedHtml = '';
                failedItemsDetail.forEach(function(item) {
                    if (!seenFailedItemIds.has(item.id)) {
                        seenFailedItemIds.add(item.id);
                    }
                    
                    const title = item.title || 'Untitled Post';
                    const truncatedTitle = title.length > 60 ? title.substring(0, 57) + '...' : title;
                    const errorMsg = item.error_message || 'Unknown error';
                    const truncatedError = errorMsg.length > 150 ? errorMsg.substring(0, 147) + '...' : errorMsg;
                    
                    failedHtml += '<div class="aebg-v2-failed-item-row">';
                    failedHtml += '<div class="aebg-v2-failed-item-title">';
                    failedHtml += '<span class="aebg-icon">❌</span>';
                    failedHtml += '<strong>' + escapeHtml(truncatedTitle) + '</strong>';
                    failedHtml += '</div>';
                    failedHtml += '<div class="aebg-v2-failed-item-error">' + escapeHtml(truncatedError) + '</div>';
                    failedHtml += '</div>';
                });
                
                $content.html(failedHtml);
                $list.show();
            } else {
                $list.hide();
                $content.html('');
                if ($count.length > 0) {
                    $count.text('');
                }
            }
        }
    }

    // Handle generation complete
    function handleGenerationComplete(response, batchId) {
        state.isGenerating = false;
        
        if (state.progressInterval) {
            clearInterval(state.progressInterval);
        }
        
        $('#aebg-v2-progress-section').removeClass('aebg-v2-progress-running');
        $('#aebg-v2-progress-header h3').html('Generating Content');
        
        if (response.status === 'completed') {
            $('#aebg-v2-progress-text').html('<span class="aebg-v2-status-indicator"></span> Generation completed!');
            $('#aebg-v2-current-activity').text('All articles generated successfully!');
            $('#aebg-v2-view-live-results').show();
            
            // Redirect after 3 seconds
            setTimeout(function() {
                window.location.href = admin_url('admin.php?page=aebg_generator&batch_id=' + batchId);
            }, 3000);
        } else if (response.status === 'failed') {
            $('#aebg-v2-progress-text').html('<span class="aebg-v2-status-indicator"></span> Generation failed');
            $('#aebg-v2-current-activity').text('Generation failed');
        }
    }

    // Cancel generation
    function initCancelGeneration() {
        $('#aebg-v2-cancel-generation').on('click', function() {
            if (!confirm('Are you sure you want to cancel the generation?')) {
                return;
            }
            
            if (state.currentBatchId) {
                $.ajax({
                    url: aebg.rest_url + 'batch/' + state.currentBatchId + '/cancel',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
                    },
                    success: function() {
                        if (state.progressInterval) {
                            clearInterval(state.progressInterval);
                        }
                        state.isGenerating = false;
                        state.currentBatchId = null;
                        
                        $('#aebg-v2-progress-section').hide();
                        $('#aebg-v2-form-progress').show();
                        $('.aebg-step-container').show();
                    }
                });
            }
        });
        
        // View live results button
        $('#aebg-v2-view-live-results').on('click', function() {
            if (state.currentBatchId) {
                window.location.href = admin_url('admin.php?page=aebg_generator&batch_id=' + state.currentBatchId);
            }
        });
    }

    // Check for active batch on page load
    function checkActiveBatch() {
        $.ajax({
            url: aebg.rest_url + 'batch/active',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', aebg.rest_nonce);
            },
            success: function(response) {
                if (response && response.batch_id) {
                    state.currentBatchId = response.batch_id;
                    state.isGenerating = true;
                    
                    // Hide form progress indicator
                    $('#aebg-v2-form-progress').hide();
                    
                    // Show progress section
                    $('#aebg-v2-progress-section').show();
                    
                    // Hide step container
                    $('.aebg-step-container').hide();
                    
                    // Start progress tracking
                    const totalItems = response.total_items || 0;
                    startProgressTracking(response.batch_id, totalItems);
                }
            },
            error: function() {
                // No active batch, continue normally
            }
        });
    }

    // Utility: Escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
    }

    // Get admin URL (WordPress function)
    function admin_url(path) {
        if (typeof aebg !== 'undefined' && aebg.admin_url) {
            return aebg.admin_url + path;
        }
        // Fallback - try to get from WordPress
        if (typeof ajaxurl !== 'undefined') {
            return ajaxurl.replace('admin-ajax.php', path);
        }
        return '/wp-admin/' + path;
    }

});
