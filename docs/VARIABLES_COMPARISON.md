# Variables Comparison: UI Display vs. Actual System

This document compares what variables are shown in the UI (as seen in the image) versus what's actually available in the AEBG Bulk Generator system.

## Summary

**The UI shows a simplified subset of available variables.** The actual system supports **many more variables** than what's displayed in the generator page interface.

---

## Basic Variables

### ✅ Shown in UI
- `{title}` - Post title
- `{year}` - Current year
- `{date}` - Current date
- `{time}` - Current time

### ✅ Available in System
**All basic variables are shown correctly.** No missing variables.

---

## Product Variables

### Shown in UI (Limited)
- `{product-1}` - First product name
- `{product-1-price}` - First product price
- `{product-1-brand}` - First product brand
- `{product-1-rating}` - First product rating
- `{product-2}` - Second product name
- `{product-2-features}` - Second product features

### ✅ Actually Available in System (Complete List)

**Core Product Variables:**
- `{product-1}` - First product name ✅ (shown)
- `{product-1-name}` - First product name (explicit) ❌ (not shown)
- `{product-1-price}` - First product price ✅ (shown)
- `{product-1-brand}` - First product brand ✅ (shown)
- `{product-1-rating}` - First product rating ✅ (shown)
- `{product-1-reviews}` - First product review count ❌ (not shown)
- `{product-1-description}` - First product description ❌ (not shown)
- `{product-1-url}` - First product URL ❌ (not shown)
- `{product-1-affiliate-url}` - First product affiliate URL ❌ (not shown)
- `{product-1-merchant}` - First product merchant ❌ (not shown)
- `{product-1-category}` - First product category ❌ (not shown)
- `{product-1-availability}` - First product availability ❌ (not shown)
- `{product-1-features}` - First product features ❌ (not shown, but product-2-features is)
- `{product-1-specs}` - First product specifications ❌ (not shown)
- `{product-1-justification}` - First product selection justification ❌ (not shown)
- `{product-1-link}` - First product link ❌ (not shown)
- `{product-1-comparison}` - First product comparison table ❌ (not shown)

**Product-2 Variables:**
- `{product-2}` - Second product name ✅ (shown)
- `{product-2-features}` - Second product features ✅ (shown)
- **All other product-1 variables are also available for product-2** ❌ (not shown)

**Product Image Variables (Not Shown at All):**
- `{product-1-image}` - First product image URL ❌ (not shown)
- `{product-1-featured-image}` - First product featured image URL ❌ (not shown)
- `{product-1-featured-image-id}` - First product featured image attachment ID ❌ (not shown)
- `{product-1-featured-image-html}` - First product featured image HTML tag ❌ (not shown)
- `{product-1-gallery-images}` - First product gallery image URLs ❌ (not shown)
- `{product-1-gallery-images-ids}` - First product gallery image attachment IDs ❌ (not shown)
- `{product-1-gallery-images-html}` - First product gallery images HTML tags ❌ (not shown)

**Product Link Variables (Not Shown at All):**
- `{product-1-url}` - First product URL ❌ (not shown)
- `{product-1-affiliate-url}` - First product affiliate URL ❌ (not shown)

**Note:** All product variables work for product-3, product-4, product-5, etc. (unlimited products)

---

## Context Variables

### Shown in UI
- `{category}` - Extracted category
- `{attributes}` - Extracted attributes
- `{target_audience}` - Target audience
- `{content_type}` - Content type
- `{key_topics}` - Key topics

### ✅ Available in System
- `{category}` - Extracted category from title ✅ (shown)
- `{attributes}` - Extracted attributes from title ✅ (shown)
- `{target_audience}` - Target audience ✅ (shown)
- `{content_type}` - Content type ✅ (shown)
- `{key_topics}` - Key topics ✅ (shown)
- `{search_keywords}` - Search keywords used ❌ (not shown)

**Additional Context Variables (May be available):**
- `{actual_category}` - Actual category from products ❌ (not shown)
- `{actual_brands}` - Actual brands found in products ❌ (not shown)
- `{actual_features}` - Actual features found in products ❌ (not shown)
- `{actual_price_range}` - Actual price range from products ❌ (not shown)
- `{actual_product_count}` - Number of products found ❌ (not shown)
- `{actual_merchants}` - Merchants found in products ❌ (not shown)
- `{actual_avg_rating}` - Average rating across all products ❌ (not shown)
- `{actual_total_reviews}` - Total reviews across all products ❌ (not shown)

---

## Key Findings

### What's Missing from UI Display

1. **Product Variables:**
   - Missing: `{product-1-reviews}`, `{product-1-description}`, `{product-1-url}`, `{product-1-merchant}`, `{product-1-category}`, `{product-1-availability}`, `{product-1-specs}`, `{product-1-justification}`, `{product-1-link}`, `{product-1-comparison}`
   - Missing: `{product-1-features}` (only product-2-features is shown)
   - Missing: Most product-2 variables (only name and features are shown)

2. **Product Image Variables:**
   - **Completely missing** from UI - no image variables are shown at all
   - Available: `{product-1-image}`, `{product-1-featured-image}`, `{product-1-featured-image-id}`, `{product-1-featured-image-html}`, `{product-1-gallery-images}`, etc.

3. **Product Link Variables:**
   - **Completely missing** from UI
   - Available: `{product-1-url}`, `{product-1-affiliate-url}`

4. **Context Variables:**
   - Missing: `{search_keywords}`
   - Missing: Additional context variables like `{actual_category}`, `{actual_brands}`, etc.

### Recommendations

1. **Update UI to show more variables** - The current UI is too limited and doesn't show users what's actually available
2. **Add product image variables** - These are completely missing from the UI
3. **Add product link variables** - Important for affiliate links
4. **Show complete product-2 variables** - Currently only shows name and features
5. **Add search_keywords context variable** - It's available but not shown

---

## Complete Variable Count

- **Shown in UI:** ~11 variables
- **Actually Available:** ~50+ variables
- **Missing from UI:** ~40+ variables

---

## Reference Files

- **Variable Implementation:** `src/Core/Variables.php`
- **Variable Replacer:** `src/Core/VariableReplacer.php`
- **UI Display:** `src/Admin/views/generator-page.php` (lines 486-516)
- **Complete Documentation:** `docs/VARIABLES_REFERENCE.md`

---

## How to Use All Available Variables

See the complete reference guide: `docs/VARIABLES_REFERENCE.md`

All variables follow the same syntax pattern:
- Basic: `{variable_name}`
- Product: `{product-N-property}` where N is the product number
- Context: `{context_key}`

Variables are automatically replaced during content generation.

