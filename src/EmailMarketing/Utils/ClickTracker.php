<?php

namespace AEBG\EmailMarketing\Utils;

use AEBG\EmailMarketing\Repositories\TrackingRepository;
use AEBG\Core\Logger;

/**
 * Click Tracker
 * 
 * Handles click tracking for email links
 * 
 * @package AEBG\EmailMarketing\Utils
 */
class ClickTracker {
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
	 * Add click tracking to email content
	 * 
	 * @param string $email_content Email HTML content
	 * @param int $queue_id Queue ID
	 * @return string Email content with tracked links
	 */
	public function add_click_tracking( $email_content, $queue_id ) {
		// Find all links in email
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $email_content, $matches );
		
		if ( empty( $matches[1] ) ) {
			return $email_content;
		}
		
		foreach ( $matches[1] as $original_url ) {
			// Skip tracking pixel and unsubscribe links
			if ( strpos( $original_url, 'aebg-email-track' ) !== false || 
				 strpos( $original_url, 'aebg-unsubscribe' ) !== false ||
				 strpos( $original_url, 'aebg-email-confirm' ) !== false ) {
				continue;
			}
			
			// Generate tracking token
			$token = TokenGenerator::generate( 16 );
			
			// Store token in queue metadata (we'll verify it when clicked)
			$queue_repository = new \AEBG\EmailMarketing\Repositories\QueueRepository();
			$queue = $queue_repository->get( $queue_id );
			if ( $queue ) {
				$metadata = $queue->metadata ? json_decode( $queue->metadata, true ) : [];
				if ( ! isset( $metadata['click_tokens'] ) ) {
					$metadata['click_tokens'] = [];
				}
				$metadata['click_tokens'][ $token ] = $original_url;
				$queue_repository->update( $queue_id, ['metadata' => $metadata] );
			}
			
			// Create tracked URL
			$tracked_url = add_query_arg( [
				'aebg_track' => 'click',
				'queue_id' => $queue_id,
				'url' => urlencode( $original_url ),
				'token' => $token,
			], home_url( '/aebg-email-track/' ) );
			
			// Replace original URL with tracked URL
			$email_content = str_replace(
				'href="' . esc_attr( $original_url ) . '"',
				'href="' . esc_url( $tracked_url ) . '"',
				$email_content
			);
		}
		
		return $email_content;
	}
	
	/**
	 * Track click
	 * 
	 * @param int $queue_id Queue ID
	 * @param string $url Original URL
	 * @param string $token Tracking token
	 * @return bool True on success
	 */
	public function track_click( $queue_id, $url, $token ) {
		// Get queue item
		$queue_repository = new \AEBG\EmailMarketing\Repositories\QueueRepository();
		$queue = $queue_repository->get( $queue_id );
		
		if ( ! $queue ) {
			return false;
		}
		
		// Verify token from metadata
		$metadata = $queue->metadata ? json_decode( $queue->metadata, true ) : [];
		$click_tokens = $metadata['click_tokens'] ?? [];
		
		if ( ! isset( $click_tokens[ $token ] ) || $click_tokens[ $token ] !== $url ) {
			Logger::warning( 'Invalid click tracking token', [
				'queue_id' => $queue_id,
				'token' => $token,
			] );
			return false;
		}
		
		// Check if already clicked (prevent duplicate tracking)
		$already_clicked = $this->tracking_repository->has_event( $queue_id, 'clicked' );
		if ( $already_clicked ) {
			// Still redirect, but don't track again
			wp_redirect( $url );
			exit;
		}
		
		// Record click
		$this->tracking_repository->log_event(
			$queue_id,
			$queue->campaign_id,
			$queue->subscriber_id,
			'clicked',
			['url' => $url]
		);
		
		// Update campaign stats
		$campaign_repository = new \AEBG\EmailMarketing\Repositories\CampaignRepository();
		$campaign = $campaign_repository->get( $queue->campaign_id );
		if ( $campaign ) {
			$stats = $campaign->stats ? json_decode( $campaign->stats, true ) : [];
			$stats['clicked'] = ( $stats['clicked'] ?? 0 ) + 1;
			$campaign_repository->update( $queue->campaign_id, ['stats' => $stats] );
		}
		
		// Redirect to original URL
		wp_redirect( $url );
		exit;
	}
}

