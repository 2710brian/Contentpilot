# Datafeedr API Call Analysis

## Summary
This analysis identifies potential unnecessary Datafeedr API calls that could be optimized to reduce API usage and improve performance.

---

## 🔴 Critical Issues (High Priority)

### 1. **VariableReplacer - Redundant Product Lookups**
**Location:** `src/Core/VariableReplacer.php` (lines 177, 218)

**Issue:** Makes API calls to fetch product details (image/URL) even when product data may already be available in the product array.

```php
// Line 177 - getProductImage()
$product_details = $datafeedr->search('id:' . $product['id'], 1);

// Line 218 - getProductUrl()  
$product_details = $datafeedr->search('id:' . $product['id'], 1);
```

**Impact:** 
- Each product image/URL lookup triggers a separate API call
- If called multiple times for the same product, this wastes API calls
- The product data might already contain `image_url` or `url` fields

**Recommendation:**
- Check if `$product` already has `image_url`/`image` or `url` fields before making API call
- Add request-level caching for product lookups by ID
- Consider using `get_product_data_from_database()` first (which uses database cache)

---

### 2. **get_merchant_comparison() - No Caching**
**Location:** `src/Core/Datafeedr.php` (line 2959)

**Issue:** Comment says "Always fetch fresh data from API - no caching" (line 2975), but this method is called frequently during content generation and frontend display.

**Impact:**
- Every merchant comparison request makes a fresh API call
- Same product comparison requested multiple times = multiple API calls
- No transient or database caching mechanism

**Current Flow:**
1. `get_merchant_comparison()` → Always calls API
2. Results are saved to database via `ComparisonManager::save_comparison()` (line 3097)
3. But the method doesn't check database first before making API call

**Recommendation:**
- Check database/comparison cache FIRST before making API call
- Use the same pattern as `ajax_get_merchant_counts()` which checks `get_comparison_data_for_merchant_count()` first
- Add transient cache (e.g., 1-6 hours) for merchant comparison data
- Only fetch fresh data if cache is expired or explicitly requested

---

### 3. **ProductManager::getPostProducts() - Redundant API Calls**
**Location:** `src/Core/ProductManager.php` (line 182)

**Issue:** When products aren't in post meta, it loops through product IDs and makes individual API calls for each.

```php
foreach ($product_ids as $product_id) {
    $product = $datafeedr->search('id:' . $product_id, 1);  // API call per product
}
```

**Impact:**
- If a post has 5 products, this makes 5 separate API calls
- Could be batched or use database lookup first
- No caching between calls

**Recommendation:**
- First try `get_product_data_from_database()` for each product ID (uses database cache)
- Only fall back to API if database lookup fails
- Consider batching multiple product lookups into a single API call if Datafeedr supports it

---

### 4. **Shortcodes::get_post_products() - Duplicate Logic**
**Location:** `src/Core/Shortcodes.php` (line 449)

**Issue:** Same pattern as ProductManager - loops through IDs making individual API calls.

**Impact:**
- Duplicate code path that could be consolidated
- Same wasteful pattern as ProductManager

**Recommendation:**
- Reuse `ProductManager::getPostProducts()` instead of duplicating logic
- Or apply same optimization (database first, then API)

---

## 🟡 Medium Priority Issues

### 5. **get_merchant_price_info() - Called Without Database Check**
**Location:** `src/Core/Datafeedr.php` (line 1936)

**Issue:** This method always makes an API call, but it's called from `ajax_get_merchant_counts()` which already checks database first. However, if database check fails, it calls this method which makes another API call.

**Current Flow:**
1. `ajax_get_merchant_counts()` checks database first ✅
2. If database check fails, calls `get_merchant_price_info()` which makes API call
3. But `get_merchant_price_info()` could also check database/comparison cache first

**Recommendation:**
- Add database/comparison cache check at the start of `get_merchant_price_info()`
- Only make API call if cache is missing or expired

---

### 6. **search() Method - No Request-Level Caching**
**Location:** `src/Core/Datafeedr.php` (line 784)

**Issue:** The `search()` method has no caching mechanism. If the same query is made multiple times in the same request, it makes multiple API calls.

**Example Scenario:**
- Shortcode renders product image → calls `search('id:123', 1)`
- Same shortcode renders product URL → calls `search('id:123', 1)` again
- Same product, same request, 2 API calls

**Recommendation:**
- Add static request-level cache (similar to `$product_data_cache` used in `get_product_data_from_database()`)
- Cache results keyed by query string + limit
- Clear cache at end of request

---

### 7. **Shortcodes::get_product() - Static Cache Exists But May Not Be Used**
**Location:** `src/Core/Shortcodes.php` (line 395)

**Good News:** This method already has static caching (`self::$products`)

**Potential Issue:** 
- Cache is per-request only (static variable)
- If same product is needed across multiple requests, API is called again
- Could benefit from transient cache for cross-request caching

**Recommendation:**
- Keep static cache for request-level optimization
- Add transient cache (e.g., 1 hour) for cross-request caching
- Check transient first, then static cache, then API

---

## 🟢 Low Priority / Optimization Opportunities

### 8. **Invalid Product ID Filtering**
**Location:** `src/Core/Datafeedr.php` (lines 785-793, 729-736)

**Good News:** Already implemented! The code skips API calls for invalid/single-digit product IDs.

**Status:** ✅ Already optimized

---

### 9. **Database Lookup Caching**
**Location:** `src/Core/Datafeedr.php` (line 728)

**Good News:** `get_product_data_from_database()` already uses static cache (`self::$product_data_cache`)

**Status:** ✅ Already optimized

---

## 📊 Estimated Impact

### High Impact Optimizations:
1. **VariableReplacer caching** - Could save 2-4 API calls per product during content rendering
2. **get_merchant_comparison caching** - Could save 1 API call per product comparison (frequently called)
3. **ProductManager database-first lookup** - Could save 1-5 API calls per post load

### Medium Impact:
4. **Request-level search() caching** - Could save 1-2 API calls per request for duplicate queries
5. **get_merchant_price_info database check** - Could save 1 API call when database has comparison data

---

## 🎯 Recommended Action Plan

### Phase 1 (Quick Wins):
1. Add database/comparison cache check to `get_merchant_comparison()` before API call
2. Add request-level static cache to `search()` method
3. Update `VariableReplacer` to check product array fields before API call

### Phase 2 (Medium Effort):
4. Update `ProductManager::getPostProducts()` to use database lookup first
5. Consolidate `Shortcodes::get_post_products()` to use `ProductManager::getPostProducts()`
6. Add transient caching to `Shortcodes::get_product()`

### Phase 3 (Long-term):
7. Add comprehensive caching layer with TTL management
8. Implement batch API calls where possible
9. Add API call logging/metrics to track actual usage

---

## 🔍 How to Verify Current API Usage

To see actual API call patterns, check your error logs for:
- `[AEBG] Datafeedr search request:`
- `[AEBG] Making API request with queries:`
- `[AEBG] Merchant discovery API request:`

These log entries indicate when API calls are being made. Count them during a typical workflow to see actual usage.

---

## 📝 Notes

- The codebase already has some good optimizations (invalid ID filtering, database caching)
- Most issues are about adding cache checks BEFORE making API calls
- The `get_comparison_data_for_merchant_count()` pattern is a good example to follow
- Consider adding a feature flag to enable/disable API caching for debugging

