<?php

namespace AEBG\Core;

/**
 * Product Variant Manager Class
 * 
 * Handles grouping products by model and managing color variants
 * 
 * @package AEBG\Core
 */
class ProductVariantManager {
    
    /**
     * Group products by model and manage variants
     * 
     * @param array $products Array of products
     * @param array $options Grouping options
     * @return array Grouped products with variants
     */
    public static function groupProductsByModel($products, $options = []) {
        if (!is_array($products) || empty($products)) {
            return [];
        }

        $default_options = [
            'group_by_model' => true,
            'include_color_variants' => true,
            'max_variants_per_product' => 5,
            'prefer_highest_rating' => true,
            'prefer_lowest_price' => false,
            'color_words' => [
                'red', 'blue', 'green', 'yellow', 'black', 'white', 'gray', 'grey',
                'orange', 'purple', 'pink', 'brown', 'beige', 'navy', 'maroon',
                'silver', 'gold', 'bronze', 'copper', 'chrome', 'stainless steel',
                'rose gold', 'space gray', 'midnight blue', 'forest green',
                // Danish/Norwegian colors
                'hvid', 'sort', 'grå', 'blå', 'rød', 'grøn', 'gul', 'orange',
                'lilla', 'pink', 'brun', 'beige', 'marine', 'bordeaux',
                'sølv', 'guld', 'bronze', 'kobber', 'krom', 'rustfrit stål',
                // Additional Danish color variants
                'blauw', 'veelkleurig', 'meerkleurig', 'meerkleuren', 'meerkleurige',
                'rood', 'groen', 'geel', 'zwart', 'wit', 'grijs', 'oranje',
                'paars', 'roze', 'bruin', 'beige', 'marineblauw', 'bordeaux',
                'zilver', 'goud', 'brons', 'koper', 'chroom', 'roestvrij staal',
                // Common color prefixes/suffixes
                'kleur', 'color', 'coloured', 'colored', 'kleuren', 'kleurig'
            ]
        ];

        $options = array_merge($default_options, $options);

        if (!$options['group_by_model']) {
            return $products;
        }

        // Group products by their base model
        $product_groups = self::groupByBaseModel($products, $options);

        // Process each group to create variant-aware products
        $grouped_products = [];
        foreach ($product_groups as $base_model => $group_products) {
            $main_product = self::selectMainProduct($group_products, $options);
            $variants = self::extractVariants($group_products, $main_product, $options);
            
            // Add variants to main product
            if (!empty($variants)) {
                $main_product['variants'] = $variants;
                $main_product['variant_count'] = count($variants);
            }

            $grouped_products[] = $main_product;
        }

        return $grouped_products;
    }

    /**
     * Group products by their base model (without color/size variants)
     * 
     * @param array $products Array of products
     * @param array $options Grouping options
     * @return array Products grouped by base model
     */
    private static function groupByBaseModel($products, $options) {
        $groups = [];

        foreach ($products as $product) {
            $base_model = self::extractBaseModel($product['name'], $options['color_words']);
            $brand = $product['brand'] ?? '';
            
            // Create a unique key for the model
            $model_key = strtolower(trim($brand . ' ' . $base_model));
            
            if (!isset($groups[$model_key])) {
                $groups[$model_key] = [];
            }
            
            $groups[$model_key][] = $product;
        }

        return $groups;
    }

    /**
     * Extract base model name from product name (remove color/size variants)
     * 
     * @param string $product_name Product name
     * @param array $color_words Array of color words to remove
     * @return string Base model name
     */
    private static function extractBaseModel($product_name, $color_words) {
        $name = strtolower(trim($product_name));
        
        // Normalize Danish characters for better matching
        $name = self::normalizeDanishCharacters($name);
        
        // Remove color words
        foreach ($color_words as $color) {
            $name = str_replace($color, '', $name);
        }
        
        // Remove common size indicators - expanded patterns
        $size_patterns = [
            // Clothing sizes
            '/\b(xs|s|m|l|xl|xxl|xxxl|xxxxl)\b/i',
            '/\b(small|medium|large|extra large|extra-?large)\b/i',
            // Numeric sizes with units
            '/\b(\d+cm|\d+inch|\d+"|\d+\s*cm|\d+\s*inch|\d+\s*")\b/i',
            // Common size words
            '/\b(\d+stk|\d+pack|\d+pk|\d+stykker)\b/i', // Danish: stykker = pieces
            // Size ranges
            '/\b(\d+-\d+cm|\d+-\d+inch|\d+-\d+")\b/i',
            // Common size indicators
            '/\b(stor|mellem|lille|mini|mega|jumbo|standard)\b/i', // Danish size words
            // Remove specific size patterns like "45x60cm" or "30x40"
            '/\b\d+x\d+(cm|inch|")?\b/i',
            // Remove standalone numbers that might be sizes (but keep model numbers)
            '/\b(\d{1,2})\b(?=\s|$)/', // Only single/double digits at word boundaries
        ];
        
        foreach ($size_patterns as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }
        
        // Clean up extra spaces and punctuation
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name, ' -–—');
        
        return $name;
    }

    /**
     * Normalize Danish characters for better matching
     * 
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private static function normalizeDanishCharacters($text) {
        // Normalize Danish characters to their basic Latin equivalents
        $normalizations = [
            'æ' => 'ae',
            'ø' => 'oe', 
            'å' => 'aa',
            'Æ' => 'AE',
            'Ø' => 'OE',
            'Å' => 'AA'
        ];
        
        return strtr($text, $normalizations);
    }

    /**
     * Select the main product from a group (best representative)
     * 
     * @param array $group_products Products in the group
     * @param array $options Selection options
     * @return array Main product
     */
    private static function selectMainProduct($group_products, $options) {
        if (empty($group_products)) {
            return [];
        }

        if (count($group_products) === 1) {
            return $group_products[0];
        }

        // Score each product
        $scored_products = [];
        foreach ($group_products as $product) {
            $score = self::calculateProductScore($product, $options);
            $scored_products[] = [
                'product' => $product,
                'score' => $score
            ];
        }

        // Sort by score (highest first)
        usort($scored_products, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $scored_products[0]['product'];
    }

    /**
     * Extract variants from a product group
     * 
     * @param array $group_products All products in the group
     * @param array $main_product The main product
     * @param array $options Variant options
     * @return array Array of variants
     */
    private static function extractVariants($group_products, $main_product, $options) {
        $variants = [];
        $max_variants = $options['max_variants_per_product'];

        foreach ($group_products as $product) {
            // Skip the main product itself
            if ($product['id'] === $main_product['id']) {
                continue;
            }

            $variant = null;

            // Check if this is a color variant
            if (self::isColorVariant($main_product['name'], $product['name'], $options['color_words'])) {
                $variant = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'type' => 'color',
                    'color' => self::extractColor($product['name'], $options['color_words']),
                    'size' => '',
                    'price' => $product['price'] ?? 0,
                    'currency' => $product['currency'] ?? 'USD',
                    'merchant' => $product['merchant'] ?? '',
                    'url' => $product['url'] ?? '',
                    'image_url' => $product['image_url'] ?? '',
                    'rating' => $product['rating'] ?? 0,
                    'reviews_count' => $product['reviews_count'] ?? 0
                ];
            }
            // Check if this is a size variant
            elseif (self::isSizeVariant($main_product['name'], $product['name'], $options['color_words'])) {
                $variant = [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'type' => 'size',
                    'color' => '',
                    'size' => self::extractSize($product['name']),
                    'price' => $product['price'] ?? 0,
                    'currency' => $product['currency'] ?? 'USD',
                    'merchant' => $product['merchant'] ?? '',
                    'url' => $product['url'] ?? '',
                    'image_url' => $product['image_url'] ?? '',
                    'rating' => $product['rating'] ?? 0,
                    'reviews_count' => $product['reviews_count'] ?? 0
                ];
            }

            if ($variant) {
                $variants[] = $variant;

                // Limit number of variants
                if (count($variants) >= $max_variants) {
                    break;
                }
            }
        }

        // Sort variants by price (lowest first) or rating (highest first)
        if ($options['prefer_lowest_price']) {
            usort($variants, function($a, $b) {
                return $a['price'] <=> $b['price'];
            });
        } else {
            usort($variants, function($a, $b) {
                return $b['rating'] <=> $a['rating'];
            });
        }

        return $variants;
    }

    /**
     * Check if two products are color variants of each other
     * 
     * @param string $name1 First product name
     * @param string $name2 Second product name
     * @param array $color_words Array of color words
     * @return bool True if they are color variants
     */
    private static function isColorVariant($name1, $name2, $color_words) {
        $base1 = self::extractBaseModel($name1, $color_words);
        $base2 = self::extractBaseModel($name2, $color_words);

        // Check if base models are similar
        $similarity = self::calculateStringSimilarity($base1, $base2);
        if ($similarity < 0.8) {
            return false;
        }

        // Check if both have color indicators
        $has_color1 = self::hasColorIndicator($name1, $color_words);
        $has_color2 = self::hasColorIndicator($name2, $color_words);

        return $has_color1 && $has_color2 && $name1 !== $name2;
    }

    /**
     * Check if two products are size variants of each other
     * 
     * @param string $name1 First product name
     * @param string $name2 Second product name
     * @param array $color_words Array of color words (for base model extraction)
     * @return bool True if they are size variants
     */
    private static function isSizeVariant($name1, $name2, $color_words) {
        $base1 = self::extractBaseModel($name1, $color_words);
        $base2 = self::extractBaseModel($name2, $color_words);

        // Check if base models are similar
        $similarity = self::calculateStringSimilarity($base1, $base2);
        if ($similarity < 0.8) {
            return false;
        }

        // Check if both have size indicators
        $has_size1 = self::hasSizeIndicator($name1);
        $has_size2 = self::hasSizeIndicator($name2);

        return $has_size1 && $has_size2 && $name1 !== $name2;
    }

    /**
     * Check if product name has size indicators
     * 
     * @param string $name Product name
     * @return bool True if name contains size indicators
     */
    private static function hasSizeIndicator($name) {
        $name_lower = strtolower($name);
        
        // Size patterns to detect
        $size_patterns = [
            '/\b(xs|s|m|l|xl|xxl|xxxl|xxxxl)\b/i',
            '/\b(small|medium|large|extra large|extra-?large)\b/i',
            '/\b(\d+cm|\d+inch|\d+"|\d+\s*cm|\d+\s*inch|\d+\s*")\b/i',
            '/\b(\d+stk|\d+pack|\d+pk|\d+stykker)\b/i',
            '/\b(\d+-\d+cm|\d+-\d+inch|\d+-\d+")\b/i',
            '/\b(stor|mellem|lille|mini|mega|jumbo|standard)\b/i',
            '/\b\d+x\d+(cm|inch|")?\b/i',
        ];
        
        foreach ($size_patterns as $pattern) {
            if (preg_match($pattern, $name_lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract color from product name
     * 
     * @param string $product_name Product name
     * @param array $color_words Array of color words
     * @return string Extracted color or empty string
     */
    private static function extractColor($product_name, $color_words) {
        $name_lower = strtolower($product_name);
        
        foreach ($color_words as $color) {
            if (strpos($name_lower, $color) !== false) {
                return ucfirst($color);
            }
        }

        return '';
    }

    /**
     * Extract size from product name
     * 
     * @param string $product_name Product name
     * @return string Extracted size or empty string
     */
    private static function extractSize($product_name) {
        $name_lower = strtolower($product_name);
        
        // Size patterns to detect and extract
        $size_patterns = [
            '/\b(xs|s|m|l|xl|xxl|xxxl|xxxxl)\b/i',
            '/\b(small|medium|large|extra large|extra-?large)\b/i',
            '/\b(\d+cm|\d+inch|\d+"|\d+\s*cm|\d+\s*inch|\d+\s*")\b/i',
            '/\b(\d+stk|\d+pack|\d+pk|\d+stykker)\b/i',
            '/\b(\d+-\d+cm|\d+-\d+inch|\d+-\d+")\b/i',
            '/\b(stor|mellem|lille|mini|mega|jumbo|standard)\b/i',
            '/\b\d+x\d+(cm|inch|")?\b/i',
        ];
        
        foreach ($size_patterns as $pattern) {
            if (preg_match($pattern, $name_lower, $matches)) {
                // Return the matched size
                return trim($matches[0]);
            }
        }

        return '';
    }

    /**
     * Check if product name has color indicators
     * 
     * @param string $name Product name
     * @param array $color_words Array of color words
     * @return bool True if name contains color indicators
     */
    private static function hasColorIndicator($name, $color_words) {
        $name_lower = strtolower($name);
        
        foreach ($color_words as $color) {
            if (strpos($name_lower, $color) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate product score for selection
     * 
     * @param array $product Product data
     * @param array $options Scoring options
     * @return float Product score
     */
    private static function calculateProductScore($product, $options) {
        $score = 0;

        // Rating score (0-50 points)
        $rating = $product['rating'] ?? 0;
        $reviews_count = $product['reviews_count'] ?? 0;
        $score += ($rating / 5) * 30; // Up to 30 points for rating
        $score += min($reviews_count / 100, 20); // Up to 20 points for review count

        // Price score (0-20 points) - lower price gets higher score if prefer_lowest_price
        $price = $product['price'] ?? 0;
        if ($price > 0) {
            if ($options['prefer_lowest_price']) {
                // For price preference, we'll give higher score to products with reasonable prices
                // This is a simplified approach - you might want to adjust based on your needs
                $score += 10;
            } else {
                $score += 10; // Neutral price score
            }
        }

        // Image availability (0-10 points)
        if (!empty($product['image_url'])) {
            $score += 10;
        }

        // Description quality (0-10 points)
        $description = $product['description'] ?? '';
        if (strlen($description) > 50) {
            $score += 10;
        } elseif (strlen($description) > 20) {
            $score += 5;
        }

        // Brand recognition (0-10 points)
        $brand = $product['brand'] ?? '';
        if (!empty($brand)) {
            $score += 10;
        }

        return $score;
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

        // Normalize Danish characters for better comparison
        $str1 = self::normalizeDanishCharacters($str1);
        $str2 = self::normalizeDanishCharacters($str2);

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
     * Get variant information for display
     * 
     * @param array $product Product with variants
     * @return array Variant information
     */
    public static function getVariantInfo($product) {
        if (empty($product['variants'])) {
            return [
                'has_variants' => false,
                'variant_count' => 0,
                'price_range' => null,
                'colors' => [],
                'sizes' => []
            ];
        }

        $variants = $product['variants'];
        $prices = array_column($variants, 'price');
        $colors = array_column($variants, 'color');
        $sizes = array_column($variants, 'size');
        $colors = array_filter($colors); // Remove empty colors
        $sizes = array_filter($sizes); // Remove empty sizes

        return [
            'has_variants' => true,
            'variant_count' => count($variants),
            'price_range' => [
                'min' => min($prices),
                'max' => max($prices),
                'currency' => $variants[0]['currency'] ?? 'USD'
            ],
            'colors' => array_unique($colors),
            'sizes' => array_unique($sizes)
        ];
    }

    /**
     * Format variant display text
     * 
     * @param array $product Product with variants
     * @return string Formatted variant text
     */
    public static function formatVariantText($product) {
        $variant_info = self::getVariantInfo($product);
        
        if (!$variant_info['has_variants']) {
            return '';
        }

        $text_parts = [];
        
        // Add color count
        if (!empty($variant_info['colors'])) {
            $color_count = count($variant_info['colors']);
            $text_parts[] = $color_count . ' color' . ($color_count > 1 ? 's' : '');
        }

        // Add size count
        if (!empty($variant_info['sizes'])) {
            $size_count = count($variant_info['sizes']);
            $text_parts[] = $size_count . ' size' . ($size_count > 1 ? 's' : '');
        }

        // Add price range
        if ($variant_info['price_range']) {
            $min = $variant_info['price_range']['min'];
            $max = $variant_info['price_range']['max'];
            $currency = $variant_info['price_range']['currency'];
            
            if ($min === $max) {
                $text_parts[] = ProductManager::formatPrice($min, $currency);
            } else {
                $text_parts[] = ProductManager::formatPrice($min, $currency) . ' - ' . ProductManager::formatPrice($max, $currency);
            }
        }

        return implode(' • ', $text_parts);
    }
} 