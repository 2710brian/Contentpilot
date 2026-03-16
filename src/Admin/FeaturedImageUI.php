<?php

namespace AEBG\Admin;

/**
 * Featured Image UI Class
 * 
 * Handles UI integration for featured image regeneration in WordPress post edit screen.
 *
 * @package AEBG\Admin
 */
class FeaturedImageUI {
	/**
	 * FeaturedImageUI constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'init' ] );
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Add custom metabox above Categories
		add_action( 'add_meta_boxes', [ $this, 'add_custom_metabox' ], 5 );
		
		// Enqueue assets on post edit screens
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}


	/**
	 * Enqueue CSS and JavaScript assets.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		global $post;

		// Only enqueue on post edit screens
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Only enqueue for supported post types (post, page, and any custom post types that support thumbnails)
		if ( ! $post || ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return;
		}

		// Check permissions - allow admins even if capability not set
		if ( ! current_user_can( 'aebg_generate_content' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache_buster = microtime( true );

		// Enqueue CSS
		wp_enqueue_style(
			'aebg-featured-image-regenerate',
			AEBG_PLUGIN_URL . 'assets/css/featured-image-regenerate.css',
			[],
			'1.0.0.' . $cache_buster
		);

		// Enqueue JavaScript
		wp_enqueue_script(
			'aebg-featured-image-regenerate',
			AEBG_PLUGIN_URL . 'assets/js/featured-image-regenerate.js',
			[ 'jquery' ],
			'1.0.0.' . $cache_buster,
			true
		);

		// Localize script
		wp_localize_script(
			'aebg-featured-image-regenerate',
			'aebgFeaturedImage',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => rest_url( 'aebg/v1/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'postId'  => $post->ID,
			]
		);

		// Include modal template
		$this->render_modal();
	}

	/**
	 * Add custom metabox above Categories.
	 */
	public function add_custom_metabox() {
		global $post;
		
		if ( ! $post ) {
			return;
		}
		
		// Check permissions
		if ( ! current_user_can( 'aebg_generate_content' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		// Check if API key is configured
		$settings = get_option( 'aebg_settings', [] );
		if ( empty( $settings['api_key'] ) ) {
			return;
		}
		
		// Only for post types that support thumbnails
		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return;
		}
		
		// Check if post has title
		if ( empty( $post->post_title ) ) {
			return;
		}
		
		// Add metabox above Categories (high priority in side context)
		add_meta_box(
			'aebg_featured_image_regenerate',
			__( 'Featured Image AI', 'aebg' ),
			[ $this, 'render_custom_metabox' ],
			$post->post_type,
			'side',
			'high' // High priority to appear above Categories
		);
	}
	
	/**
	 * Render custom metabox content.
	 */
	public function render_custom_metabox() {
		global $post;
		$has_thumbnail = has_post_thumbnail( $post->ID );
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		
		// Get thumbnail URL for preview
		$thumbnail_url = '';
		if ( $has_thumbnail && $thumbnail_id ) {
			$thumbnail_url = wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' );
		}
		
		?>
		<div class="aebg-featured-image-ai-metabox">
			<?php if ( $has_thumbnail && $thumbnail_url ) : ?>
				<div class="aebg-featured-image-preview" style="margin-bottom: 12px;">
					<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php esc_attr_e( 'Current featured image', 'aebg' ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px; display: block;" />
				</div>
			<?php endif; ?>
			
			<button type="button" class="button button-primary button-large aebg-regenerate-featured-image-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="width: 100%; text-align: center; padding: 8px 12px; font-size: 14px; line-height: 1.5;">
				<span style="font-size: 18px; vertical-align: middle; margin-right: 6px;">🎨</span>
				<?php echo $has_thumbnail ? esc_html__( 'Regenerate Featured Image', 'aebg' ) : esc_html__( 'Generate Featured Image', 'aebg' ); ?>
			</button>
			
			<p class="description" style="margin-top: 8px; margin-bottom: 0; font-size: 12px; color: #646970;">
				<?php esc_html_e( 'Create or regenerate the featured image using AI', 'aebg' ); ?>
			</p>
		</div>
		<?php
	}
	
	
	/**
	 * Render the regeneration modal.
	 */
	private function render_modal() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/featured-image-regenerate-modal.php';
	}
}
