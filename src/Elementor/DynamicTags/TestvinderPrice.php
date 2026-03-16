<?php

namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use AEBG\Core\TestvinderHelper;
use AEBG\Core\CurrencyManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * AEBG Testvinder Price Dynamic Tag
 */
class TestvinderPrice extends Tag {
    
    public function get_name() {
        return 'aebg-testvinder-price';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Testvinder Price', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::TEXT_CATEGORY ];
    }
    
    protected function register_controls() {
        $this->add_control(
            'testvinder_number',
            [
                'label' => esc_html__( 'Testvinder Number', 'aebg' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 1,
                'min' => 1,
                'max' => 10,
                'description' => esc_html__( 'Which testvinder to display (1, 2, 3, etc.)', 'aebg' ),
            ]
        );
    }
    
    public function render() {
        $post_id = $this->get_post_id();
        if ( ! $post_id ) {
            return;
        }
        
        $testvinder_number = $this->get_settings( 'testvinder_number' ) ?? 1;
        $data = TestvinderHelper::get_testvinder_data( $post_id, $testvinder_number );
        
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

