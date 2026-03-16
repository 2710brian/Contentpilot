<?php
/**
 * Subscribers Admin Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_id = isset( $_GET['list_id'] ) ? (int) $_GET['list_id'] : 0;
$subscriber_manager = new \AEBG\EmailMarketing\Core\SubscriberManager();
$subscribers = $list_id ? $subscriber_manager->get_subscribers_by_list( $list_id ) : [];
?>

<div class="wrap aebg-email-subscribers-page">
	<h1><?php esc_html_e( 'Subscribers', 'aebg' ); ?></h1>
	
	<?php if ( $list_id ): ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-lists' ) ); ?>">&larr; <?php esc_html_e( 'Back to Lists', 'aebg' ); ?></a></p>
	<?php endif; ?>
	
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Email', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Name', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Subscribed', 'aebg' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'aebg' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $subscribers ) ): ?>
				<tr>
					<td colspan="5">
						<div class="aebg-empty-state aebg-email-empty-state">
							<span class="aebg-empty-state-icon dashicons dashicons-groups"></span>
							<h3><?php esc_html_e( 'No Subscribers Found', 'aebg' ); ?></h3>
							<p><?php esc_html_e( 'No subscribers have joined this list yet. Share your signup form to start collecting subscribers.', 'aebg' ); ?></p>
						</div>
					</td>
				</tr>
			<?php else: ?>
				<?php foreach ( $subscribers as $subscriber ): ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Email', 'aebg' ); ?>">
							<a href="mailto:<?php echo esc_attr( $subscriber->email ); ?>" aria-label="<?php esc_attr_e( 'Send email to', 'aebg' ); ?> <?php echo esc_attr( $subscriber->email ); ?>">
								<?php echo esc_html( $subscriber->email ); ?>
							</a>
						</td>
						<td data-label="<?php esc_attr_e( 'Name', 'aebg' ); ?>"><?php echo esc_html( trim( $subscriber->first_name . ' ' . $subscriber->last_name ) ?: '—' ); ?></td>
						<td data-label="<?php esc_attr_e( 'Status', 'aebg' ); ?>">
							<span class="aebg-status-badge aebg-status-<?php echo esc_attr( $subscriber->status ); ?>" role="status" aria-label="<?php echo esc_attr( ucfirst( $subscriber->status ) ); ?>">
								<?php echo esc_html( ucfirst( $subscriber->status ) ); ?>
							</span>
						</td>
						<td data-label="<?php esc_attr_e( 'Subscribed', 'aebg' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscriber->created_at ) ) ); ?></td>
						<td data-label="<?php esc_attr_e( 'Actions', 'aebg' ); ?>">
							<a href="#" class="aebg-remove-subscriber" data-id="<?php echo esc_attr( $subscriber->id ); ?>" aria-label="<?php esc_attr_e( 'Remove subscriber', 'aebg' ); ?>"><?php esc_html_e( 'Remove', 'aebg' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

