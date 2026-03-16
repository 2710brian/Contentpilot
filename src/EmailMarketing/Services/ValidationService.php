<?php

namespace AEBG\EmailMarketing\Services;

/**
 * Validation Service
 * 
 * Handles input validation and sanitization
 * 
 * @package AEBG\EmailMarketing\Services
 */
class ValidationService {
	/**
	 * Validate email address
	 * 
	 * @param string $email Email address
	 * @return true|\WP_Error True if valid, WP_Error if invalid
	 */
	public function validate_email( $email ) {
		// Basic validation
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Invalid email address', 'aebg' ) );
		}
		
		// Check for disposable email domains (optional - can be enabled in settings)
		$block_disposable = get_option( 'aebg_email_block_disposable', false );
		if ( $block_disposable && $this->is_disposable_email( $email ) ) {
			return new \WP_Error( 'disposable_email', __( 'Disposable email addresses are not allowed', 'aebg' ) );
		}
		
		// Check for banned domains
		$banned_domains = get_option( 'aebg_email_banned_domains', [] );
		if ( ! empty( $banned_domains ) && $this->is_banned_domain( $email, $banned_domains ) ) {
			return new \WP_Error( 'banned_domain', __( 'This email domain is not allowed', 'aebg' ) );
		}
		
		return true;
	}
	
	/**
	 * Sanitize input based on type
	 * 
	 * @param mixed $input Input to sanitize
	 * @param string $type Input type (text, email, url, textarea, html)
	 * @return mixed Sanitized input
	 */
	public function sanitize_input( $input, $type = 'text' ) {
		switch ( $type ) {
			case 'email':
				return sanitize_email( $input );
			case 'url':
				return esc_url_raw( $input );
			case 'textarea':
				return sanitize_textarea_field( $input );
			case 'html':
				return wp_kses_post( $input );
			default:
				return sanitize_text_field( $input );
		}
	}
	
	/**
	 * Validate CSRF token
	 * 
	 * @param string $action Action name
	 * @param string $token Nonce token
	 * @return bool True if valid
	 */
	public function validate_csrf_token( $action, $token ) {
		return wp_verify_nonce( $token, $action );
	}
	
	/**
	 * Check if email is disposable
	 * 
	 * @param string $email Email address
	 * @return bool True if disposable
	 */
	private function is_disposable_email( $email ) {
		$domain = substr( strrchr( $email, '@' ), 1 );
		
		// Common disposable email domains (can be expanded)
		$disposable_domains = [
			'10minutemail.com',
			'temp-mail.org',
			'guerrillamail.com',
			'mailinator.com',
			'throwaway.email',
		];
		
		return in_array( strtolower( $domain ), $disposable_domains, true );
	}
	
	/**
	 * Check if email domain is banned
	 * 
	 * @param string $email Email address
	 * @param array $banned_domains Array of banned domains
	 * @return bool True if banned
	 */
	private function is_banned_domain( $email, $banned_domains ) {
		$domain = substr( strrchr( $email, '@' ), 1 );
		
		if ( ! is_array( $banned_domains ) ) {
			$banned_domains = explode( "\n", $banned_domains );
			$banned_domains = array_map( 'trim', $banned_domains );
		}
		
		return in_array( strtolower( $domain ), array_map( 'strtolower', $banned_domains ), true );
	}
	
	/**
	 * Validate subscriber data
	 * 
	 * @param array $data Subscriber data
	 * @return true|\WP_Error True if valid, WP_Error if invalid
	 */
	public function validate_subscriber_data( $data ) {
		// Email is required
		if ( empty( $data['email'] ) ) {
			return new \WP_Error( 'missing_email', __( 'Email address is required', 'aebg' ) );
		}
		
		// Validate email format
		$email_validation = $this->validate_email( $data['email'] );
		if ( is_wp_error( $email_validation ) ) {
			return $email_validation;
		}
		
		// Validate list_id if provided
		if ( ! empty( $data['list_id'] ) && ! is_numeric( $data['list_id'] ) ) {
			return new \WP_Error( 'invalid_list_id', __( 'Invalid list ID', 'aebg' ) );
		}
		
		return true;
	}
}

