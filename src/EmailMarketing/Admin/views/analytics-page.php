<?php
/**
 * Email Analytics Admin Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap aebg-email-analytics-page">
	<h1><?php esc_html_e( 'Email Marketing Analytics', 'aebg' ); ?></h1>
	
	<?php if ( empty( $overall_stats ) && empty( $recent_campaigns ) ): ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'No analytics data available yet. Analytics will appear once campaigns are sent.', 'aebg' ); ?></p>
		</div>
	<?php else: ?>
		
		<!-- Overall Stats Cards -->
		<div class="aebg-stats-grid">
			<?php if ( isset( $overall_stats['total_subscribers'] ) ): ?>
				<div class="aebg-stat-card">
					<h3><?php esc_html_e( 'Total Subscribers', 'aebg' ); ?></h3>
					<div class="stat-value">
						<?php echo esc_html( number_format_i18n( $overall_stats['total_subscribers'] ) ); ?>
					</div>
				</div>
			<?php endif; ?>
			
			<?php if ( isset( $overall_stats['total_campaigns'] ) ): ?>
				<div class="aebg-stat-card">
					<h3><?php esc_html_e( 'Total Campaigns', 'aebg' ); ?></h3>
					<div class="stat-value">
						<?php echo esc_html( number_format_i18n( $overall_stats['total_campaigns'] ) ); ?>
					</div>
				</div>
			<?php endif; ?>
			
			<?php if ( isset( $overall_stats['total_emails_sent'] ) ): ?>
				<div class="aebg-stat-card">
					<h3><?php esc_html_e( 'Emails Sent', 'aebg' ); ?></h3>
					<div class="stat-value">
						<?php echo esc_html( number_format_i18n( $overall_stats['total_emails_sent'] ) ); ?>
					</div>
				</div>
			<?php endif; ?>
			
			<?php if ( isset( $overall_stats['average_open_rate'] ) ): ?>
				<div class="aebg-stat-card">
					<h3><?php esc_html_e( 'Avg. Open Rate', 'aebg' ); ?></h3>
					<div class="stat-value" style="color: #10b981;">
						<?php echo esc_html( number_format_i18n( $overall_stats['average_open_rate'], 1 ) ); ?>%
					</div>
				</div>
			<?php endif; ?>
			
			<?php if ( isset( $overall_stats['average_click_rate'] ) ): ?>
				<div class="aebg-stat-card">
					<h3><?php esc_html_e( 'Avg. Click Rate', 'aebg' ); ?></h3>
					<div class="stat-value" style="color: #10b981;">
						<?php echo esc_html( number_format_i18n( $overall_stats['average_click_rate'], 1 ) ); ?>%
					</div>
				</div>
			<?php endif; ?>
		</div>
		
		<!-- Recent Campaigns -->
		<?php if ( ! empty( $recent_campaigns ) ): ?>
			<h2 class="aebg-analytics-section-title"><?php esc_html_e( 'Recent Campaigns', 'aebg' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Campaign', 'aebg' ); ?></th>
						<th><?php esc_html_e( 'Sent', 'aebg' ); ?></th>
						<th><?php esc_html_e( 'Opened', 'aebg' ); ?></th>
						<th><?php esc_html_e( 'Clicked', 'aebg' ); ?></th>
						<th><?php esc_html_e( 'Open Rate', 'aebg' ); ?></th>
						<th><?php esc_html_e( 'Click Rate', 'aebg' ); ?></th>
						<th><?php esc_html_e( 'Date', 'aebg' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent_campaigns as $campaign ): ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Campaign', 'aebg' ); ?>"><strong><?php echo esc_html( $campaign->campaign_name ?? __( 'Unnamed Campaign', 'aebg' ) ); ?></strong></td>
							<td data-label="<?php esc_attr_e( 'Sent', 'aebg' ); ?>"><?php echo esc_html( number_format_i18n( $campaign->emails_sent ?? 0 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Opened', 'aebg' ); ?>"><?php echo esc_html( number_format_i18n( $campaign->emails_opened ?? 0 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Clicked', 'aebg' ); ?>"><?php echo esc_html( number_format_i18n( $campaign->clicks ?? 0 ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Open Rate', 'aebg' ); ?>">
								<?php 
								$open_rate = ( $campaign->emails_sent > 0 ) ? ( ( $campaign->emails_opened / $campaign->emails_sent ) * 100 ) : 0;
								echo esc_html( number_format_i18n( $open_rate, 1 ) ); ?>%
							</td>
							<td data-label="<?php esc_attr_e( 'Click Rate', 'aebg' ); ?>">
								<?php 
								$click_rate = ( $campaign->emails_sent > 0 ) ? ( ( $campaign->clicks / $campaign->emails_sent ) * 100 ) : 0;
								echo esc_html( number_format_i18n( $click_rate, 1 ) ); ?>%
							</td>
							<td data-label="<?php esc_attr_e( 'Date', 'aebg' ); ?>">
								<?php 
								if ( isset( $campaign->sent_at ) ) {
									echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $campaign->sent_at ) ) );
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		
	<?php endif; ?>
</div>

