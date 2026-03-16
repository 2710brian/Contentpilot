<?php
/**
 * Email Templates Admin Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap aebg-email-templates-page">
	<h1><?php esc_html_e( 'Email Templates', 'aebg' ); ?></h1>
	
	<div class="notice notice-info">
		<p>
			<strong><?php esc_html_e( 'Default Templates:', 'aebg' ); ?></strong>
			<?php esc_html_e( 'These templates are used for automated email campaigns. You can edit them to customize the email content sent when posts are updated, products are reordered, or new posts are published.', 'aebg' ); ?>
		</p>
	</div>
	
	<div class="aebg-email-header">
		<a href="#" class="button button-primary aebg-create-template-btn"><?php esc_html_e( 'Create Custom Template', 'aebg' ); ?></a>
	</div>
	
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Name', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Type', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Default', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Created', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'aebg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $templates ) ): ?>
				<tr>
					<td colspan="7">
						<div class="aebg-empty-state aebg-email-empty-state">
							<span class="aebg-empty-state-icon dashicons dashicons-email-alt2"></span>
							<h3><?php esc_html_e( 'No Templates Found', 'aebg' ); ?></h3>
							<p><?php esc_html_e( 'Create your first email template to customize your campaign emails.', 'aebg' ); ?></p>
							<a href="#" class="button button-primary aebg-create-template-btn"><?php esc_html_e( 'Create Template', 'aebg' ); ?></a>
						</div>
					</td>
				</tr>
			<?php else: ?>
				<?php foreach ( $templates as $template ): ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'ID', 'aebg' ); ?>"><?php echo esc_html( $template->id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Name', 'aebg' ); ?>">
							<strong><?php echo esc_html( $template->template_name ); ?></strong>
							<?php if ( $template->is_default ): ?>
								<span class="dashicons dashicons-star-filled aebg-template-default-icon" title="<?php esc_attr_e( 'Default template for this campaign type', 'aebg' ); ?>" aria-label="<?php esc_attr_e( 'Default template', 'aebg' ); ?>"></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Type', 'aebg' ); ?>">
							<?php 
							$type_labels = [
								'post_update' => __( 'Post Update', 'aebg' ),
								'product_reorder' => __( 'Product Reorder', 'aebg' ),
								'product_replace' => __( 'Product Replace', 'aebg' ),
								'new_post' => __( 'New Post', 'aebg' ),
								'custom' => __( 'Custom', 'aebg' ),
							];
							echo esc_html( $type_labels[ $template->template_type ] ?? ucfirst( str_replace( '_', ' ', $template->template_type ) ) );
							?>
						</td>
						<td data-label="<?php esc_attr_e( 'Subject', 'aebg' ); ?>">
							<code class="aebg-template-subject">
								<?php echo esc_html( substr( $template->subject_template, 0, 50 ) . ( strlen( $template->subject_template ) > 50 ? '...' : '' ) ); ?>
							</code>
						</td>
						<td data-label="<?php esc_attr_e( 'Default', 'aebg' ); ?>">
							<?php if ( $template->is_default ): ?>
								<span class="dashicons dashicons-yes aebg-template-default-check" aria-label="<?php esc_attr_e( 'Default template', 'aebg' ); ?>"></span>
							<?php else: ?>
								—
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Created', 'aebg' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $template->created_at ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Actions', 'aebg' ); ?>">
							<a href="#" class="aebg-edit-template" data-template-id="<?php echo esc_attr( $template->id ); ?>" aria-label="<?php esc_attr_e( 'Edit template', 'aebg' ); ?>">
								<?php esc_html_e( 'Edit', 'aebg' ); ?>
							</a>
							<span class="aebg-action-separator" aria-hidden="true">|</span>
							<a href="#" class="aebg-preview-template" data-template-id="<?php echo esc_attr( $template->id ); ?>" aria-label="<?php esc_attr_e( 'Preview template', 'aebg' ); ?>">
								<?php esc_html_e( 'Preview', 'aebg' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	
	<div class="aebg-template-variables-info">
		<h3><?php esc_html_e( 'Available Template Variables', 'aebg' ); ?></h3>
		<p><?php esc_html_e( 'You can use these variables in your email templates:', 'aebg' ); ?></p>
		<ul class="aebg-template-variables-list">
			<li><code>{post_title}</code> - <?php esc_html_e( 'Post title', 'aebg' ); ?></li>
			<li><code>{post_url}</code> - <?php esc_html_e( 'Post permalink', 'aebg' ); ?></li>
			<li><code>{post_excerpt}</code> - <?php esc_html_e( 'Post excerpt', 'aebg' ); ?></li>
			<li><code>{site_name}</code> - <?php esc_html_e( 'Site name', 'aebg' ); ?></li>
			<li><code>{site_url}</code> - <?php esc_html_e( 'Site URL', 'aebg' ); ?></li>
			<li><code>{unsubscribe_url}</code> - <?php esc_html_e( 'Unsubscribe link', 'aebg' ); ?></li>
			<li><code>{product_list}</code> - <?php esc_html_e( 'List of products (for reorder/replace)', 'aebg' ); ?></li>
			<li><code>{new_product}</code> - <?php esc_html_e( 'New product details (for replacement)', 'aebg' ); ?></li>
		</ul>
	</div>
</div>

