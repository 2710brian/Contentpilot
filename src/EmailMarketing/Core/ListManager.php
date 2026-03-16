<?php

namespace AEBG\EmailMarketing\Core;

use AEBG\EmailMarketing\Repositories\ListRepository;
use AEBG\EmailMarketing\Repositories\SubscriberRepository;

/**
 * List Manager
 * 
 * Manages email lists (automatic and manual)
 * 
 * @package AEBG\EmailMarketing\Core
 */
class ListManager {
	/**
	 * @var ListRepository
	 */
	private $list_repository;
	
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * Constructor
	 * 
	 * @param ListRepository|null $list_repository List repository
	 * @param SubscriberRepository|null $subscriber_repository Subscriber repository
	 */
	public function __construct( ListRepository $list_repository = null, SubscriberRepository $subscriber_repository = null ) {
		$this->list_repository = $list_repository ?: new ListRepository();
		$this->subscriber_repository = $subscriber_repository ?: new SubscriberRepository();
	}
	
	/**
	 * Get or create list for post
	 * 
	 * @param int $post_id Post ID
	 * @param string $list_key List key (default: 'default')
	 * @return object List object
	 */
	public function get_or_create_list( $post_id, $list_key = 'default' ) {
		$list = $this->list_repository->get_by_post( $post_id, $list_key );
		
		if ( $list ) {
			return $list;
		}
		
		// Create new list
		$list_name = sprintf( __( 'List for: %s', 'aebg' ), get_the_title( $post_id ) );
		$list_id = $this->list_repository->create( [
			'post_id' => $post_id,
			'list_name' => $list_name,
			'list_type' => 'post',
			'list_key' => $list_key,
			'description' => sprintf( __( 'Automatic list for post #%d', 'aebg' ), $post_id ),
		] );
		
		return $this->list_repository->get( $list_id );
	}
	
	/**
	 * Get list by post ID
	 * 
	 * @param int $post_id Post ID
	 * @param string $list_key List key (default: 'default')
	 * @return object|null List object or null
	 */
	public function get_list_by_post( $post_id, $list_key = 'default' ) {
		return $this->list_repository->get_by_post( $post_id, $list_key );
	}
	
	/**
	 * Get all lists by post ID
	 * 
	 * @param int $post_id Post ID
	 * @return array Array of list objects
	 */
	public function get_lists_by_post( $post_id ) {
		return $this->list_repository->get_by_post_id( $post_id );
	}
	
	/**
	 * Create manual list
	 * 
	 * @param string $list_name List name
	 * @param string $description List description
	 * @param array $settings List settings
	 * @return object List object
	 */
	public function create_manual_list( $list_name, $description = '', $settings = [] ) {
		$list_id = $this->list_repository->create( [
			'post_id' => null,
			'list_name' => $list_name,
			'list_type' => 'global',
			'list_key' => 'manual_' . time() . '_' . wp_generate_password( 8, false ),
			'description' => $description,
			'settings' => $settings,
		] );
		
		return $this->list_repository->get( $list_id );
	}
	
	/**
	 * Get all lists
	 * 
	 * @param array $filters Filters
	 * @return array Array of list objects
	 */
	public function get_all_lists( $filters = [] ) {
		return $this->list_repository->get_all( $filters );
	}
	
	/**
	 * Get manual lists
	 * 
	 * @return array Array of list objects
	 */
	public function get_manual_lists() {
		return $this->list_repository->get_all( [
			'list_type' => 'global',
		] );
	}
	
	/**
	 * Update list
	 * 
	 * @param int $list_id List ID
	 * @param array $data Data to update
	 * @return bool True on success
	 */
	public function update_list( $list_id, $data ) {
		return $this->list_repository->update( $list_id, $data );
	}
	
	/**
	 * Delete list
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success
	 */
	public function delete_list( $list_id ) {
		return $this->list_repository->delete( $list_id );
	}
	
	/**
	 * Get list
	 * 
	 * @param int $list_id List ID
	 * @return object|null List object or null
	 */
	public function get_list( $list_id ) {
		return $this->list_repository->get( $list_id );
	}
	
	/**
	 * Get list statistics
	 * 
	 * @param int $list_id List ID
	 * @return array Statistics array
	 */
	public function get_list_stats( $list_id ) {
		$list = $this->list_repository->get( $list_id );
		
		if ( ! $list ) {
			return [];
		}
		
		$total = $this->subscriber_repository->count_by_list( $list_id );
		$confirmed = $this->subscriber_repository->count_by_list( $list_id, 'confirmed' );
		$pending = $this->subscriber_repository->count_by_list( $list_id, 'pending' );
		$unsubscribed = $this->subscriber_repository->count_by_list( $list_id, 'unsubscribed' );
		
		return [
			'total' => $total,
			'confirmed' => $confirmed,
			'pending' => $pending,
			'unsubscribed' => $unsubscribed,
		];
	}
	
	/**
	 * Increment subscriber count
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success
	 */
	public function increment_subscriber_count( $list_id ) {
		return $this->list_repository->increment_subscriber_count( $list_id );
	}
	
	/**
	 * Decrement subscriber count
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success
	 */
	public function decrement_subscriber_count( $list_id ) {
		return $this->list_repository->decrement_subscriber_count( $list_id );
	}
	
	/**
	 * Recalculate subscriber count
	 * 
	 * @param int $list_id List ID
	 * @return bool True on success
	 */
	public function recalculate_subscriber_count( $list_id ) {
		return $this->list_repository->recalculate_subscriber_count( $list_id );
	}
}

