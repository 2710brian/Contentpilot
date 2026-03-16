<?php

namespace AEBG\EmailMarketing\Admin;

/**
 * Email Marketing Menu
 * 
 * Registers admin menu pages for email marketing
 * 
 * @package AEBG\EmailMarketing\Admin
 */
class EmailMarketingMenu {
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', [$this, 'add_menu_pages'] );
		add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts'] );
	}
	
	/**
	 * Add menu pages
	 * 
	 * @return void
	 */
	public function add_menu_pages() {
		// Create top-level "Email Marketing" menu
		$parent_slug = 'aebg-email-marketing';
		
		// Main menu page (Email Lists as default page)
		add_menu_page(
			__( 'Email Marketing', 'aebg' ),
			__( 'Email Marketing', 'aebg' ),
			'manage_options',
			$parent_slug,
			[$this, 'render_lists_page'],
			'dashicons-email-alt', // Email icon
			30 // Position in menu (after Posts, Pages, etc.)
		);
		
		// Lists page (first submenu item, same as parent to avoid duplicate)
		add_submenu_page(
			$parent_slug,
			__( 'Email Lists', 'aebg' ),
			__( 'Email Lists', 'aebg' ),
			'manage_options',
			$parent_slug, // Same slug as parent to make it the default page
			[$this, 'render_lists_page']
		);
		
		// Subscribers page
		add_submenu_page(
			$parent_slug,
			__( 'Subscribers', 'aebg' ),
			__( 'Subscribers', 'aebg' ),
			'manage_options',
			'aebg-email-subscribers',
			[$this, 'render_subscribers_page']
		);
		
		// Campaigns page
		add_submenu_page(
			$parent_slug,
			__( 'Campaigns', 'aebg' ),
			__( 'Campaigns', 'aebg' ),
			'manage_options',
			'aebg-email-campaigns',
			[$this, 'render_campaigns_page']
		);
		
		// Templates page
		add_submenu_page(
			$parent_slug,
			__( 'Email Templates', 'aebg' ),
			__( 'Email Templates', 'aebg' ),
			'manage_options',
			'aebg-email-templates',
			[$this, 'render_templates_page']
		);
		
		// Analytics page
		add_submenu_page(
			$parent_slug,
			__( 'Email Analytics', 'aebg' ),
			__( 'Email Analytics', 'aebg' ),
			'manage_options',
			'aebg-email-analytics',
			[$this, 'render_analytics_page']
		);
	}
	
	/**
	 * Enqueue scripts and styles
	 * 
	 * @param string $hook Current admin page hook
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		// Only load on email marketing pages
		// Hook format: toplevel_page_aebg-email-marketing or aebg-email-marketing_page_{submenu}
		$is_email_marketing_page = (
			strpos( $hook, 'aebg-email' ) !== false ||
			strpos( $hook, 'aebg_email' ) !== false ||
			( isset( $_GET['page'] ) && strpos( $_GET['page'], 'aebg-email' ) !== false )
		);
		
		if ( ! $is_email_marketing_page ) {
			return;
		}
		
		// Enqueue CSS
		wp_enqueue_style(
			'aebg-email-marketing-admin',
			AEBG_PLUGIN_URL . 'assets/css/email-marketing-admin.css',
			[],
			AEBG_VERSION
		);
		
		// Enqueue JS
		wp_enqueue_script(
			'aebg-email-marketing-admin',
			AEBG_PLUGIN_URL . 'assets/js/email-marketing-admin.js',
			['jquery'],
			AEBG_VERSION,
			true
		);
		
		// Localize script
		wp_localize_script( 'aebg-email-marketing-admin', 'aebgEmailMarketing', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'adminUrl' => admin_url( 'admin.php' ),
			'nonce' => wp_create_nonce( 'aebg_email_marketing' ),
		] );
	}
	
	/**
	 * Render lists page
	 * 
	 * @return void
	 */
	public function render_lists_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		
		// Handle create list action
		if ( $action === 'create_list' ) {
			include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/create-list-page.php';
			return;
		}
		
		// Default: List all lists
		$list_manager = new \AEBG\EmailMarketing\Core\ListManager();
		$lists = $list_manager->get_all_lists();
		
		include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/lists-page.php';
	}
	
	/**
	 * Render subscribers page
	 * 
	 * @return void
	 */
	public function render_subscribers_page() {
		$subscriber_manager = new \AEBG\EmailMarketing\Core\SubscriberManager();
		$list_manager = new \AEBG\EmailMarketing\Core\ListManager();
		$lists = $list_manager->get_all_lists();
		
		include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/subscribers-page.php';
	}
	
	/**
	 * Render campaigns page
	 * 
	 * @return void
	 */
	public function render_campaigns_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		$campaign_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		
		$campaign_manager = new \AEBG\EmailMarketing\Core\CampaignManager();
		
		// Handle different actions
		if ( $action === 'create' ) {
			include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/create-campaign-page.php';
			return;
		} elseif ( $action === 'view' && $campaign_id > 0 ) {
			$campaign = $campaign_manager->get_campaign( $campaign_id );
			if ( $campaign ) {
				// TODO: Include campaign detail view
				// For now, show basic info
				echo '<div class="wrap"><h1>' . esc_html__( 'Campaign Details', 'aebg' ) . '</h1>';
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Campaign detail view coming soon. Campaign ID: ', 'aebg' ) . esc_html( $campaign_id ) . '</p></div>';
				echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aebg-email-campaigns' ) ) . '" class="button">' . esc_html__( 'Back to Campaigns', 'aebg' ) . '</a></p></div>';
				return;
			}
		}
		
		// Default: List all campaigns
		$campaigns = $campaign_manager->get_all_campaigns();
		
		include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/campaigns-page.php';
	}
	
	/**
	 * Render templates page
	 * 
	 * @return void
	 */
	public function render_templates_page() {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		$template_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		
		$template_repository = new \AEBG\EmailMarketing\Repositories\TemplateRepository();
		
		// Handle different actions
		if ( $action === 'edit' && $template_id > 0 ) {
			$template = $template_repository->get( $template_id );
			if ( $template ) {
				include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/edit-template-page.php';
				return;
			} else {
				wp_die( __( 'Template not found.', 'aebg' ) );
			}
		} elseif ( $action === 'create' ) {
			include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/create-template-page.php';
			return;
		} elseif ( $action === 'preview' && $template_id > 0 ) {
			$template = $template_repository->get( $template_id );
			if ( $template ) {
				// Process template with sample data for preview
				$template_repo = new \AEBG\EmailMarketing\Repositories\TemplateRepository();
				$template_service = new \AEBG\EmailMarketing\Services\TemplateService( $template_repo );
				$sample_variables = [
					'post_title' => __( 'Sample Post Title', 'aebg' ),
					'post_url' => home_url( '/sample-post/' ),
					'post_excerpt' => __( 'This is a sample excerpt for the email template preview.', 'aebg' ),
					'post_content' => __( 'This is sample content for the email template preview. It demonstrates how the template will look when rendered with actual post data.', 'aebg' ),
					'site_name' => get_bloginfo( 'name' ),
					'site_url' => home_url(),
					'unsubscribe_url' => home_url( '/unsubscribe/?token=sample' ),
					'subscriber_name' => __( 'John Doe', 'aebg' ),
					'subscriber_email' => 'john@example.com',
					'product_list' => __( 'Product 1, Product 2, Product 3', 'aebg' ),
					'new_product' => __( 'New Product Name', 'aebg' ),
				];
				
				$processed = $template_service->process_template( $template, $sample_variables );
				
				include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/preview-template-page.php';
				return;
			} else {
				wp_die( __( 'Template not found.', 'aebg' ) );
			}
		}
		
		// Default: List all templates
		$templates = $template_repository->get_all();
		
		include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/templates-page.php';
	}
	
	/**
	 * Render analytics page
	 * 
	 * @return void
	 */
	public function render_analytics_page() {
		$analytics_service = new \AEBG\EmailMarketing\Services\AnalyticsService();
		
		// Get overall stats
		$overall_stats = $analytics_service->get_overall_stats();
		
		// Get recent campaigns stats
		$recent_campaigns = $analytics_service->get_recent_campaigns_stats( 10 );
		
		include AEBG_PLUGIN_DIR . 'src/EmailMarketing/Admin/views/analytics-page.php';
	}
}

