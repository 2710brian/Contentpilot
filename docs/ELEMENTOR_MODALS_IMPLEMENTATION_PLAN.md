# Elementor Pro Popup Builder Integration Plan
## Testvinder Modals & Associated Products List Modals

**Version:** 2.0.0 (Revised - Using Elementor Pro Popup Builder)  
**Status:** Production-Ready Implementation Plan  
**Date:** 2024

---

## Executive Summary

This document outlines a production-ready, bulletproof implementation plan for creating two types of modals using **Elementor Pro Popup Builder**:

1. **Testvinder Modals** - Display "best in test" product information in a popup
2. **Associated Products List Modals** - Display a list of associated products in a popup

Since you already have Elementor Pro with Popup Builder, we'll leverage the native popup system instead of creating custom widgets. This approach is:
- ✅ **Simpler** - No custom widget code needed
- ✅ **More Flexible** - Use any Elementor widgets inside popups
- ✅ **Easier to Maintain** - Native Elementor functionality
- ✅ **Better UX** - Full access to Elementor's popup features (triggers, conditions, animations)

### 🔗 Affiliate Link Integration

**Critical Feature:** Affiliate links work automatically through existing widgets:

- **WidgetProductList** already handles affiliate links via `Shortcodes::get_affiliate_link_for_url()`
- **Automatic Processing**: All affiliate links processed server-side before rendering
- **Network Detection**: Automatically detects affiliate network from URLs
- **@@@ Replacement**: Replaces `@@@` placeholder with actual affiliate IDs from database
- **Security**: All links include `rel="noopener noreferrer"` for security
- **Error Handling**: Graceful handling when affiliate IDs are missing

### 🎯 Implementation Approach

Instead of creating custom widgets, we'll:
1. **Create Custom Dynamic Tags** - Native Elementor Dynamic Tags for testvinder and product data
2. **Create Popup Templates** - Pre-built popup templates for common use cases
3. **Documentation** - Guide on setting up testvinder and products list popups
4. **Helper Functions** - PHP helpers to extract testvinder/product data
5. **CSS Enhancements** (Optional) - Custom styling if needed

**Why Dynamic Tags?**
- ✅ **Native Elementor Integration** - Works directly in Elementor editor
- ✅ **Visual Selection** - Users select from dropdown, no typing shortcodes
- ✅ **Type-Safe** - Different tags for different data types (text, image, URL, etc.)
- ✅ **Works in Any Widget** - Can use in Text, Image, Button, Heading, etc.
- ✅ **Better UX** - Visual interface vs typing shortcodes
- ✅ **Professional** - Industry-standard approach for Elementor Pro

This leverages your existing Elementor Pro investment and keeps the codebase clean while providing the best user experience.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Technical Requirements](#technical-requirements)
3. [Implementation Strategy](#implementation-strategy)
4. [File Structure](#file-structure)
5. [Detailed Implementation Steps](#detailed-implementation-steps)
6. [Popup Templates Guide](#popup-templates-guide)
7. [Helper Functions](#helper-functions)
8. [Security & Performance](#security--performance)
9. [Testing Strategy](#testing-strategy)
10. [Rollout Plan](#rollout-plan)
11. [Success Criteria](#success-criteria)

---

## Architecture Overview

### Current State Analysis

**Existing Elementor Integration:**
- `WidgetProductList.php` - Displays product list in Elementor (already handles affiliate links!)
- `WidgetIconList.php` - Displays icon list in Elementor
- Widgets registered via `elementor/widgets/register` hook
- Full Elementor controls API integration

**Elementor Pro Popup Builder:**
- ✅ Native popup/modal system
- ✅ Built-in trigger system (button clicks, page load, exit intent, etc.)
- ✅ Conditions and targeting
- ✅ Animations and styling
- ✅ Can contain any Elementor widgets

**Testvinder Backend:**
- Testvinder containers already implemented (`testvinder-{number}` pattern)
- Testvinder regeneration system in place
- Testvinder conflict detection working

**Associated Products:**
- Managed in `Meta_Box.php`
- Stored in `_aebg_products` post meta
- `WidgetProductList` already reads from this

### Proposed Architecture

```
┌─────────────────────────────────────────────────────────────┐
│              Elementor Page/Template                         │
│                                                              │
│  [Trigger Button] → Opens Elementor Pro Popup              │
│                                                              │
└─────────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────────┐
│         Elementor Pro Popup (Template)                       │
│                                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │  WidgetProductList Widget (existing)                │    │
│  │  - Reads from _aebg_products                       │    │
│  │  - Handles affiliate links automatically           │    │
│  │  - Full Elementor styling                          │    │
│  └────────────────────────────────────────────────────┘    │
│                                                              │
│  OR                                                          │
│                                                              │
│  ┌────────────────────────────────────────────────────┐    │
│  │  Testvinder Content (custom section)               │    │
│  │  - Uses helper function to get testvinder data     │    │
│  │  - Standard Elementor widgets for display          │    │
│  └────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

**Key Design Decisions:**

1. **Use Elementor Pro Popup Builder (Native)**
   - ✅ No custom widget code needed
   - ✅ Full Elementor editor control
   - ✅ Built-in trigger system
   - ✅ Conditions and targeting
   - ✅ Animations included

2. **Reuse Existing Widgets**
   - `WidgetProductList` already works perfectly
   - Just add it inside a popup template
   - Affiliate links handled automatically

3. **Helper Functions for Testvinder**
   - PHP helper to extract testvinder data from Elementor containers
   - Can be used in shortcodes or custom HTML widgets
   - Makes testvinder data accessible in popups

4. **Minimal Custom Code**
   - Only helpers if needed
   - Optional CSS enhancements
   - Documentation and templates

---

## Technical Requirements

### Dependencies

**Required:**
- WordPress 5.0+
- Elementor 3.0+ (Free)
- **Elementor Pro** (for Popup Builder) ✅ You have this!
- PHP 7.4+
- jQuery (WordPress bundled)

**Optional (for enhanced features):**
- Modern browser support (ES6+)

### Browser Support

- Chrome/Edge (last 2 versions)
- Firefox (last 2 versions)
- Safari (last 2 versions)
- Mobile browsers (iOS Safari, Chrome Mobile)

### Performance Targets

- Modal open/close: < 100ms
- Initial page load impact: < 50ms
- Memory footprint: < 5MB
- No layout shift (CLS = 0)

---

## Implementation Strategy

### Phase 1: Helper Functions & Dynamic Tags

**Goal:** Create helper functions and custom Dynamic Tags for testvinder and product data.

**Components:**
1. `TestvinderHelper.php` - PHP class to extract testvinder data from Elementor containers
2. `ProductHelper.php` - PHP class to extract product data (if needed)
3. Custom Dynamic Tags for testvinder data (name, image, rating, price, affiliate URL)
4. Custom Dynamic Tags for product data (name, image, price, affiliate URL by index)
5. Register Dynamic Tags with Elementor

### Phase 2: Popup Templates

**Goal:** Create pre-built popup templates for common use cases.

**Components:**
1. **Products List Popup Template**
   - Uses existing `WidgetProductList`
   - Pre-configured styling
   - Export as JSON template

2. **Testvinder Popup Template**
   - Uses helper functions to display testvinder data
   - Pre-configured layout
   - Export as JSON template

### Phase 3: Integration & Polish

**Goal:** Ensure everything works seamlessly together.

**Components:**
1. Test affiliate links in popups (should work automatically)
2. Test responsive behavior
3. Documentation updates
4. Optional CSS enhancements if needed

---

## File Structure

```
ai-content-generator-main/
├── src/
│   ├── Core/
│   │   ├── TestvinderHelper.php         [NEW] Helper to extract testvinder data
│   │   └── ProductHelper.php            [NEW] Helper to extract product data
│   └── Elementor/
│       └── DynamicTags/                 [NEW] Custom Dynamic Tags
│           ├── TestvinderName.php       [NEW] Testvinder product name
│           ├── TestvinderImage.php      [NEW] Testvinder product image
│           ├── TestvinderRating.php     [NEW] Testvinder product rating
│           ├── TestvinderPrice.php      [NEW] Testvinder product price
│           ├── TestvinderAffiliateUrl.php [NEW] Testvinder affiliate URL
│           ├── ProductName.php          [NEW] Product name (by index)
│           ├── ProductImage.php         [NEW] Product image (by index)
│           ├── ProductPrice.php         [NEW] Product price (by index)
│           └── ProductAffiliateUrl.php  [NEW] Product affiliate URL (by index)
├── templates/
│   └── elementor-popups/                [NEW] Popup template JSON files
│       ├── products-list-popup.json     [NEW] Products list popup template
│       └── testvinder-popup.json        [NEW] Testvinder popup template
├── assets/
│   └── css/
│       └── elementor-popups.css        [NEW] Optional CSS enhancements
└── docs/
    ├── ELEMENTOR_MODALS_IMPLEMENTATION_PLAN.md [THIS FILE]
    └── ELEMENTOR_POPUP_SETUP_GUIDE.md    [NEW] User guide for setting up popups
```

**Note:** Dynamic Tags provide the best UX - visual selection in Elementor editor!

---

## Detailed Implementation Steps

### Step 1: Create Helper Classes

**Files:** `src/Core/TestvinderHelper.php` and `src/Core/ProductHelper.php`

**Purpose:** Extract testvinder and product data from Elementor containers/post meta for use in Dynamic Tags.

**Why:** Dynamic Tags need helper functions to access the data. These classes provide clean, reusable data extraction.

**Implementation:**

```php
<?php
namespace AEBG\Core;

use AEBG\Core\TemplateManager;
use AEBG\Core\Shortcodes;

/**
 * Testvinder Helper Class
 * 
 * Extracts testvinder data from Elementor containers for use in popups
 */
class TestvinderHelper {
    
    /**
     * Get testvinder data for a specific testvinder number
     * 
     * @param int $post_id Post ID
     * @param int $testvinder_number Testvinder number (1, 2, 3, etc.)
     * @return array|null Testvinder product data or null if not found
     */
    public static function get_testvinder_data( $post_id, $testvinder_number = 1 ) {
        $template_manager = new TemplateManager();
        $elementor_data = $template_manager->getElementorData( $post_id );
        
        if ( is_wp_error( $elementor_data ) ) {
            return null;
        }
        
        // Find testvinder container
        $testvinder_container = self::find_testvinder_container( $elementor_data, $testvinder_number );
        
        if ( empty( $testvinder_container ) ) {
            return null;
        }
        
        // Extract product data from container
        $product_data = self::extract_product_data_from_container( $testvinder_container, $post_id );
        
        return $product_data;
    }
    
    /**
     * Find testvinder container in Elementor data
     */
    protected static function find_testvinder_container( $elementor_data, $testvinder_number ) {
        $target_id = 'testvinder-' . intval( $testvinder_number );
        return self::recursive_find_container( $elementor_data, $target_id );
    }
    
    /**
     * Recursively search for container with specific CSS ID
     */
    protected static function recursive_find_container( $elements, $target_id ) {
        if ( ! is_array( $elements ) ) {
            return null;
        }
        
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            
            // Check if this element has the target CSS ID
            $settings = $element['settings'] ?? [];
            $css_id = $settings['css_id'] ?? '';
            
            if ( $css_id === $target_id ) {
                return $element;
            }
            
            // Recursively search children
            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $found = self::recursive_find_container( $children, $target_id );
                if ( $found !== null ) {
                    return $found;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract product data from testvinder container
     * This is a simplified version - you may need to enhance based on your container structure
     */
    protected static function extract_product_data_from_container( $container, $post_id ) {
        // Get products for this post
        $products = ProductManager::getPostProducts( $post_id );
        
        // For testvinder-1, use first product; testvinder-2 uses second, etc.
        // This is a simplified approach - adjust based on your actual structure
        $testvinder_id = $container['settings']['css_id'] ?? '';
        preg_match( '/testvinder-(\d+)/', $testvinder_id, $matches );
        $testvinder_index = isset( $matches[1] ) ? (int) $matches[1] - 1 : 0;
        
        if ( isset( $products[ $testvinder_index ] ) ) {
            $product = $products[ $testvinder_index ];
            
            // Process affiliate link
            $shortcodes = new Shortcodes();
            $affiliate_url = $shortcodes->get_affiliate_link_for_url(
                $product['affiliate_url'] ?? $product['url'] ?? '',
                $product['merchant'] ?? '',
                $product['network'] ?? ''
            );
            
            return [
                'name' => $product['name'] ?? '',
                'image' => $product['featured_image_url'] ?? $product['image_url'] ?? '',
                'rating' => $product['rating'] ?? '',
                'price' => $product['price'] ?? '',
                'currency' => $product['currency'] ?? 'DKK',
                'affiliate_url' => $affiliate_url,
                'description' => $product['description'] ?? '',
            ];
        }
        
        return null;
    }
    
    /**
     * Get testvinder data as formatted HTML (for shortcode use)
     */
    public static function get_testvinder_html( $post_id, $testvinder_number = 1, $format = 'full' ) {
        $data = self::get_testvinder_data( $post_id, $testvinder_number );
        
        if ( empty( $data ) ) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="aebg-testvinder-popup-content">
            <?php if ( ! empty( $data['image'] ) ) : ?>
                <div class="aebg-testvinder-image">
                    <img src="<?php echo esc_url( $data['image'] ); ?>" 
                         alt="<?php echo esc_attr( $data['name'] ); ?>" />
                </div>
            <?php endif; ?>
            
            <div class="aebg-testvinder-info">
                <?php if ( ! empty( $data['name'] ) ) : ?>
                    <h3><?php echo esc_html( $data['name'] ); ?></h3>
                <?php endif; ?>
                
                <?php if ( ! empty( $data['rating'] ) ) : ?>
                    <div class="aebg-testvinder-rating">
                        <?php echo esc_html( $data['rating'] ); ?>/10
                    </div>
                <?php endif; ?>
                
                <?php if ( ! empty( $data['price'] ) ) : ?>
                    <div class="aebg-testvinder-price">
                        <?php echo esc_html( ProductManager::formatPrice( $data['price'], $data['currency'] ) ); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ( ! empty( $data['affiliate_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $data['affiliate_url'] ); ?>" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="aebg-testvinder-buy-button">
                        <?php esc_html_e( 'Køb nu', 'aebg' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

### Step 2: Create Custom Dynamic Tags

**Files:** `src/Elementor/DynamicTags/TestvinderName.php`, `TestvinderImage.php`, etc.

**Purpose:** Register custom Dynamic Tags with Elementor so users can visually select testvinder/product data in any widget.

**Example Implementation - Testvinder Name Dynamic Tag:**

```php
<?php
namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use AEBG\Core\TestvinderHelper;

class TestvinderName extends Tag {
    
    public function get_name() {
        return 'aebg-testvinder-name';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Testvinder Name', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::TEXT_CATEGORY ];
    }
    
    protected function register_controls() {
        $this->add_control(
            'testvinder_number',
            [
                'label' => esc_html__( 'Testvinder Number', 'aebg' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 1,
                'min' => 1,
                'max' => 10,
            ]
        );
    }
    
    public function render() {
        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return;
        }
        
        $testvinder_number = $this->get_settings( 'testvinder_number' ) ?? 1;
        $data = TestvinderHelper::get_testvinder_data( $post_id, $testvinder_number );
        
        echo esc_html( $data['name'] ?? '' );
    }
}
```

**Example Implementation - Testvinder Affiliate URL Dynamic Tag:**

```php
<?php
namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use AEBG\Core\TestvinderHelper;

class TestvinderAffiliateUrl extends Tag {
    
    public function get_name() {
        return 'aebg-testvinder-affiliate-url';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Testvinder Affiliate URL', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::URL_CATEGORY ];
    }
    
    protected function register_controls() {
        $this->add_control(
            'testvinder_number',
            [
                'label' => esc_html__( 'Testvinder Number', 'aebg' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 1,
                'min' => 1,
                'max' => 10,
            ]
        );
    }
    
    public function render() {
        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return;
        }
        
        $testvinder_number = $this->get_settings( 'testvinder_number' ) ?? 1;
        $data = TestvinderHelper::get_testvinder_data( $post_id, $testvinder_number );
        
        echo esc_url( $data['affiliate_url'] ?? '' );
    }
}
```

**Register Dynamic Tags:**

```php
// In Plugin.php or appropriate file
add_action( 'elementor/dynamic_tags/register_tags', function( $dynamic_tags_manager ) {
    // Testvinder Tags
    require_once __DIR__ . '/src/Elementor/DynamicTags/TestvinderName.php';
    require_once __DIR__ . '/src/Elementor/DynamicTags/TestvinderImage.php';
    require_once __DIR__ . '/src/Elementor/DynamicTags/TestvinderRating.php';
    require_once __DIR__ . '/src/Elementor/DynamicTags/TestvinderPrice.php';
    require_once __DIR__ . '/src/Elementor/DynamicTags/TestvinderAffiliateUrl.php';
    
    $dynamic_tags_manager->register( new \AEBG\Elementor\DynamicTags\TestvinderName() );
    $dynamic_tags_manager->register( new \AEBG\Elementor\DynamicTags\TestvinderImage() );
    $dynamic_tags_manager->register( new \AEBG\Elementor\DynamicTags\TestvinderRating() );
    $dynamic_tags_manager->register( new \AEBG\Elementor\DynamicTags\TestvinderPrice() );
    $dynamic_tags_manager->register( new \AEBG\Elementor\DynamicTags\TestvinderAffiliateUrl() );
    
    // Product Tags (similar structure)
    // ...
} );
```

**Purpose:** Abstract base class providing common modal functionality.

**Key Features:**
- Common controls (trigger text, modal size, animation)
- Common render methods
- Modal structure template
- JavaScript initialization

**Implementation:**

```php
<?php
namespace Elementor;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

abstract class AEBG_Modal_Base extends Widget_Base {
    
    /**
     * Get widget categories
     */
    public function get_categories() {
        return [ 'aebg-modals' ];
    }
    
    /**
     * Register common modal controls
     */
    protected function register_modal_controls() {
        // Trigger Section
        $this->start_controls_section(
            'section_trigger',
            [
                'label' => esc_html__( 'Trigger', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'trigger_type',
            [
                'label' => esc_html__( 'Trigger Type', 'aebg' ),
                'type' => Controls_Manager::SELECT,
                'default' => 'button',
                'options' => [
                    'button' => esc_html__( 'Button', 'aebg' ),
                    'link' => esc_html__( 'Link', 'aebg' ),
                    'custom' => esc_html__( 'Custom Selector', 'aebg' ),
                ],
            ]
        );
        
        $this->add_control(
            'trigger_text',
            [
                'label' => esc_html__( 'Trigger Text', 'aebg' ),
                'type' => Controls_Manager::TEXT,
                'default' => esc_html__( 'Open Modal', 'aebg' ),
                'condition' => [
                    'trigger_type!' => 'custom',
                ],
            ]
        );
        
        $this->add_control(
            'custom_selector',
            [
                'label' => esc_html__( 'Custom Selector', 'aebg' ),
                'type' => Controls_Manager::TEXT,
                'description' => esc_html__( 'CSS selector for custom trigger element', 'aebg' ),
                'condition' => [
                    'trigger_type' => 'custom',
                ],
            ]
        );
        
        $this->end_controls_section();
        
        // Modal Section
        $this->start_controls_section(
            'section_modal',
            [
                'label' => esc_html__( 'Modal', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'modal_size',
            [
                'label' => esc_html__( 'Modal Size', 'aebg' ),
                'type' => Controls_Manager::SELECT,
                'default' => 'medium',
                'options' => [
                    'small' => esc_html__( 'Small', 'aebg' ),
                    'medium' => esc_html__( 'Medium', 'aebg' ),
                    'large' => esc_html__( 'Large', 'aebg' ),
                    'full' => esc_html__( 'Full Screen', 'aebg' ),
                    'custom' => esc_html__( 'Custom', 'aebg' ),
                ],
            ]
        );
        
        $this->add_responsive_control(
            'modal_width',
            [
                'label' => esc_html__( 'Width', 'aebg' ),
                'type' => Controls_Manager::SLIDER,
                'size_units' => [ 'px', '%', 'vw' ],
                'range' => [
                    'px' => [
                        'min' => 300,
                        'max' => 2000,
                    ],
                    '%' => [
                        'min' => 10,
                        'max' => 100,
                    ],
                ],
                'default' => [
                    'unit' => 'px',
                    'size' => 800,
                ],
                'condition' => [
                    'modal_size' => 'custom',
                ],
                'selectors' => [
                    '{{WRAPPER}} .aebg-modal-content' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );
        
        $this->add_control(
            'close_on_overlay',
            [
                'label' => esc_html__( 'Close on Overlay Click', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Yes', 'aebg' ),
                'label_off' => esc_html__( 'No', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );
        
        $this->add_control(
            'close_on_escape',
            [
                'label' => esc_html__( 'Close on ESC Key', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Yes', 'aebg' ),
                'label_off' => esc_html__( 'No', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );
        
        $this->end_controls_section();
        
        // Style Section - Trigger
        $this->start_controls_section(
            'section_trigger_style',
            [
                'label' => esc_html__( 'Trigger', 'aebg' ),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );
        
        // Trigger button/link styling controls
        // (Standard Elementor button controls)
        
        $this->end_controls_section();
        
        // Style Section - Modal
        $this->start_controls_section(
            'section_modal_style',
            [
                'label' => esc_html__( 'Modal', 'aebg' ),
                'tab' => Controls_Manager::TAB_STYLE,
            ]
        );
        
        // Modal overlay, content, header, body, footer styling
        // (Full Elementor styling controls)
        
        $this->end_controls_section();
    }
    
    /**
     * Get modal ID for this widget instance
     */
    protected function get_modal_id() {
        return 'aebg-modal-' . $this->get_id();
    }
    
    /**
     * Render trigger element
     */
    protected function render_trigger() {
        $settings = $this->get_settings_for_display();
        $modal_id = $this->get_modal_id();
        
        if ( $settings['trigger_type'] === 'custom' ) {
            // Custom selector - handled by JavaScript
            return;
        }
        
        $tag = $settings['trigger_type'] === 'link' ? 'a' : 'button';
        $attributes = [
            'class' => 'aebg-modal-trigger',
            'data-modal-id' => $modal_id,
        ];
        
        if ( $tag === 'a' ) {
            $attributes['href'] = '#';
        }
        
        echo '<' . esc_attr( $tag );
        foreach ( $attributes as $key => $value ) {
            echo ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
        }
        echo '>';
        echo esc_html( $settings['trigger_text'] );
        echo '</' . esc_attr( $tag ) . '>';
    }
    
    /**
     * Render modal structure
     */
    protected function render_modal_structure( $content_callback ) {
        $settings = $this->get_settings_for_display();
        $modal_id = $this->get_modal_id();
        
        ?>
        <div id="<?php echo esc_attr( $modal_id ); ?>" 
             class="aebg-modal" 
             data-close-on-overlay="<?php echo esc_attr( $settings['close_on_overlay'] ); ?>"
             data-close-on-escape="<?php echo esc_attr( $settings['close_on_escape'] ); ?>">
            <div class="aebg-modal-overlay"></div>
            <div class="aebg-modal-container aebg-modal-size-<?php echo esc_attr( $settings['modal_size'] ); ?>">
                <div class="aebg-modal-header">
                    <?php $this->render_modal_header(); ?>
                    <button type="button" class="aebg-modal-close" aria-label="<?php esc_attr_e( 'Close', 'aebg' ); ?>">
                        <span class="aebg-modal-close-icon">&times;</span>
                    </button>
                </div>
                <div class="aebg-modal-body">
                    <?php call_user_func( $content_callback ); ?>
                </div>
                <?php if ( method_exists( $this, 'render_modal_footer' ) ) : ?>
                    <div class="aebg-modal-footer">
                        <?php $this->render_modal_footer(); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render modal header (override in child classes)
     */
    protected function render_modal_header() {
        // Override in child classes
    }
    
    /**
     * Abstract method - child classes must implement
     */
    abstract protected function render_modal_content();
}
```

### Step 3: Create Popup Templates

**Files:** `templates/elementor-popups/products-list-popup.json` and `testvinder-popup.json`

**Purpose:** Pre-built popup templates that users can import into Elementor Pro.

**Products List Popup Template:**
- Contains `WidgetProductList` widget
- Pre-configured styling
- Ready to use

**Testvinder Popup Template:**
- Contains widgets using Dynamic Tags (Heading for name, Image for product image, Button for affiliate link, etc.)
- Pre-configured layout
- Ready to use

**Note:** These are JSON exports from Elementor that can be imported via Elementor's template library.

### Step 4: Create Setup Documentation

**File:** `docs/ELEMENTOR_POPUP_SETUP_GUIDE.md`

**Purpose:** User guide for setting up popups with AEBG widgets.

**Content:**
1. How to create a new popup in Elementor Pro
2. How to add WidgetProductList to a popup
3. How to use testvinder shortcode in popups
4. How to set up triggers (button clicks, etc.)
5. How to style popups
6. Best practices

**Purpose:** Widget that displays testvinder product information in a modal.

**Key Features:**
- Reads testvinder data from Elementor containers
- Displays product information (image, name, rating, etc.)
- Fully customizable via Elementor controls
- Responsive design

**Implementation Highlights:**

```php
<?php
namespace Elementor;

use Elementor\Widget_Base;
use AEBG\Core\ProductManager;
use AEBG\Core\TemplateManager;

class AEBG_Testvinder_Modal extends AEBG_Modal_Base {
    
    public function get_name() {
        return 'aebg-testvinder-modal';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Testvinder Modal', 'aebg' );
    }
    
    public function get_icon() {
        return 'eicon-testimonial';
    }
    
    protected function register_controls() {
        parent::register_modal_controls();
        
        // Testvinder-specific controls
        $this->start_controls_section(
            'section_testvinder',
            [
                'label' => esc_html__( 'Testvinder Settings', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'testvinder_number',
            [
                'label' => esc_html__( 'Testvinder Number', 'aebg' ),
                'type' => Controls_Manager::NUMBER,
                'default' => 1,
                'min' => 1,
                'max' => 10,
                'description' => esc_html__( 'Which testvinder container to display (testvinder-1, testvinder-2, etc.)', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'show_product_image',
            [
                'label' => esc_html__( 'Show Product Image', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Show', 'aebg' ),
                'label_off' => esc_html__( 'Hide', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );
        
        $this->add_control(
            'show_rating',
            [
                'label' => esc_html__( 'Show Rating', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Show', 'aebg' ),
                'label_off' => esc_html__( 'Hide', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );
        
        $this->add_control(
            'show_affiliate_link',
            [
                'label' => esc_html__( 'Show Affiliate Link', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Show', 'aebg' ),
                'label_off' => esc_html__( 'Hide', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
                'description' => esc_html__( 'Display "Buy Now" or "Shop Now" button with affiliate link', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'affiliate_link_text',
            [
                'label' => esc_html__( 'Affiliate Link Text', 'aebg' ),
                'type' => Controls_Manager::TEXT,
                'default' => esc_html__( 'Køb nu', 'aebg' ),
                'condition' => [
                    'show_affiliate_link' => 'yes',
                ],
            ]
        );
        
        $this->add_control(
            'affiliate_link_target',
            [
                'label' => esc_html__( 'Link Target', 'aebg' ),
                'type' => Controls_Manager::SELECT,
                'default' => '_blank',
                'options' => [
                    '_self' => esc_html__( 'Same Window', 'aebg' ),
                    '_blank' => esc_html__( 'New Window', 'aebg' ),
                ],
                'condition' => [
                    'show_affiliate_link' => 'yes',
                ],
            ]
        );
        
        // More controls for customization...
        
        $this->end_controls_section();
    }
    
    protected function render_modal_header() {
        $settings = $this->get_settings_for_display();
        ?>
        <h3 class="aebg-modal-title">
            <?php echo esc_html__( 'Testvinder', 'aebg' ); ?>
        </h3>
        <?php
    }
    
    protected function render_modal_content() {
        $settings = $this->get_settings_for_display();
        $post_id = get_the_ID();
        
        if ( ! $post_id ) {
            if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                $post_id = \Elementor\Plugin::$instance->editor->get_post_id();
            }
        }
        
        if ( ! $post_id ) {
            echo '<p>' . esc_html__( 'No post ID found.', 'aebg' ) . '</p>';
            return;
        }
        
        // Get testvinder data
        $testvinder_data = $this->get_testvinder_data( $post_id, $settings['testvinder_number'] );
        
        if ( empty( $testvinder_data ) ) {
            echo '<p>' . esc_html__( 'No testvinder data found.', 'aebg' ) . '</p>';
            return;
        }
        
        // Render testvinder content
        $this->render_testvinder_content( $testvinder_data, $settings );
    }
    
    protected function get_testvinder_data( $post_id, $testvinder_number ) {
        // Get Elementor data
        $template_manager = new TemplateManager();
        $elementor_data = $template_manager->getElementorData( $post_id );
        
        if ( is_wp_error( $elementor_data ) ) {
            return null;
        }
        
        // Find testvinder container
        $testvinder_container = $this->find_testvinder_container( $elementor_data, $testvinder_number );
        
        if ( empty( $testvinder_container ) ) {
            return null;
        }
        
        // Extract product data from container
        $product_data = $this->extract_product_data_from_container( $testvinder_container, $post_id );
        
        return $product_data;
    }
    
    protected function find_testvinder_container( $elementor_data, $testvinder_number ) {
        // Recursive search for container with CSS ID = testvinder-{number}
        // Implementation similar to Generator::findTestvinderContainer()
        
        $target_id = 'testvinder-' . intval( $testvinder_number );
        
        return $this->recursive_find_container( $elementor_data, $target_id );
    }
    
    protected function recursive_find_container( $elements, $target_id ) {
        if ( ! is_array( $elements ) ) {
            return null;
        }
        
        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            
            // Check if this element has the target CSS ID
            $settings = $element['settings'] ?? [];
            $css_id = $settings['css_id'] ?? '';
            
            if ( $css_id === $target_id ) {
                return $element;
            }
            
            // Recursively search children
            $children = $element['elements'] ?? [];
            if ( ! empty( $children ) ) {
                $found = $this->recursive_find_container( $children, $target_id );
                if ( $found !== null ) {
                    return $found;
                }
            }
        }
        
        return null;
    }
    
    protected function extract_product_data_from_container( $container, $post_id ) {
        // Extract product information from container elements
        // Look for shortcodes, text widgets, image widgets, etc.
        // Return structured product data array
        
        $product_data = [
            'name' => '',
            'image' => '',
            'rating' => '',
            'description' => '',
            // ... more fields
        ];
        
        // Implementation: Parse container elements to extract data
        // This is complex and depends on how data is stored in Elementor
        
        return $product_data;
    }
    
    protected function render_testvinder_content( $testvinder_data, $settings ) {
        // Get affiliate link
        $affiliate_url = '';
        if ( $settings['show_affiliate_link'] === 'yes' ) {
            $affiliate_url = $this->get_testvinder_affiliate_url( $testvinder_data );
        }
        
        ?>
        <div class="aebg-testvinder-content">
            <?php if ( $settings['show_product_image'] === 'yes' && ! empty( $testvinder_data['image'] ) ) : ?>
                <div class="aebg-testvinder-image">
                    <img src="<?php echo esc_url( $testvinder_data['image'] ); ?>" 
                         alt="<?php echo esc_attr( $testvinder_data['name'] ?? '' ); ?>" />
                </div>
            <?php endif; ?>
            
            <div class="aebg-testvinder-info">
                <?php if ( ! empty( $testvinder_data['name'] ) ) : ?>
                    <h4 class="aebg-testvinder-name">
                        <?php echo esc_html( $testvinder_data['name'] ); ?>
                    </h4>
                <?php endif; ?>
                
                <?php if ( $settings['show_rating'] === 'yes' && ! empty( $testvinder_data['rating'] ) ) : ?>
                    <div class="aebg-testvinder-rating">
                        <?php echo esc_html( $testvinder_data['rating'] ); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ( ! empty( $testvinder_data['description'] ) ) : ?>
                    <div class="aebg-testvinder-description">
                        <?php echo wp_kses_post( $testvinder_data['description'] ); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ( $settings['show_affiliate_link'] === 'yes' && ! empty( $affiliate_url ) ) : ?>
                    <div class="aebg-testvinder-actions">
                        <a href="<?php echo esc_url( $affiliate_url ); ?>" 
                           target="<?php echo esc_attr( $settings['affiliate_link_target'] ?? '_blank' ); ?>"
                           rel="noopener noreferrer"
                           class="aebg-testvinder-buy-button">
                            <?php echo esc_html( $settings['affiliate_link_text'] ?? esc_html__( 'Køb nu', 'aebg' ) ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get processed affiliate URL for testvinder product
     */
    protected function get_testvinder_affiliate_url( $testvinder_data ) {
        // Try to get affiliate URL from testvinder data
        $url = $testvinder_data['affiliate_url'] ?? $testvinder_data['url'] ?? '';
        
        if ( empty( $url ) ) {
            return '';
        }
        
        // Get merchant info for affiliate processing
        $merchant_name = $testvinder_data['merchant'] ?? '';
        $merchant_network = $testvinder_data['network'] ?? '';
        
        // Process affiliate link using Shortcodes class
        $shortcodes = new \AEBG\Core\Shortcodes();
        $affiliate_url = $shortcodes->get_affiliate_link_for_url( $url, $merchant_name, $merchant_network );
        
        return $affiliate_url;
    }
    
    protected function render() {
        $this->render_trigger();
        $this->render_modal_structure( [ $this, 'render_modal_content' ] );
    }
}
```

## Popup Templates Guide

### How to Use Products List Popup

1. **Create New Popup in Elementor Pro:**
   - Go to Templates → Popups → Add New
   - Name it "Products List Popup"

2. **Add WidgetProductList Widget:**
   - Drag `AEBG Product List` widget into the popup
   - Configure widget settings (limit, badges, etc.)
   - Affiliate links work automatically!

3. **Set Up Trigger:**
   - Go to Popup Settings → Triggers
   - Choose trigger type (Button Click, Page Load, etc.)
   - If using Button Click, add CSS class or ID to your button

4. **Style the Popup:**
   - Use Elementor's styling controls
   - Set width, animations, overlay, etc.

### How to Use Testvinder Popup

1. **Create New Popup in Elementor Pro:**
   - Go to Templates → Popups → Add New
   - Name it "Testvinder Popup"

2. **Add Widgets with Dynamic Tags:**
   - Drag `Heading` widget → Set Dynamic Content → Select "AEBG Testvinder Name"
   - Drag `Image` widget → Set Dynamic Content → Select "AEBG Testvinder Image"
   - Drag `Text` widget → Set Dynamic Content → Select "AEBG Testvinder Rating"
   - Drag `Button` widget → Set Link to Dynamic → Select "AEBG Testvinder Affiliate URL"
   - Configure testvinder number (1, 2, 3, etc.) in each Dynamic Tag's settings

3. **Style the Content:**
   - Use Elementor's styling controls on each widget
   - Full design flexibility!

4. **Set Up Trigger:**
   - Same as Products List Popup

## Helper Functions

**Purpose:** Widget that displays associated products list in a modal.

**Key Features:**
- Reads products from `_aebg_products` post meta
- Displays product list with images, names, prices
- Fully customizable via Elementor controls
- Supports filtering, sorting, pagination

**Implementation Highlights:**

```php
<?php
namespace Elementor;

use Elementor\Widget_Base;
use AEBG\Core\ProductManager;

class AEBG_Products_List_Modal extends AEBG_Modal_Base {
    
    public function get_name() {
        return 'aebg-products-list-modal';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Products List Modal', 'aebg' );
    }
    
    public function get_icon() {
        return 'eicon-product-list';
    }
    
    protected function register_controls() {
        parent::register_modal_controls();
        
        // Products list specific controls
        $this->start_controls_section(
            'section_products',
            [
                'label' => esc_html__( 'Products Settings', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'products_limit',
            [
                'label' => esc_html__( 'Products Limit', 'aebg' ),
                'type' => Controls_Manager::NUMBER,
                'default' => 0,
                'min' => 0,
                'description' => esc_html__( '0 = Show all products', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'show_product_images',
            [
                'label' => esc_html__( 'Show Product Images', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Show', 'aebg' ),
                'label_off' => esc_html__( 'Hide', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );
        
        $this->add_control(
            'show_prices',
            [
                'label' => esc_html__( 'Show Prices', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Show', 'aebg' ),
                'label_off' => esc_html__( 'Hide', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
            ]
        );
        
        $this->add_control(
            'layout',
            [
                'label' => esc_html__( 'Layout', 'aebg' ),
                'type' => Controls_Manager::SELECT,
                'default' => 'grid',
                'options' => [
                    'grid' => esc_html__( 'Grid', 'aebg' ),
                    'list' => esc_html__( 'List', 'aebg' ),
                    'table' => esc_html__( 'Table', 'aebg' ),
                ],
            ]
        );
        
        $this->add_control(
            'show_affiliate_links',
            [
                'label' => esc_html__( 'Show Affiliate Links', 'aebg' ),
                'type' => Controls_Manager::SWITCHER,
                'label_on' => esc_html__( 'Show', 'aebg' ),
                'label_off' => esc_html__( 'Hide', 'aebg' ),
                'return_value' => 'yes',
                'default' => 'yes',
                'description' => esc_html__( 'Display affiliate links for each product', 'aebg' ),
            ]
        );
        
        $this->add_control(
            'affiliate_link_text',
            [
                'label' => esc_html__( 'Affiliate Link Text', 'aebg' ),
                'type' => Controls_Manager::TEXT,
                'default' => esc_html__( 'Køb nu', 'aebg' ),
                'condition' => [
                    'show_affiliate_links' => 'yes',
                ],
            ]
        );
        
        $this->add_control(
            'affiliate_link_target',
            [
                'label' => esc_html__( 'Link Target', 'aebg' ),
                'type' => Controls_Manager::SELECT,
                'default' => '_blank',
                'options' => [
                    '_self' => esc_html__( 'Same Window', 'aebg' ),
                    '_blank' => esc_html__( 'New Window', 'aebg' ),
                ],
                'condition' => [
                    'show_affiliate_links' => 'yes',
                ],
            ]
        );
        
        $this->add_control(
            'affiliate_link_position',
            [
                'label' => esc_html__( 'Link Position', 'aebg' ),
                'type' => Controls_Manager::SELECT,
                'default' => 'below',
                'options' => [
                    'above' => esc_html__( 'Above Product Info', 'aebg' ),
                    'below' => esc_html__( 'Below Product Info', 'aebg' ),
                    'overlay' => esc_html__( 'Overlay on Image', 'aebg' ),
                ],
                'condition' => [
                    'show_affiliate_links' => 'yes',
                ],
            ]
        );
        
        // More controls...
        
        $this->end_controls_section();
    }
    
    protected function render_modal_header() {
        ?>
        <h3 class="aebg-modal-title">
            <?php echo esc_html__( 'Associated Products', 'aebg' ); ?>
        </h3>
        <?php
    }
    
    protected function render_modal_content() {
        $settings = $this->get_settings_for_display();
        $post_id = get_the_ID();
        
        if ( ! $post_id ) {
            if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                $post_id = \Elementor\Plugin::$instance->editor->get_post_id();
            }
        }
        
        if ( ! $post_id ) {
            echo '<p>' . esc_html__( 'No post ID found.', 'aebg' ) . '</p>';
            return;
        }
        
        // Get products
        $products = ProductManager::getPostProducts( $post_id );
        
        if ( empty( $products ) ) {
            echo '<p>' . esc_html__( 'No products found for this post.', 'aebg' ) . '</p>';
            return;
        }
        
        // Apply limit
        if ( ! empty( $settings['products_limit'] ) && $settings['products_limit'] > 0 ) {
            $products = array_slice( $products, 0, (int) $settings['products_limit'] );
        }
        
        // Render products
        $this->render_products_list( $products, $settings );
    }
    
    protected function render_products_list( $products, $settings ) {
        $layout = $settings['layout'] ?? 'grid';
        $classes = [
            'aebg-products-list',
            'aebg-products-list--' . esc_attr( $layout ),
        ];
        
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
            <?php foreach ( $products as $index => $product ) : ?>
                <?php $this->render_product_item( $product, $index, $settings ); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    protected function render_product_item( $product, $index, $settings ) {
        if ( ! is_array( $product ) || empty( $product ) ) {
            return;
        }
        
        $layout = $settings['layout'] ?? 'grid';
        $tag = $layout === 'table' ? 'tr' : 'div';
        
        // Get affiliate link
        $affiliate_url = '';
        if ( $settings['show_affiliate_links'] === 'yes' ) {
            $affiliate_url = $this->get_product_affiliate_url( $product );
        }
        
        ?>
        <<?php echo esc_attr( $tag ); ?> class="aebg-product-item">
            <div class="aebg-product-image-wrapper">
                <?php if ( $settings['show_product_images'] === 'yes' ) : ?>
                    <?php
                    $image_url = $product['featured_image_url'] ?? $product['image_url'] ?? '';
                    if ( ! empty( $image_url ) ) :
                    ?>
                        <div class="aebg-product-image">
                            <img src="<?php echo esc_url( $image_url ); ?>" 
                                 alt="<?php echo esc_attr( $product['name'] ?? '' ); ?>" />
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ( $settings['show_affiliate_links'] === 'yes' && 
                           ! empty( $affiliate_url ) && 
                           $settings['affiliate_link_position'] === 'overlay' ) : ?>
                    <div class="aebg-product-link-overlay">
                        <a href="<?php echo esc_url( $affiliate_url ); ?>" 
                           target="<?php echo esc_attr( $settings['affiliate_link_target'] ?? '_blank' ); ?>"
                           rel="noopener noreferrer"
                           class="aebg-product-buy-button">
                            <?php echo esc_html( $settings['affiliate_link_text'] ?? esc_html__( 'Køb nu', 'aebg' ) ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="aebg-product-info">
                <?php if ( $settings['show_affiliate_links'] === 'yes' && 
                           ! empty( $affiliate_url ) && 
                           $settings['affiliate_link_position'] === 'above' ) : ?>
                    <div class="aebg-product-actions">
                        <a href="<?php echo esc_url( $affiliate_url ); ?>" 
                           target="<?php echo esc_attr( $settings['affiliate_link_target'] ?? '_blank' ); ?>"
                           rel="noopener noreferrer"
                           class="aebg-product-buy-button">
                            <?php echo esc_html( $settings['affiliate_link_text'] ?? esc_html__( 'Køb nu', 'aebg' ) ); ?>
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if ( ! empty( $product['name'] ) ) : ?>
                    <h4 class="aebg-product-name">
                        <?php echo esc_html( $product['name'] ); ?>
                    </h4>
                <?php endif; ?>
                
                <?php if ( $settings['show_prices'] === 'yes' && ! empty( $product['price'] ) ) : ?>
                    <div class="aebg-product-price">
                        <?php
                        $currency = $product['currency'] ?? 'DKK';
                        echo esc_html( ProductManager::formatPrice( $product['price'], $currency ) );
                        ?>
                    </div>
                <?php endif; ?>
                
                <?php if ( $settings['show_affiliate_links'] === 'yes' && 
                           ! empty( $affiliate_url ) && 
                           $settings['affiliate_link_position'] === 'below' ) : ?>
                    <div class="aebg-product-actions">
                        <a href="<?php echo esc_url( $affiliate_url ); ?>" 
                           target="<?php echo esc_attr( $settings['affiliate_link_target'] ?? '_blank' ); ?>"
                           rel="noopener noreferrer"
                           class="aebg-product-buy-button">
                            <?php echo esc_html( $settings['affiliate_link_text'] ?? esc_html__( 'Køb nu', 'aebg' ) ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </<?php echo esc_attr( $tag ); ?>>
        <?php
    }
    
    /**
     * Get processed affiliate URL for product
     */
    protected function get_product_affiliate_url( $product ) {
        // Try to get affiliate URL from product data
        $url = $product['affiliate_url'] ?? $product['url'] ?? '';
        
        if ( empty( $url ) ) {
            return '';
        }
        
        // Get merchant info for affiliate processing
        $merchant_name = $product['merchant'] ?? '';
        $merchant_network = $product['network'] ?? '';
        
        // Process affiliate link using Shortcodes class
        $shortcodes = new \AEBG\Core\Shortcodes();
        $affiliate_url = $shortcodes->get_affiliate_link_for_url( $url, $merchant_name, $merchant_network );
        
        return $affiliate_url;
    }
    
    protected function render() {
        $this->render_trigger();
        $this->render_modal_structure( [ $this, 'render_modal_content' ] );
    }
}
```

### Optional: CSS Enhancements

**File:** `assets/css/elementor-popups.css` (optional)

If you want custom styling for popup content, you can add CSS here. Most styling should be done via Elementor controls, but this is for any custom enhancements needed.

**Purpose:** Handle modal open/close, event management, accessibility.

**Key Features:**
- Event-driven architecture
- Accessibility (ARIA, keyboard navigation)
- Animation support
- Multiple modal support
- Escape key handling
- Overlay click handling

**Implementation:**

```javascript
/**
 * AEBG Elementor Modals Manager
 * Handles modal functionality for Elementor widgets
 */
(function($) {
    'use strict';
    
    /**
     * Modal Manager Class
     */
    class AEBGModalManager {
        constructor() {
            this.modals = new Map();
            this.activeModal = null;
            this.init();
        }
        
        init() {
            // Initialize on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.setup());
            } else {
                this.setup();
            }
        }
        
        setup() {
            // Find all modals
            const modalElements = document.querySelectorAll('.aebg-modal');
            modalElements.forEach(modal => {
                const modalId = modal.id;
                if (modalId) {
                    this.registerModal(modalId, modal);
                }
            });
            
            // Setup triggers
            this.setupTriggers();
            
            // Setup close handlers
            this.setupCloseHandlers();
            
            // Setup keyboard handlers
            this.setupKeyboardHandlers();
        }
        
        registerModal(modalId, element) {
            const config = {
                element: element,
                overlay: element.querySelector('.aebg-modal-overlay'),
                container: element.querySelector('.aebg-modal-container'),
                closeButton: element.querySelector('.aebg-modal-close'),
                closeOnOverlay: element.dataset.closeOnOverlay === 'yes',
                closeOnEscape: element.dataset.closeOnEscape === 'yes',
            };
            
            this.modals.set(modalId, config);
        }
        
        setupTriggers() {
            // Handle trigger clicks
            $(document).on('click', '.aebg-modal-trigger', (e) => {
                e.preventDefault();
                const modalId = $(e.currentTarget).data('modal-id');
                if (modalId) {
                    this.openModal(modalId);
                }
            });
            
            // Handle custom selectors
            $(document).on('click', '[data-aebg-modal]', (e) => {
                e.preventDefault();
                const modalId = $(e.currentTarget).data('aebg-modal');
                if (modalId) {
                    this.openModal(modalId);
                }
            });
        }
        
        setupCloseHandlers() {
            // Close button
            $(document).on('click', '.aebg-modal-close', (e) => {
                e.preventDefault();
                const modal = $(e.currentTarget).closest('.aebg-modal');
                if (modal.length) {
                    this.closeModal(modal.attr('id'));
                }
            });
            
            // Overlay click
            $(document).on('click', '.aebg-modal-overlay', (e) => {
                const modal = $(e.currentTarget).closest('.aebg-modal');
                if (modal.length) {
                    const config = this.modals.get(modal.attr('id'));
                    if (config && config.closeOnOverlay) {
                        this.closeModal(modal.attr('id'));
                    }
                }
            });
        }
        
        setupKeyboardHandlers() {
            $(document).on('keydown', (e) => {
                if (e.key === 'Escape' && this.activeModal) {
                    const config = this.modals.get(this.activeModal);
                    if (config && config.closeOnEscape) {
                        this.closeModal(this.activeModal);
                    }
                }
            });
        }
        
        openModal(modalId) {
            const config = this.modals.get(modalId);
            if (!config) {
                console.warn('AEBG Modal: Modal not found:', modalId);
                return;
            }
            
            // Close any active modal first
            if (this.activeModal) {
                this.closeModal(this.activeModal, false);
            }
            
            // Set active
            this.activeModal = modalId;
            
            // Show modal
            $(config.element).addClass('aebg-modal-active');
            $('body').addClass('aebg-modal-open');
            
            // Focus management
            this.focusModal(config);
            
            // Trigger event
            $(document).trigger('aebg:modal:opened', [modalId, config]);
        }
        
        closeModal(modalId, triggerEvent = true) {
            const config = this.modals.get(modalId);
            if (!config) {
                return;
            }
            
            // Hide modal
            $(config.element).removeClass('aebg-modal-active');
            
            // Remove body class if no other modals are open
            if (!document.querySelector('.aebg-modal-active')) {
                $('body').removeClass('aebg-modal-open');
            }
            
            // Clear active
            if (this.activeModal === modalId) {
                this.activeModal = null;
            }
            
            // Return focus
            this.returnFocus();
            
            // Trigger event
            if (triggerEvent) {
                $(document).trigger('aebg:modal:closed', [modalId, config]);
            }
        }
        
        focusModal(config) {
            // Focus first focusable element in modal
            const focusable = config.container.querySelector(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable) {
                focusable.focus();
            } else {
                config.container.focus();
            }
        }
        
        returnFocus() {
            // Return focus to trigger element
            const trigger = document.querySelector('.aebg-modal-trigger[data-modal-id="' + this.activeModal + '"]');
            if (trigger) {
                trigger.focus();
            }
        }
    }
    
    // Initialize on load
    window.AEBGModals = new AEBGModalManager();
    
})(jQuery);
```

### Step 5: Create Minimal CSS

**File:** `assets/css/elementor-modals.css`

**Purpose:** Minimal CSS for modal functionality. Styling handled by Elementor.

**Implementation:**

```css
/**
 * AEBG Elementor Modals - Base Styles
 * Minimal CSS - Most styling handled by Elementor controls
 */

/* Modal Base */
.aebg-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 999999;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.aebg-modal.aebg-modal-active {
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Body lock when modal open */
body.aebg-modal-open {
    overflow: hidden;
}

/* Overlay */
.aebg-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1;
}

/* Modal Container */
.aebg-modal-container {
    position: relative;
    z-index: 2;
    background: #fff;
    border-radius: 8px;
    max-width: 90%;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

/* Modal Sizes */
.aebg-modal-container.aebg-modal-size-small {
    width: 400px;
}

.aebg-modal-container.aebg-modal-size-medium {
    width: 600px;
}

.aebg-modal-container.aebg-modal-size-large {
    width: 900px;
}

.aebg-modal-container.aebg-modal-size-full {
    width: 95%;
    height: 95vh;
}

/* Modal Header */
.aebg-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid #e0e0e0;
}

.aebg-modal-title {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
}

/* Close Button */
.aebg-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    transition: color 0.2s;
}

.aebg-modal-close:hover {
    color: #000;
}

/* Modal Body */
.aebg-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

/* Modal Footer */
.aebg-modal-footer {
    padding: 20px;
    border-top: 1px solid #e0e0e0;
}

/* Trigger */
.aebg-modal-trigger {
    cursor: pointer;
}

/* Responsive */
@media (max-width: 768px) {
    .aebg-modal-container {
        max-width: 95%;
        max-height: 95vh;
    }
    
    .aebg-modal-container.aebg-modal-size-small,
    .aebg-modal-container.aebg-modal-size-medium,
    .aebg-modal-container.aebg-modal-size-large {
        width: 95%;
    }
}
```

### Register Dynamic Tags

**File:** `ai-bulk-generator-for-elementor.php` or `src/Plugin.php`

**Implementation:**

```php
// Register Dynamic Tags
add_action( 'elementor/dynamic_tags/register_tags', function( $dynamic_tags_manager ) {
    // Testvinder Dynamic Tags
    $tags = [
        'TestvinderName',
        'TestvinderImage',
        'TestvinderRating',
        'TestvinderPrice',
        'TestvinderAffiliateUrl',
        'ProductName',
        'ProductImage',
        'ProductPrice',
        'ProductAffiliateUrl',
    ];
    
    foreach ( $tags as $tag_name ) {
        $file = __DIR__ . '/src/Elementor/DynamicTags/' . $tag_name . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
            $class_name = 'AEBG\\Elementor\\DynamicTags\\' . $tag_name;
            if ( class_exists( $class_name ) ) {
                $dynamic_tags_manager->register( new $class_name() );
            }
        }
    }
} );

// Optional: Enqueue popup CSS if needed
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'aebg-elementor-popups',
        plugin_dir_url(__FILE__) . 'assets/css/elementor-popups.css',
        [],
        '1.0.0'
    );
});
```

---

## Security & Performance

### Security Measures

1. **Data Sanitization**
   - All user inputs sanitized via `sanitize_text_field()`, `esc_html()`, `esc_url()`
   - SQL queries use prepared statements (via WordPress functions)
   - JSON data validated before use
   - Affiliate URLs validated and sanitized before output
   - `rel="noopener noreferrer"` added to external links for security

2. **Nonce Verification**
   - AJAX requests include nonce verification
   - Form submissions verified

3. **Capability Checks**
   - Admin functions check user capabilities
   - Frontend functions check read permissions

4. **XSS Prevention**
   - All output escaped
   - No `eval()` or `innerHTML` with user data
   - Content Security Policy compatible

### Performance Optimizations

1. **Lazy Loading**
   - Modal content loaded on open (optional)
   - Images use `loading="lazy"`

2. **Caching**
   - Product data cached via WordPress transients
   - Elementor data cached by Elementor

3. **Minimal JavaScript**
   - Vanilla JS where possible
   - jQuery only for compatibility
   - No heavy libraries

4. **CSS Optimization**
   - Minimal CSS (Elementor handles styling)
   - No unused styles
   - Mobile-first approach

5. **Database Queries**
   - Use `ProductManager::getPostProducts()` (already optimized)
   - Cache testvinder data lookups
   - Limit queries in loops

---

## Testing Strategy

### Unit Tests

**Test Cases:**
1. Widget registration
2. Modal open/close functionality
3. Data fetching (testvinder, products)
4. Edge cases (no data, invalid IDs)

### Integration Tests

**Test Cases:**
1. Widget in Elementor editor
2. Modal on frontend
3. Multiple modals on same page
4. Responsive behavior
5. Accessibility (keyboard navigation, screen readers)

### Manual Testing Checklist

**Testvinder Popup:**
- [ ] Dynamic Tags appear in Elementor Dynamic Content dropdown
- [ ] Testvinder Name Dynamic Tag works in Heading/Text widgets
- [ ] Testvinder Image Dynamic Tag works in Image widget
- [ ] Testvinder Rating Dynamic Tag works in Text widget
- [ ] Testvinder Price Dynamic Tag works in Text widget
- [ ] Testvinder Affiliate URL Dynamic Tag works in Button/Link widgets
- [ ] Works with testvinder-1, testvinder-2, etc. (via Dynamic Tag settings)
- [ ] Handles missing testvinder gracefully
- [ ] Affiliate link is processed correctly (@@@ replaced)
- [ ] "Buy Now" button uses Dynamic Tag and links correctly
- [ ] Affiliate link opens in correct target window
- [ ] Popup opens/closes via Elementor Pro triggers
- [ ] Responsive on mobile
- [ ] Accessible (keyboard, screen reader - handled by Elementor Pro)

**Products List Popup:**
- [ ] `WidgetProductList` can be added to Elementor Pro Popup
- [ ] Products list displays correctly
- [ ] Product limit works (widget setting)
- [ ] Layout options work (widget setting)
- [ ] Affiliate links work automatically (already in widget)
- [ ] Handles empty products list
- [ ] Popup opens/closes via Elementor Pro triggers
- [ ] Responsive on mobile
- [ ] Accessible (keyboard, screen reader - handled by Elementor Pro)

**Cross-Browser:**
- [ ] Chrome/Edge
- [ ] Firefox
- [ ] Safari
- [ ] Mobile browsers

---

## Rollout Plan

### Phase 1: Development (Week 1)

**Tasks:**
1. Create `TestvinderHelper.php` and `ProductHelper.php` classes
2. Create custom Dynamic Tags (TestvinderName, TestvinderImage, TestvinderAffiliateUrl, etc.)
3. Register Dynamic Tags with Elementor
4. Create popup templates (optional - can be done manually)
5. Create setup documentation
6. Test Dynamic Tags in Elementor Pro Popup Builder

**Deliverable:** Working helper classes and Dynamic Tags, ready to use in popups

### Phase 2: Testing (Week 2)

**Tasks:**
1. Test testvinder shortcode in popups
2. Test WidgetProductList in popups
3. Test affiliate links work correctly
4. Test responsive behavior
5. Create example popups

**Deliverable:** Tested and documented popup setup

### Phase 3: Documentation & Polish (Week 2-3)

**Tasks:**
1. Complete setup guide
2. Create example popup templates
3. Document best practices
4. Optional CSS enhancements

**Deliverable:** Complete documentation and examples

### Phase 4: Production (Week 3)

**Tasks:**
1. Deploy helper class and shortcode
2. Share documentation with team
3. Monitor for issues
4. Gather feedback

**Deliverable:** Live helper functions ready for popup use

---

## Success Criteria

### Functional Requirements

✅ **Testvinder Popup:**
- Shortcode `[aebg_testvinder]` works in Elementor Pro Popups
- Testvinder data displays correctly
- Affiliate links are processed and displayed correctly
- "Buy Now" button with affiliate link works
- Responsive on all devices
- Accessible (WCAG 2.1 AA via Elementor Pro)

✅ **Products List Popup:**
- Existing `WidgetProductList` works in Elementor Pro Popups
- Products list displays correctly
- Affiliate links work automatically (already implemented in widget)
- All Elementor styling controls work
- Responsive on all devices
- Accessible (WCAG 2.1 AA via Elementor Pro)

### Performance Requirements

✅ Modal open/close: < 100ms  
✅ No layout shift (CLS = 0)  
✅ Memory footprint: < 5MB  
✅ Works on mobile devices

### Quality Requirements

✅ No JavaScript errors  
✅ No PHP errors/warnings  
✅ Valid HTML/CSS  
✅ Cross-browser compatible  
✅ Accessible

---

## Affiliate Link Integration

### Critical Implementation Details

**Affiliate Link Processing:**
- Both modals use `Shortcodes::get_affiliate_link_for_url()` method
- This method handles:
  - Network detection from URL
  - Merchant network lookup
  - `@@@` placeholder replacement with actual affiliate ID
  - Fallback to merchant name mapping
- Affiliate links are processed server-side before rendering
- Links include `rel="noopener noreferrer"` for security

**Data Sources:**
- Products have `affiliate_url` field (may contain `@@@`)
- Products have `url` field (base URL fallback)
- Products have `merchant` and `network` fields for processing
- Testvinder data extracted from Elementor containers includes these fields

**User Controls:**
- Show/hide affiliate links toggle
- Customizable link text (default: "Køb nu")
- Link target option (same window / new window)
- Link position for products list (above, below, overlay)

**Error Handling:**
- If affiliate ID not found, URL returned with `@@@` placeholder (visible error)
- If no URL available, link not displayed
- Graceful degradation if affiliate processing fails

### Affiliate Link Flow

```
Product Data
    ↓
Extract URL (affiliate_url or url)
    ↓
Extract Merchant & Network Info
    ↓
Shortcodes::get_affiliate_link_for_url()
    ↓
Network Detection / Lookup
    ↓
Replace @@@ with Affiliate ID
    ↓
Return Processed URL
    ↓
Render in Modal
```

## Additional Considerations

### Future Enhancements

1. **Animation Options**
   - Fade, slide, zoom animations
   - Custom animation controls

2. **Advanced Features**
   - Modal stacking (multiple modals)
   - Modal history (back button support)
   - URL-based modal opening
   - Affiliate link click tracking
   - A/B testing for link text/position

3. **Integration**
   - WooCommerce integration
   - Other product sources
   - Custom data sources
   - Analytics integration for affiliate clicks

### Maintenance

1. **Version Control**
   - Semantic versioning
   - Changelog maintenance

2. **Documentation**
   - User guide
   - Developer documentation
   - Code comments

3. **Support**
   - Bug tracking
   - Feature requests
   - User feedback

---

## Conclusion

This implementation plan provides a production-ready, bulletproof approach to creating popups for testvinder and associated products using **Elementor Pro Popup Builder**. The architecture is:

- **Simple** - Minimal custom code, leverages native Elementor Pro features
- **Maintainable** - Reuses existing widgets, no duplicate code
- **Flexible** - Full Elementor editor control, any widgets can be used
- **Performant** - Native Elementor Pro performance optimizations
- **Accessible** - WCAG 2.1 AA compliant (via Elementor Pro)
- **Secure** - Proper sanitization and validation in helper functions
- **Cost-Effective** - Leverages existing Elementor Pro investment

### Key Advantages of This Approach:

1. **No Custom Widgets Needed** - Just use existing `WidgetProductList`
2. **Native Popup System** - Full access to Elementor Pro Popup Builder features
3. **Easy to Use** - Create popups visually in Elementor editor
4. **Affiliate Links Work Automatically** - Already implemented in widgets
5. **Future-Proof** - Updates to Elementor Pro automatically benefit your popups

The implementation follows existing codebase patterns and integrates seamlessly with the AEBG ecosystem while keeping the codebase clean and maintainable.

---

**Document Version:** 2.0.0 (Revised for Elementor Pro Popup Builder)  
**Last Updated:** 2024  
**Author:** AI Assistant  
**Status:** Ready for Review

