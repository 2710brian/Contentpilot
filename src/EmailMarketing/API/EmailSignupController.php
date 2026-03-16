<?php

namespace AEBG\EmailMarketing\API;

use AEBG\EmailMarketing\Core\SubscriberManager;
use AEBG\EmailMarketing\Services\ValidationService;
use AEBG\Core\Logger;

/**
 * Email Signup Controller
 * 
 * Handles AJAX requests for email signup
 * 
 * @package AEBG\EmailMarketing\API
 */
class EmailSignupController {
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_ajax_aebg_email_signup', [$this, 'handle_signup'] );
		add_action( 'wp_ajax_nopriv_aebg_email_signup', [$this, 'handle_signup'] );
		add_action( 'wp_ajax_aebg_remove_subscriber', [$this, 'handle_remove_subscriber'] );
	}
	
	/**
	 * Handle email signup
	 * 
	 * @return void
	 */
	public function handle_signup() {
		// Verify nonce
		if ( ! check_ajax_referer( 'aebg_email_signup', '_aebg_nonce', false ) ) {
			wp_send_json_error( ['message' => __( 'Security check failed', 'aebg' )] );
		}
		
		// Rate limiting
		$ip_address = $this->get_client_ip();
		$rate_limit_key = 'aebg_signup_rate_' . md5( $ip_address );
		$rate_limit_count = get_transient( $rate_limit_key );
		
		if ( $rate_limit_count === false ) {
			set_transient( $rate_limit_key, 1, 3600 ); // 1 hour
		} else {
			if ( $rate_limit_count >= 5 ) { // Max 5 signups per hour per IP
				wp_send_json_error( ['message' => __( 'Too many signup attempts. Please try again later.', 'aebg' )] );
			}
			set_transient( $rate_limit_key, $rate_limit_count + 1, 3600 );
		}
		
		// Honeypot check
		if ( ! empty( $_POST['aebg_website'] ) ) {
			Logger::warning( 'Honeypot triggered', ['ip' => $ip_address] );
			wp_send_json_error( ['message' => __( 'Spam detected', 'aebg' )] );
		}
		
		$list_id = isset( $_POST['list_id'] ) ? (int) $_POST['list_id'] : 0;
		$email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '';
		$last_name = isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '';
		
		if ( ! $list_id || ! $email ) {
			wp_send_json_error( ['message' => __( 'Email and list are required', 'aebg' )] );
		}
		
		$subscriber_manager = new SubscriberManager();
		$result = $subscriber_manager->add_subscriber( $list_id, $email, [
			'first_name' => $first_name,
			'last_name' => $last_name,
			'source' => 'widget',
		] );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( ['message' => $result->get_error_message()] );
		}
		
		$message = $result->status === 'pending' 
			? __( 'Please check your email to confirm your subscription.', 'aebg' )
			: __( 'Thank you for subscribing!', 'aebg' );
		
		wp_send_json_success( ['message' => $message] );
	}
	
	/**
	 * Handle subscriber removal
	 * 
	 * @return void
	 */
	public function handle_remove_subscriber() {
		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( ['message' => __( 'Permission denied', 'aebg' )] );
		}
		
		// Verify nonce
		if ( ! check_ajax_referer( 'aebg_email_marketing', 'nonce', false ) ) {
			wp_send_json_error( ['message' => __( 'Security check failed', 'aebg' )] );
		}
		
		$subscriber_id = isset( $_POST['subscriber_id'] ) ? (int) $_POST['subscriber_id'] : 0;
		
		if ( ! $subscriber_id ) {
			wp_send_json_error( ['message' => __( 'Subscriber ID is required', 'aebg' )] );
		}
		
		$subscriber_manager = new SubscriberManager();
		$result = $subscriber_manager->remove_subscriber( $subscriber_id );
		
		if ( ! $result ) {
			wp_send_json_error( ['message' => __( 'Failed to remove subscriber', 'aebg' )] );
		}
		
		wp_send_json_success( ['message' => __( 'Subscriber removed successfully', 'aebg' )] );
	}
	
	/**
	 * Get client IP address
	 * 
	 * @return string IP address
	 */
	private function get_client_ip() {
		$ip_keys = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		];
		
		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( $_SERVER[ $key ] );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		
		return '0.0.0.0';
	}
}

