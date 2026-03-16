<?php
/**
 * Currency Manager
 * 
 * Unified currency handling system for price normalization, formatting, and detection.
 * This class serves as the single source of truth for all currency operations.
 * 
 * @package AEBG\Core
 */

namespace AEBG\Core;

class CurrencyManager {
    
    /**
     * Supported currencies with their formatting rules
     * 
     * @var array
     */
    private static $currency_formats = [
        'DKK' => [
            'symbol' => '-',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'after',
            'danish_format' => true,
            'min_price' => 0.01,
            'max_price' => 100000,
            'cents_min' => 50
        ],
        'SEK' => [
            'symbol' => 'kr',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 100000,
            'cents_min' => 50
        ],
        'NOK' => [
            'symbol' => 'kr',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 100000,
            'cents_min' => 50
        ],
        'USD' => [
            'symbol' => '$',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'EUR' => [
            'symbol' => '€',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'GBP' => [
            'symbol' => '£',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'CAD' => [
            'symbol' => 'C$',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'AUD' => [
            'symbol' => 'A$',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'JPY' => [
            'symbol' => '¥',
            'decimals' => 0,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 1,
            'max_price' => 10000000,
            'cents_min' => 100
        ],
        'CHF' => [
            'symbol' => 'CHF',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => "'",
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'CNY' => [
            'symbol' => '¥',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'INR' => [
            'symbol' => '₹',
            'decimals' => 2,
            'decimal_sep' => '.',
            'thousands_sep' => ',',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'BRL' => [
            'symbol' => 'R$',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'PLN' => [
            'symbol' => 'zł',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => ' ',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'CZK' => [
            'symbol' => 'Kč',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => ' ',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'HUF' => [
            'symbol' => 'Ft',
            'decimals' => 0,
            'decimal_sep' => ',',
            'thousands_sep' => ' ',
            'position' => 'after',
            'min_price' => 1,
            'max_price' => 10000000,
            'cents_min' => 100
        ],
        'RON' => [
            'symbol' => 'lei',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'BGN' => [
            'symbol' => 'лв',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => ' ',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'HRK' => [
            'symbol' => 'kn',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'RUB' => [
            'symbol' => '₽',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => ' ',
            'position' => 'after',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
        'TRY' => [
            'symbol' => '₺',
            'decimals' => 2,
            'decimal_sep' => ',',
            'thousands_sep' => '.',
            'position' => 'before',
            'min_price' => 0.01,
            'max_price' => 50000,
            'cents_min' => 20
        ],
    ];
    
    /**
     * Domain-to-currency mapping for detection
     * 
     * @var array
     */
    private static $domain_currency_map = [
        '/\.dk$/i' => 'DKK',
        '/\.se$/i' => 'SEK',
        '/\.no$/i' => 'NOK',
        '/\.co\.uk$/i' => 'GBP',
        '/\.uk$/i' => 'GBP',
        '/\.de$/i' => 'EUR',
        '/\.fr$/i' => 'EUR',
        '/\.it$/i' => 'EUR',
        '/\.es$/i' => 'EUR',
        '/\.nl$/i' => 'EUR',
        '/\.be$/i' => 'EUR',
        '/\.at$/i' => 'EUR',
        '/\.pl$/i' => 'PLN',
        '/\.cz$/i' => 'CZK',
        '/\.hu$/i' => 'HUF',
        '/\.ro$/i' => 'RON',
        '/\.bg$/i' => 'BGN',
        '/\.hr$/i' => 'HRK',
        '/\.ru$/i' => 'RUB',
        '/\.tr$/i' => 'TRY',
        '/\.br$/i' => 'BRL',
        '/\.ch$/i' => 'CHF',
        '/\.jp$/i' => 'JPY',
        '/\.cn$/i' => 'CNY',
        '/\.in$/i' => 'INR',
        '/\.ca$/i' => 'CAD',
        '/\.au$/i' => 'AUD',
    ];
    
    /**
     * Normalize price from Datafeedr API format (cents) to decimal
     * 
     * This is the UNIFIED price normalization function that replaces:
     * - Datafeedr.php::convert_datafeedr_price()
     * - ProductManager.php::normalizePriceValue()
     * - Shortcodes.php::normalize_price_value()
     * 
     * Rules:
     * 1. Prices >= 10000 (integers) → likely cents, convert if reasonable
     * 2. Prices < 10 (decimals) → check if double-converted, correct if needed
     * 3. Prices 10-9999 → assume already decimal format
     * 
     * @param mixed $price Price value (can be string, int, or float)
     * @param string $currency Currency code (ISO-4217)
     * @return float Normalized price in decimal format
     */
    public static function normalizePrice($price, $currency = 'USD') {
        // 1. Validate input
        if (empty($price) && $price !== 0 && $price !== '0') {
            return 0.0;
        }
        
        // Convert string to float (handle comma as decimal separator)
        if (is_string($price)) {
            $price = str_replace(',', '.', $price);
            // Remove any whitespace
            $price = trim($price);
        }
        
        $price = floatval($price);
        
        // Return 0 for invalid prices
        if ($price < 0) {
            return 0.0;
        }
        
        // Normalize currency code
        $currency = strtoupper(trim($currency ?: 'USD'));
        
        // Get currency-specific ranges
        $format = self::getCurrencyFormat($currency);
        $min_price = $format['min_price'] ?? 0.01;
        $max_price = $format['max_price'] ?? 50000;
        $cents_min = $format['cents_min'] ?? 20;
        
        // 2. Check for integers that are likely in cents format
        // Datafeedr API and some sources return prices as integers in cents (e.g., 5495 = $54.95, 6500 = 65.00 DKK, 2900 = 29.00 DKK)
        // Convert if price is an integer >= 100 AND converted price is reasonable
        // We check >= 100 to avoid converting very small numbers that are already in decimal format
        if ($price == floor($price) && $price >= 100) {
            $converted_price = $price / 100;
            
            // Only convert if the result is within reasonable range for the currency
            // This handles both large (>= 10000) and smaller (100-9999) cent values
            // For DKK: 6500 -> 65 DKK (reasonable), 2900 -> 29 DKK (reasonable)
            if ($converted_price >= $cents_min && $converted_price <= $max_price) {
                // For prices >= 10000, always convert (definitely cents)
                if ($price >= 10000) {
                    return $converted_price;
                }
                
                // For prices < 10000, convert if the result is a reasonable product price
                // This handles cases like 6500 -> 65 DKK, 2900 -> 29 DKK
                // But prevents converting legitimate prices like 1999 DKK to 19.99 DKK
                // We use a wider range (10-2000) to catch more cent values while still being safe
                // Also check that the original price doesn't look like a legitimate large price
                // (e.g., 1999 should stay as 1999, not become 19.99)
                if ($converted_price >= 10 && $converted_price <= 2000) {
                    // Additional safety: if original price is >= 1000 and < 10000, 
                    // only convert if converted price is <= 100 (to avoid converting 1999 -> 19.99)
                    if ($price >= 1000 && $price < 10000) {
                        if ($converted_price <= 100) {
                            return $converted_price;
                        }
                    } else {
                        // For prices < 1000, convert if result is reasonable
                        return $converted_price;
                    }
                }
            }
        }
        
        // 3. Check for double-converted prices (suspiciously small)
        // Prices like 2.29, 0.59, 8.99 are 100x too small for most products
        // This handles cases where prices were incorrectly converted twice
        if ($price > 0 && $price < 10 && $price != floor($price)) {
            $corrected_price = $price * 100;
            
            // Only correct if the result is reasonable
            if ($corrected_price >= $min_price && $corrected_price <= 10000) {
                // Price correction is routine - no need to log every correction
                return $corrected_price;
            }
        }
        
        // 4. Assume already in correct decimal format
        // Prices like 1999, 229, 59, 899 are already in correct decimal format
        return $price;
    }
    
    /**
     * Format price for display according to currency rules
     * 
     * This is the UNIFIED price formatting function that replaces:
     * - ProductManager.php::formatPrice()
     * 
     * @param float $price Price value (should be in decimal format)
     * @param string $currency Currency code (ISO-4217)
     * @return string Formatted price string
     */
    public static function formatPrice($price, $currency = 'USD') {
        // 1. Normalize price first (handles cents conversion if needed)
        $price = self::normalizePrice($price, $currency);
        
        // 2. Validate price
        if ($price <= 0) {
            return 'N/A';
        }
        
        // 3. Get currency format rules
        $format = self::getCurrencyFormat($currency);
        
        // 4. Determine decimal places
        // For DKK, SEK, NOK - only show decimals if price has decimal places
        $decimals = $format['decimals'];
        if (in_array($currency, ['DKK', 'SEK', 'NOK'])) {
            $hasDecimals = fmod($price, 1) != 0;
            $decimals = $hasDecimals ? 2 : 0;
        }
        
        // 5. Format number with correct separators
        $formatted = number_format(
            $price,
            $decimals,
            $format['decimal_sep'],
            $format['thousands_sep']
        );
        
        // 6. Apply currency-specific formatting
        return self::applyCurrencyFormat($formatted, $currency, $decimals, $format);
    }
    
    /**
     * Apply currency-specific formatting rules
     * 
     * @param string $formatted Formatted number string
     * @param string $currency Currency code
     * @param int $decimals Number of decimal places
     * @param array $format Currency format array
     * @return string Final formatted price string
     */
    private static function applyCurrencyFormat($formatted, $currency, $decimals, $format) {
        // Special handling for Danish Kroner (DKK) - use "249,-" format
        if ($currency === 'DKK') {
            if ($decimals === 0) {
                // Whole number: "1 999,-"
                return $formatted . ',-';
            } else {
                // Has decimals: "1 999,50" (no dash, no currency symbol)
                return $formatted;
            }
        }
        
        // Add currency symbol for other currencies
        if ($format['position'] === 'before') {
            $spacer = ($format['symbol'] === 'CHF') ? ' ' : '';
            return $format['symbol'] . $spacer . $formatted;
        } else {
            return $formatted . ' ' . $format['symbol'];
        }
    }
    
    /**
     * Get currency format rules
     * 
     * @param string $currency Currency code
     * @return array Currency format array
     */
    public static function getCurrencyFormat($currency) {
        $currency = strtoupper(trim($currency ?: 'USD'));
        return self::$currency_formats[$currency] ?? self::$currency_formats['USD'];
    }
    
    /**
     * Detect currency from merchant name/domain
     * 
     * Enhanced detection that supports more domains than the original implementation.
     * 
     * @param string $merchant_name Merchant name or domain
     * @return string|null Currency code or null if cannot detect
     */
    public static function detectCurrency($merchant_name) {
        if (empty($merchant_name)) {
            return null;
        }
        
        // Check domain patterns
        foreach (self::$domain_currency_map as $pattern => $currency) {
            if (preg_match($pattern, $merchant_name)) {
                return $currency;
            }
        }
        
        return null;
    }
    
    /**
     * Detect currency from site URL/domain
     * 
     * @param string $url_or_domain Site URL or domain name
     * @return string|null Currency code or null if cannot detect
     */
    public static function detectCurrencyFromDomain($url_or_domain) {
        if (empty($url_or_domain)) {
            return null;
        }
        
        // Extract host from URL if full URL provided
        $parsed = parse_url($url_or_domain);
        $host = $parsed['host'] ?? $url_or_domain;
        
        // Use existing domain_currency_map
        foreach (self::$domain_currency_map as $pattern => $currency) {
            if (preg_match($pattern, $host)) {
                return $currency;
            }
        }
        
        return null;
    }
    
    /**
     * Get currency for product with fallback chain
     * 1. From product data
     * 2. From existing products
     * 3. From domain detection
     * 4. From settings
     * 5. Default to USD
     * 
     * @param array $product_data Product data
     * @param array $existing_products Existing products
     * @param int|null $post_id Post ID for context
     * @return string Currency code
     */
    public static function getProductCurrency($product_data, $existing_products = [], $post_id = null) {
        // 1. Check product data
        if (!empty($product_data['currency'])) {
            return $product_data['currency'];
        }
        
        // 2. Check existing products
        if (!empty($existing_products)) {
            $first_product = reset($existing_products);
            if (!empty($first_product['currency'])) {
                return $first_product['currency'];
            }
        }
        
        // 3. Detect from domain
        $site_url = get_site_url();
        $detected = self::detectCurrencyFromDomain($site_url);
        if ($detected) {
            return $detected;
        }
        
        // 4. Get from settings
        $default = self::getDefaultCurrency();
        return $default;
    }
    
    /**
     * Get default currency from settings with fallback
     * 
     * @return string Currency code
     */
    public static function getDefaultCurrency() {
        $settings = get_option('aebg_settings', []);
        $default = $settings['default_currency'] ?? 'USD';
        
        // Validate currency code
        if (self::isValidCurrency($default)) {
            return $default;
        }
        
        // Fallback to USD
        return 'USD';
    }
    
    /**
     * Check if currency code is valid/supported
     * 
     * @param string $currency Currency code
     * @return bool True if valid
     */
    public static function isValidCurrency($currency) {
        $currency = strtoupper(trim($currency ?: ''));
        return isset(self::$currency_formats[$currency]);
    }
    
    /**
     * Get all supported currencies
     * 
     * @return array Array of currency codes
     */
    public static function getSupportedCurrencies() {
        return array_keys(self::$currency_formats);
    }
}

