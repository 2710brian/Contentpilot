/**
 * Competitor Tracking JavaScript
 * 
 * Handles all frontend interactions for the competitor tracking page
 */

(function($) {
    'use strict';
    
    // Main competitor tracking object
    const CompetitorTracking = {
        
        /**
         * Initialize
         */
        init: function() {
            this.loadCompetitors();
            this.bindEvents();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;
            
            // Add competitor button
            $('#aebg-add-competitor-btn').on('click', function() {
                self.openAddModal();
            });
            
            // Close modals
            $(document).on('click', '.aebg-modal-close, #aebg-modal-cancel', function() {
                const $modal = $(this).closest('.aebg-modal');
                $modal.hide();
                
                // Clear competitor details content when modal is closed to ensure fresh data on next open
                if ($modal.attr('id') === 'aebg-competitor-details-modal') {
                    $('#aebg-competitor-details-content').html('<div class="aebg-loading">Loading details...</div>');
                }
            });
            
            // Interval dropdown change
            $('#aebg-competitor-interval').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#aebg-competitor-interval-custom').show().focus();
                } else {
                    $('#aebg-competitor-interval-custom').hide();
                }
            });
            
            // Form submission
            $('#aebg-competitor-form').on('submit', function(e) {
                e.preventDefault();
                self.saveCompetitor();
            });
            
            // Edit competitor (delegated)
            $(document).on('click', '.aebg-edit-competitor', function() {
                const competitorId = $(this).data('id');
                self.editCompetitor(competitorId);
            });
            
            // Delete competitor (delegated)
            $(document).on('click', '.aebg-delete-competitor', function() {
                if (confirm('Are you sure you want to delete this competitor? This action cannot be undone.')) {
                    const competitorId = $(this).data('id');
                    self.deleteCompetitor(competitorId);
                }
            });
            
            // Trigger scrape (delegated)
            $(document).on('click', '.aebg-trigger-scrape', function() {
                const competitorId = $(this).data('id');
                self.triggerScrape(competitorId);
            });
            
            // View details (delegated)
            $(document).on('click', '.aebg-view-details', function() {
                const competitorId = $(this).data('id');
                self.viewDetails(competitorId);
            });
        },
        
        /**
         * Load competitors list
         */
        loadCompetitors: function() {
            // Add cache-busting timestamp to ensure fresh data on each page load
            const cacheBuster = new Date().getTime();
            
            $.ajax({
                url: aebg.rest_url + 'competitors?_t=' + cacheBuster,
                type: 'GET',
                cache: false, // Disable browser caching
                headers: {
                    'X-WP-Nonce': aebg.rest_nonce
                },
                success: (response) => {
                    console.log('Competitors API response:', response);
                    
                    // WordPress REST API returns the data directly in the response
                    // Check if response is an array or if it's wrapped
                    let competitors = response;
                    if (response && typeof response === 'object' && !Array.isArray(response) && response.data) {
                        competitors = response.data;
                    }
                    
                    if (Array.isArray(competitors) && competitors.length > 0) {
                        this.renderCompetitors(competitors);
                    } else {
                        $('#aebg-competitors-tbody').html(
                            '<tr><td colspan="9">No competitors found. Add your first competitor to get started.</td></tr>'
                        );
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Error loading competitors:', xhr, status, error);
                    console.error('Response:', xhr.responseText);
                    $('#aebg-competitors-tbody').html(
                        '<tr><td colspan="9" class="aebg-error">Error loading competitors: ' + (xhr.responseJSON?.message || error || 'Unknown error') + '. Please check the browser console.</td></tr>'
                    );
                }
            });
        },
        
        /**
         * Render competitors table
         */
        renderCompetitors: function(competitors) {
            if (competitors.length === 0) {
                $('#aebg-competitors-tbody').html(
                    '<tr><td colspan="9">No competitors found. Add your first competitor to get started.</td></tr>'
                );
                return;
            }
            
            let html = '';
            competitors.forEach((competitor) => {
                // Show "Updating" if scraping is in progress, otherwise show Active/Inactive
                let statusClass, statusText;
                if (competitor.is_scraping) {
                    statusClass = 'aebg-status-updating';
                    statusText = 'Updating';
                } else {
                    statusClass = competitor.is_active ? 'aebg-status-active' : 'aebg-status-inactive';
                    statusText = competitor.is_active ? 'Active' : 'Inactive';
                }
                // Format interval - ensure it's a valid number
                const intervalValue = competitor.scraping_interval || 86400; // Default to Daily if missing
                const intervalText = this.formatInterval(intervalValue);
                const lastScraped = competitor.last_scraped_at ? this.formatDate(competitor.last_scraped_at) : 'Never';
                const nextScrape = competitor.next_scrape_at ? this.formatDate(competitor.next_scrape_at) : 'Not scheduled';
                const productCount = competitor.product_count || 0;
                const changesCount = competitor.changes_count || 0;
                
                html += `<tr data-competitor-id="${competitor.id}">`;
                html += `<td><strong>${this.escapeHtml(competitor.name)}</strong></td>`;
                html += `<td><a href="${this.escapeHtml(competitor.url)}" target="_blank">${this.escapeHtml(competitor.url)}</a></td>`;
                html += `<td><span class="aebg-status-badge ${statusClass}">${statusText}</span></td>`;
                html += `<td>${intervalText}</td>`;
                html += `<td>${lastScraped}</td>`;
                html += `<td>${nextScrape}</td>`;
                html += `<td>${productCount > 0 ? productCount : '-'}</td>`;
                html += `<td>${changesCount > 0 ? changesCount : '-'}</td>`;
                html += `<td class="aebg-actions">`;
                html += `<button class="aebg-btn-small aebg-btn-primary aebg-edit-competitor" data-id="${competitor.id}">Edit</button> `;
                html += `<button class="aebg-btn-small aebg-btn-secondary aebg-trigger-scrape" data-id="${competitor.id}">Scrape Now</button> `;
                html += `<button class="aebg-btn-small aebg-btn-secondary aebg-view-details" data-id="${competitor.id}">Details</button> `;
                html += `<button class="aebg-btn-small aebg-btn-danger aebg-delete-competitor" data-id="${competitor.id}">Delete</button>`;
                html += `</td>`;
                html += `</tr>`;
            });
            
            $('#aebg-competitors-tbody').html(html);
        },
        
        /**
         * Open add modal
         */
        openAddModal: function() {
            $('#aebg-competitor-form')[0].reset();
            $('#aebg-competitor-id').val('');
            $('#aebg-modal-title').text('Add Competitor');
            $('#aebg-competitor-interval').val('3600');
            $('#aebg-competitor-active').prop('checked', true);
            $('#aebg-competitor-interval-custom').hide();
            $('#aebg-competitor-modal').show();
        },
        
        /**
         * Save competitor
         */
        saveCompetitor: function() {
            const competitorId = $('#aebg-competitor-id').val();
            const action = competitorId ? 'aebg_update_competitor' : 'aebg_add_competitor';
            
            // Convert custom days to seconds, or use predefined interval
            let interval;
            if ($('#aebg-competitor-interval').val() === 'custom') {
                const customDays = parseInt($('#aebg-competitor-interval-custom').val());
                if (isNaN(customDays) || customDays < 1) {
                    alert('Please enter a valid number of days (minimum 1 day).');
                    return;
                }
                interval = customDays * 86400; // Convert days to seconds
            } else {
                interval = parseInt($('#aebg-competitor-interval').val());
            }
            
            const formData = {
                action: action,
                nonce: aebg.ajax_nonce,
                name: $('#aebg-competitor-name').val(),
                url: $('#aebg-competitor-url').val(),
                scraping_interval: interval,
                is_active: $('#aebg-competitor-active').is(':checked') ? 1 : 0
            };
            
            if (competitorId) {
                formData.competitor_id = competitorId;
            }
            
            $.ajax({
                url: aebg.ajaxurl,
                type: 'POST',
                data: formData,
                success: (response) => {
                    if (response.success) {
                        $('#aebg-competitor-modal').hide();
                        this.loadCompetitors();
                        alert(response.data.message || 'Success!');
                    } else {
                        alert(response.data.message || 'Error occurred');
                    }
                },
                error: () => {
                    alert('An error occurred. Please try again.');
                }
            });
        },
        
        /**
         * Edit competitor
         */
        editCompetitor: function(competitorId) {
            // Add cache-busting timestamp to ensure fresh data
            const cacheBuster = new Date().getTime();
            
            $.ajax({
                url: aebg.rest_url + 'competitors/' + competitorId + '?_t=' + cacheBuster,
                type: 'GET',
                cache: false, // Disable browser caching
                headers: {
                    'X-WP-Nonce': aebg.rest_nonce
                },
                success: (response) => {
                    if (response) {
                        $('#aebg-competitor-id').val(response.id);
                        $('#aebg-competitor-name').val(response.name);
                        $('#aebg-competitor-url').val(response.url);
                        
                        // Convert seconds to days for display, or set to custom if not a standard interval
                        const intervalSeconds = parseInt(response.scraping_interval);
                        const intervalDays = intervalSeconds / 86400;
                        
                        // Check if it matches a standard interval
                        if (intervalSeconds === 86400) {
                            $('#aebg-competitor-interval').val('86400'); // Daily
                            $('#aebg-competitor-interval-custom').hide();
                        } else if (intervalSeconds === 604800) {
                            $('#aebg-competitor-interval').val('604800'); // 7 Days
                            $('#aebg-competitor-interval-custom').hide();
                        } else if (intervalSeconds === 1209600) {
                            $('#aebg-competitor-interval').val('1209600'); // 14 Days
                            $('#aebg-competitor-interval-custom').hide();
                        } else if (intervalSeconds === 2592000) {
                            $('#aebg-competitor-interval').val('2592000'); // 30 Days
                            $('#aebg-competitor-interval-custom').hide();
                        } else {
                            // Custom interval - show custom input with days
                            $('#aebg-competitor-interval').val('custom');
                            $('#aebg-competitor-interval-custom').val(Math.round(intervalDays)).show();
                        }
                        
                        $('#aebg-competitor-active').prop('checked', response.is_active == 1);
                        $('#aebg-modal-title').text('Edit Competitor');
                        $('#aebg-competitor-modal').show();
                    }
                }
            });
        },
        
        /**
         * Delete competitor
         */
        deleteCompetitor: function(competitorId) {
            $.ajax({
                url: aebg.rest_url + 'competitors/' + competitorId,
                type: 'DELETE',
                headers: {
                    'X-WP-Nonce': aebg.rest_nonce
                },
                success: () => {
                    this.loadCompetitors();
                    alert('Competitor deleted successfully.');
                },
                error: () => {
                    alert('Error deleting competitor.');
                }
            });
        },
        
        /**
         * Trigger scrape
         */
        triggerScrape: function(competitorId) {
            const $button = $(`.aebg-trigger-scrape[data-id="${competitorId}"]`);
            const originalText = $button.text();
            $button.prop('disabled', true).text('Starting...');
            
            $.ajax({
                url: aebg.rest_url + 'competitors/' + competitorId + '/scrape',
                type: 'POST',
                headers: {
                    'X-WP-Nonce': aebg.rest_nonce
                },
                success: () => {
                    // Reload immediately to show "Updating" status
                    this.loadCompetitors();
                    
                    // Start polling to refresh table while scraping
                    this.startPolling(competitorId);
                },
                error: () => {
                    $button.prop('disabled', false).text(originalText);
                    alert('Error scheduling scrape.');
                }
            });
        },
        
        /**
         * Start polling to refresh table while scraping is in progress
         */
        startPolling: function(competitorId) {
            // Clear any existing polling for this competitor
            if (this.pollingIntervals && this.pollingIntervals[competitorId]) {
                clearInterval(this.pollingIntervals[competitorId]);
            }
            
            if (!this.pollingIntervals) {
                this.pollingIntervals = {};
            }
            
            let pollCount = 0;
            const maxPolls = 60; // Poll for up to 5 minutes (60 * 5 seconds)
            
            this.pollingIntervals[competitorId] = setInterval(() => {
                pollCount++;
                
                // Reload competitors to check status
                this.loadCompetitors();
                
                // Check if scraping is still in progress
                const $row = $(`tr[data-competitor-id="${competitorId}"]`);
                const $statusBadge = $row.find('.aebg-status-badge');
                
                // Stop polling if status is no longer "Updating" or max polls reached
                if (!$statusBadge.hasClass('aebg-status-updating') || pollCount >= maxPolls) {
                    clearInterval(this.pollingIntervals[competitorId]);
                    delete this.pollingIntervals[competitorId];
                    
                    // Final refresh to show updated data
                    if (pollCount < maxPolls) {
                        this.loadCompetitors();
                    }
                }
            }, 5000); // Poll every 5 seconds
        },
        
        /**
         * View details
         */
        viewDetails: function(competitorId) {
            // Always show loading state and clear any cached content
            $('#aebg-competitor-details-content').html('<div class="aebg-loading">Loading details...</div>');
            $('#aebg-competitor-details-modal').show();
            
            // Add cache-busting timestamp to ensure fresh data
            const cacheBuster = new Date().getTime();
            
            $.ajax({
                url: aebg.ajaxurl,
                type: 'GET',
                cache: false, // Disable browser caching
                data: {
                    action: 'aebg_get_competitor_history',
                    nonce: aebg.ajax_nonce,
                    competitor_id: competitorId,
                    _t: cacheBuster // Cache-busting parameter
                },
                success: (response) => {
                    if (response.success) {
                        this.renderDetails(response.data);
                    } else {
                        $('#aebg-competitor-details-content').html('<div class="aebg-error">Error loading details.</div>');
                    }
                },
                error: () => {
                    $('#aebg-competitor-details-content').html('<div class="aebg-error">Error loading details.</div>');
                }
            });
        },
        
        /**
         * Render details
         */
        renderDetails: function(data) {
            let html = '<div class="aebg-details-sections">';
            
            // Products section
            html += '<div class="aebg-details-section">';
            
            // Header with "Current Products" and "Last updated" on same line
            const lastUpdated = data.competitor && data.competitor.last_scraped_at 
                ? this.formatDate(data.competitor.last_scraped_at) 
                : 'Never';
            html += '<div class="aebg-details-header">';
            html += `<h3>Current Products (${data.products ? data.products.length : 0})</h3>`;
            html += `<span class="aebg-last-updated">Last updated: ${lastUpdated}</span>`;
            html += '</div>';
            
            // Website URL below the header
            if (data.competitor && data.competitor.url) {
                html += `<div class="aebg-website-url"><strong>Website url:</strong> <a href="${this.escapeHtml(data.competitor.url)}" target="_blank">${this.escapeHtml(data.competitor.url)}</a></div>`;
            }
            
            html += '<table class="aebg-details-table">';
            html += '<thead><tr><th>Position</th><th>Product Name</th><th>Price</th><th>Network</th><th>Merchant</th><th>Affiliate Link</th><th>Change</th></tr></thead>';
            html += '<tbody>';
            if (data.products && data.products.length > 0) {
                data.products.forEach((product) => {
                    // Parse product_data if it's a string
                    let productData = product.product_data;
                    if (typeof productData === 'string') {
                        try {
                            productData = JSON.parse(productData);
                        } catch (e) {
                            productData = {};
                        }
                    }
                    if (!productData) {
                        productData = {};
                    }
                    
                    // Extract fields from product_data (priority order matters)
                    // Price: actual price, not savings - blank if not found
                    const price = productData.price || productData.Price || productData.actual_price || productData.current_price || '';
                    
                    // Network: affiliate network name - blank if not found
                    const network = productData.network || productData.Network || productData.network_name || productData.affiliate_network || '';
                    
                    // Merchant: store/merchant name - blank if not found
                    const merchant = productData.merchant || productData.Merchant || productData.merchant_name || productData.store || productData.shop || '';
                    
                    // Affiliate link: primary field is affiliate_link, fallback to url or product_url
                    // Only use if it's a real link (not example/placeholder)
                    let affiliateLink = productData.affiliate_link || productData.affiliateLink || productData.affiliate_url || productData.url || product.product_url || '';
                    
                    // Filter out example/placeholder links
                    if (affiliateLink) {
                        const lowerLink = affiliateLink.toLowerCase();
                        // Check if it's an example/placeholder link
                        if (lowerLink.includes('example') || 
                            lowerLink.includes('placeholder') || 
                            lowerLink.includes('test-link') ||
                            lowerLink.includes('sample') ||
                            lowerLink.startsWith('http://example') ||
                            lowerLink.startsWith('https://example')) {
                            affiliateLink = ''; // Don't show example links
                        }
                    }
                    
                    let changeClass = '';
                    let changeText = '';
                    if (product.position_change !== null) {
                        if (product.position_change > 0) {
                            changeClass = 'aebg-change-up';
                            changeText = '↑ +' + product.position_change;
                        } else if (product.position_change < 0) {
                            changeClass = 'aebg-change-down';
                            changeText = '↓ ' + product.position_change;
                        } else {
                            changeText = '—';
                        }
                    }
                    if (product.is_new) {
                        changeText = '<span class="aebg-badge-new">NEW</span>';
                    }
                    
                    // Format affiliate link - only show if it's a real link
                    let affiliateLinkHtml = '';
                    if (affiliateLink && affiliateLink.trim() !== '') {
                        // Validate it's a real URL
                        try {
                            const url = new URL(affiliateLink);
                            // Only show if it's a valid URL and not an example
                            affiliateLinkHtml = `<a href="${this.escapeHtml(affiliateLink)}" target="_blank" rel="noopener noreferrer" class="aebg-affiliate-link">${this.escapeHtml(affiliateLink.length > 50 ? affiliateLink.substring(0, 50) + '...' : affiliateLink)}</a>`;
                        } catch (e) {
                            // Invalid URL, don't show
                            affiliateLinkHtml = '';
                        }
                    }
                    
                    html += '<tr>';
                    html += `<td>${product.position}</td>`;
                    html += `<td>${this.escapeHtml(product.product_name)}</td>`;
                    html += `<td>${price ? this.escapeHtml(price) : ''}</td>`;
                    html += `<td>${network ? this.escapeHtml(network) : ''}</td>`;
                    html += `<td>${merchant ? this.escapeHtml(merchant) : ''}</td>`;
                    html += `<td class="aebg-affiliate-link-cell">${affiliateLinkHtml}</td>`;
                    html += `<td class="${changeClass}">${changeText}</td>`;
                    html += '</tr>';
                });
            } else {
                html += '<tr><td colspan="7">No products found</td></tr>';
            }
            html += '</tbody></table>';
            html += '</div>';
            
            // Recent changes
            html += '<div class="aebg-details-section">';
            html += `<h3>Recent Changes (${data.changes ? data.changes.length : 0})</h3>`;
            html += '<div class="aebg-changes-list">';
            if (data.changes && data.changes.length > 0) {
                data.changes.slice(0, 10).forEach((change) => {
                    html += `<div class="aebg-change-item aebg-severity-${change.change_severity}">`;
                    html += `<strong>${this.escapeHtml(change.change_type)}</strong>: `;
                    if (change.product_name) {
                        html += this.escapeHtml(change.product_name) + ' ';
                    }
                    if (change.old_value && change.new_value) {
                        html += `(${this.escapeHtml(change.old_value)} → ${this.escapeHtml(change.new_value)})`;
                    }
                    html += ` <span class="aebg-change-time">${this.formatDate(change.created_at)}</span>`;
                    html += '</div>';
                });
            } else {
                html += '<p>No changes detected yet.</p>';
            }
            html += '</div>';
            html += '</div>';
            
            html += '</div>';
            $('#aebg-competitor-details-content').html(html);
        },
        
        /**
         * Format interval
         */
        formatInterval: function(seconds) {
            // Ensure seconds is a number
            const secs = parseInt(seconds, 10);
            if (isNaN(secs) || secs <= 0) {
                return 'Daily'; // Default fallback
            }
            
            // Convert to days for display
            const days = secs / 86400;
            
            // Standard intervals (exact match)
            if (secs === 86400) return 'Daily';
            if (secs === 604800) return '7 Days';
            if (secs === 1209600) return '14 Days';
            if (secs === 2592000) return '30 Days';
            
            // Custom intervals - show as days
            if (days >= 1) {
                const roundedDays = Math.round(days);
                return roundedDays === 1 ? '1 Day' : roundedDays + ' Days';
            }
            
            // Fallback for very short intervals (shouldn't happen with new options)
            if (secs < 60) return secs + 's';
            if (secs < 3600) return Math.round(secs / 60) + 'm';
            if (secs < 86400) return Math.round(secs / 3600) + 'h';
            return Math.round(days) + ' Days';
        },
        
        /**
         * Format date
         */
        formatDate: function(dateString) {
            if (!dateString) return 'Never';
            const date = new Date(dateString);
            return date.toLocaleString();
        },
        
        /**
         * Escape HTML
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
            return text.replace(/[&<>"']/g, (m) => map[m]);
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        CompetitorTracking.init();
    });
    
})(jQuery);

