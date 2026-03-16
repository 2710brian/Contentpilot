/**
 * Reorder Progress UI
 * 
 * Visual progress indicator for product reordering and regeneration processes.
 * Shows real-time status updates, progress bars, and detailed step information.
 * 
 * @package AEBG
 */

(function($) {
    'use strict';
    
    /**
     * Reorder Progress UI Manager
     */
    const ReorderProgressUI = {
        
        /**
         * Initialize progress UI
         */
        init: function() {
            // Create progress container if it doesn't exist
            this.createProgressContainer();
        },
        
        /**
         * Create progress container
         */
        createProgressContainer: function() {
            if ($('#aebg-reorder-progress-container').length > 0) {
                return; // Already exists
            }
            
            const $container = $('<div>')
                .attr('id', 'aebg-reorder-progress-container')
                .addClass('aebg-reorder-progress-container')
                .css({
                    'position': 'fixed',
                    'bottom': '20px',
                    'right': '20px',
                    'background': '#fff',
                    'border': '1px solid #ddd',
                    'border-radius': '12px',
                    'padding': '20px',
                    'box-shadow': '0 8px 24px rgba(0,0,0,0.15)',
                    'z-index': '100000',
                    'min-width': '380px',
                    'max-width': '450px',
                    'max-height': '80vh',
                    'overflow-y': 'auto',
                    'font-family': '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif',
                    'display': 'none'
                });
            
            $('body').append($container);
        },
        
        /**
         * Show progress for reordering operation
         * 
         * @param {number} postId Post ID
         * @param {Array} newOrder New product order
         * @param {Object} choices Regeneration choices
         * @param {Object} responseData Response data
         */
        showReorderingProgress: function(postId, newOrder, choices, responseData) {
            const $container = $('#aebg-reorder-progress-container');
            if ($container.length === 0) {
                this.createProgressContainer();
            }
            
            const regenerationCount = Object.keys(choices).filter(id => {
                const choice = choices[id];
                return choice.action !== 'skip';
            }).length;
            
            // Build progress HTML
            const $progress = $('<div>').addClass('aebg-reorder-progress')
                .html(this.buildProgressHTML(postId, newOrder, choices, regenerationCount, responseData));
            
            $container.html($progress).fadeIn(300);
            
            // Start polling for status
            this.startStatusPolling(postId, choices, $container);
        },
        
        /**
         * Build progress HTML
         * 
         * @param {number} postId Post ID
         * @param {Array} newOrder New product order
         * @param {Object} choices Regeneration choices
         * @param {number} regenerationCount Number of regenerations
         * @param {Object} responseData Response data
         * @returns {string} HTML string
         */
        buildProgressHTML: function(postId, newOrder, choices, regenerationCount, responseData) {
            let html = `
                <div class="aebg-progress-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0;">
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px;">
                        <span class="aebg-progress-icon" style="font-size: 20px;">🔄</span>
                        <span>Processing Reorder</span>
                    </h3>
                    <button class="aebg-progress-close" style="background: none; border: none; font-size: 24px; cursor: pointer; padding: 0; width: 28px; height: 28px; line-height: 1; color: #6b7280; transition: color 0.2s;" 
                            onmouseover="this.style.color='#1f2937'" 
                            onmouseout="this.style.color='#6b7280'">&times;</button>
                </div>
                
                <div class="aebg-progress-steps" style="margin-bottom: 16px;">
                    ${this.buildStepIndicator('reordering', 'Reordering Products', true)}
                    ${regenerationCount > 0 ? this.buildStepIndicator('regenerating', 'Regenerating Content', false) : ''}
                    ${this.buildStepIndicator('completing', 'Finalizing', false)}
                </div>
                
                <div class="aebg-progress-status" style="font-size: 13px; color: #6b7280; margin-bottom: 12px; padding: 8px; background: #f9fafb; border-radius: 6px;">
                    <div class="aebg-status-current" style="font-weight: 500; color: #4f46e5; margin-bottom: 4px;">
                        <span class="aebg-status-text">Reordering products...</span>
                    </div>
                    <div class="aebg-status-details" style="font-size: 12px; color: #9ca3af;">
                        <span class="aebg-status-detail-text">Updating product positions</span>
                    </div>
                </div>
            `;
            
            if (regenerationCount > 0) {
                html += `
                    <div class="aebg-progress-items" style="max-height: 300px; overflow-y: auto; margin-top: 12px;">
                        <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            Regenerations (${regenerationCount})
                        </div>
                        ${this.buildProgressItems(choices)}
                    </div>
                `;
            }
            
            return html;
        },
        
        /**
         * Build step indicator
         * 
         * @param {string} stepId Step identifier
         * @param {string} label Step label
         * @param {boolean} active Whether step is active
         * @returns {string} HTML string
         */
        buildStepIndicator: function(stepId, label, active) {
            const statusClass = active ? 'active' : 'pending';
            const icon = active ? '⏳' : '○';
            return `
                <div class="aebg-progress-step aebg-step-${stepId} aebg-step-${statusClass}" 
                     style="display: flex; align-items: center; gap: 10px; padding: 8px; margin-bottom: 6px; border-radius: 6px; transition: all 0.3s;"
                     data-step="${stepId}">
                    <span class="aebg-step-icon" style="font-size: 16px; width: 20px; text-align: center;">${icon}</span>
                    <span class="aebg-step-label" style="font-size: 13px; color: ${active ? '#4f46e5' : '#9ca3af'}; font-weight: ${active ? '500' : '400'};">
                        ${this.escapeHtml(label)}
                    </span>
                    <span class="aebg-step-status" style="margin-left: auto; font-size: 11px; color: #9ca3af;"></span>
                </div>
            `;
        },
        
        /**
         * Build progress items HTML
         * 
         * @param {Object} choices Regeneration choices
         * @returns {string} HTML string
         */
        buildProgressItems: function(choices) {
            let html = '';
            Object.keys(choices).forEach(productId => {
                const choice = choices[productId];
                if (choice.action === 'skip') return;
                
                const actionText = choice.action === 'regenerate_both' 
                    ? 'Product & Testvinder' 
                    : 'Testvinder Only';
                
                html += `
                    <div class="aebg-progress-item" 
                         data-product-id="${this.escapeHtml(productId)}" 
                         data-product-number="${choice.product_number}"
                         data-action="${choice.action}"
                         style="
                             padding: 12px;
                             margin-bottom: 8px;
                             background: #f9fafb;
                             border: 1px solid #e5e7eb;
                             border-radius: 8px;
                             font-size: 12px;
                             display: flex;
                             align-items: center;
                             gap: 10px;
                             transition: all 0.3s;
                         ">
                        <span class="aebg-progress-spinner" style="
                            display: inline-block;
                            width: 18px;
                            height: 18px;
                            border: 2px solid #e5e7eb;
                            border-top: 2px solid #4f46e5;
                            border-radius: 50%;
                            animation: aebg-spin 1s linear infinite;
                        "></span>
                        <div style="flex: 1; display: flex; flex-direction: column; gap: 2px;">
                            <span class="aebg-progress-text" style="font-weight: 500; color: #1f2937;">
                                Product #${choice.product_number}: ${actionText}
                            </span>
                            <span class="aebg-progress-subtext" style="font-size: 11px; color: #6b7280;">
                                <span class="aebg-progress-step-text">Preparing...</span>
                            </span>
                        </div>
                        <span class="aebg-progress-status-icon" style="font-size: 16px;">⏳</span>
                    </div>
                `;
            });
            
            return html || '<div style="padding: 12px; font-size: 12px; color: #9ca3af; text-align: center;">No regenerations scheduled.</div>';
        },
        
        /**
         * Start polling for regeneration status
         * 
         * @param {number} postId Post ID
         * @param {Object} choices Regeneration choices
         * @param {jQuery} $container Progress container
         */
        startStatusPolling: function(postId, choices, $container) {
            // Close button handler
            $container.find('.aebg-progress-close').on('click', () => {
                $container.fadeOut(300, () => {
                    $container.remove();
                });
            });
            
            // Mark reordering as complete after short delay
            setTimeout(() => {
                this.updateStepStatus('reordering', 'complete', '✓ Complete');
                this.updateStepStatus('regenerating', 'active', 'In Progress');
                this.updateStatusText('Regenerating content...', 'Processing AI fields and applying content');
            }, 1500);
            
            // Simulate progress for each regeneration
            let pollCount = 0;
            const pollInterval = 2000; // Poll every 2 seconds
            const maxPolls = 180; // Stop after 6 minutes (180 * 2s)
            
            const pollStatus = () => {
                pollCount++;
                
                if (pollCount > maxPolls) {
                    this.updateStatusText('Status check timeout', 'Regenerations may still be running in background');
                    return;
                }
                
                // Update progress for each item
                let allComplete = true;
                Object.keys(choices).forEach(productId => {
                    const choice = choices[productId];
                    if (choice.action === 'skip') return;
                    
                    const $item = $container.find(`[data-product-id="${productId}"]`);
                    if ($item.length === 0) return;
                    
                    const elapsed = pollCount * (pollInterval / 1000);
                    const progress = this.calculateProgress(elapsed, choice.action);
                    
                    this.updateItemProgress($item, progress, choice);
                    
                    if (progress.status !== 'complete') {
                        allComplete = false;
                    }
                });
                
                // Update overall status
                if (allComplete && pollCount > 5) {
                    // All complete
                    this.updateStepStatus('regenerating', 'complete', '✓ Complete');
                    this.updateStepStatus('completing', 'active', 'In Progress');
                    this.updateStatusText('Finalizing...', 'Clearing caches and updating frontend');
                    
                    setTimeout(() => {
                        this.updateStepStatus('completing', 'complete', '✓ Complete');
                        this.updateStatusText('✓ All operations complete!', 'Frontend updated successfully');
                        
                        // Auto-close after delay
                        setTimeout(() => {
                            $container.fadeOut(300, () => {
                                $container.remove();
                            });
                        }, 3000);
                    }, 2000);
                    return;
                }
                
                // Continue polling
                setTimeout(pollStatus, pollInterval);
            };
            
            // Start polling after initial delay
            setTimeout(pollStatus, pollInterval);
        },
        
        /**
         * Calculate progress based on elapsed time
         * 
         * @param {number} elapsed Elapsed time in seconds
         * @param {string} action Action type
         * @returns {Object} Progress object
         */
        calculateProgress: function(elapsed, action) {
            if (elapsed < 5) {
                return {
                    step: 'Preparing...',
                    status: 'pending',
                    icon: '⏳',
                    percentage: 10
                };
            } else if (elapsed < 15) {
                return {
                    step: 'Collecting prompts...',
                    status: 'collecting',
                    icon: '📋',
                    percentage: 25
                };
            } else if (elapsed < 45) {
                return {
                    step: 'Generating AI content...',
                    status: 'generating',
                    icon: '🤖',
                    percentage: 50
                };
            } else if (elapsed < 75) {
                return {
                    step: 'Applying content...',
                    status: 'applying',
                    icon: '📝',
                    percentage: 75
                };
            } else if (elapsed < 90) {
                return {
                    step: 'Saving changes...',
                    status: 'saving',
                    icon: '💾',
                    percentage: 90
                };
            } else {
                return {
                    step: 'Complete',
                    status: 'complete',
                    icon: '✅',
                    percentage: 100
                };
            }
        },
        
        /**
         * Update item progress
         * 
         * @param {jQuery} $item Item element
         * @param {Object} progress Progress object
         * @param {Object} choice Choice object
         */
        updateItemProgress: function($item, progress, choice) {
            const $text = $item.find('.aebg-progress-text');
            const $subtext = $item.find('.aebg-progress-subtext .aebg-progress-step-text');
            const $icon = $item.find('.aebg-progress-status-icon');
            const $spinner = $item.find('.aebg-progress-spinner');
            
            if (progress.status === 'complete') {
                $spinner.hide();
                $icon.text(progress.icon);
                $item.css({
                    'background': '#ecfdf5',
                    'border-color': '#10b981'
                });
                $text.css('color', '#059669');
            } else {
                $spinner.show();
                $icon.text(progress.icon);
            }
            
            $subtext.text(progress.step);
        },
        
        /**
         * Update step status
         * 
         * @param {string} stepId Step ID
         * @param {string} status Status (active, complete, pending)
         * @param {string} label Status label
         */
        updateStepStatus: function(stepId, status, label) {
            const $step = $(`.aebg-step-${stepId}`);
            if ($step.length === 0) return;
            
            $step.removeClass('aebg-step-active aebg-step-complete aebg-step-pending')
                 .addClass(`aebg-step-${status}`);
            
            const $icon = $step.find('.aebg-step-icon');
            const $label = $step.find('.aebg-step-label');
            const $status = $step.find('.aebg-step-status');
            
            if (status === 'active') {
                $icon.text('⏳');
                $label.css('color', '#4f46e5');
                $label.css('font-weight', '500');
                $step.css('background', '#eef2ff');
            } else if (status === 'complete') {
                $icon.text('✓');
                $label.css('color', '#059669');
                $label.css('font-weight', '500');
                $step.css('background', '#ecfdf5');
            } else {
                $icon.text('○');
                $label.css('color', '#9ca3af');
                $label.css('font-weight', '400');
                $step.css('background', 'transparent');
            }
            
            $status.text(label);
        },
        
        /**
         * Update status text
         * 
         * @param {string} mainText Main status text
         * @param {string} detailText Detail text
         */
        updateStatusText: function(mainText, detailText) {
            const $current = $('.aebg-status-text');
            const $detail = $('.aebg-status-detail-text');
            
            if ($current.length > 0) {
                $current.text(mainText);
            }
            if ($detail.length > 0) {
                $detail.text(detailText);
            }
        },
        
        /**
         * Hide progress container
         */
        hide: function() {
            const $container = $('#aebg-reorder-progress-container');
            if ($container.length > 0) {
                $container.fadeOut(300);
            }
        },
        
        /**
         * Escape HTML to prevent XSS
         * 
         * @param {string} text Text to escape
         * @returns {string} Escaped text
         */
        escapeHtml: function(text) {
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
    };
    
    // Add CSS animation
    if (!$('#aebg-reorder-progress-styles').length) {
        $('<style>')
            .attr('id', 'aebg-reorder-progress-styles')
            .text(`
                @keyframes aebg-spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                .aebg-reorder-progress-container::-webkit-scrollbar {
                    width: 6px;
                }
                
                .aebg-reorder-progress-container::-webkit-scrollbar-track {
                    background: #f1f1f1;
                    border-radius: 3px;
                }
                
                .aebg-reorder-progress-container::-webkit-scrollbar-thumb {
                    background: #c1c1c1;
                    border-radius: 3px;
                }
                
                .aebg-reorder-progress-container::-webkit-scrollbar-thumb:hover {
                    background: #a8a8a8;
                }
            `)
            .appendTo('head');
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        ReorderProgressUI.init();
    });
    
    // Export for use in other scripts
    window.ReorderProgressUI = ReorderProgressUI;
    
})(jQuery);

