<?php
/**
 * Create Email List Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_repository = new \AEBG\EmailMarketing\Repositories\ListRepository();

// Handle form submission
$message = '';
$message_type = '';
$created_list_id = 0;

if ( isset( $_POST['aebg_create_list'] ) && check_admin_referer( 'aebg_create_list' ) ) {
	$list_data = [
		'list_name' => sanitize_text_field( $_POST['list_name'] ?? '' ),
		'list_type' => sanitize_text_field( $_POST['list_type'] ?? 'manual' ),
		'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
		'post_id' => ! empty( $_POST['post_id'] ) ? (int) $_POST['post_id'] : null,
		'is_active' => isset( $_POST['is_active'] ) ? 1 : 0,
	];
	
	// Handle settings if provided
	if ( ! empty( $_POST['settings'] ) ) {
		$settings = [];
		if ( isset( $_POST['double_opt_in'] ) ) {
			$settings['double_opt_in'] = true;
		}
		if ( isset( $_POST['welcome_email'] ) ) {
			$settings['welcome_email'] = true;
		}
		if ( ! empty( $settings ) ) {
			$list_data['settings'] = $settings;
		}
	}
	
	if ( empty( $list_data['list_name'] ) ) {
		$message = __( 'Please enter a list name.', 'aebg' );
		$message_type = 'error';
	} else {
		$created_list_id = $list_repository->create( $list_data );
		
		if ( $created_list_id ) {
			$message = __( 'Email list created successfully.', 'aebg' );
			$message_type = 'success';
			// Redirect to lists page after 2 seconds
			echo '<script>setTimeout(function(){ window.location.href = "' . esc_url( admin_url( 'admin.php?page=aebg-email-marketing' ) ) . '"; }, 2000);</script>';
		} else {
			$message = __( 'Failed to create email list. Please try again.', 'aebg' );
			$message_type = 'error';
		}
	}
}

$type_labels = [
	'post' => __( 'Post List', 'aebg' ),
	'manual' => __( 'Manual List', 'aebg' ),
	'segment' => __( 'Segment', 'aebg' ),
];

// Default values
$default_list = [
	'list_name' => '',
	'list_type' => 'manual',
	'description' => '',
	'post_id' => '',
	'is_active' => 1,
	'double_opt_in' => false,
	'welcome_email' => false,
];

// If form was submitted but failed, preserve values
if ( isset( $_POST['aebg_create_list'] ) && ! $created_list_id ) {
	$default_list['list_name'] = sanitize_text_field( $_POST['list_name'] ?? '' );
	$default_list['list_type'] = sanitize_text_field( $_POST['list_type'] ?? 'manual' );
	$default_list['description'] = sanitize_textarea_field( $_POST['description'] ?? '' );
	$default_list['post_id'] = ! empty( $_POST['post_id'] ) ? (int) $_POST['post_id'] : '';
	$default_list['is_active'] = isset( $_POST['is_active'] ) ? 1 : 0;
	$default_list['double_opt_in'] = isset( $_POST['double_opt_in'] );
	$default_list['welcome_email'] = isset( $_POST['welcome_email'] );
}

// Get recent posts for post selection
$recent_posts = get_posts( [
	'numberposts' => 20,
	'post_status' => 'publish',
	'orderby' => 'date',
	'order' => 'DESC',
] );
?>

<div class="wrap aebg-email-lists-page">
	<h1><?php esc_html_e( 'Create Email List', 'aebg' ); ?></h1>
	
	<?php if ( $message ): ?>
		<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>
	
	<div class="aebg-email-header">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-marketing' ) ); ?>" class="button">
			← <?php esc_html_e( 'Back to Lists', 'aebg' ); ?>
		</a>
	</div>
	
	<form method="post" action="" class="aebg-template-edit-form">
		<?php wp_nonce_field( 'aebg_create_list' ); ?>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'List Information', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label for="list_name">
						<?php esc_html_e( 'List Name', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<input 
						type="text" 
						id="list_name" 
						name="list_name" 
						class="aebg-input" 
						value="<?php echo esc_attr( $default_list['list_name'] ); ?>" 
						required 
						aria-required="true"
						placeholder="<?php esc_attr_e( 'e.g., Newsletter Subscribers', 'aebg' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'A descriptive name for this email list.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="list_type">
						<?php esc_html_e( 'List Type', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<select id="list_type" name="list_type" class="aebg-select" required aria-required="true">
						<option value="manual" <?php selected( $default_list['list_type'], 'manual' ); ?>>
							<?php esc_html_e( 'Manual List', 'aebg' ); ?>
						</option>
						<option value="post" <?php selected( $default_list['list_type'], 'post' ); ?>>
							<?php esc_html_e( 'Post List', 'aebg' ); ?>
						</option>
						<option value="segment" <?php selected( $default_list['list_type'], 'segment' ); ?>>
							<?php esc_html_e( 'Segment', 'aebg' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Manual lists are managed manually. Post lists are automatically associated with a specific post. Segments are filtered lists based on subscriber criteria.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group" id="post_id_group" style="<?php echo $default_list['list_type'] === 'post' ? '' : 'display: none;'; ?>">
					<label for="post_id">
						<?php esc_html_e( 'Associated Post', 'aebg' ); ?>
					</label>
					<select id="post_id" name="post_id" class="aebg-select">
						<option value=""><?php esc_html_e( '— Select a Post —', 'aebg' ); ?></option>
						<?php foreach ( $recent_posts as $post ): ?>
							<option value="<?php echo esc_attr( $post->ID ); ?>" <?php selected( $default_list['post_id'], $post->ID ); ?>>
								<?php echo esc_html( $post->post_title ); ?> (ID: <?php echo esc_html( $post->ID ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select a post to associate this list with. Leave empty for a general list.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="description">
						<?php esc_html_e( 'Description', 'aebg' ); ?>
					</label>
					<textarea 
						id="description" 
						name="description" 
						class="aebg-textarea" 
						rows="4"
						placeholder="<?php esc_attr_e( 'Optional description for this email list', 'aebg' ); ?>"
					><?php echo esc_textarea( $default_list['description'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'An optional description to help you identify this list.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'List Settings', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="is_active" 
							value="1" 
							<?php checked( $default_list['is_active'], 1 ); ?>
						/>
						<?php esc_html_e( 'Active', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Inactive lists will not accept new subscribers.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="double_opt_in" 
							value="1" 
							<?php checked( $default_list['double_opt_in'], true ); ?>
						/>
						<?php esc_html_e( 'Require Double Opt-In', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If enabled, subscribers must confirm their email address before being added to this list. This is recommended for GDPR compliance.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="welcome_email" 
							value="1" 
							<?php checked( $default_list['welcome_email'], true ); ?>
						/>
						<?php esc_html_e( 'Send Welcome Email', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If enabled, a welcome email will be sent to new subscribers when they join this list.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-form-actions">
			<button type="submit" name="aebg_create_list" class="button button-primary button-large">
				<?php esc_html_e( 'Create List', 'aebg' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-marketing' ) ); ?>" class="button button-large">
				<?php esc_html_e( 'Cancel', 'aebg' ); ?>
			</a>
		</div>
	</form>
</div>

<script>
(function($) {
	'use strict';
	
	$(document).ready(function() {
		// Show/hide post_id field based on list_type
		$('#list_type').on('change', function() {
			if ($(this).val() === 'post') {
				$('#post_id_group').slideDown();
			} else {
				$('#post_id_group').slideUp();
			}
		});
	});
	
})(jQuery);
</script>

