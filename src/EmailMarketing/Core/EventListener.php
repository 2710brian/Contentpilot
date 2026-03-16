<?php

namespace AEBG\EmailMarketing\Core;

use AEBG\EmailMarketing\Core\ListManager;
use AEBG\EmailMarketing\Core\CampaignManager;
use AEBG\EmailMarketing\Services\TemplateService;
use AEBG\EmailMarketing\Repositories\TemplateRepository;
use AEBG\Core\Logger;

/**
 * Event Listener
 * 
 * Listens to WordPress and plugin events to trigger email campaigns
 * 
 * @package AEBG\EmailMarketing\Core
 */
class EventListener {
	/**
	 * @var ListManager
	 */
	private $list_manager;
	
	/**
	 * @var CampaignManager
	 */
	private $campaign_manager;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->list_manager = new ListManager();
		$this->campaign_manager = new CampaignManager();
		
		// Register WordPress hooks
		$this->register_hooks();
	}
	
	/**
	 * Register WordPress and plugin hooks
	 * 
	 * @return void
	 */
	private function register_hooks() {
		// Post updates
		add_action( 'save_post', [$this, 'on_post_saved'], 10, 3 );
		add_action( 'post_updated', [$this, 'on_post_updated'], 10, 3 );
		
		// New post publication
		add_action( 'publish_post', [$this, 'on_new_post_published'], 10, 2 );
		
		// Product reordering (from TemplateManager)
		add_action( 'aebg_post_products_reordered', [$this, 'on_product_reordered'], 10, 2 );
		
		// Product replacement (from ActionHandler)
		add_action( 'aebg_replacement_completed', [$this, 'on_product_replaced'], 10, 3 );
	}
	
	/**
	 * Handle post saved
	 * 
	 * @param int $post_id Post ID
	 * @param object $post Post object
	 * @param bool $update Whether this is an update
	 * @return void
	 */
	public function on_post_saved( $post_id, $post, $update ) {
		// Skip autosaves, revisions, etc.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		// Only process published posts
		if ( $post->post_status !== 'publish' ) {
			return;
		}
		
		// Check if email marketing is enabled
		if ( ! $this->is_email_marketing_enabled() ) {
			return;
		}
		
		// Check if post update campaigns are enabled
		if ( ! $this->should_send_campaign( $post_id, 'post_update' ) ) {
			return;
		}
		
		// For updates, we'll handle in on_post_updated
		if ( $update ) {
			return;
		}
	}
	
	/**
	 * Handle post updated
	 * 
	 * @param int $post_id Post ID
	 * @param object $post_after Post object after update
	 * @param object $post_before Post object before update
	 * @return void
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ) {
		// Skip autosaves, revisions, etc.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		// Only process published posts
		if ( $post_after->post_status !== 'publish' ) {
			return;
		}
		
		// Check if content actually changed
		if ( $post_after->post_content === $post_before->post_content &&
			 $post_after->post_title === $post_before->post_title ) {
			return;
		}
		
		// Check if email marketing is enabled
		if ( ! $this->is_email_marketing_enabled() ) {
			return;
		}
		
		// Check if post update campaigns are enabled
		if ( ! $this->should_send_campaign( $post_id, 'post_update' ) ) {
			return;
		}
		
		// Trigger campaign
		$this->trigger_campaign( $post_id, 'post_update', [
			'post_title' => $post_after->post_title,
			'post_url' => get_permalink( $post_id ),
			'changes' => [
				'content_changed' => $post_after->post_content !== $post_before->post_content,
				'title_changed' => $post_after->post_title !== $post_before->post_title,
			],
		] );
	}
	
	/**
	 * Handle new post published
	 * 
	 * @param int $post_id Post ID
	 * @param object $post Post object
	 * @return void
	 */
	public function on_new_post_published( $post_id, $post ) {
		// Skip autosaves, revisions, etc.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		// Check if email marketing is enabled
		if ( ! $this->is_email_marketing_enabled() ) {
			return;
		}
		
		// Check if new post campaigns are enabled
		if ( ! $this->should_send_campaign( $post_id, 'new_post' ) ) {
			return;
		}
		
		// Trigger campaign
		$this->trigger_campaign( $post_id, 'new_post', [
			'post_title' => $post->post_title,
			'post_url' => get_permalink( $post_id ),
		] );
	}
	
	/**
	 * Handle product reordered
	 * 
	 * @param int $post_id Post ID
	 * @param array $new_order New product order
	 * @return void
	 */
	public function on_product_reordered( $post_id, $new_order ) {
		// Check if email marketing is enabled
		if ( ! $this->is_email_marketing_enabled() ) {
			return;
		}
		
		// Check if product reorder campaigns are enabled
		if ( ! $this->should_send_campaign( $post_id, 'product_reorder' ) ) {
			return;
		}
		
		// Trigger campaign
		$this->trigger_campaign( $post_id, 'product_reorder', [
			'post_title' => get_the_title( $post_id ),
			'post_url' => get_permalink( $post_id ),
			'product_count' => count( $new_order ),
		] );
	}
	
	/**
	 * Handle product replaced
	 * 
	 * @param int $post_id Post ID
	 * @param int $product_number Product number
	 * @param array $new_product New product data
	 * @return void
	 */
	public function on_product_replaced( $post_id, $product_number, $new_product ) {
		// Check if email marketing is enabled
		if ( ! $this->is_email_marketing_enabled() ) {
			return;
		}
		
		// Check if product replacement campaigns are enabled
		if ( ! $this->should_send_campaign( $post_id, 'product_replace' ) ) {
			return;
		}
		
		// Trigger campaign
		$this->trigger_campaign( $post_id, 'product_replace', [
			'post_title' => get_the_title( $post_id ),
			'post_url' => get_permalink( $post_id ),
			'product_number' => $product_number,
			'new_product' => $new_product,
		] );
	}
	
	/**
	 * Trigger campaign
	 * 
	 * @param int $post_id Post ID
	 * @param string $campaign_type Campaign type
	 * @param array $event_data Event data
	 * @return void
	 */
	private function trigger_campaign( $post_id, $campaign_type, $event_data = [] ) {
		// Get lists for this post
		$lists = $this->list_manager->get_lists_by_post( $post_id );
		
		if ( empty( $lists ) ) {
			Logger::debug( 'No email lists found for post', [
				'post_id' => $post_id,
				'campaign_type' => $campaign_type,
			] );
			return; // No lists, no campaign
		}
		
		// Create campaign
		$campaign = $this->campaign_manager->create_campaign(
			$post_id,
			$campaign_type,
			null, // Use default template
			[
				'event_data' => $event_data,
				'trigger_event' => $campaign_type,
			]
		);
		
		if ( is_wp_error( $campaign ) ) {
			Logger::error( 'Failed to create campaign', [
				'post_id' => $post_id,
				'campaign_type' => $campaign_type,
				'error' => $campaign->get_error_message(),
			] );
			return;
		}
		
		// Schedule campaign (send immediately)
		$this->campaign_manager->send_campaign( $campaign->id, true );
		
		Logger::info( 'Campaign triggered', [
			'campaign_id' => $campaign->id,
			'post_id' => $post_id,
			'campaign_type' => $campaign_type,
		] );
	}
	
	/**
	 * Check if email marketing is enabled
	 * 
	 * @return bool True if enabled
	 */
	private function is_email_marketing_enabled() {
		return get_option( 'aebg_email_marketing_enabled', true );
	}
	
	/**
	 * Check if campaign should be sent
	 * 
	 * @param int $post_id Post ID
	 * @param string $event_type Event type
	 * @return bool True if should send
	 */
	private function should_send_campaign( $post_id, $event_type ) {
		// Check global setting
		$enabled = get_option( 'aebg_email_campaign_' . $event_type . '_enabled', true );
		
		if ( ! $enabled ) {
			return false;
		}
		
		// Check post meta (can be disabled per post)
		$post_disabled = get_post_meta( $post_id, '_aebg_email_campaigns_disabled', true );
		if ( $post_disabled ) {
			return false;
		}
		
		return true;
	}
}

