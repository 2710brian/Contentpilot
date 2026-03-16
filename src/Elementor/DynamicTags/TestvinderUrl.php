<?php

namespace AEBG\Elementor\DynamicTags;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;
use AEBG\Core\TestvinderHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * AEBG Testvinder URL Dynamic Tag (Raw URL, no affiliate processing)
 */
class TestvinderUrl extends Data_Tag {
    
    public function get_name() {
        return 'aebg-testvinder-url';
    }
    
    public function get_title() {
        return esc_html__( 'AEBG Testvinder URL', 'aebg' );
    }
    
    public function get_group() {
        return 'aebg';
    }
    
    public function get_categories() {
        return [ Module::URL_CATEGORY ];
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
    
    public function get_value( array $options = [] ) {
        $post_id = $this->determine_post_id( $options );
        
        if ( ! $post_id ) {
            return '';
        }
        
        $testvinder_number = $this->get_settings( 'testvinder_number' ) ?? 1;
        $data = TestvinderHelper::get_testvinder_data( $post_id, $testvinder_number );
        
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

