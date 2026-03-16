<?php

namespace AEBG\Core;

/**
 * Duplicate Detector Class
 * 
 * Handles detection and filtering of duplicate products in bulk generation
 * 
 * @package AEBG\Core
 */
class DuplicateDetector {
    
    /**
     * Detect and filter duplicate products
     * 
     * @param array $products Array of products to filter
     * @param array $options Filtering options
     * @return array Filtered products with duplicates removed
     */
    public static function filterDuplicates($products, $options = []) {
        if (empty($products) || !is_array($products)) {
            return [];
        }

        // Filter out any non-array products and ensure they have required keys
        $valid_products = array_filter($products, function($product) {
            if (!is_array($product)) {
                error_log('[AEBG] DuplicateDetector: Skipping non-array product: ' . gettype($product));
                return false;
            }
            if (!isset($product['id'])) {
                error_log('[AEBG] DuplicateDetector: Skipping product without ID');
                return false;
            }
            return true;
        });

        if (empty($valid_products)) {
            error_log('[AEBG] DuplicateDetector: No valid products to filter');
            return [];
        }

        // Re-index the array to ensure numeric keys
        $valid_products = array_values($valid_products);

        $default_options = [
            'prevent_same_product_different_suppliers' => true,
            'prevent_same_product_different_colors' => true,
            'similarity_threshold' => 0.85, // 85% similarity threshold
            'max_products_per_group' => 1, // Keep only 1 product per duplicate group
            'prefer_higher_rating' => true,
            'prefer_lower_price' => false,
            'prefer_more_reviews' => true,
        ];

        $options = array_merge($default_options, $options);
        
        error_log('[AEBG] DuplicateDetector: Starting duplicate filtering for ' . count($valid_products) . ' products');
        error_log('[AEBG] DuplicateDetector: Options: ' . json_encode($options));

        // Step 1: Group products by potential duplicates
        $duplicate_groups = self::groupPotentialDuplicates($valid_products, $options);
        
        // Step 2: Select the best product from each group
        $filtered_products = self::selectBestFromGroups($duplicate_groups, $valid_products, $options);
        
        error_log('[AEBG] DuplicateDetector: Filtered from ' . count($valid_products) . ' to ' . count($filtered_products) . ' products');
        
        return $filtered_products;
    }

    /**
     * Group products by potential duplicates
     * 
     * @param array $products Array of products
     * @param array $options Filtering options
     * @return array Groups of potential duplicates
     */
    private static function groupPotentialDuplicates($products, $options) {
        $groups = [];
        $processed = [];

        foreach ($products as $index => $product) {
            // Ensure index is an integer
            $index = intval($index);
            
            if (in_array($index, $processed)) {
                continue;
            }

            $current_group = [$index];
            $processed[] = $index;

            // Compare with remaining products
            for ($j = $index + 1; $j < count($products); $j++) {
                if (in_array($j, $processed)) {
                    continue;
                }

                if (self::areProductsDuplicates($products[$index], $products[$j], $options)) {
                    $current_group[] = $j;
                    $processed[] = $j;
                }
            }

            if (count($current_group) > 1) {
                $groups[] = $current_group;
                error_log('[AEBG] DuplicateDetector: Found duplicate group with ' . count($current_group) . ' products');
            }
        }

        return $groups;
    }

    /**
     * Check if two products are duplicates
     * 
     * @param array $product1 First product
     * @param array $product2 Second product
     * @param array $options Filtering options
     * @return bool True if products are duplicates
     */
    private static function areProductsDuplicates($product1, $product2, $options) {
        // Normalize product data
        $p1 = self::normalizeProduct($product1);
        $p2 = self::normalizeProduct($product2);

        // Check for exact matches first (same product, different suppliers)
        if ($options['prevent_same_product_different_suppliers']) {
            if (self::isSameProductDifferentSupplier($p1, $p2)) {
                return true;
            }
        }

        // Check for color variations (same product, different colors)
        if ($options['prevent_same_product_different_colors']) {
            if (self::isSameProductDifferentColor($p1, $p2)) {
                return true;
            }
        }

        // Check for high similarity (fuzzy matching)
        $similarity = self::calculateSimilarity($p1, $p2);
        if ($similarity >= $options['similarity_threshold']) {
            return true;
        }

        return false;
    }

    /**
     * Check if products are the same but from different suppliers
     * 
     * @param array $p1 First product (normalized)
     * @param array $p2 Second product (normalized)
     * @return bool True if same product, different supplier
     */
    private static function isSameProductDifferentSupplier($p1, $p2) {
        // Check if they have the same core identifiers
        $same_identifiers = false;
        
        // Check SKU/MPN/UPC/EAN/ISBN
        if (!empty($p1['sku']) && !empty($p2['sku']) && $p1['sku'] === $p2['sku']) {
            $same_identifiers = true;
        } elseif (!empty($p1['mpn']) && !empty($p2['mpn']) && $p1['mpn'] === $p2['mpn']) {
            $same_identifiers = true;
        } elseif (!empty($p1['upc']) && !empty($p2['upc']) && $p1['upc'] === $p2['upc']) {
            $same_identifiers = true;
        } elseif (!empty($p1['ean']) && !empty($p2['ean']) && $p1['ean'] === $p2['ean']) {
            $same_identifiers = true;
        } elseif (!empty($p1['isbn']) && !empty($p2['isbn']) && $p1['isbn'] === $p2['isbn']) {
            $same_identifiers = true;
        }

        // If same identifiers but different merchants, it's the same product from different suppliers
        if ($same_identifiers && !empty($p1['merchant']) && !empty($p2['merchant']) && $p1['merchant'] !== $p2['merchant']) {
            return true;
        }

        return false;
    }

    /**
     * Check if products are the same but different colors
     * 
     * @param array $p1 First product (normalized)
     * @param array $p2 Second product (normalized)
     * @return bool True if same product, different color
     */
    private static function isSameProductDifferentColor($p1, $p2) {
        // Check if they have the same base product name but different color indicators
        $name1 = $p1['name'];
        $name2 = $p2['name'];

        // Remove color words from names for comparison
        $base_name1 = self::removeColorWords($name1);
        $base_name2 = self::removeColorWords($name2);

        // If base names are very similar and both have color indicators
        if (self::calculateStringSimilarity($base_name1, $base_name2) >= 0.9) {
            $has_color1 = self::hasColorIndicator($name1);
            $has_color2 = self::hasColorIndicator($name2);
            
            if ($has_color1 && $has_color2 && $name1 !== $name2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate similarity between two products
     * 
     * @param array $p1 First product (normalized)
     * @param array $p2 Second product (normalized)
     * @return float Similarity score (0-1)
     */
    private static function calculateSimilarity($p1, $p2) {
        $weights = [
            'name' => 0.4,
            'brand' => 0.3,
            'category' => 0.2,
            'description' => 0.1
        ];

        $total_similarity = 0;
        $total_weight = 0;

        foreach ($weights as $field => $weight) {
            if (!empty($p1[$field]) && !empty($p2[$field])) {
                $similarity = self::calculateStringSimilarity($p1[$field], $p2[$field]);
                $total_similarity += $similarity * $weight;
                $total_weight += $weight;
            }
        }

        return $total_weight > 0 ? $total_similarity / $total_weight : 0;
    }

    /**
     * Calculate string similarity using Levenshtein distance
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score (0-1)
     */
    private static function calculateStringSimilarity($str1, $str2) {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));

        if ($str1 === $str2) {
            return 1.0;
        }

        $max_length = max(strlen($str1), strlen($str2));
        if ($max_length === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $max_length);
    }

    /**
     * Normalize product data for comparison
     * 
     * @param array $product Product data
     * @return array Normalized product data
     */
    private static function normalizeProduct($product) {
        return [
            'id' => (string) ($product['id'] ?? ''),
            'name' => (string) ($product['name'] ?? ''),
            'brand' => (string) ($product['brand'] ?? ''),
            'category' => (string) ($product['category'] ?? ''),
            'description' => (string) ($product['description'] ?? ''),
            'merchant' => (string) ($product['merchant'] ?? ''),
            'sku' => (string) ($product['sku'] ?? ''),
            'mpn' => (string) ($product['mpn'] ?? ''),
            'upc' => (string) ($product['upc'] ?? ''),
            'ean' => (string) ($product['ean'] ?? ''),
            'isbn' => (string) ($product['isbn'] ?? ''),
            'price' => (float) ($product['price'] ?? 0),
            'rating' => (float) ($product['rating'] ?? 0),
            'reviews_count' => (int) ($product['reviews_count'] ?? 0),
        ];
    }

    /**
     * Remove color words from product name
     * 
     * @param string $name Product name
     * @return string Name without color words
     */
    private static function removeColorWords($name) {
        $color_words = [
            'red', 'blue', 'green', 'yellow', 'black', 'white', 'gray', 'grey',
            'orange', 'purple', 'pink', 'brown', 'beige', 'navy', 'maroon',
            'silver', 'gold', 'bronze', 'copper', 'chrome', 'stainless steel',
            'rose gold', 'space gray', 'midnight blue', 'forest green'
        ];

        $name_lower = strtolower($name);
        foreach ($color_words as $color) {
            $name_lower = str_replace($color, '', $name_lower);
        }

        return trim($name_lower);
    }

    /**
     * Check if product name has color indicators
     * 
     * @param string $name Product name
     * @return bool True if name contains color indicators
     */
    private static function hasColorIndicator($name) {
        $color_words = [
            'red', 'blue', 'green', 'yellow', 'black', 'white', 'gray', 'grey',
            'orange', 'purple', 'pink', 'brown', 'beige', 'navy', 'maroon',
            'silver', 'gold', 'bronze', 'copper', 'chrome', 'stainless steel',
            'rose gold', 'space gray', 'midnight blue', 'forest green'
        ];

        $name_lower = strtolower($name);
        foreach ($color_words as $color) {
            if (strpos($name_lower, $color) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Select the best product from each duplicate group
     * 
     * @param array $duplicate_groups Groups of duplicate products
     * @param array $products Original products array
     * @param array $options Filtering options
     * @return array Best products from each group
     */
    private static function selectBestFromGroups($duplicate_groups, $products, $options) {
        $selected_products = [];
        $selected_indices = [];

        foreach ($duplicate_groups as $group) {
            $best_index = self::selectBestProduct($group, $products, $options);
            if ($best_index !== null) {
                $selected_products[] = $products[$best_index];
                $selected_indices[] = $best_index;
            }
        }

        // Add products that weren't in any duplicate group
        for ($i = 0; $i < count($products); $i++) {
            $in_group = false;
            foreach ($duplicate_groups as $group) {
                if (in_array($i, $group)) {
                    $in_group = true;
                    break;
                }
            }
            if (!$in_group) {
                $selected_products[] = $products[$i];
            }
        }

        return $selected_products;
    }

    /**
     * Select the best product from a group based on criteria
     * 
     * @param array $group Indices of products in the group
     * @param array $products Original products array
     * @param array $options Filtering options
     * @return int|null Index of the best product
     */
    private static function selectBestProduct($group, $products, $options) {
        if (empty($group)) {
            return null;
        }

        // Ensure group contains valid indices
        $valid_indices = array_filter($group, function($index) use ($products) {
            return is_numeric($index) && isset($products[$index]);
        });

        if (empty($valid_indices)) {
            return null;
        }

        $best_index = intval($valid_indices[0]);
        $best_score = self::calculateProductScore($products[$best_index], $options);

        foreach ($valid_indices as $index) {
            $index = intval($index);
            $score = self::calculateProductScore($products[$index], $options);
            if ($score > $best_score) {
                $best_score = $score;
                $best_index = $index;
            }
        }

        return $best_index;
    }

    /**
     * Calculate a score for product selection
     * 
     * @param array $product Product data
     * @param array $options Filtering options
     * @return float Product score
     */
    private static function calculateProductScore($product, $options) {
        $score = 0;

        // Ensure product is an array
        if (!is_array($product)) {
            error_log('[AEBG] DuplicateDetector: Product is not an array in calculateProductScore');
            return 0;
        }

        // Rating score (0-5 scale)
        if ($options['prefer_higher_rating']) {
            $rating = floatval($product['rating'] ?? 0);
            $score += $rating * 2; // 0-10 points
        }

        // Reviews count score (logarithmic to avoid bias)
        if ($options['prefer_more_reviews']) {
            $reviews = intval($product['reviews_count'] ?? 0);
            $score += min(5, log10($reviews + 1)); // 0-5 points
        }

        // Price score (lower is better if prefer_lower_price is true)
        if ($options['prefer_lower_price'] && !empty($product['price'])) {
            $price = floatval($product['price']);
            $score += max(0, 10 - ($price / 10)); // 0-10 points, lower price = higher score
        }

        // Bonus for products with images
        if (!empty($product['image_url'])) {
            $score += 1;
        }

        // Bonus for products with complete information
        $complete_info_bonus = 0;
        if (!empty($product['brand'])) $complete_info_bonus += 0.5;
        if (!empty($product['description'])) $complete_info_bonus += 0.5;
        if (!empty($product['sku']) || !empty($product['mpn'])) $complete_info_bonus += 0.5;
        $score += $complete_info_bonus;

        return $score;
    }

    /**
     * Get duplicate detection statistics
     * 
     * @param array $original_products Original products array
     * @param array $filtered_products Filtered products array
     * @return array Statistics about duplicate detection
     */
    public static function getStatistics($original_products, $filtered_products) {
        $original_count = count($original_products);
        $filtered_count = count($filtered_products);
        $removed_count = $original_count - $filtered_count;

        return [
            'original_count' => $original_count,
            'filtered_count' => $filtered_count,
            'removed_count' => $removed_count,
            'removal_percentage' => $original_count > 0 ? round(($removed_count / $original_count) * 100, 2) : 0,
        ];
    }
} 