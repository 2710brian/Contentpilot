<?php

namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;
use AEBG\Core\ProductHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * AEBG Product URL Dynamic Tag (Raw URL, no affiliate processing)
 */
class ProductUrl extends Data_Tag {
    
    public function get_name() {
        return 'aebg-product-url';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Product URL', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::URL_CATEGORY ];
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
            return '';
        }
        
        $product_number = $this->get_settings( 'product_number' ) ?? 1;
        $data = ProductHelper::get_product_data_by_number( $post_id, $product_number );
        
        if ( empty( $data ) || empty( $data['url'] ) ) {
            return '';
        }
        
        // Return raw URL (no affiliate processing) as string
        return esc_url( $data['url'] );
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

