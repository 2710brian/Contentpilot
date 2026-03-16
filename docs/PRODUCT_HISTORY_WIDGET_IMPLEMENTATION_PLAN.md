# Product History Widget - Elementor Implementation Plan

**Version:** 1.0.0  
**Status:** Comprehensive Implementation Plan  
**Date:** 2024

---

## Executive Summary

This document outlines a complete implementation plan for an Elementor widget that displays product replacements, additions, removals, and their complete history. The widget will be fully responsive, customizable through native Elementor controls, and seamlessly integrated with the existing product management system.

### Key Features

1. **Product Replacements Display** - Shows when products were replaced, what was replaced, and by what
2. **New Products Tracking** - Displays products that were added to posts
3. **Removed Products History** - Shows products that were removed from posts
4. **Complete History Timeline** - Chronological view of all product changes
5. **Fully Responsive** - Native Elementor responsive controls
6. **Highly Customizable** - Extensive styling options through Elementor controls
7. **Native Integration** - Uses existing database tables and API endpoints

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Database Schema Analysis](#database-schema-analysis)
3. [API Endpoints](#api-endpoints)
4. [Widget Structure](#widget-structure)
5. [Implementation Details](#implementation-details)
6. [File Structure](#file-structure)
7. [Step-by-Step Implementation](#step-by-step-implementation)
8. [Styling & Responsive Design](#styling--responsive-design)
9. [Testing Strategy](#testing-strategy)
10. [Performance Considerations](#performance-considerations)

---

## Architecture Overview

### Current System Analysis

**Existing Infrastructure:**
- ✅ Product replacements tracked in `aebg_product_replacements` table
- ✅ Products stored in `_aebg_products` post meta
- ✅ Product removal tracking in `Meta_Box::ajax_remove_product()`
- ✅ API endpoint `DashboardController::get_product_replacements()`
- ✅ Existing `WidgetProductList` widget as reference pattern
- ✅ Elementor Dynamic Tags for product data

**Data Flow:**
```
Post Meta (_aebg_products) → Current Products
     ↓
aebg_product_replacements → Replacement History
     ↓
Widget → Display History with Customization
```

### Proposed Architecture

```
┌─────────────────────────────────────────────────────────────┐
│         Elementor Page (Post)                                │
│                                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │  Product History Widget                            │    │
│  │  - Reads from aebg_product_replacements            │    │
│  │  - Reads from _aebg_products (current state)        │    │
│  │  - Calculates additions/removals                   │    │
│  │  - Displays timeline/history                       │    │
│  │  - Full Elementor styling controls                 │    │
│  └────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema Analysis

### Existing Tables

#### `aebg_product_replacements`
```sql
- id (BIGINT) - Primary key
- post_id (BIGINT) - Post where replacement occurred
- user_id (BIGINT) - User who performed replacement
- old_product_id (VARCHAR) - Previous product ID
- old_product_name (VARCHAR) - Previous product name
- new_product_id (VARCHAR) - New product ID
- new_product_name (VARCHAR) - New product name
- product_number (INT) - Product position (1-based)
- replacement_type (VARCHAR) - 'manual', 'auto', 'bulk'
- reason (VARCHAR) - Replacement reason
- created_at (DATETIME) - Timestamp
```

**Indexes:**
- `idx_post_id` - Fast lookup by post
- `idx_created_at` - Chronological sorting
- `idx_post_date` - Combined post + date queries

#### `_aebg_products` (Post Meta)
```php
Array of products with structure:
[
    [
        'id' => 'product_id',
        'name' => 'Product Name',
        'price' => 999.99,
        'currency' => 'DKK',
        'url' => 'https://...',
        'affiliate_url' => 'https://...',
        'image_url' => 'https://...',
        'merchant' => 'Merchant Name',
        // ... other fields
    ],
    // ... more products
]
```

### Data Gaps & Solutions

**Issue:** Removed products are not explicitly tracked in a separate table.

**Solution:** 
1. Compare current `_aebg_products` with historical replacements to infer removals
2. Track removals in `aebg_product_replacements` with `old_product_id` set and `new_product_id` NULL
3. Add removal tracking to `Meta_Box::ajax_remove_product()` method

**Issue:** New products (additions) are not explicitly tracked.

**Solution:**
1. Compare current products with replacement history
2. Products that appear in current list but have no replacement record = new additions
3. Track additions by recording when product_number > current count

---

## API Endpoints

### Existing Endpoint

**GET `/wp-json/aebg/v1/dashboard/product-replacements`**
- Returns paginated product replacements
- Supports filtering by `post_id`, `date_from`, `date_to`
- Located in: `src/API/DashboardController.php`

### Required New Endpoints

**GET `/wp-json/aebg/v1/product-history/{post_id}`**
- Returns complete history for a post:
  - Current products
  - Replacement history
  - Inferred additions
  - Inferred removals
  - Timeline sorted by date

**Structure:**
```json
{
  "current_products": [...],
  "replacements": [...],
  "additions": [...],
  "removals": [...],
  "timeline": [
    {
      "type": "replacement|addition|removal",
      "date": "2024-01-15 10:30:00",
      "product_number": 1,
      "old_product": {...},
      "new_product": {...},
      "user": {...}
    }
  ]
}
```

---

## Widget Structure

### Widget Class: `AEBG_Product_History`

**Location:** `src/Elementor/WidgetProductHistory.php`

**Inheritance:** `\Elementor\Widget_Base`

**Key Methods:**
- `get_name()` - Returns 'aebg-product-history'
- `get_title()` - Returns 'AEBG Product History'
- `get_icon()` - Returns 'eicon-history'
- `register_controls()` - Registers all Elementor controls
- `render()` - Frontend rendering
- `content_template()` - Editor preview

### Control Sections

#### 1. Content Section
- **Display Mode**: Timeline, List, Cards, Tabs
- **Filter Options**: Show replacements, additions, removals (toggles)
- **Date Range**: All time, Last 7 days, Last 30 days, Custom range
- **Limit Items**: Number of items to display
- **Group By**: None, Date, Type, Product Number
- **Show User Info**: Toggle
- **Show Product Details**: Toggle
- **Show Reason**: Toggle (for replacements)

#### 2. Layout Section
- **Layout Style**: Timeline, List, Grid, Cards
- **Items Per Row**: 1-4 (responsive)
- **Spacing**: Between items
- **Alignment**: Left, Center, Right

#### 3. Timeline Style (if timeline mode)
- **Timeline Position**: Left, Right, Center
- **Timeline Width**: Custom
- **Timeline Color**: Color picker
- **Timeline Style**: Solid, Dashed, Dotted
- **Connector Style**: Line, Dot, Arrow

#### 4. Typography Section
- **Title Typography**: Group control
- **Date Typography**: Group control
- **Product Name Typography**: Group control
- **Description Typography**: Group control

#### 5. Colors Section
- **Background Color**: Container
- **Item Background**: Individual items
- **Border Color**: Items
- **Text Color**: Primary, Secondary
- **Link Color**: Normal, Hover
- **Badge Colors**: Replacement, Addition, Removal

#### 6. Spacing Section
- **Container Padding**: Responsive dimensions
- **Container Margin**: Responsive dimensions
- **Item Padding**: Responsive dimensions
- **Item Margin**: Responsive dimensions
- **Gap Between Items**: Responsive slider

#### 7. Border Section
- **Container Border**: Group control
- **Item Border**: Group control
- **Border Radius**: Container and items

#### 8. Effects Section
- **Box Shadow**: Container and items
- **Hover Effects**: Transform, Shadow, Color transitions

#### 9. Responsive Controls
- All spacing, typography, layout controls have responsive variants
- Tablet and mobile breakpoints
- Hide/show items on different devices

---

## Implementation Details

### 1. Widget Registration

**File:** `ai-bulk-generator-for-elementor.php`

Add after existing widget registrations (around line 210):

```php
// Register the custom AEBG Product History widget
add_action('elementor/widgets/register', function($widgets_manager) {
    require_once __DIR__ . '/src/Elementor/WidgetProductHistory.php';
    $widgets_manager->register( new \Elementor\AEBG_Product_History() );
});
```

### 2. API Controller

**File:** `src/API/ProductHistoryController.php` (NEW)

```php
<?php
namespace AEBG\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ProductHistoryController extends WP_REST_Controller {
    
    protected $namespace = 'aebg/v1';
    protected $rest_base = 'product-history';
    
    public function register_routes() {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<post_id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_product_history'],
            'permission_callback' => [$this, 'get_item_permissions_check'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    }
                ],
            ],
        ]);
    }
    
    public function get_item_permissions_check($request) {
        // Allow public read access (or restrict as needed)
        return true;
    }
    
    public function get_product_history($request) {
        $post_id = (int) $request->get_param('post_id');
        
        if (!get_post($post_id)) {
            return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }
        
        $history = $this->build_product_history($post_id);
        
        return new WP_REST_Response($history, 200);
    }
    
    private function build_product_history($post_id) {
        global $wpdb;
        
        // Get current products
        $current_products = \AEBG\Core\ProductManager::getPostProducts($post_id);
        
        // Get replacement history
        $replacements_table = $wpdb->prefix . 'aebg_product_replacements';
        $replacements = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$replacements_table}
            WHERE post_id = %d
            ORDER BY created_at ASC",
            $post_id
        ), ARRAY_A);
        
        // Build timeline
        $timeline = [];
        $product_history = []; // Track product lifecycle
        
        foreach ($replacements as $replacement) {
            $user = get_userdata($replacement['user_id']);
            
            $timeline[] = [
                'type' => 'replacement',
                'id' => $replacement['id'],
                'date' => $replacement['created_at'],
                'product_number' => (int) $replacement['product_number'],
                'old_product' => [
                    'id' => $replacement['old_product_id'],
                    'name' => $replacement['old_product_name'],
                ],
                'new_product' => [
                    'id' => $replacement['new_product_id'],
                    'name' => $replacement['new_product_name'],
                ],
                'replacement_type' => $replacement['replacement_type'],
                'reason' => $replacement['reason'],
                'user' => [
                    'id' => $replacement['user_id'],
                    'name' => $user ? $user->display_name : 'Unknown',
                    'email' => $user ? $user->user_email : '',
                ],
            ];
            
            // Track product lifecycle
            if ($replacement['old_product_id']) {
                $product_history[$replacement['old_product_id']] = [
                    'removed_at' => $replacement['created_at'],
                    'replaced_by' => $replacement['new_product_id'],
                ];
            }
            if ($replacement['new_product_id']) {
                if (!isset($product_history[$replacement['new_product_id']])) {
                    $product_history[$replacement['new_product_id']] = [
                        'added_at' => $replacement['created_at'],
                        'replaced' => $replacement['old_product_id'],
                    ];
                }
            }
        }
        
        // Identify additions (products in current list not in history)
        $current_product_ids = array_column($current_products, 'id');
        $historical_product_ids = array_unique(array_merge(
            array_column($replacements, 'old_product_id'),
            array_column($replacements, 'new_product_id')
        ));
        $historical_product_ids = array_filter($historical_product_ids);
        
        $additions = [];
        foreach ($current_products as $index => $product) {
            $product_id = $product['id'] ?? '';
            if ($product_id && !in_array($product_id, $historical_product_ids)) {
                // This is a new addition (never been replaced)
                $additions[] = [
                    'type' => 'addition',
                    'product_number' => $index + 1,
                    'product' => $product,
                    'date' => get_post_time('Y-m-d H:i:s', false, $post_id), // Use post creation date as fallback
                ];
            }
        }
        
        // Identify removals (products in history but not in current list)
        $removals = [];
        foreach ($replacements as $replacement) {
            $old_id = $replacement['old_product_id'];
            if ($old_id && !in_array($old_id, $current_product_ids)) {
                // This product was removed and never replaced back
                $removals[] = [
                    'type' => 'removal',
                    'id' => $replacement['id'],
                    'date' => $replacement['created_at'],
                    'product_number' => (int) $replacement['product_number'],
                    'product' => [
                        'id' => $replacement['old_product_id'],
                        'name' => $replacement['old_product_name'],
                    ],
                    'user' => [
                        'id' => $replacement['user_id'],
                        'name' => get_userdata($replacement['user_id'])->display_name ?? 'Unknown',
                    ],
                ];
            }
        }
        
        // Merge timeline with additions and removals, sort by date
        $full_timeline = array_merge($timeline, $additions, $removals);
        usort($full_timeline, function($a, $b) {
            return strtotime($a['date'] ?? '1970-01-01') <=> strtotime($b['date'] ?? '1970-01-01');
        });
        
        return [
            'current_products' => $current_products,
            'replacements' => $timeline,
            'additions' => $additions,
            'removals' => $removals,
            'timeline' => $full_timeline,
            'summary' => [
                'total_replacements' => count($timeline),
                'total_additions' => count($additions),
                'total_removals' => count($removals),
                'current_count' => count($current_products),
            ],
        ];
    }
}
```

### 3. Register API Route

**File:** `src/Plugin.php`

Add in `register_rest_routes()` method or create new method:

```php
// Register Product History API
add_action('rest_api_init', function() {
    $controller = new \AEBG\API\ProductHistoryController();
    $controller->register_routes();
});
```

### 4. Widget Implementation

**File:** `src/Elementor/WidgetProductHistory.php` (NEW)

See detailed implementation in next section.

---

## File Structure

```
ai-content-generator-main/
├── src/
│   ├── Elementor/
│   │   ├── WidgetProductHistory.php          [NEW]
│   │   └── DynamicTags/                       [EXISTING]
│   ├── API/
│   │   └── ProductHistoryController.php      [NEW]
│   └── Core/
│       └── ProductManager.php                 [EXISTING - may need enhancement]
├── assets/
│   ├── css/
│   │   └── product-history-widget.css         [NEW]
│   └── js/
│       └── product-history-widget.js          [NEW - optional for interactions]
├── ai-bulk-generator-for-elementor.php        [MODIFY - register widget]
└── docs/
    └── PRODUCT_HISTORY_WIDGET_IMPLEMENTATION_PLAN.md  [THIS FILE]
```

---

## Step-by-Step Implementation

### Phase 1: API Foundation

1. **Create ProductHistoryController**
   - File: `src/API/ProductHistoryController.php`
   - Implement `get_product_history()` method
   - Register REST route
   - Test endpoint: `/wp-json/aebg/v1/product-history/{post_id}`

2. **Register API Route**
   - Add registration in `src/Plugin.php`
   - Test with Postman/curl

3. **Enhance Removal Tracking** (Optional but recommended)
   - Modify `Meta_Box::ajax_remove_product()` to record removal in `aebg_product_replacements`
   - Set `new_product_id` to NULL for removals

### Phase 2: Widget Core

4. **Create Widget Class**
   - File: `src/Elementor/WidgetProductHistory.php`
   - Extend `\Elementor\Widget_Base`
   - Implement basic structure: `get_name()`, `get_title()`, `get_icon()`

5. **Register Widget**
   - Add registration in `ai-bulk-generator-for-elementor.php`
   - Verify widget appears in Elementor panel

6. **Implement Basic Render**
   - Fetch data from API endpoint
   - Display simple list of history items
   - Test in Elementor editor and frontend

### Phase 3: Controls Implementation

7. **Content Controls**
   - Display mode selector
   - Filter toggles
   - Date range selector
   - Limit items control

8. **Layout Controls**
   - Layout style selector
   - Items per row (responsive)
   - Spacing controls

9. **Style Controls**
   - Typography groups
   - Color controls
   - Border controls
   - Spacing controls
   - Effects controls

10. **Responsive Controls**
    - Add responsive variants for all spacing/typography
    - Test on different screen sizes

### Phase 4: Rendering & Styling

11. **Timeline View**
    - Implement timeline layout
    - Add connector lines/dots
    - Style timeline elements

12. **List View**
    - Simple list layout
    - Item styling
    - Hover effects

13. **Card View**
    - Card-based layout
    - Grid system
    - Card styling

14. **Tab View** (Optional)
    - Separate tabs for replacements/additions/removals
    - Tab styling

### Phase 5: CSS & Responsive

15. **Create Stylesheet**
    - File: `assets/css/product-history-widget.css`
    - Base styles
    - Layout variants
    - Responsive breakpoints

16. **Register Stylesheet**
    - Enqueue in widget's `get_style_depends()`
    - Test loading

17. **Responsive Testing**
    - Test on mobile (320px+)
    - Test on tablet (768px+)
    - Test on desktop (1024px+)
    - Adjust breakpoints as needed

### Phase 6: JavaScript (Optional)

18. **Interactive Features** (if needed)
    - File: `assets/js/product-history-widget.js`
    - Expand/collapse items
    - Filter interactions
    - Lazy loading for large histories

19. **Register Script**
    - Enqueue in widget's `get_script_depends()`
    - Test interactions

### Phase 7: Testing & Refinement

20. **Unit Testing**
    - Test API endpoint with various post IDs
    - Test with posts that have no history
    - Test with posts that have extensive history

21. **Widget Testing**
    - Test all display modes
    - Test all filter combinations
    - Test responsive behavior
    - Test in Elementor editor
    - Test on frontend

22. **Performance Testing**
    - Test with 100+ history items
    - Optimize queries if needed
    - Add pagination if necessary

23. **Cross-browser Testing**
    - Chrome
    - Firefox
    - Safari
    - Edge

---

## Styling & Responsive Design

### CSS Architecture

**Base Classes:**
```css
.aebg-product-history { }
.aebg-product-history--timeline { }
.aebg-product-history--list { }
.aebg-product-history--cards { }
.aebg-product-history-item { }
.aebg-product-history-item--replacement { }
.aebg-product-history-item--addition { }
.aebg-product-history-item--removal { }
```

### Responsive Breakpoints

- **Mobile:** < 768px
- **Tablet:** 768px - 1023px
- **Desktop:** ≥ 1024px

### Elementor Responsive Controls

All spacing, typography, and layout controls should use:
- `responsive` => true
- `device_args` for tablet/mobile overrides
- `selectors` with responsive units

Example:
```php
$this->add_responsive_control(
    'item_padding',
    [
        'label' => esc_html__('Item Padding', 'aebg'),
        'type' => Controls_Manager::DIMENSIONS,
        'size_units' => ['px', 'em', 'rem'],
        'default' => [
            'top' => '20',
            'right' => '20',
            'bottom' => '20',
            'left' => '20',
            'unit' => 'px',
        ],
        'tablet_default' => [
            'top' => '15',
            'right' => '15',
            'bottom' => '15',
            'left' => '15',
            'unit' => 'px',
        ],
        'mobile_default' => [
            'top' => '10',
            'right' => '10',
            'bottom' => '10',
            'left' => '10',
            'unit' => 'px',
        ],
        'selectors' => [
            '{{WRAPPER}} .aebg-product-history-item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
        ],
    ]
);
```

---

## Testing Strategy

### Unit Tests

1. **API Endpoint Tests**
   - Valid post ID returns data
   - Invalid post ID returns 404
   - Empty history returns empty arrays
   - Large history returns paginated results

2. **Data Processing Tests**
   - Replacements correctly identified
   - Additions correctly identified
   - Removals correctly identified
   - Timeline correctly sorted

### Integration Tests

1. **Widget Rendering**
   - Widget appears in Elementor panel
   - Widget renders in editor
   - Widget renders on frontend
   - All display modes work

2. **Controls Functionality**
   - All controls affect output
   - Responsive controls work
   - Filters work correctly
   - Date ranges filter correctly

### User Acceptance Tests

1. **Editor Experience**
   - Easy to find widget
   - Controls are intuitive
   - Preview updates correctly
   - No console errors

2. **Frontend Experience**
   - Displays correctly
   - Responsive on all devices
   - Performance is acceptable
   - Accessible (WCAG compliance)

---

## Performance Considerations

### Database Optimization

1. **Index Usage**
   - Ensure `idx_post_id` and `idx_created_at` are used
   - Consider composite index on `(post_id, created_at)`

2. **Query Optimization**
   - Limit results if history is very large
   - Consider pagination for 100+ items
   - Cache results if appropriate

3. **Data Processing**
   - Process data server-side when possible
   - Minimize JavaScript processing
   - Use efficient array operations

### Frontend Optimization

1. **CSS Optimization**
   - Minimize CSS file size
   - Use efficient selectors
   - Avoid expensive properties (box-shadow, filters)

2. **JavaScript Optimization**
   - Lazy load if needed
   - Debounce filter interactions
   - Virtual scrolling for large lists

3. **Caching**
   - Consider transient caching for API responses
   - Cache duration: 5-15 minutes
   - Clear cache on product updates

---

## Additional Features (Future Enhancements)

1. **Export Functionality**
   - Export history as CSV
   - Export as PDF report

2. **Notifications**
   - Email notifications on product changes
   - Dashboard notifications

3. **Analytics**
   - Most replaced products
   - Replacement frequency
   - User activity stats

4. **Bulk Operations**
   - Bulk view across multiple posts
   - Comparison between posts

5. **Advanced Filtering**
   - Filter by user
   - Filter by product
   - Filter by date range
   - Search functionality

---

## Security Considerations

1. **API Permissions**
   - Restrict API access if needed
   - Add nonce verification
   - Sanitize all inputs

2. **Data Sanitization**
   - Sanitize all displayed data
   - Escape all outputs
   - Validate all inputs

3. **SQL Injection Prevention**
   - Use `$wpdb->prepare()` for all queries
   - Validate post IDs
   - Validate date ranges

---

## Success Criteria

✅ Widget appears in Elementor panel  
✅ Widget renders correctly in editor  
✅ Widget renders correctly on frontend  
✅ All display modes work  
✅ All controls function correctly  
✅ Responsive on all devices  
✅ Performance is acceptable (< 2s load time)  
✅ No console errors  
✅ Accessible (WCAG 2.1 AA compliance)  
✅ Works with existing product system  
✅ Handles edge cases (no history, large history, etc.)  

---

## Conclusion

This implementation plan provides a comprehensive roadmap for creating a fully-featured, responsive, and customizable Elementor widget for displaying product history. The widget will integrate seamlessly with the existing codebase and provide users with powerful tools to track and display product changes.

**Estimated Implementation Time:** 20-30 hours

**Priority:** High - Provides valuable functionality for content management and transparency

**Dependencies:** 
- Elementor Pro (for advanced controls)
- Existing product management system
- Database tables (already exist)

---

**Next Steps:**
1. Review and approve this plan
2. Begin Phase 1: API Foundation
3. Iterate through phases
4. Test thoroughly
5. Deploy to production


