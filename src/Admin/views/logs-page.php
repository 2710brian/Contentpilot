<?php
/**
 * Logs Page Template
 * 
 * @package AEBG
 * @since 1.0.0
 */
?>
<div class="aebg-logs-container">
    <!-- Header Section -->
    <div class="aebg-logs-header">
        <div class="aebg-logs-title">
            <h1>📋 System Logs</h1>
            <p>View and filter all system logs from batches, batch items, and action scheduler</p>
        </div>
        <div class="aebg-logs-actions">
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-refresh-logs">
                <span class="aebg-icon">🔄</span>
                Refresh
            </button>
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-export-logs">
                <span class="aebg-icon">📥</span>
                Export
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="aebg-logs-stats" id="aebg-logs-stats">
        <div class="aebg-stat-card">
            <div class="aebg-stat-icon">📊</div>
            <div class="aebg-stat-content">
                <div class="aebg-stat-value" id="aebg-stat-total">-</div>
                <div class="aebg-stat-label">Total Logs</div>
            </div>
        </div>
        <div class="aebg-stat-card">
            <div class="aebg-stat-icon">✅</div>
            <div class="aebg-stat-content">
                <div class="aebg-stat-value" id="aebg-stat-success">-</div>
                <div class="aebg-stat-label">Success</div>
            </div>
        </div>
        <div class="aebg-stat-card">
            <div class="aebg-stat-icon">⚠️</div>
            <div class="aebg-stat-content">
                <div class="aebg-stat-value" id="aebg-stat-warning">-</div>
                <div class="aebg-stat-label">Warnings</div>
            </div>
        </div>
        <div class="aebg-stat-card">
            <div class="aebg-stat-icon">❌</div>
            <div class="aebg-stat-content">
                <div class="aebg-stat-value" id="aebg-stat-error">-</div>
                <div class="aebg-stat-label">Errors</div>
            </div>
        </div>
        <div class="aebg-stat-card">
            <div class="aebg-stat-icon">📈</div>
            <div class="aebg-stat-content">
                <div class="aebg-stat-value" id="aebg-stat-error-rate">-</div>
                <div class="aebg-stat-label">Error Rate</div>
            </div>
        </div>
        <div class="aebg-stat-card">
            <div class="aebg-stat-icon">🕐</div>
            <div class="aebg-stat-content">
                <div class="aebg-stat-value" id="aebg-stat-recent">-</div>
                <div class="aebg-stat-label">Last 24h</div>
            </div>
        </div>
    </div>

    <!-- Filters & Search -->
    <div class="aebg-logs-filters">
        <div class="aebg-filters-header">
            <h3>🔍 Filters & Search</h3>
            <button type="button" class="aebg-btn aebg-btn-secondary aebg-btn-small" id="aebg-clear-filters">
                <span class="aebg-icon">🗑️</span>
                Clear Filters
            </button>
        </div>
        <div class="aebg-filters-grid">
            <div class="aebg-form-group">
                <label for="aebg-logs-search">Search</label>
                <input 
                    type="text" 
                    id="aebg-logs-search" 
                    class="aebg-input" 
                    placeholder="Search logs, batch IDs, messages..."
                >
            </div>
            <div class="aebg-form-group">
                <label for="aebg-logs-level">Log Level</label>
                <select id="aebg-logs-level" class="aebg-select aebg-logs-filter" data-filter-key="level">
                    <option value="">All Levels</option>
                    <option value="info">Info</option>
                    <option value="success">Success</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                </select>
            </div>
            <div class="aebg-form-group">
                <label for="aebg-logs-type">Log Type</label>
                <select id="aebg-logs-type" class="aebg-select aebg-logs-filter" data-filter-key="type">
                    <option value="">All Types</option>
                    <option value="batch">Batch</option>
                    <option value="batch_item">Batch Item</option>
                    <option value="action_scheduler">Action Scheduler</option>
                </select>
            </div>
            <div class="aebg-form-group">
                <label for="aebg-logs-status">Status</label>
                <select id="aebg-logs-status" class="aebg-select aebg-logs-filter" data-filter-key="status">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="failed">Failed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="aebg-form-group">
                <label for="aebg-logs-batch-id">Batch ID</label>
                <input 
                    type="number" 
                    id="aebg-logs-batch-id" 
                    class="aebg-input aebg-logs-filter" 
                    data-filter-key="batch_id"
                    placeholder="Filter by batch ID..."
                    min="1"
                >
            </div>
            <div class="aebg-form-group">
                <label for="aebg-logs-date-from">Date From</label>
                <input 
                    type="date" 
                    id="aebg-logs-date-from" 
                    class="aebg-input aebg-logs-filter" 
                    data-filter-key="date_from"
                >
            </div>
            <div class="aebg-form-group">
                <label for="aebg-logs-date-to">Date To</label>
                <input 
                    type="date" 
                    id="aebg-logs-date-to" 
                    class="aebg-input aebg-logs-filter" 
                    data-filter-key="date_to"
                >
            </div>
        </div>
    </div>

    <!-- Logs Content -->
    <div class="aebg-logs-content-wrapper">
        <div class="aebg-logs-table-header">
            <div class="aebg-log-header-level aebg-sortable" data-sort="level">
                Level
                <span class="aebg-sort-icon"></span>
            </div>
            <div class="aebg-log-header-message aebg-sortable" data-sort="message">
                Message
                <span class="aebg-sort-icon"></span>
            </div>
            <div class="aebg-log-header-timestamp aebg-sortable aebg-sort-active" data-sort="timestamp" data-sort-dir="desc">
                Timestamp
                <span class="aebg-sort-icon">↓</span>
            </div>
            <div class="aebg-log-header-type aebg-sortable" data-sort="type">
                Type
                <span class="aebg-sort-icon"></span>
            </div>
            <div class="aebg-log-header-actions">Actions</div>
        </div>
        <div class="aebg-logs-content" id="aebg-logs-content">
            <div class="aebg-loading">
                <div class="aebg-loading-spinner"></div>
                <div class="aebg-loading-text">Loading logs...</div>
            </div>
        </div>
    </div>

    <!-- Empty State -->
    <div class="aebg-empty-state" id="aebg-empty-state" style="display: none;">
        <div class="aebg-empty-icon">📭</div>
        <div class="aebg-empty-message">No logs found matching your filters</div>
        <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-clear-filters-empty">Clear Filters</button>
    </div>
</div>

<!-- Log Detail Modal -->
<div id="aebg-log-detail-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content">
        <div class="aebg-modal-header">
            <h3>Log Details</h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body" id="aebg-log-detail-content">
            <!-- Log details will be inserted here -->
        </div>
    </div>
</div>

