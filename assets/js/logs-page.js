/**
 * Logs Page JavaScript
 * Handles log display, filtering, search, and export functionality
 */

(function($) {
    'use strict';

    const LogsPage = {
        // Configuration
        config: {
            apiUrl: aebg.rest_url + 'logs',
            statsUrl: aebg.rest_url + 'logs/stats',
            nonce: aebg.rest_nonce,
            perPage: 50,
            debounceDelay: 300
        },

        // State
        state: {
            currentPage: 1,
            filters: {},
            searchQuery: '',
            loading: false,
            hasMore: true,
            allLogs: [],
            sortColumn: 'timestamp',
            sortDirection: 'desc' // 'asc' or 'desc'
        },

        // Initialize
        init: function() {
            this.bindEvents();
            this.updateSortIndicators(); // Initialize sort indicators
            this.loadLogs(true);
            this.loadStats();
        },

        // Event bindings
        bindEvents: function() {
            const self = this;

            // Search input with debounce
            $('#aebg-logs-search').on('input', this.debounce(function(e) {
                self.handleSearch(e);
            }, this.config.debounceDelay));

            // Filter changes (for selects)
            $('.aebg-logs-filter').on('change', function(e) {
                self.handleFilterChange(e);
            });

            // Input field changes (for number and date inputs)
            $('#aebg-logs-batch-id').on('input', this.debounce(function(e) {
                self.handleFilterChange(e);
            }, this.config.debounceDelay));

            // Date range inputs
            $('#aebg-logs-date-from, #aebg-logs-date-to').on('change', function(e) {
                self.handleDateChange(e);
            });

            // Refresh button
            $('#aebg-refresh-logs').on('click', function() {
                self.refreshLogs();
            });

            // Export button
            $('#aebg-export-logs').on('click', function() {
                self.exportLogs();
            });

            // Clear filters
            $('#aebg-clear-filters, #aebg-clear-filters-empty').on('click', function() {
                self.clearFilters();
            });

            // Infinite scroll
            $(window).on('scroll', this.debounce(function() {
                self.handleScroll();
            }, 100));

            // Modal close
            $('.aebg-modal-close, .aebg-modal').on('click', function(e) {
                if (e.target === this || $(e.target).hasClass('aebg-modal-close')) {
                    $('#aebg-log-detail-modal').hide();
                }
            });

            // Prevent modal from closing when clicking inside
            $('.aebg-modal-content').on('click', function(e) {
                e.stopPropagation();
            });

            // Expand log entry
            $(document).on('click', '[data-action="expand"]', function() {
                const $entry = $(this).closest('.aebg-log-entry');
                const logId = $entry.data('log-id');
                self.showLogDetails(logId);
            });

            // Sortable column headers
            $('.aebg-sortable').on('click', function() {
                const column = $(this).data('sort');
                self.handleSort(column);
            });
        },

        // Load logs from API
        loadLogs: function(reset = false) {
            if (this.state.loading) return;

            if (reset) {
                this.state.currentPage = 1;
                this.state.hasMore = true;
                this.state.allLogs = [];
            }

            this.state.loading = true;
            this.showLoading();

            const params = {
                page: this.state.currentPage,
                per_page: this.config.perPage,
                search: this.state.searchQuery,
                ...this.state.filters
            };

            // Remove empty filters
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null || params[key] === undefined) {
                    delete params[key];
                }
            });

            $.ajax({
                url: this.config.apiUrl,
                method: 'GET',
                data: params,
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                }
            })
            .done((response) => {
                if (response.data && Array.isArray(response.data)) {
                    this.renderLogs(response.data, reset);
                    this.state.hasMore = response.has_more || false;
                    this.state.currentPage++;
                } else {
                    this.showError('Invalid response format');
                }
            })
            .fail((error) => {
                const errorMsg = error.responseJSON?.message || error.statusText || 'Unknown error';
                this.showError('Failed to load logs: ' + errorMsg);
            })
            .always(() => {
                this.state.loading = false;
                this.hideLoading();
            });
        },

        // Render logs
        renderLogs: function(logs, reset = false) {
            const $container = $('#aebg-logs-content');

            if (reset) {
                $container.empty();
                this.state.allLogs = [];
            }

            if (logs.length === 0 && reset) {
                this.showEmptyState();
                return;
            }

            this.hideEmptyState();

            // Store logs
            this.state.allLogs = this.state.allLogs.concat(logs);

            // Sort all logs before rendering
            const sortedLogs = this.sortLogs(this.state.allLogs);

            // Clear and re-render all logs with sorting
            $container.empty();
            const html = sortedLogs.map(log => this.renderLogEntry(log)).join('');
            $container.append(html);
        },

        // Render single log entry
        renderLogEntry: function(log) {
            const timestamp = this.formatDate(log.timestamp);
            const message = this.escapeHtml(log.message);
            const hasContext = log.context && Object.keys(log.context).length > 0;
            const contextJson = hasContext ? JSON.stringify(log.context, null, 2) : '';

            return `
                <div class="aebg-log-entry" data-log-id="${log.id}" data-level="${log.level}">
                    <div class="aebg-log-level ${log.level}">${log.level}</div>
                    <div class="aebg-log-message">${message}</div>
                    <div class="aebg-log-timestamp">${timestamp}</div>
                    <div class="aebg-log-type">${log.type.replace('_', ' ')}</div>
                    <div class="aebg-log-actions">
                        ${hasContext ? `<button class="aebg-btn-icon" data-action="expand" title="View details">
                            <span class="aebg-icon">🔍</span>
                        </button>` : ''}
                    </div>
                    ${hasContext ? `<div class="aebg-log-context" style="display:none;" data-context='${this.escapeHtml(contextJson)}'></div>` : ''}
                </div>
            `;
        },

        // Search handler
        handleSearch: function(e) {
            this.state.searchQuery = $(e.target).val().trim();
            this.loadLogs(true);
        },

        // Filter handler
        handleFilterChange: function(e) {
            const $filter = $(e.target);
            let key = $filter.data('filter-key');
            
            // Fallback to extracting from ID if data attribute not set
            if (!key) {
                const id = $filter.attr('id') || '';
                key = id.replace('aebg-logs-', '');
            }
            
            let value = $filter.val();

            // Handle number inputs
            if ($filter.attr('type') === 'number') {
                value = value ? parseInt(value, 10) : '';
            }

            if (value && value !== '') {
                this.state.filters[key] = value;
            } else {
                delete this.state.filters[key];
            }

            this.loadLogs(true);
        },

        // Date change handler
        handleDateChange: function(e) {
            const $input = $(e.target);
            let key = $input.data('filter-key');
            
            // Fallback to extracting from ID if data attribute not set
            if (!key) {
                const id = $input.attr('id') || '';
                key = id.replace('aebg-logs-', '');
            }
            
            const value = $input.val();

            if (value) {
                this.state.filters[key] = value;
            } else {
                delete this.state.filters[key];
            }

            this.loadLogs(true);
        },

        // Load stats
        loadStats: function() {
            const self = this;
            $.ajax({
                url: this.config.statsUrl,
                method: 'GET',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                }
            })
            .done((response) => {
                // Handle both direct data and nested data structure
                const stats = response.data || response;
                if (stats) {
                    self.renderStats(stats);
                } else {
                    console.error('Invalid stats response:', response);
                }
            })
            .fail((error) => {
                console.error('Failed to load stats:', error);
                // Show error in stats cards
                $('#aebg-stat-total, #aebg-stat-success, #aebg-stat-warning, #aebg-stat-error, #aebg-stat-error-rate, #aebg-stat-recent').text('Error');
            });
        },

        // Render stats
        renderStats: function(stats) {
            const logsByLevel = stats.logs_by_level || {};
            
            // Use total_logs if available, otherwise calculate from levels
            const total = stats.total_logs || 
                         ((logsByLevel.info || 0) + (logsByLevel.error || 0) + 
                          (logsByLevel.success || 0) + (logsByLevel.warning || 0));

            $('#aebg-stat-total').text(this.formatNumber(total));
            $('#aebg-stat-success').text(this.formatNumber(logsByLevel.success || 0));
            $('#aebg-stat-warning').text(this.formatNumber(logsByLevel.warning || 0));
            $('#aebg-stat-error').text(this.formatNumber(logsByLevel.error || 0));
            
            const errorRate = stats.error_rate || 0;
            $('#aebg-stat-error-rate').text(errorRate.toFixed(1) + '%');
            
            $('#aebg-stat-recent').text(this.formatNumber(stats.recent_batches || 0));
        },

        // Handle column sorting
        handleSort: function(column) {
            // Toggle direction if clicking the same column, otherwise default to desc
            if (this.state.sortColumn === column) {
                this.state.sortDirection = this.state.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.state.sortColumn = column;
                this.state.sortDirection = 'desc';
            }

            // Update sort indicators
            this.updateSortIndicators();

            // Re-render logs with new sort
            this.renderLogs([], false);
        },

        // Sort logs array
        sortLogs: function(logs) {
            const column = this.state.sortColumn;
            const direction = this.state.sortDirection;
            const multiplier = direction === 'asc' ? 1 : -1;

            return [...logs].sort((a, b) => {
                let aVal, bVal;

                switch (column) {
                    case 'level':
                        // Sort by level priority: error > warning > info > success
                        const levelOrder = { error: 0, warning: 1, info: 2, success: 3 };
                        aVal = levelOrder[a.level] !== undefined ? levelOrder[a.level] : 99;
                        bVal = levelOrder[b.level] !== undefined ? levelOrder[b.level] : 99;
                        break;
                    case 'message':
                        aVal = (a.message || '').toLowerCase();
                        bVal = (b.message || '').toLowerCase();
                        break;
                    case 'timestamp':
                        aVal = new Date(a.timestamp || 0).getTime();
                        bVal = new Date(b.timestamp || 0).getTime();
                        break;
                    case 'type':
                        aVal = (a.type || '').toLowerCase();
                        bVal = (b.type || '').toLowerCase();
                        break;
                    default:
                        return 0;
                }

                if (aVal < bVal) return -1 * multiplier;
                if (aVal > bVal) return 1 * multiplier;
                return 0;
            });
        },

        // Update sort indicators in headers
        updateSortIndicators: function() {
            $('.aebg-sortable').each(function() {
                const $header = $(this);
                const column = $header.data('sort');
                const $icon = $header.find('.aebg-sort-icon');

                if (column === LogsPage.state.sortColumn) {
                    $header.addClass('aebg-sort-active');
                    $icon.text(LogsPage.state.sortDirection === 'asc' ? '↑' : '↓');
                } else {
                    $header.removeClass('aebg-sort-active');
                    $icon.text('');
                }
            });
        },

        // Clear all filters
        clearFilters: function() {
            this.state.filters = {};
            this.state.searchQuery = '';
            $('.aebg-logs-filter').val('');
            $('#aebg-logs-search').val('');
            this.loadLogs(true);
        },

        // Refresh logs
        refreshLogs: function() {
            this.loadLogs(true);
            this.loadStats();
        },

        // Export logs
        exportLogs: function() {
            const params = new URLSearchParams({
                ...this.state.filters,
                search: this.state.searchQuery,
                format: 'csv',
                export: '1',
                per_page: '10000' // Export all
            });

            // Remove empty params
            for (const [key, value] of params.entries()) {
                if (!value || value === '') {
                    params.delete(key);
                }
            }

            window.location.href = this.config.apiUrl + '?' + params.toString();
        },

        // Show log details in modal
        showLogDetails: function(logId) {
            const log = this.state.allLogs.find(l => l.id === logId);
            if (!log) return;

            const $modal = $('#aebg-log-detail-modal');
            const $content = $('#aebg-log-detail-content');

            let html = `
                <div class="aebg-log-detail">
                    <div class="aebg-log-detail-row">
                        <strong>ID:</strong> ${this.escapeHtml(log.id)}
                    </div>
                    <div class="aebg-log-detail-row">
                        <strong>Type:</strong> <span class="aebg-log-level ${log.level}">${log.type}</span>
                    </div>
                    <div class="aebg-log-detail-row">
                        <strong>Level:</strong> <span class="aebg-log-level ${log.level}">${log.level}</span>
                    </div>
                    <div class="aebg-log-detail-row">
                        <strong>Timestamp:</strong> ${this.formatDate(log.timestamp)}
                    </div>
                    <div class="aebg-log-detail-row">
                        <strong>Message:</strong> ${this.escapeHtml(log.message)}
                    </div>
            `;

            if (log.batch_id) {
                html += `<div class="aebg-log-detail-row"><strong>Batch ID:</strong> ${log.batch_id}</div>`;
            }

            if (log.item_id) {
                html += `<div class="aebg-log-detail-row"><strong>Item ID:</strong> ${log.item_id}</div>`;
            }

            if (log.action_id) {
                html += `<div class="aebg-log-detail-row"><strong>Action ID:</strong> ${log.action_id}</div>`;
            }

            if (log.status) {
                html += `<div class="aebg-log-detail-row"><strong>Status:</strong> ${this.escapeHtml(log.status)}</div>`;
            }

            if (log.context && Object.keys(log.context).length > 0) {
                html += `
                    <div class="aebg-log-detail-row">
                        <strong>Context:</strong>
                        <pre class="aebg-log-context-display">${this.escapeHtml(JSON.stringify(log.context, null, 2))}</pre>
                    </div>
                `;
            }

            html += '</div>';

            $content.html(html);
            $modal.show();
        },

        // Infinite scroll handler
        handleScroll: function() {
            if (!this.state.hasMore || this.state.loading) return;

            const scrollTop = $(window).scrollTop();
            const windowHeight = $(window).height();
            const documentHeight = $(document).height();

            // Load more when 200px from bottom
            if (scrollTop + windowHeight >= documentHeight - 200) {
                this.loadLogs();
            }
        },

        // Utility functions
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        formatDate: function(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        },

        formatNumber: function(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            }
            if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toString();
        },

        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        // Loading states
        showLoading: function() {
            const $container = $('#aebg-logs-content');
            if ($container.find('.aebg-loading').length === 0) {
                $container.append(`
                    <div class="aebg-loading">
                        <div class="aebg-loading-spinner"></div>
                        <div class="aebg-loading-text">Loading logs...</div>
                    </div>
                `);
            }
        },

        hideLoading: function() {
            $('.aebg-loading').remove();
        },

        showEmptyState: function() {
            $('#aebg-empty-state').show();
            $('#aebg-logs-content').hide();
        },

        hideEmptyState: function() {
            $('#aebg-empty-state').hide();
            $('#aebg-logs-content').show();
        },

        showError: function(message) {
            // Create or update error notification
            let $error = $('.aebg-error-notification');
            if ($error.length === 0) {
                $error = $('<div class="aebg-error-notification aebg-notice aebg-notice-error"></div>');
                $('.aebg-logs-container').prepend($error);
            }
            $error.html('<strong>Error:</strong> ' + this.escapeHtml(message));
            $error.fadeIn();

            // Auto-hide after 5 seconds
            setTimeout(() => {
                $error.fadeOut();
            }, 5000);
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        if ($('.aebg-logs-container').length) {
            LogsPage.init();
        }
    });

})(jQuery);

