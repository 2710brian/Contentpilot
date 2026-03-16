<?php
/**
 * Campaigns Admin Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap aebg-email-campaigns-page">
	<h1><?php esc_html_e( 'Email Campaigns', 'aebg' ); ?></h1>
	
	<div class="aebg-email-header">
		<a href="#" class="button button-primary aebg-create-campaign-btn"><?php esc_html_e( 'Create Campaign', 'aebg' ); ?></a>
	</div>
	
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Name', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Type', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Created', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'aebg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $campaigns ) ): ?>
				<tr>
					<td colspan="6">
						<div class="aebg-empty-state aebg-email-empty-state">
							<span class="aebg-empty-state-icon dashicons dashicons-megaphone"></span>
							<h3><?php esc_html_e( 'No Campaigns Found', 'aebg' ); ?></h3>
							<p><?php esc_html_e( 'Create your first email campaign to start sending emails to your subscribers.', 'aebg' ); ?></p>
							<a href="#" class="button button-primary aebg-create-campaign-btn"><?php esc_html_e( 'Create Campaign', 'aebg' ); ?></a>
						</div>
					</td>
				</tr>
			<?php else: ?>
				<?php foreach ( $campaigns as $campaign ): ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'ID', 'aebg' ); ?>"><?php echo esc_html( $campaign->id ); ?></td>
						<td data-label="<?php esc_attr_e( 'Name', 'aebg' ); ?>"><strong><?php echo esc_html( $campaign->campaign_name ); ?></strong></td>
						<td data-label="<?php esc_attr_e( 'Type', 'aebg' ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $campaign->campaign_type ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Status', 'aebg' ); ?>">
							<span class="aebg-status-badge aebg-status-<?php echo esc_attr( $campaign->status ); ?>" role="status" aria-label="<?php echo esc_attr( ucfirst( $campaign->status ) ); ?>">
								<?php echo esc_html( ucfirst( $campaign->status ) ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( 'Created', 'aebg' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $campaign->created_at ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Actions', 'aebg' ); ?>">
							<a href="#" class="aebg-view-campaign" data-campaign-id="<?php echo esc_attr( $campaign->id ); ?>" aria-label="<?php esc_attr_e( 'View campaign details', 'aebg' ); ?>"><?php esc_html_e( 'View', 'aebg' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

