# AEBG Bulk Generator - Complete Variables Reference

This document provides a comprehensive list of all available variables in the AEBG Bulk Generator system and how to use them correctly.

## Table of Contents
1. [Basic Variables](#basic-variables)
2. [Product Variables](#product-variables)
3. [Product Image Variables](#product-image-variables)
4. [Product Link Variables](#product-link-variables)
5. [Context Variables](#context-variables)
6. [Usage Guidelines](#usage-guidelines)

---

## Basic Variables

These variables are always available and don't require any product data.

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{title}` | Post title | "Best Laptops 2025" |
| `{year}` | Current year | "2025" |
| `{date}` | Current date (YYYY-MM-DD format) | "2025-01-15" |
| `{time}` | Current time (HH:MM:SS format) | "14:30:45" |

**Usage Example:**
```
Create a review for {title} published on {date} in {year}.
```

---

## Product Variables

Product variables are available for each product in your selection. The system supports multiple products (product-1, product-2, product-3, etc.). All product variables follow the pattern `{product-N-property}` where N is the product number.

### Core Product Variables

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{product-1}` | First product name (uses short_name if available, otherwise name) | "MacBook Pro 16-inch" |
| `{product-1-name}` | First product name (explicit, same as {product-1}) | "MacBook Pro 16-inch" |
| `{product-1-price}` | First product price | "2,499.00" |
| `{product-1-brand}` | First product brand | "Apple" |
| `{product-1-rating}` | First product rating | "4.5" |
| `{product-1-reviews}` | First product review count | "1,234" |
| `{product-1-description}` | First product description | "Powerful laptop with M3 chip..." |
| `{product-1-merchant}` | First product merchant name | "Amazon" |
| `{product-1-category}` | First product category | "Electronics > Computers" |
| `{product-1-availability}` | First product availability status | "In Stock" |
| `{product-1-features}` | First product features (comma-separated) | "16GB RAM, 512GB SSD, M3 Chip" |
| `{product-1-specs}` | First product specifications (comma-separated) | "16-inch display, 3456x2234 resolution" |
| `{product-1-justification}` | First product selection justification | "Best value for money" |

### Additional Product Variables

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{product-1-link}` | First product link (uses selected merchant) | "https://example.com/product" |
| `{product-1-comparison}` | First product price comparison table HTML | HTML table with merchant comparisons |

**Note:** All these variables are available for product-2, product-3, product-4, etc. Just replace `1` with the product number.

**Usage Example:**
```
Compare {product-1} (${product-1-price}) with {product-2} (${product-2-price}).
{product-1} has a rating of {product-1-rating} with {product-1-reviews} reviews.
```

---

## Product Image Variables

These variables provide access to product images in various formats.

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{product-1-image}` | First product image URL (simplified - most commonly used) | "https://example.com/image.jpg" |
| `{product-1-featured-image}` | First product featured image URL | "https://example.com/featured.jpg" |
| `{product-1-featured-image-id}` | First product featured image attachment ID | "12345" |
| `{product-1-featured-image-html}` | First product featured image HTML tag | `<img src="..." alt="..." />` |
| `{product-1-gallery-images}` | First product gallery image URLs (comma-separated) | "url1.jpg,url2.jpg,url3.jpg" |
| `{product-1-gallery-images-ids}` | First product gallery image attachment IDs (comma-separated) | "123,124,125" |
| `{product-1-gallery-images-html}` | First product gallery images HTML tags | `<img ... /><img ... />` |

**Note:** All these variables are available for product-2, product-3, etc.

**Usage Example:**
```
The {product-1} image: {product-1-image}
Featured image: {product-1-featured-image-html}
```

---

## Product Link Variables

These variables provide different types of product URLs.

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{product-1-url}` | First product URL (direct link) | "https://merchant.com/product" |
| `{product-1-affiliate-url}` | First product affiliate URL (with tracking) | "https://affiliate.com/track?id=123" |

**Note:** All these variables are available for product-2, product-3, etc.

**Usage Example:**
```
Buy {product-1} here: {product-1-affiliate-url}
```

---

## Context Variables

Context variables are extracted from the post title using AI analysis or set during the generation process.

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{category}` | Extracted category from title | "Laptops" |
| `{attributes}` | Extracted attributes from title (comma-separated) | "gaming, RGB, mechanical" |
| `{target_audience}` | Target audience | "professionals, students" |
| `{content_type}` | Content type | "review, comparison, guide" |
| `{key_topics}` | Key topics (comma-separated) | "performance, battery life, design" |
| `{search_keywords}` | Search keywords used (comma-separated) | "laptop, computer, notebook" |

**Additional Context Variables** (may be available depending on generation context):

| Variable | Description | Example Output |
|----------|-------------|----------------|
| `{actual_category}` | Actual category from products | "Electronics > Computers" |
| `{actual_brands}` | Actual brands found in products | "Apple, Dell, HP" |
| `{actual_features}` | Actual features found in products | "16GB RAM, SSD, Touchscreen" |
| `{actual_price_range}` | Actual price range from products | "Min: 500, Max: 3000" |
| `{actual_product_count}` | Number of products found | "7" |
| `{actual_merchants}` | Merchants found in products | "Amazon, Best Buy, Newegg" |
| `{actual_avg_rating}` | Average rating across all products | "4.3" |
| `{actual_total_reviews}` | Total reviews across all products | "15,234" |

**Usage Example:**
```
This {content_type} about {category} targets {target_audience}.
Key topics include: {key_topics}
```

---

## Usage Guidelines

### 1. Variable Syntax
- Variables must be enclosed in curly braces: `{variable_name}`
- Variable names are case-sensitive
- Use hyphens to separate words: `{product-1-price}` (not `{product_1_price}`)

### 2. Product Numbering
- Products are numbered starting from 1: `{product-1}`, `{product-2}`, `{product-3}`, etc.
- The system supports unlimited products, but typically you'll use product-1 through product-10

### 3. Context Variables
- Context variables are populated automatically during title analysis
- If a context variable is not available, it will be replaced with an empty string
- Context variables can reference each other in Elementor templates using `{field_name}` syntax

### 4. Variable Replacement
- Variables are replaced during content generation
- If a variable cannot be resolved, it may be left as-is or replaced with an empty string
- Arrays (like features, specs) are automatically converted to comma-separated strings

### 5. Best Practices
- Always test your prompts with actual product data
- Use `{product-1}` for the main product name (it uses optimized short_name)
- Use `{product-1-image}` for the most common image URL
- Use `{product-1-affiliate-url}` for affiliate links with tracking
- Context variables work best when title analysis is enabled

### 6. Common Patterns

**Product Comparison:**
```
Compare {product-1} ({product-1-price}) with {product-2} ({product-2-price}).
{product-1} features: {product-1-features}
{product-2} features: {product-2-features}
```

**Product Review:**
```
Review of {product-1} for {target_audience}.
Rating: {product-1-rating}/5 with {product-1-reviews} reviews.
Price: {product-1-price}
```

**Category Guide:**
```
Best {category} for {target_audience} in {year}.
Key topics: {key_topics}
```

---

## Implementation Details

### Variable Processing
Variables are processed by the `Variables` class (`src/Core/Variables.php`) and `VariableReplacer` class (`src/Core/VariableReplacer.php`).

### Variable Replacement Order
1. Basic variables (`{title}`, `{year}`, `{date}`, `{time}`)
2. Product variables (processed for each product)
3. Context variables (from additional_context array)

### Getting Available Variables Programmatically
```php
$variables = new \AEBG\Core\Variables();
$available = $variables->getAvailableVariables();
```

This returns an array organized by category:
- `basic` - Basic variables
- `products` - Product variables
- `product_images` - Product image variables
- `product_links` - Product link variables
- `context` - Context variables

---

## Differences from UI Display

**Note:** The UI in the generator page (`generator-page.php`) shows a simplified list of variables. The actual system supports many more variables than displayed in the UI. This document lists ALL available variables.

**Variables shown in UI but missing details:**
- `{product-2-features}` - Shown in UI but many other product-2 variables are also available
- Context variables are shown but some additional context variables may be available

**Variables available in system but not shown in UI:**
- `{product-1-reviews}`, `{product-1-description}`, `{product-1-merchant}`, etc.
- All product image variables
- `{product-1-link}`, `{product-1-comparison}`
- `{search_keywords}` and other additional context variables

---

## Troubleshooting

### Variable Not Replacing
1. Check spelling and syntax (must use curly braces)
2. Ensure product data is available for product variables
3. Check if context variables are populated (requires title analysis)
4. Verify variable name matches exactly (case-sensitive)

### Empty Variable Output
- Product variables may be empty if product data doesn't contain that field
- Context variables may be empty if title analysis hasn't run
- Check product data structure to ensure fields exist

### Multiple Products
- Use `{product-1}`, `{product-2}`, etc. for different products
- Each product number has access to all the same variable types
- Product numbering starts at 1, not 0

---

## Version Information
- **Last Updated:** 2025-01-15
- **System Version:** AEBG Bulk Generator
- **Variable System Version:** 2.0

