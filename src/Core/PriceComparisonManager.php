<?php

namespace AEBG\Core;

/**
 * Price Comparison Manager Class
 * 
 * Handles price comparison functionality for products in bulk generation
 * This class is responsible for:
 * - Fetching merchant comparison data for products
 * - Calculating price statistics
 * - Filtering products based on price comparison criteria
 * - Caching comparison data to reduce API calls
 * 
 * @package AEBG\Core
 */
class PriceComparisonManager {
    
    /**
     * Datafeedr instance
     * 
     * @var Datafeedr
     */
    private $datafeedr;
    
    /**
     * Cache duration in seconds (30 days)
     * 
     * @var int
     */
    private $cache_duration = 2592000;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->datafeedr = new Datafeedr();
    }
    
    /**
     * Process price comparison for a list of products
     * 
     * @param array $products Array of products to process
     * @param array $options Processing options
     * @return array Processed products with price comparison data
     */
    public function processPriceComparison($products, $options = []) {
        if (empty($products) || !is_array($products)) {
            Logger::debug('PriceComparisonManager: No products to process');
            return [];
        }
        
        $default_options = [
            'enable_price_comparison' => true,
            'min_merchant_count' => 2,
            'max_merchant_count' => 10,
            'prefer_lower_prices' => true,
            'prefer_higher_ratings' => true,
            'price_variance_threshold' => 0.3, // 30% price difference threshold
            'cache_comparison_data' => true,
            'batch_size' => 5, // Process products in batches to avoid API limits
        ];
        
        $options = array_merge($default_options, $options);
        
        if (!$options['enable_price_comparison']) {
            Logger::info('PriceComparisonManager: Price comparison disabled');
            return $products;
        }
        
        // CRITICAL: Check timeout before starting price comparison
        // Price comparisons can take significant time, so we need to ensure we have enough time left
        $job_start_time = $GLOBALS['aebg_job_start_time'] ?? null;
        
        // CRITICAL: Log job start time status for debugging
        if ($job_start_time) {
            $current_time = microtime(true);
            $elapsed_time = $current_time - $job_start_time;
            $max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
            $time_remaining = $max_time - $elapsed_time;
            
            Logger::debug('PriceComparisonManager: Job start time found: ' . $job_start_time . ' (current: ' . $current_time . ', elapsed: ' . round($elapsed_time, 2) . 's)');
            
            // Estimate time needed: ~2-5 seconds per product (API call + processing)
            $estimated_time_needed = count($products) * 3; // Conservative estimate: 3s per product
            
            if ($time_remaining < $estimated_time_needed) {
                Logger::warning('Insufficient time remaining (' . round($time_remaining, 2) . 's) for price comparison (estimated: ' . $estimated_time_needed . 's). Skipping to prevent timeout.');
                return $products; // Return products without price comparison to prevent timeout
            }
            
            Logger::debug('PriceComparisonManager: Time remaining: ' . round($time_remaining, 2) . 's, estimated needed: ' . $estimated_time_needed . 's');
        } else {
            Logger::warning('PriceComparisonManager: No job start time found in $GLOBALS[\'aebg_job_start_time\']! Available globals: ' . implode(', ', array_keys(array_filter($GLOBALS, function($k) { return strpos($k, 'aebg') === 0; }, ARRAY_FILTER_USE_KEY))));
            Logger::warning('PriceComparisonManager: Proceeding without timeout check (this should not happen in normal operation)');
        }
        
        Logger::info('PriceComparisonManager: Processing ' . count($products) . ' products for price comparison');
        
        $processed_products = [];
        $batch_size = $options['batch_size'];
        
        // Process products in batches
        for ($i = 0; $i < count($products); $i += $batch_size) {
            // CRITICAL: Check timeout before each batch
            if ($job_start_time) {
                $elapsed_time = microtime(true) - $job_start_time;
                $max_time = \AEBG\Core\TimeoutManager::DEFAULT_TIMEOUT - \AEBG\Core\TimeoutManager::SAFETY_BUFFER;
                $time_remaining = $max_time - $elapsed_time;
                
                // If less than 10 seconds remaining, stop processing
                if ($time_remaining < 10) {
                    error_log('[AEBG] ⚠️ WARNING: Approaching timeout (' . round($time_remaining, 2) . 's remaining). Stopping price comparison processing.');
                    // Return what we have so far plus remaining products without comparison
                    $remaining_products = array_slice($products, $i);
                    $processed_products = array_merge($processed_products, $remaining_products);
                    break;
                }
            }
            
            try {
                $batch = array_slice($products, $i, $batch_size);
                Logger::info('PriceComparisonManager: Processing batch ' . ($i / $batch_size + 1) . ' of ' . ceil(count($products) / $batch_size) . ' (products ' . ($i + 1) . '-' . min($i + $batch_size, count($products)) . ')');
                $processed_batch = $this->processBatch($batch, $options);
                Logger::info('PriceComparisonManager: Batch ' . ($i / $batch_size + 1) . ' completed, processed ' . count($processed_batch) . ' products');
                
                if (is_array($processed_batch)) {
                    $processed_products = array_merge($processed_products, $processed_batch);
                } else {
                    Logger::warning('processBatch returned non-array: ' . gettype($processed_batch) . ', using original batch products');
                    $processed_products = array_merge($processed_products, $batch);
                }
            } catch (\Throwable $e) {
                Logger::error('ERROR in PriceComparisonManager batch processing: ' . $e->getMessage());
                Logger::debug('ERROR trace: ' . $e->getTraceAsString());
                // Add batch products without comparison data to prevent data loss
                $batch = array_slice($products, $i, $batch_size);
                $processed_products = array_merge($processed_products, $batch);
            }
            
            // Add small delay between batches to avoid API rate limits
            if ($i + $batch_size < count($products)) {
                usleep(100000); // 0.1 second delay
            }
        }
        
        Logger::info('PriceComparisonManager: Completed processing ' . count($processed_products) . ' products (out of ' . count($products) . ' input products)');
        
        // CRITICAL: Ensure we always return an array, even if empty
        if (!is_array($processed_products)) {
            Logger::error('CRITICAL: processed_products is not an array! Type: ' . gettype($processed_products) . ', returning original products');
            return $products;
        }
        
        // CRITICAL: If we lost products somehow, return original products to prevent data loss
        if (count($processed_products) < count($products)) {
            Logger::warning('Lost products during processing (' . count($processed_products) . ' < ' . count($products) . '), returning original products');
            return $products;
        }
        
        return $processed_products;
    }
    
    /**
     * Process a batch of products
     * 
     * @param array $batch Batch of products to process
     * @param array $options Processing options
     * @return array Processed batch
     */
    private function processBatch($batch, $options) {
        Logger::debug('PriceComparisonManager: Processing batch of ' . count($batch) . ' products');
        $processed_batch = [];
        $product_index = 0;
        
        foreach ($batch as $product) {
            $product_index++;
            if (!is_array($product) || empty($product['id'])) {
                Logger::debug('PriceComparisonManager: Skipping invalid product at index ' . $product_index);
                // Add original product to maintain count
                $processed_batch[] = $product;
                continue;
            }
            
            // CRITICAL: Add small delay before each API call (except first) to prevent connection pool exhaustion
            // When processing products in batches, rapid API calls can exhaust the connection pool
            // This delay allows the connection pool to recover between requests
            if ($product_index > 1) {
                $delay_microseconds = \AEBG\Core\SettingsHelper::getDelayBetweenRequestsMicroseconds();
                $delay_seconds = \AEBG\Core\SettingsHelper::getDelayBetweenRequests();
                Logger::debug('PriceComparisonManager: Adding ' . $delay_seconds . 's delay before API call for product ' . $product_index . ' to prevent connection pool exhaustion');
                usleep($delay_microseconds);
                // Force garbage collection every 5 products to help free up connections
                if ($product_index % 5 === 0) {
                    gc_collect_cycles();
                    Logger::debug('PriceComparisonManager: Garbage collection performed after product ' . $product_index);
                }
                Logger::debug('PriceComparisonManager: Delay completed, proceeding with API call');
            }
            
            Logger::debug('PriceComparisonManager: Processing product ' . $product_index . '/' . count($batch) . ': ' . ($product['name'] ?? $product['id']));
            $product_start = microtime(true);
            
            try {
                $processed_product = $this->processSingleProduct($product, $options);
                
                $product_elapsed = microtime(true) - $product_start;
                Logger::debug('PriceComparisonManager: Completed product ' . $product_index . ' in ' . round($product_elapsed, 2) . ' seconds');
                
                if ($processed_product && is_array($processed_product)) {
                    $processed_batch[] = $processed_product;
                } else {
                    Logger::warning('PriceComparisonManager: Product ' . $product_index . ' returned invalid result, using original product');
                    $processed_batch[] = $product; // Use original product if processing failed
                }
            } catch (\Throwable $e) {
                Logger::error('ERROR processing product ' . $product_index . ': ' . $e->getMessage());
                Logger::debug('ERROR trace: ' . $e->getTraceAsString());
                // Add original product to maintain count and prevent data loss
                $processed_batch[] = $product;
            }
        }
        
        Logger::debug('PriceComparisonManager: Batch processing completed - ' . count($processed_batch) . ' products processed (expected: ' . count($batch) . ')');
        
        // CRITICAL: Ensure we return the same number of products we started with
        if (count($processed_batch) !== count($batch)) {
            Logger::warning('Batch count mismatch! Expected ' . count($batch) . ', got ' . count($processed_batch));
        }
        
        return $processed_batch;
    }
    
    /**
     * Process a single product for price comparison
     * 
     * @param array $product Product data
     * @param array $options Processing options
     * @return array|false Processed product or false on failure
     */
    private function processSingleProduct($product, $options) {
        $product_id = $product['id'];
        Logger::debug('PriceComparisonManager: processSingleProduct started for product ' . $product_id);
        
        // Check cache first
        if ($options['cache_comparison_data']) {
            $cached_data = $this->getCachedComparisonData($product_id);
            if ($cached_data !== false) {
                Logger::debug('PriceComparisonManager: Using cached data for product ' . $product_id);
                // CRITICAL: Even if using cached data, ensure it's saved to database
                $this->saveComparisonToDatabase($product_id, $cached_data, $options);
                return $this->mergeComparisonData($product, $cached_data);
            }
        }
        
        // Fetch fresh comparison data
        Logger::debug('PriceComparisonManager: Fetching fresh comparison data for product ' . $product_id);
        $comparison_data = $this->fetchComparisonData($product, $options);
        
        if (!$comparison_data) {
            Logger::warning('PriceComparisonManager: Failed to fetch comparison data for product ' . $product_id);
            return $product; // Return original product if comparison fails
        }
        
        Logger::debug('PriceComparisonManager: Successfully fetched comparison data for product ' . $product_id);
        
        // Cache the comparison data
        if ($options['cache_comparison_data']) {
            Logger::debug('PriceComparisonManager: Caching comparison data for product ' . $product_id);
            $this->cacheComparisonData($product_id, $comparison_data);
        }
        
        // CRITICAL: Save comparison data to database so shortcode can retrieve it
        // This ensures price comparison data is available even if post_id is not yet known
        $this->saveComparisonToDatabase($product_id, $comparison_data, $options);
        
        Logger::debug('PriceComparisonManager: Merging comparison data for product ' . $product_id);
        $merged = $this->mergeComparisonData($product, $comparison_data);
        Logger::debug('PriceComparisonManager: processSingleProduct completed for product ' . $product_id);
        
        return $merged;
    }
    
    /**
     * Fetch comparison data for a product
     * 
     * @param array $product Product data
     * @param array $options Processing options
     * @return array|false Comparison data or false on failure
     */
    private function fetchComparisonData($product, $options) {
        try {
            $merchant_limit = $options['max_merchant_count'];
            
            // Use Datafeedr to get merchant comparison
            Logger::debug('PriceComparisonManager: Calling get_merchant_comparison for product ' . ($product['id'] ?? 'unknown'));
            $merchant_data = $this->datafeedr->get_merchant_comparison($product, $merchant_limit);
            Logger::debug('PriceComparisonManager: get_merchant_comparison returned for product ' . ($product['id'] ?? 'unknown'));
            
            if (is_wp_error($merchant_data)) {
                Logger::error('PriceComparisonManager: Datafeedr error: ' . $merchant_data->get_error_message());
                return false;
            }
            
            if (empty($merchant_data['merchants'])) {
                Logger::debug('PriceComparisonManager: No merchant data found for product ' . ($product['id'] ?? 'unknown'));
                return false;
            }
            
            Logger::debug('PriceComparisonManager: Processing ' . count($merchant_data['merchants']) . ' merchants for product ' . ($product['id'] ?? 'unknown'));
            
            // Calculate price statistics
            Logger::debug('PriceComparisonManager: Calculating price statistics');
            $price_stats = $this->calculatePriceStatistics($merchant_data['merchants']);
            Logger::debug('PriceComparisonManager: Price statistics calculated');
            
            // Determine if this product has good price competition
            Logger::debug('PriceComparisonManager: Checking price competition');
            $has_competition = $this->hasPriceCompetition($merchant_data['merchants'], $price_stats, $options);
            Logger::debug('PriceComparisonManager: Price competition check completed');
            
            return [
                'merchants' => $merchant_data['merchants'],
                'price_statistics' => $price_stats,
                'has_price_competition' => $has_competition,
                'merchant_count' => count($merchant_data['merchants']),
                'price_variance' => $price_stats['variance'],
                'price_range' => [
                    'lowest' => $price_stats['min'],
                    'highest' => $price_stats['max'],
                    'average' => $price_stats['average']
                ]
            ];
            
        } catch (\Exception $e) {
            Logger::error('PriceComparisonManager: Exception while fetching comparison data: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calculate price statistics from merchant data
     * 
     * @param array $merchants Array of merchant data
     * @return array Price statistics
     */
    private function calculatePriceStatistics($merchants) {
        $prices = [];
        
        foreach ($merchants as $merchant) {
            $price = floatval($merchant['lowest_price'] ?? 0);
            if ($price > 0) {
                $prices[] = $price;
            }
        }
        
        if (empty($prices)) {
            return [
                'min' => 0,
                'max' => 0,
                'average' => 0,
                'median' => 0,
                'variance' => 0,
                'count' => 0
            ];
        }
        
        sort($prices);
        $count = count($prices);
        $sum = array_sum($prices);
        $average = $sum / $count;
        $median = $count % 2 === 0 ? 
            ($prices[$count/2 - 1] + $prices[$count/2]) / 2 : 
            $prices[floor($count/2)];
        
        // Calculate variance
        $variance = 0;
        foreach ($prices as $price) {
            $variance += pow($price - $average, 2);
        }
        $variance = $count > 1 ? $variance / ($count - 1) : 0;
        
        return [
            'min' => min($prices),
            'max' => max($prices),
            'average' => round($average, 2),
            'median' => round($median, 2),
            'variance' => round($variance, 2),
            'count' => $count
        ];
    }
    
    /**
     * Determine if a product has good price competition
     * 
     * @param array $merchants Array of merchant data
     * @param array $price_stats Price statistics
     * @param array $options Processing options
     * @return bool True if product has good competition
     */
    private function hasPriceCompetition($merchants, $price_stats, $options) {
        // Check minimum merchant count
        if ($price_stats['count'] < $options['min_merchant_count']) {
            return false;
        }
        
        // Check price variance threshold
        if ($price_stats['average'] > 0) {
            $coefficient_of_variation = sqrt($price_stats['variance']) / $price_stats['average'];
            if ($coefficient_of_variation < $options['price_variance_threshold']) {
                return false; // Prices are too similar, no real competition
            }
        }
        
        // Check if there's a reasonable price spread
        $price_spread = $price_stats['max'] - $price_stats['min'];
        $price_spread_percentage = $price_stats['average'] > 0 ? 
            ($price_spread / $price_stats['average']) * 100 : 0;
        
        // Consider it competitive if there's at least 10% price spread
        return $price_spread_percentage >= 10;
    }
    
    /**
     * Merge comparison data with product data
     * 
     * @param array $product Original product data
     * @param array $comparison_data Comparison data
     * @return array Merged product data
     */
    private function mergeComparisonData($product, $comparison_data) {
        return array_merge($product, [
            'price_comparison' => $comparison_data,
            'merchant_count' => $comparison_data['merchant_count'],
            'has_price_competition' => $comparison_data['has_price_competition'],
            'price_variance' => $comparison_data['price_variance'],
            'price_range' => $comparison_data['price_range']
        ]);
    }
    
    /**
     * Get cached comparison data
     * 
     * @param string $product_id Product ID
     * @return array|false Cached data or false if not found/expired
     */
    private function getCachedComparisonData($product_id) {
        $cache_key = 'aebg_price_comparison_' . md5($product_id);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data === false) {
            return false;
        }
        
        return $cached_data;
    }
    
    /**
     * Cache comparison data
     * 
     * @param string $product_id Product ID
     * @param array $comparison_data Comparison data to cache
     * @return bool True on success, false on failure
     */
    private function cacheComparisonData($product_id, $comparison_data) {
        $cache_key = 'aebg_price_comparison_' . md5($product_id);
        return set_transient($cache_key, $comparison_data, $this->cache_duration);
    }
    
    /**
     * Save comparison data to database
     * 
     * CRITICAL: This ensures price comparison data is available for shortcode rendering
     * even if the post_id is not yet known during bulk generation.
     * The post_id will be linked later via updateComparisonRecordsWithPostId()
     * 
     * @param string $product_id Product ID
     * @param array $comparison_data Comparison data to save
     * @param array $options Processing options (may contain user_id and post_id)
     * @return bool|int Returns comparison ID on success, false on failure
     */
    private function saveComparisonToDatabase($product_id, $comparison_data, $options) {
        // Get user_id from options or try to get current user
        $user_id = $options['user_id'] ?? null;
        if (!$user_id) {
            // Try to get from global context (during bulk generation)
            $user_id = $GLOBALS['aebg_author_id'] ?? get_current_user_id();
        }
        
        // If still no user_id, we can't save (but log warning)
        if (!$user_id || $user_id <= 0) {
            Logger::warning('PriceComparisonManager: Cannot save comparison data - no valid user_id found for product ' . $product_id);
            return false;
        }
        
        // post_id may be null during bulk generation (will be linked later)
        $post_id = $options['post_id'] ?? null;
        
        // Prepare comparison data in the format expected by ComparisonManager
        // The comparison_data from fetchComparisonData already has 'merchants' key
        // which is what ComparisonManager expects
        $formatted_data = [
            'merchants' => $comparison_data['merchants'] ?? [],
            'price_range' => $comparison_data['price_range'] ?? [],
            'price_statistics' => $comparison_data['price_statistics'] ?? [],
        ];
        
        // Save to database via ComparisonManager
        try {
            $comparison_id = ComparisonManager::save_comparison(
                $user_id,
                $post_id, // May be null - will be updated later
                $product_id,
                'Price Comparison (Bulk Generation)',
                $formatted_data
            );
            
            if ($comparison_id !== false) {
                Logger::debug('PriceComparisonManager: Successfully saved comparison data to database for product ' . $product_id . ' (comparison_id: ' . $comparison_id . ')');
            } else {
                Logger::warning('PriceComparisonManager: Failed to save comparison data to database for product ' . $product_id);
            }
            
            return $comparison_id;
        } catch (\Exception $e) {
            Logger::error('PriceComparisonManager: Exception saving comparison data to database: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Filter products based on price comparison criteria
     * 
     * @param array $products Array of products
     * @param array $criteria Filter criteria
     * @return array Filtered products
     */
    public function filterByPriceComparison($products, $criteria = []) {
        $default_criteria = [
            'min_merchant_count' => 2,
            'require_price_competition' => true,
            'max_price_variance' => 0.5,
            'prefer_lower_prices' => true
        ];
        
        $criteria = array_merge($default_criteria, $criteria);
        
        return array_filter($products, function($product) use ($criteria) {
            // Skip products without price comparison data
            if (!isset($product['price_comparison'])) {
                return false;
            }
            
            $comparison = $product['price_comparison'];
            
            // Check minimum merchant count
            if ($comparison['merchant_count'] < $criteria['min_merchant_count']) {
                return false;
            }
            
            // Check price competition requirement
            if ($criteria['require_price_competition'] && !$comparison['has_price_competition']) {
                return false;
            }
            
            // Check price variance
            if ($comparison['price_variance'] > $criteria['max_price_variance']) {
                return false;
            }
            
            return true;
        });
    }
    
    /**
     * Sort products by price comparison criteria
     * 
     * @param array $products Array of products
     * @param array $sort_criteria Sort criteria
     * @return array Sorted products
     */
    public function sortByPriceComparison($products, $sort_criteria = []) {
        $default_criteria = [
            'primary' => 'merchant_count', // merchant_count, price_variance, has_price_competition
            'secondary' => 'price_variance',
            'direction' => 'desc' // asc, desc
        ];
        
        $sort_criteria = array_merge($default_criteria, $sort_criteria);
        
        usort($products, function($a, $b) use ($sort_criteria) {
            $primary_a = $this->getSortValue($a, $sort_criteria['primary']);
            $primary_b = $this->getSortValue($b, $sort_criteria['primary']);
            
            if ($primary_a !== $primary_b) {
                return $sort_criteria['direction'] === 'desc' ? 
                    $primary_b <=> $primary_a : 
                    $primary_a <=> $primary_b;
            }
            
            // Secondary sort
            $secondary_a = $this->getSortValue($a, $sort_criteria['secondary']);
            $secondary_b = $this->getSortValue($b, $sort_criteria['secondary']);
            
            return $sort_criteria['direction'] === 'desc' ? 
                $secondary_b <=> $secondary_a : 
                $secondary_a <=> $secondary_b;
        });
        
        return $products;
    }
    
    /**
     * Get sort value for a product
     * 
     * @param array $product Product data
     * @param string $field Field name
     * @return mixed Sort value
     */
    private function getSortValue($product, $field) {
        switch ($field) {
            case 'merchant_count':
                return $product['merchant_count'] ?? 0;
            case 'price_variance':
                return $product['price_variance'] ?? 0;
            case 'has_price_competition':
                return $product['has_price_competition'] ?? false ? 1 : 0;
            case 'price_range_average':
                return $product['price_range']['average'] ?? 0;
            case 'price_range_lowest':
                return $product['price_range']['lowest'] ?? 0;
            default:
                return 0;
        }
    }
    
    /**
     * Get price comparison summary for a list of products
     * 
     * @param array $products Array of products
     * @return array Summary statistics
     */
    public function getPriceComparisonSummary($products) {
        $summary = [
            'total_products' => count($products),
            'products_with_comparison' => 0,
            'products_with_competition' => 0,
            'average_merchant_count' => 0,
            'average_price_variance' => 0,
            'price_ranges' => []
        ];
        
        $merchant_counts = [];
        $price_variances = [];
        
        foreach ($products as $product) {
            if (isset($product['price_comparison'])) {
                $summary['products_with_comparison']++;
                
                if ($product['has_price_competition']) {
                    $summary['products_with_competition']++;
                }
                
                $merchant_counts[] = $product['merchant_count'];
                $price_variances[] = $product['price_variance'];
                
                if (isset($product['price_range'])) {
                    $summary['price_ranges'][] = $product['price_range'];
                }
            }
        }
        
        if (!empty($merchant_counts)) {
            $summary['average_merchant_count'] = round(array_sum($merchant_counts) / count($merchant_counts), 2);
        }
        
        if (!empty($price_variances)) {
            $summary['average_price_variance'] = round(array_sum($price_variances) / count($price_variances), 2);
        }
        
        return $summary;
    }
} 