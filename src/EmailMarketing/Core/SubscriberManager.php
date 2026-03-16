<?php

namespace AEBG\EmailMarketing\Core;

use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\EmailMarketing\Repositories\ListRepository;
use AEBG\EmailMarketing\Services\ValidationService;
use AEBG\EmailMarketing\Utils\OptInManager;
use AEBG\EmailMarketing\Utils\EmailValidator;
use AEBG\Core\Logger;

/**
 * Subscriber Manager
 * 
 * Manages email subscribers
 * 
 * @package AEBG\EmailMarketing\Core
 */
class SubscriberManager {
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * @var ListRepository
	 */
	private $list_repository;
	
	/**
	 * @var ValidationService
	 */
	private $validation_service;
	
	/**
	 * @var OptInManager
	 */
	private $opt_in_manager;
	
	/**
	 * Constructor
	 * 
	 * @param SubscriberRepository|null $subscriber_repository Subscriber repository
	 * @param ListRepository|null $list_repository List repository
	 * @param ValidationService|null $validation_service Validation service
	 * @param OptInManager|null $opt_in_manager Opt-in manager
	 */
	public function __construct( 
		SubscriberRepository $subscriber_repository = null,
		ListRepository $list_repository = null,
		ValidationService $validation_service = null,
		OptInManager $opt_in_manager = null
	) {
		$this->subscriber_repository = $subscriber_repository ?: new SubscriberRepository();
		$this->list_repository = $list_repository ?: new ListRepository();
		$this->validation_service = $validation_service ?: new ValidationService();
		$this->opt_in_manager = $opt_in_manager ?: new OptInManager( $this->subscriber_repository );
	}
	
	/**
	 * Add subscriber
	 * 
	 * @param int $list_id List ID
	 * @param string $email Email address
	 * @param array $data Additional subscriber data
	 * @return object|\WP_Error Subscriber object or error
	 */
	public function add_subscriber( $list_id, $email, $data = [] ) {
		// Validate email
		$email_validation = $this->validation_service->validate_email( $email );
		if ( is_wp_error( $email_validation ) ) {
			return $email_validation;
		}
		
		// Normalize email
		$email = EmailValidator::normalize( $email );
		
		// Check if already exists
		$existing = $this->subscriber_repository->get_by_email_and_list( $email, $list_id );
		if ( $existing ) {
			return new \WP_Error( 'subscriber_exists', __( 'This email is already subscribed to this list', 'aebg' ) );
		}
		
		// Check if list exists
		$list = $this->list_repository->get( $list_id );
		if ( ! $list ) {
			return new \WP_Error( 'list_not_found', __( 'List not found', 'aebg' ) );
		}
		
		// Determine status based on settings
		$settings = $list->settings ? json_decode( $list->settings, true ) : [];
		$require_double_opt_in = $settings['opt_in_type'] ?? 'double' === 'double';
		$status = $require_double_opt_in ? 'pending' : 'confirmed';
		
		// Create subscriber
		$subscriber_id = $this->subscriber_repository->create( [
			'list_id' => $list_id,
			'email' => $email,
			'first_name' => $data['first_name'] ?? '',
			'last_name' => $data['last_name'] ?? '',
			'status' => $status,
			'source' => $data['source'] ?? 'widget',
			'metadata' => $data['metadata'] ?? [],
		] );
		
		if ( ! $subscriber_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create subscriber', 'aebg' ) );
		}
		
		$subscriber = $this->subscriber_repository->get( $subscriber_id );
		
		// Send confirmation email if double opt-in required
		if ( $require_double_opt_in ) {
			$this->opt_in_manager->send_confirmation_email( $subscriber_id );
		} else {
			// Increment list count immediately if confirmed
			$this->list_repository->increment_subscriber_count( $list_id );
		}
		
		Logger::info( 'Subscriber added', [
			'subscriber_id' => $subscriber_id,
			'list_id' => $list_id,
			'email' => $email,
			'status' => $status,
		] );
		
		return $subscriber;
	}
	
	/**
	 * Confirm opt-in
	 * 
	 * @param string $token Confirmation token
	 * @param string $email Email address
	 * @return bool|\WP_Error True on success
	 */
	public function confirm_opt_in( $token, $email ) {
		return $this->opt_in_manager->confirm_opt_in( $token, $email );
	}
	
	/**
	 * Remove subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return bool True on success
	 */
	public function remove_subscriber( $subscriber_id ) {
		$subscriber = $this->subscriber_repository->get( $subscriber_id );
		
		if ( ! $subscriber ) {
			return false;
		}
		
		$deleted = $this->subscriber_repository->delete( $subscriber_id );
		
		if ( $deleted && $subscriber->status === 'confirmed' ) {
			$this->list_repository->decrement_subscriber_count( $subscriber->list_id );
		}
		
		return $deleted;
	}
	
	/**
	 * Get subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @return object|null Subscriber object or null
	 */
	public function get_subscriber( $subscriber_id ) {
		return $this->subscriber_repository->get( $subscriber_id );
	}
	
	/**
	 * Get subscribers by list
	 * 
	 * @param int $list_id List ID
	 * @param string $status Status filter
	 * @return array Array of subscriber objects
	 */
	public function get_subscribers_by_list( $list_id, $status = 'confirmed' ) {
		return $this->subscriber_repository->get_by_list( $list_id, $status );
	}
	
	/**
	 * Update subscriber
	 * 
	 * @param int $subscriber_id Subscriber ID
	 * @param array $data Data to update
	 * @return bool True on success
	 */
	public function update_subscriber( $subscriber_id, $data ) {
		return $this->subscriber_repository->update( $subscriber_id, $data );
	}
	
	/**
	 * Validate email
	 * 
	 * @param string $email Email address
	 * @return true|\WP_Error True if valid
	 */
	public function validate_email( $email ) {
		return $this->validation_service->validate_email( $email );
	}
	
	/**
	 * Bulk add subscribers
	 * 
	 * @param int $list_id List ID
	 * @param array $subscribers Array of subscriber data
	 * @return array Results array
	 */
	public function bulk_add_subscribers( $list_id, $subscribers ) {
		$results = [
			'success' => 0,
			'failed' => 0,
			'errors' => [],
		];
		
		foreach ( $subscribers as $index => $subscriber_data ) {
			$result = $this->add_subscriber( $list_id, $subscriber_data['email'], $subscriber_data );
			
			if ( is_wp_error( $result ) ) {
				$results['failed']++;
				$results['errors'][] = [
					'index' => $index,
					'email' => $subscriber_data['email'],
					'error' => $result->get_error_message(),
				];
			} else {
				$results['success']++;
			}
		}
		
		return $results;
	}
}

