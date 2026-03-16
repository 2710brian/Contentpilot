<?php

namespace AEBG\Core;

use AEBG\Core\Logger;

/**
 * Competitor Scraper
 * 
 * Handles web scraping of competitor pages
 * 
 * @package AEBG\Core
 */
class CompetitorScraper {
	
	/**
	 * Scrape a competitor URL
	 * 
	 * @param string $url URL to scrape
	 * @return array|\WP_Error Scraped data or error
	 */
	public function scrape( $url ) {
		$scrape_start = microtime( true );
		$max_scrape_time = 30; // 30 seconds max for scraping
		
		Logger::info( 'Starting competitor scrape', [ 'url' => $url ] );
		
		// Fetch page with wp_remote_get (same pattern as TrustpilotScraper)
		$response = wp_remote_get( $url, [
			'timeout'     => $max_scrape_time,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'headers'     => [
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
				'Connection'      => 'close', // Force connection close to prevent reuse
				'Cache-Control'   => 'no-cache',
			],
			'cookies'     => false, // Don't send cookies (fresh request)
			'sslverify'   => true,
			'redirection' => 5, // Allow up to 5 redirects
		] );
		
		// Check if scrape took too long (watchdog)
		$scrape_elapsed = microtime( true ) - $scrape_start;
		if ( $scrape_elapsed > ( $max_scrape_time + 2 ) ) {
			Logger::warning( 'CompetitorScraper: Scrape exceeded timeout', [
				'url'      => $url,
				'elapsed'  => round( $scrape_elapsed, 2 ),
				'timeout'  => $max_scrape_time,
			] );
			return new \WP_Error( 'timeout', __( 'Scrape exceeded timeout limit.', 'aebg' ) );
		}
		
		if ( is_wp_error( $response ) ) {
			Logger::error( 'CompetitorScraper: Failed to fetch', [
				'url'   => $url,
				'error' => $response->get_error_message(),
			] );
			return new \WP_Error( 'fetch_failed', $response->get_error_message() );
		}
		
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			if ( $status_code === 404 ) {
				Logger::debug( 'CompetitorScraper: Page not found (404)', [ 'url' => $url ] );
			} else {
				Logger::warning( 'CompetitorScraper: HTTP error', [
					'url'         => $url,
					'status_code' => $status_code,
				] );
			}
			return new \WP_Error( 'http_error', sprintf( __( 'HTTP %d error.', 'aebg' ), $status_code ) );
		}
		
		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			Logger::warning( 'CompetitorScraper: Empty response body', [ 'url' => $url ] );
			return new \WP_Error( 'empty_response', __( 'Empty response from URL.', 'aebg' ) );
		}
		
		// Clean and extract content
		$cleaned_content = $this->clean_html( $html );
		
		// Validate content size - if too short, try alternative extraction
		if ( strlen( $cleaned_content ) < 500 ) {
			Logger::warning( 'CompetitorScraper: Extracted content is very short, trying alternative extraction', [
				'url' => $url,
				'content_size' => strlen( $cleaned_content ),
				'html_size' => strlen( $html ),
			] );
			
			// Try alternative: extract text directly from body without heavy cleaning
			$cleaned_content = $this->extract_text_alternative( $html );
			
			if ( strlen( $cleaned_content ) < 500 ) {
				Logger::error( 'CompetitorScraper: Alternative extraction also failed - content may be JavaScript-rendered', [
					'url' => $url,
					'content_size' => strlen( $cleaned_content ),
					'html_size' => strlen( $html ),
				] );
				// Continue anyway - let AI try with what we have
			}
		}
		
		Logger::info( 'Competitor scrape completed', [
			'url'            => $url,
			'html_size'      => strlen( $html ),
			'content_size'   => strlen( $cleaned_content ),
			'processing_time' => round( $scrape_elapsed, 2 ),
		] );
		
		return [
			'html'    => $html,
			'content' => $cleaned_content,
			'url'     => $url,
		];
	}
	
	/**
	 * Clean HTML and extract main content
	 * Preserves structure to help AI identify products better
	 * 
	 * @param string $html Raw HTML
	 * @return string Cleaned content
	 */
	private function clean_html( $html ) {
		// Try using DOMDocument for better parsing (if available)
		if ( class_exists( 'DOMDocument' ) && function_exists( 'libxml_use_internal_errors' ) ) {
			libxml_use_internal_errors( true );
			$dom = new \DOMDocument();
			@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
			libxml_clear_errors();
			
			// Remove script and style elements
			$scripts = $dom->getElementsByTagName( 'script' );
			foreach ( $scripts as $script ) {
				$script->parentNode->removeChild( $script );
			}
			$styles = $dom->getElementsByTagName( 'style' );
			foreach ( $styles as $style ) {
				$style->parentNode->removeChild( $style );
			}
			
			// Extract text content with structure
			$xpath = new \DOMXPath( $dom );
			
			// Try to find main content area (common patterns)
			$content_selectors = [
				'//main',
				'//article',
				'//div[@class="content"]',
				'//div[@class="post-content"]',
				'//div[@class="entry-content"]',
				'//div[@id="content"]',
				'//div[@class="product-list"]',
				'//div[@class="products"]',
				'//ul[@class="product-list"]',
				'//ol[@class="product-list"]',
			];
			
			$content_node = null;
			foreach ( $content_selectors as $selector ) {
				$nodes = $xpath->query( $selector );
				if ( $nodes->length > 0 ) {
					$content_node = $nodes->item( 0 );
					break;
				}
			}
			
			// If no main content found, use body
			if ( ! $content_node ) {
				$body = $dom->getElementsByTagName( 'body' );
				if ( $body->length > 0 ) {
					$content_node = $body->item( 0 );
				}
			}
			
			if ( $content_node ) {
				$html = $this->extract_text_with_structure( $content_node );
			} else {
				// Fallback to regex method
				$html = $this->clean_html_regex( $html );
			}
		} else {
			// Fallback to regex method if DOMDocument not available
			$html = $this->clean_html_regex( $html );
		}
		
		// Clean up excessive whitespace but preserve structure
		$html = preg_replace( '/[ \t]+/', ' ', $html ); // Multiple spaces to single space
		$html = preg_replace( '/\n{3,}/', "\n\n", $html ); // Multiple newlines to double newline
		$html = trim( $html );
		
		// Log if content is suspiciously short
		if ( strlen( $html ) < 500 ) {
			Logger::warning( 'CompetitorScraper: Extracted content is very short', [
				'content_length' => strlen( $html ),
				'content_preview' => substr( $html, 0, 200 ),
			] );
		}
		
		// Try to find and preserve product list sections
		// Look for patterns like "1.", "2.", "3." or product names followed by prices
		// This helps the AI identify product boundaries
		
		// Limit content size (AI has token limits)
		// Increased limit to capture more products (up to ~20,000 tokens)
		$max_length = 80000; // ~20,000 tokens - enough for 10+ products
		if ( strlen( $html ) > $max_length ) {
			// Try to find product list section and preserve it
			// Look for patterns that indicate product lists
			$product_patterns = [
				'/(\d+\.\s+[A-ZÆØÅ][^0-9]{20,})/u', // Numbered products
				'/([A-ZÆØÅ][^.!?]{10,}\s+\d+[.,]\d+\s*(kr|DKK|€|\$))/u', // Product name + price
			];
			
			$best_cut = $max_length;
			foreach ( $product_patterns as $pattern ) {
				preg_match_all( $pattern, substr( $html, 0, $max_length * 1.2 ), $matches, PREG_OFFSET_CAPTURE );
				if ( ! empty( $matches[0] ) ) {
					$last_match = end( $matches[0] );
					if ( $last_match[1] > $max_length * 0.8 && $last_match[1] < $max_length * 1.2 ) {
						$best_cut = min( $best_cut, $last_match[1] + strlen( $last_match[0] ) );
					}
				}
			}
			
			if ( $best_cut < $max_length * 1.2 ) {
				$html = substr( $html, 0, $best_cut ) . "\n\n... [content truncated after product list]";
			} else {
				// Fallback: try to end at sentence or product boundary
				$truncated = substr( $html, 0, $max_length );
				$last_period = strrpos( $truncated, '.' );
				$last_newline = strrpos( $truncated, "\n" );
				$cut_point = max( $last_period, $last_newline );
				if ( $cut_point && $cut_point > $max_length * 0.9 ) {
					$html = substr( $truncated, 0, $cut_point + 1 ) . "\n\n... [content truncated]";
				} else {
					$html = $truncated . "\n\n... [content truncated]";
				}
			}
		}
		
		return $html;
	}
	
	/**
	 * Extract text content with structure from DOM node
	 * 
	 * @param \DOMNode $node DOM node
	 * @return string Text content with structure
	 */
	private function extract_text_with_structure( $node ) {
		$text = '';
		
		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType === XML_TEXT_NODE ) {
				$text .= trim( $child->textContent ) . ' ';
			} elseif ( $child->nodeType === XML_ELEMENT_NODE ) {
				$tag_name = strtolower( $child->tagName );
				
				switch ( $tag_name ) {
					case 'h1':
					case 'h2':
					case 'h3':
					case 'h4':
					case 'h5':
					case 'h6':
						$text .= "\n\n=== " . strtoupper( $tag_name ) . ': ' . trim( $child->textContent ) . " ===\n";
						break;
						
					case 'p':
						$text .= "\n\n" . trim( $child->textContent );
						break;
						
					case 'li':
						$text .= "\n- " . trim( $child->textContent );
						break;
						
					case 'a':
						$href = $child->getAttribute( 'href' );
						$link_text = trim( $child->textContent );
						if ( $href ) {
							$text .= $link_text . ' [LINK: ' . $href . ']';
						} else {
							$text .= $link_text;
						}
						break;
						
					case 'div':
					case 'section':
					case 'article':
						$text .= "\n" . $this->extract_text_with_structure( $child );
						break;
						
					default:
						$text .= $this->extract_text_with_structure( $child );
						break;
				}
			}
		}
		
		return $text;
	}
	
	/**
	 * Clean HTML using regex (fallback method)
	 * 
	 * @param string $html Raw HTML
	 * @return string Cleaned content
	 */
	private function clean_html_regex( $html ) {
		// Remove script and style tags
		$html = preg_replace( '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/is', '', $html );
		
		// Remove comments
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		
		// Convert HTML entities
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		
		// Preserve important structure: headings, links, and list items
		// Convert headings to text with markers
		$html = preg_replace( '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', "\n\n=== HEADING $1: $2 ===\n", $html );
		
		// Preserve links - extract href and text
		$html = preg_replace_callback( '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function( $matches ) {
			$url = $matches[1];
			$text = strip_tags( $matches[2] );
			return $text . ' [LINK: ' . $url . ']';
		}, $html );
		
		// Convert list items to numbered/bulleted format
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "\n- $1", $html );
		
		// Convert divs/sections to newlines for structure
		$html = preg_replace( '/<\/?(div|section|article|main|header|footer)[^>]*>/is', "\n", $html );
		
		// Convert paragraphs to double newlines
		$html = preg_replace( '/<\/p>/i', "\n\n", $html );
		$html = preg_replace( '/<p[^>]*>/i', '', $html );
		
		// Remove remaining HTML tags but preserve text
		$html = strip_tags( $html );
		
		return $html;
	}
	
	/**
	 * Alternative text extraction method (fallback)
	 * Extracts text more aggressively when standard cleaning fails
	 * 
	 * @param string $html Raw HTML
	 * @return string Extracted text
	 */
	private function extract_text_alternative( $html ) {
		// Remove scripts and styles
		$html = preg_replace( '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/is', '', $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		
		// Try to extract text from common content containers
		$patterns = [
			'/<main[^>]*>(.*?)<\/main>/is',
			'/<article[^>]*>(.*?)<\/article>/is',
			'/<div[^>]*class=["\'][^"\']*content[^"\']*["\'][^>]*>(.*?)<\/div>/is',
			'/<div[^>]*class=["\'][^"\']*post[^"\']*["\'][^>]*>(.*?)<\/div>/is',
			'/<div[^>]*class=["\'][^"\']*entry[^"\']*["\'][^>]*>(.*?)<\/div>/is',
			'/<ul[^>]*class=["\'][^"\']*product[^"\']*["\'][^>]*>(.*?)<\/ul>/is',
			'/<ol[^>]*class=["\'][^"\']*product[^"\']*["\'][^>]*>(.*?)<\/ol>/is',
		];
		
		$extracted = '';
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					$text = strip_tags( $match );
					$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$extracted .= "\n\n" . trim( $text );
				}
			}
		}
		
		// If we got content, use it; otherwise fall back to full HTML text extraction
		if ( strlen( $extracted ) > 500 ) {
			return trim( $extracted );
		}
		
		// Last resort: extract all text from body
		if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $html, $body_match ) ) {
			$body = $body_match[1];
			$body = strip_tags( $body );
			$body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$body = preg_replace( '/\s+/', ' ', $body );
			return trim( $body );
		}
		
		// Final fallback: extract all text
		$text = strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}
}

