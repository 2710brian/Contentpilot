<?php
/**
 * Create Email Campaign Page
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$campaign_repository = new \AEBG\EmailMarketing\Repositories\CampaignRepository();
$template_repository = new \AEBG\EmailMarketing\Repositories\TemplateRepository();
$list_repository = new \AEBG\EmailMarketing\Repositories\ListRepository();

// Get available templates and lists
$templates = $template_repository->get_all();
$lists = $list_repository->get_all();

// Handle form submission
$message = '';
$message_type = '';
$created_campaign_id = 0;

if ( isset( $_POST['aebg_create_campaign'] ) && check_admin_referer( 'aebg_create_campaign' ) ) {
	$campaign_data = [
		'campaign_name' => sanitize_text_field( $_POST['campaign_name'] ?? '' ),
		'campaign_type' => sanitize_text_field( $_POST['campaign_type'] ?? 'manual' ),
		'template_id' => ! empty( $_POST['template_id'] ) ? (int) $_POST['template_id'] : null,
		'subject' => sanitize_text_field( $_POST['subject'] ?? '' ),
		'content_html' => wp_kses_post( $_POST['content_html'] ?? '' ),
		'content_text' => sanitize_textarea_field( $_POST['content_text'] ?? '' ),
		'status' => sanitize_text_field( $_POST['status'] ?? 'draft' ),
		'post_id' => ! empty( $_POST['post_id'] ) ? (int) $_POST['post_id'] : null,
	];
	
	// Handle list IDs
	if ( ! empty( $_POST['list_ids'] ) && is_array( $_POST['list_ids'] ) ) {
		$campaign_data['list_ids'] = array_map( 'intval', $_POST['list_ids'] );
	} else {
		$campaign_data['list_ids'] = [];
	}
	
	// Handle scheduled date/time
	if ( ! empty( $_POST['schedule_campaign'] ) && ! empty( $_POST['scheduled_date'] ) && ! empty( $_POST['scheduled_time'] ) ) {
		$scheduled_datetime = sanitize_text_field( $_POST['scheduled_date'] ) . ' ' . sanitize_text_field( $_POST['scheduled_time'] );
		$campaign_data['scheduled_at'] = date( 'Y-m-d H:i:s', strtotime( $scheduled_datetime ) );
		$campaign_data['status'] = 'scheduled';
	}
	
	// Handle settings
	$settings = [];
	if ( isset( $_POST['track_opens'] ) ) {
		$settings['track_opens'] = true;
	}
	if ( isset( $_POST['track_clicks'] ) ) {
		$settings['track_clicks'] = true;
	}
	if ( ! empty( $settings ) ) {
		$campaign_data['settings'] = $settings;
	}
	
	// Validation
	if ( empty( $campaign_data['campaign_name'] ) ) {
		$message = __( 'Please enter a campaign name.', 'aebg' );
		$message_type = 'error';
	} elseif ( empty( $campaign_data['subject'] ) ) {
		$message = __( 'Please enter an email subject.', 'aebg' );
		$message_type = 'error';
	} elseif ( empty( $campaign_data['content_html'] ) ) {
		$message = __( 'Please enter email content.', 'aebg' );
		$message_type = 'error';
	} elseif ( empty( $campaign_data['list_ids'] ) ) {
		$message = __( 'Please select at least one email list.', 'aebg' );
		$message_type = 'error';
	} else {
		$created_campaign_id = $campaign_repository->create( $campaign_data );
		
		if ( $created_campaign_id ) {
			$message = __( 'Email campaign created successfully.', 'aebg' );
			$message_type = 'success';
			// Redirect to campaigns page after 2 seconds
			echo '<script>setTimeout(function(){ window.location.href = "' . esc_url( admin_url( 'admin.php?page=aebg-email-campaigns' ) ) . '"; }, 2000);</script>';
		} else {
			$message = __( 'Failed to create email campaign. Please try again.', 'aebg' );
			$message_type = 'error';
		}
	}
}

$type_labels = [
	'manual' => __( 'Manual Campaign', 'aebg' ),
	'post_update' => __( 'Post Update', 'aebg' ),
	'product_reorder' => __( 'Product Reorder', 'aebg' ),
	'product_replace' => __( 'Product Replace', 'aebg' ),
	'new_post' => __( 'New Post', 'aebg' ),
];

// Default values
$default_campaign = [
	'campaign_name' => '',
	'campaign_type' => 'manual',
	'template_id' => '',
	'subject' => '',
	'content_html' => '',
	'content_text' => '',
	'status' => 'draft',
	'post_id' => '',
	'list_ids' => [],
	'schedule_campaign' => false,
	'scheduled_date' => '',
	'scheduled_time' => '',
	'track_opens' => true,
	'track_clicks' => true,
];

// If form was submitted but failed, preserve values
if ( isset( $_POST['aebg_create_campaign'] ) && ! $created_campaign_id ) {
	$default_campaign['campaign_name'] = sanitize_text_field( $_POST['campaign_name'] ?? '' );
	$default_campaign['campaign_type'] = sanitize_text_field( $_POST['campaign_type'] ?? 'manual' );
	$default_campaign['template_id'] = ! empty( $_POST['template_id'] ) ? (int) $_POST['template_id'] : '';
	$default_campaign['subject'] = sanitize_text_field( $_POST['subject'] ?? '' );
	$default_campaign['content_html'] = wp_kses_post( $_POST['content_html'] ?? '' );
	$default_campaign['content_text'] = sanitize_textarea_field( $_POST['content_text'] ?? '' );
	$default_campaign['status'] = sanitize_text_field( $_POST['status'] ?? 'draft' );
	$default_campaign['post_id'] = ! empty( $_POST['post_id'] ) ? (int) $_POST['post_id'] : '';
	$default_campaign['list_ids'] = ! empty( $_POST['list_ids'] ) && is_array( $_POST['list_ids'] ) ? array_map( 'intval', $_POST['list_ids'] ) : [];
	$default_campaign['schedule_campaign'] = isset( $_POST['schedule_campaign'] );
	$default_campaign['scheduled_date'] = sanitize_text_field( $_POST['scheduled_date'] ?? '' );
	$default_campaign['scheduled_time'] = sanitize_text_field( $_POST['scheduled_time'] ?? '' );
	$default_campaign['track_opens'] = isset( $_POST['track_opens'] );
	$default_campaign['track_clicks'] = isset( $_POST['track_clicks'] );
}

// Get recent posts for post selection
$recent_posts = get_posts( [
	'numberposts' => 20,
	'post_status' => 'publish',
	'orderby' => 'date',
	'order' => 'DESC',
] );
?>

<div class="wrap aebg-email-campaigns-page">
	<h1><?php esc_html_e( 'Create Email Campaign', 'aebg' ); ?></h1>
	
	<?php if ( $message ): ?>
		<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>
	
	<div class="aebg-email-header">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-campaigns' ) ); ?>" class="button">
			← <?php esc_html_e( 'Back to Campaigns', 'aebg' ); ?>
		</a>
	</div>
	
	<form method="post" action="" class="aebg-template-edit-form">
		<?php wp_nonce_field( 'aebg_create_campaign' ); ?>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'Campaign Information', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label for="campaign_name">
						<?php esc_html_e( 'Campaign Name', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<input 
						type="text" 
						id="campaign_name" 
						name="campaign_name" 
						class="aebg-input" 
						value="<?php echo esc_attr( $default_campaign['campaign_name'] ); ?>" 
						required 
						aria-required="true"
						placeholder="<?php esc_attr_e( 'e.g., Weekly Newsletter', 'aebg' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'A descriptive name for this email campaign.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="campaign_type">
						<?php esc_html_e( 'Campaign Type', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<select id="campaign_type" name="campaign_type" class="aebg-select" required aria-required="true">
						<option value="manual" <?php selected( $default_campaign['campaign_type'], 'manual' ); ?>>
							<?php esc_html_e( 'Manual Campaign', 'aebg' ); ?>
						</option>
						<option value="post_update" <?php selected( $default_campaign['campaign_type'], 'post_update' ); ?>>
							<?php esc_html_e( 'Post Update', 'aebg' ); ?>
						</option>
						<option value="product_reorder" <?php selected( $default_campaign['campaign_type'], 'product_reorder' ); ?>>
							<?php esc_html_e( 'Product Reorder', 'aebg' ); ?>
						</option>
						<option value="product_replace" <?php selected( $default_campaign['campaign_type'], 'product_replace' ); ?>>
							<?php esc_html_e( 'Product Replace', 'aebg' ); ?>
						</option>
						<option value="new_post" <?php selected( $default_campaign['campaign_type'], 'new_post' ); ?>>
							<?php esc_html_e( 'New Post', 'aebg' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select the type of campaign. Manual campaigns are sent manually, while other types are triggered automatically.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="template_id">
						<?php esc_html_e( 'Email Template (Optional)', 'aebg' ); ?>
					</label>
					<select id="template_id" name="template_id" class="aebg-select">
						<option value=""><?php esc_html_e( '— Use Custom Content —', 'aebg' ); ?></option>
						<?php foreach ( $templates as $template ): ?>
							<option value="<?php echo esc_attr( $template->id ); ?>" <?php selected( $default_campaign['template_id'], $template->id ); ?>>
								<?php echo esc_html( $template->template_name ); ?> (<?php echo esc_html( ucfirst( str_replace( '_', ' ', $template->template_type ) ) ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select a template to pre-fill the email content, or leave empty to create custom content.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group" id="post_id_group" style="<?php echo in_array( $default_campaign['campaign_type'], ['post_update', 'product_reorder', 'product_replace', 'new_post'] ) ? '' : 'display: none;'; ?>">
					<label for="post_id">
						<?php esc_html_e( 'Associated Post', 'aebg' ); ?>
					</label>
					<select id="post_id" name="post_id" class="aebg-select">
						<option value=""><?php esc_html_e( '— Select a Post —', 'aebg' ); ?></option>
						<?php foreach ( $recent_posts as $post ): ?>
							<option value="<?php echo esc_attr( $post->ID ); ?>" <?php selected( $default_campaign['post_id'], $post->ID ); ?>>
								<?php echo esc_html( $post->post_title ); ?> (ID: <?php echo esc_html( $post->ID ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select a post to associate this campaign with. Required for automatic campaign types.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'Email Lists', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label>
						<?php esc_html_e( 'Select Email Lists', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<?php if ( empty( $lists ) ): ?>
						<p class="description" style="color: #d63638;">
							<?php esc_html_e( 'No email lists found. Please create an email list first.', 'aebg' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-marketing&action=create_list' ) ); ?>">
								<?php esc_html_e( 'Create List', 'aebg' ); ?>
							</a>
						</p>
					<?php else: ?>
						<div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--aebg-gray-300); border-radius: var(--aebg-radius-md); padding: var(--aebg-spacing-md);">
							<?php foreach ( $lists as $list ): ?>
								<label style="display: block; margin-bottom: var(--aebg-spacing-sm);">
									<input 
										type="checkbox" 
										name="list_ids[]" 
										value="<?php echo esc_attr( $list->id ); ?>"
										<?php checked( in_array( $list->id, $default_campaign['list_ids'] ), true ); ?>
									/>
									<strong><?php echo esc_html( $list->list_name ); ?></strong>
									<span style="color: var(--aebg-gray-600); font-size: 13px;">
										(<?php echo esc_html( number_format_i18n( $list->subscriber_count ) ); ?> <?php esc_html_e( 'subscribers', 'aebg' ); ?>)
									</span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Select one or more email lists to send this campaign to.', 'aebg' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'Email Content', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label for="subject">
						<?php esc_html_e( 'Email Subject', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<input 
						type="text" 
						id="subject" 
						name="subject" 
						class="aebg-input" 
						value="<?php echo esc_attr( $default_campaign['subject'] ); ?>" 
						required 
						aria-required="true"
						placeholder="<?php esc_attr_e( 'e.g., Check out our latest update!', 'aebg' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'The subject line that will appear in the recipient\'s inbox.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label for="content_html">
						<?php esc_html_e( 'HTML Content', 'aebg' ); ?>
						<span class="aebg-required" aria-label="required">*</span>
					</label>
					<?php
					wp_editor(
						$default_campaign['content_html'],
						'content_html',
						[
							'textarea_name' => 'content_html',
							'textarea_rows' => 20,
							'media_buttons' => true,
							'teeny' => false,
							'quicktags' => true,
						]
					);
					?>
					<p class="description">
						<?php esc_html_e( 'The HTML content of your email. You can use template variables like {post_title}, {post_url}, {site_name}, etc.', 'aebg' ); ?>
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
					><?php echo esc_textarea( $default_campaign['content_text'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'If left empty, a plain text version will be automatically generated from the HTML content.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-settings-card">
			<div class="aebg-card-header">
				<h2><?php esc_html_e( 'Campaign Settings', 'aebg' ); ?></h2>
			</div>
			<div class="aebg-card-content">
				<div class="aebg-form-group">
					<label for="status">
						<?php esc_html_e( 'Status', 'aebg' ); ?>
					</label>
					<select id="status" name="status" class="aebg-select">
						<option value="draft" <?php selected( $default_campaign['status'], 'draft' ); ?>>
							<?php esc_html_e( 'Draft', 'aebg' ); ?>
						</option>
						<option value="ready" <?php selected( $default_campaign['status'], 'ready' ); ?>>
							<?php esc_html_e( 'Ready to Send', 'aebg' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Draft campaigns are saved but not sent. Ready campaigns can be sent immediately or scheduled.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="schedule_campaign" 
							value="1" 
							id="schedule_campaign"
							<?php checked( $default_campaign['schedule_campaign'], true ); ?>
						/>
						<?php esc_html_e( 'Schedule Campaign', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If enabled, you can schedule when this campaign should be sent.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group" id="schedule_fields" style="<?php echo $default_campaign['schedule_campaign'] ? '' : 'display: none;'; ?>">
					<label for="scheduled_date">
						<?php esc_html_e( 'Scheduled Date', 'aebg' ); ?>
					</label>
					<input 
						type="date" 
						id="scheduled_date" 
						name="scheduled_date" 
						class="aebg-input" 
						value="<?php echo esc_attr( $default_campaign['scheduled_date'] ); ?>"
						min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
					/>
					
					<label for="scheduled_time" style="margin-top: var(--aebg-spacing-md);">
						<?php esc_html_e( 'Scheduled Time', 'aebg' ); ?>
					</label>
					<input 
						type="time" 
						id="scheduled_time" 
						name="scheduled_time" 
						class="aebg-input" 
						value="<?php echo esc_attr( $default_campaign['scheduled_time'] ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Select the date and time when this campaign should be sent.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="track_opens" 
							value="1" 
							<?php checked( $default_campaign['track_opens'], true ); ?>
						/>
						<?php esc_html_e( 'Track Email Opens', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If enabled, the system will track when recipients open this email.', 'aebg' ); ?>
					</p>
				</div>
				
				<div class="aebg-form-group">
					<label>
						<input 
							type="checkbox" 
							name="track_clicks" 
							value="1" 
							<?php checked( $default_campaign['track_clicks'], true ); ?>
						/>
						<?php esc_html_e( 'Track Link Clicks', 'aebg' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'If enabled, the system will track when recipients click links in this email.', 'aebg' ); ?>
					</p>
				</div>
			</div>
		</div>
		
		<div class="aebg-form-actions">
			<button type="submit" name="aebg_create_campaign" class="button button-primary button-large">
				<?php esc_html_e( 'Create Campaign', 'aebg' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=aebg-email-campaigns' ) ); ?>" class="button button-large">
				<?php esc_html_e( 'Cancel', 'aebg' ); ?>
			</a>
		</div>
	</form>
</div>

<script>
(function($) {
	'use strict';
	
	$(document).ready(function() {
		// Show/hide post_id field based on campaign_type
		$('#campaign_type').on('change', function() {
			const campaignType = $(this).val();
			if (['post_update', 'product_reorder', 'product_replace', 'new_post'].includes(campaignType)) {
				$('#post_id_group').slideDown();
			} else {
				$('#post_id_group').slideUp();
			}
		});
		
		// Show/hide schedule fields
		$('#schedule_campaign').on('change', function() {
			if ($(this).is(':checked')) {
				$('#schedule_fields').slideDown();
			} else {
				$('#schedule_fields').slideUp();
			}
		});
		
		// Load template content when template is selected
		$('#template_id').on('change', function() {
			const templateId = $(this).val();
			if (templateId) {
				// TODO: Load template content via AJAX
				// For now, just show a message
				alert('Template loading feature coming soon. Please manually enter content.');
			}
		});
	});
	
})(jQuery);
</script>

