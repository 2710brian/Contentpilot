<?php

namespace AEBG\Admin;

/**
 * Bulk Category Manager
 * 
 * Enhances WordPress's native bulk edit functionality to allow
 * bulk category assignment for posts.
 *
 * @package AEBG\Admin
 */
class BulkCategoryManager {
	/**
	 * BulkCategoryManager constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'init' ] );
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Add category selector to bulk edit form
		add_action( 'admin_footer', [ $this, 'add_bulk_category_selector' ] );
		
		// Process bulk category assignment (runs before redirect)
		add_action( 'load-edit.php', [ $this, 'process_bulk_category_assignment' ] );
		
		// Enqueue scripts and styles
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue assets for bulk category functionality.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only enqueue on posts list page
		if ( 'edit.php' !== $hook ) {
			return;
		}

		// Check if we're on the post type edit page
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : 'post';
		if ( 'post' !== $post_type ) {
			return;
		}

		$cache_buster = microtime( true );

		// Enqueue CSS
		wp_enqueue_style(
			'aebg-bulk-categories',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/bulk-categories.css',
			[],
			'1.0.0.' . $cache_buster
		);

		// Enqueue JavaScript
		wp_enqueue_script(
			'aebg-bulk-categories',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/js/bulk-categories.js',
			[ 'jquery' ],
			'1.0.0.' . $cache_buster,
			true
		);

		// Localize script with categories data
		$categories = get_categories( [
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		$categories_data = [];
		foreach ( $categories as $category ) {
			$categories_data[] = [
				'id'   => $category->term_id,
				'name' => $category->name,
				'slug' => $category->slug,
			];
		}

		wp_localize_script(
			'aebg-bulk-categories',
			'aebgBulkCategories',
			[
				'categories' => $categories_data,
				'nonce'      => wp_create_nonce( 'aebg_bulk_categories' ),
				'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				'strings'    => [
					'oneSelected'  => __( '1 category selected', 'aebg' ),
					'manySelected' => __( '{count} categories selected', 'aebg' ),
					'noneSelected' => __( 'Hold Ctrl/Cmd to select multiple categories. Selected categories will be added to all selected posts.', 'aebg' ),
				],
			]
		);
	}

	/**
	 * Add category selector to bulk edit form.
	 */
	public function add_bulk_category_selector() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-post' !== $screen->id ) {
			return;
		}
		
		// Only show on posts list page
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : 'post';
		if ( 'post' !== $post_type ) {
			return;
		}

		// Get all categories
		$categories = get_categories( [
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		if ( empty( $categories ) ) {
			return;
		}

		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Function to add category selector to bulk edit form
			function addCategorySelector() {
				// Find the bulk edit form (it's added by WordPress when posts are selected)
				var $bulkEditForm = $('#bulk-edit');
				
				if ( $bulkEditForm.length === 0 ) {
					return;
				}
				
				// Check if we've already added our selector
				if ( $bulkEditForm.find('.aebg-bulk-category-selector').length > 0 ) {
					return;
				}
				
				// Find the status field to insert after
				var $statusField = $bulkEditForm.find('select[name="_status"]').closest('.inline-edit-group');
				
				if ( $statusField.length === 0 ) {
					// If status field not found, try to find the last field group before buttons
					$statusField = $bulkEditForm.find('.inline-edit-group').last();
				}
				
				// Create category selector HTML
				var categoryHtml = '<div class="inline-edit-group aebg-bulk-category-selector">' +
					'<label class="alignleft">' +
						'<span class="title"><?php echo esc_js( __( 'Categories', 'aebg' ) ); ?></span>' +
						'<select name="aebg_bulk_categories[]" multiple="multiple" class="aebg-bulk-category-multiselect" style="width: 100%; min-height: 100px;">';
				
				<?php foreach ( $categories as $category ) : ?>
					categoryHtml += '<option value="<?php echo esc_js( $category->term_id ); ?>"><?php echo esc_js( $category->name ); ?></option>';
				<?php endforeach; ?>
				
				categoryHtml += '</select>' +
					'<span class="description" style="display: block; margin-top: 5px; font-style: italic;">' +
						'<?php echo esc_js( __( 'Hold Ctrl/Cmd to select multiple categories. Selected categories will be added to all selected posts.', 'aebg' ) ); ?>' +
					'</span>' +
					'<div class="aebg-bulk-category-actions" style="margin-top: 10px;">' +
						'<label style="display: inline-block; margin-right: 15px;">' +
							'<input type="radio" name="aebg_bulk_category_action" value="add" checked> ' +
							'<?php echo esc_js( __( 'Add categories', 'aebg' ) ); ?>' +
						'</label>' +
						'<label style="display: inline-block; margin-right: 15px;">' +
							'<input type="radio" name="aebg_bulk_category_action" value="replace"> ' +
							'<?php echo esc_js( __( 'Replace categories', 'aebg' ) ); ?>' +
						'</label>' +
						'<label style="display: inline-block;">' +
							'<input type="radio" name="aebg_bulk_category_action" value="remove"> ' +
							'<?php echo esc_js( __( 'Remove categories', 'aebg' ) ); ?>' +
						'</label>' +
					'</div>' +
				'</div>';
				
				// Insert after status field
				$statusField.after( categoryHtml );
			}
			
			// Watch for when bulk edit form is shown (WordPress uses inline editing)
			// WordPress triggers this when "Edit" is selected from bulk actions
			$(document).on('click', '#doaction, #doaction2', function(e) {
				var action = $(this).siblings('select').val();
				if (action === 'edit') {
					setTimeout(function() {
						addCategorySelector();
					}, 200);
				}
			});
			
			// Use MutationObserver for better compatibility with WordPress's dynamic form
			if ( typeof MutationObserver !== 'undefined' ) {
				var observer = new MutationObserver(function(mutations) {
					mutations.forEach(function(mutation) {
						if ( mutation.addedNodes.length > 0 ) {
							var $bulkEdit = $(mutation.addedNodes).find('#bulk-edit');
							if ( $bulkEdit.length > 0 || $(mutation.addedNodes).is('#bulk-edit') ) {
								setTimeout(function() {
									addCategorySelector();
								}, 200);
							}
						}
					});
				});
				
				observer.observe(document.body, {
					childList: true,
					subtree: true
				});
			}
			
			// Initial check in case form is already visible
			setTimeout(function() {
				addCategorySelector();
			}, 500);
		});
		</script>
		<?php
	}

	/**
	 * Process bulk category assignment.
	 */
	public function process_bulk_category_assignment() {
		// Check if this is a bulk edit request
		if ( ! isset( $_REQUEST['bulk_edit'] ) ) {
			return;
		}
		
		// Check if posts are selected
		if ( ! isset( $_REQUEST['post'] ) || ! is_array( $_REQUEST['post'] ) ) {
			return;
		}

		// Verify nonce
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-posts' ) ) {
			return;
		}
		
		// Only process for post type
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( $_GET['post_type'] ) : 'post';
		if ( 'post' !== $post_type ) {
			return;
		}

		// Check user permissions
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Get selected posts
		$post_ids = array_map( 'intval', $_REQUEST['post'] );
		if ( empty( $post_ids ) ) {
			return;
		}

		// Get category action and selected categories
		$category_action = isset( $_REQUEST['aebg_bulk_category_action'] ) ? sanitize_text_field( $_REQUEST['aebg_bulk_category_action'] ) : 'add';
		$category_ids = isset( $_REQUEST['aebg_bulk_categories'] ) ? array_map( 'intval', $_REQUEST['aebg_bulk_categories'] ) : [];

		// If no categories selected and action is "add", skip
		// For "replace" and "remove", empty array is valid (remove all or replace with none)
		if ( empty( $category_ids ) && 'add' === $category_action ) {
			return;
		}

		// Process each post
		$updated_count = 0;
		foreach ( $post_ids as $post_id ) {
			// Check if user can edit this post
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			// Get current categories
			$current_categories = wp_get_post_categories( $post_id );

			// Apply category action
			switch ( $category_action ) {
				case 'add':
					// Add categories (merge with existing)
					$new_categories = array_unique( array_merge( $current_categories, $category_ids ) );
					break;

				case 'replace':
					// Replace all categories
					$new_categories = $category_ids;
					break;

				case 'remove':
					// Remove selected categories
					$new_categories = array_diff( $current_categories, $category_ids );
					break;

				default:
					$new_categories = $current_categories;
					break;
			}

			// Update post categories
			$result = wp_set_post_categories( $post_id, $new_categories );
			
			if ( ! is_wp_error( $result ) ) {
				$updated_count++;
			}
		}

		// Add admin notice
		if ( $updated_count > 0 ) {
			$action_label = '';
			switch ( $category_action ) {
				case 'add':
					$action_label = __( 'added to', 'aebg' );
					break;
				case 'replace':
					$action_label = __( 'replaced in', 'aebg' );
					break;
				case 'remove':
					$action_label = __( 'removed from', 'aebg' );
					break;
			}

			add_action( 'admin_notices', function() use ( $updated_count, $action_label ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %1$d: number of posts, %2$s: action label */
							esc_html__( 'Categories %2$s %1$d post(s).', 'aebg' ),
							$updated_count,
							esc_html( $action_label )
						);
						?>
					</p>
				</div>
				<?php
			} );
		}
	}
}

