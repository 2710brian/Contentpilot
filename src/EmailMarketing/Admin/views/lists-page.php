<?php
/**
 * Email Lists Admin Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap aebg-email-lists-page">
	<h1><?php esc_html_e( 'Email Lists', 'aebg' ); ?></h1>
	
	<div class="aebg-email-header">
		<a href="#" class="button button-primary aebg-create-list-btn"><?php esc_html_e( 'Create List', 'aebg' ); ?></a>
	</div>
	
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Name', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Type', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Subscribers', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Created', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'aebg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $lists ) ): ?>
				<tr>
					<td colspan="6">
						<div class="aebg-empty-state aebg-email-empty-state">
							<span class="aebg-empty-state-icon dashicons dashicons-email-alt"></span>
							<h3><?php esc_html_e( 'No Email Lists Found', 'aebg' ); ?></h3>
							<p><?php esc_html_e( 'Create your first email list to start collecting subscribers.', 'aebg' ); ?></p>
							<a href="#" class="button button-primary aebg-create-list-btn"><?php esc_html_e( 'Create List', 'aebg' ); ?></a>
						</div>
					</td>
				</tr>
			<?php else: ?>
				<?php foreach ( $lists as $list ): ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'ID', 'aebg' ); ?>"><?php echo esc_html( $list->id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Name', 'aebg' ); ?>"><strong><?php echo esc_html( $list->list_name ); ?></strong></td>
						<td data-label="<?php esc_attr_e( 'Type', 'aebg' ); ?>"><?php echo esc_html( ucfirst( $list->list_type ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Subscribers', 'aebg' ); ?>"><?php echo esc_html( number_format_i18n( $list->subscriber_count ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Created', 'aebg' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $list->created_at ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Actions', 'aebg' ); ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-subscribers&list_id=' . $list->id ) ); ?>" aria-label="<?php esc_attr_e( 'View subscribers in this list', 'aebg' ); ?>"><?php esc_html_e( 'View', 'aebg' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

