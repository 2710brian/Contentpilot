<?php

namespace AEBG\EmailMarketing\Utils;

use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\EmailMarketing\Repositories\TrackingRepository;
use AEBG\Core\Logger;

/**
 * Unsubscribe Manager
 * 
 * Handles unsubscribe functionality
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class UnsubscribeManager {
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * @var TrackingRepository
	 */
	private $tracking_repository;
	
	/**
	 * Constructor
	 * 
	 * @param SubscriberRepository $subscriber_repository Subscriber repository
	 * @param TrackingRepository $tracking_repository Tracking repository
	 */
	public function __construct( SubscriberRepository $subscriber_repository, TrackingRepository $tracking_repository ) {
		$this->subscriber_repository = $subscriber_repository;
		$this->tracking_repository = $tracking_repository;
	}
	
	/**
	 * Generate unsubscribe URL
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return string Unsubscribe URL
	 */
	public function generate_unsubscribe_url( $subscriber_id ) {
		$subscriber = $this->subscriber_repository->get( $subscriber_id );
		
		if ( ! $subscriber ) {
			return '';
		}
		
		// Generate secure token
		$token = TokenGenerator::generate( 32 );
		
		// Store unsubscribe token
		$this->subscriber_repository->update( $subscriber_id, [
			'unsubscribe_token' => wp_hash( $token ),
		] );
		
		return add_query_arg( [
			'aebg_unsubscribe' => $token,
			'email' => urlencode( $subscriber->email ),
		], home_url( '/aebg-unsubscribe/' ) );
	}
	
	/**
	 * Process unsubscribe
	 * 
	 * @param string $token Unsubscribe token
	 * @param string $email Email address
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public function process_unsubscribe( $token, $email ) {
		$subscriber = $this->subscriber_repository->get_by_email( $email );
		
		if ( ! $subscriber ) {
			return new \WP_Error( 'subscriber_not_found', __( 'Subscriber not found', 'aebg' ) );
		}
		
		// Verify token
		if ( ! hash_equals( $subscriber->unsubscribe_token, wp_hash( $token ) ) ) {
			return new \WP_Error( 'invalid_token', __( 'Invalid unsubscribe token', 'aebg' ) );
		}
		
		// Check if already unsubscribed
		if ( $subscriber->status === 'unsubscribed' ) {
			return true; // Already unsubscribed
		}
		
		// Mark as unsubscribed
		$updated = $this->subscriber_repository->update( $subscriber->id, [
			'status' => 'unsubscribed',
			'unsubscribed_at' => current_time( 'mysql' ),
		] );
		
		if ( ! $updated ) {
			return new \WP_Error( 'update_failed', __( 'Failed to unsubscribe', 'aebg' ) );
		}
		
		// Decrement list subscriber count
		$list_repository = new \AEBG\EmailMarketing\Repositories\ListRepository();
		$list_repository->decrement_subscriber_count( $subscriber->list_id );
		
		// Log unsubscribe event (if we have a queue_id, we can track it)
		Logger::info( 'Subscriber unsubscribed', [
			'subscriber_id' => $subscriber->id,
			'email' => $email,
			'ip_address' => $this->get_client_ip(),
		] );
		
		return true;
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

