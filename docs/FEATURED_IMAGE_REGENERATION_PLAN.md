# Featured Image Regeneration - Implementation Plan

## 🎯 Overview

This document outlines a **modular, component-based architecture** for implementing a "Regenerate Featured Image with AI" feature that integrates seamlessly into the WordPress post edit screen. The implementation reuses existing components, follows established patterns, and provides a modern, intuitive user experience.

---

## 🏗️ Architecture Principles

### Modular Design
- **Separation of Concerns**: Each module handles a single responsibility
- **Reusability**: Leverage existing UI components, CSS patterns, and JavaScript utilities
- **Extensibility**: Easy to add features (e.g., preview, variations, history)
- **Maintainability**: Clear boundaries between modules

### Component Reuse Strategy
- ✅ Reuse existing modal system (`aebg-modal`)
- ✅ Reuse existing button styles (`aebg-btn`, `aebg-btn-primary`)
- ✅ Reuse existing form components (selects, inputs)
- ✅ Reuse existing AJAX patterns
- ✅ Reuse existing `FeaturedImageGenerator` class
- ✅ Reuse existing settings retrieval system

---

## 📦 Module Breakdown

### Module 0: ActionHandler Integration (Background Processing)
**File**: `src/Core/ActionHandler.php` (ADD to existing file)

**Responsibility**:
- Handles background featured image regeneration via Action Scheduler
- Integrates with existing ActionHandler architecture
- Provides progress tracking

**Key Methods** (to add):
- `execute_regenerate_featured_image()` - Main handler for Action Scheduler hook
- Updates progress in transient/option for frontend polling

**Action Scheduler Hook**:
- `aebg_regenerate_featured_image` - Registered in ActionHandler constructor

**Integration Pattern**:
- Follows same pattern as `aebg_regenerate_testvinder_only` (line 59)
- Uses existing timeout management, error handling, logging

---

## 📦 Module Breakdown

### Module 1: Featured Image UI Integration
**File**: `src/Admin/FeaturedImageUI.php`

**Responsibility**: 
- Hooks into WordPress featured image metabox
- Adds "Regenerate with AI" button
- Manages UI state and interactions

**Key Methods**:
- `add_featured_image_button()` - Adds button to featured image metabox
- `enqueue_assets()` - Enqueues CSS/JS only on post edit screens
- `get_featured_image_state()` - Returns current featured image status

**WordPress Hooks**:
- `admin_init` - Register metabox hooks
- `admin_enqueue_scripts` - Enqueue assets
- `post_thumbnail_html` - Inject button into featured image metabox

---

### Module 2: Regeneration API Controller
**File**: `src/API/FeaturedImageController.php`

**Responsibility**:
- Handles AJAX requests for image regeneration
- Validates permissions and input
- Coordinates with FeaturedImageGenerator

**Key Methods**:
- `register_routes()` - Registers REST API endpoints
- `regenerate_image()` - Main regeneration handler
- `get_settings()` - Retrieves user settings (style, model, etc.)
- `permissions_check()` - Validates user capabilities

**API Endpoints**:
- `POST /wp-json/aebg/v1/featured-image/regenerate` - Regenerate image
- `GET /wp-json/aebg/v1/featured-image/settings` - Get available styles/settings
- `POST /wp-json/aebg/v1/featured-image/estimate` - Calculate cost and time estimate (reuses GeneratorV2Controller logic)

**Request Format** (regenerate endpoint):
```json
{
  "post_id": 123,
  "style": "realistic photo",
  "image_model": "dall-e-3",
  "image_size": "1024x1024",
  "image_quality": "standard",
  "custom_prompt": "Optional custom prompt text",
  "use_custom_prompt": true
}
```

**Request Format** (estimate endpoint):
```json
{
  "image_model": "dall-e-3",
  "image_quality": "standard",
  "image_size": "1024x1024"
}
```

**Response Format** (estimate endpoint):
```json
{
  "success": true,
  "data": {
    "cost_per_image": 0.04,
    "estimated_time_seconds": 15,
    "estimated_time_formatted": "~15 seconds",
    "model": "dall-e-3",
    "quality": "standard",
    "size": "1024x1024"
  }
}
```

**Response Format**:
```json
{
  "success": true,
  "data": {
    "attachment_id": 123,
    "attachment_url": "https://...",
    "thumbnail_url": "https://...",
    "prompt_used": "The actual prompt that was sent to DALL-E"
  }
}
```

---

### Module 3: Regeneration Modal Component
**File**: `src/Admin/views/featured-image-regenerate-modal.php`

**Responsibility**:
- Provides UI for style selection
- Shows generation progress
- Displays results

**Structure**:
```html
<div id="aebg-featured-image-modal" class="aebg-modal">
  <div class="aebg-modal-content">
    <div class="aebg-modal-header">
      <h3>🎨 Regenerate Featured Image</h3>
      <button class="aebg-modal-close">&times;</button>
    </div>
    <div class="aebg-modal-body">
      <!-- Current image preview (if exists) -->
      <div class="aebg-current-image-preview"></div>
      
      <!-- Style selector -->
      <div class="aebg-form-group">
        <label>Visual Style</label>
        <select id="aebg-featured-image-style" class="aebg-select">
          <!-- Options populated from settings -->
        </select>
      </div>
      
      <!-- Image Model Selection (Reuse from Generator V2) -->
      <div class="aebg-form-group">
        <label for="aebg-featured-image-model">Image Model</label>
        <select id="aebg-featured-image-model" class="aebg-select">
          <option value="dall-e-3" selected>DALL-E 3 (Recommended - Best Quality)</option>
          <option value="dall-e-2">DALL-E 2 (Legacy - Lower Cost)</option>
        </select>
      </div>
      
      <!-- Image Size Selection (Reuse from Generator V2) -->
      <div class="aebg-form-group">
        <label for="aebg-featured-image-size">Image Size</label>
        <select id="aebg-featured-image-size" class="aebg-select">
          <option value="1024x1024" selected>Square (1024×1024)</option>
          <option value="1792x1024">Landscape (1792×1024)</option>
          <option value="1024x1792">Portrait (1024×1792)</option>
        </select>
      </div>
      
      <!-- Image Quality Selection (Reuse from Generator V2) -->
      <div class="aebg-form-group">
        <label>Image Quality</label>
        <div class="aebg-radio-group">
          <label class="aebg-radio-option">
            <input type="radio" name="aebg-featured-image-quality" value="standard" checked>
            <span class="aebg-radio-content">
              <strong>Standard Quality</strong>
              <small>Faster generation, lower cost ($0.04 per image)</small>
            </span>
          </label>
          <label class="aebg-radio-option">
            <input type="radio" name="aebg-featured-image-quality" value="hd">
            <span class="aebg-radio-content">
              <strong>HD Quality</strong>
              <small>Higher resolution, slower generation, higher cost ($0.08 per image)</small>
            </span>
          </label>
        </div>
      </div>
      
      <!-- Cost & Time Estimate (Reuse from Generator V2) -->
      <div class="aebg-featured-image-estimate" id="aebg-featured-image-estimate">
        <div class="aebg-estimate-header">
          <h4>💰 Cost & Time Estimate</h4>
        </div>
        <div class="aebg-estimate-content">
          <div class="aebg-estimate-item">
            <span>Estimated Cost:</span>
            <strong id="aebg-featured-image-cost">$0.04</strong>
          </div>
          <div class="aebg-estimate-item">
            <span>Estimated Time:</span>
            <strong id="aebg-featured-image-time">~15 seconds</strong>
          </div>
        </div>
        <p class="aebg-estimate-note">
          <span class="aebg-icon">ℹ️</span>
          Estimates update automatically based on your selections.
        </p>
      </div>
      
      <!-- Custom Prompt Toggle -->
      <div class="aebg-form-group">
        <label>
          <input type="checkbox" id="aebg-use-custom-prompt">
          Use custom prompt (Advanced)
        </label>
        <p class="description">Override auto-generated prompt with your own</p>
      </div>
      
      <!-- Custom Prompt Field (hidden by default) -->
      <div class="aebg-form-group" id="aebg-custom-prompt-group" style="display: none;">
        <label for="aebg-custom-prompt">Custom Prompt</label>
        <textarea 
          id="aebg-custom-prompt" 
          class="aebg-textarea" 
          rows="4"
          placeholder="Describe the image you want to generate..."
          maxlength="1000"></textarea>
        <div class="aebg-char-counter">
          <span id="aebg-prompt-char-count">0</span> / 1000 characters
        </div>
        <p class="description">
          💡 Tip: Be specific about composition, colors, mood, and key elements.
          The style you selected will still be applied.
        </p>
      </div>
      
      <!-- Progress indicator (hidden initially) -->
      <div class="aebg-featured-image-progress" style="display: none;">
        <!-- Loading spinner and message -->
      </div>
      
      <!-- Preview area (hidden initially) -->
      <div class="aebg-featured-image-preview" style="display: none;">
        <!-- Generated image preview -->
      </div>
    </div>
    <div class="aebg-modal-footer">
      <button type="button" class="aebg-btn aebg-btn-secondary aebg-modal-close">Cancel</button>
      <button type="button" class="aebg-btn aebg-btn-primary" id="aebg-generate-featured-image">
        <span class="aebg-btn-text">Generate Image</span>
        <span class="aebg-btn-spinner" style="display: none;">⏳</span>
      </button>
    </div>
  </div>
</div>
```

**Reuses**:
- `.aebg-modal` styles from `generator.css`
- `.aebg-modal-content` structure
- `.aebg-btn` button styles
- `.aebg-form-group` form styles

---

### Module 4: JavaScript Controller
**File**: `assets/js/featured-image-regenerate.js`

**Responsibility**:
- Handles modal interactions
- Schedules regeneration via API (Action Scheduler)
- Polls status endpoint for progress updates
- Updates UI with real-time progress
- Handles errors and completion

**Progress Tracking** (Reuses existing pattern):
- Polls `/wp-json/aebg/v1/featured-image/status/{job_id}` every 2 seconds
- Shows progress bar, status messages, estimated time remaining
- Updates featured image UI when complete

**Key Functions**:
```javascript
class FeaturedImageRegenerator {
  constructor() {
    this.postId = null;
    this.modal = null;
    this.postTitle = null;
    this.init();
  }
  
  init() {
    // Bind events
    // Setup modal
    // Handle custom prompt toggle
    this.setupCustomPromptToggle();
  }
  
  setupCustomPromptToggle() {
    $('#aebg-use-custom-prompt').on('change', (e) => {
      const useCustom = $(e.target).is(':checked');
      if (useCustom) {
        $('#aebg-custom-prompt-group').slideDown();
        this.updatePromptPreview();
      } else {
        $('#aebg-custom-prompt-group').slideUp();
      }
    });
    
    // Character counter
    $('#aebg-custom-prompt').on('input', () => {
      const length = $('#aebg-custom-prompt').val().length;
      $('#aebg-prompt-char-count').text(length);
    });
    
    // Cost & Time Estimation (Reuse from Generator V2)
    // Update estimates when model, quality, or size changes
    $('#aebg-featured-image-model, #aebg-featured-image-size, input[name="aebg-featured-image-quality"]').on('change', () => {
      this.updateEstimate();
    });
    
    // Initial estimate
    this.updateEstimate();
  }
  
  async updateEstimate() {
    const model = $('#aebg-featured-image-model').val() || 'dall-e-3';
    const quality = $('input[name="aebg-featured-image-quality"]:checked').val() || 'standard';
    const size = $('#aebg-featured-image-size').val() || '1024x1024';
    
    try {
      const response = await $.ajax({
        url: aebgFeaturedImage.ajaxUrl.replace('admin-ajax.php', 'wp-json/aebg/v1/featured-image/estimate'),
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
          image_model: model,
          image_quality: quality,
          image_size: size
        }),
        headers: {
          'X-WP-Nonce': aebgFeaturedImage.nonce
        }
      });
      
      if (response.success && response.data) {
        $('#aebg-featured-image-cost').text('$' + response.data.cost_per_image.toFixed(2));
        $('#aebg-featured-image-time').text(response.data.estimated_time_formatted);
      }
    } catch (error) {
      console.error('Failed to update estimate:', error);
      // Fallback to client-side calculation (reuse from generator.js)
      this.calculateEstimateClientSide(model, quality, size);
    }
  }
  
  calculateEstimateClientSide(model, quality, size) {
    // Reuse calculation logic from generator.js (lines 2065-2087)
    let costPerImage = 0;
    if (model === 'dall-e-3') {
      costPerImage = quality === 'hd' ? 0.08 : 0.04;
    } else if (model === 'dall-e-2') {
      costPerImage = 0.02;
    }
    
    let timePerImage = 15; // Base time
    if (model === 'dall-e-3') {
      timePerImage = quality === 'hd' ? 25 : 15;
    } else if (model === 'dall-e-2') {
      timePerImage = 10;
    }
    // Larger images take slightly longer
    if (size === '1792x1024' || size === '1024x1792') {
      timePerImage += 3;
    }
    
    $('#aebg-featured-image-cost').text('$' + costPerImage.toFixed(2));
    $('#aebg-featured-image-time').text('~' + timePerImage + ' seconds');
  }
  
  openModal(postId) {
    this.postId = postId;
    this.postTitle = $('#title').val() || '';
    // Show modal with current post context
    // Pre-fill custom prompt with auto-generated version
    this.updatePromptPreview();
  }
  
  updatePromptPreview() {
    // Show what the auto-generated prompt would be
    // This helps users understand what they're overriding
  }
  
  async regenerate() {
    const style = $('#aebg-featured-image-style').val();
    const model = $('#aebg-featured-image-model').val();
    const size = $('#aebg-featured-image-size').val();
    const quality = $('input[name="aebg-featured-image-quality"]:checked').val();
    const useCustom = $('#aebg-use-custom-prompt').is(':checked');
    const customPrompt = $('#aebg-custom-prompt').val().trim();
    
    // Validation
    if (useCustom && !customPrompt) {
      alert('Please enter a custom prompt or disable custom prompt mode.');
      return;
    }
    
    // Call API with all parameters
    // Show progress
    // Update featured image on success
  }
  
  updateFeaturedImage(attachmentId, url) {
    // Update WordPress featured image UI
  }
}
```

**Dependencies**:
- jQuery (already enqueued)
- Existing modal utilities (if any)

---

### Module 5: CSS Styles
**File**: `assets/css/featured-image-regenerate.css`

**Responsibility**:
- Styles for button integration
- Modal enhancements (if needed)
- Progress indicators
- Loading states

**Reuses Existing**:
- `.aebg-modal` - Modal container
- `.aebg-btn` - Button styles
- `.aebg-btn-primary` - Primary button
- `.aebg-form-group` - Form elements
- `.aebg-loading-spinner` - Loading indicator

**New Styles** (minimal additions):
```css
/* Button in featured image metabox */
.postimagediv .aebg-regenerate-featured-image-btn {
  margin-top: 10px;
  width: 100%;
}

/* Custom Prompt Toggle */
#aebg-use-custom-prompt {
  margin-right: 8px;
}

/* Custom Prompt Group */
#aebg-custom-prompt-group {
  margin-top: 15px;
  padding-top: 15px;
  border-top: 1px solid #e5e7eb;
}

/* Character Counter */
.aebg-char-counter {
  margin-top: 5px;
  font-size: 12px;
  color: #6b7280;
  text-align: right;
}

.aebg-char-counter .warning {
  color: #f59e0b;
  font-weight: 600;
}

/* Prompt Preview (optional) */
.aebg-prompt-preview {
  background: #f8fafc;
  padding: 12px;
  border-radius: 6px;
  font-size: 13px;
  color: #4b5563;
  margin-top: 10px;
  font-style: italic;
}

/* Progress indicator in modal */
.aebg-featured-image-progress {
  text-align: center;
  padding: 20px;
}

/* Cost & Time Estimate (Reuse from Generator V2) */
.aebg-featured-image-estimate {
  background: #f0f7ff;
  border: 2px solid #b3d9ff;
  border-radius: 12px;
  padding: 20px;
  margin-top: 20px;
}

.aebg-estimate-header h4 {
  margin: 0 0 15px 0;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}

.aebg-estimate-content {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.aebg-estimate-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  font-size: 14px;
  color: #374151;
}

.aebg-estimate-item strong {
  font-size: 16px;
  color: #4f46e5;
  font-weight: 600;
}

.aebg-estimate-note {
  margin-top: 12px;
  font-size: 12px;
  color: #6b7280;
  font-style: italic;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Radio Group (Reuse from Generator V2) */
.aebg-radio-group {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.aebg-radio-option {
  display: flex;
  align-items: flex-start;
  padding: 12px;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.aebg-radio-option:hover {
  border-color: #4f46e5;
  background: #f8fafc;
}

.aebg-radio-option input[type="radio"] {
  margin-right: 12px;
  margin-top: 2px;
}

.aebg-radio-content {
  flex: 1;
}

.aebg-radio-content strong {
  display: block;
  font-size: 14px;
  color: #1f2937;
  margin-bottom: 4px;
}

.aebg-radio-content small {
  display: block;
  font-size: 12px;
  color: #6b7280;
}

.aebg-radio-option input[type="radio"]:checked + .aebg-radio-content,
.aebg-radio-option:has(input[type="radio"]:checked) {
  border-color: #4f46e5;
  background: #f0f7ff;
}

/* Preview thumbnail */
.aebg-featured-image-preview {
  margin-top: 20px;
  padding: 15px;
  background: #f8fafc;
  border-radius: 8px;
}

.aebg-preview-container img {
  max-width: 100%;
  height: auto;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.aebg-prompt-used {
  margin-top: 10px;
  font-size: 12px;
  color: #6b7280;
  font-style: italic;
}

/* Messages */
.aebg-message {
  padding: 12px;
  border-radius: 6px;
  margin-bottom: 15px;
}

.aebg-message-success {
  background: #d1fae5;
  color: #065f46;
  border: 1px solid #6ee7b7;
}

.aebg-message-error {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fca5a5;
}
```

---

## 🎨 User Experience Flow

### 1. Discovery
- **Location**: WordPress featured image metabox (post edit screen)
- **Button**: "🎨 Regenerate with AI" button appears below featured image
- **Visibility**: Only shows when:
  - Post has a title (required for generation)
  - User has `aebg_generate_content` capability
  - OpenAI API key is configured

### 2. Interaction
- **Click Button** → Modal opens
- **Modal Shows**:
  - Current featured image (if exists)
  - Style selector dropdown (reuses existing styles)
  - **Custom Prompt Toggle** (Advanced option)
    - When enabled: Shows textarea for custom prompt
    - When disabled: Uses auto-generated prompt (title + style)
  - Character counter for custom prompt
  - "Generate" button

### 3. Generation
- **Click Generate** → Button shows loading state
- **Progress Indicator**:
  - "Generating image..." message
  - Spinner animation (reuses existing)
  - Estimated time: 10-30 seconds

### 4. Result
- **Success**:
  - New image preview appears
  - "Use This Image" button
  - "Generate Another" option
  - Featured image metabox updates automatically
- **Error**:
  - Clear error message
  - "Try Again" button
  - Help text with troubleshooting

---

## 🔌 Integration Points

### WordPress Featured Image Metabox
```php
// Hook into post_thumbnail_html filter
add_filter('post_thumbnail_html', function($html, $post_id) {
  if (!current_user_can('aebg_generate_content')) {
    return $html;
  }
  
  $button = '<button type="button" class="aebg-regenerate-featured-image-btn aebg-btn aebg-btn-secondary" data-post-id="' . $post_id . '">🎨 Regenerate with AI</button>';
  return $html . $button;
}, 10, 2);
```

### Settings Integration
```php
// Reuse existing Settings class
$settings = get_option('aebg_settings', []);
$api_key = $settings['api_key'] ?? '';
$default_style = $settings['featured_image_style'] ?? 'realistic photo';
```

### FeaturedImageGenerator Integration

**Option 1: Extend ImageProcessor (Recommended - Modular)**
```php
// Create a new method in ImageProcessor that accepts custom prompt
// This keeps the existing generateFeaturedImage() unchanged
$attachment_id = ImageProcessor::generateFeaturedImageWithPrompt(
  $custom_prompt,  // User's custom prompt
  $api_key,
  $ai_model,
  $image_settings  // Includes style, size, quality
);
```

**Option 2: Create Wrapper Method (Alternative)**
```php
// In FeaturedImageController, handle custom prompt logic
if ($use_custom_prompt && !empty($custom_prompt)) {
  // Use custom prompt directly
  $prompt = sanitize_textarea_field($custom_prompt);
  // Optionally append style instructions
  $prompt .= '. ' . ImageProcessor::getStyleInstructions($style);
} else {
  // Use auto-generated prompt (existing behavior)
  $attachment_id = FeaturedImageGenerator::generate(
    $post_id,
    $post_title,
    $settings,
    $api_key,
    $ai_model
  );
}
```

**Recommended Approach**: Extend `ImageProcessor` with a new method that accepts a custom prompt, keeping the existing `generateFeaturedImage()` method unchanged for backward compatibility.

---

## 📁 File Structure

```
ai-content-generator-main/
├── src/
│   ├── Admin/
│   │   ├── FeaturedImageUI.php          [NEW] - UI integration
│   │   └── views/
│   │       └── featured-image-regenerate-modal.php  [NEW] - Modal template
│   └── API/
│       └── FeaturedImageController.php   [NEW] - API endpoints
├── assets/
│   ├── css/
│   │   └── featured-image-regenerate.css [NEW] - Minimal styles
│   └── js/
│       └── featured-image-regenerate.js  [NEW] - JS controller
└── docs/
    └── FEATURED_IMAGE_REGENERATION_PLAN.md [THIS FILE]
```

**No modifications needed to existing files** (except plugin registration)

---

## 🚀 Implementation Steps

### Phase 1: Core Infrastructure (Foundation)
1. ✅ Create `FeaturedImageUI.php` class
   - Register WordPress hooks
   - Add button to featured image metabox
   - Enqueue assets conditionally

2. ✅ Create `FeaturedImageController.php`
   - Register REST API routes
   - Implement permissions check
   - Implement regenerate endpoint

### Phase 2: Frontend Components (UI)
3. ✅ Create modal template
   - Reuse existing modal structure
   - Add style selector
   - Add preview area

4. ✅ Create JavaScript controller
   - Modal management
   - API communication
   - UI updates

5. ✅ Create CSS file
   - Button styling
   - Modal enhancements
   - Progress indicators

### Phase 3: Integration & Testing
6. ✅ Register new classes in Plugin.php
   - Initialize FeaturedImageUI
   - Register API controller

7. ✅ Test integration
   - Button appears correctly
   - Modal opens/closes
   - API calls work
   - Image updates in WordPress

### Phase 4: Polish & Enhancement
8. ✅ Error handling
   - Network errors
   - API errors
   - Validation errors

9. ✅ Loading states
   - Button disabled during generation
   - Progress feedback
   - Timeout handling

10. ✅ Success feedback
    - Image preview
    - Confirmation message
    - Auto-update featured image

---

## 🎯 Key Design Decisions

### 1. Why REST API instead of wp_ajax?
- **Consistency**: Other features use REST API (GeneratorV2Controller)
- **Modern**: Better error handling, validation
- **Testable**: Easier to test and debug
- **Future-proof**: Easier to extend

### 2. Why Separate CSS File?
- **Modularity**: Can be loaded conditionally
- **Maintainability**: Clear separation of concerns
- **Performance**: Only loads on post edit screens
- **Reusability**: Can be extended for other features

### 3. Why Modal Instead of Inline?
- **Consistency**: Matches existing modal patterns
- **UX**: Doesn't clutter featured image metabox
- **Flexibility**: Room for future features (preview, variations)
- **Reusability**: Uses existing modal system

### 4. Why Not Modify FeaturedImageGenerator?
- **Single Responsibility**: Generator handles generation only
- **Reusability**: Can be used by other features
- **Testability**: Easier to test in isolation
- **Maintainability**: No risk of breaking existing functionality

---

## 🔒 Security Considerations

### Permissions
- Check `aebg_generate_content` capability
- Verify post ownership/edit permissions
- Validate nonce for AJAX requests

### Input Validation
- Sanitize post ID (must be integer)
- Validate style selection (whitelist)
- Sanitize API key (never expose in responses)

### Error Handling
- Don't expose sensitive error details
- Log errors server-side
- Return user-friendly messages

---

## 📊 Performance Considerations

### Asset Loading
- **Conditional**: Only load on `post.php` and `post-new.php`
- **Caching**: Use version numbers for cache busting
- **Minification**: Consider minified versions for production

### API Calls
- **Timeout**: Set appropriate timeout (60 seconds for DALL-E)
- **Retry**: Handle transient failures gracefully
- **Rate Limiting**: Consider rate limiting for bulk operations

### Image Handling
- **Optimization**: WordPress handles image optimization
- **Storage**: Images saved to media library (standard WordPress)
- **Cleanup**: Consider cleanup of failed generations (future)

---

## 🔮 Future Enhancements

### Phase 2 Features (Post-MVP)
1. **Image Preview Before Save**
   - Show generated image in modal
   - Allow regeneration without saving
   - Compare with current image

2. **Multiple Variations**
   - Generate 2-3 variations at once
   - User selects favorite
   - Saves others to media library

3. **Generation History**
   - Track previous generations
   - Allow reverting to previous images
   - Show generation metadata

4. **Custom Prompts** ✅ **IMPLEMENTED IN MVP**
   - Allow user to customize prompt
   - Toggle between auto-generated and custom
   - Character counter and validation
   - Preview of auto-generated prompt
   - **Future**: Save prompt templates
   - **Future**: Share prompts between posts

5. **Bulk Regeneration**
   - Select multiple posts
   - Regenerate all featured images
   - Progress tracking

### Phase 3 Features (Advanced)
1. **Style Presets**
   - Save custom style combinations
   - Apply to multiple posts
   - Share with team

2. **A/B Testing**
   - Generate multiple styles
   - Track performance
   - Auto-select best performer

3. **Integration with Media Library**
   - Direct integration with media picker
   - Search generated images
   - Tag and organize

---

## 🧪 Testing Strategy

### Unit Tests
- `FeaturedImageUI` class methods
- `FeaturedImageController` API endpoints
- JavaScript controller functions

### Integration Tests
- Button appears in correct context
- Modal opens/closes correctly
- API calls succeed/fail appropriately
- Featured image updates in WordPress

### User Acceptance Tests
- User can discover feature
- User can regenerate image
- User sees progress feedback
- User can handle errors gracefully

---

## 📝 Code Examples

### FeaturedImageUI.php (Skeleton)
```php
<?php
namespace AEBG\Admin;

class FeaturedImageUI {
    public function __construct() {
        add_action('admin_init', [$this, 'init']);
    }
    
    public function init() {
        add_filter('admin_post_thumbnail_html', [$this, 'add_regenerate_button'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    public function add_regenerate_button($content, $post_id) {
        // Add button logic
    }
    
    public function enqueue_assets($hook) {
        // Enqueue CSS/JS only on post edit screens
    }
}
```

### FeaturedImageController.php (With Custom Prompt Support)
```php
<?php
namespace AEBG\API;

use AEBG\Core\FeaturedImageGenerator;
use AEBG\Core\ImageProcessor;
use AEBG\Core\Settings;

class FeaturedImageController extends \WP_REST_Controller {
    public function __construct() {
        $this->namespace = 'aebg/v1';
        $this->rest_base = 'featured-image';
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/regenerate', [
            'methods' => 'POST',
            'callback' => [$this, 'regenerate_image'],
            'permission_callback' => [$this, 'permissions_check'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ],
                'style' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'realistic photo',
                ],
                'image_model' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'dall-e-3',
                    'enum' => ['dall-e-3', 'dall-e-2'],
                ],
                'image_size' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '1024x1024',
                    'enum' => ['1024x1024', '1792x1024', '1024x1792'],
                ],
                'image_quality' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'standard',
                    'enum' => ['standard', 'hd'],
                ],
                'custom_prompt' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'use_custom_prompt' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);
    }
    
    public function regenerate_image($request) {
        $post_id = $request->get_param('post_id');
        $style = $request->get_param('style');
        $custom_prompt = $request->get_param('custom_prompt');
        $use_custom_prompt = $request->get_param('use_custom_prompt');
        
        // Validate post exists and user can edit
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            return new \WP_Error('invalid_post', 'Invalid post or insufficient permissions', ['status' => 403]);
        }
        
        // Get settings
        $settings = get_option('aebg_settings', []);
        $api_key = $settings['api_key'] ?? '';
        $ai_model = $settings['model'] ?? 'gpt-4';
        
        if (empty($api_key)) {
            return new \WP_Error('no_api_key', 'OpenAI API key not configured', ['status' => 400]);
        }
        
        // Get image settings from request (user selections)
        $image_model = $request->get_param('image_model') ?: ($settings['image_model'] ?? 'dall-e-3');
        $image_size = $request->get_param('image_size') ?: ($settings['image_size'] ?? '1024x1024');
        $image_quality = $request->get_param('image_quality') ?: ($settings['image_quality'] ?? 'standard');
        
        // Prepare image settings
        $image_settings = [
            'image_model' => $image_model,
            'image_size' => $image_size,
            'image_quality' => $image_quality,
        ];
        
        // Handle custom prompt vs auto-generated
        if ($use_custom_prompt && !empty($custom_prompt)) {
            // Use custom prompt with style instructions appended
            $style_instructions = ImageProcessor::getStyleInstructions($style);
            $full_prompt = sanitize_textarea_field($custom_prompt);
            
            // Optionally append style if not already in prompt
            if (stripos($full_prompt, 'style:') === false) {
                $full_prompt .= '. ' . $style_instructions;
            }
            
            // Generate image with custom prompt
            $image_url = ImageProcessor::generateAIImage($full_prompt, $api_key, $ai_model, $image_settings);
            
            if (!$image_url) {
                return new \WP_Error('generation_failed', 'Failed to generate image', ['status' => 500]);
            }
            
            // Download and save image
            $attachment_id = \AEBG\Core\ProductImageManager::downloadAndInsertImage(
                $image_url,
                $full_prompt,
                0
            );
            
            if (!$attachment_id) {
                return new \WP_Error('save_failed', 'Failed to save image', ['status' => 500]);
            }
            
            // Set as featured image
            set_post_thumbnail($post_id, $attachment_id);
            
            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'attachment_id' => $attachment_id,
                    'attachment_url' => wp_get_attachment_url($attachment_id),
                    'thumbnail_url' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
                    'prompt_used' => $full_prompt,
                ]
            ]);
        } else {
            // Use existing FeaturedImageGenerator (auto-generated prompt)
            $settings['generate_featured_images'] = true;
            $settings['featured_image_style'] = $style;
            
            $attachment_id = FeaturedImageGenerator::generate(
                $post_id,
                $post->post_title,
                $settings,
                $api_key,
                $ai_model
            );
            
            if (!$attachment_id) {
                return new \WP_Error('generation_failed', 'Failed to generate image', ['status' => 500]);
            }
            
            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'attachment_id' => $attachment_id,
                    'attachment_url' => wp_get_attachment_url($attachment_id),
                    'thumbnail_url' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
                ]
            ]);
        }
    }
    
    public function permissions_check($request) {
        if (!current_user_can('aebg_generate_content')) {
            return new \WP_Error(
                'rest_forbidden',
                __('Sorry, you are not allowed to do that.', 'aebg'),
                ['status' => rest_authorization_required_code()]
            );
        }
        return true;
    }
}
```

**Note**: This requires making `ImageProcessor::generateAIImage()` and `ImageProcessor::getStyleInstructions()` public or creating public wrapper methods.

### JavaScript (With Custom Prompt Support)
```javascript
(function($) {
    'use strict';
    
    class FeaturedImageRegenerator {
        constructor() {
            this.postId = null;
            this.postTitle = '';
            this.init();
        }
        
        init() {
            // Button click handler
            $(document).on('click', '.aebg-regenerate-featured-image-btn', (e) => {
                const postId = $(e.target).data('post-id');
                this.openModal(postId);
            });
            
            // Custom prompt toggle
            $('#aebg-use-custom-prompt').on('change', () => {
                this.toggleCustomPrompt();
            });
            
            // Character counter
            $('#aebg-custom-prompt').on('input', () => {
                this.updateCharCounter();
            });
            
            // Generate button
            $('#aebg-generate-featured-image').on('click', () => {
                this.regenerate();
            });
            
            // Modal close handlers
            $('.aebg-modal-close').on('click', () => {
                this.closeModal();
            });
        }
        
        openModal(postId) {
            this.postId = postId;
            this.postTitle = $('#title').val() || '';
            
            // Show modal
            $('#aebg-featured-image-modal').addClass('show').show();
            
            // Reset form
            $('#aebg-use-custom-prompt').prop('checked', false);
            $('#aebg-custom-prompt').val('');
            this.toggleCustomPrompt();
            
            // Pre-fill with auto-generated prompt preview (optional)
            this.updatePromptPreview();
        }
        
        toggleCustomPrompt() {
            const useCustom = $('#aebg-use-custom-prompt').is(':checked');
            if (useCustom) {
                $('#aebg-custom-prompt-group').slideDown();
                this.updateCharCounter();
            } else {
                $('#aebg-custom-prompt-group').slideUp();
            }
        }
        
        updateCharCounter() {
            const length = $('#aebg-custom-prompt').val().length;
            $('#aebg-prompt-char-count').text(length);
            
            // Visual feedback for character limit
            const maxLength = 1000;
            if (length > maxLength * 0.9) {
                $('#aebg-prompt-char-count').addClass('warning');
            } else {
                $('#aebg-prompt-char-count').removeClass('warning');
            }
        }
        
        updatePromptPreview() {
            // Optional: Show what auto-generated prompt would be
            // This helps users understand what they're overriding
        }
        
        async regenerate() {
            const style = $('#aebg-featured-image-style').val();
            const useCustom = $('#aebg-use-custom-prompt').is(':checked');
            const customPrompt = $('#aebg-custom-prompt').val().trim();
            
            // Validation
            if (useCustom && !customPrompt) {
                alert('Please enter a custom prompt or disable custom prompt mode.');
                return;
            }
            
            if (useCustom && customPrompt.length > 1000) {
                alert('Custom prompt must be 1000 characters or less.');
                return;
            }
            
            // Disable button and show loading
            const $btn = $('#aebg-generate-featured-image');
            $btn.prop('disabled', true);
            $btn.find('.aebg-btn-text').text('Generating...');
            $btn.find('.aebg-btn-spinner').show();
            
            // Show progress indicator
            $('.aebg-featured-image-progress').show();
            $('.aebg-featured-image-preview').hide();
            
            try {
                const response = await $.ajax({
                    url: aebgFeaturedImage.ajaxUrl.replace('admin-ajax.php', 'wp-json/aebg/v1/featured-image/regenerate'),
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        post_id: this.postId,
                        style: style,
                        custom_prompt: useCustom ? customPrompt : null,
                        use_custom_prompt: useCustom
                    }),
                    headers: {
                        'X-WP-Nonce': aebgFeaturedImage.nonce
                    }
                });
                
                if (response.success) {
                    // Update featured image in WordPress UI
                    this.updateFeaturedImage(response.data.attachment_id, response.data.attachment_url);
                    
                    // Show preview
                    this.showPreview(response.data.thumbnail_url, response.data.prompt_used);
                    
                    // Success message
                    this.showMessage('Image generated successfully!', 'success');
                } else {
                    throw new Error(response.message || 'Generation failed');
                }
            } catch (error) {
                console.error('Regeneration error:', error);
                this.showMessage(
                    error.responseJSON?.message || error.message || 'Failed to generate image. Please try again.',
                    'error'
                );
            } finally {
                // Re-enable button
                $btn.prop('disabled', false);
                $btn.find('.aebg-btn-text').text('Generate Image');
                $btn.find('.aebg-btn-spinner').hide();
            }
        }
        
        updateFeaturedImage(attachmentId, url) {
            // Update WordPress featured image metabox
            // This requires WordPress's built-in featured image functionality
            $('#_thumbnail_id').val(attachmentId);
            
            // Update preview
            const $preview = $('.inside .postimagediv');
            if ($preview.length) {
                $preview.html(`
                    <p class="hide-if-no-js">
                        <a href="#" class="thickbox">
                            <img src="${url}" alt="" style="max-width: 100%; height: auto;" />
                        </a>
                    </p>
                    <p class="hide-if-no-js">
                        <a href="#" class="remove-post-thumbnail">Remove featured image</a>
                    </p>
                `);
            }
        }
        
        showPreview(imageUrl, promptUsed) {
            $('.aebg-featured-image-preview').html(`
                <div class="aebg-preview-container">
                    <h4>Generated Image</h4>
                    <img src="${imageUrl}" alt="Generated featured image" style="max-width: 100%; border-radius: 8px;" />
                    ${promptUsed ? `<p class="aebg-prompt-used"><strong>Prompt used:</strong> ${promptUsed}</p>` : ''}
                </div>
            `).show();
        }
        
        showMessage(message, type) {
            // Show success/error message
            const $msg = $(`<div class="aebg-message aebg-message-${type}">${message}</div>`);
            $('.aebg-modal-body').prepend($msg);
            setTimeout(() => $msg.fadeOut(), 5000);
        }
        
        closeModal() {
            $('#aebg-featured-image-modal').removeClass('show').hide();
        }
    }
    
    $(document).ready(() => {
        new FeaturedImageRegenerator();
    });
})(jQuery);
```

---

## ✅ Success Criteria

### Functional Requirements
- [ ] Button appears in featured image metabox
- [ ] Modal opens with style selector
- [ ] Image generation works via API
- [ ] Featured image updates in WordPress
- [ ] Error handling works correctly

### Non-Functional Requirements
- [ ] No conflicts with existing features
- [ ] Performance: < 100ms for UI interactions
- [ ] Accessibility: Keyboard navigable
- [ ] Browser compatibility: Modern browsers
- [ ] Mobile responsive (admin screen)

### Code Quality
- [ ] Follows existing code patterns
- [ ] Reuses existing components
- [ ] Modular architecture
- [ ] Well-documented
- [ ] No code duplication

---

## 📚 References

### Existing Components to Reuse
- Modal system: `generator.css` (lines 910-1000)
- Button styles: `generator.css` (lines 872-882)
- Form components: `generator.css` (form styles)
- API patterns: `GeneratorV2Controller.php`
- Settings retrieval: `Settings.php`

### WordPress Hooks
- `admin_post_thumbnail_html` - Modify featured image metabox
- `admin_enqueue_scripts` - Enqueue assets
- `rest_api_init` - Register REST API routes

### Existing Classes
- `FeaturedImageGenerator` - Image generation
- `ImageProcessor` - Image processing
- `Settings` - Settings retrieval

---

## 🎉 Conclusion

This modular architecture provides:
- ✅ **Clean separation** of concerns
- ✅ **Maximum reuse** of existing components
- ✅ **Easy extensibility** for future features
- ✅ **Modern UX** with familiar patterns
- ✅ **Maintainable code** with clear boundaries

The implementation can be done incrementally, tested at each phase, and extended without breaking existing functionality.

---

## 🔄 Component Reuse Summary

### Reused from Generator V2

1. **Model Selection UI**
   - Source: `generator-v2-page.php` (lines 256-261)
   - Reuse: Dropdown with DALL-E 2/3 options
   - CSS: Reuse existing select styles

2. **Quality Selection UI**
   - Source: `generator-v2-page.php` (lines 273-291)
   - Reuse: Radio buttons for Standard/HD
   - CSS: Reuse `.aebg-v2-radio-group` styles

3. **Size Selection UI**
   - Source: `generator-v2-page.php` (lines 264-271)
   - Reuse: Dropdown with size options
   - CSS: Reuse existing select styles

4. **Cost Calculation Logic**
   - Source: `GeneratorV2Controller.php` (lines 329-346)
   - Method: `calculate_image_cost()`
   - Pricing: DALL-E 3 ($0.04/$0.08), DALL-E 2 ($0.02)

5. **Time Estimation Logic**
   - Source: `GeneratorV2Controller.php` (lines 386-401)
   - Method: `estimate_image_time()`
   - Base times: DALL-E 3 (15s), DALL-E 2 (10s), HD (+50%)

6. **Cost Display UI**
   - Source: `generator-v2-page.php` (lines 402-420)
   - CSS: Reuse `.aebg-v2-cost-breakdown` styles
   - Format: Same layout and styling

7. **Estimate Update Logic**
   - Source: `generator-v2.js` (lines 491-528)
   - Pattern: AJAX call to calculate endpoint
   - Fallback: Client-side calculation from `generator.js` (lines 2049-2111)

### Benefits of Reuse

- ✅ **Consistency**: Same UI/UX patterns users already know
- ✅ **Reliability**: Battle-tested calculation logic
- ✅ **Maintainability**: Single source of truth for pricing
- ✅ **Speed**: Faster development, less code to write
- ✅ **Quality**: Proven components with existing bug fixes

---

*Last Updated: [Current Date]*
*Version: 1.1 - Added Model Selection, Cost & Time Estimation*

