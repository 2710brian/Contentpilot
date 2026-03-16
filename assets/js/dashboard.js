(function($) {
    'use strict';

    const Dashboard = {
        config: {
            apiUrl: (typeof aebgDashboard !== 'undefined' && aebgDashboard.restUrl) ? aebgDashboard.restUrl : (typeof aebgDashboard !== 'undefined' && aebgDashboard.rest_url) ? aebgDashboard.rest_url : '',
            nonce: (typeof aebgDashboard !== 'undefined' && aebgDashboard.nonce) ? aebgDashboard.nonce : (typeof aebgDashboard !== 'undefined' && aebgDashboard.rest_nonce) ? aebgDashboard.rest_nonce : '',
            dateFrom: (typeof aebgDashboard !== 'undefined' && aebgDashboard.dateFrom) ? aebgDashboard.dateFrom : '',
            dateTo: (typeof aebgDashboard !== 'undefined' && aebgDashboard.dateTo) ? aebgDashboard.dateTo : '',
        },
        
        state: {
            currentTab: 'generations',
            charts: {},
            loading: false,
        },

        init: function() {
            // Validate configuration
            if (!this.config.apiUrl) {
                console.error('Dashboard configuration error:', {
                    aebgDashboard: typeof aebgDashboard !== 'undefined' ? aebgDashboard : 'undefined',
                    apiUrl: this.config.apiUrl,
                    nonce: this.config.nonce
                });
                this.showError('Dashboard configuration error. Please refresh the page.');
                return;
            }
            
            // Set default date range if not set
            if (!this.config.dateFrom || !this.config.dateTo) {
                this.setDateRange('7days');
            }
            
            this.bindEvents();
            this.loadDashboard();
        },

        bindEvents: function() {
            // Date range selector
            $('#aebg-dashboard-date-range').on('change', this.handleDateRangeChange.bind(this));
            $('#aebg-apply-date-range').on('click', this.applyCustomDateRange.bind(this));
            
            // Refresh button
            $('#aebg-refresh-dashboard').on('click', this.loadDashboard.bind(this));
            
            // Export button
            $('#aebg-export-dashboard').on('click', this.exportDashboard.bind(this));
            
            // Tab switching
            $('.aebg-tab-btn').on('click', this.handleTabSwitch.bind(this));
            
            // Activity filter
            $('#aebg-activity-type-filter').on('change', this.loadActivity.bind(this));
        },

        handleDateRangeChange: function(e) {
            const value = $(e.target).val();
            const $customRange = $('#aebg-custom-date-range');
            
            if (value === 'custom') {
                $customRange.show();
            } else {
                $customRange.hide();
                this.setDateRange(value);
                this.loadDashboard();
            }
        },

        setDateRange: function(range) {
            let dateFrom, dateTo;
            const today = new Date();
            
            switch(range) {
                case 'today':
                    dateFrom = dateTo = this.formatDate(today);
                    break;
                case '7days':
                    dateFrom = this.formatDate(new Date(today.getTime() - 6 * 24 * 60 * 60 * 1000));
                    dateTo = this.formatDate(today);
                    break;
                case '30days':
                    dateFrom = this.formatDate(new Date(today.getTime() - 29 * 24 * 60 * 60 * 1000));
                    dateTo = this.formatDate(today);
                    break;
                case 'all':
                    // No date filter - show all generations
                    dateFrom = '';
                    dateTo = '';
                    break;
                default:
                    // Default to 30 days if range not specified
                    dateFrom = this.formatDate(new Date(today.getTime() - 29 * 24 * 60 * 60 * 1000));
                    dateTo = this.formatDate(today);
                    break;
            }
            
            this.config.dateFrom = dateFrom;
            this.config.dateTo = dateTo;
        },

        applyCustomDateRange: function() {
            const dateFrom = $('#aebg-date-from').val();
            const dateTo = $('#aebg-date-to').val();
            
            if (!dateFrom || !dateTo) {
                alert('Please select both start and end dates');
                return;
            }
            
            if (new Date(dateFrom) > new Date(dateTo)) {
                alert('Start date must be before end date');
                return;
            }
            
            this.config.dateFrom = dateFrom;
            this.config.dateTo = dateTo;
            this.loadDashboard();
        },

        formatDate: function(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },

        loadDashboard: function() {
            if (this.state.loading) return;
            
            this.state.loading = true;
            this.showLoading();
            
            // Load stats
            this.loadStats().then(() => {
                this.loadCharts();
                this.loadActivity();
                this.loadTabData();
                this.state.loading = false;
                this.hideLoading();
            }).catch((error) => {
                console.error('Error loading dashboard:', error);
                this.showError('Failed to load dashboard data');
                this.state.loading = false;
                this.hideLoading();
            });
        },

        loadStats: function() {
            if (!this.config.apiUrl) {
                console.error('Dashboard API URL not configured', {
                    aebgDashboard: typeof aebgDashboard !== 'undefined' ? aebgDashboard : 'undefined',
                    config: this.config
                });
                this.showError('Dashboard API URL not configured. Please refresh the page.');
                return Promise.reject('API URL not configured');
            }
            
            // Ensure URL ends with / before appending endpoint
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            
            return $.ajax({
                url: baseUrl + 'stats',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom,
                    date_to: this.config.dateTo,
                },
                beforeSend: (xhr) => {
                    if (this.config.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                    }
                }
            }).done((response) => {
                this.renderMetrics(response.overview);
                this.renderEfficiency(response.comparison);
            }).fail((jqXHR, textStatus, errorThrown) => {
                console.error('Failed to load stats:', textStatus, errorThrown);
                console.error('Response:', jqXHR.responseText);
                this.showError('Failed to load dashboard statistics: ' + (errorThrown || textStatus));
            });
        },

        renderMetrics: function(overview) {
            $('#aebg-metric-total-cost').text('$' + overview.total_cost.toFixed(2));
            $('#aebg-metric-avg-cost-per-gen').text('$' + overview.avg_cost_per_generation.toFixed(4));
            $('#aebg-metric-primary-model').text(overview.primary_model || 'N/A');
            $('#aebg-metric-total-generations').text(overview.total_generations.toLocaleString());
            $('#aebg-metric-success-rate').text(overview.success_rate.toFixed(1) + '%');
            $('#aebg-metric-product-replacements').text(overview.product_replacements.toLocaleString());
            $('#aebg-metric-input-tokens').text((overview.total_prompt_tokens || 0).toLocaleString());
            $('#aebg-metric-output-tokens').text((overview.total_completion_tokens || 0).toLocaleString());
            $('#aebg-metric-total-images').text((overview.total_images || 0).toLocaleString());
            
            // Render model usage breakdown
            if (overview.model_usage && overview.model_usage.length > 0) {
                this.renderModelUsage(overview.model_usage);
            }
            
            // Trend indicator
            const trend = overview.cost_change_percent;
            const $trend = $('#aebg-metric-cost-trend');
            if (trend < 0) {
                $trend.text('↓ ' + Math.abs(trend).toFixed(1) + '%').addClass('trend-down');
            } else if (trend > 0) {
                $trend.text('↑ ' + trend.toFixed(1) + '%').addClass('trend-up');
            } else {
                $trend.text('→ 0%').addClass('trend-stable');
            }
        },

        renderModelUsage: function(modelUsage) {
            const $grid = $('#aebg-model-usage-grid');
            $grid.empty();

            if (modelUsage.length === 0) {
                $grid.html('<div class="aebg-empty-state">No model usage data</div>');
                return;
            }

            modelUsage.forEach(model => {
                const $card = $('<div class="aebg-model-card"></div>');
                
                let typesHtml = '';
                if (model.by_type && Object.keys(model.by_type).length > 0) {
                    typesHtml = '<div class="aebg-model-types">';
                    Object.entries(model.by_type).forEach(([type, data]) => {
                        const typeLabel = type === 'image_generation' ? 'Images' : 'Text';
                        typesHtml += `<div class="aebg-model-type">
                            <span class="aebg-type-label">${typeLabel}:</span>
                            <span class="aebg-type-value">${data.requests} requests</span>
                            ${data.tokens > 0 ? `<span class="aebg-type-tokens">${data.tokens.toLocaleString()} tokens</span>` : ''}
                            <span class="aebg-type-cost">$${data.cost.toFixed(2)}</span>
                        </div>`;
                    });
                    typesHtml += '</div>';
                }
                
                $card.html(`
                    <div class="aebg-model-header">
                        <h3>${model.model}</h3>
                        <div class="aebg-model-total-cost">$${model.total_cost.toFixed(2)}</div>
                    </div>
                    <div class="aebg-model-stats">
                        <div class="aebg-model-stat">
                            <span class="aebg-stat-label">Requests:</span>
                            <span class="aebg-stat-value">${model.total_requests.toLocaleString()}</span>
                        </div>
                        ${model.total_tokens > 0 ? `
                        <div class="aebg-model-stat">
                            <span class="aebg-stat-label">Tokens:</span>
                            <span class="aebg-stat-value">${model.total_tokens.toLocaleString()}</span>
                        </div>
                        ` : ''}
                    </div>
                    ${typesHtml}
                `);
                
                $grid.append($card);
            });
        },

        renderEfficiency: function(comparison) {
            const status = comparison.trend;
            const change = comparison.change_percent;
            
            $('#aebg-efficiency-status').text(status.charAt(0).toUpperCase() + status.slice(1));
            $('#aebg-efficiency-status').removeClass('improving declining stable')
                .addClass(status);
            
            if (change < 0) {
                $('#aebg-efficiency-change').text('(' + Math.abs(change).toFixed(1) + '% improvement)').addClass('positive');
            } else if (change > 0) {
                $('#aebg-efficiency-change').text('(' + change.toFixed(1) + '% increase)').addClass('negative');
            } else {
                $('#aebg-efficiency-change').text('(no change)').removeClass('positive negative');
            }
        },

        loadCharts: function() {
            if (!this.config.apiUrl) {
                console.error('Dashboard API URL not configured for charts');
                return;
            }
            
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            $.ajax({
                url: baseUrl + 'stats',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom,
                    date_to: this.config.dateTo,
                    group_by: 'daily',
                },
                beforeSend: (xhr) => {
                    if (this.config.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                    }
                }
            }).done((response) => {
                this.renderCharts(response.trends, response.breakdowns);
            }).fail((jqXHR, textStatus, errorThrown) => {
                console.error('Failed to load charts:', textStatus, errorThrown);
            });
        },

        renderCharts: function(trends, breakdowns) {
            // Ensure trends and breakdowns are defined
            trends = trends || {};
            breakdowns = breakdowns || {};
            
            // Ensure arrays exist
            trends.avg_cost_per_generation = trends.avg_cost_per_generation || [];
            trends.costs = trends.costs || [];
            trends.tokens = trends.tokens || [];
            trends.generations = trends.generations || [];
            breakdowns.by_model = breakdowns.by_model || [];

            // Average Cost per Generation Trend
            if (trends.avg_cost_per_generation && trends.avg_cost_per_generation.length > 0) {
                this.renderLineChart('aebg-chart-avg-cost-trend', {
                    labels: trends.avg_cost_per_generation.map(t => t.date),
                    datasets: [{
                        label: 'Avg Cost per Generation',
                        data: trends.avg_cost_per_generation.map(t => parseFloat(t.avg_cost_per_gen || 0)),
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    }]
                });
            } else {
                this.showNoDataMessage('aebg-chart-avg-cost-trend');
            }

            // Cost Over Time
            if (trends.costs && trends.costs.length > 0) {
                this.renderLineChart('aebg-chart-cost-trend', {
                    labels: trends.costs.map(t => t.date),
                    datasets: [{
                        label: 'Cost',
                        data: trends.costs.map(t => parseFloat(t.cost || 0)),
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    }]
                });
            } else {
                this.showNoDataMessage('aebg-chart-cost-trend');
            }

            // Token Usage Over Time
            if (trends.tokens && trends.tokens.length > 0) {
                this.renderLineChart('aebg-chart-token-trend', {
                    labels: trends.tokens.map(t => t.date),
                    datasets: [{
                        label: 'Tokens',
                        data: trends.tokens.map(t => parseInt(t.tokens || 0)),
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    }]
                });
            } else {
                this.showNoDataMessage('aebg-chart-token-trend');
            }

            // Generations by Status
            const statusData = trends.generations.reduce((acc, gen) => {
                acc.successful = (acc.successful || 0) + parseInt(gen.successful || 0);
                acc.failed = (acc.failed || 0) + parseInt(gen.failed || 0);
                return acc;
            }, { successful: 0, failed: 0 });

            if (statusData.successful > 0 || statusData.failed > 0) {
                this.renderDoughnutChart('aebg-chart-generations-status', {
                    labels: ['Successful', 'Failed'],
                    datasets: [{
                        data: [statusData.successful, statusData.failed],
                        backgroundColor: ['rgb(75, 192, 192)', 'rgb(255, 99, 132)'],
                    }]
                });
            } else {
                this.showNoDataMessage('aebg-chart-generations-status');
            }

            // Cost per Generation by Model
            if (breakdowns.by_model && breakdowns.by_model.length > 0) {
                this.renderBarChart('aebg-chart-cost-by-model', {
                    labels: breakdowns.by_model.map(m => m.model),
                    datasets: [{
                        label: 'Avg Cost per Request',
                        data: breakdowns.by_model.map(m => parseFloat(m.avg_cost_per_request || 0)),
                        backgroundColor: 'rgba(153, 102, 255, 0.6)',
                    }]
                });
            } else {
                this.showNoDataMessage('aebg-chart-cost-by-model');
            }

            // Daily Activity
            if (trends.generations && trends.generations.length > 0) {
                this.renderLineChart('aebg-chart-daily-activity', {
                    labels: trends.generations.map(t => t.date),
                    datasets: [{
                        label: 'Generations',
                        data: trends.generations.map(t => parseInt(t.generations || 0)),
                        borderColor: 'rgb(255, 159, 64)',
                        backgroundColor: 'rgba(255, 159, 64, 0.2)',
                    }]
                });
            } else {
                this.showNoDataMessage('aebg-chart-daily-activity');
            }
        },

        renderLineChart: function(canvasId, data) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            
            // Destroy existing chart if it exists
            if (this.state.charts[canvasId]) {
                this.state.charts[canvasId].destroy();
            }

            // Remove no data message if it exists
            const $card = $(canvas).closest('.aebg-chart-card');
            $card.find('.aebg-no-data').remove();
            $(canvas).show();

            // Check if Chart.js is available
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }

            this.state.charts[canvasId] = new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        },

        renderBarChart: function(canvasId, data) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            
            if (this.state.charts[canvasId]) {
                this.state.charts[canvasId].destroy();
            }

            // Remove no data message if it exists
            const $card = $(canvas).closest('.aebg-chart-card');
            $card.find('.aebg-no-data').remove();
            $(canvas).show();

            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }

            this.state.charts[canvasId] = new Chart(ctx, {
                type: 'bar',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        },

        renderDoughnutChart: function(canvasId, data) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            
            if (this.state.charts[canvasId]) {
                this.state.charts[canvasId].destroy();
            }

            // Remove no data message if it exists
            const $card = $(canvas).closest('.aebg-chart-card');
            $card.find('.aebg-no-data').remove();
            $(canvas).show();

            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }

            this.state.charts[canvasId] = new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                }
            });
        },

        loadActivity: function() {
            const type = $('#aebg-activity-type-filter').val();
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            
            $.ajax({
                url: baseUrl + 'activity',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom,
                    date_to: this.config.dateTo,
                    type: type,
                },
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                }
            }).done((response) => {
                this.renderActivity(response.data);
            });
        },

        renderActivity: function(activities) {
            const $feed = $('#aebg-activity-feed');
            $feed.empty();

            if (activities.length === 0) {
                $feed.html('<div class="aebg-empty-state">No activity found</div>');
                return;
            }

            activities.forEach(activity => {
                const $item = $('<div class="aebg-activity-item"></div>');
                
                if (activity.type === 'generation') {
                    $item.html(`
                        <div class="aebg-activity-icon">📝</div>
                        <div class="aebg-activity-content">
                            <div class="aebg-activity-title">Generation ${activity.status}</div>
                            <div class="aebg-activity-details">
                                ${activity.post_title} | 
                                ${activity.duration ? activity.duration + 's' : ''} | 
                                ${activity.cost ? '$' + activity.cost : ''} | 
                                ${activity.tokens ? activity.tokens + ' tokens' : ''}
                            </div>
                            <div class="aebg-activity-meta">
                                ${activity.user_name} • ${this.formatDateTime(activity.timestamp)}
                            </div>
                        </div>
                    `);
                } else if (activity.type === 'product_replacement') {
                    $item.html(`
                        <div class="aebg-activity-icon">🔄</div>
                        <div class="aebg-activity-content">
                            <div class="aebg-activity-title">Product Replaced</div>
                            <div class="aebg-activity-details">
                                ${activity.old_product} → ${activity.new_product}
                            </div>
                            <div class="aebg-activity-meta">
                                ${activity.post_title} • ${activity.user_name} • ${this.formatDateTime(activity.timestamp)}
                            </div>
                        </div>
                    `);
                }
                
                $feed.append($item);
            });
        },

        loadTabData: function() {
            if (this.state.currentTab === 'generations') {
                this.loadGenerations();
            } else if (this.state.currentTab === 'token-usage') {
                this.loadTokenUsage();
            } else if (this.state.currentTab === 'product-replacements') {
                this.loadProductReplacements();
            } else if (this.state.currentTab === 'cost-breakdown') {
                this.loadCostBreakdown();
            }
        },

        loadGenerations: function() {
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            $.ajax({
                url: baseUrl + 'generations',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom || '',
                    date_to: this.config.dateTo || '',
                    page: 1,
                    per_page: 50,
                },
                beforeSend: (xhr) => {
                    if (this.config.nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                    }
                }
            }).done((response) => {
                if (response && response.data) {
                    this.renderGenerationsTable(response.data);
                } else {
                    console.error('Invalid response format:', response);
                    $('#aebg-table-generations tbody').html('<tr><td colspan="7">Invalid response format</td></tr>');
                }
            }).fail((jqXHR, textStatus, errorThrown) => {
                console.error('Failed to load generations:', {
                    status: jqXHR.status,
                    statusText: textStatus,
                    error: errorThrown,
                    responseText: jqXHR.responseText,
                    url: baseUrl + 'generations'
                });
                $('#aebg-table-generations tbody').html(`<tr><td colspan="7">Failed to load generations: ${textStatus} (${jqXHR.status})</td></tr>`);
            });
        },

        renderGenerationsTable: function(data) {
            const $tbody = $('#aebg-table-generations tbody');
            $tbody.empty();

            if (data.length === 0) {
                $tbody.html('<tr><td colspan="7">No generations found</td></tr>');
                return;
            }

            data.forEach(item => {
                $tbody.append(`
                    <tr>
                        <td>${item.id}</td>
                        <td>${item.post_title || 'N/A'}</td>
                        <td><span class="aebg-status-badge ${item.status}">${item.status}</span></td>
                        <td>${item.duration_seconds ? item.duration_seconds + 's' : 'N/A'}</td>
                        <td>${item.total_cost ? '$' + item.total_cost.toFixed(4) : 'N/A'}</td>
                        <td>${item.total_tokens ? item.total_tokens.toLocaleString() : 'N/A'}</td>
                        <td>${this.formatDateTime(item.started_at)}</td>
                    </tr>
                `);
            });
        },

        loadTokenUsage: function() {
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            $.ajax({
                url: baseUrl + 'token-usage',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom,
                    date_to: this.config.dateTo,
                    page: 1,
                    per_page: 50,
                },
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                }
            }).done((response) => {
                this.renderTokenUsageTable(response.data);
            });
        },

        renderTokenUsageTable: function(data) {
            const $tbody = $('#aebg-table-token-usage tbody');
            $tbody.empty();

            if (data.length === 0) {
                $tbody.html('<tr><td colspan="6">No token usage data found</td></tr>');
                return;
            }

            data.forEach(item => {
                $tbody.append(`
                    <tr>
                        <td>${this.formatDateTime(item.timestamp)}</td>
                        <td>${item.model}</td>
                        <td>${item.prompt_tokens.toLocaleString()}</td>
                        <td>${item.completion_tokens.toLocaleString()}</td>
                        <td>${item.total_tokens.toLocaleString()}</td>
                        <td>$${item.cost.toFixed(4)}</td>
                    </tr>
                `);
            });
        },

        loadProductReplacements: function() {
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            $.ajax({
                url: baseUrl + 'product-replacements',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom,
                    date_to: this.config.dateTo,
                    page: 1,
                    per_page: 50,
                },
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                }
            }).done((response) => {
                this.renderProductReplacementsTable(response.data);
            });
        },

        renderProductReplacementsTable: function(data) {
            const $tbody = $('#aebg-table-product-replacements tbody');
            $tbody.empty();

            if (data.length === 0) {
                $tbody.html('<tr><td colspan="5">No product replacements found</td></tr>');
                return;
            }

            data.forEach(item => {
                $tbody.append(`
                    <tr>
                        <td>${this.formatDateTime(item.created_at)}</td>
                        <td>${item.post_title}</td>
                        <td>${item.old_product_name || 'N/A'}</td>
                        <td>${item.new_product_name}</td>
                        <td>${item.user_name}</td>
                    </tr>
                `);
            });
        },

        loadCostBreakdown: function() {
            const baseUrl = this.config.apiUrl.endsWith('/') ? this.config.apiUrl : this.config.apiUrl + '/';
            $.ajax({
                url: baseUrl + 'cost-breakdown',
                method: 'GET',
                data: {
                    date_from: this.config.dateFrom,
                    date_to: this.config.dateTo,
                },
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
                }
            }).done((response) => {
                this.renderCostBreakdown(response);
            });
        },

        renderCostBreakdown: function(data) {
            const $container = $('#aebg-cost-breakdown');
            
            let html = `
                <div class="aebg-cost-summary">
                    <div class="aebg-cost-item">
                        <span class="aebg-cost-label">Total Cost:</span>
                        <span class="aebg-cost-value">$${data.total_cost.toFixed(2)}</span>
                    </div>
                    <div class="aebg-cost-item">
                        <span class="aebg-cost-label">Avg Cost per Generation:</span>
                        <span class="aebg-cost-value">$${data.avg_cost_per_generation.toFixed(4)}</span>
                    </div>
                    <div class="aebg-cost-item">
                        <span class="aebg-cost-label">Cost per Token:</span>
                        <span class="aebg-cost-value">$${data.cost_per_token.toFixed(6)}</span>
                    </div>
                </div>
            `;

            if (data.by_model && data.by_model.length > 0) {
                html += '<h3>By Model</h3><table class="aebg-table"><thead><tr><th>Model</th><th>Cost</th><th>Requests</th><th>Avg Cost/Request</th></tr></thead><tbody>';
                data.by_model.forEach(model => {
                    html += `<tr>
                        <td>${model.model}</td>
                        <td>$${parseFloat(model.cost).toFixed(2)}</td>
                        <td>${model.requests}</td>
                        <td>$${parseFloat(model.avg_cost_per_request).toFixed(4)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            }

            if (data.by_user && data.by_user.length > 0) {
                html += '<h3>By User</h3><table class="aebg-table"><thead><tr><th>User</th><th>Cost</th><th>Generations</th><th>Avg Cost/Generation</th></tr></thead><tbody>';
                data.by_user.forEach(user => {
                    html += `<tr>
                        <td>${user.user_name}</td>
                        <td>$${parseFloat(user.cost).toFixed(2)}</td>
                        <td>${user.generations}</td>
                        <td>$${parseFloat(user.avg_cost_per_generation).toFixed(4)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
            }

            $container.html(html);
        },

        handleTabSwitch: function(e) {
            const tab = $(e.target).data('tab');
            
            $('.aebg-tab-btn').removeClass('active');
            $('.aebg-tab-panel').removeClass('active');
            
            $(e.target).addClass('active');
            $('#aebg-tab-' + tab).addClass('active');
            
            this.state.currentTab = tab;
            this.loadTabData();
        },

        formatDateTime: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString();
        },

        exportDashboard: function() {
            // TODO: Implement export functionality
            alert('Export functionality coming soon');
        },

        showLoading: function() {
            $('.aebg-dashboard-container').addClass('loading');
        },

        hideLoading: function() {
            $('.aebg-dashboard-container').removeClass('loading');
        },

        showError: function(message) {
            // Show error notification
            const $error = $('<div class="aebg-error-notice">' + message + '</div>');
            $('.aebg-dashboard-container').prepend($error);
            setTimeout(() => $error.fadeOut(), 5000);
        },

        showNoDataMessage: function(canvasId) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            
            // Destroy existing chart if it exists
            if (this.state.charts[canvasId]) {
                this.state.charts[canvasId].destroy();
                delete this.state.charts[canvasId];
            }
            
            // Get parent card
            const $card = $(canvas).closest('.aebg-chart-card');
            if ($card.length) {
                // Hide canvas and show no data message
                $(canvas).hide();
                if ($card.find('.aebg-no-data').length === 0) {
                    $card.append('<div class="aebg-no-data">No data available for the selected period</div>');
                }
            }
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        if ($('.aebg-dashboard-container').length) {
            // Wait a bit to ensure localization is loaded
            setTimeout(function() {
                // Re-check config after delay
                if (typeof aebgDashboard !== 'undefined') {
                    Dashboard.config.apiUrl = aebgDashboard.restUrl || aebgDashboard.rest_url || '';
                    Dashboard.config.nonce = aebgDashboard.nonce || aebgDashboard.rest_nonce || '';
                    Dashboard.config.dateFrom = aebgDashboard.dateFrom || '';
                    Dashboard.config.dateTo = aebgDashboard.dateTo || '';
                }
                Dashboard.init();
            }, 100);
        }
    });

})(jQuery);

