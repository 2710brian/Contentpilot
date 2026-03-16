<?php

namespace AEBG\Admin;

/**
 * Admin Menu Class
 *
 * @package AEBG\Admin
 */
class Menu {
	/**
	 * Menu constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_old_url_redirects' ] );
		add_action( 'admin_post_aebg_import_elementor_prompt_csv', [ $this, 'handle_import_elementor_prompt_csv' ] );
		
		// Initialize Networks Manager
		new \AEBG\Admin\Networks_Manager();
	}
	
	/**
	 * Handle redirects for old menu item URLs to new tab structure
	 */
	public function handle_old_url_redirects() {
		// Only redirect on admin pages
		if ( ! is_admin() ) {
			return;
		}
		
		// Get current page
		$page = isset( $_GET['page'] ) ? $_GET['page'] : '';
		
		// Redirect old menu items to settings page with appropriate tab hash
		if ( $page === 'aebg_networks' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=aebg_settings#networks' ) );
			exit;
		} elseif ( $page === 'aebg_logs' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=aebg_settings#logs' ) );
			exit;
		} elseif ( $page === 'aebg_competitor_tracking' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=aebg_settings#competitor-tracking' ) );
			exit;
		}
	}

	/**
	 * Register admin pages.
	 */
	public function register_pages() {
		// Prevent multiple registrations
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		// Main menu: AI Bulk Generator
		add_menu_page(
			__( 'AI Bulk Generator', 'aebg' ),
			__( 'AI Bulk Generator', 'aebg' ),
			'aebg_generate_content',
			'aebg_generator',
			[ $this, 'render_generator_page' ],
			'dashicons-admin-generic',
			6
		);
		
		// Submenu items in order:
		// 1. Bulk Generator
		add_submenu_page(
			'aebg_generator',
			__( 'Bulk Generator', 'aebg' ),
			__( 'Bulk Generator', 'aebg' ),
			'aebg_generate_content',
			'aebg_generator',
			[ $this, 'render_generator_page' ]
		);
		
		// 1.5. Generator V2 (Test - Step-by-Step Interface)
		add_submenu_page(
			'aebg_generator',
			__( 'Generator V2', 'aebg' ),
			__( 'Generator V2', 'aebg' ),
			'aebg_generate_content',
			'aebg_generator_v2',
			[ $this, 'render_generator_v2_page' ]
		);
		
		// 2. Clone Competitor (was Clone Content)
		add_submenu_page(
			'aebg_generator',
			__( 'Clone Competitor', 'aebg' ),
			__( 'Clone Competitor', 'aebg' ),
			'aebg_generate_content',
			'aebg_clone_content',
			[ $this, 'render_clone_content_page' ]
		);
		
		// 3. Find Product (was Product Scout)
		add_submenu_page(
			'aebg_generator',
			__( 'Find Product', 'aebg' ),
			__( 'Find Product', 'aebg' ),
			'aebg_generate_content',
			'aebg_product_scout',
			[ $this, 'render_product_scout_page' ]
		);
		
		// 4. Network Analytics
		add_submenu_page(
			'aebg_generator',
			__( 'Network Analytics', 'aebg' ),
			__( 'Network Analytics', 'aebg' ),
			'manage_options',
			'aebg_network_analytics',
			[ $this, 'render_network_analytics_page' ]
		);
		
		// 5. Prompt Templates
		add_submenu_page(
			'aebg_generator',
			__( 'AI Prompt Templates', 'aebg' ),
			__( 'Prompt Templates', 'aebg' ),
			'edit_posts',
			'aebg_prompt_templates',
			[ $this, 'render_prompt_templates_page' ]
		);

		// 5.5 CSV Prompt Import (Elementor templates)
		add_submenu_page(
			'aebg_generator',
			__( 'Import Prompts from CSV', 'aebg' ),
			__( 'CSV Prompt Import', 'aebg' ),
			'manage_options',
			'aebg_prompt_csv_import',
			[ $this, 'render_prompt_csv_import_page' ]
		);
		
		// 6. Settings
		add_submenu_page(
			'aebg_generator',
			__( 'Settings', 'aebg' ),
			__( 'Settings', 'aebg' ),
			'manage_options',
			'aebg_settings',
			[ $this, 'render_settings_page' ]
		);
		// Networks, Logs, and Competitor Tracking are now tabs in Settings page
		// Old menu items removed - redirects handled in admin_init
		
		// 7. Dashboard
		add_submenu_page(
			'aebg_generator',
			__( 'Activity Dashboard', 'aebg' ),
			__( 'Dashboard', 'aebg' ),
			'manage_options',
			'aebg_dashboard',
			[ $this, 'render_dashboard_page' ]
		);

	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_assets( $hook ) {
		// Check if we're on one of our plugin pages
		$is_generator_page = ( 'toplevel_page_aebg_generator' === $hook || 
		                       ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_generator' ) ||
		                       strpos( $hook, 'aebg_generator' ) !== false );
		$is_product_scout_page = ( 'aebg_generator_page_aebg_product_scout' === $hook || 
		                           ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_product_scout' ) ||
		                           strpos( $hook, 'aebg_product_scout' ) !== false );
		$is_settings_page = ( 'aebg_generator_page_aebg_settings' === $hook || 
		                      ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_settings' ) ||
		                      strpos( $hook, 'aebg_settings' ) !== false );
		// Networks, Logs, and Competitor Tracking are now tabs in Settings page
		// Old page checks removed - redirects handled in handle_old_url_redirects()
		$is_prompt_templates_page = ( 'aebg_generator_page_aebg_prompt_templates' === $hook || 
		                              ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_prompt_templates' ) ||
		                              strpos( $hook, 'aebg_prompt_templates' ) !== false );
		$is_prompt_csv_import_page = ( 'aebg_generator_page_aebg_prompt_csv_import' === $hook ||
		                               ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_prompt_csv_import' ) ||
		                               strpos( $hook, 'aebg_prompt_csv_import' ) !== false );
		$is_dashboard_page = ( 'aebg_generator_page_aebg_dashboard' === $hook || 
		                       ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_dashboard' ) ||
		                       strpos( $hook, 'aebg_dashboard' ) !== false );
		$is_clone_content_page = ( 'aebg_generator_page_aebg_clone_content' === $hook || 
		                          ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_clone_content' ) ||
		                          strpos( $hook, 'aebg_clone_content' ) !== false );
		$is_generator_v2_page = ( 'aebg_generator_page_aebg_generator_v2' === $hook || 
		                          ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_generator_v2' ) ||
		                          strpos( $hook, 'aebg_generator_v2' ) !== false );
		$is_network_analytics_page = ( 'aebg_generator_page_aebg_network_analytics' === $hook || 
		                               ( isset( $_GET['page'] ) && $_GET['page'] === 'aebg_network_analytics' ) ||
		                               strpos( $hook, 'aebg_network_analytics' ) !== false );

		$is_post_page = ( 'post.php' === $hook || 'post-new.php' === $hook );
		

		
		if ( ! $is_generator_page && ! $is_product_scout_page && ! $is_settings_page && ! $is_prompt_templates_page && ! $is_prompt_csv_import_page && ! $is_dashboard_page && ! $is_clone_content_page && ! $is_generator_v2_page && ! $is_network_analytics_page && ! $is_post_page ) {
			return;
		}

		// Only enqueue for users with permission on generator, product scout, clone content, and generator v2 pages
		if ( ( $is_generator_page || $is_product_scout_page || $is_clone_content_page || $is_generator_v2_page ) && ! current_user_can( 'aebg_generate_content' ) ) {
			return;
		}
		


		// Enqueue CSS for all our pages
		wp_enqueue_style(
			'aebg-admin-css',
			AEBG_PLUGIN_URL . 'assets/css/admin.css',
			[],
			AEBG_VERSION
		);

		// Add inline CSS for debugging
		if ( $is_product_scout_page ) {
			wp_add_inline_style(
				'aebg-admin-css',
				'/* Product Scout page detected - CSS should be loaded */'
			);
		}

		// Generator page specific assets
		if ( $is_generator_page ) {
			wp_enqueue_style(
				'aebg-generator-css',
				AEBG_PLUGIN_URL . 'assets/css/generator.css',
				[ 'aebg-admin-css' ],
				AEBG_VERSION
			);

			wp_enqueue_script(
				'aebg-generator-js',
				AEBG_PLUGIN_URL . 'assets/js/generator.js',
				[ 'jquery' ],
				AEBG_VERSION,
				true
			);

			wp_localize_script(
				'aebg-generator-js',
				'aebg',
				[
					'ajax_nonce' => wp_create_nonce('aebg_schedule_batch'),
					'validate_nonce' => wp_create_nonce('aebg_validate_template'),
					'analyze_nonce' => wp_create_nonce('aebg_analyze_template'),
					'update_nonce' => wp_create_nonce('aebg_update_template_products'),
					'update_post_nonce' => wp_create_nonce('aebg_update_post_products'),
					'duplicate_check_nonce' => wp_create_nonce('aebg_check_duplicates'),
					'rest_nonce' => wp_create_nonce('wp_rest'),
					'rest_url'   => rest_url('aebg/v1/'),
				]
			);

			wp_localize_script(
				'aebg-generator-js',
				'aebg_ajax',
				[
					'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				]
			);
		}

		// Product Scout page specific assets
		if ( $is_product_scout_page ) {
			
			            // Enqueue CSS for Product Scout page
            wp_enqueue_style(
                'aebg-generator-css',
                AEBG_PLUGIN_URL . 'assets/css/generator.css',
                [ 'aebg-admin-css' ],
                AEBG_VERSION . '.' . time()
            );

			            // Enqueue JavaScript for Product Scout page
            wp_enqueue_script(
                'aebg-product-scout-js',
                AEBG_PLUGIN_URL . 'assets/js/product-scout.js',
                [ 'jquery' ],
                AEBG_VERSION . '.' . time(),
                true
            );

			// Localize script with AJAX data
			wp_localize_script(
				'aebg-product-scout-js',
				'aebg',
				[
					'rest_nonce' => wp_create_nonce('wp_rest'),
					'rest_url'   => rest_url('aebg/v1/'),
				]
			);

			wp_localize_script(
				'aebg-product-scout-js',
				'aebg_ajax',
				[
					'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
					'search_products_nonce' => wp_create_nonce( 'aebg_search_products' ),
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				]
			);

			// Add inline script for debugging
			wp_add_inline_script(
				'aebg-product-scout-js',
				'console.log("Product Scout assets loaded successfully");'
			);

			// Add inline CSS for debugging
			wp_add_inline_style(
				'aebg-generator-css',
				'
				/* Product Scout Debug Styles */
				.aebg-generator-container {
					border: 2px solid #4f46e5 !important;
					background: #f0f9ff !important;
				}
				.aebg-generator-header {
					border: 2px solid #10b981 !important;
				}
				'
			);
		}

		// Dashboard page specific assets
		if ( $is_dashboard_page ) {
			wp_enqueue_style(
				'aebg-dashboard-css',
				AEBG_PLUGIN_URL . 'assets/css/dashboard.css',
				[ 'aebg-admin-css' ],
				AEBG_VERSION
			);

			// Enqueue Chart.js for charts
			wp_enqueue_script(
				'chart-js',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
				[],
				'4.4.0',
				true
			);

			wp_enqueue_script(
				'aebg-dashboard-js',
				AEBG_PLUGIN_URL . 'assets/js/dashboard.js',
				[ 'jquery', 'chart-js' ],
				AEBG_VERSION,
				true
			);

			wp_localize_script(
				'aebg-dashboard-js',
				'aebgDashboard',
				[
					'restUrl'   => rest_url('aebg/v1/dashboard/'),
					'nonce'     => wp_create_nonce('wp_rest'),
					'dateFrom'  => date('Y-m-d', strtotime('-7 days')),
					'dateTo'    => date('Y-m-d'),
				]
			);
		}

		// Enqueue main admin JS for settings, prompt templates and post pages
		// Note: Networks, Logs, and Competitor Tracking are now tabs in Settings
		if ( $is_settings_page || $is_prompt_templates_page || $is_post_page ) {
			// Enqueue generator CSS for settings and prompt templates pages to get enhanced checkbox styles
			if ( $is_settings_page || $is_prompt_templates_page ) {
				wp_enqueue_style(
					'aebg-generator-css',
					AEBG_PLUGIN_URL . 'assets/css/generator.css',
					[ 'aebg-admin-css' ],
					AEBG_VERSION
				);
			}
			
			// Enqueue tabs CSS and JS for settings page
			if ( $is_settings_page ) {
				wp_enqueue_style(
					'aebg-tabs-css',
					AEBG_PLUGIN_URL . 'assets/css/tabs.css',
					[ 'aebg-admin-css' ],
					AEBG_VERSION
				);
				
				wp_enqueue_style(
					'aebg-settings-tabs-css',
					AEBG_PLUGIN_URL . 'assets/css/settings-tabs.css',
					[ 'aebg-tabs-css', 'aebg-generator-css' ],
					AEBG_VERSION
				);
				
				wp_enqueue_script(
					'aebg-tabs-js',
					AEBG_PLUGIN_URL . 'assets/js/tabs.js',
					[],
					AEBG_VERSION,
					true
				);
				
				wp_enqueue_script(
					'aebg-settings-tabs-js',
					AEBG_PLUGIN_URL . 'assets/js/settings-tabs.js',
					[ 'aebg-tabs-js' ],
					AEBG_VERSION,
					true
				);
			}

			wp_enqueue_script(
				'aebg-admin-js',
				AEBG_PLUGIN_URL . 'assets/js/admin.js',
				[ 'jquery' ],
				AEBG_VERSION,
				true
			);

			// Localize script with common data
			wp_localize_script(
				'aebg-admin-js',
				'aebg',
				[
					'ajax_nonce' => wp_create_nonce('aebg_schedule_batch'),
					'rest_nonce' => wp_create_nonce('wp_rest'),
					'rest_url'   => rest_url('aebg/v1/'),
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				]
			);

			// Add specific data for settings page (now includes networks, logs, competitor tracking as tabs)
			if ( $is_settings_page ) {
				wp_localize_script(
					'aebg-admin-js',
					'aebg_ajax',
					[
						'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
						'ajaxurl' => admin_url( 'admin-ajax.php' ),
						'plugin_url' => AEBG_PLUGIN_URL,
					]
				);
			}
			
			// Enqueue prompt templates specific assets
			if ( $is_prompt_templates_page ) {
				wp_enqueue_style(
					'aebg-prompt-templates-css',
					AEBG_PLUGIN_URL . 'assets/css/prompt-templates.css',
					[],
					AEBG_VERSION
				);
				
				wp_enqueue_script(
					'aebg-prompt-templates-admin-js',
					AEBG_PLUGIN_URL . 'assets/js/prompt-templates-admin.js',
					[ 'jquery' ],
					AEBG_VERSION,
					true
				);
				
				wp_localize_script(
					'aebg-prompt-templates-admin-js',
					'aebgPromptTemplates',
					[
						'nonce' => wp_create_nonce( 'aebg_prompt_templates_nonce' ),
						'rest_nonce' => wp_create_nonce( 'wp_rest' ),
						'rest_url' => rest_url( 'aebg/v1/prompt-templates/' ),
						'ajaxurl' => admin_url( 'admin-ajax.php' ),
					]
				);
			}
			
			// Add modern networks selector script and styles specifically for settings page (Integrations tab)
			if ( $is_settings_page ) {
				wp_enqueue_script(
					'aebg-modern-networks-selector-js',
					AEBG_PLUGIN_URL . 'assets/js/modern-networks-selector.js',
					[], // No jQuery dependency
					AEBG_VERSION,
					true
				);
				
				wp_enqueue_style(
					'aebg-modern-networks-selector-css',
					AEBG_PLUGIN_URL . 'assets/css/modern-networks-selector.css',
					[],
					AEBG_VERSION
				);
			}
			
			// Enqueue logs page assets for Settings Logs tab
			if ( $is_settings_page ) {
				wp_enqueue_style(
					'aebg-logs-css',
					AEBG_PLUGIN_URL . 'assets/css/logs-page.css',
					[ 'aebg-generator-css', 'aebg-admin-css' ],
					AEBG_VERSION
				);

				wp_enqueue_script(
					'aebg-logs-js',
					AEBG_PLUGIN_URL . 'assets/js/logs-page.js',
					[ 'jquery' ],
					AEBG_VERSION,
					true
				);

				wp_localize_script(
					'aebg-logs-js',
					'aebg',
					[
						'rest_nonce' => wp_create_nonce('wp_rest'),
						'rest_url'   => rest_url('aebg/v1/'),
					]
				);
			}
			
			// Enqueue competitor tracking assets for Settings Competitor Tracking tab
			if ( $is_settings_page ) {
				wp_enqueue_style(
					'aebg-competitor-tracking-css',
					AEBG_PLUGIN_URL . 'assets/css/competitor-tracking.css',
					[ 'aebg-generator-css', 'aebg-admin-css' ],
					AEBG_VERSION
				);

				wp_enqueue_script(
					'aebg-competitor-tracking-js',
					AEBG_PLUGIN_URL . 'assets/js/competitor-tracking.js',
					[ 'jquery' ],
					AEBG_VERSION,
					true
				);

				wp_localize_script(
					'aebg-competitor-tracking-js',
					'aebg',
					[
						'rest_nonce' => wp_create_nonce('wp_rest'),
						'rest_url'   => rest_url('aebg/v1/'),
						'ajax_nonce' => wp_create_nonce('aebg_competitor_tracking'),
						'ajaxurl'    => admin_url( 'admin-ajax.php' ),
					]
				);
			}
			
			// Enqueue networks tab assets for Settings Networks tab
			if ( $is_settings_page ) {
				wp_enqueue_style(
					'aebg-networks-tab-css',
					AEBG_PLUGIN_URL . 'assets/css/networks-tab.css',
					[ 'aebg-settings-tabs-css', 'aebg-admin-css' ],
					AEBG_VERSION
				);

				wp_enqueue_script(
					'aebg-networks-tab-js',
					AEBG_PLUGIN_URL . 'assets/js/networks-tab.js',
					[], // No dependencies - uses vanilla JS
					AEBG_VERSION,
					true
				);
			}
		}

		// Generator V2 page specific assets (Step-by-Step Interface)
		if ( $is_generator_v2_page ) {
			wp_enqueue_style(
				'aebg-generator-v2-css',
				AEBG_PLUGIN_URL . 'assets/css/generator-v2.css',
				[ 'aebg-admin-css' ],
				AEBG_VERSION
			);

			wp_enqueue_script(
				'aebg-generator-v2-js',
				AEBG_PLUGIN_URL . 'assets/js/generator-v2.js',
				[ 'jquery' ],
				AEBG_VERSION,
				true
			);

			wp_localize_script(
				'aebg-generator-v2-js',
				'aebg',
				[
					'ajax_nonce' => wp_create_nonce('aebg_schedule_batch'),
					'validate_nonce' => wp_create_nonce('aebg_validate_template'),
					'analyze_nonce' => wp_create_nonce('aebg_analyze_template'),
					'update_nonce' => wp_create_nonce('aebg_update_template_products'),
					'duplicate_check_nonce' => wp_create_nonce('aebg_check_duplicates'),
					'rest_nonce' => wp_create_nonce('wp_rest'),
					'rest_url'   => rest_url('aebg/v1/'),
				]
			);

			wp_localize_script(
				'aebg-generator-v2-js',
				'aebg_ajax',
				[
					'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				]
			);
		}

		// Network Analytics page specific assets
		if ( $is_network_analytics_page ) {
			wp_enqueue_style(
				'aebg-generator-css',
				AEBG_PLUGIN_URL . 'assets/css/generator.css',
				[ 'aebg-admin-css' ],
				AEBG_VERSION
			);

			wp_enqueue_script(
				'aebg-admin-js',
				AEBG_PLUGIN_URL . 'assets/js/admin.js',
				[ 'jquery' ],
				AEBG_VERSION,
				true
			);

			wp_localize_script(
				'aebg-admin-js',
				'aebg',
				[
					'ajax_nonce' => wp_create_nonce('aebg_schedule_batch'),
					'rest_nonce' => wp_create_nonce('wp_rest'),
					'rest_url'   => rest_url('aebg/v1/'),
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				]
			);

			wp_localize_script(
				'aebg-admin-js',
				'aebg_ajax',
				[
					'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'plugin_url' => AEBG_PLUGIN_URL,
				]
			);
		}

		// Clone Content page specific assets (now called Clone Competitor)
		if ( $is_clone_content_page ) {
			wp_enqueue_style(
				'aebg-clone-content-css',
				AEBG_PLUGIN_URL . 'assets/css/generator.css',
				[ 'aebg-admin-css' ],
				AEBG_VERSION
			);

			wp_enqueue_script(
				'aebg-clone-content-js',
				AEBG_PLUGIN_URL . 'assets/js/clone-content.js',
				[ 'jquery' ],
				AEBG_VERSION,
				true
			);

			wp_localize_script(
				'aebg-clone-content-js',
				'aebg',
				[
					'rest_nonce' => wp_create_nonce('wp_rest'),
					'rest_url'   => rest_url('aebg/v1/'),
				]
			);

			wp_localize_script(
				'aebg-clone-content-js',
				'aebg_ajax',
				[
					'nonce' => wp_create_nonce( 'aebg_ajax_nonce' ),
					'search_products_nonce' => wp_create_nonce( 'aebg_search_products' ),
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				]
			);
		}

		// Meta box JS is now handled by the Meta_Box class
	}

	/**
	 * Render the generator page.
	 */
	public function render_generator_page() {
		if ( isset( $_GET['batch_id'] ) ) {
			include_once AEBG_PLUGIN_DIR . 'src/Admin/views/results-page.php';
		} else {
			include_once AEBG_PLUGIN_DIR . 'src/Admin/views/generator-page.php';
		}
	}

	/**
	 * Render the generator V2 page (Step-by-Step Interface).
	 */
	public function render_generator_v2_page() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/generator-v2-page.php';
	}

	/**
	 * Render the product scout page.
	 */
	public function render_product_scout_page() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/product-scout-page.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		$options = \AEBG\Admin\Settings::get_settings();
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/settings-page.php';
	}

	/**
	 * Render the networks page.
	 * @deprecated Networks is now a tab in Settings page. Kept for backward compatibility.
	 */
	public function render_networks_page() {
		// Redirect to settings page with networks tab
		wp_safe_redirect( admin_url( 'admin.php?page=aebg_settings#networks' ) );
		exit;
	}

	/**
	 * Render the logs page.
	 * @deprecated Logs is now a tab in Settings page. Kept for backward compatibility.
	 */
	public function render_logs_page() {
		// Redirect to settings page with logs tab
		wp_safe_redirect( admin_url( 'admin.php?page=aebg_settings#logs' ) );
		exit;
	}

	/**
	 * Render the competitor tracking page.
	 * @deprecated Competitor Tracking is now a tab in Settings page. Kept for backward compatibility.
	 */
	public function render_competitor_tracking_page() {
		// Redirect to settings page with competitor-tracking tab
		wp_safe_redirect( admin_url( 'admin.php?page=aebg_settings#competitor-tracking' ) );
		exit;
	}

	/**
	 * Render the clone content page.
	 */
	public function render_clone_content_page() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/clone-content-page.php';
	}
	
	/**
	 * Render the prompt templates page.
	 */
	public function render_prompt_templates_page() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/prompt-templates-page.php';
	}

	/**
	 * Render the CSV prompt import page.
	 */
	public function render_prompt_csv_import_page() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/prompt-csv-import-page.php';
	}

	/**
	 * Handle CSV upload and create Elementor template(s) with per-widget AI prompts.
	 */
	public function handle_import_elementor_prompt_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'aebg' ) );
		}

		check_admin_referer( 'aebg_import_elementor_prompt_csv', 'aebg_nonce' );

		if ( empty( $_FILES['aebg_prompt_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['aebg_prompt_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( [ 'page' => 'aebg_prompt_csv_import', 'aebg_import' => 'no_file' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$importer = new \AEBG\Core\ElementorPromptCsvImporter();
		$result   = $importer->import_from_csv_file( $_FILES['aebg_prompt_csv']['tmp_name'] );

		$key = 'aebg_csv_import_' . get_current_user_id() . '_' . time();

		if ( is_wp_error( $result ) ) {
			set_transient( $key, [ 'error' => $result->get_error_message() ], 60 );
			wp_safe_redirect(
				add_query_arg(
					[
						'page'        => 'aebg_prompt_csv_import',
						'aebg_import' => 'error',
						'aebg_result' => $key,
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		set_transient( $key, $result, 60 );
		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => 'aebg_prompt_csv_import',
					'aebg_import' => 'success',
					'aebg_result' => $key,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the dashboard page.
	 */
	/**
	 * Render the network analytics page.
	 */
	public function render_network_analytics_page() {
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/network-analytics-page.php';
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page() {
		// Ensure dashboard tables exist before rendering
		if ( class_exists( 'AEBG\\Installer' ) ) {
			\AEBG\Installer::ensureDashboardTables();
		}
		
		include_once AEBG_PLUGIN_DIR . 'src/Admin/views/dashboard-page.php';
	}

}
