# AI Product Search Chatbot - Production-Ready Implementation Plan

## 📋 Executive Summary

This document outlines a comprehensive, production-ready plan for implementing an AI-powered chatbot that helps users find products at the best price using the Datafeedr API. The chatbot will be built as a modular addition to the existing AI Content Generator plugin, following established architectural patterns and best practices.

> **⚠️ IMPORTANT**: Before implementing, review:
> 1. **[CHATBOT_REUSABLE_COMPONENTS_ANALYSIS.md](./CHATBOT_REUSABLE_COMPONENTS_ANALYSIS.md)** - Identifies existing components that can be reused (~5,750 lines saved)
> 2. **[CHATBOT_SAFETY_AND_ISOLATION_GUARANTEE.md](./CHATBOT_SAFETY_AND_ISOLATION_GUARANTEE.md)** - **CRITICAL**: Absolute guarantees that chatbot will NEVER interfere with existing code

---

## 🎯 Feature Overview

### Core Functionality
- **Natural Language Product Search**: Users can describe products in natural language
- **AI-Powered Query Understanding**: Uses AI to interpret user intent and extract search parameters
- **Datafeedr Integration**: Searches products via Datafeedr API with intelligent query building
- **Price Comparison**: Automatically finds and compares prices across multiple merchants
- **Best Price Recommendations**: AI analyzes results and recommends the best deals
- **Conversational Interface**: Maintains context across conversation turns
- **Product Details**: Provides comprehensive product information (images, ratings, reviews, etc.)

### Key Features
1. **Intelligent Query Processing**
   - Natural language understanding
   - Intent extraction (search, compare, filter, refine)
   - Parameter extraction (price range, category, brand, features)
   - Query refinement through conversation

2. **Product Discovery**
   - Multi-criteria search (name, category, brand, price, rating)
   - Smart filtering and sorting
   - Relevance-based ranking
   - Merchant comparison

3. **Price Intelligence**
   - Real-time price comparison
   - Price history tracking (optional)
   - Best deal identification
   - Savings calculations

4. **Conversation Management**
   - Session management
   - Context preservation
   - Multi-turn conversations
   - Query refinement

---

## 🏗️ Architecture Overview

### Modular Structure
Following the existing EmailMarketing module pattern, the chatbot will be organized as:

```
src/
├── Chatbot/
│   ├── Admin/
│   │   ├── ChatbotMenu.php          # Admin menu and settings
│   │   └── views/
│   │       ├── chatbot-settings-page.php
│   │       └── chatbot-analytics-page.php
│   ├── API/
│   │   └── ChatbotController.php     # REST API endpoints
│   ├── Core/
│   │   ├── ConversationManager.php   # Manages chat sessions
│   │   ├── QueryProcessor.php        # AI-powered query processing
│   │   ├── ProductSearchEngine.php   # Datafeedr search integration
│   │   ├── PriceComparator.php       # Price comparison logic
│   │   ├── ResponseGenerator.php    # AI response generation
│   │   ├── SessionManager.php       # Session handling
│   │   └── AnalyticsTracker.php     # Usage analytics
│   ├── Repositories/
│   │   ├── ConversationRepository.php
│   │   ├── MessageRepository.php
│   │   └── SearchHistoryRepository.php
│   ├── Services/
│   │   ├── AIService.php            # AI API integration
│   │   ├── DatafeedrService.php      # Datafeedr API wrapper
│   │   └── CacheService.php          # Caching layer
│   └── Utils/
│       ├── QueryParser.php           # Query parsing utilities
│       ├── ProductFormatter.php      # Product data formatting
│       └── ResponseFormatter.php     # Response formatting
```

---

## 📊 Database Schema

### Tables

#### 1. `wp_aebg_chatbot_conversations`
Stores conversation sessions.

```sql
CREATE TABLE `wp_aebg_chatbot_conversations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` enum('active','completed','abandoned') DEFAULT 'active',
  `context` longtext DEFAULT NULL COMMENT 'JSON: conversation context, preferences, filters',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_activity_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `last_activity_at` (`last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 2. `wp_aebg_chatbot_messages`
Stores individual messages in conversations.

```sql
CREATE TABLE `wp_aebg_chatbot_messages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('user','assistant','system') NOT NULL,
  `content` longtext NOT NULL,
  `metadata` longtext DEFAULT NULL COMMENT 'JSON: extracted params, products, etc.',
  `ai_model` varchar(50) DEFAULT NULL,
  `tokens_used` int(11) DEFAULT NULL,
  `response_time_ms` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `role` (`role`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `fk_chatbot_messages_conversation` FOREIGN KEY (`conversation_id`) 
    REFERENCES `wp_aebg_chatbot_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 3. `wp_aebg_chatbot_searches`
Tracks product searches for analytics and caching.

```sql
CREATE TABLE `wp_aebg_chatbot_searches` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `query` text NOT NULL,
  `processed_query` text DEFAULT NULL COMMENT 'AI-processed query',
  `search_params` longtext DEFAULT NULL COMMENT 'JSON: filters, sort, etc.',
  `results_count` int(11) DEFAULT 0,
  `products_shown` longtext DEFAULT NULL COMMENT 'JSON: array of product IDs shown',
  `best_price_product_id` varchar(100) DEFAULT NULL,
  `search_time_ms` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `created_at` (`created_at`),
  FULLTEXT KEY `query` (`query`),
  CONSTRAINT `fk_chatbot_searches_conversation` FOREIGN KEY (`conversation_id`) 
    REFERENCES `wp_aebg_chatbot_conversations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 4. `wp_aebg_chatbot_product_interactions`
Tracks user interactions with products (views, clicks, etc.).

```sql
CREATE TABLE `wp_aebg_chatbot_product_interactions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` varchar(100) NOT NULL,
  `interaction_type` enum('viewed','clicked','shared','saved') NOT NULL,
  `metadata` longtext DEFAULT NULL COMMENT 'JSON: additional data',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  KEY `message_id` (`message_id`),
  KEY `product_id` (`product_id`),
  KEY `interaction_type` (`interaction_type`),
  CONSTRAINT `fk_chatbot_interactions_conversation` FOREIGN KEY (`conversation_id`) 
    REFERENCES `wp_aebg_chatbot_conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chatbot_interactions_message` FOREIGN KEY (`message_id`) 
    REFERENCES `wp_aebg_chatbot_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 🔌 API Endpoints

### REST API Structure

Following the existing pattern (`aebg/v1` namespace):

#### 1. **POST** `/aebg/v1/chatbot/message`
Send a message to the chatbot.

**Request:**
```json
{
  "session_id": "abc123...",
  "message": "I'm looking for a wireless mouse under $50",
  "context": {
    "previous_products": [],
    "filters": {}
  }
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "message_id": 123,
    "response": "I found several wireless mice under $50. Here are the best options:",
    "products": [
      {
        "id": "df_12345",
        "name": "Logitech MX Master 3",
        "price": 49.99,
        "currency": "USD",
        "merchant": "Amazon",
        "image": "https://...",
        "rating": 4.8,
        "url": "https://...",
        "is_best_price": true
      }
    ],
    "suggestions": [
      "Show me more options",
      "Filter by brand",
      "Compare prices"
    ],
    "session_id": "abc123...",
    "context": {
      "extracted_params": {
        "category": "computer accessories",
        "product_type": "wireless mouse",
        "max_price": 50,
        "currency": "USD"
      }
    }
  }
}
```

#### 2. **POST** `/aebg/v1/chatbot/session`
Create a new conversation session.

**Request:**
```json
{
  "user_id": 1,  // Optional, for logged-in users
  "initial_message": "Hello"  // Optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "session_id": "abc123...",
    "conversation_id": 456,
    "welcome_message": "Hi! I can help you find products at the best price. What are you looking for?"
  }
}
}
```

#### 3. **GET** `/aebg/v1/chatbot/session/{session_id}`
Get conversation history.

**Response:**
```json
{
  "success": true,
  "data": {
    "conversation_id": 456,
    "messages": [
      {
        "id": 1,
        "role": "user",
        "content": "I'm looking for a wireless mouse",
        "created_at": "2024-01-15T10:00:00Z"
      },
      {
        "id": 2,
        "role": "assistant",
        "content": "I found several options...",
        "products": [...],
        "created_at": "2024-01-15T10:00:01Z"
      }
    ],
    "context": {...}
  }
}
```

#### 4. **POST** `/aebg/v1/chatbot/refine`
Refine search with additional criteria.

**Request:**
```json
{
  "session_id": "abc123...",
  "refinement": {
    "type": "filter",
    "filters": {
      "min_rating": 4.5,
      "brand": "Logitech"
    }
  }
}
```

#### 5. **POST** `/aebg/v1/chatbot/product-details`
Get detailed product information.

**Request:**
```json
{
  "product_id": "df_12345",
  "session_id": "abc123..."  // Optional
}
```

#### 6. **GET** `/aebg/v1/chatbot/analytics`
Get chatbot analytics (admin only).

**Query Parameters:**
- `date_from`, `date_to`
- `group_by`: `day`, `week`, `month`
- `metrics`: `searches`, `conversations`, `products_viewed`

---

## 🧠 Core Components

### 1. QueryProcessor

**Purpose**: Process natural language queries using AI to extract search parameters.

**Reuses**: `AEBG\Core\APIClient` for AI calls, `AEBG\Core\Logger` for logging

**Key Methods:**
```php
use AEBG\Core\APIClient;
use AEBG\Core\Logger;

class QueryProcessor {
    /**
     * Process user query and extract search parameters
     * 
     * @param string $query User's natural language query
     * @param array $context Previous conversation context
     * @return array Extracted parameters
     */
    public function processQuery(string $query, array $context = []): array;
    
    /**
     * Refine query based on user feedback
     * 
     * @param string $refinement User's refinement request
     * @param array $currentParams Current search parameters
     * @return array Refined parameters
     */
    public function refineQuery(string $refinement, array $currentParams): array;
    
    /**
     * Build Datafeedr search conditions from extracted parameters
     * 
     * @param array $params Extracted parameters
     * @return array Datafeedr search conditions
     */
    public function buildSearchConditions(array $params): array;
}
```

**AI Prompt Template:**
```
You are a product search assistant. Analyze the user's query and extract:
1. Product name/type
2. Category (if mentioned)
3. Price range (min/max)
4. Brand preferences
5. Features/requirements
6. Sort preference (price, rating, relevance)

User query: {query}
Previous context: {context}

Return JSON with extracted parameters.
```

### 2. ProductSearchEngine

**Purpose**: Execute product searches using Datafeedr API with intelligent query building.

**Reuses**: `AEBG\Core\Datafeedr` directly, `AEBG\Core\ProductManager` for validation, `AEBG\Core\MerchantCache` for caching

**Key Methods:**
```php
use AEBG\Core\Datafeedr;
use AEBG\Core\ProductManager;
use AEBG\Core\MerchantCache;

class ProductSearchEngine {
    private $datafeedr;
    
    public function __construct() {
        $this->datafeedr = new Datafeedr();
    }
    
    /**
     * Search products using Datafeedr API
     * 
     * @param array $searchParams Extracted search parameters
     * @param int $limit Number of results
     * @return array|WP_Error Products or error
     */
    public function search(array $searchParams, int $limit = 20): array;
    
    /**
     * Find best price for a product across merchants
     * 
     * @param string $productId Product ID
     * @return array|WP_Error Best price info or error
     */
    public function findBestPrice(string $productId): array;
    
    /**
     * Compare prices across merchants
     * 
     * @param array $productIds Product IDs to compare
     * @return array Comparison data
     */
    public function comparePrices(array $productIds): array;
}
```

**Integration Points:**
- Uses existing `AEBG\Core\Datafeedr` class
- Leverages `search_advanced()` method
- Implements caching via `CacheService`
- Reuses `get_merchant_comparison()` for price comparison

### 3. ResponseGenerator

**Purpose**: Generate natural, helpful responses using AI.

**Reuses**: `AEBG\Core\APIClient` for AI calls, `AEBG\Core\Logger` for logging

**Key Methods:**
```php
use AEBG\Core\APIClient;
use AEBG\Core\Logger;

class ResponseGenerator {
    /**
     * Generate response message
     * 
     * @param array $products Found products
     * @param array $context Conversation context
     * @param string $userQuery Original user query
     * @return string Generated response
     */
    public function generateResponse(
        array $products, 
        array $context, 
        string $userQuery
    ): string;
    
    /**
     * Generate product recommendations
     * 
     * @param array $products All products
     * @param int $limit Number of recommendations
     * @return array Recommended products with reasoning
     */
    public function generateRecommendations(array $products, int $limit = 5): array;
    
    /**
     * Generate follow-up suggestions
     * 
     * @param array $context Current context
     * @return array Suggested follow-up questions
     */
    public function generateSuggestions(array $context): array;
}
```

**AI Prompt Template:**
```
You are a helpful shopping assistant. Generate a friendly, informative response about these products:

Products: {products}
User query: {query}
Context: {context}

Requirements:
- Be conversational and helpful
- Highlight best deals
- Mention key features
- Suggest follow-up questions
- Keep response under 200 words
```

### 4. ConversationManager

**Purpose**: Manage conversation sessions and context.

**Reuses**: `AEBG\Core\Logger` for logging, `AEBG\Core\ErrorHandler` for error handling, Repository pattern for database access

**Key Methods:**
```php
use AEBG\Core\Logger;
use AEBG\Core\ErrorHandler;
use AEBG\Chatbot\Repositories\ConversationRepository;

class ConversationManager {
    private $repository;
    
    public function __construct() {
        $this->repository = new ConversationRepository();
    }
    
    /**
     * Create new conversation session
     * 
     * @param int|null $userId User ID (null for guests)
     * @return array Session data
     */
    public function createSession(?int $userId = null): array;
    
    /**
     * Get conversation context
     * 
     * @param string $sessionId Session ID
     * @return array Context data
     */
    public function getContext(string $sessionId): array;
    
    /**
     * Update conversation context
     * 
     * @param string $sessionId Session ID
     * @param array $contextUpdates Context updates
     * @return bool Success
     */
    public function updateContext(string $sessionId, array $contextUpdates): bool;
    
    /**
     * Add message to conversation
     * 
     * @param string $sessionId Session ID
     * @param string $role Message role (user/assistant)
     * @param string $content Message content
     * @param array $metadata Additional metadata
     * @return int Message ID
     */
    public function addMessage(
        string $sessionId, 
        string $role, 
        string $content, 
        array $metadata = []
    ): int;
    
    /**
     * Get conversation history
     * 
     * @param string $sessionId Session ID
     * @param int $limit Number of messages
     * @return array Messages
     */
    public function getHistory(string $sessionId, int $limit = 50): array;
}
```

### 5. PriceComparator

**Purpose**: Compare prices and identify best deals.

**Reuses**: `AEBG\Core\ComparisonManager` for comparison data, `AEBG\Core\CurrencyManager` for price formatting

**Key Methods:**
```php
use AEBG\Core\ComparisonManager;
use AEBG\Core\CurrencyManager;

class PriceComparator {
    /**
     * Find best price among products
     * 
     * @param array $products Products to compare
     * @return array Best price product with comparison data
     */
    public function findBestPrice(array $products): array;
    
    /**
     * Calculate savings
     * 
     * @param float $originalPrice Original price
     * @param float $currentPrice Current price
     * @return array Savings data
     */
    public function calculateSavings(float $originalPrice, float $currentPrice): array;
    
    /**
     * Rank products by value
     * 
     * @param array $products Products to rank
     * @param array $criteria Ranking criteria
     * @return array Ranked products
     */
    public function rankByValue(array $products, array $criteria): array;
}
```

---

## 🎨 Frontend Implementation

### Chatbot Widget

**Location**: `assets/js/chatbot.js` and `assets/css/chatbot.css`

**Features:**
- Floating chat button
- Slide-up chat window
- Message bubbles (user/assistant)
- Product cards with images
- Typing indicators
- Smooth animations
- Mobile-responsive

**Integration Options:**
1. **Shortcode**: `[aebg_chatbot]`
2. **Widget**: WordPress widget
3. **Elementor Widget**: Custom Elementor widget
4. **Block**: Gutenberg block

**Example Usage:**
```html
<!-- Shortcode -->
[aebg_chatbot position="bottom-right" theme="light"]

<!-- Elementor Widget -->
Chatbot widget with customizable styling

<!-- Gutenberg Block -->
Chatbot block with settings panel
```

### JavaScript Architecture

```javascript
class ChatbotWidget {
    constructor(options) {
        this.sessionId = null;
        this.apiUrl = '/wp-json/aebg/v1/chatbot';
        this.isOpen = false;
        this.messages = [];
    }
    
    async sendMessage(message) {
        // Show typing indicator
        // Send to API
        // Display response
        // Handle products
    }
    
    displayProducts(products) {
        // Render product cards
        // Add click handlers
    }
    
    render() {
        // Render chat UI
    }
}
```

---

## 🔄 Data Flow

### Message Processing Flow

```
1. User sends message
   ↓
2. ChatbotController receives request
   ↓
3. ConversationManager loads/creates session
   ↓
4. QueryProcessor processes query with AI
   ↓
5. ProductSearchEngine searches Datafeedr
   ↓
6. PriceComparator finds best prices
   ↓
7. ResponseGenerator creates AI response
   ↓
8. ConversationManager saves message
   ↓
9. Response sent to frontend
   ↓
10. Frontend displays response + products
```

### Caching Strategy

**Multi-Layer Caching:**

1. **Request-Level Cache** (Static variables)
   - Caches product data within single request
   - Prevents duplicate API calls

2. **Transient Cache** (WordPress transients)
   - Caches search results (15-30 minutes)
   - Caches AI responses for common queries (1 hour)
   - Key format: `aebg_chatbot_search_{hash}`

3. **Database Cache** (Search history table)
   - Stores successful searches
   - Reuses results for similar queries
   - TTL: 24 hours

4. **Datafeedr Cache** (Existing system)
   - Leverages existing product caching
   - Uses `get_product_data_from_database()`

**Cache Invalidation:**
- Price updates: 15 minutes
- Product data: 1 hour
- Search results: 30 minutes
- AI responses: 1 hour (or never for common queries)

---

## 🔐 Security & Performance

### Security Measures

1. **Input Sanitization**
   - All user inputs sanitized
   - SQL injection prevention (prepared statements)
   - XSS prevention (output escaping)

2. **Rate Limiting**
   - Per-session rate limits (10 messages/minute)
   - Per-IP rate limits (50 messages/hour)
   - Per-user rate limits (100 messages/hour for logged-in)

3. **Session Security**
   - Secure session ID generation
   - Session expiration (30 minutes inactivity)
   - CSRF protection for API endpoints

4. **API Security**
   - Nonce verification
   - Capability checks
   - Input validation
   - Output sanitization

### Performance Optimizations

1. **API Call Optimization**
   - Batch product lookups
   - Cache aggressively
   - Use existing Datafeedr optimizations
   - Request-level caching

2. **Database Optimization**
   - Indexed columns
   - Efficient queries
   - Connection pooling
   - Query result caching

3. **AI Response Optimization**
   - Cache common queries
   - Use faster models for simple queries
   - Stream responses (future enhancement)
   - Batch processing where possible

4. **Frontend Optimization**
   - Lazy loading
   - Image optimization
   - Minified assets
   - CDN support (optional)

---

## 📈 Analytics & Monitoring

### Tracked Metrics

1. **Usage Metrics**
   - Total conversations
   - Messages per conversation
   - Average session duration
   - Active sessions

2. **Search Metrics**
   - Total searches
   - Successful searches
   - Average results per search
   - Most searched products/categories

3. **Product Metrics**
   - Products viewed
   - Products clicked
   - Best price selections
   - Conversion tracking

4. **Performance Metrics**
   - Average response time
   - AI API latency
   - Datafeedr API latency
   - Cache hit rates

5. **User Metrics**
   - New vs returning users
   - User satisfaction (optional)
   - Drop-off points

### Analytics Dashboard

**Admin Page**: `AI Bulk Generator > Chatbot > Analytics`

**Charts:**
- Conversations over time
- Top searched products
- Response time trends
- Cache performance
- User engagement metrics

---

## 🧪 Testing Strategy

### Unit Tests

**Test Files:**
- `tests/Unit/Chatbot/QueryProcessorTest.php`
- `tests/Unit/Chatbot/ProductSearchEngineTest.php`
- `tests/Unit/Chatbot/ResponseGeneratorTest.php`
- `tests/Unit/Chatbot/ConversationManagerTest.php`
- `tests/Unit/Chatbot/PriceComparatorTest.php`

**Coverage Goals:**
- Core logic: 80%+
- API integration: 70%+
- Database operations: 75%+

### Integration Tests

**Test Scenarios:**
1. End-to-end message flow
2. Datafeedr API integration
3. AI API integration
4. Database operations
5. Caching behavior

### E2E Tests

**Test Scenarios:**
1. User starts conversation
2. User searches for product
3. User refines search
4. User views product details
5. User compares prices

---

## 🚀 Implementation Phases

### Phase 1: Foundation (Week 1-2)
**Goal**: Core infrastructure and basic functionality

**Tasks:**
- [ ] Create database tables
- [ ] Implement `ConversationManager`
- [ ] Implement `SessionManager`
- [ ] Create `ChatbotController` with basic endpoints
- [ ] Set up repository classes
- [ ] Create basic frontend widget
- [ ] Unit tests for core classes

**Deliverables:**
- Working session management
- Basic message storage
- Simple frontend chat widget

### Phase 2: AI Integration (Week 3-4)
**Goal**: AI-powered query processing and response generation

**Tasks:**
- [ ] Implement `QueryProcessor` with AI integration
- [ ] Implement `ResponseGenerator`
- [ ] Create AI prompt templates
- [ ] Integrate with existing `APIClient`
- [ ] Add context management
- [ ] Test AI responses

**Deliverables:**
- AI-powered query understanding
- Natural language responses
- Context-aware conversations

### Phase 3: Datafeedr Integration (Week 5-6)
**Goal**: Product search and price comparison

**Tasks:**
- [ ] Implement `ProductSearchEngine`
- [ ] Integrate with existing `Datafeedr` class
- [ ] Implement `PriceComparator`
- [ ] Add merchant comparison
- [ ] Implement caching layer
- [ ] Optimize API calls

**Deliverables:**
- Working product search
- Price comparison
- Best price identification

### Phase 4: Frontend Enhancement (Week 7-8)
**Goal**: Polished user interface

**Tasks:**
- [ ] Enhance chat widget UI
- [ ] Add product cards
- [ ] Implement typing indicators
- [ ] Add animations
- [ ] Mobile optimization
- [ ] Accessibility improvements

**Deliverables:**
- Beautiful, responsive chat interface
- Product display cards
- Smooth user experience

### Phase 5: Advanced Features (Week 9-10)
**Goal**: Advanced functionality and optimizations

**Tasks:**
- [ ] Query refinement
- [ ] Advanced filtering
- [ ] Product recommendations
- [ ] Analytics dashboard
- [ ] Performance optimization
- [ ] Security hardening

**Deliverables:**
- Advanced search features
- Analytics dashboard
- Optimized performance

### Phase 6: Testing & Polish (Week 11-12)
**Goal**: Comprehensive testing and final polish

**Tasks:**
- [ ] Comprehensive testing
- [ ] Bug fixes
- [ ] Performance tuning
- [ ] Documentation
- [ ] User acceptance testing
- [ ] Final optimizations

**Deliverables:**
- Production-ready chatbot
- Complete documentation
- Test coverage reports

---

## 📝 Code Examples

### Example 1: ChatbotController Endpoint

```php
<?php

namespace AEBG\Chatbot\API;

use AEBG\Chatbot\Core\ConversationManager;
use AEBG\Chatbot\Core\QueryProcessor;
use AEBG\Chatbot\Core\ProductSearchEngine;
use AEBG\Chatbot\Core\ResponseGenerator;
use AEBG\Core\Logger;

class ChatbotController extends \WP_REST_Controller {
    
    public function __construct() {
        $this->namespace = 'aebg/v1';
        $this->rest_base = 'chatbot';
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }
    
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/message',
            [
                'methods' => 'POST',
                'callback' => [ $this, 'handle_message' ],
                'permission_callback' => [ $this, 'permissions_check' ],
                'args' => [
                    'session_id' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'message' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ]
        );
    }
    
    public function handle_message( \WP_REST_Request $request ) {
        $session_id = $request->get_param( 'session_id' );
        $message = $request->get_param( 'message' );
        
        try {
            // Load conversation
            $conversation_manager = new ConversationManager();
            $context = $conversation_manager->getContext( $session_id );
            
            // Process query with AI
            $query_processor = new QueryProcessor();
            $search_params = $query_processor->processQuery( $message, $context );
            
            // Search products
            $search_engine = new ProductSearchEngine();
            $products = $search_engine->search( $search_params, 20 );
            
            if ( is_wp_error( $products ) ) {
                return new \WP_Error(
                    'search_failed',
                    $products->get_error_message(),
                    [ 'status' => 500 ]
                );
            }
            
            // Find best prices
            $price_comparator = new PriceComparator();
            $best_price = $price_comparator->findBestPrice( $products );
            
            // Generate response
            $response_generator = new ResponseGenerator();
            $response = $response_generator->generateResponse(
                $products,
                array_merge( $context, [ 'search_params' => $search_params ] ),
                $message
            );
            
            // Save messages
            $user_message_id = $conversation_manager->addMessage(
                $session_id,
                'user',
                $message
            );
            
            $assistant_message_id = $conversation_manager->addMessage(
                $session_id,
                'assistant',
                $response,
                [
                    'products' => $products,
                    'best_price' => $best_price,
                    'search_params' => $search_params,
                ]
            );
            
            // Update context
            $conversation_manager->updateContext( $session_id, [
                'last_search_params' => $search_params,
                'last_products' => array_column( $products, 'id' ),
            ] );
            
            return rest_ensure_response( [
                'success' => true,
                'data' => [
                    'message_id' => $assistant_message_id,
                    'response' => $response,
                    'products' => $products,
                    'best_price' => $best_price,
                    'suggestions' => $response_generator->generateSuggestions( $context ),
                ],
            ] );
            
        } catch ( \Exception $e ) {
            Logger::error( 'Chatbot message handling failed', [
                'error' => $e->getMessage(),
                'session_id' => $session_id,
            ] );
            
            return new \WP_Error(
                'internal_error',
                'An error occurred processing your message.',
                [ 'status' => 500 ]
            );
        }
    }
    
    public function permissions_check( $request ) {
        // Allow all users (guests and logged-in)
        // Could add rate limiting here
        return true;
    }
}
```

### Example 2: QueryProcessor Implementation

```php
<?php

namespace AEBG\Chatbot\Core;

use AEBG\Core\APIClient;
use AEBG\Core\Logger;

class QueryProcessor {
    
    private $ai_model = 'gpt-4o-mini'; // Fast and cost-effective
    private $api_key;
    
    public function __construct() {
        $options = get_option( 'aebg_settings' );
        $this->api_key = $options['openai_api_key'] ?? '';
    }
    
    public function processQuery( string $query, array $context = [] ): array {
        // Build prompt
        $prompt = $this->buildPrompt( $query, $context );
        
        // Call AI API
        $response = $this->callAI( $prompt );
        
        if ( is_wp_error( $response ) ) {
            Logger::error( 'Query processing failed', [
                'error' => $response->get_error_message(),
            ] );
            return $this->fallbackParse( $query );
        }
        
        // Parse AI response
        $params = json_decode( $response, true );
        
        if ( ! $params || ! is_array( $params ) ) {
            return $this->fallbackParse( $query );
        }
        
        return $this->normalizeParams( $params );
    }
    
    private function buildPrompt( string $query, array $context ): string {
        $context_str = ! empty( $context ) 
            ? json_encode( $context, JSON_PRETTY_PRINT )
            : 'None';
        
        return <<<PROMPT
You are a product search assistant. Analyze the user's query and extract search parameters.

User query: {$query}
Previous context: {$context_str}

Extract and return JSON with these fields:
{
  "product_name": "main product name or type",
  "category": "product category if mentioned",
  "min_price": number or null,
  "max_price": number or null,
  "currency": "USD",
  "brand": "brand name if mentioned",
  "features": ["feature1", "feature2"],
  "sort_by": "price" | "rating" | "relevance",
  "intent": "search" | "compare" | "filter" | "refine"
}

Return ONLY valid JSON, no other text.
PROMPT;
    }
    
    private function callAI( string $prompt ): string|\WP_Error {
        $endpoint = APIClient::getApiEndpoint( $this->ai_model );
        $body = APIClient::buildRequestBody( 
            $this->ai_model, 
            $prompt, 
            500,  // Short response
            0.3   // Lower temperature for structured output
        );
        
        $response = APIClient::makeRequest( 
            $endpoint, 
            $this->api_key, 
            $body, 
            30,   // 30s timeout
            2     // 2 retries
        );
        
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        
        return APIClient::extractContentFromResponse( $response, $this->ai_model );
    }
    
    private function fallbackParse( string $query ): array {
        // Simple keyword-based fallback
        return [
            'product_name' => $query,
            'category' => null,
            'min_price' => null,
            'max_price' => null,
            'currency' => 'USD',
            'brand' => null,
            'features' => [],
            'sort_by' => 'relevance',
            'intent' => 'search',
        ];
    }
    
    private function normalizeParams( array $params ): array {
        // Ensure all required fields exist with defaults
        return wp_parse_args( $params, [
            'product_name' => '',
            'category' => null,
            'min_price' => null,
            'max_price' => null,
            'currency' => 'USD',
            'brand' => null,
            'features' => [],
            'sort_by' => 'relevance',
            'intent' => 'search',
        ] );
    }
    
    public function buildSearchConditions( array $params ): array {
        $conditions = [];
        
        // Product name
        if ( ! empty( $params['product_name'] ) ) {
            $conditions[] = 'name LIKE ' . $this->sanitizeConditionValue( $params['product_name'] );
        }
        
        // Price range
        if ( isset( $params['min_price'] ) && $params['min_price'] > 0 ) {
            $conditions[] = 'price >= ' . intval( $params['min_price'] * 100 ); // Datafeedr uses cents
        }
        
        if ( isset( $params['max_price'] ) && $params['max_price'] > 0 ) {
            $conditions[] = 'price <= ' . intval( $params['max_price'] * 100 );
        }
        
        // Brand
        if ( ! empty( $params['brand'] ) ) {
            $conditions[] = 'brand LIKE ' . $this->sanitizeConditionValue( $params['brand'] );
        }
        
        // Category
        if ( ! empty( $params['category'] ) ) {
            $conditions[] = 'category LIKE ' . $this->sanitizeConditionValue( $params['category'] );
        }
        
        return $conditions;
    }
    
    private function sanitizeConditionValue( string $value ): string {
        // Remove quotes and special characters for Datafeedr
        $value = trim( $value );
        $value = str_replace( [ '"', "'", '%' ], '', $value );
        return $value;
    }
}
```

---

## 🔧 Configuration & Settings

### Admin Settings Page

**Location**: `AI Bulk Generator > Settings > Chatbot`

**Settings:**
1. **General**
   - Enable/disable chatbot
   - Default AI model
   - Max results per search
   - Session timeout

2. **AI Configuration**
   - OpenAI API key (reuse existing)
   - Model selection
   - Temperature settings
   - Max tokens

3. **Search Settings**
   - Default search limit
   - Price comparison enabled
   - Merchant filtering
   - Category preferences

4. **Display Settings**
   - Widget position
   - Theme (light/dark)
   - Welcome message
   - Product card style

5. **Performance**
   - Cache TTL settings
   - Rate limiting
   - API timeout settings

---

## 📚 Documentation Requirements

### Developer Documentation
- API endpoint documentation
- Class reference
- Integration guide
- Extension points

### User Documentation
- Setup guide
- Configuration guide
- Usage examples
- Troubleshooting

### Admin Documentation
- Analytics guide
- Settings explanation
- Best practices

---

## 🎯 Success Metrics

### Key Performance Indicators (KPIs)

1. **Engagement**
   - Average messages per conversation: > 5
   - Average session duration: > 2 minutes
   - Return user rate: > 30%

2. **Search Quality**
   - Successful search rate: > 90%
   - Average results per search: 5-20
   - User satisfaction: > 4/5

3. **Performance**
   - Average response time: < 3 seconds
   - Cache hit rate: > 60%
   - API error rate: < 1%

4. **Business Impact**
   - Products viewed per session: > 3
   - Click-through rate: > 15%
   - Conversion rate: Track and optimize

---

## 🚨 Risk Mitigation

### Identified Risks

1. **AI API Costs**
   - **Risk**: High API usage costs
   - **Mitigation**: 
     - Use efficient models (gpt-4o-mini)
     - Aggressive caching
     - Rate limiting
     - Cost monitoring

2. **Datafeedr API Limits**
   - **Risk**: API rate limits exceeded
   - **Mitigation**:
     - Leverage existing caching
     - Batch requests
     - Request-level caching
     - Fallback to cached data

3. **Performance Issues**
   - **Risk**: Slow response times
   - **Mitigation**:
     - Multi-layer caching
     - Async processing where possible
     - Database optimization
     - CDN for assets

4. **Scalability**
   - **Risk**: High traffic overload
   - **Mitigation**:
     - Rate limiting
     - Queue system (Action Scheduler)
     - Database indexing
     - Horizontal scaling preparation

---

## 🔄 Future Enhancements

### Phase 2 Features (Post-Launch)

1. **Advanced AI Features**
   - Multi-language support
   - Voice input/output
   - Image-based product search
   - Personalized recommendations

2. **Enhanced Search**
   - Price alerts
   - Price history tracking
   - Deal notifications
   - Wishlist functionality

3. **Integration**
   - WooCommerce integration
   - Email notifications
   - Social sharing
   - Affiliate link tracking

4. **Analytics**
   - Advanced analytics dashboard
   - A/B testing
   - User behavior tracking
   - Conversion optimization

---

## ✅ Checklist

### Pre-Development
- [ ] Review and approve plan
- [ ] Set up development environment
- [ ] Create feature branch
- [ ] Set up testing framework

### Development
- [ ] Database schema implementation
- [ ] Core classes implementation
- [ ] API endpoints implementation
- [ ] Frontend widget implementation
- [ ] Integration testing
- [ ] Unit testing
- [ ] Performance optimization

### Pre-Launch
- [ ] Security audit
- [ ] Performance testing
- [ ] User acceptance testing
- [ ] Documentation completion
- [ ] Training materials
- [ ] Backup and rollback plan

### Launch
- [ ] Deploy to staging
- [ ] Staging testing
- [ ] Production deployment
- [ ] Monitor metrics
- [ ] Gather feedback
- [ ] Iterate and improve

---

## 📞 Support & Maintenance

### Monitoring
- Error logging (existing Logger class)
- Performance monitoring
- API usage tracking
- User feedback collection

### Maintenance Tasks
- Regular cache cleanup
- Database optimization
- Security updates
- Feature enhancements

---

## 🎉 Conclusion

This plan provides a comprehensive, production-ready roadmap for implementing an AI-powered product search chatbot. The modular architecture ensures maintainability, the caching strategy optimizes performance, and the phased approach allows for iterative development and testing.

The chatbot will seamlessly integrate with existing systems (Datafeedr API, AI services) while providing a modern, user-friendly interface for product discovery and price comparison.

**Next Steps:**
1. Review and approve this plan
2. Set up development environment
3. Begin Phase 1 implementation
4. Regular progress reviews
5. Iterate based on feedback

---

---

## 🛡️ Safety & Isolation Guarantee

**CRITICAL**: The chatbot implementation is designed with **absolute isolation** from existing code. 

**Key Safety Features:**
- ✅ **8 layers of isolation** (namespace, hooks, routes, database, files, CSS/JS, variables, options)
- ✅ **4 layers of safety** (conditional loading, feature flags, error handling, dependency checks)
- ✅ **100% backward compatibility** (no existing code modified)
- ✅ **Instant rollback capability** (can be disabled immediately)

**Risk Level**: ✅ **ZERO** - Complete isolation with multiple safety layers

> **📖 Read**: **[CHATBOT_SAFETY_AND_ISOLATION_GUARANTEE.md](./CHATBOT_SAFETY_AND_ISOLATION_GUARANTEE.md)** for complete safety analysis and guarantees.

---

## 📚 Related Documents

- **[CHATBOT_SAFETY_AND_ISOLATION_GUARANTEE.md](./CHATBOT_SAFETY_AND_ISOLATION_GUARANTEE.md)**: **CRITICAL** - Absolute guarantees that chatbot will NEVER interfere with existing code
- **[CHATBOT_REUSABLE_COMPONENTS_ANALYSIS.md](./CHATBOT_REUSABLE_COMPONENTS_ANALYSIS.md)**: Comprehensive analysis of existing components that can be reused (saves ~5,750 lines of code)
- **[EMAIL_MARKETING_SYSTEM_IMPLEMENTATION_PLAN.md](./EMAIL_MARKETING_SYSTEM_IMPLEMENTATION_PLAN.md)**: Reference for modular architecture patterns

---

**Document Version**: 1.1  
**Last Updated**: 2024-01-15  
**Author**: AI Assistant  
**Status**: Ready for Review  
**Changes**: Added references to reusable components analysis

