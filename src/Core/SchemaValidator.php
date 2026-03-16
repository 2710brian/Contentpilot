<?php

namespace AEBG\Core;

/**
 * Schema Validator Class
 * Validates Schema.org JSON-LD structured data against best practices.
 *
 * @package AEBG\Core
 */
class SchemaValidator {
	
	/**
	 * Validation errors.
	 *
	 * @var array
	 */
	private static $errors = [];
	
	/**
	 * Validation warnings.
	 *
	 * @var array
	 */
	private static $warnings = [];
	
	/**
	 * Validate schema data.
	 *
	 * @param array $schema_data Schema data to validate.
	 * @param int $post_id Post ID for context.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate(array $schema_data, int $post_id = 0): bool {
		self::$errors = [];
		self::$warnings = [];
		
		// Validate required top-level properties
		if (!isset($schema_data['@context'])) {
			self::$errors[] = 'Missing required property: @context';
		} elseif ($schema_data['@context'] !== 'https://schema.org') {
			self::$warnings[] = '@context should be "https://schema.org"';
		}
		
		if (!isset($schema_data['@type'])) {
			self::$errors[] = 'Missing required property: @type';
		}
		
		// Validate based on schema type
		$schema_type = $schema_data['@type'] ?? '';
		switch ($schema_type) {
			case 'Article':
				self::validate_article($schema_data, $post_id);
				break;
			case 'Product':
				self::validate_product($schema_data, $post_id);
				break;
			case 'ItemList':
				self::validate_item_list($schema_data, $post_id);
				break;
		}
		
		// Log validation results
		if (!empty(self::$errors)) {
			Logger::warning('Schema validation errors', [
				'post_id' => $post_id,
				'errors' => self::$errors,
				'schema_type' => $schema_type
			]);
		}
		
		if (!empty(self::$warnings)) {
			Logger::debug('Schema validation warnings', [
				'post_id' => $post_id,
				'warnings' => self::$warnings,
				'schema_type' => $schema_type
			]);
		}
		
		return empty(self::$errors);
	}
	
	/**
	 * Validate Article schema.
	 *
	 * @param array $schema_data Schema data.
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function validate_article(array $schema_data, int $post_id): void {
		// Required properties for Article
		$required = ['headline', 'author', 'datePublished'];
		foreach ($required as $prop) {
			if (!isset($schema_data[$prop])) {
				self::$errors[] = "Article schema missing required property: {$prop}";
			}
		}
		
		// Validate author structure
		if (isset($schema_data['author'])) {
			if (!is_array($schema_data['author'])) {
				self::$errors[] = 'Article author must be an object';
			} elseif (!isset($schema_data['author']['@type'])) {
				self::$warnings[] = 'Article author should have @type property';
			} elseif ($schema_data['author']['@type'] !== 'Person' && $schema_data['author']['@type'] !== 'Organization') {
				self::$warnings[] = 'Article author @type should be Person or Organization';
			}
		}
		
		// Validate dates
		if (isset($schema_data['datePublished'])) {
			if (!self::is_valid_iso_date($schema_data['datePublished'])) {
				self::$errors[] = 'Article datePublished must be in ISO 8601 format';
			}
		}
		
		if (isset($schema_data['dateModified'])) {
			if (!self::is_valid_iso_date($schema_data['dateModified'])) {
				self::$warnings[] = 'Article dateModified should be in ISO 8601 format';
			}
		}
		
		// Recommended properties
		if (!isset($schema_data['publisher'])) {
			self::$warnings[] = 'Article schema should include publisher (Organization) for rich results';
		}
		
		if (!isset($schema_data['image'])) {
			self::$warnings[] = 'Article schema should include image for rich results';
		}
	}
	
	/**
	 * Validate Product schema.
	 *
	 * @param array $schema_data Schema data.
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function validate_product(array $schema_data, int $post_id): void {
		// Required properties for Product
		$required = ['name'];
		foreach ($required as $prop) {
			if (!isset($schema_data[$prop])) {
				self::$errors[] = "Product schema missing required property: {$prop}";
			}
		}
		
		// Validate offers
		if (isset($schema_data['offers'])) {
			if (!is_array($schema_data['offers'])) {
				self::$errors[] = 'Product offers must be an object or array';
			} else {
				$offers = is_array($schema_data['offers']) && isset($schema_data['offers'][0]) 
					? $schema_data['offers'] 
					: [$schema_data['offers']];
				
				foreach ($offers as $offer) {
					if (!isset($offer['price'])) {
						self::$errors[] = 'Product offer missing required property: price';
					}
					if (!isset($offer['priceCurrency'])) {
						self::$errors[] = 'Product offer missing required property: priceCurrency';
					}
					if (!isset($offer['availability'])) {
						self::$warnings[] = 'Product offer should include availability';
					}
				}
			}
		}
		
		// Recommended properties
		if (!isset($schema_data['brand'])) {
			self::$warnings[] = 'Product schema should include brand';
		}
		
		if (!isset($schema_data['image'])) {
			self::$warnings[] = 'Product schema should include image';
		}
	}
	
	/**
	 * Validate ItemList schema.
	 *
	 * @param array $schema_data Schema data.
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function validate_item_list(array $schema_data, int $post_id): void {
		if (!isset($schema_data['itemListElement'])) {
			self::$errors[] = 'ItemList schema missing required property: itemListElement';
		} elseif (!is_array($schema_data['itemListElement'])) {
			self::$errors[] = 'ItemList itemListElement must be an array';
		} elseif (empty($schema_data['itemListElement'])) {
			self::$warnings[] = 'ItemList itemListElement should not be empty';
		}
	}
	
	/**
	 * Check if string is valid ISO 8601 date.
	 *
	 * @param string $date Date string.
	 * @return bool True if valid ISO 8601 date.
	 */
	private static function is_valid_iso_date(string $date): bool {
		// ISO 8601 format: YYYY-MM-DDTHH:MM:SS+00:00 or YYYY-MM-DDTHH:MM:SSZ
		$pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/';
		if (preg_match($pattern, $date)) {
			return true;
		}
		
		// Also accept date-only format
		$pattern_date = '/^\d{4}-\d{2}-\d{2}$/';
		return preg_match($pattern_date, $date) !== false;
	}
	
	/**
	 * Get validation errors.
	 *
	 * @return array Array of error messages.
	 */
	public static function get_errors(): array {
		return self::$errors;
	}
	
	/**
	 * Get validation warnings.
	 *
	 * @return array Array of warning messages.
	 */
	public static function get_warnings(): array {
		return self::$warnings;
	}
	
	/**
	 * Check if schema is valid.
	 *
	 * @return bool True if valid (no errors).
	 */
	public static function is_valid(): bool {
		return empty(self::$errors);
	}
}

