/**
 * Reorder Conflict Handler
 * 
 * Handles conflict detection and user choice collection for product reordering
 * when testvinder containers exist at target positions.
 * 
 * @package AEBG
 */

(function($) {
    'use strict';
    
    /**
     * Reorder Conflict Handler
     * Handles conflict detection and user choice collection
     */
    const ReorderConflictHandler = {
        
        /**
         * Initialize conflict handler
         */
        init: function() {
            // Bind events
            $(document).on('click', '.aebg-btn-proceed', this.handleProceed.bind(this));
            $(document).on('click', '.aebg-btn-cancel', this.handleCancel.bind(this));
            $(document).on('change', '.aebg-regeneration-choice', this.updateChoice.bind(this));
        },
        
        /**
         * Detect conflicts before reordering
         * 
         * @param {number} postId Post ID
         * @param {Array} newOrder New product order
         * @returns {Promise} AJAX promise
         */
        detectConflicts: function(postId, newOrder) {
            return $.ajax({
                url: aebg_reorder.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aebg_detect_reorder_conflicts',
                    _ajax_nonce: aebg_reorder.nonce_detect,
                    post_id: postId,
                    new_product_order: JSON.stringify(newOrder)
                },
                dataType: 'json'
            });
        },
        
        /**
         * Show conflict modal
         * 
         * @param {Array} conflicts Array of conflict objects
         * @param {number} postId Post ID
         * @param {Array} newOrder New product order
         */
        showConflictModal: function(conflicts, postId, newOrder) {
            const $modal = $('#aebg-reorder-conflict-modal');
            const $list = $('#aebg-conflicts-list');
            
            // Store data in modal for later use
            $modal.data('post-id', postId);
            $modal.data('new-order', newOrder);
            
            $list.empty();
            
            if (conflicts.length === 0) {
                $list.html('<p>' + this.escapeHtml('No conflicts detected.') + '</p>');
                return;
            }
            
            conflicts.forEach((conflict, index) => {
                const $item = this.createConflictItem(conflict, index);
                $list.append($item);
            });
            
            $modal.addClass('show');
        },
        
        /**
         * Create conflict item HTML
         * 
         * @param {Object} conflict Conflict object
         * @param {number} index Item index
         * @returns {jQuery} Conflict item element
         */
        createConflictItem: function(conflict, index) {
            const $item = $('<div>').addClass('aebg-conflict-item');
            
            const productName = this.escapeHtml(conflict.product_name || 'Unknown Product');
            const oldPosition = conflict.old_position || 0;
            const newPosition = conflict.new_position || 0;
            const testvinderId = this.escapeHtml(conflict.testvinder_css_id || '');
            
            $item.html(`
                <div class="aebg-conflict-info">
                    <h4>${productName}</h4>
                    <p class="aebg-conflict-movement">
                        Moving from position #${oldPosition} to position #${newPosition}
                    </p>
                    <p class="aebg-conflict-warning">
                        <span class="dashicons dashicons-warning"></span>
                        Position #${newPosition} has a testvinder container (${testvinderId})
                    </p>
                </div>
                <div class="aebg-conflict-choices">
                    <label class="aebg-choice-label">
                        <input type="radio" 
                               name="regeneration_${index}" 
                               value="regenerate_both"
                               class="aebg-regeneration-choice"
                               data-product-id="${this.escapeHtml(conflict.product_id || '')}"
                               data-product-number="${newPosition}"
                               checked>
                        <span>Regenerate Product Container And Testvinder</span>
                    </label>
                    <label class="aebg-choice-label">
                        <input type="radio" 
                               name="regeneration_${index}" 
                               value="regenerate_testvinder_only"
                               class="aebg-regeneration-choice"
                               data-product-id="${this.escapeHtml(conflict.product_id || '')}"
                               data-product-number="${newPosition}">
                        <span>Regenerate Testvinder Only</span>
                    </label>
                    <label class="aebg-choice-label">
                        <input type="radio" 
                               name="regeneration_${index}" 
                               value="skip"
                               class="aebg-regeneration-choice"
                               data-product-id="${this.escapeHtml(conflict.product_id || '')}"
                               data-product-number="${newPosition}">
                        <span>Skip Regeneration</span>
                    </label>
                </div>
            `);
            
            return $item;
        },
        
        /**
         * Collect user choices
         * 
         * @returns {Object} Choices object
         */
        collectChoices: function() {
            const choices = {};
            
            $('.aebg-regeneration-choice:checked').each(function() {
                const $input = $(this);
                const productId = $input.data('product-id');
                const productNumber = $input.data('product-number');
                const action = $input.val();
                
                if (productId && productNumber) {
                    choices[productId] = {
                        action: action,
                        product_number: productNumber
                    };
                }
            });
            
            return choices;
        },
        
        /**
         * Handle proceed button
         */
        handleProceed: function() {
            const $modal = $('#aebg-reorder-conflict-modal');
            const postId = $modal.data('post-id');
            const newOrder = $modal.data('new-order');
            
            if (!postId || !newOrder) {
                console.error('Missing post ID or new order');
                if (typeof showMessage === 'function') {
                    showMessage('Error: Missing data. Please try again.', 'error');
                }
                return;
            }
            
            const choices = this.collectChoices();
            
            // Execute reordering with choices
            this.executeReordering(postId, newOrder, choices);
        },
        
        /**
         * Handle cancel button
         */
        handleCancel: function() {
            $('#aebg-reorder-conflict-modal').removeClass('show');
        },
        
        /**
         * Update choice (for logging/debugging)
         */
        updateChoice: function(e) {
            const $input = $(e.target);
            const productId = $input.data('product-id');
            const action = $input.val();
            
            if (typeof console !== 'undefined' && console.debug) {
                console.debug('Regeneration choice updated', {
                    productId: productId,
                    action: action
                });
            }
        },
        
        /**
         * Execute reordering with regeneration choices
         * 
         * @param {number} postId Post ID
         * @param {Array} newOrder New product order
         * @param {Object} choices Regeneration choices
         */
        executeReordering: function(postId, newOrder, choices) {
            const $modal = $('#aebg-reorder-conflict-modal');
            const $proceedBtn = $modal.find('.aebg-btn-proceed');
            
            // Disable button during processing
            $proceedBtn.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: aebg_reorder.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aebg_execute_reorder_with_choices',
                    _ajax_nonce: aebg_reorder.nonce,
                    post_id: postId,
                    new_product_order: JSON.stringify(newOrder),
                    regeneration_choices: JSON.stringify(choices)
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        // Close modal
                        $modal.removeClass('show');
                        
                        // Remove loading state from product rows
                        $('.aebg-product-row').removeClass('updating');
                        
                        // Show visual progress UI
                        if (typeof window.ReorderProgressUI !== 'undefined') {
                            window.ReorderProgressUI.showReorderingProgress(
                                postId,
                                newOrder,
                                choices,
                                response.data
                            );
                        }
                        
                        // Update product order in UI without reload
                        // Use existing updateFrontendDynamically function for consistency
                        if (typeof updateFrontendDynamically === 'function') {
                            updateFrontendDynamically(newOrder, response.data);
                        } else {
                            // Fallback: update UI manually
                            this.updateProductOrderInUI(newOrder);
                        }
                        
                        // Also update hidden inputs and counts
                        if (typeof updateProductIds === 'function') {
                            updateProductIds();
                        }
                        if (typeof updateProductsCount === 'function') {
                            updateProductsCount();
                        }
                        
                        // Show success message (progress UI will show detailed status)
                        if (typeof showMessage === 'function') {
                            const regenerationCount = Object.keys(choices).filter(id => choices[id].action !== 'skip').length;
                            if (regenerationCount > 0) {
                                showMessage('Product order updated! Regenerations are running in the background.', 'success');
                            } else {
                                showMessage('Product order updated successfully!', 'success');
                            }
                        }
                    } else {
                        if (typeof showMessage === 'function') {
                            showMessage('Error: ' + (response.data?.message || 'Unknown error'), 'error');
                        }
                        $proceedBtn.prop('disabled', false).text('Proceed with Reordering');
                    }
                },
                error: (xhr) => {
                    console.error('Reorder error:', xhr);
                    if (typeof showMessage === 'function') {
                        showMessage('Error updating product order. Please try again.', 'error');
                    }
                    $proceedBtn.prop('disabled', false).text('Proceed with Reordering');
                }
            });
        },
        
        /**
         * Update product order in UI without reload
         * 
         * @param {Array} newOrder New product order
         */
        updateProductOrderInUI: function(newOrder) {
            // Get the product table
            const $table = $('.aebg-associated-products-container .aebg-associated-products-table tbody');
            if ($table.length === 0) {
                console.warn('Product table not found for UI update');
                return;
            }
            
            // Create a map of product ID to row
            const rowMap = {};
            $table.find('tr').each(function() {
                const productId = $(this).data('product-id');
                if (productId) {
                    rowMap[productId] = $(this).detach(); // Detach to preserve event handlers
                }
            });
            
            // Reorder rows based on newOrder
            $table.empty();
            newOrder.forEach(productId => {
                if (rowMap[productId]) {
                    $table.append(rowMap[productId]);
                }
            });
            
            // Update hidden input with new order
            if (typeof updateProductIds === 'function') {
                updateProductIds();
            }
            
            // Update products count
            if (typeof updateProductsCount === 'function') {
                updateProductsCount();
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
    
    /**
     * Update product order in UI manually (fallback)
     * 
     * @param {Array} newOrder New product order
     */
    function updateProductOrderInUIManually(newOrder) {
        const $table = $('.aebg-associated-products-container .aebg-associated-products-table tbody');
        if ($table.length === 0) return;
        
        const rowMap = {};
        $table.find('tr').each(function() {
            const productId = $(this).data('product-id');
            if (productId) {
                rowMap[productId] = $(this).detach();
            }
        });
        
        $table.empty();
        newOrder.forEach(productId => {
            if (rowMap[productId]) {
                $table.append(rowMap[productId]);
            }
        });
        
        if (typeof updateProductIds === 'function') {
            updateProductIds();
        }
        
        if (typeof updateProductsCount === 'function') {
            updateProductsCount();
        }
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        ReorderConflictHandler.init();
    });
    
    // Export for use in edit-posts.js
    window.ReorderConflictHandler = ReorderConflictHandler;
    
})(jQuery);

