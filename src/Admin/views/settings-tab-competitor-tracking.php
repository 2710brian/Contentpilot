<?php
/**
 * Settings Tab: Competitor Tracking
 * 
 * Contains: Competitor tracking interface
 * 
 * @package AEBG
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aebg-competitor-tracking-container">
    <!-- Header Section -->
    <div class="aebg-competitor-header">
        <div class="aebg-competitor-title">
            <h1>🔍 Competitor Tracking</h1>
            <p>Monitor competitor websites and track product position changes over time</p>
        </div>
        <div class="aebg-competitor-actions">
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-add-competitor-btn">
                <span class="aebg-icon">➕</span>
                Add Competitor
            </button>
        </div>
    </div>

    <!-- Competitors Table -->
    <div class="aebg-competitor-table-wrapper">
        <table class="aebg-competitor-table wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>URL</th>
                    <th>Status</th>
                    <th>Interval</th>
                    <th>Last Scraped</th>
                    <th>Next Scrape</th>
                    <th>Products</th>
                    <th>Changes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="aebg-competitors-tbody">
                <tr>
                    <td colspan="9" class="aebg-loading">Loading competitors...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Add/Edit Competitor Modal -->
    <div id="aebg-competitor-modal" class="aebg-modal" style="display: none;">
        <div class="aebg-modal-content">
            <div class="aebg-modal-header">
                <h2 id="aebg-modal-title">Add Competitor</h2>
                <button type="button" class="aebg-modal-close" id="aebg-modal-close">&times;</button>
            </div>
            <div class="aebg-modal-body">
                <form id="aebg-competitor-form">
                    <input type="hidden" id="aebg-competitor-id" name="competitor_id" value="">
                    
                    <div class="aebg-form-group">
                        <label for="aebg-competitor-name">Competitor Name *</label>
                        <input 
                            type="text" 
                            id="aebg-competitor-name" 
                            name="name" 
                            class="aebg-input" 
                            required
                            placeholder="e.g., Competitor Site Name"
                        >
                    </div>
                    
                    <div class="aebg-form-group">
                        <label for="aebg-competitor-url">URL *</label>
                        <input 
                            type="url" 
                            id="aebg-competitor-url" 
                            name="url" 
                            class="aebg-input" 
                            required
                            placeholder="https://example.com/best-products"
                        >
                    </div>
                    
                    <div class="aebg-form-group">
                        <label for="aebg-competitor-interval">Scraping Interval *</label>
                        <select id="aebg-competitor-interval" name="scraping_interval" class="aebg-select" required>
                            <option value="86400" selected>Daily</option>
                            <option value="604800">7 Days</option>
                            <option value="1209600">14 Days</option>
                            <option value="2592000">30 Days</option>
                            <option value="custom">Custom (days)</option>
                        </select>
                        <input 
                            type="number" 
                            id="aebg-competitor-interval-custom" 
                            name="scraping_interval_custom" 
                            class="aebg-input" 
                            style="display: none; margin-top: 10px;"
                            placeholder="Enter number of days (e.g., 5 for 5 days)"
                            min="1"
                            step="1"
                        >
                    </div>
                    
                    <div class="aebg-form-group">
                        <label>
                            <input 
                                type="checkbox" 
                                id="aebg-competitor-active" 
                                name="is_active" 
                                value="1"
                                checked
                            >
                            Active (Enable tracking)
                        </label>
                    </div>
                    
                    <div class="aebg-modal-footer">
                        <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-modal-cancel">Cancel</button>
                        <button type="submit" class="aebg-btn aebg-btn-primary" id="aebg-modal-save">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Competitor Details Modal -->
    <div id="aebg-competitor-details-modal" class="aebg-modal" style="display: none;">
        <div class="aebg-modal-content aebg-modal-large">
            <div class="aebg-modal-header">
                <h2 id="aebg-details-title">Competitor Details</h2>
                <button type="button" class="aebg-modal-close" id="aebg-details-modal-close">&times;</button>
            </div>
            <div class="aebg-modal-body">
                <div id="aebg-competitor-details-content">
                    <div class="aebg-loading">Loading details...</div>
                </div>
            </div>
        </div>
    </div>
</div>

