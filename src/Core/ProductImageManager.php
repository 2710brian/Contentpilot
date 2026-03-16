<?php

namespace AEBG\Core;

/**
 * Product Image Manager Class
 * 
 * Handles downloading product images and inserting them into WordPress media library
 * 
 * @package AEBG\Core
 */
class ProductImageManager {
    
    /**
     * Download and insert product images into WordPress media library
     * 
     * @param array $products Array of products with image URLs
     * @param int $post_id Post ID to associate images with
     * @return array Array of processed products with attachment IDs
     */
    public static function processProductImages($products, $post_id = 0) {
        if (!is_array($products) || empty($products)) {
            return $products;
        }

        // CRITICAL: Set maximum time limit for entire image processing
        // Prevents hanging if image downloads take too long
        $process_start = microtime(true);
        $max_process_time = 300; // 5 minutes max for all image processing
        
        $processed_products = [];
        
        foreach ($products as $index => $product) {
            // CRITICAL: Check if we've exceeded maximum process time
            $process_elapsed = microtime(true) - $process_start;
            if ($process_elapsed > $max_process_time) {
                error_log("[AEBG] ⚠️ Image processing exceeded maximum time ({$process_elapsed}s > {$max_process_time}s), skipping remaining images");
                // Add remaining products without processing
                for ($i = $index; $i < count($products); $i++) {
                    $processed_products[] = $products[$i];
                }
                break;
            }
            
            $product_num = intval($index) + 1;
            $processed_product = $product;
            
            // Process featured image
            if (!empty($product['image_url'])) {
                // CRITICAL: Wrap in try-catch to prevent fatal errors from halting execution
                try {
                    error_log("[AEBG] Processing featured image for product {$product_num}: {$product['image_url']}");
                    $featured_attachment_id = self::downloadAndInsertImage(
                        $product['image_url'],
                        $product['name'] ?? "Product {$product_num}",
                        $post_id,
                        "product-{$product_num}-featured"
                    );
                    
                    error_log("[AEBG] downloadAndInsertImage returned for product {$product_num}: " . ($featured_attachment_id ? "ID {$featured_attachment_id}" : "false"));
                    
                    if ($featured_attachment_id) {
                        error_log("[AEBG] Getting attachment URL for product {$product_num}, attachment ID: {$featured_attachment_id}");
                        $processed_product['featured_image_id'] = $featured_attachment_id;
                        $processed_product['featured_image_url'] = wp_get_attachment_url($featured_attachment_id);
                        error_log("[AEBG] Got attachment URL for product {$product_num}: " . ($processed_product['featured_image_url'] ?? 'null'));
                        
                        // If we have a post_id, associate the attachment with the post
                        if ($post_id > 0) {
                            error_log("[AEBG] Associating attachment {$featured_attachment_id} with post {$post_id}");
                            self::associateAttachmentWithPost($featured_attachment_id, $post_id);
                            error_log("[AEBG] Associated attachment {$featured_attachment_id} with post {$post_id}");
                        }
                    }
                    error_log("[AEBG] Completed featured image processing for product {$product_num}");
                } catch (\Throwable $e) {
                    error_log("[AEBG] ERROR downloading featured image for product {$product_num}: {$e->getMessage()}");
                    error_log("[AEBG] ERROR trace: " . $e->getTraceAsString());
                    // Continue with next product - don't halt entire process
                }
            }
            
            // Process gallery images (if available)
            $gallery_images = self::getGalleryImages($product);
            if (!empty($gallery_images)) {
                $gallery_attachment_ids = [];
                $gallery_urls = [];
                
                foreach ($gallery_images as $gallery_index => $gallery_url) {
                    // CRITICAL: Wrap in try-catch to prevent fatal errors from halting execution
                    try {
                        $attachment_id = self::downloadAndInsertImage(
                            $gallery_url,
                            ($product['name'] ?? "Product {$product_num}") . " Gallery " . ($gallery_index + 1),
                            $post_id,
                            "product-{$product_num}-gallery-{$gallery_index}"
                        );
                        
                        if ($attachment_id) {
                            $gallery_attachment_ids[] = $attachment_id;
                            $gallery_urls[] = wp_get_attachment_url($attachment_id);
                        }
                    } catch (\Throwable $e) {
                        error_log("[AEBG] ERROR downloading gallery image {$gallery_index} for product {$product_num}: {$e->getMessage()}");
                        // Continue with next gallery image - don't halt entire process
                    }
                }
                
                if (!empty($gallery_attachment_ids)) {
                    $processed_product['gallery_image_ids'] = $gallery_attachment_ids;
                    $processed_product['gallery_image_urls'] = $gallery_urls;
                    error_log("[AEBG] Downloaded " . count($gallery_attachment_ids) . " gallery images for product {$product_num}");
                }
            }
            
            $processed_products[] = $processed_product;
            error_log("[AEBG] Completed processing product " . ($index + 1) . " of " . count($products));
        }
        
        error_log("[AEBG] processProductImages completed - processed " . count($processed_products) . " products");
        return $processed_products;
    }
    
    /**
     * Download and insert a single image into WordPress media library
     * 
     * @param string $image_url Image URL to download
     * @param string $title Image title
     * @param int $post_id Post ID to associate with
     * @param string $alt_text Alt text for the image
     * @return int|false Attachment ID on success, false on failure
     */
    public static function downloadAndInsertImage($image_url, $title, $post_id = 0, $alt_text = '') {
        if (empty($image_url)) {
            return false;
        }
        
        // Check if image already exists in media library
        $existing_attachment_id = self::getAttachmentByUrl($image_url);
        if ($existing_attachment_id) {
            // error_log("[AEBG] Image already exists in media library: {$image_url} (ID: {$existing_attachment_id})");
            return $existing_attachment_id;
        }
        
        // Download the image
        $upload = self::downloadImage($image_url);
        if (is_wp_error($upload)) {
            $error_message = $upload->get_error_message();
            $error_code = $upload->get_error_code();
            
            // Only log 403 errors briefly (expected when servers block hotlinking)
            // Don't log the full WP_Error object - it's too verbose
            if (strpos($error_message, '403') !== false || strpos($error_message, 'HTTP code: 403') !== false) {
                error_log("[AEBG] Image download blocked (403) for {$image_url} - creating placeholder instead");
            } else {
                // Log other errors more verbosely
                error_log("[AEBG] Failed to download image {$image_url}: {$error_message} (code: {$error_code})");
            }
            
            // Try to create a placeholder image instead
            $placeholder_attachment_id = self::createPlaceholderImage($title, $post_id, $alt_text, $image_url);
            if ($placeholder_attachment_id) {
                error_log("[AEBG] Created placeholder image for failed download: {$image_url}");
                return $placeholder_attachment_id;
            }
            
            return false;
        }
        
        // Prepare attachment data
        $attachment_data = [
            'post_title' => sanitize_text_field($title),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_mime_type' => $upload['type'],
            'post_parent' => $post_id,
        ];
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data, $upload['file'], $post_id);
        
        if (is_wp_error($attachment_id)) {
            error_log("[AEBG] Failed to insert attachment for {$image_url}: " . $attachment_id->get_error_message());
            return false;
        }
        
        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);
        
        // Set alt text
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }
        
        // Store original URL for future reference
        update_post_meta($attachment_id, '_aebg_original_url', esc_url_raw($image_url));
        
        error_log("[AEBG] Successfully downloaded and inserted image: {$image_url} (ID: {$attachment_id})");
        return $attachment_id;
    }
    
    /**
     * Create a placeholder image when download fails
     * 
     * @param string $title Image title
     * @param int $post_id Post ID to associate with
     * @param string $alt_text Alt text for the image
     * @param string $original_url Original URL that failed
     * @return int|false Attachment ID on success, false on failure
     */
    private static function createPlaceholderImage($title, $post_id = 0, $alt_text = '', $original_url = '') {
        // Check if placeholder images are enabled
        if (!self::isPlaceholderImagesEnabled()) {
            error_log("[AEBG] Placeholder images disabled, skipping for failed download: {$original_url}");
            return false;
        }
        
        // Create a simple placeholder image using GD or ImageMagick
        $placeholder_path = self::generatePlaceholderImage($title);
        
        if (!$placeholder_path) {
            error_log("[AEBG] Failed to generate placeholder image for: {$original_url}");
            return false;
        }
        
        // Prepare attachment data
        $attachment_data = [
            'post_title' => sanitize_text_field($title) . ' (Placeholder)',
            'post_content' => 'Placeholder image - original download failed: ' . esc_url($original_url),
            'post_status' => 'inherit',
            'post_mime_type' => 'image/png',
            'post_parent' => $post_id,
        ];
        
        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data, $placeholder_path, $post_id);
        
        if (is_wp_error($attachment_id)) {
            error_log("[AEBG] Failed to insert placeholder attachment: " . $attachment_id->get_error_message());
            return false;
        }
        
        // Generate attachment metadata
        error_log("[AEBG] Generating attachment metadata for placeholder, attachment ID: {$attachment_id}");
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $placeholder_path);
        error_log("[AEBG] Generated attachment metadata for placeholder, updating metadata");
        wp_update_attachment_metadata($attachment_id, $attachment_metadata);
        error_log("[AEBG] Updated attachment metadata for placeholder");
        
        // Set alt text
        if (!empty($alt_text)) {
            error_log("[AEBG] Setting alt text for placeholder attachment {$attachment_id}");
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text) . ' (Placeholder)');
            error_log("[AEBG] Set alt text for placeholder attachment {$attachment_id}");
        }
        
        // Store original URL and mark as placeholder
        error_log("[AEBG] Storing original URL and placeholder flag for attachment {$attachment_id}");
        update_post_meta($attachment_id, '_aebg_original_url', esc_url_raw($original_url));
        update_post_meta($attachment_id, '_aebg_is_placeholder', '1');
        error_log("[AEBG] Stored metadata for placeholder attachment {$attachment_id}");
        
        return $attachment_id;
    }
    
    /**
     * Check if placeholder images are enabled
     * 
     * @return bool True if placeholder images should be created
     */
    private static function isPlaceholderImagesEnabled() {
        // Check WordPress option first
        $option = get_option('aebg_placeholder_images', 'enabled');
        if ($option === 'disabled') {
            return false;
        }
        
        // Check if required extensions are available
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            error_log("[AEBG] No image generation extensions available (GD/ImageMagick), disabling placeholder images");
            return false;
        }
        
        return true;
    }
    
    /**
     * Get image download configuration
     * 
     * @return array Configuration array
     */
    public static function getImageDownloadConfig() {
        return [
            'max_retries' => get_option('aebg_image_max_retries', 3),
            'timeout' => get_option('aebg_image_timeout', 60),
            'placeholder_images' => self::isPlaceholderImagesEnabled(),
            'skip_failed_downloads' => get_option('aebg_skip_failed_downloads', false),
            'log_download_attempts' => get_option('aebg_log_download_attempts', true),
        ];
    }
    
    /**
     * Generate a placeholder image using GD or ImageMagick
     * 
     * @param string $title Text to display on the placeholder
     * @return string|false Path to generated image or false on failure
     */
    private static function generatePlaceholderImage($title) {
        $width = 400;
        $height = 300;
        
        // Try GD first
        if (extension_loaded('gd')) {
            return self::generatePlaceholderWithGD($width, $height, $title);
        }
        
        // Try ImageMagick
        if (extension_loaded('imagick')) {
            return self::generatePlaceholderWithImageMagick($width, $height, $title);
        }
        
        // Fallback: create a simple text file
        return self::createTextPlaceholder($width, $height, $title);
    }
    
    /**
     * Generate placeholder using GD
     */
    private static function generatePlaceholderWithGD($width, $height, $title) {
        $image = imagecreate($width, $height);
        
        // Set colors
        $bg_color = imagecolorallocate($image, 240, 240, 240);
        $text_color = imagecolorallocate($image, 100, 100, 100);
        $border_color = imagecolorallocate($image, 200, 200, 200);
        
        // Fill background
        imagefill($image, 0, 0, $bg_color);
        
        // Draw border
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $border_color);
        
        // Add text
        $font_size = 5;
        $text = substr($title, 0, 30); // Limit text length
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $x = (int) (($width - $text_width) / 2);
        $y = (int) (($height - $text_height) / 2);
        
        imagestring($image, $font_size, $x, $y, $text, $text_color);
        
        // Save image
        $upload_dir = wp_upload_dir();
        $filename = 'placeholder-' . uniqid() . '.png';
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        if (imagepng($image, $filepath)) {
            imagedestroy($image);
            return $filepath;
        }
        
        imagedestroy($image);
        return false;
    }
    
    /**
     * Generate placeholder using ImageMagick
     */
    private static function generatePlaceholderWithImageMagick($width, $height, $title) {
        try {
            $imagick = new Imagick();
            $imagick->newImage($width, $height, new ImagickPixel('#f0f0f0'));
            $imagick->setImageFormat('png');
            
            // Add text
            $draw = new ImagickDraw();
            $draw->setFontSize(16);
            $draw->setFillColor(new ImagickPixel('#646464'));
            $draw->setGravity(Imagick::GRAVITY_CENTER);
            
            $text = substr($title, 0, 30);
            $imagick->annotateImage($draw, 0, 0, 0, $text);
            
            // Save image
            $upload_dir = wp_upload_dir();
            $filename = 'placeholder-' . uniqid() . '.png';
            $filepath = $upload_dir['path'] . '/' . $filename;
            
            $imagick->writeImage($filepath);
            $imagick->destroy();
            
            return $filepath;
        } catch (Exception $e) {
            error_log("[AEBG] ImageMagick placeholder generation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a simple text placeholder file
     */
    private static function createTextPlaceholder($width, $height, $title) {
        $upload_dir = wp_upload_dir();
        $filename = 'placeholder-' . uniqid() . '.txt';
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        $content = "Placeholder Image\n";
        $content .= "Dimensions: {$width}x{$height}\n";
        $content .= "Title: " . substr($title, 0, 50) . "\n";
        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        
        if (file_put_contents($filepath, $content)) {
            return $filepath;
        }
        
        return false;
    }
    
    /**
     * Download image from URL
     * 
     * @param string $image_url Image URL to download
     * @return array|WP_Error Upload data on success, WP_Error on failure
     */
    private static function downloadImage($image_url) {
        // Clean and validate URL
        $image_url = trim($image_url);
        
        // Handle relative URLs by making them absolute
        if (strpos($image_url, 'http') !== 0) {
            if (strpos($image_url, '//') === 0) {
                $image_url = 'https:' . $image_url;
            } else {
                $image_url = 'https://' . $image_url;
            }
        }
        
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new \WP_Error('invalid_url', 'Invalid image URL: ' . $image_url);
        }
        
        // Try multiple download methods
        $download_result = self::tryDownloadMethods($image_url);
        
        if (is_wp_error($download_result)) {
            return $download_result;
        }
        
        // Determine file extension
        $extension = self::getImageExtension($download_result['type']);
        if (!$extension) {
            return new \WP_Error('unsupported_format', 'Unsupported image format: ' . $download_result['type']);
        }
        
        // Generate unique filename
        $filename = 'product-image-' . uniqid() . '.' . $extension;
        
        // Get upload directory
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['path'] . '/' . $filename;
        $upload_url = $upload_dir['url'] . '/' . $filename;
        
        // Write file
        $file_written = file_put_contents($upload_path, $download_result['content']);
        if ($file_written === false) {
            return new \WP_Error('file_write_failed', 'Failed to write file to disk');
        }
        
        return [
            'file' => $upload_path,
            'url' => $upload_url,
            'type' => $download_result['type'],
            'size' => $file_written,
        ];
    }
    
    /**
     * Try multiple download methods to get the image
     * 
     * @param string $image_url Image URL to download
     * @return array|WP_Error Download result on success, WP_Error on failure
     */
    private static function tryDownloadMethods($image_url) {
        // CRITICAL: Set maximum time limit for entire download process
        // Prevents hanging indefinitely if all methods fail
        $process_start = microtime(true);
        $max_process_time = 45; // 45 seconds max for entire download process
        
        // Preprocess the URL to handle common issues
        $processed_urls = self::preprocessImageUrl($image_url);
        
        $methods = [
            'method1' => 'wp_remote_get_with_stream',
            'method2' => 'wp_remote_get_without_stream',
            'method3' => 'wp_remote_get_with_curl_headers',
            'method4' => 'file_get_contents_with_context',
        ];
        
        $all_errors = [];
        
        // Try each processed URL with each method
        foreach ($processed_urls as $url_variant) {
            // error_log("[AEBG] Trying URL variant: {$url_variant}");
            
            foreach ($methods as $method_name => $method_function) {
                // CRITICAL: Check if we've exceeded maximum process time
                $process_elapsed = microtime(true) - $process_start;
                if ($process_elapsed > $max_process_time) {
                    error_log("[AEBG] ⚠️ Image download process exceeded maximum time ({$process_elapsed}s > {$max_process_time}s), aborting");
                    return new \WP_Error('download_timeout', 'Image download process exceeded maximum time');
                }
                
                // error_log("[AEBG] Trying download method: {$method_name} for URL: {$url_variant}");
                $method_start = microtime(true);
                
                $result = self::$method_function($url_variant);
                
                $method_elapsed = microtime(true) - $method_start;
                if ($method_elapsed > 35) {
                    error_log("[AEBG] ⚠️ Download method {$method_name} took {$method_elapsed}s (exceeded 35s limit)");
                }
                
                if (!is_wp_error($result) && !empty($result['content'])) {
                    // error_log("[AEBG] Download method {$method_name} succeeded for URL: {$url_variant} (took " . round($method_elapsed, 2) . "s)");
                    return $result;
                }
                
                if (is_wp_error($result)) {
                    $error_msg = $result->get_error_message();
                    $all_errors[] = "Method {$method_name} failed: {$error_msg}";
                    // error_log("[AEBG] Download method {$method_name} failed: {$error_msg} (took " . round($method_elapsed, 2) . "s)");
                } else {
                    $all_errors[] = "Method {$method_name} returned empty content";
                    // error_log("[AEBG] Download method {$method_name} returned empty content (took " . round($method_elapsed, 2) . "s)");
                }
            }
        }
        
        // Try fallback methods if all standard methods failed
        $fallback_result = self::tryFallbackMethods($image_url);
        if (!is_wp_error($fallback_result) && !empty($fallback_result['content'])) {
            // error_log("[AEBG] Fallback method succeeded for URL: {$image_url}");
            return $fallback_result;
        }
        
        // Log all errors for debugging (but reduce verbosity for 403 errors)
        $error_summary = implode('; ', $all_errors);
        $is_403_error = strpos($error_summary, '403') !== false || strpos($error_summary, 'HTTP code: 403') !== false;
        
        if ($is_403_error) {
            // 403 errors are expected when servers block hotlinking - log briefly
            error_log("[AEBG] Image download blocked (403) for {$image_url} - all methods failed");
        } else {
            // Log other errors more verbosely
            error_log("[AEBG] All download methods failed for URL: {$image_url}. Errors: {$error_summary}");
        }
        
        return new \WP_Error('all_download_methods_failed', 'All download methods failed for URL: ' . $image_url . '. Errors: ' . $error_summary);
    }
    
    /**
     * Try fallback methods for problematic URLs
     * 
     * @param string $image_url Image URL to download
     * @return array|WP_Error Download result on success, WP_Error on failure
     */
    private static function tryFallbackMethods($image_url) {
        // Method 1: Try with cURL directly if available
        if (function_exists('curl_init')) {
            $result = self::curlDownload($image_url);
            if (!is_wp_error($result) && !empty($result['content'])) {
                return $result;
            }
        }
        
        // Method 2: Try with different User-Agent
        $result = self::wp_remote_get_with_different_user_agent($image_url);
        if (!is_wp_error($result) && !empty($result['content'])) {
            return $result;
        }
        
        // Method 3: Try with longer timeout
        $result = self::wp_remote_get_with_longer_timeout($image_url);
        if (!is_wp_error($result) && !empty($result['content'])) {
            return $result;
        }
        
        return new \WP_Error('fallback_failed', 'All fallback methods failed');
    }
    
    /**
     * Fallback method: cURL download
     */
    private static function curlDownload($image_url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $image_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
            ],
        ]);
        
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return new \WP_Error('curl_error', 'cURL error: ' . $error);
        }
        
        if ($http_code !== 200) {
            return new \WP_Error('http_error', 'HTTP error: ' . $http_code);
        }
        
        if (empty($content)) {
            return new \WP_Error('empty_content', 'cURL returned empty content');
        }
        
        if (!$content_type || strpos($content_type, 'image/') !== 0) {
            return new \WP_Error('invalid_content_type', 'Invalid content type: ' . $content_type);
        }
        
        return [
            'content' => $content,
            'type' => $content_type,
        ];
    }
    
    /**
     * Fallback method: Different User-Agent
     */
    private static function wp_remote_get_with_different_user_agent($image_url) {
        $user_agents = [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
        ];
        
        foreach ($user_agents as $user_agent) {
            $response = wp_remote_get($image_url, [
                'timeout' => 60,
                'stream' => false,
                'user-agent' => $user_agent,
                'headers' => [
                    'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Connection' => 'close', // Force connection close to prevent reuse
                ],
                'cookies' => false, // Don't send cookies (fresh request)
            ]);
            
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code === 200) {
                    $content_type = wp_remote_retrieve_header($response, 'content-type');
                    if ($content_type && strpos($content_type, 'image/') === 0) {
                        $file_content = wp_remote_retrieve_body($response);
                        if (!empty($file_content)) {
                            return [
                                'content' => $file_content,
                                'type' => $content_type,
                            ];
                        }
                    }
                }
            }
        }
        
        return new \WP_Error('user_agent_failed', 'All User-Agent variants failed');
    }
    
    /**
     * Fallback method: Longer timeout
     */
    private static function wp_remote_get_with_longer_timeout($image_url) {
        $response = wp_remote_get($image_url, [
            'timeout' => 120, // 2 minutes
            'stream' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => [
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Connection' => 'close', // Force connection close to prevent reuse
            ],
            'cookies' => false, // Don't send cookies (fresh request)
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new \WP_Error('download_failed', 'Failed to download image. HTTP code: ' . $response_code);
        }
        
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type || strpos($content_type, 'image/') !== 0) {
            return new \WP_Error('invalid_content_type', 'URL does not point to an image: ' . $content_type);
        }
        
        $file_content = wp_remote_retrieve_body($response);
        if (empty($file_content)) {
            return new \WP_Error('empty_content', 'Downloaded file is empty');
        }
        
        return [
            'content' => $file_content,
            'type' => $content_type,
        ];
    }
    
    /**
     * Preprocess image URL to handle common issues
     * 
     * @param string $original_url Original image URL
     * @return array Array of URL variants to try
     */
    private static function preprocessImageUrl($original_url) {
        $url_variants = [$original_url];
        
        // Handle ProductServe URLs with complex parameters
        if (strpos($original_url, 'productserve.com') !== false) {
            // Extract the actual image URL from the productserve parameters
            if (preg_match('/url=([^&]+)/', $original_url, $matches)) {
                $extracted_url = urldecode($matches[1]);
                if (strpos($extracted_url, 'http') !== 0) {
                    $extracted_url = 'https://' . ltrim($extracted_url, '/');
                }
                $url_variants[] = $extracted_url;
                error_log("[AEBG] Extracted ProductServe URL: {$extracted_url}");
            }
            
            // Try without some parameters
            $clean_url = preg_replace('/\?.*$/', '', $original_url);
            if ($clean_url !== $original_url) {
                $url_variants[] = $clean_url;
            }
        }
        
        // Handle Fyndiq URLs
        if (strpos($original_url, 'fyndiq.se') !== false) {
            // Try with different image sizes
            $size_variants = ['t_800x800', 't_1200x1200', 't_400x400'];
            foreach ($size_variants as $size) {
                $variant = preg_replace('/t_\d+x\d+/', $size, $original_url);
                if ($variant !== $original_url) {
                    $url_variants[] = $variant;
                }
            }
            
            // Try without size parameter
            $no_size_url = preg_replace('/\/t_\d+x\d+\//', '/', $original_url);
            if ($no_size_url !== $original_url) {
                $url_variants[] = $no_size_url;
            }
        }
        
        // Handle URLs with SSL protocol issues
        if (strpos($original_url, 'ssl:') !== false) {
            $https_url = str_replace('ssl:', 'https://', $original_url);
            $url_variants[] = $https_url;
        }
        
        // Remove duplicate URLs
        $url_variants = array_unique($url_variants);
        
            // error_log("[AEBG] Generated " . count($url_variants) . " URL variants for: {$original_url}");
        
        return $url_variants;
    }
    
    /**
     * Download method 1: wp_remote_get with stream
     */
    private static function wp_remote_get_with_stream($image_url) {
        // CRITICAL: Use shorter timeout and add timeout protection
        // wp_remote_get can hang even with timeout set, especially after many requests
        $download_start = microtime(true);
        $max_download_time = 30; // 30 seconds max for image downloads
        
        $response = wp_remote_get($image_url, [
            'timeout' => $max_download_time,
            'stream' => true,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => [
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Connection' => 'close', // Force connection close to prevent reuse
            ],
            // CRITICAL: Force fresh connection to prevent hanging
            'reject_unsafe_urls' => false,
            'cookies' => false, // Don't send cookies (fresh request)
        ]);
        
        // CRITICAL: Check if download took too long (watchdog)
        $download_elapsed = microtime(true) - $download_start;
        if ($download_elapsed > ($max_download_time + 5)) {
            error_log("[AEBG] ⚠️ Image download exceeded timeout ({$download_elapsed}s > {$max_download_time}s) for: {$image_url}");
            return new \WP_Error('download_timeout', 'Image download exceeded timeout');
        }
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new \WP_Error('download_failed', 'Failed to download image. HTTP code: ' . $response_code);
        }
        
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type || strpos($content_type, 'image/') !== 0) {
            return new \WP_Error('invalid_content_type', 'URL does not point to an image: ' . $content_type);
        }
        
        $file_content = wp_remote_retrieve_body($response);
        if (empty($file_content)) {
            return new \WP_Error('empty_content', 'Downloaded file is empty');
        }
        
        return [
            'content' => $file_content,
            'type' => $content_type,
        ];
    }
    
    /**
     * Download method 2: wp_remote_get without stream
     */
    private static function wp_remote_get_without_stream($image_url) {
        // CRITICAL: Use shorter timeout and add timeout protection
        $download_start = microtime(true);
        $max_download_time = 30; // 30 seconds max for image downloads
        
        $response = wp_remote_get($image_url, [
            'timeout' => $max_download_time,
            'stream' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => [
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Connection' => 'close', // Force connection close to prevent reuse
            ],
            'reject_unsafe_urls' => false,
            'cookies' => false, // Don't send cookies (fresh request)
        ]);
        
        // CRITICAL: Check if download took too long (watchdog)
        $download_elapsed = microtime(true) - $download_start;
        if ($download_elapsed > ($max_download_time + 5)) {
            error_log("[AEBG] ⚠️ Image download exceeded timeout ({$download_elapsed}s > {$max_download_time}s) for: {$image_url}");
            return new \WP_Error('download_timeout', 'Image download exceeded timeout');
        }
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new \WP_Error('download_failed', 'Failed to download image. HTTP code: ' . $response_code);
        }
        
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type || strpos($content_type, 'image/') !== 0) {
            return new \WP_Error('invalid_content_type', 'URL does not point to an image: ' . $content_type);
        }
        
        $file_content = wp_remote_retrieve_body($response);
        if (empty($file_content)) {
            return new \WP_Error('empty_content', 'Downloaded file is empty');
        }
        
        return [
            'content' => $file_content,
            'type' => $content_type,
        ];
    }
    
    /**
     * Download method 3: wp_remote_get with cURL-style headers
     */
    private static function wp_remote_get_with_curl_headers($image_url) {
        // CRITICAL: Use shorter timeout and add timeout protection
        $download_start = microtime(true);
        $max_download_time = 30; // 30 seconds max for image downloads
        
        $response = wp_remote_get($image_url, [
            'timeout' => $max_download_time,
            'stream' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => [
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Referer' => home_url(),
                'DNT' => '1',
                'Connection' => 'close', // Force connection close to prevent reuse
            ],
            'sslverify' => false,
            'redirection' => 5,
            'reject_unsafe_urls' => false,
            'cookies' => false, // Don't send cookies (fresh request)
        ]);
        
        // CRITICAL: Check if download took too long (watchdog)
        $download_elapsed = microtime(true) - $download_start;
        if ($download_elapsed > ($max_download_time + 5)) {
            error_log("[AEBG] ⚠️ Image download exceeded timeout ({$download_elapsed}s > {$max_download_time}s) for: {$image_url}");
            return new \WP_Error('download_timeout', 'Image download exceeded timeout');
        }
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new \WP_Error('download_failed', 'Failed to download image. HTTP code: ' . $response_code);
        }
        
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!$content_type || strpos($content_type, 'image/') !== 0) {
            return new \WP_Error('invalid_content_type', 'URL does not point to an image: ' . $content_type);
        }
        
        $file_content = wp_remote_retrieve_body($response);
        if (empty($file_content)) {
            return new \WP_Error('empty_content', 'Downloaded file is empty');
        }
        
        return [
            'content' => $file_content,
            'type' => $content_type,
        ];
    }
    
    /**
     * Download method 4: file_get_contents with context
     */
    private static function file_get_contents_with_context($image_url) {
        // Check if allow_url_fopen is enabled
        if (!ini_get('allow_url_fopen')) {
            return new \WP_Error('allow_url_fopen_disabled', 'allow_url_fopen is disabled');
        }
        
        // CRITICAL: Use shorter timeout and add timeout protection
        $download_start = microtime(true);
        $max_download_time = 30; // 30 seconds max for image downloads
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Cache-Control: no-cache',
                ],
                'timeout' => $max_download_time,
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        
        $file_content = @file_get_contents($image_url, false, $context);
        
        // CRITICAL: Check if download took too long (watchdog)
        $download_elapsed = microtime(true) - $download_start;
        if ($download_elapsed > ($max_download_time + 5)) {
            error_log("[AEBG] ⚠️ Image download exceeded timeout ({$download_elapsed}s > {$max_download_time}s) for: {$image_url}");
            return new \WP_Error('download_timeout', 'Image download exceeded timeout');
        }
        
        if ($file_content === false) {
            return new \WP_Error('file_get_contents_failed', 'file_get_contents failed for URL: ' . $image_url);
        }
        
        if (empty($file_content)) {
            return new \WP_Error('empty_content', 'Downloaded file is empty');
        }
        
        // Try to determine content type from URL or content
        $content_type = 'image/jpeg'; // Default fallback
        
        // Check if we can get content type from headers
        if (function_exists('get_headers')) {
            $headers = @get_headers($image_url, 1);
            if ($headers && isset($headers['Content-Type'])) {
                $ct = is_array($headers['Content-Type']) ? $headers['Content-Type'][0] : $headers['Content-Type'];
                if (strpos($ct, 'image/') === 0) {
                    $content_type = $ct;
                }
            }
        }
        
        return [
            'content' => $file_content,
            'type' => $content_type,
        ];
    }
    
    /**
     * Get image extension from content type
     * 
     * @param string $content_type Content type
     * @return string|false File extension or false if unsupported
     */
    private static function getImageExtension($content_type) {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        
        return $extensions[$content_type] ?? false;
    }
    
    /**
     * Check if image already exists in media library by URL
     * 
     * @param string $image_url Image URL to check
     * @return int|false Attachment ID if found, false otherwise
     */
    private static function getAttachmentByUrl($image_url) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_aebg_original_url' 
            AND meta_value = %s",
            $image_url
        ));
        
        return $attachment_id ? (int) $attachment_id : false;
    }
    
    /**
     * Get gallery images from product data
     * 
     * @param array $product Product data
     * @return array Array of gallery image URLs
     */
    private static function getGalleryImages($product) {
        $gallery_images = [];
        
        // Check for gallery_images field
        if (!empty($product['gallery_images']) && is_array($product['gallery_images'])) {
            $gallery_images = array_merge($gallery_images, $product['gallery_images']);
        }
        
        // Check for additional_images field
        if (!empty($product['additional_images']) && is_array($product['additional_images'])) {
            $gallery_images = array_merge($gallery_images, $product['additional_images']);
        }
        
        // Check for images field
        if (!empty($product['images']) && is_array($product['images'])) {
            $gallery_images = array_merge($gallery_images, $product['images']);
        }
        
        // Remove duplicates and empty values
        $gallery_images = array_filter(array_unique($gallery_images));
        
        // Limit to reasonable number of images
        return array_slice($gallery_images, 0, 10);
    }
    
    /**
     * Get product image variables for replacement
     * 
     * @param array $products Processed products with attachment IDs
     * @return array Array of variables for replacement
     */
    public static function getProductImageVariables($products) {
        $variables = [];
        
        foreach ($products as $index => $product) {
            $product_num = $index + 1;
            
            // Featured image variables
            if (!empty($product['featured_image_id'])) {
                $variables["product-{$product_num}-featured-image"] = wp_get_attachment_url($product['featured_image_id']);
                $variables["product-{$product_num}-featured-image-id"] = $product['featured_image_id'];
                $variables["product-{$product_num}-featured-image-html"] = wp_get_attachment_image($product['featured_image_id'], 'full');
            }
            
            // Gallery images variables
            if (!empty($product['gallery_image_ids']) && is_array($product['gallery_image_ids'])) {
                $gallery_html = '';
                foreach ($product['gallery_image_ids'] as $gallery_id) {
                    $gallery_html .= wp_get_attachment_image($gallery_id, 'medium') . "\n";
                }
                
                $variables["product-{$product_num}-gallery-images"] = implode(',', $product['gallery_image_urls']);
                $variables["product-{$product_num}-gallery-images-ids"] = implode(',', $product['gallery_image_ids']);
                $variables["product-{$product_num}-gallery-images-html"] = $gallery_html;
            }
        }
        
        return $variables;
    }
    
    /**
     * Clean up orphaned images (optional maintenance function)
     * 
     * @param int $days_old Remove images older than this many days
     * @return int Number of images removed
     */
    public static function cleanupOrphanedImages($days_old = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        
        $orphaned_attachments = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND pm.meta_key = '_aebg_original_url'
            AND p.post_date < %s
            AND p.post_parent = 0",
            $cutoff_date
        ));
        
        $removed_count = 0;
        foreach ($orphaned_attachments as $attachment_id) {
            if (wp_delete_attachment($attachment_id, true)) {
                $removed_count++;
            }
        }
        
        error_log("[AEBG] Cleaned up {$removed_count} orphaned product images");
        return $removed_count;
    }
    
    /**
     * Associate an attachment with a post
     * 
     * @param int $attachment_id Attachment ID
     * @param int $post_id Post ID
     * @return bool Success status
     */
    private static function associateAttachmentWithPost($attachment_id, $post_id) {
        if (!$attachment_id || !$post_id) {
            return false;
        }
        
        $result = wp_update_post([
            'ID' => $attachment_id,
            'post_parent' => $post_id
        ]);
        
        if (is_wp_error($result)) {
            error_log("[AEBG] Failed to associate attachment {$attachment_id} with post {$post_id}: " . $result->get_error_message());
            return false;
        }
        
        error_log("[AEBG] Successfully associated attachment {$attachment_id} with post {$post_id}");
        return true;
    }
} 