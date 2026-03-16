<?php
/**
 * AI Content Generator V2 Page Template
 * Step-by-Step Interface
 * 
 * @package AEBG
 * @since 2.0.0
 */
?>
<div class="aebg-generator-v2-container">
    <!-- Progress Section - Live Generation Progress -->
    <div id="aebg-v2-progress-section" class="aebg-v2-progress-section" style="display: none;">
        <div class="aebg-v2-progress-header">
            <h3>🔄 Generating Content</h3>
            <div class="aebg-v2-progress-stats">
                <span id="aebg-v2-progress-stats"></span>
            </div>
        </div>
        
        <div class="aebg-v2-progress-bar-container">
            <div class="aebg-v2-progress-bar">
                <div id="aebg-v2-progress-bar-inner" class="aebg-v2-progress-bar-inner"></div>
            </div>
            <div class="aebg-v2-progress-text" id="aebg-v2-progress-text">Initializing...</div>
        </div>

        <div class="aebg-v2-progress-details">
            <div class="aebg-v2-progress-item" id="aebg-v2-current-item">
                <span class="aebg-icon">📝</span>
                <span id="aebg-v2-current-activity">Preparing to generate...</span>
            </div>
            <!-- List of all posts being processed with their current steps -->
            <div id="aebg-v2-processing-items-list" class="aebg-v2-processing-items-list" style="display: none;">
                <div class="aebg-v2-processing-items-header">
                    <strong>Currently Processing:</strong>
                </div>
                <div id="aebg-v2-processing-items-content" class="aebg-v2-processing-items-content">
                    <!-- Items will be dynamically inserted here -->
                </div>
            </div>
            <!-- List of failed items with error messages -->
            <div id="aebg-v2-failed-items-list" class="aebg-v2-failed-items-list" style="display: none;">
                <div class="aebg-v2-failed-items-header">
                    <strong>⚠️ Failed Items:</strong>
                    <span id="aebg-v2-failed-count" class="aebg-v2-failed-count"></span>
                </div>
                <div id="aebg-v2-failed-items-content" class="aebg-v2-failed-items-content">
                    <!-- Failed items will be dynamically inserted here -->
                </div>
            </div>
        </div>

        <div class="aebg-v2-progress-actions">
            <button type="button" id="aebg-v2-cancel-generation" class="aebg-btn aebg-btn-secondary">
                <span class="aebg-icon">⏹️</span>
                Cancel Generation
            </button>
            <button type="button" id="aebg-v2-view-live-results" class="aebg-btn aebg-btn-success" style="display: none;">
                <span class="aebg-icon">👁️</span>
                View Live Results
            </button>
        </div>
    </div>

    <!-- Progress Indicator (Step-by-Step Form) -->
    <div class="aebg-progress-indicator" id="aebg-v2-form-progress">
        <div class="aebg-progress-dots">
            <span class="aebg-dot active" data-step="1"></span>
            <span class="aebg-dot" data-step="2"></span>
            <span class="aebg-dot" data-step="3"></span>
            <span class="aebg-dot" data-step="4"></span>
            <span class="aebg-dot" data-step="5"></span>
        </div>
        <div class="aebg-progress-text">
            <span id="aebg-step-indicator">Step <span id="aebg-current-step">1</span> of 5</span>
            <span class="aebg-progress-percentage" id="aebg-progress-percentage">20%</span>
        </div>
    </div>

    <!-- Step Container -->
    <div class="aebg-step-container" id="aebg-step-container">
        <!-- Step 1: Enter Titles -->
        <div class="aebg-step active" data-step="1" id="aebg-step-1">
            <div class="aebg-step-question">
                <h2>Hvad vil du generere i dag?</h2>
                <p class="aebg-step-subtitle">What do you want to generate today?</p>
            </div>
            
            <div class="aebg-step-content">
                <div class="aebg-input-wrapper">
                    <div class="aebg-input-icon">+</div>
                    <textarea 
                        id="aebg-v2-titles" 
                        class="aebg-v2-textarea" 
                        placeholder="Enter post titles, one per line..."
                        rows="8"
                    ></textarea>
                </div>
                
                <div id="aebg-v2-duplicate-warning" class="aebg-v2-warning" style="display: none;">
                    <span class="aebg-icon">⚠️</span>
                    <span id="aebg-v2-duplicate-count">0</span> duplicate title(s) found.
                    <a href="#" id="aebg-v2-view-duplicates-link">View details</a>
                </div>
                
                <p class="aebg-help-text">
                    <span class="aebg-icon">💡</span>
                    Enter one title per line. You can add multiple titles.
                </p>
            </div>
            
            <div class="aebg-step-actions">
                <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-v2-back" style="display: none;">
                    ← Back
                </button>
                <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-v2-continue" disabled>
                    Continue →
                </button>
            </div>
        </div>

        <!-- Step 2: Select Template -->
        <div class="aebg-step" data-step="2" id="aebg-step-2" style="display: none;">
            <div class="aebg-step-question">
                <h2>Hvilken template vil du bruge?</h2>
                <p class="aebg-step-subtitle">Which template would you like to use?</p>
            </div>
            
            <div class="aebg-step-content">
                <div class="aebg-search-wrapper">
                    <input 
                        type="text" 
                        id="aebg-v2-template-search" 
                        class="aebg-v2-search" 
                        placeholder="Search templates..."
                    />
                </div>
                
                <div class="aebg-template-table-wrapper">
                    <table class="aebg-template-table" id="aebg-v2-template-table">
                        <thead>
                            <tr>
                                <th>Template</th>
                                <th>Type</th>
                                <th>Products</th>
                                <th>Last Used</th>
                                <th>Select</th>
                            </tr>
                        </thead>
                        <tbody id="aebg-v2-template-list">
                            <!-- Templates will be loaded here -->
                        </tbody>
                    </table>
                </div>
                
                <div id="aebg-v2-template-selected" class="aebg-v2-success" style="display: none;">
                    <span class="aebg-icon">✓</span>
                    <span id="aebg-v2-template-message">Template selected. Set to <strong id="aebg-v2-product-count">X</strong> products per post.</span>
                </div>
            </div>
            
            <div class="aebg-step-actions">
                <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-v2-back-2">
                    ← Back
                </button>
                <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-v2-continue-2" disabled>
                    Continue →
                </button>
            </div>
        </div>

        <!-- Step 3: Post Status & Images -->
        <div class="aebg-step" data-step="3" id="aebg-step-3" style="display: none;">
            <div class="aebg-step-question">
                <h2>Hvordan skal posts oprettes?</h2>
                <p class="aebg-step-subtitle">How should posts be created?</p>
            </div>
            
            <div class="aebg-step-content">
                <!-- Post Status Selection -->
                <div class="aebg-v2-post-status">
                    <label class="aebg-v2-option">
                        <input type="radio" name="aebg-v2-post-status" value="draft" checked>
                        <span class="aebg-option-content">
                            <span class="aebg-option-icon">📝</span>
                            <span class="aebg-option-text">
                                <strong>Draft</strong>
                                <small>Save as draft for review</small>
                            </span>
                        </span>
                    </label>
                    
                    <label class="aebg-v2-option">
                        <input type="radio" name="aebg-v2-post-status" value="publish">
                        <span class="aebg-option-content">
                            <span class="aebg-option-icon">🌐</span>
                            <span class="aebg-option-text">
                                <strong>Published</strong>
                                <small>Publish immediately</small>
                            </span>
                        </span>
                    </label>
                    
                    <label class="aebg-v2-option">
                        <input type="radio" name="aebg-v2-post-status" value="private">
                        <span class="aebg-option-content">
                            <span class="aebg-option-icon">🔒</span>
                            <span class="aebg-option-text">
                                <strong>Private</strong>
                                <small>Only visible to you</small>
                            </span>
                        </span>
                    </label>
                    
                    <label class="aebg-v2-option">
                        <input type="radio" name="aebg-v2-post-status" value="pending">
                        <span class="aebg-option-content">
                            <span class="aebg-option-icon">⏳</span>
                            <span class="aebg-option-text">
                                <strong>Pending Review</strong>
                                <small>Requires approval</small>
                            </span>
                        </span>
                    </label>
                </div>
                
                <!-- Image Configuration (appears dynamically) -->
                <div id="aebg-v2-image-config" class="aebg-v2-image-config" style="display: none;">
                    <h3>Image Generation</h3>
                    
                    <div class="aebg-v2-toggle-group">
                        <label class="aebg-v2-toggle">
                            <input type="checkbox" id="aebg-v2-generate-featured-images">
                            <span class="aebg-toggle-label">
                                <span class="aebg-icon">🖼️</span>
                                Generate featured images
                            </span>
                        </label>
                    </div>
                    
                    <div id="aebg-v2-image-options" style="display: none;">
                        <div class="aebg-v2-form-group">
                            <label for="aebg-v2-featured-image-style">Featured Image Style</label>
                            <select id="aebg-v2-featured-image-style" class="aebg-v2-select">
                                <option value="realistic photo">📸 Realistic Photo</option>
                                <option value="digital art">🎨 Digital Art</option>
                                <option value="illustration">✏️ Illustration</option>
                                <option value="3D render">🎭 3D Render</option>
                                <option value="minimalist">⚪ Minimalist</option>
                                <option value="vintage">📜 Vintage</option>
                                <option value="modern">🚀 Modern</option>
                                <option value="professional">💼 Professional</option>
                            </select>
                        </div>
                        
                        <div class="aebg-v2-form-group">
                            <label for="aebg-v2-image-model">Image Model</label>
                            <select id="aebg-v2-image-model" class="aebg-v2-select">
                                <option value="dall-e-3" selected>DALL-E 3 (Recommended - Best Quality)</option>
                                <option value="dall-e-2">DALL-E 2 (Legacy - Lower Cost)</option>
                                <optgroup label="Nano Banana (Google Gemini)">
                                    <option value="nano-banana-2">Nano Banana 2 (Gemini 3.1 Flash – recommended)</option>
                                    <option value="nano-banana">Nano Banana (Gemini 2.5 Flash – fast)</option>
                                    <option value="nano-banana-pro">Nano Banana Pro (Gemini 3 Pro – professional)</option>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="aebg-v2-form-group">
                            <label for="aebg-v2-image-size">Image Size</label>
                            <select id="aebg-v2-image-size" class="aebg-v2-select">
                                <option value="1024x1024" selected>Square (1024×1024)</option>
                                <option value="1792x1024">Landscape (1792×1024)</option>
                                <option value="1024x1792">Portrait (1024×1792)</option>
                            </select>
                        </div>
                        
                        <div class="aebg-v2-form-group">
                            <label>Image Quality</label>
                            <div class="aebg-v2-radio-group">
                                <label class="aebg-v2-radio-option">
                                    <input type="radio" name="aebg-v2-image-quality" value="standard" checked>
                                    <span class="aebg-radio-content">
                                        <strong>Standard Quality</strong>
                                        <small>Faster generation, lower cost ($0.04 per image)</small>
                                    </span>
                                </label>
                                <label class="aebg-v2-radio-option" id="aebg-v2-hd-option">
                                    <input type="radio" name="aebg-v2-image-quality" value="hd">
                                    <span class="aebg-radio-content">
                                        <strong>HD Quality</strong>
                                        <small>Higher resolution, slower generation, higher cost ($0.08 per image)</small>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="aebg-step-actions">
                <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-v2-back-3">
                    ← Back
                </button>
                <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-v2-continue-3">
                    Continue →
                </button>
            </div>
        </div>

        <!-- Step 4: Generation Options -->
        <div class="aebg-step" data-step="4" id="aebg-step-4" style="display: none;">
            <div class="aebg-step-question">
                <h2>Generation Options</h2>
                <p class="aebg-step-subtitle">
                    <span class="aebg-optional-badge">OPTIONAL</span>
                    Configure additional generation options
                </p>
            </div>
            
            <div class="aebg-step-content">
                <div class="aebg-v2-generation-options">
                    <div class="aebg-v2-option-card">
                        <div class="aebg-option-card-icon">
                            <span class="aebg-icon">🏷️</span>
                            <span class="aebg-checkmark">✓</span>
                        </div>
                        <div class="aebg-option-card-content">
                            <h3>Auto-assign categories</h3>
                            <p>Automatically assign relevant categories based on content analysis.</p>
                        </div>
                        <label class="aebg-option-card-toggle">
                            <input type="checkbox" id="aebg-v2-auto-categories" checked>
                            <span class="aebg-toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="aebg-v2-option-card">
                        <div class="aebg-option-card-icon">
                            <span class="aebg-icon">🏷️</span>
                            <span class="aebg-checkmark">✓</span>
                        </div>
                        <div class="aebg-option-card-content">
                            <h3>Auto-generate tags</h3>
                            <p>Generate relevant tags for better SEO and organization.</p>
                        </div>
                        <label class="aebg-option-card-toggle">
                            <input type="checkbox" id="aebg-v2-auto-tags" checked>
                            <span class="aebg-toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="aebg-v2-option-card">
                        <div class="aebg-option-card-icon">
                            <span class="aebg-icon">🔍</span>
                            <span class="aebg-checkmark">✓</span>
                        </div>
                        <div class="aebg-option-card-content">
                            <h3>Generate meta descriptions</h3>
                            <p>Create SEO-optimized meta descriptions for each post.</p>
                        </div>
                        <label class="aebg-option-card-toggle">
                            <input type="checkbox" id="aebg-v2-generate-meta" checked>
                            <span class="aebg-toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="aebg-v2-option-card">
                        <div class="aebg-option-card-icon">
                            <span class="aebg-icon">📊</span>
                            <span class="aebg-checkmark">✓</span>
                        </div>
                        <div class="aebg-option-card-content">
                            <h3>Add structured data (Schema.org)</h3>
                            <p>Include structured data markup for better search engine understanding.</p>
                        </div>
                        <label class="aebg-option-card-toggle">
                            <input type="checkbox" id="aebg-v2-add-schema">
                            <span class="aebg-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="aebg-step-actions">
                <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-v2-back-4">
                    ← Back
                </button>
                <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-v2-continue-4">
                    Continue →
                </button>
            </div>
        </div>

        <!-- Step 5: Review & Generate -->
        <div class="aebg-step" data-step="5" id="aebg-step-5" style="display: none;">
            <div class="aebg-step-question">
                <h2>Er alt korrekt?</h2>
                <p class="aebg-step-subtitle">Is everything correct?</p>
            </div>
            
            <div class="aebg-step-content">
                <div class="aebg-v2-review-summary" id="aebg-v2-review-summary">
                    <!-- Summary will be generated here -->
                </div>
                
                <div class="aebg-v2-cost-breakdown" id="aebg-v2-cost-breakdown">
                    <h3>Cost Estimate</h3>
                    <div class="aebg-cost-item">
                        <span>Text Generation:</span>
                        <strong id="aebg-v2-text-cost">$0.00</strong>
                    </div>
                    <div class="aebg-cost-item">
                        <span>Image Generation:</span>
                        <strong id="aebg-v2-image-cost">$0.00</strong>
                    </div>
                    <div class="aebg-cost-total">
                        <span>Total Estimated Cost:</span>
                        <strong id="aebg-v2-total-cost">$0.00</strong>
                    </div>
                    <div class="aebg-cost-time">
                        <span>Estimated Time:</span>
                        <strong id="aebg-v2-estimated-time">~0 minutes</strong>
                    </div>
                </div>
            </div>
            
            <div class="aebg-step-actions">
                <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-v2-back-5">
                    ← Back
                </button>
                <button type="button" class="aebg-btn aebg-btn-primary aebg-btn-large" id="aebg-v2-generate">
                    🚀 Generate Content
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="aebg-v2-loading-overlay" class="aebg-v2-loading-overlay" style="display: none;">
    <div class="aebg-loading-spinner"></div>
    <div class="aebg-loading-text">Processing your request...</div>
</div>

