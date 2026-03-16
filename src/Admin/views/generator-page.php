<?php
/**
 * AI Content Generator Page Template
 * 
 * @package AEBG
 * @since 1.0.0
 */
?>
<div class="aebg-generator-container">
    <!-- Header Section -->
    <div class="aebg-generator-header">
        <div class="aebg-generator-title">
            <h1>🚀 AI Content Generator</h1>
            <p>Generate high-quality blog posts, pages, and custom post types with AI-powered content creation</p>
        </div>
        <div class="aebg-generator-actions">
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-test-connection">
                <span class="aebg-icon">🔗</span>
                Test Connection
            </button>
            <button type="button" class="aebg-btn aebg-btn-success" id="aebg-view-results">
                <span class="aebg-icon">📊</span>
                View Results
            </button>
        </div>
    </div>

    <!-- Connection Status -->
    <div class="aebg-connection-status">
        <div class="aebg-status-indicator" id="aebg-status-indicator"></div>
        <span id="aebg-status-text">Checking connection...</span>
    </div>

    <!-- Progress Section - Moved to top for visibility -->
    <div id="aebg-progress-section" class="aebg-progress-section" style="display: none;">
        <div class="aebg-progress-header">
            <h3>🔄 Generating Content</h3>
            <div class="aebg-progress-stats">
                <span id="aebg-progress-stats"></span>
            </div>
        </div>
        
        <div class="aebg-progress-bar-container">
            <div class="aebg-progress-bar">
                <div id="aebg-progress-bar-inner" class="aebg-progress-bar-inner"></div>
            </div>
            <div class="aebg-progress-text" id="aebg-progress-text">Initializing...</div>
        </div>

        <div class="aebg-progress-details">
            <div class="aebg-progress-item" id="aebg-current-item">
                <span class="aebg-icon">📝</span>
                <span id="aebg-current-activity">Preparing to generate...</span>
            </div>
            <!-- List of all posts being processed with their current steps -->
            <div id="aebg-processing-items-list" class="aebg-processing-items-list" style="display: none;">
                <div class="aebg-processing-items-header">
                    <strong>Currently Processing:</strong>
                </div>
                <div id="aebg-processing-items-content" class="aebg-processing-items-content">
                    <!-- Items will be dynamically inserted here -->
                </div>
            </div>
            <!-- List of failed items with error messages -->
            <div id="aebg-failed-items-list" class="aebg-failed-items-list" style="display: none;">
                <div class="aebg-failed-items-header">
                    <strong>⚠️ Failed Items:</strong>
                    <span id="aebg-failed-count" class="aebg-failed-count"></span>
                </div>
                <div id="aebg-failed-items-content" class="aebg-failed-items-content">
                    <!-- Failed items will be dynamically inserted here -->
                </div>
            </div>
        </div>

        <div class="aebg-progress-actions">
            <button type="button" id="aebg-cancel-generation" class="aebg-btn aebg-btn-secondary">
                <span class="aebg-icon">⏹️</span>
                Cancel Generation
            </button>
            <button type="button" id="aebg-view-live-results" class="aebg-btn aebg-btn-success" style="display: none;">
                <span class="aebg-icon">👁️</span>
                View Live Results
            </button>
        </div>
    </div>

    <!-- Main Generator Form -->
    <div class="aebg-generator-grid">
        <!-- Content Configuration Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>📝 Content Configuration</h2>
                <div class="aebg-card-badge">Required</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label for="aebg-titles">Post Titles</label>
                        <button type="button" id="aebg-check-duplicates" class="aebg-btn aebg-btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                            <span class="aebg-icon">🔍</span>
                            Check for Duplicates
                        </button>
                    </div>
                    <textarea 
                        name="aebg_titles" 
                        id="aebg-titles" 
                        class="aebg-textarea" 
                        rows="8" 
                        placeholder="Enter one title per line. For example:&#10;Best 7 Gaming Headsets for Streaming in 2025&#10;Top 10 Wireless Earbuds for Running&#10;Ultimate Guide to Smart Home Security Systems"></textarea>
                    <div id="aebg-duplicate-warning" class="aebg-duplicate-warning" style="display: none; margin-top: 8px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404;">
                        <span class="aebg-icon">⚠️</span>
                        <span id="aebg-duplicate-count">0</span> duplicate title(s) found. 
                        <a href="#" id="aebg-view-duplicates-link" style="color: #856404; text-decoration: underline;">View details</a>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💡</span>
                        Enter one title per line. The AI will analyze each title and generate comprehensive content with product recommendations.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-post-type">Post Type</label>
                    <select name="aebg_post_type" id="aebg-post-type" class="aebg-select">
                        <option value="post">📄 Blog Post</option>
                        <option value="page">📋 Page</option>
                        <?php
                        // Get custom post types
                        $custom_post_types = get_post_types(['_builtin' => false, 'public' => true], 'objects');
                        foreach ($custom_post_types as $post_type) {
                            $icon = '📄';
                            if (strpos($post_type->name, 'product') !== false) $icon = '🛍️';
                            if (strpos($post_type->name, 'review') !== false) $icon = '⭐';
                            if (strpos($post_type->name, 'guide') !== false) $icon = '📚';
                            
                            echo '<option value="' . esc_attr($post_type->name) . '">' . $icon . ' ' . esc_html($post_type->labels->singular_name) . '</option>';
                        }
                        ?>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">ℹ️</span>
                        Choose the type of content you want to generate. This affects how the content is structured and categorized.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-post-status">Post Status</label>
                    <select name="aebg_post_status" id="aebg-post-status" class="aebg-select">
                        <option value="draft">📝 Draft</option>
                        <option value="publish">🌐 Published</option>
                        <option value="private">🔒 Private</option>
                        <option value="pending">⏳ Pending Review</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">ℹ️</span>
                        Choose whether to publish immediately or save as draft for review.
                    </p>
                </div>
            </div>
        </div>

        <!-- Template & Products Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>🎨 Template & Products</h2>
                <div class="aebg-card-badge">Required</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label for="aebg-template">Elementor Template</label>
                    <select name="aebg_template" id="aebg-template" class="aebg-select">
                        <option value="">Select a template...</option>
                        <?php
                        $templates = get_posts([
                            'post_type' => 'elementor_library',
                            'posts_per_page' => -1,
                            'orderby' => 'title',
                            'order' => 'ASC'
                        ]);
                        
                        if (empty($templates)) {
                            echo '<option value="" disabled>No Elementor templates found. Please create a template first.</option>';
                        } else {
                            foreach ($templates as $template) {
                                $template_type = get_post_meta($template->ID, '_elementor_template_type', true);
                                $type_icon = '📄';
                                if ($template_type === 'page') $type_icon = '📋';
                                if ($template_type === 'section') $type_icon = '🔧';
                                
                                echo '<option value="' . esc_attr($template->ID) . '">' . $type_icon . ' ' . esc_html($template->post_title) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎨</span>
                        Choose an Elementor template to use as the base layout for your generated content.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-num-products">Number of Products</label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_num_products" 
                            id="aebg-num-products" 
                            class="aebg-slider" 
                            min="1" 
                            max="20" 
                            value="7"
                        >
                        <div class="aebg-slider-labels">
                            <span>1</span>
                            <span id="aebg-num-products-value">7</span>
                            <span>20</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🛍️</span>
                        Number of products to feature in each generated post. More products = longer content.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_include_ai_images" id="aebg-include-ai-images" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🖼️</span>
                            Generate AI images for posts
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🖼️</span>
                        Use AI to generate relevant images for your content (requires DALL-E API access).
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_generate_featured_images" id="aebg-generate-featured-images" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🖼️</span>
                            Generate featured images for posts
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🖼️</span>
                        Automatically create featured images for each post using AI (requires DALL-E API access).
                    </p>
                </div>

                <div class="aebg-form-group" id="aebg-featured-image-style-group" style="display: none;">
                    <label for="aebg-featured-image-style">Featured Image Style</label>
                    <select name="aebg_featured_image_style" id="aebg-featured-image-style" class="aebg-select">
                        <option value="realistic photo">📸 Realistic Photo</option>
                        <option value="digital art">🎨 Digital Art</option>
                        <option value="illustration">✏️ Illustration</option>
                        <option value="3D render">🎭 3D Render</option>
                        <option value="minimalist">⚪ Minimalist</option>
                        <option value="vintage">📜 Vintage</option>
                        <option value="modern">🚀 Modern</option>
                        <option value="professional">💼 Professional</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎨</span>
                        Choose the visual style for your featured images. The AI will use the post title to create relevant images.
                    </p>
                </div>

                <!-- Image Generation Model Selection -->
                <div class="aebg-form-group" id="aebg-image-model-group" style="display: none;">
                    <label for="aebg-image-model">Image Generation Model</label>
                    <select name="aebg_image_model" id="aebg-image-model" class="aebg-select">
                        <option value="dall-e-3" selected>DALL-E 3 (Recommended - Best Quality)</option>
                        <option value="dall-e-2">DALL-E 2 (Legacy - Lower Cost)</option>
                        <optgroup label="Nano Banana (Google Gemini)">
                            <option value="nano-banana-2">Nano Banana 2 (Gemini 3.1 Flash Image – recommended)</option>
                            <option value="nano-banana">Nano Banana (Gemini 2.5 Flash – fast, high-volume)</option>
                            <option value="nano-banana-pro">Nano Banana Pro (Gemini 3 Pro Image – professional)</option>
                        </optgroup>
                    </select>
                    <div class="aebg-model-comparison" style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px; font-size: 12px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 1px solid #ddd;">
                                    <th style="text-align: left; padding: 5px;">Feature</th>
                                    <th style="text-align: center; padding: 5px;">DALL-E 3</th>
                                    <th style="text-align: center; padding: 5px;">DALL-E 2</th>
                                    <th style="text-align: center; padding: 5px;">Nano Banana 2</th>
                                    <th style="text-align: center; padding: 5px;">Nano Banana</th>
                                    <th style="text-align: center; padding: 5px;">Nano Banana Pro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding: 5px;">Quality</td>
                                    <td style="text-align: center; padding: 5px;">⭐⭐⭐⭐⭐</td>
                                    <td style="text-align: center; padding: 5px;">⭐⭐⭐</td>
                                    <td style="text-align: center; padding: 5px;">⭐⭐⭐⭐</td>
                                    <td style="text-align: center; padding: 5px;">⭐⭐⭐⭐</td>
                                    <td style="text-align: center; padding: 5px;">⭐⭐⭐⭐⭐</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Cost (Standard)</td>
                                    <td style="text-align: center; padding: 5px;">$0.04</td>
                                    <td style="text-align: center; padding: 5px;">$0.02</td>
                                    <td style="text-align: center; padding: 5px;">~$0.02</td>
                                    <td style="text-align: center; padding: 5px;">~$0.02</td>
                                    <td style="text-align: center; padding: 5px;">~$0.04</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Cost (HD)</td>
                                    <td style="text-align: center; padding: 5px;">$0.08</td>
                                    <td style="text-align: center; padding: 5px;">N/A</td>
                                    <td style="text-align: center; padding: 5px;">N/A</td>
                                    <td style="text-align: center; padding: 5px;">N/A</td>
                                    <td style="text-align: center; padding: 5px;">N/A</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px;">Sizes Available</td>
                                    <td style="text-align: center; padding: 5px;">3 options</td>
                                    <td style="text-align: center; padding: 5px;">3 options</td>
                                    <td style="text-align: center; padding: 5px;">3 options</td>
                                    <td style="text-align: center; padding: 5px;">3 options</td>
                                    <td style="text-align: center; padding: 5px;">3 options</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🤖</span>
                        Choose the AI model for image generation. Nano Banana 2 is the recommended Gemini option. All Nano Banana models require a Google API key in Settings.
                    </p>
                </div>

                <!-- Image Size Selection -->
                <div class="aebg-form-group" id="aebg-image-size-group" style="display: none;">
                    <label for="aebg-image-size">Image Size / Aspect Ratio</label>
                    <select name="aebg_image_size" id="aebg-image-size" class="aebg-select">
                        <option value="1024x1024" selected>Square (1024×1024)</option>
                        <option value="1792x1024">Landscape (1792×1024)</option>
                        <option value="1024x1792">Portrait (1024×1792)</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📐</span>
                        Choose the image dimensions. Square works for most cases, landscape for headers, portrait for mobile-first content.
                    </p>
                </div>

                <!-- Image Quality Selection -->
                <div class="aebg-form-group" id="aebg-image-quality-group" style="display: none;">
                    <label>Image Quality</label>
                    <div class="aebg-radio-group">
                        <label class="aebg-radio-label">
                            <input type="radio" name="aebg_image_quality" id="aebg-image-quality-standard" value="standard" checked>
                            <span class="aebg-radio-custom"></span>
                            <span class="aebg-radio-text">
                                <strong>Standard Quality</strong>
                                <small>Faster generation, lower cost ($0.04 per image)</small>
                            </span>
                        </label>
                        <label class="aebg-radio-label" id="aebg-image-quality-hd-wrapper">
                            <input type="radio" name="aebg_image_quality" id="aebg-image-quality-hd" value="hd">
                            <span class="aebg-radio-custom"></span>
                            <span class="aebg-radio-text">
                                <strong>HD Quality</strong>
                                <small>Higher resolution, slower generation, higher cost ($0.08 per image)</small>
                            </span>
                        </label>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">⚡</span>
                        Standard quality is recommended for most use cases. HD is available for DALL-E 3 only.
                    </p>
                </div>

                <!-- Cost and Time Estimation -->
                <div class="aebg-form-group" id="aebg-image-estimates-group" style="display: none;">
                    <div class="aebg-estimates-container" style="padding: 15px; background: #f0f7ff; border-radius: 6px; border: 1px solid #b3d9ff;">
                        <div class="aebg-estimate-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div>
                                <span class="aebg-icon">💰</span>
                                <strong>Cost per image:</strong>
                            </div>
                            <div>
                                <strong id="aebg-cost-per-image">$0.04</strong>
                            </div>
                        </div>
                        <div class="aebg-estimate-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div>
                                <span class="aebg-icon">💰</span>
                                <strong>Total estimated cost:</strong>
                            </div>
                            <div>
                                <strong id="aebg-total-cost">$0.00</strong>
                                <small id="aebg-post-count-text" style="display: block; color: #666; font-size: 11px; margin-top: 2px;">(0 posts)</small>
                            </div>
                        </div>
                        <div class="aebg-estimate-row" style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #b3d9ff; padding-top: 10px;">
                            <div>
                                <span class="aebg-icon">⏱️</span>
                                <strong>Estimated time per image:</strong>
                            </div>
                            <div>
                                <strong id="aebg-time-per-image">~15 seconds</strong>
                            </div>
                        </div>
                        <div class="aebg-estimate-row" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                            <div>
                                <span class="aebg-icon">⏱️</span>
                                <strong>Total estimated time:</strong>
                            </div>
                            <div>
                                <strong id="aebg-total-time">~0 seconds</strong>
                            </div>
                        </div>
                    </div>
                    <p class="aebg-help-text" style="margin-top: 10px;">
                        <span class="aebg-icon">ℹ️</span>
                        Estimates are based on current settings and number of posts. Actual costs and times may vary.
                    </p>
                </div>
            </div>
        </div>

        <!-- AI Settings Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>🤖 AI Configuration</h2>
                <div class="aebg-card-badge advanced">Advanced</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label for="aebg-ai-model">AI Model</label>
                    <select name="aebg_ai_model" id="aebg-ai-model" class="aebg-select">
                        <option value="gpt-5.2">🚀 GPT-5.2 (Best Quality)</option>
                        <option value="gpt-5-mini">⚡ GPT-5 Mini (Fast & Efficient)</option>
                        <option value="gpt-4">🔥 GPT-4 (Best Quality)</option>
                        <option value="gpt-3.5-turbo" selected>⚡ GPT-3.5 Turbo (Fast & Cost-Effective)</option>
                        <option value="gpt-4-turbo">🔥 GPT-4 Turbo (Latest)</option>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🧠</span>
                        Choose the AI model for content generation. GPT-4 provides better quality but costs more.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-creativity">Creativity Level</label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_creativity" 
                            id="aebg-creativity" 
                            class="aebg-slider" 
                            min="0" 
                            max="2" 
                            step="0.1" 
                            value="0.7"
                        >
                        <div class="aebg-slider-labels">
                            <span>Focused</span>
                            <span id="aebg-creativity-value">0.7</span>
                            <span>Creative</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎭</span>
                        Control the creativity vs consistency of the generated content.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-content-length">Content Length</label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_content_length" 
                            id="aebg-content-length" 
                            class="aebg-slider" 
                            min="500" 
                            max="3000" 
                            step="100" 
                            value="1500"
                        >
                        <div class="aebg-slider-labels">
                            <span>Short</span>
                            <span id="aebg-content-length-value">1500 words</span>
                            <span>Long</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📏</span>
                        Target word count for each generated post.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label>AI Content Prompt Variables</label>
                    <div class="aebg-variables-help">
                        <p class="aebg-help-text">
                            <span class="aebg-icon">💡</span>
                            You can use variables in your Elementor template AI Content Prompts. Click below to view all available variables with documentation.
                        </p>
                        <button type="button" class="aebg-btn aebg-btn-secondary aebg-variables-modal-trigger" id="aebg-view-variables">
                            <span class="aebg-icon">📋</span>
                            View All Variables & Documentation
                        </button>
                        <p class="aebg-help-text" style="margin-top: 10px; font-size: 12px; opacity: 0.8;">
                            <span class="aebg-icon">🔗</span>
                            <strong>Context Sharing:</strong> AI Content Prompt fields can reference each other using <code>{field_name}</code> syntax.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generation Options Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>⚙️ Generation Options</h2>
                <div class="aebg-card-badge optional">Optional</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_auto_categories" id="aebg-auto-categories" value="1" checked>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🏷️</span>
                            Auto-assign categories
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🏷️</span>
                        Automatically assign relevant categories based on content analysis.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_auto_tags" id="aebg-auto-tags" value="1" checked>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🏷️</span>
                            Auto-generate tags
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🏷️</span>
                        Generate relevant tags for better SEO and organization.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_include_meta" id="aebg-include-meta" value="1" checked>
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">🔍</span>
                            Generate meta descriptions
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🔍</span>
                        Create SEO-optimized meta descriptions for each post.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_include_schema" id="aebg-include-schema" value="1">
                        <span class="aebg-checkbox-custom"></span>
                        <span class="aebg-checkbox-text">
                            <span class="aebg-icon">📊</span>
                            Add structured data (Schema.org)
                        </span>
                    </label>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📊</span>
                        Include structured data markup for better search engine understanding.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Button -->
    <div class="aebg-generate-section">
        <button type="button" id="aebg-generate-posts" class="aebg-btn aebg-btn-primary aebg-btn-large">
            <span class="aebg-icon">🚀</span>
            Generate Content
        </button>
        <p class="aebg-generate-note">
            <span class="aebg-icon">⏱️</span>
            Generation time varies based on content length and number of titles. You'll be notified when complete.
        </p>
    </div>

</div>

<!-- Loading Overlay -->
<div id="aebg-loading-overlay" class="aebg-loading-overlay">
    <div class="aebg-loading-spinner"></div>
    <div class="aebg-loading-text">Processing your request...</div>
</div>

<!-- Error Modal -->
<div id="aebg-error-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content">
        <div class="aebg-modal-header">
            <h3>❌ Generation Error</h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div id="aebg-error-content"></div>
        </div>
    </div>
</div>

<?php
// Get available variables for the modal
try {
    $variables_class = new \AEBG\Core\Variables();
    $available_variables = $variables_class->getAvailableVariables();
} catch (Exception $e) {
    // Fallback if Variables class is not available
    $available_variables = [
        'basic' => [
            '{title}' => 'Post title',
            '{year}' => 'Current year',
            '{date}' => 'Current date (YYYY-MM-DD)',
            '{time}' => 'Current time (HH:MM:SS)',
        ],
        'products' => [],
        'product_images' => [],
        'product_links' => [],
        'context' => [],
    ];
}
?>

<!-- Variables Documentation Modal -->
<div id="aebg-variables-modal" class="aebg-modal aebg-variables-modal" style="display: none;">
    <div class="aebg-modal-content aebg-variables-modal-content">
        <div class="aebg-modal-header">
            <h3>
                <span class="aebg-icon">📋</span>
                Available Variables & Documentation
            </h3>
            <button class="aebg-modal-close" aria-label="Close modal">&times;</button>
        </div>
        <div class="aebg-modal-body aebg-variables-modal-body">
            <div class="aebg-variables-intro">
                <p>Use these variables in your Elementor template AI Content Prompts. Click any variable to copy it to your clipboard.</p>
                <p class="aebg-variables-note">
                    <strong>💡 Tip:</strong> Variables are automatically replaced during content generation. Context variables are extracted from your post title using AI analysis.
                </p>
            </div>

            <div class="aebg-variables-tabs">
                <button class="aebg-variables-tab active" data-tab="basic">Basic</button>
                <button class="aebg-variables-tab" data-tab="products">Products</button>
                <button class="aebg-variables-tab" data-tab="images">Images</button>
                <button class="aebg-variables-tab" data-tab="links">Links</button>
                <button class="aebg-variables-tab" data-tab="context">Context</button>
            </div>

            <div class="aebg-variables-content">
                <!-- Basic Variables -->
                <div class="aebg-variables-tab-content active" data-content="basic">
                    <div class="aebg-variables-section">
                        <h4>Basic Variables</h4>
                        <p class="aebg-variables-section-desc">These variables are always available and don't require any product data.</p>
                        <div class="aebg-variables-list">
                            <?php foreach ($available_variables['basic'] as $var => $desc): ?>
                                <div class="aebg-variable-item" data-variable="<?php echo esc_attr($var); ?>" data-tooltip="<?php echo esc_attr($desc); ?>">
                                    <code class="aebg-variable-code"><?php echo esc_html($var); ?></code>
                                    <span class="aebg-variable-desc"><?php echo esc_html($desc); ?></span>
                                    <button class="aebg-variable-copy" aria-label="Copy variable">
                                        <span class="aebg-icon">📋</span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Product Variables -->
                <div class="aebg-variables-tab-content" data-content="products">
                    <div class="aebg-variables-section">
                        <h4>Product Variables</h4>
                        <p class="aebg-variables-section-desc">Available for each product in your selection. Replace <code>1</code> with <code>2</code>, <code>3</code>, etc. for additional products.</p>
                        <div class="aebg-variables-list">
                            <?php 
                            // Filter out product-2, product-3 from products list (we'll show them separately)
                            $product_vars = array_filter($available_variables['products'], function($key) {
                                return strpos($key, 'product-1') === 0 || strpos($key, 'product-2') === 0 || strpos($key, 'product-3') === 0;
                            }, ARRAY_FILTER_USE_KEY);
                            
                            // Show product-1 variables
                            foreach ($product_vars as $var => $desc): 
                                if (strpos($var, 'product-1') === 0):
                            ?>
                                <div class="aebg-variable-item" data-variable="<?php echo esc_attr($var); ?>" data-tooltip="<?php echo esc_attr($desc); ?>">
                                    <code class="aebg-variable-code"><?php echo esc_html($var); ?></code>
                                    <span class="aebg-variable-desc"><?php echo esc_html($desc); ?></span>
                                    <button class="aebg-variable-copy" aria-label="Copy variable">
                                        <span class="aebg-icon">📋</span>
                                    </button>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                        <div class="aebg-variables-note-box">
                            <strong>Multiple Products:</strong> All these variables work for product-2, product-3, product-4, etc. Just replace the number in the variable name.
                        </div>
                    </div>
                </div>

                <!-- Product Image Variables -->
                <div class="aebg-variables-tab-content" data-content="images">
                    <div class="aebg-variables-section">
                        <h4>Product Image Variables</h4>
                        <p class="aebg-variables-section-desc">Access product images in various formats. Available for all products (product-1, product-2, etc.).</p>
                        <div class="aebg-variables-list">
                            <?php foreach ($available_variables['product_images'] as $var => $desc): ?>
                                <div class="aebg-variable-item" data-variable="<?php echo esc_attr($var); ?>" data-tooltip="<?php echo esc_attr($desc); ?>">
                                    <code class="aebg-variable-code"><?php echo esc_html($var); ?></code>
                                    <span class="aebg-variable-desc"><?php echo esc_html($desc); ?></span>
                                    <button class="aebg-variable-copy" aria-label="Copy variable">
                                        <span class="aebg-icon">📋</span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Product Link Variables -->
                <div class="aebg-variables-tab-content" data-content="links">
                    <div class="aebg-variables-section">
                        <h4>Product Link Variables</h4>
                        <p class="aebg-variables-section-desc">Different types of product URLs. Available for all products.</p>
                        <div class="aebg-variables-list">
                            <?php foreach ($available_variables['product_links'] as $var => $desc): ?>
                                <div class="aebg-variable-item" data-variable="<?php echo esc_attr($var); ?>" data-tooltip="<?php echo esc_attr($desc); ?>">
                                    <code class="aebg-variable-code"><?php echo esc_html($var); ?></code>
                                    <span class="aebg-variable-desc"><?php echo esc_html($desc); ?></span>
                                    <button class="aebg-variable-copy" aria-label="Copy variable">
                                        <span class="aebg-icon">📋</span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="aebg-variables-note-box">
                            <strong>Affiliate URLs:</strong> The <code>{product-N-affiliate-url}</code> variable includes affiliate tracking automatically.
                        </div>
                    </div>
                </div>

                <!-- Context Variables -->
                <div class="aebg-variables-tab-content" data-content="context">
                    <div class="aebg-variables-section">
                        <h4>Context Variables</h4>
                        <p class="aebg-variables-section-desc">Extracted from your post title using AI analysis or set during the generation process.</p>
                        <div class="aebg-variables-list">
                            <?php foreach ($available_variables['context'] as $var => $desc): ?>
                                <div class="aebg-variable-item" data-variable="<?php echo esc_attr($var); ?>" data-tooltip="<?php echo esc_attr($desc); ?>">
                                    <code class="aebg-variable-code"><?php echo esc_html($var); ?></code>
                                    <span class="aebg-variable-desc"><?php echo esc_html($desc); ?></span>
                                    <button class="aebg-variable-copy" aria-label="Copy variable">
                                        <span class="aebg-icon">📋</span>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="aebg-variables-note-box">
                            <strong>Context Sharing:</strong> AI Content Prompt fields can reference each other using <code>{field_name}</code> syntax.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-close-variables-modal">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Duplicate Titles Modal -->
<div id="aebg-duplicate-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content" style="max-width: 800px;">
        <div class="aebg-modal-header">
            <h3>⚠️ Duplicate Titles Found</h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <p style="margin-bottom: 15px;">
                The following titles already exist in your database. You can remove them, edit them, or proceed anyway.
            </p>
            <div id="aebg-duplicate-list" style="max-height: 400px; overflow-y: auto; margin-bottom: 15px;">
                <!-- Duplicate items will be inserted here -->
            </div>
            <div style="display: flex; gap: 10px; padding-top: 15px; border-top: 1px solid #ddd;">
                <button type="button" id="aebg-remove-all-duplicates" class="aebg-btn aebg-btn-secondary">
                    <span class="aebg-icon">🗑️</span>
                    Remove All Duplicates
                </button>
                <button type="button" id="aebg-keep-all-duplicates" class="aebg-btn aebg-btn-secondary">
                    <span class="aebg-icon">✅</span>
                    Keep All (Proceed Anyway)
                </button>
                <div style="flex: 1;"></div>
                <button type="button" id="aebg-close-duplicate-modal" class="aebg-btn aebg-btn-primary">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>

