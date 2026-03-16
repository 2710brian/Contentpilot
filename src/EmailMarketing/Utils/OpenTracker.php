<?php

namespace AEBG\EmailMarketing\Utils;

use AEBG\EmailMarketing\Repositories\TrackingRepository;
use AEBG\EmailMarketing\Repositories\CampaignRepository;
use AEBG\Core\Logger;

/**
 * Open Tracker
 * 
 * Handles open tracking for emails
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class OpenTracker {
	/**
	 * @var TrackingRepository
	 */
	private $tracking_repository;
	
	/**
	 * Constructor
	 * 
	 * @param TrackingRepository $tracking_repository Tracking repository
	 */
	public function __construct( TrackingRepository $tracking_repository ) {
		$this->tracking_repository = $tracking_repository;
	}
	
	/**
	 * Add tracking pixel to email content
	 * 
	 * @param string $email_content Email HTML content
	 * @param int $queue_id Queue ID
	 * @return string Email content with tracking pixel
	 */
	public function add_tracking_pixel( $email_content, $queue_id ) {
		// Generate tracking token
		$token = TokenGenerator::generate( 16 );
		
		// Store token in queue metadata
		$queue_repository = new \AEBG\EmailMarketing\Repositories\QueueRepository();
		$queue = $queue_repository->get( $queue_id );
		if ( $queue ) {
			$metadata = $queue->metadata ? json_decode( $queue->metadata, true ) : [];
			$metadata['open_token'] = wp_hash( $token );
			$queue_repository->update( $queue_id, ['metadata' => $metadata] );
		}
		
		// Build tracking URL
		$tracking_url = add_query_arg( [
			'aebg_track' => 'open',
			'queue_id' => $queue_id,
			'token' => $token,
		], home_url( '/aebg-email-track/' ) );
		
		// Add 1x1 transparent pixel before closing body tag
		$pixel = '<img src="' . esc_url( $tracking_url ) . '" width="1" height="1" style="display:none;" alt="" />';
		
		// Insert before closing body tag, or at end if no body tag
		if ( strpos( $email_content, '</body>' ) !== false ) {
			$email_content = str_replace( '</body>', $pixel . '</body>', $email_content );
		} else {
			$email_content .= $pixel;
		}
		
		return $email_content;
	}
	
	/**
	 * Track email open
	 * 
	 * @param int $queue_id Queue ID
	 * @param string $token Tracking token
	 * @return void
	 */
	public function track_open( $queue_id, $token ) {
		// Get queue item
		$queue_repository = new \AEBG\EmailMarketing\Repositories\QueueRepository();
		$queue = $queue_repository->get( $queue_id );
		
		if ( ! $queue ) {
			$this->send_pixel();
			return;
		}
		
		// Verify token from metadata
		$metadata = $queue->metadata ? json_decode( $queue->metadata, true ) : [];
		$stored_token = $metadata['open_token'] ?? '';
		
		if ( ! hash_equals( $stored_token, wp_hash( $token ) ) ) {
			Logger::warning( 'Invalid open tracking token', [
				'queue_id' => $queue_id,
			] );
			$this->send_pixel();
			return;
		}
		
		// Check if already opened (prevent duplicate tracking)
		$already_opened = $this->tracking_repository->has_event( $queue_id, 'opened' );
		if ( $already_opened ) {
			$this->send_pixel();
			return;
		}
		
		// Record open
		$this->tracking_repository->log_event(
			$queue_id,
			$queue->campaign_id,
			$queue->subscriber_id,
			'opened',
			[]
		);
		
		// Update campaign stats
		$campaign_repository = new CampaignRepository();
		$campaign = $campaign_repository->get( $queue->campaign_id );
		if ( $campaign ) {
			$stats = $campaign->stats ? json_decode( $campaign->stats, true ) : [];
			$stats['opened'] = ( $stats['opened'] ?? 0 ) + 1;
			$campaign_repository->update( $queue->campaign_id, ['stats' => $stats] );
		}
		
		// Return 1x1 transparent pixel
		$this->send_pixel();
	}
	
	/**
	 * Send 1x1 transparent pixel
	 * 
	 * @return void
	 */
	private function send_pixel() {
		header( 'Content-Type: image/gif' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}
}

