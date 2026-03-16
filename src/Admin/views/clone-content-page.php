<?php
/**
 * Clone Content Page Template
 * 
 * @package AEBG
 * @since 1.0.0
 */
?>
<div class="aebg-generator-container">
    <!-- Header Section -->
    <div class="aebg-generator-header">
        <div class="aebg-generator-title">
            <h1>📋 Clone Content</h1>
            <p>Generate a single post using products from a competitor's website</p>
        </div>
    </div>

    <!-- Progress Section for Scanning -->
    <div id="aebg-scan-progress-section" class="aebg-progress-section" style="display: none;">
        <div class="aebg-progress-header">
            <h3>🔍 Scanning Competitor</h3>
        </div>
        
        <div class="aebg-progress-bar-container">
            <div class="aebg-progress-bar">
                <div id="aebg-scan-progress-bar-inner" class="aebg-progress-bar-inner" style="width: 0%;"></div>
            </div>
            <div class="aebg-progress-text" id="aebg-scan-progress-text">Initializing scan...</div>
        </div>

        <div class="aebg-progress-details">
            <div class="aebg-progress-item" id="aebg-scan-current-step">
                <span class="aebg-icon">⏳</span>
                <span id="aebg-scan-current-activity">Preparing to scan...</span>
            </div>
        </div>
    </div>

    <!-- Progress Section for Generation -->
    <div id="aebg-generation-progress-section" class="aebg-progress-section" style="display: none;">
        <div class="aebg-progress-header">
            <h3>🔄 Generating Content</h3>
        </div>
        
        <div class="aebg-progress-bar-container">
            <div class="aebg-progress-bar">
                <div id="aebg-generation-progress-bar-inner" class="aebg-progress-bar-inner" style="width: 0%;"></div>
            </div>
            <div class="aebg-progress-text" id="aebg-generation-progress-text">Initializing generation...</div>
        </div>

        <div class="aebg-progress-details">
            <div class="aebg-progress-item" id="aebg-generation-current-step">
                <span class="aebg-icon">📝</span>
                <span id="aebg-generation-current-activity">Preparing to generate...</span>
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="aebg-generator-grid">
        <!-- Content Configuration Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>📝 Content Configuration</h2>
                <div class="aebg-card-badge">Required</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label for="aebg-clone-title">Post Title</label>
                    <input 
                        type="text" 
                        name="aebg_clone_title" 
                        id="aebg-clone-title" 
                        class="aebg-input" 
                        placeholder="Best 7 Gaming Headsets for Streaming in 2025"
                        required
                    >
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💡</span>
                        Enter the title for your post. The AI will generate comprehensive content based on this title.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-clone-post-type">Post Type</label>
                    <select name="aebg_clone_post_type" id="aebg-clone-post-type" class="aebg-select">
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
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-clone-post-status">Post Status</label>
                    <select name="aebg_clone_post_status" id="aebg-clone-post-status" class="aebg-select">
                        <option value="draft">📝 Draft</option>
                        <option value="publish">🌐 Published</option>
                        <option value="private">🔒 Private</option>
                        <option value="pending">⏳ Pending Review</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Competitor & Template Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>🔍 Competitor & Template</h2>
                <div class="aebg-card-badge">Required</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label for="aebg-clone-competitor-url">Competitor URL</label>
                    <input 
                        type="url" 
                        name="aebg_clone_competitor_url" 
                        id="aebg-clone-competitor-url" 
                        class="aebg-input" 
                        placeholder="https://competitor.com/best-products"
                        required
                    >
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🔗</span>
                        Enter the URL of a competitor's product list page. The system will extract products for you to review.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-clone-template">Elementor Template</label>
                    <select name="aebg_clone_template" id="aebg-clone-template" class="aebg-select" required>
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
                        Choose an Elementor template to use as the base layout. The template should contain {product-X} variables.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label>Scan Method</label>
                    <div class="aebg-radio-group" style="margin-bottom: 15px;">
                        <label class="aebg-radio-label">
                            <input type="radio" name="aebg_clone_scan_method" id="aebg-clone-scan-method-scrape" value="scrape" checked>
                            <span class="aebg-radio-custom"></span>
                            <span class="aebg-radio-text">
                                <strong>🔧 Scrape Website</strong>
                                <small>Fetches and cleans HTML, then analyzes with AI (current method)</small>
                            </span>
                        </label>
                        <label class="aebg-radio-label">
                            <input type="radio" name="aebg_clone_scan_method" id="aebg-clone-scan-method-ai" value="ai_analysis">
                            <span class="aebg-radio-custom"></span>
                            <span class="aebg-radio-text">
                                <strong>🤖 AI Analysis</strong>
                                <small>Direct AI analysis with URL context (uses GPT-4o for better web understanding)</small>
                            </span>
                        </label>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">💡</span>
                        <strong>Scrape Website:</strong> Better for complex pages, handles JavaScript-rendered content. <strong>AI Analysis:</strong> Faster, uses advanced AI models for direct website understanding.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-clone-scan-competitor">
                        <span class="aebg-icon">🔍</span>
                        Scan Competitor
                    </button>
                </div>
            </div>
        </div>

        <!-- Template & Products Card -->
        <div class="aebg-generator-card">
            <div class="aebg-card-header">
                <h2>🖼️ Image Settings</h2>
                <div class="aebg-card-badge optional">Optional</div>
            </div>
            <div class="aebg-card-content">
                <div class="aebg-form-group">
                    <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                        <input type="checkbox" name="aebg_clone_include_ai_images" id="aebg-clone-include-ai-images" value="1">
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
                        <input type="checkbox" name="aebg_clone_generate_featured_images" id="aebg-clone-generate-featured-images" value="1">
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

                <div class="aebg-form-group" id="aebg-clone-featured-image-style-group" style="display: none;">
                    <label for="aebg-clone-featured-image-style">Featured Image Style</label>
                    <select name="aebg_clone_featured_image_style" id="aebg-clone-featured-image-style" class="aebg-select">
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
                <div class="aebg-form-group" id="aebg-clone-image-model-group" style="display: none;">
                    <label for="aebg-clone-image-model">Image Generation Model</label>
                    <select name="aebg_clone_image_model" id="aebg-clone-image-model" class="aebg-select">
                        <option value="dall-e-3" selected>DALL-E 3 (Recommended - Best Quality)</option>
                        <option value="dall-e-2">DALL-E 2 (Legacy - Lower Cost)</option>
                        <optgroup label="Nano Banana (Google Gemini)">
                            <option value="nano-banana-2">Nano Banana 2 (Gemini 3.1 Flash – recommended)</option>
                            <option value="nano-banana">Nano Banana (Gemini 2.5 Flash – fast)</option>
                            <option value="nano-banana-pro">Nano Banana Pro (Gemini 3 Pro – professional)</option>
                        </optgroup>
                    </select>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🤖</span>
                        Choose the AI model for image generation. DALL-E 3 provides better quality but costs more.
                    </p>
                </div>

                <!-- Image Size Selection -->
                <div class="aebg-form-group" id="aebg-clone-image-size-group" style="display: none;">
                    <label for="aebg-clone-image-size">Image Size / Aspect Ratio</label>
                    <select name="aebg_clone_image_size" id="aebg-clone-image-size" class="aebg-select">
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
                <div class="aebg-form-group" id="aebg-clone-image-quality-group" style="display: none;">
                    <label>Image Quality</label>
                    <div class="aebg-radio-group">
                        <label class="aebg-radio-label">
                            <input type="radio" name="aebg_clone_image_quality" id="aebg-clone-image-quality-standard" value="standard" checked>
                            <span class="aebg-radio-custom"></span>
                            <span class="aebg-radio-text">
                                <strong>Standard Quality</strong>
                                <small>Faster generation, lower cost ($0.04 per image)</small>
                            </span>
                        </label>
                        <label class="aebg-radio-label" id="aebg-clone-image-quality-hd-wrapper">
                            <input type="radio" name="aebg_clone_image_quality" id="aebg-clone-image-quality-hd" value="hd">
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
                    <label for="aebg-clone-ai-model">AI Model</label>
                    <select name="aebg_clone_ai_model" id="aebg-clone-ai-model" class="aebg-select">
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
                    <label for="aebg-clone-creativity">Creativity Level</label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_clone_creativity" 
                            id="aebg-clone-creativity" 
                            class="aebg-slider" 
                            min="0" 
                            max="2" 
                            step="0.1" 
                            value="0.7"
                        >
                        <div class="aebg-slider-labels">
                            <span>Focused</span>
                            <span id="aebg-clone-creativity-value">0.7</span>
                            <span>Creative</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">🎭</span>
                        Control the creativity vs consistency of the generated content.
                    </p>
                </div>

                <div class="aebg-form-group">
                    <label for="aebg-clone-content-length">Content Length</label>
                    <div class="aebg-slider-container">
                        <input 
                            type="range" 
                            name="aebg_clone_content_length" 
                            id="aebg-clone-content-length" 
                            class="aebg-slider" 
                            min="500" 
                            max="3000" 
                            step="100" 
                            value="1500"
                        >
                        <div class="aebg-slider-labels">
                            <span>Short</span>
                            <span id="aebg-clone-content-length-value">1500 words</span>
                            <span>Long</span>
                        </div>
                    </div>
                    <p class="aebg-help-text">
                        <span class="aebg-icon">📏</span>
                        Target word count for each generated post.
                    </p>
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
                        <input type="checkbox" name="aebg_clone_auto_categories" id="aebg-clone-auto-categories" value="1" checked>
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
                        <input type="checkbox" name="aebg_clone_auto_tags" id="aebg-clone-auto-tags" value="1" checked>
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
                        <input type="checkbox" name="aebg_clone_include_meta" id="aebg-clone-include-meta" value="1" checked>
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
                        <input type="checkbox" name="aebg_clone_include_schema" id="aebg-clone-include-schema" value="1">
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
</div>

<!-- Product Approval Modal -->
<div id="aebg-product-approval-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content aebg-modal-large">
        <div class="aebg-modal-header">
            <h2>✓ Review & Approve Products</h2>
            <button type="button" class="aebg-modal-close" id="aebg-approval-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <!-- Shortage Notification -->
            <div id="aebg-shortage-notification" class="aebg-shortage-notification" style="display: none;">
                <div class="aebg-shortage-content">
                    <span class="aebg-icon">⚠️</span>
                    <div class="aebg-shortage-text">
                        <strong>Product Shortage Detected</strong>
                        <p>Found <strong id="aebg-shortage-found-count">0</strong> products, but template requires <strong id="aebg-shortage-required-count">0</strong>. Missing <strong id="aebg-shortage-missing-count">0</strong> products.</p>
                    </div>
                </div>
                <div class="aebg-shortage-actions">
                    <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-ai-find-missing-btn">
                        <span class="aebg-icon">🤖</span>
                        AI Find Missing Products
                    </button>
                    <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-manual-search-btn">
                        <span class="aebg-icon">🔍</span>
                        Manual Search
                    </button>
                </div>
            </div>
            
            <div class="aebg-product-summary">
                <p>Found <strong id="aebg-found-count">0</strong> products, need <strong id="aebg-required-count">0</strong> for template</p>
                <p>Selected: <strong id="aebg-selected-count">0</strong> / <strong id="aebg-required-count-2">0</strong> required</p>
            </div>
            <div id="aebg-products-list" class="aebg-products-list">
                <!-- Products will be dynamically inserted here -->
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-cancel-approval">Cancel</button>
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-approve-and-generate-btn" disabled>
                Approve & Generate
            </button>
        </div>
    </div>
</div>

<!-- AI Recommendations Modal -->
<div id="aebg-ai-recommendations-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content aebg-modal-large">
        <div class="aebg-modal-header">
            <h2>🤖 AI Product Recommendations</h2>
            <button type="button" class="aebg-modal-close" id="aebg-ai-recommendations-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div id="aebg-ai-recommendations-content">
                <!-- Recommendations will be dynamically inserted here -->
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-cancel-recommendations">Cancel</button>
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-add-recommendations-btn">
                Add Selected to List
            </button>
        </div>
    </div>
</div>

<!-- Manual Product Search Modal -->
<div id="aebg-manual-search-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content aebg-modal-large">
        <div class="aebg-modal-header">
            <h2>🔍 Manual Product Search</h2>
            <button type="button" class="aebg-modal-close" id="aebg-manual-search-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div class="aebg-search-section">
                <div class="aebg-search-filters">
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="aebg-manual-search-name">Product Name</label>
                            <input type="text" id="aebg-manual-search-name" placeholder="Enter product name..." />
                        </div>
                        <div class="aebg-search-field">
                            <label for="aebg-manual-search-brand">Brand (optional)</label>
                            <input type="text" id="aebg-manual-search-brand" placeholder="Enter brand name..." />
                        </div>
                    </div>
                    <div class="aebg-search-row">
                        <div class="aebg-search-field">
                            <label for="aebg-manual-search-min-price">Min Price</label>
                            <input type="number" id="aebg-manual-search-min-price" placeholder="0" step="0.01" />
                        </div>
                        <div class="aebg-search-field">
                            <label for="aebg-manual-search-max-price">Max Price</label>
                            <input type="number" id="aebg-manual-search-max-price" placeholder="999999" step="0.01" />
                        </div>
                    </div>
                    <div class="aebg-search-actions">
                        <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-manual-search-execute">
                            <span class="aebg-icon">🔍</span> Search Products
                        </button>
                        <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-manual-search-clear">
                            Clear
                        </button>
                    </div>
                </div>
            </div>
            <div class="aebg-search-results-section" id="aebg-manual-search-results-section" style="display: none;">
                <div class="aebg-search-results-header">
                    <h3>Search Results <span id="aebg-manual-search-results-count">(0 results)</span></h3>
                </div>
                <div class="aebg-search-results" id="aebg-manual-search-results">
                    <!-- Search results will be inserted here -->
                </div>
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-cancel-manual-search">Cancel</button>
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-add-selected-products-btn" disabled>
                Add Selected Products
            </button>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div id="aebg-error-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content">
        <div class="aebg-modal-header">
            <h3>❌ Error</h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div id="aebg-error-content"></div>
        </div>
    </div>
</div>
                </div>
                <div class="aebg-shortage-actions">
                    <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-ai-find-missing-btn">
                        <span class="aebg-icon">🤖</span>
                        AI Find Missing Products
                    </button>
                    <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-manual-search-btn">
                        <span class="aebg-icon">🔍</span>
                        Manual Search
                    </button>
                </div>
            </div>
            
            <div class="aebg-product-summary">
                <p>Found <strong id="aebg-found-count">0</strong> products, need <strong id="aebg-required-count">0</strong> for template</p>
                <p>Selected: <strong id="aebg-selected-count">0</strong> / <strong id="aebg-required-count-2">0</strong> required</p>
            </div>
            <div id="aebg-products-list" class="aebg-products-list">
                <!-- Products will be dynamically inserted here -->
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-cancel-approval">Cancel</button>
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-approve-and-generate-btn" disabled>
                Approve & Generate
            </button>
        </div>
    </div>
</div>

<!-- AI Recommendations Modal -->
<div id="aebg-ai-recommendations-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content aebg-modal-large">
        <div class="aebg-modal-header">
            <h2>🤖 AI Product Recommendations</h2>
            <button type="button" class="aebg-modal-close" id="aebg-ai-recommendations-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div id="aebg-ai-recommendations-content">
                <!-- Recommendations will be dynamically inserted here -->
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="aebg-btn aebg-btn-secondary" id="aebg-cancel-recommendations">Cancel</button>
            <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-add-recommendations-btn">
                Add Selected to List
            </button>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div id="aebg-error-modal" class="aebg-modal" style="display: none;">
    <div class="aebg-modal-content">
        <div class="aebg-modal-header">
            <h3>❌ Error</h3>
            <button class="aebg-modal-close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <div id="aebg-error-content"></div>
        </div>
    </div>
</div>

