<?php

namespace AEBG\Core;

use AEBG\Core\ProductManager;
use AEBG\Core\Shortcodes;

/**
 * Product Helper Class
 * 
 * Extracts product data for use in Dynamic Tags
 */
class ProductHelper {
    
    /**
     * Get product data by index (0-based)
     * 
     * @param int $post_id Post ID
     * @param int $product_index Product index (0 = first product, 1 = second, etc.)
     * @return array|null Product data or null if not found
     */
    public static function get_product_data( $post_id, $product_index = 0 ) {
        if ( ! $post_id ) {
            return null;
        }
        
        // Get products for this post
        $products = ProductManager::getPostProducts( $post_id );
        
        if ( empty( $products ) || ! isset( $products[ $product_index ] ) ) {
            return null;
        }
        
        $product = $products[ $product_index ];
        
        // Process affiliate link
        $shortcodes = new Shortcodes();
        $affiliate_url = $shortcodes->get_affiliate_link_for_url(
            $product['affiliate_url'] ?? $product['url'] ?? '',
            $product['merchant'] ?? '',
            $product['network'] ?? ''
        );
        
        // Get image URL and ID
        $image_url = '';
        $image_id = '';
        
        // Prefer featured_image_id if available (for WordPress attachments)
        if ( ! empty( $product['featured_image_id'] ) ) {
            $image_id = (int) $product['featured_image_id'];
            $image_url = wp_get_attachment_url( $image_id );
            // Fallback to featured_image_url if wp_get_attachment_url fails
            if ( ! $image_url ) {
                $image_url = $product['featured_image_url'] ?? '';
            }
        } else {
            // Use URL if no attachment ID
            $image_url = $product['featured_image_url'] ?? $product['image_url'] ?? '';
        }
        
        return [
            'name' => $product['name'] ?? '',
            'image' => $image_url,
            'image_id' => $image_id,
            'price' => $product['price'] ?? 0,
            'currency' => $product['currency'] ?? 'DKK',
            'affiliate_url' => $affiliate_url,
            'url' => $product['url'] ?? '',
            'description' => $product['description'] ?? '',
            'brand' => $product['brand'] ?? '',
            'merchant' => $product['merchant'] ?? '',
            'rating' => $product['rating'] ?? '',
        ];
    }
    
    /**
     * Get product data by product number (1-based)
     * 
     * @param int $post_id Post ID
     * @param int $product_number Product number (1 = first product, 2 = second, etc.)
     * @return array|null Product data or null if not found
     */
    public static function get_product_data_by_number( $post_id, $product_number = 1 ) {
        $product_index = (int) $product_number - 1;
        return self::get_product_data( $post_id, $product_index );
    }
}

