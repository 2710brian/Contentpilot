# Reorder Conflict Resolution - Technical Specification

## Executive Summary

This document outlines a bulletproof, state-of-the-art, modular, secure, and performant implementation for handling testvinder container regeneration conflicts during product reordering.

---

## 1. Architecture Overview

### 1.1 Design Principles

- **Separation of Concerns**: Each class has a single, well-defined responsibility
- **Dependency Injection**: Classes receive dependencies rather than creating them
- **Fail-Safe Defaults**: Operations fail gracefully with rollback capabilities
- **Security First**: All inputs validated, sanitized, and authorized
- **Performance Optimized**: Minimal database queries, efficient caching, async operations
- **Observable**: Comprehensive logging and error tracking

### 1.2 Module Structure

```
src/Core/
├── TestvinderConflictDetector.php      (Detection Layer)
├── TestvinderRegenerationManager.php   (Regeneration Orchestration)
└── ReorderConflictResolver.php         (Resolution Orchestrator)

src/Admin/views/
└── reorder-conflict-modal.php          (UI Template)

assets/js/
└── reorder-conflict-handler.js         (Frontend Logic)

assets/css/
└── edit-posts.css                      (Minimal additions)
```

---

## 2. Security Architecture

### 2.1 AJAX Security Pattern

**Following existing patterns from `TemplateManager::ajax_update_post_products()`:**

```php
// 1. Capability Check (Authorization)
if (!current_user_can('edit_posts')) {
    wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
}

// 2. Nonce Verification (CSRF Protection)
if (!check_ajax_referer('aebg_reorder_conflict', '_ajax_nonce', false)) {
    wp_send_json_error(['message' => __('Security check failed.', 'aebg')], 403);
}

// 3. Input Validation & Sanitization
$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
$new_order = isset($_POST['new_product_order']) ? json_decode(stripslashes($_POST['new_product_order']), true) : [];
$regeneration_choices = isset($_POST['regeneration_choices']) ? json_decode(stripslashes($_POST['regeneration_choices']), true) : [];

// 4. Type Validation
if (empty($post_id) || !is_numeric($post_id) || $post_id <= 0) {
    wp_send_json_error(['message' => __('Invalid post ID.', 'aebg')]);
}

// 5. Array Structure Validation
if (!is_array($new_order) || !is_array($regeneration_choices)) {
    wp_send_json_error(['message' => __('Invalid data format.', 'aebg')]);
}

// 6. Size Limits (DoS Prevention)
if (count($new_order) > 100 || count($regeneration_choices) > 100) {
    wp_send_json_error(['message' => __('Too many items provided.', 'aebg')]);
}

// 7. Post Ownership Validation (if needed)
if (!current_user_can('edit_post', $post_id)) {
    wp_send_json_error(['message' => __('You cannot edit this post.', 'aebg')], 403);
}
```

### 2.2 Data Sanitization Strategy

**For Elementor Data:**
- Use existing `sanitizeElementorDataStructure()` from `Generator.php`
- Validate JSON structure before processing
- Sanitize all user inputs before database operations

**For Regeneration Choices:**
```php
private function sanitizeRegenerationChoices(array $choices): array {
    $sanitized = [];
    $allowed_actions = ['regenerate_both', 'regenerate_testvinder_only', 'skip'];
    
    foreach ($choices as $product_id => $choice) {
        $product_id = absint($product_id);
        $action = sanitize_text_field($choice['action'] ?? 'skip');
        
        if (!in_array($action, $allowed_actions, true)) {
            $action = 'skip'; // Fail-safe default
        }
        
        $sanitized[$product_id] = [
            'action' => $action,
            'product_number' => absint($choice['product_number'] ?? 0),
        ];
    }
    
    return $sanitized;
}
```

### 2.3 Nonce Management

**Backend:**
```php
// In Plugin.php or Meta_Box.php
wp_localize_script('aebg-edit-posts', 'aebg_reorder', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('aebg_reorder_conflict'),
    'nonce_detect' => wp_create_nonce('aebg_detect_reorder_conflicts'),
]);
```

**Frontend:**
```javascript
// Always include nonce in AJAX requests
$.ajax({
    url: aebg_reorder.ajaxurl,
    type: 'POST',
    data: {
        action: 'aebg_detect_reorder_conflicts',
        _ajax_nonce: aebg_reorder.nonce_detect,
        post_id: postId,
        new_product_order: JSON.stringify(newOrder)
    }
});
```

---

## 3. Performance Architecture

### 3.1 Caching Strategy

**Following existing patterns from `TemplateManager.php`:**

```php
// 1. Cache Elementor Data Reads
private function getElementorDataCached($post_id) {
    $cache_key = 'aebg_elementor_data_' . $post_id;
    $cached = wp_cache_get($cache_key, 'aebg_elementor');
    
    if ($cached !== false) {
        return $cached;
    }
    
    $data = $this->getElementorData($post_id);
    wp_cache_set($cache_key, $data, 'aebg_elementor', 300); // 5 min TTL
    
    return $data;
}

// 2. Cache Conflict Detection Results
private function getCachedConflicts($post_id, $new_order) {
    $cache_key = 'aebg_conflicts_' . $post_id . '_' . md5(json_encode($new_order));
    return wp_cache_get($cache_key, 'aebg_reorder');
}

// 3. Invalidate Cache on Updates
private function invalidateElementorCache($post_id) {
    wp_cache_delete('aebg_elementor_data_' . $post_id, 'aebg_elementor');
    wp_cache_delete($post_id, 'post_meta');
    clean_post_cache($post_id);
}
```

### 3.2 Database Query Optimization

**Minimize Queries:**
```php
// BAD: Multiple queries
foreach ($product_ids as $id) {
    $product = get_post_meta($post_id, '_aebg_product_' . $id, true);
}

// GOOD: Single query with meta_query
$products = get_post_meta($post_id, '_aebg_products', true);
// Process in memory
```

**Use Prepared Statements:**
```php
global $wpdb;
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT post_id, meta_value 
     FROM {$wpdb->postmeta} 
     WHERE meta_key = %s 
     AND post_id = %d",
    '_aebg_products',
    $post_id
));
```

### 3.3 Async Processing

**Use Action Scheduler for Regeneration:**
```php
// Don't block AJAX response - schedule async regeneration
TestvinderRegenerationManager::scheduleTestvinderRegeneration(
    $post_id,
    $product_number,
    $regeneration_type
);

// Return immediately
wp_send_json_success([
    'message' => 'Regeneration scheduled',
    'status' => 'queued'
]);
```

---

## 4. Error Handling & Rollback

### 4.1 Transaction-Like Behavior

**Following `ProductTransactionManager` pattern:**

```php
class ReorderConflictResolver {
    
    public function resolveConflictsAndReorder($post_id, $new_order, $regeneration_choices) {
        // 1. Create comprehensive backup
        $backup = $this->createBackup($post_id);
        $backup_key = 'aebg_reorder_backup_' . $post_id . '_' . time();
        update_option($backup_key, $backup);
        
        try {
            // 2. Validate inputs
            $this->validateInputs($post_id, $new_order, $regeneration_choices);
            
            // 3. Execute reordering
            $reorder_result = $this->executeReordering($post_id, $new_order);
            if (is_wp_error($reorder_result)) {
                throw new \Exception($reorder_result->get_error_message());
            }
            
            // 4. Execute regenerations based on choices
            $regeneration_results = $this->executeRegenerations(
                $post_id,
                $regeneration_choices
            );
            
            // 5. Validate final state
            $validation = $this->validateFinalState($post_id, $new_order);
            if (!$validation['valid']) {
                throw new \Exception('Validation failed: ' . $validation['error']);
            }
            
            // 6. Success - clean up backup after delay (safety net)
            $this->scheduleBackupCleanup($backup_key, 3600); // 1 hour
            
            return [
                'success' => true,
                'reorder_result' => $reorder_result,
                'regeneration_results' => $regeneration_results,
            ];
            
        } catch (\Exception $e) {
            // Rollback on any error
            Logger::error('Reorder conflict resolution failed', [
                'post_id' => $post_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->rollback($post_id, $backup);
            
            return new \WP_Error(
                'reorder_failed',
                'Reorder failed: ' . $e->getMessage(),
                ['backup_key' => $backup_key]
            );
        }
    }
    
    private function createBackup($post_id): array {
        return [
            'elementor_data' => get_post_meta($post_id, '_elementor_data', true),
            'products' => get_post_meta($post_id, '_aebg_products', true),
            'product_ids' => get_post_meta($post_id, '_aebg_product_ids', true),
            'timestamp' => time(),
            'user_id' => get_current_user_id(),
        ];
    }
    
    private function rollback($post_id, array $backup): bool {
        if (isset($backup['elementor_data'])) {
            update_post_meta($post_id, '_elementor_data', $backup['elementor_data']);
        }
        if (isset($backup['products'])) {
            update_post_meta($post_id, '_aebg_products', $backup['products']);
        }
        if (isset($backup['product_ids'])) {
            update_post_meta($post_id, '_aebg_product_ids', $backup['product_ids']);
        }
        
        // Clear caches
        $this->invalidateElementorCache($post_id);
        
        Logger::warning('Rolled back reorder operation', [
            'post_id' => $post_id,
            'backup_timestamp' => $backup['timestamp'] ?? 'unknown',
        ]);
        
        return true;
    }
}
```

### 4.2 Error Logging

**Following `Logger` pattern:**

```php
use AEBG\Core\Logger;

// Structured logging
Logger::info('Reorder conflict detected', [
    'post_id' => $post_id,
    'conflicts' => $conflicts,
    'user_id' => get_current_user_id(),
]);

Logger::error('Regeneration failed', [
    'post_id' => $post_id,
    'product_number' => $product_number,
    'error' => $error->getMessage(),
    'trace' => $error->getTraceAsString(),
]);
```

---

## 5. Modular Class Specifications

### 5.1 TestvinderConflictDetector

**Purpose:** Pure detection logic, no side effects

```php
<?php
namespace AEBG\Core;

/**
 * Testvinder Conflict Detector
 * 
 * Detects conflicts when products are reordered to positions
 * that already have testvinder containers.
 * 
 * Pure function - no side effects, no database writes.
 * 
 * @package AEBG\Core
 */
class TestvinderConflictDetector {
    
    /**
     * Detect conflicts for product reordering
     * 
     * @param array $elementor_data Current Elementor data
     * @param array $position_mapping Position mapping [old_position => new_position]
     * @param array $current_products Current products array
     * @return array Array of conflicts
     */
    public static function detectConflicts(
        array $elementor_data,
        array $position_mapping,
        array $current_products
    ): array {
        $conflicts = [];
        $testvinder_containers = self::findTestvinderContainers($elementor_data);
        
        foreach ($position_mapping as $old_position => $new_position) {
            // Check if target position has testvinder container
            $testvinder_key = 'testvinder-' . $new_position;
            
            if (isset($testvinder_containers[$testvinder_key])) {
                // Get product info
                $product_index = $old_position - 1;
                $product = $current_products[$product_index] ?? null;
                $product_id = is_array($product) ? ($product['id'] ?? '') : $product;
                $product_name = is_array($product) ? ($product['name'] ?? 'Unknown') : 'Unknown';
                
                $conflicts[] = [
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'old_position' => $old_position,
                    'new_position' => $new_position,
                    'testvinder_exists' => true,
                    'testvinder_css_id' => $testvinder_key,
                ];
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Find all testvinder containers in Elementor data
     * 
     * @param array $elementor_data Elementor data
     * @return array Map of CSS ID => container data
     */
    private static function findTestvinderContainers(array $elementor_data): array {
        $containers = [];
        self::recursiveFindTestvinder($elementor_data, $containers);
        return $containers;
    }
    
    /**
     * Recursively find testvinder containers
     */
    private static function recursiveFindTestvinder(
        array $data,
        array &$containers,
        string $path = ''
    ): void {
        if (!is_array($data)) {
            return;
        }
        
        // Check for testvinder CSS ID
        if (isset($data['settings']['_element_id'])) {
            $css_id = $data['settings']['_element_id'];
            if (preg_match('/^testvinder-(\d+)$/', $css_id, $matches)) {
                $containers[$css_id] = [
                    'css_id' => $css_id,
                    'product_number' => (int)$matches[1],
                    'path' => $path,
                    'container' => $data,
                ];
            }
        }
        
        // Recursively check children
        if (isset($data['elements']) && is_array($data['elements'])) {
            foreach ($data['elements'] as $index => $element) {
                $new_path = $path ? $path . '.elements.' . $index : 'elements.' . $index;
                self::recursiveFindTestvinder($element, $containers, $new_path);
            }
        }
        
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $index => $content_item) {
                $new_path = $path ? $path . '.content.' . $index : 'content.' . $index;
                self::recursiveFindTestvinder($content_item, $containers, $new_path);
            }
        }
    }
}
```

### 5.2 TestvinderRegenerationManager

**Purpose:** Orchestrate regeneration scheduling

```php
<?php
namespace AEBG\Core;

use AEBG\Core\ProductReplacementScheduler;
use AEBG\Core\Logger;

/**
 * Testvinder Regeneration Manager
 * 
 * Manages scheduling of testvinder container regenerations.
 * Extends ProductReplacementScheduler pattern.
 * 
 * @package AEBG\Core
 */
class TestvinderRegenerationManager {
    
    /**
     * Schedule testvinder-only regeneration
     * 
     * @param int $post_id Post ID
     * @param int $product_number Product number (position)
     * @return int|false Action ID on success, false on failure
     */
    public static function scheduleTestvinderOnlyRegeneration(
        int $post_id,
        int $product_number
    ) {
        Logger::info('Scheduling testvinder-only regeneration', [
            'post_id' => $post_id,
            'product_number' => $product_number,
        ]);
        
        // Validate
        if (empty($post_id) || empty($product_number) || $product_number < 1) {
            Logger::error('Invalid parameters for testvinder regeneration', [
                'post_id' => $post_id,
                'product_number' => $product_number,
            ]);
            return false;
        }
        
        // Use existing ProductReplacementScheduler with testvinder flag
        // This requires extending ProductReplacementScheduler to support testvinder-only mode
        // For now, we'll use a new action hook
        
        $action_id = ActionSchedulerHelper::schedule_action(
            'aebg_regenerate_testvinder_only',
            [$post_id, $product_number],
            "aebg_testvinder_{$post_id}_{$product_number}",
            0, // Immediate
            true // Unique
        );
        
        if ($action_id > 0) {
            Logger::info('Testvinder regeneration scheduled', [
                'action_id' => $action_id,
                'post_id' => $post_id,
                'product_number' => $product_number,
            ]);
        }
        
        return $action_id;
    }
    
    /**
     * Schedule full regeneration (product + testvinder)
     * 
     * @param int $post_id Post ID
     * @param int $product_number Product number
     * @return int|false Action ID on success, false on failure
     */
    public static function scheduleProductAndTestvinderRegeneration(
        int $post_id,
        int $product_number
    ) {
        // Use existing ProductReplacementScheduler
        return ProductReplacementScheduler::scheduleReplacement(
            $post_id,
            $product_number
        );
    }
}
```

### 5.3 ReorderConflictResolver

**Purpose:** Orchestrate entire conflict resolution flow

```php
<?php
namespace AEBG\Core;

use AEBG\Core\TestvinderConflictDetector;
use AEBG\Core\TestvinderRegenerationManager;
use AEBG\Core\TemplateManager;
use AEBG\Core\Logger;

/**
 * Reorder Conflict Resolver
 * 
 * Orchestrates product reordering with conflict detection and resolution.
 * 
 * @package AEBG\Core
 */
class ReorderConflictResolver {
    
    private $template_manager;
    
    public function __construct(TemplateManager $template_manager = null) {
        $this->template_manager = $template_manager ?: new TemplateManager();
    }
    
    /**
     * Prepare reordering - detect conflicts for frontend
     * 
     * @param int $post_id Post ID
     * @param array $new_order New product order
     * @return array|\WP_Error Conflicts array or error
     */
    public function prepareReorderingWithChoices(int $post_id, array $new_order) {
        Logger::info('Preparing reordering with conflict detection', [
            'post_id' => $post_id,
            'new_order' => $new_order,
        ]);
        
        // 1. Get Elementor data
        $elementor_data = $this->template_manager->getElementorData($post_id);
        if (is_wp_error($elementor_data)) {
            return $elementor_data;
        }
        
        // 2. Get current products
        $current_products = get_post_meta($post_id, '_aebg_products', true);
        if (!is_array($current_products)) {
            $current_products = [];
        }
        
        // 3. Create position mapping
        $position_mapping = $this->createPositionMapping(
            $elementor_data,
            $current_products,
            $new_order,
            $post_id
        );
        
        // 4. Detect conflicts
        $conflicts = TestvinderConflictDetector::detectConflicts(
            $elementor_data,
            $position_mapping,
            $current_products
        );
        
        return [
            'conflicts' => $conflicts,
            'position_mapping' => $position_mapping,
            'has_conflicts' => !empty($conflicts),
        ];
    }
    
    /**
     * Resolve conflicts and execute reordering
     * 
     * @param int $post_id Post ID
     * @param array $new_order New product order
     * @param array $regeneration_choices User's regeneration choices
     * @return array|\WP_Error Success result or error
     */
    public function resolveConflictsAndReorder(
        int $post_id,
        array $new_order,
        array $regeneration_choices
    ) {
        // Implementation with backup/rollback (see section 4.1)
        // ...
    }
    
    private function createPositionMapping(
        array $elementor_data,
        array $current_products,
        array $new_order,
        int $post_id
    ): array {
        // Reuse TemplateManager logic
        return $this->template_manager->createPositionMappingFromElementorData(
            $elementor_data,
            $current_products,
            $new_order,
            $post_id
        );
    }
}
```

---

## 6. Frontend Architecture

### 6.1 Modal Component (Reusing Existing)

**HTML Structure (reorder-conflict-modal.php):**
```php
<div id="aebg-reorder-conflict-modal" class="aebg-modal">
    <div class="aebg-modal-overlay"></div>
    <div class="aebg-modal-container">
        <div class="aebg-modal-header">
            <h3><?php esc_html_e('Product Reordering Conflicts', 'aebg'); ?></h3>
            <button class="aebg-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="aebg-modal-body">
            <p class="aebg-conflict-intro">
                <?php esc_html_e('The following products are moving to positions that have testvinder containers. Choose how to handle each:', 'aebg'); ?>
            </p>
            <div class="aebg-conflicts-list" id="aebg-conflicts-list">
                <!-- Populated by JavaScript -->
            </div>
        </div>
        <div class="aebg-modal-footer">
            <button type="button" class="button aebg-btn-cancel">
                <?php esc_html_e('Cancel', 'aebg'); ?>
            </button>
            <button type="button" class="button button-primary aebg-btn-proceed">
                <?php esc_html_e('Proceed with Reordering', 'aebg'); ?>
            </button>
        </div>
    </div>
</div>
```

### 6.2 JavaScript Handler

**Following existing patterns from `edit-posts.js`:**

```javascript
(function($) {
    'use strict';
    
    /**
     * Reorder Conflict Handler
     * Handles conflict detection and user choice collection
     */
    const ReorderConflictHandler = {
        
        /**
         * Initialize conflict handler
         */
        init: function() {
            // Bind events
            $(document).on('click', '.aebg-btn-proceed', this.handleProceed.bind(this));
            $(document).on('click', '.aebg-btn-cancel', this.handleCancel.bind(this));
            $(document).on('change', '.aebg-regeneration-choice', this.updateChoice.bind(this));
        },
        
        /**
         * Detect conflicts before reordering
         */
        detectConflicts: function(postId, newOrder) {
            return $.ajax({
                url: aebg_reorder.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aebg_detect_reorder_conflicts',
                    _ajax_nonce: aebg_reorder.nonce_detect,
                    post_id: postId,
                    new_product_order: JSON.stringify(newOrder)
                },
                dataType: 'json'
            });
        },
        
        /**
         * Show conflict modal
         */
        showConflictModal: function(conflicts) {
            const $modal = $('#aebg-reorder-conflict-modal');
            const $list = $('#aebg-conflicts-list');
            
            $list.empty();
            
            conflicts.forEach((conflict, index) => {
                const $item = this.createConflictItem(conflict, index);
                $list.append($item);
            });
            
            $modal.addClass('show');
        },
        
        /**
         * Create conflict item HTML
         */
        createConflictItem: function(conflict, index) {
            const $item = $('<div>').addClass('aebg-conflict-item');
            
            $item.html(`
                <div class="aebg-conflict-info">
                    <h4>${this.escapeHtml(conflict.product_name)}</h4>
                    <p>Moving from position #${conflict.old_position} to position #${conflict.new_position}</p>
                    <p class="aebg-conflict-warning">
                        Position #${conflict.new_position} has a testvinder container (${conflict.testvinder_css_id})
                    </p>
                </div>
                <div class="aebg-conflict-choices">
                    <label>
                        <input type="radio" 
                               name="regeneration_${index}" 
                               value="regenerate_both"
                               class="aebg-regeneration-choice"
                               data-product-id="${conflict.product_id}"
                               data-product-number="${conflict.new_position}"
                               checked>
                        <span>Regenerate Product Container And Testvinder</span>
                    </label>
                    <label>
                        <input type="radio" 
                               name="regeneration_${index}" 
                               value="regenerate_testvinder_only"
                               class="aebg-regeneration-choice"
                               data-product-id="${conflict.product_id}"
                               data-product-number="${conflict.new_position}">
                        <span>Regenerate Testvinder Only</span>
                    </label>
                    <label>
                        <input type="radio" 
                               name="regeneration_${index}" 
                               value="skip"
                               class="aebg-regeneration-choice"
                               data-product-id="${conflict.product_id}"
                               data-product-number="${conflict.new_position}">
                        <span>Skip Regeneration</span>
                    </label>
                </div>
            `);
            
            return $item;
        },
        
        /**
         * Collect user choices
         */
        collectChoices: function() {
            const choices = {};
            
            $('.aebg-regeneration-choice:checked').each(function() {
                const $input = $(this);
                const productId = $input.data('product-id');
                const productNumber = $input.data('product-number');
                const action = $input.val();
                
                choices[productId] = {
                    action: action,
                    product_number: productNumber
                };
            });
            
            return choices;
        },
        
        /**
         * Handle proceed button
         */
        handleProceed: function() {
            const choices = this.collectChoices();
            const postId = $('#aebg-reorder-conflict-modal').data('post-id');
            const newOrder = $('#aebg-reorder-conflict-modal').data('new-order');
            
            // Execute reordering with choices
            this.executeReordering(postId, newOrder, choices);
        },
        
        /**
         * Execute reordering with regeneration choices
         */
        executeReordering: function(postId, newOrder, choices) {
            $.ajax({
                url: aebg_reorder.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aebg_execute_reorder_with_choices',
                    _ajax_nonce: aebg_reorder.nonce,
                    post_id: postId,
                    new_product_order: JSON.stringify(newOrder),
                    regeneration_choices: JSON.stringify(choices)
                },
                dataType: 'json',
                success: (response) => {
                    if (response.success) {
                        showMessage('Product order updated successfully!', 'success');
                        $('#aebg-reorder-conflict-modal').removeClass('show');
                        // Refresh page or update UI
                        location.reload();
                    } else {
                        showMessage('Error: ' + (response.data?.message || 'Unknown error'), 'error');
                    }
                },
                error: (xhr) => {
                    showMessage('Error updating product order', 'error');
                    console.error('Reorder error:', xhr);
                }
            });
        },
        
        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        ReorderConflictHandler.init();
    });
    
    // Export for use in edit-posts.js
    window.ReorderConflictHandler = ReorderConflictHandler;
    
})(jQuery);
```

---

## 7. Integration Points

### 7.1 Modify `TemplateManager::ajax_update_post_products()`

```php
public function ajax_update_post_products() {
    // ... existing security checks ...
    
    // NEW: Check for conflicts first
    $resolver = new ReorderConflictResolver($this);
    $conflict_check = $resolver->prepareReorderingWithChoices($post_id, $new_product_order);
    
    if (is_wp_error($conflict_check)) {
        wp_send_json_error(['message' => $conflict_check->get_error_message()]);
    }
    
    // If conflicts exist, return them for frontend modal
    if (!empty($conflict_check['conflicts'])) {
        wp_send_json_success([
            'has_conflicts' => true,
            'conflicts' => $conflict_check['conflicts'],
            'position_mapping' => $conflict_check['position_mapping'],
            'message' => 'Conflicts detected. User choice required.',
        ]);
        return;
    }
    
    // No conflicts - proceed with normal reordering
    $result = $this->updatePostProductsWithOrder($post_id, $new_product_order);
    // ... rest of existing code ...
}
```

### 7.2 Add New AJAX Endpoint

```php
/**
 * AJAX execute reorder with regeneration choices
 */
public function ajax_execute_reorder_with_choices() {
    // Security checks (same pattern as ajax_update_post_products)
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => __('Permission denied.', 'aebg')], 403);
    }
    
    if (!check_ajax_referer('aebg_reorder_conflict', '_ajax_nonce', false)) {
        wp_send_json_error(['message' => __('Security check failed.', 'aebg')], 403);
    }
    
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $new_order = isset($_POST['new_product_order']) ? json_decode(stripslashes($_POST['new_product_order']), true) : [];
    $regeneration_choices = isset($_POST['regeneration_choices']) ? json_decode(stripslashes($_POST['regeneration_choices']), true) : [];
    
    // Validation...
    
    $resolver = new ReorderConflictResolver($this);
    $result = $resolver->resolveConflictsAndReorder($post_id, $new_order, $regeneration_choices);
    
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
    
    wp_send_json_success($result);
}
```

---

## 8. Testing Strategy

### 8.1 Unit Tests

- `TestvinderConflictDetector::detectConflicts()` - Test conflict detection logic
- `TestvinderRegenerationManager::scheduleTestvinderOnlyRegeneration()` - Test scheduling
- `ReorderConflictResolver::prepareReorderingWithChoices()` - Test conflict preparation

### 8.2 Integration Tests

- Full reordering flow with conflicts
- Rollback on failure
- Cache invalidation

### 8.3 Security Tests

- Nonce validation
- Capability checks
- Input sanitization
- XSS prevention

---

## 9. Performance Benchmarks

### 9.1 Expected Performance

- **Conflict Detection**: < 50ms (cached Elementor data)
- **Reordering**: < 200ms (existing performance)
- **Regeneration Scheduling**: < 10ms (async, non-blocking)

### 9.2 Optimization Targets

- Cache hit rate: > 80%
- Database queries: < 5 per operation
- Memory usage: < 10MB per operation

---

## 10. Deployment Checklist

- [ ] All classes created and tested
- [ ] Security checks implemented
- [ ] Error handling and rollback tested
- [ ] Frontend modal integrated
- [ ] Nonces registered
- [ ] Cache invalidation verified
- [ ] Logging verified
- [ ] Documentation updated

---

## Conclusion

This specification provides a bulletproof, state-of-the-art implementation that:
- ✅ Follows existing codebase patterns
- ✅ Implements comprehensive security
- ✅ Optimizes for performance
- ✅ Provides robust error handling
- ✅ Maintains modular architecture
- ✅ Reuses existing components

**Ready for implementation.**

