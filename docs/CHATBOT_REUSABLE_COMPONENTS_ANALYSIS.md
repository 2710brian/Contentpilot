# Chatbot Implementation - Reusable Components Analysis

## 📋 Executive Summary

This document identifies all existing codebase components, patterns, and utilities that can be **reused** in the chatbot implementation to avoid duplicate code, reduce bloat, and maintain consistency. This analysis will save significant development time and ensure the chatbot integrates seamlessly with existing systems.

---

## 🎯 Reusability Strategy

### Principles
1. **Reuse First, Create Second**: Always check for existing solutions before creating new ones
2. **Follow Established Patterns**: Use existing architectural patterns (Repository, Service, Controller)
3. **Extend, Don't Duplicate**: Extend existing classes rather than duplicating functionality
4. **Leverage Existing Infrastructure**: Use existing caching, logging, error handling, and API clients

---

## ✅ Directly Reusable Components

### 1. **Core Utilities**

#### `AEBG\Core\Logger`
**Location**: `src/Core/Logger.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Centralized logging with levels (debug, info, warning, error)
- Automatic context sanitization (removes sensitive data)
- WP_DEBUG-aware logging
- Consistent log format

**Usage in Chatbot:**
```php
use AEBG\Core\Logger;

// Instead of creating new logging
Logger::info('Chatbot message received', ['session_id' => $session_id]);
Logger::error('Query processing failed', ['error' => $e->getMessage()]);
Logger::debug('AI response generated', ['tokens' => $tokens]);
```

**Savings**: ~200 lines of code, consistent logging across system

---

#### `AEBG\Core\ErrorHandler`
**Location**: `src/Core/ErrorHandler.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Centralized exception handling
- Error level detection
- Retry logic determination
- Consistent error logging

**Usage in Chatbot:**
```php
use AEBG\Core\ErrorHandler;

try {
    // Chatbot code
} catch (\Exception $e) {
    ErrorHandler::handle_exception($e, 'Chatbot::processMessage', [
        'session_id' => $session_id,
        'query' => $query
    ]);
    
    if (ErrorHandler::should_retry($e)) {
        // Retry logic
    }
}
```

**Savings**: ~200 lines of code, consistent error handling

---

#### `AEBG\Core\SettingsHelper`
**Location**: `src/Core/SettingsHelper.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Cached settings access (avoids multiple `get_option()` calls)
- Type-safe setting retrieval
- Convenience methods for common settings

**Usage in Chatbot:**
```php
use AEBG\Core\SettingsHelper;

// Instead of: get_option('aebg_settings')['openai_api_key']
$api_key = SettingsHelper::get('openai_api_key');
$chatbot_enabled = SettingsHelper::get('chatbot_enabled', false);
$max_results = SettingsHelper::get('chatbot_max_results', 20);
```

**Savings**: ~50 lines of code, better performance (cached)

---

### 2. **API Clients**

#### `AEBG\Core\APIClient`
**Location**: `src/Core/APIClient.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Robust OpenAI API integration
- Timeout handling
- Retry logic with exponential backoff
- DNS pre-flight checks
- Rate limit management
- Multiple model support

**Usage in Chatbot:**
```php
use AEBG\Core\APIClient;

// For AI query processing
$endpoint = APIClient::getApiEndpoint('gpt-4o-mini');
$body = APIClient::buildRequestBody('gpt-4o-mini', $prompt, 500, 0.3);
$response = APIClient::makeRequest($endpoint, $api_key, $body, 30, 2);
$content = APIClient::extractContentFromResponse($response, 'gpt-4o-mini');
```

**Savings**: ~500 lines of code, production-ready API handling

---

#### `AEBG\Core\Datafeedr`
**Location**: `src/Core/Datafeedr.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Complete Datafeedr API integration
- Product search (`search()`, `search_advanced()`)
- Merchant comparison (`get_merchant_comparison()`)
- Product data retrieval (`get_product_data_from_database()`)
- Built-in caching
- Error handling

**Usage in Chatbot:**
```php
use AEBG\Core\Datafeedr;

$datafeedr = new Datafeedr();

// Search products
$products = $datafeedr->search_advanced(
    $query,
    $limit,
    $sort_by,
    $min_price,
    $max_price,
    $min_rating,
    $in_stock_only,
    $currency,
    $country,
    $category,
    $has_image,
    $offset,
    $networks,
    $brand
);

// Get merchant comparison
$comparison = $datafeedr->get_merchant_comparison($product, 20);
```

**Savings**: ~3000 lines of code, full Datafeedr integration

---

### 3. **Product Management**

#### `AEBG\Core\ProductManager`
**Location**: `src/Core/ProductManager.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Product validation (`validateProduct()`, `validateProducts()`)
- Product normalization
- Safe type conversion
- JSON encoding validation

**Usage in Chatbot:**
```php
use AEBG\Core\ProductManager;

// Validate products from Datafeedr
$validated_products = ProductManager::validateProducts($raw_products);

// Each product is guaranteed to have all required fields
foreach ($validated_products as $product) {
    // Safe to use: $product['name'], $product['price'], etc.
}
```

**Savings**: ~200 lines of code, consistent product data structure

---

#### `AEBG\Core\ProductHelper`
**Location**: `src/Core/ProductHelper.php`  
**Reusability**: ✅ **90% - Use with Minor Extensions**

**What it provides:**
- Product data extraction
- Affiliate link processing
- Product formatting

**Usage in Chatbot:**
```php
use AEBG\Core\ProductHelper;

// Format product for display
$formatted = ProductHelper::get_product_data($post_id, $index);
```

**Note**: May need extension for chatbot-specific formatting (no post_id needed)

---

### 4. **Price & Currency Management**

#### `AEBG\Core\CurrencyManager`
**Location**: `src/Core/CurrencyManager.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- Currency formatting
- Price normalization
- Multi-currency support (DKK, SEK, NOK, USD, EUR, GBP, etc.)
- Currency detection

**Usage in Chatbot:**
```php
use AEBG\Core\CurrencyManager;

// Format price for display
$formatted_price = CurrencyManager::formatPrice($price, $currency);

// Normalize price (convert to cents)
$normalized = CurrencyManager::normalizePrice($price, $currency);
```

**Savings**: ~500 lines of code, full currency support

---

#### `AEBG\Core\ComparisonManager`
**Location**: `src/Core/ComparisonManager.php`  
**Reusability**: ✅ **90% - Use with Extensions**

**What it provides:**
- Save/retrieve comparison data
- Database caching for comparisons
- Price range calculation

**Usage in Chatbot:**
```php
use AEBG\Core\ComparisonManager;

// Save comparison for caching
ComparisonManager::save_comparison(
    $user_id,
    $post_id,
    $product_id,
    'chatbot_search',
    $comparison_data
);

// Retrieve cached comparison
$cached = ComparisonManager::get_comparison($user_id, $product_id, $post_id);
```

**Note**: May need extension for session-based comparisons (no user_id)

---

#### `AEBG\Core\MerchantCache`
**Location**: `src/Core/MerchantCache.php`  
**Reusability**: ✅ **100% - Use Directly**

**What it provides:**
- 30-day merchant data caching
- Reduces API calls
- Transient-based caching

**Usage in Chatbot:**
```php
use AEBG\Core\MerchantCache;

// Check cache first
$cached = MerchantCache::get($product_id);
if ($cached) {
    return $cached;
}

// Fetch and cache
$merchant_data = $datafeedr->get_merchant_comparison($product);
MerchantCache::set($product_id, $merchant_data);
```

**Savings**: ~100 lines of code, optimized caching

---

### 5. **Repository Pattern**

#### EmailMarketing Repository Pattern
**Location**: `src/EmailMarketing/Repositories/`  
**Reusability**: ✅ **100% - Follow Pattern**

**Existing Repositories:**
- `SubscriberRepository.php`
- `ListRepository.php`
- `CampaignRepository.php`
- `QueueRepository.php`
- `TemplateRepository.php`
- `TrackingRepository.php`

**Pattern to Follow:**
```php
namespace AEBG\Chatbot\Repositories;

class ConversationRepository {
    public function get($conversation_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'aebg_chatbot_conversations';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            $conversation_id
        ));
    }
    
    public function get_by_session($session_id) {
        // Similar pattern
    }
    
    public function create($data) {
        // Similar pattern
    }
}
```

**Savings**: Consistent database access pattern, ~50 lines per repository

---

### 6. **Service Layer Pattern**

#### EmailMarketing Service Pattern
**Location**: `src/EmailMarketing/Services/`  
**Reusability**: ✅ **100% - Follow Pattern**

**Existing Services:**
- `ValidationService.php`
- `EmailService.php`
- `TemplateService.php`
- `AnalyticsService.php`

**Pattern to Follow:**
```php
namespace AEBG\Chatbot\Services;

use AEBG\EmailMarketing\Services\ValidationService;

class ChatbotValidationService extends ValidationService {
    // Extend existing validation
    public function validate_message($message) {
        // Use parent methods
        return $this->sanitize_input($message, 'textarea');
    }
}
```

**Savings**: Reuse validation logic, consistent service architecture

---

### 7. **REST API Controller Pattern**

#### Existing Controllers
**Location**: `src/API/`  
**Reusability**: ✅ **100% - Follow Pattern**

**Existing Controllers:**
- `GeneratorV2Controller.php`
- `DashboardController.php`
- `CompetitorTrackingController.php`
- `PromptTemplateController.php`
- `NetworkAnalyticsController.php`

**Pattern to Follow:**
```php
namespace AEBG\Chatbot\API;

class ChatbotController extends \WP_REST_Controller {
    public function __construct() {
        $this->namespace = 'aebg/v1';
        $this->rest_base = 'chatbot';
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/message',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_message'],
                'permission_callback' => [$this, 'permissions_check'],
            ]
        );
    }
    
    public function permissions_check($request) {
        // Reuse existing permission patterns
        return true; // or check capabilities
    }
}
```

**Savings**: Consistent API structure, ~100 lines per endpoint

---

## 🔄 Components to Extend (Not Duplicate)

### 1. **Caching Strategy**

#### Existing Caching Patterns
**Locations**: 
- `MerchantCache.php` (transient-based)
- `Datafeedr.php` (static request cache)
- EmailMarketing (multi-layer cache pattern)

**Extend, Don't Duplicate:**
```php
namespace AEBG\Chatbot\Services;

class ChatbotCacheService {
    // Reuse existing patterns
    private static $request_cache = []; // Request-level
    
    public function get($key, $group = 'chatbot') {
        // Layer 1: Request cache
        if (isset(self::$request_cache[$group][$key])) {
            return self::$request_cache[$group][$key];
        }
        
        // Layer 2: Object cache (reuse wp_cache)
        $cached = wp_cache_get($key, $group);
        if ($cached !== false) {
            self::$request_cache[$group][$key] = $cached;
            return $cached;
        }
        
        // Layer 3: Transient (reuse get_transient)
        $transient = get_transient("aebg_chatbot_{$key}");
        if ($transient !== false) {
            wp_cache_set($key, $transient, $group, 3600);
            self::$request_cache[$group][$key] = $transient;
            return $transient;
        }
        
        return false;
    }
    
    public function set($key, $value, $group = 'chatbot', $expiration = 3600) {
        self::$request_cache[$group][$key] = $value;
        wp_cache_set($key, $value, $group, $expiration);
        set_transient("aebg_chatbot_{$key}", $value, $expiration);
    }
}
```

**Savings**: Reuse WordPress caching infrastructure, ~150 lines

---

### 2. **Database Operations**

#### Reuse WordPress Database Patterns
**Pattern**: Use `$wpdb` with prepared statements (same as EmailMarketing)

**Don't Create:**
- New database connection classes
- New query builders
- New ORM systems

**Reuse:**
- `$wpdb` global
- Prepared statements pattern
- Transaction patterns (if needed)

**Example:**
```php
// Follow EmailMarketing repository pattern
global $wpdb;
$table = $wpdb->prefix . 'aebg_chatbot_conversations';

$result = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table} WHERE session_id = %s LIMIT 1",
    $session_id
));
```

**Savings**: No new database abstraction layer needed

---

## 🚫 What NOT to Create (Already Exists)

### ❌ Don't Create These:

1. **New Logger Class**
   - ✅ Use: `AEBG\Core\Logger`

2. **New Error Handler**
   - ✅ Use: `AEBG\Core\ErrorHandler`

3. **New Settings Helper**
   - ✅ Use: `AEBG\Core\SettingsHelper`

4. **New API Client for OpenAI**
   - ✅ Use: `AEBG\Core\APIClient`

5. **New Datafeedr Integration**
   - ✅ Use: `AEBG\Core\Datafeedr`

6. **New Product Validation**
   - ✅ Use: `AEBG\Core\ProductManager`

7. **New Currency Management**
   - ✅ Use: `AEBG\Core\CurrencyManager`

8. **New Caching Infrastructure**
   - ✅ Extend: WordPress transients + object cache

9. **New Database Abstraction**
   - ✅ Use: WordPress `$wpdb` with prepared statements

10. **New Validation Service**
    - ✅ Extend: `AEBG\EmailMarketing\Services\ValidationService`

---

## 📊 Code Savings Estimate

### Direct Reuse Savings:
| Component | Lines Saved | Complexity Saved |
|-----------|-------------|------------------|
| Logger | ~200 | High |
| ErrorHandler | ~200 | High |
| SettingsHelper | ~50 | Medium |
| APIClient | ~500 | Very High |
| Datafeedr | ~3000 | Very High |
| ProductManager | ~200 | Medium |
| CurrencyManager | ~500 | High |
| ComparisonManager | ~150 | Medium |
| MerchantCache | ~100 | Low |
| **Total Direct** | **~4,900 lines** | **Massive** |

### Pattern Reuse Savings:
| Pattern | Lines Saved | Consistency Benefit |
|---------|-------------|-------------------|
| Repository Pattern | ~300 | High |
| Service Pattern | ~200 | High |
| Controller Pattern | ~200 | High |
| Caching Pattern | ~150 | Medium |
| **Total Patterns** | **~850 lines** | **High** |

### **Grand Total: ~5,750 lines of code saved!**

---

## 🎯 Implementation Recommendations

### Phase 1: Core Reuse (Week 1)
1. ✅ Import and use `Logger` for all logging
2. ✅ Import and use `ErrorHandler` for exception handling
3. ✅ Import and use `SettingsHelper` for settings access
4. ✅ Import and use `APIClient` for AI calls
5. ✅ Import and use `Datafeedr` for product search

### Phase 2: Product & Price Reuse (Week 2)
1. ✅ Use `ProductManager` for product validation
2. ✅ Use `CurrencyManager` for price formatting
3. ✅ Use `ComparisonManager` for price comparisons
4. ✅ Use `MerchantCache` for caching

### Phase 3: Architecture Patterns (Week 3)
1. ✅ Follow Repository pattern for database access
2. ✅ Follow Service pattern for business logic
3. ✅ Follow Controller pattern for REST API
4. ✅ Extend caching patterns

---

## 🔍 Code Examples: Before vs After

### Example 1: Logging

**❌ Without Reuse (Bad):**
```php
class ChatbotLogger {
    public function log($message, $level = 'info') {
        $prefix = '[CHATBOT]';
        error_log($prefix . ' [' . strtoupper($level) . '] ' . $message);
    }
}
```

**✅ With Reuse (Good):**
```php
use AEBG\Core\Logger;

Logger::info('Chatbot message received', ['session_id' => $session_id]);
Logger::error('Query processing failed', ['error' => $e->getMessage()]);
```

**Savings**: 20 lines, consistent format, automatic sanitization

---

### Example 2: AI API Calls

**❌ Without Reuse (Bad):**
```php
class ChatbotAIClient {
    public function callAI($prompt) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'gpt-4',
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // ... 50+ more lines of error handling, retries, etc.
    }
}
```

**✅ With Reuse (Good):**
```php
use AEBG\Core\APIClient;

$endpoint = APIClient::getApiEndpoint('gpt-4o-mini');
$body = APIClient::buildRequestBody('gpt-4o-mini', $prompt, 500, 0.3);
$response = APIClient::makeRequest($endpoint, $api_key, $body, 30, 2);
$content = APIClient::extractContentFromResponse($response, 'gpt-4o-mini');
```

**Savings**: 200+ lines, production-ready with retries, timeouts, error handling

---

### Example 3: Product Search

**❌ Without Reuse (Bad):**
```php
class ChatbotProductSearch {
    public function search($query) {
        // Implement Datafeedr API integration
        // Handle authentication
        // Build search conditions
        // Make API request
        // Handle errors
        // Parse response
        // ... 500+ lines
    }
}
```

**✅ With Reuse (Good):**
```php
use AEBG\Core\Datafeedr;

$datafeedr = new Datafeedr();
$products = $datafeedr->search_advanced(
    $query,
    $limit,
    'relevance',
    $min_price,
    $max_price,
    $min_rating,
    false,
    'USD',
    '',
    $category,
    true
);
```

**Savings**: 500+ lines, full Datafeedr integration, caching included

---

### Example 4: Price Formatting

**❌ Without Reuse (Bad):**
```php
class ChatbotPriceFormatter {
    public function format($price, $currency) {
        if ($currency === 'USD') {
            return '$' . number_format($price, 2);
        } elseif ($currency === 'EUR') {
            return '€' . number_format($price, 2, ',', '.');
        } elseif ($currency === 'DKK') {
            return number_format($price, 2, ',', '.') . ' kr.';
        }
        // ... 100+ more lines for all currencies
    }
}
```

**✅ With Reuse (Good):**
```php
use AEBG\Core\CurrencyManager;

$formatted = CurrencyManager::formatPrice($price, $currency);
```

**Savings**: 200+ lines, full currency support

---

## 📝 Updated Chatbot Architecture (With Reuse)

### Simplified Structure:

```
src/Chatbot/
├── Admin/
│   ├── ChatbotMenu.php          # Uses SettingsHelper
│   └── views/
├── API/
│   └── ChatbotController.php    # Follows existing Controller pattern
├── Core/
│   ├── ConversationManager.php  # Uses Logger, ErrorHandler
│   ├── QueryProcessor.php        # Uses APIClient
│   ├── ProductSearchEngine.php   # Uses Datafeedr, ProductManager
│   ├── PriceComparator.php      # Uses ComparisonManager, CurrencyManager
│   └── ResponseGenerator.php    # Uses APIClient
├── Repositories/
│   ├── ConversationRepository.php  # Follows EmailMarketing pattern
│   ├── MessageRepository.php
│   └── SearchHistoryRepository.php
├── Services/
│   ├── AIService.php            # Wraps APIClient
│   ├── DatafeedrService.php     # Wraps Datafeedr
│   └── CacheService.php         # Extends WordPress caching
└── Utils/
    ├── QueryParser.php          # New utility
    └── ProductFormatter.php     # Uses ProductManager, CurrencyManager
```

**Key Changes:**
- ❌ Removed: `AIService` (use `APIClient` directly)
- ❌ Removed: `DatafeedrService` (use `Datafeedr` directly)
- ✅ Added: Wrapper classes only if needed for chatbot-specific logic
- ✅ Reuse: All core utilities, managers, and helpers

---

## ✅ Checklist: Reuse Verification

Before implementing any component, check:

- [ ] Is there an existing Logger? → ✅ Use `AEBG\Core\Logger`
- [ ] Is there an existing ErrorHandler? → ✅ Use `AEBG\Core\ErrorHandler`
- [ ] Is there an existing Settings helper? → ✅ Use `AEBG\Core\SettingsHelper`
- [ ] Is there an existing API client? → ✅ Use `AEBG\Core\APIClient`
- [ ] Is there an existing Datafeedr integration? → ✅ Use `AEBG\Core\Datafeedr`
- [ ] Is there an existing Product manager? → ✅ Use `AEBG\Core\ProductManager`
- [ ] Is there an existing Currency manager? → ✅ Use `AEBG\Core\CurrencyManager`
- [ ] Is there an existing Repository pattern? → ✅ Follow EmailMarketing pattern
- [ ] Is there an existing Service pattern? → ✅ Follow EmailMarketing pattern
- [ ] Is there an existing Controller pattern? → ✅ Follow existing API controllers
- [ ] Is there existing caching? → ✅ Extend WordPress caching patterns

---

## 🎉 Summary

### Benefits of Reuse:
1. **~5,750 lines of code saved**
2. **Consistent architecture** across the plugin
3. **Proven, tested components** (already in production)
4. **Faster development** (no need to reinvent the wheel)
5. **Easier maintenance** (fix bugs in one place)
6. **Better performance** (optimized existing code)

### Key Takeaways:
- ✅ **Reuse 90%+ of existing infrastructure**
- ✅ **Follow established patterns** (Repository, Service, Controller)
- ✅ **Extend, don't duplicate** existing functionality
- ✅ **Only create new code** for chatbot-specific logic

### Estimated Development Time Reduction:
- **Without reuse**: ~12 weeks
- **With reuse**: ~8-9 weeks (25-33% faster)

---

**Document Version**: 1.0  
**Last Updated**: 2024-01-15  
**Status**: Ready for Implementation

