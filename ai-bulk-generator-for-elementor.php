<?php
/**
 * Plugin Name: AI Bulk Generator for Elementor
 * Plugin URI: https://nicohansen.com/
 * Description: A comprehensive WordPress plugin that leverages OpenAI's GPT models to automatically generate high-quality, SEO-optimized blog posts, pages, and custom content at scale. Features seamless Elementor integration, intelligent product recommendations via Datafeedr API, AI-powered image generation with DALL-E, bulk batch processing, competitor tracking, affiliate network management, and dynamic price comparison tables. Perfect for content creators, affiliate marketers, and businesses looking to scale their content production efficiently.
 * Version: 1.0.0
 * Author: Nicolai Hansen
 * Author URI: https://nicohansen.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aebg
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define plugin constants
// Use static version in production, append timestamp only in development
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	define( 'AEBG_VERSION', '1.0.2.' . time() );
} else {
	define( 'AEBG_VERSION', '1.0.2' );
}
define( 'AEBG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AEBG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoloader with fallback
$autoloader_path = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoloader_path ) ) {
    require_once $autoloader_path;
} else {
    // Fallback autoloader if Composer autoloader is not available
    spl_autoload_register( function ( $class ) {
        // Only handle AEBG namespace
        if ( strpos( $class, 'AEBG\\' ) !== 0 ) {
            return;
        }
        
        // Convert namespace to file path
        $class_path = str_replace( 'AEBG\\', '', $class );
        $class_path = str_replace( '\\', '/', $class_path );
        $file_path = __DIR__ . '/src/' . $class_path . '.php';
        
        if ( file_exists( $file_path ) ) {
            require_once $file_path;
        }
    } );
}

/**
 * The main function to retrieve the plugin instance.
 * Ensures the plugin is a singleton.
 */
function aebg() {
    // Check if the Plugin class exists before trying to instantiate it
    if ( ! class_exists( 'AEBG\\Plugin' ) ) {
        // Try to load the Plugin class manually
        $plugin_file = __DIR__ . '/src/Plugin.php';
        if ( file_exists( $plugin_file ) ) {
            require_once $plugin_file;
        } else {
            // Log the error and show a user-friendly message
            error_log( '[AEBG] Plugin class file not found at: ' . $plugin_file );
            wp_die( 'AEBG Plugin class not found. Please ensure the plugin files are properly installed. File path: ' . $plugin_file );
        }
    }
    
    // Double-check if the class exists after loading
    if ( ! class_exists( 'AEBG\\Plugin' ) ) {
        error_log( '[AEBG] Plugin class still not found after loading file' );
        wp_die( 'AEBG Plugin class could not be loaded. Please check the plugin installation.' );
    }
    
    try {
        return \AEBG\Plugin::instance();
    } catch ( \Exception $e ) {
        error_log( '[AEBG] Error instantiating Plugin class: ' . $e->getMessage() );
        wp_die( 'AEBG Plugin could not be initialized: ' . $e->getMessage() );
    }
}

// Initialize the plugin with error handling
try {
    aebg();
} catch ( \Exception $e ) {
    error_log( '[AEBG] Error during plugin initialization: ' . $e->getMessage() );
    // Don't die here, just log the error to prevent breaking the site
}

// Activation hook
register_activation_hook( __FILE__, function() {
    try {
        $installer = new \AEBG\Installer();
        $installer->activate();
    } catch ( \Exception $e ) {
        error_log( '[AEBG] Error during plugin activation: ' . $e->getMessage() );
    }
} );

// Deactivation hook
register_deactivation_hook( __FILE__, function() {
    try {
        $installer = new \AEBG\Installer();
        $installer->deactivate();
    } catch ( \Exception $e ) {
        error_log( '[AEBG] Error during plugin deactivation: ' . $e->getMessage() );
    }
} );

// Include migration tool activation
require_once __DIR__ . '/activate-migration-tool.php';

/**
 * Handle Elementor Cloud Library 403 errors gracefully
 * This prevents fatal errors from blocking Dynamic Tags registration
 */
add_action( 'init', function() {
    // Catch Elementor Cloud Library 403 errors during initialization
    if ( class_exists( '\Elementor\Plugin' ) ) {
        try {
            // Try to access Elementor instance - this will trigger Cloud Library initialization
            $elementor = \Elementor\Plugin::instance();
        } catch ( \WpOrg\Requests\Exception\Http\Status403 $e ) {
            // Elementor Cloud Library 403 error - log but don't die
            error_log( '[AEBG] ⚠️ Elementor Cloud Library 403 error caught (non-critical): ' . $e->getMessage() );
            // Set a flag so we know Elementor had an issue but can still function
            if ( ! defined( 'AEBG_ELEMENTOR_CLOUD_LIBRARY_ERROR' ) ) {
                define( 'AEBG_ELEMENTOR_CLOUD_LIBRARY_ERROR', true );
            }
        } catch ( \Exception $e ) {
            // Other Elementor initialization errors
            error_log( '[AEBG] ⚠️ Elementor initialization error (non-critical): ' . $e->getMessage() );
        } catch ( \Error $e ) {
            // Fatal errors from Elementor
            error_log( '[AEBG] ⚠️ Elementor fatal error (non-critical): ' . $e->getMessage() );
        }
    }
}, 1 );

/**
 * Global exception handler to catch Elementor Cloud Library errors
 * This prevents fatal errors from stopping WordPress execution
 */
if ( ! function_exists( 'aebg_handle_elementor_exceptions' ) ) {
    function aebg_handle_elementor_exceptions( $exception ) {
        // Check if this is an Elementor Cloud Library 403 error
        if ( $exception instanceof \WpOrg\Requests\Exception\Http\Status403 ) {
            $trace = $exception->getTraceAsString();
            // Check if the error originates from Elementor Cloud Library
            if ( strpos( $trace, 'elementor/modules/cloud-library' ) !== false ) {
                error_log( '[AEBG] ⚠️ Caught Elementor Cloud Library 403 error via exception handler: ' . $exception->getMessage() );
                // Don't re-throw - allow execution to continue
                return;
            }
        }
        // For other exceptions, let WordPress handle them normally
        throw $exception;
    }
    
    // Register the exception handler early, but only if not already set
    if ( ! set_exception_handler( 'aebg_handle_elementor_exceptions' ) ) {
        // If there's already a handler, we'll use error suppression in our hooks instead
    }
}

// Enqueue Elementor editor scripts
add_action('elementor/editor/before_enqueue_scripts', function() {
    wp_enqueue_script(
        'aebg-elementor-prompt-templates',
        AEBG_PLUGIN_URL . 'assets/js/elementor-prompt-templates.js',
        ['jquery', 'elementor-editor'],
        AEBG_VERSION,
        true
    );
    
    wp_localize_script(
        'aebg-elementor-prompt-templates',
        'aebgPromptTemplates',
        [
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'rest_url' => rest_url('aebg/v1/prompt-templates/'),
        ]
    );
    
    wp_enqueue_style(
        'aebg-elementor-prompt-templates',
        AEBG_PLUGIN_URL . 'assets/css/prompt-templates.css',
        [],
        AEBG_VERSION
    );
});

// Enhanced AI Content Prompt tab: add toggle to enable/disable AI prompt usage
add_action('elementor/element/common/_section_style/after_section_end', function($element, $args) {
    $element->start_controls_section(
        'aebg_ai_section',
        [
            'label' => __('AI Content Prompt', 'aebg'),
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ]
    );

    $element->add_control(
        'aebg_ai_enable',
        [
            'label' => __('Enable AI Content Generation', 'aebg'),
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'label_on' => __('On', 'aebg'),
            'label_off' => __('Off', 'aebg'),
            'return_value' => 'yes',
            'default' => '', // Off by default
            'description' => __('When enabled, the prompt below will be used to generate content in the bulk generator. When off, the original widget content will be used.', 'aebg'),
        ]
    );

    $is_image = (isset($element->get_name) && $element->get_name() === 'image') || (method_exists($element, 'get_name') && $element->get_name() === 'image');
    $desc = $is_image
        ? __('Enter a prompt to generate an image with AI. The image will be replaced during bulk generation.', 'aebg')
        : __('Enter your prompt for AI content generation. This will be used by the bulk generator if enabled.', 'aebg');

    // Template library links
    $element->add_control(
        'aebg_ai_template_links',
        [
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => '<div style="margin-top: 10px;">
                <a href="#" class="aebg-open-template-library-link" style="color: #2271b1; text-decoration: none; margin-right: 10px;">
                    <span class="dashicons dashicons-admin-page" style="font-size: 16px; vertical-align: middle;"></span> ' . __('Browse Template Library', 'aebg') . '
                </a>
                <a href="#" class="aebg-save-as-template-link" style="color: #2271b1; text-decoration: none;">
                    <span class="dashicons dashicons-saved" style="font-size: 16px; vertical-align: middle;"></span> ' . __('Save as Template', 'aebg') . '
                </a>
            </div>',
            'condition' => [ 'aebg_ai_enable' => 'yes' ],
            'separator' => 'before',
        ]
    );

    $element->add_control(
        'aebg_ai_prompt',
        [
            'label' => __('Prompt', 'aebg'),
            'type' => \Elementor\Controls_Manager::TEXTAREA,
            'description' => $desc,
            'condition' => [ 'aebg_ai_enable' => 'yes' ],
        ]
    );

    $element->end_controls_section();
}, 10, 2);

// Register the custom AEBG Icon List widget
add_action('elementor/widgets/register', function($widgets_manager) {
    // Include the widget file only when Elementor is ready
    require_once __DIR__ . '/src/Core/WidgetIconList.php';
    $widgets_manager->register( new \Elementor\AEBG_Icon_List() );
});

// Register the custom AEBG Product List widget
add_action('elementor/widgets/register', function($widgets_manager) {
    // Include the widget file only when Elementor is ready
    require_once __DIR__ . '/src/Core/WidgetProductList.php';
    $widgets_manager->register( new \Elementor\AEBG_Product_List() );
});

// Email Marketing integration now uses Elementor's built-in webhook feature
// See: Email Marketing > Settings for webhook URL configuration

// Register widget styles
add_action('wp_enqueue_scripts', function() {
    if ( ! did_action( 'elementor/loaded' ) ) {
        return;
    }
    
    wp_register_style(
        'aebg-product-list-widget',
        plugin_dir_url( __FILE__ ) . 'assets/css/product-list-widget.css',
        [],
        '2.1.3'
    );
}, 20);

// Also register for Elementor editor
add_action('elementor/frontend/after_enqueue_styles', function() {
    wp_enqueue_style( 'aebg-product-list-widget' );
});

// Register AEBG Dynamic Tag Group
// Based on working example: Register group on elementor/dynamic_tags/register hook
add_action( 'elementor/dynamic_tags/register', function( $dynamic_tags_manager ) {
    try {
        // Context check: Only register in Elementor editor/preview/AJAX contexts
        if ( is_admin() ) {
            $is_elementor_preview = isset( $_GET['elementor-preview'] ) && $_GET['elementor-preview'] === 'true';
            
            // Safely check edit mode with error handling
            $is_edit_mode = false;
            try {
                if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) ) {
                    $is_edit_mode = \Elementor\Plugin::$instance->editor->is_edit_mode();
                }
            } catch ( \Exception $e ) {
                // Elementor may have initialization issues, but we can still register
                error_log( '[AEBG] Could not check edit mode: ' . $e->getMessage() );
            }
            
            $is_doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
            
            // If it's admin, but not Elementor preview, not edit mode, and not AJAX => skip
            if ( ! $is_elementor_preview && ! $is_edit_mode && ! $is_doing_ajax ) {
                return;
            }
        }
        
        // Ensure Elementor is loaded
        if ( ! did_action( 'elementor/loaded' ) ) {
            error_log( '[AEBG] Elementor not fully loaded; skipping dynamic tag group registration.' );
            return;
        }
        
        // Register the 'aebg' group
        if ( method_exists( $dynamic_tags_manager, 'register_group' ) ) {
            $dynamic_tags_manager->register_group( 'aebg', [
                'title' => esc_html__( 'AEBG', 'aebg' ),
            ] );
            error_log( '[AEBG] ✅ Registered dynamic tag group: aebg' );
        } else {
            error_log( '[AEBG] ⚠️ register_group method not found on dynamic_tags_manager' );
        }
    } catch ( \WpOrg\Requests\Exception\Http\Status403 $e ) {
        // Elementor Cloud Library 403 error - log but continue
        error_log( '[AEBG] ⚠️ Elementor Cloud Library 403 error during group registration (non-critical): ' . $e->getMessage() );
    } catch ( \Exception $e ) {
        error_log( '[AEBG] Error during dynamic tag group registration: ' . $e->getMessage() );
    } catch ( \Error $e ) {
        error_log( '[AEBG] Fatal error during dynamic tag group registration: ' . $e->getMessage() );
    }
}, 1 );

// Register AEBG Dynamic Tags
// Based on working example pattern
add_action( 'elementor/dynamic_tags/register_tags', function( $dynamic_tags_manager ) {
    try {
        // Context check: Only register in Elementor editor/preview/AJAX contexts
        if ( is_admin() ) {
            $is_elementor_preview = isset( $_GET['elementor-preview'] ) && $_GET['elementor-preview'] === 'true';
            
            // Safely check edit mode with error handling
            $is_edit_mode = false;
            try {
                if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) ) {
                    $is_edit_mode = \Elementor\Plugin::$instance->editor->is_edit_mode();
                }
            } catch ( \Exception $e ) {
                // Elementor may have initialization issues, but we can still register
                error_log( '[AEBG] Could not check edit mode: ' . $e->getMessage() );
            }
            
            $is_doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
            
            // If it's admin, but not Elementor preview, not edit mode, and not AJAX => skip
            if ( ! $is_elementor_preview && ! $is_edit_mode && ! $is_doing_ajax ) {
                return;
            }
        }
        
        // Ensure Elementor is loaded
        if ( ! did_action( 'elementor/loaded' ) ) {
            error_log( '[AEBG] Elementor not fully loaded; skipping dynamic tags registration.' );
            return;
        }
        
        // Check if Elementor Pro is active (Dynamic Tags require Pro)
        if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
            // Try alternative check
            if ( ! function_exists( 'elementor_pro_load_plugin' ) && ! class_exists( '\ElementorPro\Plugin' ) ) {
                error_log( '[AEBG] Elementor Pro not found' );
                return;
            }
        }
        
        // Check if Dynamic Tags module exists
        if ( ! class_exists( '\Elementor\Modules\DynamicTags\Module' ) ) {
            error_log( '[AEBG] Dynamic Tags Module class not found' );
            return;
        }
    } catch ( \WpOrg\Requests\Exception\Http\Status403 $e ) {
        // Elementor Cloud Library 403 error - log but continue
        error_log( '[AEBG] ⚠️ Elementor Cloud Library 403 error during registration check (non-critical): ' . $e->getMessage() );
        // Continue with registration anyway - the error is in Cloud Library, not Dynamic Tags
    } catch ( \Exception $e ) {
        error_log( '[AEBG] Error during dynamic tags registration check: ' . $e->getMessage() );
        return;
    } catch ( \Error $e ) {
        error_log( '[AEBG] Fatal error during dynamic tags registration check: ' . $e->getMessage() );
        return;
    }
    
    // Ensure helper classes are loaded (they should be autoloaded, but just in case)
    $helper_files = [
        __DIR__ . '/src/Core/TestvinderHelper.php',
        __DIR__ . '/src/Core/ProductHelper.php',
    ];
    
    foreach ( $helper_files as $helper_file ) {
        if ( file_exists( $helper_file ) ) {
            require_once $helper_file;
        }
    }
    
    error_log( '[AEBG] Starting Dynamic Tags registration' );
    
    // Testvinder Dynamic Tags
    $testvinder_tags = [
        'TestvinderName',
        'TestvinderImage',
        'TestvinderRating',
        'TestvinderPrice',
        'TestvinderUrl',
        'TestvinderAffiliateUrl',
    ];
    
    // Product Dynamic Tags
    $product_tags = [
        'ProductName',
        'ProductImage',
        'ProductPrice',
        'ProductUrl',
        'ProductAffiliateUrl',
    ];
    
    $all_tags = array_merge( $testvinder_tags, $product_tags );
    $registered_count = 0;
    
    foreach ( $all_tags as $tag_name ) {
        $file = __DIR__ . '/src/Elementor/DynamicTags/' . $tag_name . '.php';
        
        if ( ! file_exists( $file ) ) {
            error_log( '[AEBG] ERROR: File not found: ' . $file );
            continue;
        }
        
        require_once $file;
        $class_name = 'AEBG\\Elementor\\DynamicTags\\' . $tag_name;
        
        if ( ! class_exists( $class_name ) ) {
            error_log( '[AEBG] ERROR: Class not found: ' . $class_name );
            continue;
        }
        
        // Verify it extends the correct base class (Tag or Data_Tag)
        // Data_Tag extends Tag, so is_subclass_of should work for both
        if ( ! is_subclass_of( $class_name, '\Elementor\Core\DynamicTags\Tag' ) ) {
            // Try checking for Data_Tag explicitly as well
            if ( ! is_subclass_of( $class_name, '\Elementor\Core\DynamicTags\Data_Tag' ) ) {
                error_log( '[AEBG] WARNING: Class ' . $class_name . ' may not extend Tag or Data_Tag, but attempting registration anyway' );
            }
        }
        
        try {
            $tag_instance = new $class_name();
            
            // Get the tag name to check if already registered
            $tag_id = $tag_instance->get_name();
            
            // Check if tag is already registered (prevent double registration)
            if ( method_exists( $dynamic_tags_manager, 'get_tag_info' ) ) {
                if ( $dynamic_tags_manager->get_tag_info( $tag_id ) ) {
                    error_log( '[AEBG] Tag already registered, skipping: ' . $tag_name );
                    continue;
                }
            }
            
            // Use register_tag() method (matching working example)
            $dynamic_tags_manager->register_tag( $tag_instance );
            
            $registered_count++;
            error_log( '[AEBG] ✅ Successfully registered: ' . $tag_name . ' (ID: ' . $tag_id . ')' );
        } catch ( \Exception $e ) {
            error_log( '[AEBG] ERROR registering ' . $tag_name . ': ' . $e->getMessage() );
            error_log( '[AEBG] Stack trace: ' . $e->getTraceAsString() );
        } catch ( \Error $e ) {
            error_log( '[AEBG] FATAL ERROR registering ' . $tag_name . ': ' . $e->getMessage() );
            error_log( '[AEBG] Stack trace: ' . $e->getTraceAsString() );
        }
    }
    
    error_log( '[AEBG] ===== Dynamic Tags registration complete. Registered: ' . $registered_count . ' of ' . count( $all_tags ) . ' tags =====' );
}, 1 );

/**
 * Handle synthetic image IDs for external images in Elementor dynamic tags
 * This allows Elementor to properly display external images by intercepting
 * wp_get_attachment_image_src() calls for negative (synthetic) IDs
 */
add_filter( 'wp_get_attachment_image_src', function( $image, $attachment_id, $size, $icon ) {
    // Only handle negative IDs (our synthetic IDs for external images)
    if ( $attachment_id < 0 ) {
        global $aebg_synthetic_images;
        if ( isset( $aebg_synthetic_images[ $attachment_id ] ) ) {
            // Return proper URL array format that Elementor expects
            // Format: [url, width, height, is_intermediate]
            return [
                $aebg_synthetic_images[ $attachment_id ],
                1920, // Default width
                1080, // Default height
                false // Not intermediate
            ];
        }
    }
    return $image;
}, 10, 4 );
