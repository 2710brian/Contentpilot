<?php

namespace AEBG\Core;

/**
 * Trustpilot Scraper Class
 * 
 * Handles scraping Trustpilot merchant ratings from their public pages.
 * Extracts rating scores and review counts, caches results in database.
 * 
 * @package AEBG\Core
 */
class TrustpilotScraper {
    
    /**
     * Cache duration in days
     * 
     * @var int
     */
    private $cache_duration = 7;
    
    /**
     * Constructor
     * 
     * @param int $cache_duration Cache duration in days (default: 7)
     */
    public function __construct($cache_duration = 7) {
        $this->cache_duration = max(1, intval($cache_duration)); // Minimum 1 day
    }
    
    /**
     * Get Trustpilot rating for a merchant
     * 
     * @param string $merchant_name Merchant name
     * @param string $merchant_url Merchant website URL (to extract domain)
     * @return array|false Rating data with 'rating', 'review_count', 'fetched_at' or false on failure
     */
    public function get_merchant_rating($merchant_name, $merchant_url = null) {
        if (empty($merchant_name)) {
            Logger::warning('TrustpilotScraper: Empty merchant name provided');
            return false;
        }
        
        // 1. Check cache first
        $cached = $this->get_cached_rating($merchant_name);
        if ($cached !== false) {
            Logger::debug('TrustpilotScraper: Using cached rating for ' . $merchant_name);
            return $cached;
        }
        
        // 2. Extract domain from URL
        $domain = $this->extract_domain($merchant_url);
        if (!$domain) {
            Logger::warning('TrustpilotScraper: Could not extract domain from URL for ' . $merchant_name . ' (URL: ' . ($merchant_url ?? 'null') . ')');
            // Cache "not found" to avoid repeated attempts
            $this->cache_not_found($merchant_name);
            return false;
        }
        
        // 3. Build Trustpilot URL
        $trustpilot_url = $this->build_trustpilot_url($domain);
        Logger::info('TrustpilotScraper: Fetching rating for domain "' . $domain . '" from ' . $trustpilot_url);
        
        // 4. Scrape the page (pass domain to help with parsing)
        $rating_data = $this->scrape_trustpilot_page($trustpilot_url, $domain);
        
        // 5. Cache result (even if false, to avoid repeated failed attempts)
        if ($rating_data) {
            $this->cache_rating($merchant_name, $rating_data);
            Logger::info('TrustpilotScraper: Successfully fetched and cached rating for ' . $merchant_name . ' (' . $rating_data['rating'] . '/5, ' . $rating_data['review_count'] . ' reviews)');
        } else {
            // Cache "not found" to avoid repeated failed attempts
            $this->cache_not_found($merchant_name);
            Logger::warning('TrustpilotScraper: Failed to fetch rating for ' . $merchant_name . ' from ' . $trustpilot_url);
        }
        
        return $rating_data;
    }
    
    /**
     * Extract domain from URL
     * 
     * @param string $url Merchant website URL
     * @return string|false Domain name or false on failure
     */
    private function extract_domain($url) {
        if (empty($url)) {
            return false;
        }
        
        // Parse URL
        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return false;
        }
        
        $host = $parsed['host'];
        
        // Remove www. prefix
        $host = preg_replace('/^www\./', '', $host);
        
        // Remove port if present
        $host = preg_replace('/:[0-9]+$/', '', $host);
        
        // Validate domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/i', $host)) {
            Logger::warning('TrustpilotScraper: Invalid domain format: ' . $host);
            return false;
        }
        
        return strtolower($host);
    }
    
    /**
     * Build Trustpilot URL from domain
     * 
     * @param string $domain Domain name
     * @return string Trustpilot URL
     */
    private function build_trustpilot_url($domain) {
        return 'https://www.trustpilot.com/review/' . urlencode($domain);
    }
    
    /**
     * Scrape Trustpilot page for rating
     * 
     * @param string $url Trustpilot page URL
     * @param string $domain Domain name (to verify we're parsing the correct company)
     * @return array|false Rating data or false on failure
     */
    private function scrape_trustpilot_page($url, $domain = '') {
        $scrape_start = microtime(true);
        $max_scrape_time = 10; // 10 seconds max for scraping
        
        // Fetch page with wp_remote_get (similar to ProductImageManager)
        $response = wp_remote_get($url, [
            'timeout' => $max_scrape_time,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Connection' => 'close', // Force connection close to prevent reuse
                'Cache-Control' => 'no-cache',
            ],
            'cookies' => false, // Don't send cookies (fresh request)
            'sslverify' => true,
            'redirection' => 5, // Allow up to 5 redirects
        ]);
        
        // Check if scrape took too long (watchdog)
        $scrape_elapsed = microtime(true) - $scrape_start;
        if ($scrape_elapsed > ($max_scrape_time + 2)) {
            Logger::warning('TrustpilotScraper: Scrape exceeded timeout (' . round($scrape_elapsed, 2) . 's > ' . $max_scrape_time . 's) for ' . $url);
            return false;
        }
        
        if (is_wp_error($response)) {
            Logger::error('TrustpilotScraper: Failed to fetch ' . $url . ': ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            if ($status_code === 404) {
                Logger::debug('TrustpilotScraper: Trustpilot page not found (404) for ' . $url);
            } else {
                Logger::warning('TrustpilotScraper: HTTP ' . $status_code . ' for ' . $url);
            }
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            Logger::warning('TrustpilotScraper: Empty response body for ' . $url);
            return false;
        }
        
        // Parse HTML to extract rating (pass domain to help identify correct company)
        $rating_data = $this->parse_trustpilot_html($html, $url, $domain);
        
        if (!$rating_data) {
            Logger::warning('TrustpilotScraper: Could not parse rating from HTML for ' . $url . ' (domain: ' . $domain . ')');
        } else {
            Logger::info('TrustpilotScraper: Successfully parsed rating for ' . $domain . ': ' . $rating_data['rating'] . '/5, ' . $rating_data['review_count'] . ' reviews');
        }
        
        return $rating_data;
    }
    
    /**
     * Parse HTML to extract rating and review count
     * Uses multiple fallback methods for reliability
     * Prioritizes main company rating over related companies
     * 
     * @param string $html HTML content
     * @param string $url URL (for logging)
     * @param string $domain Domain name to verify we're getting the right company
     * @return array|false Rating data or false on failure
     */
    private function parse_trustpilot_html($html, $url = '', $domain = '') {
        // Method 1: Look for JSON-LD structured data (most reliable)
        // This should contain the main company's rating
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $json_ld_string) {
                $json_ld = json_decode(trim($json_ld_string), true);
                
                // Handle both single objects and arrays
                if (is_array($json_ld) && isset($json_ld[0])) {
                    $json_ld = $json_ld[0];
                }
                
                if ($json_ld && isset($json_ld['aggregateRating'])) {
                    $rating = floatval($json_ld['aggregateRating']['ratingValue'] ?? 0);
                    $review_count = intval($json_ld['aggregateRating']['reviewCount'] ?? 0);
                    
                    if ($rating > 0 && $rating <= 5 && $review_count > 0) {
                        Logger::debug('TrustpilotScraper: Found rating via JSON-LD: ' . $rating . '/5, ' . $review_count . ' reviews');
                        return [
                            'rating' => round($rating, 1),
                            'review_count' => $review_count,
                            'fetched_at' => current_time('mysql'),
                        ];
                    }
                }
            }
        }
        
        // Method 2: Look for main TrustScore pattern (most specific)
        // Pattern: "TrustScore X out of 5" followed by rating number and review count
        // This appears in the main company section, not related companies
        if (preg_match('/TrustScore\s+([0-9])\s+out\s+of\s+5[^>]*>.*?([0-9]+\.[0-9]+).*?([0-9,]+)\s+reviews?/is', $html, $matches)) {
            $rating = floatval($matches[2]);
            $review_count = intval(str_replace(',', '', $matches[3]));
            
            if ($rating > 0 && $rating <= 5 && $review_count > 0) {
                Logger::debug('TrustpilotScraper: Found rating via TrustScore pattern: ' . $rating . '/5, ' . $review_count . ' reviews');
                return [
                    'rating' => round($rating, 1),
                    'review_count' => $review_count,
                    'fetched_at' => current_time('mysql'),
                ];
            }
        }
        
        // Method 3: Look for main heading with review count, then find rating nearby
        // Pattern: "# CompanyName Reviews X" followed by rating
        if (preg_match('/#\s+[^<]+Reviews\s+([0-9,]+)[^<]*<[^>]*>.*?([0-9]+\.[0-9]+)\s*(?:out\s+of\s+5|\/5|TrustScore)/is', $html, $matches)) {
            $review_count = intval(str_replace(',', '', $matches[1]));
            $rating = floatval($matches[2]);
            
            if ($rating > 0 && $rating <= 5 && $review_count > 0) {
                Logger::debug('TrustpilotScraper: Found rating via heading pattern: ' . $rating . '/5, ' . $review_count . ' reviews');
                return [
                    'rating' => round($rating, 1),
                    'review_count' => $review_count,
                    'fetched_at' => current_time('mysql'),
                ];
            }
        }
        
        // Method 4: Look for "Excellent" or rating text followed by TrustScore and review count
        // This pattern appears in the main company section
        if (preg_match('/(?:Excellent|Great|Average|Poor|Bad)[^<]*<[^>]*>.*?([0-9]+\.[0-9]+).*?TrustScore[^<]*<[^>]*>.*?([0-9,]+)\s+reviews?/is', $html, $matches)) {
            $rating = floatval($matches[1]);
            $review_count = intval(str_replace(',', '', $matches[2]));
            
            if ($rating > 0 && $rating <= 5 && $review_count > 0) {
                Logger::debug('TrustpilotScraper: Found rating via Excellent/TrustScore pattern: ' . $rating . '/5, ' . $review_count . ' reviews');
                return [
                    'rating' => round($rating, 1),
                    'review_count' => $review_count,
                    'fetched_at' => current_time('mysql'),
                ];
            }
        }
        
        // Method 5: Look for data-rating attribute in main content (not sidebar)
        // Exclude sections that might contain related companies
        $main_content = $html;
        // Try to extract main content area (before "People also looked at" or similar)
        // Also exclude any sections mentioning different domains
        if (preg_match('/(.*?)(?:People also looked at|Suggested companies|Related companies)/is', $html, $content_match)) {
            $main_content = $content_match[1];
        }
        
        // Additional safety: if we have a domain, try to ensure we're in the main section
        // Look for the domain in the URL or page structure to identify main content
        if (!empty($domain)) {
            // The main content should contain the domain in links or text
            // Related companies will have different domains
            $domain_escaped = preg_quote($domain, '/');
            // Try to find content section that contains our domain before related companies
            if (preg_match('/(.*?review\/' . $domain_escaped . '.*?)(?:People also looked at|Suggested companies|Related companies)/is', $html, $domain_match)) {
                // We found the domain before related companies section - use that as main content
                $main_content = $domain_match[1];
            } elseif (preg_match('/(.*?' . $domain_escaped . '.*?)(?:People also looked at|Suggested companies|Related companies)/is', $html, $domain_match)) {
                // Fallback: find domain anywhere before related companies
                $main_content = $domain_match[1];
            }
        }
        
        if (preg_match('/data-rating=["\']([0-9.]+)["\']/', $main_content, $matches)) {
            $rating = floatval($matches[1]);
            
            if ($rating > 0 && $rating <= 5) {
                // Look for review count in the same section
                $review_count = 0;
                // Look for pattern like "293 reviews" near the rating
                if (preg_match('/data-rating=["\']' . preg_quote($matches[1], '/') . '["\'][^>]*>.*?([0-9,]+)\s+reviews?/is', $main_content, $review_matches)) {
                    $review_count = intval(str_replace(',', '', $review_matches[1]));
                } elseif (preg_match('/([0-9,]+)\s+reviews?/i', $main_content, $review_matches)) {
                    // Get the first substantial review count (likely the main one)
                    $potential_count = intval(str_replace(',', '', $review_matches[1]));
                    if ($potential_count > 10) { // Filter out small numbers that might be from related companies
                        $review_count = $potential_count;
                    }
                }
                
                if ($review_count > 0) {
                    Logger::debug('TrustpilotScraper: Found rating via data-rating attribute: ' . $rating . '/5, ' . $review_count . ' reviews');
                    return [
                        'rating' => round($rating, 1),
                        'review_count' => $review_count,
                        'fetched_at' => current_time('mysql'),
                    ];
                }
            }
        }
        
        // Method 6: Fallback - look for largest review count with rating
        // This helps identify the main company vs related companies
        $all_ratings = [];
        if (preg_match_all('/([0-9]+\.[0-9]+).*?([0-9,]+)\s+reviews?/i', $html, $all_matches, PREG_SET_ORDER)) {
            foreach ($all_matches as $match) {
                $potential_rating = floatval($match[1]);
                $potential_count = intval(str_replace(',', '', $match[2]));
                
                if ($potential_rating > 0 && $potential_rating <= 5 && $potential_count > 0) {
                    $all_ratings[] = [
                        'rating' => $potential_rating,
                        'review_count' => $potential_count,
                    ];
                }
            }
            
            // Sort by review count descending, take the largest (main company)
            if (!empty($all_ratings)) {
                usort($all_ratings, function($a, $b) {
                    return $b['review_count'] - $a['review_count'];
                });
                
                $main_rating = $all_ratings[0];
                Logger::debug('TrustpilotScraper: Found rating via largest review count: ' . $main_rating['rating'] . '/5, ' . $main_rating['review_count'] . ' reviews');
                return [
                    'rating' => round($main_rating['rating'], 1),
                    'review_count' => $main_rating['review_count'],
                    'fetched_at' => current_time('mysql'),
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Get cached rating from database
     * 
     * @param string $merchant_name Merchant name
     * @return array|false Cached rating data or false if not found/expired
     */
    private function get_cached_rating($merchant_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        
        if (!$table_exists) {
            Logger::debug('TrustpilotScraper: Table does not exist yet, skipping cache check');
            return false;
        }
        
        $cached = $wpdb->get_row($wpdb->prepare(
            "SELECT rating, review_count, last_updated FROM {$table_name} 
             WHERE merchant_name = %s 
             AND last_updated > DATE_SUB(NOW(), INTERVAL %d DAY)
             AND rating IS NOT NULL
             LIMIT 1",
            $merchant_name,
            $this->cache_duration
        ), ARRAY_A);
        
        if ($cached && isset($cached['rating'])) {
            return [
                'rating' => floatval($cached['rating']),
                'review_count' => intval($cached['review_count'] ?? 0),
                'fetched_at' => $cached['last_updated'],
            ];
        }
        
        return false;
    }
    
    /**
     * Cache rating in database
     * 
     * @param string $merchant_name Merchant name
     * @param array $rating_data Rating data with 'rating', 'review_count', 'fetched_at'
     * @return bool True on success, false on failure
     */
    private function cache_rating($merchant_name, $rating_data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        
        if (!$table_exists) {
            Logger::warning('TrustpilotScraper: Table does not exist, cannot cache rating');
            return false;
        }
        
        $result = $wpdb->replace($table_name, [
            'merchant_name' => $merchant_name,
            'rating' => $rating_data['rating'],
            'review_count' => $rating_data['review_count'] ?? 0,
            'last_updated' => current_time('mysql'),
        ], [
            '%s', // merchant_name
            '%f', // rating
            '%d', // review_count
            '%s', // last_updated
        ]);
        
        if ($result === false) {
            Logger::error('TrustpilotScraper: Failed to cache rating for ' . $merchant_name . ': ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Cache "not found" result to avoid repeated failed attempts
     * 
     * @param string $merchant_name Merchant name
     * @return bool True on success
     */
    private function cache_not_found($merchant_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        
        if (!$table_exists) {
            return false;
        }
        
        // Cache with NULL rating to indicate "not found"
        // This prevents repeated scraping attempts for merchants without Trustpilot pages
        // Cache for shorter duration (1 day) for "not found" results
        $wpdb->replace($table_name, [
            'merchant_name' => $merchant_name,
            'rating' => null,
            'review_count' => null,
            'last_updated' => current_time('mysql'),
        ], [
            '%s', // merchant_name
            '%f', // rating (NULL)
            '%d', // review_count (NULL)
            '%s', // last_updated
        ]);
        
        return true;
    }
    
    /**
     * Batch enrich merchants with Trustpilot ratings
     * 
     * @param array $merchants Merchants array from Datafeedr (keyed by merchant name)
     * @return array Enriched merchants array
     */
    public function enrich_merchants($merchants) {
        if (!is_array($merchants) || empty($merchants)) {
            return $merchants;
        }
        
        Logger::debug('TrustpilotScraper: Enriching ' . count($merchants) . ' merchants with Trustpilot ratings');
        
        $enriched_count = 0;
        $cached_count = 0;
        $failed_count = 0;
        
        foreach ($merchants as $merchant_name => &$merchant_data) {
            // Ensure merchant_data is an array
            if (!is_array($merchant_data)) {
                continue;
            }
            
            $merchant_url = $merchant_data['url'] ?? '';
            
            // Get rating (will use cache if available)
            $rating_data = $this->get_merchant_rating($merchant_name, $merchant_url);
            
            if ($rating_data) {
                $merchant_data['trustpilot_rating'] = $rating_data['rating'];
                $merchant_data['trustpilot_review_count'] = $rating_data['review_count'];
                $enriched_count++;
                
                // Check if this was from cache
                $cached = $this->get_cached_rating($merchant_name);
                if ($cached && $cached['fetched_at'] !== $rating_data['fetched_at']) {
                    $cached_count++;
                }
            } else {
                $merchant_data['trustpilot_rating'] = null;
                $merchant_data['trustpilot_review_count'] = null;
                $failed_count++;
            }
        }
        
        Logger::info('TrustpilotScraper: Enrichment complete - ' . $enriched_count . ' enriched, ' . $cached_count . ' from cache, ' . $failed_count . ' failed');
        
        return $merchants;
    }
    
    /**
     * Clear cache for a specific merchant
     * 
     * @param string $merchant_name Merchant name
     * @return bool True on success
     */
    public function clear_cache($merchant_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
        
        $result = $wpdb->delete($table_name, [
            'merchant_name' => $merchant_name,
        ], ['%s']);
        
        if ($result !== false) {
            Logger::info('TrustpilotScraper: Cleared cache for ' . $merchant_name);
            return true;
        }
        
        return false;
    }
    
    /**
     * Clear all cached ratings
     * 
     * @return int Number of rows deleted
     */
    public function clear_all_cache() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
        
        $deleted = $wpdb->query("DELETE FROM {$table_name}");
        
        if ($deleted !== false) {
            Logger::info('TrustpilotScraper: Cleared all cached ratings (' . $deleted . ' rows)');
            return $deleted;
        }
        
        return 0;
    }
    
    /**
     * Get cache statistics
     * 
     * @return array Statistics array
     */
    public function get_cache_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aebg_trustpilot_ratings';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        
        if (!$table_exists) {
            return [
                'total' => 0,
                'with_ratings' => 0,
                'not_found' => 0,
            ];
        }
        
        $stats = [
            'total' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name}")),
            'with_ratings' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE rating IS NOT NULL")),
            'not_found' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE rating IS NULL")),
        ];
        
        return $stats;
    }
}

