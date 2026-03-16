<?php
/**
 * Dashboard Page Template
 * 
 * @package AEBG
 * @since 1.0.0
 */
?>
<div class="aebg-dashboard-container">
    <!-- Header Section -->
    <div class="aebg-dashboard-header">
        <div class="aebg-dashboard-title">
            <h1>📊 Activity Dashboard</h1>
            <p>Monitor generations, costs, tokens, and product replacements</p>
        </div>
        <div class="aebg-dashboard-actions">
            <div class="aebg-date-range-selector">
                <select id="aebg-dashboard-date-range" class="aebg-select">
                    <option value="today">Today</option>
                    <option value="7days" selected>Last 7 Days</option>
                    <option value="30days">Last 30 Days</option>
                    <option value="all">All Time</option>
                    <option value="custom">Custom Range</option>
                </select>
            </div>
            <div class="aebg-custom-date-range" id="aebg-custom-date-range" style="display: none;">
                <input type="date" id="aebg-date-from" class="aebg-input">
                <span>to</span>
                <input type="date" id="aebg-date-to" class="aebg-input">
                <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-apply-date-range">Apply</button>
            </div>
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-refresh-dashboard">
                <span class="aebg-icon">🔄</span>
                Refresh
            </button>
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-export-dashboard">
                <span class="aebg-icon">📥</span>
                Export
            </button>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="aebg-dashboard-metrics" id="aebg-dashboard-metrics">
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">💰</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-total-cost">-</div>
                <div class="aebg-metric-label">Total API Cost</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">📈</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-avg-cost-per-gen">-</div>
                <div class="aebg-metric-label">Avg Cost/Generation <span id="aebg-metric-cost-trend" class="aebg-trend"></span></div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">🤖</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-primary-model">-</div>
                <div class="aebg-metric-label">Primary Model</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">📝</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-total-generations">-</div>
                <div class="aebg-metric-label">Total Generations</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">📥</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-input-tokens">-</div>
                <div class="aebg-metric-label">Input Tokens</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">📤</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-output-tokens">-</div>
                <div class="aebg-metric-label">Output Tokens</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">✅</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-success-rate">-</div>
                <div class="aebg-metric-label">Success Rate</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">🔄</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-product-replacements">-</div>
                <div class="aebg-metric-label">Product Replacements</div>
            </div>
        </div>
        <div class="aebg-metric-card">
            <div class="aebg-metric-icon">🖼️</div>
            <div class="aebg-metric-content">
                <div class="aebg-metric-value" id="aebg-metric-total-images">-</div>
                <div class="aebg-metric-label">Images Generated</div>
            </div>
        </div>
    </div>

    <!-- Efficiency Badge -->
    <div class="aebg-efficiency-badge" id="aebg-efficiency-badge">
        <div class="aebg-efficiency-content">
            <span class="aebg-efficiency-label">Cost Efficiency:</span>
            <span class="aebg-efficiency-status" id="aebg-efficiency-status">-</span>
            <span class="aebg-efficiency-change" id="aebg-efficiency-change">-</span>
        </div>
    </div>

    <!-- Model Usage Breakdown -->
    <div class="aebg-model-usage-section">
        <h2>Model Usage Breakdown</h2>
        <div class="aebg-model-usage-grid" id="aebg-model-usage-grid">
            <div class="aebg-loading">Loading model usage...</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="aebg-dashboard-charts">
        <div class="aebg-chart-card">
            <h3>Average Cost per Generation Trend</h3>
            <canvas id="aebg-chart-avg-cost-trend"></canvas>
        </div>
        <div class="aebg-chart-card">
            <h3>Cost Over Time</h3>
            <canvas id="aebg-chart-cost-trend"></canvas>
        </div>
        <div class="aebg-chart-card">
            <h3>Token Usage Over Time</h3>
            <canvas id="aebg-chart-token-trend"></canvas>
        </div>
        <div class="aebg-chart-card">
            <h3>Generations by Status</h3>
            <canvas id="aebg-chart-generations-status"></canvas>
        </div>
        <div class="aebg-chart-card">
            <h3>Cost per Generation by Model</h3>
            <canvas id="aebg-chart-cost-by-model"></canvas>
        </div>
        <div class="aebg-chart-card">
            <h3>Daily Activity</h3>
            <canvas id="aebg-chart-daily-activity"></canvas>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="aebg-dashboard-activity">
        <div class="aebg-activity-header">
            <h2>Recent Activity</h2>
            <div class="aebg-activity-filters">
                <select id="aebg-activity-type-filter" class="aebg-select">
                    <option value="">All Types</option>
                    <option value="generation">Generations</option>
                    <option value="product_replacement">Product Replacements</option>
                </select>
            </div>
        </div>
        <div class="aebg-activity-feed" id="aebg-activity-feed">
            <div class="aebg-loading">Loading activity...</div>
        </div>
    </div>

    <!-- Detailed Tables Tabs -->
    <div class="aebg-dashboard-tabs">
        <div class="aebg-tabs-nav">
            <button class="aebg-tab-btn active" data-tab="generations">Generations</button>
            <button class="aebg-tab-btn" data-tab="token-usage">Token Usage</button>
            <button class="aebg-tab-btn" data-tab="product-replacements">Product Replacements</button>
            <button class="aebg-tab-btn" data-tab="cost-breakdown">Cost Breakdown</button>
        </div>
        <div class="aebg-tabs-content">
            <div class="aebg-tab-panel active" id="aebg-tab-generations">
                <div class="aebg-table-container">
                    <table class="aebg-table" id="aebg-table-generations">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Post</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Cost</th>
                                <th>Tokens</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="7" class="aebg-loading">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="aebg-tab-panel" id="aebg-tab-token-usage">
                <div class="aebg-table-container">
                    <table class="aebg-table" id="aebg-table-token-usage">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Model</th>
                                <th>Prompt Tokens</th>
                                <th>Completion Tokens</th>
                                <th>Total Tokens</th>
                                <th>Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="6" class="aebg-loading">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="aebg-tab-panel" id="aebg-tab-product-replacements">
                <div class="aebg-table-container">
                    <table class="aebg-table" id="aebg-table-product-replacements">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Post</th>
                                <th>Old Product</th>
                                <th>New Product</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="5" class="aebg-loading">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="aebg-tab-panel" id="aebg-tab-cost-breakdown">
                <div class="aebg-cost-breakdown" id="aebg-cost-breakdown">
                    <div class="aebg-loading">Loading cost breakdown...</div>
                </div>
            </div>
        </div>
    </div>
</div>


