<?php
/**
 * Create Email Template Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$template_repository = new \AEBG\EmailMarketing\Repositories\TemplateRepository();

// Handle form submission
$message = '';
$message_type = '';
$created_template_id = 0;

if ( isset( $_POST['aebg_create_template'] ) && check_admin_referer( 'aebg_create_template' ) ) {
	$template_data = [
		'template_name' => sanitize_text_field( $_POST['template_name'] ?? '' ),
		'template_type' => sanitize_text_field( $_POST['template_type'] ?? 'custom' ),
		'subject_template' => sanitize_text_field( $_POST['subject_template'] ?? '' ),
		'content_html' => wp_kses_post( $_POST['content_html'] ?? '' ),
		'content_text' => sanitize_textarea_field( $_POST['content_text'] ?? '' ),
		'is_default' => isset( $_POST['is_default'] ) ? 1 : 0,
		'is_active' => isset( $_POST['is_active'] ) ? 1 : 0,
	];
	
	if ( empty( $template_data['template_name'] ) || empty( $template_data['subject_template'] ) || empty( $template_data['content_html'] ) ) {
		$message = __( 'Please fill in all required fields.', 'aebg' );
		$message_type = 'error';
	} else {
		$created_template_id = $template_repository->create( $template_data );
		
		if ( $created_template_id ) {
			$message = __( 'Template created successfully.', 'aebg' );
			$message_type = 'success';
			// Redirect to edit page after 2 seconds
			echo '<script>setTimeout(function(){ window.location.href = "' . esc_url( admin_url( 'admin.php?page=aebg-email-templates&action=edit&id=' . $created_template_id ) ) . '"; }, 2000);</script>';
		} else {
			$message = __( 'Failed to create template. Please try again.', 'aebg' );
			$message_type = 'error';
		}
	}
}

$type_labels = [
	'post_update' => __( 'Post Update', 'aebg' ),
	'product_reorder' => __( 'Product Reorder', 'aebg' ),
	'product_replace' => __( 'Product Replace', 'aebg' ),
	'new_post' => __( 'New Post', 'aebg' ),
	'custom' => __( 'Custom', 'aebg' ),
];

// Default values
$default_template = [
	'template_name' => '',
	'template_type' => 'custom',
	'subject_template' => '',
	'content_html' => '',
	'content_text' => '',
	'is_default' => 0,
	'is_active' => 1,
];

// If form was submitted but failed, preserve values
if ( isset( $_POST['aebg_create_template'] ) && ! $created_template_id ) {
	$default_template['template_name'] = sanitize_text_field( $_POST['template_name'] ?? '' );
	$default_template['template_type'] = sanitize_text_field( $_POST['template_type'] ?? 'custom' );
	$default_template['subject_template'] = sanitize_text_field( $_POST['subject_template'] ?? '' );
	$default_template['content_html'] = wp_kses_post( $_POST['content_html'] ?? '' );
	$default_template['content_text'] = sanitize_textarea_field( $_POST['content_text'] ?? '' );
	$default_template['is_default'] = isset( $_POST['is_default'] ) ? 1 : 0;
	$default_template['is_active'] = isset( $_POST['is_active'] ) ? 1 : 0;
}
?>

<div class="wrap aebg-email-templates-page">
	<h1><?php esc_html_e( 'Create Email Template', 'aebg' ); ?></h1>
	
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
		<?php wp_nonce_field( 'aebg_create_template' ); ?>
		
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
						value="<?php echo esc_attr( $default_template['template_name'] ); ?>" 
						required 
						aria-required="true"
						placeholder="<?php esc_attr_e( 'e.g., Newsletter Template', 'aebg' ); ?>"
					/>
				</div>
				
				<div class="aebg-form-group">
					<label for="template_type">
						<?php esc_html_e( 'Template Type', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<select id="template_type" name="template_type" class="aebg-select" required aria-required="true">
						<option value="custom" <?php selected( $default_template['template_type'], 'custom' ); ?>>
							<?php esc_html_e( 'Custom', 'aebg' ); ?>
						</option>
						<option value="post_update" <?php selected( $default_template['template_type'], 'post_update' ); ?>>
							<?php esc_html_e( 'Post Update', 'aebg' ); ?>
						</option>
						<option value="product_reorder" <?php selected( $default_template['template_type'], 'product_reorder' ); ?>>
							<?php esc_html_e( 'Product Reorder', 'aebg' ); ?>
						</option>
						<option value="product_replace" <?php selected( $default_template['template_type'], 'product_replace' ); ?>>
							<?php esc_html_e( 'Product Replace', 'aebg' ); ?>
						</option>
						<option value="new_post" <?php selected( $default_template['template_type'], 'new_post' ); ?>>
							<?php esc_html_e( 'New Post', 'aebg' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select the type of campaign this template will be used for. Custom templates can be used for any campaign type.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="is_default" 
							value="1" 
							<?php checked( $default_template['is_default'], 1 ); ?>
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
							<?php checked( $default_template['is_active'], 1 ); ?>
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
						value="<?php echo esc_attr( $default_template['subject_template'] ); ?>" 
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
						$default_template['content_html'],
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
					><?php echo esc_textarea( $default_template['content_text'] ); ?></textarea>
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
			<button type="submit" name="aebg_create_template" class="button button-primary button-large">
				<?php esc_html_e( 'Create Template', 'aebg' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-templates' ) ); ?>" class="button button-large">
				<?php esc_html_e( 'Cancel', 'aebg' ); ?>
			</a>
		</div>
	</form>
</div>

