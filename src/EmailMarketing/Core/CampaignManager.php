<?php

namespace AEBG\EmailMarketing\Core;

use AEBG\EmailMarketing\Repositories\CampaignRepository;
use AEBG\EmailMarketing\Repositories\TemplateRepository;
use AEBG\EmailMarketing\Repositories\ListRepository;
use AEBG\EmailMarketing\Repositories\SubscriberRepository;
use AEBG\EmailMarketing\Repositories\QueueRepository;
use AEBG\EmailMarketing\Services\TemplateService;
use AEBG\EmailMarketing\Core\QueueManager;
use AEBG\Core\Logger;

/**
 * Campaign Manager
 * 
 * Manages email campaigns (automatic and manual)
 * 
 * @package AEBG\EmailMarketing\Core
 */
class CampaignManager {
	/**
	 * @var CampaignRepository
	 */
	private $campaign_repository;
	
	/**
	 * @var TemplateRepository
	 */
	private $template_repository;
	
	/**
	 * @var ListRepository
	 */
	private $list_repository;
	
	/**
	 * @var SubscriberRepository
	 */
	private $subscriber_repository;
	
	/**
	 * @var TemplateService
	 */
	private $template_service;
	
	/**
	 * @var QueueManager
	 */
	private $queue_manager;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->campaign_repository = new CampaignRepository();
		$this->template_repository = new TemplateRepository();
		$this->list_repository = new ListRepository();
		$this->subscriber_repository = new SubscriberRepository();
		$this->template_service = new TemplateService( $this->template_repository );
	}
	
	/**
	 * Get queue manager (lazy loading to avoid circular dependency)
	 * 
	 * @return QueueManager
	 */
	private function get_queue_manager() {
		if ( ! $this->queue_manager ) {
			$this->queue_manager = new QueueManager();
		}
		return $this->queue_manager;
	}
	
	/**
	 * Create campaign
	 * 
	 * @param int $post_id Post ID
	 * @param string $campaign_type Campaign type
	 * @param int|null $template_id Template ID
	 * @param array $settings Campaign settings
	 * @return object|\WP_Error Campaign object or error
	 */
	public function create_campaign( $post_id, $campaign_type, $template_id = null, $settings = [] ) {
		// Get template
		if ( ! $template_id ) {
			$template = $this->template_service->get_template_by_type( $campaign_type );
			if ( ! $template ) {
				return new \WP_Error( 'no_template', __( 'No template found for this campaign type', 'aebg' ) );
			}
			$template_id = $template->id;
		} else {
			$template = $this->template_repository->get( $template_id );
			if ( ! $template ) {
				return new \WP_Error( 'template_not_found', __( 'Template not found', 'aebg' ) );
			}
		}
		
		// Get lists for this post
		$list_manager = new ListManager();
		$lists = $list_manager->get_lists_by_post( $post_id );
		
		if ( empty( $lists ) ) {
			return new \WP_Error( 'no_lists', __( 'No email lists found for this post', 'aebg' ) );
		}
		
		$list_ids = array_column( $lists, 'id' );
		
		// Prepare variables for template
		$variables = $this->prepare_template_variables( $post_id, $campaign_type, $settings );
		
		// Process template
		$processed = $this->template_service->process_template( $template, $variables );
		
		// Create campaign
		$campaign_id = $this->campaign_repository->create( [
			'post_id' => $post_id,
			'campaign_name' => sprintf( __( '%s - %s', 'aebg' ), ucfirst( str_replace( '_', ' ', $campaign_type ) ), get_the_title( $post_id ) ),
			'campaign_type' => $campaign_type,
			'trigger_event' => $settings['trigger_event'] ?? null,
			'template_id' => $template_id,
			'subject' => $processed['subject'],
			'content_html' => $processed['content_html'],
			'content_text' => $processed['content_text'],
			'status' => 'draft',
			'list_ids' => $list_ids,
			'settings' => $settings,
		] );
		
		if ( ! $campaign_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create campaign', 'aebg' ) );
		}
		
		return $this->campaign_repository->get( $campaign_id );
	}
	
	/**
	 * Create manual campaign
	 * 
	 * @param array $data Campaign data
	 * @return object|\WP_Error Campaign object or error
	 */
	public function create_manual_campaign( $data ) {
		$campaign_id = $this->campaign_repository->create( $data );
		
		if ( ! $campaign_id ) {
			return new \WP_Error( 'create_failed', __( 'Failed to create campaign', 'aebg' ) );
		}
		
		return $this->campaign_repository->get( $campaign_id );
	}
	
	/**
	 * Schedule campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param string|null $scheduled_at Scheduled datetime
	 * @return bool True on success
	 */
	public function schedule_campaign( $campaign_id, $scheduled_at = null ) {
		if ( ! $scheduled_at ) {
			$scheduled_at = current_time( 'mysql' );
		}
		
		$updated = $this->campaign_repository->update( $campaign_id, [
			'status' => 'scheduled',
			'scheduled_at' => $scheduled_at,
		] );
		
		if ( $updated ) {
			// Add to queue
			$this->get_queue_manager()->add_to_queue( $campaign_id );
		}
		
		return $updated;
	}
	
	/**
	 * Send campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @param bool $send_immediately Send immediately
	 * @return bool True on success
	 */
	public function send_campaign( $campaign_id, $send_immediately = false ) {
		if ( $send_immediately ) {
			$this->campaign_repository->update( $campaign_id, [
				'status' => 'sending',
			] );
			
			// Add to queue
			$this->get_queue_manager()->add_to_queue( $campaign_id );
		} else {
			$this->schedule_campaign( $campaign_id );
		}
		
		return true;
	}
	
	/**
	 * Get campaign
	 * 
	 * @param int $campaign_id Campaign ID
	 * @return object|null Campaign object or null
	 */
	public function get_campaign( $campaign_id ) {
		return $this->campaign_repository->get( $campaign_id );
	}
	
	/**
	 * Get campaigns by post
	 * 
	 * @param int $post_id Post ID
	 * @return array Array of campaign objects
	 */
	public function get_campaigns_by_post( $post_id ) {
		return $this->campaign_repository->get_by_post( $post_id );
	}
	
	/**
	 * Get all campaigns
	 * 
	 * @param array $filters Filters
	 * @return array Array of campaign objects
	 */
	public function get_all_campaigns( $filters = [] ) {
		return $this->campaign_repository->get_all( $filters );
	}
	
	/**
	 * Prepare template variables
	 * 
	 * @param int $post_id Post ID
	 * @param string $campaign_type Campaign type
	 * @param array $settings Settings
	 * @return array Variables array
	 */
	private function prepare_template_variables( $post_id, $campaign_type, $settings ) {
		$post = get_post( $post_id );
		
		$variables = [
			'post_title' => $post->post_title ?? '',
			'post_url' => get_permalink( $post_id ),
			'post_excerpt' => get_the_excerpt( $post_id ),
			'post_content' => wp_trim_words( $post->post_content ?? '', 50 ),
			'site_name' => get_bloginfo( 'name' ),
			'site_url' => home_url(),
		];
		
		// Add product list if available
		if ( isset( $settings['products'] ) ) {
			$variables['product_list'] = $settings['products'];
		}
		
		return $variables;
	}
}

