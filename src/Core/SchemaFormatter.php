<?php

namespace AEBG\Core;

/**
 * Schema Formatter Class
 * Handles formatting and sanitizing schema data according to Schema.org standards.
 *
 * @package AEBG\Core
 */
class SchemaFormatter {
	
	/**
	 * Format price for Schema.org Offer.
	 * Price must be a string, not a number.
	 *
	 * @param mixed $price Price value.
	 * @return string Formatted price string.
	 */
	public static function format_price($price): string {
		if (is_string($price)) {
			// Remove currency symbols and whitespace
			$price = preg_replace('/[^\d.,]/', '', $price);
			// Ensure it's a valid number
			$price = filter_var($price, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRAC);
		}
		
		if (is_numeric($price)) {
			// Format as string with 2 decimal places
			return number_format((float)$price, 2, '.', '');
		}
		
		// Default to 0.00 if invalid
		return '0.00';
	}
	
	/**
	 * Format date to ISO 8601 format (required by Schema.org).
	 *
	 * @param string|int $date Date string or timestamp.
	 * @return string ISO 8601 formatted date.
	 */
	public static function format_date($date): string {
		if (is_numeric($date)) {
			// Assume it's a timestamp
			return gmdate('c', $date);
		}
		
		if (is_string($date)) {
			// Try to parse and format
			$timestamp = strtotime($date);
			if ($timestamp !== false) {
				return gmdate('c', $timestamp);
			}
		}
		
		// Return current date as fallback
		return gmdate('c');
	}
	
	/**
	 * Format URL, ensuring it's absolute and valid.
	 *
	 * @param string $url URL to format.
	 * @return string|false Formatted URL or false if invalid.
	 */
	public static function format_url(string $url): string|false {
		if (empty($url)) {
			return false;
		}
		
		// If relative URL, make it absolute
		if (strpos($url, 'http') !== 0) {
			$url = home_url($url);
		}
		
		// Validate URL
		$url = esc_url_raw($url);
		if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}
		
		return $url;
	}
	
	/**
	 * Format image URL for Schema.org.
	 * Images must be absolute URLs.
	 *
	 * @param string|int $image Image URL or attachment ID.
	 * @return string|false Formatted image URL or false if invalid.
	 */
	public static function format_image($image): string|false {
		if (is_numeric($image)) {
			// Assume it's an attachment ID
			$image_url = wp_get_attachment_image_url($image, 'full');
			if ($image_url) {
				return self::format_url($image_url);
			}
			return false;
		}
		
		if (is_string($image) && !empty($image)) {
			return self::format_url($image);
		}
		
		return false;
	}
	
	/**
	 * Format text for Schema.org (strip HTML, limit length).
	 *
	 * @param string $text Text to format.
	 * @param int $max_length Maximum length (0 = no limit).
	 * @return string Formatted text.
	 */
	public static function format_text(string $text, int $max_length = 0): string {
		// Strip HTML tags
		$text = wp_strip_all_tags($text);
		
		// Trim whitespace
		$text = trim($text);
		
		// Limit length if specified
		if ($max_length > 0 && mb_strlen($text) > $max_length) {
			$text = mb_substr($text, 0, $max_length);
			// Try to cut at word boundary
			$last_space = mb_strrpos($text, ' ');
			if ($last_space !== false && $last_space > $max_length * 0.8) {
				$text = mb_substr($text, 0, $last_space);
			}
			$text .= '...';
		}
		
		return $text;
	}
	
	/**
	 * Format currency code (ISO 4217).
	 *
	 * @param string $currency Currency code.
	 * @return string Valid currency code (default: USD).
	 */
	public static function format_currency(string $currency): string {
		// Common currency codes
		$valid_currencies = [
			'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
			'SEK', 'NZD', 'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'RUB',
			'INR', 'BRL', 'ZAR', 'DKK', 'PLN', 'TWD', 'THB', 'MYR'
		];
		
		$currency = strtoupper(trim($currency));
		if (in_array($currency, $valid_currencies, true)) {
			return $currency;
		}
		
		// Default to USD if invalid
		return 'USD';
	}
	
	/**
	 * Format brand name for Schema.org.
	 *
	 * @param string $brand Brand name.
	 * @return string|false Formatted brand name or false if empty.
	 */
	public static function format_brand(string $brand): string|false {
		$brand = trim($brand);
		if (empty($brand)) {
			return false;
		}
		
		// Limit length (Schema.org doesn't specify, but keep reasonable)
		if (mb_strlen($brand) > 100) {
			$brand = mb_substr($brand, 0, 100);
		}
		
		return $brand;
	}
}

