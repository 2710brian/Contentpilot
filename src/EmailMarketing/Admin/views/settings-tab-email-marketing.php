<?php
/**
 * Email Marketing Settings Tab
 * 
 * @package AEBG\EmailMarketing\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_marketing_enabled = get_option( 'aebg_email_marketing_enabled', true );
$post_update_enabled = get_option( 'aebg_email_campaign_post_update_enabled', true );
$product_reorder_enabled = get_option( 'aebg_email_campaign_product_reorder_enabled', true );
$product_replace_enabled = get_option( 'aebg_email_campaign_product_replace_enabled', true );
$new_post_enabled = get_option( 'aebg_email_campaign_new_post_enabled', true );
$from_name = get_option( 'aebg_email_from_name', get_bloginfo( 'name' ) );
$from_email = get_option( 'aebg_email_from_email', get_option( 'admin_email' ) );
$double_opt_in = get_option( 'aebg_email_double_opt_in', true );
?>

<div class="aebg-settings-section">
	<h2><?php esc_html_e( 'Email Marketing Settings', 'aebg' ); ?></h2>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_marketing_enabled" value="1" <?php checked( $email_marketing_enabled ); ?>>
			<?php esc_html_e( 'Enable Email Marketing', 'aebg' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Enable the email marketing system', 'aebg' ); ?></p>
	</div>
	
	<h3><?php esc_html_e( 'Campaign Triggers', 'aebg' ); ?></h3>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_campaign_post_update_enabled" value="1" <?php checked( $post_update_enabled ); ?>>
			<?php esc_html_e( 'Send email when post is updated', 'aebg' ); ?>
		</label>
	</div>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_campaign_product_reorder_enabled" value="1" <?php checked( $product_reorder_enabled ); ?>>
			<?php esc_html_e( 'Send email when products are reordered', 'aebg' ); ?>
		</label>
	</div>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_campaign_product_replace_enabled" value="1" <?php checked( $product_replace_enabled ); ?>>
			<?php esc_html_e( 'Send email when product is replaced', 'aebg' ); ?>
		</label>
	</div>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_campaign_new_post_enabled" value="1" <?php checked( $new_post_enabled ); ?>>
			<?php esc_html_e( 'Send email when new post is published', 'aebg' ); ?>
		</label>
	</div>
	
	<h3><?php esc_html_e( 'Email Settings', 'aebg' ); ?></h3>
	
	<div class="aebg-form-group">
		<label for="aebg_email_from_name"><?php esc_html_e( 'From Name', 'aebg' ); ?></label>
		<input type="text" id="aebg_email_from_name" name="aebg_email_from_name" value="<?php echo esc_attr( $from_name ); ?>" class="regular-text">
	</div>
	
	<div class="aebg-form-group">
		<label for="aebg_email_from_email"><?php esc_html_e( 'From Email', 'aebg' ); ?></label>
		<input type="email" id="aebg_email_from_email" name="aebg_email_from_email" value="<?php echo esc_attr( $from_email ); ?>" class="regular-text">
	</div>
	
	<h3><?php esc_html_e( 'Elementor Forms Integration', 'aebg' ); ?></h3>
	
	<div class="aebg-form-group">
		<label for="aebg_webhook_url"><?php esc_html_e( 'Webhook URL', 'aebg' ); ?></label>
		<div style="display: flex; gap: 8px; align-items: center;">
			<input 
				type="text" 
				id="aebg_webhook_url" 
				readonly 
				value="<?php echo esc_attr( rest_url( 'aebg/v1/email-marketing/webhook' ) ); ?>" 
				class="regular-text code"
				style="font-family: monospace; background: #f5f5f5;"
				onclick="this.select();"
			>
			<button 
				type="button" 
				class="button button-small" 
				onclick="document.getElementById('aebg_webhook_url').select(); document.execCommand('copy'); alert('<?php esc_attr_e( 'Webhook URL copied to clipboard!', 'aebg' ); ?>');"
			>
				<?php esc_html_e( 'Copy', 'aebg' ); ?>
			</button>
		</div>
		<p class="description">
			<?php esc_html_e( 'Use this webhook URL in Elementor Forms. Go to your Form widget > Actions After Submit > Add Webhook, then paste this URL.', 'aebg' ); ?>
			<br>
			<strong><?php esc_html_e( 'Optional Query Parameters:', 'aebg' ); ?></strong>
			<br>
			<code>?list_id=123</code> - <?php esc_html_e( 'Use specific list ID', 'aebg' ); ?><br>
			<code>?post_id=456</code> - <?php esc_html_e( 'Use list from specific post', 'aebg' ); ?><br>
			<code>?list_key=newsletter</code> - <?php esc_html_e( 'Use specific list key (default: "default")', 'aebg' ); ?>
		</p>
	</div>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_auto_process_elementor_forms" value="1" <?php checked( get_option( 'aebg_email_auto_process_elementor_forms', false ) ); ?>>
			<?php esc_html_e( 'Auto-process Elementor forms with email fields (Legacy)', 'aebg' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Legacy auto-detection method. We recommend using the webhook approach above for better reliability.', 'aebg' ); ?></p>
	</div>
	
	<h3><?php esc_html_e( 'Privacy & Compliance', 'aebg' ); ?></h3>
	
	<div class="aebg-form-group">
		<label>
			<input type="checkbox" name="aebg_email_double_opt_in" value="1" <?php checked( $double_opt_in ); ?>>
			<?php esc_html_e( 'Require double opt-in (GDPR compliant)', 'aebg' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Subscribers must confirm their email address before being added to the list', 'aebg' ); ?></p>
	</div>
</div>

