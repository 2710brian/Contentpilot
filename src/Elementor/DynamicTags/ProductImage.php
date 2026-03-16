<?php

namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;
use Elementor\Utils;
use AEBG\Core\ProductHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * AEBG Product Image Dynamic Tag
 */
class ProductImage extends Data_Tag {
    
    public function get_name() {
        return 'aebg-product-image';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Product Image', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::IMAGE_CATEGORY ];
    }
    
    protected function register_controls() {
        $this->add_control(
            'product_number',
            [
                'label' => esc_html__( 'Product Number', 'aebg' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 1,
                'min' => 1,
                'max' => 20,
                'description' => esc_html__( 'Which product to display (1 = first product, 2 = second, etc.)', 'aebg' ),
            ]
        );
    }
    
    public function get_value( array $options = [] ) {
        $post_id = $this->determine_post_id( $options );
        
        if ( ! $post_id ) {
            return $this->image_array( Utils::get_placeholder_image_src() );
        }
        
        $product_number = $this->get_settings( 'product_number' ) ?? 1;
        $data = ProductHelper::get_product_data_by_number( $post_id, $product_number );
        
        if ( empty( $data ) || empty( $data['image'] ) ) {
            return $this->image_array( Utils::get_placeholder_image_src() );
        }
        
        // Get image URL - prefer attachment URL if we have an ID
        $image_url = '';
        $image_id = null;
        
        if ( ! empty( $data['image_id'] ) ) {
            $attachment_id = (int) $data['image_id'];
            $attachment_url = wp_get_attachment_url( $attachment_id );
            if ( $attachment_url ) {
                // Valid WordPress attachment - use real ID
                return [
                    'id'  => $attachment_id,
                    'url' => esc_url( $attachment_url ),
                ];
            }
        }
        
        // Use image URL from data (external or local)
        $image_url = $data['image'];
        
        // Validate URL
        if ( empty( $image_url ) || ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
            return $this->image_array( Utils::get_placeholder_image_src() );
        }
        
        // Return with synthetic ID for external images
        return $this->image_array( $image_url );
    }
    
    /**
     * Create image array with synthetic ID for external images
     */
    private function image_array( string $url ): array {
        if ( empty( $url ) ) {
            $url = Utils::get_placeholder_image_src();
        }
        
        // Generate a synthetic negative ID to make Elementor happy with external images
        $synthetic_id = -abs( crc32( $url ) );
        
        // Ensure global synthetic images array is set
        global $aebg_synthetic_images;
        if ( ! isset( $aebg_synthetic_images ) ) {
            $aebg_synthetic_images = [];
        }
        
        $aebg_synthetic_images[ $synthetic_id ] = esc_url( $url );
        
        return [
            'id'  => $synthetic_id,
            'url' => esc_url( $url ),
        ];
    }
    
    /**
     * Determine post ID, handling Elementor editor and frontend contexts
     */
    protected function determine_post_id( array $options = [] ) {
        global $post;
        
        // Check options first (from Elementor context - works on both frontend and editor)
        if ( isset( $options['post_id'] ) && (int) $options['post_id'] > 0 ) {
            return (int) $options['post_id'];
        }
        
        // Check get_the_ID() - works on frontend
        $post_id = get_the_ID();
        if ( $post_id ) {
            return (int) $post_id;
        }
        
        // Check global post object
        if ( ! empty( $post->ID ) ) {
            return (int) $post->ID;
        }
        
        // Check Elementor editor context
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $elementor = \Elementor\Plugin::instance();
            
            // Try to get from current document (works in editor and frontend preview)
            if ( isset( $elementor->documents ) ) {
                $doc = $elementor->documents->get_current();
                if ( $doc && method_exists( $doc, 'get_main_id' ) ) {
                    $doc_id = $doc->get_main_id();
                    if ( $doc_id ) {
                        return (int) $doc_id;
                    }
                }
            }
            
            // Fallback to editor post ID
            if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
                if ( $elementor->editor->is_edit_mode() && method_exists( $elementor->editor, 'get_post_id' ) ) {
                    $editor_post_id = $elementor->editor->get_post_id();
                    if ( $editor_post_id ) {
                        return (int) $editor_post_id;
                    }
                }
            }
        }
        
        return null;
    }
}



