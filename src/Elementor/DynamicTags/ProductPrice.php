<?php

namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use AEBG\Core\ProductHelper;
use AEBG\Core\CurrencyManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * AEBG Product Price Dynamic Tag
 */
class ProductPrice extends Tag {
    
    public function get_name() {
        return 'aebg-product-price';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Product Price', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::TEXT_CATEGORY ];
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
    
    public function render() {
        $post_id = $this->get_post_id();
        if ( ! $post_id ) {
            return;
        }
        
        $product_number = $this->get_settings( 'product_number' ) ?? 1;
        $data = ProductHelper::get_product_data_by_number( $post_id, $product_number );
        
        if ( empty( $data ) || empty( $data['price'] ) ) {
            return;
        }
        
        $price = CurrencyManager::formatPrice( $data['price'], $data['currency'] ?? 'DKK' );
        echo esc_html( $price );
    }
    
    /**
     * Get post ID, handling Elementor editor context
     */
    protected function get_post_id() {
        $post_id = get_the_ID();
        
        // Handle Elementor editor context
        if ( ! $post_id && class_exists( '\Elementor\Plugin' ) ) {
            $elementor = \Elementor\Plugin::instance();
            if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) ) {
                if ( $elementor->editor->is_edit_mode() ) {
                    $post_id = $elementor->editor->get_post_id();
                }
            }
        }
        
        return $post_id;
    }
}

