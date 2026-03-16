<?php

namespace AEBG\EmailMarketing\Core;

use AEBG\EmailMarketing\Repositories\QueueRepository;
use AEBG\EmailMarketing\Repositories\CampaignRepository;
use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\EmailMarketing\Services\EmailService;
use AEBG\EmailMarketing\Services\TemplateService;
use AEBG\EmailMarketing\Repositories\TemplateRepository;
use AEBG\EmailMarketing\Utils\UnsubscribeManager;
use AEBG\Core\Logger;

/**
 * Queue Manager
 * 
 * Manages email queue processing
 * 
 * @package AEBG\EmailMarketing\Core
 */
class QueueManager {
	/**
	 * @var QueueRepository
	 */
	private $queue_repository;
	
	/**
	 * @var CampaignRepository
	 */
	private $campaign_repository;
	
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * @var EmailService
	 */
	private $email_service;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->queue_repository = new QueueRepository();
		$this->campaign_repository = new CampaignRepository();
		$this->subscriber_repository = new SubscriberRepository();
		
		$tracking_repository = new \AEBG\EmailMarketing\Repositories\TrackingRepository();
		$unsubscribe_manager = new UnsubscribeManager( $this->subscriber_repository, $tracking_repository );
		$open_tracker = new \AEBG\EmailMarketing\Utils\OpenTracker( $tracking_repository );
		$click_tracker = new \AEBG\EmailMarketing\Utils\ClickTracker( $tracking_repository );
		
		$this->email_service = new EmailService( $open_tracker, $click_tracker, $unsubscribe_manager );
	}
	
	/**
	 * Add campaign to queue
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param array $subscriber_ids Subscriber IDs (optional, if empty gets all from lists)
	 * @return bool True on success
	 */
	public function add_to_queue( $campaign_id, $subscriber_ids = [] ) {
		$campaign = $this->campaign_repository->get( $campaign_id );
		
		if ( ! $campaign ) {
			return false;
		}
		
		// Get list IDs
		$list_ids = $campaign->list_ids ? json_decode( $campaign->list_ids, true ) : [];
		
		if ( empty( $list_ids ) ) {
			return false;
		}
		
		// Get subscribers
		if ( empty( $subscriber_ids ) ) {
			$subscriber_repository = new \AEBG\EmailMarketing\Repositories\SubscriberRepository();
			$subscribers = $subscriber_repository->get_by_list_batch( $list_ids, 'confirmed' );
		} else {
			$subscribers = [];
			foreach ( $subscriber_ids as $subscriber_id ) {
				$subscriber = $this->subscriber_repository->get( $subscriber_id );
				if ( $subscriber && $subscriber->status === 'confirmed' ) {
					$subscribers[] = $subscriber;
				}
			}
		}
		
		// Add each subscriber to queue
		foreach ( $subscribers as $subscriber ) {
			$this->queue_repository->create( [
				'campaign_id' => $campaign_id,
				'subscriber_id' => $subscriber->id,
				'email' => $subscriber->email,
				'subject' => $campaign->subject,
				'content_html' => $campaign->content_html,
				'content_text' => $campaign->content_text,
				'status' => 'pending',
				'scheduled_at' => $campaign->scheduled_at ?: current_time( 'mysql' ),
			] );
		}
		
		// Update campaign stats
		$stats = $campaign->stats ? json_decode( $campaign->stats, true ) : [];
		$stats['queued'] = count( $subscribers );
		$this->campaign_repository->update( $campaign_id, ['stats' => $stats] );
		
		return true;
	}
	
	/**
	 * Process queue batch
	 * 
	 * @param int $batch_size Batch size
	 * @return array Results array
	 */
	public function process_queue( $batch_size = 50 ) {
		$pending = $this->queue_repository->get_pending( $batch_size );
		
		if ( empty( $pending ) ) {
			return ['sent' => 0, 'failed' => 0];
		}
		
		$results = ['sent' => 0, 'failed' => 0];
		
		foreach ( $pending as $queue_item ) {
			// Mark as sending
			$this->queue_repository->update( $queue_item->id, ['status' => 'sending'] );
			
			// Send email
			$sent = $this->email_service->send_email(
				$queue_item->id,
				$queue_item->email,
				$queue_item->subject,
				$queue_item->content_html,
				$queue_item->content_text
			);
			
			if ( $sent ) {
				$this->queue_repository->mark_as_sent( $queue_item->id );
				$results['sent']++;
				
				// Update campaign stats
				$campaign = $this->campaign_repository->get( $queue_item->campaign_id );
				if ( $campaign ) {
					$stats = $campaign->stats ? json_decode( $campaign->stats, true ) : [];
					$stats['sent'] = ( $stats['sent'] ?? 0 ) + 1;
					$this->campaign_repository->update( $queue_item->campaign_id, ['stats' => $stats] );
				}
			} else {
				$this->queue_repository->mark_as_failed( $queue_item->id, __( 'Email send failed', 'aebg' ) );
				$results['failed']++;
			}
		}
		
		return $results;
	}
	
	/**
	 * Get pending emails
	 * 
	 * @param int $limit Limit
	 * @return array Array of queue objects
	 */
	public function get_pending_emails( $limit = 50 ) {
		return $this->queue_repository->get_pending( $limit );
	}
	
	/**
	 * Retry failed email
	 * 
	 * @param int $queue_id Queue ID
	 * @return bool True on success
	 */
	public function retry_failed( $queue_id ) {
		return $this->queue_repository->schedule_retry( $queue_id );
	}
}

