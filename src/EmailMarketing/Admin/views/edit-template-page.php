<?php
/**
 * Edit Email Template Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$template_repository = new \AEBG\EmailMarketing\Repositories\TemplateRepository();
$template = $template_repository->get( $template_id );

if ( ! $template ) {
	wp_die( __( 'Template not found.', 'aebg' ) );
}

// Handle form submission
$message = '';
$message_type = '';

if ( isset( $_POST['aebg_save_template'] ) && check_admin_referer( 'aebg_edit_template_' . $template_id ) ) {
	$update_data = [
		'template_name' => sanitize_text_field( $_POST['template_name'] ?? '' ),
		'subject_template' => sanitize_text_field( $_POST['subject_template'] ?? '' ),
		'content_html' => wp_kses_post( $_POST['content_html'] ?? '' ),
		'content_text' => sanitize_textarea_field( $_POST['content_text'] ?? '' ),
		'is_default' => isset( $_POST['is_default'] ) ? 1 : 0,
		'is_active' => isset( $_POST['is_active'] ) ? 1 : 0,
	];
	
	if ( $template_repository->update( $template_id, $update_data ) ) {
		$message = __( 'Template updated successfully.', 'aebg' );
		$message_type = 'success';
		// Reload template to show updated data
		$template = $template_repository->get( $template_id );
	} else {
		$message = __( 'Failed to update template. Please try again.', 'aebg' );
		$message_type = 'error';
	}
}

$type_labels = [
	'post_update' => __( 'Post Update', 'aebg' ),
	'product_reorder' => __( 'Product Reorder', 'aebg' ),
	'product_replace' => __( 'Product Replace', 'aebg' ),
	'new_post' => __( 'New Post', 'aebg' ),
	'custom' => __( 'Custom', 'aebg' ),
];
?>

<div class="wrap aebg-email-templates-page">
	<h1><?php esc_html_e( 'Edit Email Template', 'aebg' ); ?></h1>
	
	<?php if ( $message ): ?>
		<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>
	
	<div class="aebg-email-header">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-templates' ) ); ?>" class="button">
			← <?php esc_html_e( 'Back to Templates', 'aebg' ); ?>
		</a>
	</div>
	
	<form method="post" action="" class="aebg-template-edit-form">
		<?php wp_nonce_field( 'aebg_edit_template_' . $template_id ); ?>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'Template Information', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label for="template_name">
						<?php esc_html_e( 'Template Name', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<input 
						type="text" 
						id="template_name" 
						name="template_name" 
						class="aebg-input" 
						value="<?php echo esc_attr( $template->template_name ); ?>" 
						required 
						aria-required="true"
					/>
				</div>
				
				<div class="aebg-form-group">
					<label for="template_type">
						<?php esc_html_e( 'Template Type', 'aebg' ); ?>
					</label>
					<input 
						type="text" 
						id="template_type" 
						class="aebg-input" 
						value="<?php echo esc_attr( $type_labels[ $template->template_type ] ?? ucfirst( str_replace( '_', ' ', $template->template_type ) ) ); ?>" 
						readonly 
						disabled
					/>
					<p class="description">
						<?php esc_html_e( 'Template type cannot be changed after creation.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="is_default" 
							value="1" 
							<?php checked( $template->is_default, 1 ); ?>
						/>
						<?php esc_html_e( 'Set as default template for this type', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If checked, this template will be used as the default for automated campaigns of this type.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="is_active" 
							value="1" 
							<?php checked( $template->is_active, 1 ); ?>
						/>
						<?php esc_html_e( 'Active', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Inactive templates will not be available for use in campaigns.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'Email Content', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label for="subject_template">
						<?php esc_html_e( 'Email Subject', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<input 
						type="text" 
						id="subject_template" 
						name="subject_template" 
						class="aebg-input" 
						value="<?php echo esc_attr( $template->subject_template ); ?>" 
						required 
						aria-required="true"
						placeholder="<?php esc_attr_e( 'e.g., New Update: {post_title}', 'aebg' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Use template variables like {post_title}, {site_name}, etc.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="content_html">
						<?php esc_html_e( 'HTML Content', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<?php
					wp_editor(
						$template->content_html,
						'content_html',
						[
							'textarea_name' => 'content_html',
							'textarea_rows' => 20,
							'media_buttons' => false,
							'teeny' => false,
							'quicktags' => true,
						]
					);
					?>
					<p class="description">
						<?php esc_html_e( 'Use template variables like {post_title}, {post_url}, {post_excerpt}, {site_name}, {unsubscribe_url}, etc.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="content_text">
						<?php esc_html_e( 'Plain Text Content (Optional)', 'aebg' ); ?>
					</label>
					<textarea 
						id="content_text" 
						name="content_text" 
						class="aebg-textarea" 
						rows="15"
						placeholder="<?php esc_attr_e( 'Plain text version of the email (for email clients that don\'t support HTML)', 'aebg' ); ?>"
					><?php echo esc_textarea( $template->content_text ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'If left empty, a plain text version will be automatically generated from the HTML content.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-template-variables-info">
			<h3><?php esc_html_e( 'Available Template Variables', 'aebg' ); ?></h3>
			<p><?php esc_html_e( 'You can use these variables in your email templates:', 'aebg' ); ?></p>
			<ul class="aebg-template-variables-list">
				<li><code>{post_title}</code> - <?php esc_html_e( 'Post title', 'aebg' ); ?></li>
				<li><code>{post_url}</code> - <?php esc_html_e( 'Post permalink', 'aebg' ); ?></li>
				<li><code>{post_excerpt}</code> - <?php esc_html_e( 'Post excerpt', 'aebg' ); ?></li>
				<li><code>{post_content}</code> - <?php esc_html_e( 'Post content (truncated)', 'aebg' ); ?></li>
				<li><code>{site_name}</code> - <?php esc_html_e( 'Site name', 'aebg' ); ?></li>
				<li><code>{site_url}</code> - <?php esc_html_e( 'Site URL', 'aebg' ); ?></li>
				<li><code>{unsubscribe_url}</code> - <?php esc_html_e( 'Unsubscribe link', 'aebg' ); ?></li>
				<li><code>{subscriber_name}</code> - <?php esc_html_e( 'Subscriber name', 'aebg' ); ?></li>
				<li><code>{subscriber_email}</code> - <?php esc_html_e( 'Subscriber email', 'aebg' ); ?></li>
				<li><code>{product_list}</code> - <?php esc_html_e( 'List of products (for reorder/replace)', 'aebg' ); ?></li>
				<li><code>{new_product}</code> - <?php esc_html_e( 'New product details (for replacement)', 'aebg' ); ?></li>
			</ul>
		</div>
		
		<div class="aebg-form-actions">
			<button type="submit" name="aebg_save_template" class="button button-primary button-large">
				<?php esc_html_e( 'Save Template', 'aebg' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-templates&action=preview&id=' . $template_id ) ); ?>" class="button button-large" target="_blank">
				<?php esc_html_e( 'Preview', 'aebg' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-templates' ) ); ?>" class="button button-large">
				<?php esc_html_e( 'Cancel', 'aebg' ); ?>
			</a>
		</div>
	</form>
</div>

