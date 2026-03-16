<?php

namespace AEBG\EmailMarketing\Utils;

use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\Core\Logger;

/**
 * Opt-In Manager
 * 
 * Handles double opt-in confirmation flow
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class OptInManager {
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * Constructor
	 * 
	 * @param SubscriberRepository $subscriber_repository Subscriber repository
	 */
	public function __construct( SubscriberRepository $subscriber_repository ) {
		$this->subscriber_repository = $subscriber_repository;
	}
	
	/**
	 * Send confirmation email
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public function send_confirmation_email( $subscriber_id ) {
		$subscriber = $this->subscriber_repository->get( $subscriber_id );
		
		if ( ! $subscriber ) {
			return new \WP_Error( 'subscriber_not_found', __( 'Subscriber not found', 'aebg' ) );
		}
		
		// Generate secure token
		$token = TokenGenerator::generate( 32 );
		
		// Store token in subscriber metadata
		$metadata = $subscriber->metadata ? json_decode( $subscriber->metadata, true ) : [];
		$metadata['opt_in_token'] = wp_hash( $token );
		$metadata['opt_in_token_expires'] = time() + ( 24 * HOUR_IN_SECONDS );
		
		$this->subscriber_repository->update( $subscriber_id, [
			'opt_in_token' => wp_hash( $token ),
			'metadata' => $metadata,
		] );
		
		// Build confirmation URL
		$confirmation_url = add_query_arg( [
			'aebg_confirm' => $token,
			'email' => urlencode( $subscriber->email ),
		], home_url( '/aebg-email-confirm/' ) );
		
		// Get confirmation email template
		$subject = __( 'Confirm your email subscription', 'aebg' );
		$message = $this->get_confirmation_email_template( $confirmation_url, $subscriber );
		
		// Send email
		$sent = wp_mail(
			$subscriber->email,
			$subject,
			$message,
			['Content-Type: text/html; charset=UTF-8']
		);
		
		if ( ! $sent ) {
			Logger::error( 'Failed to send confirmation email', [
				'subscriber_id' => $subscriber_id,
				'email' => $subscriber->email,
			] );
			return new \WP_Error( 'email_send_failed', __( 'Failed to send confirmation email', 'aebg' ) );
		}
		
		return true;
	}
	
	/**
	 * Confirm opt-in
	 * 
	 * @param string $token Confirmation token
	 * @param string $email Email address
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public function confirm_opt_in( $token, $email ) {
		$subscriber = $this->subscriber_repository->get_by_email( $email );
		
		if ( ! $subscriber ) {
			return new \WP_Error( 'subscriber_not_found', __( 'Subscriber not found', 'aebg' ) );
		}
		
		// Verify token
		if ( ! hash_equals( $subscriber->opt_in_token, wp_hash( $token ) ) ) {
			return new \WP_Error( 'invalid_token', __( 'Invalid confirmation token', 'aebg' ) );
		}
		
		// Check if already confirmed
		if ( $subscriber->status === 'confirmed' ) {
			return true; // Already confirmed
		}
		
		// Update subscriber status
		$updated = $this->subscriber_repository->update( $subscriber->id, [
			'status' => 'confirmed',
			'opt_in_confirmed_at' => current_time( 'mysql' ),
			'opt_in_token' => null, // Clear token
		] );
		
		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to confirm subscription', 'aebg' ) );
		}
		
		// Increment list subscriber count
		$list_repository = new \AEBG\EmailMarketing\Repositories\ListRepository();
		$list_repository->increment_subscriber_count( $subscriber->list_id );
		
		Logger::info( 'Subscriber confirmed opt-in', [
			'subscriber_id' => $subscriber->id,
			'email' => $email,
		] );
		
		return true;
	}
	
	/**
	 * Get confirmation email template
	 * 
	 * @param string $confirmation_url Confirmation URL
	 * @param object $subscriber Subscriber object
	 * @return string Email HTML
	 */
	private function get_confirmation_email_template( $confirmation_url, $subscriber ) {
		$site_name = get_bloginfo( 'name' );
		$site_url = home_url();
		
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php esc_html_e( 'Confirm your subscription', 'aebg' ); ?></title>
		</head>
		<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
			<div style="background: #f8f9fa; padding: 30px; border-radius: 8px;">
				<h1 style="color: #667eea; margin-top: 0;"><?php esc_html_e( 'Confirm your email subscription', 'aebg' ); ?></h1>
				
				<p><?php esc_html_e( 'Hello', 'aebg' ); ?><?php echo ! empty( $subscriber->first_name ) ? ' ' . esc_html( $subscriber->first_name ) : ''; ?>,</p>
				
				<p><?php esc_html_e( 'Thank you for subscribing! Please confirm your email address by clicking the button below:', 'aebg' ); ?></p>
				
				<div style="text-align: center; margin: 30px 0;">
					<a href="<?php echo esc_url( $confirmation_url ); ?>" style="display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 600;">
						<?php esc_html_e( 'Confirm Subscription', 'aebg' ); ?>
					</a>
				</div>
				
				<p style="font-size: 12px; color: #666;">
					<?php esc_html_e( 'Or copy and paste this link into your browser:', 'aebg' ); ?><br>
					<a href="<?php echo esc_url( $confirmation_url ); ?>" style="color: #667eea; word-break: break-all;"><?php echo esc_url( $confirmation_url ); ?></a>
				</p>
				
				<p style="font-size: 12px; color: #666; margin-top: 30px;">
					<?php esc_html_e( 'If you did not subscribe to this list, please ignore this email.', 'aebg' ); ?>
				</p>
				
				<hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
				
				<p style="font-size: 12px; color: #999; text-align: center;">
					&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( $site_name ); ?>. <?php esc_html_e( 'All rights reserved.', 'aebg' ); ?>
				</p>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
}

