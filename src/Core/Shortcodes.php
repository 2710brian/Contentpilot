<?php

namespace AEBG\Core;

use AEBG\Core\CurrencyManager;


class Shortcodes {
	private static $products = [];

	public function __construct() {
		add_shortcode( 'bit_products', [ $this, 'bit_products_shortcode' ] );
		add_shortcode( 'aebg_price_comparison', [ $this, 'price_comparison_shortcode' ] );
		add_shortcode( 'aebg_email_signup', [ $this, 'email_signup_shortcode' ] );
	}

	/**
	 * Check if we're in Elementor editor context OR frontend template rendering
	 * 
	 * CRITICAL: Elementor needs to detect the_content() in templates (especially header/footer)
	 * to allow editing. We must NOT block shortcode execution when Elementor is in edit mode
	 * OR when Elementor is scanning templates to detect if they can be edited.
	 * 
	 * CRITICAL: Also allow execution when Elementor Header & Footer templates are being rendered
	 * on the frontend, as blocking shortcodes can prevent these templates from displaying properly.
	 * 
	 * @return bool True if in Elementor editor context or frontend template rendering, false otherwise
	 */
	private function is_elementor_editor_context() {
		// Check if Elementor plugin is active
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		
		// Method 1: Check Elementor's editor API (most reliable)
		try {
			$elementor = \Elementor\Plugin::instance();
			if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
				if ( $elementor->editor->is_edit_mode() ) {
					return true;
				}
			}
		} catch ( \Exception $e ) {
			// Elementor API not available, continue with other checks
		}
		
		// Method 2: Check for Elementor editor query parameters
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) {
			return true;
		}
		
		// Method 3: Check for Elementor preview mode
		if ( isset( $_GET['elementor-preview'] ) ) {
			return true;
		}
		
		// Method 4: Check for Elementor in POST data (AJAX requests)
		if ( isset( $_POST['action'] ) && strpos( $_POST['action'], 'elementor' ) !== false ) {
			return true;
		}
		
		// Method 5: Check if we're in admin and URL contains elementor
		if ( is_admin() && isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_uri = $_SERVER['REQUEST_URI'];
			if ( strpos( $request_uri, 'elementor' ) !== false ) {
				// Check for various Elementor admin pages
				if ( strpos( $request_uri, 'action=elementor' ) !== false ||
				     strpos( $request_uri, 'post_type=elementor_library' ) !== false ||
				     strpos( $request_uri, 'page=elementor' ) !== false ) {
					return true;
				}
			}
		}
		
		// Method 6: Check if we're on an Elementor template post type
		// This is critical for template creation - Elementor scans templates to detect the_content()
		if ( is_admin() ) {
			$screen = get_current_screen();
			if ( $screen && ( $screen->post_type === 'elementor_library' || 
			                  $screen->id === 'elementor_library' ||
			                  $screen->base === 'edit' && $screen->post_type === 'elementor_library' ) ) {
				return true;
			}
		}
		
		// Method 7: Check if current post is an Elementor template
		// This helps when Elementor is scanning templates to detect if they can be edited
		$post_id = get_the_ID();
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );
			if ( $post_type === 'elementor_library' ) {
				return true;
			}
		}
		
		// Method 8: Check for Elementor template creation/editing via AJAX
		// Elementor uses AJAX to check if templates can be edited
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			if ( isset( $_REQUEST['action'] ) && strpos( $_REQUEST['action'], 'elementor' ) !== false ) {
				return true;
			}
			// Check for Elementor template scanning requests
			if ( isset( $_REQUEST['editor_post_id'] ) || isset( $_REQUEST['template_id'] ) ) {
				// If we're in an AJAX request that might be Elementor-related, allow it
				// This is safer than blocking potentially legitimate Elementor requests
				if ( isset( $_SERVER['HTTP_REFERER'] ) && strpos( $_SERVER['HTTP_REFERER'], 'elementor' ) !== false ) {
					return true;
				}
			}
		}
		
		// Method 9: CRITICAL FIX - Check if we're rendering Elementor Header/Footer templates on frontend
		// Elementor Header & Footer templates are rendered on every page load, and blocking shortcodes
		// during their rendering can prevent them from displaying properly.
		if ( ! is_admin() ) {
			try {
				// Check if we're in the header/footer rendering context
				// This is the most reliable way to detect when Header/Footer templates are being rendered
				if ( did_action( 'get_header' ) || did_action( 'get_footer' ) ) {
					// We're rendering header or footer - allow shortcode execution
					// to prevent blocking Elementor Header & Footer templates
					// This ensures Header & Footer templates can render properly on the frontend
					return true;
				}
				
				// Also check if Elementor Pro Theme Builder is active and we're on frontend
				// This covers cases where Elementor is rendering templates via Theme Builder
				if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
					$elementor = \Elementor\Plugin::instance();
					
					// Check if Elementor frontend is rendering a template
					if ( isset( $elementor->frontend ) ) {
						// Check if we're rendering an Elementor template by checking
						// if the current post being rendered is an Elementor template
						global $wp_query;
						if ( isset( $wp_query ) && isset( $wp_query->queried_object ) ) {
							$queried_object = $wp_query->queried_object;
							if ( isset( $queried_object->post_type ) && $queried_object->post_type === 'elementor_library' ) {
								return true;
							}
						}
					}
				}
			} catch ( \Exception $e ) {
				// Elementor API not available, continue
			}
		}
		
		return false;
	}

	public function bit_products_shortcode( $atts ) {
		// TEMPORARY DEBUG: Allow disabling shortcode blocking via constant or filter
		// Add this to wp-config.php to disable: define('AEBG_DISABLE_SHORTCODE_BLOCKING', true);
		$disable_blocking = defined('AEBG_DISABLE_SHORTCODE_BLOCKING') && AEBG_DISABLE_SHORTCODE_BLOCKING;
		$disable_blocking = apply_filters('aebg_disable_shortcode_blocking', $disable_blocking);
		
		// CRITICAL: Prevent shortcode execution during content generation
		// When generation is in progress, shortcodes should return fallback to avoid
		// processing merchant data, database queries, and other expensive operations
		// that can cause hangs when Elementor renders posts during editing
		// Use BOTH checks: global variable (more reliable) and ActionHandler flag
		// Also check for API requests in progress (wp_remote_post can trigger WordPress hooks)
		// CRITICAL: Allow execution when Elementor is in edit mode - Elementor needs to detect
		// the_content() in templates (especially header/footer) to allow editing
		$is_elementor_editor = $this->is_elementor_editor_context();
		
		if (!$disable_blocking && !$is_elementor_editor && 
		    ((isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']) ||
		     (class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing()) ||
		     (isset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']) && $GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']))) {
			error_log('[AEBG] bit_products_shortcode - BLOCKED: Skipping execution during generation/API request');
			$atts = shortcode_atts(['fallback' => ''], $atts, 'bit_products');
			return $atts['fallback'];
		}
		
		$atts = shortcode_atts(
			[
				'order' => 1,
				'field' => 'name',
				'fallback' => '',
				'format' => 'text', // text, html, json
				'size' => 'medium', // thumbnail, medium, large, full
			],
			$atts,
			'bit_products'
		);

		$post_id = get_the_ID();
		if (!$post_id) {
			return $atts['fallback'];
		}

		// Try to get products from post meta first (preferred method)
		$products = get_post_meta( $post_id, '_aebg_products', true );
		$product_ids = get_post_meta( $post_id, '_aebg_product_ids', true );

		// If no products found, return fallback
		if ( empty( $products ) && empty( $product_ids ) ) {
			error_log('[AEBG] No products found for post ' . $post_id);
			return $atts['fallback'];
		}

		// Use products array if available (new method)
		if (!empty($products) && is_array($products)) {
			$product_index = (int) $atts['order'] - 1;
			$product = $products[$product_index] ?? null;
			
			if ($product) {
				// Handle special image fields
				if (strpos($atts['field'], 'featured-image') !== false) {
					return $this->handleImageField($product, $atts['field'], $atts['size'], $atts['format'], $atts['fallback']);
				}
				
				if (strpos($atts['field'], 'gallery-images') !== false) {
					return $this->handleGalleryField($product, $atts['field'], $atts['size'], $atts['format'], $atts['fallback']);
				}
				
				// Handle variant fields
				if (strpos($atts['field'], 'variant-') === 0) {
					return $this->handleVariantField($product, $atts['field'], $atts['format'], $atts['fallback']);
				}
				
				// Handle regular fields
				if (isset($product[$atts['field']])) {
					return $this->format_output($product[$atts['field']], $atts['format']);
				}
			}
		}

		// Fallback to product IDs method (backward compatibility)
		if (!empty($product_ids) && is_array($product_ids)) {
			$product_id = $product_ids[ (int) $atts['order'] - 1 ] ?? '';
			if ( empty( $product_id ) ) {
				return $atts['fallback'];
			}

			$product = $this->get_product_by_id($product_id);
			if ($product) {
				// Handle special image fields
				if (strpos($atts['field'], 'featured-image') !== false) {
					return $this->handleImageField($product, $atts['field'], $atts['size'], $atts['format'], $atts['fallback']);
				}
				
				if (strpos($atts['field'], 'gallery-images') !== false) {
					return $this->handleGalleryField($product, $atts['field'], $atts['size'], $atts['format'], $atts['fallback']);
				}
				
				// Handle variant fields
				if (strpos($atts['field'], 'variant-') === 0) {
					return $this->handleVariantField($product, $atts['field'], $atts['format'], $atts['fallback']);
				}
				
				// Handle regular fields
				if (isset($product[$atts['field']])) {
					return $this->format_output($product[$atts['field']], $atts['format']);
				}
			}
		}

		return $atts['fallback'];
	}

	/**
	 * Handle featured image field
	 */
	private function handleImageField($product, $field, $size, $format, $fallback) {
		// Check for attachment ID first
		if (!empty($product['featured_image_id'])) {
			$attachment_id = $product['featured_image_id'];
			
			switch ($field) {
				case 'featured-image':
					return wp_get_attachment_url($attachment_id);
				case 'featured-image-id':
					return $attachment_id;
				case 'featured-image-html':
					return wp_get_attachment_image($attachment_id, $size);
				default:
					return $fallback;
			}
		}
		
		// Fallback to image_url if no attachment ID
		if (!empty($product['image_url'])) {
			switch ($field) {
				case 'featured-image':
					return $product['image_url'];
				case 'featured-image-html':
					return '<img src="' . esc_url($product['image_url']) . '" alt="' . esc_attr($product['name'] ?? '') . '" />';
				default:
					return $fallback;
			}
		}
		
		return $fallback;
	}

	/**
	 * Handle variant fields
	 */
	private function handleVariantField($product, $field, $format, $fallback) {
		if (empty($product['variants'])) {
			return $fallback;
		}

		$variant_info = ProductVariantManager::getVariantInfo($product);
		
		switch ($field) {
			case 'variant-count':
				return $variant_info['variant_count'];
			case 'variant-colors':
				return implode(', ', $variant_info['colors']);
			case 'variant-price-range':
				if ($variant_info['price_range']) {
					$min = $variant_info['price_range']['min'];
					$max = $variant_info['price_range']['max'];
					$currency = $variant_info['price_range']['currency'];
					
					if ($min === $max) {
						return ProductManager::formatPrice($min, $currency);
					} else {
						return ProductManager::formatPrice($min, $currency) . ' - ' . ProductManager::formatPrice($max, $currency);
					}
				}
				return $fallback;
			case 'variant-text':
				return ProductVariantManager::formatVariantText($product);
			case 'variant-list':
				$variant_list = [];
				foreach ($product['variants'] as $variant) {
					$variant_list[] = $variant['color'] . ': ' . ProductManager::formatPrice($variant['price'], $variant['currency']);
				}
				return implode(', ', $variant_list);
			default:
				return $fallback;
		}
	}

	/**
	 * Handle gallery images field
	 */
	private function handleGalleryField($product, $field, $size, $format, $fallback) {
		// Check for attachment IDs first
		if (!empty($product['gallery_image_ids']) && is_array($product['gallery_image_ids'])) {
			$attachment_ids = $product['gallery_image_ids'];
			
			switch ($field) {
				case 'gallery-images':
					$urls = [];
					foreach ($attachment_ids as $id) {
						$urls[] = wp_get_attachment_url($id);
					}
					return implode(',', $urls);
				case 'gallery-images-ids':
					return implode(',', $attachment_ids);
				case 'gallery-images-html':
					$html = '';
					foreach ($attachment_ids as $id) {
						$html .= wp_get_attachment_image($id, $size) . "\n";
					}
					return $html;
				default:
					return $fallback;
			}
		}
		
		// Fallback to gallery URLs if no attachment IDs
		if (!empty($product['gallery_image_urls']) && is_array($product['gallery_image_urls'])) {
			switch ($field) {
				case 'gallery-images':
					return implode(',', $product['gallery_image_urls']);
				case 'gallery-images-html':
					$html = '';
					foreach ($product['gallery_image_urls'] as $url) {
						$html .= '<img src="' . esc_url($url) . '" alt="' . esc_attr($product['name'] ?? '') . '" />' . "\n";
					}
					return $html;
				default:
					return $fallback;
			}
		}
		
		return $fallback;
	}

	/**
	 * Get product by ID with caching
	 */
	private function get_product_by_id($product_id) {
		if ( isset( self::$products[ $product_id ] ) ) {
			return self::$products[ $product_id ];
		}

		$datafeedr = new Datafeedr();
		$product = $datafeedr->search( 'id:' . $product_id, 1 );

		if ( is_wp_error( $product ) || empty( $product ) ) {
			error_log('[AEBG] Failed to retrieve product ' . $product_id . ': ' . (is_wp_error($product) ? $product->get_error_message() : 'not found'));
			return null;
		}

		$product = $product[0];
		self::$products[ $product_id ] = $product;
		return $product;
	}

	/**
	 * Format output based on format parameter
	 */
	private function format_output($value, $format) {
		switch ($format) {
			case 'html':
				return esc_html($value);
			case 'json':
				return json_encode($value);
			case 'text':
			default:
				return $value;
		}
	}

	/**
	 * Get all products for a post (utility method)
	 * 
	 * PRODUCTION-READY: Delegates to ProductManager for consistency and caching
	 */
	public static function get_post_products($post_id = null) {
		if (!$post_id) {
			$post_id = get_the_ID();
		}
		
		if (!$post_id) {
			return [];
		}

		// Delegate to ProductManager for consistent behavior and caching
		return \AEBG\Core\ProductManager::getPostProducts($post_id);
	}

	/**
	 * Get product count for a post (utility method)
	 */
	public static function get_product_count($post_id = null) {
		if (!$post_id) {
			$post_id = get_the_ID();
		}
		
		if (!$post_id) {
			return 0;
		}

		$count = get_post_meta($post_id, '_aebg_product_count', true);
		if ($count !== '') {
			return (int) $count;
		}

		$products = self::get_post_products($post_id);
		return count($products);
	}

	/**
	 * Price comparison table shortcode
	 * Usage: [aebg_price_comparison product="1" style="table" show_image="true" show_rating="true"]
	 * Usage: [aebg_price_comparison product="{product_id}" style="table" show_image="true" show_rating="true"]
	 */
	public function price_comparison_shortcode($atts) {
		// TEMPORARY DEBUG: Allow disabling shortcode blocking via constant or filter
		// Add this to wp-config.php to disable: define('AEBG_DISABLE_SHORTCODE_BLOCKING', true);
		$disable_blocking = defined('AEBG_DISABLE_SHORTCODE_BLOCKING') && AEBG_DISABLE_SHORTCODE_BLOCKING;
		$disable_blocking = apply_filters('aebg_disable_shortcode_blocking', $disable_blocking);
		
		// CRITICAL: Prevent shortcode execution during content generation
		// When generation is in progress, shortcodes should return fallback to avoid
		// processing merchant data, database queries, and other expensive operations
		// that can cause hangs when Elementor renders posts during editing
		// Use BOTH checks: global variable (more reliable) and ActionHandler flag
		// Also check for API requests in progress (wp_remote_post can trigger WordPress hooks)
		// CRITICAL: Also check database for active generation (frontend requests are separate processes)
		// CRITICAL: Allow execution when Elementor is in edit mode - Elementor needs to detect
		// the_content() in templates (especially header/footer) to allow editing
		$is_elementor_editor = $this->is_elementor_editor_context();
		
		$is_generating = (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
		$is_executing = (class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing());
		$is_api_request = (isset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']) && $GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']);
		
		// CRITICAL: Check database for active generation (works across processes)
		// Frontend requests are separate PHP processes, so global flags don't work
		$is_generating_db = false;
		$current_post_id = get_the_ID();
		if ($current_post_id) {
			global $wpdb;
			// Check if this post has an active batch item (generation in progress)
			$active_batch = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}aebg_batch_items 
				 WHERE generated_post_id = %d 
				   AND status IN ('processing', 'pending') 
				 LIMIT 1",
				$current_post_id
			));
			$is_generating_db = !empty($active_batch);
			
			// Also check if post was recently created (within last 60 seconds) - likely during generation
			if (!$is_generating_db) {
				$post = get_post($current_post_id);
				if ($post && (time() - strtotime($post->post_date_gmt)) < 60) {
					// Double-check with batch items query
					$batch_item = $wpdb->get_var($wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}aebg_batch_items WHERE generated_post_id = %d AND status IN ('processing', 'pending') LIMIT 1",
						$current_post_id
					));
					$is_generating_db = !empty($batch_item);
				}
			}
		}
		
		if (!$disable_blocking && !$is_elementor_editor && ($is_generating || $is_executing || $is_api_request || $is_generating_db)) {
			error_log('[AEBG] price_comparison_shortcode - BLOCKED: Skipping execution during generation/API request (is_generating: ' . ($is_generating ? 'true' : 'false') . ', is_executing: ' . ($is_executing ? 'true' : 'false') . ', is_api_request: ' . ($is_api_request ? 'true' : 'false') . ', is_generating_db: ' . ($is_generating_db ? 'true' : 'false') . ')');
			$atts = shortcode_atts(['class' => ''], $atts, 'aebg_price_comparison');
			return '<div class="aebg-price-comparison ' . esc_attr($atts['class']) . '">Merchant comparison will be processed asynchronously</div>';
		}
		
		// CRITICAL: Suppress WordPress deprecation warnings during entire shortcode execution
		// The footer.php deprecation warning can break execution during shortcode rendering
		$old_error_reporting = error_reporting();
		error_reporting($old_error_reporting & ~E_DEPRECATED);
		
		try {
			// Validate and sanitize input attributes
			$atts = $this->validate_shortcode_attributes($atts);
			
			$atts = shortcode_atts([
				'product' => 1, // Which product to show comparison for (1, 2, 3, etc. or {product_id})
				'style' => 'table', // table, cards, list
				'show_image' => 'true',
				'show_rating' => 'true',
				'show_availability' => 'true',
				'limit' => 10, // Max number of merchants to show
				'class' => '', // Additional CSS classes
			], $atts, 'aebg_price_comparison');

			$post_id = get_the_ID();
			if (!$post_id) {
				error_reporting($old_error_reporting);
				return $this->get_standardized_error_message('no_post_id');
			}

			// Get product based on shortcode attributes
			$product = $this->get_product_from_shortcode($post_id, $atts['product']);
			if (!$product) {
				error_reporting($old_error_reporting);
				return $this->get_standardized_error_message('product_not_found', [
					'product_identifier' => $atts['product']
				]);
			}
			
			// Validate and sanitize product data
			$validated_product = $this->validate_product_data($product);
			if (!$validated_product) {
				error_reporting($old_error_reporting);
				return $this->get_standardized_error_message('invalid_product_data', [
					'product_id' => $product['id'] ?? 'Unknown'
				]);
			}
			$product = $validated_product;

			// Get comparison data with fallback strategies
			$comparison_data = $this->get_comparison_data_with_fallbacks($post_id, $product);
			
			// Prepare merchants data for display
			$merchants = $this->prepare_merchants_for_display($comparison_data, $atts['limit']);
			
			if (empty($merchants)) {
				// Check if merchant comparison is being processed asynchronously
				// Show a more helpful message if data is still being processed
				if (empty($comparison_data)) {
					// Merchant comparison is being processed asynchronously
					error_reporting($old_error_reporting);
					return $this->get_standardized_error_message('merchant_comparison_processing', [
						'product_id' => $product['id'] ?? 'Unknown',
						'product_name' => $product['name'] ?? 'Unknown'
					]);
				}
				
				// No merchants found even after processing
				error_reporting($old_error_reporting);
				return $this->get_standardized_error_message('no_merchants_found', [
					'product_id' => $product['id'] ?? 'Unknown',
					'product_name' => $product['name'] ?? 'Unknown'
				]);
			}

			// Generate the comparison table with loading state support
			$result = $this->generate_comparison_html($product, $merchants, $atts);
			error_reporting($old_error_reporting);
			return $result;
		} catch (\Throwable $e) {
			error_log('[AEBG] ERROR in price_comparison_shortcode: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
			error_reporting($old_error_reporting);
			// Return safe fallback
			return $this->get_standardized_error_message('shortcode_error', [
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Get comparison data from database by product name (fallback for IWAO/oGAWA products)
	 */
	private function get_comparison_data_by_name($post_id, $product_name) {
		// CRITICAL: Prevent execution during content generation
		// This method does expensive database queries that can cause hangs
		// Use BOTH checks: global variable (more reliable) and ActionHandler flag
		// Also check for API requests in progress (wp_remote_post can trigger WordPress hooks)
		// CRITICAL: Allow execution when Elementor is in edit mode - Elementor needs to detect
		// the_content() in templates (especially header/footer) to allow editing
		$is_elementor_editor = $this->is_elementor_editor_context();
		
		if (!$is_elementor_editor &&
		    ((isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']) ||
		     (class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing()) ||
		     (isset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']) && $GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']))) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log('[AEBG] get_comparison_data_by_name - BLOCKED: Skipping during generation/API request');
			}
			return [];
		}
		
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'aebg_comparisons';
		
		// if (defined('WP_DEBUG') && WP_DEBUG) {
		// 	error_log('[AEBG] get_comparison_data_by_name - Looking for post_id: ' . $post_id . ', product_name: ' . $product_name);
		// }
		
		// Search for comparison data that might contain this product name
		$query = $wpdb->prepare(
			"SELECT comparison_data FROM $table_name WHERE post_id = %d AND status = 'active' AND comparison_data LIKE %s ORDER BY updated_at DESC LIMIT 1",
			$post_id,
			'%' . $wpdb->esc_like($product_name) . '%'
		);
		// if (defined('WP_DEBUG') && WP_DEBUG) {
		// 	error_log('[AEBG] get_comparison_data_by_name - Query: ' . $query);
		// }
		
		$result = $wpdb->get_row($query, ARRAY_A);

		if ($result && !empty($result['comparison_data'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[AEBG] get_comparison_data_by_name - Found data by product name');
			}
			$data = json_decode($result['comparison_data'], true);
			if ($data) {
				// Check for both data structures: 'products' (old) and 'merchants' (new)
				if (isset($data['merchants']) && is_array($data['merchants']) && count($data['merchants']) > 0) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('[AEBG] get_comparison_data_by_name - Found merchants data with ' . count($data['merchants']) . ' merchants');
					}
					return $data; // New format - return as-is
				} elseif (isset($data['products']) && is_array($data['products']) && count($data['products']) > 0) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('[AEBG] get_comparison_data_by_name - Found products data with ' . count($data['products']) . ' products');
					}
					return $this->convert_comparison_format($data); // Old format - convert
				}
			}
		}

		// if (defined('WP_DEBUG') && WP_DEBUG) {
		// 	error_log('[AEBG] get_comparison_data_by_name - No comparison data found by product name');
		// }
		return [];
	}

	/**
	 * Get comparison data from database with caching
	 * Optimized method that uses a single, efficient query with proper fallbacks
	 */
	private function get_comparison_data($post_id, $product_id) {
		// CRITICAL: Prevent execution during content generation
		// This method does expensive database queries that can cause hangs
		// Use BOTH checks: global variable (more reliable) and ActionHandler flag
		// Also check for API requests in progress (wp_remote_post can trigger WordPress hooks)
		// CRITICAL: Also check database for active generation (frontend requests are separate processes)
		// CRITICAL: Allow execution when Elementor is in edit mode - Elementor needs to detect
		// the_content() in templates (especially header/footer) to allow editing
		$is_elementor_editor = $this->is_elementor_editor_context();
		
		$is_generating_global = (isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']);
		$is_executing = (class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing());
		$is_api_request = (isset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']) && $GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']);
		
		// CRITICAL: Check database for active generation (works across processes)
		// Frontend requests are separate PHP processes, so global flags don't work
		$is_generating_db = false;
		if ($post_id) {
			global $wpdb;
			// Check if this post has an active batch item (generation in progress)
			$active_batch = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}aebg_batch_items 
				 WHERE generated_post_id = %d 
				   AND status IN ('processing', 'pending') 
				 LIMIT 1",
				$post_id
			));
			$is_generating_db = !empty($active_batch);
		}
		
		if (!$is_elementor_editor && ($is_generating_global || $is_executing || $is_api_request || $is_generating_db)) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log('[AEBG] get_comparison_data - BLOCKED: Skipping during generation/API request (global: ' . ($is_generating_global ? 'yes' : 'no') . ', executing: ' . ($is_executing ? 'yes' : 'no') . ', api: ' . ($is_api_request ? 'yes' : 'no') . ', db: ' . ($is_generating_db ? 'yes' : 'no') . ')');
			}
			return [];
		}
		
		// CRITICAL: Add timeout protection for database queries during generation
		// Prevents hanging if database is slow or overloaded
		$query_start = microtime(true);
		$max_query_time = 5; // 5 seconds max per query
		
		// Check cache first
		$cache_key = 'aebg_comparison_' . md5($post_id . '_' . $product_id);
		$cached_data = wp_cache_get($cache_key, 'aebg_comparisons');
		
		if ($cached_data !== false) {
			// if (defined('WP_DEBUG') && WP_DEBUG) {
			// 	error_log('[AEBG] get_comparison_data - Cache HIT for product_id: ' . $product_id);
			// }
			return $cached_data;
		}
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// error_log('[AEBG] get_comparison_data - Cache MISS for product_id: ' . $product_id);
		}
		
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'aebg_comparisons';
		
		// Single optimized query with proper priority ordering
		// CRITICAL: Also check for records with null post_id (created before post creation)
		// This ensures comparison data is found even if it was generated before the post existed
		$user_id = get_current_user_id();
		
		// Build optimized query that handles both post_id matches and null post_id records
		// Uses composite index idx_product_status_updated for better performance
		if ($post_id) {
			// Optimized query using composite index (product_id, status, updated_at)
			// Filter by product_id and status first (uses index), then sort by priority and updated_at
			$query = $wpdb->prepare(
				"SELECT comparison_data, user_id, post_id, status, updated_at 
				 FROM $table_name 
				 WHERE product_id = %s 
				   AND status = 'active'
				   AND (post_id = %d OR post_id IS NULL)
				 ORDER BY 
					CASE 
						WHEN user_id = %d AND post_id = %d THEN 1
						WHEN user_id = %d AND post_id IS NULL THEN 2
						ELSE 3
					END,
					updated_at DESC 
				 LIMIT 1",
				$product_id,
				$post_id,
				$user_id,
				$post_id,
				$user_id
			);
		} else {
			// If no post_id provided, only look for null post_id records
			// Optimized to use composite index
			$query = $wpdb->prepare(
				"SELECT comparison_data, user_id, post_id, status, updated_at 
				 FROM $table_name 
				 WHERE product_id = %s 
				   AND status = 'active'
				   AND post_id IS NULL
				 ORDER BY 
					CASE 
						WHEN user_id = %d THEN 1
						ELSE 2
					END,
					updated_at DESC 
				 LIMIT 1",
				$product_id,
				$user_id
			);
		}
		
		// if (defined('WP_DEBUG') && WP_DEBUG) {
		// 	error_log('[AEBG] get_comparison_data - Optimized query: ' . $query);
		// }
		
		$result = $wpdb->get_row($query, ARRAY_A);
		
		// CRITICAL: Check if query took too long (watchdog)
		$query_elapsed = microtime(true) - $query_start;
		if ($query_elapsed > $max_query_time) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log('[AEBG] ⚠️ get_comparison_data query exceeded timeout (' . round($query_elapsed, 2) . 's > ' . $max_query_time . 's) for product_id: ' . $product_id);
			}
			// Return empty to prevent blocking - async processing will handle it
			wp_cache_set($cache_key, [], 'aebg_comparisons', 300);
			return [];
		}

		if ($result && !empty($result['comparison_data'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				$priority = $result['user_id'] == $user_id && $result['post_id'] == $post_id && $result['status'] == 'active' ? 'exact match' : 
						   ($result['status'] == 'active' ? 'active status' : 'any status');
				// error_log('[AEBG] get_comparison_data - Found data with priority: ' . $priority);
			}
			
			$data = json_decode($result['comparison_data'], true);
			if ($data) {
				// Check for both data structures: 'products' (old) and 'merchants' (new)
				if (isset($data['merchants']) && is_array($data['merchants']) && count($data['merchants']) > 0) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						// error_log('[AEBG] get_comparison_data - Found merchants data with ' . count($data['merchants']) . ' merchants, returning directly');
					}
					// Cache the result for 15 minutes
					wp_cache_set($cache_key, $data, 'aebg_comparisons', 900);
					return $data; // New format - return as-is
				} elseif (isset($data['products']) && is_array($data['products']) && count($data['products']) > 0) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						// error_log('[AEBG] get_comparison_data - Found products data with ' . count($data['products']) . ' products, converting format');
					}
					$converted_data = $this->convert_comparison_format($data);
					// Cache the converted result for 15 minutes
					wp_cache_set($cache_key, $converted_data, 'aebg_comparisons', 900);
					return $converted_data; // Old format - convert
				}
			}
		}

		// if (defined('WP_DEBUG') && WP_DEBUG) {
		// 	error_log('[AEBG] get_comparison_data - No comparison data found for product_id: ' . $product_id);
		// }
		
		// Cache empty result to prevent repeated database queries
		wp_cache_set($cache_key, [], 'aebg_comparisons', 300); // 5 minutes for empty results
		
		return [];
	}
	
	/**
	 * Invalidate cache for a specific product comparison
	 * Called when comparison data is updated
	 */
	public function invalidate_comparison_cache($post_id, $product_id) {
		$cache_key = 'aebg_comparison_' . md5($post_id . '_' . $product_id);
		wp_cache_delete($cache_key, 'aebg_comparisons');
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[AEBG] Cache invalidated for product_id: ' . $product_id);
		}
	}
	
	/**
	 * Get comparison data with comprehensive fallback strategies
	 */
	private function get_comparison_data_with_fallbacks($post_id, $product) {
		// Get comparison data from database - try multiple strategies
		$comparison_data = $this->get_comparison_data($post_id, $product['id']);
		
		// FALLBACK 1: If no comparison data found with post_id, try without post_id
		// This handles cases where comparison data was saved before post creation
		if (empty($comparison_data) && $post_id) {
			$comparison_data = $this->get_comparison_data(null, $product['id']);
			if (!empty($comparison_data)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[AEBG] Shortcode - Found comparison data without post_id (will be linked later)');
				}
			}
		}
		
		// FALLBACK 2: If no comparison data found by ID, try to find by product name (for IWAO/oGAWA products)
		if (empty($comparison_data) && !empty($product['name'])) {
			// if (defined('WP_DEBUG') && WP_DEBUG) {
			// 	error_log('[AEBG] Shortcode - No comparison data found by ID, trying to find by product name: ' . $product['name']);
			// }
			$comparison_data = $this->get_comparison_data_by_name($post_id, $product['name']);
			if (!empty($comparison_data)) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('[AEBG] Shortcode - Found comparison data by product name!');
				}
			}
		}
		
		// Enhanced debug logging for IWAO/oGAWA products (only in development)
		$product_name = $product['name'] ?? '';
		$product_brand = $product['brand'] ?? '';
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// error_log('[AEBG] Shortcode - Post ID: ' . $post_id . ', Product ID: ' . $product['id']);
			// error_log('[AEBG] Shortcode - Product Name: ' . ($product_name));
			// error_log('[AEBG] Shortcode - Product Brand: ' . ($product_brand));
			
			// Special debugging for IWAO/OGAWA products
			if (stripos($product_name, 'IWAO') !== false || stripos($product_name, 'OGAWA') !== false || 
				stripos($product_brand, 'IWAO') !== false || stripos($product_brand, 'OGAWA') !== false) {
				error_log('[AEBG] 🔍 IWAO/OGAWA PRODUCT DETECTED - Enhanced debugging enabled');
			}
			
		// error_log('[AEBG] Shortcode - Comparison data found: ' . (!empty($comparison_data) ? 'yes' : 'no'));
		if (!empty($comparison_data)) {
			// error_log('[AEBG] Shortcode - Merchants count: ' . count($comparison_data['merchants'] ?? []));
			// error_log('[AEBG] Shortcode - First merchant name: ' . ($comparison_data['merchants'][0]['name'] ?? 'None'));
			}
		}
		
		// Check if we have comparison data with merchants (even just 1 merchant is valid)
		// Also handle both old and new data formats
		if (empty($comparison_data) || 
			(!isset($comparison_data['merchants']) && !isset($comparison_data['products'])) || 
			(!is_array($comparison_data['merchants']) && !is_array($comparison_data['products']))) {
			
			// DEFERRED: Merchant comparison is now processed asynchronously after post creation
			// Don't fetch synchronously during shortcode rendering to prevent timeouts
			// The data will be available on next page load after async processing completes
			// if (defined('WP_DEBUG') && WP_DEBUG) {
			// 	error_log('[AEBG] No comparison data found - merchant comparison will be processed asynchronously');
			// }
			
			// Return empty array to show placeholder/loading state
			// The shortcode will display a message that data is being processed
			return [];
		}
		
		return $comparison_data;
	}
	
	/**
	 * Prepare merchants data for display with proper formatting
	 */
	private function prepare_merchants_for_display($comparison_data, $limit) {
		// Handle both old and new data formats
		$merchants = [];
		if (isset($comparison_data['merchants']) && is_array($comparison_data['merchants'])) {
			// New format: merchants can be associative array (key = merchant name) or indexed array
			foreach ($comparison_data['merchants'] as $key => $merchant) {
				if (is_array($merchant)) {
					// If key is numeric, it's an indexed array; otherwise key is the merchant name
					if (!is_numeric($key)) {
						// Preserve merchant name from key
						$merchant['merchant_name'] = $key;
						$merchant['name'] = $merchant['name'] ?? $key;
					}
					$merchants[] = $merchant;
				}
			}
		} elseif (isset($comparison_data['products']) && is_array($comparison_data['products'])) {
			// Old format: convert products to merchants array
			$merchants = array_values($comparison_data['products']);
		}
		
		// CRITICAL: Always filter merchants by configured networks (not just when setting is enabled)
		// This ensures consistency between admin modal, database, and frontend shortcode
		$original_count = count($merchants);
		$merchants = $this->filter_merchants_by_configured_networks($merchants);
		$filtered_count = count($merchants);
		
		if ($original_count !== $filtered_count) {
			error_log('[AEBG] Shortcode: Filtered merchants by configured networks - ' . $original_count . ' before, ' . $filtered_count . ' after');
		}
		
		// Enhance existing merchants data with missing fields (for backward compatibility)
		foreach ($merchants as &$merchant) {
			$merchant = $this->enhance_merchant_data($merchant);
		}
		unset($merchant); // Break reference
		
		// Enrich merchants with Trustpilot ratings if enabled
		try {
			$settings = \AEBG\Admin\Settings::get_settings();
			if (!empty($settings['trustpilot_enabled'])) {
				$scraper = new \AEBG\Core\TrustpilotScraper();
				// Convert indexed array to associative for enrich_merchants (it expects keyed by merchant name)
				$merchants_assoc = [];
				foreach ($merchants as $merchant) {
					$merchant_name = $merchant['name'] ?? $merchant['merchant'] ?? 'Unknown';
					$merchants_assoc[$merchant_name] = $merchant;
				}
				$merchants_assoc = $scraper->enrich_merchants($merchants_assoc);
				// Convert back to indexed array
				$merchants = array_values($merchants_assoc);
			}
		} catch (\Throwable $e) {
			// Log error but don't break shortcode rendering
			error_log('[AEBG] Error enriching merchants with Trustpilot ratings: ' . $e->getMessage());
		}
		
		// Validate and sanitize each merchant
		$validated_merchants = [];
		foreach ($merchants as $merchant) {
			$validated_merchant = $this->validate_merchant_data($merchant);
			if ($validated_merchant) {
				$validated_merchants[] = $validated_merchant;
			}
		}
		
		// Limit merchants
		return array_slice($validated_merchants, 0, (int) $limit);
	}
	
	/**
	 * Enhance existing merchant data with missing fields for backward compatibility
	 * Adds finalprice, salediscount, and products array if missing
	 * 
	 * @param array $merchant Merchant data
	 * @return array Enhanced merchant data
	 */
	private function enhance_merchant_data($merchant) {
		// If merchant already has products array with finalprice/salediscount, ensure merchant-level fields exist
		if (!empty($merchant['products']) && is_array($merchant['products'])) {
			$first_product = $merchant['products'][0];
			
					// Add merchant-level finalprice and salediscount if missing
					if (!isset($merchant['finalprice']) && isset($first_product['finalprice'])) {
						$merchant['finalprice'] = $first_product['finalprice'];
					}
					if (!isset($merchant['salediscount']) && isset($first_product['salediscount'])) {
						$merchant['salediscount'] = floatval($first_product['salediscount']);
					}
					if (!isset($merchant['currency'])) {
						// Try product currency first, then detect from merchant name, then default to DKK
						$merchant_name_for_detection = $merchant['name'] ?? $merchant['merchant_name'] ?? '';
						$merchant['currency'] = $first_product['currency'] ?? $this->detect_currency_from_merchant_name($merchant_name_for_detection) ?? 'DKK';
					}
					// Extract affiliate_url from products array if available (for existing data compatibility)
					if (!isset($merchant['affiliate_url']) && isset($first_product['affiliate_url'])) {
						$merchant['affiliate_url'] = $first_product['affiliate_url'];
					}
		} else {
			// If products array is missing, create a minimal one from merchant-level data
			// This ensures the price extraction code can find the data
			if (empty($merchant['products']) || !is_array($merchant['products'])) {
				$merchant['products'] = [];
				
				// Detect currency from merchant name if not set
				$detected_currency = null;
				$merchant_name_for_detection = $merchant['name'] ?? $merchant['merchant_name'] ?? '';
				if (empty($merchant['currency'])) {
					$detected_currency = $this->detect_currency_from_merchant_name($merchant_name_for_detection);
				}
				
				// Create a product entry from merchant-level data
				$product_data = [
					'id' => '',
					'name' => '',
					'price' => $merchant['price'] ?? $merchant['lowest_price'] ?? 0,
					'finalprice' => $merchant['finalprice'] ?? $merchant['price'] ?? $merchant['lowest_price'] ?? 0,
					'salediscount' => floatval($merchant['salediscount'] ?? 0),
					'currency' => $merchant['currency'] ?? $detected_currency ?? 'DKK',
					'url' => $merchant['url'] ?? '',
					'affiliate_url' => $merchant['affiliate_url'] ?? '', // Include affiliate_url if available
					'availability' => $merchant['availability'] ?? 'unknown',
					'rating' => $merchant['rating'] ?? $merchant['average_rating'] ?? 0,
					'network' => $merchant['network'] ?? $merchant['network_name'] ?? '',
				];
				
				$merchant['products'][] = $product_data;
				
				// Ensure merchant-level fields exist
				if (!isset($merchant['finalprice'])) {
					$merchant['finalprice'] = $product_data['finalprice'];
				}
				if (!isset($merchant['salediscount'])) {
					$merchant['salediscount'] = $product_data['salediscount'];
				}
			}
		}
		
		return $merchant;
	}
	
	/**
	 * Filter merchants by configured networks
	 * Only returns merchants that belong to networks with configured affiliate IDs
	 * 
	 * @param array $merchants Array of merchant data
	 * @return array Filtered merchants array
	 */
	private function filter_merchants_by_configured_networks($merchants) {
		if (empty($merchants)) {
			return [];
		}
		
		// Get all configured networks
		$networks_manager = new \AEBG\Admin\Networks_Manager();
		$all_affiliate_ids = $networks_manager->get_all_affiliate_ids();
		$configured_network_keys = array_keys(array_filter($all_affiliate_ids, function($id) {
			return !empty($id);
		}));
		
		if (empty($configured_network_keys)) {
			error_log('[AEBG] No configured networks found, returning all merchants');
			return $merchants;
		}
		
		// Get Datafeedr instance to map networks to sources
		$datafeedr = new \AEBG\Core\Datafeedr();
		
		// Map configured network keys to Datafeedr source names
		$configured_sources = [];
		foreach ($configured_network_keys as $network_key) {
			$mapped_source = $datafeedr->mapNetworkToSource($network_key);
			if ($mapped_source) {
				$display_name = $datafeedr->mapSourceToDisplayName($mapped_source);
				if ($display_name) {
					$configured_sources[] = strtolower($display_name);
				}
				// Also add the mapped source itself
				$configured_sources[] = strtolower($mapped_source);
			}
			// Also add the network key itself for matching
			$configured_sources[] = strtolower($network_key);
		}
		
		if (empty($configured_sources)) {
			error_log('[AEBG] No configured sources found after mapping, returning all merchants');
			return $merchants;
		}
		
		// error_log('[AEBG] Filtering merchants by configured sources: ' . json_encode($configured_sources));
		
		// Filter merchants by network/source
		$filtered_merchants = [];
		foreach ($merchants as $merchant) {
			$merchant_network = strtolower(trim($merchant['network'] ?? $merchant['network_name'] ?? ''));
			$merchant_source = strtolower(trim($merchant['source'] ?? ''));
			
			// Check if merchant's network or source matches any configured source
			// Use exact match or word-boundary matching to avoid false positives
			$is_configured = false;
			foreach ($configured_sources as $configured_source) {
				$configured_source = strtolower(trim($configured_source));
				if (empty($configured_source)) {
					continue;
				}
				
				// Check merchant network
				if (!empty($merchant_network)) {
					// Exact match
					if ($merchant_network === $configured_source) {
						$is_configured = true;
						break;
					}
					
					// Word-boundary matching to prevent false positives (e.g., "partner" won't match "partnerize")
					if (preg_match('/\b' . preg_quote($configured_source, '/') . '\b/i', $merchant_network)) {
						$is_configured = true;
						break;
					}
					
					// Reverse: configured contains merchant network as whole word
					if (preg_match('/\b' . preg_quote($merchant_network, '/') . '\b/i', $configured_source)) {
						$is_configured = true;
						break;
					}
				}
				
				// Check merchant source
				if (!empty($merchant_source)) {
					// Exact match
					if ($merchant_source === $configured_source) {
						$is_configured = true;
						break;
					}
					
					// Word-boundary matching
					if (preg_match('/\b' . preg_quote($configured_source, '/') . '\b/i', $merchant_source)) {
						$is_configured = true;
						break;
					}
					
					// Reverse: configured contains merchant source
					if (preg_match('/\b' . preg_quote($merchant_source, '/') . '\b/i', $configured_source)) {
						$is_configured = true;
						break;
					}
				}
			}
			
			if ($is_configured) {
				$filtered_merchants[] = $merchant;
			} else {
				// error_log('[AEBG] Filtered out merchant: ' . ($merchant['merchant_name'] ?? $merchant['name'] ?? 'unknown') . ' (network: ' . $merchant_network . ', source: ' . $merchant_source . ')');
			}
		}
		
		// error_log('[AEBG] Merchant filtering result: ' . count($merchants) . ' before, ' . count($filtered_merchants) . ' after');
		
		return $filtered_merchants;
	}
	
	/**
	 * Validate merchant data structure for security
	 */
	private function validate_merchant_data($merchant) {
		if (!is_array($merchant)) {
			return false;
		}
		
		// Required fields
		if (!isset($merchant['name']) || empty($merchant['name'])) {
			return false;
		}
		
		// Sanitize string fields
		$string_fields = ['name', 'description', 'network', 'country'];
		foreach ($string_fields as $field) {
			if (isset($merchant[$field])) {
				$merchant[$field] = sanitize_text_field($merchant[$field]);
			}
		}
		
		// Validate URL fields
		$url_fields = ['url', 'direct_url', 'product_url', 'merchant_url', 'affiliate_url'];
		foreach ($url_fields as $field) {
			if (isset($merchant[$field]) && !empty($merchant[$field])) {
				$url = esc_url_raw($merchant[$field]);
				if (!filter_var($url, FILTER_VALIDATE_URL)) {
					$merchant[$field] = '';
				} else {
					$merchant[$field] = $url;
				}
			}
		}
		
		// Validate numeric fields
		$numeric_fields = ['price', 'rating', 'review_count', 'stock'];
		foreach ($numeric_fields as $field) {
			if (isset($merchant[$field])) {
				$merchant[$field] = is_numeric($merchant[$field]) ? floatval($merchant[$field]) : 0;
			}
		}
		
		// Validate availability field
		if (isset($merchant['availability'])) {
			$merchant['availability'] = sanitize_text_field($merchant['availability']);
		}
		
		return $merchant;
	}
	
	/**
	 * Generate loading state HTML for comparison tables
	 */
	private function get_loading_state_html($product_name = '') {
		$html = '<div class="aebg-loading-comparison" data-loading="true">';
		$html .= '<div class="aebg-loading-spinner"></div>';
		$html .= '<div class="aebg-loading-content">';
		$html .= '<h3 class="aebg-loading-title">Henter pris sammenligning</h3>';
		if (!empty($product_name)) {
			$html .= '<p class="aebg-loading-subtitle">for ' . esc_html($product_name) . '</p>';
		}
		$html .= '<p class="aebg-loading-description">Dette kan tage et øjeblik...</p>';
		$html .= '</div>';
		$html .= '</div>';
		
		return $html;
	}
	
	/**
	 * Generate async loading comparison HTML with JavaScript support
	 */
	private function generate_async_comparison_html($product, $atts) {
		$product_name = $product['name'] ?? 'produktet';
		$product_id = $product['id'] ?? '';
		$post_id = get_the_ID();
		
		$html = '<div class="aebg-async-comparison" data-product-id="' . esc_attr($product_id) . '" data-post-id="' . esc_attr($post_id) . '">';
		$html .= $this->get_loading_state_html($product_name);
		$html .= '<script type="text/javascript">';
		$html .= 'document.addEventListener("DOMContentLoaded", function() {';
		$html .= '  var container = document.querySelector(\'[data-product-id="' . esc_js($product_id) . '"]\');';
		$html .= '  if (container) {';
		$html .= '    setTimeout(function() {';
		$html .= '      container.innerHTML = \'<div class="aebg-loading-error">Kunne ikke indlæse sammenligning automatisk. <button onclick="location.reload()" class="aebg-retry-button">Prøv igen</button></div>\';';
		$html .= '    }, 10000);'; // 10 second timeout
		$html .= '  }';
		$html .= '});';
		$html .= '</script>';
		$html .= '</div>';
		
		return $html;
	}

	/**
	 * Validate and sanitize shortcode attributes for security
	 */
	private function validate_shortcode_attributes($atts) {
		if (!is_array($atts)) {
			return [];
		}
		
		$validated = [];
		
		// Validate product attribute
		if (isset($atts['product'])) {
			$product = $atts['product'];
			if (is_numeric($product) || is_string($product)) {
				$validated['product'] = sanitize_text_field($product);
			} else {
				$validated['product'] = 1; // Default fallback
			}
		}
		
		// Validate style attribute
		if (isset($atts['style'])) {
			$style = strtolower(sanitize_text_field($atts['style']));
			$allowed_styles = ['table', 'cards', 'list'];
			$validated['style'] = in_array($style, $allowed_styles) ? $style : 'table';
		}
		
		// Validate boolean attributes
		$boolean_attrs = ['show_image', 'show_rating', 'show_availability'];
		foreach ($boolean_attrs as $attr) {
			if (isset($atts[$attr])) {
				$value = strtolower(sanitize_text_field($atts[$attr]));
				$validated[$attr] = in_array($value, ['true', '1', 'yes', 'on']) ? 'true' : 'false';
			}
		}
		
		// Validate limit attribute
		if (isset($atts['limit'])) {
			$limit = intval($atts['limit']);
			$validated['limit'] = ($limit > 0 && $limit <= 100) ? $limit : 10; // Max 100 merchants
		}
		
		// Validate class attribute
		if (isset($atts['class'])) {
			$class = sanitize_text_field($atts['class']);
			// Only allow safe CSS class names
			$class = preg_replace('/[^a-zA-Z0-9\-\_\s]/', '', $class);
			$validated['class'] = trim($class);
		}
		
		return $validated;
	}
	
	/**
	 * Validate product data structure for security
	 */
	private function validate_product_data($product) {
		if (!is_array($product)) {
			return false;
		}
		
		// Required fields
		$required_fields = ['id', 'name'];
		foreach ($required_fields as $field) {
			if (!isset($product[$field]) || empty($product[$field])) {
				return false;
			}
		}
		
		// Sanitize string fields
		$string_fields = ['id', 'name', 'brand', 'description', 'url'];
		foreach ($string_fields as $field) {
			if (isset($product[$field])) {
				$product[$field] = sanitize_text_field($product[$field]);
			}
		}
		
		// Validate URL fields
		$url_fields = ['url', 'direct_url', 'product_url', 'merchant_url', 'affiliate_url'];
		foreach ($url_fields as $field) {
			if (isset($product[$field]) && !empty($product[$field])) {
				$url = esc_url_raw($product[$field]);
				if (!filter_var($url, FILTER_VALIDATE_URL)) {
					$product[$field] = '';
				} else {
					$product[$field] = $url;
				}
			}
		}
		
		// Validate numeric fields
		$numeric_fields = ['price', 'rating', 'review_count'];
		foreach ($numeric_fields as $field) {
			if (isset($product[$field])) {
				$product[$field] = is_numeric($product[$field]) ? floatval($product[$field]) : 0;
			}
		}
		
		return $product;
	}
	
	/**
	 * Get product from shortcode attributes with comprehensive fallback logic
	 */
	private function get_product_from_shortcode($post_id, $product_identifier) {
		// CRITICAL: Prevent execution during content generation
		// This method does database queries that can cause hangs
		// Use BOTH checks: global variable (more reliable) and ActionHandler flag
		// Also check for API requests in progress (wp_remote_post can trigger WordPress hooks)
		// CRITICAL: Allow execution when Elementor is in edit mode - Elementor needs to detect
		// the_content() in templates (especially header/footer) to allow editing
		$is_elementor_editor = $this->is_elementor_editor_context();
		
		if (!$is_elementor_editor &&
		    ((isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']) ||
		     (class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing()) ||
		     (isset($GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']) && $GLOBALS['AEBG_API_REQUEST_IN_PROGRESS']))) {
			error_log('[AEBG] get_product_from_shortcode - BLOCKED: Skipping during generation/API request');
			return null;
		}
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// error_log('[AEBG] get_product_from_shortcode - Post ID: ' . $post_id . ', Product identifier: ' . $product_identifier);
		}
		
		$product = null;
		
		// Check if it's a variable placeholder like {product_id}
		if (preg_match('/\{([^}]+)\}/', $product_identifier, $matches)) {
			$variable_name = $matches[1];
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// error_log('[AEBG] get_product_from_shortcode - Variable name extracted: ' . $variable_name);
			}
			
			// Get products from post meta
			$products = get_post_meta($post_id, '_aebg_products', true);
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// error_log('[AEBG] get_product_from_shortcode - _aebg_products found: ' . (!empty($products) ? 'yes' : 'no'));
				if (!empty($products)) {
					// error_log('[AEBG] get_product_from_shortcode - _aebg_products count: ' . count($products));
				}
			}
			
			// Handle product-X format (e.g., product-1, product-2, etc.)
			if (preg_match('/^product-(\d+)$/', $variable_name, $matches)) {
				$product_index = (int) $matches[1] - 1; // Convert to 0-based index
				$product = $products[$product_index] ?? null;
				
				if (!$product) {
					// Fallback: Try to get product from _aebg_product_ids
					$product_ids = get_post_meta($post_id, '_aebg_product_ids', true);
					if (!empty($product_ids) && is_array($product_ids)) {
						$product_id = $product_ids[$product_index] ?? null;
						if ($product_id) {
							$product = $this->get_product_by_id($product_id);
						}
					}
				}
			} else {
				// For other variable formats, get the first product
				$product = $products[0] ?? null;
				if (!$product) {
					// Fallback: Try to get first product from _aebg_product_ids
					$product_ids = get_post_meta($post_id, '_aebg_product_ids', true);
					if (!empty($product_ids) && is_array($product_ids)) {
						$product_id = $product_ids[0] ?? null;
						if ($product_id) {
							$product = $this->get_product_by_id($product_id);
						}
					}
				}
			}
		} else {
			// Not a variable placeholder, try to find by index first (1, 2, 3, etc.)
			$products = get_post_meta($post_id, '_aebg_products', true);
			if (!empty($products) && is_array($products)) {
				$product_index = (int) $product_identifier - 1;
				$product = $products[$product_index] ?? null;
			}
			
			// If not found by index, try by ID
			if (!$product) {
				$product = $this->get_product_by_id($product_identifier);
			}
		}
		
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// error_log('[AEBG] get_product_from_shortcode - Final product found: ' . ($product ? 'yes' : 'no'));
			if ($product) {
				// error_log('[AEBG] get_product_from_shortcode - Product ID: ' . ($product['id'] ?? 'unknown'));
			}
		}
		
		return $product;
	}

	/**
	 * Get standardized error messages for consistent user experience
	 */
	private function get_standardized_error_message($error_type, $context = []) {
		$messages = [
			'no_comparison_available' => [
				'title' => 'Ingen pris sammenligning tilgængelig',
				'message' => 'Vi kunne ikke hente pris sammenligning for dette produkt.',
				'suggestion' => 'Prøv at opdatere siden eller kontakt support hvis problemet fortsætter.'
			],
			'no_merchants_found' => [
				'title' => 'Ingen forhandlere fundet',
				'message' => 'Der blev ikke fundet nogen forhandlere for dette produkt.',
				'suggestion' => 'Prøv at opdatere siden eller kontakt support hvis problemet fortsætter.'
			],
			'merchant_comparison_processing' => [
				'title' => 'Pris sammenligning behandles',
				'message' => 'Pris sammenligning for dette produkt behandles i øjeblikket.',
				'suggestion' => 'Opdater siden om et øjeblik for at se pris sammenligningen.'
			],
			'product_not_found' => [
				'title' => 'Produkt ikke fundet',
				'message' => 'Det angivne produkt kunne ikke lokaliseres.',
				'suggestion' => 'Kontroller produkt-ID eller kontakt support.'
			],
			'no_post_id' => [
				'title' => 'Intet indlægs-ID fundet',
				'message' => 'Kunne ikke bestemme hvilket indlæg der skal vises sammenligning for.',
				'suggestion' => 'Kontroller at shortcoden bruges på en gyldig side eller indlæg.'
			],
			'invalid_product_data' => [
				'title' => 'Ugyldige produktdata',
				'message' => 'Produktdataene kunne ikke valideres eller indeholder ugyldige oplysninger.',
				'suggestion' => 'Kontroller produktoplysningerne eller kontakt support.'
			]
		];
		
		$error = $messages[$error_type] ?? $messages['no_comparison_available'];
		
		$html = '<div class="aebg-error-message">';
		$html .= '<div class="aebg-error-icon">⚠️</div>';
		$html .= '<h3 class="aebg-error-title">' . esc_html($error['title']) . '</h3>';
		$html .= '<p class="aebg-error-text">' . esc_html($error['message']) . '</p>';
		
		// Add context information only in development
		if (defined('WP_DEBUG') && WP_DEBUG && !empty($context)) {
			$html .= '<div class="aebg-error-context">';
			$html .= '<p><strong>Debug Info:</strong></p>';
			foreach ($context as $key => $value) {
				$html .= '<p><small>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ': ' . esc_html($value) . '</small></p>';
			}
			$html .= '</div>';
		}
		
		$html .= '<p class="aebg-error-suggestion">' . esc_html($error['suggestion']) . '</p>';
		$html .= '</div>';
		
		return $html;
	}

	/**
	 * Convert comparison data from saved format to expected format
	 * The saved format has 'products' array, but the shortcode expects 'merchants' array
	 * This function handles both old and new data structures comprehensively
	 */
	private function convert_comparison_format($saved_data) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[AEBG] convert_comparison_format - Converting saved data: ' . print_r($saved_data, true));
		}
		
		// Handle case where data is already in merchants format
		if (isset($saved_data['merchants']) && is_array($saved_data['merchants'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[AEBG] convert_comparison_format - Data already in merchants format, returning as-is');
			}
			return $saved_data;
		}
		
		// Handle case where data has products array (old format)
		if (isset($saved_data['products']) && is_array($saved_data['products'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[AEBG] convert_comparison_format - Converting products array to merchants format');
			}
			
			$merchants = [];
			$prices = [];
			
			foreach ($saved_data['products'] as $product) {
				$merchant_name = $product['merchant'] ?? $product['merchant_name'] ?? $product['store'] ?? 'Unknown Merchant';
				$merchant_key = sanitize_title($merchant_name);
				
				// Extract price and handle different price field names
				$price = 0;
				if (isset($product['price'])) {
					$price = is_numeric($product['price']) ? floatval($product['price']) : 0;
				} elseif (isset($product['final_price'])) {
					$price = is_numeric($product['final_price']) ? floatval($product['final_price']) : 0;
				} elseif (isset($product['lowest_price'])) {
					$price = is_numeric($product['lowest_price']) ? floatval($product['lowest_price']) : 0;
				}
				
				// Skip if no valid price
				if ($price <= 0) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('[AEBG] convert_comparison_format - Skipping product with invalid price: ' . ($product['name'] ?? 'Unknown'));
					}
					continue;
				}
				
				// Extract URL from multiple possible fields
				$url = '';
				$url_fields = ['url', 'direct_url', 'product_url', 'merchant_url', 'affiliate_url', 'link'];
				foreach ($url_fields as $field) {
					if (!empty($product[$field])) {
						$url = $product[$field];
						break;
					}
				}
				
				// Extract availability with enhanced detection
				$availability = 'unknown';
				if (isset($product['availability'])) {
					$availability = $product['availability'];
				} elseif (isset($product['in_stock'])) {
					$availability = $product['in_stock'] ? 'in_stock' : 'out_of_stock';
				} elseif (isset($product['stock_status'])) {
					$availability = $product['stock_status'];
				} elseif (isset($product['stock'])) {
					$availability = $product['stock'];
				} elseif (isset($product['status'])) {
					$availability = $product['status'];
				}
				
				// Enhanced availability normalization
				$availability = $this->normalize_availability($availability);
				
				// Extract rating
				$rating = 0;
				if (isset($product['rating'])) {
					$rating = is_numeric($product['rating']) ? floatval($product['rating']) : 0;
				} elseif (isset($product['average_rating'])) {
					$rating = is_numeric($product['average_rating']) ? floatval($product['average_rating']) : 0;
				}
				
				// Extract network
				$network = $product['network'] ?? $product['network_name'] ?? 'Unknown';
				
				// Extract currency - detect from merchant name if not in product data
				$currency = $product['currency'] ?? null;
				if (empty($currency)) {
					$currency = $this->detect_currency_from_merchant_name($merchant_name);
				}
				$currency = $currency ?: 'DKK'; // Default to DKK for Danish shops
				
				// Extract finalprice and salediscount if available
				$finalprice = null;
				$salediscount = 0;
				if (isset($product['finalprice'])) {
					$finalprice = is_numeric($product['finalprice']) ? floatval($product['finalprice']) : null;
				} elseif (isset($product['final_price'])) {
					$finalprice = is_numeric($product['final_price']) ? floatval($product['final_price']) : null;
				} elseif (isset($product['sale_price'])) {
					$finalprice = is_numeric($product['sale_price']) ? floatval($product['sale_price']) : null;
				}
				
				if (isset($product['salediscount'])) {
					$salediscount = is_numeric($product['salediscount']) ? floatval($product['salediscount']) : 0;
				} elseif (isset($product['discount'])) {
					$salediscount = is_numeric($product['discount']) ? floatval($product['discount']) : 0;
				}
				
				// Use finalprice as selling price if available, otherwise use price
				$selling_price = $finalprice !== null && $finalprice > 0 ? $finalprice : $price;
				
				// Create merchant entry if it doesn't exist (use selling_price for initial lowest/highest)
				if (!isset($merchants[$merchant_key])) {
					$merchants[$merchant_key] = [
						'name' => $merchant_name,
						'network' => $network,
						'currency' => $currency,
						'prices' => [],
						'products' => [],
						'lowest_price' => $selling_price,
						'highest_price' => $selling_price,
						'average_price' => $selling_price,
						'average_rating' => $rating,
						'product_count' => 0,
						'is_original' => false,
						'availability' => $availability
					];
				}
				
				// Add product to merchant
				$merchants[$merchant_key]['prices'][] = $selling_price;
				$merchants[$merchant_key]['products'][] = [
					'id' => $product['id'] ?? $product['_id'] ?? '',
					'name' => $product['name'] ?? '',
					'price' => $price, // Retail/original price
					'finalprice' => $finalprice !== null ? $finalprice : $price, // Sale/current price
					'salediscount' => $salediscount,
					'currency' => $currency,
					'url' => $url,
					'image_url' => $product['image'] ?? $product['image_url'] ?? '',
					'availability' => $availability,
					'rating' => $rating,
					'reviews_count' => $product['reviews_count'] ?? 0,
					'network' => $network,
					'is_original' => false
				];
				
				// Update merchant stats (use selling_price for lowest/highest, not original price)
				$merchants[$merchant_key]['lowest_price'] = min($merchants[$merchant_key]['lowest_price'], $selling_price);
				$merchants[$merchant_key]['highest_price'] = max($merchants[$merchant_key]['highest_price'], $selling_price);
				$merchants[$merchant_key]['product_count']++;
				
				$prices[] = $selling_price;
			}
			
			// Calculate averages and finalize merchant data
			foreach ($merchants as $key => &$merchant) {
				if (!empty($merchant['prices'])) {
					$merchant['average_price'] = array_sum($merchant['prices']) / count($merchant['prices']);
					$merchant['prices'] = array_unique($merchant['prices']);
					sort($merchant['prices']);
				}
				
				// Calculate average rating
				if (!empty($merchant['products'])) {
					$ratings = array_column($merchant['products'], 'rating');
					$ratings = array_filter($ratings, function($rating) { return $rating > 0; });
					$merchant['average_rating'] = !empty($ratings) ? array_sum($ratings) / count($ratings) : 0;
					
					// Extract finalprice and salediscount from first product for merchant-level access
					$first_product = $merchant['products'][0];
					$merchant['finalprice'] = $first_product['finalprice'] ?? null;
					$merchant['salediscount'] = floatval($first_product['salediscount'] ?? 0);
					$merchant['currency'] = $first_product['currency'] ?? $merchant['currency'] ?? 'USD';
				}
			}
			
			// Sort merchants by lowest price
			uasort($merchants, function($a, $b) {
				return $a['lowest_price'] <=> $b['lowest_price'];
			});
			
			$converted_data = [
				'merchants' => $merchants,
				'merchant_count' => count($merchants),
				'price_range' => [
					'lowest' => !empty($prices) ? min($prices) : 0,
					'highest' => !empty($prices) ? max($prices) : 0
				],
				'original_product' => $saved_data['original_product'] ?? [],
				'total_products_found' => count($saved_data['products']),
				'timestamp' => $saved_data['timestamp'] ?? current_time('mysql'),
				'converted_from_old_format' => true
			];
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('[AEBG] convert_comparison_format - Successfully converted to merchants format with ' . count($merchants) . ' merchants');
			}
			return $converted_data;
		}
		
		// Handle case where data structure is completely different
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[AEBG] convert_comparison_format - Unknown data structure, creating fallback format');
		}
		
		// Create a minimal fallback structure
		return [
			'merchants' => [],
			'merchant_count' => 0,
			'price_range' => ['lowest' => 0, 'highest' => 0],
			'original_product' => $saved_data['original_product'] ?? [],
			'total_products_found' => 0,
			'timestamp' => current_time('mysql'),
			'converted_from_old_format' => true,
			'fallback_format' => true
		];
	}

	/**
	 * Generate comparison HTML
	 */
	private function generate_comparison_html($product, $merchants, $atts) {
		// CRITICAL: Suppress WordPress deprecation warnings during HTML generation
		// The footer.php deprecation warning can break execution during HTML generation
		$old_error_reporting = error_reporting();
		error_reporting($old_error_reporting & ~E_DEPRECATED);
		
		try {
			$show_image = filter_var($atts['show_image'], FILTER_VALIDATE_BOOLEAN);
			$show_rating = filter_var($atts['show_rating'], FILTER_VALIDATE_BOOLEAN);
			$show_availability = filter_var($atts['show_availability'], FILTER_VALIDATE_BOOLEAN);
			$style = $atts['style'];
			$class = $atts['class'];

			// CRITICAL: Only enqueue styles/scripts on frontend, not during generation/background processing
			// wp_enqueue_* can trigger WordPress hooks that cause rendering and shortcode execution
			// During generation, we don't need these - they'll be enqueued when the post is viewed on frontend
			if (!is_admin() && !wp_doing_ajax() && !defined('WP_CLI') && 
			    !(isset($GLOBALS['AEBG_GENERATION_IN_PROGRESS']) && $GLOBALS['AEBG_GENERATION_IN_PROGRESS']) &&
			    !(class_exists('\AEBG\Core\ActionHandler') && \AEBG\Core\ActionHandler::is_executing())) {
				wp_enqueue_style('aebg-frontend-comparison', plugin_dir_url(dirname(__DIR__)) . 'assets/css/frontend-comparison.css', [], '4.0.0');
				wp_enqueue_script('aebg-frontend-comparison', plugin_dir_url(dirname(__DIR__)) . 'assets/js/frontend-comparison.js', [], '1.0.0', true);
			}

			$html = '<div class="aebg-price-comparison ' . esc_attr($class) . '">';

			if ($style === 'table') {
				$html .= $this->generate_table_comparison($product, $merchants, $show_image, $show_rating, $show_availability);
			} elseif ($style === 'cards') {
				$html .= $this->generate_cards_comparison($product, $merchants, $show_image, $show_rating, $show_availability);
			} else {
				$html .= $this->generate_list_comparison($product, $merchants, $show_image, $show_rating, $show_availability);
			}

			$html .= '</div>';
			error_reporting($old_error_reporting);
			return $html;
		} catch (\Throwable $e) {
			error_log('[AEBG] ERROR in generate_comparison_html: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
			error_reporting($old_error_reporting);
			// Return safe fallback
			return '<div class="aebg-price-comparison"><p>Error generating comparison table. Please try again later.</p></div>';
		}
	}

	/**
	 * Generate table comparison (card-based layout matching Image 1 design)
	 */
	private function generate_table_comparison($product, $merchants, $show_image, $show_rating, $show_availability) {
		$html = '<div class="aebg-comparison-cards-container">';

		// Get currency from product (primary source) or first merchant
		$currency = $product['currency'] ?? null;
		if (empty($currency) && !empty($merchants)) {
			// Try to get currency from first merchant's products
			$first_merchant = $merchants[0];
			if (isset($first_merchant['products']) && is_array($first_merchant['products']) && !empty($first_merchant['products'])) {
				$currency = $first_merchant['products'][0]['currency'] ?? null;
			}
			// Fallback to merchant currency field
			if (empty($currency)) {
				$currency = $first_merchant['currency'] ?? null;
			}
		}
		
		// If still no currency, detect from merchant names/domains (Danish shops should use DKK)
		if (empty($currency) && !empty($merchants)) {
			$currency = $this->detect_currency_from_merchants($merchants);
		}
		
		$currency = $currency ?: 'DKK'; // Default to DKK for Danish shops (was USD)
		
		// Get product discount info as fallback (in case merchant data doesn't have it)
		$product_original_price = $product['price'] ?? null;
		$product_final_price = $product['finalprice'] ?? $product['price'] ?? null;
		$product_discount = floatval($product['salediscount'] ?? 0);
		
		foreach ($merchants as $merchant) {
			// Handle both old and new merchant data formats - check multiple possible fields
			$merchant_name = $merchant['merchant_name'] ?? $merchant['name'] ?? $merchant['merchant'] ?? $merchant['store'] ?? 'Unknown Merchant';
			
			// Get currency for this specific merchant (prefer merchant-specific currency)
			$merchant_currency = $merchant['currency'] ?? null;
			if (empty($merchant_currency) && isset($merchant['products']) && is_array($merchant['products']) && !empty($merchant['products'])) {
				$merchant_currency = $merchant['products'][0]['currency'] ?? null;
			}
			
			// If still no currency, detect from merchant name/domain
			if (empty($merchant_currency)) {
				$merchant_currency = $this->detect_currency_from_merchant_name($merchant_name);
			}
			
			$merchant_currency = $merchant_currency ?: $currency; // Fallback to product currency
			
			// Get price and discount information from merchant data
			// Check products array first for most accurate data
			$merchant_retail_price = null;  // Retail price (original/regular price)
			$merchant_sales_price = null;   // Sales price (finalprice, discounted price)
			$merchant_discount = 0;
			
			if (isset($merchant['products']) && is_array($merchant['products']) && !empty($merchant['products'])) {
				// Get discount info from first product (they should all be the same for same merchant)
				$product_data = $merchant['products'][0];
				// Retail price comes from 'price' field (original/regular price)
				$merchant_retail_price = $product_data['price'] ?? null;
				// Sales price comes from 'finalprice' field (discounted price)
				$merchant_sales_price = $product_data['finalprice'] ?? null;
				$merchant_discount = floatval($product_data['salediscount'] ?? 0);
				
				// Debug: Log raw prices before normalization (only in verbose debug mode to reduce log noise)
				if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
					error_log('[AEBG] Raw prices for ' . $merchant_name . ' BEFORE normalization: retail=' . ($merchant_retail_price ?? 'null') . ', sales=' . ($merchant_sales_price ?? 'null') . ' (currency: ' . $merchant_currency . ')');
				}
				
				// Normalize prices before comparison
				if ($merchant_retail_price !== null) {
					$merchant_retail_price = $this->normalize_price_value($merchant_retail_price, $merchant_currency);
				}
				if ($merchant_sales_price !== null) {
					$merchant_sales_price = $this->normalize_price_value($merchant_sales_price, $merchant_currency);
				}
				
				// If finalprice is not set or equals price, there's no discount - sales price equals retail price
				if ($merchant_sales_price === null && $merchant_retail_price !== null) {
					$merchant_sales_price = $merchant_retail_price;
				} elseif ($merchant_retail_price !== null && $merchant_sales_price !== null) {
					// If both prices exist but are equal (within tolerance), treat as no discount
					// But keep both values so we can still display them if needed
					if (abs($merchant_retail_price - $merchant_sales_price) < 0.01) {
						// Prices are essentially equal - no discount
						// Keep both values the same
					}
				}
				
				// Debug logging (only in verbose debug mode to reduce log noise)
				if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
					error_log('[AEBG] Price check for ' . $merchant_name . ': retail=' . ($merchant_retail_price ?? 'null') . ', sales=' . ($merchant_sales_price ?? 'null') . ', discount=' . $merchant_discount);
				}
			}
			
			// Fallback to merchant-level data if product data not available
			if ($merchant_retail_price === null || $merchant_sales_price === null) {
				// Check merchant-level price fields (not main product prices - each merchant should have unique prices)
				// First, try to get retail price from products array if we have it but didn't extract it earlier
				if ($merchant_retail_price === null && !empty($merchant['products']) && is_array($merchant['products'])) {
					$product_data = $merchant['products'][0];
					$merchant_retail_price = $product_data['price'] ?? null;
					if ($merchant_retail_price !== null) {
						$merchant_retail_price = $this->normalize_price_value($merchant_retail_price, $merchant_currency);
					}
				}
				
				// Check for retail price (original/regular price) at merchant level
				if ($merchant_retail_price === null) {
					$merchant_retail_price = $merchant['price'] ?? $merchant['original_price'] ?? $merchant['retail_price'] ?? null;
					if ($merchant_retail_price !== null) {
						$merchant_retail_price = $this->normalize_price_value($merchant_retail_price, $merchant_currency);
					}
				}
				
				// First, try to get sales price from products array if we have it but didn't extract it earlier
				if ($merchant_sales_price === null && !empty($merchant['products']) && is_array($merchant['products'])) {
					$product_data = $merchant['products'][0];
					$merchant_sales_price = $product_data['finalprice'] ?? null;
					if ($merchant_sales_price !== null) {
						$merchant_sales_price = $this->normalize_price_value($merchant_sales_price, $merchant_currency);
					}
				}
				
				// Check for sales price (finalprice/discounted price) at merchant level (now included in Datafeedr output)
				if ($merchant_sales_price === null) {
					$merchant_sales_price = $merchant['finalprice'] ?? $merchant['sale_price'] ?? $merchant['lowest_price'] ?? null;
					if ($merchant_sales_price !== null) {
						$merchant_sales_price = $this->normalize_price_value($merchant_sales_price, $merchant_currency);
					}
				}
				
				// If still no prices found, use lowest_price as fallback (but this is merchant-specific, not main product)
				if ($merchant_retail_price === null && $merchant_sales_price === null) {
					$raw_price = $merchant['lowest_price'] ?? $merchant['price'] ?? 0;
					$fallback_price = $this->normalize_price_value($raw_price, $merchant_currency);
					if ($fallback_price > 0) {
						$merchant_retail_price = $fallback_price;
						$merchant_sales_price = $fallback_price;
					}
				} elseif ($merchant_retail_price === null) {
					// If we have sales price but no retail price, use sales price as retail (no discount)
					$merchant_retail_price = $merchant_sales_price;
				} elseif ($merchant_sales_price === null) {
					// If we have retail price but no sales price, use retail price as sales (no discount)
					$merchant_sales_price = $merchant_retail_price;
				}
				
				// Check for discount at merchant level (now included in Datafeedr output)
				if ($merchant_discount == 0) {
					$merchant_discount = floatval($merchant['salediscount'] ?? $merchant['discount'] ?? 0);
				}
			}
			
			// Ensure we have valid prices
			if ($merchant_retail_price === null || $merchant_retail_price <= 0) {
				$merchant_retail_price = $merchant_sales_price ?? 0;
			}
			if ($merchant_sales_price === null || $merchant_sales_price <= 0) {
				$merchant_sales_price = $merchant_retail_price ?? 0;
			}
			
			// Determine if there's a discount
			// Discount exists if: sales price < retail price (with tolerance for floating point)
			$has_discount = ($merchant_retail_price > $merchant_sales_price + 0.01) && $merchant_retail_price > 0 && $merchant_sales_price > 0;
			
			// If we have a discount percentage but prices are equal, calculate retail from discount
			if (!$has_discount && $merchant_discount > 0 && $merchant_sales_price > 0) {
				// Calculate retail price from discount percentage: retail = sales / (1 - discount/100)
				$calculated_retail = $merchant_sales_price / (1 - ($merchant_discount / 100));
				if ($calculated_retail > $merchant_sales_price + 0.01) {
					$merchant_retail_price = $calculated_retail;
					$has_discount = true;
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('[AEBG] Calculated retail price from discount: retail=' . $merchant_retail_price . ', sales=' . $merchant_sales_price . ', discount=' . $merchant_discount . '%');
					}
				}
			}
			
			// Calculate savings amount
			$savings_amount = $has_discount ? ($merchant_retail_price - $merchant_sales_price) : 0;
			
			// Display price: use sales price if discount, otherwise retail price
			$merchant_price = $has_discount ? $merchant_sales_price : $merchant_retail_price;
			
			// Calculate discount percentage if not provided but we have a discount
			if ($has_discount && $merchant_discount == 0 && $merchant_retail_price > 0) {
				$merchant_discount = (($merchant_retail_price - $merchant_sales_price) / $merchant_retail_price) * 100;
			}
			
			// Debug final discount status (only in verbose debug mode to reduce log noise)
			if (defined('WP_DEBUG') && WP_DEBUG && defined('AEBG_VERBOSE_DEBUG') && AEBG_VERBOSE_DEBUG) {
				error_log('[AEBG] Final price status for ' . $merchant_name . ': has_discount=' . ($has_discount ? 'true' : 'false') . ', retail=' . $merchant_retail_price . ', sales=' . $merchant_sales_price . ', savings=' . $savings_amount . ', discount=' . $merchant_discount . '%');
			}
			
			$raw_shipping = $merchant['shipping'] ?? $merchant['shipping_cost'] ?? 0;
			$merchant_shipping = $this->normalize_price_value($raw_shipping, $merchant_currency);
			$merchant_availability = $merchant['availability'] ?? 'unknown';
			
			// Check multiple fields for merchant logo - Datafeedr might store it in different places
			$merchant_logo = '';
			$logo_fields = ['logo', 'merchant_logo', 'image', 'merchant_image', 'logo_url', 'merchant_logo_url', 'store_logo', 'brand_logo'];
			foreach ($logo_fields as $field) {
				if (!empty($merchant[$field]) && filter_var($merchant[$field], FILTER_VALIDATE_URL)) {
					$merchant_logo = $merchant[$field];
					break;
				}
			}
			
			// Also check nested products array for logo
			if (empty($merchant_logo) && isset($merchant['products']) && is_array($merchant['products'])) {
				foreach ($merchant['products'] as $product_item) {
					foreach ($logo_fields as $field) {
						if (!empty($product_item[$field]) && filter_var($product_item[$field], FILTER_VALIDATE_URL)) {
							$merchant_logo = $product_item[$field];
							break 2;
						}
					}
				}
			}
			
			$merchant_network = $merchant['network'] ?? $merchant['network_name'] ?? '';
			
			// Debug logging to help identify merchant name issues
			if (defined('WP_DEBUG') && WP_DEBUG) {
				// error_log('[AEBG] Merchant data keys: ' . implode(', ', array_keys($merchant)));
				// error_log('[AEBG] Extracted merchant_name: ' . $merchant_name);
				// error_log('[AEBG] Extracted merchant_logo: ' . ($merchant_logo ?: 'NOT FOUND'));
			}
			
			// Calculate total price (base + shipping)
			$total_price = floatval($merchant_price) + floatval($merchant_shipping);
			
			// Enhanced URL extraction for each merchant - ensure unique URLs
			$merchant_url = $this->extract_merchant_url($merchant, $product);
			
			// Process affiliate link to replace @@@ with actual affiliate ID
			if (!empty($merchant_url)) {
				// error_log('[AEBG] Processing affiliate link for merchant: ' . $merchant_name . ' - URL: ' . substr($merchant_url, 0, 100));
				$merchant_url = $this->get_affiliate_link_for_url($merchant_url, $merchant_name, $merchant_network);
				// error_log('[AEBG] Affiliate link processed successfully for merchant: ' . $merchant_name);
			}
			
			// Get merchant logo HTML
			// CRITICAL: Suppress deprecation warnings that might break execution
			// WordPress footer.php deprecation warnings are non-fatal but can interfere
			$old_error_reporting = error_reporting();
			error_reporting($old_error_reporting & ~E_DEPRECATED);
			try {
				$merchant_logo_html = $this->get_merchant_logo_html($merchant_name, $merchant_logo, $merchant_network);
			} catch (\Throwable $e) {
				error_log('[AEBG] ERROR in get_merchant_logo_html: ' . $e->getMessage());
				$merchant_logo_html = '<div class="aebg-merchant-name-text">' . esc_html($merchant_name) . '</div>';
			} finally {
				error_reporting($old_error_reporting);
			}
			
			// Get availability indicator HTML
			$availability_html = '';
			
			if ($show_availability) {
				// CRITICAL: Suppress deprecation warnings during availability class retrieval
				$old_error_reporting = error_reporting();
				error_reporting($old_error_reporting & ~E_DEPRECATED);
				try {
					// Default to in-stock (green checkmark) for unknown/not found statuses
					$availability_class = $this->get_availability_class($merchant_availability);
				} catch (\Throwable $e) {
					error_log('[AEBG] ERROR in get_availability_class: ' . $e->getMessage());
					$availability_class = 'in-stock';
				} finally {
					error_reporting($old_error_reporting);
				}
				// If unknown or empty, default to in-stock
				if (empty($merchant_availability) || $merchant_availability === 'unknown' || $merchant_availability === 'not_found') {
					$availability_class = 'in-stock';
				}
				$availability_html = '<div class="aebg-availability-indicator ' . esc_attr($availability_class) . '">
					<span class="aebg-availability-icon"></span>
					<span class="aebg-availability-text">På lager</span>
				</div>';
			}
			
			// Start card
			$html .= '<div class="aebg-comparison-card">';
			
			// Left section: Merchant logo
			$html .= '<div class="aebg-card-logo">';
			$html .= $merchant_logo_html;
			$html .= '</div>';
			
			// Middle section: Availability, Price, Shipping
			$html .= '<div class="aebg-card-content">';
			$html .= $availability_html;
			
			$html .= '<div class="aebg-card-pricing">';
			
			// Display pricing information
			if ($has_discount && $merchant_retail_price > 0 && $merchant_sales_price > 0) {
				// DISCOUNT CASE: Show retail price (strikethrough), sales price, and savings
				// 1. Retail price (strikethrough, gray, smaller) - the original price before discount
				$html .= '<div class="aebg-card-original-price" style="text-decoration: line-through; color: #999; font-size: 0.9em; margin-bottom: 2px;">' . $this->format_price($merchant_retail_price, $merchant_currency) . '</div>';
				
				// 2. Sales price (red, bold, larger) - the discounted price
				$html .= '<div class="aebg-card-base-price" style="color: #d32f2f; font-weight: bold; font-size: 1.1em; margin-bottom: 2px;">' . $this->format_price($merchant_sales_price, $merchant_currency) . '</div>';
				
				// 3. Savings and Discount - Combined on same line for desktop
				if ($savings_amount > 0 || $merchant_discount > 0) {
					$html .= '<div class="aebg-card-savings-wrapper">';
					if ($savings_amount > 0) {
						$html .= '<span class="aebg-card-savings">Spar ' . $this->format_price($savings_amount, $merchant_currency) . '</span>';
					}
					if ($merchant_discount > 0) {
						$html .= '<span class="aebg-card-discount">-' . number_format($merchant_discount, 0) . '%</span>';
					}
					$html .= '</div>';
				}
			} else {
				// NO DISCOUNT CASE: Show retail price only
				$html .= '<div class="aebg-card-base-price" style="font-weight: bold;">' . $this->format_price($merchant_retail_price, $merchant_currency) . '</div>';
			}
			
			// Shipping information
			if ($merchant_shipping > 0) {
				$html .= '<div class="aebg-card-shipping-price">Inkl. fragt: ' . $this->format_price($total_price, $merchant_currency) . '</div>';
			} else {
				$html .= '<div class="aebg-card-shipping-price">Inkl. fragt: ' . $this->format_price($merchant_price, $merchant_currency) . '</div>';
			}
			$html .= '</div>';
			
			$html .= '</div>'; // End card-content
			
			// Right section: Button
			$html .= '<div class="aebg-card-action">';
			if (!empty($merchant_url)) {
				$html .= '<a href="' . esc_url($merchant_url) . '" class="aebg-til-butik-btn" target="_blank" rel="noopener noreferrer">Til butik</a>';
			} else {
				$html .= '<span class="aebg-til-butik-btn" style="opacity: 0.5; cursor: not-allowed;">Ingen link</span>';
			}
			$html .= '</div>';
			
			$html .= '</div>'; // End card
		}

		$html .= '</div>'; // End cards container
		
		// CRITICAL: Log completion to identify hang points
		// error_log('[AEBG] generate_comparison_html completed, returning HTML (length: ' . strlen($html) . ')');
		
		return $html;
	}

	/**
	 * Generate cards comparison
	 */
	private function generate_cards_comparison($product, $merchants, $show_image, $show_rating, $show_availability) {
		// Initialize timeout variables to prevent undefined variable warnings
		$html_start_time = microtime(true);
		$max_html_generation_time = 10.0;
		
		$html = '<div class="aebg-comparison-cards">';
		
		// Get currency from product or detect from merchants
		$currency = $product['currency'] ?? null;
		if (empty($currency) && !empty($merchants)) {
			$currency = $this->detect_currency_from_merchants($merchants);
		}
		$currency = $currency ?: 'DKK'; // Default to DKK for Danish shops
		
		foreach ($merchants as $merchant) {
			$html .= '<div class="aebg-comparison-card">';
			
			if ($show_image) {
				$image_url = $product['image_url'] ?? '';
				$html .= '<div class="aebg-card-image">';
				if ($image_url) {
					$html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($product['name']) . '" />';
				} else {
					$html .= '<div class="aebg-no-image">No Image</div>';
				}
				$html .= '</div>';
			}

			// Handle both old and new merchant data formats
			$merchant_name = $merchant['name'] ?? $merchant['merchant'] ?? 'Unknown Merchant';
			$merchant_price = $merchant['lowest_price'] ?? $merchant['price'] ?? 0;
			// Prioritize Trustpilot rating if available, fallback to other rating sources
			$merchant_rating = $merchant['trustpilot_rating'] 
				?? $merchant['average_rating'] 
				?? $merchant['rating'] 
				?? 0;
			$merchant_availability = $merchant['availability'] ?? 'unknown';
			
			// Get currency for this merchant
			$merchant_currency = $merchant['currency'] ?? $currency;
			if (empty($merchant_currency) && isset($merchant['products']) && is_array($merchant['products']) && !empty($merchant['products'])) {
				$merchant_currency = $merchant['products'][0]['currency'] ?? $currency;
			}

			$html .= '<div class="aebg-card-content">';
			$html .= '<h4 class="aebg-card-merchant">' . esc_html($merchant_name) . '</h4>';
			$html .= '<div class="aebg-card-price">' . $this->format_price($merchant_price, $merchant_currency) . '</div>';
			
			if ($show_rating) {
				$html .= '<div class="aebg-card-rating">' . $this->generate_stars($merchant_rating) . ' ' . number_format($merchant_rating, 1) . '/5</div>';
			}
			
			if ($show_availability) {
				$availability_text = $this->get_availability_text($merchant_availability);
				$availability_class = $this->get_availability_class($merchant_availability);
				$html .= '<div class="aebg-card-availability ' . $availability_class . '">' . esc_html($availability_text) . '</div>';
			}

			// Enhanced URL extraction for each merchant - ensure unique URLs
			$merchant_url = $this->extract_merchant_url($merchant, $product);
			
			// Get merchant network for affiliate link processing
			$merchant_network = $merchant['network'] ?? $merchant['network_name'] ?? '';
			$merchant_name = $merchant['name'] ?? $merchant['merchant'] ?? '';
			
			// Process affiliate link to replace @@@ with actual affiliate ID
			if (!empty($merchant_url)) {
				$merchant_url = $this->get_affiliate_link_for_url($merchant_url, $merchant_name, $merchant_network);
			}
			
			if (!empty($merchant_url)) {
				$html .= '<a href="' . esc_url($merchant_url) . '" target="_blank" class="aebg-see-offer-btn" rel="noopener noreferrer">Se tilbud</a>';
			} else {
				$html .= '<span class="aebg-see-offer-btn" style="opacity: 0.5; cursor: not-allowed;">Ingen link</span>';
			}
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Generate list comparison
	 */
	private function generate_list_comparison($product, $merchants, $show_image, $show_rating, $show_availability) {
		$html = '<ul class="aebg-comparison-list">';
		
		// Get currency from product or detect from merchants
		$currency = $product['currency'] ?? null;
		if (empty($currency) && !empty($merchants)) {
			$currency = $this->detect_currency_from_merchants($merchants);
		}
		$currency = $currency ?: 'DKK'; // Default to DKK for Danish shops
		
		foreach ($merchants as $merchant) {
			$html .= '<li class="aebg-comparison-item">';
			
			if ($show_image) {
				$image_url = $product['image_url'] ?? '';
				$html .= '<div class="aebg-item-image">';
				if ($image_url) {
					$html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($product['name']) . '" />';
				} else {
					$html .= '<div class="aebg-no-image">No Image</div>';
				}
				$html .= '</div>';
			}

			// Handle both old and new merchant data formats
			$merchant_name = $merchant['name'] ?? $merchant['merchant'] ?? 'Unknown Merchant';
			$merchant_price = $merchant['lowest_price'] ?? $merchant['price'] ?? 0;
			// Prioritize Trustpilot rating if available, fallback to other rating sources
			$merchant_rating = $merchant['trustpilot_rating'] 
				?? $merchant['average_rating'] 
				?? $merchant['rating'] 
				?? 0;
			$merchant_availability = $merchant['availability'] ?? 'unknown';
			
			// Get currency for this merchant
			$merchant_currency = $merchant['currency'] ?? $currency;
			if (empty($merchant_currency) && isset($merchant['products']) && is_array($merchant['products']) && !empty($merchant['products'])) {
				$merchant_currency = $merchant['products'][0]['currency'] ?? $currency;
			}

			$html .= '<div class="aebg-item-content">';
			$html .= '<span class="aebg-item-merchant">' . esc_html($merchant_name) . '</span>';
			$html .= '<span class="aebg-item-price">' . $this->format_price($merchant_price, $merchant_currency) . '</span>';
			
			if ($show_rating) {
				$html .= '<span class="aebg-item-rating">' . $this->generate_stars($merchant_rating) . ' ' . number_format($merchant_rating, 1) . '/5</span>';
			}
			
			if ($show_availability) {
				$availability_text = $this->get_availability_text($merchant_availability);
				$availability_class = $this->get_availability_class($merchant_availability);
				$html .= '<span class="aebg-item-availability ' . $availability_class . '">' . esc_html($availability_text) . '</span>';
			}

			// Enhanced URL extraction for each merchant - ensure unique URLs
			$merchant_url = $this->extract_merchant_url($merchant, $product);
			
			// Get merchant network for affiliate link processing
			$merchant_network = $merchant['network'] ?? $merchant['network_name'] ?? '';
			$merchant_name = $merchant['name'] ?? $merchant['merchant'] ?? '';
			
			// Process affiliate link to replace @@@ with actual affiliate ID
			if (!empty($merchant_url)) {
				$merchant_url = $this->get_affiliate_link_for_url($merchant_url, $merchant_name, $merchant_network);
			}
			
			if (!empty($merchant_url)) {
				$html .= '<a href="' . esc_url($merchant_url) . '" target="_blank" class="aebg-see-offer-btn" rel="noopener noreferrer">Se tilbud</a>';
			} else {
				$html .= '<span class="aebg-see-offer-btn" style="opacity: 0.5; cursor: not-allowed;">Ingen link</span>';
			}
			$html .= '</div>';
			$html .= '</li>';
		}

		$html .= '</ul>';
		return $html;
	}

	/**
	 * Get merchant logo HTML
	 * Generates a logo based on merchant name/network or uses provided logo URL
	 */
	private function get_merchant_logo_html($merchant_name, $logo_url = '', $network = '') {
		// Get fallback HTML first
		$fallback_html = $this->get_merchant_fallback_html($merchant_name, $network);
		
		// If we have a logo URL, use it with proper fallback handling
		if (!empty($logo_url) && filter_var($logo_url, FILTER_VALIDATE_URL)) {
			// Use a wrapper to properly handle logo failure
			return '<div class="aebg-merchant-logo-wrapper">' .
				   '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($merchant_name) . '" class="aebg-merchant-logo-img" onerror="this.style.display=\'none\'; this.parentElement.querySelector(\'.aebg-merchant-logo-fallback\').style.display=\'flex\';" />' .
				   '<div class="aebg-merchant-logo-fallback" style="display:none;">' . $fallback_html . '</div>' .
				   '</div>';
		}
		
		// No logo available - show merchant name
		return $fallback_html;
	}
	
	/**
	 * Get fallback HTML when no merchant logo is available
	 * Shows full merchant name
	 */
	private function get_merchant_fallback_html($merchant_name, $network = '') {
		// CRITICAL: Suppress WordPress deprecation warnings that might break execution
		// The footer.php deprecation warning is non-fatal but can interfere with execution
		$old_error_reporting = error_reporting();
		error_reporting($old_error_reporting & ~E_DEPRECATED);
		try {
			// Convert slug-like names to readable format (e.g., "casa-decor" → "Casa Decor")
			$display_name = $this->format_merchant_name_for_display($merchant_name);
			
			// Always show full merchant name when no logo is available
			$result = '<div class="aebg-merchant-name-text" title="' . esc_attr($display_name) . '">' . esc_html($display_name) . '</div>';
			error_reporting($old_error_reporting);
			return $result;
		} catch (\Throwable $e) {
			error_log('[AEBG] ERROR in get_merchant_fallback_html: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
			error_reporting($old_error_reporting);
			// Return safe fallback
			return '<div class="aebg-merchant-name-text">' . esc_html($merchant_name) . '</div>';
		}
	}
	
	/**
	 * Format merchant name for display
	 * Converts slug-like names (e.g., "casa-decor") to readable format (e.g., "Casa Decor")
	 * Preserves hyphens in domain names (e.g., "Hunde-foder.dk" stays as "Hunde-foder.dk")
	 */
	private function format_merchant_name_for_display($merchant_name) {
		if (empty($merchant_name)) {
			return 'Unknown';
		}
		
		// Check if this looks like a domain name (contains .com, .dk, .net, etc.)
		$is_domain = preg_match('/\.(com|dk|net|org|io|co|se|no|fi|de|uk|fr|es|it|eu|info|biz|shop|store|online|site|website|store|shop|market|store|biz)$/i', $merchant_name);
		
		if ($is_domain) {
			// Domain names should preserve hyphens - only capitalize if needed
			// If it's all lowercase, capitalize first letter of each word part
			if (strtolower($merchant_name) === $merchant_name) {
				// Split by hyphens, capitalize each part, rejoin with hyphens
				$parts = explode('-', $merchant_name);
				$parts = array_map('ucfirst', $parts);
				return implode('-', $parts);
			}
			// Return as-is if already has capitalization (preserves existing formatting)
			return $merchant_name;
		}
		
		// For non-domain names, apply existing slug formatting logic
		if (strpos($merchant_name, '-') !== false || strpos($merchant_name, '_') !== false) {
			// Replace hyphens and underscores with spaces
			$formatted = str_replace(['-', '_'], ' ', $merchant_name);
			// Capitalize each word
			$formatted = ucwords(strtolower($formatted));
			return $formatted;
		}
		
		// If it's all lowercase and looks like it should be capitalized
		if (strtolower($merchant_name) === $merchant_name && strlen($merchant_name) > 2) {
			// Capitalize first letter of each word
			return ucwords($merchant_name);
		}
		
		// Return as-is if it's already properly formatted
		return $merchant_name;
	}
	
	/**
	 * Get merchant initials (first 2 letters)
	 */
	private function get_merchant_initials($merchant_name) {
		$name = trim($merchant_name);
		if (empty($name)) {
			return '??';
		}
		
		$words = explode(' ', $name);
		if (count($words) >= 2) {
			return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
		}
		
		return strtoupper(substr($name, 0, 2));
	}
	
	/**
	 * Get merchant color based on name/network (for consistent branding)
	 */
	private function get_merchant_color($merchant_name, $network = '') {
		// Color mapping for known merchants/networks
		$color_map = [
			'partner-ads' => '#ff6b35', // Orange
			'partnerads' => '#ff6b35',
			'awin' => '#f70d1a', // Red
			'tradedoubler' => '#00a651', // Green
			'webgains' => '#00a651',
		];
		
		$key = strtolower($network ?: $merchant_name);
		
		// Check network first
		if (!empty($network)) {
			foreach ($color_map as $map_key => $color) {
				if (stripos($key, $map_key) !== false) {
					return $color;
				}
			}
		}
		
		// Check merchant name
		foreach ($color_map as $map_key => $color) {
			if (stripos($key, $map_key) !== false) {
				return $color;
			}
		}
		
		// Generate a consistent color based on merchant name hash
		$hash = md5($merchant_name);
		$colors = [
			'#ff6b35', // Orange
			'#00a651', // Green
			'#f70d1a', // Red
			'#6366f1', // Indigo
			'#8b5cf6', // Purple
			'#f59e0b', // Amber
		];
		
		$index = hexdec(substr($hash, 0, 2)) % count($colors);
		return $colors[$index];
	}
	
	/**
	 * Get merchant icon if available (SVG or special character)
	 */
	private function get_merchant_icon($merchant_name, $network = '') {
		$key = strtolower($merchant_name);
		$network_key = strtolower($network);
		
		// Special icons for known merchants
		$icon_map = [
			'partner-ads' => '<svg viewBox="0 0 24 24" fill="white" width="24" height="24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>',
		];
		
		// Check network first
		if (!empty($network)) {
			foreach ($icon_map as $map_key => $icon) {
				if (stripos($network_key, $map_key) !== false) {
					return $icon;
				}
			}
		}
		
		// Check merchant name
		foreach ($icon_map as $map_key => $icon) {
			if (stripos($key, $map_key) !== false) {
				return $icon;
			}
		}
		
		return null;
	}

	/**
	 * Normalize price value (convert to float, handle string formats)
	 * 
	 * @deprecated Use CurrencyManager::normalizePrice() instead
	 * This method is kept for backward compatibility but now delegates to CurrencyManager
	 * 
	 * @param mixed $price Price value (string, int, or float)
	 * @param string $currency Currency code (optional, helps with detection)
	 * @return float Normalized price value
	 */
	private function normalize_price_value($price, $currency = null) {
		// Delegate to CurrencyManager for unified normalization
		$currency = $currency ?: CurrencyManager::getDefaultCurrency();
		return CurrencyManager::normalizePrice($price, $currency);
	}

	/**
	 * Detect currency from merchant name/domain
	 * 
	 * @deprecated Use CurrencyManager::detectCurrency() instead
	 * This method is kept for backward compatibility but now delegates to CurrencyManager
	 * 
	 * @param string $merchant_name Merchant name or domain
	 * @return string|null Currency code or null if cannot detect
	 */
	private function detect_currency_from_merchant_name($merchant_name) {
		return CurrencyManager::detectCurrency($merchant_name);
	}
	
	/**
	 * Detect currency from multiple merchants
	 * Checks if majority of merchants are from a specific country
	 * 
	 * @param array $merchants Array of merchant data
	 * @return string|null Currency code or null if cannot detect
	 */
	private function detect_currency_from_merchants($merchants) {
		if (empty($merchants) || !is_array($merchants)) {
			return null;
		}
		
		$currency_counts = [];
		
		foreach ($merchants as $merchant) {
			$merchant_name = $merchant['merchant_name'] ?? $merchant['name'] ?? $merchant['merchant'] ?? '';
			$detected_currency = $this->detect_currency_from_merchant_name($merchant_name);
			
			if ($detected_currency) {
				$currency_counts[$detected_currency] = ($currency_counts[$detected_currency] ?? 0) + 1;
			}
		}
		
		if (empty($currency_counts)) {
			return null;
		}
		
		// Return the most common currency
		arsort($currency_counts);
		return array_key_first($currency_counts);
	}
	
	/**
	 * Format price for display
	 * 
	 * @param float $price Price value
	 * @param string $currency Currency code (optional, will use default currency if not provided)
	 * @return string Formatted price
	 */
	private function format_price($price, $currency = null) {
		// Use CurrencyManager for unified formatting
		$currency = $currency ?: CurrencyManager::getDefaultCurrency();
		return CurrencyManager::formatPrice($price, $currency);
	}

	/**
	 * Generate star rating HTML
	 */
	private function generate_stars($rating) {
		$rating = floatval($rating);
		$full_stars = floor($rating);
		$half_star = $rating - $full_stars >= 0.5;
		$empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);

		$html = '';
		for ($i = 0; $i < $full_stars; $i++) {
			$html .= '<span class="star filled">★</span>';
		}
		if ($half_star) {
			$html .= '<span class="star half">☆</span>';
		}
		for ($i = 0; $i < $empty_stars; $i++) {
			$html .= '<span class="star empty">☆</span>';
		}

		return $html;
	}

	/**
	 * Detect affiliate network from URL domain
	 * 
	 * @param string $url The affiliate URL
	 * @return string|null Network identifier or null if not detected
	 */
	private function detect_network_from_url($url) {
		if (empty($url)) {
			return null;
		}
		
		// Extract domain from URL
		$parsed = parse_url($url);
		if (empty($parsed['host'])) {
			return null;
		}
		
		$domain = strtolower($parsed['host']);
		
		// Remove www. prefix
		$domain = preg_replace('/^www\./', '', $domain);
		
		// Network domain mappings
		$domain_mappings = [
			'partner-ads.com' => 'partner_ads',
			'partnerads.com' => 'partner_ads',
			'awin.com' => 'awin',
			'awin1.com' => 'awin',
			'timeone.com' => 'timeone',
			'adrecord.com' => 'adrecord',
			'addrevenue.io' => 'addrevenue',
			'zanox.com' => 'zanox',
			'partnerize.com' => 'partnerize',
			'cj.com' => 'cj_us',
			'linkshare.com' => 'linkshare_us',
			'shareasale.com' => 'shareasale',
			'flexoffers.com' => 'flexoffers',
			'pepperjam.com' => 'pepperjam',
			'rakuten.com' => 'rakuten',
		];
		
		// Check exact domain match
		if (isset($domain_mappings[$domain])) {
			return $domain_mappings[$domain];
		}
		
		// Check partial domain match (e.g., dk.partner-ads.com)
		foreach ($domain_mappings as $domain_pattern => $network) {
			if (strpos($domain, $domain_pattern) !== false) {
				return $network;
			}
		}
		
		return null;
	}

	/**
	 * Normalize network key for database lookup
	 * Handles various formats: "Partner-ads Denmark", "partner_ads_dk", "partner_ads", etc.
	 * 
	 * @param string $source Source name or network identifier
	 * @return array Array of possible network keys to try
	 */
	private function normalize_network_key($source) {
		if (empty($source)) {
			return [];
		}
		
		$source_lower = strtolower(trim($source));
		$keys_to_try = [];
		
		// Direct mappings from source names to network keys
		// NOTE: 'api_15' is the Datafeedr API network code for Partner-ads Denmark
		$source_mapping = [
			'partner-ads' => ['api_15', 'partner_ads', 'partner_ads_dk', 'Partner-ads Denmark', 'Partner-ads', 'partnerads', 'PartnerAds', 'partner-ads.com'],
			'partner-ads denmark' => ['api_15', 'partner_ads_dk', 'Partner-ads Denmark', 'partner_ads', 'Partner-ads', 'partnerads', 'PartnerAds'],
			'partner_ads' => ['api_15', 'partner_ads', 'partner_ads_dk', 'Partner-ads Denmark', 'Partner-ads', 'partnerads', 'PartnerAds', 'partner-ads.com'],
			'partner_ads_dk' => ['partner_ads_dk', 'Partner-ads Denmark', 'partner_ads', 'Partner-ads', 'partnerads', 'PartnerAds'],
			'avantlink us' => ['avantlink_us', 'AvantLink US'],
			'avantlink uk' => ['avantlink_uk', 'AvantLink UK'],
			'linkshare us' => ['linkshare_us', 'LinkShare US'],
			'linkshare uk' => ['linkshare_uk', 'LinkShare UK'],
			'commission junction' => ['cj_us', 'Commission Junction'],
			'commission junction uk' => ['cj_uk', 'Commission Junction UK'],
			'shareasale' => ['shareasale', 'ShareASale'],
			'flexoffers' => ['flexoffers', 'FlexOffers'],
			'pepperjam' => ['pepperjam', 'Pepperjam'],
			'rakuten' => ['rakuten', 'Rakuten'],
			'effinity' => ['effinity', 'Effinity'],
			'partnerize' => ['partnerize', 'Partnerize'],
			'awin' => ['awin', 'Awin', 'Awin Denmark'],
			'linkconnector' => ['linkconnector', 'LinkConnector'],
			'timeone' => ['timeone', 'TimeOne'],
			'adrecord' => ['adrecord', 'Adrecord'],
			'belboon' => ['belboon', 'Belboon'],
			'adservice' => ['adservice', 'Adservice'],
			'affiliate gateway' => ['affiliate_gateway', 'Affiliate Gateway'],
			'commission factory' => ['commission_factory', 'Commission Factory'],
			'goaffpro' => ['goaffpro', 'GoAffPro'],
			'addrevenue' => ['addrevenue', 'AddRevenue'],
		];
		
		// Check exact match
		if (isset($source_mapping[$source_lower])) {
			$keys_to_try = array_merge($keys_to_try, $source_mapping[$source_lower]);
		}
		
		// Generate normalized versions
		$normalized = strtolower(str_replace([' ', '-'], '_', $source));
		if (!in_array($normalized, $keys_to_try)) {
			$keys_to_try[] = $normalized;
		}
		
		// Add original source (in case it's already a network key)
		if (!in_array($source, $keys_to_try)) {
			$keys_to_try[] = $source;
		}
		
		// Remove duplicates and return
		return array_unique($keys_to_try);
	}

	/**
	 * Process affiliate links by replacing @@@ with actual affiliate IDs
	 * Based on Datafeedr documentation: https://datafeedrapi.helpscoutdocs.com/category/183-networks-merchants
	 * 
	 * @param string $url The affiliate URL with @@@ placeholder
	 * @param string $source The source/network name (optional, will be detected from URL if not provided)
	 * @return string Processed URL with affiliate ID replaced
	 */
	public function process_affiliate_link($url, $source = '') {
		if (empty($url)) {
			return $url;
		}
		
		// CRITICAL: Fix malformed URLs BEFORE processing @@@ replacement
		// Handle patterns like http://s://, s://, s//, etc.
		if (preg_match('/^(http:\/\/|https:\/\/)?s:\/\//', $url, $matches)) {
			// Replace s:// with https://
			$url = preg_replace('/^(http:\/\/|https:\/\/)?s:\/\//', 'https://', $url);
			error_log('[AEBG] Fixed malformed URL pattern s://: ' . $url);
		} elseif (preg_match('/^(http:\/\/|https:\/\/)?s\/\//', $url, $matches)) {
			// Replace s// with https://
			$url = preg_replace('/^(http:\/\/|https:\/\/)?s\/\//', 'https://', $url);
			error_log('[AEBG] Fixed malformed URL pattern s//: ' . $url);
		}
		
		// Check if URL contains @@@ placeholder
		if (strpos($url, '@@@') === false) {
			return $url;
		}
		
		// Step 1: Try to detect network from URL if source not provided
		if (empty($source)) {
			$detected_network = $this->detect_network_from_url($url);
			if ($detected_network) {
				$source = $detected_network;
				error_log('[AEBG] Detected network from URL: ' . $detected_network);
			}
		}
		
		// Step 2: Normalize source to get possible network keys
		$network_keys_to_try = $this->normalize_network_key($source);
		
		// Step 3: Try to get affiliate ID from database (PRIMARY METHOD)
		$affiliate_id = null;
		$found_network_key = null;
		$networks_manager = new \AEBG\Admin\Networks_Manager();
		
		// error_log('[AEBG] Attempting to find affiliate ID for source: ' . $source);
		// error_log('[AEBG] Network keys to try: ' . implode(', ', $network_keys_to_try));
		
		foreach ($network_keys_to_try as $network_key) {
			// error_log('[AEBG] Trying network key: ' . $network_key);
			$affiliate_id = $networks_manager->get_affiliate_id($network_key);
			if (!empty($affiliate_id)) {
				$found_network_key = $network_key;
				// error_log('[AEBG] Found affiliate ID in database for network key: ' . $network_key . ' = ' . $affiliate_id);
				break;
			} else {
				// error_log('[AEBG] No affiliate ID found for network key: ' . $network_key);
			}
		}
		
		// Additional fallback: Try to get all affiliate IDs and find a match
		if (empty($affiliate_id)) {
			// error_log('[AEBG] Primary lookup failed, trying to get all affiliate IDs...');
			$all_affiliate_ids = $networks_manager->get_all_affiliate_ids();
			// error_log('[AEBG] Found ' . count($all_affiliate_ids) . ' affiliate IDs in database');
			// error_log('[AEBG] Available network keys: ' . implode(', ', array_keys($all_affiliate_ids)));
			
			// Try case-insensitive matching
			foreach ($network_keys_to_try as $network_key) {
				foreach ($all_affiliate_ids as $stored_key => $stored_id) {
					if (strtolower($stored_key) === strtolower($network_key)) {
						$affiliate_id = $stored_id;
						$found_network_key = $stored_key;
						// error_log('[AEBG] Found affiliate ID via case-insensitive match: ' . $stored_key . ' = ' . $affiliate_id);
						break 2;
					}
				}
			}
			
			// Try partial matching for partner-ads variations
			if (empty($affiliate_id) && (strpos($source, 'partner') !== false || strpos($source, 'ads') !== false)) {
				// error_log('[AEBG] Trying partial match for partner-ads...');
				foreach ($all_affiliate_ids as $stored_key => $stored_id) {
					$stored_lower = strtolower($stored_key);
					if (strpos($stored_lower, 'partner') !== false && strpos($stored_lower, 'ads') !== false) {
						$affiliate_id = $stored_id;
						$found_network_key = $stored_key;
						// error_log('[AEBG] Found affiliate ID via partial match: ' . $stored_key . ' = ' . $affiliate_id);
						break;
					}
				}
			}
		}
		
		// Step 4: Fallback to WordPress options (BACKWARD COMPATIBILITY)
		if (empty($affiliate_id)) {
			$settings = get_option('aebg_settings', []);
			$affiliate_ids = $settings['affiliate_ids'] ?? [];
			
			foreach ($network_keys_to_try as $network_key) {
				if (isset($affiliate_ids[$network_key]) && !empty($affiliate_ids[$network_key])) {
					$affiliate_id = $affiliate_ids[$network_key];
					$found_network_key = $network_key;
					// error_log('[AEBG] Found affiliate ID in WordPress options for network key: ' . $network_key . ' = ' . $affiliate_id);
					break;
				}
			}
		}
		
		// Step 5: Final fallback - try to detect from URL if still not found
		if (empty($affiliate_id)) {
			$detected_network = $this->detect_network_from_url($url);
			if ($detected_network && $detected_network !== $source) {
				$fallback_keys = $this->normalize_network_key($detected_network);
				foreach ($fallback_keys as $network_key) {
					$affiliate_id = $networks_manager->get_affiliate_id($network_key);
					if (!empty($affiliate_id)) {
						$found_network_key = $network_key;
						// error_log('[AEBG] Found affiliate ID via URL detection fallback: ' . $network_key . ' = ' . $affiliate_id);
						break;
					}
				}
			}
		}
		
		// Step 6: Replace @@@ with affiliate ID or log error
		if (empty($affiliate_id)) {
			// Log detailed error for debugging
			error_log('[AEBG] ERROR: Missing affiliate ID for source: ' . $source);
			error_log('[AEBG] ERROR: Tried network keys: ' . implode(', ', $network_keys_to_try));
			error_log('[AEBG] ERROR: URL: ' . $url);
			
			// Return URL unchanged (with @@@) so it's visible that something is wrong
			return $url;
		}
		
		// Replace @@@ with the actual affiliate ID
		$processed_url = str_replace('@@@', $affiliate_id, $url);
		
		// Log successful processing
		// error_log('[AEBG] SUCCESS: Processed affiliate link for source "' . $source . '" (network key: ' . ($found_network_key ?? 'unknown') . ')');
		// error_log('[AEBG] SUCCESS: Original URL: ' . $url);
		// error_log('[AEBG] SUCCESS: Processed URL: ' . $processed_url);
		
		return $processed_url;
	}



	/**
	 * Get affiliate link for a URL and merchant name
	 * Public method for use by other classes
	 *
	 * @param string $url The product URL
	 * @param string $merchant_name The merchant name
	 * @param string|null $merchant_network The merchant's network (optional, from data structure)
	 * @return string The affiliate URL
	 */
	public function get_affiliate_link_for_url($url, $merchant_name, $merchant_network = null) {
		if (empty($url)) {
			return '#';
		}

		// Datafeedr should provide affiliate links with @@@ placeholder
		// Process the URL to replace @@@ with actual affiliate ID
		
		// Step 1: Try to detect network from URL (if it contains affiliate network domain)
		$detected_network = $this->detect_network_from_url($url);
		
		if ($detected_network) {
			// Network detected from URL - use it directly
			return $this->process_affiliate_link($url, $detected_network);
		}
		
		// Step 2: If URL detection failed, use merchant's network from data structure
		if (!empty($merchant_network)) {
			$normalized_networks = $this->normalize_network_key($merchant_network);
			if (!empty($normalized_networks)) {
				// Try each possible network key for @@@ processing
				foreach ($normalized_networks as $network_key) {
					$processed = $this->process_affiliate_link($url, $network_key);
					// If processed URL is different from original and doesn't contain @@@ placeholder, it was successful
					if ($processed !== $url && strpos($processed, '@@@') === false) {
						// Successfully processed (affiliate ID was found and inserted)
						if (defined('WP_DEBUG') && WP_DEBUG) {
							error_log('[AEBG] get_affiliate_link_for_url: Successfully processed using merchant network: ' . $merchant_network . ' (key: ' . $network_key . ')');
						}
						return $processed;
					}
				}
			}
		}
		
		// Step 3: Last resort: fallback to merchant name mapping
		$source = $this->map_merchant_to_source($merchant_name);
		return $this->process_affiliate_link($url, $source);
	}

	/**
	 * Map merchant name to source for affiliate processing
	 *
	 * @param string $merchant_name The merchant name
	 * @return string The source name
	 */
	private function map_merchant_to_source($merchant_name) {
		if (empty($merchant_name)) {
			return '';
		}

		// Common merchant to source mappings
		$merchant_mapping = [
			'amazon' => 'Amazon',
			'walmart' => 'Walmart',
			'best buy' => 'Best Buy',
			'target' => 'Target',
			'home depot' => 'Home Depot',
			'lowes' => 'Lowe\'s',
			'ebay' => 'eBay',
			'etsy' => 'Etsy',
			'wayfair' => 'Wayfair',
			'overstock' => 'Overstock',
			'newegg' => 'Newegg',
			'bhphotovideo' => 'B&H Photo Video',
			'adorama' => 'Adorama',
			'adorama camera' => 'Adorama',
			'adorama.com' => 'Adorama',
			'partner-ads' => 'Partner-ads',
			'partner-ads denmark' => 'Partner-ads',
			'partnerads' => 'Partner-ads',
			'partner_ads' => 'Partner-ads',
			// Danish merchants (Partner-ads network)
			'proshop' => 'Partner-ads',
			'proshop.dk' => 'Partner-ads',
			'boligcenter' => 'Partner-ads',
			'boligcenter.dk' => 'Partner-ads',
			'hundefoder.dk' => 'Partner-ads',
			'hunde-foder.dk' => 'Partner-ads',
			'hunde foder.dk' => 'Partner-ads',
			'mypets.dk' => 'Partner-ads',
			'my pets.dk' => 'Partner-ads',
		];

		$merchant_lower = strtolower(trim($merchant_name));
		
		// Check for exact matches first
		if (isset($merchant_mapping[$merchant_lower])) {
			return $merchant_mapping[$merchant_lower];
		}

		// Check for partial matches
		foreach ($merchant_mapping as $key => $source) {
			if (strpos($merchant_lower, $key) !== false) {
				return $source;
			}
		}

		// If no match found, return the merchant name as-is
		return $merchant_name;
	}

	/**
	 * Get variable value from current context
	 * Handles variables like {product_id}, {product_name}, {product-1}, {product-2}, etc.
	 */
	private function get_variable_value($variable_name, $post_id) {
		// Get products for this post
		$products = get_post_meta($post_id, '_aebg_products', true);
		if (empty($products) || !is_array($products)) {
			return null;
		}

		// Handle product-X format (e.g., product-1, product-2, etc.)
		if (preg_match('/^product-(\d+)$/', $variable_name, $matches)) {
			$product_index = (int) $matches[1] - 1; // Convert to 0-based index
			$product = $products[$product_index] ?? null;
			
			if (!$product) {
				return null;
			}
			
			// Return the product ID for price comparison
			return $product['id'] ?? null;
		}

		// Handle product-X-field format (e.g., product-1-name, product-2-image, etc.)
		if (preg_match('/^product-(\d+)-(.+)$/', $variable_name, $matches)) {
			$product_index = (int) $matches[1] - 1; // Convert to 0-based index
			$field_name = $matches[2];
			
			$product = $products[$product_index] ?? null;
			if (!$product) {
				return null;
			}
			
			// Handle special fields
			switch ($field_name) {
				case 'id':
					return $product['id'] ?? null;
				case 'name':
					return $product['name'] ?? null;
				case 'price':
					return $product['price'] ?? null;
				case 'rating':
					return $product['rating'] ?? null;
				case 'merchant':
					return $product['merchant'] ?? null;
				case 'url':
					return $product['url'] ?? null;
				case 'image':
					return $product['image_url'] ?? $product['image'] ?? null;
				default:
					// Try to get from product array directly
					return $product[$field_name] ?? null;
			}
		}

		// Handle legacy format (e.g., product_id, product_name, etc.)
		$product = $products[0] ?? null;
		if (!$product) {
			return null;
		}

		// Map variable names to product fields
		switch ($variable_name) {
			case 'product_id':
				return $product['id'] ?? null;
			case 'product_name':
				return $product['name'] ?? null;
			case 'product_price':
				return $product['price'] ?? null;
			case 'product_rating':
				return $product['rating'] ?? null;
			case 'product_merchant':
				return $product['merchant'] ?? null;
			case 'product_url':
				return $product['url'] ?? null;
			case 'product_image':
				return $product['image_url'] ?? $product['image'] ?? null;
			default:
				// Try to get from product array directly
				return $product[$variable_name] ?? null;
		}
	}
	
	/**
	 * Normalize availability values to standard format
	 * 
	 * @param string $availability Raw availability value
	 * @return string Normalized availability value
	 */
	private function normalize_availability($availability) {
		if (empty($availability)) {
			return 'unknown';
		}
		
		// Normalize the availability value
		$availability = strtolower(trim($availability));
		
		// Map various availability formats to standard values
		$normalization_mapping = [
			// In stock variations
			'in_stock' => 'in_stock',
			'in stock' => 'in_stock',
			'instock' => 'in_stock',
			'available' => 'in_stock',
			'yes' => 'in_stock',
			'true' => 'in_stock',
			'1' => 'in_stock',
			'active' => 'in_stock',
			'ready' => 'in_stock',
			'ready to ship' => 'in_stock',
			'ready to ship' => 'in_stock',
			
			// Out of stock variations
			'out_of_stock' => 'out_of_stock',
			'out of stock' => 'out_of_stock',
			'outofstock' => 'out_of_stock',
			'unavailable' => 'out_of_stock',
			'no' => 'out_of_stock',
			'false' => 'out_of_stock',
			'0' => 'out_of_stock',
			'inactive' => 'out_of_stock',
			'discontinued' => 'out_of_stock',
			'not available' => 'out_of_stock',
			
			// Special statuses
			'pre_order' => 'pre_order',
			'preorder' => 'pre_order',
			'backorder' => 'backorder',
			'back_order' => 'backorder',
			'limited' => 'limited',
			'low_stock' => 'low_stock',
			'low stock' => 'low_stock',
			'reserved' => 'reserved',
			'pending' => 'pending',
			'processing' => 'processing'
		];
		
		// Check for exact matches first
		if (isset($normalization_mapping[$availability])) {
			return $normalization_mapping[$availability];
		}
		
		// Check for partial matches
		foreach ($normalization_mapping as $key => $normalized) {
			if (strpos($availability, $key) !== false) {
				return $normalized;
			}
		}
		
		// If no match found, try to infer from context
		if (strpos($availability, 'stock') !== false) {
			if (strpos($availability, 'out') !== false || strpos($availability, 'no') !== false) {
				return 'out_of_stock';
			} else {
				return 'in_stock';
			}
		}
		
		// Default to unknown if we can't determine
		return 'unknown';
	}
	
	/**
	 * Get human-readable availability text
	 * 
	 * @param string $availability Raw availability value
	 * @return string Formatted availability text
	 */
	private function get_availability_text($availability) {
		// Normalize the availability value
		$availability = strtolower(trim($availability));
		
		// Map common availability values to Danish text
		$availability_mapping = [
			'in_stock' => 'På lager',
			'in stock' => 'På lager',
			'instock' => 'På lager',
			'available' => 'På lager',
			'yes' => 'På lager',
			'true' => 'På lager',
			'1' => 'På lager',
			
			'out_of_stock' => 'Ikke på lager',
			'out of stock' => 'Ikke på lager',
			'outofstock' => 'Ikke på lager',
			'unavailable' => 'Ikke på lager',
			'no' => 'Ikke på lager',
			'false' => 'Ikke på lager',
			'0' => 'Ikke på lager',
			
			'pre_order' => 'Forudbestilling',
			'preorder' => 'Forudbestilling',
			'backorder' => 'Tilbagebestilling',
			'back_order' => 'Tilbagebestilling',
			
			'limited' => 'Begrænset lager',
			'low_stock' => 'Lavt lager',
			'low stock' => 'Lavt lager',
			
			'unknown' => 'Ukendt status',
			'' => 'Ukendt status'
		];
		
		// Check for exact matches first
		if (isset($availability_mapping[$availability])) {
			return $availability_mapping[$availability];
		}
		
		// Check for partial matches
		foreach ($availability_mapping as $key => $text) {
			if (strpos($availability, $key) !== false) {
				return $text;
			}
		}
		
		// If no match found, return the original value capitalized
		return ucfirst($availability) ?: 'Ukendt status';
	}
	
	/**
	 * Get CSS class for availability styling
	 * 
	 * @param string $availability Raw availability value
	 * @return string CSS class name
	 */
	private function get_availability_class($availability) {
		// Normalize the availability value
		$availability = strtolower(trim($availability));
		
		// Map availability values to CSS classes
		$class_mapping = [
			'in_stock' => 'in-stock',
			'in stock' => 'in-stock',
			'instock' => 'in-stock',
			'available' => 'in-stock',
			'yes' => 'in-stock',
			'true' => 'in-stock',
			'1' => 'in-stock',
			
			'out_of_stock' => 'out-of-stock',
			'out of stock' => 'out-of-stock',
			'outofstock' => 'out-of-stock',
			'unavailable' => 'out-of-stock',
			'no' => 'out-of-stock',
			'false' => 'out-of-stock',
			'0' => 'out-of-stock',
			
			'pre_order' => 'pre-order',
			'preorder' => 'pre-order',
			'backorder' => 'backorder',
			'back_order' => 'backorder',
			
			'limited' => 'limited-stock',
			'low_stock' => 'low-stock',
			'low stock' => 'low-stock',
			
			'unknown' => 'unknown-status',
			'' => 'unknown-status'
		];
		
		// Check for exact matches first
		if (isset($class_mapping[$availability])) {
			return $class_mapping[$availability];
		}
		
		// Check for partial matches
		foreach ($class_mapping as $key => $class) {
			if (strpos($availability, $key) !== false) {
				return $class;
			}
		}
		
		// Default to unknown status
		return 'unknown-status';
	}
	
	/**
	 * Extract merchant URL with enhanced logic to ensure unique URLs for each merchant
	 * 
	 * @param array $merchant The merchant data array
	 * @param array $product The main product data array
	 * @return string The extracted URL for this specific merchant
	 */
	private function extract_merchant_url($merchant, $product) {
		// Priority 1: affiliate_url from Datafeedr (contains @@@ placeholder for affiliate processing)
		// This is the highest priority since Datafeedr provides affiliate links with @@@
		if (!empty($merchant['affiliate_url'])) {
			$url = trim($merchant['affiliate_url']);
			if (filter_var($url, FILTER_VALIDATE_URL) || strpos($url, '@@@') !== false) {
				// Accept URLs with @@@ placeholder even if filter_var fails (some URLs with @@@ may not validate)
				return $url;
			}
		}
		
		// Priority 2: Check nested products array for affiliate_url first
		if (isset($merchant['products']) && is_array($merchant['products'])) {
			foreach ($merchant['products'] as $product_item) {
				// Prioritize affiliate_url from products array (Datafeedr provides this)
				if (!empty($product_item['affiliate_url'])) {
					$url = trim($product_item['affiliate_url']);
					if (filter_var($url, FILTER_VALIDATE_URL) || strpos($url, '@@@') !== false) {
						return $url;
					}
				}
			}
		}
		
		// Priority 3: Other merchant URL fields (fallback if no affiliate_url)
		$priority_url_fields = ['url', 'direct_url', 'product_url', 'merchant_url'];
		foreach ($priority_url_fields as $field) {
			if (!empty($merchant[$field])) {
				$url = trim($merchant[$field]);
				// URLs from Datafeedr API are already correctly formatted - validate only
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					return $url;
				}
			}
		}
		
		// Priority 4: Check nested products array for other URL fields
		if (isset($merchant['products']) && is_array($merchant['products'])) {
			foreach ($merchant['products'] as $product_item) {
				// Check other URL fields in the nested product
				foreach ($priority_url_fields as $field) {
					if (!empty($product_item[$field])) {
						$url = trim($product_item[$field]);
						// URLs from Datafeedr API are already correctly formatted - validate only
						if (filter_var($url, FILTER_VALIDATE_URL)) {
							return $url;
						}
					}
				}
			}
		}
		
		// Priority 3: Check if merchant has a specific product URL
		if (isset($merchant['product_urls']) && is_array($merchant['product_urls'])) {
			foreach ($merchant['product_urls'] as $url) {
				$url = trim($url);
				// URLs from Datafeedr API are already correctly formatted - validate only
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					// if (defined('WP_DEBUG') && WP_DEBUG) {
					// 	error_log('[AEBG] extract_merchant_url - Found URL in product_urls array: ' . $url);
					// }
					return $url;
				}
			}
		}
		
		// Priority 4: Check merchant-specific fields that might contain URLs
		$merchant_specific_fields = ['merchant_link', 'store_url', 'shop_url', 'website'];
		foreach ($merchant_specific_fields as $field) {
			if (!empty($merchant[$field])) {
				$url = trim($merchant[$field]);
				// URLs from Datafeedr API are already correctly formatted - validate only
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					// if (defined('WP_DEBUG') && WP_DEBUG) {
					// 	error_log('[AEBG] extract_merchant_url - Found URL in merchant-specific field "' . $field . '": ' . $url);
					// }
					return $url;
				}
			}
		}
		
		// Priority 5: Fallback to main product URL (lowest priority)
		if (!empty($product['url'])) {
			$url = trim($product['url']);
			
			// URLs from Datafeedr API are already correctly formatted - validate only
			if (filter_var($url, FILTER_VALIDATE_URL)) {
				// error_log('[AEBG] extract_merchant_url - Using main product URL as fallback: ' . $url);
				return $url;
			} else {
				// error_log('[AEBG] extract_merchant_url - Main product URL is invalid: ' . $url);
			}
		}
		
		// Priority 6: Check main product for other URL fields
		$product_url_fields = ['direct_url', 'product_url', 'affiliate_url'];
		foreach ($product_url_fields as $field) {
			if (!empty($product[$field])) {
				$url = trim($product[$field]);
				// URLs from Datafeedr API are already correctly formatted - validate only
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					// error_log('[AEBG] extract_merchant_url - Using main product field "' . $field . '" as fallback: ' . $url);
					return $url;
				}
			}
		}
		
		// if (defined('WP_DEBUG') && WP_DEBUG) {
		// 	error_log('[AEBG] extract_merchant_url - No valid URL found for merchant: ' . ($merchant['name'] ?? 'Unknown'));
		// }
		return '';
	}
	
	/**
	 * Email signup shortcode
	 * 
	 * @param array $atts Shortcode attributes
	 * @return string Shortcode output
	 */
	public function email_signup_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'list' => 'default',
			'post_id' => 0,
			'show_name' => 'yes',
			'button_text' => __( 'Subscribe', 'aebg' ),
		], $atts, 'aebg_email_signup' );
		
		$list_key = sanitize_text_field( $atts['list'] );
		$post_id = (int) $atts['post_id'] ?: get_the_ID();
		$show_name = $atts['show_name'] === 'yes';
		$button_text = sanitize_text_field( $atts['button_text'] );
		
		if ( ! $post_id ) {
			return '';
		}
		
		// Get or create list
		$list_manager = new \AEBG\EmailMarketing\Core\ListManager();
		$list = $list_manager->get_or_create_list( $post_id, $list_key );
		
		if ( ! $list ) {
			return '';
		}
		
		ob_start();
		?>
		<div class="aebg-email-signup-shortcode" data-list-id="<?php echo esc_attr( $list->id ); ?>">
			<form class="aebg-email-signup-form">
				<?php wp_nonce_field( 'aebg_email_signup', '_aebg_nonce' ); ?>
				<input type="hidden" name="list_id" value="<?php echo esc_attr( $list->id ); ?>">
				
				<?php if ( $show_name ): ?>
					<div class="aebg-form-group">
						<input type="text" name="first_name" placeholder="<?php esc_attr_e( 'First Name', 'aebg' ); ?>" class="aebg-form-input">
					</div>
					<div class="aebg-form-group">
						<input type="text" name="last_name" placeholder="<?php esc_attr_e( 'Last Name', 'aebg' ); ?>" class="aebg-form-input">
					</div>
				<?php endif; ?>
				
				<div class="aebg-form-group">
					<input type="email" name="email" placeholder="<?php esc_attr_e( 'Email Address', 'aebg' ); ?>" required class="aebg-form-input">
				</div>
				
				<!-- Honeypot field -->
				<input type="text" name="aebg_website" value="" style="display:none !important;" tabindex="-1" autocomplete="off">
				
				<div class="aebg-form-group">
					<button type="submit" class="aebg-form-submit" data-original-text="<?php echo esc_attr( $button_text ); ?>">
						<?php echo esc_html( $button_text ); ?>
					</button>
				</div>
				
				<div class="aebg-form-message"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

}



