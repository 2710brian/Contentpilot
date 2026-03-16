<?php

namespace AEBG\Core;

use AEBG\Core\TemplateManager;
use AEBG\Core\ProductManager;
use AEBG\Core\Shortcodes;

/**
 * Testvinder Helper Class
 * 
 * Extracts testvinder data from Elementor containers for use in Dynamic Tags
 */
class TestvinderHelper {
    
    /**
     * Get testvinder data for a specific testvinder number
     * 
     * @param int $post_id Post ID
     * @param int $testvinder_number Testvinder number (1, 2, 3, etc.)
     * @return array|null Testvinder product data or null if not found
     */
    public static function get_testvinder_data( $post_id, $testvinder_number = 1 ) {
        if ( ! $post_id ) {
            return null;
        }
        
        $template_manager = new TemplateManager();
        $elementor_data = $template_manager->getElementorData( $post_id );
        
        if ( is_wp_error( $elementor_data ) ) {
            return null;
        }
        
        // Find testvinder container
        $testvinder_container = self::find_testvinder_container( $elementor_data, $testvinder_number );
        
        if ( empty( $testvinder_container ) ) {
            return null;
        }
        
        // Get products for this post
        $products = ProductManager::getPostProducts( $post_id );
        
        if ( empty( $products ) ) {
            return null;
        }
        
        // For testvinder-1, use first product; testvinder-2 uses second, etc.
        $product_index = (int) $testvinder_number - 1;
        
        if ( ! isset( $products[ $product_index ] ) ) {
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
            'rating' => $product['rating'] ?? '',
            'price' => $product['price'] ?? 0,
            'currency' => $product['currency'] ?? 'DKK',
            'affiliate_url' => $affiliate_url,
            'url' => $product['url'] ?? '',
            'description' => $product['description'] ?? '',
            'brand' => $product['brand'] ?? '',
            'merchant' => $product['merchant'] ?? '',
        ];
    }
    
    /**
     * Find testvinder container in Elementor data
     */
    protected static function find_testvinder_container( $elementor_data, $testvinder_number ) {
        $target_id = 'testvinder-' . intval( $testvinder_number );
        return self::recursive_find_container( $elementor_data, $target_id );
    }
    
    /**
     * Recursively search for container with specific CSS ID
     */
    protected static function recursive_find_container( $elements, $target_id ) {
        if ( ! is_array( $elements ) ) {
            return null;
        }
        
        // Handle numeric array (top-level array of elements)
        if ( array_keys( $elements ) === range( 0, count( $elements ) - 1 ) ) {
            foreach ( $elements as $item ) {
                $found = self::recursive_find_container( $item, $target_id );
                if ( $found !== null ) {
                    return $found;
                }
            }
            return null;
        }
        
        // Check if this element has the target CSS ID
        if ( isset( $elements['settings'] ) && is_array( $elements['settings'] ) ) {
            $css_id = $elements['settings']['_element_id'] ?? $elements['settings']['_css_id'] ?? $elements['settings']['css_id'] ?? '';
            
            if ( $css_id === $target_id ) {
                return $elements;
            }
        }
        
        // Recursively search children
        if ( isset( $elements['elements'] ) && is_array( $elements['elements'] ) ) {
            foreach ( $elements['elements'] as $element ) {
                $found = self::recursive_find_container( $element, $target_id );
                if ( $found !== null ) {
                    return $found;
                }
            }
        }
        
        if ( isset( $elements['content'] ) && is_array( $elements['content'] ) ) {
            foreach ( $elements['content'] as $content_item ) {
                $found = self::recursive_find_container( $content_item, $target_id );
                if ( $found !== null ) {
                    return $found;
                }
            }
        }
        
        return null;
    }
}

