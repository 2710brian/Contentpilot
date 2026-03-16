<?php

namespace AEBG\Core;

use AEBG\Core\Logger;
use AEBG\Core\APIClient;
use AEBG\Core\Datafeedr;
use AEBG\Admin\Settings;

/**
 * Competitor Analyzer
 * 
 * Analyzes scraped content using AI to extract product information
 * 
 * @package AEBG\Core
 */
class CompetitorAnalyzer {
	
	/**
	 * Analyze scraped content using AI
	 * 
	 * @param string $content Scraped/cleaned content
	 * @param array  $config  Analysis configuration (optional, can include 'ai_prompt_template')
	 * @return array|\WP_Error Extracted products or error
	 */
	public function analyze( $content, $config = [] ) {
		Logger::info( 'Starting AI analysis', [
			'content_length' => strlen( $content ),
			'content_preview' => substr( $content, 0, 500 ),
		] );
		
		// Log content preview for debugging
		error_log( '[AEBG] CompetitorAnalyzer: Content preview (first 1000 chars): ' . substr( $content, 0, 1000 ) );
		
		$settings = Settings::get_settings();
		
		// Get API key (handle both 'api_key' and 'openai_api_key' for compatibility)
		$api_key = $settings['api_key'] ?? $settings['openai_api_key'] ?? '';
		
		// Get AI model (handle both 'model' and 'ai_model' for compatibility)
		// Default to 'gpt-4o' which is excellent for website analysis and structured data extraction
		$model = $settings['model'] ?? $settings['ai_model'] ?? 'gpt-4o';
		
		Logger::debug( 'AI model configuration', [
			'model' => $model,
			'has_api_key' => ! empty( $api_key ),
			'settings_keys' => array_keys( $settings ),
		] );
		
		if ( empty( $api_key ) ) {
			$error = new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'aebg' ) );
			Logger::error( 'AI analysis failed - no API key', [
				'error_code' => 'no_api_key',
				'error_message' => 'OpenAI API key not configured',
			] );
			error_log( '[AEBG] CompetitorAnalyzer::analyze() - OpenAI API key not configured' );
			return $error;
		}
		
		// Build AI prompt (pass config which may include ai_prompt_template)
		$prompt = $this->build_prompt( $content, $config );
		
		// Calculate max_tokens efficiently based on expected product count
		// Each product in JSON format: ~200-250 tokens (name, position, url, metadata with price, affiliate_link, merchant, network)
		// For 20 products: 20 * 250 = 5000 tokens, but we cap at model limit
		// We want to extract ALL products, so calculate for up to 25 products to be safe
		$tokens_per_product = 250; // Conservative estimate per product
		$max_expected_products = 25; // Allow for up to 25 products
		$calculated_tokens = $max_expected_products * $tokens_per_product; // 6250 tokens
		
		// Cap at model's max completion tokens (most models have 4096, newer ones have more)
		// For gpt-3.5-turbo and gpt-4: 4096 max completion tokens
		// For gpt-4-turbo: 4096 max completion tokens
		$model_max_tokens = 4096;
		$max_tokens = min( $calculated_tokens, $model_max_tokens ); // Use 4096 for safety, but could be optimized further
		
		// Log for debugging
		Logger::debug( 'Calculated max_tokens for product extraction', [
			'calculated' => $calculated_tokens,
			'model_max' => $model_max_tokens,
			'final' => $max_tokens,
			'estimated_products' => round( $max_tokens / $tokens_per_product ),
		] );
		
		// Build request body
		$request_body = APIClient::buildRequestBody(
			$model,
			$prompt,
			$max_tokens,
			0.3   // temperature (lower for more consistent extraction)
		);
		
		// Get API endpoint
		$api_endpoint = APIClient::getApiEndpoint( $model );
		
		// Make API request
		$response = APIClient::makeRequest(
			$api_endpoint,
			$api_key,
			$request_body,
			60, // timeout
			3   // max_retries
		);
		
		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			$error_message = $response->get_error_message();
			$error_data = $response->get_error_data();
			
			Logger::error( 'AI analysis failed', [
				'error_code' => $error_code,
				'error_message' => $error_message,
				'error_data' => $error_data,
				'api_endpoint' => $api_endpoint ?? 'unknown',
				'model' => $model,
			] );
			
			// Also log to error_log for immediate visibility
			error_log( sprintf(
				'[AEBG] CompetitorAnalyzer::analyze() FAILED | code=%s | message=%s | endpoint=%s | model=%s',
				$error_code,
				$error_message,
				$api_endpoint ?? 'unknown',
				$model
			) );
			
			return $response;
		}
		
		// Extract content from response
		$ai_content = APIClient::extractContentFromResponse( $response, $model );
		
		if ( empty( $ai_content ) ) {
			return new \WP_Error( 'empty_response', __( 'AI returned empty response.', 'aebg' ) );
		}
		
		// Parse AI response
		$products = $this->parse_ai_response( $ai_content );
		
		if ( is_wp_error( $products ) ) {
			return $products;
		}
		
		Logger::info( 'AI analysis completed', [
			'products_found' => count( $products ),
		] );
		
		return $products;
	}
	
	/**
	 * Build AI prompt for product extraction
	 * 
	 * @param string $content Page content
	 * @param array  $config  Custom prompt config
	 * @return string AI prompt
	 */
	private function build_prompt( $content, $config = [] ) {
		// Use custom prompt template if provided
		$custom_prompt = $config['ai_prompt_template'] ?? '';
		
		if ( ! empty( $custom_prompt ) ) {
			// Replace {CONTENT} placeholder
			$prompt = str_replace( '{CONTENT}', $content, $custom_prompt );
			return $prompt;
		}
		
		// Get required count from config (for UI display, but extract ALL products)
		$required_count = $config['required_count'] ?? 0;
		$count_instruction = '';
		if ( $required_count > 0 ) {
			$count_instruction = "\n\n⚠️ CRITICAL: The template requires {$required_count} products, but you MUST extract ALL products found on the page. Do NOT limit yourself to {$required_count} - extract EVERY product in the list so the user can choose which ones to use. If the page has 16 products, extract all 16. If it has 20, extract all 20.";
		}
		
		// Default prompt
		$prompt = "You are analyzing a competitor's \"best in test\" product list page. Extract ALL products mentioned in the list along with their positions, prices, affiliate links, merchants, and networks.
 {$count_instruction}

Page Content:
{$content}

🚨 CRITICAL EXTRACTION REQUIREMENTS - READ CAREFULLY:

1. PRODUCT IDENTIFICATION - EXTRACT EVERY SINGLE PRODUCT:
   - ⚠️ YOU MUST EXTRACT ALL PRODUCTS - DO NOT STOP EARLY
   - Count the products in the content first - if you see 16 products, extract all 16
   - If you see 20 products, extract all 20 - DO NOT stop at 7 or 10
   - Extract products in sequential order: 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, etc.
   - Continue extracting until you have extracted EVERY product visible in the content
   - Extract the EXACT product names as they appear on the page - do NOT make up or generalize names
   - Look for product names in headings, product titles, or clearly marked product sections
   - If you see \"iHero Gamerstol - GameMate Premium RGB\", extract exactly that name, not \"Gamer Stol X1\"
   - If you see \"Arozzi Vernazza Gaming Chair - Black\", extract exactly that name
   - Determine their exact position/rank (1st, 2nd, 3rd, etc.) based on the order they appear
   - Extract products in order from first to last - DO NOT SKIP ANY PRODUCTS
   - ⚠️ IF THE PAGE LISTS 16 PRODUCTS, YOU MUST EXTRACT ALL 16. IF IT LISTS 20, EXTRACT ALL 20.
   - ⚠️ DO NOT STOP AT 7 PRODUCTS - CONTINUE UNTIL ALL PRODUCTS ARE EXTRACTED

2. PRICE EXTRACTION (MANDATORY):
   - Extract the ACTUAL PRICE of the product (e.g., \"1,299 kr\", \"999 DKK\", \"€89.99\")
   - Do NOT extract only savings/discounts (e.g., \"Spar 500 kr\" is savings, not price)
   - Look for price patterns like: numbers with currency symbols, price tags, \"koster\", \"pris\", \"price\", etc.
   - If multiple prices exist, extract the main/current price
   - Format: Include currency symbol and amount (e.g., \"1,299 kr\", \"999 DKK\")

3. AFFILIATE LINK EXTRACTION (MANDATORY):
   - Extract ALL clickable links associated with each product
   - Look for: \"Buy now\" links, \"Shop here\" links, product links, merchant links, \"Se pris\" links, \"Køb nu\" links
   - These are typically affiliate links that redirect to merchant websites
   - Extract the FULL URL including query parameters (they often contain affiliate tracking)
   - If a link is cloaked/redirected, extract the visible link (it may still contain tracking info)
   - Priority: Product-specific links > Merchant links > General links

4. MERCHANT EXTRACTION (MANDATORY):
   - Extract the merchant/store name where the product can be purchased
   - Look for merchant names near the product, in links, or in \"Køb hos\" (Buy at) sections
   - Examples: \"Elgiganten\", \"Power\", \"Bilka\", \"Amazon\", \"Coolshop\", etc.
   - If merchant name is in the affiliate link URL, extract it from there

5. NETWORK EXTRACTION (MANDATORY):
   - Extract the affiliate network name if identifiable
   - Networks are often visible in URLs (e.g., \"partner-ads\", \"awin\", \"tradedoubler\", \"affiliatewindow\")
   - Look for network identifiers in affiliate link URLs
   - Common networks: Awin, TradeDoubler, Partner-ads, Adtraction, etc.
   - If network is not directly visible, try to infer from URL patterns or merchant relationships

6. RETURN FORMAT:
Return data in JSON format:
{
  \"products\": [
    {
      \"name\": \"Product Name\",
      \"position\": 1,
      \"url\": \"https://product-page-url-if-available\",
      \"metadata\": {
        \"price\": \"1,299 kr\",
        \"affiliate_link\": \"https://full-affiliate-link-url-with-parameters\",
        \"merchant\": \"Merchant Name\",
        \"network\": \"Network Name (if identifiable)\",
        \"rating\": \"4.5\" (if available),
        \"savings\": \"Spar 500 kr\" (if mentioned separately from price)
      }
    }
  ]
}

CRITICAL - PRODUCT NAME EXTRACTION:
- Extract the EXACT product name as it appears on the page
- Do NOT generalize, summarize, or make up product names
- If the page shows \"iHero Gamerstol - GameMate Premium RGB\", return exactly that
- If the page shows \"Arozzi Vernazza Gaming Chair - Black\", return exactly that
- Do NOT return generic names like \"Gamer Stol X1\" or \"Gaming Chair Pro\"
- Look for product names in the content - they are usually clearly marked
- Product names often appear near prices, in headings, or in product card titles

⚠️ FINAL REMINDERS:
- Extract affiliate_link, merchant, and network for EVERY product - these should be visible in the HTML
- If affiliate link is not found, extract any product-related link (look for [LINK: ...] markers in the content)
- Price must be the actual purchase price, not just savings
- ⚠️ COUNT THE PRODUCTS IN THE CONTENT - IF YOU SEE 16 PRODUCTS, EXTRACT ALL 16
- ⚠️ DO NOT STOP AT 7 PRODUCTS - CONTINUE EXTRACTING UNTIL ALL PRODUCTS ARE CAPTURED
- Return ONLY valid JSON, no markdown formatting, no explanations, just the JSON object with ALL products.";
		
		return $prompt;
	}
	
	/**
	 * Parse AI response into structured product data
	 * 
	 * @param string $ai_response Raw AI response
	 * @return array|\WP_Error Structured products array or error
	 */
	private function parse_ai_response( $ai_response ) {
		// Remove markdown code blocks if present
		$json_content = APIClient::extractJsonFromMarkdown( $ai_response );
		
		// Try to extract JSON from response
		$json_data = json_decode( $json_content, true );
		
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Try to find JSON in the response (look for complete JSON object)
			if ( preg_match( '/\{[\s\S]*"products"[\s\S]*\[[\s\S]*\][\s\S]*\}/', $json_content, $matches ) ) {
				$json_data = json_decode( $matches[0], true );
			}
			
			// If still failing, try to fix incomplete JSON (common when response is truncated)
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				// Try to extract partial products from incomplete JSON
				if ( preg_match( '/"products"\s*:\s*\[(.*?)(?:\]|$)/s', $json_content, $matches ) ) {
					$products_content = $matches[1];
					// Try to extract individual product objects
					preg_match_all( '/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $products_content, $product_matches );
					if ( ! empty( $product_matches[0] ) ) {
						$products = [];
						foreach ( $product_matches[0] as $product_json ) {
							$product = json_decode( $product_json, true );
							if ( json_last_error() === JSON_ERROR_NONE && is_array( $product ) && isset( $product['name'] ) ) {
								$products[] = $product;
							}
						}
						if ( ! empty( $products ) ) {
							Logger::warning( 'Extracted partial products from incomplete JSON', [
								'products_count' => count( $products ),
								'original_error' => json_last_error_msg(),
							] );
							$json_data = [ 'products' => $products ];
						}
					}
				}
			}
		}
		
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json_data ) ) {
			// Check if we have partial products that we can use
			$partial_products = [];
			
			// Try to extract individual product objects even if JSON is incomplete
			if ( preg_match_all( '/\{[^{}]*"name"\s*:\s*"([^"]+)"[^{}]*\}/', $json_content, $name_matches, PREG_SET_ORDER ) ) {
				foreach ( $name_matches as $match ) {
					// Try to extract full product object around this name
					$name_pos = strpos( $json_content, $match[0] );
					if ( $name_pos !== false ) {
						// Look backwards and forwards to find the full object
						$start = max( 0, $name_pos - 500 );
						$end = min( strlen( $json_content ), $name_pos + 1000 );
						$chunk = substr( $json_content, $start, $end - $start );
						
						// Try to find the opening brace for this product
						$obj_start = strrpos( substr( $json_content, 0, $name_pos ), '{' );
						$obj_end = strpos( $json_content, '}', $name_pos );
						
						if ( $obj_start !== false && $obj_end !== false && $obj_end > $obj_start ) {
							$product_json = substr( $json_content, $obj_start, $obj_end - $obj_start + 1 );
							$product = json_decode( $product_json, true );
							if ( json_last_error() === JSON_ERROR_NONE && is_array( $product ) && isset( $product['name'] ) ) {
								$partial_products[] = $product;
							}
						}
					}
				}
			}
			
			// If we found partial products, use them
			if ( ! empty( $partial_products ) ) {
				Logger::warning( 'Extracted partial products from incomplete JSON', [
					'products_count' => count( $partial_products ),
					'original_error' => json_last_error_msg(),
				] );
				$json_data = [ 'products' => $partial_products ];
			} else {
				// No recoverable products found
				Logger::error( 'Failed to parse AI response as JSON', [
					'json_error' => json_last_error_msg(),
					'response_length' => strlen( $json_content ),
					'response_preview' => substr( $json_content, 0, 1000 ),
					'response_end' => substr( $json_content, -200 ),
				] );
				return new \WP_Error( 
					'json_parse_error', 
					__( 'Failed to parse AI response as JSON. The response may have been truncated. Please try again.', 'aebg' ),
					[ 'response_preview' => substr( $json_content, 0, 500 ) ]
				);
			}
		}
		
		// Extract products array
		$products = $json_data['products'] ?? [];
		
		if ( empty( $products ) || ! is_array( $products ) ) {
			return new \WP_Error( 'no_products', __( 'No products found in AI response.', 'aebg' ) );
		}
		
		// Validate and normalize products
		$validated_products = [];
		foreach ( $products as $index => $product ) {
			if ( ! isset( $product['name'] ) || empty( $product['name'] ) ) {
				continue; // Skip invalid products
			}
			
			$validated_products[] = [
				'name'     => sanitize_text_field( $product['name'] ),
				'position' => isset( $product['position'] ) ? absint( $product['position'] ) : ( $index + 1 ),
				'url'      => ! empty( $product['url'] ) ? esc_url_raw( $product['url'] ) : null,
				'metadata' => $product['metadata'] ?? [],
			];
		}
		
		// Sort by position
		usort( $validated_products, function( $a, $b ) {
			return $a['position'] <=> $b['position'];
		} );
		
		return $validated_products;
	}
	
	/**
	 * Analyze website directly using AI with URL context
	 * This method sends the URL and page content directly to AI for analysis
	 * Uses GPT-4o or GPT-4-turbo for better web understanding
	 * 
	 * @param string $url The competitor URL
	 * @param array  $scraped_data Scraped data from CompetitorScraper (contains 'html' and 'content')
	 * @param array  $config Analysis configuration
	 * @return array|\WP_Error Extracted products or error
	 */
	public function analyzeDirectly( $url, $scraped_data, $config = [] ) {
		Logger::info( 'Starting direct AI analysis', [
			'url' => $url,
			'html_size' => strlen( $scraped_data['html'] ?? '' ),
			'content_size' => strlen( $scraped_data['content'] ?? '' ),
		] );
		
		$settings = Settings::get_settings();
		
		// Get API key
		$api_key = $settings['api_key'] ?? $settings['openai_api_key'] ?? '';
		
		// For direct AI analysis, we MUST use GPT-4o or GPT-4-turbo (they have 128k token context)
		// GPT-3.5-turbo only has 16k tokens which is insufficient for website analysis
		$default_model = 'gpt-4o'; // Best for web analysis, 128k context window
		$configured_model = $settings['model'] ?? $settings['ai_model'] ?? '';
		
		// Force GPT-4o for AI Analysis if user has GPT-3.5 configured
		if ( strpos( $configured_model, 'gpt-3.5' ) === 0 || empty( $configured_model ) ) {
			$model = $default_model;
			Logger::info( 'AI Analysis requires GPT-4o (128k context). Using GPT-4o instead of configured model.', [
				'configured_model' => $configured_model,
				'using_model' => $model,
			] );
		} else {
			// Use configured model if it's GPT-4 or better
			$model = $configured_model;
			Logger::info( 'Using configured model for AI Analysis', [
				'model' => $model,
			] );
		}
		
		if ( empty( $api_key ) ) {
			$error = new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'aebg' ) );
			Logger::error( 'Direct AI analysis failed - no API key' );
			return $error;
		}
		
		// Build prompt for direct AI analysis
		$prompt = $this->build_direct_analysis_prompt( $url, $scraped_data, $config );
		
		// Calculate max_tokens for response
		// GPT-4o can output up to 16,384 tokens, but we'll use a reasonable amount
		$tokens_per_product = 250;
		$max_expected_products = 30; // Allow for more products
		$calculated_tokens = $max_expected_products * $tokens_per_product;
		$model_max_output_tokens = 16384; // GPT-4o max output tokens
		$max_tokens = min( $calculated_tokens, $model_max_output_tokens );
		
		Logger::debug( 'Direct AI analysis configuration', [
			'model' => $model,
			'max_tokens' => $max_tokens,
			'url' => $url,
		] );
		
		// For AI Analysis, use web_search tool to let AI browse the website directly
		$use_web_search = true;
		
		// Build request body with web_search tool
		$request_body = APIClient::buildRequestBody(
			$model,
			$prompt,
			$max_tokens,
			0.3,   // Lower temperature for more consistent extraction
			$use_web_search
		);
		
		// Get API endpoint (Responses API for web_search)
		$api_endpoint = APIClient::getApiEndpoint( $model, $use_web_search );
		
		// Make API request
		$response = APIClient::makeRequest(
			$api_endpoint,
			$api_key,
			$request_body,
			120, // Longer timeout for web search (AI needs to browse the site)
			3   // max_retries
		);
		
		if ( is_wp_error( $response ) ) {
			Logger::error( 'Direct AI analysis failed', [
				'error_code' => $response->get_error_code(),
				'error_message' => $response->get_error_message(),
				'url' => $url,
				'used_web_search' => $use_web_search,
			] );
			return $response;
		}
		
		// Log the raw response for debugging
		Logger::debug( 'Direct AI analysis raw response', [
			'response_keys' => array_keys( $response ),
			'response_preview' => json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			'url' => $url,
		] );
		
		// Extract content from response
		$ai_content = APIClient::extractContentFromResponse( $response, $model, $use_web_search );
		
		Logger::debug( 'Extracted AI content', [
			'content_length' => strlen( $ai_content ),
			'content_preview' => substr( $ai_content, 0, 500 ),
		] );
		
		if ( empty( $ai_content ) ) {
			Logger::error( 'AI returned empty response', [
				'response_structure' => json_encode( $response, JSON_PRETTY_PRINT ),
				'url' => $url,
			] );
			return new \WP_Error( 'empty_response', __( 'AI returned empty response. Please check the logs for details.', 'aebg' ) );
		}
		
		// Parse AI response (reuse existing parser)
		$products = $this->parse_ai_response( $ai_content );
		
		if ( is_wp_error( $products ) ) {
			return $products;
		}
		
		Logger::info( 'Direct AI analysis completed', [
			'products_found' => count( $products ),
			'url' => $url,
		] );
		
		return $products;
	}
	
	/**
	 * Build prompt for direct AI analysis with URL context
	 * 
	 * @param string $url The competitor URL
	 * @param array  $scraped_data Scraped data
	 * @param array  $config Configuration
	 * @return string AI prompt
	 */
	private function build_direct_analysis_prompt( $url, $scraped_data, $config = [] ) {
		$required_count = $config['required_count'] ?? 0;
		
		// For web_search tool, we just send the URL and let AI browse it
		// No need to send HTML content - AI will fetch it via web_search tool
		
		$count_instruction = '';
		if ( $required_count > 0 ) {
			$count_instruction = "\n\n⚠️ CRITICAL: The template requires {$required_count} products, but you MUST extract ALL products found on the page. Do NOT limit yourself to {$required_count} - extract EVERY product in the list so the user can choose which ones to use. If the page has 16 products, extract all 16. If it has 20, extract all 20.";
		}
		
		$prompt = "Analyze this website and extract all products being promoted: {$url}

{$count_instruction}

🚨 CRITICAL EXTRACTION REQUIREMENTS - READ CAREFULLY:

1. PRODUCT IDENTIFICATION - EXTRACT EVERY SINGLE PRODUCT:
   - ⚠️ YOU MUST EXTRACT ALL PRODUCTS - DO NOT STOP EARLY
   - Count the products in the content first - if you see 16 products, extract all 16
   - If you see 20 products, extract all 20 - DO NOT stop at 7 or 10
   - Extract products in sequential order: 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, etc.
   - Continue extracting until you have extracted EVERY product visible in the content
   - Extract the EXACT product names as they appear on the page - do NOT make up or generalize names
   - Look for product names in headings, product titles, list items, or clearly marked product sections
   - Determine their exact position/rank (1st, 2nd, 3rd, etc.) based on the order they appear
   - Extract products in order from first to last - DO NOT SKIP ANY PRODUCTS
   - ⚠️ IF THE PAGE LISTS 16 PRODUCTS, YOU MUST EXTRACT ALL 16. IF IT LISTS 20, EXTRACT ALL 20.
   - ⚠️ DO NOT STOP AT 7 PRODUCTS - CONTINUE UNTIL ALL PRODUCTS ARE EXTRACTED

2. PRICE EXTRACTION (MANDATORY):
   - Extract the ACTUAL PRICE of the product (e.g., \"1,299 kr\", \"999 DKK\", \"€89.99\")
   - Do NOT extract only savings/discounts (e.g., \"Spar 500 kr\" is savings, not price)
   - Look for price patterns in the HTML: numbers with currency symbols, price tags, \"koster\", \"pris\", \"price\", etc.
   - If multiple prices exist, extract the main/current price
   - Format: Include currency symbol and amount (e.g., \"1,299 kr\", \"999 DKK\")

3. AFFILIATE LINK EXTRACTION (MANDATORY):
   - Extract ALL clickable links associated with each product from the HTML
   - Look for: \"Buy now\" links, \"Shop here\" links, product links, merchant links, \"Se pris\" links, \"Køb nu\" links
   - These are typically affiliate links that redirect to merchant websites
   - Extract the FULL URL including query parameters (they often contain affiliate tracking)
   - Look for <a> tags with href attributes near each product
   - Priority: Product-specific links > Merchant links > General links

4. MERCHANT EXTRACTION (MANDATORY):
   - Extract the merchant/store name where the product can be purchased
   - Look for merchant names near the product, in links, or in \"Køb hos\" (Buy at) sections
   - Examples: \"Elgiganten\", \"Power\", \"Bilka\", \"Amazon\", \"Coolshop\", etc.
   - If merchant name is in the affiliate link URL, extract it from there

5. NETWORK EXTRACTION (MANDATORY):
   - Extract the affiliate network name if identifiable
   - Networks are often visible in URLs (e.g., \"partner-ads\", \"awin\", \"tradedoubler\", \"affiliatewindow\")
   - Look for network identifiers in affiliate link URLs
   - Common networks: Awin, TradeDoubler, Partner-ads, Adtraction, etc.

6. RETURN FORMAT:
Return data in JSON format:
{
  \"products\": [
    {
      \"name\": \"Product Name\",
      \"position\": 1,
      \"url\": \"https://product-page-url-if-available\",
      \"metadata\": {
        \"price\": \"1,299 kr\",
        \"affiliate_link\": \"https://full-affiliate-link-url-with-parameters\",
        \"merchant\": \"Merchant Name\",
        \"network\": \"Network Name (if identifiable)\",
        \"rating\": \"4.5\" (if available),
        \"savings\": \"Spar 500 kr\" (if mentioned separately from price)
      }
    }
  ]
}

⚠️ FINAL REMINDERS:
- Extract affiliate_link, merchant, and network for EVERY product - these should be visible in the HTML
- Price must be the actual purchase price, not just savings
- ⚠️ COUNT THE PRODUCTS IN THE CONTENT - IF YOU SEE 16 PRODUCTS, EXTRACT ALL 16
- ⚠️ DO NOT STOP AT 7 PRODUCTS - CONTINUE EXTRACTING UNTIL ALL PRODUCTS ARE CAPTURED
- Return ONLY valid JSON, no markdown formatting, no explanations, just the JSON object with ALL products.";
		
		return $prompt;
	}
	
	/**
	 * Find missing products through market analysis (category-agnostic)
	 * 
	 * Analyzes found products to understand category, then performs market research
	 * to identify popular products that customers actually like.
	 * 
	 * @param array  $found_products Already found products from competitor site
	 * @param string $competitor_url Original competitor URL for context
	 * @param int    $missing_count Number of products needed
	 * @param array  $config Configuration (country, language, etc.)
	 * @return array|\WP_Error Recommendations with Datafeedr matches or error
	 */
	public function findMissingProductsByMarketAnalysis(
		array $found_products,
		string $competitor_url,
		int $missing_count,
		array $config = []
	): array|\WP_Error {
		Logger::info( 'Starting market analysis for missing products', [
			'found_count' => count( $found_products ),
			'missing_count' => $missing_count,
			'competitor_url' => $competitor_url,
		] );
		
		$settings = Settings::get_settings();
		
		// Get API key
		$api_key = $settings['api_key'] ?? $settings['openai_api_key'] ?? '';
		
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'aebg' ) );
		}
		
		// Get AI model (prefer GPT-4o for market analysis)
		$model = $settings['model'] ?? $settings['ai_model'] ?? 'gpt-4o';
		
		// Build market analysis prompt
		$prompt = $this->buildMarketAnalysisPrompt(
			$found_products,
			$competitor_url,
			$missing_count,
			$config
		);
		
		// Calculate max_tokens for market analysis response
		// Each recommendation: ~150-200 tokens
		$tokens_per_recommendation = 200;
		$max_expected_recommendations = $missing_count + 2; // Extra for alternatives
		$calculated_tokens = $max_expected_recommendations * $tokens_per_recommendation;
		$model_max_tokens = 4096;
		$max_tokens = min( $calculated_tokens, $model_max_tokens );
		
		// Build request body
		$request_body = APIClient::buildRequestBody(
			$model,
			$prompt,
			$max_tokens,
			0.4   // Slightly higher temperature for creative market research
		);
		
		// Get API endpoint
		$api_endpoint = APIClient::getApiEndpoint( $model );
		
		// Make API request
		$response = APIClient::makeRequest(
			$api_endpoint,
			$api_key,
			$request_body,
			60, // timeout
			3   // max_retries
		);
		
		if ( is_wp_error( $response ) ) {
			Logger::error( 'Market analysis failed', [
				'error_code' => $response->get_error_code(),
				'error_message' => $response->get_error_message(),
			] );
			return $response;
		}
		
		// Extract content from response
		$ai_content = APIClient::extractContentFromResponse( $response, $model );
		
		if ( empty( $ai_content ) ) {
			return new \WP_Error( 'empty_response', __( 'AI returned empty response for market analysis.', 'aebg' ) );
		}
		
		// Parse AI response
		$recommendations = $this->parseMarketAnalysisResponse( $ai_content );
		
		if ( is_wp_error( $recommendations ) ) {
			return $recommendations;
		}
		
		// Filter out duplicates (check against found products)
		$recommendations = $this->filterDuplicateRecommendations(
			$recommendations,
			$found_products
		);
		
		// Search Datafeedr for each recommendation
		$datafeedr = new Datafeedr();
		$products_with_matches = [];
		$category = $recommendations['category'] ?? '';
		
		// Extract product names from found products to avoid duplicates
		$found_product_names = [];
		foreach ( $found_products as $product ) {
			$name = $product['name'] ?? $product['product_name'] ?? '';
			if ( ! empty( $name ) ) {
				$found_product_names[] = strtolower( trim( $name ) );
			}
		}
		
		foreach ( $recommendations['recommendations'] ?? [] as $rec ) {
			$matches = $this->searchDatafeedrForRecommendation( 
				$rec, 
				$datafeedr, 
				$category, 
				$found_product_names 
			);
			
			if ( ! empty( $matches ) ) {
				$products_with_matches[] = [
					'recommendation' => $rec,
					'datafeedr_matches' => $matches,
					'best_match' => $matches[0], // Top match
				];
			}
		}
		
		Logger::info( 'Market analysis completed', [
			'category' => $recommendations['category'] ?? 'unknown',
			'recommendations_found' => count( $products_with_matches ),
		] );
		
		return [
			'category' => $recommendations['category'] ?? 'unknown',
			'market_insights' => $recommendations['market_insights'] ?? '',
			'recommendations' => $products_with_matches,
			'total_found' => count( $products_with_matches ),
		];
	}
	
	/**
	 * Build market analysis prompt (category-agnostic)
	 * 
	 * @param array  $found_products Already found products
	 * @param string $competitor_url Competitor URL
	 * @param int    $missing_count Number of products needed
	 * @param array  $config Configuration
	 * @return string AI prompt
	 */
	private function buildMarketAnalysisPrompt(
		array $found_products,
		string $competitor_url,
		int $missing_count,
		array $config = []
	): string {
		$product_context = $this->formatProductsForAnalysis( $found_products );
		$country = $config['country'] ?? 'Denmark';
		$language = $config['language'] ?? 'Danish';
		
		$prompt = "You are a market research analyst. Based on the following products found on a competitor website, 
perform market analysis to find popular products that customers actually like.

FOUND PRODUCTS:
{$product_context}

COMPETITOR CONTEXT:
URL: {$competitor_url}
Market: {$country}
Language: {$language}

TASK:
1. Identify the product category from the found products (e.g., 'robot vacuums', 'pet grooming tools', 'IPL hair removers', 'smartphones', 'blankets', 'e-scooters', 'cookware', 'desks', 'LEGO sets', etc.)

2. Perform market research for this category in the specified market:
   - What are the most popular/trending products customers actually buy?
   - What are trending models or brands?
   - What products are highly rated but NOT already in the found list?

3. Recommend exactly {$missing_count} specific products that:
   - Are popular/trending in this category
   - Are NOT duplicates of already-found products
   - Have specific, searchable names (brand + model)
   - Would fit well with the existing product list

4. For each recommendation, provide:
   - Exact product name (as customers would search for it)
   - Alternative search terms (brand variations, model numbers)
   - Brief reason why it's popular/trending
   - Estimated price range (if relevant)

IMPORTANT:
- Return products with EXACT, SEARCHABLE names (e.g., 'Roomba j7', 'iPhone 15 Pro', 'LEGO Star Wars Millennium Falcon')
- Avoid generic terms - be specific with brand + model
- Consider local market preferences (e.g., Danish brands, local availability)
- Ensure products are NOT already in the found list

Return JSON format:
{
  \"category\": \"detected category name\",
  \"market_insights\": \"brief analysis of the category market\",
  \"recommendations\": [
    {
      \"product_name\": \"Exact searchable product name\",
      \"alternative_names\": [\"alternative search terms\"],
      \"reason\": \"why this product is popular\",
      \"estimated_price_range\": \"if known\"
    }
  ]
}

Return ONLY valid JSON, no markdown formatting, no explanations, just the JSON object.";
		
		return $prompt;
	}
	
	/**
	 * Format products for AI analysis
	 * 
	 * @param array $products Products array
	 * @return string Formatted product context
	 */
	private function formatProductsForAnalysis( array $products ): string {
		$formatted = [];
		
		foreach ( $products as $index => $product ) {
			$name = $product['name'] ?? 'Unknown';
			$price = $product['price'] ?? $product['metadata']['price'] ?? 'N/A';
			$merchant = $product['merchant'] ?? $product['metadata']['merchant'] ?? 'N/A';
			$description = substr( $product['description'] ?? $product['metadata']['description'] ?? '', 0, 100 );
			
			$formatted[] = sprintf(
				"%d. %s\n   Price: %s\n   Merchant: %s\n   Description: %s",
				$index + 1,
				$name,
				$price,
				$merchant,
				$description
			);
		}
		
		return implode( "\n\n", $formatted );
	}
	
	/**
	 * Parse market analysis AI response
	 * 
	 * @param string $ai_response Raw AI response
	 * @return array|\WP_Error Parsed recommendations or error
	 */
	private function parseMarketAnalysisResponse( string $ai_response ): array|\WP_Error {
		// Remove markdown code blocks if present
		$json_content = APIClient::extractJsonFromMarkdown( $ai_response );
		
		// Try to extract JSON from response
		$json_data = json_decode( $json_content, true );
		
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Try to find JSON in the response
			if ( preg_match( '/\{[\s\S]*"category"[\s\S]*"recommendations"[\s\S]*\}/', $json_content, $matches ) ) {
				$json_data = json_decode( $matches[0], true );
			}
		}
		
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json_data ) ) {
			Logger::error( 'Failed to parse market analysis response', [
				'json_error' => json_last_error_msg(),
				'response_preview' => substr( $json_content, 0, 500 ),
			] );
			return new \WP_Error( 
				'json_parse_error', 
				__( 'Failed to parse market analysis response as JSON.', 'aebg' )
			);
		}
		
		// Validate structure
		if ( ! isset( $json_data['recommendations'] ) || ! is_array( $json_data['recommendations'] ) ) {
			return new \WP_Error( 
				'invalid_response', 
				__( 'Market analysis response missing recommendations.', 'aebg' )
			);
		}
		
		return $json_data;
	}
	
	/**
	 * Filter out duplicate recommendations (check against found products)
	 * 
	 * @param array $recommendations AI recommendations
	 * @param array $found_products Already found products
	 * @return array Filtered recommendations
	 */
	private function filterDuplicateRecommendations( array $recommendations, array $found_products ): array {
		// Extract product names from found products
		$found_names = [];
		foreach ( $found_products as $product ) {
			$name = strtolower( trim( $product['name'] ?? '' ) );
			if ( ! empty( $name ) ) {
				$found_names[] = $name;
			}
		}
		
		// Filter recommendations
		$filtered = [];
		foreach ( $recommendations['recommendations'] ?? [] as $rec ) {
			$rec_name = strtolower( trim( $rec['product_name'] ?? '' ) );
			
			// Check if this recommendation is a duplicate
			$is_duplicate = false;
			foreach ( $found_names as $found_name ) {
				// Simple similarity check (can be improved)
				if ( strpos( $rec_name, $found_name ) !== false || strpos( $found_name, $rec_name ) !== false ) {
					$is_duplicate = true;
					break;
				}
			}
			
			if ( ! $is_duplicate ) {
				$filtered[] = $rec;
			}
		}
		
		$recommendations['recommendations'] = $filtered;
		return $recommendations;
	}
	
	/**
	 * Search Datafeedr for recommendation (try all name variations)
	 * 
	 * @param array     $recommendation Recommendation from AI
	 * @param Datafeedr $datafeedr Datafeedr instance
	 * @param string    $category Product category for fallback search
	 * @param array     $found_product_names Array of found product names to avoid duplicates
	 * @return array Datafeedr matches
	 */
	private function searchDatafeedrForRecommendation( 
		array $recommendation, 
		Datafeedr $datafeedr, 
		string $category = '', 
		array $found_product_names = [] 
	): array {
		$all_names = array_merge(
			[ $recommendation['product_name'] ?? '' ],
			$recommendation['alternative_names'] ?? []
		);
		
		$matches = [];
		
		// Get configured networks from settings (CRITICAL: Only search in configured networks)
		$settings = get_option( 'aebg_settings', [] );
		$search_configured_only = isset( $settings['search_configured_only'] ) ? (bool) $settings['search_configured_only'] : true; // Default to true for security
		$networks_to_search = ['all']; // Default to all if not configured
		
		if ( $search_configured_only ) {
			$networks_manager = new \AEBG\Admin\Networks_Manager();
			$all_affiliate_ids = $networks_manager->get_all_affiliate_ids();
			$configured_network_keys = array_keys( array_filter( $all_affiliate_ids, function( $id ) {
				return ! empty( $id );
			} ) );
			
			if ( ! empty( $configured_network_keys ) ) {
				$networks_to_search = $configured_network_keys;
				Logger::debug( 'Using configured networks for recommendation search', [
					'networks' => $networks_to_search,
					'count' => count( $networks_to_search ),
				] );
			} else {
				Logger::warning( 'No configured networks found, but search_configured_only is enabled. This may return no results.' );
			}
		} else {
			// Use default_networks from settings if available
			$networks_to_search = $settings['default_networks'] ?? ['all'];
			if ( empty( $networks_to_search ) || ( is_array( $networks_to_search ) && in_array( 'all', $networks_to_search ) ) ) {
				$networks_to_search = ['all'];
			}
		}
		
		// First, try specific product name searches
		foreach ( $all_names as $search_term ) {
			if ( empty( trim( $search_term ) ) ) {
				continue;
			}
			
			// Use search_advanced for better results with configured networks
			$results = $datafeedr->search_advanced(
				$search_term,
				5, // Get top 5 matches
				'relevance',
				0, 0, 0, false, '', '', '', true, 0, $networks_to_search, ''
			);
			
			if ( ! is_wp_error( $results ) && ! empty( $results ) ) {
				// search_advanced() returns array with 'products' key containing formatted products
				$products = $results['products'] ?? [];
				
				if ( ! empty( $products ) && is_array( $products ) ) {
					// Products are already formatted by search_advanced() -> format_products()
					// They have normalized fields: 'network' (not 'source'), 'affiliate_url' (not 'url'), etc.
					$matches = array_merge( $matches, $products );
				}
			}
			
			// If we found good matches, stop searching
			if ( count( $matches ) >= 3 ) {
				break;
			}
		}
		
		// CRITICAL FALLBACK: If specific product search returned 0 results, try category-based search
		if ( empty( $matches ) && ! empty( $category ) ) {
			Logger::info( 'Specific product search returned 0 results, trying category-based fallback', [
				'category' => $category,
				'recommendation' => $recommendation['product_name'] ?? 'unknown',
				'networks' => $networks_to_search,
			] );
			
			// Strategy 1: Search by category name in configured networks (broader search)
			$category_results = $datafeedr->search_advanced(
				$category, // Use category as search term (e.g., "massagestole")
				30, // Get more results for better selection
				'relevance',
				0, 0, 0, false, '', '', '', true, 0, $networks_to_search, '' // Don't filter by category field, just search term
			);
			
			if ( ! is_wp_error( $category_results ) && ! empty( $category_results ) ) {
				$category_products = $category_results['products'] ?? [];
				
				if ( ! empty( $category_products ) && is_array( $category_products ) ) {
					Logger::debug( 'Category fallback found products before filtering', [
						'count' => count( $category_products ),
					] );
					
					// Filter out products already in the found list
					$filtered_products = [];
					foreach ( $category_products as $product ) {
						$product_name = strtolower( trim( $product['name'] ?? '' ) );
						
						// Skip if this product is already in the found list
						if ( ! empty( $product_name ) && in_array( $product_name, $found_product_names, true ) ) {
							continue;
						}
						
						$filtered_products[] = $product;
						
						// Stop when we have enough matches
						if ( count( $filtered_products ) >= 5 ) {
							break;
						}
					}
					
					if ( ! empty( $filtered_products ) ) {
						$matches = $filtered_products;
						
						Logger::info( 'Category fallback search found products', [
							'category' => $category,
							'found' => count( $matches ),
							'before_filtering' => count( $category_products ),
						] );
					} else {
						Logger::warning( 'Category fallback found products but all were filtered out as duplicates', [
							'category' => $category,
							'total_found' => count( $category_products ),
						] );
					}
				}
			} else {
				Logger::warning( 'Category fallback search returned no results', [
					'category' => $category,
					'is_error' => is_wp_error( $category_results ),
				] );
			}
		}
		
		// Remove duplicates by product ID and filter out found products
		$unique_matches = [];
		$seen_ids = [];
		
		foreach ( $matches as $match ) {
			$product_id = $match['id'] ?? $match['product_id'] ?? null;
			$product_name = strtolower( trim( $match['name'] ?? '' ) );
			
			// Skip if already in found products
			if ( ! empty( $product_name ) && in_array( $product_name, $found_product_names, true ) ) {
				continue;
			}
			
			// Skip if duplicate ID
			if ( $product_id && in_array( $product_id, $seen_ids, true ) ) {
				continue;
			}
			
			if ( $product_id ) {
				$seen_ids[] = $product_id;
			}
			
			$unique_matches[] = $match;
		}
		
		// Return top 3 matches
		return array_slice( $unique_matches, 0, 3 );
	}
}

