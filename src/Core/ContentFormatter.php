<?php

namespace AEBG\Core;

/**
 * Content Formatter Class
 * Handles formatting of generated content for different widget types.
 *
 * @package AEBG\Core
 */
class ContentFormatter {
	/**
	 * Convert markdown-style formatting to HTML
	 * Handles headings, lists, bold, italic, etc.
	 *
	 * @param string $content The content with markdown formatting
	 * @return string The content with HTML formatting
	 */
	public static function convertMarkdownToHtml($content) {
		if (empty($content)) {
			return '';
		}
		
		// If content already has HTML tags, check if it's already formatted
		// Only convert if we detect markdown patterns
		$has_html = strip_tags($content) !== $content;
		$has_markdown = preg_match('/^#{1,6}\s+/m', $content) || 
		                preg_match('/^[-*+]\s+/m', $content) ||
		                preg_match('/\*\*[^*]+\*\*/', $content) ||
		                preg_match('/\*[^*]+\*/', $content);
		
		// If already HTML and no markdown detected, return as is
		if ($has_html && !$has_markdown) {
			return $content;
		}
		
		// Split content into lines for processing
		$lines = preg_split('/\r?\n/', $content);
		$formatted_lines = [];
		$in_list = false;
		$list_type = null; // 'ul' or 'ol'
		
		foreach ($lines as $line) {
			$original_line = $line;
			$trimmed = trim($line);
			
			// Skip empty lines (they'll be handled as paragraph breaks)
			if (empty($trimmed)) {
				if ($in_list) {
					// Close list if we hit an empty line
					$formatted_lines[] = $in_list === 'ul' ? '</ul>' : '</ol>';
					$in_list = false;
					$list_type = null;
				}
				$formatted_lines[] = '';
				continue;
			}
			
			// Process headings (###, ####, etc.)
			if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
				// Close any open list
				if ($in_list) {
					$formatted_lines[] = $in_list === 'ul' ? '</ul>' : '</ol>';
					$in_list = false;
					$list_type = null;
				}
				
				$level = strlen($matches[1]);
				$heading_text = trim($matches[2]);
				// Convert bold/italic in headings
				$heading_text = self::convertInlineMarkdown($heading_text);
				$formatted_lines[] = '<h' . $level . '>' . $heading_text . '</h' . $level . '>';
				continue;
			}
			
			// Process unordered lists (-, *, +)
			if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $matches)) {
				if (!$in_list || $list_type !== 'ul') {
					if ($in_list && $list_type === 'ol') {
						$formatted_lines[] = '</ol>';
					}
					$formatted_lines[] = '<ul>';
					$in_list = 'ul';
					$list_type = 'ul';
				}
				$list_item = trim($matches[1]);
				// Convert bold/italic in list items
				$list_item = self::convertInlineMarkdown($list_item);
				$formatted_lines[] = '<li>' . $list_item . '</li>';
				continue;
			}
			
			// Process ordered lists (1., 2., etc.)
			if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $matches)) {
				if (!$in_list || $list_type !== 'ol') {
					if ($in_list && $list_type === 'ul') {
						$formatted_lines[] = '</ul>';
					}
					$formatted_lines[] = '<ol>';
					$in_list = 'ol';
					$list_type = 'ol';
				}
				$list_item = trim($matches[1]);
				// Convert bold/italic in list items
				$list_item = self::convertInlineMarkdown($list_item);
				$formatted_lines[] = '<li>' . $list_item . '</li>';
				continue;
			}
			
			// Regular paragraph line
			// Close any open list
			if ($in_list) {
				$formatted_lines[] = $in_list === 'ul' ? '</ul>' : '</ol>';
				$in_list = false;
				$list_type = null;
			}
			
			// Convert inline markdown (bold, italic) in regular text
			$formatted_line = self::convertInlineMarkdown($trimmed);
			$formatted_lines[] = $formatted_line;
		}
		
		// Close any remaining open list
		if ($in_list) {
			$formatted_lines[] = $in_list === 'ul' ? '</ul>' : '</ol>';
		}
		
		// Join lines back together
		$converted = implode("\n", $formatted_lines);
		
		return $converted;
	}
	
	/**
	 * Convert inline markdown formatting (bold, italic) to HTML
	 *
	 * @param string $text The text to convert
	 * @return string The text with HTML formatting
	 */
	private static function convertInlineMarkdown($text) {
		// Convert **bold** to <strong>bold</strong>
		// Handle cases where ** might be at word boundaries
		$text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
		
		// Convert *italic* to <em>italic</em> (but not if it's part of **)
		// Only match single * that aren't part of **
		$text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
		
		return $text;
	}
	
	/**
	 * Add paragraph breaks after sentence-ending periods for better readability
	 * Intelligently handles abbreviations and doesn't break mid-sentence
	 *
	 * @param string $content The content to format
	 * @return string The content with paragraph breaks after sentences
	 */
	private static function addSentenceBreaks($content) {
		if (empty($content)) {
			return '';
		}
		
		// Don't process if content already has HTML structure (headings, lists, etc.)
		// This prevents breaking up already-formatted content
		if (preg_match('/^#{1,6}\s+/m', $content) || 
		    preg_match('/^[-*+]\s+/m', $content) ||
		    preg_match('/^<(h[1-6]|ul|ol|li|p)[\s>]/i', $content)) {
			return $content;
		}
		
		// Common abbreviations that shouldn't trigger sentence breaks
		// These are patterns that end with a period but aren't sentence endings
		$abbreviations = [
			'\b(Dr|Mr|Mrs|Ms|Prof|Sr|Jr|vs|etc|i\.e|e\.g|cf|vs|approx|est|min|max|no|vol|pp|ed|rev|inc|corp|ltd|co|st|ave|blvd|rd|dr)\.',
			'\b\d+\.', // Numbers with periods (like "1.", "2.")
		];
		
		// Split content into lines to process
		$lines = preg_split('/\r?\n/', $content);
		$formatted_lines = [];
		
		foreach ($lines as $line) {
			$trimmed = trim($line);
			
			// Skip empty lines and lines that are already formatted
			if (empty($trimmed) || preg_match('/^#{1,6}\s+/', $trimmed) || preg_match('/^[-*+]\s+/', $trimmed)) {
				$formatted_lines[] = $line;
				continue;
			}
			
			// Process the line to add breaks after sentence-ending periods
			// Pattern: period followed by space and capital letter (or end of line)
			// But exclude abbreviations
			$processed = $trimmed;
			
			// First, protect abbreviations by temporarily replacing them
			$abbrev_placeholders = [];
			$abbrev_counter = 0;
			
			foreach ($abbreviations as $pattern) {
				$processed = preg_replace_callback('/' . $pattern . '/i', function($matches) use (&$abbrev_placeholders, &$abbrev_counter) {
					$placeholder = '__ABBREV_' . $abbrev_counter . '__';
					$abbrev_placeholders[$placeholder] = $matches[0];
					$abbrev_counter++;
					return $placeholder;
				}, $processed);
			}
			
			// Now add paragraph breaks after sentence-ending periods
			// Match: period/exclamation/question, optional quote/closing punctuation, space, capital letter
			// Pattern matches: . " or . ) followed by space and capital letter (including accented chars)
			$processed = preg_replace('/([.!?][\'"\)]*)\s+([A-ZÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞ])/u', '$1' . "\n\n" . '$2', $processed);
			
			// Also handle periods at end of line (before a newline or end of string)
			// This catches sentences that end at the end of a line
			$processed = preg_replace('/([.!?][\'"\)]*)(\s*)$/m', '$1' . "\n\n", $processed);
			
			// Restore abbreviations
			foreach ($abbrev_placeholders as $placeholder => $original) {
				$processed = str_replace($placeholder, $original, $processed);
			}
			
			// Clean up multiple consecutive line breaks (max 2)
			$processed = preg_replace('/\n{3,}/', "\n\n", $processed);
			
			$formatted_lines[] = $processed;
		}
		
		return implode("\n", $formatted_lines);
	}
	
	/**
	 * Process HTML blocks and wrap regular text in paragraphs
	 * Handles multi-line HTML blocks (lists, headings) properly
	 *
	 * @param string $content The HTML content to process
	 * @return string The processed content
	 */
	private static function processHtmlBlocks($content) {
		if (empty($content)) {
			return '';
		}
		
		// After markdown conversion, content should have proper HTML structure
		// We need to preserve HTML blocks and wrap plain text in paragraphs
		// Split by double newlines, but be smart about HTML blocks
		
		// First, check if content is already well-formatted HTML
		// If it starts with HTML tags and has proper structure, use it as-is
		if (preg_match('/^<(h[1-6]|ul|ol|p)/i', $content)) {
			// Content starts with HTML - check if it's complete
			// Count opening and closing tags to see if structure is intact
			$open_tags = preg_match_all('/<(h[1-6]|ul|ol|li|p)[\s>]/i', $content);
			$close_tags = preg_match_all('/<\/(h[1-6]|ul|ol|li|p)>/i', $content);
			
			// If tags are balanced and content looks formatted, return as-is
			if ($open_tags > 0 && $open_tags <= $close_tags + 2) {
				// Clean up any extra whitespace between blocks
				$content = preg_replace('/>\s*\n\s*</', ">\n<", $content);
				return $content;
			}
		}
		
		// Split by double newlines, but preserve complete HTML blocks
		$lines = explode("\n", $content);
		$blocks = [];
		$current_block = [];
		$in_html_block = false;
		
		foreach ($lines as $line) {
			$trimmed = trim($line);
			
			// Check if line starts an HTML block
			if (preg_match('/^<(h[1-6]|ul|ol|li|p)[\s>]/i', $trimmed)) {
				// Start of HTML block
				if (!empty($current_block) && !$in_html_block) {
					// Save previous text block
					$text_block = implode("\n", $current_block);
					if (!empty(trim($text_block))) {
						$blocks[] = trim($text_block);
					}
					$current_block = [];
				}
				$current_block[] = $trimmed;
				$in_html_block = true;
			} elseif ($in_html_block && preg_match('/<\/(h[1-6]|ul|ol|li|p)>/i', $trimmed)) {
				// End of HTML block
				$current_block[] = $trimmed;
				$blocks[] = implode("\n", $current_block);
				$current_block = [];
				$in_html_block = false;
			} elseif ($in_html_block) {
				// Continuation of HTML block
				$current_block[] = $trimmed;
			} else {
				// Regular text line
				if (empty($trimmed)) {
					// Empty line - save current block if not empty
					if (!empty($current_block)) {
						$text_block = implode("\n", $current_block);
						if (!empty(trim($text_block))) {
							$blocks[] = trim($text_block);
						}
						$current_block = [];
					}
				} else {
					$current_block[] = $trimmed;
				}
			}
		}
		
		// Add any remaining block
		if (!empty($current_block)) {
			$text_block = implode("\n", $current_block);
			if (!empty(trim($text_block))) {
				$blocks[] = trim($text_block);
			}
		}
		
		// Process blocks: HTML blocks stay as-is, text blocks get wrapped in <p>
		$formatted_blocks = [];
		foreach ($blocks as $block) {
			$block = trim($block);
			if (empty($block)) {
				continue;
			}
			
			// Check if it's already an HTML block
			if (preg_match('/^<(h[1-6]|ul|ol|li|p)[\s>]/i', $block)) {
				$formatted_blocks[] = $block;
			} else {
				// Regular text - wrap in paragraph
				$block = str_replace(["\n", "\r"], '<br>', $block);
				$block = preg_replace('/<br\s*\/?>\s*<br\s*\/?>/', '<br>', $block);
				$formatted_blocks[] = '<p>' . $block . '</p>';
			}
		}
		
		return implode("\n", $formatted_blocks);
	}

	/**
	 * Format text editor content with proper HTML structure
	 *
	 * @param string $content The raw content to format
	 * @return string The formatted content
	 */
	public static function formatTextEditorContent($content) {
		if (empty($content)) {
			return '';
		}
		
		// If content already has HTML tags, return as is (unless it has markdown)
		if (strip_tags($content) !== $content) {
			// Check if it has markdown patterns that need conversion
			$has_markdown = preg_match('/^#{1,6}\s+/m', $content) || 
			                preg_match('/^[-*+]\s+/m', $content) ||
			                preg_match('/\*\*[^*]+\*\*/', $content);
			if (!$has_markdown) {
				return $content;
			}
		}
		
		// Step 0: Add sentence breaks for better readability (before markdown conversion)
		// This improves readability by adding paragraph breaks after sentences
		$content = self::addSentenceBreaks($content);
		
		// Step 1: Convert markdown to HTML first
		$content = self::convertMarkdownToHtml($content);
		
		// Step 2: Clean up the content
		$content = trim($content);
		
		// Step 3: Process HTML blocks properly
		// Split by double newlines, but preserve multi-line HTML blocks
		$formatted_content = self::processHtmlBlocks($content);
		
		error_log('[AEBG] Formatted text editor content: ' . substr($formatted_content, 0, 200) . '...');
		
		return $formatted_content;
	}

	/**
	 * Parse content into list items for icon-list widgets
	 *
	 * @param string $content The content to parse
	 * @return array Array of list items with text and _id
	 */
	public static function parseContentIntoListItems($content) {
		$list_items = [];
		
		// Strip only <p> tags from content (allow other HTML like <b>, <i>, <strong>, <span>, etc.)
		$content = preg_replace('/<\/?p[^>]*>/i', '', $content);
		
		// Split content by common list separators
		$lines = preg_split('/[\r\n]+/', $content);
		
		foreach ($lines as $line) {
			$line = trim($line);
			if (!empty($line)) {
				// Remove common list markers
				$line = preg_replace('/^[-*•\d]+\.?\s*/', '', $line);
				if (!empty($line)) {
					$list_items[] = [
						'text' => $line,
						'_id' => uniqid('item_')
					];
				}
			}
		}
		
		return $list_items;
	}

	/**
	 * Format product content for display
	 *
	 * @param string $content The generated content
	 * @param array $product The product data
	 * @return string The formatted content
	 */
	public static function formatProductContent($content, $product) {
		// Basic formatting - can be extended with product-specific formatting
		$formatted = self::formatTextEditorContent($content);
		
		// Add product-specific formatting if needed
		if (!empty($product['name'])) {
			// Could add product name as heading, etc.
		}
		
		return $formatted;
	}

	/**
	 * Format heading content
	 *
	 * @param string $content The content to format
	 * @return string The formatted heading
	 */
	public static function formatHeadingContent($content) {
		if (empty($content)) {
			return '';
		}

		// Clean and trim
		$content = trim($content);
		
		// Remove HTML tags if present (headings should be plain text)
		$content = strip_tags($content);
		
		// Sanitize for heading use
		$content = sanitize_text_field($content);
		
		return $content;
	}

	/**
	 * Format button content
	 *
	 * @param string $content The content to format
	 * @return string The formatted button text
	 */
	public static function formatButtonContent($content) {
		if (empty($content)) {
			return '';
		}

		// Clean and trim
		$content = trim($content);
		
		// Remove HTML tags (buttons should be plain text)
		$content = strip_tags($content);
		
		// CRITICAL: Don't use sanitize_text_field as it truncates long text
		// Instead, use wp_kses_post to allow safe HTML and preserve full length
		// But since buttons should be plain text, just clean it manually
		$content = wp_strip_all_tags($content);
		$content = trim($content);
		
		// Remove any control characters but preserve newlines/spaces for multi-line buttons
		$content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
		
		return $content;
	}

	/**
	 * Validate formatted content
	 *
	 * @param string $content The content to validate
	 * @param string $type The content type (text-editor, heading, button, etc.)
	 * @return bool|\WP_Error True if valid, WP_Error if invalid
	 */
	public static function validateFormattedContent($content, $type = 'text-editor') {
		if (empty($content) && $type !== 'button') {
			return new \WP_Error('empty_content', 'Content cannot be empty for type: ' . $type);
		}

		// Check for XSS attempts
		$stripped = strip_tags($content);
		if ($stripped !== $content && $type === 'heading') {
			return new \WP_Error('invalid_html', 'Headings should not contain HTML tags');
		}

		// Check content length
		$max_lengths = [
			'heading' => 200,
			'button' => 50,
			'text-editor' => 10000,
		];

		if (isset($max_lengths[$type]) && strlen($content) > $max_lengths[$type]) {
			return new \WP_Error('content_too_long', 'Content exceeds maximum length for type: ' . $type);
		}

		return true;
	}
}

