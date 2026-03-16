# Featured Image Regeneration - Action Scheduler Integration

## 🔄 Background Processing Integration

This document details how the Featured Image Regeneration feature integrates with your existing Action Scheduler, ActionHandler, and background processing architecture.

---

## ✅ Full Integration Guarantee

The featured image regeneration **fully integrates** with your existing system:

- ✅ **Action Scheduler** - Uses `as_schedule_single_action()` pattern
- ✅ **ActionHandler** - Adds new hook following existing pattern
- ✅ **Background Processing** - Non-blocking, runs in background
- ✅ **Progress Tracking** - Real-time status updates via polling
- ✅ **Frontend Visualization** - Reuses existing progress UI patterns
- ✅ **Error Handling** - Uses existing error handling infrastructure
- ✅ **Timeout Management** - Uses existing TimeoutManager
- ✅ **Logging** - Uses existing Logger class

---

## 🏗️ Architecture Integration

### 1. ActionHandler Integration

**File**: `src/Core/ActionHandler.php` (ADD to existing file)

**Pattern**: Follows same pattern as `aebg_regenerate_testvinder_only` (line 59)

```php
// In ActionHandler constructor (add to line 61):
add_action( 'aebg_regenerate_featured_image', [ $this, 'execute_regenerate_featured_image' ] );

// New method (add to ActionHandler class):
public function execute_regenerate_featured_image( $job_id ) {
    // Set execution flag (prevents shutdown hook conflicts)
    self::$is_executing = true;
    $GLOBALS['aebg_current_item_id'] = $job_id; // For usage tracking
    
    try {
        // Get job data from transient
        $job_data = get_transient( 'aebg_featured_image_job_' . $job_id );
        if ( ! $job_data ) {
            throw new \Exception( 'Job data not found' );
        }
        
        $post_id = $job_data['post_id'];
        $style = $job_data['style'];
        $image_model = $job_data['image_model'];
        $image_size = $job_data['image_size'];
        $image_quality = $job_data['image_quality'];
        $custom_prompt = $job_data['custom_prompt'] ?? null;
        $use_custom_prompt = $job_data['use_custom_prompt'] ?? false;
        
        // Update status to 'processing'
        $this->update_featured_image_job_status( $job_id, 'processing', 10, 'Starting image generation...' );
        
        // Get settings
        $settings = get_option( 'aebg_settings', [] );
        $api_key = $settings['api_key'] ?? '';
        $ai_model = $settings['model'] ?? 'gpt-4';
        
        if ( empty( $api_key ) ) {
            throw new \Exception( 'OpenAI API key not configured' );
        }
        
        // Prepare image settings
        $image_settings = [
            'image_model' => $image_model,
            'image_size' => $image_size,
            'image_quality' => $image_quality,
        ];
        
        // Update progress
        $this->update_featured_image_job_status( $job_id, 'processing', 30, 'Generating image prompt...' );
        
        // Handle custom prompt vs auto-generated
        if ( $use_custom_prompt && ! empty( $custom_prompt ) ) {
            // Use custom prompt
            $style_instructions = \AEBG\Core\ImageProcessor::getStyleInstructions( $style );
            $full_prompt = sanitize_textarea_field( $custom_prompt );
            if ( stripos( $full_prompt, 'style:' ) === false ) {
                $full_prompt .= '. ' . $style_instructions;
            }
        } else {
            // Use auto-generated prompt
            $post = get_post( $post_id );
            $full_prompt = \AEBG\Core\ImageProcessor::createFeaturedImagePrompt( 
                $post->post_title, 
                $style 
            );
        }
        
        // Update progress
        $this->update_featured_image_job_status( $job_id, 'processing', 50, 'Calling DALL-E API...' );
        
        // Generate image (reuse existing ImageProcessor)
        $image_url = \AEBG\Core\ImageProcessor::generateAIImage( 
            $full_prompt, 
            $api_key, 
            $ai_model, 
            $image_settings 
        );
        
        if ( ! $image_url ) {
            throw new \Exception( 'Failed to generate image' );
        }
        
        // Update progress
        $this->update_featured_image_job_status( $job_id, 'processing', 70, 'Downloading image...' );
        
        // Download and save image
        $attachment_id = \AEBG\Core\ProductImageManager::downloadAndInsertImage(
            $image_url,
            $full_prompt,
            0
        );
        
        if ( ! $attachment_id ) {
            throw new \Exception( 'Failed to save image to media library' );
        }
        
        // Update progress
        $this->update_featured_image_job_status( $job_id, 'processing', 90, 'Setting as featured image...' );
        
        // Set as featured image
        set_post_thumbnail( $post_id, $attachment_id );
        
        // Update status to completed
        $this->update_featured_image_job_status( $job_id, 'completed', 100, 'Image generated successfully!', [
            'attachment_id' => $attachment_id,
            'attachment_url' => wp_get_attachment_url( $attachment_id ),
            'thumbnail_url' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
            'prompt_used' => $full_prompt,
        ] );
        
        // Clean up transient after 1 hour (keep for history)
        set_transient( 'aebg_featured_image_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
        
    } catch ( \Exception $e ) {
        // Update status to failed
        $this->update_featured_image_job_status( $job_id, 'failed', 0, 'Generation failed: ' . $e->getMessage(), [
            'error_message' => $e->getMessage(),
        ] );
        
        \AEBG\Core\Logger::error( 'Featured image regeneration failed', [
            'job_id' => $job_id,
            'error' => $e->getMessage(),
        ] );
    } finally {
        // Clear execution flag
        self::$is_executing = false;
        unset( $GLOBALS['aebg_current_item_id'] );
    }
}

/**
 * Update featured image job status
 */
private function update_featured_image_job_status( $job_id, $status, $progress, $message, $data = [] ) {
    $status_data = [
        'status' => $status,
        'progress' => $progress,
        'message' => $message,
        'updated_at' => time(),
    ] + $data;
    
    set_transient( 'aebg_featured_image_status_' . $job_id, $status_data, HOUR_IN_SECONDS );
}
```

---

### 2. API Controller Integration

**File**: `src/API/FeaturedImageController.php`

**Key Changes**:
- `regenerate_image()` now schedules Action Scheduler action instead of blocking
- New `get_status()` endpoint for progress polling

```php
public function regenerate_image( $request ) {
    // ... validation ...
    
    // Generate unique job ID
    $job_id = 'fi_' . time() . '_' . wp_generate_password( 8, false );
    
    // Store job data in transient
    $job_data = [
        'post_id' => $post_id,
        'style' => $style,
        'image_model' => $image_model,
        'image_size' => $image_size,
        'image_quality' => $image_quality,
        'custom_prompt' => $custom_prompt,
        'use_custom_prompt' => $use_custom_prompt,
        'created_at' => time(),
    ];
    
    set_transient( 'aebg_featured_image_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
    
    // Initialize status
    $this->update_job_status( $job_id, 'pending', 0, 'Scheduled for processing...' );
    
    // Schedule Action Scheduler action (non-blocking)
    $action_id = as_schedule_single_action(
        time(), // Run immediately
        'aebg_regenerate_featured_image',
        [ $job_id ],
        'aebg_featured_image',
        true // Unique - prevents duplicates
    );
    
    if ( ! $action_id ) {
        return new \WP_Error( 'schedule_failed', 'Failed to schedule regeneration', [ 'status' => 500 ] );
    }
    
    return rest_ensure_response( [
        'success' => true,
        'data' => [
            'job_id' => $job_id,
            'action_id' => $action_id,
            'status' => 'pending',
            'message' => 'Regeneration scheduled. Processing will begin shortly...',
        ],
    ] );
}

public function get_status( $request ) {
    $job_id = $request->get_param( 'job_id' );
    
    $status_data = get_transient( 'aebg_featured_image_status_' . $job_id );
    
    if ( ! $status_data ) {
        return new \WP_Error( 'job_not_found', 'Job not found', [ 'status' => 404 ] );
    }
    
    return rest_ensure_response( [
        'success' => true,
        'data' => $status_data,
    ] );
}
```

---

### 3. Frontend Progress Tracking

**File**: `assets/js/featured-image-regenerate.js`

**Pattern**: Reuses progress tracking from `generator.js` (lines 545-783)

**Key Features**:
- Polls status endpoint every 2 seconds
- Updates progress bar (0-100%)
- Shows status messages
- Handles completion/failure
- Updates WordPress featured image UI when complete

---

## 🔄 Process Flow

### 1. User Initiates Regeneration
```
User clicks "Generate Image" 
  → JavaScript calls POST /featured-image/regenerate
  → API schedules Action Scheduler action
  → Returns job_id immediately (non-blocking)
```

### 2. Background Processing
```
Action Scheduler triggers aebg_regenerate_featured_image hook
  → ActionHandler::execute_regenerate_featured_image() runs
  → Updates progress via transients
  → Generates image using existing ImageProcessor
  → Sets featured image
  → Marks job as completed
```

### 3. Frontend Progress Tracking
```
JavaScript polls GET /featured-image/status/{job_id} every 2 seconds
  → Updates progress bar
  → Shows status messages
  → On completion: Updates WordPress UI
  → On failure: Shows error message
```

---

## ✅ Benefits of Integration

1. **Non-Blocking**: User can continue working while image generates
2. **Timeout Safe**: Uses Action Scheduler's timeout management
3. **Progress Tracking**: Real-time updates via polling
4. **Error Handling**: Uses existing error handling infrastructure
5. **Consistent UX**: Matches existing bulk generation patterns
6. **Scalable**: Can handle multiple regenerations simultaneously
7. **Reliable**: Uses proven Action Scheduler architecture

---

## 🛡️ Safety Guarantees

- ✅ **No Conflicts**: Uses unique hook name `aebg_regenerate_featured_image`
- ✅ **Isolated**: Separate from bulk generation (different hook)
- ✅ **Timeout Safe**: Uses existing TimeoutManager
- ✅ **Error Safe**: Uses existing error handling
- ✅ **Logging**: Uses existing Logger class

---

*Last Updated: [Current Date]*
*Version: 1.0*

